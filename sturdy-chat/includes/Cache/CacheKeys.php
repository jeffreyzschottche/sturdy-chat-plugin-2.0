<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Cache_Keys
{
    /**
     * Build a map of target keys derived from URLs and paths for fast matching.
     *
     * @param array<int,string> $urls  Absolute URLs.
     * @param array<int,string> $paths Normalised paths.
     * @return array<string,bool> Map used for membership checks.
     */
    public static function buildTargets(array $urls, array $paths): array
    {
        $targets = [];

        foreach ($urls as $url) {
            foreach (self::extract((string) $url) as $key) {
                $targets[$key] = true;
            }
        }

        foreach ($paths as $path) {
            $pathKey = self::normalizePathKey((string) $path);
            if ($pathKey !== '') {
                $targets['p|' . $pathKey] = true;
            }
        }

        return $targets;
    }

    /**
     * Check whether any of the provided keys intersect with the target map.
     *
     * @param string[]            $keys    Keys extracted from a source.
     * @param array<string,bool> $targets Target lookup map.
     * @return bool True if there is a match.
     */
    public static function matchAny(array $keys, array $targets): bool
    {
        foreach ($keys as $key) {
            if (isset($targets[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract URL and path keys from a single URL string.
     *
     * @param string $url Source URL.
     * @return array<int,string> List of keys representing URL and path.
     */
    public static function extract(string $url): array
    {
        $keys = [];

        $urlKey = self::normalizeUrlKey($url);
        if ($urlKey !== '') {
            $keys[] = 'u|' . $urlKey;
        }

        $pathKey = self::normalizePathKey($url);
        if ($pathKey !== '') {
            $keys[] = 'p|' . $pathKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Normalise a URL into a consistent key (host + path + sorted query).
     *
     * @param string $url Source URL.
     * @return string Normalised key or empty string when invalid.
     */
    private static function normalizeUrlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = self::stripFragment($url);
        if ($url === '') {
            return '';
        }

        if (function_exists('untrailingslashit')) {
            $url = untrailingslashit($url);
        } else {
            $url = rtrim($url, '/');
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $path = '';
        if (!empty($parts['path'])) {
            $path = self::normalizePathOnly((string) $parts['path']);
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
            if (is_array($qs) && $qs) {
                ksort($qs);
                $query = '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
            }
        }

        return $host . $path . $query;
    }

    /**
     * Derive a normalised path key from a full URL or bare path string.
     *
     * @param string $value Path or full URL.
     * @return string Normalised lowercase path.
     */
    private static function normalizePathKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = self::stripFragment($value);
        if ($value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (is_array($parts) && isset($parts['path'])) {
            $path = (string) $parts['path'];
        } else {
            $path = $value;
        }

        $path = self::normalizePathOnly($path);

        return $path === '' ? '' : strtolower($path);
    }

    private static function normalizePathOnly(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if (function_exists('untrailingslashit')) {
            $path = untrailingslashit($path);
        } else {
            $path = rtrim($path, '/');
        }
        return $path === '/' ? '' : $path;
    }

    private static function stripFragment(string $value): string
    {
        $stripped = preg_replace('/#.*$/', '', $value);
        return is_string($stripped) ? $stripped : '';
    }
}
