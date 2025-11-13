<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_Debugger_ShowIndexEmbedding
{
    public static function logChunk(array $data): void
    {
        SturdyChat_Debugger::log('show_index_embedding', 'index', [
            'post_id'      => $data['post_id'] ?? null,
            'chunk_index'  => $data['chunk_index'] ?? null,
            'chunk_length' => isset($data['chunk']) ? mb_strlen((string) $data['chunk']) : 0,
            'chunk'        => $data['chunk'] ?? '',
            'embedding'    => $data['embedding'] ?? [],
            'hash'         => $data['hash'] ?? '',
            'timestamp'    => current_time('mysql'),
        ]);
    }
}
