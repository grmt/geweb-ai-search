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
     * @param string $displayName
     * @param array<string,mixed>|null $referencedItem
     * @return array<int,string>
     */
    private function resolveDocumentUrls(string $displayName, ?array $referencedItem = null): array {
        $urls = [];

        if (is_array($referencedItem) && !empty($referencedItem['posts']) && is_array($referencedItem['posts'])) {
            foreach ($referencedItem['posts'] as $post) {
                if (!is_array($post)) {
                    continue;
                }

                $postId = isset($post['id']) ? (int) $post['id'] : 0;
                if ($postId <= 0) {
                    continue;
                }

                $url = get_permalink($postId);
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        if (!empty($urls)) {
            return array_values(array_unique($urls));
        }

        $slug = trim($displayName);
        if ($slug === '') {
            return [];
        }

        if (preg_match('/^(\d+)\.md$/', $slug, $matches) === 1 || preg_match('/^(\d+)-.+$/', $slug, $matches) === 1) {
            $postId = (int) ($matches[1] ?? 0);
            if ($postId > 0) {
                $url = get_permalink($postId);
                if (is_string($url) && $url !== '') {
                    return [$url];
                }
            }
        }

        return [];
    }

    /**
     * @param string $url
     * @return string
     */
    private function formatDocumentUrlLabel(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        $siteUrl = site_url('/');
        $siteParts = wp_parse_url($siteUrl);
        $urlParts = wp_parse_url($url);

        if (is_array($siteParts) && is_array($urlParts)) {
            $siteHost = isset($siteParts['host']) ? strtolower((string) $siteParts['host']) : '';
            $urlHost = isset($urlParts['host']) ? strtolower((string) $urlParts['host']) : '';
            if ($siteHost !== '' && $siteHost === $urlHost) {
                $path = isset($urlParts['path']) ? (string) $urlParts['path'] : '/';
                $path = $path === '' ? '/' : $path;
                $query = isset($urlParts['query']) && $urlParts['query'] !== '' ? '?' . $urlParts['query'] : '';
                $fragment = isset($urlParts['fragment']) && $urlParts['fragment'] !== '' ? '#' . $urlParts['fragment'] : '';
                return $path . $query . $fragment;
            }
        }

        return preg_replace('#^https?://#', '', $url);
    }

    /**
     * @param string $mimeType
     * @param string $displayName
     * @return string
     */
    private function resolveTypeLabel(string $mimeType, string $displayName): string {
        $mimeType = strtolower(trim($mimeType));
        if ($mimeType !== '') {
            if ($mimeType === 'text/markdown') {
                return 'Page (Markdown)';
            }

            if (strpos($mimeType, 'image/') === 0) {
                return 'Image';
            }

            return 'Document';
        }

        if (strtolower(substr($displayName, -3)) === '.md') {
            return 'Page (Markdown)';
        }

        return 'Document';
    }

    /**
     * Render a document list for a selected Gemini store.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return string
     */
    public static function renderDocumentList(array $documents): string {
        $table = new self();

        return $table->buildDocumentListHtml($documents);
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return string
     */
    private function buildDocumentListHtml(array $documents): string {
        if (empty($this->referencedDocumentByGeminiName)) {
            $this->referencedDocumentByGeminiName = $this->buildReferencedDocumentMap();
        }

        $browserId = 'geweb-gemini-store-browser-' . substr(md5(wp_json_encode($documents)), 0, 8);

        if (empty($documents)) {
            return '<p style="margin:0;">No uploaded items found for this store.</p>';
        }

        $referencedTable = new ReferencedDocumentListTable();
        $rows = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $displayName = isset($document['display_name']) ? trim((string) $document['display_name']) : '';
            $name = isset($document['name']) ? trim((string) $document['name']) : '';
            $mimeType = isset($document['mime_type']) ? trim((string) $document['mime_type']) : '';
            $label = $displayName !== '' ? $displayName : ($name !== '' ? $name : 'Unnamed');
            $referencedItem = $name !== '' && isset($this->referencedDocumentByGeminiName[$name])
                ? $this->referencedDocumentByGeminiName[$name]
                : null;
            $urls = $this->resolveDocumentUrls($displayName, is_array($referencedItem) ? $referencedItem : null);
            $slugs = $this->resolveDocumentSlugs(is_array($referencedItem) ? $referencedItem : null, $displayName);
            $typeLabel = $this->resolveTypeLabel($mimeType, $displayName);
            $formatValue = $this->resolveFormatFilterValue($mimeType, $displayName);
            $sizeBytes = isset($document['size_bytes']) ? (int) $document['size_bytes'] : 0;
            $sizeLabel = $sizeBytes > 0 ? GeminiStorageEstimator::formatBytes($sizeBytes) : '—';
            $urlSortValue = implode(', ', array_map([$this, 'formatDocumentUrlLabel'], $urls));
            $slugValue = implode(', ', $slugs);
            $actionsHtml = is_array($referencedItem) ? $referencedTable->renderActionsCell($referencedItem) : '—';
            $urlHtml = '—';
            if (!empty($urls)) {
                $urlLinks = [];
                foreach ($urls as $url) {
                    $urlLinks[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($this->formatDocumentUrlLabel($url)) . '</a>';
                }
                $urlHtml = implode(', ', $urlLinks);
            } else {
                $rootUrl = site_url('/');
                $urlSortValue = '/';
                $urlHtml = '<a href="' . esc_url($rootUrl) . '" target="_blank" rel="noopener noreferrer">/</a>';
            }

            $rows[] = sprintf(
                '<tr data-name="%s" data-id="%s" data-slug="%s" data-type="%s" data-format="%s" data-size="%s" data-url="%s" data-doc-name="%s">' .
                '<td>%s</td>' .
                '<td>%s</td>' .
                '<td>%s</td>' .
                '<td>%s</td>' .
                '<td>%s</td>' .
                '<td style="word-break:break-all;">%s</td>' .
                '<td class="column-actions">%s</td>' .
                '</tr>',
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label)),
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($this->resolveDocumentIds(is_array($referencedItem) ? $referencedItem : null, $displayName), 'UTF-8') : strtolower($this->resolveDocumentIds(is_array($referencedItem) ? $referencedItem : null, $displayName))),
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($slugValue, 'UTF-8') : strtolower($slugValue)),
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($typeLabel, 'UTF-8') : strtolower($typeLabel)),
                esc_attr($formatValue),
                esc_attr((string) $sizeBytes),
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($urlSortValue, 'UTF-8') : strtolower($urlSortValue)),
                esc_attr(function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name)),
                esc_html($label),
                esc_html($this->resolveDocumentIds(is_array($referencedItem) ? $referencedItem : null, $displayName)),
                esc_html($slugValue !== '' ? $slugValue : '—'),
                esc_html($typeLabel !== '' ? $typeLabel : 'Document'),
                esc_html($sizeLabel),
                $urlHtml,
                $actionsHtml
            );
        }

        if (empty($rows)) {
            return '<p style="margin:0;">No uploaded items found for this store.</p>';
        }

        return '' .
            '<div class="geweb-gemini-store-documents-browser">' .
                '<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:end; margin-bottom:12px;">' .
                    '<label for="' . esc_attr($browserId . '-filter') . '" style="display:flex; flex-direction:column; gap:4px;">' .
                        '<span>Filter</span>' .
                        '<input type="search" id="' . esc_attr($browserId . '-filter') . '" name="' . esc_attr($browserId . '-filter') . '" class="regular-text geweb-gemini-store-documents-filter" placeholder="Filter uploaded items">' .
                    '</label>' .
                    '<label for="' . esc_attr($browserId . '-type-filter') . '" style="display:flex; flex-direction:column; gap:4px;">' .
                        '<span>Type</span>' .
                        '<select id="' . esc_attr($browserId . '-type-filter') . '" name="' . esc_attr($browserId . '-type-filter') . '" class="geweb-gemini-store-documents-type-filter">' .
                            '<option value="">All types</option>' .
                            '<option value="page (markdown)">Page (Markdown)</option>' .
                            '<option value="image">Image</option>' .
                            '<option value="document">Document</option>' .
                        '</select>' .
                    '</label>' .
                    '<label for="' . esc_attr($browserId . '-format-filter') . '" style="display:flex; flex-direction:column; gap:4px;">' .
                        '<span>Format</span>' .
                        '<select id="' . esc_attr($browserId . '-format-filter') . '" name="' . esc_attr($browserId . '-format-filter') . '" class="geweb-gemini-store-documents-format-filter">' .
                            '<option value="">All formats</option>' .
                            '<option value="excel">Excel (.xls/.xlsx)</option>' .
                            '<option value="pdf">PDF</option>' .
                            '<option value="word">Word</option>' .
                            '<option value="markdown">Markdown</option>' .
                            '<option value="image">Image</option>' .
                            '<option value="other">Other</option>' .
                        '</select>' .
                    '</label>' .
                    '<label for="' . esc_attr($browserId . '-id-filter') . '" style="display:flex; flex-direction:column; gap:4px;">' .
                        '<span>Page ID</span>' .
                        '<input type="search" id="' . esc_attr($browserId . '-id-filter') . '" name="' . esc_attr($browserId . '-id-filter') . '" class="small-text geweb-gemini-store-documents-id-filter" placeholder="e.g. 62">' .
                    '</label>' .
                    '<label for="' . esc_attr($browserId . '-slug-filter') . '" style="display:flex; flex-direction:column; gap:4px;">' .
                        '<span>Slug</span>' .
                        '<input type="search" id="' . esc_attr($browserId . '-slug-filter') . '" name="' . esc_attr($browserId . '-slug-filter') . '" class="regular-text geweb-gemini-store-documents-slug-filter" placeholder="Filter by slug">' .
                    '</label>' .
                '</div>' .
                '<div class="geweb-gemini-store-documents-filter-status description" style="margin-bottom:8px; color:#646970;"></div>' .
                '<table class="widefat striped geweb-gemini-store-documents-table">' .
                    '<thead><tr>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="name" data-sort-label="Document Name">Document Name</button></th>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="id" data-sort-label="ID">ID</button></th>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="slug" data-sort-label="Slug">Slug</button></th>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="type" data-sort-label="Type">Type</button></th>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="size" data-sort-label="Size">Size</button></th>' .
                        '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="url" data-sort-label="Referenced Page URL">Referenced Page URL</button></th>' .
                        '<th>Actions</th>' .
                    '</tr></thead>' .
                    '<tbody>' . implode('', $rows) . '</tbody>' .
                '</table>' .
            '</div>';
    }

    private function resolveFormatFilterValue(string $mimeType, string $displayName): string {
        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));

        if ($mimeType === 'text/markdown' || $extension === 'md') {
            return 'markdown';
        }

        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }

        if (
            strpos($mimeType, 'msword') !== false ||
            strpos($mimeType, 'wordprocessingml') !== false ||
            in_array($extension, ['doc', 'docx'], true)
        ) {
            return 'word';
        }

        if (
            strpos($mimeType, 'ms-excel') !== false ||
            strpos($mimeType, 'spreadsheetml') !== false ||
            in_array($extension, ['xls', 'xlsx'], true)
        ) {
            return 'excel';
        }

        if (strpos($mimeType, 'image/') === 0 || in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
            return 'image';
        }

        return 'other';
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @param string $displayName
     * @return string
     */
    private function resolveDocumentIds(?array $referencedItem, string $displayName): string {
        $ids = [];

        if (is_array($referencedItem) && !empty($referencedItem['posts']) && is_array($referencedItem['posts'])) {
            foreach ($referencedItem['posts'] as $post) {
                if (!is_array($post)) {
                    continue;
                }

                $postId = isset($post['id']) ? (int) $post['id'] : 0;
                if ($postId > 0) {
                    $ids[] = (string) $postId;
                }
            }
        }

        if (empty($ids)) {
            $slug = trim($displayName);
            if (preg_match('/^(\d+)\.md$/', $slug, $matches) === 1 || preg_match('/^(\d+)-.+$/', $slug, $matches) === 1) {
                $postId = (int) ($matches[1] ?? 0);
                if ($postId > 0) {
                    $ids[] = (string) $postId;
                }
            }
        }

        return empty($ids) ? '—' : implode(', ', array_values(array_unique($ids)));
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @param string $displayName
     * @return array<int,string>
     */
    private function resolveDocumentSlugs(?array $referencedItem, string $displayName): array {
        $slugs = [];

        if (is_array($referencedItem) && !empty($referencedItem['posts']) && is_array($referencedItem['posts'])) {
            foreach ($referencedItem['posts'] as $post) {
                if (!is_array($post)) {
                    continue;
                }

                $postId = isset($post['id']) ? (int) $post['id'] : 0;
                if ($postId <= 0) {
                    continue;
                }

                $postObject = get_post($postId);
                $slug = $postObject instanceof \WP_Post ? trim((string) $postObject->post_name) : '';
                if ($slug !== '') {
                    $slugs[] = $slug;
                }
            }
        }

        if (!empty($slugs)) {
            return array_values(array_unique($slugs));
        }

        $slug = trim($displayName);
        if (preg_match('/^(\d+)\.md$/', $slug, $matches) === 1 || preg_match('/^(\d+)-.+$/', $slug, $matches) === 1) {
            $postId = (int) ($matches[1] ?? 0);
            if ($postId > 0) {
                $postObject = get_post($postId);
                $resolvedSlug = $postObject instanceof \WP_Post ? trim((string) $postObject->post_name) : '';
                if ($resolvedSlug !== '') {
                    return [$resolvedSlug];
                }
            }
        }

        return [];
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
