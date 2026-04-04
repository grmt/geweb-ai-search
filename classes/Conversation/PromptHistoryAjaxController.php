<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class PromptHistoryAjaxController {
    private const OPTION_PROMPT_HISTORY = 'geweb_aisearch_prompt_history';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';

    public function ajaxClearPromptHistory(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        UserScope::updateScopedOption(self::OPTION_PROMPT_HISTORY, []);

        wp_send_json_success([
            'message' => 'Prompt history cleared.',
        ]);
    }

    public function ajaxDeletePromptHistoryItem(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $entryId = isset($_POST['entry_id']) ? sanitize_text_field(wp_unslash($_POST['entry_id'])) : '';
        if ($entryId === '') {
            wp_send_json_error(['message' => 'Invalid prompt history entry.'], 400);
        }

        $history = PromptSupport::normalizePromptHistoryEntries(UserScope::getScopedOption(self::OPTION_PROMPT_HISTORY, []));
        if (empty($history)) {
            wp_send_json_success(['message' => 'History is already empty.']);
            return;
        }

        $newHistory = [];
        $found = false;
        foreach ($history as $entry) {
            if ((string) ($entry['entry_id'] ?? '') === $entryId) {
                $found = true;
                continue;
            }
            $newHistory[] = $entry;
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Prompt version not found.'], 404);
        }

        UserScope::updateScopedOption(self::OPTION_PROMPT_HISTORY, $newHistory);

        wp_send_json_success(['message' => 'Prompt version deleted.']);
    }

    public function ajaxRenderPromptDiff(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $currentPrompt = PromptSupport::normalizePromptInput($_POST['current_prompt'] ?? '');
        $selectedPrompt = PromptSupport::normalizePromptInput($_POST['selected_prompt'] ?? '');

        if ($selectedPrompt === '') {
            wp_send_json_success([
                'html' => '<p>Select a saved prompt version to compare it with the current prompt field.</p>',
            ]);
        }

        if ($currentPrompt === $selectedPrompt) {
            wp_send_json_success([
                'html' => '<p>No differences from the current AI Prompt.</p>',
            ]);
        }

        if (!function_exists('wp_text_diff')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $diffHtml = wp_text_diff($selectedPrompt, $currentPrompt, [
            'title' => '',
            'title_left' => 'Saved version',
            'title_right' => 'Current prompt',
            'show_split_view' => true,
        ]);

        wp_send_json_success([
            'html' => is_string($diffHtml) && $diffHtml !== '' ? $diffHtml : '<p>No differences from the current AI Prompt.</p>',
        ]);
    }
}
