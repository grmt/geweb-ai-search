<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Shared interface for AI provider integrations.
 */
interface AIProviderInterface extends AIVisionProviderInterface, AIStoreProviderInterface {
    /**
     * @return string
     */
    public function getProviderKey(): string;

    /**
     * @return string
     */
    public function getProviderLabel(): string;

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
     * @param string|null $model
     * @return string
     */
    public function getDefaultSystemInstructionForModel(?string $model = null): string;

    /**
     * @param string|null $model
     * @param string|null $promptOverride
     * @return array<string,string>
     */
    public function getPromptDescriptor(?string $model = null, ?string $promptOverride = null): array;

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
     * @param string $model
     * @return array<string,mixed>
     */
    public function testModel(string $model): array;

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
    public function search(array $messages, ?string $model = null, ?string $promptOverride = null, array $excludedSources = []): array;
}
