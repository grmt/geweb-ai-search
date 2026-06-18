<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles Gemini vision-style helper requests such as OCR and image description.
 */
class GeminiVisionClient {
    private const FILE_PROCESSING_TIMEOUT_SECONDS = 120;
    private const FILE_PROCESSING_POLL_INTERVAL_SECONDS = 2;

    private string $apiBase;
    private string $apiUploadBase;
    private string $apiKey;
    private string $defaultOcrModel;
    private $makeRequestCallback;
    private $extractCandidateTextCallback;
    private $getSummaryTimeoutSecondsCallback;
    private $getDocumentProcessingTimeoutSecondsCallback;

    /**
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $apiUploadBase, string $apiKey, string $defaultOcrModel, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->apiUploadBase = $apiUploadBase;
        $this->apiKey = $apiKey;
        $this->defaultOcrModel = $defaultOcrModel;
        $this->makeRequestCallback = isset($callbacks['make_request']) && is_callable($callbacks['make_request']) ? $callbacks['make_request'] : null;
        $this->extractCandidateTextCallback = isset($callbacks['extract_candidate_text']) && is_callable($callbacks['extract_candidate_text']) ? $callbacks['extract_candidate_text'] : null;
        $this->getSummaryTimeoutSecondsCallback = isset($callbacks['get_summary_timeout_seconds']) && is_callable($callbacks['get_summary_timeout_seconds']) ? $callbacks['get_summary_timeout_seconds'] : null;
        $this->getDocumentProcessingTimeoutSecondsCallback = isset($callbacks['get_document_processing_timeout_seconds']) && is_callable($callbacks['get_document_processing_timeout_seconds']) ? $callbacks['get_document_processing_timeout_seconds'] : null;
    }

    public function extractImageText(string $filePath, string $mimeType): string {
        return $this->runImagePrompt(
            $filePath,
            $mimeType,
            'Invalid document MIME type for OCR.',
            'Could not read local document file for OCR.',
            'Convert this scanned image or PDF document into clean Markdown. Preserve headings, tables, lists, emphasis, dates, amounts, and reading order. Reconstruct multi-column layouts into a sensible single-column reading flow. Return only the Markdown content, without conversational filler.',
            'You are a professional document digitizer. Convert scanned documents into clean, high-fidelity Markdown. Preserve structural elements such as headers, tables, bold text, lists, dates, amounts, and page-relevant labels. If a page has a multi-column layout, reconstruct it into a single-column flow that makes sense for reading.'
        );
    }

    public function describeImage(string $filePath, string $mimeType): string {
        return $this->runImagePrompt(
            $filePath,
            $mimeType,
            'Invalid document MIME type for description.',
            'Could not read local document file for description.',
            'Describe this image or PDF document briefly and factually. If it contains important visible text, include the meaningful text in reading order. Do not speculate or embellish.'
        );
    }

    private function runImagePrompt(string $filePath, string $mimeType, string $invalidMimeMessage, string $readErrorMessage, string $prompt, string $systemInstruction = ''): string {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || (strpos($mimeType, 'image/') !== 0 && $mimeType !== 'application/pdf')) {
            throw new \Exception($invalidMimeMessage);
        }

        $model = apply_filters('geweb_aisearch_gemini_ocr_model', $this->defaultOcrModel);
        $model = is_string($model) && trim($model) !== '' ? trim($model) : $this->defaultOcrModel;
        $url = $this->apiBase . '/models/' . $model . ':generateContent';
        $timeoutSeconds = $this->getDocumentProcessingTimeoutSeconds($mimeType);

        if ($mimeType === 'application/pdf') {
            $result = $this->runUploadedFilePrompt($url, $filePath, $mimeType, $prompt, $systemInstruction, $timeoutSeconds, $readErrorMessage);
            return trim($this->extractCandidateText($result));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception($readErrorMessage);
        }

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
                'temperature' => $systemInstruction !== '' ? 0.1 : 0,
            ],
        ];
        if ($systemInstruction !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        $result = $this->makeRequest($url, $body, 'POST', $timeoutSeconds);
        return trim($this->extractCandidateText($result));
    }

    /**
     * @return array<string,mixed>
     */
    private function runUploadedFilePrompt(string $generateUrl, string $filePath, string $mimeType, string $prompt, string $systemInstruction, int $timeoutSeconds, string $readErrorMessage): array {
        $uploadedFile = $this->uploadPromptFile($filePath, $mimeType, $readErrorMessage);

        try {
            $uploadedFile = $this->waitForPromptFile($uploadedFile);
            $fileUri = trim((string) ($uploadedFile['uri'] ?? ''));
            if ($fileUri === '') {
                throw new \Exception('Gemini file upload returned no file URI.');
            }

            $body = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        [
                            'file_data' => [
                                'mime_type' => $mimeType,
                                'file_uri' => $fileUri,
                            ],
                        ],
                        ['text' => $prompt],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => $systemInstruction !== '' ? 0.1 : 0,
                ],
            ];
            if ($systemInstruction !== '') {
                $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
            }

            return $this->makeRequest($generateUrl, $body, 'POST', $timeoutSeconds);
        } finally {
            $this->deletePromptFile($uploadedFile);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadPromptFile(string $filePath, string $mimeType, string $readErrorMessage): array {
        $fileSize = @filesize($filePath);
        if (!is_int($fileSize) && !is_float($fileSize)) {
            throw new \Exception($readErrorMessage);
        }

        $fileSize = (int) $fileSize;
        $displayName = basename($filePath);
        $startUrl = $this->apiUploadBase . '/files?key=' . rawurlencode($this->apiKey);
        $metadata = wp_json_encode(['file' => ['display_name' => $displayName]]);
        if (!is_string($metadata) || $metadata === '') {
            throw new \Exception('Could not encode Gemini file upload metadata.');
        }

        $startResponse = wp_remote_post($startUrl, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $fileSize,
                'X-Goog-Upload-Header-Content-Type' => $mimeType,
                'x-goog-api-key' => $this->apiKey,
            ],
            'body' => $metadata,
        ]);

        if (is_wp_error($startResponse)) {
            throw new \Exception('Gemini file upload start failed: ' . $startResponse->get_error_message());
        }

        $startCode = wp_remote_retrieve_response_code($startResponse);
        if ($startCode < 200 || $startCode >= 300) {
            throw new \Exception('Gemini file upload start failed with HTTP code ' . $startCode);
        }

        $uploadUrl = $this->extractHeaderValue($startResponse, 'x-goog-upload-url');
        if ($uploadUrl === '') {
            throw new \Exception('Gemini file upload start returned no upload URL.');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception($readErrorMessage);
        }

        $uploadResponse = wp_remote_post($uploadUrl, [
            'timeout' => $this->getDocumentProcessingTimeoutSeconds($mimeType),
            'headers' => [
                'Content-Length' => (string) strlen($content),
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
                'x-goog-api-key' => $this->apiKey,
            ],
            'body' => $content,
        ]);

        if (is_wp_error($uploadResponse)) {
            throw new \Exception('Gemini file upload failed: ' . $uploadResponse->get_error_message());
        }

        $uploadCode = wp_remote_retrieve_response_code($uploadResponse);
        $uploadBody = wp_remote_retrieve_body($uploadResponse);
        if ($uploadCode < 200 || $uploadCode >= 300) {
            throw new \Exception('Gemini file upload failed with HTTP code ' . $uploadCode);
        }

        $result = json_decode($uploadBody, true);
        if (!is_array($result) || !isset($result['file']) || !is_array($result['file'])) {
            throw new \Exception('Gemini file upload returned an invalid response.');
        }

        return $result['file'];
    }

    /**
     * @param array<string,mixed> $uploadedFile
     * @return array<string,mixed>
     */
    private function waitForPromptFile(array $uploadedFile): array {
        $name = trim((string) ($uploadedFile['name'] ?? ''));
        if ($name === '') {
            return $uploadedFile;
        }

        $startedAt = time();
        do {
            $state = strtoupper(trim((string) ($uploadedFile['state'] ?? '')));
            if ($state === '' || $state === 'ACTIVE') {
                return $uploadedFile;
            }

            if ($state === 'FAILED') {
                throw new \Exception('Gemini file processing failed.');
            }

            if ((time() - $startedAt) >= self::FILE_PROCESSING_TIMEOUT_SECONDS) {
                throw new \Exception('Gemini file processing did not complete in time.');
            }

            sleep(self::FILE_PROCESSING_POLL_INTERVAL_SECONDS);
            $uploadedFile = $this->fetchPromptFile($name);
        } while (true);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPromptFile(string $name): array {
        $url = $this->apiBase . '/' . ltrim($name, '/') . '?key=' . rawurlencode($this->apiKey);
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Gemini file status check failed: ' . $response->get_error_message());
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('Gemini file status check failed with HTTP code ' . $httpCode);
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            throw new \Exception('Gemini file status check returned an invalid response.');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $uploadedFile
     */
    private function deletePromptFile(array $uploadedFile): void {
        $name = trim((string) ($uploadedFile['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $url = $this->apiBase . '/' . ltrim($name, '/') . '?key=' . rawurlencode($this->apiKey);
        wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
            ],
        ]);
    }

    /**
     * @param array<string,mixed>|\WP_HTTP_Requests_Response $response
     */
    private function extractHeaderValue($response, string $headerName): string {
        $headers = wp_remote_retrieve_headers($response);
        if (is_object($headers) && method_exists($headers, 'offsetGet')) {
            return trim((string) $headers->offsetGet($headerName));
        }

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $headerName) === 0) {
                    return trim((string) (is_array($value) ? reset($value) : $value));
                }
            }
        }

        return '';
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

    private function getDocumentProcessingTimeoutSeconds(string $mimeType): int {
        if ($this->getDocumentProcessingTimeoutSecondsCallback === null) {
            return $this->getSummaryTimeoutSeconds();
        }

        return (int) call_user_func($this->getDocumentProcessingTimeoutSecondsCallback, $mimeType);
    }
}
