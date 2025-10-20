<?php
if (!defined('ABSPATH')) exit;

class SturdyChat_Install
{

    /**
     * Checks if the specified database table exists.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public static function tableExists(): bool
    {
        global $wpdb;
        $t1 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", STURDYCHAT_TABLE));
        $t2 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", STURDYCHAT_TABLE_SITEMAP));
        return (bool)($t1 && $t2);
    }

    /**
     * Ensures the database table required for the application exists.
     * Creates the table with specified schema if it does not already exist.
     *
     * @return void
     */
    public static function ensureDb(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Original table
        $sql1 = "CREATE TABLE " . STURDYCHAT_TABLE . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            content LONGTEXT NOT NULL,
            embedding LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            content_hash CHAR(64) NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY updated_at (updated_at),
            FULLTEXT KEY content_ft (content)
        ) $charset;";

        // New sitemap table
        $sql2 = "CREATE TABLE " . STURDYCHAT_TABLE_SITEMAP . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            cpt VARCHAR(64) DEFAULT '' NOT NULL,
            title TEXT NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            content LONGTEXT NOT NULL,
            embedding LONGTEXT NOT NULL,
            published_at DATETIME NULL,
            modified_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            content_hash CHAR(64) NOT NULL,
            jsonld LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY cpt (cpt),
            KEY updated_at (updated_at),
            FULLTEXT KEY content_ft (content)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
    }
}
