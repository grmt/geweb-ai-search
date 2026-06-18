<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminReferencedDocumentAjaxSupport {
    public const MESSAGE_MISSING_FILE_HASH = 'Missing file hash.';

    public static function getRequestedFileHash(): string {
        return isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
    }

    public static function requireRequestedFileHash(): string {
        $fileHash = self::getRequestedFileHash();
        if ($fileHash === '') {
            wp_send_json_error(['message' => self::MESSAGE_MISSING_FILE_HASH], 400);
        }

        return $fileHash;
    }

    public static function touchFilesCacheState(): array {
        AdminViewRevision::touchFiles();
        return AdminViewRevision::ensureCurrentState();
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildRevisionPayload(): array {
        return [
            'group_revision' => GroupDataRevision::touch(),
            'cache_state' => self::touchFilesCacheState(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    public static function findItemByHash(array $items, string $fileHash): ?array {
        foreach ($items as $item) {
            if (is_array($item) && (($item['file_hash'] ?? '') === $fileHash)) {
                return $item;
            }
        }

        return null;
    }

    public static function getUpdatedItem(DocumentStore $documentStore, string $fileHash): ?array {
        return self::findItemByHash($documentStore->getReferencedDocumentOverview(true), $fileHash);
    }

    /**
     * @param array<string,mixed>|null $item
     * @return array<string,mixed>
     */
    public static function buildRowPayload(?array $item, bool $includeRowHtml = false): array {
        $table = new ReferencedDocumentListTable();
        $payload = [
            'row_exists' => is_array($item),
            'status_html' => is_array($item) ? $table->renderStatusCell($item) : '',
            'actions_html' => is_array($item) ? $table->renderActionsCell($item) : '',
            'markdown_cache_html' => is_array($item) ? $table->renderMarkdownCacheCell($item) : '',
        ];

        if ($includeRowHtml) {
            $payload['row_html'] = is_array($item) ? $table->renderRowHtml($item) : '';
        }

        return $payload;
    }

    /**
     * @param array<string,mixed>|null $item
     * @return array<string,mixed>
     */
    public static function buildImageProcessingRowPayload(?array $item): array {
        $table = new ReferencedDocumentListTable();
        return [
            'row_exists' => is_array($item),
            'actions_html' => is_array($item) ? $table->renderActionsCell($item) : '',
            'pdf_analysis_html' => is_array($item) ? $table->renderPdfAnalysisCell($item) : '',
            'markdown_cache_html' => is_array($item) ? $table->renderMarkdownCacheCell($item) : '',
        ];
    }

    /**
     * @param array<string,mixed>|null $item
     */
    public static function getProcessingSubject(?array $item): string {
        return is_array($item) && ((string) ($item['mime_type'] ?? '')) === 'application/pdf' ? 'PDF' : 'image';
    }

    public static function buildImageProcessingMessage(string $mode, string $subject, string $cacheWarning): string {
        if ($mode === ImageOcrService::MODE_DESCRIBE) {
            $message = $subject === 'PDF' ? 'PDF description enabled.' : 'Image description enabled.';
        } elseif ($mode === ImageOcrService::MODE_DOCUMENT_AI_OCR) {
            $message = $subject === 'PDF' ? 'Document AI OCR enabled for this PDF.' : 'Document AI OCR is only supported for PDFs.';
        } elseif ($mode === ImageOcrService::MODE_OCR) {
            $message = $subject === 'PDF' ? 'Markdown extraction enabled for this PDF.' : 'OCR enabled for this image.';
        } else {
            $message = $subject === 'PDF' ? 'PDF processing disabled.' : 'Image processing disabled.';
        }

        if ($cacheWarning !== '') {
            $message .= ' Cache could not be generated yet: ' . $cacheWarning;
        }

        return $message;
    }

    public static function renderReferencedDocumentsTable(AdminPageSections $adminPageSections): string {
        ob_start();
        $adminPageSections->renderReferencedDocumentsTable();
        return (string) ob_get_clean();
    }
}
