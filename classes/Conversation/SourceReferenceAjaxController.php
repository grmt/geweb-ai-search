<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class SourceReferenceAjaxController {
    private ManagedSourceReferenceResolver $resolver;

    public function __construct(ManagedSourceReferenceResolver $resolver) {
        $this->resolver = $resolver;
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
}
