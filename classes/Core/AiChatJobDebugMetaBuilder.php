<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AiChatJobDebugMetaBuilder {
    /**
     * @param array<string,mixed> $result
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @param array<int,array{key:string,title:string,url:string}> $excludedSources
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @return array<string,mixed>
     */
    public function attach(array $result, array $context, string $selectedModel, string $temporaryPrompt, array $excludedSources, array $thoughtHistory = []): array {
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
        $meta['request'] = [
            'created_at' => time(),
            'compacted' => !empty($context['compacted']),
            'context_summary' => trim((string) ($context['summary'] ?? '')),
            'context_message_count' => count($context['messages']),
            'model' => trim($selectedModel),
            'temporary_prompt_active' => trim($temporaryPrompt) !== '',
            'excluded_source_count' => count($excludedSources),
            'excluded_sources' => $this->buildExcludedSourceDebugLabels($excludedSources),
            'messages_preview' => $this->buildContextMessagePreview($context['messages']),
        ];

        if (!empty($thoughtHistory)) {
            $meta['thought_history'] = array_values(array_filter($thoughtHistory, static function ($entry): bool {
                return is_array($entry) && !empty($entry['thoughts']) && is_array($entry['thoughts']);
            }));
            $meta['request']['thought_history_updates'] = count($meta['thought_history']);
        }

        $result['meta'] = $meta;
        return $result;
    }

    /**
     * @param array<int,array{key:string,title:string,url:string}> $excludedSources
     * @return array<int,string>
     */
    private function buildExcludedSourceDebugLabels(array $excludedSources): array {
        $labels = [];
        foreach ($excludedSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $label = $this->buildExcludedSourceDebugLabel($source);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    private function buildExcludedSourceDebugLabel(array $source): string {
        $title = trim((string) ($source['title'] ?? ''));
        $url = trim((string) ($source['url'] ?? ''));
        $label = trim((string) ($source['key'] ?? ''));
        if ($url !== '') {
            $label = $url;
        }
        if ($title !== '') {
            $label = $title;
        }

        return $label;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array{role:string,content:string}>
     */
    private function buildContextMessagePreview(array $messages): array {
        $preview = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $content = $this->trimPreviewContent((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $preview[] = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => $content,
            ];
        }

        return $preview;
    }

    private function trimPreviewContent(string $content): string {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($content, 0, 320, '...');
        }

        return strlen($content) > 320 ? substr($content, 0, 317) . '...' : $content;
    }
}
