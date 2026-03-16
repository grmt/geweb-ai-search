<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Gemini AI Provider
 *
 * Handles all interactions with Google Gemini API
 */
class Gemini {
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
     * Create new File Search Store
     *
     * @param string $name Store display name
     */
    public function createStore(string $name = 'WebsiteSearch'): bool {
        $url = self::API_BASE . '/fileSearchStores';
        $body = ['display_name' => $name . '-' . time()];

        try {
            $result = $this->makeRequest($url, $body, 'POST');
            if (!empty($result['name']) && update_option(self::OPTION_STORE, $result['name'])) {
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
     * Upload document to Gemini File Search Store
     *
     * @param string $content Markdown document content
     * @param int $postId WordPress post ID
     * @return string Document name in Gemini system
     * @throws \Exception On upload error
     */
    public function uploadDocument(string $content, int $postId): string {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
            throw new \Exception('Configuration error');
        }

        $url = self::API_UPLOAD_BASE . '/' . $storeName . ':uploadToFileSearchStore?key=' . $this->apiKey;

        $boundary = uniqid();
        $metadata = wp_json_encode([
            'displayName' => "{$postId}.md",
            'mimeType'    => 'text/markdown',
        ]);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/markdown\r\n\r\n";
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
            throw new \Exception('Configuration error');
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
     * Search in documents using Gemini File Search
     *
     * @param array $messages Array of messages in format [['role' => 'user', 'content' => '...'], ...]
     * @return array Response ['answer' => '...', 'sources' => [...]] or ['answer' => '...']
     * @throws \Exception On API or network error
     */
    public function search(array $messages): array {
        $storeName = $this->getStoreData();
        if (empty($this->apiKey) || empty($storeName)) {
            throw new \Exception('Configuration error');
        }

        if (empty($messages)) {
            throw new \Exception('Messages array is empty');
        }

        // Build request body
        $body = $this->buildSearchBody($messages, $storeName);

        // Make API request
        $url = self::API_BASE . '/models/' . $this->model . ':generateContent';
        $result = $this->makeRequest($url, $body, 'POST');

        // Parse response
        if (empty($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Empty response from AI');
        }

        $responseText = $result['candidates'][0]['content']['parts'][0]['text'];

        // Gemini 3+ returns JSON, Gemini 2.5 returns plain text
        if ($this->isGemini2Model($this->model)) {
            return ['answer' => apply_filters('the_content', $responseText)];
        } else {
            $decoded = json_decode($responseText, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['answer' => apply_filters('the_content', $responseText)];
            }
            $decoded['answer'] = apply_filters('the_content', $decoded['answer']);
            return $decoded;
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
            throw new \Exception('Configuration error');
        }

        $args = [
            'method'  => $method,
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'x-goog-api-key' => $this->apiKey,
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
    public function getModels(): array {
        $models = $this->getDefaultModels();

        if (empty($this->apiKey)) {
            return apply_filters('geweb_aisearch_gemini_models', $models);
        }

        $cachedModels = get_transient(self::TRANSIENT_MODELS);
        if (is_array($cachedModels) && !empty($cachedModels)) {
            return apply_filters('geweb_aisearch_gemini_models', $cachedModels);
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            if (!empty($remoteModels)) {
                set_transient(self::TRANSIENT_MODELS, $remoteModels, 12 * HOUR_IN_SECONDS);
                return apply_filters('geweb_aisearch_gemini_models', $remoteModels);
            }
        } catch (\Exception $e) {}

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
            'gemini-3.1-pro-preview'
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
     * Clear cached Gemini models
     *
     * @return void
     */
    public function clearModelsCache(): void {
        delete_transient(self::TRANSIENT_MODELS);
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

            $models[] = $shortName;
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
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
