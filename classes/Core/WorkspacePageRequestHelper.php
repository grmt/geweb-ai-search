<?php
namespace Geweb\AISearch;

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class WorkspacePageRequestHelper {
    /**
     * @return array<string,array<string,mixed>>
     */
    public function getCommonPageArgs(): array {
        return [
            'title' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'content' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => null,
            ],
            'excerpt' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'slug' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_title',
            ],
            'status' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => [$this, 'validatePageStatus'],
            ],
            'parent' => [
                'type' => 'integer',
                'required' => false,
                'sanitize_callback' => 'absint',
            ],
            'menu_order' => [
                'type' => 'integer',
                'required' => false,
                'sanitize_callback' => 'intval',
            ],
            'template' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'workspace_id' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => [WorkspaceRegistry::class, 'normalizeWorkspaceId'],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getUpdatePageArgs(): array {
        $args = $this->getCommonPageArgs();
        $args['id'] = [
            'type' => 'integer',
            'required' => true,
            'sanitize_callback' => 'absint',
        ];
        return $args;
    }

    public function validatePageStatus($value): bool {
        $status = sanitize_key((string) $value);
        if ($status === '') {
            return true;
        }

        return in_array($status, ['draft', 'pending', 'private', 'publish'], true);
    }

    public function resolveRequestedWorkspaceId(WP_REST_Request $request): string {
        return WorkspaceRegistry::normalizeWorkspaceId((string) $request->get_param('workspace_id'));
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPagePayload(WP_REST_Request $request, bool $includeAuthor = false): array {
        $payload = ['post_type' => 'page'];
        if ($includeAuthor) {
            $payload['post_author'] = get_current_user_id();
        }

        $fieldMap = [
            'title' => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'slug' => 'post_name',
            'status' => 'post_status',
            'parent' => 'post_parent',
            'menu_order' => 'menu_order',
            'template' => 'page_template',
        ];

        foreach ($fieldMap as $requestKey => $postKey) {
            if ($request->has_param($requestKey)) {
                $payload[$postKey] = $request->get_param($requestKey);
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPageResponse(int $pageId, WorkspacePageAccessController $accessController): array {
        $page = get_post($pageId);
        if (!$page instanceof WP_Post) {
            return ['id' => $pageId];
        }

        return [
            'id' => $page->ID,
            'title' => get_the_title($page),
            'status' => (string) $page->post_status,
            'slug' => (string) $page->post_name,
            'link' => (string) get_permalink($page),
            'edit_link' => (string) get_edit_post_link($page->ID, 'raw'),
            'parent' => (int) $page->post_parent,
            'menu_order' => (int) $page->menu_order,
            'workspace_id' => $accessController->getPageWorkspaceId($page->ID),
        ];
    }

    public function buildErrorResponse(string $code, string $message, int $status): WP_REST_Response {
        return new WP_REST_Response([
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
