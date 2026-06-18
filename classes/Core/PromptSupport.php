<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Shared helpers for prompt input and prompt-history normalization.
 */
class PromptSupport {
    private const REGEX_DISALLOWED_URL = '/\b(?:https?:\/\/|www\.)[^\s<>"]+/i';

    /**
     * Normalize prompt text from browser/request input.
     *
     * @param mixed $rawPrompt
     * @return string
     */
    public static function normalizePromptInput($rawPrompt): string {
        if (!is_string($rawPrompt)) {
            return '';
        }

        $prompt = wp_unslash($rawPrompt);
        $prompt = wp_check_invalid_utf8($prompt, true);
        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
        $prompt = str_replace("\0", '', $prompt);

        return trim($prompt);
    }

    /**
     * @param string $prompt
     * @return bool
     */
    public static function containsDisallowedUrl(string $prompt): bool {
        return preg_match(self::REGEX_DISALLOWED_URL, $prompt) === 1;
    }

    /**
     * @param string $prompt
     * @param string $fieldLabel
     * @return void
     */
    public static function assertNoUrls(string $prompt, string $fieldLabel = 'Prompt'): void {
        if (!self::containsDisallowedUrl($prompt)) {
            return;
        }

        throw new \InvalidArgumentException($fieldLabel . ' cannot contain URLs. Remove links and try again.');
    }

    /**
     * @param mixed $history
     * @return array<int,array<string,mixed>>
     */
    public static function normalizePromptHistoryEntries($history): array {
        if (!is_array($history)) {
            return [];
        }

        $normalized = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $prompt = trim((string) ($entry['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }

            $scope = (($entry['scope'] ?? 'global') === 'model') ? 'model' : 'global';
            $rawMode = (string) ($entry['mode'] ?? 'base');
            if ($rawMode === 'override') {
                $mode = 'override';
            } elseif ($rawMode === 'append') {
                $mode = 'append';
            } else {
                $mode = 'base';
            }

            $normalized[] = [
                'entry_id' => sanitize_text_field((string) ($entry['entry_id'] ?? wp_generate_uuid4())),
                'prompt' => $prompt,
                'saved_at' => intval($entry['saved_at'] ?? current_time('timestamp')),
                'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
                'scope' => $scope,
                'model' => $scope === 'model' ? sanitize_text_field((string) ($entry['model'] ?? '')) : '',
                'mode' => $mode,
            ];
        }

        return $normalized;
    }
}
