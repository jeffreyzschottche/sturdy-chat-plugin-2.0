<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/RAGChat.php';
require_once __DIR__ . '/RAGRetriever.php';

/**
 * High-level RAG facade used throughout the plugin.
 */
class SturdyChat_RAG
{
    public const FALLBACK_ANSWER = 'Deze informatie bestaat niet in onze huidige kennisbank. Probeer je vraag specifieker te stellen of gebruik andere trefwoorden.';

    public static function fallbackAnswer(array $settings = []): string
    {
        $fallback = isset($settings['fallback_answer']) ? trim((string) $settings['fallback_answer']) : '';
        if ($fallback !== '') {
            return $fallback;
        }
        return self::FALLBACK_ANSWER;
    }

    /**
     * @return array{
     *   ok: bool,
     *   answer?: string,
     *   sources?: array<int, array{post_id:int,title:string,url:string,score:float}>,
     *   message?: string
     * }
     */
    public static function answer(string $question, array $settings, array $hints = [], ?string $traceId = null): array
    {
        $topK = (int) ($settings['top_k'] ?? 6);
        $temperature = (float) ($settings['temperature'] ?? 0.2);
        $fallbackAnswer = self::fallbackAnswer($settings);

        $retrieved = SturdyChat_RAG_Retriever::retrieve($question, $settings, $topK, $hints, $traceId);
        $context   = trim((string) ($retrieved['context'] ?? ''));

        if ($context === '') {
            return [
                'ok'      => true,
                'answer'  => $fallbackAnswer,
                'sources' => [],
            ];
        }

        $chat = SturdyChat_RAG_Chat::generate($question, $context, $settings, $temperature, $fallbackAnswer);
        if (!$chat['ok']) {
            return $chat;
        }

        return [
            'ok'      => true,
            'answer'  => $chat['answer'] ?? '',
            'sources' => $retrieved['sources'],
        ];
    }

    /**
     * Proxy method to keep backwards compatibility with existing usages.
     *
     * @return array{context:string,sources:array<int,array{post_id:int,title:string,url:string,score:float}>}
     */
    public static function retrieve(string $query, array $settings, int $topK = 6, array $hints = [], ?string $traceId = null): array
    {
        return SturdyChat_RAG_Retriever::retrieve($query, $settings, $topK, $hints, $traceId);
    }
}
