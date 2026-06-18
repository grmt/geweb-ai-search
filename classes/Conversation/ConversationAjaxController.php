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

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

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
            'cache_state' => AdminViewRevision::ensureCurrentState(),
        ]);
    }

    public function ajaxGetFrontendConversations(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        wp_send_json_success([
            'conversations' => $this->conversationManager->getFrontendConversationSummaries(),
            'cache_state' => AdminViewRevision::ensureCurrentState(),
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
            'cache_state' => AdminViewRevision::ensureCurrentState(),
        ]);
    }

    public function ajaxSaveFrontendConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Saving chats is temporarily paused.'), 503);
        }

        $conversationId = isset($_POST['conversation_id']) ? FrontendAiContext::sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        $summary = isset($_POST['summary']) ? sanitize_text_field(wp_unslash($_POST['summary'])) : '';
        $compacted = !empty($_POST['compacted']);
        $contextSummary = isset($_POST['context_summary']) ? sanitize_textarea_field(wp_unslash($_POST['context_summary'])) : '';
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized in ConversationManager
        $messages = isset($_POST['messages']) && is_array($_POST['messages']) ? wp_unslash($_POST['messages']) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $conversation = $this->conversationManager->saveFrontendConversation($conversationId, $messages, $summary, $compacted, $contextSummary);

        wp_send_json_success([
            'conversation' => $this->conversationManager->getFrontendConversation((string) ($conversation['id'] ?? '')),
            'cache_state' => AdminViewRevision::ensureCurrentState(),
        ]);
    }

    public function ajaxFrontendRenameConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Renaming chats is temporarily paused.'), 503);
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
            'summary' => $summary,
            'cache_state' => AdminViewRevision::ensureCurrentState(),
        ]);
    }

    public function ajaxDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $conversationId = $this->requireConversationIdFromRequest();
        if (!$this->conversationManager->deleteConversation($conversationId)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'message' => 'Conversation deleted.',
            'cache_state' => AdminViewRevision::ensureCurrentState(),
        ]);
    }

    public function ajaxFrontendDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Deleting chats is temporarily paused.'), 503);
        }

        $conversationId = $this->requireConversationIdFromRequest();
        if (!$this->conversationManager->deleteConversation($conversationId)) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        wp_send_json_success([
            'deleted' => true,
            'cache_state' => AdminViewRevision::ensureCurrentState(),
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
