<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class WorkspaceAdminManager {
    /**
     * @return array<int,array{workspace_id:string,name:string,external_groups_csv:string}>
     */
    public function getWorkspaceRows(): array {
        $definitions = (new WorkspaceRegistry())->getWorkspaceDefinitions();
        $rows = [];
        foreach ($definitions as $definition) {
            $rows[] = [
                'workspace_id' => (string) ($definition['workspace_id'] ?? ''),
                'name' => (string) ($definition['name'] ?? ''),
                'external_groups_csv' => implode(', ', is_array($definition['external_groups'] ?? null) ? $definition['external_groups'] : []),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['workspace_id'] ?? ''), (string) ($right['workspace_id'] ?? ''));
        });

        if (empty($rows)) {
            $rows[] = [
                'workspace_id' => '',
                'name' => '',
                'external_groups_csv' => '',
            ];
        }

        return $rows;
    }

    /**
     * @return array{workspace_id:string,assigned:array<int,array{user_id:int,label:string,email:string,role:string}>,unassigned:array<int,array{user_id:int,label:string,email:string,default_role:string}>}
     */
    public function getWorkspaceAssignmentView(string $workspaceId): array {
        $membershipService = new WorkspaceMembershipService();
        $users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $normalizedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId($workspaceId);
        $assigned = [];
        $unassigned = [];
        foreach ($users as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $userId = (int) $user->ID;
            $baseRow = [
                'user_id' => $userId,
                'label' => $this->buildUserLabel($user),
                'email' => (string) $user->user_email,
            ];

            $role = $normalizedWorkspaceId !== ''
                ? $membershipService->getUserWorkspaceRole($userId, $normalizedWorkspaceId)
                : '';
            if ($role !== '') {
                $assigned[] = $baseRow + ['role' => $role];
                continue;
            }

            $unassigned[] = $baseRow + ['default_role' => WorkspaceMembershipService::ROLE_EDITOR];
        }

        return [
            'workspace_id' => $normalizedWorkspaceId,
            'assigned' => $assigned,
            'unassigned' => $unassigned,
        ];
    }

    public function saveFromRequest(): void {
        $this->saveWorkspaceDefinitionsFromRequest();
        $this->saveSelectedWorkspaceAssignmentsFromRequest();
    }

    private function saveWorkspaceDefinitionsFromRequest(): void {
        $rawRows = isset($_POST['geweb_ai_workspaces']) ? wp_unslash($_POST['geweb_ai_workspaces']) : [];
        if (!is_array($rawRows)) {
            update_option(WorkspaceRegistry::OPTION_WORKSPACE_DEFINITIONS, [], false);
            return;
        }

        $definitions = [];
        foreach ($rawRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $workspaceId = WorkspaceRegistry::normalizeWorkspaceId((string) ($row['workspace_id'] ?? ''));
            $name = sanitize_text_field((string) ($row['name'] ?? ''));
            if ($workspaceId === '') {
                continue;
            }

            $definitions[$workspaceId] = [
                'workspace_id' => $workspaceId,
                'name' => $name !== '' ? $name : $workspaceId,
                'external_groups' => $this->parseCsvList((string) ($row['external_groups_csv'] ?? '')),
            ];
        }

        update_option(WorkspaceRegistry::OPTION_WORKSPACE_DEFINITIONS, $definitions, false);
    }

    private function saveSelectedWorkspaceAssignmentsFromRequest(): void {
        $workspaceId = isset($_POST['geweb_workspace_assignment_workspace_id'])
            ? WorkspaceRegistry::normalizeWorkspaceId((string) wp_unslash($_POST['geweb_workspace_assignment_workspace_id']))
            : '';
        if ($workspaceId === '') {
            return;
        }

        $assignedRoles = isset($_POST['geweb_ai_workspace_assigned_roles']) && is_array($_POST['geweb_ai_workspace_assigned_roles'])
            ? wp_unslash($_POST['geweb_ai_workspace_assigned_roles'])
            : [];
        $removedUserIds = isset($_POST['geweb_ai_workspace_remove_user_ids']) && is_array($_POST['geweb_ai_workspace_remove_user_ids'])
            ? array_map('absint', wp_unslash($_POST['geweb_ai_workspace_remove_user_ids']))
            : [];
        $addedUserIds = isset($_POST['geweb_ai_workspace_add_user_ids']) && is_array($_POST['geweb_ai_workspace_add_user_ids'])
            ? array_map('absint', wp_unslash($_POST['geweb_ai_workspace_add_user_ids']))
            : [];
        $addedRoles = isset($_POST['geweb_ai_workspace_add_roles']) && is_array($_POST['geweb_ai_workspace_add_roles'])
            ? wp_unslash($_POST['geweb_ai_workspace_add_roles'])
            : [];

        $membershipService = new WorkspaceMembershipService();
        foreach (get_users(['fields' => 'ID']) as $userId) {
            $normalizedUserId = absint($userId);
            if ($normalizedUserId <= 0) {
                continue;
            }

            $memberships = $membershipService->getUserMemberships($normalizedUserId);
            $filteredMemberships = array_values(array_filter($memberships, static function (array $membership) use ($workspaceId): bool {
                return (string) ($membership['workspace_id'] ?? '') !== $workspaceId;
            }));

            if (in_array($normalizedUserId, $removedUserIds, true)) {
                update_user_meta($normalizedUserId, WorkspaceMembershipService::USER_WORKSPACES_META_KEY, $filteredMemberships);
                continue;
            }

            if (isset($assignedRoles[$normalizedUserId])) {
                $filteredMemberships[] = $this->buildMembershipEntry($workspaceId, (string) $assignedRoles[$normalizedUserId]);
                update_user_meta($normalizedUserId, WorkspaceMembershipService::USER_WORKSPACES_META_KEY, $filteredMemberships);
                continue;
            }

            if (in_array($normalizedUserId, $addedUserIds, true)) {
                $role = isset($addedRoles[$normalizedUserId]) ? (string) $addedRoles[$normalizedUserId] : WorkspaceMembershipService::ROLE_EDITOR;
                $filteredMemberships[] = $this->buildMembershipEntry($workspaceId, $role);
                update_user_meta($normalizedUserId, WorkspaceMembershipService::USER_WORKSPACES_META_KEY, $filteredMemberships);
            }
        }
    }

    private function buildUserLabel(\WP_User $user): string {
        $displayName = trim((string) $user->display_name);
        $userLogin = trim((string) $user->user_login);
        return $displayName !== '' ? $displayName : $userLogin;
    }

    /**
     * @return array<int,string>
     */
    private function parseCsvList(string $value): array {
        $items = array_map('trim', explode(',', $value));
        $items = array_filter($items, static function (string $item): bool {
            return $item !== '';
        });

        return array_values(array_unique($items));
    }

    /**
     * @return array{workspace_id:string,role:string,external_groups:array<int,string>}
     */
    private function buildMembershipEntry(string $workspaceId, string $role): array {
        $normalizedRole = sanitize_key($role);
        if (!in_array($normalizedRole, [
            WorkspaceMembershipService::ROLE_MEMBER,
            WorkspaceMembershipService::ROLE_EDITOR,
            WorkspaceMembershipService::ROLE_ADMIN,
        ], true)) {
            $normalizedRole = WorkspaceMembershipService::ROLE_EDITOR;
        }

        return [
            'workspace_id' => $workspaceId,
            'role' => $normalizedRole,
            'external_groups' => [],
        ];
    }
}
