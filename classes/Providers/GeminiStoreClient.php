<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Manages Gemini File Search stores, store caches, and document listings.
 */
class GeminiStoreClientException extends \RuntimeException {}

class GeminiStoreClient {
    private const STORE_REQUEST_CALLBACK_MISSING = 'Gemini store request callback is not configured.';

    private string $apiBase;
    private string $apiKey;
    private string $optionStore;
    private string $optionStoresCache;
    private string $optionStoresCacheTime;
    private string $optionStoresCacheError;
    private string $optionStoreDocumentsCache;
    private string $optionStoreDocumentsCacheTime;
    private int $storeOverviewCacheMaxAge;
    private int $storeDocumentsCacheMaxAge;
    private $getScopedOptionCallback;
    private $updateScopedOptionCallback;
    private $deleteScopedOptionCallback;
    private $makeRequestCallback;
    private $sanitizeConnectionErrorMessageCallback;

    /**
     * @param array<string,mixed> $options
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $apiKey, array $options, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->apiKey = $apiKey;
        $this->optionStore = (string) ($options['option_store'] ?? '');
        $this->optionStoresCache = (string) ($options['option_stores_cache'] ?? '');
        $this->optionStoresCacheTime = (string) ($options['option_stores_cache_time'] ?? '');
        $this->optionStoresCacheError = (string) ($options['option_stores_cache_error'] ?? '');
        $this->optionStoreDocumentsCache = (string) ($options['option_store_documents_cache'] ?? '');
        $this->optionStoreDocumentsCacheTime = (string) ($options['option_store_documents_cache_time'] ?? '');
        $this->storeOverviewCacheMaxAge = (int) ($options['store_overview_cache_max_age'] ?? DAY_IN_SECONDS);
        $this->storeDocumentsCacheMaxAge = (int) ($options['store_documents_cache_max_age'] ?? DAY_IN_SECONDS);
        $this->getScopedOptionCallback = isset($callbacks['get_scoped_option']) && is_callable($callbacks['get_scoped_option']) ? $callbacks['get_scoped_option'] : null;
        $this->updateScopedOptionCallback = isset($callbacks['update_scoped_option']) && is_callable($callbacks['update_scoped_option']) ? $callbacks['update_scoped_option'] : null;
        $this->deleteScopedOptionCallback = isset($callbacks['delete_scoped_option']) && is_callable($callbacks['delete_scoped_option']) ? $callbacks['delete_scoped_option'] : null;
        $this->makeRequestCallback = isset($callbacks['make_request']) && is_callable($callbacks['make_request']) ? $callbacks['make_request'] : null;
        $this->sanitizeConnectionErrorMessageCallback = isset($callbacks['sanitize_connection_error_message']) && is_callable($callbacks['sanitize_connection_error_message']) ? $callbacks['sanitize_connection_error_message'] : null;
    }

    public function createStore(string $name = 'WebsiteSearch'): bool {
        $url = $this->apiBase . '/fileSearchStores';
        $body = ['display_name' => $name . '-' . time()];
        $previousStore = $this->readStoreData();

        try {
            $result = $this->callCallback('makeRequestCallback', [$url, $body, 'POST'], [], self::STORE_REQUEST_CALLBACK_MISSING);
            if (!empty($result['name']) && $this->callCallback('updateScopedOptionCallback', [$this->optionStore, (string) $result['name']], false)) {
                $newStore = (string) $result['name'];
                $this->clearStoresCache();
                if ($previousStore !== '' && $previousStore !== $newStore) {
                    try {
                        $this->deleteStoreByName($previousStore);
                    } catch (\Exception $e) {
                        error_log('WARN geweb-ai-search: could not delete previous Gemini store "' . $previousStore . '": ' . $e->getMessage());
                    }
                }
                return true;
            }
        } catch (\Exception $e) {
            error_log('WARN geweb-ai-search: could not create Gemini store: ' . $e->getMessage());
        }

        return false;
    }

    public function deleteStore(): void {
        $storeName = $this->readStoreData();
        if ($storeName === '') {
            return;
        }

        $this->deleteStoreByName($storeName);
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStore]);
        $this->clearStoresCache();
    }

    public function deleteStoreByResourceName(string $storeName): void {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return;
        }

        $this->deleteStoreByName($storeName);

        if ($storeName === $this->readStoreData()) {
            $this->callCallback('deleteScopedOptionCallback', [$this->optionStore]);
        }

        $this->clearStoresCache();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getStoreOverview(bool $forceRefresh = false): array {
        $cached = $this->callCallback('getScopedOptionCallback', [$this->optionStoresCache, null], null);
        $cacheTimeBefore = (int) $this->callCallback('getScopedOptionCallback', [$this->optionStoresCacheTime, 0], 0);
        $lockToken = null;
        $result = !$forceRefresh && is_array($cached) ? $cached : null;

        try {
            if ($result === null) {
                $lockToken = $this->acquireOrAwaitOverviewLock($cached, $forceRefresh, $cacheTimeBefore);
                if (is_array($lockToken)) {
                    $result = $lockToken;
                } else {
                    try {
                        $result = $this->buildStoreOverview($forceRefresh);
                        $this->callCallback('updateScopedOptionCallback', [$this->optionStoresCache, $result], false);
                        $this->callCallback('updateScopedOptionCallback', [$this->optionStoresCacheTime, (string) time()], false);
                        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoresCacheError]);
                    } catch (\Exception $e) {
                        $errorMessage = (string) $this->callCallback('sanitizeConnectionErrorMessageCallback', [$e->getMessage()], $e->getMessage());
                        $this->callCallback('updateScopedOptionCallback', [$this->optionStoresCacheError, $errorMessage], false);
                        $result = [];
                    }
                }
            }
        } finally {
            if (is_string($lockToken) && $lockToken !== '') {
                SharedRefreshLock::releaseGroup('gemini_store_overview', $lockToken);
            }
        }

        return $result;
    }

    public function clearStoresCache(): void {
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoresCache]);
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoresCacheTime]);
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoresCacheError]);
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoreDocumentsCache]);
        $this->callCallback('deleteScopedOptionCallback', [$this->optionStoreDocumentsCacheTime]);
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getStoreDocuments(string $storeName, bool $forceRefresh = false): array {
        $storeName = trim($storeName);
        $cached = $storeName !== '' ? $this->getCachedStoreDocuments($storeName) : null;
        $cacheTimeBefore = $storeName !== '' ? $this->getCachedStoreDocumentsCacheTime($storeName) : 0;
        $lockToken = null;
        $documents = [];

        try {
            if ($storeName === '') {
                $documents = [];
            } elseif (!$forceRefresh && is_array($cached)) {
                $documents = $cached;
            } else {
                $lockToken = $this->acquireOrAwaitDocumentsLock($storeName, $cached, $forceRefresh, $cacheTimeBefore);
                if (is_array($lockToken)) {
                    $documents = $lockToken;
                } else {
                    $documents = $this->listStoreDocuments($storeName);
                    $this->saveStoreDocumentsCache($storeName, $documents);
                }
            }
        } finally {
            if (is_string($lockToken) && $lockToken !== '') {
                SharedRefreshLock::releaseGroup('gemini_store_documents_' . md5($storeName), $lockToken);
            }
        }

        return $documents;
    }

    private function deleteStoreByName(string $storeName): void {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        $url = $this->apiBase . '/' . ltrim($storeName, '/') . '?force=1';
        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new GeminiStoreClientException(esc_html('Delete store failed: ' . $response->get_error_message()));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new GeminiStoreClientException(esc_html('Delete store failed with HTTP code ' . $httpCode));
        }

        $this->clearStoresCache();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildStoreOverview(bool $forceRefresh = false): array {
        $stores = $this->listStores();
        $activeStore = $this->readStoreData();
        $items = [];

        foreach ($stores as $store) {
            $item = $this->buildStoreOverviewItem($store, $activeStore, $forceRefresh);
            if (!empty($item)) {
                $items[] = $item;
            }
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
     * @param array<int,array<string,mixed>>|mixed $cached
     * @return array<int,array<string,mixed>>|string|null
     */
    private function acquireOrAwaitOverviewLock($cached, bool $forceRefresh, int $cacheTimeBefore) {
        $lockToken = SharedRefreshLock::acquireGroup('gemini_store_overview', 45);
        if ($lockToken === null && !$forceRefresh && is_array($cached)) {
            $lockToken = $cached;
        }

        if ($lockToken === null) {
            $waited = SharedRefreshLock::waitFor(function () use ($cacheTimeBefore) {
                $items = $this->callCallback('getScopedOptionCallback', [$this->optionStoresCache, null], null);
                $cacheTime = (int) $this->callCallback('getScopedOptionCallback', [$this->optionStoresCacheTime, 0], 0);
                return is_array($items) && $cacheTime > $cacheTimeBefore ? $items : null;
            }, 10000, 250);
            $lockToken = is_array($waited) ? $waited : SharedRefreshLock::acquireGroup('gemini_store_overview', 45);
        }

        return $lockToken;
    }

    /**
     * @param array<int,array<string,string>>|null $cached
     * @return array<int,array<string,string>>|string|null
     */
    private function acquireOrAwaitDocumentsLock(string $storeName, ?array $cached, bool $forceRefresh, int $cacheTimeBefore) {
        $lockKey = 'gemini_store_documents_' . md5($storeName);
        $lockToken = SharedRefreshLock::acquireGroup($lockKey, 45);
        if ($lockToken === null && !$forceRefresh && is_array($cached)) {
            $lockToken = $cached;
        }

        if ($lockToken === null) {
            $waited = SharedRefreshLock::waitFor(function () use ($storeName, $cacheTimeBefore) {
                $documents = $this->getCachedStoreDocuments($storeName);
                $cacheTime = $this->getCachedStoreDocumentsCacheTime($storeName);
                return is_array($documents) && $cacheTime > $cacheTimeBefore ? $documents : null;
            }, 10000, 250);
            $lockToken = is_array($waited) ? $waited : SharedRefreshLock::acquireGroup($lockKey, 45);
        }

        return $lockToken;
    }

    /**
     * @param mixed $store
     * @return array<string,mixed>
     */
    private function buildStoreOverviewItem($store, string $activeStore, bool $forceRefresh): array {
        if (!is_array($store)) {
            return [];
        }

        $name = isset($store['name']) ? (string) $store['name'] : '';
        if ($name === '') {
            return [];
        }

        $isActive = $name === $activeStore;
        $displayName = '';
        if (!empty($store['displayName'])) {
            $displayName = (string) $store['displayName'];
        } elseif (!empty($store['display_name'])) {
            $displayName = (string) $store['display_name'];
        }
        $storeDocuments = $isActive ? $this->getStoreDocuments($name, $forceRefresh) : [];

        return [
            'name' => $name,
            'display_name' => $displayName,
            'status' => $isActive ? 'Active in plugin' : 'Likely orphaned',
            'status_color' => $isActive ? '#46b450' : '#996800',
            'document_count' => $isActive ? count($storeDocuments) : $this->countStoreDocuments($name),
            'documents' => $storeDocuments,
            'is_active' => $isActive,
        ];
    }

    /**
     * @return array<int,array<string,string>>|null
     */
    private function getCachedStoreDocuments(string $storeName): ?array {
        $cache = $this->callCallback('getScopedOptionCallback', [$this->optionStoreDocumentsCache, null], null);
        $cacheTimes = $this->callCallback('getScopedOptionCallback', [$this->optionStoreDocumentsCacheTime, null], null);
        if (!is_array($cache) || !isset($cache[$storeName]) || !is_array($cache[$storeName])) {
            return null;
        }

        $cacheTime = is_array($cacheTimes) ? (int) ($cacheTimes[$storeName] ?? 0) : 0;
        if ($cacheTime <= 0 || (time() - $cacheTime) >= $this->storeDocumentsCacheMaxAge) {
            return null;
        }

        return $cache[$storeName];
    }

    /**
     * @param array<int,array<string,string>> $documents
     */
    private function saveStoreDocumentsCache(string $storeName, array $documents): void {
        $cache = $this->callCallback('getScopedOptionCallback', [$this->optionStoreDocumentsCache, []], []);
        $cache = is_array($cache) ? $cache : [];
        $cache[$storeName] = $documents;
        $this->callCallback('updateScopedOptionCallback', [$this->optionStoreDocumentsCache, $cache], false);

        $cacheTimes = $this->callCallback('getScopedOptionCallback', [$this->optionStoreDocumentsCacheTime, []], []);
        $cacheTimes = is_array($cacheTimes) ? $cacheTimes : [];
        $cacheTimes[$storeName] = time();
        $this->callCallback('updateScopedOptionCallback', [$this->optionStoreDocumentsCacheTime, $cacheTimes], false);
    }

    private function getCachedStoreDocumentsCacheTime(string $storeName): int {
        $cacheTimes = $this->callCallback('getScopedOptionCallback', [$this->optionStoreDocumentsCacheTime, null], null);
        return is_array($cacheTimes) ? (int) ($cacheTimes[$storeName] ?? 0) : 0;
    }

    private function readStoreData(): string {
        return (string) $this->callCallback('getScopedOptionCallback', [$this->optionStore, ''], '');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listStores(): array {
        $stores = [];
        $pageToken = '';

        do {
            $url = $this->apiBase . '/fileSearchStores';
            if ($pageToken !== '') {
                $url .= '?pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->callCallback('makeRequestCallback', [$url, null, 'GET'], [], self::STORE_REQUEST_CALLBACK_MISSING);
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

    private function countStoreDocuments(string $storeName): int {
        $count = 0;
        $pageToken = '';

        do {
            $url = $this->apiBase . '/' . ltrim($storeName, '/') . '/documents?pageSize=20';
            if ($pageToken !== '') {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->callCallback('makeRequestCallback', [$url, null, 'GET'], [], self::STORE_REQUEST_CALLBACK_MISSING);
            $documents = $result['documents'] ?? [];
            if (is_array($documents)) {
                $count += count($documents);
            }

            $pageToken = isset($result['nextPageToken']) ? (string) $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        return $count;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function listStoreDocuments(string $storeName): array {
        $items = [];
        $pageToken = '';

        do {
            $url = $this->apiBase . '/' . ltrim($storeName, '/') . '/documents?pageSize=20';
            if ($pageToken !== '') {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            $result = $this->callCallback('makeRequestCallback', [$url, null, 'GET'], [], self::STORE_REQUEST_CALLBACK_MISSING);
            $documents = $result['documents'] ?? [];
            if (is_array($documents)) {
                foreach ($documents as $document) {
                    $normalizedDocument = GeminiStoreDocumentNormalizer::normalize($document);
                    if (!empty($normalizedDocument)) {
                        $items[] = $normalizedDocument;
                    }
                }
            }

            $pageToken = isset($result['nextPageToken']) ? (string) $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        error_log('geweb-ai-search: Gemini store ' . $storeName . ' returned ' . count($items) . ' document(s) from listStoreDocuments().');

        return $items;
    }

    /**
     * @param array<int,mixed> $args
     * @param mixed $default
     * @return mixed
     */
    private function callCallback(string $callbackProperty, array $args = [], $default = null, ?string $exceptionMessage = null) {
        $callback = $this->{$callbackProperty} ?? null;
        if (!is_callable($callback)) {
            if ($exceptionMessage !== null) {
                throw new GeminiStoreClientException($exceptionMessage);
            }

            return $default;
        }

        return call_user_func_array($callback, $args);
    }

}
