<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Cache_Normalizer
{
    public static function normalizeQuestion(string $question): string
    {
        $question = wp_strip_all_tags($question);
        $question = html_entity_decode((string) $question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $question = preg_replace('/[[:punct:]]+/u', ' ', (string) $question);
        $question = preg_replace('/[[:space:]]+/u', ' ', (string) $question);
        $question = trim((string) $question);

        if ($question === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $question = mb_strtolower($question, 'UTF-8');
        } else {
            $question = strtolower($question);
        }

        return $question;
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
