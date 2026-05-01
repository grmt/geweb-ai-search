<?php
namespace Geweb\AISearch;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class WorkspacePageRestController {
    private const REST_NAMESPACE = 'geweb-ai-search/v1';
    private const REST_ROUTE_BASE = '/workspace-pages';

    private WorkspacePageAccessController $accessController;
    private WorkspacePageRequestHelper $requestHelper;

    public function __construct(WorkspacePageAccessController $accessController, ?WorkspacePageRequestHelper $requestHelper = null) {
        $this->accessController = $accessController;
        $this->requestHelper = $requestHelper instanceof WorkspacePageRequestHelper
            ? $requestHelper
            : new WorkspacePageRequestHelper();
    }

    public function registerRoutes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE_BASE, [
            'methods' => 'POST',
            'callback' => [$this, 'createPage'],
            'permission_callback' => [$this, 'canCreatePage'],
            'args' => $this->requestHelper->getCommonPageArgs(),
        ]);

        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE_BASE . '/(?P<id>\d+)', [
            'methods' => ['POST', 'PUT', 'PATCH'],
            'callback' => [$this, 'updatePage'],
            'permission_callback' => [$this, 'canUpdatePage'],
            'args' => $this->requestHelper->getUpdatePageArgs(),
        ]);
    }

    public function canCreatePage(WP_REST_Request $request) {
        $userId = get_current_user_id();
        $workspaceId = $this->requestHelper->resolveRequestedWorkspaceId($request);

        if ($workspaceId !== '' && $this->accessController->canUserCreatePageInWorkspace($userId, $workspaceId)) {
            return true;
        }

        if ($workspaceId === '' && $this->canCreatePageInImplicitWorkspace($userId)) {
            return true;
        }

        return new WP_Error(
            'geweb_ai_search_forbidden',
            __('You are not allowed to create pages for the requested workspace.', 'geweb-ai-search'),
            ['status' => rest_authorization_required_code()]
        );
    }

    public function canUpdatePage(WP_REST_Request $request) {
        $pageId = (int) $request->get_param('id');
        if ($this->accessController->currentUserCanEditWorkspacePage($pageId)) {
            return true;
        }

        return new WP_Error(
            'geweb_ai_search_forbidden',
            __('You are not allowed to modify this workspace page.', 'geweb-ai-search'),
            ['status' => rest_authorization_required_code()]
        );
    }

    public function createPage(WP_REST_Request $request): WP_REST_Response {
        $workspaceId = $this->getEffectiveWorkspaceIdForCreate($request);
        if ($workspaceId === '') {
            return $this->requestHelper->buildErrorResponse(
                'geweb_ai_search_workspace_required',
                __('A workspace_id is required when the user belongs to multiple workspaces.', 'geweb-ai-search'),
                400
            );
        }

        $parentPageId = (int) $request->get_param('parent');
        if (
            !$this->accessController->canAssignParentPage(get_current_user_id(), $parentPageId)
            || ($parentPageId > 0 && $this->accessController->getPageWorkspaceId($parentPageId) !== $workspaceId)
        ) {
            return $this->requestHelper->buildErrorResponse(
                'geweb_ai_search_invalid_parent',
                __('The selected parent page is outside your workspace.', 'geweb-ai-search'),
                403
            );
        }

        $pageId = wp_insert_post($this->requestHelper->buildPagePayload($request, true), true);
        if (is_wp_error($pageId)) {
            return $this->requestHelper->buildErrorResponse(
                'geweb_ai_search_page_create_failed',
                $pageId->get_error_message(),
                400
            );
        }

        $this->accessController->assignCurrentUserWorkspaceToPage((int) $pageId, $workspaceId);
        return new WP_REST_Response($this->requestHelper->buildPageResponse((int) $pageId, $this->accessController), 201);
    }

    public function updatePage(WP_REST_Request $request): WP_REST_Response {
        $pageId = (int) $request->get_param('id');
        $parentPageId = (int) $request->get_param('parent');
        if (!$this->accessController->canAssignParentPage(get_current_user_id(), $parentPageId)) {
            return $this->requestHelper->buildErrorResponse(
                'geweb_ai_search_invalid_parent',
                __('The selected parent page is outside your workspace.', 'geweb-ai-search'),
                403
            );
        }

        $payload = $this->requestHelper->buildPagePayload($request);
        $payload['ID'] = $pageId;
        $updatedPageId = wp_update_post($payload, true);
        if (is_wp_error($updatedPageId)) {
            return $this->requestHelper->buildErrorResponse(
                'geweb_ai_search_page_update_failed',
                $updatedPageId->get_error_message(),
                400
            );
        }

        $this->accessController->assignCurrentUserWorkspaceToPage($pageId);
        return new WP_REST_Response($this->requestHelper->buildPageResponse($pageId, $this->accessController), 200);
    }

    private function canCreatePageInImplicitWorkspace(int $userId): bool {
        if (!$this->accessController->currentUserCanCreateWorkspacePages()) {
            return false;
        }

        return count($this->accessController->getUserWorkspaceMemberships($userId)) === 1;
    }

    private function getEffectiveWorkspaceIdForCreate(WP_REST_Request $request): string {
        $requestedWorkspaceId = $this->requestHelper->resolveRequestedWorkspaceId($request);
        if ($requestedWorkspaceId !== '') {
            return $requestedWorkspaceId;
        }

        return $this->accessController->getCurrentUserWorkspaceId();
    }
}
