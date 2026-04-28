<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ConversationFrontendExporter {
    private const REGEX_WHITESPACE = '/\s+/';
    private const LABEL_UNTITLED_CONVERSATION = 'Untitled conversation';
    private const COMPACTED_SENTINEL = '__frontend_compacted__';

    /**
     * @param array<string,mixed> $conversation
     * @return array<string,mixed>
     */
    public function exportSummary(array $conversation): array {
        $messages = isset($conversation['messages']) && is_array($conversation['messages']) ? $conversation['messages'] : [];
        $contextSummary = trim((string) ($conversation['context_summary'] ?? ''));

        return [
            'id' => (string) ($conversation['id'] ?? ''),
            'summary' => $this->resolveSummaryLabel((string) ($conversation['summary'] ?? '')),
            'savedAt' => (int) (($conversation['last_used_at'] ?? $conversation['started_at'] ?? time()) * 1000),
            'compacted' => $contextSummary !== '',
            'context_summary' => $contextSummary === self::COMPACTED_SENTINEL ? '' : $contextSummary,
            'firstUserMessage' => $this->extractFirstUserMessageText($messages),
        ];
    }

    /**
     * @param array<string,mixed> $conversation
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    public function export(array $conversation, array $messages): array {
        $export = $this->exportSummary($conversation);
        $export['messages'] = $messages;

        return $export;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    public function buildSummary(array $messages): string {
        $firstUserMessage = $this->extractFirstUserMessageText($messages);
        $summary = self::LABEL_UNTITLED_CONVERSATION;
        if ($firstUserMessage !== '') {
            $summary = $this->truncateText($firstUserMessage, 120);
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    public function buildContextSummary(array $messages): string {
        $summary = '';
        if (!empty($messages)) {
            $lines = ['Earlier conversation summary:'];
            foreach ($messages as $message) {
                $this->appendContextSummaryLine($lines, $message);
                if (count($lines) >= 9) {
                    break;
                }
            }
            $summary = implode("\n", $lines);
        }

        return $summary;
    }

    /**
     * @param array<int,string> $lines
     * @param mixed $message
     */
    private function appendContextSummaryLine(array &$lines, $message): void {
        if (!is_array($message) || !isset($message['content']) || trim((string) $message['content']) === '') {
            return;
        }

        $prefix = ($message['role'] ?? '') === 'model' ? 'Assistant answered: ' : 'User asked: ';
        $content = $this->normalizeText((string) ($message['content'] ?? ''));
        $lines[] = '- ' . $prefix . $this->truncateText($content, 220);
    }

    private function resolveSummaryLabel(string $summary): string {
        $summary = trim($summary);
        return $summary !== '' ? $summary : self::LABEL_UNTITLED_CONVERSATION;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    private function extractFirstUserMessageText(array $messages): string {
        $text = '';
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role === 'user' && $content !== '') {
                $text = $this->normalizeText($content);
                break;
            }
        }

        return $text;
    }

    private function normalizeText(string $text): string {
        $normalized = preg_replace(self::REGEX_WHITESPACE, ' ', wp_strip_all_tags($text));
        return is_string($normalized) ? trim($normalized) : trim($text);
    }

    private function truncateText(string $text, int $width): string {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $width, '...');
        }

        return strlen($text) > $width ? substr($text, 0, max(0, $width - 3)) . '...' : $text;
    }
}
