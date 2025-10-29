<?php
if (!defined('ABSPATH'))
    exit;

class SturdyChat_REST
{

    /**
     * Registers REST API routes for the given namespace and endpoint.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route('sturdychat/v1', '/ask', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [__CLASS__, 'handleAsk'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'handleAsk'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Handles the REST API request for processing a question and generating an answer.
     *
     * This method retrieves a question from the request, either from the `q` parameter
     * or the JSON body under the `question` field. It processes the question through
     * various logic layers, including custom post type (CPT) modules and a
     * Retrieval-Augmented Generation (RAG) system, to generate an appropriate response.
     *
     * If the question is empty, an error response is returned. If an exception is thrown
     * during processing, a server error response is returned.
     *
     * @param WP_REST_Request $req The REST API request object that contains request data.
     * @return WP_REST_Response A REST API response object containing the result, an error
     *                          message, or an exception message with the appropriate HTTP status code.
     */
    public static function handleAsk(WP_REST_Request $req): WP_REST_Response
    {
        $s = get_option('sturdychat_settings', []);

        // Get Query from ?q or JSON body {question:"..."}
        $q = $req->get_param('q');
        if (!$q) {
            $body = $req->get_json_params();
            $q = (is_array($body) && isset($body['question'])) ? (string) $body['question'] : '';
        }
        $q = trim((string) $q);
        if ($q === '') {
            return new WP_REST_Response([
                'error' => 'empty_question',
                'message' => 'Vraag is leeg.'
            ], 400);
        }

        $originalQuestion = $q;
        $detectedLang = class_exists('SturdyChat_Language') ? SturdyChat_Language::detect($q) : 'nl';
        $searchQuestion = $q;
        if ($detectedLang !== 'nl' && class_exists('SturdyChat_Language')) {
            $translated = SturdyChat_Language::translate($q, 'nl', $s, $detectedLang);
            if ($translated !== '') {
                $searchQuestion = $translated;
            }
        }

        // Firstly let the CPT-modules try to handle the query
        if (class_exists('SturdyChat_CPTs')) {
            $maybe = SturdyChat_CPTs::maybe_handle_query($searchQuestion, $s);
            if (is_array($maybe) && isset($maybe['answer'])) {
                $rawAnswer = (string) $maybe['answer'];
                $answer = $rawAnswer;
                if ($detectedLang !== 'nl' && class_exists('SturdyChat_Language')) {
                    $translatedAnswer = SturdyChat_Language::translate($answer, $detectedLang, $s, 'nl');
                    if ($translatedAnswer !== '') {
                        $answer = $translatedAnswer;
                    }
                }
                $sources = isset($maybe['sources']) && is_array($maybe['sources']) ? $maybe['sources'] : [];
                $fallbackAnswer = (class_exists('SturdyChat_RAG') && defined('SturdyChat_RAG::FALLBACK_ANSWER'))
                    ? SturdyChat_RAG::FALLBACK_ANSWER
                    : null;
                $isFallback = ($fallbackAnswer !== null && $rawAnswer === $fallbackAnswer);
                if ($isFallback) {
                    $sources = [];
                }
                return new WP_REST_Response([
                    'answer'  => $answer,
                    'sources' => $isFallback ? [] : array_map(
                        fn($src) => [
                            'title' => $src['title'] ?? '',
                            'url'   => $src['url'] ?? '',
                            'score' => $src['score'] ?? 0,
                        ],
                        $sources
                    ),
                    'language' => $detectedLang,
                ], 200);
            }
        }


        // Fallback the Retrieval-Augmented Generation, this will check the database for relevant content
        try {
            $out = SturdyChat_RAG::answer($searchQuestion, $s, [], null, [
                'answer_language' => $detectedLang,
                'original_question' => $originalQuestion,
                'translated_question' => $searchQuestion,
            ]);
            if (empty($out['ok'])) {
                return new WP_REST_Response([
                    'error' => 'chat_failed',
                    'message' => isset($out['message']) ? $out['message'] : 'Onbekende fout'
                ], 500);
            }

            $answer  = (string) ($out['answer'] ?? '');
            $sources = isset($out['sources']) && is_array($out['sources']) ? $out['sources'] : [];
            $fallbackAnswer = (class_exists('SturdyChat_RAG') && defined('SturdyChat_RAG::FALLBACK_ANSWER'))
                ? SturdyChat_RAG::FALLBACK_ANSWER
                : null;
            $isFallback = false;
            if ($fallbackAnswer !== null) {
                if (trim($answer) === trim($fallbackAnswer)) {
                    $isFallback = true;
                } elseif ($detectedLang !== 'nl' && class_exists('SturdyChat_Language')) {
                    $localizedFallback = SturdyChat_Language::translate($fallbackAnswer, $detectedLang, $s, 'nl');
                    if ($localizedFallback !== '' && trim($answer) === trim($localizedFallback)) {
                        $isFallback = true;
                    }
                }
            }
            if ($isFallback) {
                $sources = [];
            }
            return new WP_REST_Response([
                'answer'  => $answer,
                'sources' => $isFallback ? [] : array_map(
                    fn($src) => [
                        'title' => $src['title'] ?? '',
                        'url'   => $src['url'] ?? '',
                        'score' => $src['score'] ?? 0,
                    ],
                    $sources
                ),
                'language' => $detectedLang,
            ], 200);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'error' => 'server_error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
