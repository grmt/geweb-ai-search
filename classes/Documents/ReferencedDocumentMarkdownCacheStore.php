<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Stores generated Markdown snapshots for referenced documents.
 */
class ReferencedDocumentMarkdownCacheStore {
    private static $table;
    private static bool $schemaEnsured = false;
    private string $ownerKey;

    public function __construct() {
        $this->ownerKey = UserScope::getCurrentGroupScopeKey();
    }

    public static function init(): void {
        global $wpdb;
        self::$table = $wpdb->prefix . 'geweb_ai_document_markdown_cache';
        self::ensureSchema();
    }

    public static function install(): void {
        self::init();
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::$table . " (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_key VARCHAR(191) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            markdown LONGTEXT NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            updated_at BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY owner_file_hash (owner_key, file_hash),
            KEY owner_key (owner_key)
        ) $charsetCollate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        self::$schemaEnsured = true;
        global $wpdb;
        $tableName = $wpdb->prefix . 'geweb_ai_document_markdown_cache';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));
        if ($exists === $tableName) {
            return;
        }

        self::install();
    }

    public function saveMarkdown(string $fileHash, string $displayName, string $markdown): void {
        self::init();
        $fileHash = trim($fileHash);
        if ($fileHash === '' || trim($markdown) === '') {
            return;
        }

        global $wpdb;
        $wpdb->replace(
            self::$table,
            [
                'owner_key' => $this->ownerKey,
                'file_hash' => $fileHash,
                'display_name' => $displayName,
                'markdown' => $markdown,
                'content_hash' => hash('sha256', $markdown),
                'updated_at' => time(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d']
        );
    }

    public function getMarkdown(string $fileHash): string {
        self::init();
        $fileHash = trim($fileHash);
        if ($fileHash === '') {
            return '';
        }

        global $wpdb;
        $markdown = $wpdb->get_var($wpdb->prepare(
            "SELECT markdown FROM " . self::$table . " WHERE owner_key = %s AND file_hash = %s",
            $this->ownerKey,
            $fileHash
        ));

        return is_string($markdown) ? $markdown : '';
    }

    public function getDisplayName(string $fileHash): string {
        self::init();
        $fileHash = trim($fileHash);
        if ($fileHash === '') {
            return '';
        }

        global $wpdb;
        $displayName = $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM " . self::$table . " WHERE owner_key = %s AND file_hash = %s",
            $this->ownerKey,
            $fileHash
        ));

        return is_string($displayName) ? $displayName : '';
    }

    public function getMarkdownBytes(string $fileHash): int {
        return strlen($this->getMarkdown($fileHash));
    }

    public function deleteMarkdown(string $fileHash): void {
        self::init();
        $fileHash = trim($fileHash);
        if ($fileHash === '') {
            return;
        }

        global $wpdb;
        $wpdb->delete(
            self::$table,
            [
                'owner_key' => $this->ownerKey,
                'file_hash' => $fileHash,
            ],
            ['%s', '%s']
        );
    }
}
