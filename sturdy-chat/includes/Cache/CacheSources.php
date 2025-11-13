<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Cache_Sources
{
    /**
     * @param array<int, array{title?:string,url?:string,score?:float}> $sources
     */
    public static function encode(array $sources): string
    {
        if (!$sources) {
            return '';
        }

        $normalized = [];
        foreach ($sources as $src) {
            if (!is_array($src)) {
                continue;
            }
            $normalized[] = [
                'title' => (string) ($src['title'] ?? ''),
                'url'   => (string) ($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float) $src['score'] : 0.0,
            ];
        }

        if (!$normalized) {
            return '';
        }

        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($normalized);
        }

        return (string) json_encode($normalized);
    }

    /**
     * @return array<int, array{title:string,url:string,score:float}>
     */
    public static function decode(string $sources): array
    {
        if ($sources === '') {
            return [];
        }

        $decoded = json_decode($sources, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $src) {
            if (!is_array($src)) {
                continue;
            }
            $normalized[] = [
                'title' => (string) ($src['title'] ?? ''),
                'url'   => (string) ($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float) $src['score'] : 0.0,
            ];
        }

        return $normalized;
    }

    /**
     * Sanitize raw JSON input from the editor.
     *
     * @return array{ok:bool,value:string,message?:string}
     */
    public static function normalizeInput(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => true, 'value' => ''];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $error = 'JSON decode error';
            if (function_exists('json_last_error_msg')) {
                $error = json_last_error_msg();
            }
            return [
                'ok'      => false,
                'value'   => '',
                'message' => sprintf(__('Invalid sources JSON: %s', 'sturdychat-chatbot'), $error),
            ];
        }

        $normalized = [];
        foreach ($decoded as $src) {
            if (!is_array($src)) {
                continue;
            }

            $item = [
                'title' => (string) ($src['title'] ?? ''),
                'url'   => (string) ($src['url'] ?? ''),
                'score' => isset($src['score']) ? (float) $src['score'] : 0.0,
            ];

            if ($item['title'] === '' && $item['url'] === '' && 0.0 === $item['score']) {
                continue;
            }

            $normalized[] = $item;
        }

        if (!$normalized) {
            return ['ok' => true, 'value' => ''];
        }

        $flags = 0;
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_PRETTY_PRINT')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = function_exists('wp_json_encode')
            ? wp_json_encode($normalized, $flags)
            : json_encode($normalized, $flags);

        if (!is_string($json)) {
            return [
                'ok'      => false,
                'value'   => '',
                'message' => __('Unable to encode sources as JSON.', 'sturdychat-chatbot'),
            ];
        }

        return ['ok' => true, 'value' => $json];
    }

    public static function formatForEditor(string $sources): string
    {
        $sources = trim($sources);
        if ($sources === '') {
            return '';
        }

        $result = self::normalizeInput($sources);
        if ($result['ok']) {
            return $result['value'];
        }

        return $sources;
    }
}
