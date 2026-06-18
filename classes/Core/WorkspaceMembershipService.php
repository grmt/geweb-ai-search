<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class WorkspaceMembershipService {
    public const USER_WORKSPACES_META_KEY = 'geweb_ai_workspace_memberships';
    public const LEGACY_USER_WORKSPACE_META_KEY = 'workspace_id';
    public const PAGE_WORKSPACE_META_KEY = 'workspace_id';
    public const ROLE_MEMBER = 'member';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_ADMIN = 'admin';

    private WorkspaceMembershipNormalizer $membershipNormalizer;

    public function __construct(?WorkspaceMembershipNormalizer $membershipNormalizer = null) {
        $this->membershipNormalizer = $membershipNormalizer instanceof WorkspaceMembershipNormalizer
            ? $membershipNormalizer
            : new WorkspaceMembershipNormalizer();
    }

    /**
     * @return array<int,array{workspace_id:string,role:string,external_groups:array<int,string>}>
     */
    public function getUserMemberships(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $stored = get_user_meta($userId, self::USER_WORKSPACES_META_KEY, true);
        $memberships = $this->membershipNormalizer->normalizeStoredMemberships($stored);
        if (!empty($memberships)) {
            return $memberships;
        }

        return $this->membershipNormalizer->buildLegacyMemberships(
            (string) get_user_meta($userId, self::LEGACY_USER_WORKSPACE_META_KEY, true)
        );
    }

    /**
     * @return array<int,string>
     */
    public function getUserWorkspaceIds(int $userId): array {
        $workspaceIds = [];
        foreach ($this->getUserMemberships($userId) as $membership) {
            $workspaceIds[] = $membership['workspace_id'];
        }

        return array_values(array_unique(array_filter($workspaceIds)));
    }

    public function getPrimaryWorkspaceId(int $userId): string {
        $workspaceIds = $this->getUserWorkspaceIds($userId);
        return $workspaceIds[0] ?? '';
    }

    public function userBelongsToWorkspace(int $userId, string $workspaceId): bool {
        return $this->getUserWorkspaceRole($userId, $workspaceId) !== '';
    }

    public function userCanEditWorkspace(int $userId, string $workspaceId): bool {
        $role = $this->getUserWorkspaceRole($userId, $workspaceId);
        return in_array($role, [self::ROLE_EDITOR, self::ROLE_ADMIN], true);
    }

    public function userCanAdminWorkspace(int $userId, string $workspaceId): bool {
        return $this->getUserWorkspaceRole($userId, $workspaceId) === self::ROLE_ADMIN;
    }

    public function getUserWorkspaceRole(int $userId, string $workspaceId): string {
        $normalizedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId($workspaceId);
        if ($userId <= 0 || $normalizedWorkspaceId === '') {
            return '';
        }

        foreach ($this->getUserMemberships($userId) as $membership) {
            if ($membership['workspace_id'] === $normalizedWorkspaceId) {
                return $membership['role'];
            }
        }

        return '';
    }

    public function getPageWorkspaceId(int $pageId): string {
        if ($pageId <= 0) {
            return '';
        }

        return WorkspaceRegistry::normalizeWorkspaceId(
            (string) get_post_meta($pageId, self::PAGE_WORKSPACE_META_KEY, true)
        );
    }

    public function assignWorkspaceToPage(int $pageId, string $workspaceId): void {
        $normalizedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId($workspaceId);
        if ($pageId <= 0 || $normalizedWorkspaceId === '') {
            return;
        }

        update_post_meta($pageId, self::PAGE_WORKSPACE_META_KEY, $normalizedWorkspaceId);
    }
}
