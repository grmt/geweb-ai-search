<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders an overview of Gemini File Search Stores.
 */
class GeminiStoreListTable extends \WP_List_Table {
    /**
     * @var array<string,array<string,mixed>>
     */
    private $referencedDocumentByGeminiName = [];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Gemini Store',
            'plural'   => 'Gemini Stores',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [
            'display_name' => 'Display Name',
            'name' => 'Store',
            'status' => 'Status',
            'document_count' => 'Documents',
            'actions' => 'Actions',
        ];
    }

    /**
     * @return array<string,array<int|string>>
     */
    protected function get_sortable_columns(): array {
        return [
            'display_name' => ['display_name', true],
            'name' => ['name', false],
            'status' => ['status', false],
            'document_count' => ['document_count', false],
        ];
    }

    /**
     * Prepare table items.
     *
     * @return void
     */
    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            $this->items = [];
            return;
        }

        $this->referencedDocumentByGeminiName = $this->buildReferencedDocumentMap();
        $items = $this->sortItems($provider->getStoreOverview());

        $this->items = $items;
    }

    /**
     * @param array<string,mixed> $item
     * @param string $columnName
     * @return string
     */
    protected function column_default($item, $columnName): string {
        return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '—';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_display_name($item): string {
        $displayName = isset($item['display_name']) ? (string) $item['display_name'] : '';
        $label = $displayName === '' ? '—' : $displayName;
        $storeName = isset($item['name']) ? (string) $item['name'] : '';
        $isActive = !empty($item['is_active']);

        if ($storeName === '') {
            return esc_html($label);
        }

        return sprintf(
            '<button type="button" class="button-link geweb-select-gemini-store" data-store-name="%s" data-store-label="%s" style="font-weight:%s;">%s</button>%s',
            esc_attr($storeName),
            esc_attr($label),
            $isActive ? '600' : '400',
            esc_html($label),
            $isActive ? '<br><small style="color:#646970;">Current plugin store</small>' : ''
        );
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
    protected function column_actions($item): string {
        $storeName = isset($item['name']) ? (string) $item['name'] : '';
        if ($storeName === '') {
            return '—';
        }

        $isActive = !empty($item['is_active']);
        $confirmMessage = $isActive
            ? 'Delete this active Gemini store? The plugin will no longer have a configured store until a new one is created.'
            : 'Delete this Gemini store?';

        return sprintf(
            '<button type="button" class="button-link-delete geweb-delete-gemini-store" data-store-name="%s" data-is-active="%s" data-confirm-message="%s">Delete</button>',
            esc_attr($storeName),
            $isActive ? '1' : '0',
            esc_attr($confirmMessage)
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildReferencedDocumentMap(): array {
        $map = [];
        $documentStore = new DocumentStore();
        $overviewItems = $documentStore->getReferencedDocumentOverview();

        foreach ($overviewItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $geminiName = isset($item['gemini_doc_name']) ? trim((string) $item['gemini_doc_name']) : '';
            if ($geminiName === '') {
                continue;
            }

            $map[$geminiName] = $item;
        }

        return $map;
    }

    /**
     * Render a document list for a selected Gemini store.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return string
     */
    public static function renderDocumentList(array $documents): string {
        $renderer = new GeminiStoreDocumentBrowserRenderer();
        return $renderer->render($documents);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortItems(array $items): array {
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'display_name';
        $order = isset($_GET['order']) ? strtolower(sanitize_key(wp_unslash($_GET['order']))) : 'asc';
        $allowedOrderBy = ['display_name', 'name', 'status', 'document_count'];
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
