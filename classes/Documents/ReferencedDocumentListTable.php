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
    use ReferencedDocumentListTableHelpersTrait;

    private const COLOR_WARNING = '#996800';
    private const COLOR_SUCCESS = '#46b450';
    private const COLOR_INFO = '#2271b1';
    private const COLOR_MUTED = '#646970';
    private const COLOR_ERROR = '#d63638';
    private const MIME_PDF = 'application/pdf';

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
            'pdf_analysis'  => 'Analysis',
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
    public function prepare_items(?array $items = null): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $allItems = is_array($items) ? $items : (new DocumentStore())->getReferencedDocumentOverview();
        // Skip server-side filtering; JavaScript handles it client-side.
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
            $html .= '<div><small style="color:' . esc_attr(self::COLOR_ERROR) . ';font-weight:600;">Broken link or missing file</small></div>';
        }
        if ($isMissingFromSimpleFileList) {
            $html .= '<div><small style="color:' . esc_attr(self::COLOR_ERROR) . ';font-weight:600;">Missing from Simple File List</small></div>';
        }
        $duplicateNotice = (new FileDuplicateHashIndex())->getReferencedFileDuplicateNotice($fileHash, $filePath);
        if ($duplicateNotice !== '') {
            $html .= '<div><small style="color:' . esc_attr(self::COLOR_WARNING) . ';font-weight:600;">' . esc_html($duplicateNotice) . '</small></div>';
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
            return '<span style="color:' . esc_attr(self::COLOR_MUTED) . ';">N/A</span>';
        }
        return $this->buildMarkdownCacheCellHtml($fileHash, $item);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function buildMarkdownCacheCellHtml(string $fileHash, array $item): string {
        $bytes = (new ReferencedDocumentMarkdownCacheStore())->getMarkdownBytes($fileHash);
        if ($bytes <= 0) {
            $lastUploaded = isset($item['last_uploaded']) ? (int) $item['last_uploaded'] : 0;
            $label = $lastUploaded > 0 ? 'N/A' : 'Missing';
            return '<span style="color:' . esc_attr(self::COLOR_MUTED) . ';">' . esc_html($label) . '</span>';
        }
        return sprintf(
            '<a href="#" class="geweb-ai-markdown-cache-view" data-cache-kind="document" data-file-hash="%s" title="%s" style="display:inline-block;font-weight:600;color:%s;">%s</a>',
            esc_attr($fileHash),
            esc_attr__('View cached Markdown for this document', 'geweb-ai-search'),
            esc_attr(self::COLOR_SUCCESS),
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
            '%s<br><small style="color:' . esc_attr(self::COLOR_WARNING) . ';">Modified after upload</small>',
            esc_html($label)
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_pdf_analysis($item): string {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        $isPdf = $mimeType === self::MIME_PDF;
        $isImage = strpos($mimeType, 'image/') === 0;
        if (!$isPdf && !$isImage) {
            return '—';
        }

        $isTransitioning = in_array(sanitize_key((string) ($item['operation_status'] ?? '')), ['uploading', 'excluding'], true);
        if ($isImage) {
            $html = $this->buildAnalysisLabelHtml($item, 'Image', self::COLOR_SUCCESS, '');
            $processingControlHtml = $this->buildImageProcessingControlHtml($item, $isTransitioning);
            if ($processingControlHtml !== '') {
                $html .= '<div style="margin-top:6px;">' . $processingControlHtml . '</div>';
                $html .= '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>';
            }

            return $html;
        }

        $color = self::COLOR_MUTED;
        $label = trim((string) ($item['pdf_classification_label'] ?? ''));
        $details = trim((string) ($item['pdf_classification_details'] ?? ''));
        if ($label === '') {
            $label = 'Unknown PDF';
        }

        $key = (string) ($item['pdf_classification'] ?? '');
        if ($key === 'text') {
            $color = self::COLOR_SUCCESS;
        } elseif ($key === 'mixed') {
            $color = self::COLOR_WARNING;
        } elseif ($key === 'scanned' || $key === 'broken') {
            $color = self::COLOR_ERROR;
        }

        $title = $details !== '' ? ' title="' . esc_attr($details) . '"' : '';
        $html = $this->buildAnalysisLabelHtml($item, $label, $color, $title);
        $processingControlHtml = $this->buildPdfProcessingControlHtml($item, $isTransitioning);
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
        $operationStatus = (string) sanitize_key((string) ($item['operation_status'] ?? ''));
        $isUploaded = strpos($status, 'Uploaded') === 0;
        $isBrokenReference = !empty($item['broken_reference']);
        $canManage = !$isBrokenReference && ($isUploaded || !empty($item['file_path']));
        $includeTarget = !empty($item['include_in_store_target']);
        $isTransitioning = in_array($operationStatus, ['uploading', 'excluding'], true);
        $disableUpload = !$includeTarget || $isTransitioning;
        $disableExcludeToggle = $isTransitioning;
        $statusHtml = $this->getIndexedStatusHtml($item);
        $errorHtml = $this->getIndexedErrorHtml($item);

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
            $disableExcludeToggle ? ' disabled' : ''
        );
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
     * @param array<string,mixed> $item
     * @return string
     */
    private function getIndexedFilterValue(array $item): string {
        $operationStatus = (string) sanitize_key((string) ($item['operation_status'] ?? ''));
        $directOpMap = ['uploading' => 'uploading', 'excluding' => 'excluding', 'error' => 'error', 'excluded' => 'excluded', 'indexed' => 'indexed'];
        if (isset($directOpMap[$operationStatus])) {
            return $directOpMap[$operationStatus];
        }

        $isBrokenReference = !empty($item['broken_reference']);
        $isMissingFromSimpleFileList = $this->hasReferencedPosts($item) && empty($item['managed_by_simple_file_list']);
        if ($isBrokenReference || $isMissingFromSimpleFileList) {
            return 'error';
        }

        return $this->deriveIndexedFilterFromStatus($item);
    }

}
