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
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [
            'display_name'  => 'Document Name',
            'nice_name'     => 'Nice Name',
            'status'        => 'AI Indexed',
            'mime_type'     => 'Type',
            'last_uploaded' => 'Upload Date',
            'referenced_in' => 'Referenced In',
            'actions'       => 'Actions',
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    protected function get_sortable_columns(): array {
        return [
            'display_name' => ['display_name', true],
            'nice_name' => ['nice_name', false],
            'status' => ['status', false],
            'mime_type' => ['mime_type', false],
            'last_uploaded' => ['last_uploaded', false],
            'referenced_in' => ['reference_count', false],
        ];
    }

    /**
     * Prepare table items.
     */
    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $documentStore = new DocumentStore();
        $allItems = $documentStore->getReferencedDocumentOverview();
        $allItems = $this->filterItems($allItems);
        $allItems = $this->sortItems($allItems);

        $perPage = 20;
        $currentPage = $this->get_pagenum();
        $totalItems = count($allItems);

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
        if ($url === '') {
            return esc_html($label);
        }

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
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
            '<input type="text" class="regular-text geweb-edit-nice-name-input" value="%s" placeholder="%s" style="max-width:24rem;"> ' .
            '<button type="button" class="button button-small geweb-save-nice-name">Save</button> ' .
            '<button type="button" class="button-link geweb-cancel-nice-name">Cancel</button>' .
            '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>' .
            '</div>' .
            '</div>',
            esc_attr($fileHash),
            esc_attr($niceName),
            esc_attr($placeholder),
            esc_html($niceName !== '' ? $niceName : 'Add nice name'),
            esc_attr($niceName),
            esc_attr($placeholder)
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_status($item): string {
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;

        if (!$includeTarget) {
            $label = 'Excluded';
            $color = '#996800';
        } elseif ($isUploaded) {
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
     * @return string
     */
    protected function column_last_uploaded($item): string {
        $timestamp = isset($item['last_uploaded']) ? (int) $item['last_uploaded'] : 0;
        if ($timestamp <= 0) {
            return '—';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
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

        $status = isset($item['status']) ? (string) $item['status'] : '';
        $isUploaded = strpos($status, 'Uploaded') === 0;
        $canManage = $isUploaded || !empty($item['file_path']);
        $includeTarget = !empty($item['include_in_store_target']);

        if (!$canManage) {
            return '<div class="geweb-ai-index-cell geweb-referenced-document-cell" data-file-hash="' . esc_attr($fileHash) . '"><p style="margin:0;">—</p><p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p></div>';
        }

        return sprintf(
            '<div class="geweb-ai-index-cell geweb-referenced-document-cell" data-file-hash="%s" data-current-uploaded="%s" data-current-target="%s">' .
            '<button type="button" class="button button-small geweb-referenced-document-upload-now" data-file-hash="%s"%s>Upload</button> ' .
            '<label style="margin-left:8px;"><input type="checkbox" class="geweb-referenced-document-toggle-exclude" data-file-hash="%s" %s> Exclude</label>' .
            '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>' .
            '</div>',
            esc_attr($fileHash),
            $isUploaded ? '1' : '0',
            $includeTarget ? '1' : '0',
            esc_attr($fileHash),
            $includeTarget ? '' : ' disabled',
            esc_attr($fileHash),
            checked(!$includeTarget, true, false)
        );
    }

    /**
     * Render status cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderStatusCell(array $item): string {
        return $this->column_status($item);
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
     * Render nice-name cell HTML for a single overview item.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    public function renderNiceNameCell(array $item): string {
        return $this->column_nice_name($item);
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
        <div class="alignleft actions">
            <label class="screen-reader-text" for="geweb_ai_referenced_doc_status">Filter documents by status</label>
            <select name="geweb_ai_referenced_doc_status" id="geweb_ai_referenced_doc_status">
                <option value="">All AI Indexed states</option>
                <option value="indexed" <?php selected($selectedStatus, 'indexed'); ?>>Indexed</option>
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
                <option value="spreadsheet" <?php selected($selectedType, 'spreadsheet'); ?>>Spreadsheet</option>
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
            <?php submit_button('Apply filters', '', 'filter_action', false); ?>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'geweb-ai-search', 'geweb_tab' => 'documents'], admin_url('admin.php'))); ?>" class="button" style="margin-left:4px;">Reset</a>
        </div>
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

        if ($selectedStatus === '' && $selectedType === '' && $selectedReferencedIn === '') {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($selectedStatus, $selectedType, $selectedReferencedIn): bool {
            if ($selectedStatus !== '' && $this->getIndexedFilterValue($item) !== $selectedStatus) {
                return false;
            }

            if ($selectedType !== '' && $this->getTypeFilterValue((string) ($item['mime_type'] ?? '')) !== $selectedType) {
                return false;
            }

            if ($selectedReferencedIn !== '' && $this->getScopeFilterValue($item) !== $selectedReferencedIn) {
                return false;
            }

            return true;
        }));
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
            strpos($mimeType, 'spreadsheetml') !== false ||
            strpos($mimeType, 'csv') !== false
        ) {
            return 'spreadsheet';
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
        $includeTarget = !empty($item['include_in_store_target']);
        $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;

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
        $allowedOrderBy = ['display_name', 'nice_name', 'status', 'mime_type', 'last_uploaded', 'reference_count'];
        if (!in_array($orderby, $allowedOrderBy, true)) {
            $orderby = 'display_name';
        }

        usort($items, static function (array $left, array $right) use ($orderby, $order): int {
            $leftValue = $left[$orderby] ?? '';
            $rightValue = $right[$orderby] ?? '';

            if (is_numeric($leftValue) || is_numeric($rightValue)) {
                $comparison = (int) $leftValue <=> (int) $rightValue;
            } else {
                $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $order === 'desc' ? -$comparison : $comparison;
        });

        return $items;
    }
}
