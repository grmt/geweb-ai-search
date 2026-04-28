<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles Gemini vision-style helper requests such as OCR and image description.
 */
class GeminiVisionClient {
    private string $apiBase;
    private string $apiKey;
    private string $defaultOcrModel;
    private $makeRequestCallback;
    private $extractCandidateTextCallback;
    private $getSummaryTimeoutSecondsCallback;

    /**
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $apiKey, string $defaultOcrModel, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->apiKey = $apiKey;
        $this->defaultOcrModel = $defaultOcrModel;
        $this->makeRequestCallback = isset($callbacks['make_request']) && is_callable($callbacks['make_request']) ? $callbacks['make_request'] : null;
        $this->extractCandidateTextCallback = isset($callbacks['extract_candidate_text']) && is_callable($callbacks['extract_candidate_text']) ? $callbacks['extract_candidate_text'] : null;
        $this->getSummaryTimeoutSecondsCallback = isset($callbacks['get_summary_timeout_seconds']) && is_callable($callbacks['get_summary_timeout_seconds']) ? $callbacks['get_summary_timeout_seconds'] : null;
    }

    public function extractImageText(string $filePath, string $mimeType): string {
        return $this->runImagePrompt(
            $filePath,
            $mimeType,
            'Invalid image MIME type for OCR.',
            'Could not read local image file for OCR.',
            'Extract all visible text from this image faithfully in reading order. Return only the extracted text. If there is no readable text, return an empty response.'
        );
    }

    public function describeImage(string $filePath, string $mimeType): string {
        return $this->runImagePrompt(
            $filePath,
            $mimeType,
            'Invalid image MIME type for description.',
            'Could not read local image file for description.',
            'Describe this image briefly and factually. If it contains important visible text, include the meaningful text in reading order. Do not speculate or embellish.'
        );
    }

    private function runImagePrompt(string $filePath, string $mimeType, string $invalidMimeMessage, string $readErrorMessage, string $prompt): string {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            throw new \Exception($invalidMimeMessage);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception($readErrorMessage);
        }

        $model = apply_filters('geweb_aisearch_gemini_ocr_model', $this->defaultOcrModel);
        $model = is_string($model) && trim($model) !== '' ? trim($model) : $this->defaultOcrModel;
        $url = $this->apiBase . '/models/' . $model . ':generateContent';
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => base64_encode($content),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
            ],
        ];

        $result = $this->makeRequest($url, $body, 'POST', $this->getSummaryTimeoutSeconds());
        return trim($this->extractCandidateText($result));
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = 90): array {
        if ($this->makeRequestCallback === null) {
            throw new \Exception('Gemini vision request callback is not configured.');
        }

        return call_user_func($this->makeRequestCallback, $url, $body, $method, $timeoutSeconds);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function extractCandidateText(array $result): string {
        if ($this->extractCandidateTextCallback === null) {
            return '';
        }

        return (string) call_user_func($this->extractCandidateTextCallback, $result);
    }

    private function getSummaryTimeoutSeconds(): int {
        if ($this->getSummaryTimeoutSecondsCallback === null) {
            return 12;
        }

        return (int) call_user_func($this->getSummaryTimeoutSecondsCallback);
    }
}
