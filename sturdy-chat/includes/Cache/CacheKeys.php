<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Cache_Keys
{
    /**
     * @param array<int,string> $urls
     * @param array<int,string> $paths
     * @return array<string,bool>
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
     * @param string[] $keys
     * @param array<string,bool> $targets
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
     * @return array<int,string>
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
