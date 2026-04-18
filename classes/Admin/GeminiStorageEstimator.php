<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class GeminiStorageEstimator {
    private const BYTES_PER_MB = 1048576;
    private const TIER_LIMITS = [
        'Free' => 1073741824,
        'Tier 1' => 10737418240,
        'Tier 2' => 107374182400,
        'Tier 3' => 1099511627776,
    ];

    private DocumentStore $documentStore;
    private MarkdownCacheStore $markdownCacheStore;

    public function __construct(?DocumentStore $documentStore = null, ?MarkdownCacheStore $markdownCacheStore = null) {
        $this->documentStore = $documentStore ?? new DocumentStore();
        $this->markdownCacheStore = $markdownCacheStore ?? new MarkdownCacheStore();
    }

    /**
     * @return array<string,mixed>
     */
    public function buildEstimate(): array {
        $markdownStats = $this->markdownCacheStore->getCacheStats();
        $referencedOverview = $this->documentStore->getReferencedDocumentOverview();
        $items = is_array($referencedOverview['items'] ?? null) ? $referencedOverview['items'] : [];
        $provider = ProviderFactory::make();
        $remoteSizeByGeminiName = [];
        $remoteDocumentCount = 0;
        $remoteDocumentsWithSize = 0;
        $remoteDocumentsWithoutSize = 0;

        if ($provider instanceof Gemini) {
            foreach ($provider->getStoreDocuments($provider->getStoreData()) as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $name = trim((string) ($document['name'] ?? ''));
                $sizeBytes = (int) ($document['size_bytes'] ?? 0);
                if ($name === '') {
                    continue;
                }

                $remoteDocumentCount++;
                if ($sizeBytes > 0) {
                    $remoteSizeByGeminiName[$name] = $sizeBytes;
                    $remoteDocumentsWithSize++;
                    continue;
                }

                $remoteDocumentsWithoutSize++;
            }
        }

        $referencedBytes = 0;
        $referencedCount = 0;
        $unknownReferencedCount = 0;
        $remoteReferencedBytes = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $status = strtolower(trim((string) ($item['status'] ?? '')));
            if (strpos($status, 'uploaded') !== 0) {
                continue;
            }

            $referencedCount++;
            $geminiName = trim((string) ($item['gemini_doc_name'] ?? ''));
            if ($geminiName !== '' && isset($remoteSizeByGeminiName[$geminiName])) {
                $remoteReferencedBytes += (int) $remoteSizeByGeminiName[$geminiName];
                continue;
            }

            $filePath = trim((string) ($item['file_path'] ?? ''));
            if ($filePath === '' || !file_exists($filePath)) {
                $unknownReferencedCount++;
                continue;
            }

            $size = @filesize($filePath);
            if (!is_int($size) && !is_float($size)) {
                $unknownReferencedCount++;
                continue;
            }

            $referencedBytes += (int) $size;
        }

        $markdownBytes = (int) ($markdownStats['total_bytes'] ?? 0);
        $markdownCount = (int) ($markdownStats['count'] ?? 0);
        $rawKnownBytes = $markdownBytes + $remoteReferencedBytes + $referencedBytes;
        $estimatedBackendBytes = (int) round($rawKnownBytes * 3);

        return [
            'markdown_count' => $markdownCount,
            'markdown_bytes' => $markdownBytes,
            'referenced_count' => $referencedCount,
            'referenced_bytes' => $referencedBytes,
            'remote_document_count' => $remoteDocumentCount,
            'remote_documents_with_size' => $remoteDocumentsWithSize,
            'remote_documents_without_size' => $remoteDocumentsWithoutSize,
            'remote_referenced_bytes' => $remoteReferencedBytes,
            'unknown_referenced_count' => $unknownReferencedCount,
            'raw_known_bytes' => $rawKnownBytes,
            'estimated_backend_bytes' => $estimatedBackendBytes,
            'recommended_store_limit_bytes' => 21474836480,
            'tier_limits' => self::TIER_LIMITS,
        ];
    }

    public static function formatBytes(int $bytes): string {
        if ($bytes >= 1099511627776) {
            return round($bytes / 1099511627776, 2) . ' TB';
        }

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= self::BYTES_PER_MB) {
            return round($bytes / self::BYTES_PER_MB, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
