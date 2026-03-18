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
            'status'        => 'Status',
            'mime_type'     => 'Type',
            'last_uploaded' => 'Upload Date',
            'referenced_in' => 'Referenced In',
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    protected function get_sortable_columns(): array {
        return [
            'display_name' => ['display_name', true],
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
        $sortable = [];
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
    protected function column_status($item): string {
        $label = isset($item['status']) ? (string) $item['status'] : 'Unknown';
        $color = isset($item['status_color']) ? (string) $item['status_color'] : '#646970';

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
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="geweb_ai_referenced_doc_status">Filter documents by status</label>
            <select name="geweb_ai_referenced_doc_status" id="geweb_ai_referenced_doc_status">
                <option value="">All statuses</option>
                <option value="Uploaded" <?php selected($selectedStatus, 'Uploaded'); ?>>Uploaded</option>
                <option value="Detected, not uploaded" <?php selected($selectedStatus, 'Detected, not uploaded'); ?>>Detected, not uploaded</option>
                <option value="Uploads disabled" <?php selected($selectedStatus, 'Uploads disabled'); ?>>Uploads disabled</option>
                <option value="Uploaded, only in Simple File List" <?php selected($selectedStatus, 'Uploaded, only in Simple File List'); ?>>Uploaded, only in Simple File List</option>
                <option value="Only in Simple File List" <?php selected($selectedStatus, 'Only in Simple File List'); ?>>Only in Simple File List</option>
                <option value="Uploaded, referenced elsewhere" <?php selected($selectedStatus, 'Uploaded, referenced elsewhere'); ?>>Uploaded, referenced elsewhere</option>
                <option value="Referenced outside Simple File List" <?php selected($selectedStatus, 'Referenced outside Simple File List'); ?>>Referenced outside Simple File List</option>
                <option value="Uploaded, not referenced" <?php selected($selectedStatus, 'Uploaded, not referenced'); ?>>Uploaded, not referenced</option>
            </select>
            <?php submit_button('Filter', '', 'filter_action', false); ?>
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
        if ($selectedStatus === '') {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($selectedStatus): bool {
            return isset($item['status']) && (string) $item['status'] === $selectedStatus;
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortItems(array $items): array {
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'display_name';
        $order = isset($_GET['order']) ? strtolower(sanitize_key(wp_unslash($_GET['order']))) : 'asc';
        $allowedOrderBy = ['display_name', 'status', 'mime_type', 'last_uploaded', 'reference_count'];
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
