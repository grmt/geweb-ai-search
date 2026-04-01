<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ConversationAjaxController {
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const MESSAGE_MISSING_CONVERSATION_ID = 'Missing conversation ID.';
    private const MESSAGE_CONVERSATION_NOT_FOUND = 'Conversation not found.';

    private ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager) {
        $this->conversationManager = $conversationManager;
    }

    public function ajaxRenameConversation(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $conversationId = $this->requireConversationIdFromRequest();
        $summary = isset($_POST['summary']) ? sanitize_text_field(wp_unslash($_POST['summary'])) : '';
        $summary = trim($summary);

        if ($summary === '') {
            wp_send_json_error(['message' => 'Conversation name cannot be empty.'], 400);
        }

        if (!$this->conversationManager->renameConversation($conversationId, $summary)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'message' => 'Conversation renamed.',
            'summary' => $summary,
        ]);
    }

    public function ajaxGetFrontendConversations(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        wp_send_json_success([
            'conversations' => $this->conversationManager->getFrontendConversationSummaries(),
        ]);
    }

    public function ajaxGetFrontendConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromRequest();
        $conversation = $this->conversationManager->getFrontendConversation($conversationId);

        if ($conversation === null) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'conversation' => $conversation,
        ]);
    }

    public function ajaxFrontendRenameConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromRequest();
        $summary = isset($_POST['summary']) ? sanitize_text_field(wp_unslash($_POST['summary'])) : '';
        $summary = trim($summary);

        if ($summary === '') {
            wp_send_json_error(['message' => 'Conversation name cannot be empty.'], 400);
        }

        if (!$this->conversationManager->renameConversation($conversationId, $summary)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'summary' => $summary,
        ]);
    }

    public function ajaxDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $conversationId = $this->requireConversationIdFromRequest();
        if (!$this->conversationManager->deleteConversation($conversationId)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'message' => 'Conversation deleted.',
        ]);
    }

    public function ajaxFrontendDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromRequest();
        if (!$this->conversationManager->deleteConversation($conversationId)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'deleted' => true,
        ]);
    }

    private function requireConversationIdFromRequest(): string {
        $conversationId = isset($_POST['conversation_id']) ? FrontendAiContext::sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        if ($conversationId === '') {
            wp_send_json_error(['message' => self::MESSAGE_MISSING_CONVERSATION_ID], 400);
        }

        return $conversationId;
    }
}
