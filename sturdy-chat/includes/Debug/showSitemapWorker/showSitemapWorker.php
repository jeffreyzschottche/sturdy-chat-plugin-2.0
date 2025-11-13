<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_Debugger_ShowSitemapWorker
{
    /**
     * Persist details about a sitemap worker batch (urls processed, progress).
     *
     * @param array $data Batch summary payload.
     * @return void
     */
    public static function logBatch(array $data): void
    {
        SturdyChat_Debugger::log('show_sitemap_worker', 'worker', [
            'batch'      => $data['batch'] ?? [],
            'position'   => $data['position'] ?? 0,
            'total'      => $data['total'] ?? 0,
            'timestamp'  => current_time('mysql'),
        ]);
    }
}
