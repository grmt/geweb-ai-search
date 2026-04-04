<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders saved AI conversations and their usage totals.
 */
class ConversationListTable extends \WP_List_Table {
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $sourceItems = [];
    private string $frontendAiPageUrl = '';

    /**
     * @param array<int,array<string,mixed>> $items
     * @param string $frontendAiPageUrl
     */
    public function __construct(array $items, string $frontendAiPageUrl = '') {
        $this->sourceItems = $items;
        $this->frontendAiPageUrl = $frontendAiPageUrl;

        parent::__construct([
            'singular' => 'Chat',
            'plural'   => 'Chats',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [
            'summary' => 'Summary',
            'model' => 'Model',
            'request_count' => 'Requests',
            'last_used_at' => 'Last Used',
            'total_tokens' => 'Tokens',
            'estimated_cost_usd' => 'Estimated Cost',
        ];
    }

    /**
     * @return array<string,array<int,string|bool>>
     */
    protected function get_sortable_columns(): array {
        return [
            'summary' => ['summary', false],
            'model' => ['model', false],
            'request_count' => ['request_count', false],
            'last_used_at' => ['last_used_at', true],
            'total_tokens' => ['total_tokens', false],
            'estimated_cost_usd' => ['estimated_cost_usd', false],
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

        $items = $this->filterItems($this->sourceItems);
        $items = $this->sortItems($items);
        $perPage = 20;
        $currentPage = $this->get_pagenum();
        $totalItems = count($items);

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
        ]);

        $this->items = array_slice($items, (($currentPage - 1) * $perPage), $perPage);
    }

    /**
     * Render a clearer empty state when no conversations have been stored yet.
     *
     * @return void
     */
    public function no_items(): void {
        esc_html_e('No saved chats yet. A chat is added here after a successful AI response.', 'geweb-ai-search');
    }

    /**
     * @param array<string,mixed> $item
     * @param string $column_name
     * @return string
     */
    protected function column_default($item, $column_name): string {
        return isset($item[$column_name]) ? esc_html((string) $item[$column_name]) : '—';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_summary($item): string {
        $summary = trim((string) ($item['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Untitled chat';
        }
        $conversationId = isset($item['id']) ? (string) $item['id'] : '';
        $editInputId = $conversationId !== ''
            ? 'geweb-edit-conversation-' . substr(sanitize_html_class($conversationId), 0, 24)
            : 'geweb-edit-conversation';

        $startedAt = isset($item['started_at']) ? (int) $item['started_at'] : 0;
        $startedLabel = $startedAt > 0
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $startedAt)
            : '—';

        if ($conversationId === '') {
            return '<strong>' . esc_html($summary) . '</strong><br><small style="color:#646970;">Started: ' . esc_html($startedLabel) . '</small>';
        }

        return sprintf(
            '<div class="geweb-conversation-summary-cell" data-conversation-id="%s" data-current-summary="%s">' .
                '<div>' .
                    '<strong class="geweb-conversation-summary-label">%s</strong>' .
                '</div>' .
                '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:6px;">' .
                    '<button type="button" class="button-link geweb-edit-conversation-trigger">Rename</button>' .
                    '<button type="button" class="button-link geweb-delete-conversation-trigger">Delete</button>' .
                    '%s' .
                '</div>' .
                '<div class="geweb-edit-conversation-form" style="display:none; margin-top:6px;">' .
                    '<input type="text" id="%s" name="%s" class="regular-text geweb-edit-conversation-input" value="%s" style="max-width:24rem;"> ' .
                    '<button type="button" class="button button-small geweb-save-conversation-name">Save</button> ' .
                    '<button type="button" class="button-link geweb-cancel-conversation-name">Cancel</button>' .
                    '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>' .
                '</div>' .
                '<small style="color:#646970;">Started: %s</small>' .
            '</div>',
            esc_attr($conversationId),
            esc_attr($summary),
            esc_html($summary),
            $this->getOpenConversationLinkHtml($conversationId),
            esc_attr($editInputId),
            esc_attr($editInputId),
            esc_attr($summary),
            esc_html($startedLabel)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function filterItems(array $items): array {
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $search = trim($search);

        if ($search === '') {
            return $items;
        }

        $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);

        return array_values(array_filter($items, static function (array $item) use ($needle): bool {
            $haystackParts = [
                isset($item['summary']) ? (string) $item['summary'] : '',
                isset($item['provider']) ? (string) $item['provider'] : '',
                isset($item['model']) ? (string) $item['model'] : '',
            ];

            $haystack = implode(' ', $haystackParts);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);

            return strpos($haystack, $needle) !== false;
        }));
    }

    /**
     * @param string $conversationId
     * @return string
     */
    private function getOpenConversationLinkHtml(string $conversationId): string {
        if ($conversationId === '' || $this->frontendAiPageUrl === '') {
            return '';
        }

        $url = add_query_arg('geweb_ai_conversation', rawurlencode($conversationId), $this->frontendAiPageUrl);

        return sprintf(
            '<a class="button-link" href="%s">Open</a>',
            esc_url($url)
        );
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_model($item): string {
        $provider = trim((string) ($item['provider'] ?? ''));
        $model = trim((string) ($item['model'] ?? ''));
        if ($provider === '' && $model === '') {
            return '—';
        }

        if ($provider === '') {
            return '<code>' . esc_html($model) . '</code>';
        }

        return esc_html($provider) . '<br><code>' . esc_html($model) . '</code>';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_request_count($item): string {
        return esc_html((string) ((int) ($item['request_count'] ?? 0)));
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_last_used_at($item): string {
        $timestamp = isset($item['last_used_at']) ? (int) $item['last_used_at'] : 0;
        if ($timestamp <= 0) {
            return '—';
        }

        return esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp));
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_total_tokens($item): string {
        $total = (int) ($item['total_tokens'] ?? 0);
        $input = (int) ($item['input_tokens'] ?? 0);
        $output = (int) ($item['output_tokens'] ?? 0);

        if ($total <= 0 && $input <= 0 && $output <= 0) {
            return '—';
        }

        return esc_html(number_format_i18n($total)) . '<br><small style="color:#646970;">In ' . esc_html(number_format_i18n($input)) . ' / Out ' . esc_html(number_format_i18n($output)) . '</small>';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    protected function column_estimated_cost_usd($item): string {
        $cost = isset($item['estimated_cost_usd']) ? (float) $item['estimated_cost_usd'] : 0.0;
        if ($cost <= 0) {
            return '—';
        }

        return '$' . esc_html(number_format_i18n($cost, 4));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortItems(array $items): array {
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'last_used_at';
        $order = isset($_GET['order']) ? strtolower(sanitize_text_field(wp_unslash($_GET['order']))) : 'desc';
        $allowedOrderBy = ['summary', 'model', 'request_count', 'last_used_at', 'total_tokens', 'estimated_cost_usd'];

        if (!in_array($orderby, $allowedOrderBy, true)) {
            $orderby = 'last_used_at';
        }

        $direction = $order === 'asc' ? 1 : -1;

        usort($items, static function (array $a, array $b) use ($orderby, $direction): int {
            $valueA = $a[$orderby] ?? '';
            $valueB = $b[$orderby] ?? '';

            if (in_array($orderby, ['request_count', 'last_used_at', 'total_tokens'], true)) {
                return (((int) $valueA) <=> ((int) $valueB)) * $direction;
            }

            if ($orderby === 'estimated_cost_usd') {
                return (((float) $valueA) <=> ((float) $valueB)) * $direction;
            }

            return strcasecmp((string) $valueA, (string) $valueB) * $direction;
        });

        return $items;
    }
}
