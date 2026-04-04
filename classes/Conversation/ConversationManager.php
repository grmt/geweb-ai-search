<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ConversationManager {
    private const OPTION_CONVERSATIONS = 'geweb_aisearch_conversations';
    private const REGEX_WHITESPACE = '/\s+/';
    private const DEFAULT_CONVERSATION_LIMIT = 50;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getConversationLog(): array {
        return array_values($this->getConversationOption());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getFrontendConversationSummaries(): array {
        return array_map(function (array $entry): array {
            return $this->exportConversationSummaryForFrontend($entry);
        }, $this->getConversationLog());
    }

    /**
     * @param string $conversationId
     * @return array<string,mixed>|null
     */
    public function getFrontendConversation(string $conversationId): ?array {
        $conversation = $this->getConversationById($conversationId);
        if ($conversation === null) {
            return null;
        }

        return $this->exportConversationForFrontend($conversation);
    }

    /**
     * @param string $conversationId
     * @param string $summary
     * @return bool
     */
    public function renameConversation(string $conversationId, string $summary): bool {
        $conversations = $this->getConversationOption();
        if (!isset($conversations[$conversationId]) || !is_array($conversations[$conversationId])) {
            return false;
        }

        $conversations[$conversationId]['summary'] = $summary;
        UserScope::updateUserScopedOption(self::OPTION_CONVERSATIONS, $conversations, false);

        return true;
    }

    /**
     * @param string $conversationId
     * @return bool
     */
    public function deleteConversation(string $conversationId): bool {
        $conversations = $this->getConversationOption();
        if (!isset($conversations[$conversationId]) || !is_array($conversations[$conversationId])) {
            return false;
        }

        unset($conversations[$conversationId]);
        UserScope::updateUserScopedOption(self::OPTION_CONVERSATIONS, $conversations, false);

        return true;
    }

    /**
     * @param string $conversationId
     * @param array<int,array<string,mixed>> $messages
     * @param string $summary
     * @param bool $compacted
     * @return array<string,mixed>
     */
    public function saveFrontendConversation(string $conversationId, array $messages, string $summary = '', bool $compacted = false): array {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            $conversationId = 'geweb-ai-' . wp_generate_password(12, false, false);
        }

        $normalizedMessages = $this->normalizeConversationMessages($messages);
        $normalizedSummary = trim($summary);
        if ($normalizedSummary === '') {
            $normalizedSummary = $this->buildConversationSummary($normalizedMessages);
        }

        $conversations = $this->getConversationOption();
        $existing = isset($conversations[$conversationId]) && is_array($conversations[$conversationId])
            ? $conversations[$conversationId]
            : [];
        $now = time();

        $conversation = [
            'id' => $conversationId,
            'summary' => $normalizedSummary !== '' ? $normalizedSummary : 'Untitled conversation',
            'started_at' => (int) ($existing['started_at'] ?? $now),
            'last_used_at' => $now,
            'provider' => (string) ($existing['provider'] ?? ''),
            'model' => (string) ($existing['model'] ?? ''),
            'request_count' => (int) ($existing['request_count'] ?? 0),
            'input_tokens' => (int) ($existing['input_tokens'] ?? 0),
            'output_tokens' => (int) ($existing['output_tokens'] ?? 0),
            'total_tokens' => (int) ($existing['total_tokens'] ?? 0),
            'estimated_cost_usd' => (float) ($existing['estimated_cost_usd'] ?? 0),
            'messages' => $normalizedMessages,
            'context_summary' => $compacted ? '__frontend_compacted__' : '',
        ];

        $conversations[$conversationId] = $conversation;
        uasort($conversations, static function (array $a, array $b): int {
            return ((int) ($b['last_used_at'] ?? 0)) <=> ((int) ($a['last_used_at'] ?? 0));
        });

        UserScope::updateUserScopedOption(self::OPTION_CONVERSATIONS, array_slice($conversations, 0, self::DEFAULT_CONVERSATION_LIMIT, true), false);

        return $conversation;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{role:string,content:string}|null
     */
    public function extractLatestUserMessage(array $messages): ?array {
        for ($index = count($messages) - 1; $index >= 0; $index -= 1) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role !== 'user' || $content === '') {
                continue;
            }

            return [
                'role' => 'user',
                'content' => $content,
            ];
        }

        return null;
    }

    /**
     * @param string $conversationId
     * @param array<int,array<string,mixed>> $incomingMessages
     * @param array{role:string,content:string}|null $latestUserMessage
     * @return array<int,array<string,mixed>>
     */
    public function buildFullConversationMessages(string $conversationId, array $incomingMessages, ?array $latestUserMessage): array {
        $existing = $conversationId !== '' ? $this->getConversationById($conversationId) : null;
        $storedMessages = $this->normalizeConversationMessages(isset($existing['messages']) && is_array($existing['messages']) ? $existing['messages'] : []);

        if (empty($storedMessages)) {
            return !empty($incomingMessages) ? $this->normalizeConversationMessages($incomingMessages) : [];
        }

        if ($latestUserMessage === null) {
            return $storedMessages;
        }

        $lastStored = end($storedMessages);
        if (!is_array($lastStored) || ($lastStored['role'] ?? '') !== 'user' || ($lastStored['content'] ?? '') !== $latestUserMessage['content']) {
            $storedMessages[] = $latestUserMessage;
        }

        return $storedMessages;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    public function normalizeConversationMessages(array $messages): array {
        $normalized = [];
        foreach ($messages as $message) {
            $normalizedMessage = $this->normalizeConversationMessage($message);
            if ($normalizedMessage !== null) {
                $normalized[] = $normalizedMessage;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool}
     */
    public function compactConversationForRequest(array $messages): array {
        $messages = $this->normalizeConversationMessages($messages);
        if (empty($messages)) {
            return [
                'messages' => [],
                'summary' => '',
                'compacted' => false,
            ];
        }

        $maxMessages = FrontendAiContext::getConversationTrimMessageLimit();
        $maxChars = FrontendAiContext::getConversationTrimCharLimit();
        if (count($messages) <= $maxMessages && $this->getConversationMessageLength($messages) <= $maxChars) {
            return [
                'messages' => $messages,
                'summary' => '',
                'compacted' => false,
            ];
        }

        $recentCount = max(2, $maxMessages - 1);
        $recentMessages = array_slice($messages, -$recentCount);
        $olderMessages = array_slice($messages, 0, max(0, count($messages) - $recentCount));
        $summary = $this->buildConversationContextSummary($olderMessages);

        $compactedMessages = $recentMessages;
        if ($summary !== '') {
            array_unshift($compactedMessages, [
                'role' => 'user',
                'content' => $summary,
            ]);
        }

        while ($this->getConversationMessageLength($compactedMessages) > $maxChars && count($compactedMessages) > 3) {
            $removalIndex = $summary !== '' ? 1 : 0;
            if (!isset($compactedMessages[$removalIndex])) {
                break;
            }
            array_splice($compactedMessages, $removalIndex, 1);
        }

        return [
            'messages' => array_values($compactedMessages),
            'summary' => $summary,
            'compacted' => true,
        ];
    }

    /**
     * @param string $conversationId
     * @param array<int,array<string,mixed>> $messages
     * @param string $contextSummary
     * @param array<string,mixed> $result
     * @param AIProviderInterface $provider
     * @return void
     */
    public function recordConversationUsage(string $conversationId, array $messages, string $contextSummary, array $result, AIProviderInterface $provider): void {
        $conversationId = $conversationId !== '' ? $conversationId : 'geweb-ai-' . wp_generate_password(12, false, false);
        $conversations = $this->getConversationOption();
        $now = time();
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
        $usage = isset($meta['usage']) && is_array($meta['usage']) ? $meta['usage'] : [];

        if (isset($conversations[$conversationId]) && is_array($conversations[$conversationId])) {
            $existing = $conversations[$conversationId];
        } else {
            $existing = [
                'id' => $conversationId,
                'summary' => $this->buildConversationSummary($messages),
                'started_at' => $now,
                'last_used_at' => $now,
                'provider' => $provider->getProviderLabel(),
                'model' => method_exists($provider, 'getModel') ? $provider->getModel() : '',
                'request_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost_usd' => 0.0,
                'messages' => [],
                'context_summary' => '',
            ];
        }

        if (trim((string) ($existing['summary'] ?? '')) === '') {
            $existing['summary'] = $this->buildConversationSummary($messages);
        }

        $existing['last_used_at'] = $now;
        $existing['provider'] = isset($meta['provider']) ? (string) $meta['provider'] : $provider->getProviderLabel();
        if (isset($meta['model'])) {
            $existing['model'] = (string) $meta['model'];
        } elseif (method_exists($provider, 'getModel')) {
            $existing['model'] = $provider->getModel();
        } else {
            $existing['model'] = '';
        }
        $existing['request_count'] = (int) ($existing['request_count'] ?? 0) + 1;
        $existing['input_tokens'] = (int) ($existing['input_tokens'] ?? 0) + (int) ($usage['input_tokens'] ?? 0);
        $existing['output_tokens'] = (int) ($existing['output_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
        $existing['total_tokens'] = (int) ($existing['total_tokens'] ?? 0) + (int) ($usage['total_tokens'] ?? 0);
        $existing['estimated_cost_usd'] = (float) ($existing['estimated_cost_usd'] ?? 0) + (float) ($meta['estimated_cost_usd'] ?? 0);
        $existing['messages'] = $this->normalizeConversationMessages($messages);
        $existing['context_summary'] = $contextSummary;

        $conversations[$conversationId] = $existing;

        uasort($conversations, static function (array $a, array $b): int {
            return ((int) ($b['last_used_at'] ?? 0)) <=> ((int) ($a['last_used_at'] ?? 0));
        });

        UserScope::updateUserScopedOption(self::OPTION_CONVERSATIONS, array_slice($conversations, 0, self::DEFAULT_CONVERSATION_LIMIT, true), false);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function getConversationOption(): array {
        $conversations = UserScope::getUserScopedOption(self::OPTION_CONVERSATIONS, []);
        return is_array($conversations) ? $conversations : [];
    }

    /**
     * @param string $conversationId
     * @return array<string,mixed>|null
     */
    private function getConversationById(string $conversationId): ?array {
        $conversations = $this->getConversationOption();
        return isset($conversations[$conversationId]) && is_array($conversations[$conversationId]) ? $conversations[$conversationId] : null;
    }

    /**
     * @param mixed $message
     * @return array<string,mixed>|null
     */
    private function normalizeConversationMessage($message): ?array {
        if (!is_array($message)) {
            return null;
        }

        $role = isset($message['role']) ? (string) $message['role'] : 'user';
        $content = trim((string) ($message['content'] ?? ''));
        if ($content === '') {
            return null;
        }

        $sources = isset($message['sources']) && is_array($message['sources'])
            ? array_values(array_filter($message['sources'], 'is_array'))
            : [];
        $meta = isset($message['meta']) && is_array($message['meta'])
            ? $message['meta']
            : [];
        $isModelMessage = $role === 'model';

        return [
            'role' => $isModelMessage ? 'model' : 'user',
            'content' => $isModelMessage ? wp_kses_post($content) : sanitize_textarea_field($content),
            'sources' => $sources,
            'meta' => $isModelMessage ? $meta : [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return int
     */
    private function getConversationMessageLength(array $messages): int {
        $total = 0;
        foreach ($messages as $message) {
            $total += strlen(wp_strip_all_tags((string) ($message['content'] ?? '')));
        }

        return $total;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string
     */
    private function buildConversationContextSummary(array $messages): string {
        if (empty($messages)) {
            return '';
        }

        $lines = ['Earlier conversation summary:'];
        $maxLines = 8;
        foreach ($messages as $message) {
            if (!isset($message['content']) || trim($message['content']) === '') {
                continue;
            }

            $prefix = ($message['role'] ?? '') === 'model'
                ? 'Assistant answered: '
                : 'User asked: ';
            $content = wp_strip_all_tags((string) ($message['content'] ?? ''));
            $content = preg_replace(self::REGEX_WHITESPACE, ' ', $content);
            $content = is_string($content) ? trim($content) : (string) ($message['content'] ?? '');

            if (function_exists('mb_strimwidth')) {
                $content = mb_strimwidth($content, 0, 220, '...');
            } elseif (strlen($content) > 220) {
                $content = substr($content, 0, 217) . '...';
            }

            $lines[] = '- ' . $prefix . $content;
            if (count($lines) >= ($maxLines + 1)) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $conversation
     * @return array<string,mixed>
     */
    private function exportConversationSummaryForFrontend(array $conversation): array {
        $messages = isset($conversation['messages']) && is_array($conversation['messages']) ? $conversation['messages'] : [];

        return [
            'id' => (string) ($conversation['id'] ?? ''),
            'summary' => trim((string) ($conversation['summary'] ?? '')) !== '' ? (string) $conversation['summary'] : 'Untitled conversation',
            'savedAt' => (int) (($conversation['last_used_at'] ?? $conversation['started_at'] ?? time()) * 1000),
            'compacted' => trim((string) ($conversation['context_summary'] ?? '')) !== '',
            'firstUserMessage' => $this->extractFirstUserMessageText($messages),
        ];
    }

    /**
     * @param array<string,mixed> $conversation
     * @return array<string,mixed>
     */
    private function exportConversationForFrontend(array $conversation): array {
        $export = $this->exportConversationSummaryForFrontend($conversation);
        $export['messages'] = $this->normalizeConversationMessages(
            isset($conversation['messages']) && is_array($conversation['messages']) ? $conversation['messages'] : []
        );

        return $export;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string
     */
    private function buildConversationSummary(array $messages): string {
        $firstUserMessage = $this->extractFirstUserMessageText($messages);
        if ($firstUserMessage === '') {
            return 'Untitled conversation';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($firstUserMessage, 0, 120, '...');
        }

        return strlen($firstUserMessage) > 120 ? substr($firstUserMessage, 0, 117) . '...' : $firstUserMessage;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    private function extractFirstUserMessageText(array $messages): string {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role !== 'user' || $content === '') {
                continue;
            }

            $normalized = preg_replace(self::REGEX_WHITESPACE, ' ', $content);
            return is_string($normalized) ? trim($normalized) : $content;
        }

        return '';
    }
}
