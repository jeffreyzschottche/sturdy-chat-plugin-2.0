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
    'logic/CPT/CPTs.php',
    'logic/CPT/Jobs.php',
    'logic/CPT/Posts.php',
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
    SturdyChat_SitemapIndexer::workBatch(8); // tune batch size (e.g., 5–10)
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
        add_action('admin_post_sturdychat_list_unindexed', ['SturdyChat_Admin', 'handleListUnindexed']);

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
