<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class WorkspacePageAccessController {
    private WorkspaceMembershipService $membershipService;

    public function __construct(?WorkspaceMembershipService $membershipService = null) {
        $this->membershipService = $membershipService instanceof WorkspaceMembershipService
            ? $membershipService
            : new WorkspaceMembershipService();
    }

    /**
     * @param array<int,string> $caps
     * @param array<int,mixed> $args
     * @return array<int,string>
     */
    public function filterPageCapabilities(array $caps, string $cap, int $userId, array $args): array {
        if ($this->shouldBypassCapabilityFilter($cap, $userId, $args)) {
            return $caps;
        }

        $pageId = (int) ($args[0] ?? 0);
        if ($pageId <= 0 || !$this->isPagePost($pageId)) {
            return $caps;
        }

        $pageWorkspaceId = $this->getPageWorkspaceId($pageId);
        if ($pageWorkspaceId === '') {
            return $caps;
        }

        if (!$this->membershipService->userBelongsToWorkspace($userId, $pageWorkspaceId)) {
            return ['do_not_allow'];
        }

        return $this->buildWorkspaceCapabilityMap($cap, $pageId);
    }

    public function currentUserCanCreateWorkspacePages(): bool {
        $userId = get_current_user_id();
        return $userId > 0 && $this->canUserCreateWorkspacePages($userId);
    }

    public function canUserCreateWorkspacePages(int $userId): bool {
        return $userId > 0
            && user_can($userId, 'edit_pages')
            && !empty($this->membershipService->getUserWorkspaceIds($userId));
    }

    public function currentUserCanEditWorkspacePage(int $pageId): bool {
        $userId = get_current_user_id();
        return $userId > 0 && $this->canUserEditWorkspacePage($userId, $pageId);
    }

    public function canUserEditWorkspacePage(int $userId, int $pageId): bool {
        if ($userId <= 0 || $pageId <= 0 || !$this->isPagePost($pageId)) {
            return false;
        }

        if (user_can($userId, 'manage_options')) {
            return user_can($userId, 'edit_post', $pageId);
        }

        $pageWorkspaceId = $this->getPageWorkspaceId($pageId);
        if ($pageWorkspaceId === '') {
            return false;
        }

        if (!$this->membershipService->userCanEditWorkspace($userId, $pageWorkspaceId)) {
            return false;
        }

        return user_can($userId, 'edit_post', $pageId);
    }

    public function canAssignParentPage(int $userId, int $parentPageId): bool {
        if ($parentPageId <= 0) {
            return true;
        }

        return $this->canUserEditWorkspacePage($userId, $parentPageId);
    }

    public function assignCurrentUserWorkspaceToPage(int $pageId, string $workspaceId = ''): void {
        $this->assignUserWorkspaceToPage($pageId, get_current_user_id(), $workspaceId);
    }

    public function assignUserWorkspaceToPage(int $pageId, int $userId, string $workspaceId = ''): void {
        $resolvedWorkspaceId = $workspaceId !== ''
            ? WorkspaceRegistry::normalizeWorkspaceId($workspaceId)
            : $this->getPrimaryWorkspaceId($userId);
        if ($pageId <= 0 || $resolvedWorkspaceId === '') {
            return;
        }

        $this->membershipService->assignWorkspaceToPage($pageId, $resolvedWorkspaceId);
    }

    public function getCurrentUserWorkspaceId(): string {
        return $this->getPrimaryWorkspaceId(get_current_user_id());
    }

    public function getPrimaryWorkspaceId(int $userId): string {
        return $this->membershipService->getPrimaryWorkspaceId($userId);
    }

    public function getPageWorkspaceId(int $pageId): string {
        return $this->membershipService->getPageWorkspaceId($pageId);
    }

    /**
     * @return array<int,array{workspace_id:string,role:string,external_groups:array<int,string>}>
     */
    public function getUserWorkspaceMemberships(int $userId): array {
        return $this->membershipService->getUserMemberships($userId);
    }

    public function canUserCreatePageInWorkspace(int $userId, string $workspaceId): bool {
        return $userId > 0
            && user_can($userId, 'edit_pages')
            && $this->membershipService->userCanEditWorkspace($userId, $workspaceId);
    }

    private function shouldBypassCapabilityFilter(string $cap, int $userId, array $args): bool {
        if (!in_array($cap, ['edit_post', 'delete_post', 'read_post'], true)) {
            return true;
        }

        if ($userId <= 0 || empty($args) || user_can($userId, 'manage_options')) {
            return true;
        }

        return false;
    }

    private function isPagePost(int $pageId): bool {
        return get_post_type($pageId) === 'page';
    }

    /**
     * @return array<int,string>
     */
    private function buildWorkspaceCapabilityMap(string $cap, int $pageId): array {
        if ($cap === 'read_post') {
            return ['read'];
        }

        $status = get_post_status($pageId);
        if ($cap === 'edit_post') {
            $mappedCaps = ['edit_pages'];
            if ($status === 'publish') {
                $mappedCaps[] = 'edit_published_pages';
            } elseif ($status === 'private') {
                $mappedCaps[] = 'edit_private_pages';
            }

            return $mappedCaps;
        }

        if ($cap === 'delete_post') {
            $mappedCaps = ['delete_pages'];
            if ($status === 'publish') {
                $mappedCaps[] = 'delete_published_pages';
            } elseif ($status === 'private') {
                $mappedCaps[] = 'delete_private_pages';
            }

            return $mappedCaps;
        }

        return ['do_not_allow'];
    }
}
