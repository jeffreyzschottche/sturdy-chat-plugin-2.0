<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Menu.php';
require_once __DIR__ . '/SettingsRegister.php';
require_once __DIR__ . '/SettingsPage.php';
require_once __DIR__ . '/CachedAnswersPage.php';
require_once __DIR__ . '/CacheActions.php';

/**
 * Thin manager that keeps the legacy public API while delegating to smaller classes.
 */
class SturdyChat_Admin
{
    public static function registerMenu(): void
    {
        SturdyChat_Admin_Menu::register();
    }

    public static function registerSettings(): void
    {
        SturdyChat_Admin_SettingsRegister::register();
    }

    public static function renderSettingsPage(): void
    {
        SturdyChat_Admin_SettingsPage::render();
    }

    public static function renderCachedAnswersPage(): void
    {
        SturdyChat_Admin_CachedAnswersPage::render();
    }

    public static function handleIndexSitemap(): void
    {
        SturdyChat_Admin_CacheActions::handleIndexSitemap();
    }

    public static function handleEnableCache(): void
    {
        SturdyChat_Admin_CacheActions::handleEnableCache();
    }

    public static function handleDisableCache(): void
    {
        SturdyChat_Admin_CacheActions::handleDisableCache();
    }

    public static function handleResetCache(): void
    {
        SturdyChat_Admin_CacheActions::handleResetCache();
    }
}
