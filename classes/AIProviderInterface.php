<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Shared interface for AI provider integrations.
 */
interface AIProviderInterface {
    /**
     * @return string
     */
    public function getProviderKey(): string;

    /**
     * @return string
     */
    public function getProviderLabel(): string;

    /**
     * @return string
     */
    public function getStoreData(): string;

    /**
     * @param bool $forceRefresh
     * @return array<int,string>
     */
    public function getModels(bool $forceRefresh = false): array;

    /**
     * @return string
     */
    public function getModel(): string;

    /**
     * @param array<int,string>|null $models
     * @return string
     */
    public function getDefaultModel(?array $models = null): string;

    /**
     * @return string
     */
    public function getDefaultSystemInstruction(): string;

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getModelStatuses(): array;

    /**
     * @return array<string,mixed>
     */
    public function getConnectionStatus(): array;

    /**
     * @return array<string,mixed>
     */
    public function validateConnection(): array;

    /**
     * @return void
     */
    public function clearModelsCache(): void;

    /**
     * @param string $name
     * @return bool
     */
    public function createStore(string $name = 'WebsiteSearch'): bool;

    /**
     * @return void
     */
    public function deleteStore(): void;

    /**
     * @param string $content
     * @param int $postId
     * @return string
     */
    public function uploadDocument(string $content, int $postId): string;

    /**
     * @param string $filePath
     * @param string $displayName
     * @param string $mimeType
     * @return string
     */
    public function uploadLocalFile(string $filePath, string $displayName, string $mimeType): string;

    /**
     * @param string $documentName
     * @return void
     */
    public function deleteDocument(string $documentName): void;

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    public function search(array $messages, ?string $model = null): array;
}
