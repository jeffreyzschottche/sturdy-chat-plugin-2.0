<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_RAG_Chat
{
    /**
     * @return array{ok:bool,answer?:string,message?:string}
     */
    public static function generate(string $question, string $context, array $settings, float $temperature, string $fallbackAnswer): array
    {
        $base  = rtrim((string) ($settings['openai_api_base'] ?? 'https://api.openai.com/v1'), '/');
        $key   = trim((string) ($settings['openai_api_key'] ?? ''));
        $model = (string) ($settings['chat_model'] ?? 'gpt-4o-mini');

        if ($key === '') {
            return ['ok' => false, 'message' => __('OpenAI API Key ontbreekt. Stel deze in.', 'sturdychat-chatbot')];
        }

        $today = function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d');

        $system = "Je beantwoordt vragen namens Dutch Green Building Council (DGBC) op basis van CONTEXT-SNIPPETS uit onze eigen site.
- Gebruik alleen feiten die letterlijk in de snippets staan; geen externe aannames.
- De snippets zijn voorzien van labels 'Bron #<n> (score <waarde>)'. Hogere score betekent hogere prioriteit en betrouwbaarheid. Bron #1 heeft de hoogste prioriteit; lagere scores mag je alleen gebruiken als ze relevante details toevoegen.
- Formuleer een helder antwoord van 3–6 zinnen in het Nederlands. Combineer meerdere bronnen wanneer dat helpt, maar herhaal geen informatie zonder toegevoegde waarde.
- Als een vraag niet expliciet wordt beantwoord in de context, geef dan dit antwoord: '{$fallbackAnswer}'.
- Eindig met een korte uitnodiging om andere DGBC-bronnen te bekijken wanneer extra verdieping gewenst is.
- Datum van vandaag is: {$today}, nieuwere berichten zijn ook relevanter dan oudere.";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "VRAAG:\n" . $question . "\n\nCONTEXT (snippets):\n" . $context],
        ];

        if (class_exists('SturdyChat_Debugger') && SturdyChat_Debugger::isEnabled('show_prompt_context')) {
            SturdyChat_Debugger_ShowPrompt::logPrompt([
                'question'    => $question,
                'context'     => $context,
                'model'       => $model,
                'temperature' => $temperature,
                'messages'    => $messages,
            ]);
        }

        $response = wp_remote_post($base . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'model'       => $model,
                'temperature' => $temperature,
                'messages'    => $messages,
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'message' => 'Chat request failed: HTTP ' . $statusCode];
        }

        $body   = json_decode((string) wp_remote_retrieve_body($response), true);
        $answer = trim((string) ($body['choices'][0]['message']['content'] ?? ''));

        return [
            'ok'     => true,
            'answer' => $answer,
        ];
    }
}
