<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SturdyChat_Debugger_ShowPrompt
{
    /**
     * Log the final prompt sent to the LLM for inspection.
     *
     * @param array $data Payload containing question, context, model, etc.
     * @return void
     */
    public static function logPrompt(array $data): void
    {
        SturdyChat_Debugger::log('show_prompt_context', 'prompt', [
            'question'      => $data['question'] ?? '',
            'context'       => $data['context'] ?? '',
            'model'         => $data['model'] ?? '',
            'temperature'   => $data['temperature'] ?? 0,
            'messages'      => $data['messages'] ?? [],
            'timestamp'     => current_time('mysql'),
        ]);
    }
}
