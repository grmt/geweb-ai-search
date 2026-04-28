<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles Gemini File Search uploads, upload polling, and document deletion.
 */
class GeminiUploadClient {
    private string $apiBase;
    private string $apiUploadBase;
    private string $apiKey;
    private int $maxUploadFileBytes;
    private $getStoreDataCallback;
    private $clearStoresCacheCallback;
    private $formatByteSizeCallback;
    private $makeRequestCallback;
    private $getUploadOperationTimeoutSecondsCallback;
    private $getUploadOperationPollIntervalMsCallback;
    private $logInfoCallback;
    private $logErrorCallback;

    /**
     * @param array<string,mixed> $options
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $apiUploadBase, string $apiKey, array $options, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->apiUploadBase = $apiUploadBase;
        $this->apiKey = $apiKey;
        $this->maxUploadFileBytes = (int) ($options['max_upload_file_bytes'] ?? 104857600);
        $this->getStoreDataCallback = isset($callbacks['get_store_data']) && is_callable($callbacks['get_store_data']) ? $callbacks['get_store_data'] : null;
        $this->clearStoresCacheCallback = isset($callbacks['clear_stores_cache']) && is_callable($callbacks['clear_stores_cache']) ? $callbacks['clear_stores_cache'] : null;
        $this->formatByteSizeCallback = isset($callbacks['format_byte_size']) && is_callable($callbacks['format_byte_size']) ? $callbacks['format_byte_size'] : null;
        $this->makeRequestCallback = isset($callbacks['make_request']) && is_callable($callbacks['make_request']) ? $callbacks['make_request'] : null;
        $this->getUploadOperationTimeoutSecondsCallback = isset($callbacks['get_upload_operation_timeout_seconds']) && is_callable($callbacks['get_upload_operation_timeout_seconds']) ? $callbacks['get_upload_operation_timeout_seconds'] : null;
        $this->getUploadOperationPollIntervalMsCallback = isset($callbacks['get_upload_operation_poll_interval_ms']) && is_callable($callbacks['get_upload_operation_poll_interval_ms']) ? $callbacks['get_upload_operation_poll_interval_ms'] : null;
        $this->logInfoCallback = isset($callbacks['log_info']) && is_callable($callbacks['log_info']) ? $callbacks['log_info'] : null;
        $this->logErrorCallback = isset($callbacks['log_error']) && is_callable($callbacks['log_error']) ? $callbacks['log_error'] : null;
    }

    public function uploadDocument(string $content, int $postId): string {
        return $this->uploadNamedDocument($content, "{$postId}.md");
    }

    public function uploadNamedDocument(string $content, string $displayName): string {
        return $this->uploadMultipartDocument($content, $displayName, 'text/markdown');
    }

    public function uploadLocalFile(string $filePath, string $displayName, string $mimeType): string {
        $fileSize = @filesize($filePath);
        if (is_int($fileSize) || is_float($fileSize)) {
            $fileSize = (int) $fileSize;
            if ($fileSize > $this->maxUploadFileBytes) {
                throw new \Exception(sprintf(
                    'File is too large for Gemini File Search upload (%s). Maximum allowed size is 100 MB.',
                    $this->formatByteSize($fileSize)
                ));
            }
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception('Could not read local file for upload.');
        }

        return $this->uploadMultipartDocument($content, $displayName, $mimeType);
    }

    public function deleteDocument(string $documentName): void {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        $url = $this->apiBase . '/' . $documentName . '?force=1';
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html('Delete failed: ' . $response->get_error_message()));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode === 404) {
            $this->clearStoresCache();
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html('Delete failed with HTTP code ' . $httpCode));
        }

        $this->clearStoresCache();
    }

    private function uploadMultipartDocument(string $content, string $displayName, string $mimeType): string {
        $storeName = $this->getStoreData();
        if ($this->apiKey === '' || $storeName === '') {
            throw new ConfigurationException('Configuration error');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mimeType) !== 1) {
            throw new \Exception(sprintf('Invalid MIME type for upload: %s', $mimeType !== '' ? $mimeType : '[empty]'));
        }

        $responseMeta = $this->performMultipartUploadRequest($storeName, $content, $displayName, $mimeType, true);
        $httpCode = $responseMeta['http_code'];
        $responseBody = $responseMeta['body'];
        $elapsedMs = $responseMeta['elapsed_ms'];

        if ($httpCode === 400 && $this->shouldRetrySpreadsheetUploadWithoutMimeType($displayName, $mimeType, $responseBody)) {
            $this->logInfo(sprintf(
                'retrying spreadsheet upload without explicit mimeType metadata displayName="%s" original_mimeType="%s"',
                $displayName,
                $mimeType
            ));
            $responseMeta = $this->performMultipartUploadRequest($storeName, $content, $displayName, $mimeType, false);
            $httpCode = $responseMeta['http_code'];
            $responseBody = $responseMeta['body'];
            $elapsedMs = $responseMeta['elapsed_ms'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html(sprintf(
                'Upload failed after %d ms with HTTP code %d',
                $elapsedMs,
                $httpCode
            )));
        }

        $result = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError(sprintf(
                'multipart upload JSON decode failure displayName="%s" elapsed_ms=%d json_error="%s"',
                $displayName,
                $elapsedMs,
                json_last_error_msg()
            ));
            throw new \Exception(esc_html(sprintf(
                'Upload returned invalid JSON after %d ms: %s',
                $elapsedMs,
                json_last_error_msg()
            )));
        }

        $documentName = $this->extractUploadDocumentName($result);
        if ($documentName === '' && $this->isUploadOperationResponse($result)) {
            $operationName = trim((string) ($result['name'] ?? ''));
            $this->logInfo(sprintf(
                'multipart upload queued operation="%s" displayName="%s" initial_elapsed_ms=%d',
                $operationName,
                $displayName,
                $elapsedMs
            ));

            $operationResult = $this->waitForUploadOperation($operationName, $displayName, $elapsedMs);
            $documentName = $this->extractUploadDocumentName($operationResult);
            $elapsedMs = isset($operationResult['_geweb_elapsed_ms']) ? (int) $operationResult['_geweb_elapsed_ms'] : $elapsedMs;
        }

        if ($documentName === '') {
            $this->logError(sprintf(
                'multipart upload missing documentName displayName="%s" elapsed_ms=%d',
                $displayName,
                $elapsedMs
            ));
            throw new \Exception(sprintf(
                'Upload completed after %d ms but returned an invalid response',
                $elapsedMs
            ));
        }

        $this->logInfo(sprintf(
            'multipart upload completed displayName="%s" document_name="%s" elapsed_ms=%d',
            $displayName,
            $documentName,
            $elapsedMs
        ));

        $this->clearStoresCache();

        return $documentName;
    }

    /**
     * @return array{http_code:int,body:string,elapsed_ms:int}
     */
    private function performMultipartUploadRequest(string $storeName, string $content, string $displayName, string $mimeType, bool $includeMimeTypeMetadata): array {
        $url = $this->apiUploadBase . '/' . $storeName . ':uploadToFileSearchStore?key=' . $this->apiKey;
        $boundary = uniqid();
        $metadata = ['displayName' => $displayName];
        if ($includeMimeTypeMetadata) {
            $metadata['mimeType'] = $mimeType;
        }

        $metadataJson = wp_json_encode($metadata);
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadataJson . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--";

        $payloadBytes = strlen($body);
        $contentBytes = strlen($content);
        $startedAt = microtime(true);
        $this->logInfo(sprintf(
            'multipart upload starting displayName="%s" mimeType="%s" metadata_mimeType=%s content_bytes=%d payload_bytes=%d timeout_seconds=%d store="%s"',
            $displayName,
            $mimeType,
            $includeMimeTypeMetadata ? 'yes' : 'no',
            $contentBytes,
            $payloadBytes,
            120,
            $storeName
        ));

        $attempt = 0;
        $maxAttempts = 2;

        do {
            $attempt++;
            $response = wp_remote_post($url, [
                'timeout' => 120,
                'headers' => [
                    'Content-Type' => "multipart/related; boundary={$boundary}",
                    'Content-Length' => (string) $payloadBytes,
                    'x-goog-api-key' => $this->apiKey,
                    'X-Goog-Upload-Protocol' => 'multipart',
                ],
                'body' => $body,
            ]);

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            if (is_wp_error($response)) {
                $this->logError(sprintf(
                    'multipart upload transport failure after %d ms displayName="%s" metadata_mimeType=%s attempt=%d/%d message="%s"',
                    $elapsedMs,
                    $displayName,
                    $includeMimeTypeMetadata ? 'yes' : 'no',
                    $attempt,
                    $maxAttempts,
                    $response->get_error_message()
                ));
                throw new \Exception(sprintf(
                    'Upload failed during transport after %d ms: %s',
                    $elapsedMs,
                    $response->get_error_message()
                ));
            }

            $httpCode = wp_remote_retrieve_response_code($response);
            $responseBody = wp_remote_retrieve_body($response);
            $responseBytes = strlen($responseBody);
            $this->logInfo(sprintf(
                'multipart upload response received displayName="%s" metadata_mimeType=%s attempt=%d/%d http_code=%d elapsed_ms=%d response_bytes=%d',
                $displayName,
                $includeMimeTypeMetadata ? 'yes' : 'no',
                $attempt,
                $maxAttempts,
                $httpCode,
                $elapsedMs,
                $responseBytes
            ));

            if ($httpCode < 200 || $httpCode >= 300) {
                $responseSnippet = trim(substr((string) preg_replace('/\s+/', ' ', $responseBody), 0, 300));
                $this->logError(sprintf(
                    'multipart upload API failure displayName="%s" metadata_mimeType=%s attempt=%d/%d http_code=%d elapsed_ms=%d response_snippet="%s"',
                    $displayName,
                    $includeMimeTypeMetadata ? 'yes' : 'no',
                    $attempt,
                    $maxAttempts,
                    $httpCode,
                    $elapsedMs,
                    $responseSnippet
                ));

                if ($this->shouldRetryTransientMultipartUpload($displayName, $mimeType, $httpCode, $responseBody, $attempt, $maxAttempts)) {
                    $this->logInfo(sprintf(
                        'retrying multipart upload after transient API failure displayName="%s" metadata_mimeType=%s attempt=%d/%d http_code=%d',
                        $displayName,
                        $includeMimeTypeMetadata ? 'yes' : 'no',
                        $attempt,
                        $maxAttempts,
                        $httpCode
                    ));
                    sleep(2);
                    continue;
                }
            }

            return [
                'http_code' => (int) $httpCode,
                'body' => (string) $responseBody,
                'elapsed_ms' => $elapsedMs,
            ];
        } while ($attempt < $maxAttempts);

        return [
            'http_code' => (int) $httpCode,
            'body' => (string) $responseBody,
            'elapsed_ms' => $elapsedMs,
        ];
    }

    private function shouldRetrySpreadsheetUploadWithoutMimeType(string $displayName, string $mimeType, string $responseBody): bool {
        $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));
        $isSpreadsheet = in_array($extension, ['xls', 'xlsx'], true)
            || strpos($mimeType, 'spreadsheetml') !== false
            || strpos($mimeType, 'ms-excel') !== false;

        if (!$isSpreadsheet) {
            return false;
        }

        $normalizedBody = strtolower((string) preg_replace('/\s+/', ' ', $responseBody));
        return strpos($normalizedBody, 'mime type must be in a valid type/subtype format') !== false
            || strpos($normalizedBody, 'uploadtofilesearchstorerequest.mime_type') !== false;
    }

    private function shouldRetryTransientMultipartUpload(string $displayName, string $mimeType, int $httpCode, string $responseBody, int $attempt, int $maxAttempts): bool {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));
        $isSpreadsheet = in_array($extension, ['xls', 'xlsx'], true)
            || strpos($mimeType, 'spreadsheetml') !== false
            || strpos($mimeType, 'ms-excel') !== false;

        if (!$isSpreadsheet) {
            return false;
        }

        $normalizedBody = strtolower((string) preg_replace('/\s+/', ' ', $responseBody));
        return $httpCode === 503
            && (
                strpos($normalizedBody, 'failed to count tokens') !== false
                || strpos($normalizedBody, 'unavailable') !== false
            );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractUploadDocumentName(array $payload): string {
        $candidates = [
            $payload['response']['documentName'] ?? '',
            $payload['response']['document_name'] ?? '',
            $payload['documentName'] ?? '',
            $payload['document_name'] ?? '',
            $payload['response']['name'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && strpos($value, 'fileSearchStores/') !== false) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isUploadOperationResponse(array $payload): bool {
        $name = trim((string) ($payload['name'] ?? ''));
        return $name !== ''
            && strpos($name, 'fileSearchStores/') === 0
            && strpos($name, '/operations/') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    private function waitForUploadOperation(string $operationName, string $displayName, int $initialElapsedMs = 0): array {
        $operationName = trim($operationName);
        if ($operationName === '') {
            throw new \Exception('Upload operation name is missing.');
        }

        $timeoutSeconds = $this->getUploadOperationTimeoutSeconds();
        $pollIntervalMs = $this->getUploadOperationPollIntervalMs();
        $startedAt = microtime(true);
        $attempt = 0;

        do {
            $attempt++;
            $result = $this->fetchUploadOperation($operationName);
            $elapsedMs = $initialElapsedMs + (int) round((microtime(true) - $startedAt) * 1000);
            $done = !empty($result['done']);
            error_log(sprintf(
                'geweb-ai-search: upload operation poll operation="%s" displayName="%s" attempt=%d done=%s elapsed_ms=%d',
                $operationName,
                $displayName,
                $attempt,
                $done ? 'true' : 'false',
                $elapsedMs
            ));

            if (!empty($result['error']) && is_array($result['error'])) {
                $message = trim((string) ($result['error']['message'] ?? 'Upload operation failed.'));
                $code = isset($result['error']['code']) ? (int) $result['error']['code'] : 0;
                error_log(sprintf(
                    'geweb-ai-search: upload operation error operation="%s" code=%d message="%s" elapsed_ms=%d',
                    $operationName,
                    $code,
                    $message,
                    $elapsedMs
                ));
                throw new \Exception(sprintf('Upload operation failed after %d ms: %s', $elapsedMs, $message));
            }

            if ($done) {
                $result['_geweb_elapsed_ms'] = $elapsedMs;
                return $result;
            }

            if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
                throw new \Exception(sprintf(
                    'Upload operation did not complete within %d ms',
                    $initialElapsedMs + ($timeoutSeconds * 1000)
                ));
            }

            usleep(max(0, $pollIntervalMs) * 1000);
        } while (true);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchUploadOperation(string $operationName): array {
        $url = $this->apiBase . '/' . ltrim($operationName, '/');
        return $this->makeRequest($url, null, 'GET', 30);
    }

    private function getStoreData(): string {
        if ($this->getStoreDataCallback === null) {
            return '';
        }

        return (string) call_user_func($this->getStoreDataCallback);
    }

    private function clearStoresCache(): void {
        if ($this->clearStoresCacheCallback !== null) {
            call_user_func($this->clearStoresCacheCallback);
        }
    }

    private function formatByteSize(int $bytes): string {
        if ($this->formatByteSizeCallback !== null) {
            return (string) call_user_func($this->formatByteSizeCallback, $bytes);
        }

        return (string) $bytes;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = 90): array {
        if ($this->makeRequestCallback === null) {
            throw new \Exception('Gemini upload request callback is not configured.');
        }

        return call_user_func($this->makeRequestCallback, $url, $body, $method, $timeoutSeconds);
    }

    private function getUploadOperationTimeoutSeconds(): int {
        if ($this->getUploadOperationTimeoutSecondsCallback === null) {
            return 300;
        }

        return (int) call_user_func($this->getUploadOperationTimeoutSecondsCallback);
    }

    private function getUploadOperationPollIntervalMs(): int {
        if ($this->getUploadOperationPollIntervalMsCallback === null) {
            return 5000;
        }

        return (int) call_user_func($this->getUploadOperationPollIntervalMsCallback);
    }

    private function logInfo(string $message): void {
        if ($this->logInfoCallback !== null) {
            call_user_func($this->logInfoCallback, $message);
        }
    }

    private function logError(string $message): void {
        if ($this->logErrorCallback !== null) {
            call_user_func($this->logErrorCallback, $message);
        }
    }
}
