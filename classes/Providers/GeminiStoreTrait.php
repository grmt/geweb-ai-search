<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait GeminiStoreTrait {
    public function createStore(string $name = 'WebsiteSearch'): bool {
        return $this->createStoreClient()->createStore($name);
    }

    public function getStoreData(): string {
        return (string) $this->getScopedOption(self::OPTION_STORE, '');
    }

    public function deleteStore(): void {
        $this->createStoreClient()->deleteStore();
    }

    public function deleteStoreByResourceName(string $storeName): void {
        $this->createStoreClient()->deleteStoreByResourceName($storeName);
    }

    public function getStoreOverview(bool $forceRefresh = false): array {
        return $this->createStoreClient()->getStoreOverview($forceRefresh);
    }

    public function getStoreOverviewError(): string {
        return (string) $this->getScopedOption(self::OPTION_STORES_CACHE_ERROR, '');
    }

    public function clearStoresCache(): void {
        $this->createStoreClient()->clearStoresCache();
    }

    public function getStoreDocuments(string $storeName, bool $forceRefresh = false): array {
        return $this->createStoreClient()->getStoreDocuments($storeName, $forceRefresh);
    }

    public function hasStoreOverviewCache(): bool {
        return is_array($this->getScopedOption(self::OPTION_STORES_CACHE, null));
    }

    public function getStoreOverviewCacheTime(): int {
        return (int) $this->getScopedOption(self::OPTION_STORES_CACHE_TIME, 0);
    }

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
}
