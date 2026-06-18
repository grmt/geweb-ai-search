<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class SourceReferenceAjaxController {
    private ManagedSourceReferenceResolver $resolver;
    private MarkdownContextReconstructor $markdownContextReconstructor;

    public function __construct(ManagedSourceReferenceResolver $resolver, ?MarkdownContextReconstructor $markdownContextReconstructor = null) {
        $this->resolver = $resolver;
        $this->markdownContextReconstructor = $markdownContextReconstructor ?? new MarkdownContextReconstructor();
    }

    public function ajaxResolveSourceReferences(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $rawUrls = isset($_POST['urls']) && is_array($_POST['urls']) ? wp_unslash($_POST['urls']) : [];
        $resolved = [];

        foreach ($rawUrls as $rawUrl) {
            $url = is_string($rawUrl) ? trim($rawUrl) : '';
            if ($url === '') {
                continue;
            }

            $reference = $this->resolver->resolve($url);
            if (empty($reference)) {
                continue;
            }

            $resolved[$url] = $reference;
        }

        wp_send_json_success([
            'references' => $resolved,
        ]);
    }

    public function ajaxReconstructSourceContexts(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $rawItems = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : [];
        $items = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $items[] = [
                'key' => isset($rawItem['key']) ? sanitize_text_field((string) $rawItem['key']) : '',
                'url' => isset($rawItem['url']) ? esc_url_raw((string) $rawItem['url']) : '',
                'source_url' => isset($rawItem['sourceUrl']) ? esc_url_raw((string) $rawItem['sourceUrl']) : '',
                'text' => isset($rawItem['text']) ? sanitize_textarea_field((string) $rawItem['text']) : '',
            ];
        }

        wp_send_json_success([
            'contexts' => $this->markdownContextReconstructor->reconstructBatch($items),
        ]);
    }
}
