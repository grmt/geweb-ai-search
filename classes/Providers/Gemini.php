<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Configuration exception
 */
class ConfigurationException extends \Exception {}

/**
 * Gemini AI Provider
 *
 * Handles all interactions with Google Gemini API
 */
class Gemini implements AIProviderInterface {
    /**
     * API endpoints
     */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const API_UPLOAD_BASE = 'https://generativelanguage.googleapis.com/upload/v1beta';

    /**
     * Option key for storing File Search Store data
     */
    private const OPTION_STORE = 'geweb_aisearch_gemini_store';

    /**
     * Option key for Model
     */
    private const OPTION_MODEL = 'geweb_aisearch_model';
    private const OPTION_MODEL_SELECTION_MODE = 'geweb_aisearch_model_selection_mode';
    private const OPTION_MODEL_STATUS = 'geweb_aisearch_model_status';
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    public const OPTION_TIMEOUT_FLASH = 'geweb_aisearch_timeout_flash';
    public const OPTION_TIMEOUT_PRO = 'geweb_aisearch_timeout_pro';
    public const OPTION_SYSTEM_RETRIES = 'geweb_aisearch_gemini_system_retries';
    public const OPTION_HUMAN_RETRIES = 'geweb_aisearch_gemini_human_retries';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const OPTION_STORES_CACHE = 'geweb_aisearch_gemini_stores_cache';
    private const OPTION_STORES_CACHE_TIME = 'geweb_aisearch_gemini_stores_cache_time';
    private const OPTION_STORES_CACHE_ERROR = 'geweb_aisearch_gemini_stores_cache_error';
    private const OPTION_STORE_DOCUMENTS_CACHE = 'geweb_aisearch_gemini_store_documents_cache';
    private const OPTION_STORE_DOCUMENTS_CACHE_TIME = 'geweb_aisearch_gemini_store_documents_cache_time';
    private const STORE_OVERVIEW_CACHE_MAX_AGE = DAY_IN_SECONDS;
    private const STORE_DOCUMENTS_CACHE_MAX_AGE = DAY_IN_SECONDS;

    /**
     * Option key for custom system instruction
     */
    private const OPTION_CUSTOM_PROMPT = 'geweb_aisearch_custom_prompt';

    /**
     * Default system instruction
     */
    private const DEFAULT_SYSTEM_INSTRUCTION = "You are a knowledge base search assistant.\n\n" .
        "Your task:\n" .
        "1. Summarize the information from the documents in your own words. Avoid direct long quotes.\n" .
        "2. Provide a clear answer to the user's question\n" .
        "3. Extract the URL from the frontmatter of each document used (line 'url: ...')\n" .
        "4. Return a list of sources with URLs and page titles\n\n" .
        "Rules:\n" .
        "- Answer briefly in your own words based on the provided data\n" .
        "- Use only information from the found documents\n" .
        "- If there's no information — say so\n" .
        "- Add to sources only the pages you actually used for the answer\n" .
        "- Do not use markdown in response, change it to html\n" .
        "- URL is taken from the document's frontmatter (---\\nurl: ...\\n---)\n" .
        "- Title is taken from H1 in the document\n\n";
    private const DEFAULT_SYSTEM_INSTRUCTION_GEMINI2_APPENDIX = "Formatting requirements for Gemini 2.x models:\n" .
        "- Do not add a separate Sources, Bronnen, References, or Links section at the end of the answer\n" .
        "- Keep the answer body clean and readable without repeating the source list\n" .
        "- If source evidence is needed, keep it concise and rely on grounding metadata rather than a manual source appendix\n\n";
    private const DEFAULT_SYSTEM_INSTRUCTION_STRUCTURED_APPENDIX = "Formatting requirements for structured-output models:\n" .
        "- Do not add a separate Sources, Bronnen, References, or Links section at the end of the answer\n" .
        "- Put source URLs and titles only in the structured sources field, not in the answer body\n" .
        "- Keep the answer body focused on the explanation itself\n\n";

    /**
     * Default model name
     */
    private const DEFAULT_MODEL = 'gemini-3-flash-preview';
    private const OFFICIAL_LATEST_MODEL_ALIASES = [
        'gemini-flash-latest',
        'gemini-pro-latest',
    ];
    private const LEGACY_DEFAULT_MODELS = [
        'gemini-2.5-flash',
    ];
    private const MODEL_SELECTION_MODE_DEFAULT = 'default';
    private const MODEL_SELECTION_MODE_CUSTOM = 'custom';
    private const TRANSIENT_MODELS = 'geweb_aisearch_gemini_models_v2';
    private const MODEL_PRICING_USD_PER_MILLION = [
        'gemini-2.5-flash' => [
            'input' => 0.30,
            'output' => 2.50,
        ],
        'gemini-2.5-pro' => [
            'input' => 1.25,
            'output' => 10.00,
        ],
    ];
    public const DEFAULT_HTTP_TIMEOUT_SECONDS = 90;
    public const DEFAULT_PRO_HTTP_TIMEOUT_SECONDS = 90;
    public const DEFAULT_SYSTEM_RETRIES = 2;
    public const DEFAULT_HUMAN_RETRIES = 2;
    private const DEFAULT_SUMMARY_TIMEOUT_SECONDS = 12;
    private const DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS = 300;
    private const DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS = 5000;
    private const MAX_GENERATE_TIMEOUT_SECONDS = 300;
    private const GENERATE_TIMEOUT_BACKOFF_OPTION = 'geweb_aisearch_gemini_generate_timeout_backoff';
    private const GENERATE_TIMEOUT_BACKOFF_TTL_SECONDS = 3600;
    private const MAX_UPLOAD_FILE_BYTES = 104857600;
    private const DEFAULT_OCR_MODEL = 'gemini-2.5-flash';
    private const STALE_FAILED_MODEL_RETENTION_SECONDS = WEEK_IN_SECONDS;
    private const MODEL_TEST_TIMEOUT_SECONDS = 20;
    private const REGEX_WHITESPACE = '/\s+/';

    /**
     * @var string Gemini API key
     */
    private string $apiKey;

    /**
     * @var string Selected model name
     */
    private string $model;

    /**
     * @var array<string,string|int>
     */
    private array $runtimeLogContext = [];

    /**
     * @var callable|null
     */
    private $streamProgressCallback = null;

    /**
     * Constructor
     *
     * @param string $apiKey Gemini API key
     * @param string $model Model name
     */
    public function __construct() {
        $encryption = new Encryption();

        $this->apiKey = $encryption->getApiKey();
        $this->model = $this->getModel();
    }

    private function logInfo(string $message): void {
        error_log('INFO geweb-ai-search: ' . $message);
    }

    private function logWarning(string $message): void {
        error_log('WARN geweb-ai-search: ' . $message);
    }

    private function logError(string $message): void {
        error_log('ERROR geweb-ai-search: ' . $message);
    }

    /**
     * @param array<string,string|int> $context
     * @return void
     */
    public function setRuntimeLogContext(array $context): void {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = sanitize_key((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_int($value)) {
                $normalized[$normalizedKey] = $value;
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $normalized[$normalizedKey] = $normalizedValue;
        }

        $this->runtimeLogContext = $normalized;
    }

    /**
     * @param callable|null $callback Receives array{stage:string,label:string,thoughts:array<int,string>,answer_preview:string}
     */
    public function setStreamProgressCallback($callback): void {
        $this->streamProgressCallback = is_callable($callback) ? $callback : null;
    }

    /**
     * @return string
     */
    public function getProviderKey(): string {
        return ProviderFactory::PROVIDER_GEMINI;
    }

    /**
     * @return string
     */
    public function getProviderLabel(): string {
        return 'Google Gemini';
    }

    /**
     * Create new File Search Store
     *
     * @param string $name Store display name
     */
    public function createStore(string $name = 'WebsiteSearch'): bool {
        $url = self::API_BASE . '/fileSearchStores';
        $body = ['display_name' => $name . '-' . time()];
        $previousStore = $this->getStoreData();

        try {
            $result = $this->makeRequest($url, $body, 'POST');
            if (!empty($result['name']) && $this->updateScopedOption(self::OPTION_STORE, $result['name'])) {
                $newStore = (string) $result['name'];
                $this->clearStoresCache();
                if ($previousStore !== '' && $previousStore !== $newStore) {
                    try {
                        $this->deleteStoreByName($previousStore);
                    } catch (\Exception $e) {}
                }
                return true;
            }
        } catch (\Exception $e) {}
        return false;
    }

    /**
     * Get File Search Store data
     *
     * @return string Store name or empty string if not exists
     */
    public function getStoreData(): string {
        return (string) $this->getScopedOption(self::OPTION_STORE, '');
    }

    /**
     * Delete the currently configured File Search Store.
     *
     * @return void
     * @throws \Exception
     */
    public function deleteStore(): void {
        $storeName = $this->getStoreData();
        if ($storeName === '') {
            return;
        }

        $this->deleteStoreByName($storeName);
        $this->deleteScopedOption(self::OPTION_STORE);
        $this->clearStoresCache();
    }

    /**
     * Delete a specific File Search Store by resource name.
     *
     * @param string $storeName
     * @return void
     * @throws \Exception
     */
    public function deleteStoreByResourceName(string $storeName): void {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return;
        }

        $this->deleteStoreByName($storeName);

        if ($storeName === $this->getStoreData()) {
            $this->deleteScopedOption(self::OPTION_STORE);
        }

        $this->clearStoresCache();
    }

    /**
     * Get a cached overview of all Gemini File Search Stores.
     *
     * @param bool $forceRefresh
     * @return array<int,array<string,mixed>>
     */
    public function getStoreOverview(bool $forceRefresh = false): array {
        $cached = $this->getScopedOption(self::OPTION_STORES_CACHE, null);
        $cacheTimeBefore = $this->getStoreOverviewCacheTime();
        if (!$forceRefresh) {
            if (is_array($cached)) {
                return $cached;
            }
        }

        $lockToken = SharedRefreshLock::acquireGroup('gemini_store_overview', 45);
        if ($lockToken === null) {
            if (!$forceRefresh && is_array($cached)) {
                return $cached;
            }

            $waited = SharedRefreshLock::waitFor(function () use ($cacheTimeBefore) {
                $items = $this->getScopedOption(self::OPTION_STORES_CACHE, null);
                $cacheTime = (int) $this->getScopedOption(self::OPTION_STORES_CACHE_TIME, 0);
                if (is_array($items) && $cacheTime > $cacheTimeBefore) {
                    return $items;
                }

                return null;
            }, 10000, 250);

            if (is_array($waited)) {
                return $waited;
            }

            if (is_array($cached)) {
                return $cached;
            }

            $lockToken = SharedRefreshLock::acquireGroup('gemini_store_overview', 45);
        }

        try {
            try {
                $items = $this->buildStoreOverview($forceRefresh);
                $this->updateScopedOption(self::OPTION_STORES_CACHE, $items);
                $this->updateScopedOption(self::OPTION_STORES_CACHE_TIME, (string) time());
                $this->deleteScopedOption(self::OPTION_STORES_CACHE_ERROR);
                return $items;
            } catch (\Exception $e) {
                $this->updateScopedOption(self::OPTION_STORES_CACHE_ERROR, $this->sanitizeConnectionErrorMessage($e->getMessage()));
                return [];
            }
        } finally {
            if (is_string($lockToken) && $lockToken !== '') {
                SharedRefreshLock::releaseGroup('gemini_store_overview', $lockToken);
            }
        }
    }

    /**
     * @return bool
     */
    public function hasStoreOverviewCache(): bool {
        return is_array($this->getScopedOption(self::OPTION_STORES_CACHE, null));
    }

    /**
     * @return int
     */
    public function getStoreOverviewCacheTime(): int {
        return (int) $this->getScopedOption(self::OPTION_STORES_CACHE_TIME, 0);
    }

    /**
     * @return bool
     */
    public function isStoreOverviewCacheStale(): bool {
        if (!$this->hasStoreOverviewCache()) {
            return true;
        }

        $cacheTime = $this->getStoreOverviewCacheTime();
        if ($cacheTime <= 0) {
            return true;
        }

        return (time() - $cacheTime) >= self::STORE_OVERVIEW_CACHE_MAX_AGE;
    }

    /**
     * @return string
     */
    public function getStoreOverviewError(): string {
        return (string) $this->getScopedOption(self::OPTION_STORES_CACHE_ERROR, '');
    }

    /**
     * @return void
     */
    public function clearStoresCache(): void {
        $this->deleteScopedOption(self::OPTION_STORES_CACHE);
        $this->deleteScopedOption(self::OPTION_STORES_CACHE_TIME);
        $this->deleteScopedOption(self::OPTION_STORES_CACHE_ERROR);
        $this->deleteScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE);
        $this->deleteScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE_TIME);
    }

    /**
     * Get all documents for a specific Gemini File Search Store.
     *
     * @param string $storeName
     * @return array<int,array<string,string>>
     * @throws \Exception
     */
    public function getStoreDocuments(string $storeName, bool $forceRefresh = false): array {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return [];
        }

        $cached = $this->getCachedStoreDocuments($storeName);
        $cacheTimeBefore = $this->getCachedStoreDocumentsCacheTime($storeName);
        if (!$forceRefresh) {
            if (is_array($cached)) {
                return $cached;
            }
        }

        $lockToken = SharedRefreshLock::acquireGroup('gemini_store_documents_' . md5($storeName), 45);
        if ($lockToken === null) {
            if (!$forceRefresh && is_array($cached)) {
                return $cached;
            }

            $waited = SharedRefreshLock::waitFor(function () use ($storeName, $cacheTimeBefore) {
                $documents = $this->getCachedStoreDocuments($storeName);
                $cacheTime = $this->getCachedStoreDocumentsCacheTime($storeName);
                if (is_array($documents) && $cacheTime > $cacheTimeBefore) {
                    return $documents;
                }

                return null;
            }, 10000, 250);

            if (is_array($waited)) {
                return $waited;
            }

            if (is_array($cached)) {
                return $cached;
            }

            $lockToken = SharedRefreshLock::acquireGroup('gemini_store_documents_' . md5($storeName), 45);
        }

        try {
            $documents = $this->listStoreDocuments($storeName);
            $this->saveStoreDocumentsCache($storeName, $documents);

            return $documents;
        } finally {
            if (is_string($lockToken) && $lockToken !== '') {
                SharedRefreshLock::releaseGroup('gemini_store_documents_' . md5($storeName), $lockToken);
            }
        }
    }

    public function hasStoreDocumentsCache(string $storeName): bool {
        return is_array($this->getCachedStoreDocuments(trim($storeName)));
    }

    /**
     * Upload document to Gemini File Search Store
     *
     * @param string $content Markdown document content
     * @param int $postId WordPress post ID
     * @return string Document name in Gemini system
     * @throws \Exception On upload error
     */
    public function uploadDocument(string $content, int $postId): string {
        return $this->uploadNamedDocument($content, "{$postId}.md");
    }

    /**
     * Upload a named markdown document to Gemini File Search Store.
     *
     * @param string $content Markdown document content
     * @param string $displayName Uploaded filename shown in Gemini
     * @return string
     * @throws \Exception
     */
    public function uploadNamedDocument(string $content, string $displayName): string {
        return $this->uploadMultipartDocument($content, $displayName, 'text/markdown');
    }

    /**
     * Upload a local file to Gemini File Search Store without converting it.
     *
     * @param string $filePath Absolute local path
     * @param string $displayName Uploaded filename shown in Gemini
     * @param string $mimeType File MIME type
     * @return string
     * @throws \Exception
     */
    public function uploadLocalFile(string $filePath, string $displayName, string $mimeType): string {
        $fileSize = @filesize($filePath);
        if (is_int($fileSize) || is_float($fileSize)) {
            $fileSize = (int) $fileSize;
            if ($fileSize > self::MAX_UPLOAD_FILE_BYTES) {
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

    public function extractImageText(string $filePath, string $mimeType): string {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            throw new \Exception('Invalid image MIME type for OCR.');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception('Could not read local image file for OCR.');
        }

        $model = apply_filters('geweb_aisearch_gemini_ocr_model', self::DEFAULT_OCR_MODEL);
        $model = is_string($model) && trim($model) !== '' ? trim($model) : self::DEFAULT_OCR_MODEL;
        $url = self::API_BASE . '/models/' . $model . ':generateContent';
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Extract all visible text from this image faithfully in reading order. Return only the extracted text. If there is no readable text, return an empty response.'],
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

    public function describeImage(string $filePath, string $mimeType): string {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            throw new \Exception('Invalid image MIME type for description.');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception('Could not read local image file for description.');
        }

        $model = apply_filters('geweb_aisearch_gemini_ocr_model', self::DEFAULT_OCR_MODEL);
        $model = is_string($model) && trim($model) !== '' ? trim($model) : self::DEFAULT_OCR_MODEL;
        $url = self::API_BASE . '/models/' . $model . ':generateContent';
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Describe this image briefly and factually. If it contains important visible text, include the meaningful text in reading order. Do not speculate or embellish.'],
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
     * Upload arbitrary multipart content to Gemini File Search Store.
     *
     * @param string $content File content
     * @param string $displayName Uploaded filename shown in Gemini
     * @param string $mimeType File MIME type
     * @return string
     * @throws \Exception
     */
    private function uploadMultipartDocument(string $content, string $displayName, string $mimeType): string {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
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

        if (
            $httpCode === 400 &&
            $this->shouldRetrySpreadsheetUploadWithoutMimeType($displayName, $mimeType, $responseBody)
        ) {
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
            $result = $operationResult;
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
     * @throws \Exception
     */
    private function performMultipartUploadRequest(string $storeName, string $content, string $displayName, string $mimeType, bool $includeMimeTypeMetadata): array {
        $url = self::API_UPLOAD_BASE . '/' . $storeName . ':uploadToFileSearchStore?key=' . $this->apiKey;
        $boundary = uniqid();

        $metadata = [
            'displayName' => $displayName,
        ];
        if ($includeMimeTypeMetadata) {
            $metadata['mimeType'] = $mimeType;
        }

        $metadataJson = wp_json_encode($metadata);
        $body  = "--{$boundary}\r\n";
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
                    'Content-Type'            => "multipart/related; boundary={$boundary}",
                    'Content-Length'          => (string) $payloadBytes,
                    'x-goog-api-key'          => $this->apiKey,
                    'X-Goog-Upload-Protocol'  => 'multipart',
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
     * @return string
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
     * @return bool
     */
    private function isUploadOperationResponse(array $payload): bool {
        $name = trim((string) ($payload['name'] ?? ''));
        return $name !== ''
            && strpos($name, 'fileSearchStores/') === 0
            && strpos($name, '/operations/') !== false;
    }

    /**
     * @return array<string,mixed>
     * @throws \Exception
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
     * @throws \Exception
     */
    private function fetchUploadOperation(string $operationName): array {
        $url = self::API_BASE . '/' . ltrim($operationName, '/');
        return $this->makeRequest($url, null, 'GET', 30);
    }

    /**
     * Delete document from Gemini File Search Store
     *
     * @param string $documentName Full document name in Gemini system
     * @return void
     * @throws \Exception On deletion error
     */
    public function deleteDocument(string $documentName): void {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $url = self::API_BASE . '/' . $documentName . '?force=1';

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
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
        // Treat "already removed" as success to keep delete idempotent.
        if ($httpCode === 404) {
            $this->clearStoresCache();
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html('Delete failed with HTTP code ' . $httpCode));
        }

        $this->clearStoresCache();
    }

    /**
     * Delete a specific File Search Store by resource name.
     *
     * @param string $storeName
     * @return void
     * @throws \Exception
     */
    private function deleteStoreByName(string $storeName): void {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $url = self::API_BASE . '/' . ltrim($storeName, '/') . '?force=1';
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html('Delete store failed: ' . $response->get_error_message()));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html('Delete store failed with HTTP code ' . $httpCode));
        }

        $this->clearStoresCache();
    }

    /**
     * Build a live overview of Gemini File Search Stores.
     *
     * @return array<int,array<string,mixed>>
     * @throws \Exception
     */
    private function buildStoreOverview(bool $forceRefresh = false): array {
        $stores = $this->listStores();
        $activeStore = $this->getStoreData();
        $items = [];

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }

            $name = isset($store['name']) ? (string) $store['name'] : '';
            if ($name === '') {
                continue;
            }

            $displayName = '';
            if (!empty($store['displayName'])) {
                $displayName = (string) $store['displayName'];
            } elseif (!empty($store['display_name'])) {
                $displayName = (string) $store['display_name'];
            }

            $status = $name === $activeStore ? 'Active in plugin' : 'Likely orphaned';
            $statusColor = $name === $activeStore ? '#46b450' : '#996800';
            $storeDocuments = $name === $activeStore ? $this->getStoreDocuments($name, $forceRefresh) : [];

            $items[] = [
                'name' => $name,
                'display_name' => $displayName,
                'status' => $status,
                'status_color' => $statusColor,
                'document_count' => $name === $activeStore ? count($storeDocuments) : $this->countStoreDocuments($name),
                'documents' => $storeDocuments,
                'is_active' => $name === $activeStore,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ((string) ($left['status'] ?? '') !== (string) ($right['status'] ?? '')) {
                return ((string) ($left['status'] ?? '')) === 'Active in plugin' ? -1 : 1;
            }

            return strcasecmp((string) ($left['display_name'] ?: $left['name']), (string) ($right['display_name'] ?: $right['name']));
        });

        return $items;
    }

    /**
     * @return array<int,array<string,string>>|null
     */
    private function getCachedStoreDocuments(string $storeName): ?array {
        $cache = $this->getScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE, null);
        $cacheTimes = $this->getScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE_TIME, null);
        if (!is_array($cache) || !isset($cache[$storeName]) || !is_array($cache[$storeName])) {
            return null;
        }

        $cacheTime = is_array($cacheTimes) ? (int) ($cacheTimes[$storeName] ?? 0) : 0;
        if ($cacheTime <= 0 || (time() - $cacheTime) >= self::STORE_DOCUMENTS_CACHE_MAX_AGE) {
            return null;
        }

        return $cache[$storeName];
    }

    /**
     * @param array<int,array<string,string>> $documents
     */
    private function saveStoreDocumentsCache(string $storeName, array $documents): void {
        $cache = $this->getScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE, []);
        $cache = is_array($cache) ? $cache : [];
        $cache[$storeName] = $documents;
        $this->updateScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE, $cache);

        $cacheTimes = $this->getScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE_TIME, []);
        $cacheTimes = is_array($cacheTimes) ? $cacheTimes : [];
        $cacheTimes[$storeName] = time();
        $this->updateScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE_TIME, $cacheTimes);
    }

    private function getCachedStoreDocumentsCacheTime(string $storeName): int {
        $cacheTimes = $this->getScopedOption(self::OPTION_STORE_DOCUMENTS_CACHE_TIME, null);
        return is_array($cacheTimes) ? (int) ($cacheTimes[$storeName] ?? 0) : 0;
    }

    /**
     * List all Gemini File Search Stores for the configured API key.
     *
     * @return array<int,array<string,mixed>>
     * @throws \Exception
     */
    private function listStores(): array {
        $stores = [];
        $pageToken = '';

        do {
            $url = self::API_BASE . '/fileSearchStores';
            if ($pageToken !== '') {
                $url .= '?pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->makeRequest($url, null, 'GET');
            $pageStores = $result['fileSearchStores'] ?? $result['file_search_stores'] ?? [];
            if (is_array($pageStores)) {
                foreach ($pageStores as $store) {
                    if (is_array($store)) {
                        $stores[] = $store;
                    }
                }
            }

            $pageToken = isset($result['nextPageToken']) ? (string) $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        return $stores;
    }

    /**
     * Count documents in a specific Gemini File Search Store.
     *
     * @param string $storeName
     * @return int
     * @throws \Exception
     */
    private function countStoreDocuments(string $storeName): int {
        $count = 0;
        $pageToken = '';

        do {
            $url = self::API_BASE . '/' . ltrim($storeName, '/') . '/documents?pageSize=20';
            if ($pageToken !== '') {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->makeRequest($url, null, 'GET');
            $documents = $result['documents'] ?? [];
            if (is_array($documents)) {
                $count += count($documents);
            }

            $pageToken = isset($result['nextPageToken']) ? (string) $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        return $count;
    }

    /**
     * List all documents in a specific Gemini File Search Store.
     *
     * @param string $storeName
     * @return array<int,array<string,string>>
     * @throws \Exception
     */
    private function listStoreDocuments(string $storeName): array {
        $items = [];
        $pageToken = '';

        do {
            $url = self::API_BASE . '/' . ltrim($storeName, '/') . '/documents?pageSize=20';
            if ($pageToken !== '') {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->makeRequest($url, null, 'GET');
            $documents = $result['documents'] ?? [];
            if (is_array($documents)) {
                foreach ($documents as $document) {
                    $normalizedDocument = $this->normalizeStoreDocumentListItem($document);
                    if (empty($normalizedDocument)) {
                        continue;
                    }

                    $items[] = $normalizedDocument;
                }
            }

            $pageToken = isset($result['nextPageToken']) ? (string) $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        error_log('geweb-ai-search: Gemini store ' . $storeName . ' returned ' . count($items) . ' document(s) from listStoreDocuments().');

        return $items;
    }

    /**
     * @param mixed $document
     * @return array<string,string>
     */
    private function normalizeStoreDocumentListItem($document): array {
        if (!is_array($document)) {
            return [];
        }

        $name = isset($document['name']) ? (string) $document['name'] : '';
        if ($name === '') {
            return [];
        }

        return [
            'name' => $name,
            'display_name' => $this->pickFirstDocumentFieldValue($document, ['displayName', 'display_name']),
            'mime_type' => $this->pickFirstDocumentFieldValue($document, ['mimeType', 'mime_type']),
            'size_bytes' => (string) $this->pickFirstDocumentFieldValue($document, ['sizeBytes', 'size_bytes']),
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @param array<int,string> $keys
     * @return string
     */
    private function pickFirstDocumentFieldValue(array $document, array $keys): string {
        foreach ($keys as $key) {
            if (!empty($document[$key])) {
                return (string) $document[$key];
            }
        }

        return '';
    }

    /**
     * Search in documents using Gemini File Search
     *
     * @param array $messages Array of messages in format [['role' => 'user', 'content' => '...'], ...]
     * @return array Response ['answer' => '...', 'sources' => [...]] or ['answer' => '...']
     * @throws \Exception On API or network error
     */
    public function search(array $messages, ?string $model = null, ?string $promptOverride = null, array $excludedSources = []): array {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
            throw new ConfigurationException('Configuration error');
        }

        if (empty($messages)) {
            throw new \Exception('Messages array is empty');
        }

        $requestModel = is_string($model) && $model !== '' ? $model : $this->model;
        $latestQuestion = $this->extractLatestUserQuestion($messages);
        $questionHash = $latestQuestion !== '' ? $this->buildQuestionHash($latestQuestion) : '';
        $requestId = isset($this->runtimeLogContext['request_id']) ? trim((string) $this->runtimeLogContext['request_id']) : '';
        if ($requestId === '') {
            $requestId = 'gem-' . wp_generate_password(10, false, false);
        }

        $promptDescriptor = $this->getPromptDescriptor($requestModel, $promptOverride);
        $effectivePrompt = trim((string) ($promptDescriptor['instruction'] ?? ''));
        $retryPlan = $this->buildGenerateRetryPlan($latestQuestion, $requestModel, $effectivePrompt);

        $this->runtimeLogContext['request_id'] = $requestId;
        $this->runtimeLogContext['model'] = $requestModel;
        if ($questionHash !== '') {
            $this->runtimeLogContext['question_hash'] = $questionHash;
        }
        if ($retryPlan['prompt_hash'] !== '') {
            $this->runtimeLogContext['prompt_hash'] = $retryPlan['prompt_hash'];
        }
        if ($retryPlan['request_fingerprint'] !== '') {
            $this->runtimeLogContext['request_fingerprint'] = $retryPlan['request_fingerprint'];
        }
        $this->logInfo(sprintf(
            'Gemini search dispatch request_id=%s question_hash=%s conversation_id=%s message_count=%d excluded_sources=%d model="%s" system_retries=%d human_retries=%d overall_attempt_start=%d overall_attempt_max=%d',
            $requestId,
            $questionHash !== '' ? $questionHash : 'none',
            isset($this->runtimeLogContext['conversation_id']) ? (string) $this->runtimeLogContext['conversation_id'] : 'none',
            count($messages),
            count($excludedSources),
            $requestModel,
            $retryPlan['system_retries'],
            $retryPlan['human_retries'],
            $retryPlan['overall_attempt_start'],
            $retryPlan['overall_attempt_max']
        ));

        // Build request body
        if ($effectivePrompt !== '' && PromptSupport::containsDisallowedUrl($effectivePrompt)) {
            throw new \Exception('Prompt cannot contain URLs. Remove links and try again.');
        }
        $body = $this->buildSearchBody(
            $messages,
            $storeName,
            $requestModel,
            $this->appendExcludedSourcesInstruction($promptDescriptor['instruction'], $excludedSources)
        );

        try {
            $result = $this->executeSearchRequest(
                $requestModel,
                $body,
                $retryPlan['timeout_seconds'],
                $retryPlan['system_retries'],
                $retryPlan['completed_attempts'],
                $retryPlan['overall_attempt_max']
            );
            $responseText = $this->extractSearchResponseText($result);
            $meta = $this->buildResponseMeta($result, $requestModel, $promptDescriptor);

            $this->clearGenerateTimeoutBackoffForRequest($latestQuestion, $requestModel, $effectivePrompt);
            $this->recordModelStatus($requestModel, 'ok');
            return $this->formatSearchResponse($responseText, $meta, $requestModel);
        } catch (\Exception $e) {
            $isTimeout = $this->isTimeoutException($e);

            if ($isTimeout) {
                $this->recordGenerateTimeoutBackoff(
                    $latestQuestion,
                    $requestModel,
                    $effectivePrompt,
                    $retryPlan['completed_attempts'] + $retryPlan['system_retries']
                );
            } else {
                $this->clearGenerateTimeoutBackoffForRequest($latestQuestion, $requestModel, $effectivePrompt);
            }
            $this->recordModelStatus($requestModel, $isTimeout ? 'timeout' : 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string $model
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function executeSearchRequest(string $model, array $body, int $timeoutSeconds, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array {
        if ($this->supportsThoughtSummaries($model) && function_exists('curl_init')) {
            $url = self::API_BASE . '/models/' . $model . ':streamGenerateContent?alt=sse';
            try {
                return $this->makeStreamingRequest($url, $body, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
            } catch (\Exception $e) {
                if ($this->isTimeoutException($e)) {
                    throw $e;
                }

                $this->logWarning(sprintf(
                    'Gemini streaming request failed, falling back to generateContent model="%s" message="%s"%s',
                    $model,
                    $e->getMessage(),
                    $this->formatRuntimeLogContextSuffix()
                ));
            }
        }

        $url = self::API_BASE . '/models/' . $model . ':generateContent';
        return $this->makeRequest($url, $body, 'POST', $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    /**
     * @param array<string,mixed> $result
     * @return string
     */
    private function extractSearchResponseText(array $result): string {
        $responseText = $this->extractCandidateText($result);

        if ($responseText === '') {
            throw new \Exception($this->buildEmptyResponseErrorMessage($result));
        }

        return $responseText;
    }

    /**
     * @param array<string,mixed> $result
     * @return string
     */
    private function extractCandidateText(array $result): string {
        $candidates = isset($result['candidates']) && is_array($result['candidates'])
            ? $result['candidates']
            : [];

        if (empty($candidates)) {
            return '';
        }

        $textParts = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])
                ? $candidate['content']['parts']
                : [];

            foreach ($parts as $part) {
                if (!is_array($part) || !isset($part['text']) || !empty($part['thought'])) {
                    continue;
                }

                $text = trim((string) $part['text']);
                if ($text !== '') {
                    $textParts[] = $text;
                }
            }

            if (!empty($textParts)) {
                break;
            }
        }

        return trim(implode("\n", $textParts));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function extractResolvedModelName(array $result, string $fallback = ''): string {
        $modelVersion = isset($result['modelVersion']) ? trim((string) $result['modelVersion']) : '';
        if ($modelVersion !== '') {
            return $modelVersion;
        }

        $model = isset($result['model']) ? trim((string) $result['model']) : '';
        if ($model !== '') {
            return preg_replace('#^models/#', '', $model) ?: $model;
        }

        return trim($fallback);
    }

    /**
     * @param array<string,mixed> $result
     * @return string
     */
    private function buildEmptyResponseErrorMessage(array $result): string {
        $candidate = isset($result['candidates'][0]) && is_array($result['candidates'][0])
            ? $result['candidates'][0]
            : [];

        $finishReason = strtoupper(trim((string) ($candidate['finishReason'] ?? '')));
        $finishMessage = trim((string) ($candidate['finishMessage'] ?? ''));
        $toolTokens = (int) ($result['usageMetadata']['toolUsePromptTokenCount'] ?? 0);

        if ($finishReason === 'MAX_TOKENS') {
            return 'AI response was truncated by MAX_TOKENS. No automatic retry was done. Please shorten the question or temporarily exclude one or more sources and try again.';
        }

        if ($finishReason === 'SAFETY') {
            return 'AI response was blocked by safety filters. Please rephrase the request.';
        }

        if ($toolTokens > 200000) {
            return 'AI returned no answer text because the search context became too large. No automatic retry was done. Please temporarily exclude one or more large sources in the Sources panel and try again.';
        }

        if ($finishMessage !== '') {
            return 'AI returned no answer text. ' . $finishMessage . ' You can temporarily exclude specific sources and retry.';
        }

        if ($finishReason !== '') {
            return 'AI returned no answer text (finish reason: ' . $finishReason . '). You can temporarily exclude specific sources and retry.';
        }

        return 'AI returned no answer text. No automatic retry was done. Please temporarily exclude one or more sources and try again.';
    }

    /**
     * @param string $responseText
     * @param array<string,mixed> $meta
     * @param string $model
     * @return array<string,mixed>
     */
    private function formatSearchResponse(string $responseText, array $meta, string $model): array {
        $formattedAnswer = apply_filters('the_content', $responseText);
        if ($this->isGemini2Model($model)) {
            return [
                'answer' => $formattedAnswer,
                'meta' => $meta,
            ];
        }

        $decoded = json_decode($responseText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'answer' => $formattedAnswer,
                'meta' => $meta,
            ];
        }

        $decoded['answer'] = apply_filters('the_content', (string) ($decoded['answer'] ?? ''));
        $decoded['meta'] = $meta;
        return $decoded;
    }

    /**
     * Build a concise API-generated summary for older conversation turns.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param string|null $model
     * @param int $maxItems
     * @return string
     */
    public function summarizeConversationForContext(array $messages, ?string $model = null, int $maxItems = 5, string $previousSummary = ''): string {
        $normalizedItems = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = trim(wp_strip_all_tags((string) ($message['content'] ?? '')));
            if ($content === '') {
                continue;
            }

            if (function_exists('mb_strimwidth')) {
                $content = mb_strimwidth($content, 0, 520, '...');
            } elseif (strlen($content) > 520) {
                $content = substr($content, 0, 517) . '...';
            }

            $normalizedItems[] = [
                'role' => $role === 'model' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        if (empty($normalizedItems)) {
            return '';
        }

        $lines = [];
        foreach ($normalizedItems as $item) {
            $lines[] = '- ' . ucfirst((string) $item['role']) . ': ' . (string) $item['content'];
        }

        $maxItems = max(1, min(8, $maxItems));
        $previousSummary = trim($previousSummary);
        $prompt = "Summarize the earlier conversation for continuation.\n" .
            "Return exactly a short Dutch summary with at most {$maxItems} bullet points.\n" .
            "Focus only on: verified facts, corrections, open questions, and constraints.\n" .
            "Do not include markdown code blocks.\n\n" .
            ($previousSummary !== ''
                ? ("Previous summary (N-1), refine and keep only still relevant items:\n" . $previousSummary . "\n\n")
                : '') .
            "Conversation:\n" . implode("\n", $lines);

        $requestModel = is_string($model) && $model !== '' ? $model : $this->model;
        $url = self::API_BASE . '/models/' . $requestModel . ':generateContent';
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
            ],
        ];

        $result = $this->makeRequest($url, $body, 'POST', $this->getSummaryTimeoutSeconds());
        $summary = $this->extractCandidateText($result);
        if ($summary === '') {
            return '';
        }

        return "Earlier conversation summary:\n" . trim($summary);
    }

    /**
     * Make HTTP request to Gemini API
     *
     * @param string $url Full API URL
     * @param array $body Request body
     * @param string $method HTTP method
     * @return array Decoded JSON response
     * @throws \Exception On request error
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $timeoutSeconds = max(5, $timeoutSeconds);
        $attemptTimeoutStepSeconds = max(1, (int) floor($timeoutSeconds / max(1, $overallAttemptBase + 1)));
        $attemptTimeoutSeconds = $timeoutSeconds;
        $args = [
            'method'  => $method,
            'timeout' => $attemptTimeoutSeconds,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $bodyBytes = isset($args['body']) ? strlen((string) $args['body']) : 0;
        $attempt = 0;
        $maxAttempts = max(1, min(4, $maxAttempts));
        $overallAttemptMax = max($maxAttempts, $overallAttemptMax);

        do {
            $attempt++;
            $args['timeout'] = $attemptTimeoutSeconds;
            $startedAt = microtime(true);
            $overallAttempt = $overallAttemptBase + $attempt;
            $this->logInfo(sprintf(
                'Gemini request starting method=%s attempt=%d/%d overall_attempt=%d/%d timeout_seconds=%d body_bytes=%d endpoint="%s"%s',
                $method,
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $attemptTimeoutSeconds,
                $bodyBytes,
                $url,
                $this->formatRuntimeLogContextSuffix()
            ));

            $response = wp_remote_request($url, $args);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (is_wp_error($response)) {
                $errorMessage = $response->get_error_message();
                $this->logError(sprintf(
                    'Gemini request transport failure method=%s attempt=%d/%d elapsed_ms=%d endpoint="%s" message="%s"%s',
                    $method,
                    $attempt,
                    $maxAttempts,
                    $elapsedMs,
                    $url,
                    $errorMessage,
                    $this->formatRuntimeLogContextSuffix()
                ));

                if ($attempt < $maxAttempts && $this->isTimeoutException(new \Exception($errorMessage))) {
                    $attemptTimeoutSeconds += $attemptTimeoutStepSeconds;
                    sleep(1);
                    continue;
                }

                throw new \Exception(esc_html(sprintf(
                    'API request failed after %d ms: %s',
                    $elapsedMs,
                    $errorMessage
                )));
            }

            $httpCode = wp_remote_retrieve_response_code($response);
            $responseBody = wp_remote_retrieve_body($response);
            $responseBytes = strlen($responseBody);
            $this->logInfo(sprintf(
                'Gemini request response method=%s attempt=%d/%d http_code=%d elapsed_ms=%d response_bytes=%d endpoint="%s"%s',
                $method,
                $attempt,
                $maxAttempts,
                $httpCode,
                $elapsedMs,
                $responseBytes,
                $url,
                $this->formatRuntimeLogContextSuffix()
            ));

            if ($httpCode < 200 || $httpCode >= 300) {
                $responseSnippet = trim(substr(preg_replace('/\s+/', ' ', $responseBody), 0, 400));

                if ($attempt < $maxAttempts && ($httpCode === 503 || $httpCode === 429)) {
                    $this->logInfo(sprintf(
                        'retrying Gemini request after transient API failure method=%s attempt=%d/%d http_code=%d',
                        $method,
                        $attempt,
                        $maxAttempts,
                        $httpCode
                    ));
                    sleep(2);
                    continue;
                }

                $requestModel = $this->extractRequestedModelFromUrl($url);
                if ($requestModel !== '' && $this->shouldMarkModelPermanentlyUnavailable($requestModel, $httpCode, $responseBody)) {
                    $this->recordModelStatus($requestModel, 'failed', $responseBody, [
                        'permanent_unavailable' => true,
                    ]);
                    $this->clearModelsCache();
                    if ((string) get_option(self::OPTION_MODEL, '') === $requestModel) {
                        update_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
                        update_option(self::OPTION_MODEL_SELECTION_MODE, self::MODEL_SELECTION_MODE_DEFAULT);
                    }
                }
                $this->logError(sprintf(
                    'Gemini request API failure method=%s http_code=%d elapsed_ms=%d endpoint="%s" response_snippet="%s"%s',
                    $method,
                    $httpCode,
                    $elapsedMs,
                    $url,
                    $responseSnippet,
                    $this->formatRuntimeLogContextSuffix()
                ));
                throw new \Exception(esc_html(sprintf(
                    'API request failed after %d ms with HTTP code %d: %s',
                    $elapsedMs,
                    $httpCode,
                    $responseBody
                )));
            }

            $result = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logError(sprintf(
                    'Gemini request JSON decode failure method=%s elapsed_ms=%d endpoint="%s" json_error="%s"%s',
                    $method,
                    $elapsedMs,
                    $url,
                    json_last_error_msg(),
                    $this->formatRuntimeLogContextSuffix()
                ));
                throw new \Exception(esc_html(sprintf(
                    'Failed to decode JSON response after %d ms: %s',
                    $elapsedMs,
                    json_last_error_msg()
                )));
            }

            return $result;
        } while ($attempt < $maxAttempts);

        return []; // Fallback, never reached
    }

    /**
     * Stream a Gemini SSE response with cURL and merge chunks into one result payload.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws \Exception
     */
    private function makeStreamingRequest(string $url, array $body, int $timeoutSeconds, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        if (!function_exists('curl_init')) {
            throw new \Exception('cURL extension is required for Gemini streaming requests.');
        }

        $timeoutSeconds = max(5, $timeoutSeconds);
        $attemptTimeoutStepSeconds = max(1, (int) floor($timeoutSeconds / max(1, $overallAttemptBase + 1)));
        $attemptTimeoutSeconds = $timeoutSeconds;
        $encodedBody = wp_json_encode($body);
        if (!is_string($encodedBody) || $encodedBody === '') {
            throw new \Exception('Could not encode Gemini streaming request body.');
        }

        $bodyBytes = strlen($encodedBody);
        $attempt = 0;
        $maxAttempts = max(1, min(4, $maxAttempts));
        $overallAttemptMax = max($maxAttempts, $overallAttemptMax);

        do {
            $attempt++;
            $overallAttempt = $overallAttemptBase + $attempt;
            $startedAt = microtime(true);
            $this->logInfo(sprintf(
                'Gemini streaming request starting method=%s attempt=%d/%d overall_attempt=%d/%d timeout_seconds=%d body_bytes=%d endpoint="%s"%s',
                'POST',
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $attemptTimeoutSeconds,
                $bodyBytes,
                $url,
                $this->formatRuntimeLogContextSuffix()
            ));

            $aggregate = [];
            $rawBuffer = '';
            $lineBuffer = '';
            $eventDataLines = [];
            $streamThoughts = [];
            $streamAnswer = '';
            $streamErrorMessage = '';
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \Exception('Could not initialize cURL for Gemini streaming request.');
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedBody,
                CURLOPT_HTTPHEADER => [
                    'x-goog-api-key: ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $attemptTimeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(30, $attemptTimeoutSeconds),
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$aggregate, &$rawBuffer, &$lineBuffer, &$eventDataLines, &$streamThoughts, &$streamAnswer, &$streamErrorMessage) {
                    $rawBuffer .= $chunk;
                    $lineBuffer .= $chunk;

                    while (($lineBreakPos = strpos($lineBuffer, "\n")) !== false) {
                        $line = substr($lineBuffer, 0, $lineBreakPos);
                        $lineBuffer = substr($lineBuffer, $lineBreakPos + 1);
                        $line = rtrim($line, "\r");

                        if ($line === '') {
                            if (!$this->flushStreamingEventDataLines($eventDataLines, $aggregate, $streamThoughts, $streamAnswer, $streamErrorMessage)) {
                                return 0;
                            }
                            continue;
                        }

                        if (strpos($line, 'data:') === 0) {
                            $eventDataLines[] = ltrim(substr($line, 5));
                        }
                    }

                    return strlen($chunk);
                },
            ]);

            $execResult = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($lineBuffer !== '' || !empty($eventDataLines)) {
                $lineBuffer = '';
                if (!$this->flushStreamingEventDataLines($eventDataLines, $aggregate, $streamThoughts, $streamAnswer, $streamErrorMessage)) {
                    $execResult = false;
                }
            }

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            if ($streamErrorMessage !== '') {
                $this->logError(sprintf(
                    'Gemini streaming request parse failure method=%s attempt=%d/%d elapsed_ms=%d endpoint="%s" message="%s"%s',
                    'POST',
                    $attempt,
                    $maxAttempts,
                    $elapsedMs,
                    $url,
                    $streamErrorMessage,
                    $this->formatRuntimeLogContextSuffix()
                ));
                throw new \Exception($streamErrorMessage);
            }

            if ($execResult === false) {
                $errorMessage = $curlError !== '' ? $curlError : 'Unknown cURL streaming error';
                $this->logError(sprintf(
                    'Gemini streaming request transport failure method=%s attempt=%d/%d elapsed_ms=%d endpoint="%s" message="%s"%s',
                    'POST',
                    $attempt,
                    $maxAttempts,
                    $elapsedMs,
                    $url,
                    $errorMessage,
                    $this->formatRuntimeLogContextSuffix()
                ));

                if ($attempt < $maxAttempts && $this->isTimeoutException(new \Exception($errorMessage))) {
                    $attemptTimeoutSeconds += $attemptTimeoutStepSeconds;
                    sleep(1);
                    continue;
                }

                throw new \Exception(esc_html(sprintf(
                    'API streaming request failed after %d ms: %s',
                    $elapsedMs,
                    $errorMessage
                )));
            }

            $responseBytes = strlen($rawBuffer);
            $this->logInfo(sprintf(
                'Gemini streaming request response method=%s attempt=%d/%d http_code=%d elapsed_ms=%d response_bytes=%d endpoint="%s"%s',
                'POST',
                $attempt,
                $maxAttempts,
                $httpCode,
                $elapsedMs,
                $responseBytes,
                $url,
                $this->formatRuntimeLogContextSuffix()
            ));

            if ($httpCode < 200 || $httpCode >= 300) {
                $responseSnippet = trim(substr(preg_replace('/\s+/', ' ', $rawBuffer), 0, 400));
                if ($attempt < $maxAttempts && ($httpCode === 503 || $httpCode === 429)) {
                    $this->logInfo(sprintf(
                        'retrying Gemini streaming request after transient API failure method=%s attempt=%d/%d http_code=%d',
                        'POST',
                        $attempt,
                        $maxAttempts,
                        $httpCode
                    ));
                    sleep(2);
                    continue;
                }

                throw new \Exception(esc_html(sprintf(
                    'API streaming request failed after %d ms with HTTP code %d: %s',
                    $elapsedMs,
                    $httpCode,
                    $responseSnippet
                )));
            }

            if (empty($aggregate)) {
                throw new \Exception('Gemini streaming request returned no usable chunks.');
            }

            return $aggregate;
        } while ($attempt < $maxAttempts);

        return [];
    }

    /**
     * @param array<int,string> $eventDataLines
     * @param array<string,mixed> $aggregate
     */
    private function flushStreamingEventDataLines(array &$eventDataLines, array &$aggregate, array &$streamThoughts, string &$streamAnswer, string &$streamErrorMessage): bool {
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

        $this->mergeStreamingChunkIntoResult($aggregate, $decoded);
        $this->collectStreamingProgressFromChunk($decoded, $streamThoughts, $streamAnswer);
        return true;
    }

    /**
     * @param array<string,mixed> $aggregate
     * @param array<string,mixed> $chunk
     */
    private function mergeStreamingChunkIntoResult(array &$aggregate, array $chunk): void {
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

                    $this->mergeStreamingCandidate($aggregate['candidates'][$candidateIndex], $candidateChunk);
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
    private function mergeStreamingCandidate(array &$aggregateCandidate, array $candidateChunk): void {
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
    private function collectStreamingProgressFromChunk(array $chunk, array &$streamThoughts, string &$streamAnswer): void {
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
                $this->mergeThoughtTextIntoSegments($streamThoughts, $text);
            } else {
                $streamAnswer .= $text;
            }
        }

        if ($this->streamProgressCallback !== null) {
            call_user_func($this->streamProgressCallback, [
                'stage' => $streamAnswer !== '' ? 'answer' : 'thoughts',
                'label' => $streamAnswer !== ''
                    ? 'Drafting answer'
                    : 'Receiving thought process',
                'thoughts' => $streamThoughts,
                'answer_preview' => $streamAnswer,
            ]);
        }
    }

    /**
     * @param array<int,string> $segments
     */
    private function mergeThoughtTextIntoSegments(array &$segments, string $text): void {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalizedText === '') {
            return;
        }

        if (empty($segments)) {
            $segments[] = $normalizedText;
            return;
        }

        $startsNewSegment = preg_match('/^[A-Z][^\n]{0,100}\n\n[\s\S]+$/u', $normalizedText) === 1;
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

    private function formatRuntimeLogContextSuffix(): string {
        if (empty($this->runtimeLogContext)) {
            return '';
        }

        $parts = [];
        foreach ($this->runtimeLogContext as $key => $value) {
            $normalizedKey = sanitize_key((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_int($value)) {
                $parts[] = $normalizedKey . '=' . $value;
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $parts[] = $normalizedKey . '="' . str_replace('"', '\"', $normalizedValue) . '"';
        }

        return empty($parts) ? '' : ' ' . implode(' ', $parts);
    }

    private function formatByteSize(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * @return int
     */
    private function getHttpTimeoutSeconds(string $model = ''): int {
        $isPro = strpos(strtolower($model), 'pro') !== false;
        $default = $isPro ? self::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS : self::DEFAULT_HTTP_TIMEOUT_SECONDS;
        $optionKey = $isPro ? self::OPTION_TIMEOUT_PRO : self::OPTION_TIMEOUT_FLASH;

        $configuredTimeout = get_option($optionKey);
        $baseTimeout = (is_numeric($configuredTimeout) && (int) $configuredTimeout > 0) ? (int) $configuredTimeout : $default;

        $timeout = apply_filters('geweb_aisearch_gemini_http_timeout', $baseTimeout, $model);
        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : $default;
    }

    private function getSystemRetryCount(): int {
        $configured = get_option(self::OPTION_SYSTEM_RETRIES);
        return (is_numeric($configured) && (int) $configured > 0)
            ? max(1, min(4, (int) $configured))
            : self::DEFAULT_SYSTEM_RETRIES;
    }

    private function getHumanRetryCount(): int {
        $configured = get_option(self::OPTION_HUMAN_RETRIES);
        return (is_numeric($configured) && (int) $configured >= 0)
            ? max(0, min(4, (int) $configured))
            : self::DEFAULT_HUMAN_RETRIES;
    }

    /**
     * @return array{timeout_seconds:int,completed_attempts:int,overall_attempt_start:int,overall_attempt_max:int,system_retries:int,human_retries:int,request_fingerprint:string,prompt_hash:string}
     */
    private function buildGenerateRetryPlan(string $question, string $model, string $promptInstruction): array {
        $baseTimeout = $this->getHttpTimeoutSeconds($model);
        $systemRetries = $this->getSystemRetryCount();
        $humanRetries = $this->getHumanRetryCount();
        $overallAttemptMax = $systemRetries * (1 + $humanRetries);
        $promptHash = $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '';
        $requestFingerprint = $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction);
        $completedAttempts = 0;

        if ($requestFingerprint !== '') {
            $state = $this->getGenerateTimeoutBackoffState();
            if (is_array($state)) {
                $storedFingerprint = isset($state['request_fingerprint']) ? (string) $state['request_fingerprint'] : '';
                $storedCompletedAttempts = isset($state['completed_attempts']) ? (int) $state['completed_attempts'] : 0;
                if ($storedFingerprint === $requestFingerprint) {
                    $completedAttempts = max(0, min($overallAttemptMax, $storedCompletedAttempts));
                }
            }
        }

        if ($question !== '' && $completedAttempts >= $overallAttemptMax) {
            throw new \Exception('This request has already timed out the maximum number of times for the same question, model, and prompt. I do not expect a result without changing the model or the prompt.');
        }

        $overallAttemptStart = $completedAttempts + 1;
        return [
            'timeout_seconds' => $baseTimeout * max(1, $overallAttemptStart),
            'completed_attempts' => $completedAttempts,
            'overall_attempt_start' => $overallAttemptStart,
            'overall_attempt_max' => $overallAttemptMax,
            'system_retries' => $systemRetries,
            'human_retries' => $humanRetries,
            'request_fingerprint' => $requestFingerprint,
            'prompt_hash' => $promptHash,
        ];
    }

    private function recordGenerateTimeoutBackoff(string $question, string $model, string $promptInstruction, int $completedAttempts): void {
        if ($question === '') {
            return;
        }

        $state = [
            'question_hash' => $this->buildQuestionHash($question),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
            'model' => $model,
            'request_fingerprint' => $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction),
            'completed_attempts' => max(0, $completedAttempts),
            'expires_at' => time() + self::GENERATE_TIMEOUT_BACKOFF_TTL_SECONDS,
        ];
        $this->updateUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION, $state);
    }

    private function clearGenerateTimeoutBackoffForRequest(string $question, string $model, string $promptInstruction): void {
        if ($question === '') {
            return;
        }

        $state = $this->getGenerateTimeoutBackoffState();
        if (!is_array($state)) {
            return;
        }

        $storedFingerprint = isset($state['request_fingerprint']) ? (string) $state['request_fingerprint'] : '';
        if ($storedFingerprint !== $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction)) {
            return;
        }

        $this->deleteUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getGenerateTimeoutBackoffState(): ?array {
        $state = $this->getUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION, null);
        if (!is_array($state)) {
            return null;
        }

        $expiresAt = isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
        if ($expiresAt < time()) {
            $this->deleteUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION);
            return null;
        }

        return $state;
    }

    private function extractLatestUserQuestion(array $messages): string {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            if ($role !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function buildQuestionHash(string $question): string {
        $normalizedQuestion = preg_replace('/\s+/', ' ', $question);
        $normalized = strtolower(trim($normalizedQuestion ?? $question));
        return hash('sha256', $normalized);
    }

    private function buildGenerateRequestFingerprint(string $question, string $model, string $promptInstruction): string {
        $normalizedQuestion = trim((string) $question);
        if ($normalizedQuestion === '') {
            return '';
        }

        return hash('sha256', wp_json_encode([
            'question_hash' => $this->buildQuestionHash($normalizedQuestion),
            'model' => trim($model),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
        ]));
    }

    private function isTimeoutException(\Exception $exception): bool {
        return $this->messageContainsAny($exception->getMessage(), [
            'timed out',
            'timeout',
            'operation timed out',
            'cURL error 28',
        ]);
    }

    /**
     * @return int
     */
    private function getSummaryTimeoutSeconds(): int {
        $timeout = apply_filters('geweb_aisearch_gemini_summary_timeout', self::DEFAULT_SUMMARY_TIMEOUT_SECONDS);
        return is_numeric($timeout) ? (int) $timeout : self::DEFAULT_SUMMARY_TIMEOUT_SECONDS;
    }

    /**
     * @return int
     */
    private function getUploadOperationTimeoutSeconds(): int {
        $timeout = apply_filters('geweb_aisearch_gemini_upload_operation_timeout', self::DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS);
        return is_numeric($timeout) ? (int) $timeout : self::DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS;
    }

    /**
     * @return int
     */
    private function getUploadOperationPollIntervalMs(): int {
        $interval = apply_filters('geweb_aisearch_gemini_upload_operation_poll_interval_ms', self::DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS);
        return is_numeric($interval) ? (int) $interval : self::DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS;
    }

    /**
     * Build request body for search API call
     *
     * @param array $messages Conversation messages
     * @param string $storeName File Search Store name
     * @return array Request body
     */
    private function buildSearchBody(array $messages, string $storeName, string $model, string $systemInstruction): array {
        $contents = $this->buildSearchContents($messages);
        $body = [
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            'contents' => $contents,
            'tools' => [[
                'file_search' => [
                    'file_search_store_names' => [$storeName]
                ]
            ]]
        ];

        if (!$this->isGemini2Model($model)) {
            $body['generationConfig'] = $this->getStructuredGenerationConfig();
        }

        if ($this->supportsThoughtSummaries($model)) {
            if (!isset($body['generationConfig']) || !is_array($body['generationConfig'])) {
                $body['generationConfig'] = [];
            }

            $body['generationConfig']['thinkingConfig'] = [
                'includeThoughts' => true,
            ];
        }

        return $body;
    }

    /**
     * @param string $systemInstruction
     * @param array<int,array<string,string>> $excludedSources
     * @return string
     */
    private function appendExcludedSourcesInstruction(string $systemInstruction, array $excludedSources): string {
        $lines = [];

        foreach ($excludedSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $title = isset($source['title']) ? trim((string) $source['title']) : '';
            $url = isset($source['url']) ? trim((string) $source['url']) : '';
            $label = $title !== '' && $url !== ''
                ? $title . ' (' . $url . ')'
                : ($title !== '' ? $title : $url);

            if ($label === '') {
                continue;
            }

            $lines[] = '- ' . $label;
        }

        if (!$lines) {
            return $systemInstruction;
        }

        return rtrim($systemInstruction) . "\n\n" .
            "Temporary source exclusions for this chat request:\n" .
            implode("\n", array_values(array_unique($lines))) . "\n\n" .
            "Treat every source listed above as unavailable for this request.\n" .
            "Do not use, quote, summarize, cite, or rely on those excluded sources, even if they would otherwise be relevant.\n" .
            "If the remaining allowed sources are insufficient, say so briefly instead of using an excluded source.\n";
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private function buildSearchContents(array $messages): array {
        $contents = [];
        foreach ($messages as $message) {
            if (empty($message['content'])) {
                continue;
            }

            $contents[] = [
                'role' => $message['role'],
                'parts' => [['text' => $message['content']]]
            ];
        }

        return $contents;
    }

    /**
     * @return array<string,mixed>
     */
    private function getStructuredGenerationConfig(): array {
        return [
            'temperature' => 0.3,
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'answer' => [
                        'type' => 'string',
                        'description' => 'Answer to the user question in HTML format do not use markdown'
                    ],
                    'sources' => [
                        'type' => 'array',
                        'description' => 'List of sources used for the answer',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'url' => [
                                    'type' => 'string',
                                    'description' => 'Page URL'
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Page title'
                                ]
                            ],
                            'required' => ['url', 'title']
                        ]
                    ]
                ],
                'required' => ['answer', 'sources']
            ]
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function buildResponseMeta(array $result, string $model, array $promptDescriptor = []): array {
        $usage = $this->extractUsageMetadata($result);
        $candidate = isset($result['candidates'][0]) && is_array($result['candidates'][0])
            ? $result['candidates'][0]
            : [];
        $meta = [
            'provider' => $this->getProviderLabel(),
            'model' => $model,
        ];

        $responseId = isset($result['responseId']) ? trim((string) $result['responseId']) : '';
        if ($responseId !== '') {
            $meta['response_id'] = $responseId;
        }

        $modelVersion = isset($result['modelVersion']) ? trim((string) $result['modelVersion']) : '';
        if ($modelVersion !== '') {
            $meta['model_version'] = $modelVersion;
        }

        if (!empty($promptDescriptor)) {
            $meta['prompt'] = $this->buildPromptMeta($promptDescriptor);
        }

        if (!empty($usage)) {
            $meta['usage'] = $usage;
        }

        $thoughtSummaries = $this->extractThoughtSummaries($result);
        if (!empty($thoughtSummaries)) {
            $meta['thoughts'] = $thoughtSummaries;
        }

        if (!empty($candidate)) {
            $candidateMeta = $this->buildCandidateMeta($candidate);
            if (count($candidateMeta) > 1) {
                $meta['candidate'] = $candidateMeta;
            }
        }

        if (isset($result['promptFeedback']) && is_array($result['promptFeedback']) && !empty($result['promptFeedback'])) {
            $meta['prompt_feedback'] = $result['promptFeedback'];
        }

        $estimatedCost = $this->estimateTextGenerationCost($usage, $model);
        if ($estimatedCost !== null) {
            $meta['estimated_cost_usd'] = $estimatedCost;
        }

        return $meta;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,string>
     */
    private function extractThoughtSummaries(array $result): array {
        $candidates = isset($result['candidates']) && is_array($result['candidates'])
            ? $result['candidates']
            : [];

        if (empty($candidates)) {
            return [];
        }

        $thoughts = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])
                ? $candidate['content']['parts']
                : [];

            foreach ($parts as $part) {
                if (!is_array($part) || empty($part['thought']) || !isset($part['text'])) {
                    continue;
                }

                $text = trim((string) $part['text']);
                if ($text !== '') {
                    $this->mergeThoughtTextIntoSegments($thoughts, $text);
                }
            }
        }

        return array_values(array_filter($thoughts, static function ($thought): bool {
            return trim((string) $thought) !== '';
        }));
    }

    /**
     * @param array<string,mixed> $promptDescriptor
     * @return array<string,mixed>
     */
    private function buildPromptMeta(array $promptDescriptor): array {
        $promptText = isset($promptDescriptor['instruction']) ? trim((string) $promptDescriptor['instruction']) : '';

        return [
            'name' => isset($promptDescriptor['name']) ? trim((string) $promptDescriptor['name']) : '',
            'scope' => isset($promptDescriptor['scope']) ? trim((string) $promptDescriptor['scope']) : '',
            'mode' => isset($promptDescriptor['mode']) ? trim((string) $promptDescriptor['mode']) : '',
            'base_name' => isset($promptDescriptor['base_name']) ? trim((string) $promptDescriptor['base_name']) : '',
            'is_model_specific' => !empty($promptDescriptor['is_model_specific']),
            'is_custom' => !empty($promptDescriptor['is_custom']),
            'hash' => $promptText !== '' ? md5($promptText) : '',
            'preview' => $promptText !== '' ? $this->buildPromptPreview($promptText) : '',
            'text' => $promptText,
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function buildCandidateMeta(array $candidate): array {
        $candidateMeta = [
            'index' => isset($candidate['index']) ? (int) $candidate['index'] : 0,
        ];

        $finishReason = isset($candidate['finishReason']) ? trim((string) $candidate['finishReason']) : '';
        if ($finishReason !== '') {
            $candidateMeta['finish_reason'] = $finishReason;
        }

        $finishMessage = isset($candidate['finishMessage']) ? trim((string) $candidate['finishMessage']) : '';
        if ($finishMessage !== '') {
            $candidateMeta['finish_message'] = $finishMessage;
        }

        if (isset($candidate['tokenCount'])) {
            $candidateMeta['token_count'] = (int) $candidate['tokenCount'];
        }

        if (isset($candidate['avgLogprobs'])) {
            $candidateMeta['avg_logprobs'] = (float) $candidate['avgLogprobs'];
        }

        foreach ($this->getCandidateArrayFieldMap() as $sourceKey => $targetKey) {
            if (isset($candidate[$sourceKey]) && is_array($candidate[$sourceKey]) && !empty($candidate[$sourceKey])) {
                $candidateMeta[$targetKey] = $candidate[$sourceKey];
            }
        }

        return $candidateMeta;
    }

    /**
     * @return array<string,string>
     */
    private function getCandidateArrayFieldMap(): array {
        return [
            'safetyRatings' => 'safety_ratings',
            'citationMetadata' => 'citation_metadata',
            'groundingAttributions' => 'grounding_attributions',
            'groundingMetadata' => 'grounding_metadata',
            'urlContextMetadata' => 'url_context_metadata',
            'logprobsResult' => 'logprobs_result',
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,int>
     */
    private function extractUsageMetadata(array $result): array {
        $usage = isset($result['usageMetadata']) && is_array($result['usageMetadata'])
            ? $result['usageMetadata']
            : [];

        if (empty($usage)) {
            return [];
        }

        $mapped = [
            'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
            'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
            'thought_tokens' => (int) ($usage['thoughtsTokenCount'] ?? 0),
            'tool_tokens' => (int) ($usage['toolUsePromptTokenCount'] ?? 0),
            'cached_tokens' => (int) ($usage['cachedContentTokenCount'] ?? 0),
        ];

        return array_filter($mapped, static function (int $value): bool {
            return $value > 0;
        });
    }

    /**
     * @param array<string,int> $usage
     * @return float|null
     */
    private function estimateTextGenerationCost(array $usage, string $model): ?float {
        if (empty($usage)) {
            return null;
        }

        $pricing = self::MODEL_PRICING_USD_PER_MILLION[$model] ?? null;
        if (!is_array($pricing)) {
            return null;
        }

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        if ($inputTokens <= 0 && $outputTokens <= 0) {
            return null;
        }

        $inputRate = (float) ($pricing['input'] ?? 0);
        $outputRate = (float) ($pricing['output'] ?? 0);

        return (($inputTokens / 1000000) * $inputRate) + (($outputTokens / 1000000) * $outputRate);
    }

    /**
     * Get the effective system instruction
     *
     * @return string
     */
    public function getSystemInstruction(): string {
        $descriptor = $this->getPromptDescriptor($this->getModel());
        return apply_filters('geweb_aisearch_gemini_system_instruction', $descriptor['instruction'], $this->getModel(), $descriptor);
    }

    /**
     * @param string|null $model
     * @return array<string,mixed>
     */
    public function getPromptDescriptor(?string $model = null, ?string $promptOverride = null): array {
        $resolvedModel = is_string($model) && $model !== '' ? $model : $this->getModel();
        $promptOverride = is_string($promptOverride) ? trim($promptOverride) : '';
        if ($promptOverride !== '') {
            $baseInstruction = $this->getBasePromptDescriptor($resolvedModel);
            $baseName = trim((string) ($baseInstruction['name'] ?? ''));
            return [
                'instruction' => $promptOverride,
                'name' => $baseName !== '' ? ('Temporary override of ' . $baseName) : 'Temporary prompt override',
                'scope' => 'temporary',
                'is_model_specific' => false,
                'is_custom' => true,
                'is_temporary' => true,
                'mode' => 'override',
                'base_name' => (string) ($baseInstruction['name'] ?? ''),
            ];
        }

        $storedModelPrompts = $this->getStoredModelPrompts();
        $storedModelPromptNames = $this->getStoredModelPromptNames();
        $storedModelPromptModes = $this->getStoredModelPromptModes();
        $baseInstruction = $this->getBasePromptDescriptor($resolvedModel);

        $modelPrompt = trim((string) ($storedModelPrompts[$resolvedModel] ?? ''));
        if ($modelPrompt !== '') {
            $mode = ($storedModelPromptModes[$resolvedModel] ?? 'append') === 'override' ? 'override' : 'append';
            $instruction = $mode === 'override'
                ? $modelPrompt
                : trim($baseInstruction['instruction'] . "\n\n" . $modelPrompt);
            return [
                'instruction' => $instruction,
                'name' => trim((string) ($storedModelPromptNames[$resolvedModel] ?? '')) ?: ('Prompt override for ' . $resolvedModel),
                'scope' => 'model',
                'is_model_specific' => true,
                'is_custom' => true,
                'mode' => $mode,
                'base_name' => (string) ($baseInstruction['name'] ?? ''),
            ];
        }

        return $baseInstruction;
    }

    /**
     * @param string $resolvedModel
     * @return array<string,mixed>
     */
    private function getBasePromptDescriptor(string $resolvedModel): array {
        $customPrompt = trim((string) $this->getScopedOption(self::OPTION_CUSTOM_PROMPT, ''));
        if ($customPrompt !== '') {
            return [
                'instruction' => $customPrompt,
                'name' => trim((string) $this->getScopedOption('geweb_aisearch_custom_prompt_name', '')) ?: 'Custom prompt',
                'scope' => 'global',
                'is_model_specific' => false,
                'is_custom' => true,
                'mode' => 'base',
            ];
        }

        return [
            'instruction' => $this->getDefaultSystemInstructionForModel($resolvedModel),
            'name' => $this->isGemini2Model($resolvedModel) ? 'Built-in Gemini 2.x prompt' : 'Built-in structured-model prompt',
            'scope' => $this->isGemini2Model($resolvedModel) ? 'default-gemini-2' : 'default-structured',
            'is_model_specific' => false,
            'is_custom' => false,
            'mode' => 'base',
        ];
    }

    /**
     * @param string|null $model
     * @return string
     */
    public function getDefaultSystemInstructionForModel(?string $model = null): string {
        $resolvedModel = is_string($model) && $model !== '' ? $model : $this->getModel();
        if ($this->isGemini2Model($resolvedModel)) {
            return self::DEFAULT_SYSTEM_INSTRUCTION . self::DEFAULT_SYSTEM_INSTRUCTION_GEMINI2_APPENDIX;
        }

        return self::DEFAULT_SYSTEM_INSTRUCTION . self::DEFAULT_SYSTEM_INSTRUCTION_STRUCTURED_APPENDIX;
    }

    /**
     * @return string
     */
    public function getDefaultSystemInstruction(): string {
        return $this->getDefaultSystemInstructionForModel($this->getModel());
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPrompts(): array {
        $value = $this->getScopedOption(self::OPTION_MODEL_PROMPTS, []);
        if (!is_array($value)) {
            return [];
        }

        $prompts = array_map('strval', $value);
        return array_filter($prompts, static function (string $item): bool {
            return trim($item) !== '';
        });
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPromptNames(): array {
        $value = $this->getScopedOption(self::OPTION_MODEL_PROMPT_NAMES, []);
        return is_array($value) ? array_map('strval', $value) : [];
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPromptModes(): array {
        $value = $this->getScopedOption(self::OPTION_MODEL_PROMPT_MODES, []);
        return is_array($value) ? array_map('strval', $value) : [];
    }

    /**
     * @param string $prompt
     * @return string
     */
    private function buildPromptPreview(string $prompt): string {
        $prompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt);
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($prompt, 0, 160, '...');
        }

        return strlen($prompt) > 160 ? substr($prompt, 0, 157) . '...' : $prompt;
    }

    /**
     * Get list of available Gemini models
     *
     * @return array Model names
     */
    public function getModels(bool $forceRefresh = false): array {
        $models = $this->getDefaultModels();
        $filteredCachedModels = [];

        if (empty($this->apiKey)) {
            $this->recordConnectionStatus('missing', 'No API key saved.');
            return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($models)));
        }

        $cachedModels = get_transient(self::TRANSIENT_MODELS);
        if (is_array($cachedModels) && !empty($cachedModels)) {
            $filteredCachedModels = array_values(array_filter($cachedModels, function ($model): bool {
                return is_string($model) && $this->supportsFileSearch($model);
            }));
            if (!empty($filteredCachedModels)) {
                set_transient(self::TRANSIENT_MODELS, $filteredCachedModels, 12 * HOUR_IN_SECONDS);
                if (!$forceRefresh) {
                    return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($filteredCachedModels)));
                }
            }
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            if (!empty($remoteModels)) {
                set_transient(self::TRANSIENT_MODELS, $remoteModels, 12 * HOUR_IN_SECONDS);
                $this->recordConnectionStatus('ok', 'Gemini API key is valid.');
                return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($remoteModels)));
            }
        } catch (\Exception $e) {
            $this->recordConnectionStatus('failed', $this->sanitizeConnectionErrorMessage($e->getMessage()));
        }

        if (!empty($filteredCachedModels)) {
            return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($filteredCachedModels)));
        }

        return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($models)));
    }

    /**
     * Get bundled fallback models
     *
     * @return array<int,string>
     */
    private function getDefaultModels(): array {
        $models = [
            self::DEFAULT_MODEL,
            'gemini-3-flash-preview',
            'gemini-3.1-flash-lite-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ];

        $cachedModels = get_transient(self::TRANSIENT_MODELS);
        if (is_array($cachedModels)) {
            foreach ($cachedModels as $model) {
                if (is_string($model) && $this->supportsFileSearch($model)) {
                    $models[] = $model;
                }
            }
        }

        $storedModel = (string) get_option(self::OPTION_MODEL, '');
        if ($storedModel !== '' && $this->supportsFileSearch($storedModel)) {
            $models[] = $storedModel;
        }

        return array_values(array_unique($models));
    }

    /**
     * Get Selected Model
     *
     * @return string Model
     */
    public function getModel(): string {
        $selectionMode = $this->getModelSelectionMode();
        $storedModel = (string) get_option(self::OPTION_MODEL, '');
        if ($this->isPermanentlyUnavailableModel($storedModel)) {
            $storedModel = '';
            update_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
            update_option(self::OPTION_MODEL_SELECTION_MODE, self::MODEL_SELECTION_MODE_DEFAULT);
        }

        if ($selectionMode === self::MODEL_SELECTION_MODE_DEFAULT) {
            if ($storedModel !== self::DEFAULT_MODEL) {
                update_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
            }

            return self::DEFAULT_MODEL;
        }

        if ($storedModel !== '') {
            return $storedModel;
        }

        $models = $this->getModels();
        return $this->getDefaultModel($models);
    }

    /**
     * @return string
     */
    private function getModelSelectionMode(): string {
        $storedMode = (string) get_option(self::OPTION_MODEL_SELECTION_MODE, '');
        if (in_array($storedMode, [self::MODEL_SELECTION_MODE_DEFAULT, self::MODEL_SELECTION_MODE_CUSTOM], true)) {
            return $storedMode;
        }

        $storedModel = (string) get_option(self::OPTION_MODEL, '');
        $resolvedMode = in_array($storedModel, array_merge([''], self::LEGACY_DEFAULT_MODELS), true)
            ? self::MODEL_SELECTION_MODE_DEFAULT
            : self::MODEL_SELECTION_MODE_CUSTOM;

        update_option(self::OPTION_MODEL_SELECTION_MODE, $resolvedMode);

        return $resolvedMode;
    }

    /**
     * Get the default model name
     *
     * @param array<int,string>|null $models Available models
     * @return string
     */
    public function getDefaultModel(?array $models = null): string {
        $models = $models ?? $this->getModels();

        return in_array(self::DEFAULT_MODEL, $models, true) ? self::DEFAULT_MODEL : $models[0];
    }

    /**
     * Get recorded model statuses
     *
     * @return array<string,array<string,mixed>>
     */
    public function getModelStatuses(): array {
        $statuses = get_option(self::OPTION_MODEL_STATUS, []);
        return is_array($statuses) ? $statuses : [];
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function filterStaleFailedModels(array $models): array {
        $statuses = $this->getModelStatuses();
        $now = current_time('timestamp');
        $connectionStatus = $this->getConnectionStatus();
        $hasRecentSuccessfulConnection = is_array($connectionStatus)
            && (($connectionStatus['status'] ?? '') === 'ok')
            && ($now - (int) ($connectionStatus['timestamp'] ?? 0)) < self::STALE_FAILED_MODEL_RETENTION_SECONDS;

        return array_values(array_filter($models, function ($model) use ($statuses, $now, $hasRecentSuccessfulConnection): bool {
            if (!is_string($model) || trim($model) === '') {
                return false;
            }

            $status = $statuses[$model] ?? null;
            if (!is_array($status) || ($status['status'] ?? '') !== 'failed') {
                return true;
            }

            if (!empty($status['permanent_unavailable'])) {
                return false;
            }

            $timestamp = isset($status['timestamp']) ? (int) $status['timestamp'] : 0;
            if ($timestamp <= 0) {
                return true;
            }

            return ($now - $timestamp) < self::STALE_FAILED_MODEL_RETENTION_SECONDS || !$hasRecentSuccessfulConnection;
        }));
    }

    /**
     * Get recorded API connection status.
     *
     * @return array<string,mixed>
     */
    public function getConnectionStatus(): array {
        $status = get_option(self::OPTION_CONNECTION_STATUS, []);
        return is_array($status) ? $status : [];
    }

    /**
     * Validate the configured API key by fetching usable models.
     *
     * @return array<string,mixed>
     */
    public function validateConnection(): array {
        if (empty($this->apiKey)) {
            return $this->storeConnectionValidationResult([
                'status' => 'missing',
                'message' => 'No API key saved.',
                'timestamp' => current_time('timestamp'),
            ]);
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            $result = [
                'status' => !empty($remoteModels) ? 'ok' : 'failed',
                'message' => !empty($remoteModels) ? 'Gemini API key is valid.' : 'Gemini API returned no usable models.',
                'timestamp' => current_time('timestamp'),
            ];
            if (!empty($remoteModels)) {
                set_transient(self::TRANSIENT_MODELS, $remoteModels, 12 * HOUR_IN_SECONDS);
            }
            return $this->storeConnectionValidationResult($result);
        } catch (\Exception $e) {
            return $this->storeConnectionValidationResult([
                'status' => 'failed',
                'message' => $this->sanitizeConnectionErrorMessage($e->getMessage()),
                'timestamp' => current_time('timestamp'),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function storeConnectionValidationResult(array $result): array {
        update_option(self::OPTION_CONNECTION_STATUS, $result);
        return $result;
    }

    /**
     * Clear cached Gemini models
     *
     * @return void
     */
    public function clearModelsCache(): void {
        delete_transient(self::TRANSIENT_MODELS);
    }

    /**
     * @param string $model
     * @return array<string,mixed>
     */
    public function testModel(string $model): array {
        $requestModel = trim($model);
        if ($requestModel === '') {
            return [
                'status' => 'failed',
                'message' => 'No model selected.',
                'timestamp' => current_time('timestamp'),
            ];
        }

        try {
            $testPrompt = 'Reply with OK.';
            $body = [
                'contents' => [[
                    'parts' => [[
                        'text' => $testPrompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 8,
                ],
            ];

            $url = self::API_BASE . '/models/' . $requestModel . ':generateContent';
            $response = $this->makeRequest($url, $body, 'POST', self::MODEL_TEST_TIMEOUT_SECONDS);
            $responseText = $this->extractCandidateText($response);
            $resolvedModel = $this->extractResolvedModelName($response, $requestModel);
            $this->recordModelStatus($requestModel, 'ok', '', [
                'test_prompt' => $testPrompt,
                'test_response' => $responseText,
                'resolved_model' => $resolvedModel,
            ]);

            return [
                'status' => 'ok',
                'message' => 'Model responded successfully.',
                'test_prompt' => $testPrompt,
                'test_response' => $responseText,
                'resolved_model' => $resolvedModel,
                'timestamp' => current_time('timestamp'),
            ];
        } catch (\Exception $e) {
            $isTimeout = $this->isTimeoutException($e);
            $this->recordModelStatus($requestModel, $isTimeout ? 'timeout' : 'failed', $e->getMessage(), [
                'test_prompt' => isset($testPrompt) ? $testPrompt : 'Reply with OK.',
                'test_response' => '',
                'resolved_model' => '',
                'permanent_unavailable' => $this->shouldMarkModelPermanentlyUnavailable(
                    $requestModel,
                    $this->extractHttpCodeFromMessage($e->getMessage()),
                    $e->getMessage()
                ),
            ]);

            return [
                'status' => $isTimeout ? 'timeout' : 'failed',
                'message' => $this->sanitizeConnectionErrorMessage($e->getMessage()),
                'test_prompt' => isset($testPrompt) ? $testPrompt : 'Reply with OK.',
                'test_response' => '',
                'resolved_model' => '',
                'timestamp' => current_time('timestamp'),
            ];
        }
    }

    /**
     * Record API connection status.
     *
     * @param string $status
     * @param string $message
     * @return void
     */
    private function recordConnectionStatus(string $status, string $message = ''): void {
        if ($status === 'failed') {
            $message = $this->sanitizeConnectionErrorMessage($message);
        }

        update_option(self::OPTION_CONNECTION_STATUS, [
            'status' => $status,
            'timestamp' => current_time('timestamp'),
            'message' => $message,
        ]);
    }

    /**
     * Convert verbose API errors into concise admin-facing messages.
     *
     * @param string $message
     * @return string
     */
    private function sanitizeConnectionErrorMessage(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return 'Could not validate the API key. This plugin expects a Google AI Studio Gemini API key.';
        }

        if ($this->messageContainsAny($message, ['API_KEY_INVALID', 'API key not valid'])) {
            return 'The API key is invalid. Enter a valid Google AI Studio Gemini API key.';
        }

        if ($this->messageContainsAny($message, ['PERMISSION_DENIED', 'permission denied', 'forbidden'])) {
            return 'The API key does not have permission to use the Gemini API or the selected resource.';
        }

        if ($this->messageContainsAny($message, ['RESOURCE_EXHAUSTED', 'quota', 'rate limit', 'too many requests'])) {
            return 'The Gemini API quota or rate limit has been reached for this project.';
        }

        if ($this->messageContainsAny($message, ['SERVICE_DISABLED', 'api has not been used', 'is not enabled'])) {
            return 'The Gemini API is not enabled for this Google project.';
        }

        if ($this->messageContainsAny($message, ['API key expired', 'API_KEY_SERVICE_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED'])) {
            return 'This API key is blocked by its Google API key restrictions.';
        }

        if ($this->messageContainsAny($message, ['UNAVAILABLE', 'timed out', 'could not resolve host', 'network'])) {
            return 'The Gemini API could not be reached right now. Please try again.';
        }

        if (preg_match('/HTTP code\s+(\d{3})/', $message, $matches)) {
            $httpCode = (int) $matches[1];
            if ($httpCode === 400) {
                return 'The Gemini API rejected the request (HTTP 400). Check the API key and request settings.';
            }
            if ($httpCode === 401) {
                return 'Authentication failed (HTTP 401). The API key is missing, invalid, or not accepted.';
            }
            if ($httpCode === 403) {
                return 'Access denied (HTTP 403). The API key may lack permission or be blocked by restrictions.';
            }
            if ($httpCode === 429) {
                return 'The Gemini API rate limit or quota has been exceeded (HTTP 429).';
            }
            if ($httpCode >= 500) {
                return 'The Gemini API returned a server error (HTTP ' . $httpCode . '). Please try again.';
            }

            return 'Gemini API request failed (HTTP ' . $httpCode . ').';
        }

        return preg_replace('/\s+/', ' ', $message) ?: 'Could not validate the API key. This plugin expects a Google AI Studio Gemini API key.';
    }

    /**
     * @param string $message
     * @param array<int,string> $fragments
     * @return bool
     */
    private function messageContainsAny(string $message, array $fragments): bool {
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && stripos($message, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record whether a model worked or failed
     *
     * @param string $model Model name
     * @param string $status ok|failed
     * @param string $message Optional error message
     * @return void
     */
    private function recordModelStatus(string $model, string $status, string $message = '', array $details = []): void {
        if ($model === '') {
            return;
        }

        $statuses = $this->getModelStatuses();
        $statuses[$model] = [
            'status' => $status,
            'timestamp' => current_time('timestamp'),
            'message' => $message,
            'test_prompt' => isset($details['test_prompt']) ? trim((string) $details['test_prompt']) : '',
            'test_response' => isset($details['test_response']) ? trim((string) $details['test_response']) : '',
            'resolved_model' => isset($details['resolved_model']) ? trim((string) $details['resolved_model']) : '',
            'permanent_unavailable' => !empty($details['permanent_unavailable']),
        ];

        update_option(self::OPTION_MODEL_STATUS, $statuses);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getScopedOption(string $optionName, $default = false) {
        return UserScope::getGroupScopedOption($optionName, $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getUserScopedOption(string $optionName, $default = false) {
        return UserScope::getUserScopedOption($optionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function updateScopedOption(string $optionName, $value): bool {
        return UserScope::updateGroupScopedOption($optionName, $value, false);
    }

    private function deleteScopedOption(string $optionName): void {
        UserScope::deleteGroupScopedOption($optionName);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function updateUserScopedOption(string $optionName, $value): bool {
        return UserScope::updateUserScopedOption($optionName, $value, false);
    }

    private function deleteUserScopedOption(string $optionName): void {
        UserScope::deleteUserScopedOption($optionName);
    }

    /**
     * Fetch currently usable Gemini models from the API
     *
     * @return array<int,string>
     */
    private function fetchUsableModels(): array {
        $result = $this->makeRequest(self::API_BASE . '/models', null, 'GET');
        $models = [];

        foreach (($result['models'] ?? []) as $model) {
            $shortName = $this->extractUsableModelName($model);
            if ($shortName === '') {
                continue;
            }

            $models[] = $shortName;
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * @param mixed $model
     * @return string
     */
    private function extractUsableModelName($model): string {
        if (!is_array($model)) {
            return '';
        }

        $name = isset($model['name']) ? (string) $model['name'] : '';
        $methods = isset($model['supportedGenerationMethods']) && is_array($model['supportedGenerationMethods'])
            ? $model['supportedGenerationMethods']
            : [];

        if ($name === '' || !in_array('generateContent', $methods, true)) {
            return '';
        }

        $shortName = preg_replace('#^models/#', '', $name);
        if (!is_string($shortName) || $shortName === '') {
            return '';
        }

        if (strpos($shortName, 'embedding') !== false || !$this->supportsFileSearch($shortName)) {
            return '';
        }

        return $shortName;
    }

    /**
     * Determine whether a model supports Gemini File Search.
     *
     * Uses a broad Gemini chat model pattern so newly released models
     * become available without plugin code updates.
     *
     * @param string $model
     * @return bool
     */
    private function supportsFileSearch(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        if ($this->isPermanentlyUnavailableModel($normalizedModel)) {
            return false;
        }

        if (in_array($normalizedModel, self::OFFICIAL_LATEST_MODEL_ALIASES, true)) {
            return true;
        }

        $blockedFragments = [
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
            'image',
            'video',
            'live',
            'robotics',
            'deep-research',
            'computer-use',
        ];

        foreach ($blockedFragments as $fragment) {
            if (strpos($normalizedModel, $fragment) !== false) {
                return false;
            }
        }

        return preg_match('/^gemini-[0-9][a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $normalizedModel) === 1;
    }

    public function isDeprecatedModel(string $model): bool {
        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^gemini-1\.[05]/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/-001$|-002$/', $normalized) === 1) {
            return true;
        }

        return in_array($normalized, ['gemini-pro', 'gemini-flash', 'gemini-pro-vision'], true);
    }

    private function isPermanentlyUnavailableModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        $statuses = $this->getModelStatuses();
        $entry = $statuses[$normalizedModel] ?? null;
        return is_array($entry) && !empty($entry['permanent_unavailable']);
    }

    private function extractRequestedModelFromUrl(string $url): string {
        if (preg_match('#/models/([^:/?]+):generateContent#', $url, $matches) !== 1) {
            return '';
        }

        return strtolower(trim((string) ($matches[1] ?? '')));
    }

    private function extractHttpCodeFromMessage(string $message): int {
        if (preg_match('/HTTP code\s+(\d{3})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function shouldMarkModelPermanentlyUnavailable(string $model, int $httpCode, string $message): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '' || $httpCode !== 404) {
            return false;
        }

        $normalizedMessage = strtolower($message);
        if ($normalizedMessage === '') {
            return false;
        }

        return strpos($normalizedMessage, $normalizedModel) !== false
            && (
                strpos($normalizedMessage, 'no longer available') !== false
                || strpos($normalizedMessage, 'not available') !== false
                || strpos($normalizedMessage, 'not found') !== false
            );
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function prependOfficialLatestAliases(array $models): array {
        return array_values(array_unique(array_merge(self::OFFICIAL_LATEST_MODEL_ALIASES, $models)));
    }

    /**
     * Check if model is Gemini 2.x (doesn't support JSON schema)
     *
     * @param string $model Model name
     * @return bool True if Gemini 2.x model
     */
    private function isGemini2Model(string $model): bool {
        return strpos($model, 'gemini-2') === 0;
    }

    /**
     * Gemini 3 models can return thought summaries through thinkingConfig.
     */
    private function supportsThoughtSummaries(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        return strpos($normalizedModel, 'gemini-3') === 0
            || strpos($normalizedModel, 'gemini-2.5') === 0;
    }
}
