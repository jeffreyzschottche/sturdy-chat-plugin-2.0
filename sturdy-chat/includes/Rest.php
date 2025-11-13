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
        $q = $req->get_param('q');
        if (!$q) {
            $body = $req->get_json_params();
            $q = (is_array($body) && isset($body['question'])) ? (string) $body['question'] : '';
        }
        $q = trim((string) $q);
        if ($q === '') {
            return new WP_REST_Response([
                'error' => 'empty_question',
                'message' => __('Vraag is leeg.', 'sturdychat-chatbot')
            ], 400);
        }

        $result = sturdychat_answer_question($q);
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $status = ($code === 'empty_question') ? 400 : 500;
            return new WP_REST_Response([
                'error' => $code,
                'message' => $result->get_error_message()
            ], $status);
        }

        return new WP_REST_Response($result, 200);
    }
}
