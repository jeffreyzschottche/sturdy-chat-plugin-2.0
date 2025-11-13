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
    'includes/Admin/Admin.php',
    'includes/Debug/debugger.php',
    'includes/Embedder.php',
    'includes/Indexer.php',
    'includes/SitemapIndexer/SitemapIndexer.php',
    'includes/RAG/RAG.php',
    'includes/REST.php',
    'includes/Cache/Cache.php',
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
 * Schedule the sitemap worker to run once after a short delay.
 *
 * @param int $delay Number of seconds to wait before scheduling the worker.
 *                   Defaults to 60 seconds to avoid immediate re-entry.
 * @return void
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
 * Return all public post type slugs except for attachments.
 *
 * @return string[] Array of post type names that should be considered for indexing.
 */
function sturdychat_all_public_types(): array
{
    $names = get_post_types(['public' => true], 'names');
    unset($names['attachment']);
    return array_values($names);
}

/**
 * Drain the sitemap queue synchronously by repeatedly invoking the worker.
 *
 * @param int $batchSize Number of URLs processed per worker invocation.
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
 * Trigger sitemap indexing for a post whenever it is saved or updated.
 *
 * @param int     $post_id Post identifier emitted by the save action.
 * @param WP_Post $post    Post object instance or null.
 * @param bool    $update  Indicates whether this save is an update.
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
 * Remove stored embeddings and cached answers for a post that is being deleted.
 *
 * @param int $post_id Identifier of the post being removed or trashed.
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
 * Build a list of canonical URLs and normalized paths for a post.
 *
 * @param WP_Post $post Post instance to analyse.
 * @return array{urls:string[],paths:string[]} URL variants and path fragments used for purging.
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
 * Normalize a URL path so comparisons ignore trailing slashes and case.
 *
 * @param string $path Raw path to normalize.
 * @return string Normalized path beginning with a slash.
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

/**
 * Ensure a hex color is valid or fall back to a default.
 *
 * @param string|null $color   User provided color string.
 * @param string      $default Hex string used when the provided color is invalid.
 * @return string Sanitized color value.
 */
function sturdychat_sanitize_hex_color_default(?string $color, string $default): string
{
    $color = (string) $color;

    if (function_exists('sanitize_hex_color')) {
        $sanitized = sanitize_hex_color($color);
        if ($sanitized !== null && $sanitized !== false) {
            return $sanitized;
        }
    } elseif (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color)) {
        return $color;
    }

    return $default;
}

/**
 * Resolve UI customisation settings, providing safe defaults.
 *
 * @param array|null $settings Optional base settings array (falls back to option).
 * @return array{text_color:string,pill_color:string,sources_limit:int,style_variant:string}
 */
function sturdychat_get_ui_settings(?array $settings = null): array
{
    $settings = $settings ?? get_option('sturdychat_settings', []);

    $textColor = sturdychat_sanitize_hex_color_default($settings['ui_text_color'] ?? '', '#0f172a');
    $pillColor = sturdychat_sanitize_hex_color_default($settings['ui_pill_color'] ?? '', '#2563eb');

    $limit = isset($settings['ui_sources_limit']) ? (int) $settings['ui_sources_limit'] : 3;
    $limit = max(1, min(6, $limit));

    $style = isset($settings['ui_style_variant']) ? sanitize_key((string) $settings['ui_style_variant']) : 'pill';
    $allowedStyles = ['pill', 'outline', 'minimal'];
    if (!in_array($style, $allowedStyles, true)) {
        $style = 'pill';
    }

    return [
        'text_color'     => $textColor,
        'pill_color'     => $pillColor,
        'sources_limit'  => $limit,
        'style_variant'  => $style,
    ];
}

/**
 * Cap the number of sources shown client side.
 *
 * @param array<int, array{title:string,url:string,score:float}> $sources
 * @return array<int, array{title:string,url:string,score:float}>
 */
/**
 * Limit the number of sources that will be displayed or returned.
 *
 * @param array<int,array<string,mixed>> $sources Raw source array.
 * @param int                            $limit   Maximum number of entries to keep.
 * @return array<int,array<string,mixed>> Trimmed list of sources.
 */
function sturdychat_limit_sources(array $sources, int $limit): array
{
    if ($limit < 1) {
        return $sources;
    }

    return array_slice($sources, 0, $limit);
}

/**
 * Normalize raw source data so both the REST endpoint and shortcode can reuse it.
 *
 * @param array<int,array{title?:string,url?:string,score?:float}> $sources Raw sources from upstream calls.
 * @param string|null                                             $fallbackAnswer Fallback answer configured in settings.
 * @param string                                                  $answer         The final answer string returned to the user.
 * @return array<int,array{title:string,url:string,score:float}> Normalized source entries.
 */
function sturdychat_prepare_sources(array $sources, ?string $fallbackAnswer, string $answer): array
{
    if ($fallbackAnswer !== null && $answer === $fallbackAnswer) {
        return [];
    }

    $normalized = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $title = isset($source['title']) ? trim((string) $source['title']) : '';
        $url = isset($source['url']) ? trim((string) $source['url']) : '';
        $score = isset($source['score']) ? (float) $source['score'] : 0.0;

        if ($title === '' && $url === '') {
            continue;
        }

        $normalized[] = [
            'title' => $title,
            'url' => $url,
            'score' => $score,
        ];
    }

    return $normalized;
}

/**
 * Generate or retrieve an answer for a question, used by both shortcode and REST API.
 *
 * @param string $question Natural-language question submitted by the user.
 * @return array{answer:string,sources:array<int,array{title:string,url:string,score:float}>}|\WP_Error
 */
function sturdychat_answer_question(string $question)
{
    $question = trim((string) $question);
    if ($question === '') {
        return new WP_Error('empty_question', __('Vraag is leeg.', 'sturdychat-chatbot'));
    }

    $settings = get_option('sturdychat_settings', []);
    $cacheEnabled = (bool) get_option('sturdychat_cache_enabled', 1);
    $cacheAvailable = $cacheEnabled && class_exists('SturdyChat_Cache');
    $ui = sturdychat_get_ui_settings($settings);
    $maxSources = (int) $ui['sources_limit'];

    $configuredFallback = null;
    if (class_exists('SturdyChat_RAG')) {
        if (method_exists('SturdyChat_RAG', 'fallbackAnswer')) {
            $configuredFallback = SturdyChat_RAG::fallbackAnswer($settings);
        } elseif (defined('SturdyChat_RAG::FALLBACK_ANSWER')) {
            $configuredFallback = SturdyChat_RAG::FALLBACK_ANSWER;
        }
    }

    if ($cacheAvailable) {
        $cached = SturdyChat_Cache::find($question);
        if (is_array($cached) && isset($cached['answer'])) {
            $answer = (string) $cached['answer'];
            $sources = sturdychat_prepare_sources($cached['sources'] ?? [], $configuredFallback, $answer);
            $sources = sturdychat_limit_sources($sources, $maxSources);
            return [
                'answer'  => $answer,
                'sources' => $sources,
            ];
        }
    }

    if (class_exists('SturdyChat_CPTs')) {
        $maybe = SturdyChat_CPTs::maybe_handle_query($question, $settings);
        if (is_array($maybe) && isset($maybe['answer'])) {
            $answer = (string) $maybe['answer'];
            $sources = sturdychat_prepare_sources($maybe['sources'] ?? [], $configuredFallback, $answer);
            $sources = sturdychat_limit_sources($sources, $maxSources);

            if ($cacheAvailable) {
                SturdyChat_Cache::store($question, $answer, $sources);
            }

            return [
                'answer'  => $answer,
                'sources' => $sources,
            ];
        }
    }

    if (!class_exists('SturdyChat_RAG')) {
        return new WP_Error('rag_missing', __('RAG component ontbreekt.', 'sturdychat-chatbot'));
    }

    try {
        $out = SturdyChat_RAG::answer($question, $settings);
    } catch (\Throwable $e) {
        return new WP_Error('server_error', $e->getMessage());
    }

    if (empty($out['ok'])) {
        $message = isset($out['message']) ? (string) $out['message'] : __('Onbekende fout', 'sturdychat-chatbot');
        return new WP_Error('chat_failed', $message);
    }

    $answer = (string) ($out['answer'] ?? '');
    $sources = sturdychat_prepare_sources($out['sources'] ?? [], $configuredFallback, $answer);
    $sources = sturdychat_limit_sources($sources, $maxSources);

    if ($cacheAvailable) {
        SturdyChat_Cache::store($question, $answer, $sources);
    }

    return [
        'answer'  => $answer,
        'sources' => $sources,
    ];
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
            'ui_text_color' => '#0f172a',
            'ui_pill_color' => '#2563eb',
            'ui_sources_limit' => 3,
            'ui_style_variant' => 'pill',
            'fallback_answer' => class_exists('SturdyChat_RAG')
                ? SturdyChat_RAG::FALLBACK_ANSWER
                : 'Deze informatie bestaat niet in onze huidige kennisbank. Probeer je vraag specifieker te stellen of gebruik andere trefwoorden.',
        ]);
    }
    if (false === get_option('sturdychat_cache_enabled', false)) {
        add_option('sturdychat_cache_enabled', 1, false);
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
        add_action('admin_post_sturdychat_cache_enable', ['SturdyChat_Admin', 'handleEnableCache']);
        add_action('admin_post_sturdychat_cache_disable', ['SturdyChat_Admin', 'handleDisableCache']);
        add_action('admin_post_sturdychat_cache_reset', ['SturdyChat_Admin', 'handleResetCache']);

    }
    if (class_exists('SturdyChat_REST')) {
        add_action('rest_api_init', ['SturdyChat_REST', 'registerRoutes']);
    }

    /**
     * Shortcode [sturdy-chat]
     */
    add_shortcode('sturdy-chat', function ($atts = []) {
        $settings = get_option('sturdychat_settings', []);
        $ui = sturdychat_get_ui_settings($settings);

        $atts = shortcode_atts([
            'question' => '',
        ], $atts, 'sturdy-chat');

        $question = trim((string) $atts['question']);
        if ($question === '' && function_exists('is_search') && is_search()) {
            $question = (string) get_search_query();
        }

        if ($question === '') {
            return '';
        }

        wp_enqueue_style('sturdychat-result', STURDYCHAT_URL . 'assets/css/sturdychat-result.css', [], STURDYCHAT_VERSION);
        wp_enqueue_script('sturdychat-result', STURDYCHAT_URL . 'assets/js/sturdychat-result.js', [], STURDYCHAT_VERSION, true);

        $result = sturdychat_answer_question($question);
        if (is_wp_error($result)) {
            return sprintf(
                '<div class="sturdychat-result sturdychat-result--error">%s</div>',
                esc_html($result->get_error_message())
            );
        }

        $answerText = isset($result['answer']) ? (string) $result['answer'] : '';
        $sources = isset($result['sources']) && is_array($result['sources']) ? $result['sources'] : [];
        $answerHtml = wpautop(wp_kses_post($answerText));

        $styleAttr = sprintf(
            'style="--sturdychat-text-color:%1$s;--sturdychat-pill-color:%2$s;"',
            esc_attr($ui['text_color']),
            esc_attr($ui['pill_color'])
        );

        $styleClass = sanitize_html_class('sturdychat-style-' . $ui['style_variant']);
        $html = sprintf(
            '<div class="sturdychat-result %s" %s data-style="%s">',
            $styleClass,
            $styleAttr,
            esc_attr($ui['style_variant'])
        );

        if ($answerText !== '') {
            $html .= '<div class="sturdychat-result__answer">';
            $html .= '<div class="sturdychat-result__answer-text" aria-live="polite"></div>';
            $html .= '<template class="sturdychat-answer-template">' . $answerHtml . '</template>';
            $html .= '<noscript><div class="sturdychat-result__answer-noscript">' . $answerHtml . '</div></noscript>';
            $html .= '</div>';
        }

        $items = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $title = isset($source['title']) ? trim((string) $source['title']) : '';
            $url = isset($source['url']) ? trim((string) $source['url']) : '';
            if ($title === '' && $url === '') {
                continue;
            }
            if ($title === '') {
                $title = $url;
            }

            if ($url !== '') {
                $items[] = sprintf(
                    '<li><a href="%s" target="_blank" rel="noopener">%s</a></li>',
                    esc_url($url),
                    esc_html($title)
                );
            } else {
                $items[] = sprintf('<li>%s</li>', esc_html($title));
            }
        }

        if (!empty($items)) {
            $html .= sprintf(
                '<div class="sturdychat-result__sources"><strong>%s</strong><ol>%s</ol></div>',
                esc_html__('Bronnen', 'sturdychat-chatbot'),
                implode('', $items)
            );
        }

        $html .= '</div>';

        return $html;
    });

    // WP index is deprecated; sitemap worker handles crawling.
});
