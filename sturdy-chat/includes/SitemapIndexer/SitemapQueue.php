<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_SitemapIndexer_Queue
{
    private const OPT_QUEUE = 'sturdychat_sitemap_queue';
    private const OPT_POS   = 'sturdychat_sitemap_queue_pos';
    private const OPT_TOTAL = 'sturdychat_sitemap_queue_total';
    private const LOCK_KEY  = 'sturdychat_sitemap_lock';

    public static function enqueueUrls(array $urls): void
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        update_option(self::OPT_QUEUE, $urls, false);
        update_option(self::OPT_POS, 0, false);
        update_option(self::OPT_TOTAL, count($urls), false);
    }

    public static function clearQueue(): void
    {
        delete_option(self::OPT_QUEUE);
        delete_option(self::OPT_POS);
        delete_option(self::OPT_TOTAL);
    }

    public static function workBatch(int $batchSize, callable $processor): void
    {
        if (!self::acquireLock(300)) {
            return;
        }

        try {
            $settings = get_option('sturdychat_settings', []);
            $queue    = (array) get_option(self::OPT_QUEUE, []);
            $total    = (int) get_option(self::OPT_TOTAL, 0);
            $pos      = (int) get_option(self::OPT_POS, 0);

            if ($total <= 0) {
                $total = count($queue);
                update_option(self::OPT_TOTAL, $total, false);
            }

            if ($pos >= $total || $pos >= count($queue)) {
                self::clearQueue();
                update_option('sturdychat_sitemap_last_done', current_time('mysql'), false);
                return;
            }

            $batchSize = max(1, $batchSize);
            $slice     = array_slice($queue, $pos, $batchSize);

            foreach ($slice as $url) {
                try {
                    $processor((string) $url, $settings);
                } catch (\Throwable $e) {
                    error_log('[SturdyChat] sitemap index error ' . $url . ': ' . $e->getMessage());
                    update_option('sturdychat_sitemap_last_error', '[' . current_time('mysql') . '] ' . $url . ' :: ' . $e->getMessage(), false);
                }

                $pos++;
                update_option(self::OPT_POS, $pos, false);

                $sleepUs = (int) apply_filters('sturdychat_sitemap_usleep_between', 150000);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }

            if ($pos < $total && $pos < count($queue)) {
                if (function_exists('sturdychat_schedule_sitemap_worker')) {
                    sturdychat_schedule_sitemap_worker(60);
                } else {
                    if (!wp_next_scheduled('sturdychat_sitemap_worker')) {
                        wp_schedule_single_event(time() + 60, 'sturdychat_sitemap_worker');
                    }
                }
            } else {
                self::clearQueue();
                update_option('sturdychat_sitemap_last_done', current_time('mysql'), false);
            }
        } finally {
            self::releaseLock();
        }
    }

    private static function acquireLock(int $ttl): bool
    {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }
        set_transient(self::LOCK_KEY, 1, $ttl);
        return true;
    }

    private static function releaseLock(): void
    {
        delete_transient(self::LOCK_KEY);
    }
}
