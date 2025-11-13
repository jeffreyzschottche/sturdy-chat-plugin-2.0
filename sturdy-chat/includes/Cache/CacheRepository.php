<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Cache_Repository
{
    public static function findRowByHash(string $hash): ?array
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
    public static function getCandidatesByLength(int $minLen, int $maxLen): array
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

    public static function findExistingIdByHash(string $hash): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . STURDYCHAT_TABLE_CACHE . " WHERE normalized_hash = %s LIMIT 1",
                $hash
            )
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $format
     */
    public static function upsert(array $data, array $format, int $existingId): void
    {
        global $wpdb;

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
     * @return array<int,array{id:int,sources:?string}>
     */
    public static function rowsWithSources(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, sources FROM " . STURDYCHAT_TABLE_CACHE . " WHERE sources <> ''",
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,int> $ids
     */
    public static function deleteIds(array $ids): void
    {
        if (!$ids) {
            return;
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . STURDYCHAT_TABLE_CACHE . " WHERE id IN ($placeholders)",
                $ids
            )
        );
    }
}
