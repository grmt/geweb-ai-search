<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class GeminiStoreDocumentBrowserRenderer {
    private const REGEX_MARKDOWN_POST_ID = '/^(\d+)\.md$/';
    private const REGEX_PREFIXED_POST_ID = '/^(\d+)-.+$/';
    private const HTML_NAME_ATTR_PREFIX = '" name="';

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $referencedDocumentByGeminiName = [];

    /**
     * @param array<int,array<string,mixed>> $documents
     */
    public function render(array $documents): string {
        if (empty($documents)) {
            return $this->renderEmptyMessage();
        }

        $this->referencedDocumentByGeminiName = $this->buildReferencedDocumentMap();
        $rows = $this->buildRows($documents);
        if (empty($rows)) {
            return $this->renderEmptyMessage();
        }

        return $this->renderBrowser($documents, $rows);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildReferencedDocumentMap(): array {
        $map = [];
        $documentStore = new DocumentStore();
        foreach ($documentStore->getReferencedDocumentOverview() as $item) {
            if (!is_array($item)) {
                continue;
            }

            $geminiName = isset($item['gemini_doc_name']) ? trim((string) $item['gemini_doc_name']) : '';
            if ($geminiName !== '') {
                $map[$geminiName] = $item;
            }
        }

        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,string>
     */
    private function buildRows(array $documents): array {
        $rows = [];
        $referencedTable = new ReferencedDocumentListTable();
        foreach ($documents as $document) {
            if (is_array($document)) {
                $rows[] = $this->buildRow($document, $referencedTable);
            }
        }

        return array_values(array_filter($rows));
    }

    /**
     * @param array<string,mixed> $document
     */
    private function buildRow(array $document, ReferencedDocumentListTable $referencedTable): string {
        $context = $this->buildDocumentContext($document, $referencedTable);

        return sprintf(
            '<tr data-name="%s" data-id="%s" data-slug="%s" data-type="%s" data-format="%s" data-size="%s" data-url="%s" data-doc-name="%s">' .
            '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="word-break:break-all;">%s</td><td class="column-actions">%s</td></tr>',
            esc_attr($context['normalized_label']),
            esc_attr($context['normalized_ids']),
            esc_attr($context['normalized_slug']),
            esc_attr($context['normalized_type']),
            esc_attr($context['format']),
            esc_attr($context['size_bytes']),
            esc_attr($context['normalized_url']),
            esc_attr($context['normalized_name']),
            esc_html($context['label']),
            esc_html($context['document_ids']),
            esc_html($context['slug_value'] !== '' ? $context['slug_value'] : '—'),
            esc_html($context['type_label']),
            esc_html($context['size_label']),
            $context['url_html'],
            $context['actions_html']
        );
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,string>
     */
    private function buildDocumentContext(array $document, ReferencedDocumentListTable $referencedTable): array {
        $displayName = isset($document['display_name']) ? trim((string) $document['display_name']) : '';
        $name = isset($document['name']) ? trim((string) $document['name']) : '';
        $mimeType = isset($document['mime_type']) ? trim((string) $document['mime_type']) : '';
        $referencedItem = $this->getReferencedItem($name);
        $urls = $this->resolveDocumentUrls($displayName, $referencedItem);
        $urlDisplay = $this->buildUrlDisplay($urls);
        $slugValue = implode(', ', $this->resolveDocumentSlugs($referencedItem, $displayName));
        $typeLabel = $this->resolveTypeLabel($mimeType, $displayName);
        $documentIds = $this->resolveDocumentIds($referencedItem, $displayName);
        $label = $this->resolveDocumentLabel($displayName, $name);
        $sizeBytes = isset($document['size_bytes']) ? (int) $document['size_bytes'] : 0;

        return [
            'label' => $label,
            'document_ids' => $documentIds,
            'slug_value' => $slugValue,
            'type_label' => $typeLabel,
            'format' => $this->resolveFormatFilterValue($mimeType, $displayName),
            'size_bytes' => (string) $sizeBytes,
            'size_label' => $sizeBytes > 0 ? GeminiStorageEstimator::formatBytes($sizeBytes) : '—',
            'url_html' => $urlDisplay['html'],
            'actions_html' => is_array($referencedItem) ? $referencedTable->renderActionsCell($referencedItem) : '—',
            'normalized_label' => $this->normalizeSortValue($label),
            'normalized_ids' => $this->normalizeSortValue($documentIds),
            'normalized_slug' => $this->normalizeSortValue($slugValue),
            'normalized_type' => $this->normalizeSortValue($typeLabel),
            'normalized_url' => $this->normalizeSortValue($urlDisplay['sort_value']),
            'normalized_name' => $this->normalizeSortValue($name),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getReferencedItem(string $name): ?array {
        $item = $name !== '' && isset($this->referencedDocumentByGeminiName[$name])
            ? $this->referencedDocumentByGeminiName[$name]
            : null;

        return is_array($item) ? $item : null;
    }

    private function resolveDocumentLabel(string $displayName, string $name): string {
        $label = $displayName;
        if ($label === '') {
            $label = $name !== '' ? $name : 'Unnamed';
        }

        return $label;
    }

    /**
     * @param array<int,string> $urls
     * @return array{html:string,sort_value:string}
     */
    private function buildUrlDisplay(array $urls): array {
        $display = [
            'html' => '<a href="' . esc_url(site_url('/')) . '" target="_blank" rel="noopener noreferrer">/</a>',
            'sort_value' => '/',
        ];
        if (!empty($urls)) {
            $urlLinks = [];
            foreach ($urls as $url) {
                $urlLinks[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($this->formatDocumentUrlLabel($url)) . '</a>';
            }
            $display = [
                'html' => implode(', ', $urlLinks),
                'sort_value' => implode(', ', array_map([$this, 'formatDocumentUrlLabel'], $urls)),
            ];
        }

        return $display;
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @return array<int,string>
     */
    private function resolveDocumentUrls(string $displayName, ?array $referencedItem = null): array {
        $urls = $this->resolvePostValues($referencedItem, function (int $postId): string {
            $url = get_permalink($postId);
            return is_string($url) ? $url : '';
        });
        if (empty($urls)) {
            $postId = $this->extractPostIdFromDisplayName($displayName);
            $url = $postId > 0 ? get_permalink($postId) : '';
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function formatDocumentUrlLabel(string $url): string {
        $url = trim($url);
        $label = $url === '' ? '/' : preg_replace('#^https?://#', '', $url);
        $siteParts = $url === '' ? null : wp_parse_url(site_url('/'));
        $urlParts = $url === '' ? null : wp_parse_url($url);

        if (is_array($siteParts) && is_array($urlParts) && $this->isSameHost($siteParts, $urlParts)) {
            $label = $this->buildRelativeUrlLabel($urlParts);
        }

        return $label;
    }

    /**
     * @param array<string,mixed> $siteParts
     * @param array<string,mixed> $urlParts
     */
    private function isSameHost(array $siteParts, array $urlParts): bool {
        $siteHost = isset($siteParts['host']) ? strtolower((string) $siteParts['host']) : '';
        $urlHost = isset($urlParts['host']) ? strtolower((string) $urlParts['host']) : '';

        return $siteHost !== '' && $siteHost === $urlHost;
    }

    /**
     * @param array<string,mixed> $urlParts
     */
    private function buildRelativeUrlLabel(array $urlParts): string {
        $path = isset($urlParts['path']) && (string) $urlParts['path'] !== '' ? (string) $urlParts['path'] : '/';
        $query = isset($urlParts['query']) && $urlParts['query'] !== '' ? '?' . $urlParts['query'] : '';
        $fragment = isset($urlParts['fragment']) && $urlParts['fragment'] !== '' ? '#' . $urlParts['fragment'] : '';

        return $path . $query . $fragment;
    }

    private function resolveTypeLabel(string $mimeType, string $displayName): string {
        $mimeType = strtolower(trim($mimeType));
        $label = 'Document';
        if ($mimeType === 'text/markdown' || ($mimeType === '' && strtolower(substr($displayName, -3)) === '.md')) {
            $label = 'Page (Markdown)';
        } elseif (strpos($mimeType, 'image/') === 0) {
            $label = 'Image';
        }

        return $label;
    }

    private function resolveFormatFilterValue(string $mimeType, string $displayName): string {
        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));
        $format = 'other';
        foreach ($this->getFormatRules() as $candidate => $rule) {
            if ($this->matchesFormatRule($mimeType, $extension, $rule)) {
                $format = $candidate;
                break;
            }
        }

        return $format;
    }

    /**
     * @return array<string,array{mime:array<int,string>,extensions:array<int,string>}>
     */
    private function getFormatRules(): array {
        return [
            'markdown' => ['mime' => ['text/markdown'], 'extensions' => ['md']],
            'pdf' => ['mime' => ['application/pdf'], 'extensions' => ['pdf']],
            'word' => ['mime' => ['msword', 'wordprocessingml'], 'extensions' => ['doc', 'docx']],
            'excel' => ['mime' => ['ms-excel', 'spreadsheetml'], 'extensions' => ['xls', 'xlsx']],
            'image' => ['mime' => ['image/'], 'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg']],
        ];
    }

    /**
     * @param array{mime:array<int,string>,extensions:array<int,string>} $rule
     */
    private function matchesFormatRule(string $mimeType, string $extension, array $rule): bool {
        return in_array($extension, $rule['extensions'], true) || $this->mimeMatchesAny($mimeType, $rule['mime']);
    }

    /**
     * @param array<int,string> $fragments
     */
    private function mimeMatchesAny(string $mimeType, array $fragments): bool {
        $matches = false;
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && strpos($mimeType, $fragment) !== false) {
                $matches = true;
                break;
            }
        }

        return $matches;
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     */
    private function resolveDocumentIds(?array $referencedItem, string $displayName): string {
        $ids = $this->resolvePostValues($referencedItem, static function (int $postId): string {
            return (string) $postId;
        });
        if (empty($ids)) {
            $postId = $this->extractPostIdFromDisplayName($displayName);
            if ($postId > 0) {
                $ids[] = (string) $postId;
            }
        }

        return empty($ids) ? '—' : implode(', ', array_values(array_unique($ids)));
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @return array<int,string>
     */
    private function resolveDocumentSlugs(?array $referencedItem, string $displayName): array {
        $slugs = $this->resolvePostValues($referencedItem, function (int $postId): string {
            $postObject = get_post($postId);
            return $postObject instanceof \WP_Post ? trim((string) $postObject->post_name) : '';
        });
        if (empty($slugs)) {
            $postId = $this->extractPostIdFromDisplayName($displayName);
            $slug = $postId > 0 ? $this->resolvePostSlug($postId) : '';
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    private function resolvePostSlug(int $postId): string {
        $postObject = get_post($postId);
        return $postObject instanceof \WP_Post ? trim((string) $postObject->post_name) : '';
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @param callable(int): string $resolver
     * @return array<int,string>
     */
    private function resolvePostValues(?array $referencedItem, callable $resolver): array {
        $values = [];
        foreach ($this->getReferencedPosts($referencedItem) as $post) {
            $postId = isset($post['id']) ? (int) $post['id'] : 0;
            $value = $postId > 0 ? $resolver($postId) : '';
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string,mixed>|null $referencedItem
     * @return array<int,array<string,mixed>>
     */
    private function getReferencedPosts(?array $referencedItem): array {
        return is_array($referencedItem) && !empty($referencedItem['posts']) && is_array($referencedItem['posts'])
            ? array_values(array_filter($referencedItem['posts'], 'is_array'))
            : [];
    }

    private function extractPostIdFromDisplayName(string $displayName): int {
        $postId = 0;
        $matches = [];
        $slug = trim($displayName);
        if ($slug !== '' && (preg_match(self::REGEX_MARKDOWN_POST_ID, $slug, $matches) === 1 || preg_match(self::REGEX_PREFIXED_POST_ID, $slug, $matches) === 1)) {
            $postId = (int) ($matches[1] ?? 0);
        }

        return $postId;
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,string> $rows
     */
    private function renderBrowser(array $documents, array $rows): string {
        $browserId = 'geweb-gemini-store-browser-' . substr(md5(wp_json_encode($documents)), 0, 8);

        return '<div class="geweb-gemini-store-documents-browser">' .
            $this->renderFilters($browserId) .
            '<div class="geweb-gemini-store-documents-filter-status description" style="margin-bottom:8px; color:#646970;"></div>' .
            '<table class="widefat striped geweb-gemini-store-documents-table">' .
            $this->renderTableHeader() .
            '<tbody>' . implode('', $rows) . '</tbody></table></div>';
    }

    private function renderFilters(string $browserId): string {
        return '<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:end; margin-bottom:12px;">' .
            $this->renderInputFilter($browserId . '-filter', 'Filter', 'regular-text geweb-gemini-store-documents-filter', 'Filter uploaded items') .
            $this->renderSelectFilter($browserId . '-type-filter', 'Type', 'geweb-gemini-store-documents-type-filter', [
                '' => 'All types',
                'page (markdown)' => 'Page (Markdown)',
                'image' => 'Image',
                'document' => 'Document',
            ]) .
            $this->renderSelectFilter($browserId . '-format-filter', 'Format', 'geweb-gemini-store-documents-format-filter', [
                '' => 'All formats',
                'excel' => 'Excel (.xls/.xlsx)',
                'pdf' => 'PDF',
                'word' => 'Word',
                'markdown' => 'Markdown',
                'image' => 'Image',
                'other' => 'Other',
            ]) .
            $this->renderInputFilter($browserId . '-id-filter', 'Page ID', 'small-text geweb-gemini-store-documents-id-filter', 'e.g. 62') .
            $this->renderInputFilter($browserId . '-slug-filter', 'Slug', 'regular-text geweb-gemini-store-documents-slug-filter', 'Filter by slug') .
            '</div>';
    }

    private function renderInputFilter(string $id, string $label, string $className, string $placeholder): string {
        return '<label for="' . esc_attr($id) . '" style="display:flex; flex-direction:column; gap:4px;">' .
            '<span>' . esc_html($label) . '</span>' .
            '<input type="search" id="' . esc_attr($id) . self::HTML_NAME_ATTR_PREFIX . esc_attr($id) . '" class="' . esc_attr($className) . '" placeholder="' . esc_attr($placeholder) . '">' .
            '</label>';
    }

    /**
     * @param array<string,string> $options
     */
    private function renderSelectFilter(string $id, string $label, string $className, array $options): string {
        $optionHtml = '';
        foreach ($options as $value => $text) {
            $optionHtml .= '<option value="' . esc_attr($value) . '">' . esc_html($text) . '</option>';
        }

        return '<label for="' . esc_attr($id) . '" style="display:flex; flex-direction:column; gap:4px;">' .
            '<span>' . esc_html($label) . '</span>' .
            '<select id="' . esc_attr($id) . self::HTML_NAME_ATTR_PREFIX . esc_attr($id) . '" class="' . esc_attr($className) . '">' . $optionHtml . '</select>' .
            '</label>';
    }

    private function renderTableHeader(): string {
        return '<thead><tr>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="name" data-sort-label="Document Name">Document Name</button></th>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="id" data-sort-label="ID">ID</button></th>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="slug" data-sort-label="Slug">Slug</button></th>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="type" data-sort-label="Type">Type</button></th>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="size" data-sort-label="Size">Size</button></th>' .
            '<th><button type="button" class="button-link geweb-gemini-store-documents-sort-header" data-sort-key="url" data-sort-label="Referenced Page URL">Referenced Page URL</button></th>' .
            '<th>Actions</th></tr></thead>';
    }

    private function renderEmptyMessage(): string {
        return '<p style="margin:0;">No uploaded items found for this store.</p>';
    }

    private function normalizeSortValue(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
