<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders an overview of referenced local documents and their upload status.
 */
class ReferencedDocumentListTable extends \WP_List_Table {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Referenced Document',
            'plural'   => 'Referenced Documents',
            'ajax'     => false,
        ]);
    }

    /**
     * @param array<string,mixed> $item
     */
    public function single_row($item): void {
        $searchText = $this->getReferencedDocumentSearchText($item);
        $statusValue = $this->getIndexedFilterValue($item);
        $typeValue = $this->getTypeFilterValue((string) ($item['mime_type'] ?? ''));
        $referencedInValue = $this->getScopeFilterValue($item);

        $attributes = sprintf(
            ' data-referenced-document-status="%s" data-referenced-document-type="%s" data-referenced-document-referenced-in="%s" data-referenced-document-search="%s"',
            esc_attr($statusValue),
            esc_attr($typeValue),
            esc_attr($referencedInValue),
            esc_attr($searchText)
        );

        echo '<tr' . $attributes . '>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    /**
     * @return string
     */
    private function getReferencedDocumentSearchText(array $item): string {
        $parts = [
            (string) ($item['display_name'] ?? ''),
            (string) ($item['nice_name'] ?? ''),
            (string) ($item['content_ids'] ?? ''),
            (string) ($item['document_id'] ?? ''),
        ];

        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $parts[] = (string) ($post['title'] ?? '');
            $parts[] = (string) ($post['id'] ?? '');
        }

        $parts = array_filter(array_map('trim', $parts));
        return implode(' ', $parts);
    }

    /**
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [
            'display_name'  => 'Name',
            'nice_name'     => 'Nice Name',
            'actions'       => 'AI Indexed',
            'markdown_cache'=> 'MD Cache',
            'tracked_as'    => 'Tracked As',
            'mime_type'     => 'Type',
            'pdf_analysis'  => 'PDF',
            'last_modified' => 'Last Modified',
            'last_uploaded' => 'Upload Date',
            'referenced_in' => 'Reference',
            'content_ids'   => 'Id',
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    protected function get_sortable_columns(): array {
        return [
            'content_ids' => ['reference_count', false],
            'display_name' => ['display_name', true],
            'nice_name' => ['nice_name', false],
            'markdown_cache' => ['markdown_cache', false],
            'mime_type' => ['mime_type', false],
            'pdf_analysis' => ['pdf_analysis', false],
            'last_modified' => ['last_modified', false],
            'last_uploaded' => ['last_uploaded', false],
            'referenced_in' => ['reference_count', false],
            'actions' => ['actions', false],
        ];
    }

    /**
     * Prepare table items.
     * Load ALL items for client-side filtering (ignore server-side filters).
     */
    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $documentStore = new DocumentStore();
        $allItems = $documentStore->getReferencedDocumentOverview();
        // Skip server-side filtering - all filtering is now done client-side in JavaScript
        // $allItems = $this->filterItems($allItems);
        $allItems = $this->sortItems($allItems);

        $totalItems = count($allItems);

        // Set per_page high enough to load all items in a single page
        // Client-side filtering will hide/show rows without pagination
        $perPage = max(500, $totalItems);
        $currentPage = $this->get_pagenum();

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
        ]);

        $this->items = array_slice($allItems, (($currentPage - 1) * $perPage), $perPage);
    }

    /**
     * @param array<string,mixed> $item
     * @param string $column_name
     * @return string
     */
    protected function column_default($item, $column_name): string {
        return isset($item[$column_name]) ? (string) $item[$column_name] : '—';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_display_name($item): string {
        $label = isset($item['display_name']) ? (string) $item['display_name'] : '—';
        $url = isset($item['file_url']) ? (string) $item['file_url'] : '';
        $filePath = isset($item['file_path']) ? (string) $item['file_path'] : '';
        $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
        $managedBySimpleFileList = !empty($item['managed_by_simple_file_list']) && $filePath !== '';
        $isReferenced = $this->hasReferencedPosts($item);
        $isMissingFromSimpleFileList = $isReferenced && !$managedBySimpleFileList;
        $isBrokenReference = !empty($item['broken_reference']);

        $actions = [];
        if ($url !== '') {
            $actions[] = '<span class="download"><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Download</a></span>';
        }

        if ($managedBySimpleFileList) {
            $support = new SimpleFileListSupport();
            $actions[] = '<span class="view"><a href="' . esc_url($support->buildSimpleFileListAdminUrl($filePath)) . '">Show in File List</a></span>';
            if ($fileHash !== '' && !$isReferenced) {
                $actions[] = '<span class="trash"><a href="#" class="geweb-referenced-document-remove-from-file-list" data-file-hash="' . esc_attr($fileHash) . '">Remove from File List</a></span>';
            }
        }

        $html = '<strong>' . esc_html($label) . '</strong>';
        if ($isBrokenReference) {
            $html .= '<div><small style="color:#d63638;font-weight:600;">Broken link or missing file</small></div>';
        }
        if ($isMissingFromSimpleFileList) {
            $html .= '<div><small style="color:#d63638;font-weight:600;">Missing from Simple File List</small></div>';
        }
        $duplicateNotice = (new FileDuplicateHashIndex())->getReferencedFileDuplicateNotice($fileHash, $filePath);
        if ($duplicateNotice !== '') {
            $html .= '<div><small style="color:#996800;font-weight:600;">' . esc_html($duplicateNotice) . '</small></div>';
        }
        if (!empty($actions)) {
            $html .= '<div class="row-actions visible">' . implode(' | ', $actions) . '</div>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_content_ids($item): string {
        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        if (empty($posts)) {
            return '—';
        }

        $ids = [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $postId = isset($post['id']) ? (int) $post['id'] : 0;
            if ($postId > 0) {
                $ids[] = (string) $postId;
            }
        }

        return empty($ids) ? '—' : esc_html(implode(', ', array_values(array_unique($ids))));
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_nice_name($item): string {
        $niceName = isset($item['nice_name']) ? trim((string) $item['nice_name']) : '';
        $displayName = isset($item['display_name']) ? trim((string) $item['display_name']) : '';
        $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
        $isEditable = !empty($item['managed_by_simple_file_list']) && $fileHash !== '';
        $niceNameInputId = 'geweb-edit-nice-name-' . substr(sanitize_html_class($fileHash), 0, 12);

        if ($niceName === '' || $niceName === $displayName) {
            $niceName = '';
        }

        if (!$isEditable) {
            return $niceName === '' ? '—' : esc_html($niceName);
        }

        $placeholder = $displayName !== '' ? $displayName : 'Enter a nice name';

        return sprintf(
            '<div class="geweb-nice-name-cell" data-file-hash="%s" data-current-nice-name="%s" data-placeholder="%s">' .
            '<button type="button" class="button-link geweb-edit-nice-name-trigger">%s</button>' .
            '<div class="geweb-edit-nice-name-form" style="display:none; margin-top:4px;">' .
            '<input type="text" id="%s" name="%s" class="regular-text geweb-edit-nice-name-input" value="%s" placeholder="%s" style="max-width:24rem;"> ' .
            '<button type="button" class="button button-small geweb-save-nice-name">Save</button> ' .
            '<button type="button" class="button-link geweb-cancel-nice-name">Cancel</button>' .
            '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>' .
            '</div>' .
            '</div>',
            esc_attr($fileHash),
            esc_attr($niceName),
            esc_attr($placeholder),
            esc_html($niceName !== '' ? $niceName : 'Add nice name'),
            esc_attr($niceNameInputId),
            esc_attr($niceNameInputId),
            esc_attr($niceName),
            esc_attr($placeholder)
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    private function getIndexedStatusHtml(array $item): string {
        $operationStatus = sanitize_key((string) ($item['operation_status'] ?? ''));
        $status = (string) ($item['status'] ?? '');
        $statusColor = (string) ($item['status_color'] ?? '');
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos($status, 'Uploaded') === 0;
        $isOutOfSync = $isUploaded && !empty($item['modified_after_upload']);
        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);

        if ($operationStatus === 'uploading') {
            $label = 'Uploading';
            $color = '#2271b1';
        } elseif ($operationStatus === 'excluding') {
            $label = 'Excluding';
            $color = '#996800';
        } elseif ($isBrokenReference) {
            $label = 'Error';
            $color = '#d63638';
        } elseif ($isMissingFromSimpleFileList) {
            $label = 'Error';
            $color = '#d63638';
        } elseif ($statusColor === '#d63638') {
            $label = 'Error';
            $color = '#d63638';
        } elseif ($operationStatus === 'error') {
            $label = 'Error';
            $color = '#d63638';
        } elseif (!$includeTarget || $operationStatus === 'excluded') {
            $label = 'Excluded';
            $color = '#996800';
        } elseif ($isOutOfSync) {
            $label = 'Out of sync';
            $color = '#d63638';
        } elseif ($isUploaded || $operationStatus === 'indexed') {
            $label = 'Indexed';
            $color = '#46b450';
        } else {
            $label = 'Not indexed';
            $color = '#646970';
        }

        return '<span style="color:' . esc_attr($color) . ';">' . esc_html($label) . '</span>';
    }

    /**
     * @param array<string,mixed> $item
     */
    private function getIndexedErrorHtml(array $item): string {
        $operationStatus = sanitize_key((string) ($item['operation_status'] ?? ''));
        $operationError = trim((string) ($item['operation_error'] ?? ''));
        $operationUpdatedAt = (int) ($item['operation_updated_at'] ?? 0);
        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);

        if ($isBrokenReference) {
            return '<p style="margin:6px 0 0; color:#d63638;">Broken link or missing file.</p>';
        }

        if ($isMissingFromSimpleFileList) {
            return '<p style="margin:6px 0 0; color:#d63638;">Missing from Simple File List.</p>';
        }

        if ($operationStatus !== 'error' || $operationError === '') {
            return '';
        }

        $html = '<p style="margin:6px 0 0; color:#d63638;">' . esc_html($operationError) . '</p>';
        if ($operationUpdatedAt > 0) {
            $html .= '<p style="margin:4px 0 0; color:#646970;"><small>Last error: ' . esc_html(DateDisplay::formatDateTime($operationUpdatedAt)) . '</small></p>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_tracked_as($item): string {
        $documentId = isset($item['document_id']) ? (int) $item['document_id'] : 0;
        $geminiDocName = isset($item['gemini_doc_name']) ? trim((string) $item['gemini_doc_name']) : '';

        if ($documentId <= 0 && $geminiDocName === '') {
            return '—';
        }

        $parts = [];
        if ($documentId > 0) {
            $parts[] = '<code>#' . esc_html((string) $documentId) . '</code>';
        }

        if ($geminiDocName !== '') {
            $parts[] = '<code title="' . esc_attr($geminiDocName) . '">' . esc_html($this->getTrackedAsLabel($geminiDocName)) . '</code>';
        }

        return implode('<br>', $parts);
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_markdown_cache($item): string {
        $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
        if ($fileHash === '') {
            return '—';
        }

        if (!$this->isMarkdownCacheApplicable($item)) {
            return '<span style="color:#646970;">N/A</span>';
        }

        $cacheStore = new ReferencedDocumentMarkdownCacheStore();
        $bytes = $cacheStore->getMarkdownBytes($fileHash);
        if ($bytes <= 0) {
            $lastUploaded = isset($item['last_uploaded']) ? (int) $item['last_uploaded'] : 0;
            if ($lastUploaded > 0) {
                return '<span style="color:#646970;">N/A</span>';
            }

            return '<span style="color:#646970;">Missing</span>';
        }

        return sprintf(
            '<a href="#" class="geweb-ai-markdown-cache-view" data-cache-kind="document" data-file-hash="%s" title="%s" style="display:inline-block;font-weight:600;color:%s;">%s</a>',
            esc_attr($fileHash),
            esc_attr__('View cached Markdown for this document', 'geweb-ai-search'),
            esc_attr('#46b450'),
            esc_html(size_format($bytes, 1))
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_last_uploaded($item): string {
        $timestamp = isset($item['last_uploaded']) ? (int) $item['last_uploaded'] : 0;
        if ($timestamp <= 0) {
            return '—';
        }

        return DateDisplay::formatDateTime($timestamp);
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_last_modified($item): string {
        $timestamp = isset($item['last_modified']) ? (int) $item['last_modified'] : 0;
        if ($timestamp <= 0) {
            return '—';
        }

        $label = DateDisplay::formatDateTime($timestamp);
        $modifiedAfterUpload = !empty($item['modified_after_upload']);
        if (!$modifiedAfterUpload) {
            return $label;
        }

        return sprintf(
            '%s<br><small style="color:#996800;">Modified after upload</small>',
            esc_html($label)
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_pdf_analysis($item): string {
        if (((string) ($item['mime_type'] ?? '')) !== 'application/pdf') {
            return '—';
        }

        $label = trim((string) ($item['pdf_classification_label'] ?? ''));
        $details = trim((string) ($item['pdf_classification_details'] ?? ''));
        if ($label === '') {
            return '<span style="color:#646970;">Unknown PDF</span>';
        }

        $color = '#646970';
        $key = (string) ($item['pdf_classification'] ?? '');
        if ($key === 'text') {
            $color = '#46b450';
        } elseif ($key === 'mixed') {
            $color = '#996800';
        } elseif ($key === 'scanned' || $key === 'broken') {
            $color = '#d63638';
        }

        $title = $details !== '' ? ' title="' . esc_attr($details) . '"' : '';
        $html = '<span style="color:' . esc_attr($color) . ';"' . $title . '>' . esc_html($label) . '</span>';
        $processingControlHtml = $this->buildPdfProcessingControlHtml($item, in_array(sanitize_key((string) ($item['operation_status'] ?? '')), ['uploading', 'excluding'], true));
        if ($processingControlHtml !== '') {
            $html .= '<div style="margin-top:6px;">' . $processingControlHtml . '</div>';
            $html .= '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_referenced_in($item): string {
        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        if (empty($posts)) {
            return 'Not referenced';
        }

        $links = [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $title = isset($post['title']) ? (string) $post['title'] : 'Untitled';
            $editUrl = isset($post['edit_url']) ? (string) $post['edit_url'] : '';
            if ($editUrl !== '') {
                $links[] = '<a href="' . esc_url($editUrl) . '">' . esc_html($title) . '</a>';
                continue;
            }

            $links[] = esc_html($title);
        }

        return empty($links) ? 'Not referenced' : implode('<br>', $links);
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_actions($item): string {
        $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
        if ($fileHash === '') {
            return '—';
        }

        $excludeToggleId = 'geweb-referenced-document-exclude-' . substr(sanitize_html_class($fileHash), 0, 12);

        $status = isset($item['status']) ? (string) $item['status'] : '';
        $operationStatus = sanitize_key((string) ($item['operation_status'] ?? ''));
        $isUploaded = strpos($status, 'Uploaded') === 0;
        $isBrokenReference = !empty($item['broken_reference']);
        $canManage = !$isBrokenReference && ($isUploaded || !empty($item['file_path']));
        $includeTarget = !empty($item['include_in_store_target']);
        $isTransitioning = in_array($operationStatus, ['uploading', 'excluding'], true);
        $disableUpload = !$includeTarget || $isTransitioning;
        $disableExcludeToggle = $isTransitioning;
        $statusHtml = $this->getIndexedStatusHtml($item);
        $errorHtml = $this->getIndexedErrorHtml($item);
        $imageProcessingControlHtml = $this->buildImageProcessingControlHtml($item, $isTransitioning);

        if (!$canManage) {
            return '<div class="geweb-ai-index-cell geweb-referenced-document-cell" data-file-hash="' . esc_attr($fileHash) . '"><p style="margin:0 0 6px;">' . $statusHtml . '</p>' . $errorHtml . '<p style="margin:6px 0 0;">—</p><p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p></div>';
        }

        return sprintf(
            '<div class="geweb-ai-index-cell geweb-referenced-document-cell" data-file-hash="%s" data-current-uploaded="%s" data-current-target="%s">' .
            '<p style="margin:0 0 6px;">%s</p>' .
            '%s' .
            '<p style="margin:8px 0 0;"><button type="button" class="button button-small geweb-referenced-document-upload-now" data-file-hash="%s"%s>Upload</button></p>' .
            '%s' .
            '<p style="margin:6px 0 0;"><label for="%s" style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;"><input type="checkbox" id="%s" name="%s" class="geweb-referenced-document-toggle-exclude" data-file-hash="%s" %s%s> <span>Exclude</span></label></p>' .
            '%s' .
            '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>' .
            '</div>',
            esc_attr($fileHash),
            $isUploaded ? '1' : '0',
            $includeTarget ? '1' : '0',
            $statusHtml,
            $errorHtml,
            esc_attr($fileHash),
            $disableUpload ? ' disabled' : '',
            $isUploaded && !empty($item['modified_after_upload'])
                ? '<button type="button" class="button button-small geweb-referenced-document-remove-now" data-file-hash="' . esc_attr($fileHash) . '" style="margin-left:8px;">Remove from store</button> '
                : '',
            esc_attr($excludeToggleId),
            esc_attr($excludeToggleId),
            esc_attr($excludeToggleId),
            esc_attr($fileHash),
            checked(!$includeTarget, true, false),
            $disableExcludeToggle ? ' disabled' : '',
            $imageProcessingControlHtml
        );
    }

    /**
     * Render status cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderStatusCell(array $item): string {
        return $this->getIndexedStatusHtml($item);
    }

    /**
     * Render actions cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderActionsCell(array $item): string {
        return $this->column_actions($item);
    }

    /**
     * Render markdown-cache cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderMarkdownCacheCell(array $item): string {
        return $this->column_markdown_cache($item);
    }

    /**
     * Render PDF-analysis cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderPdfAnalysisCell(array $item): string {
        return $this->column_pdf_analysis($item);
    }

    /**
     * Render nice-name cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderNiceNameCell(array $item): string {
        return $this->column_nice_name($item);
    }

    /**
     * Render name cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderDisplayNameCell(array $item): string {
        return $this->column_display_name($item);
    }

    /**
     * Render a single row HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderRowHtml(array $item): string {
        ob_start();
        $this->single_row($item);
        return (string) ob_get_clean();
    }

    /**
     * Display pagination or items info.
     * Only show on top, hide on bottom.
     *
     * @param string $which
     * @return void
     */
    protected function display_pagination_or_items($which): void {
        if ($which === 'bottom') {
            return;
        }
        parent::display_pagination_or_items($which);
    }

    /**
     * Render extra controls above the table.
     *
     * @param string $which
     * @return void
     */
    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }

        $totalItems = $this->get_pagination_arg('total_items');
        $itemsText = $totalItems === 1 ? '1 document' : $totalItems . ' documents';

        $selectedStatus = isset($_GET['geweb_ai_referenced_doc_status'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_status']))
            : '';
        $selectedType = isset($_GET['geweb_ai_referenced_doc_type'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_type']))
            : '';
        $selectedReferencedIn = isset($_GET['geweb_ai_referenced_doc_referenced_in'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_referenced_in']))
            : '';
        ?>
        <div class="alignleft actions" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <label class="screen-reader-text" for="geweb_ai_referenced_doc_status">Filter documents by status</label>
            <select name="geweb_ai_referenced_doc_status" id="geweb_ai_referenced_doc_status">
                <option value="">All AI Indexed states</option>
                <option value="indexed" <?php selected($selectedStatus, 'indexed'); ?>>Indexed</option>
                <option value="out_of_sync" <?php selected($selectedStatus, 'out_of_sync'); ?>>Out of sync</option>
                <option value="uploading" <?php selected($selectedStatus, 'uploading'); ?>>Uploading</option>
                <option value="excluding" <?php selected($selectedStatus, 'excluding'); ?>>Excluding</option>
                <option value="not_indexed" <?php selected($selectedStatus, 'not_indexed'); ?>>Not indexed</option>
                <option value="error" <?php selected($selectedStatus, 'error'); ?>>Error</option>
                <option value="excluded" <?php selected($selectedStatus, 'excluded'); ?>>Excluded</option>
            </select>
            <label class="screen-reader-text" for="geweb_ai_referenced_doc_type">Filter documents by file type</label>
            <select name="geweb_ai_referenced_doc_type" id="geweb_ai_referenced_doc_type">
                <option value="">All file types</option>
                <option value="pdf" <?php selected($selectedType, 'pdf'); ?>>PDF</option>
                <option value="word" <?php selected($selectedType, 'word'); ?>>Word</option>
                <option value="excel" <?php selected($selectedType, 'excel'); ?>>Excel (.xls/.xlsx)</option>
                <option value="text" <?php selected($selectedType, 'text'); ?>>Text and other</option>
                <option value="unknown" <?php selected($selectedType, 'unknown'); ?>>Unknown type</option>
            </select>
            <label class="screen-reader-text" for="geweb_ai_referenced_doc_referenced_in">Filter documents by referenced in</label>
            <select name="geweb_ai_referenced_doc_referenced_in" id="geweb_ai_referenced_doc_referenced_in">
                <option value="">Referenced In: Any</option>
                <option value="referenced" <?php selected($selectedReferencedIn, 'referenced'); ?>>Referenced</option>
                <option value="not_referenced" <?php selected($selectedReferencedIn, 'not_referenced'); ?>>Not referenced</option>
                <option value="simple_file_list_only" <?php selected($selectedReferencedIn, 'simple_file_list_only'); ?>>Only in Simple File List</option>
            </select>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'geweb-ai-search', 'geweb_tab' => 'documents'], admin_url('admin.php'))); ?>" class="button geweb-reset-referenced-documents-filters" style="margin-left:4px;">Reset</a>
        </div>
        <style>
            .wp-list-table.geweb-referenced-documents-table .tablenav-pages,
            .wp-list-table.geweb-referenced-documents-table .pagination-links {
                display: none;
            }
        </style>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function filterItems(array $items): array {
        $selectedStatus = isset($_GET['geweb_ai_referenced_doc_status'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_status']))
            : '';
        $selectedType = isset($_GET['geweb_ai_referenced_doc_type'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_type']))
            : '';
        $selectedReferencedIn = isset($_GET['geweb_ai_referenced_doc_referenced_in'])
            ? sanitize_text_field(wp_unslash($_GET['geweb_ai_referenced_doc_referenced_in']))
            : '';
        $searchTerm = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        if ($selectedStatus === '' && $selectedType === '' && $selectedReferencedIn === '' && $searchTerm === '') {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($selectedStatus, $selectedType, $selectedReferencedIn, $searchTerm): bool {
            if ($selectedStatus !== '' && $this->getIndexedFilterValue($item) !== $selectedStatus) {
                return false;
            }

            if ($selectedType !== '' && $this->getTypeFilterValue((string) ($item['mime_type'] ?? '')) !== $selectedType) {
                return false;
            }

            if ($selectedReferencedIn !== '' && $this->getScopeFilterValue($item) !== $selectedReferencedIn) {
                return false;
            }

            if ($searchTerm !== '' && !$this->itemMatchesSearch($item, $searchTerm)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<string,mixed> $item
     * @param string $searchTerm
     * @return bool
     */
    private function itemMatchesSearch(array $item, string $searchTerm): bool {
        $needle = function_exists('mb_strtolower') ? mb_strtolower(trim($searchTerm)) : strtolower(trim($searchTerm));
        if ($needle === '') {
            return true;
        }

        $haystackParts = [
            (string) ($item['display_name'] ?? ''),
            (string) ($item['nice_name'] ?? ''),
            (string) ($item['content_ids'] ?? ''),
            (string) ($item['document_id'] ?? ''),
        ];

        $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $haystackParts[] = (string) ($post['title'] ?? '');
            $haystackParts[] = (string) ($post['id'] ?? '');
        }

        $haystack = function_exists('mb_strtolower')
            ? mb_strtolower(implode("\n", $haystackParts))
            : strtolower(implode("\n", $haystackParts));

        return str_contains($haystack, $needle);
    }

    /**
     * @param array<string,mixed> $item
     */
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

    /**
     * @param string $mimeType
     * @return string
     */
    private function getTypeFilterValue(string $mimeType): string {
        $mimeType = strtolower(trim($mimeType));
        if ($mimeType === '') {
            return 'unknown';
        }

        if ($mimeType === 'application/pdf') {
            return 'pdf';
        }

        if (
            strpos($mimeType, 'msword') !== false ||
            strpos($mimeType, 'wordprocessingml') !== false
        ) {
            return 'word';
        }

        if (
            strpos($mimeType, 'ms-excel') !== false ||
            strpos($mimeType, 'spreadsheetml') !== false
        ) {
            return 'excel';
        }

        return 'text';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
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

        if ($externalReferenceCount > 0) {
            return 'referenced_elsewhere';
        }

        return 'referenced';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    private function getIndexedFilterValue(array $item): string {
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        $statusColor = strtolower(trim((string) ($item['status_color'] ?? '')));
        $operationStatus = sanitize_key((string) ($item['operation_status'] ?? ''));
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;
        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);

        if ($operationStatus === 'uploading') {
            return 'uploading';
        }

        if ($operationStatus === 'excluding') {
            return 'excluding';
        }

        if ($operationStatus === 'error') {
            return 'error';
        }

        if ($operationStatus === 'excluded') {
            return 'excluded';
        }

        if ($operationStatus === 'indexed') {
            return 'indexed';
        }

        if ($isBrokenReference) {
            return 'error';
        }

        if ($isMissingFromSimpleFileList) {
            return 'error';
        }

        if ($includeTarget && $isUploaded && !empty($item['modified_after_upload'])) {
            return 'out_of_sync';
        }

        if (strpos($status, 'uploading') !== false || strpos($status, 'pending') !== false) {
            return 'uploading';
        }

        if (strpos($status, 'removing') !== false || strpos($status, 'excluding') !== false) {
            return 'excluding';
        }

        if (!$includeTarget) {
            return 'excluded';
        }

        if ($isUploaded) {
            return 'indexed';
        }

        if ($statusColor === '#d63638' || strpos($status, 'error') !== false) {
            return 'error';
        }

        return 'not_indexed';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
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

    /**
     * @param array<string,mixed> $item
     * @return int|string
     */
    private function getSortValue(array $item, string $orderby) {
        if ($orderby === 'actions') {
            return $this->getIndexedSortWeight($item);
        }

        if ($orderby === 'markdown_cache') {
            if (!$this->isMarkdownCacheApplicable($item)) {
                return -1;
            }

            $fileHash = isset($item['file_hash']) ? (string) $item['file_hash'] : '';
            if ($fileHash === '') {
                return 0;
            }

            return (new ReferencedDocumentMarkdownCacheStore())->getMarkdownBytes($fileHash);
        }

        if ($orderby === 'pdf_analysis') {
            return $this->getPdfClassificationSortWeight($item);
        }

        return $item[$orderby] ?? '';
    }

    /**
     * @param array<string,mixed> $item
     */
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

    /**
     * @param array<string,mixed> $item
     */
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

    /**
     * @param array<string,mixed> $item
     */
    private function isMarkdownCacheApplicable(array $item): bool {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType === '') {
            return false;
        }

        if (strpos($mimeType, 'image/') === 0) {
            return ((string) ($item['image_processing_mode'] ?? ImageOcrService::MODE_NONE)) !== ImageOcrService::MODE_NONE;
        }

        if ($mimeType === 'application/pdf') {
            return ((string) ($item['image_processing_mode'] ?? ImageOcrService::MODE_NONE)) !== ImageOcrService::MODE_NONE;
        }

        return strpos($mimeType, 'spreadsheetml') !== false || strpos($mimeType, 'ms-excel') !== false;
    }

    /**
     * @param array<string,mixed> $item
     */
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

    /**
     * @param array<string,mixed> $item
     */
    private function buildPdfProcessingControlHtml(array $item, bool $disabled): string {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType !== 'application/pdf') {
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
}
