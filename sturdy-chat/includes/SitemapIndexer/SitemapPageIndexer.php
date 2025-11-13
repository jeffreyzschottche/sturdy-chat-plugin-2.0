<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_SitemapIndexer_PageIndexer
{
    /**
     * Index a single URL immediately, optionally forcing re-insertion.
     *
     * @param string $url             Canonical URL to index.
     * @param array  $settings        Plugin settings controlling chunking/embedding.
     * @param bool   $force           Whether to skip hash checks and always reinsert.
     * @param array  $knownVariants   Optional url variants to purge beforehand.
     * @return bool|null True when new rows inserted, null if skipped, false on HTTP failure.
     */
    public static function indexSingleUrl(string $url, array $settings, bool $force = false, array $knownVariants = []): ?bool
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $variants = [];
        foreach ($knownVariants as $variant) {
            if (!is_string($variant)) {
                continue;
            }
            $variant = trim($variant);
            if ($variant === '') {
                continue;
            }
            $variants[$variant] = true;
        }
        if (!isset($variants[$url])) {
            $variants[$url] = true;
        }

        return self::indexUrl($url, null, $settings, $force, array_keys($variants));
    }

    /**
     * Convenience wrapper used by the queue worker to process one URL.
     *
     * @param string $url      URL fetched from the queue.
     * @param array  $settings Plugin settings array.
     * @return bool|null Result of {@see indexUrl()}.
     */
    public static function processUrl(string $url, array $settings): ?bool
    {
        return self::indexUrl($url, null, $settings, false, [$url]);
    }

    /**
     * Fetch, extract, chunk, embed, and persist the contents of a single URL.
     *
     * @param string      $url        URL to index.
     * @param string|null $lastmod    Optional last-modified timestamp from sitemap.
     * @param array       $settings   Plugin settings array.
     * @param bool        $force      Whether to reinsert even if hashes match.
     * @param array       $deleteUrls URL variants to purge before inserting.
     * @return bool|null True when inserted, null if skipped, false on fetch error.
     */
    private static function indexUrl(string $url, ?string $lastmod, array $settings, bool $force = false, array $deleteUrls = []): ?bool
    {
        unset($lastmod); // reserved for future use.

        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 5,
            'headers'     => ['User-Agent' => 'SturdyChat/1.0 (+WordPress)'],
            'sslverify'   => apply_filters('sturdychat_sitemap_sslverify', is_ssl()),
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            return false;
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return null;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        $title = '';
        $titleNode = $xp->query('//title');
        if ($titleNode && $titleNode->length > 0) {
            $title = trim((string) $titleNode->item(0)->textContent);
        }
        if ($title === '') {
            $title = (string) $url;
        }

        $jsonldNodes = $xp->query('//script[@type="application/ld+json"]');
        $jsonld = [];
        if ($jsonldNodes && $jsonldNodes->length > 0) {
            foreach ($jsonldNodes as $node) {
                $jsonld[] = trim((string) $node->textContent);
            }
        }
        $jsonld = $jsonld ? wp_json_encode($jsonld) : null;

        $publishedAt = self::firstText($xp, '//meta[@property="article:published_time"]/@content');
        if (!$publishedAt) {
            $publishedAt = self::firstText($xp, '//meta[@name="article:published_time"]/@content');
        }
        $modifiedAt = self::firstText($xp, '//meta[@property="article:modified_time"]/@content');
        if (!$modifiedAt) {
            $modifiedAt = self::firstText($xp, '//meta[@name="article:modified_time"]/@content');
        }

        $contentSelectors = [
            '//main//article',
            '//article',
            '//main',
            '//*[@id="content"]',
        ];
        $content = '';
        foreach ($contentSelectors as $selector) {
            $node = $xp->query($selector);
            if ($node && $node->length > 0) {
                $content = $dom->saveHTML($node->item(0)) ?: '';
                break;
            }
        }
        if ($content === '') {
            $content = $html;
        }

        $cpt = self::guessCpt($url);

        $plain = wp_strip_all_tags($content);
        if ($plain === '') {
            return null;
        }

        global $wpdb;
        $table      = STURDYCHAT_TABLE_SITEMAP;
        $chunkChars = max(400, (int) ($settings['chunk_chars'] ?? 1200));
        $chunks     = self::chunkText($plain, $chunkChars);
        $hash       = hash('sha256', $plain);
        $now        = current_time('mysql');

        $existingRow = $wpdb->get_row($wpdb->prepare(
            "SELECT cpt, content_hash FROM {$table} WHERE url = %s LIMIT 1",
            $url
        ), ARRAY_A);
        if ($existingRow && !$force) {
            $existingHash = (string) ($existingRow['content_hash'] ?? '');
            $existingCpt  = sanitize_key((string) ($existingRow['cpt'] ?? ''));
            if ($existingHash === $hash && $existingCpt === $cpt) {
                return null;
            }
        }

        if (empty($deleteUrls)) {
            $deleteUrls = [$url];
        }
        $deleteMap = [];
        foreach ($deleteUrls as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $deleteMap[$candidate] = true;
        }
        if (!$deleteMap) {
            $deleteMap[$url] = true;
        }
        foreach (array_keys($deleteMap) as $deleteUrl) {
            $wpdb->delete($table, ['url' => $deleteUrl]);
        }

        foreach ($chunks as $index => $chunk) {
            $vector = SturdyChat_Embedder::embed($chunk, $settings);
            if (class_exists('SturdyChat_Debugger') && SturdyChat_Debugger::isEnabled('show_index_embedding')) {
                SturdyChat_Debugger_ShowIndexEmbedding::logChunk([
                    'post_id'     => 0,
                    'chunk_index' => $index,
                    'chunk'       => $chunk,
                    'embedding'   => $vector,
                    'hash'        => $hash,
                    'url'         => $url,
                    'source'      => 'sitemap',
                ]);
            }
            $wpdb->insert(
                $table,
                [
                    'url'          => $url,
                    'cpt'          => $cpt,
                    'title'        => $title,
                    'chunk_index'  => $index,
                    'content'      => $chunk,
                    'embedding'    => wp_json_encode($vector),
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

    /**
     * Helper to extract the first matching text node for a given XPath.
     *
     * @param \DOMXPath $xp    XPath instance to query.
     * @param string    $xpath XPath expression pointing to an attribute or node text.
     * @return string|null Trimmed text content or null when not found.
     */
    private static function firstText(\DOMXPath $xp, string $xpath): ?string
    {
        $nodes = $xp->query($xpath);
        if ($nodes && $nodes->length) {
            return trim((string) $nodes->item(0)->textContent);
        }
        return null;
    }

    /**
     * Guess a CPT slug based on the first segment of the path component in the URL.
     *
     * @param string $url URL whose path should be analysed.
     * @return string Sanitized CPT slug (defaults to "page").
     */
    private static function guessCpt(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $path = trim((string) $path, '/');
        if ($path === '') {
            return 'page';
        }
        $parts = explode('/', $path);
        return sanitize_key($parts[0] ?? 'page');
    }

    /**
     * Split text into sentence-aware chunks limited by character count.
     *
     * @param string $text     Raw text content to chunk.
     * @param int    $maxChars Maximum length of each chunk.
     * @return string[] Array of trimmed chunk strings.
     */
    private static function chunkText(string $text, int $maxChars): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        $chunks = [];
        $buffer = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer . ' ' . $sentence) > $maxChars && $buffer !== '') {
                $chunks[] = trim($buffer);
                $buffer = $sentence;
            } else {
                $buffer = $buffer ? ($buffer . ' ' . $sentence) : $sentence;
            }
        }

        if ($buffer !== '') {
            $chunks[] = trim($buffer);
        }

        return $chunks;
    }
}
