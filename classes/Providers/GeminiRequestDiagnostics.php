<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Tracks Gemini request attempts and formats provider failures for user-facing UI.
 */
class GeminiRequestDiagnostics {
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $attempts = [];

    public function reset(): void {
        $this->attempts = [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAttempts(): array {
        return $this->attempts;
    }

    public function recordAttempt(
        string $method,
        int $attempt,
        int $maxAttempts,
        int $overallAttempt,
        int $overallAttemptMax,
        string $retryTriplet,
        string $model,
        int $startedAt,
        int $elapsedMs,
        int $httpCode,
        string $status,
        string $message = ''
    ): void {
        $entry = [
            'started_at' => $startedAt,
            'finished_at' => time(),
            'elapsed_ms' => $elapsedMs,
            'method' => $method,
            'attempt' => $attempt,
            'attempt_max' => $maxAttempts,
            'overall_attempt' => $overallAttempt,
            'overall_attempt_max' => $overallAttemptMax,
            'retry_triplet' => $retryTriplet,
            'status' => sanitize_key($status),
            'model' => $model,
        ];

        if ($httpCode > 0) {
            $entry['http_code'] = $httpCode;
        }

        $normalizedMessage = $this->normalizeRemoteErrorMessage($message);
        if ($normalizedMessage !== '') {
            $entry['message'] = $normalizedMessage;
        }

        $this->attempts[] = $entry;
    }

    public function isSpendingCapError(string $responseBody): bool {
        return stripos($responseBody, 'monthly spending cap') !== false
            || stripos($responseBody, 'spend cap') !== false;
    }

    public function formatApiFailureMessage(string $prefix, int $elapsedMs, int $httpCode, string $responseBody): string {
        $status = '';
        $remoteMessage = '';
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $status = trim((string) ($decoded['error']['status'] ?? ''));
            $remoteMessage = trim((string) ($decoded['error']['message'] ?? ''));
        }

        if ($httpCode === 429 && $this->isSpendingCapError($responseBody)) {
            return sprintf(
                '%s after %d ms with HTTP code 429: the Google AI Studio project has exceeded its monthly spending cap. Increase the spend cap or switch to another API key/project, then retry.',
                $prefix,
                $elapsedMs
            );
        }

        if ($httpCode === 429 && $status === 'RESOURCE_EXHAUSTED') {
            return sprintf(
                '%s after %d ms with HTTP code 429: the Gemini API quota or spending limit has been reached for this project.',
                $prefix,
                $elapsedMs
            );
        }

        if ($remoteMessage !== '') {
            return sprintf(
                '%s after %d ms with HTTP code %d: %s%s',
                $prefix,
                $elapsedMs,
                $httpCode,
                $this->normalizeRemoteErrorMessage($remoteMessage),
                $status !== '' ? ' (' . $status . ')' : ''
            );
        }

        $snippet = trim((string) preg_replace('/\s+/', ' ', $responseBody));
        if (strlen($snippet) > 220) {
            $snippet = substr($snippet, 0, 217) . '...';
        }

        return sprintf(
            '%s after %d ms with HTTP code %d%s',
            $prefix,
            $elapsedMs,
            $httpCode,
            $snippet !== '' ? ': ' . $this->normalizeRemoteErrorMessage($snippet) : '.'
        );
    }

    private function normalizeRemoteErrorMessage(string $message): string {
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = wp_strip_all_tags($message);
        $message = (string) preg_replace('/https?:\/\/\S+/i', '', $message);
        $message = (string) preg_replace('/\s+/', ' ', $message);
        return trim($message);
    }
}
