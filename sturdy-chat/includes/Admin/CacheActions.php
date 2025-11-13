<?php

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Admin_CacheActions
{
    /**
     * Handle the admin action that triggers a full sitemap re-index.
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
        self::redirectSettingsWithMessage($msg);
    }

    /**
     * Enable cached answers via the admin interface.
     *
     * @return void
     */
    public static function handleEnableCache(): void
    {
        self::guardCacheAction('sturdychat_cache_enable');
        update_option('sturdychat_cache_enabled', 1, false);
        self::redirectSettingsWithMessage(__('Cache is ingeschakeld. Antwoorden worden opnieuw gebruikt.', 'sturdychat-chatbot'));
    }

    /**
     * Disable cached answers via the admin interface.
     *
     * @return void
     */
    public static function handleDisableCache(): void
    {
        self::guardCacheAction('sturdychat_cache_disable');
        update_option('sturdychat_cache_enabled', 0, false);
        self::redirectSettingsWithMessage(__('Cache is uitgeschakeld. Antwoorden worden niet langer opgeslagen.', 'sturdychat-chatbot'));
    }

    /**
     * Reset the cached answers table to an empty state.
     *
     * @return void
     */
    public static function handleResetCache(): void
    {
        self::guardCacheAction('sturdychat_cache_reset');

        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            self::redirectSettingsWithMessage(__('Cache tabel niet gevonden.', 'sturdychat-chatbot'));
        }

        global $wpdb;
        $table = STURDYCHAT_TABLE_CACHE;

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $cleared = $wpdb->query("TRUNCATE TABLE {$table}");
        if ($cleared === false) {
            $cleared = $wpdb->query("DELETE FROM {$table}");
        }

        if ($cleared === false) {
            self::redirectSettingsWithMessage(__('Cache reset is mislukt. Controleer database rechten.', 'sturdychat-chatbot'));
        }

        $msg = sprintf(
            _n('Cache gereset. %d cached answer verwijderd.', 'Cache gereset. %d cached answers verwijderd.', $count, 'sturdychat-chatbot'),
            $count
        );
        self::redirectSettingsWithMessage($msg);
    }

    /**
     * Ensure the current user can perform cache actions and validate the nonce.
     *
     * @param string $nonceAction Nonce action string tied to the form.
     * @return void
     */
    private static function guardCacheAction(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer($nonceAction);
    }

    /**
     * Redirect back to the settings page with a status message.
     *
     * @param string $message Message to show in the admin notice.
     * @return void
     */
    private static function redirectSettingsWithMessage(string $message): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'sturdychat', 'msg' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }
}
