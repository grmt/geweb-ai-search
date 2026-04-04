<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Performs a one-time migration from legacy shared data and the interim single-user scope
 * into the final user/group ownership model.
 */
class UserScopeMigration {
    private const OPTION_MIGRATION_DONE = 'geweb_aisearch_user_scope_migration_done_v2';

    /**
     * @var array<int,string>
     */
    private const USER_SCOPED_OPTION_NAMES = [
        'geweb_aisearch_conversations',
    ];

    /**
     * @var array<int,string>
     */
    private const GROUP_SCOPED_OPTION_NAMES = [
        'geweb_aisearch_prompt_history',
        'geweb_aisearch_prompt_history_limit',
        'geweb_aisearch_custom_prompt',
        'geweb_aisearch_custom_prompt_name',
        'geweb_aisearch_model_prompts',
        'geweb_aisearch_model_prompt_names',
        'geweb_aisearch_model_prompt_modes',
        'geweb_aisearch_gemini_store',
        'geweb_aisearch_gemini_stores_cache',
        'geweb_aisearch_gemini_stores_cache_time',
        'geweb_aisearch_gemini_stores_cache_error',
        'geweb_aisearch_referenced_documents_cache',
        'geweb_aisearch_referenced_documents_cache_time',
        'geweb_aisearch_referenced_documents_cache_debug',
        'geweb_aisearch_referenced_document_selection_targets',
    ];

    public static function maybeRun(): void {
        if ((string) get_option(self::OPTION_MIGRATION_DONE, '') !== '') {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        $userScopeKey = UserScope::getCurrentUserScopeKey();
        $groupScopeKey = UserScope::getCurrentGroupScopeKey();
        if ($userScopeKey === '' || $groupScopeKey === '') {
            return;
        }

        self::migrateUserScopedOptions($userScopeKey, $groupScopeKey);
        self::migrateGroupScopedOptions($userScopeKey, $groupScopeKey);
        self::migrateLegacyDocumentOwnership($userScopeKey, $groupScopeKey);

        update_option(
            self::OPTION_MIGRATION_DONE,
            $userScopeKey . '|' . $groupScopeKey . '|' . (string) time(),
            false
        );
    }

    private static function migrateUserScopedOptions(string $userScopeKey, string $groupScopeKey): void {
        foreach (self::USER_SCOPED_OPTION_NAMES as $baseOptionName) {
            self::migrateOptionIfMissing(
                UserScope::getOptionNameForScope($baseOptionName, $userScopeKey),
                [
                    UserScope::getOptionNameForScope($baseOptionName, $groupScopeKey),
                    $baseOptionName,
                ]
            );
        }
    }

    private static function migrateGroupScopedOptions(string $userScopeKey, string $groupScopeKey): void {
        foreach (self::GROUP_SCOPED_OPTION_NAMES as $baseOptionName) {
            self::migrateOptionIfMissing(
                UserScope::getOptionNameForScope($baseOptionName, $groupScopeKey),
                [
                    UserScope::getOptionNameForScope($baseOptionName, $userScopeKey),
                    $baseOptionName,
                ]
            );
        }
    }

    /**
     * @param array<int,string> $candidateOptionNames
     * @return void
     */
    private static function migrateOptionIfMissing(string $targetOptionName, array $candidateOptionNames): void {
        $missing = new \stdClass();
        $targetValue = get_option($targetOptionName, $missing);
        if ($targetValue !== $missing) {
            return;
        }

        foreach (array_values(array_unique($candidateOptionNames)) as $candidateOptionName) {
            if ($candidateOptionName === '' || $candidateOptionName === $targetOptionName) {
                continue;
            }

            $candidateValue = get_option($candidateOptionName, $missing);
            if ($candidateValue === $missing) {
                continue;
            }

            update_option($targetOptionName, $candidateValue, false);
            return;
        }
    }

    private static function migrateLegacyDocumentOwnership(string $userScopeKey, string $groupScopeKey): void {
        DocumentStore::init();
        global $wpdb;

        $documentsTable = $wpdb->prefix . 'geweb_ai_documents';
        $refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';

        $docsHasOwnerKey = $wpdb->get_var("SHOW COLUMNS FROM {$documentsTable} LIKE 'owner_key'");
        $refsHasOwnerKey = $wpdb->get_var("SHOW COLUMNS FROM {$refsTable} LIKE 'owner_key'");
        if (!$docsHasOwnerKey || !$refsHasOwnerKey) {
            return;
        }

        self::migrateDocumentsForBlankOwners($documentsTable, $refsTable, $groupScopeKey);

        if ($userScopeKey !== $groupScopeKey) {
            self::migrateDocumentsForOwner($documentsTable, $refsTable, $userScopeKey, $groupScopeKey);
        }
    }

    private static function migrateDocumentsForBlankOwners(string $documentsTable, string $refsTable, string $targetOwnerKey): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$documentsTable} WHERE owner_key IS NULL OR owner_key = '' OR owner_key = '0'",
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            self::migrateDocumentRow($documentsTable, $refsTable, $row, null, $targetOwnerKey);
        }
    }

    private static function migrateDocumentsForOwner(string $documentsTable, string $refsTable, string $sourceOwnerKey, string $targetOwnerKey): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$documentsTable} WHERE owner_key = %s",
                $sourceOwnerKey
            ),
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            self::migrateDocumentRow($documentsTable, $refsTable, $row, $sourceOwnerKey, $targetOwnerKey);
        }
    }

    /**
     * @param array<string,mixed> $documentRow
     * @return void
     */
    private static function migrateDocumentRow(string $documentsTable, string $refsTable, array $documentRow, ?string $sourceOwnerKey, string $targetOwnerKey): void {
        global $wpdb;

        $sourceDocumentId = (int) ($documentRow['id'] ?? 0);
        $fileHash = (string) ($documentRow['file_hash'] ?? '');
        $geminiDocName = (string) ($documentRow['gemini_doc_name'] ?? '');

        if ($sourceDocumentId <= 0 || $fileHash === '') {
            return;
        }

        $targetDocument = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$documentsTable} WHERE owner_key = %s AND (file_hash = %s OR gemini_doc_name = %s) LIMIT 1",
                $targetOwnerKey,
                $fileHash,
                $geminiDocName
            ),
            ARRAY_A
        );

        if (is_array($targetDocument) && !empty($targetDocument['id'])) {
            self::mergeDocumentReferencesIntoTarget(
                $refsTable,
                $sourceDocumentId,
                (int) $targetDocument['id'],
                $sourceOwnerKey,
                $targetOwnerKey
            );
            self::deleteSourceDocumentRow($documentsTable, $sourceDocumentId, $sourceOwnerKey);
            return;
        }

        self::moveDocumentReferencesToTargetOwner($refsTable, $sourceDocumentId, $sourceOwnerKey, $targetOwnerKey);
        self::moveDocumentRowToTargetOwner($documentsTable, $sourceDocumentId, $sourceOwnerKey, $targetOwnerKey);
    }

    private static function mergeDocumentReferencesIntoTarget(
        string $refsTable,
        int $sourceDocumentId,
        int $targetDocumentId,
        ?string $sourceOwnerKey,
        string $targetOwnerKey
    ): void {
        global $wpdb;

        $sourcePostIds = $sourceOwnerKey === null
            ? $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$refsTable} WHERE document_id = %d AND (owner_key IS NULL OR owner_key = %s OR owner_key = %s)",
                    $sourceDocumentId,
                    '',
                    '0'
                )
            )
            : $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$refsTable} WHERE owner_key = %s AND document_id = %d",
                    $sourceOwnerKey,
                    $sourceDocumentId
                )
            );

        if (is_array($sourcePostIds)) {
            foreach ($sourcePostIds as $postId) {
                $postId = (int) $postId;
                if ($postId <= 0) {
                    continue;
                }

                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$refsTable} WHERE owner_key = %s AND post_id = %d AND document_id = %d",
                        $targetOwnerKey,
                        $postId,
                        $targetDocumentId
                    )
                );
                if ($exists > 0) {
                    continue;
                }

                $wpdb->insert(
                    $refsTable,
                    [
                        'owner_key' => $targetOwnerKey,
                        'post_id' => $postId,
                        'document_id' => $targetDocumentId,
                    ],
                    ['%s', '%d', '%d']
                );
            }
        }

        self::deleteSourceReferences($refsTable, $sourceDocumentId, $sourceOwnerKey);
    }

    private static function moveDocumentReferencesToTargetOwner(
        string $refsTable,
        int $sourceDocumentId,
        ?string $sourceOwnerKey,
        string $targetOwnerKey
    ): void {
        global $wpdb;

        if ($sourceOwnerKey === null) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$refsTable} SET owner_key = %s WHERE document_id = %d AND (owner_key IS NULL OR owner_key = %s OR owner_key = %s)",
                    $targetOwnerKey,
                    $sourceDocumentId,
                    '',
                    '0'
                )
            );
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$refsTable} SET owner_key = %s WHERE owner_key = %s AND document_id = %d",
                $targetOwnerKey,
                $sourceOwnerKey,
                $sourceDocumentId
            )
        );
    }

    private static function moveDocumentRowToTargetOwner(
        string $documentsTable,
        int $sourceDocumentId,
        ?string $sourceOwnerKey,
        string $targetOwnerKey
    ): void {
        global $wpdb;

        if ($sourceOwnerKey === null) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$documentsTable} SET owner_key = %s WHERE id = %d AND (owner_key IS NULL OR owner_key = %s OR owner_key = %s)",
                    $targetOwnerKey,
                    $sourceDocumentId,
                    '',
                    '0'
                )
            );
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$documentsTable} SET owner_key = %s WHERE id = %d AND owner_key = %s",
                $targetOwnerKey,
                $sourceDocumentId,
                $sourceOwnerKey
            )
        );
    }

    private static function deleteSourceDocumentRow(string $documentsTable, int $sourceDocumentId, ?string $sourceOwnerKey): void {
        global $wpdb;

        if ($sourceOwnerKey === null) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$documentsTable} WHERE id = %d AND (owner_key IS NULL OR owner_key = %s OR owner_key = %s)",
                    $sourceDocumentId,
                    '',
                    '0'
                )
            );
            return;
        }

        $wpdb->delete(
            $documentsTable,
            [
                'id' => $sourceDocumentId,
                'owner_key' => $sourceOwnerKey,
            ],
            ['%d', '%s']
        );
    }

    private static function deleteSourceReferences(string $refsTable, int $sourceDocumentId, ?string $sourceOwnerKey): void {
        global $wpdb;

        if ($sourceOwnerKey === null) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$refsTable} WHERE document_id = %d AND (owner_key IS NULL OR owner_key = %s OR owner_key = %s)",
                    $sourceDocumentId,
                    '',
                    '0'
                )
            );
            return;
        }

        $wpdb->delete(
            $refsTable,
            [
                'owner_key' => $sourceOwnerKey,
                'document_id' => $sourceDocumentId,
            ],
            ['%s', '%d']
        );
    }
}
