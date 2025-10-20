<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI and settings for Sturdy Chat.
 *
 * - Registers the main admin menu.
 * - Registers settings and renders the settings page.
 * - Handles the "Index Sitemap" action (admin-post endpoint).
 */
class SturdyChat_Admin
{
    /**
     * Registers a new menu item within the WordPress admin dashboard.
     *
     * This method adds a menu page for the Sturdy Chat plugin, allowing administrators
     * to access the settings page. The page is added with the specified title, capability,
     * menu slug, callback function for rendering the settings, icon, and position in the menu.
     *
     * @return void
     */
    public static function registerMenu(): void
    {
        add_menu_page(
            __('Sturdy Chat', 'sturdychat-chatbot'),
            'Sturdy Chat',
            'manage_options',
            'sturdychat',
            [__CLASS__, 'renderSettingsPage'],
            'dashicons-format-chat',
            58
        );
    }

    /**
     * Registers and defines the settings for the SturdyChat plugin.
     *
     * This method sets up a settings group and links it to validation/sanitization callbacks.
     * Additionally, it defines multiple settings fields and organizes them into one or more
     * logical sections using the WordPress settings API.
     *
     * @return void Does not return any value.
     */
    public static function registerSettings(): void
    {
        register_setting('sturdychat_settings_group', 'sturdychat_settings', [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitizeSettings'],
            'default'           => [],
        ]);

        add_settings_section('sturdychat_main', __('General', 'sturdychat-chatbot'), '__return_false', 'sturdychat');

        add_settings_field('provider', __('Provider', 'sturdychat-chatbot'), [__CLASS__, 'fieldProvider'], 'sturdychat', 'sturdychat_main');
        add_settings_field('openai_api_base', __('OpenAI API Base', 'sturdychat-chatbot'), [__CLASS__, 'fieldOpenaiBase'], 'sturdychat', 'sturdychat_main');
        add_settings_field('openai_api_key', __('OpenAI API Key', 'sturdychat-chatbot'), [__CLASS__, 'fieldOpenaiKey'], 'sturdychat', 'sturdychat_main');
        add_settings_field('embed_model', __('Embedding model', 'sturdychat-chatbot'), [__CLASS__, 'fieldEmbedModel'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chat_model', __('Chat model', 'sturdychat-chatbot'), [__CLASS__, 'fieldChatModel'], 'sturdychat', 'sturdychat_main');
        add_settings_field('top_k', __('Top K (context score, DESC % match)', 'sturdychat-chatbot'), [__CLASS__, 'fieldTopK'], 'sturdychat', 'sturdychat_main');
        add_settings_field('temperature', __('Temperature (Weirdness)', 'sturdychat-chatbot'), [__CLASS__, 'fieldTemperature'], 'sturdychat', 'sturdychat_main');

        add_settings_field('index_post_types', __('Post types to index', 'sturdychat-chatbot'), [__CLASS__, 'fieldPostTypes'], 'sturdychat', 'sturdychat_main');
        add_settings_field('include_taxonomies', __('Include taxonomies in context', 'sturdychat-chatbot'), [__CLASS__, 'fieldIncludeTax'], 'sturdychat', 'sturdychat_main');
        add_settings_field('include_meta', __('Include meta fields', 'sturdychat-chatbot'), [__CLASS__, 'fieldIncludeMeta'], 'sturdychat', 'sturdychat_main');
        add_settings_field('meta_keys', __('Meta keys (comma-separated)', 'sturdychat-chatbot'), [__CLASS__, 'fieldMetaKeys'], 'sturdychat', 'sturdychat_main');
        add_settings_field('batch_size', __('Batch size', 'sturdychat-chatbot'), [__CLASS__, 'fieldBatchSize'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chunk_chars', __('Chunk size (characters)', 'sturdychat-chatbot'), [__CLASS__, 'fieldChunkChars'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chat_title', __('Chat title', 'sturdychat-chatbot'), [__CLASS__, 'fieldChatTitle'], 'sturdychat', 'sturdychat_main');

        // New: Sitemap URL
        add_settings_field(
            'sitemap_url',
            'Sitemap URL',
            function (): void {
                $s = get_option('sturdychat_settings', []);
                $v = esc_attr($s['sitemap_url'] ?? home_url('/sitemap_index.xml'));
                echo '<input type="text" class="regular-text" name="sturdychat_settings[sitemap_url]" value="' . $v . '" />';
                echo '<p class="description">Root sitemap (e.g., Yoast): usually /sitemap_index.xml</p>';
            },
            'sturdychat',
            'sturdychat_main'
        );

    }

    /**
     * Sanitizes and processes the given settings array, applying default values
     * where necessary and ensuring input values are properly sanitized.
     *
     * @param array|string $in Raw settings input.
     * @return array Sanitized settings array.
     */
    public static function sanitizeSettings($in): array
    {
        $in  = is_array($in) ? $in : [];
        $out = [];

        $out['provider']        = 'openai';
        $out['openai_api_base'] = isset($in['openai_api_base']) ? esc_url_raw($in['openai_api_base']) : 'https://api.openai.com/v1';
        $out['openai_api_key']  = isset($in['openai_api_key']) ? sanitize_text_field($in['openai_api_key']) : '';
        $out['embed_model']     = !empty($in['embed_model']) ? sanitize_text_field($in['embed_model']) : 'text-embedding-3-small';
        $out['chat_model']      = !empty($in['chat_model']) ? sanitize_text_field($in['chat_model']) : 'gpt-4o-mini';
        $out['top_k']           = max(1, min(12, (int) ($in['top_k'] ?? 6)));
        $out['temperature']     = max(0, min(1, (float) ($in['temperature'] ?? 0.2)));

        // Post types: default to all public CPTs (excluding attachment).
        $allPublic = sturdychat_all_public_types();
        if (isset($in['index_post_types']) && is_array($in['index_post_types']) && count($in['index_post_types']) > 0) {
            $chosen = array_map('sanitize_text_field', $in['index_post_types']);
            $out['index_post_types'] = array_values(array_unique(array_merge($chosen, $allPublic)));
        } else {
            $out['index_post_types'] = $allPublic;
        }

        $out['include_taxonomies'] = !empty($in['include_taxonomies']) ? 1 : 0;
        $out['include_meta']       = !empty($in['include_meta']) ? 1 : 0;
        $out['meta_keys']          = isset($in['meta_keys']) ? sanitize_text_field($in['meta_keys']) : '';
        $out['batch_size']         = max(1, (int) ($in['batch_size'] ?? 25));
        $out['chunk_chars']        = max(400, min(4000, (int) ($in['chunk_chars'] ?? 1200)));
        $out['chat_title']         = !empty($in['chat_title']) ? sanitize_text_field($in['chat_title']) : 'Stel je vraag';

        // New
        $out['sitemap_url'] = isset($in['sitemap_url']) ? esc_url_raw($in['sitemap_url']) : home_url('/sitemap_index.xml');

        return $out;
    }

    /**
     * Outputs a static field label or identifier for display purposes.
     *
     * @return void
     */
    public static function fieldProvider(): void
    {
        echo '<strong>OpenAI</strong> (MVP).';
    }

    /**
     * Renders an input field for setting the base URL of the OpenAI API in the plugin settings.
     *
     * @return void
     */
    public static function fieldOpenaiBase(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="url" name="sturdychat_settings[openai_api_base]" value="%s" class="regular-text" placeholder="https://api.openai.com/v1" />',
            esc_attr($s['openai_api_base'] ?? '')
        );
    }

    /**
     * Outputs a password input field for the OpenAI API key in the settings page.
     *
     * @return void
     */
    public static function fieldOpenaiKey(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="password" name="sturdychat_settings[openai_api_key]" value="%s" class="regular-text" />',
            esc_attr($s['openai_api_key'] ?? '')
        );
        echo '<p class="description">Server-side only.</p>';
    }

    /**
     * Outputs an HTML input field for the 'embed_model' configuration setting.
     *
     * @return void
     */
    public static function fieldEmbedModel(): void
    {
        $s   = get_option('sturdychat_settings', []);
        $val = $s['embed_model'] ?? 'text-embedding-3-small';
        printf('<input type="text" name="sturdychat_settings[embed_model]" value="%s" class="regular-text" />', esc_attr($val));
    }

    /**
     * Outputs an HTML input field for the 'chat_model' configuration setting.
     *
     * @return void
     */
    public static function fieldChatModel(): void
    {
        $s   = get_option('sturdychat_settings', []);
        $val = $s['chat_model'] ?? 'gpt-4o-mini';
        printf('<input type="text" name="sturdychat_settings[chat_model]" value="%s" class="regular-text" />', esc_attr($val));
    }

    /**
     * Outputs an HTML input field for configuring the "Top K" setting in the SturdyChat plugin.
     *
     * @return void
     */
    public static function fieldTopK(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="1" max="12" name="sturdychat_settings[top_k]" value="%d" class="small-text" />', (int) ($s['top_k'] ?? 6));
    }

    /**
     * Outputs an HTML input field for configuring the "Temperature" setting in the SturdyChat plugin.
     *
     * @return void
     */
    public static function fieldTemperature(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="number" step="0.1" min="0" max="1" name="sturdychat_settings[temperature]" value="%s" class="small-text" />',
            esc_attr((string) ($s['temperature'] ?? 0.2))
        );
    }

    /**
     * Outputs an HTML form section for selecting which post types should be indexed by the SturdyChat plugin.
     *
     * @return void
     */
    public static function fieldPostTypes(): void
    {
        $s        = get_option('sturdychat_settings', []);
        $all      = sturdychat_all_public_types();
        $selected = isset($s['index_post_types']) && is_array($s['index_post_types']) ? $s['index_post_types'] : $all;

        $objs = get_post_types(['public' => true], 'objects');
        unset($objs['attachment']);

        echo '<div style="display:flex;gap:1rem;flex-wrap:wrap">';
        foreach ($objs as $k => $obj) {
            printf(
                '<label><input type="checkbox" name="sturdychat_settings[index_post_types][]" value="%s" %s> %s</label>',
                esc_attr($k),
                checked(in_array($k, $selected, true), true, false),
                esc_html($obj->labels->name)
            );
        }
        echo '</div>';
        echo '<p class="description">By default, all public post types are selected.</p>';
    }

    /**
     * Outputs an HTML checkbox input field for configuring the "Include Taxonomies" option.
     *
     * @return void
     */
    public static function fieldIncludeTax(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<label><input type="checkbox" name="sturdychat_settings[include_taxonomies]" value="1" %s> %s</label>',
            checked(!empty($s['include_taxonomies']), true, false),
            __('Send terms (categories, tags, custom taxonomies)', 'sturdychat-chatbot')
        );
    }

    /**
     * Renders a checkbox input field for the "include_meta" setting.
     *
     * @return void
     */
    public static function fieldIncludeMeta(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<label><input type="checkbox" name="sturdychat_settings[include_meta]" value="1" %s> %s</label>',
            checked(!empty($s['include_meta']), true, false),
            __('Send selected meta fields', 'sturdychat-chatbot')
        );
    }

    /**
     * Renders a text input field for the "meta_keys" setting.
     *
     * @return void
     */
    public static function fieldMetaKeys(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="text" name="sturdychat_settings[meta_keys]" value="%s" class="regular-text" placeholder="key1, key2" />',
            esc_attr($s['meta_keys'] ?? '')
        );
    }

    /**
     * Renders a numeric input field for the "batch_size" setting.
     *
     * @return void
     */
    public static function fieldBatchSize(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="1" max="500" name="sturdychat_settings[batch_size]" value="%d" class="small-text" />', (int) ($s['batch_size'] ?? 25));
    }

    /**
     * Renders a number input field for the "chunk_chars" setting.
     *
     * @return void
     */
    public static function fieldChunkChars(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="400" max="4000" name="sturdychat_settings[chunk_chars]" value="%d" class="small-text" />', (int) ($s['chunk_chars'] ?? 1200));
    }

    /**
     * Renders a text input field for the "chat_title" setting.
     *
     * @return void
     */
    public static function fieldChatTitle(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="text" name="sturdychat_settings[chat_title]" value="%s" class="regular-text" />', esc_attr($s['chat_title'] ?? 'Stel je vraag'));
    }

    /**
     * Renders the settings page for the Sturdy Chat plugin.
     * Includes forms for updating plugin settings and triggering an indexing process.
     * Displays success or error notices based on user actions.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $indexUrl = wp_nonce_url(admin_url('admin-post.php?action=sturdychat_index_sitemap'), 'sturdychat_index_sitemap');

        echo '<div class="wrap"><h1>Sturdy Chat (Self-Hosted RAG)</h1>';

        if (!empty($_GET['msg'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash((string) $_GET['msg'])) . '</p></div>';
        }

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;">';
        echo '<h2>' . esc_html__('Index sitemap', 'sturdychat-chatbot') . '</h2>';
        echo '<p>' . esc_html__('Crawl the configured sitemap and refresh the vector index used for retrieval.', 'sturdychat-chatbot') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($indexUrl) . '">' . esc_html__('Start indexing', 'sturdychat-chatbot') . '</a></p>';
        echo '</div>';

        echo '<form method="post" action="options.php">';
        settings_fields('sturdychat_settings_group');
        do_settings_sections('sturdychat');
        submit_button(__('Save settings', 'sturdychat-chatbot'));
        echo '</form>';

        echo '</div>';
    }

    /**
     * Handles the admin-post action for indexing the sitemap.
     *
     * @return void
     */
    public static function handleIndexSitemap(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('sturdychat_index_sitemap');

        $s   = get_option('sturdychat_settings', []);
        $res = SturdyChat_SitemapIndexer::indexAll($s);

        $msg = $res['ok'] ? $res['message'] : ('Failed: ' . $res['message']);
        wp_safe_redirect(add_query_arg(
            ['page' => 'sturdychat', 'msg' => rawurlencode($msg)],
            admin_url('admin.php')
        ));
        exit;
    }
}
