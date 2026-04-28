<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AiChatContextRefiner {
    private const RECENT_MESSAGE_LIMIT = 5;
    private const SUMMARY_POINT_LIMIT = 5;
    private const SUMMARY_RETRY_OLDER_MESSAGE_LIMIT = 12;

    private ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager) {
        $this->conversationManager = $conversationManager;
    }

    /**
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @param array<int,array<string,mixed>> $fullMessages
     * @return array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool}
     */
    public function refine(array $context, array $fullMessages, AIProviderInterface $provider, string $selectedModel, string $conversationId): array {
        if (empty($context['compacted'])) {
            return $context;
        }

        $recentMessages = array_slice($fullMessages, -self::RECENT_MESSAGE_LIMIT);
        $olderCount = max(0, count($fullMessages) - count($recentMessages));
        $olderMessages = $olderCount > 0 ? array_slice($fullMessages, 0, $olderCount) : [];
        $summaryText = $this->resolveContextSummary($context, $provider, $olderMessages, $selectedModel, $conversationId);

        return [
            'messages' => $this->buildRefinedContextMessages($summaryText, $recentMessages),
            'summary' => $summaryText,
            'compacted' => true,
        ];
    }

    /**
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @param array<int,array<string,mixed>> $olderMessages
     */
    private function resolveContextSummary(array $context, AIProviderInterface $provider, array $olderMessages, string $selectedModel, string $conversationId): string {
        $summaryText = trim((string) ($context['summary'] ?? ''));
        if (!$provider instanceof Gemini || empty($olderMessages)) {
            return $summaryText;
        }

        $apiSummary = $this->buildApiContextSummaryWithRetry(
            $provider,
            $olderMessages,
            $selectedModel,
            $this->conversationManager->getConversationContextSummary($conversationId)
        );

        return $apiSummary !== '' ? $apiSummary : $summaryText;
    }

    /**
     * @param array<int,array<string,mixed>> $recentMessages
     * @return array<int,array{role:string,content:string}>
     */
    private function buildRefinedContextMessages(string $summaryText, array $recentMessages): array {
        $contextMessages = [];
        if ($summaryText !== '') {
            $contextMessages[] = [
                'role' => 'user',
                'content' => $summaryText,
            ];
        }

        foreach ($recentMessages as $message) {
            $normalized = $this->normalizeContextMessage($message);
            if ($normalized !== null) {
                $contextMessages[] = $normalized;
            }
        }

        return $contextMessages;
    }

    /**
     * @param mixed $message
     * @return array{role:string,content:string}|null
     */
    private function normalizeContextMessage($message): ?array {
        if (!is_array($message)) {
            return null;
        }

        $content = trim((string) ($message['content'] ?? ''));
        if ($content === '') {
            return null;
        }

        $role = isset($message['role']) ? (string) $message['role'] : 'user';
        return [
            'role' => $role === 'model' ? 'model' : 'user',
            'content' => $content,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $olderMessages
     */
    private function buildApiContextSummaryWithRetry(Gemini $provider, array $olderMessages, string $selectedModel, string $previousSummary): string {
        foreach ($this->buildSummaryAttempts($olderMessages) as $attempt) {
            try {
                $summary = trim((string) $provider->summarizeConversationForContext(
                    $attempt['messages'],
                    $selectedModel,
                    $attempt['max_items'],
                    $previousSummary
                ));

                if ($summary !== '') {
                    return $summary;
                }
            } catch (\Exception $e) {
                // Retry with the next smaller attempt payload.
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $olderMessages
     * @return array<int,array{messages:array<int,array<string,mixed>>,max_items:int}>
     */
    private function buildSummaryAttempts(array $olderMessages): array {
        return [
            [
                'messages' => $olderMessages,
                'max_items' => self::SUMMARY_POINT_LIMIT,
            ],
            [
                'messages' => array_slice($olderMessages, -self::SUMMARY_RETRY_OLDER_MESSAGE_LIMIT),
                'max_items' => max(3, self::SUMMARY_POINT_LIMIT - 1),
            ],
        ];
    }
}
