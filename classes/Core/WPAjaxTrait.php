<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait WPAjaxTrait {
    public function ajaxSearch(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $query_len = strlen($query);

        if ($query_len > 50 || $query_len < 3) {
            wp_send_json_error();
        }

        $results = [];
        $wpQuery = new \WP_Query([
            'post_type' => get_option('geweb_aisearch_post_types', ['post']),
            'posts_per_page' => 10,
            's' => $query
        ]);
        if ($wpQuery->have_posts()) {
            while ($wpQuery->have_posts()) {
                $wpQuery->the_post();
                $results[] = [
                    'url' => get_permalink(get_the_ID()),
                    'title' => get_the_title()
                ];
            }
        }
        wp_reset_postdata();
        wp_send_json_success($results);
    }

    public function ajaxGetNonce(): void {
        wp_send_json_success([
            'nonce' => wp_create_nonce('geweb_ai_search_search')
        ]);
    }

    public function ajaxAiChat(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $rawMessages = isset($_POST['messages']) && is_array($_POST['messages']) ? wp_unslash($_POST['messages']) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $conversationId = isset($_POST['conversation_id']) ? FrontendAiContext::sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        $requestedModel = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $temporaryPrompt = isset($_POST['temporary_prompt']) ? PromptSupport::normalizePromptInput($_POST['temporary_prompt']) : '';

        if ($temporaryPrompt !== '' && PromptSupport::containsDisallowedUrl($temporaryPrompt)) {
            wp_send_json_error(['message' => 'Temporary prompt cannot contain URLs. Remove links and try again.'], 400);
        }

        if (empty($rawMessages)) {
            wp_send_json_error(['message' => 'No messages provided']);
        }

        $messages = $this->normalizeAjaxChatMessages($rawMessages);

        try {
            $provider = ProviderFactory::make();
            $selectedModel = $this->resolveRequestedModel($provider, $requestedModel);
            $latestUserMessage = $this->conversationManager->extractLatestUserMessage($messages);
            $fullMessages = $this->conversationManager->buildFullConversationMessages($conversationId, $messages, $latestUserMessage);
            $initialConversation = $this->conversationManager->saveFrontendConversation(
                $conversationId,
                $fullMessages,
                '',
                false,
                ''
            );
            $resolvedConversationId = (string) ($initialConversation['id'] ?? $conversationId);
            $jobId = 'chatjob-' . wp_generate_password(12, false, false);

            $this->aiChatJobStore->write([
                'id' => $jobId,
                'status' => 'queued',
                'conversation_id' => $resolvedConversationId,
                'messages' => $fullMessages,
                'requested_model' => $selectedModel,
                'temporary_prompt' => $temporaryPrompt,
                'excluded_sources' => $this->extractExcludedSourcesFromRequest(),
                'created_at' => time(),
                'updated_at' => time(),
                'result' => null,
                'error_message' => '',
                'progress' => [
                    'stage' => 'queued',
                    'label' => __('Queued', 'geweb-ai-search'),
                    'thoughts' => [
                        __('Queued the request and waiting for the background worker to start.', 'geweb-ai-search'),
                    ],
                    'supports_thoughts' => strpos(strtolower($selectedModel), 'gemini-3') === 0,
                    'updated_at' => time(),
                ],
            ]);

            $this->sendAsyncJsonAndContinue([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'job_id' => $jobId,
                    'conversation_id' => $resolvedConversationId,
                ],
            ]);

            $this->aiChatJobProcessor->process($jobId);
            exit;
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajaxGetAiChatJob(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if ($jobId === '') {
            wp_send_json_error(['message' => __('Missing AI chat job id.', 'geweb-ai-search')], 400);
        }

        $job = $this->aiChatJobStore->read($jobId);
        if ($job === null) {
            wp_send_json_error(['message' => __('AI chat job was not found or has expired.', 'geweb-ai-search')], 404);
        }

        $status = sanitize_key((string) ($job['status'] ?? 'queued'));
        $payload = [
            'job_id' => (string) ($job['id'] ?? $jobId),
            'status' => $status !== '' ? $status : 'queued',
            'conversation_id' => (string) ($job['conversation_id'] ?? ''),
            'created_at' => (int) ($job['created_at'] ?? 0),
            'updated_at' => (int) ($job['updated_at'] ?? 0),
            'progress' => isset($job['progress']) && is_array($job['progress']) ? $job['progress'] : [],
        ];

        if ($payload['status'] === 'completed') {
            $payload['result'] = isset($job['result']) && is_array($job['result']) ? $job['result'] : [];
        } elseif ($payload['status'] === 'error') {
            $payload['message'] = trim((string) ($job['error_message'] ?? '')) ?: __('The AI request failed.', 'geweb-ai-search');
            $payload['meta'] = isset($job['error_meta']) && is_array($job['error_meta']) ? $job['error_meta'] : [];
        }

        wp_send_json_success($payload);
    }

    private function normalizeAjaxChatMessages(array $rawMessages): array {
        $allowedRoles = ['user', 'model'];
        $messages = [];
        foreach ($rawMessages as $rawMessage) {
            if (!is_array($rawMessage)) { continue; }
            $content = isset($rawMessage['content']) ? sanitize_textarea_field($rawMessage['content']) : '';
            if ($content === '') { continue; }
            $role = isset($rawMessage['role']) ? sanitize_text_field($rawMessage['role']) : '';
            if (!in_array($role, $allowedRoles, true)) { $role = 'user'; }
            $messages[] = $this->buildNormalizedChatMessage($role, $content, $rawMessage);
        }
        return $messages;
    }

    private function buildNormalizedChatMessage(string $role, string $content, array $rawMessage): array {
        $message = ['role' => $role, 'content' => $content];
        $createdAt = $this->normalizeMessageTimestamp($rawMessage['created_at'] ?? $rawMessage['createdAt'] ?? null);
        if ($createdAt !== null) { $message['created_at'] = $createdAt; }
        return $message;
    }

    private function normalizeMessageTimestamp(mixed $raw): ?int {
        if ($raw === null || !is_numeric($raw)) { return null; }
        $ts = (int) $raw;
        if ($ts > 1000000000000) { $ts = (int) floor($ts / 1000); }
        return $ts > 0 ? $ts : null;
    }

    private function extractExcludedSourcesFromRequest(): array {
        $rawValue = isset($_POST['excluded_sources']) ? wp_unslash($_POST['excluded_sources']) : '';
        if (!is_string($rawValue) || trim($rawValue) === '') { return []; }
        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) { return []; }
        $excludedSources = [];
        foreach ($decoded as $item) {
            $normalized = is_array($item) ? $this->normalizeExcludedSourceItem($item) : null;
            if ($normalized !== null) { $excludedSources[] = $normalized; }
        }
        return $excludedSources;
    }

    private function normalizeExcludedSourceItem(array $item): ?array {
        $key   = isset($item['key'])   ? sanitize_text_field((string) $item['key'])  : '';
        $title = isset($item['title']) ? sanitize_text_field((string) $item['title']) : '';
        $url   = isset($item['url'])   ? esc_url_raw((string) $item['url'])           : '';
        if ($key === '' && $url === '' && $title === '') { return null; }
        return ['key' => $key, 'title' => $title, 'url' => $url];
    }

    private function resolveRequestedModel(AIProviderInterface $provider, string $requestedModel): string {
        $availableModels = $provider->getModels();
        return in_array($requestedModel, $availableModels, true) ? $requestedModel : $provider->getModel();
    }

    private function sendAsyncJsonAndContinue(array $payload): void {
        if (!headers_sent()) {
            status_header(202);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
        }
        ignore_user_abort(true);
        $json = wp_json_encode($payload);
        echo is_string($json) ? $json : '{"success":false,"data":{"message":"Could not encode async response."}}';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }
}
