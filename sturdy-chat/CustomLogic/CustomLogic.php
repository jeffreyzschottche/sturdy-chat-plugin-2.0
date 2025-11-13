<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Defines the methods required for a SturdyChat custom module to handle content enrichment
 * and optional query or intent handling.
 */
interface SturdyChat_CPT_Module
{
    /**
     * Enhances the content of a given WordPress post using specified parts and settings.
     *
     * @param WP_Post $post The WordPress post object to be enriched.
     * @param array $parts An array of content parts or elements to be utilized for enrichment.
     * @param array $settings An array of settings or configurations influencing the enrichment process.
     * @return array An array representing the enriched content.
     */
    public function enrich_content(WP_Post $post, array $parts, array $settings): array;

    /**
     * Processes a query string based on the provided settings and returns the result.
     *
     * @param string $q The query string to be handled.
     * @param array $settings An array of settings or configurations to guide the query handling process.
     * @return array|null An array containing the query result, or null if no result is found or the query cannot be processed.
     */
    public function handle_query(string $q, array $settings): ?array;
}

class SturdyChat_CPTs
{
    /** @var SturdyChat_CPT_Module[] */
    private static $modules = [];

    /**
     * Initializes custom post type modules by loading their respective files and registering them.
     *
     * @return void
     */
    public static function init(): void
    {
        // Load all CPT modules
        // Possible to add require or dynamically *.php files
        if (!class_exists('SturdyChat_CPT_Jobs')) {
            require_once STURDYCHAT_DIR . 'CustomLogic/Jobs.php';
        }
        self::register(new SturdyChat_CPT_Jobs());
    }

    public static function register(SturdyChat_CPT_Module $m): void
    {
        self::$modules[] = $m;
    }

    /**
     * Enhances the content of a post by iterating through registered modules and applying their enrichment logic.
     *
     * @param WP_Post $post The post object to be enriched.
     * @param array $parts An array containing the current content parts to be processed.
     * @param array $settings An array of additional settings to customize the enrichment process.
     *
     * @return array The enriched content parts after applying all modules.
     */
    public static function enrich(WP_Post $post, array $parts, array $settings): array
    {
        foreach (self::$modules as $m) {
            $parts = $m->enrich_content($post, $parts, $settings);
        }
        return $parts;
    }

    /**
     * Attempts to handle a query by iterating through registered modules and processing the query.
     *
     * @param string $q The query string to be processed.
     * @param array $settings An array of settings or configuration parameters to be used during the query handling.
     * @return array|null Returns an array of results if the query is successfully handled by a module, or null if no module handles the query.
     */
    public static function maybe_handle_query(string $q, array $settings): ?array
    {
        foreach (self::$modules as $m) {
            $res = $m->handle_query($q, $settings);
            if ($res)
                return $res;
        }
        return null;
    }
}
