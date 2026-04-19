<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds a per-request SHA-256 index for attachments and file-list items so
 * duplicate local files can be surfaced in the admin UI.
 */
class FileDuplicateHashIndex {
    /**
     * @var array<string,array<int,array<string,string>>>
     */
    private static array $entriesByHash = [];
    private static bool $built = false;

    public function getAttachmentDuplicateCount(int $attachmentId): int {
        return count($this->getAttachmentDuplicateMatches($attachmentId));
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getAttachmentDuplicateMatches(int $attachmentId): array {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) {
            return [];
        }

        $filePath = get_attached_file($attachmentId);
        $filePath = is_string($filePath) ? wp_normalize_path($filePath) : '';
        if ($filePath === '' || !is_readable($filePath)) {
            return [];
        }

        $hash = hash_file('sha256', $filePath);
        if (!is_string($hash) || $hash === '') {
            return [];
        }

        return $this->getMatchingEntries($hash, 'attachment:' . $attachmentId);
    }

    public function getAttachmentDuplicateNotice(int $attachmentId): string {
        return $this->formatNotice($this->getAttachmentDuplicateMatches($attachmentId));
    }

    public function getReferencedFileDuplicateNotice(string $fileHash, string $filePath = ''): string {
        $fileHash = trim($fileHash);
        if ($fileHash === '') {
            return '';
        }

        $normalizedPath = $filePath !== '' ? wp_normalize_path($filePath) : '';
        $matches = $this->getMatchingEntries($fileHash, $normalizedPath !== '' ? 'file:' . $normalizedPath : '');
        return $this->formatNotice($matches);
    }

    public function getAttachmentDuplicateColumnHtml(int $attachmentId): string {
        $matches = $this->getAttachmentDuplicateMatches($attachmentId);
        if (empty($matches)) {
            return '<span style="color:#646970;">—</span>';
        }

        $summary = count($matches) === 1 ? '1 match' : (count($matches) . ' matches');
        $items = [];

        foreach (array_slice($matches, 0, 3) as $match) {
            $kind = (string) ($match['kind'] ?? '');
            $label = trim((string) ($match['label'] ?? ''));
            $url = trim((string) ($match['url'] ?? ''));
            if ($label === '') {
                continue;
            }

            if ($url !== '' && $kind === 'attachment') {
                $items[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
                continue;
            }

            $prefix = $kind === 'file' ? 'File list: ' : '';
            $items[] = esc_html($prefix . $label);
        }

        $html = '<strong style="color:#996800;">' . esc_html($summary) . '</strong>';
        if (!empty($items)) {
            $html .= '<div><small>' . implode('<br>', $items) . '</small></div>';
        }
        if (count($matches) > 3) {
            $html .= '<div><small style="color:#646970;">+' . esc_html((string) (count($matches) - 3)) . ' more</small></div>';
        }

        return $html;
    }

    /**
     * @return array<int,int>
     */
    public function getAttachmentDuplicateCounts(): array {
        $this->ensureBuilt();
        $counts = [];

        foreach (self::$entriesByHash as $entries) {
            $attachmentIds = [];
            $fileCount = 0;

            foreach ($entries as $entry) {
                $kind = (string) ($entry['kind'] ?? '');
                if ($kind === 'attachment') {
                    $attachmentId = (int) ($entry['attachment_id'] ?? 0);
                    if ($attachmentId > 0) {
                        $attachmentIds[] = $attachmentId;
                    }
                } elseif ($kind === 'file') {
                    $fileCount++;
                }
            }

            if (empty($attachmentIds)) {
                continue;
            }

            $attachmentIds = array_values(array_unique($attachmentIds));
            $attachmentCount = count($attachmentIds);
            foreach ($attachmentIds as $attachmentId) {
                $otherAttachments = max(0, $attachmentCount - 1);
                $counts[$attachmentId] = $otherAttachments + $fileCount;
            }
        }

        return array_filter($counts, static function (int $count): bool {
            return $count > 0;
        });
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getMatchingEntries(string $hash, string $selfKey): array {
        $this->ensureBuilt();
        $matches = self::$entriesByHash[$hash] ?? [];

        if ($selfKey === '') {
            return $matches;
        }

        return array_values(array_filter($matches, static function (array $entry) use ($selfKey): bool {
            return ($entry['key'] ?? '') !== $selfKey;
        }));
    }

    /**
     * @param array<int,array<string,string>> $matches
     */
    private function formatNotice(array $matches): string {
        if (empty($matches)) {
            return '';
        }

        $attachmentCount = 0;
        $fileCount = 0;
        $labels = [];

        foreach ($matches as $match) {
            $kind = (string) ($match['kind'] ?? '');
            if ($kind === 'attachment') {
                $attachmentCount++;
            } elseif ($kind === 'file') {
                $fileCount++;
            }

            $label = trim((string) ($match['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $parts = [];
        if ($attachmentCount > 0) {
            $parts[] = $attachmentCount === 1 ? '1 other media item' : ($attachmentCount . ' other media items');
        }
        if ($fileCount > 0) {
            $parts[] = $fileCount === 1 ? '1 file-list item' : ($fileCount . ' file-list items');
        }

        $summary = empty($parts) ? 'Duplicate file hash detected' : ('Same file as ' . implode(' and ', $parts));
        $labels = array_values(array_unique($labels));
        if (!empty($labels)) {
            $summary .= ': ' . implode(', ', array_slice($labels, 0, 3));
            if (count($labels) > 3) {
                $summary .= ' +' . (count($labels) - 3);
            }
        }

        return $summary;
    }

    private function ensureBuilt(): void {
        if (self::$built) {
            return;
        }

        self::$built = true;
        self::$entriesByHash = [];
        $this->indexAttachments();
        $this->indexSimpleFileListEntries();
    }

    private function indexAttachments(): void {
        $attachmentIds = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        if (!is_array($attachmentIds)) {
            return;
        }

        foreach ($attachmentIds as $attachmentId) {
            $attachmentId = (int) $attachmentId;
            if ($attachmentId <= 0) {
                continue;
            }

            $filePath = get_attached_file($attachmentId);
            $filePath = is_string($filePath) ? wp_normalize_path($filePath) : '';
            if ($filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $hash = hash_file('sha256', $filePath);
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $fileName = basename($filePath);
            $title = trim((string) get_the_title($attachmentId));
            $label = $title !== '' && $title !== $fileName
                ? ($title . ' (' . $fileName . ', #' . $attachmentId . ')')
                : ($fileName . ' (#' . $attachmentId . ')');

            self::$entriesByHash[$hash][] = [
                'key' => 'attachment:' . $attachmentId,
                'kind' => 'attachment',
                'label' => $label,
                'url' => (string) get_edit_post_link($attachmentId, 'raw'),
                'attachment_id' => (string) $attachmentId,
            ];
        }
    }

    private function indexSimpleFileListEntries(): void {
        $entries = (new SimpleFileListSupport())->getSimpleFileListEntries();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $filePath = isset($entry['file_path']) ? wp_normalize_path((string) $entry['file_path']) : '';
            if ($filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $hash = hash_file('sha256', $filePath);
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $label = trim((string) ($entry['display_name'] ?? ''));
            if ($label === '') {
                $label = basename($filePath);
            }

            self::$entriesByHash[$hash][] = [
                'key' => 'file:' . $filePath,
                'kind' => 'file',
                'label' => $label,
                'url' => '',
                'attachment_id' => '0',
            ];
        }
    }
}
