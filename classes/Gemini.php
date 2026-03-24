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

    /**
     * Default model name
     */
    private const DEFAULT_MODEL = 'gemini-2.5-flash';
    private const TRANSIENT_MODELS = 'geweb_aisearch_gemini_models';

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
            if (!empty($result['name']) && update_option(self::OPTION_STORE, $result['name'])) {
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
        return get_option(self::OPTION_STORE, '');
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
        delete_option(self::OPTION_STORE);
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
            delete_option(self::OPTION_STORE);
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
            $cached = get_option(self::OPTION_STORES_CACHE, null);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $items = $this->buildStoreOverview();
            update_option(self::OPTION_STORES_CACHE, $items, false);
            update_option(self::OPTION_STORES_CACHE_TIME, (string) time(), false);
            delete_option(self::OPTION_STORES_CACHE_ERROR);
            return $items;
        } catch (\Exception $e) {
            update_option(self::OPTION_STORES_CACHE_ERROR, $this->sanitizeConnectionErrorMessage($e->getMessage()), false);
            return [];
        }
    }

    /**
     * @return bool
     */
    public function hasStoreOverviewCache(): bool {
        return is_array(get_option(self::OPTION_STORES_CACHE, null));
    }

    /**
     * @return int
     */
    public function getStoreOverviewCacheTime(): int {
        return (int) get_option(self::OPTION_STORES_CACHE_TIME, 0);
    }

    /**
     * @return string
     */
    public function getStoreOverviewError(): string {
        return (string) get_option(self::OPTION_STORES_CACHE_ERROR, '');
    }

    /**
     * @return void
     */
    public function clearStoresCache(): void {
        delete_option(self::OPTION_STORES_CACHE);
        delete_option(self::OPTION_STORES_CACHE_TIME);
        delete_option(self::OPTION_STORES_CACHE_ERROR);
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

            $items[] = [
                'name' => $name,
                'display_name' => $displayName,
                'status' => $status,
                'status_color' => $statusColor,
                'document_count' => $this->countStoreDocuments($name),
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
     * Search in documents using Gemini File Search
     *
     * @param array $messages Array of messages in format [['role' => 'user', 'content' => '...'], ...]
     * @return array Response ['answer' => '...', 'sources' => [...]] or ['answer' => '...']
     * @throws \Exception On API or network error
     */
    public function search(array $messages): array {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
            throw new ConfigurationException('Configuration error');
        }

        if (empty($messages)) {
            throw new \Exception('Messages array is empty');
        }

        // Build request body
        $body = $this->buildSearchBody($messages, $storeName);

        try {
            // Make API request
            $url = self::API_BASE . '/models/' . $this->model . ':generateContent';
            $result = $this->makeRequest($url, $body, 'POST');

            // Parse response
            if (empty($result['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \Exception('Empty response from AI');
            }

            $responseText = $result['candidates'][0]['content']['parts'][0]['text'];

            $this->recordModelStatus($this->model, 'ok');

            // Gemini 3+ returns JSON, Gemini 2.5 returns plain text
            if ($this->isGemini2Model($this->model)) {
                return ['answer' => apply_filters('the_content', $responseText)];
            }
            $decoded = json_decode($responseText, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['answer' => apply_filters('the_content', $responseText)];
            }
            $decoded['answer'] = apply_filters('the_content', $decoded['answer']);
            return $decoded;
        } catch (\Exception $e) {
            $this->recordModelStatus($this->model, 'failed', $e->getMessage());
            throw $e;
        }
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
    private function buildSearchBody(array $messages, string $storeName): array {
        // Get system instruction with filter support
        $systemInstruction = $this->getSystemInstruction();

        // Format messages for Gemini API
        $contents = [];
        foreach ($messages as $message) {
            if (!empty($message['content'])) {
                $contents[] = [
                    'role' => $message['role'],
                    'parts' => [['text' => $message['content']]]
                ];
            }
        }

        // Base request body
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

        // Add JSON schema for Gemini 3+ models
        if (!$this->isGemini2Model($this->model)) {
            $body['generationConfig'] = [
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

        return $body;
    }

    /**
     * Get the effective system instruction
     *
     * @return string
     */
    public function getSystemInstruction(): string {
        $customPrompt = trim((string) get_option(self::OPTION_CUSTOM_PROMPT, ''));
        $systemInstruction = $customPrompt !== '' ? $customPrompt : self::DEFAULT_SYSTEM_INSTRUCTION;

        return apply_filters('geweb_aisearch_gemini_system_instruction', $systemInstruction);
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
     * Get the built-in default system instruction
     *
     * @return string
     */
    public function getDefaultSystemInstruction(): string {
        return self::DEFAULT_SYSTEM_INSTRUCTION;
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
            $result = [
                'status' => 'missing',
                'message' => 'No API key saved.',
                'timestamp' => current_time('timestamp'),
            ];
            update_option(self::OPTION_CONNECTION_STATUS, $result);
            return $result;
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            $result = [
                'status' => !empty($remoteModels) ? 'ok' : 'failed',
                'message' => !empty($remoteModels) ? 'Gemini API key is valid.' : 'Gemini API returned no usable models.',
                'timestamp' => current_time('timestamp'),
            ];
            update_option(self::OPTION_CONNECTION_STATUS, $result);
            if (!empty($remoteModels)) {
                set_transient(self::TRANSIENT_MODELS, $remoteModels, 12 * HOUR_IN_SECONDS);
            }
            return $result;
        } catch (\Exception $e) {
            $result = [
                'status' => 'failed',
                'message' => $this->sanitizeConnectionErrorMessage($e->getMessage()),
                'timestamp' => current_time('timestamp'),
            ];
            update_option(self::OPTION_CONNECTION_STATUS, $result);
            return $result;
        }
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

        if (stripos($message, 'API_KEY_INVALID') !== false || stripos($message, 'API key not valid') !== false) {
            return 'The API key is invalid. Enter a valid Google AI Studio Gemini API key.';
        }

        if (
            stripos($message, 'PERMISSION_DENIED') !== false ||
            stripos($message, 'permission denied') !== false ||
            stripos($message, 'forbidden') !== false
        ) {
            return 'The API key does not have permission to use the Gemini API or the selected resource.';
        }

        if (
            stripos($message, 'RESOURCE_EXHAUSTED') !== false ||
            stripos($message, 'quota') !== false ||
            stripos($message, 'rate limit') !== false ||
            stripos($message, 'too many requests') !== false
        ) {
            return 'The Gemini API quota or rate limit has been reached for this project.';
        }

        if (
            stripos($message, 'SERVICE_DISABLED') !== false ||
            stripos($message, 'api has not been used') !== false ||
            stripos($message, 'is not enabled') !== false
        ) {
            return 'The Gemini API is not enabled for this Google project.';
        }

        if (
            stripos($message, 'API key expired') !== false ||
            stripos($message, 'API_KEY_SERVICE_BLOCKED') !== false ||
            stripos($message, 'API_KEY_HTTP_REFERRER_BLOCKED') !== false ||
            stripos($message, 'API_KEY_IP_ADDRESS_BLOCKED') !== false
        ) {
            return 'This API key is blocked by its Google API key restrictions.';
        }

        if (
            stripos($message, 'UNAVAILABLE') !== false ||
            stripos($message, 'timed out') !== false ||
            stripos($message, 'could not resolve host') !== false ||
            stripos($message, 'network') !== false
        ) {
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
     * Fetch currently usable Gemini models from the API
     *
     * @return array<int,string>
     */
    private function fetchUsableModels(): array {
        $result = $this->makeRequest(self::API_BASE . '/models', null, 'GET');
        $models = [];

        foreach (($result['models'] ?? []) as $model) {
            $name = isset($model['name']) ? (string) $model['name'] : '';
            $methods = isset($model['supportedGenerationMethods']) && is_array($model['supportedGenerationMethods'])
                ? $model['supportedGenerationMethods']
                : [];

            if ($name === '' || !in_array('generateContent', $methods, true)) {
                continue;
            }

            $shortName = preg_replace('#^models/#', '', $name);
            if (!is_string($shortName) || $shortName === '') {
                continue;
            }

            if (strpos($shortName, 'embedding') !== false) {
                continue;
            }

            if (!$this->supportsFileSearch($shortName)) {
                continue;
            }

            $models[] = $shortName;
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
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
