<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves durable user and group scope identifiers for plugin-owned data.
 */
class UserScope {
    private const COOKIE_NAME = 'geweb_ai_scope';
    private const COOKIE_TTL = YEAR_IN_SECONDS;
    private const SHARED_SEARCH_SCOPE_KEY = 'shared_search';
    private const WORKSPACE_CONFIG_SCOPE_PREFIX = 'workspacecfg';
    private static ?string $groupScopeOverride = null;

    public static function getCurrentUserScopeKey(): string {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return 'user_' . $userId;
        }

        return 'guest_' . self::getGuestScopeId();
    }

    public static function getCurrentGroupScopeKey(): string {
        $scopeKey = 'group_authenticated';
        if (is_string(self::$groupScopeOverride) && self::$groupScopeOverride !== '') {
            $scopeKey = self::$groupScopeOverride;
        } elseif (!is_user_logged_in()) {
            $scopeKey = 'group_guests';
        } elseif (current_user_can('manage_options')) {
            $scopeKey = 'group_administrators';
        } elseif (current_user_can('edit_others_posts') || current_user_can('publish_posts') || current_user_can('edit_posts')) {
            $scopeKey = 'group_content_creators';
        } elseif (current_user_can('read')) {
            $scopeKey = 'group_readers';
        }

        return $scopeKey;
    }

    public static function getOptionNameForScope(string $baseOptionName, string $scopeKey): string {
        return self::buildScopedOptionName($baseOptionName, $scopeKey);
    }

    public static function getUserScopedOptionName(string $baseOptionName): string {
        return self::buildScopedOptionName($baseOptionName, self::getCurrentUserScopeKey());
    }

    public static function getGroupScopedOptionName(string $baseOptionName): string {
        return self::buildScopedOptionName($baseOptionName, self::getCurrentGroupScopeKey());
    }

    public static function getSharedSearchScopedOptionName(string $baseOptionName): string {
        $workspaceScopeKey = self::getCurrentWorkspaceConfigScopeKey();
        if ($workspaceScopeKey !== '') {
            return self::buildScopedOptionName($baseOptionName, $workspaceScopeKey);
        }

        return self::getLegacySharedSearchScopedOptionName($baseOptionName);
    }

    public static function getWorkspaceConfigOptionName(string $baseOptionName): string {
        $workspaceScopeKey = self::getCurrentWorkspaceConfigScopeKey();
        return $workspaceScopeKey !== ''
            ? self::buildScopedOptionName($baseOptionName, $workspaceScopeKey)
            : $baseOptionName;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getUserScopedOption(string $baseOptionName, $default = false) {
        return get_option(self::getUserScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getGroupScopedOption(string $baseOptionName, $default = false) {
        return get_option(self::getGroupScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getSharedSearchScopedOption(string $baseOptionName, $default = false) {
        $workspaceScopeKey = self::getCurrentWorkspaceConfigScopeKey();
        if ($workspaceScopeKey !== '') {
            $workspaceOptionName = self::buildScopedOptionName($baseOptionName, $workspaceScopeKey);
            if (self::optionExists($workspaceOptionName)) {
                return get_option($workspaceOptionName, $default);
            }
        }

        return get_option(self::getLegacySharedSearchScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getWorkspaceConfigOption(string $baseOptionName, $default = false) {
        $workspaceOptionName = self::getWorkspaceConfigOptionName($baseOptionName);
        if ($workspaceOptionName !== $baseOptionName && self::optionExists($workspaceOptionName)) {
            return get_option($workspaceOptionName, $default);
        }

        return get_option($baseOptionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateUserScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getUserScopedOptionName($baseOptionName), $value, $autoload);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateGroupScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getGroupScopedOptionName($baseOptionName), $value, $autoload);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateSharedSearchScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getSharedSearchScopedOptionName($baseOptionName), $value, $autoload);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateWorkspaceConfigOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getWorkspaceConfigOptionName($baseOptionName), $value, $autoload);
    }

    public static function deleteUserScopedOption(string $baseOptionName): void {
        delete_option(self::getUserScopedOptionName($baseOptionName));
    }

    public static function deleteGroupScopedOption(string $baseOptionName): void {
        delete_option(self::getGroupScopedOptionName($baseOptionName));
    }

    public static function deleteSharedSearchScopedOption(string $baseOptionName): void {
        delete_option(self::getSharedSearchScopedOptionName($baseOptionName));
    }

    public static function deleteWorkspaceConfigOption(string $baseOptionName): void {
        delete_option(self::getWorkspaceConfigOptionName($baseOptionName));
    }

    public static function getCurrentUserScopeStorageKey(): string {
        return self::getUserScopedOptionName('scope');
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    public static function withGroupScopeOverride(string $scopeKey, callable $callback) {
        $normalizedScopeKey = trim($scopeKey);
        if ($normalizedScopeKey === '') {
            return $callback();
        }

        $previousScopeKey = self::$groupScopeOverride;
        self::$groupScopeOverride = $normalizedScopeKey;

        try {
            return $callback();
        } finally {
            self::$groupScopeOverride = $previousScopeKey;
        }
    }

    public static function getCurrentWorkspaceId(): string {
        $requestedWorkspaceId = '';
        if (isset($_POST['geweb_workspace_id'])) {
            $requestedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId((string) wp_unslash($_POST['geweb_workspace_id']));
        } elseif (isset($_GET['geweb_workspace_id'])) {
            $requestedWorkspaceId = WorkspaceRegistry::normalizeWorkspaceId((string) wp_unslash($_GET['geweb_workspace_id']));
        }

        if ($requestedWorkspaceId !== '') {
            return $requestedWorkspaceId;
        }

        $membershipService = new WorkspaceMembershipService();
        $primaryWorkspaceId = $membershipService->getPrimaryWorkspaceId(get_current_user_id());
        if ($primaryWorkspaceId !== '') {
            return $primaryWorkspaceId;
        }

        $definitions = (new WorkspaceRegistry())->getWorkspaceDefinitions();
        if (count($definitions) === 1) {
            $definition = reset($definitions);
            return is_array($definition) ? (string) ($definition['workspace_id'] ?? '') : '';
        }

        return '';
    }

    public static function hasCurrentWorkspace(): bool {
        return self::getCurrentWorkspaceId() !== '';
    }

    private static function buildScopedOptionName(string $baseOptionName, string $scopeKey): string {
        $suffix = preg_replace('/\W/', '_', $scopeKey);
        $suffix = is_string($suffix) ? trim($suffix, '_') : '';

        return $suffix !== ''
            ? $baseOptionName . '__' . $suffix
            : $baseOptionName;
    }

    private static function getGuestScopeId(): string {
        return UserScopeGuestCookie::resolve(self::COOKIE_NAME, self::COOKIE_TTL);
    }

    private static function getCurrentWorkspaceConfigScopeKey(): string {
        $workspaceId = self::getCurrentWorkspaceId();
        return $workspaceId !== ''
            ? self::WORKSPACE_CONFIG_SCOPE_PREFIX . '_' . $workspaceId
            : '';
    }

    private static function getLegacySharedSearchScopedOptionName(string $baseOptionName): string {
        return self::buildScopedOptionName($baseOptionName, self::SHARED_SEARCH_SCOPE_KEY);
    }

    private static function optionExists(string $optionName): bool {
        return array_key_exists($optionName, wp_load_alloptions())
            || get_option($optionName, null) !== null;
    }
}
