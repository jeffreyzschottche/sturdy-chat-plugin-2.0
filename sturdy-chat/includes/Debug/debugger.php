<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_Debugger
{
    /**
     * Toggle debug features here.
     *
     * @var array<string,bool>
     */
    private static array $config = [
        'show_query_embedding'  => false,
        'show_index_embedding'  => false,
        'show_sitemap_worker'   => false,
        'show_prompt_context'   => false,
    ];

    /**
     * Map feature keys to their directory name inside includes/Debug/.
     *
     * @var array<string,string>
     */
    private static array $directoryMap = [
        'show_query_embedding'  => 'showQueryEmbedding',
        'show_index_embedding'  => 'showIndexEmbedding',
        'show_sitemap_worker'   => 'showSitemapWorker',
        'show_prompt_context'   => 'showPrompt',
    ];

    /**
     * Determine if a particular debug feature is currently enabled.
     *
     * @param string $key Feature key (e.g. show_query_embedding).
     * @return bool True if logging for the feature should run.
     */
    public static function isEnabled(string $key): bool
    {
        $value = self::$config[$key] ?? false;
        return (bool) apply_filters('sturdychat_debugger_enabled_' . $key, $value);
    }

    /**
     * Write a JSON payload to the appropriate debug results folder.
     *
     * @param string $key        Feature key controlling the target directory.
     * @param string $filePrefix Prefix used to name the log file.
     * @param array  $payload    Arbitrary data to persist as JSON.
     * @return void
     */
    public static function log(string $key, string $filePrefix, array $payload): void
    {
        if (!self::isEnabled($key)) {
            return;
        }

        if (!isset(self::$directoryMap[$key])) {
            return;
        }

        $dir = __DIR__ . '/' . self::$directoryMap[$key] . '/results';
        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }

        $suffix = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
            ? uniqid('', true)
            : gmdate('Ymd-His');

        $path = $dir . '/' . sanitize_file_name($filePrefix . '-' . $suffix) . '.json';
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = print_r($payload, true);
        }

        file_put_contents($path, $json);
    }
}

require_once __DIR__ . '/showQueryEmbedding/showQueryEmbedding.php';
require_once __DIR__ . '/showIndexEmbedding/showIndexEmbedding.php';
require_once __DIR__ . '/showSitemapWorker/showSitemapWorker.php';
require_once __DIR__ . '/showPrompt/showPrompt.php';
