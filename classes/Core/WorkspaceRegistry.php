<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class WorkspaceRegistry {
    public const OPTION_WORKSPACE_DEFINITIONS = 'geweb_ai_workspace_definitions';
    public const DEFAULT_WORKSPACE_ID = 'default';
    public const DEFAULT_WORKSPACE_NAME = 'Default Workspace';

    /**
     * @return array<string,array{workspace_id:string,name:string,external_groups:array<int,string>}>
     */
    public function getWorkspaceDefinitions(): array {
        $stored = get_option(self::OPTION_WORKSPACE_DEFINITIONS, []);
        if (!is_array($stored)) {
            return [];
        }

        $definitions = [];
        foreach ($stored as $workspaceId => $definition) {
            $normalizedDefinition = $this->normalizeWorkspaceDefinition($workspaceId, $definition);
            if ($normalizedDefinition === null) {
                continue;
            }

            $definitions[$normalizedDefinition['workspace_id']] = $normalizedDefinition;
        }

        return $definitions;
    }

    public function workspaceExists(string $workspaceId): bool {
        $normalizedWorkspaceId = self::normalizeWorkspaceId($workspaceId);
        if ($normalizedWorkspaceId === '') {
            return false;
        }

        return isset($this->getWorkspaceDefinitions()[$normalizedWorkspaceId]);
    }

    public static function normalizeWorkspaceId(string $workspaceId): string {
        $normalizedWorkspaceId = sanitize_key($workspaceId);
        return trim($normalizedWorkspaceId);
    }

    /**
     * @param mixed $definition
     * @return array{workspace_id:string,name:string,external_groups:array<int,string>}|null
     */
    private function normalizeWorkspaceDefinition(string $fallbackWorkspaceId, $definition): ?array {
        if (is_string($definition)) {
            $workspaceId = self::normalizeWorkspaceId($fallbackWorkspaceId);
            $name = trim($definition);
            return ($workspaceId !== '' && $name !== '')
                ? [
                    'workspace_id' => $workspaceId,
                    'name' => $name,
                    'external_groups' => [],
                ]
                : null;
        }

        if (!is_array($definition)) {
            return null;
        }

        $workspaceId = self::normalizeWorkspaceId((string) ($definition['workspace_id'] ?? $fallbackWorkspaceId));
        if ($workspaceId === '') {
            return null;
        }

        $name = trim((string) ($definition['name'] ?? $workspaceId));
        $externalGroups = [];
        if (isset($definition['external_groups']) && is_array($definition['external_groups'])) {
            foreach ($definition['external_groups'] as $group) {
                $normalizedGroup = trim((string) $group);
                if ($normalizedGroup !== '') {
                    $externalGroups[] = $normalizedGroup;
                }
            }
        }

        return [
            'workspace_id' => $workspaceId,
            'name' => $name !== '' ? $name : $workspaceId,
            'external_groups' => array_values(array_unique($externalGroups)),
        ];
    }
}
