<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight utilities for language detection and translation.
 */
class SturdyChat_Language
{
    private const LANGUAGE_LABELS = [
        'nl' => 'Nederlands',
        'en' => 'Engels',
        'de' => 'Duits',
        'fr' => 'Frans',
        'es' => 'Spaans',
        'it' => 'Italiaans',
        'pt' => 'Portugees',
        'zh' => 'Chinees',
        'ja' => 'Japans',
        'ko' => 'Koreaans',
    ];

    private const DUTCH_HINTS = [
        'de', 'het', 'een', 'vacature', 'vacatures', 'waar', 'vind', 'informatie', 'over',
        'dit', 'pagina', 'kunnen', 'bekijk', 'alle', 'hier', 'banen', 'functie', 'werk',
    ];

    private const ENGLISH_HINTS = [
        'the', 'and', 'or', 'vacancy', 'vacancies', 'job', 'jobs', 'page', 'where', 'find',
        'information', 'about', 'this', 'website', 'career', 'careers', 'position', 'positions',
    ];

    /**
     * Attempts to detect the language of a string. Falls back to Dutch.
     */
    public static function detect(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'nl';
        }

        // Simple script-based detection for non-Latin scripts.
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
            return 'zh';
        }
        if (preg_match('/[\x{3040}-\x{30FF}\x{31F0}-\x{31FF}]/u', $text)) {
            return 'ja';
        }
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) {
            return 'ko';
        }

        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return 'nl';
        }

        $dutchScore = self::scoreTokens($tokens, self::DUTCH_HINTS);
        $englishScore = self::scoreTokens($tokens, self::ENGLISH_HINTS);

        if ($englishScore > $dutchScore) {
            return 'en';
        }
        if ($dutchScore > $englishScore) {
            return 'nl';
        }

        // Default to user's locale if available, otherwise Dutch.
        $locale = get_locale();
        if (is_string($locale) && strlen($locale) >= 2) {
            return strtolower(substr($locale, 0, 2));
        }

        return 'nl';
    }

    /**
     * Translates text to a target language using the configured OpenAI chat model.
     * Falls back to original text if translation is not possible.
     */
    public static function translate(string $text, string $targetLang, array $settings, ?string $sourceLang = null): string
    {
        $text = trim($text);
        $targetLang = strtolower(trim($targetLang));
        $sourceLang = $sourceLang ? strtolower(trim($sourceLang)) : null;

        if ($text === '' || $targetLang === '') {
            return $text;
        }
        if ($sourceLang && $sourceLang === $targetLang) {
            return $text;
        }

        $key = trim((string) ($settings['openai_api_key'] ?? ''));
        if ($key === '') {
            return $text;
        }
        $base = rtrim((string) ($settings['openai_api_base'] ?? 'https://api.openai.com/v1'), '/');
        $model = (string) ($settings['chat_model'] ?? 'gpt-4o-mini');

        static $cache = [];
        $cacheKey = md5($text . '|' . $targetLang . '|' . ($sourceLang ?? '*'));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $targetLabel = self::languageLabel($targetLang);
        $sourceSnippet = $sourceLang ? 'De brontaal is ' . self::languageLabel($sourceLang) . '. ' : '';
        $system = $sourceSnippet
            . 'Vertaal de tekst naar ' . $targetLabel . '. '
            . 'Laat url\'s, woorden tussen aanhalingstekens en Nederlandse paginanamen zoals "Vacatures" onvertaald. '
            . 'Geef alleen de vertaling.';

        $response = wp_remote_post($base . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $text],
                ],
            ]),
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            return $text;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $text;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $translated = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
        if ($translated === '') {
            return $text;
        }

        $cache[$cacheKey] = $translated;
        return $translated;
    }

    /**
     * Provides a human readable name for a language code.
     */
    public static function languageLabel(string $code): string
    {
        $code = strtolower(trim($code));
        if (isset(self::LANGUAGE_LABELS[$code])) {
            return self::LANGUAGE_LABELS[$code];
        }
        return ucfirst($code);
    }

    private static function scoreTokens(array $tokens, array $hints): int
    {
        $score = 0;
        $hintMap = array_fill_keys($hints, 1);
        foreach ($tokens as $token) {
            if (isset($hintMap[$token])) {
                $score++;
            }
        }
        return $score;
    }
}
