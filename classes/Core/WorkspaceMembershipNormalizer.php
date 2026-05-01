<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class WorkspaceMembershipNormalizer {
    /**
     * @param mixed $stored
     * @return array<int,array{workspace_id:string,role:string,external_groups:array<int,string>}>
     */
    public function normalizeStoredMemberships($stored): array {
        if (!is_array($stored)) {
            return [];
        }

        $memberships = [];
        foreach ($stored as $membership) {
            $normalizedMembership = $this->normalizeMembership($membership);
            if ($normalizedMembership === null) {
                continue;
            }

            $memberships[$normalizedMembership['workspace_id']] = $normalizedMembership;
        }

        return array_values($memberships);
    }

    /**
     * @return array<int,array{workspace_id:string,role:string,external_groups:array<int,string>}>
     */
    public function buildLegacyMemberships(string $legacyWorkspaceId): array {
        $normalizedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId($legacyWorkspaceId);
        if ($normalizedWorkspaceId === '') {
            return [];
        }

        return [$this->buildMembershipEntry($normalizedWorkspaceId, WorkspaceMembershipService::ROLE_EDITOR)];
    }

    /**
     * @param mixed $membership
     * @return array{workspace_id:string,role:string,external_groups:array<int,string>}|null
     */
    private function normalizeMembership($membership): ?array {
        if (is_string($membership)) {
            $workspaceId = WorkspaceRegistry::normalizeWorkspaceId($membership);
            return $workspaceId !== '' ? $this->buildMembershipEntry($workspaceId, WorkspaceMembershipService::ROLE_EDITOR) : null;
        }

        if (!is_array($membership)) {
            return null;
        }

        $workspaceId = WorkspaceRegistry::normalizeWorkspaceId((string) ($membership['workspace_id'] ?? ''));
        if ($workspaceId === '') {
            return null;
        }

        $role = $this->normalizeWorkspaceRole((string) ($membership['role'] ?? WorkspaceMembershipService::ROLE_MEMBER));
        $externalGroups = $this->normalizeExternalGroups($membership['external_groups'] ?? []);
        return $this->buildMembershipEntry($workspaceId, $role, $externalGroups);
    }

    /**
     * @return array{workspace_id:string,role:string,external_groups:array<int,string>}
     */
    private function buildMembershipEntry(string $workspaceId, string $role, array $externalGroups = []): array {
        return [
            'workspace_id' => $workspaceId,
            'role' => $this->normalizeWorkspaceRole($role),
            'external_groups' => $this->normalizeExternalGroups($externalGroups),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeExternalGroups($value): array {
        if (!is_array($value)) {
            return [];
        }

        $groups = [];
        foreach ($value as $group) {
            $normalizedGroup = trim((string) $group);
            if ($normalizedGroup !== '') {
                $groups[] = $normalizedGroup;
            }
        }

        return array_values(array_unique($groups));
    }

    private function normalizeWorkspaceRole(string $role): string {
        $normalizedRole = sanitize_key($role);
        $allowedRoles = [
            WorkspaceMembershipService::ROLE_MEMBER,
            WorkspaceMembershipService::ROLE_EDITOR,
            WorkspaceMembershipService::ROLE_ADMIN,
        ];
        return in_array($normalizedRole, $allowedRoles, true)
            ? $normalizedRole
            : WorkspaceMembershipService::ROLE_MEMBER;
    }
}
