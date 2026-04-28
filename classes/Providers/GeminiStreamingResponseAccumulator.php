<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Merges Gemini SSE chunks and emits streaming progress snapshots.
 */
class GeminiStreamingResponseAccumulator {
    /**
     * @var callable|null
     */
    private $progressCallback;

    public function __construct($progressCallback = null) {
        $this->progressCallback = is_callable($progressCallback) ? $progressCallback : null;
    }

    /**
     * @param array<int,string> $eventDataLines
     * @param array<string,mixed> $aggregate
     */
    public function flushEventDataLines(array &$eventDataLines, array &$aggregate, array &$streamThoughts, string &$streamAnswer, string &$streamErrorMessage): bool {
        if (empty($eventDataLines)) {
            return true;
        }

        $payload = trim(implode("\n", $eventDataLines));
        $eventDataLines = [];
        if ($payload === '' || $payload === '[DONE]') {
            return true;
        }

        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $streamErrorMessage = 'Could not decode Gemini streaming event: ' . json_last_error_msg();
            return false;
        }

        $this->mergeChunkIntoResult($aggregate, $decoded);
        $this->collectProgressFromChunk($decoded, $streamThoughts, $streamAnswer);
        return true;
    }

    /**
     * @param array<int,string> $segments
     */
    public static function mergeThoughtTextIntoSegments(array &$segments, string $text): void {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalizedText === '') {
            return;
        }

        if (empty($segments)) {
            $segments[] = $normalizedText;
            return;
        }

        $startsNewSegment = self::startsNewThoughtSegment($normalizedText);
        $lastIndex = count($segments) - 1;
        if ($startsNewSegment) {
            $segments[] = $normalizedText;
            return;
        }

        $separator = '';
        $lastSegment = (string) $segments[$lastIndex];
        if (
            $lastSegment !== ''
            && !preg_match('/[\s(\[{\/-]$/u', $lastSegment)
            && !preg_match('/^[\s,.;:!?)]/u', $normalizedText)
        ) {
            $separator = ' ';
        }

        $segments[$lastIndex] = $lastSegment . $separator . $normalizedText;
    }

    private static function startsNewThoughtSegment(string $text): bool {
        if (self::extractMarkdownThoughtSection($text) !== null) {
            return true;
        }

        if (preg_match('/^[A-Z][^\n]{0,100}\n\n[\s\S]+$/u', $text) === 1) {
            return true;
        }

        return self::extractInlineThoughtHeading($text) !== null;
    }

    /**
     * @return array{title:string,body:string}|null
     */
    public static function extractMarkdownThoughtSection(string $text): ?array {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalizedText === '') {
            return null;
        }

        if (preg_match('/^\*\*([^\n*][^\n]*?)\*\*\n\n([\s\S]+)$/u', $normalizedText, $matches) !== 1) {
            return null;
        }

        $title = trim((string) ($matches[1] ?? ''));
        $body = trim((string) ($matches[2] ?? ''));
        if ($title === '' || $body === '') {
            return null;
        }

        return [
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * @return array{title:string,body:string}|null
     */
    public static function extractInlineThoughtHeading(string $text): ?array {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalizedText === '') {
            return null;
        }

        if (preg_match('/^((?:[A-Z][\p{Ll}-]+)(?:\s+[A-Z][\p{Ll}-]+){1,5})\s+([\s\S]+)$/u', $normalizedText, $matches) !== 1) {
            return null;
        }

        $title = trim((string) ($matches[1] ?? ''));
        $body = trim((string) ($matches[2] ?? ''));
        if ($title === '' || $body === '') {
            return null;
        }

        return [
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * @param array<string,mixed> $aggregate
     * @param array<string,mixed> $chunk
     */
    private function mergeChunkIntoResult(array &$aggregate, array $chunk): void {
        foreach ($chunk as $key => $value) {
            if ($key === 'candidates' && is_array($value)) {
                if (!isset($aggregate['candidates']) || !is_array($aggregate['candidates'])) {
                    $aggregate['candidates'] = [];
                }

                foreach ($value as $candidateIndex => $candidateChunk) {
                    if (!is_array($candidateChunk)) {
                        continue;
                    }
                    if (!isset($aggregate['candidates'][$candidateIndex]) || !is_array($aggregate['candidates'][$candidateIndex])) {
                        $aggregate['candidates'][$candidateIndex] = [];
                    }

                    $this->mergeCandidate($aggregate['candidates'][$candidateIndex], $candidateChunk);
                }
                continue;
            }

            $aggregate[$key] = $value;
        }
    }

    /**
     * @param array<string,mixed> $aggregateCandidate
     * @param array<string,mixed> $candidateChunk
     */
    private function mergeCandidate(array &$aggregateCandidate, array $candidateChunk): void {
        foreach ($candidateChunk as $key => $value) {
            if ($key === 'content' && is_array($value)) {
                if (!isset($aggregateCandidate['content']) || !is_array($aggregateCandidate['content'])) {
                    $aggregateCandidate['content'] = [];
                }

                if (isset($value['role'])) {
                    $aggregateCandidate['content']['role'] = $value['role'];
                }

                if (isset($value['parts']) && is_array($value['parts'])) {
                    if (!isset($aggregateCandidate['content']['parts']) || !is_array($aggregateCandidate['content']['parts'])) {
                        $aggregateCandidate['content']['parts'] = [];
                    }

                    foreach ($value['parts'] as $part) {
                        if (is_array($part)) {
                            $aggregateCandidate['content']['parts'][] = $part;
                        }
                    }
                }
                continue;
            }

            $aggregateCandidate[$key] = $value;
        }
    }

    /**
     * @param array<string,mixed> $chunk
     */
    private function collectProgressFromChunk(array $chunk, array &$streamThoughts, string &$streamAnswer): void {
        $candidates = isset($chunk['candidates']) && is_array($chunk['candidates'])
            ? $chunk['candidates']
            : [];
        if (empty($candidates)) {
            return;
        }

        $candidate = is_array($candidates[0] ?? null) ? $candidates[0] : [];
        $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])
            ? $candidate['content']['parts']
            : [];

        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['text'])) {
                continue;
            }

            $text = (string) $part['text'];
            if ($text === '') {
                continue;
            }

            if (!empty($part['thought'])) {
                self::mergeThoughtTextIntoSegments($streamThoughts, $text);
            } else {
                $streamAnswer .= $text;
            }
        }

        if ($this->progressCallback !== null) {
            call_user_func($this->progressCallback, [
                'stage' => $streamAnswer !== '' ? 'answer' : 'thoughts',
                'label' => $streamAnswer !== ''
                    ? 'Drafting answer'
                    : 'Receiving thought process',
                'thoughts' => $streamThoughts,
                'answer_preview' => $streamAnswer,
            ]);
        }
    }
}
