<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight cache for previously answered chat questions.
 */
class SturdyChat_Cache
{
    private const SIMILARITY_THRESHOLD = 0.99;

    /**
     * Attempts to return a cached answer for the given question.
     *
     * @return array{answer:string,sources:array<int,array{title:string,url:string,score:float}>}|null
     */
    public static function find(string $question): ?array
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return null;
        }

        $normalized = self::normalizeQuestion($question);
        if ($normalized === '') {
            return null;
        }

        $hash = self::hashNormalized($normalized);

        $row = self::getRowByHash($hash);
        if ($row) {
            return self::formatResult($row);
        }

        $length = self::stringLength($normalized);
        $minLen = max(1, $length - 2);
        $maxLen = $length + 2;

        $candidates = self::getCandidatesByLength($minLen, $maxLen);
        foreach ($candidates as $candidate) {
            $normalizedCandidate = (string)($candidate['normalized_question'] ?? '');
            if ($normalizedCandidate === '') {
                continue;
            }
            $percent = 0.0;
            similar_text($normalized, $normalizedCandidate, $percent);
            if ($percent >= self::SIMILARITY_THRESHOLD * 100) {
                return self::formatResult($candidate);
            }
        }

        return null;
    }

    /**
     * Persist an answer so it can be reused for near-identical future questions.
     *
     * @param array<int, array{title?:string,url?:string,score?:float}> $sources
     */
    public static function store(string $question, string $answer, array $sources = []): void
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return;
        }

        $question = trim((string)$question);
        $answer = trim((string)$answer);
        if ($question === '' || $answer === '') {
            return;
        }

        $normalized = self::normalizeQuestion($question);
        if ($normalized === '') {
            return;
        }

        $hash = self::hashNormalized($normalized);
        $encodedSources = self::encodeSources($sources);

        global $wpdb;

        $existingId = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . STURDYCHAT_TABLE_CACHE . " WHERE normalized_hash = %s LIMIT 1",
                $hash
            )
        );

        $data = [
            'question'             => $question,
            'normalized_question'  => $normalized,
            'normalized_hash'      => $hash,
            'answer'               => $answer,
            'sources'              => $encodedSources,
            'created_at'           => current_time('mysql', true),
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%s'];

        if ($existingId > 0) {
            $wpdb->update(
                STURDYCHAT_TABLE_CACHE,
                $data,
                ['id' => $existingId],
                $format,
                ['%d']
            );
        } else {
            $wpdb->insert(
                STURDYCHAT_TABLE_CACHE,
                $data,
                $format
            );
        }
    }

    /**
     * Provide normalized question and hash for reuse outside the cache class.
     *
     * @return array{normalized:string,hash:string}
     */
    public static function normalizeForStorage(string $question): array
    {
        $normalized = self::normalizeQuestion($question);

        if ($normalized === '') {
            return ['normalized' => '', 'hash' => ''];
        }

        return [
            'normalized' => $normalized,
            'hash'       => self::hashNormalized($normalized),
        ];
    }

    /**
     * Remove cached answers whose sources include any of the provided URLs.
     *
     * @param array<int, string> $urls
     * @param array<int, string> $paths
     * @return int Number of cache entries removed.
     */
    public static function purgeBySourceUrls(array $urls, array $paths = []): int
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return 0;
        }

        $targets = self::buildTargetKeys($urls, $paths);

        if (!$targets) {
            return 0;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, sources FROM " . STURDYCHAT_TABLE_CACHE . " WHERE sources <> ''",
            ARRAY_A
        );
        if (!$rows) {
            return 0;
        }

        $deleteIds = [];
        foreach ($rows as $row) {
            $sources = self::decodeSources((string) ($row['sources'] ?? ''));
            if (!$sources) {
                continue;
            }
            foreach ($sources as $src) {
                $keys = self::extractSourceKeys((string) ($src['url'] ?? ''));
                if ($keys && self::matchAnyTarget($keys, $targets)) {
                    $deleteIds[] = (int) ($row['id'] ?? 0);
                    break;
                }
            }
        }

        if (!$deleteIds) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($deleteIds), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . STURDYCHAT_TABLE_CACHE . " WHERE id IN ($placeholders)",
                $deleteIds
            )
        );

        return count($deleteIds);
    }

    /**
     * Sanitize raw JSON input from the editor and return a normalized JSON string for storage.
     *
     * @return array{ok:bool,value:string,message?:string}
     */
    public static function normalizeSourcesInput(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => true, 'value' => ''];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $error = 'JSON decode error';
            if (function_exists('json_last_error_msg')) {
                $error = json_last_error_msg();
            }
            return [
                'ok'      => false,
                'value'   => '',
                'message' => sprintf(__('Invalid sources JSON: %s', 'sturdychat-chatbot'), $error),
            ];
        }

        $normalized = [];
        foreach ($decoded as $src) {
            if (!is_array($src)) {
                continue;
            }

            $item = [
                'title' => (string) ($src['title'] ?? ''),
                'url'   => (string) ($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float) $src['score'] : 0.0,
            ];

            if ($item['title'] === '' && $item['url'] === '' && 0.0 === $item['score']) {
                continue;
            }

            $normalized[] = $item;
        }

        if (!$normalized) {
            return ['ok' => true, 'value' => ''];
        }

        $flags = 0;
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_PRETTY_PRINT')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = function_exists('wp_json_encode')
            ? wp_json_encode($normalized, $flags)
            : json_encode($normalized, $flags);

        if (!is_string($json)) {
            return [
                'ok'      => false,
                'value'   => '',
                'message' => __('Unable to encode sources as JSON.', 'sturdychat-chatbot'),
            ];
        }

        return ['ok' => true, 'value' => $json];
    }

    /**
     * Pretty print sources JSON for the admin editor.
     */
    public static function formatSourcesForEditor(string $sources): string
    {
        $sources = trim($sources);
        if ($sources === '') {
            return '';
        }

        $result = self::normalizeSourcesInput($sources);
        if ($result['ok']) {
            return $result['value'];
        }

        return $sources;
    }

    private static function normalizeQuestion(string $question): string
    {
        $question = wp_strip_all_tags($question);
        $question = html_entity_decode((string)$question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $question = preg_replace('/[[:punct:]]+/u', ' ', (string)$question);
        $question = preg_replace('/[[:space:]]+/u', ' ', (string)$question);
        $question = trim((string)$question);

        if ($question === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $question = mb_strtolower($question, 'UTF-8');
        } else {
            $question = strtolower($question);
        }

        return $question;
    }

    private static function hashNormalized(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    private static function getRowByHash(string $hash): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, answer, sources, normalized_question FROM " . STURDYCHAT_TABLE_CACHE . " WHERE normalized_hash = %s ORDER BY id DESC LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array{id:int,answer:string,sources:?string,normalized_question:string}>
     */
    private static function getCandidatesByLength(int $minLen, int $maxLen): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, answer, sources, normalized_question FROM " . STURDYCHAT_TABLE_CACHE . " WHERE CHAR_LENGTH(normalized_question) BETWEEN %d AND %d ORDER BY id DESC LIMIT 25",
                $minLen,
                $maxLen
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{answer:string,sources:array<int,array{title:string,url:string,score:float}>}
     */
    private static function formatResult(array $row): array
    {
        return [
            'answer'  => (string)($row['answer'] ?? ''),
            'sources' => self::decodeSources($row['sources'] ?? ''),
        ];
    }

    /**
     * @param array<int, array{title?:string,url?:string,score?:float}> $sources
     */
    private static function encodeSources(array $sources): string
    {
        if (!$sources) {
            return '';
        }

        $normalized = [];
        foreach ($sources as $src) {
            if (!is_array($src)) {
                continue;
            }
            $normalized[] = [
                'title' => (string)($src['title'] ?? ''),
                'url'   => (string)($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float)$src['score'] : 0.0,
            ];
        }

        if (!$normalized) {
            return '';
        }

        if (function_exists('wp_json_encode')) {
            return (string)wp_json_encode($normalized);
        }

        return (string)json_encode($normalized);
    }

    /**
     * @return array<int, array{title:string,url:string,score:float}>
     */
    private static function decodeSources(string $sources): array
    {
        if ($sources === '') {
            return [];
        }

        $decoded = json_decode($sources, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $src) {
            if (!is_array($src)) {
                continue;
            }
            $normalized[] = [
                'title' => (string)($src['title'] ?? ''),
                'url'   => (string)($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float)$src['score'] : 0.0,
            ];
        }

        return $normalized;
    }

    private static function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private static function buildTargetKeys(array $urls, array $paths): array
    {
        $targets = [];

        foreach ($urls as $url) {
            foreach (self::extractSourceKeys((string) $url) as $key) {
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
    private static function matchAnyTarget(array $keys, array $targets): bool
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
    private static function extractSourceKeys(string $url): array
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
