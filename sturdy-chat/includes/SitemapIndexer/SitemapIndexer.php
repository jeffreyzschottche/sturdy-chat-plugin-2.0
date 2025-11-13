<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/SitemapFetcher.php';
require_once __DIR__ . '/SitemapPageIndexer.php';
require_once __DIR__ . '/SitemapQueue.php';

final class SturdyChat_SitemapIndexer
{
    /**
     * Build or refresh the sitemap index queue based on the configured sitemap URL.
     *
     * @param array $settings Plugin settings array used to determine sitemap root.
     * @return array{ok:bool,message:string} Status message describing the queueing result.
     */
    public static function indexAll(array $settings): array
    {
        $root = trim((string) ($settings['sitemap_url'] ?? home_url('/sitemap_index.xml')));
        if ($root === '') {
            return ['ok' => false, 'message' => __('Sitemap URL is empty. Set it in Settings.', 'sturdychat-chatbot')];
        }

        $childSitemaps = SturdyChat_SitemapIndexer_Fetcher::fetchSitemapIndex($root);
        if (empty($childSitemaps)) {
            return ['ok' => false, 'message' => sprintf(__('No child sitemaps parsed at %s.', 'sturdychat-chatbot'), $root)];
        }

        $urls = [];
        foreach ($childSitemaps as $child) {
            $rows = SturdyChat_SitemapIndexer_Fetcher::fetchSitemapUrls($child);
            foreach ($rows as $row) {
                $loc = trim((string) ($row['loc'] ?? ''));
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }
        $urls = array_values(array_unique($urls));

        if (!$urls) {
            return ['ok' => false, 'message' => __('Child sitemaps had no URLs.', 'sturdychat-chatbot')];
        }

        $urls = self::filterUnindexedUrls($urls);
        if (!$urls) {
            SturdyChat_SitemapIndexer_Queue::clearQueue();
            return ['ok' => true, 'message' => __('Sitemap already indexed. No new URLs found.', 'sturdychat-chatbot')];
        }

        SturdyChat_SitemapIndexer_Queue::enqueueUrls($urls);
        if (function_exists('sturdychat_schedule_sitemap_worker')) {
            sturdychat_schedule_sitemap_worker();
        }

        return ['ok' => true, 'message' => sprintf(__('Queued %d new URLs for background indexing.', 'sturdychat-chatbot'), count($urls))];
    }

    /**
     * Index a single URL immediately, typically triggered by save_post hooks.
     *
     * @param string $url              URL that should be fetched and embedded.
     * @param array  $settings         Plugin settings array.
     * @param bool   $force            Whether to force reindexing even if hashes match.
     * @param array  $knownUrlVariants Optional list of URL variants to purge before inserting.
     * @return bool|null True when inserted, null when skipped, false on HTTP failure.
     */
    public static function indexSingleUrl(string $url, array $settings, bool $force = false, array $knownUrlVariants = []): ?bool
    {
        return SturdyChat_SitemapIndexer_PageIndexer::indexSingleUrl($url, $settings, $force, $knownUrlVariants);
    }

    /**
     * Process one batch from the sitemap queue, scheduling the worker again if needed.
     *
     * @param int $batchSize Number of URLs to process in this run.
     * @return void
     */
    public static function workBatch(int $batchSize = 50): void
    {
        SturdyChat_SitemapIndexer_Queue::workBatch($batchSize, function (string $url, array $settings): ?bool {
            return SturdyChat_SitemapIndexer_PageIndexer::processUrl($url, $settings);
        });
    }

    /**
     * Remove URLs that are already indexed so we only queue new work.
     *
     * @param string[] $urls Candidate URLs extracted from the sitemap.
     * @return string[] URLs that still need to be indexed.
     */
    private static function filterUnindexedUrls(array $urls): array
    {
        global $wpdb;
        $table    = STURDYCHAT_TABLE_SITEMAP;
        $existing = [];

        foreach (array_chunk($urls, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
            $sql          = "SELECT DISTINCT url FROM {$table} WHERE url IN ($placeholders)";
            $found        = $wpdb->get_col($wpdb->prepare($sql, $chunk));
            if ($found) {
                $existing = array_merge($existing, $found);
            }
        }

        if ($existing) {
            $urls = array_values(array_diff($urls, array_unique($existing)));
        }

        return $urls;
    }
}
