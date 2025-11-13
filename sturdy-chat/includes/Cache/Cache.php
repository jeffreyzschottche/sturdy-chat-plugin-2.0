<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/CacheNormalizer.php';
require_once __DIR__ . '/CacheRepository.php';
require_once __DIR__ . '/CacheSources.php';
require_once __DIR__ . '/CacheKeys.php';

/**
 * Lightweight cache for previously answered chat questions.
 */
class SturdyChat_Cache
{
    private const SIMILARITY_THRESHOLD = 0.99;

    /**
     * Attempt to return a cached answer for the given question.
     *
     * @param string $question User question to search for in the cache.
     * @return array{answer:string,sources:array<int,array{title:string,url:string,score:float}>}|null
     */
    public static function find(string $question): ?array
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return null;
        }

        $normalized = SturdyChat_Cache_Normalizer::normalizeQuestion($question);
        if ($normalized === '') {
            return null;
        }

        $hash = SturdyChat_Cache_Normalizer::hash($normalized);

        $row = SturdyChat_Cache_Repository::findRowByHash($hash);
        if ($row) {
            return self::formatResult($row);
        }

        $length = SturdyChat_Cache_Normalizer::length($normalized);
        $minLen = max(1, $length - 2);
        $maxLen = $length + 2;

        $candidates = SturdyChat_Cache_Repository::getCandidatesByLength($minLen, $maxLen);
        foreach ($candidates as $candidate) {
            $normalizedCandidate = (string) ($candidate['normalized_question'] ?? '');
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
     * @param string                                      $question Question string.
     * @param string                                      $answer   Answer text to cache.
     * @param array<int, array{title?:string,url?:string,score?:float}> $sources
     */
    public static function store(string $question, string $answer, array $sources = []): void
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return;
        }

        $question = trim((string) $question);
        $answer = trim((string) $answer);
        if ($question === '' || $answer === '') {
            return;
        }

        $normalized = SturdyChat_Cache_Normalizer::normalizeQuestion($question);
        if ($normalized === '') {
            return;
        }

        $hash = SturdyChat_Cache_Normalizer::hash($normalized);
        $encodedSources = SturdyChat_Cache_Sources::encode($sources);

        $existingId = SturdyChat_Cache_Repository::findExistingIdByHash($hash);

        $data = [
            'question'            => $question,
            'normalized_question' => $normalized,
            'normalized_hash'     => $hash,
            'answer'              => $answer,
            'sources'             => $encodedSources,
            'created_at'          => current_time('mysql', true),
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%s'];

        SturdyChat_Cache_Repository::upsert($data, $format, $existingId);
    }

    /**
     * Provide normalized question and hash for reuse outside the cache class.
     *
     * @param string $question Question text to normalise.
     * @return array{normalized:string,hash:string}
     */
    public static function normalizeForStorage(string $question): array
    {
        $normalized = SturdyChat_Cache_Normalizer::normalizeQuestion($question);

        if ($normalized === '') {
            return ['normalized' => '', 'hash' => ''];
        }

        return [
            'normalized' => $normalized,
            'hash'       => SturdyChat_Cache_Normalizer::hash($normalized),
        ];
    }

    /**
     * Remove cached answers whose sources include any of the provided URLs.
     *
     * @param array<int,string> $urls  Absolute URLs to search for.
     * @param array<int,string> $paths Normalised path strings to search for.
     * @return int Number of cache entries removed.
     */
    public static function purgeBySourceUrls(array $urls, array $paths = []): int
    {
        if (!defined('STURDYCHAT_TABLE_CACHE')) {
            return 0;
        }

        $targets = SturdyChat_Cache_Keys::buildTargets($urls, $paths);
        if (!$targets) {
            return 0;
        }

        $rows = SturdyChat_Cache_Repository::rowsWithSources();
        if (!$rows) {
            return 0;
        }

        $deleteIds = [];
        foreach ($rows as $row) {
            $sources = SturdyChat_Cache_Sources::decode((string) ($row['sources'] ?? ''));
            if (!$sources) {
                continue;
            }
            foreach ($sources as $src) {
                $keys = SturdyChat_Cache_Keys::extract((string) ($src['url'] ?? ''));
                if ($keys && SturdyChat_Cache_Keys::matchAny($keys, $targets)) {
                    $deleteIds[] = (int) ($row['id'] ?? 0);
                    break;
                }
            }
        }

        if (!$deleteIds) {
            return 0;
        }

        SturdyChat_Cache_Repository::deleteIds($deleteIds);

        return count($deleteIds);
    }

    /**
     * Sanitize raw JSON input from the editor and return a normalized JSON string for storage.
     *
      * @param string $raw Raw JSON string from the editor.
     * @return array{ok:bool,value:string,message?:string}
     */
    public static function normalizeSourcesInput(string $raw): array
    {
        return SturdyChat_Cache_Sources::normalizeInput($raw);
    }

    /**
     * Pretty print sources JSON for the admin editor.
     *
     * @param string $sources Raw sources JSON stored in the DB.
     * @return string Pretty-printed JSON string.
     */
    public static function formatSourcesForEditor(string $sources): string
    {
        return SturdyChat_Cache_Sources::formatForEditor($sources);
    }

    /**
     * @return array{answer:string,sources:array<int,array{title:string,url:string,score:float}>}
     */
    private static function formatResult(array $row): array
    {
        return [
            'answer'  => (string) ($row['answer'] ?? ''),
            'sources' => SturdyChat_Cache_Sources::decode($row['sources'] ?? ''),
        ];
    }
}
