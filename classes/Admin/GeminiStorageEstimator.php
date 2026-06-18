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
        $remoteStats = $this->buildRemoteDocumentStats(ProviderFactory::make());
        $referencedStats = $this->buildReferencedDocumentStats($items, $remoteStats['size_by_gemini_name']);

        $markdownBytes = (int) ($markdownStats['total_bytes'] ?? 0);
        $markdownCount = (int) ($markdownStats['count'] ?? 0);
        $rawKnownBytes = $markdownBytes + $referencedStats['remote_bytes'] + $referencedStats['local_bytes'];
        $estimatedBackendBytes = (int) round($rawKnownBytes * 3);

        return [
            'markdown_count' => $markdownCount,
            'markdown_bytes' => $markdownBytes,
            'referenced_count' => $referencedStats['count'],
            'referenced_bytes' => $referencedStats['local_bytes'],
            'remote_document_count' => $remoteStats['count'],
            'remote_documents_with_size' => $remoteStats['with_size'],
            'remote_documents_without_size' => $remoteStats['without_size'],
            'remote_referenced_bytes' => $referencedStats['remote_bytes'],
            'unknown_referenced_count' => $referencedStats['unknown_count'],
            'raw_known_bytes' => $rawKnownBytes,
            'estimated_backend_bytes' => $estimatedBackendBytes,
            'recommended_store_limit_bytes' => 21474836480,
            'tier_limits' => self::TIER_LIMITS,
        ];
    }

    public static function formatBytes(int $bytes): string {
        $formatted = $bytes . ' bytes';
        foreach ([
            'TB' => 1099511627776,
            'GB' => 1073741824,
            'MB' => self::BYTES_PER_MB,
            'KB' => 1024,
        ] as $unit => $threshold) {
            if ($bytes >= $threshold) {
                $formatted = round($bytes / $threshold, 2) . ' ' . $unit;
                break;
            }
        }

        return $formatted;
    }

    /**
     * @return array{size_by_gemini_name:array<string,int>,count:int,with_size:int,without_size:int}
     */
    private function buildRemoteDocumentStats(AIProviderInterface $provider): array {
        $stats = [
            'size_by_gemini_name' => [],
            'count' => 0,
            'with_size' => 0,
            'without_size' => 0,
        ];

        if (!$provider instanceof Gemini) {
            return $stats;
        }

        foreach ($provider->getStoreDocuments($provider->getStoreData()) as $document) {
            $this->appendRemoteDocumentStats($stats, $document);
        }

        return $stats;
    }

    /**
     * @param array{size_by_gemini_name:array<string,int>,count:int,with_size:int,without_size:int} $stats
     * @param mixed $document
     */
    private function appendRemoteDocumentStats(array &$stats, $document): void {
        if (!is_array($document)) {
            return;
        }

        $name = trim((string) ($document['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $stats['count']++;
        $sizeBytes = (int) ($document['size_bytes'] ?? 0);
        if ($sizeBytes > 0) {
            $stats['size_by_gemini_name'][$name] = $sizeBytes;
            $stats['with_size']++;
            return;
        }

        $stats['without_size']++;
    }

    /**
     * @param array<int,mixed> $items
     * @param array<string,int> $remoteSizeByGeminiName
     * @return array{count:int,local_bytes:int,remote_bytes:int,unknown_count:int}
     */
    private function buildReferencedDocumentStats(array $items, array $remoteSizeByGeminiName): array {
        $stats = [
            'count' => 0,
            'local_bytes' => 0,
            'remote_bytes' => 0,
            'unknown_count' => 0,
        ];

        foreach ($items as $item) {
            $this->appendReferencedDocumentStats($stats, $item, $remoteSizeByGeminiName);
        }

        return $stats;
    }

    /**
     * @param array{count:int,local_bytes:int,remote_bytes:int,unknown_count:int} $stats
     * @param mixed $item
     * @param array<string,int> $remoteSizeByGeminiName
     */
    private function appendReferencedDocumentStats(array &$stats, $item, array $remoteSizeByGeminiName): void {
        if (!is_array($item) || !$this->isUploadedReferencedDocument($item)) {
            return;
        }

        $stats['count']++;
        $geminiName = trim((string) ($item['gemini_doc_name'] ?? ''));
        if ($geminiName !== '' && isset($remoteSizeByGeminiName[$geminiName])) {
            $stats['remote_bytes'] += (int) $remoteSizeByGeminiName[$geminiName];
            return;
        }

        $localSize = $this->getLocalReferencedFileSize($item);
        if ($localSize === null) {
            $stats['unknown_count']++;
            return;
        }

        $stats['local_bytes'] += $localSize;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function isUploadedReferencedDocument(array $item): bool {
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        return strpos($status, 'uploaded') === 0;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function getLocalReferencedFileSize(array $item): ?int {
        $filePath = trim((string) ($item['file_path'] ?? ''));
        if ($filePath === '' || !file_exists($filePath)) {
            return null;
        }

        $size = @filesize($filePath);
        return (is_int($size) || is_float($size)) ? (int) $size : null;
    }
}
