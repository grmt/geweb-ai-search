<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait DocumentStoreCacheTrait {
    public function hasReferencedDocumentOverviewCache(): bool {
        return is_array(UserScope::getGroupScopedOption('geweb_aisearch_referenced_documents_cache', null));
    }

    public function isReferencedDocumentOverviewCacheFresh(): bool {
        $cacheTime = $this->getReferencedDocumentOverviewCacheTime();
        return $cacheTime > 0 && (time() - $cacheTime) < DAY_IN_SECONDS;
    }

    public function getReferencedDocumentOverviewCacheTime(): int {
        return (int) UserScope::getGroupScopedOption('geweb_aisearch_referenced_documents_cache_time', 0);
    }

    public function getReferencedDocumentOverviewDebug(): array {
        $debug = UserScope::getGroupScopedOption('geweb_aisearch_referenced_documents_cache_debug', []);
        return is_array($debug) ? $debug : [];
    }
}
