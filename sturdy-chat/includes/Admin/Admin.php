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
    /**
     * Register the admin menu entries for Sturdy Chat.
     *
     * @return void
     */
    public static function registerMenu(): void
    {
        SturdyChat_Admin_Menu::register();
    }

    /**
     * Register plugin settings and their sanitization callbacks.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        SturdyChat_Admin_SettingsRegister::register();
    }

    /**
     * Render the main settings page.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        SturdyChat_Admin_SettingsPage::render();
    }

    /**
     * Render the cached answers management page.
     *
     * @return void
     */
    public static function renderCachedAnswersPage(): void
    {
        SturdyChat_Admin_CachedAnswersPage::render();
    }

    /**
     * Handle the sitemap indexing request triggered from the admin UI.
     *
     * @return void
     */
    public static function handleIndexSitemap(): void
    {
        SturdyChat_Admin_CacheActions::handleIndexSitemap();
    }

    /**
     * Enable cached answers from the admin UI.
     *
     * @return void
     */
    public static function handleEnableCache(): void
    {
        SturdyChat_Admin_CacheActions::handleEnableCache();
    }

    /**
     * Disable cached answers from the admin UI.
     *
     * @return void
     */
    public static function handleDisableCache(): void
    {
        SturdyChat_Admin_CacheActions::handleDisableCache();
    }

    /**
     * Reset the cached answers table from the admin UI.
     *
     * @return void
     */
    public static function handleResetCache(): void
    {
        SturdyChat_Admin_CacheActions::handleResetCache();
    }
}
