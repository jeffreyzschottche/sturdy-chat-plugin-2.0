<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_Debugger_ShowQueryEmbedding
{
    public static function logRetrieval(array $data): void
    {
        SturdyChat_Debugger::log('show_query_embedding', 'query', $data);
    }

    /**
     * @param array<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function summarizeCandidates(array $rows, int $limit = 25): array
    {
        $rows = array_slice($rows, 0, $limit);
        $summary = [];
        foreach ($rows as $row) {
            $summary[] = [
                'title'        => $row['title'] ?? '',
                'url'          => $row['url'] ?? '',
                'bm25'         => $row['bm25'] ?? null,
                'cosine'       => $row['cos'] ?? null,
                'final_score'  => $row['final'] ?? null,
                'cpt'          => $row['cpt'] ?? '',
                'hub'          => !empty($row['_hub']),
                'cpt_match'    => !empty($row['_cpt_match']),
                'content_snip' => mb_substr((string) ($row['content'] ?? ''), 0, 180),
            ];
        }
        return $summary;
    }
}
