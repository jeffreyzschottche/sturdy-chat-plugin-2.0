<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Admin_SettingsPage
{
    /**
     * Render the main settings page UI.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $indexUrl = wp_nonce_url(admin_url('admin-post.php?action=sturdychat_index_sitemap'), 'sturdychat_index_sitemap');
        $cacheEnabled = (bool) get_option('sturdychat_cache_enabled', 1);

        echo '<div class="wrap"><h1>Sturdy Chat (Self-Hosted RAG)</h1>';

        if (!empty($_GET['msg'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash((string) $_GET['msg'])) . '</p></div>';
        }

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;">';
        echo '<h2>' . esc_html__('Index sitemap', 'sturdychat-chatbot') . '</h2>';
        echo '<p>' . esc_html__('Crawl the configured sitemap and refresh the vector index used for retrieval.', 'sturdychat-chatbot') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($indexUrl) . '">' . esc_html__('Start indexing', 'sturdychat-chatbot') . '</a></p>';
        echo '</div>';

        $cacheStatus = $cacheEnabled
            ? __('Cache staat aan. Herhaalde vragen gebruiken het opgeslagen antwoord.', 'sturdychat-chatbot')
            : __('Cache staat uit. Antwoorden worden niet opgeslagen of opgehaald.', 'sturdychat-chatbot');

        echo '<div class="card" style="max-width:600px;margin-bottom:20px;">';
        echo '<h2>' . esc_html__('Cached answers', 'sturdychat-chatbot') . '</h2>';
        echo '<p>' . esc_html__('Enable caching to reuse answers for repeated questions or reset it to clear stored responses.', 'sturdychat-chatbot') . '</p>';
        echo '<p><strong>' . esc_html__('Status:', 'sturdychat-chatbot') . '</strong> ' . esc_html($cacheStatus) . '</p>';
        echo '<div class="sturdychat-cache-actions" style="display:flex;gap:10px;flex-wrap:wrap;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
        wp_nonce_field('sturdychat_cache_enable');
        echo '<input type="hidden" name="action" value="sturdychat_cache_enable" />';
        $enableDisabled = $cacheEnabled ? ' disabled="disabled"' : '';
        echo '<button type="submit" class="button button-primary"' . $enableDisabled . '>' . esc_html__('Enable cache', 'sturdychat-chatbot') . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
        wp_nonce_field('sturdychat_cache_disable');
        echo '<input type="hidden" name="action" value="sturdychat_cache_disable" />';
        $disableDisabled = $cacheEnabled ? '' : ' disabled="disabled"';
        echo '<button type="submit" class="button button-secondary"' . $disableDisabled . '>' . esc_html__('Disable cache', 'sturdychat-chatbot') . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
        wp_nonce_field('sturdychat_cache_reset');
        echo '<input type="hidden" name="action" value="sturdychat_cache_reset" />';
        $confirmReset = esc_js(__('Resetting removes every cached answer. Continue?', 'sturdychat-chatbot'));
        echo '<button type="submit" class="button button-secondary button-small" onclick="return confirm(\'' . $confirmReset . '\');">' . esc_html__('Reset cache', 'sturdychat-chatbot') . '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';

        echo '<form method="post" action="options.php">';
        settings_fields('sturdychat_settings_group');
        do_settings_sections('sturdychat');
        submit_button(__('Save settings', 'sturdychat-chatbot'));
        echo '</form>';

        echo '</div>';
    }
}
