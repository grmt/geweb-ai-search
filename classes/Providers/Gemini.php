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
    private const OPTION_MODEL_STATUS = 'geweb_aisearch_model_status';
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const OPTION_STORES_CACHE = 'geweb_aisearch_gemini_stores_cache';
    private const OPTION_STORES_CACHE_TIME = 'geweb_aisearch_gemini_stores_cache_time';
    private const OPTION_STORES_CACHE_ERROR = 'geweb_aisearch_gemini_stores_cache_error';

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
    private const DEFAULT_MODEL = 'gemini-2.5-flash';
    private const TRANSIENT_MODELS = 'geweb_aisearch_gemini_models';
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

    /**
     * @var string Gemini API key
     */
    private string $apiKey;

    /**
     * @var string Selected model name
     */
    private string $model;

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
        if (!$forceRefresh) {
            $cached = $this->getScopedOption(self::OPTION_STORES_CACHE, null);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $items = $this->buildStoreOverview();
            $this->updateScopedOption(self::OPTION_STORES_CACHE, $items);
            $this->updateScopedOption(self::OPTION_STORES_CACHE_TIME, (string) time());
            $this->deleteScopedOption(self::OPTION_STORES_CACHE_ERROR);
            return $items;
        } catch (\Exception $e) {
            $this->updateScopedOption(self::OPTION_STORES_CACHE_ERROR, $this->sanitizeConnectionErrorMessage($e->getMessage()));
            return [];
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
    }

    /**
     * Get all documents for a specific Gemini File Search Store.
     *
     * @param string $storeName
     * @return array<int,array<string,string>>
     * @throws \Exception
     */
    public function getStoreDocuments(string $storeName): array {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return [];
        }

        return $this->listStoreDocuments($storeName);
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
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception('Could not read local file for upload.');
        }

        return $this->uploadMultipartDocument($content, $displayName, $mimeType);
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

        $url = self::API_UPLOAD_BASE . '/' . $storeName . ':uploadToFileSearchStore?key=' . $this->apiKey;

        $boundary = uniqid();
        $metadata = wp_json_encode([
            'displayName' => $displayName,
            'mimeType'    => $mimeType,
        ]);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--";

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'           => "multipart/related; boundary={$boundary}",
                'X-Goog-Upload-Protocol' => 'multipart',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html('Upload failed: ' . $response->get_error_message()));
        }

        $httpCode     = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html("Upload failed with HTTP code {$httpCode}"));
        }

        $result = json_decode($responseBody, true);
        if (empty($result['response']['documentName'])) {
            throw new \Exception('Invalid upload response');
        }

        $this->clearStoresCache();

        return $result['response']['documentName'];
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

        $url = self::API_BASE . '/' . $documentName . '?key=' . $this->apiKey . '&force=1';

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'timeout' => 30,
            'headers' => [
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

        $url = self::API_BASE . '/' . ltrim($storeName, '/') . '?key=' . $this->apiKey . '&force=1';
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => [
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
    private function buildStoreOverview(): array {
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
            $storeDocuments = $name === $activeStore ? $this->listStoreDocuments($name) : [];

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
    public function search(array $messages, ?string $model = null, ?string $promptOverride = null): array {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
            throw new ConfigurationException('Configuration error');
        }

        if (empty($messages)) {
            throw new \Exception('Messages array is empty');
        }

        $requestModel = is_string($model) && $model !== '' ? $model : $this->model;

        // Build request body
        $promptDescriptor = $this->getPromptDescriptor($requestModel, $promptOverride);
        $body = $this->buildSearchBody($messages, $storeName, $requestModel, $promptDescriptor['instruction']);

        try {
            $result = $this->executeSearchRequest($requestModel, $body);
            $responseText = $this->extractSearchResponseText($result);
            $meta = $this->buildResponseMeta($result, $requestModel, $promptDescriptor);

            $this->recordModelStatus($requestModel, 'ok');
            return $this->formatSearchResponse($responseText, $meta, $requestModel);
        } catch (\Exception $e) {
            $this->recordModelStatus($requestModel, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string $model
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function executeSearchRequest(string $model, array $body): array {
        $url = self::API_BASE . '/models/' . $model . ':generateContent';
        return $this->makeRequest($url, $body, 'POST');
    }

    /**
     * @param array<string,mixed> $result
     * @return string
     */
    private function extractSearchResponseText(array $result): string {
        if (empty($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Empty response from AI');
        }

        return (string) $result['candidates'][0]['content']['parts'][0]['text'];
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
     * Make HTTP request to Gemini API
     *
     * @param string $url Full API URL
     * @param array $body Request body
     * @param string $method HTTP method
     * @return array Decoded JSON response
     * @throws \Exception On request error
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST'): array {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        $url = $this->appendApiKeyToUrl($url);
        $args = [
            'method'  => $method,
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html('API request failed: ' . $response->get_error_message()));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(esc_html("API request failed with HTTP code {$httpCode}: {$responseBody}"));
        }

        $result = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(esc_html('Failed to decode JSON response: ' . json_last_error_msg()));
        }

        return $result;
    }

    /**
     * Append the API key to a Gemini API URL.
     *
     * @param string $url
     * @return string
     */
    private function appendApiKeyToUrl(string $url): string {
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'key=' . rawurlencode($this->apiKey);
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

        return $body;
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
            return apply_filters('geweb_aisearch_gemini_models', $models);
        }

        $cachedModels = get_transient(self::TRANSIENT_MODELS);
        if (is_array($cachedModels) && !empty($cachedModels)) {
            $filteredCachedModels = array_values(array_filter($cachedModels, function ($model): bool {
                return is_string($model) && $this->supportsFileSearch($model);
            }));
            if (!empty($filteredCachedModels)) {
                set_transient(self::TRANSIENT_MODELS, $filteredCachedModels, 12 * HOUR_IN_SECONDS);
                if (!$forceRefresh) {
                    return apply_filters('geweb_aisearch_gemini_models', $filteredCachedModels);
                }
            }
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            if (!empty($remoteModels)) {
                set_transient(self::TRANSIENT_MODELS, $remoteModels, 12 * HOUR_IN_SECONDS);
                $this->recordConnectionStatus('ok', 'Gemini API key is valid.');
                return apply_filters('geweb_aisearch_gemini_models', $remoteModels);
            }
        } catch (\Exception $e) {
            $this->recordConnectionStatus('failed', $this->sanitizeConnectionErrorMessage($e->getMessage()));
        }

        if (!empty($filteredCachedModels)) {
            return apply_filters('geweb_aisearch_gemini_models', $filteredCachedModels);
        }

        return apply_filters('geweb_aisearch_gemini_models', $models);
    }

    /**
     * Get bundled fallback models
     *
     * @return array<int,string>
     */
    private function getDefaultModels(): array {
        return [
            self::DEFAULT_MODEL,
            'gemini-2.5-pro',
            'gemini-3-flash-preview',
            'gemini-3-pro-preview',
            'gemini-2.5-flash-lite',
        ];
    }

    /**
     * Get Selected Model
     *
     * @return string Model
     */
    public function getModel(): string {
        $storedModel = (string) get_option(self::OPTION_MODEL, '');
        if ($storedModel !== '') {
            return $storedModel;
        }

        $models = $this->getModels();
        return $this->getDefaultModel($models);
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
    private function recordModelStatus(string $model, string $status, string $message = ''): void {
        if ($model === '') {
            return;
        }

        $statuses = $this->getModelStatuses();
        $statuses[$model] = [
            'status' => $status,
            'timestamp' => current_time('timestamp'),
            'message' => $message,
        ];

        update_option(self::OPTION_MODEL_STATUS, $statuses);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getScopedOption(string $optionName, $default = false) {
        return UserScope::getScopedOption($optionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function updateScopedOption(string $optionName, $value): bool {
        return UserScope::updateScopedOption($optionName, $value, false);
    }

    private function deleteScopedOption(string $optionName): void {
        UserScope::deleteScopedOption($optionName);
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
     * Based on Google's File Search docs:
     * gemini-3-pro-preview, gemini-3-flash-preview,
     * gemini-2.5-pro, gemini-2.5-flash (+ preview versions),
     * gemini-2.5-flash-lite (+ preview versions).
     *
     * @param string $model
     * @return bool
     */
    private function supportsFileSearch(string $model): bool {
        $blockedFragments = [
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
        ];

        foreach ($blockedFragments as $fragment) {
            if (strpos($model, $fragment) !== false) {
                return false;
            }
        }

        $allowedPrefixes = [
            'gemini-3-pro-preview',
            'gemini-3-flash-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }

        return false;
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
}
