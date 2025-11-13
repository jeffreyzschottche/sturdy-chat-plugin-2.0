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

    /**
     * Determine the fallback answer text, optionally using a configured override.
     *
     * @param array $settings Plugin settings array which may contain a custom fallback.
     * @return string Text used when no contextual answer can be produced.
     */
    public static function fallbackAnswer(array $settings = []): string
    {
        $fallback = isset($settings['fallback_answer']) ? trim((string) $settings['fallback_answer']) : '';
        if ($fallback !== '') {
            return $fallback;
        }
        return self::FALLBACK_ANSWER;
    }

    /**
     * Generate an answer for a question using RAG retrieval plus chat completion.
     *
     * @param string      $question Natural-language question provided by the user.
     * @param array       $settings Plugin configuration (embedding models, top_k, etc.).
     * @param array       $hints    Optional hints (post IDs, URLs) to boost retrieval.
     * @param string|null $traceId  Reserved identifier for future tracing use.
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
     * Proxy method to keep backwards compatibility with existing retrieval usages.
     *
     * @param string      $query    Search query text.
     * @param array       $settings Plugin settings array.
     * @param int         $topK     Maximum number of documents to return.
     * @param array       $hints    Optional hints (postId, URL) consumed by the retriever.
     * @param string|null $traceId  Optional trace identifier.
     * @return array{context:string,sources:array<int,array{post_id:int,title:string,url:string,score:float}>}
     */
    public static function retrieve(string $query, array $settings, int $topK = 6, array $hints = [], ?string $traceId = null): array
    {
        return SturdyChat_RAG_Retriever::retrieve($query, $settings, $topK, $hints, $traceId);
    }
}
