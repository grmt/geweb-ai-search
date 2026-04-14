<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Stores generated Markdown snapshots for indexed posts.
 */
class MarkdownCacheStore {
    private static $table;
    private static bool $schemaEnsured = false;
    private string $ownerKey;

    public function __construct() {
        $this->ownerKey = UserScope::getCurrentGroupScopeKey();
    }

    public static function init(): void {
        global $wpdb;
        self::$table = $wpdb->prefix . 'geweb_ai_markdown_cache';
        self::ensureSchema();
    }

    public static function install(): void {
        self::init();
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::$table . " (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_key VARCHAR(191) NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            markdown LONGTEXT NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            updated_at BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY owner_post (owner_key, post_id),
            KEY owner_key (owner_key),
            KEY post_id (post_id)
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
        $tableName = $wpdb->prefix . 'geweb_ai_markdown_cache';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName));
        if ($exists === $tableName) {
            return;
        }

        self::install();
    }

    public function saveMarkdown(int $postId, string $markdown): void {
        self::init();
        if ($postId <= 0 || trim($markdown) === '') {
            return;
        }

        global $wpdb;
        $wpdb->replace(
            self::$table,
            [
                'owner_key' => $this->ownerKey,
                'post_id' => $postId,
                'markdown' => $markdown,
                'content_hash' => hash('sha256', $markdown),
                'updated_at' => time(),
            ],
            ['%s', '%d', '%s', '%s', '%d']
        );
    }

    public function getMarkdown(int $postId): string {
        self::init();
        if ($postId <= 0) {
            return '';
        }

        global $wpdb;
        $markdown = $wpdb->get_var($wpdb->prepare(
            "SELECT markdown FROM " . self::$table . " WHERE owner_key = %s AND post_id = %d",
            $this->ownerKey,
            $postId
        ));

        return is_string($markdown) ? $markdown : '';
    }

    public function hasMarkdown(int $postId): bool {
        return $this->getMarkdown($postId) !== '';
    }

    public function deleteMarkdown(int $postId): void {
        self::init();
        if ($postId <= 0) {
            return;
        }

        global $wpdb;
        $wpdb->delete(
            self::$table,
            [
                'owner_key' => $this->ownerKey,
                'post_id' => $postId,
            ],
            ['%s', '%d']
        );
    }

    public static function deleteAllMarkdown(): void {
        self::init();
        global $wpdb;
        $wpdb->query("DELETE FROM " . self::$table);
    }

    /**
     * @return array{count:int,total_bytes:int}
     */
    public function getCacheStats(): array {
        self::init();
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS item_count, COALESCE(SUM(CHAR_LENGTH(markdown)), 0) AS total_bytes FROM " . self::$table . " WHERE owner_key = %s",
                $this->ownerKey
            ),
            ARRAY_A
        );

        return [
            'count' => isset($row['item_count']) ? (int) $row['item_count'] : 0,
            'total_bytes' => isset($row['total_bytes']) ? (int) $row['total_bytes'] : 0,
        ];
    }
}
