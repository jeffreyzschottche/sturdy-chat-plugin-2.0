<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crawls a Yoast-style sitemap index and builds/updates the secondary vector index table.
 */
final class SturdyChat_SitemapIndexer
{
    private const OPT_QUEUE = 'sturdychat_sitemap_queue';
    private const OPT_POS   = 'sturdychat_sitemap_queue_pos';
    private const OPT_TOTAL = 'sturdychat_sitemap_queue_total';
    private const LOCK_KEY  = 'sturdychat_sitemap_lock';

    /**
     * Crawl the configured sitemap index and (re)index linked URLs into STURDYCHAT_TABLE_SITEMAP.
     *
     * @param array $s Plugin settings.
     * @return array{ok:bool,message:string}
     */
    /**
     * Admin entry: put URLs in a queue and schedule the worker.
     * Safe to click multiple times — queue overwrites and de-dupes.
     */
    public static function indexAll(array $s): array
    {
        $list = self::findUnindexedUrls($s);
        if (!$list['ok']) {
            return ['ok' => false, 'message' => $list['message']];
        }

        $urls = $list['urls'];

        if (empty($urls)) {
            delete_option(self::OPT_QUEUE);
            delete_option(self::OPT_POS);
            delete_option(self::OPT_TOTAL);
            return ['ok' => true, 'message' => 'Sitemap already indexed. No new URLs found.'];
        }

        // (Re)queue and reset pointer
        update_option(self::OPT_QUEUE, $urls, false);
        update_option(self::OPT_POS, 0, false);
        update_option(self::OPT_TOTAL, count($urls), false);

        if (function_exists('sturdychat_schedule_sitemap_worker')) {
            sturdychat_schedule_sitemap_worker();
        }
        return ['ok' => true, 'message' => 'Queued ' . count($urls) . ' new URLs for background indexing.'];
    }

    /**
     * Collect URLs from the configured sitemap that are not yet stored in the sitemap chunk table.
     *
     * @param array $s Plugin settings.
     * @return array{ok:bool,message:string,urls:array,skipped:int}
     */
    public static function findUnindexedUrls(array $s): array
    {
        $root = $s['sitemap_url'] ?? home_url('/sitemap_index.xml');

        $childSitemaps = self::fetchSitemapIndex($root);
        if (empty($childSitemaps)) {
            return ['ok' => false, 'message' => 'No child sitemaps parsed at ' . $root . '.', 'urls' => [], 'skipped' => 0];
        }

        $urls = [];
        foreach ($childSitemaps as $child) {
            $rows = self::fetchSitemapUrls($child);
            foreach ($rows as $r) {
                $loc = trim((string) ($r['loc'] ?? ''));
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return ['ok' => false, 'message' => 'Child sitemaps had no URLs.', 'urls' => [], 'skipped' => 0];
        }

        // Skip URLs that are already indexed in the sitemap chunk table.
        global $wpdb;
        $table    = STURDYCHAT_TABLE_SITEMAP;
        $existing = [];

        foreach (array_chunk($urls, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
            $sql          = "SELECT DISTINCT url FROM {$table} WHERE url IN ($placeholders)";
            $found        = $wpdb->get_col($wpdb->prepare($sql, $chunk));
            if (!empty($found)) {
                $existing = array_merge($existing, $found);
            }
        }

        if (!empty($existing)) {
            $urls = array_values(array_diff($urls, array_unique($existing)));
        }

        $skipListRaw = (array) get_option('sturdychat_skipped_sitemap_urls', []);
        $skipList    = [];
        foreach ($skipListRaw as $skipUrl) {
            $clean = esc_url_raw((string) $skipUrl);
            if ($clean !== '') {
                $skipList[] = $clean;
            }
        }
        $skipList     = array_values(array_unique($skipList));
        $skippedCount = 0;

        if (!empty($skipList)) {
            $skippedCount = count(array_intersect($urls, $skipList));
            if ($skippedCount > 0) {
                $urls = array_values(array_diff($urls, $skipList));
            }
        }

        if (empty($urls)) {
            $message = 'Sitemap already indexed. No new URLs found.';
            if ($skippedCount > 0) {
                $message .= ' ' . $skippedCount . ' URLs are currently ignored.';
            }

            return ['ok' => true, 'message' => $message, 'urls' => [], 'skipped' => $skippedCount];
        }

        $message = 'Found ' . count($urls) . ' unindexed URLs.';
        if ($skippedCount > 0) {
            $message .= ' ' . $skippedCount . ' URLs are currently ignored.';
        }

        return ['ok' => true, 'message' => $message, 'urls' => $urls, 'skipped' => $skippedCount];
    }

    /**
     * Cron worker: process a small batch each run. Automatically re-schedules until done.
     */
    /**
     * Process a small batch from the sitemap queue.
     *
     * - Uses a lock to avoid concurrency.
     * - Persists POS and TOTAL so progress can be inspected via wp option get.
     * - Reschedules itself until the queue is done.
     * - Cleans up options and stores a last_done timestamp when finished.
     *
     * @param int $batchSize Number of URLs to process in this run.
     * @return void
     */
    public static function workBatch(int $batchSize = 50): void
    {
        // Acquire a short-lived lock (e.g., 5 minutes)
        if (!self::acquireLock(300)) {
            return;
        }

        try {
            $settings = get_option('sturdychat_settings', []);
            $queue    = (array) get_option(self::OPT_QUEUE, []);
            $total    = (int) get_option(self::OPT_TOTAL, 0);
            $pos      = (int) get_option(self::OPT_POS, 0);

            // Initialize total the first time if missing
            if ($total <= 0) {
                $total = count($queue);
                update_option(self::OPT_TOTAL, $total, false);
            }

            // Nothing to do / already finished
            if ($pos >= $total || $pos >= count($queue)) {
                delete_option(self::OPT_QUEUE);
                delete_option(self::OPT_POS);
                delete_option(self::OPT_TOTAL);
                update_option('sturdychat_sitemap_last_done', current_time('mysql'), false);
                return;
            }

            // Compute slice and process
            $batchSize = max(1, (int) $batchSize);
            $slice     = array_slice($queue, $pos, $batchSize);

            foreach ($slice as $url) {
                try {
                    // Your existing single-URL pipeline:
                    // - fetch HTML
                    // - extract title/content/json-ld/dates
                    // - chunk content
                    // - compute content_hash
                    // - skip if unchanged
                    // - else: embed & insert rows into STURDYCHAT_TABLE_SITEMAP
                    self::indexOneUrl((string) $url, $settings);
                } catch (\Throwable $e) {
                    error_log('[SturdyChat] sitemap index error ' . $url . ': ' . $e->getMessage());
                    // Optional: keep a rolling last_error for debugging
                    update_option('sturdychat_sitemap_last_error', '[' . current_time('mysql') . '] ' . $url . ' :: ' . $e->getMessage(), false);
                }

                // Advance pointer and persist progress each URL
                $pos++;
                update_option(self::OPT_POS, $pos, false);

                // Gentle throttle between requests (filterable)
                $sleepUs = (int) apply_filters('sturdychat_sitemap_usleep_between', 150000); // 150ms
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }

            // More work left? Reschedule a single event shortly.
            if ($pos < $total && $pos < count($queue)) {
                if (function_exists('sturdychat_schedule_sitemap_worker')) {
                    // If you have a helper, allow a delay parameter (default 60s)
                    sturdychat_schedule_sitemap_worker(60);
                } else {
                    // Fallback: schedule directly if not already queued
                    if (!wp_next_scheduled('sturdychat_sitemap_worker')) {
                        wp_schedule_single_event(time() + 60, 'sturdychat_sitemap_worker');
                    }
                }
            } else {
                // Done: clean up and mark completion
                delete_option(self::OPT_QUEUE);
                delete_option(self::OPT_POS);
                delete_option(self::OPT_TOTAL);
                update_option('sturdychat_sitemap_last_done', current_time('mysql'), false);
            }
        } finally {
            self::releaseLock();
        }
    }

//    public static function workBatch(int $batchSize = 8): void
//    {
//        if (!self::acquireLock(60)) {
//            return;
//        }
//
//        try {
//            $s     = get_option('sturdychat_settings', []);
//            $queue = (array) get_option(self::OPT_QUEUE, []);
//            $pos   = (int) get_option(self::OPT_POS, 0);
//
//            if ($pos >= count($queue)) {
//                delete_option(self::OPT_QUEUE);
//                delete_option(self::OPT_POS);
//                return;
//            }
//
//            $slice = array_slice($queue, $pos, max(1, $batchSize));
//            foreach ($slice as $url) {
//                try {
//                    // Your existing “index single URL” logic should:
//                    // - fetch HTML
//                    // - extract title/content/json-ld/dates
//                    // - chunk content
//                    // - compute content_hash
//                    // - if unchanged: skip
//                    // - else: embed and insert rows
//                    self::indexOneUrl($url, $s);
//                } catch (\Throwable $e) {
//                    error_log('[SturdyChat] sitemap index error ' . $url . ': ' . $e->getMessage());
//                }
//                $pos++;
//                update_option(self::OPT_POS, $pos, false);
//            }
//
//            if ($pos < count($queue) && function_exists('sturdychat_schedule_sitemap_worker')) {
//                sturdychat_schedule_sitemap_worker();
//            }
//
//        } finally {
//            self::releaseLock();
//        }
//    }

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
//    public static function indexAll(array $s): array
//    {
//        $root = trim((string) ($s['sitemap_url'] ?? home_url('/sitemap_index.xml')));
//        if ($root === '') {
//            return ['ok' => false, 'message' => 'Sitemap URL is empty. Set it in Settings.'];
//        }
//
//        $children = self::fetchSitemapIndex($root);
//        if (empty($children)) {
//            return [
//                'ok'      => false,
//                'message' => 'No child sitemaps parsed at ' . $root . ' (check XML namespace/HTTP).',
//            ];
//        }
//
//        $totalUrls = 0;
//        $indexed   = 0;
//        $skipped   = 0;
//        $errors    = 0;
//
//        foreach ($children as $childUrl) {
//            $urls = self::fetchSitemapUrls($childUrl);
//            $totalUrls += count($urls);
//
//            foreach ($urls as $u) {
//                $ok = self::indexUrl($u['loc'], $u['lastmod'] ?? null, $s);
//                if ($ok === true) {
//                    $indexed++;
//                } elseif ($ok === null) {
//                    $skipped++; // unchanged or no meaningful content
//                } else {
//                    $errors++;
//                }
//            }
//        }
//
//        return [
//            'ok'      => true,
//            'message' => sprintf(
//                'Sitemap indexed: %d pages from %d child sitemaps (indexed=%d, skipped=%d, errors=%d).',
//                $totalUrls,
//                count($children),
//                $indexed,
//                $skipped,
//                $errors
//            ),
//        ];
//    }

    /**
     * Parse a Yoast sitemap index into an array of child sitemap URLs.
     *
     * @param string $url
     * @return string[]
     */
    private static function fetchSitemapIndex(string $url): array
    {
        $body = self::fetchBody($url);
        if (!$body) {
            return [];
        }

        // Probeer eerst XML + namespace
        $xml = self::loadXml($body);
        if ($xml) {
            $ns = $xml->getNamespaces(true);
            if (isset($ns[''])) {
                $xml->registerXPathNamespace('sm', $ns['']);
            } elseif (isset($ns['sm'])) {
                $xml->registerXPathNamespace('sm', $ns['sm']);
            } else {
                $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            }
            $locs = $xml->xpath('//sm:sitemapindex/sm:sitemap/sm:loc');
            if (empty($locs)) {
                $locs = $xml->xpath('//sitemapindex/sitemap/loc');
            }
            $out = [];
            foreach ($locs as $n) {
                $out[] = trim((string) $n);
            }
            if (!empty($out)) {
                return array_values(array_filter(array_unique($out)));
            }
        }

        // Fallback: ruwe regex (werkt ook als er HTML omheen staat)
        if (stripos($body, '<sitemapindex') !== false) {
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $body, $m);
            if (!empty($m[1])) {
                $candidates = array_map('trim', $m[1]);
                $candidates = array_values(array_filter($candidates, static function ($u) {
                    // Alleen child sitemaps, geen images/news alt.
                    return (bool) preg_match('#-sitemap(?:\d*)?\.xml$#i', $u);
                }));
                if (!empty($candidates)) {
                    return array_values(array_unique($candidates));
                }
            }
        }

        error_log('[SturdyChat] No child sitemaps parsed from body (first 400 chars): ' . substr($body, 0, 400));
        return [];
    }


    /**
     * Parse a child sitemap into an array of page URLs (and optional lastmod).
     *
     * @param string $url
     * @return array<int, array{loc:string,lastmod:?string}>
     */
    private static function fetchSitemapUrls(string $url): array
    {
        $body = self::fetchBody($url);
        if (!$body) {
            return [];
        }

        $out = [];

        // XML-pad
        $xml = self::loadXml($body);
        if ($xml) {
            $ns = $xml->getNamespaces(true);
            if (isset($ns[''])) {
                $xml->registerXPathNamespace('sm', $ns['']);
            } elseif (isset($ns['sm'])) {
                $xml->registerXPathNamespace('sm', $ns['sm']);
            } else {
                $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            }

            $nodes = $xml->xpath('//sm:urlset/sm:url');
            if (empty($nodes)) {
                $nodes = $xml->xpath('//urlset/url');
            }
            foreach ($nodes as $n) {
                $loc = trim((string) ($n->loc ?? ''));
                if ($loc === '') {
                    continue;
                }
                $lastmod = isset($n->lastmod) ? trim((string) $n->lastmod) : null;
                $out[]   = ['loc' => $loc, 'lastmod' => $lastmod];
            }
            if (!empty($out)) {
                return $out;
            }
        }

        // Regex fallback (pakt <url><loc>…</loc><lastmod>…</lastmod></url>)
        if (stripos($body, '<urlset') !== false) {
            // Eerst alle <url> blokken zoeken
            if (preg_match_all('#<url\b[^>]*>(.*?)</url>#is', $body, $urlBlocks)) {
                foreach ($urlBlocks[1] as $blk) {
                    if (preg_match('#<loc>\s*(.*?)\s*</loc>#i', $blk, $m1)) {
                        $loc = trim($m1[1]);
                        $lastmod = null;
                        if (preg_match('#<lastmod>\s*(.*?)\s*</lastmod>#i', $blk, $m2)) {
                            $lastmod = trim($m2[1]);
                        }
                        $out[] = ['loc' => $loc, 'lastmod' => $lastmod];
                    }
                }
            }
        }

        if (empty($out)) {
            error_log('[SturdyChat] No URLs parsed from ' . $url . ' (first 400 chars): ' . substr($body, 0, 400));
        }
        return $out;
    }

    /**
     * Fetch a URL and return SimpleXMLElement (or null on failure).
     *
     * @param string $url
     * @return ?\SimpleXMLElement
     */
    private static function fetchBody(string $url): ?string
    {
        $res = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 5,
            'headers'     => [
                'Accept'     => 'application/xml, text/xml;q=0.9, */*;q=0.8',
                'User-Agent' => 'SturdyChat/1.0 (+WordPress)',
            ],
            'sslverify'   => apply_filters('sturdychat_sitemap_sslverify', is_ssl()),
        ]);
        if (is_wp_error($res)) {
            error_log('[SturdyChat] fetchBody error: ' . $res->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            error_log('[SturdyChat] fetchBody HTTP ' . $code . ' for ' . $url);
            return null;
        }
        $body = (string) wp_remote_retrieve_body($res);
        // Trim UTF-8 BOM en leading whitespace
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $body = ltrim($body);
        // Kleine sanity log voor debugging
        error_log('[SturdyChat] fetchBody len=' . strlen($body) . ' url=' . $url);
        return $body !== '' ? $body : null;
    }

    private static function loadXml(string $body): ?\SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        return ($xml instanceof \SimpleXMLElement) ? $xml : null;
    }

    /**
     * Fetch, extract, chunk, embed and store a single page into STURDYCHAT_TABLE_SITEMAP.
     *
     * @param string      $url
     * @param string|null $lastmod
     * @param array       $s
     * @return bool|null true=inserted, null=skipped (unchanged/no content), false=error
     */
    private static function indexUrl(string $url, ?string $lastmod, array $s): ?bool
    {
        $res = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 5,
            'headers'     => ['User-Agent' => 'SturdyChat/1.0 (+WordPress)'],
            'sslverify'   => apply_filters('sturdychat_sitemap_sslverify', is_ssl()),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 400) {
            return false;
        }

        $html = (string) wp_remote_retrieve_body($res);
        if ($html === '') {
            return null;
        }

        // Parse HTML
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // Title
        $title = '';
        $nTitle = $xp->query('//title');
        if ($nTitle && $nTitle->length) {
            $title = trim($nTitle->item(0)->textContent);
        } else {
            $og = $xp->query('//meta[@property="og:title"]/@content');
            if ($og && $og->length) {
                $title = trim((string) $og->item(0)->nodeValue);
            }
        }

        // Main content (theme-specific first, then fallbacks)
        $content = self::firstText($xp, '//main[contains(@class,"main-content")]')
            ?? self::firstText($xp, '//article')
            ?? self::firstText($xp, '//div[@id="content"]')
            ?? '';

        // JSON-LD (Yoast or generic)
        $jsonld = '';
        $yoast = $xp->query('//script[@type="application/ld+json" and contains(@class, "yoast-schema-graph")]');
        if ($yoast && $yoast->length) {
            $jsonld = trim((string) $yoast->item(0)->textContent);
        } else {
            $anyLd = $xp->query('//script[@type="application/ld+json"]');
            if ($anyLd && $anyLd->length) {
                $jsonld = trim((string) $anyLd->item(0)->textContent);
            }
        }

        // Dates from JSON-LD (fallback to <lastmod> from sitemap)
        $publishedAt = null;
        $modifiedAt  = null;
        if ($jsonld !== '') {
            $decoded = json_decode($jsonld, true);
            if (is_array($decoded)) {
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $node) {
                        if (!is_array($node)) {
                            continue;
                        }
                        if (isset($node['@type']) && in_array($node['@type'], ['Article', 'NewsArticle', 'WebPage'], true)) {
                            $publishedAt = $publishedAt ?: ($node['datePublished'] ?? null);
                            $modifiedAt  = $modifiedAt  ?: ($node['dateModified']  ?? null);
                        }
                    }
                } else {
                    $publishedAt = $decoded['datePublished'] ?? null;
                    $modifiedAt  = $decoded['dateModified']  ?? null;
                }
            }
        }
        if (!$publishedAt && $lastmod) {
            $publishedAt = $lastmod;
        }

        // CPT guess (first path segment)
        $cpt = self::guessCpt($url);

        $plain = wp_strip_all_tags($content);
        if ($plain === '') {
            return null;
        }

        global $wpdb;
        $table      = STURDYCHAT_TABLE_SITEMAP;
        $chunkChars = max(400, (int) ($s['chunk_chars'] ?? 1200));
        $chunks     = self::chunkText($plain, $chunkChars);
        $hash       = hash('sha256', $plain);
        $now        = current_time('mysql');

        // Unchanged? (by URL + hash)
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE url=%s AND content_hash=%s",
            $url,
            $hash
        ));
        if ($exists > 0) {
            return null;
        }

        // Replace previous rows for this URL
        $wpdb->delete($table, ['url' => $url]);

        foreach ($chunks as $i => $chunk) {
            $vec = SturdyChat_Embedder::embed($chunk, $s);
            $wpdb->insert(
                $table,
                [
                    'url'          => $url,
                    'cpt'          => $cpt,
                    'title'        => $title,
                    'chunk_index'  => $i,
                    'content'      => $chunk,
                    'embedding'    => wp_json_encode($vec),
                    'published_at' => $publishedAt ? gmdate('Y-m-d H:i:s', strtotime($publishedAt)) : null,
                    'modified_at'  => $modifiedAt  ? gmdate('Y-m-d H:i:s', strtotime($modifiedAt))  : null,
                    'updated_at'   => $now,
                    'content_hash' => $hash,
                    'jsonld'       => $jsonld,
                ],
                ['%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s']
            );
        }

        return true;
    }

    private static function firstText(\DOMXPath $xp, string $xpath): ?string
    {
        $nodes = $xp->query($xpath);
        if ($nodes && $nodes->length) {
            return trim((string) $nodes->item(0)->textContent);
        }
        return null;
    }

    private static function guessCpt(string $url): string
    {
        $p = parse_url($url, PHP_URL_PATH) ?? '/';
        $p = trim((string) $p, '/');
        if ($p === '') {
            return 'page';
        }
        $parts = explode('/', $p);
        return sanitize_title($parts[0] ?? 'page');
    }

    private static function chunkText(string $text, int $maxChars): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        $chunks = [];
        $buf = '';
        foreach ($sentences as $s) {
            if (mb_strlen($buf . ' ' . $s) > $maxChars && $buf !== '') {
                $chunks[] = trim($buf);
                $buf = $s;
            } else {
                $buf = $buf ? ($buf . ' ' . $s) : $s;
            }
        }
        if ($buf !== '') {
            $chunks[] = trim($buf);
        }
        return $chunks;
    }
    /**
     * Thin wrapper so workBatch() can call a single-URL pipeline.
     *
     * @param string $url
     * @param array  $settings
     * @return void
     */
    private static function indexOneUrl(string $url, array $settings): void
    {
        // You currently don't store lastmod in the queue, so pass null here.
        // If you later switch the queue to keep ['loc' => ..., 'lastmod' => ...],
        // you can thread lastmod through.
        self::indexUrl($url, null, $settings);
    }
}
