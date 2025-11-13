<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_SitemapIndexer_Fetcher
{
    /**
     * @return string[]
     */
    public static function fetchSitemapIndex(string $url): array
    {
        $body = self::fetchBody($url);
        if (!$body) {
            return [];
        }

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

            if (!empty($locs)) {
                $urls = [];
                foreach ($locs as $node) {
                    $urls[] = trim((string) $node);
                }
                $urls = array_values(array_filter(array_unique($urls)));
                if ($urls) {
                    return $urls;
                }
            }
        }

        if (stripos($body, '<sitemapindex') !== false) {
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $body, $matches);
            if (!empty($matches[1])) {
                $candidates = array_map('trim', $matches[1]);
                $candidates = array_values(array_filter($candidates, static function (string $candidate): bool {
                    return (bool) preg_match('#-sitemap(?:\d*)?\.xml$#i', $candidate);
                }));
                if ($candidates) {
                    return array_values(array_unique($candidates));
                }
            }
        }

        error_log('[SturdyChat] No child sitemaps parsed from body (first 400 chars): ' . substr($body, 0, 400));
        return [];
    }

    /**
     * @return array<int, array{loc:string,lastmod:?string}>
     */
    public static function fetchSitemapUrls(string $url): array
    {
        $body = self::fetchBody($url);
        if (!$body) {
            return [];
        }

        $out = [];

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
            foreach ($nodes as $node) {
                $loc = trim((string) ($node->loc ?? ''));
                if ($loc === '') {
                    continue;
                }
                $lastmod = isset($node->lastmod) ? trim((string) $node->lastmod) : null;
                $out[]   = ['loc' => $loc, 'lastmod' => $lastmod];
            }
            if ($out) {
                return $out;
            }
        }

        if (stripos($body, '<urlset') !== false) {
            if (preg_match_all('#<url\b[^>]*>(.*?)</url>#is', $body, $urlBlocks)) {
                foreach ($urlBlocks[1] as $block) {
                    if (preg_match('#<loc>\s*(.*?)\s*</loc>#i', $block, $locMatch)) {
                        $loc     = trim($locMatch[1]);
                        $lastmod = null;
                        if (preg_match('#<lastmod>\s*(.*?)\s*</lastmod>#i', $block, $lastmodMatch)) {
                            $lastmod = trim($lastmodMatch[1]);
                        }
                        $out[] = ['loc' => $loc, 'lastmod' => $lastmod];
                    }
                }
            }
        }

        if (!$out) {
            error_log('[SturdyChat] No URLs parsed from ' . $url . ' (first 400 chars): ' . substr($body, 0, 400));
        }

        return $out;
    }

    public static function fetchBody(string $url): ?string
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
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $body = ltrim($body);

        return $body !== '' ? $body : null;
    }

    public static function loadXml(string $body): ?\SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        return ($xml instanceof \SimpleXMLElement) ? $xml : null;
    }
}
