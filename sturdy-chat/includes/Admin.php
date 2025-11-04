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

        add_submenu_page(
            'sturdychat',
            __('Cached Answers', 'sturdychat-chatbot'),
            __('Cached Answers', 'sturdychat-chatbot'),
            'manage_options',
            'sturdychat-cached-answers',
            [__CLASS__, 'renderCachedAnswersPage']
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

        $indexUrl  = wp_nonce_url(admin_url('admin-post.php?action=sturdychat_index_sitemap'), 'sturdychat_index_sitemap');
        $searchUrl = wp_nonce_url(admin_url('admin-post.php?action=sturdychat_list_unindexed'), 'sturdychat_list_unindexed');

        $unindexedUrls    = (array) get_option('sturdychat_unindexed_urls', []);
        $unindexedMessage = (string) get_option('sturdychat_unindexed_message', '');
        $skippedUrls      = [];
        foreach ((array) get_option('sturdychat_skipped_sitemap_urls', []) as $skip) {
            $clean = esc_url_raw((string) $skip);
            if ($clean !== '') {
                $skippedUrls[] = $clean;
            }
        }
        $skippedUrls = array_values(array_unique($skippedUrls));
        $skipAction  = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Sturdy Chat (Self-Hosted RAG)</h1>';

        if (!empty($_GET['msg'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash((string) $_GET['msg'])) . '</p></div>';
        }

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;">';
        echo '<h2>' . esc_html__('Index sitemap', 'sturdychat-chatbot') . '</h2>';
        echo '<p>' . esc_html__('Crawl the configured sitemap and refresh the vector index used for retrieval.', 'sturdychat-chatbot') . '</p>';
        echo '<p>';
        echo '<a class="button" style="margin-right:8px;" href="' . esc_url($searchUrl) . '">' . esc_html__('Search unindexed sitemap', 'sturdychat-chatbot') . '</a>';
        echo '<a class="button button-primary" href="' . esc_url($indexUrl) . '">' . esc_html__('Index / vector embed', 'sturdychat-chatbot') . '</a>';
        echo '</p>';

        if ($unindexedMessage !== '') {
            echo '<p><em>' . esc_html($unindexedMessage) . '</em></p>';
        }

        if (!empty($unindexedUrls)) {
            $joined = implode("\n", array_map('esc_url_raw', $unindexedUrls));
            echo '<p><textarea rows="10" class="large-text code" readonly>' . esc_textarea($joined) . '</textarea></p>';
            echo '<form method="post" action="' . esc_url($skipAction) . '" style="margin-top:12px;">';
            echo '<input type="hidden" name="action" value="sturdychat_skip_unindexed" />';
            echo '<input type="hidden" name="mode" value="skip" />';
            wp_nonce_field('sturdychat_skip_unindexed');
            echo '<p>' . esc_html__('Select the URLs you want to ignore for future indexing runs.', 'sturdychat-chatbot') . '</p>';
            echo '<ul class="ul-disc" style="max-height:220px;overflow:auto;padding-left:20px;">';
            foreach ($unindexedUrls as $url) {
                $clean = esc_url_raw((string) $url);
                if ($clean === '') {
                    continue;
                }
                echo '<li><label><input type="checkbox" name="skip_urls[]" value="' . esc_attr($clean) . '" /> ' . esc_html($clean) . '</label></li>';
            }
            echo '</ul>';
            submit_button(__('Ignore selected URLs', 'sturdychat-chatbot'), 'secondary', 'submit', false);
            echo '</form>';
        }

        if (!empty($skippedUrls)) {
            echo '<hr />';
            echo '<h3>' . esc_html__('Ignored sitemap URLs', 'sturdychat-chatbot') . '</h3>';
            echo '<p>' . esc_html__('These URLs are skipped when you search for unindexed sitemap entries.', 'sturdychat-chatbot') . '</p>';
            echo '<form method="post" action="' . esc_url($skipAction) . '" style="margin-top:12px;">';
            echo '<input type="hidden" name="action" value="sturdychat_skip_unindexed" />';
            echo '<input type="hidden" name="mode" value="restore" />';
            wp_nonce_field('sturdychat_skip_unindexed');
            echo '<ul class="ul-disc" style="max-height:220px;overflow:auto;padding-left:20px;">';
            foreach ($skippedUrls as $url) {
                echo '<li><label><input type="checkbox" name="skip_urls[]" value="' . esc_attr($url) . '" /> ' . esc_html($url) . '</label></li>';
            }
            echo '</ul>';
            submit_button(__('Stop ignoring selected URLs', 'sturdychat-chatbot'), 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '</div>';

        echo '<form method="post" action="options.php">';
        settings_fields('sturdychat_settings_group');
        do_settings_sections('sturdychat');
        submit_button(__('Save settings', 'sturdychat-chatbot'));
        echo '</form>';

        echo '</div>';
    }

    /**
     * Render and handle the cached answers management screen.
     */
    public static function renderCachedAnswersPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        global $wpdb;

        $errors  = [];
        $updated = false;

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['sturdychat_cache_action'])) {
            $postAction = sanitize_key(wp_unslash((string) $_POST['sturdychat_cache_action']));
            if ($postAction === 'delete') {
                check_admin_referer('sturdychat_cache_delete');

                $entryId = isset($_POST['entry_id']) ? (int) wp_unslash((string) $_POST['entry_id']) : 0;
                if ($entryId <= 0) {
                    $errors[] = __('Entry not found.', 'sturdychat-chatbot');
                }

                if (!$errors) {
                    $deleted = $wpdb->delete(STURDYCHAT_TABLE_CACHE, ['id' => $entryId], ['%d']);
                    if (false === $deleted) {
                        $errors[] = __('Database error while deleting the cached answer.', 'sturdychat-chatbot');
                    } else {
                        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
                        if (!$redirect) {
                            $redirect = add_query_arg(
                                [
                                    'page'     => 'sturdychat-cached-answers',
                                    'deleted'  => 1,
                                ],
                                admin_url('admin.php')
                            );
                        } else {
                            $redirect = add_query_arg('deleted', 1, remove_query_arg('deleted', $redirect));
                        }

                        wp_safe_redirect($redirect);
                        exit;
                    }
                }
            } elseif ($postAction === 'bulk_delete') {
                check_admin_referer('sturdychat_cache_bulk_delete');

                $ids = isset($_POST['sturdychat_bulk']) ? (array) wp_unslash($_POST['sturdychat_bulk']) : [];
                $ids = array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
                    return $id > 0;
                }));

                if (!$ids) {
                    $errors[] = __('Select at least one cached answer to delete.', 'sturdychat-chatbot');
                }

                if (!$errors) {
                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $sql = 'DELETE FROM ' . STURDYCHAT_TABLE_CACHE . ' WHERE id IN (' . $placeholders . ')';

                    $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $ids));
                    if (!$prepared) {
                        $errors[] = __('Unable to prepare bulk delete statement.', 'sturdychat-chatbot');
                    }

                    if (!$errors) {
                        $deleted = $wpdb->query($prepared);

                        if (false === $deleted) {
                            $errors[] = __('Database error while deleting cached answers.', 'sturdychat-chatbot');
                        } else {
                            $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
                            if (!$redirect) {
                                $redirect = add_query_arg(
                                    [
                                        'page'         => 'sturdychat-cached-answers',
                                        'bulkdeleted'  => count($ids),
                                    ],
                                    admin_url('admin.php')
                                );
                            } else {
                                $redirect = add_query_arg('bulkdeleted', count($ids), remove_query_arg('bulkdeleted', $redirect));
                            }

                            wp_safe_redirect($redirect);
                            exit;
                        }
                    }
                }
            } elseif ($postAction === 'update') {
                check_admin_referer('sturdychat_cache_edit');

                $entryId = isset($_POST['entry_id']) ? (int) wp_unslash((string) $_POST['entry_id']) : 0;
                $question = isset($_POST['question']) ? sanitize_text_field(wp_unslash((string) $_POST['question'])) : '';
                $answer = isset($_POST['answer']) ? wp_kses_post(wp_unslash((string) $_POST['answer'])) : '';
                $sourcesRaw = isset($_POST['sources']) ? wp_unslash((string) $_POST['sources']) : '';

                if ($entryId <= 0) {
                    $errors[] = __('Entry not found.', 'sturdychat-chatbot');
                }

                if ($question === '') {
                    $errors[] = __('Question cannot be empty.', 'sturdychat-chatbot');
                }

                if ($answer === '') {
                    $errors[] = __('Answer cannot be empty.', 'sturdychat-chatbot');
                }

                $normalized = '';
                $hash       = '';
                $sourcesValue = '';

                if (!$errors) {
                    $norm = SturdyChat_Cache::normalizeForStorage($question);
                    $normalized = $norm['normalized'];
                    $hash       = $norm['hash'];

                    if ($normalized === '' || $hash === '') {
                        $errors[] = __('Unable to normalize question; please adjust the text.', 'sturdychat-chatbot');
                    }
                }

                if (!$errors) {
                    $sourcesResult = SturdyChat_Cache::normalizeSourcesInput($sourcesRaw);
                    if (!$sourcesResult['ok']) {
                        $errors[] = $sourcesResult['message'];
                    } else {
                        $sourcesValue = $sourcesResult['value'];
                    }
                }

                if (!$errors) {
                    $result = $wpdb->update(
                        STURDYCHAT_TABLE_CACHE,
                        [
                            'question'            => $question,
                            'normalized_question' => $normalized,
                            'normalized_hash'     => $hash,
                            'answer'              => $answer,
                            'sources'             => $sourcesValue,
                        ],
                        ['id' => $entryId],
                        ['%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );

                    if (false === $result) {
                        $errors[] = __('Database error while updating the cached answer.', 'sturdychat-chatbot');
                    } else {
                        $updated = true;

                        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
                        if (!$redirect) {
                            $redirect = add_query_arg(
                                [
                                    'page'   => 'sturdychat-cached-answers',
                                    'action' => 'edit',
                                    'entry'  => $entryId,
                                    'updated' => 1,
                                ],
                                admin_url('admin.php')
                            );
                        } else {
                            $redirect = add_query_arg('updated', 1, remove_query_arg('updated', $redirect));
                        }

                        wp_safe_redirect($redirect);
                        exit;
                    }
                }
            }
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, (int) wp_unslash((string) $_GET['paged'])) : 1;
        $perPage = 20;
        $offset = ($paged - 1) * $perPage;

        $whereClause = '';
        $whereParams = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $whereClause = 'WHERE question LIKE %s OR normalized_question LIKE %s OR answer LIKE %s';
            $whereParams = [$like, $like, $like];
        }

        $countSql = 'SELECT COUNT(*) FROM ' . STURDYCHAT_TABLE_CACHE . ' ' . $whereClause;
        $total = $whereClause ? (int) $wpdb->get_var($wpdb->prepare($countSql, $whereParams)) : (int) $wpdb->get_var($countSql);

        $selectSql = 'SELECT id, question, answer, sources, created_at FROM ' . STURDYCHAT_TABLE_CACHE . ' ' . $whereClause . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $whereClause
            ? $wpdb->get_results($wpdb->prepare($selectSql, array_merge($whereParams, [$perPage, $offset])), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($selectSql, $perPage, $offset), ARRAY_A);

        $rows = is_array($rows) ? $rows : [];

        $currentEditId = isset($_GET['entry']) ? (int) $_GET['entry'] : 0;
        $entryToEdit = null;
        if ($action === 'edit' && $currentEditId > 0) {
            $entryToEdit = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . STURDYCHAT_TABLE_CACHE . ' WHERE id = %d', $currentEditId),
                ARRAY_A
            );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Cached Answers', 'sturdychat-chatbot') . '</h1>';

        if (!empty($_GET['updated']) || $updated) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached answer updated.', 'sturdychat-chatbot') . '</p></div>';
        }

        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached answer deleted.', 'sturdychat-chatbot') . '</p></div>';
        }

        if (!empty($_GET['bulkdeleted'])) {
            $count = (int) $_GET['bulkdeleted'];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d cached answer deleted.', '%d cached answers deleted.', $count, 'sturdychat-chatbot'), $count)) . '</p></div>';
        }

        foreach ($errors as $message) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<form method="get" class="search-form">';
        echo '<input type="hidden" name="page" value="sturdychat-cached-answers" />';
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="sturdychat-cache-search">' . esc_html__('Search cached answers', 'sturdychat-chatbot') . '</label>';
        echo '<input type="search" id="sturdychat-cache-search" name="s" value="' . esc_attr($search) . '" />';
        echo '<input type="submit" class="button" value="' . esc_attr__('Search', 'sturdychat-chatbot') . '" />';
        echo '</p>';
        echo '</form>';

        if ($entryToEdit) {
            $redirectArgs = [
                'page'   => 'sturdychat-cached-answers',
                'action' => 'edit',
                'entry'  => $entryToEdit['id'],
                'paged'  => $paged,
            ];
            if ($search !== '') {
                $redirectArgs['s'] = $search;
            }

            $redirectTo = add_query_arg($redirectArgs, admin_url('admin.php'));

            $listRedirectArgs = [
                'page'  => 'sturdychat-cached-answers',
                'paged' => $paged,
            ];
            if ($search !== '') {
                $listRedirectArgs['s'] = $search;
            }

            $redirectToList = add_query_arg($listRedirectArgs, admin_url('admin.php'));

            $sourcesForEditor = SturdyChat_Cache::formatSourcesForEditor((string) ($entryToEdit['sources'] ?? ''));

            echo '<div class="card" style="max-width:960px; margin-top: 20px;">';
            echo '<h2>' . esc_html__('Edit cached answer', 'sturdychat-chatbot') . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('sturdychat_cache_edit');
            echo '<input type="hidden" name="sturdychat_cache_action" value="update" />';
            echo '<input type="hidden" name="entry_id" value="' . (int) $entryToEdit['id'] . '" />';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '" />';

            echo '<p><label>' . esc_html__('Question', 'sturdychat-chatbot') . '<br />';
            echo '<input type="text" name="question" value="' . esc_attr($entryToEdit['question'] ?? '') . '" class="regular-text" /></label></p>';

            echo '<p><label>' . esc_html__('Answer', 'sturdychat-chatbot') . '<br />';
            echo '<textarea name="answer" rows="6" class="large-text" style="width:100%">' . esc_textarea($entryToEdit['answer'] ?? '') . '</textarea></label></p>';

            echo '<p><label>' . esc_html__('Sources (JSON array)', 'sturdychat-chatbot') . '<br />';
            echo '<textarea name="sources" rows="6" class="large-text" style="width:100%" placeholder="[\n  {\n    \"title\": \"...\",\n    \"url\": \"https://...\",\n    \"score\": 0.0\n  }\n]">' . esc_textarea($sourcesForEditor) . '</textarea></label></p>';
            echo '<p class="description">' . esc_html__('Optional. Provide a JSON array with objects containing title, url, and score.', 'sturdychat-chatbot') . '</p>';

            if (!empty($entryToEdit['created_at'])) {
                echo '<p class="description">' . sprintf(
                    esc_html__('Created at: %s', 'sturdychat-chatbot'),
                    esc_html(get_date_from_gmt($entryToEdit['created_at'], get_option('date_format') . ' ' . get_option('time_format')))
                ) . '</p>';
            }

            submit_button(__('Save changes', 'sturdychat-chatbot'));
            echo '</form>';

            echo '<form method="post" style="margin-top:15px;">';
            wp_nonce_field('sturdychat_cache_delete');
            echo '<input type="hidden" name="sturdychat_cache_action" value="delete" />';
            echo '<input type="hidden" name="entry_id" value="' . (int) $entryToEdit['id'] . '" />';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectToList) . '" />';
            $confirm = esc_js(__('Are you sure you want to delete this cached answer?', 'sturdychat-chatbot'));
            submit_button(__('Delete cached answer', 'sturdychat-chatbot'), 'delete', 'submit', false, ['onclick' => "return confirm('" . $confirm . "');"]);
            echo '</form>';

            echo '</div>';
        }

        if (!$rows) {
            echo '<p>' . esc_html__('No cached answers found.', 'sturdychat-chatbot') . '</p>';
        } else {
            echo '<form method="post" style="margin-top:20px;">';
            wp_nonce_field('sturdychat_cache_bulk_delete');
            echo '<input type="hidden" name="sturdychat_cache_action" value="bulk_delete" />';

            $bulkRedirectArgs = [
                'page'  => 'sturdychat-cached-answers',
                'paged' => $paged,
            ];
            if ($search !== '') {
                $bulkRedirectArgs['s'] = $search;
            }

            echo '<input type="hidden" name="redirect_to" value="' . esc_attr(add_query_arg($bulkRedirectArgs, admin_url('admin.php'))) . '" />';

            echo '<table id="sturdychat-cache-table" class="wp-list-table widefat fixed striped table-view-list">';
            echo '<thead><tr>';
            echo '<td id="cb" class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1" /></td>';
            echo '<th scope="col">' . esc_html__('ID', 'sturdychat-chatbot') . '</th>';
            echo '<th scope="col">' . esc_html__('Question', 'sturdychat-chatbot') . '</th>';
            echo '<th scope="col">' . esc_html__('Answer (excerpt)', 'sturdychat-chatbot') . '</th>';
            echo '<th scope="col">' . esc_html__('Actions', 'sturdychat-chatbot') . '</th>';
            echo '</tr></thead>';

            echo '<tbody>';
            foreach ($rows as $row) {
                $answerExcerpt = wp_trim_words(wp_strip_all_tags((string) ($row['answer'] ?? '')), 25, '…');
                $editUrl = add_query_arg(
                    [
                        'page'   => 'sturdychat-cached-answers',
                        'action' => 'edit',
                        'entry'  => (int) $row['id'],
                        's'      => $search,
                        'paged'  => $paged,
                    ],
                    admin_url('admin.php')
                );

                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="sturdychat_bulk[]" value="' . (int) $row['id'] . '" /></th>';
                echo '<td>' . (int) $row['id'] . '</td>';
                echo '<td>' . esc_html($row['question'] ?? '') . '</td>';
                echo '<td>' . esc_html($answerExcerpt) . '</td>';
                echo '<td><a href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'sturdychat-chatbot') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';

            $confirmBulk = esc_js(__('Are you sure you want to delete the selected cached answers?', 'sturdychat-chatbot'));
            echo '<p>';
            submit_button(__('Delete selected', 'sturdychat-chatbot'), 'delete', 'submit', false, ['onclick' => "return confirm('" . $confirmBulk . "');"]);
            echo '</p>';
            echo '</form>';

            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var selectAll = document.getElementById("cb-select-all-1");
                    var checkboxes = document.querySelectorAll("#sturdychat-cache-table tbody input[type=checkbox]");
                    if (!selectAll || !checkboxes.length) {
                        return;
                    }

                    var syncState = function() {
                        var checkedCount = 0;
                        for (var i = 0; i < checkboxes.length; i++) {
                            if (checkboxes[i].checked) {
                                checkedCount++;
                            }
                        }
                        if (checkedCount === 0) {
                            selectAll.checked = false;
                            selectAll.indeterminate = false;
                        } else if (checkedCount === checkboxes.length) {
                            selectAll.checked = true;
                            selectAll.indeterminate = false;
                        } else {
                            selectAll.checked = false;
                            selectAll.indeterminate = true;
                        }
                    };

                    selectAll.addEventListener("change", function() {
                        for (var i = 0; i < checkboxes.length; i++) {
                            checkboxes[i].checked = selectAll.checked;
                        }
                        selectAll.indeterminate = false;
                    });

                    for (var i = 0; i < checkboxes.length; i++) {
                        checkboxes[i].addEventListener("change", syncState);
                    }

                    syncState();
                });
            </script>';

            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($totalPages > 1) {
                $baseUrl = add_query_arg(
                    [
                        'page'  => 'sturdychat-cached-answers',
                        'paged' => '%#%',
                    ],
                    admin_url('admin.php')
                );

                $addArgs = [];
                if ($search !== '') {
                    $addArgs['s'] = $search;
                }

                $pagination = paginate_links([
                    'base'      => $baseUrl,
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $totalPages,
                    'current'   => $paged,
                    'add_args'  => $addArgs,
                ]);

                if ($pagination) {
                    echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
                }
            }
        }

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

        delete_option('sturdychat_unindexed_urls');
        delete_option('sturdychat_unindexed_message');

        $s   = get_option('sturdychat_settings', []);
        $res = SturdyChat_SitemapIndexer::indexAll($s);

        $msg = $res['ok'] ? $res['message'] : ('Failed: ' . $res['message']);
        wp_safe_redirect(add_query_arg(
            ['page' => 'sturdychat', 'msg' => rawurlencode($msg)],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles the admin-post action for listing unindexed sitemap URLs.
     */
    public static function handleListUnindexed(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('sturdychat_list_unindexed');

        $s   = get_option('sturdychat_settings', []);
        $res = SturdyChat_SitemapIndexer::findUnindexedUrls($s);

        if ($res['ok'] && !empty($res['urls'])) {
            update_option('sturdychat_unindexed_urls', array_values($res['urls']), false);
        } else {
            delete_option('sturdychat_unindexed_urls');
        }

        update_option('sturdychat_unindexed_message', $res['message'] ?? '', false);

        $msg = $res['message'] ?? '';

        wp_safe_redirect(add_query_arg(
            ['page' => 'sturdychat', 'msg' => rawurlencode($msg)],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles skipping or restoring sitemap URLs from the "unindexed" suggestions list.
     */
    public static function handleSkipUnindexed(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('sturdychat_skip_unindexed');

        $mode      = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : 'skip';
        $rawSelect = isset($_POST['skip_urls']) ? (array) wp_unslash($_POST['skip_urls']) : [];

        $selected = [];
        foreach ($rawSelect as $url) {
            $clean = esc_url_raw((string) $url);
            if ($clean !== '') {
                $selected[] = $clean;
            }
        }
        $selected = array_values(array_unique($selected));

        $existingSkip = [];
        foreach ((array) get_option('sturdychat_skipped_sitemap_urls', []) as $url) {
            $clean = esc_url_raw((string) $url);
            if ($clean !== '') {
                $existingSkip[] = $clean;
            }
        }
        $existingSkip = array_values(array_unique($existingSkip));

        $count   = count($selected);
        $message = '';

        if ($mode === 'restore') {
            if ($count > 0) {
                $updated = array_values(array_diff($existingSkip, $selected));
                if (empty($updated)) {
                    delete_option('sturdychat_skipped_sitemap_urls');
                } else {
                    update_option('sturdychat_skipped_sitemap_urls', $updated, false);
                }

                $message = sprintf(
                    _n('One URL has been restored to the indexing queue.', '%d URLs have been restored to the indexing queue.', $count, 'sturdychat-chatbot'),
                    $count
                );
            } else {
                $message = __('No URL selected.', 'sturdychat-chatbot');
            }
        } else {
            if ($count > 0) {
                $merged = array_values(array_unique(array_merge($existingSkip, $selected)));
                update_option('sturdychat_skipped_sitemap_urls', $merged, false);

                $pending = [];
                foreach ((array) get_option('sturdychat_unindexed_urls', []) as $url) {
                    $clean = esc_url_raw((string) $url);
                    if ($clean !== '' && !in_array($clean, $selected, true)) {
                        $pending[] = $clean;
                    }
                }
                if (!empty($pending)) {
                    update_option('sturdychat_unindexed_urls', array_values(array_unique($pending)), false);
                } else {
                    delete_option('sturdychat_unindexed_urls');
                }

                $message = sprintf(
                    _n('One URL ignored.', '%d URLs ignored.', $count, 'sturdychat-chatbot'),
                    $count
                );
            } else {
                $message = __('No URL selected.', 'sturdychat-chatbot');
            }
        }

        update_option('sturdychat_unindexed_message', $message, false);

        wp_safe_redirect(add_query_arg(
            ['page' => 'sturdychat', 'msg' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }
}
