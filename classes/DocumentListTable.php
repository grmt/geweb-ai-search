<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class DocumentListTable
 *
 * Renders the list of indexed documents.
 */
class DocumentListTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Document',
            'plural'   => 'Documents',
            'ajax'     => false
        ]);
    }

    /**
     * Get a list of columns.
     *
     * @return array
     */
    public function get_columns(): array {
        return [
            'display_name'   => 'Document Name',
            'last_uploaded'  => 'Upload Date',
            'referenced_in'  => 'Referenced In',
        ];
    }

    /**
     * Prepare the items for the table to process.
     */
    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];

        $documentStore = new DocumentStore();
        $all_items = $documentStore->getAllDocuments();

        $perPage = 20;
        $currentPage = $this->get_pagenum();
        $totalItems = count($all_items);

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page'    => $perPage
        ]);

        $this->items = array_slice($all_items, (($currentPage - 1) * $perPage), $perPage);
    }

    /**
     * Default column rendering.
     *
     * @param array $item
     * @param string $column_name
     * @return string
     */
    protected function column_default($item, $column_name): string {
        return $item[$column_name] ?? '—';
    }

    /**
     * Render the "Upload Date" column.
     *
     * @param array $item
     * @return string
     */
    protected function column_last_uploaded($item): string {
        $timestamp = (int) ($item['last_uploaded'] ?? 0);
        if (!$timestamp) {
            return '—';
        }
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Render the "Referenced In" column.
     *
     * @param array $item
     * @return string
     */
    protected function column_referenced_in($item): string {
        $documentStore = new DocumentStore();
        $posts = $documentStore->getPostsForDocument((int) $item['id']);

        if (empty($posts)) {
            return 'Not referenced';
        }

        $links = [];
        foreach ($posts as $post) {
            $links[] = '<a href="' . get_edit_post_link($post->ID) . '">' . esc_html($post->post_title) . '</a>';
        }

        return implode('<br>', $links);
    }
}
