<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Admin_Menu
{
    public static function register(): void
    {
        add_menu_page(
            __('Sturdy Chat', 'sturdychat-chatbot'),
            'Sturdy Chat',
            'manage_options',
            'sturdychat',
            [SturdyChat_Admin_SettingsPage::class, 'render'],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'sturdychat',
            __('Cached Answers', 'sturdychat-chatbot'),
            __('Cached Answers', 'sturdychat-chatbot'),
            'manage_options',
            'sturdychat-cached-answers',
            [SturdyChat_Admin_CachedAnswersPage::class, 'render']
        );
    }
}
