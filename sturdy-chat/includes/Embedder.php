<?php
if (!defined('ABSPATH'))
    exit;

class SturdyChat_Embedder
{
    /**
     * Generates an embedding for a given text input using OpenAI's API.
     *
     * @param string $text The input text to generate embeddings for.
     * @param array $settings Configuration settings, including 'openai_api_base', 'openai_api_key', and 'embed_model'.
     *                        'openai_api_base' specifies the API base URL.
     *                        'openai_api_key' provides the API key for authentication.
     *                        'embed_model' defines the model to use (default configured via sturdychat_default_settings()).
     * @return array The computed embedding as a normalized array of floating-point values.
     *
     * @throws RuntimeException If the API key is missing, the API call fails, or the response is invalid.
     */
    public static function embed(string $text, array $settings): array
    {
        $settings = sturdychat_settings_with_defaults($settings);
        $base = rtrim($settings['openai_api_base'], '/');
        $key = trim($settings['openai_api_key']);
        $model = $settings['embed_model'];
        if (!$key)
            throw new RuntimeException('OpenAI API Key ontbreekt.');

        $res = wp_remote_post($base . '/embeddings', [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['input' => $text, 'model' => $model]),
            'timeout' => 45,
        ]);
        if (is_wp_error($res))
            throw new RuntimeException($res->get_error_message());

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300 || !isset($body['data'][0]['embedding'])) {
            throw new RuntimeException('Embeddings mislukt: HTTP ' . $code);
        }
        return self::normalize($body['data'][0]['embedding']);
    }

    /**
     * Normalizes a vector by adjusting its magnitude to be of length 1.
     *
     * @param array $v An array of floating-point numbers representing the vector to be normalized.
     * @return array The normalized vector as an array of floating-point values.
     */
    public static function normalize(array $v): array
    {
        $norm = 0.0;
        foreach ($v as $x) {
            $norm += $x * $x;
        }
        $norm = sqrt(max(1e-12, $norm));
        foreach ($v as $i => $x) {
            $v[$i] = $x / $norm;
        }
        return $v;
    }

    /**
     * Calculates the cosine similarity between two vectors.
     *
     * @param array $a The first vector as an array of numerical values.
     * @param array $b The second vector as an array of numerical values.
     * @return float The cosine similarity value as a floating-point number.
     */
    public static function cosine(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++)
            $sum += $a[$i] * $b[$i];
        return $sum;
    }
}
