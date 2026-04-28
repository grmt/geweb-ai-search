<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait ReferencedDocumentListTableHelpersTrait {
    private function getReferencedDocumentSearchText(array $item): string {
        $parts = [(string) ($item['display_name'] ?? ''), (string) ($item['nice_name'] ?? ''), (string) ($item['content_ids'] ?? ''), (string) ($item['document_id'] ?? '')];
        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) { continue; }
            $parts[] = (string) ($post['title'] ?? ''); $parts[] = (string) ($post['id'] ?? '');
        }
        return implode(' ', array_filter(array_map('trim', $parts)));
    }

    private function isIndexedErrorState(string $operationStatus, string $statusColor, bool $isBrokenReference, bool $isMissingFromSimpleFileList): bool {
        return $isBrokenReference || $isMissingFromSimpleFileList || $statusColor === self::COLOR_ERROR || $operationStatus === 'error';
    }

    private function getIndexedErrorHtml(array $item): string {
        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);
        if ($isBrokenReference || $isMissingFromSimpleFileList) {
            return '<p style="margin:6px 0 0; color:' . esc_attr(self::COLOR_ERROR) . ';">' . esc_html($isBrokenReference ? 'Broken link or missing file.' : 'Missing from Simple File List.') . '</p>';
        }
        $operationStatus = (string) sanitize_key((string) ($item['operation_status'] ?? ''));
        $operationError = trim((string) ($item['operation_error'] ?? ''));
        $operationUpdatedAt = (int) ($item['operation_updated_at'] ?? 0);
        if ($operationStatus !== 'error' || $operationError === '') { return ''; }
        $html = '<p style="margin:6px 0 0; color:' . esc_attr(self::COLOR_ERROR) . ';">' . esc_html($operationError) . '</p>';
        if ($operationUpdatedAt > 0) { $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_MUTED) . ';"><small>Last error: ' . esc_html(DateDisplay::formatDateTime($operationUpdatedAt)) . '</small></p>'; }
        return $html;
    }

    private function matchStatusStringPattern(string $status): ?string {
        if (strpos($status, 'uploading') !== false || strpos($status, 'pending') !== false) { return 'uploading'; }
        if (strpos($status, 'removing') !== false || strpos($status, 'excluding') !== false) { return 'excluding'; }
        return null;
    }

    private function computeBasicIndexedFilter(string $statusColor, string $status, bool $includeTarget, bool $isUploaded): string {
        if (!$includeTarget) { return 'excluded'; }
        if (!$isUploaded) { return ($statusColor === self::COLOR_ERROR || strpos($status, 'error') !== false) ? 'error' : 'not_indexed'; }
        return 'indexed';
    }

    private function getIndexedStatusHtml(array $item): string {
        $operationStatus = (string) sanitize_key((string) ($item['operation_status'] ?? ''));
        $status = (string) ($item['status'] ?? '');
        $statusColor = (string) ($item['status_color'] ?? '');
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos($status, 'Uploaded') === 0;
        $isOutOfSync = $isUploaded && !empty($item['modified_after_upload']);
        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);

        if ($operationStatus === 'uploading') {
            $label = 'Uploading';
            $color = self::COLOR_INFO;
        } elseif ($operationStatus === 'excluding') {
            $label = 'Excluding';
            $color = self::COLOR_WARNING;
        } elseif ($this->isIndexedErrorState($operationStatus, $statusColor, $isBrokenReference, $isMissingFromSimpleFileList)) {
            $label = 'Error';
            $color = self::COLOR_ERROR;
        } elseif (!$includeTarget || $operationStatus === 'excluded') {
            $label = 'Excluded';
            $color = self::COLOR_WARNING;
        } elseif ($isOutOfSync) {
            $label = 'Out of sync';
            $color = self::COLOR_ERROR;
        } elseif ($isUploaded || $operationStatus === 'indexed') {
            $label = 'Indexed';
            $color = self::COLOR_SUCCESS;
        } else {
            $label = 'Not indexed';
            $color = self::COLOR_MUTED;
        }

        return '<span style="color:' . esc_attr($color) . ';">' . esc_html($label) . '</span>';
    }

    private function hasReferencedPosts(array $item): bool {
        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            if ((int) ($post['id'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function getTrackedAsLabel(string $geminiDocName): string {
        $normalized = trim($geminiDocName);
        if ($normalized === '') {
            return '';
        }

        $documentMarker = '/documents/';
        $markerPosition = strpos($normalized, $documentMarker);
        if ($markerPosition !== false) {
            return substr($normalized, $markerPosition + strlen($documentMarker));
        }

        $segments = explode('/', $normalized);
        return (string) end($segments);
    }

    private function getTypeFilterValue(string $mimeType): string {
        $mimeType = strtolower(trim($mimeType));
        if ($mimeType === '') {
            return 'unknown';
        }

        $patternMap = [
            'pdf'   => [self::MIME_PDF],
            'word'  => ['msword', 'wordprocessingml'],
            'excel' => ['ms-excel', 'spreadsheetml'],
        ];
        foreach ($patternMap as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($mimeType, $pattern) !== false) {
                    return $type;
                }
            }
        }

        return 'text';
    }

    private function getScopeFilterValue(array $item): string {
        $referenceCount = isset($item['reference_count']) ? (int) $item['reference_count'] : 0;
        $managedBySimpleFileList = !empty($item['managed_by_simple_file_list']);
        $externalReferenceCount = isset($item['external_reference_count']) ? (int) $item['external_reference_count'] : 0;

        if ($referenceCount === 0) {
            return 'not_referenced';
        }
        if ($managedBySimpleFileList && $externalReferenceCount === 0) {
            return 'simple_file_list_only';
        }
        return $externalReferenceCount > 0 ? 'referenced_elsewhere' : 'referenced';
    }

    private function deriveIndexedFilterFromStatus(array $item): string {
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        $statusColor = strtolower(trim((string) ($item['status_color'] ?? '')));
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;

        if ($includeTarget && $isUploaded && !empty($item['modified_after_upload'])) {
            return 'out_of_sync';
        }
        $byPattern = $this->matchStatusStringPattern($status);
        if ($byPattern !== null) {
            return $byPattern;
        }
        return $this->computeBasicIndexedFilter($statusColor, $status, $includeTarget, $isUploaded);
    }

    private function sortItems(array $items): array {
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'display_name';
        $order = isset($_GET['order']) ? strtolower(sanitize_key(wp_unslash($_GET['order']))) : 'asc';
        $allowedOrderBy = ['display_name', 'nice_name', 'status', 'actions', 'markdown_cache', 'mime_type', 'last_modified', 'last_uploaded', 'reference_count'];
        if (!in_array($orderby, $allowedOrderBy, true)) {
            $orderby = 'display_name';
        }

        usort($items, function (array $left, array $right) use ($orderby, $order): int {
            $leftValue = $this->getSortValue($left, $orderby);
            $rightValue = $this->getSortValue($right, $orderby);

            if (is_numeric($leftValue) || is_numeric($rightValue)) {
                $comparison = (int) $leftValue <=> (int) $rightValue;
            } else {
                $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $order === 'desc' ? -$comparison : $comparison;
        });

        return $items;
    }

    private function getSortValue(array $item, string $orderby) {
        $delegateMap = [
            'actions'      => fn() => $this->getIndexedSortWeight($item),
            'pdf_analysis' => fn() => $this->getPdfClassificationSortWeight($item),
            'markdown_cache' => fn() => $this->getMarkdownCacheSortValue($item),
        ];
        if (isset($delegateMap[$orderby])) {
            return ($delegateMap[$orderby])();
        }
        return $item[$orderby] ?? '';
    }

    private function getMarkdownCacheSortValue(array $item): int {
        if (!$this->isMarkdownCacheApplicable($item)) {
            return -1;
        }
        $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
        if ($fileHash === '') {
            return 0;
        }
        return (new ReferencedDocumentMarkdownCacheStore())->getMarkdownBytes($fileHash);
    }

    private function getIndexedSortWeight(array $item): int {
        $state = $this->getIndexedFilterValue($item);
        $weights = [
            'out_of_sync' => 60,
            'indexed' => 50,
            'uploading' => 40,
            'excluding' => 30,
            'not_indexed' => 20,
            'excluded' => 10,
            'error' => 0,
        ];

        return $weights[$state] ?? -1;
    }

    private function getPdfClassificationSortWeight(array $item): int {
        $key = (string) ($item['pdf_classification'] ?? '');
        $weights = [
            'text' => 40,
            'mixed' => 30,
            'unknown' => 20,
            'scanned' => 10,
            'broken' => 0,
        ];

        return $weights[$key] ?? -1;
    }

    private function isMarkdownCacheApplicable(array $item): bool {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType === '') {
            return false;
        }

        if (strpos($mimeType, 'image/') === 0 || $mimeType === self::MIME_PDF) {
            return ((string) ($item['image_processing_mode'] ?? ImageOcrService::MODE_NONE)) !== ImageOcrService::MODE_NONE;
        }

        return strpos($mimeType, 'spreadsheetml') !== false || strpos($mimeType, 'ms-excel') !== false;
    }

    private function buildImageProcessingControlHtml(array $item, bool $disabled): string {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            return '';
        }

        $fileHash = (string) ($item['file_hash'] ?? '');
        if ($fileHash === '') {
            return '';
        }

        $selectId = 'geweb-referenced-document-image-mode-' . substr(sanitize_html_class($fileHash), 0, 12);
        $currentMode = (string) ($item['image_processing_mode'] ?? ImageOcrService::MODE_NONE);
        if (!in_array($currentMode, [ImageOcrService::MODE_NONE, ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE], true)) {
            $currentMode = ImageOcrService::MODE_NONE;
        }

        $html = '<label for="' . esc_attr($selectId) . '" style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;margin-left:8px;">';
        $html .= '<span>Image</span>';
        $html .= '<select id="' . esc_attr($selectId) . '" class="geweb-ai-referenced-document-image-mode" data-file-hash="' . esc_attr($fileHash) . '" style="min-width:96px;"' . disabled($disabled, true, false) . '>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_NONE) . '"' . selected($currentMode, ImageOcrService::MODE_NONE, false) . '>None</option>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_OCR) . '"' . selected($currentMode, ImageOcrService::MODE_OCR, false) . '>OCR</option>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_DESCRIBE) . '"' . selected($currentMode, ImageOcrService::MODE_DESCRIBE, false) . '>Describe</option>';
        $html .= '</select>';
        $html .= '</label>';

        return $html;
    }

    private function buildPdfProcessingControlHtml(array $item, bool $disabled): string {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType !== self::MIME_PDF) {
            return '';
        }

        $fileHash = (string) ($item['file_hash'] ?? '');
        if ($fileHash === '') {
            return '';
        }

        $selectId = 'geweb-referenced-document-pdf-mode-' . substr(sanitize_html_class($fileHash), 0, 12);
        $currentMode = (string) ($item['image_processing_mode'] ?? ImageOcrService::MODE_NONE);
        if (!in_array($currentMode, [ImageOcrService::MODE_NONE, ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE], true)) {
            $currentMode = ImageOcrService::MODE_NONE;
        }

        $html = '<label for="' . esc_attr($selectId) . '" style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">';
        $html .= '<span>PDF</span>';
        $html .= '<select id="' . esc_attr($selectId) . '" class="geweb-ai-referenced-document-image-mode" data-file-hash="' . esc_attr($fileHash) . '" data-processing-subject="pdf" style="min-width:96px;"' . disabled($disabled, true, false) . '>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_NONE) . '"' . selected($currentMode, ImageOcrService::MODE_NONE, false) . '>None</option>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_OCR) . '"' . selected($currentMode, ImageOcrService::MODE_OCR, false) . '>OCR</option>';
        $html .= '<option value="' . esc_attr(ImageOcrService::MODE_DESCRIBE) . '"' . selected($currentMode, ImageOcrService::MODE_DESCRIBE, false) . '>Describe</option>';
        $html .= '</select>';
        $html .= '</label>';

        return $html;
    }

    public function renderStatusCell(array $item): string {
        return $this->getIndexedStatusHtml($item);
    }

    public function renderActionsCell(array $item): string {
        return $this->column_actions($item);
    }

    public function renderMarkdownCacheCell(array $item): string {
        return $this->column_markdown_cache($item);
    }

    public function renderPdfAnalysisCell(array $item): string {
        return $this->column_pdf_analysis($item);
    }

    public function renderNiceNameCell(array $item): string {
        return $this->column_nice_name($item);
    }

    public function renderDisplayNameCell(array $item): string {
        return $this->column_display_name($item);
    }

    public function renderRowHtml(array $item): string {
        ob_start();
        $this->single_row($item);
        return (string) ob_get_clean();
    }
}
