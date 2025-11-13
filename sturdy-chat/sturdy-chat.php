<?php
/**
 * Plugin Name: Sturdy Chat
 * Builds a local vectorindex from your WP content and creates a shortcode for a shortcode + /ask REST-route
 * Version: 1.0.0
 * Author: Jeffrey Zschöttche
 * Text Domain: sturdychat-chatbot
 */

if (!defined('ABSPATH'))
    exit;

/** Constants */
define('STURDYCHAT_VERSION', '1.0.0');
define('STURDYCHAT_FILE', __FILE__);
define('STURDYCHAT_DIR', plugin_dir_path(__FILE__));
define('STURDYCHAT_URL', plugin_dir_url(__FILE__));

global $wpdb;

// DB Table for vector embedded chunks
define('STURDYCHAT_TABLE', $wpdb->prefix . 'sturdychat_chunks');
define('STURDYCHAT_TABLE_SITEMAP', $wpdb->prefix . 'sturdychat_chunks_sitemap');
define('STURDYCHAT_TABLE_CACHE', $wpdb->prefix . 'sturdychat_cached_answers');

add_filter('sturdychat_sitemap_sslverify', function($verify) {
    $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
    if (preg_match('/(\.test|\.local|localhost)$/i', $host)) {
        return false;
    }
    return $verify;
});


/** Includes */
$need = [
    'includes/Install.php',
    'includes/Admin.php',
    'includes/Embedder.php',
    'includes/Indexer.php',
    'includes/SitemapIndexer.php',
    'includes/RAG.php',
    'includes/REST.php',
    'includes/Cache.php',
    'CustomLogic/CustomLogic.php',
    'CustomLogic/Jobs.php',
    'CustomLogic/Posts.php',
];
foreach ($need as $rel) {
    $abs = STURDYCHAT_DIR . $rel;
    if (file_exists($abs))
        require_once $abs;
}

// Cron worker that processes a small batch each run
add_action('sturdychat_sitemap_worker', function (): void {
    if (!class_exists('SturdyChat_SitemapIndexer')) {
        return;
    }
    SturdyChat_SitemapIndexer::workBatch(50); // tune batch size (e.g., 5–10)
});

/**
 * Plan a single-run from sitemap worker.
 *
 */
function sturdychat_schedule_sitemap_worker(int $delay = 60): void
{
    $ts = time() + max(0, $delay);

    // Als er al een event staat, niets doen (we werken met single events die zichzelf herplannen)
    if (!wp_next_scheduled('sturdychat_sitemap_worker')) {
        wp_schedule_single_event($ts, 'sturdychat_sitemap_worker');
    }
}


/**
 * Retrieves a list of all public post types, excluding 'attachment'.
 *
 * @return array An array of public post type names, excluding 'attachment'.
 */
function sturdychat_all_public_types(): array
{
    $names = get_post_types(['public' => true], 'names');
    unset($names['attachment']);
    return array_values($names);
}

/**
 * Processes the sitemap queue immediately by repeatedly running the worker until it is empty.
 *
 * @param int $batchSize Number of URLs to process per worker run.
 * @return void
 */
function sturdychat_process_sitemap_queue_until_complete(int $batchSize = 50): void
{
    if (!class_exists('SturdyChat_SitemapIndexer')) {
        return;
    }

    $maxIterations = 200; // Safety guard: 200 * 50 = 10k URLs.
    for ($i = 0; $i < $maxIterations; $i++) {
        $total = (int) get_option('sturdychat_sitemap_queue_total', 0);
        $pos   = (int) get_option('sturdychat_sitemap_queue_pos', 0);

        if ($total <= 0 || $pos >= $total) {
            break;
        }

        $before = $pos;
        SturdyChat_SitemapIndexer::workBatch($batchSize);
        $after = (int) get_option('sturdychat_sitemap_queue_pos', 0);

        // No progress (e.g. lock contention or error) — stop looping.
        if ($after <= $before) {
            break;
        }
    }
}

/**
 * Automatically triggers sitemap indexing whenever a public post is saved.
 *
 * @param int     $post_id The post ID being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an existing post being updated.
 * @return void
 */
function sturdychat_trigger_sitemap_index_on_save(int $post_id, $post, bool $update): void
{
    if (!class_exists('SturdyChat_SitemapIndexer')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    if (!$post instanceof WP_Post) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return;
        }
    }

    // Only trigger for published (or scheduled) content.
    if (!in_array($post->post_status, ['publish', 'future'], true)) {
        return;
    }

    $permalink = get_permalink($post);
    if (!$permalink || is_wp_error($permalink)) {
        return;
    }

    $targets = sturdychat_collect_post_url_targets($post);
    $urls    = $targets['urls'];
    if (empty($urls)) {
        $urls = [$permalink];
    }

    $settings = get_option('sturdychat_settings', []);

    try {
        SturdyChat_SitemapIndexer::indexSingleUrl($permalink, $settings, true, $urls);
    } catch (\Throwable $e) {
        error_log('[SturdyChat] Failed to index ' . $permalink . ': ' . $e->getMessage());
    }
}
add_action('save_post', 'sturdychat_trigger_sitemap_index_on_save', 20, 3);

/**
 * Cleanup helper: remove indexed chunks and cached answers when a post is deleted.
 *
 * @param int $post_id
 * @return void
 */
function sturdychat_handle_post_delete(int $post_id): void
{
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return;
    }

    $targets = sturdychat_collect_post_url_targets($post);
    $urls    = $targets['urls'];
    $paths   = $targets['paths'];

    global $wpdb;

    if (defined('STURDYCHAT_TABLE')) {
        $wpdb->delete(STURDYCHAT_TABLE, ['post_id' => $post_id], ['%d']);
    }

    if (defined('STURDYCHAT_TABLE_SITEMAP')) {
        if ($urls) {
            $placeholders = implode(', ', array_fill(0, count($urls), '%s'));
            $sql = "DELETE FROM " . STURDYCHAT_TABLE_SITEMAP . " WHERE url IN ($placeholders)";
            $wpdb->query($wpdb->prepare($sql, $urls));
        }

        if ($paths) {
            $conditions = [];
            $args       = [];
            foreach ($paths as $path) {
                if ($path === '' || $path === '/') {
                    continue;
                }
                $conditions[] = 'url LIKE %s';
                $args[]       = '%' . $path . '%';
            }
            if ($conditions) {
                $sql = "DELETE FROM " . STURDYCHAT_TABLE_SITEMAP . " WHERE " . implode(' OR ', $conditions);
                $wpdb->query($wpdb->prepare($sql, $args));
            }
        }
    }

    if (class_exists('SturdyChat_Cache') && method_exists('SturdyChat_Cache', 'purgeBySourceUrls')) {
        SturdyChat_Cache::purgeBySourceUrls($urls, $paths);
    }
}
add_action('before_delete_post', 'sturdychat_handle_post_delete', 10, 1);
add_action('wp_trash_post', 'sturdychat_handle_post_delete', 10, 1);

/**
 * Collect URL and path variants for a post so we can purge chunks/caches reliably.
 *
 * @param WP_Post $post
 * @return array{urls:array<int,string>,paths:array<int,string>}
 */
function sturdychat_collect_post_url_targets(WP_Post $post): array
{
    $candidates = [];
    $permalink  = get_permalink($post);
    if ($permalink && !is_wp_error($permalink)) {
        $candidates[] = $permalink;
    }

    if (function_exists('get_post_permalink')) {
        $sample = get_post_permalink($post, false, true);
        if ($sample && !is_wp_error($sample)) {
            $candidates[] = $sample;
        }
    }

    $uri = function_exists('get_page_uri') ? get_page_uri($post) : '';
    if (is_string($uri) && $uri !== '') {
        $uri = '/' . ltrim($uri, '/');
        if (function_exists('user_trailingslashit')) {
            $candidates[] = home_url(user_trailingslashit($uri));
            $candidates[] = home_url(untrailingslashit($uri));
        } else {
            $candidates[] = home_url(rtrim($uri, '/') . '/');
            $candidates[] = home_url(rtrim($uri, '/'));
        }
    }

    $candidates[] = home_url('?p=' . $post->ID);

    $urls = [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        $urls[] = $candidate;
        if (function_exists('set_url_scheme')) {
            $urls[] = set_url_scheme($candidate, 'https');
            $urls[] = set_url_scheme($candidate, 'http');
        }
    }

    $expanded = [];
    foreach ($urls as $url) {
        if ($url === '') {
            continue;
        }
        $expanded[] = $url;
        if (function_exists('untrailingslashit')) {
            $trimmed = untrailingslashit($url);
            $expanded[] = $trimmed;
            $expanded[] = trailingslashit($trimmed);
        } else {
            $trimmed = rtrim($url, '/');
            $expanded[] = $trimmed;
            $expanded[] = $trimmed . '/';
        }
    }
    $urls = array_values(array_unique(array_filter($expanded, static fn($url): bool => is_string($url) && $url !== '')));

    $paths = [];
    foreach ($urls as $url) {
        $parts = wp_parse_url($url);
        if (is_array($parts) && isset($parts['path'])) {
            $paths[] = sturdychat_normalize_url_path($parts['path']);
        }
    }

    if (!empty($post->post_name)) {
        $paths[] = sturdychat_normalize_url_path('/' . ltrim((string) $post->post_name, '/'));
    }

    if ($uri) {
        $paths[] = sturdychat_normalize_url_path($uri);
    }

    $paths = array_values(array_unique(array_filter($paths, static fn($path): bool => is_string($path) && $path !== '')));

    return [
        'urls'  => $urls,
        'paths' => $paths,
    ];
}

/**
 * Normalize a URL path to a consistent form (leading slash, no trailing slash, lower-case).
 */
function sturdychat_normalize_url_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    if (function_exists('untrailingslashit')) {
        $path = untrailingslashit($path);
    } else {
        $path = rtrim($path, '/');
    }
    return strtolower($path);
}

if (class_exists('SturdyChat_CPTs')) {
    SturdyChat_CPTs::init();
}

/** Activate */
register_activation_hook(__FILE__, function () {
    if (!get_option('sturdychat_settings')) {
        add_option('sturdychat_settings', [
            'provider' => 'openai',
            'openai_api_base' => 'https://api.openai.com/v1',
            'openai_api_key' => '',
            'embed_model' => 'text-embedding-3-small',
            'chat_model' => 'gpt-4o-mini',
            'top_k' => 6,
            'temperature' => 0.2,
            'index_post_types' => sturdychat_all_public_types(),
            'index_post_types_order' => sturdychat_all_public_types(),
            'include_taxonomies' => 1,
            'include_meta' => 0,
            'meta_keys' => '',
            'batch_size' => 25,
            'chunk_chars' => 1200,
            'chat_title' => 'Stel je vraag',
        ]);
    }
    if (class_exists('SturdyChat_Install')) {
        SturdyChat_Install::ensureDb();
    }
    // Endpoint, inspired by NLWEB's /ask endpoint. Hopefully AI crawlers will also notice our url.
    add_rewrite_rule('^sturdychat/ask/?$', 'index.php?rest_route=/sturdychat/v1/ask', 'top');
    flush_rewrite_rules();
});

/** Deactivate */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/** Plugins loaded */
add_action('plugins_loaded', function () {
    if (class_exists('SturdyChat_Install') && !SturdyChat_Install::tableExists()) {
        SturdyChat_Install::ensureDb();
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success"><p>Sturdy Chat: database (her)gemaakt. Je kunt nu indexeren.</p></div>';
        });
    }

    if (class_exists('SturdyChat_Admin')) {
        add_action('admin_menu', ['SturdyChat_Admin', 'registerMenu']);
        add_action('admin_init', ['SturdyChat_Admin', 'registerSettings'], 20);
        add_action('admin_post_sturdychat_index_sitemap', ['SturdyChat_Admin', 'handleIndexSitemap']);

    }
    if (class_exists('SturdyChat_REST')) {
        add_action('rest_api_init', ['SturdyChat_REST', 'registerRoutes']);
    }

    /**
     * Shortcode [sturdy-chat]
     */
    add_shortcode('sturdy-chat', function ($atts = []) {
        $s = get_option('sturdychat_settings', []);
        $atts = shortcode_atts([
            'title' => $s['chat_title'] ?? 'Stel je vraag',
            'placeholder' => '',
            'button' => '',
        ], $atts, 'sturdy-chat');

        $rest_url = rest_url('sturdychat/v1/ask');
        $rest_fallback = home_url('/index.php?rest_route=/sturdychat/v1/ask');

        wp_enqueue_style('sturdychat-chatbot', STURDYCHAT_URL . 'assets/css/chatbot.css', [], STURDYCHAT_VERSION);
        wp_enqueue_script('sturdychat-chatbot', STURDYCHAT_URL . 'assets/js/chatbot.js', [], STURDYCHAT_VERSION, true);

        wp_localize_script('sturdychat-chatbot', 'STURDYCHAT', [
            'restUrl' => esc_url_raw($rest_url),
            'restUrlFallback' => esc_url_raw($rest_fallback),
            'title' => $atts['title'],
            'placeholder' => $atts['placeholder'],
            'button' => $atts['button'],
        ]);

        ob_start(); ?>
        <div class="sturdychat-chatbot" data-rest-url="<?php echo esc_attr($rest_url); ?>"
            data-rest-fallback="<?php echo esc_attr($rest_fallback); ?>"></div>
        <?php return ob_get_clean();
    });

    // WP index is deprecated; sitemap worker handles crawling.
});
