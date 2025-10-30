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
}
