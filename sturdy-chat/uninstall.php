<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table = isset($wpdb) ? $wpdb->prefix . 'sturdychat_chunks' : null;

// delete plugin settings
delete_option('sturdychat_settings');

// drop table
if ($table) {
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}
