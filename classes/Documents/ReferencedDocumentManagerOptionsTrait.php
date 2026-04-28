<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait ReferencedDocumentManagerOptionsTrait {
    public function getReferencedDocumentSelectionTargets(): array {
        $stored = UserScope::getGroupScopedOption('geweb_aisearch_referenced_document_selection_targets', []);
        if (!is_array($stored)) { return []; }
        $targets = [];
        foreach ($stored as $fileHash => $target) {
            if (is_string($fileHash) && $fileHash !== '') { $targets[$fileHash] = (bool) $target; }
        }
        return $targets;
    }

    public function saveReferencedDocumentSelectionTargets(array $targets): void {
        $normalized = [];
        foreach ($targets as $fileHash => $target) {
            if (is_string($fileHash) && $fileHash !== '') { $normalized[sanitize_text_field($fileHash)] = (bool) $target; }
        }
        UserScope::updateGroupScopedOption('geweb_aisearch_referenced_document_selection_targets', $normalized, false);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function saveReferencedDocumentSelectionTarget(string $fileHash, bool $include): void {
        $targets = $this->getReferencedDocumentSelectionTargets();
        $targets[sanitize_text_field($fileHash)] = $include;
        UserScope::updateGroupScopedOption('geweb_aisearch_referenced_document_selection_targets', $targets, false);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function getReferencedDocumentImageProcessingModes(): array {
        $stored = UserScope::getGroupScopedOption('geweb_aisearch_referenced_document_image_processing_modes', []);
        if (!is_array($stored)) { return []; }
        $normalized = [];
        foreach ($stored as $fileHash => $mode) {
            if (is_string($fileHash) && $fileHash !== '') {
                $normalizedMode = in_array($mode, [ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE], true) ? $mode : ImageOcrService::MODE_NONE;
                if ($normalizedMode !== ImageOcrService::MODE_NONE) { $normalized[sanitize_text_field($fileHash)] = $normalizedMode; }
            }
        }
        return $normalized;
    }

    public function getReferencedDocumentImageProcessingMode(string $fileHash): string {
        $modes = $this->getReferencedDocumentImageProcessingModes();
        return $modes[sanitize_text_field($fileHash)] ?? ImageOcrService::MODE_NONE;
    }

    public function saveReferencedDocumentImageProcessingMode(string $fileHash, string $mode): void {
        $fileHash = sanitize_text_field($fileHash);
        if ($fileHash === '') { return; }
        $modes = $this->getReferencedDocumentImageProcessingModes();
        $normalizedMode = in_array($mode, [ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE], true) ? $mode : ImageOcrService::MODE_NONE;
        if ($normalizedMode === ImageOcrService::MODE_NONE) { unset($modes[$fileHash]); } else { $modes[$fileHash] = $normalizedMode; }
        UserScope::updateGroupScopedOption('geweb_aisearch_referenced_document_image_processing_modes', $modes, false);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function getReferencedDocumentOperationStatuses(): array {
        $stored = UserScope::getGroupScopedOption('geweb_aisearch_referenced_document_operation_statuses', []);
        return is_array($stored) ? $stored : [];
    }

    public function getReferencedDocumentOperationStatus(string $fileHash): ?array {
        $fileHash = sanitize_text_field($fileHash);
        if ($fileHash === '') { return null; }
        $statuses = $this->getReferencedDocumentOperationStatuses();
        return $statuses[$fileHash] ?? null;
    }

    public function saveReferencedDocumentOperationStatus(string $fileHash, string $status, string $error = ''): void {
        $fileHash = sanitize_text_field($fileHash);
        $status = sanitize_key($status);
        if ($fileHash === '' || $status === '') { return; }
        $statuses = $this->getReferencedDocumentOperationStatuses();
        $statuses[$fileHash] = ['status' => $status, 'error' => sanitize_text_field($error), 'updated_at' => time()];
        UserScope::updateGroupScopedOption('geweb_aisearch_referenced_document_operation_statuses', $statuses, false);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function clearReferencedDocumentOperationStatus(string $fileHash): void {
        $fileHash = sanitize_text_field($fileHash);
        if ($fileHash === '') { return; }
        $statuses = $this->getReferencedDocumentOperationStatuses();
        if (!array_key_exists($fileHash, $statuses)) { return; }
        unset($statuses[$fileHash]);
        UserScope::updateGroupScopedOption('geweb_aisearch_referenced_document_operation_statuses', $statuses, false);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }
}
