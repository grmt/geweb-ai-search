<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves frontend chat models and prompt descriptors.
 */
class FrontendAiPromptManager {
    public function getFrontendAiChatModelConfig(AIProviderInterface $provider): array {
        $models = array_values(array_filter($provider->getModels(), function ($model): bool {
            return is_string($model) && $this->supportsFrontendAiChatModel($model);
        }));
        $selectedModel = $provider->getModel();

        if ($selectedModel === '' || !in_array($selectedModel, $models, true)) {
            $selectedModel = !empty($models)
                ? $provider->getDefaultModel($models)
                : $provider->getModel();
        }

        return [
            'models' => $models,
            'selected_model' => $selectedModel,
        ];
    }

    /**
     * @param array<int,string> $models
     * @return array<string,array<string,string>>
     */
    public function getFrontendPromptDescriptors(AIProviderInterface $provider, array $models, string $selectedModel): array {
        $descriptorModels = array_values(array_unique(array_filter(array_map('strval', array_merge($models, [$selectedModel])))));
        $descriptors = [];

        foreach ($descriptorModels as $model) {
            $descriptors[$model] = $this->buildFrontendPromptDescriptor($provider, $model);
        }

        if (empty($descriptors)) {
            $descriptors[''] = $this->buildFrontendPromptDescriptor($provider, '');
        }

        return $descriptors;
    }

    /**
     * @return array<string,string>
     */
    public function buildFrontendPromptDescriptor(AIProviderInterface $provider, string $model): array {
        $instruction = '';
        $name = '';

        if (method_exists($provider, 'getPromptDescriptor')) {
            $descriptor = $provider->getPromptDescriptor($model !== '' ? $model : null);
            if (is_array($descriptor)) {
                $instruction = trim((string) ($descriptor['instruction'] ?? ''));
                $name = trim((string) ($descriptor['name'] ?? ''));
            }
        }

        if ($instruction === '' && method_exists($provider, 'getDefaultSystemInstructionForModel')) {
            $instruction = trim((string) $provider->getDefaultSystemInstructionForModel($model !== '' ? $model : null));
        }

        if ($instruction === '') {
            $instruction = trim((string) $provider->getDefaultSystemInstruction());
        }

        if ($name === '') {
            $customPrompt = trim((string) UserScope::getScopedOption('geweb_aisearch_custom_prompt', ''));
            $customPromptName = trim((string) UserScope::getScopedOption('geweb_aisearch_custom_prompt_name', ''));
            $name = __('Built-in prompt', 'geweb-ai-search');
            if ($customPrompt !== '') {
                $name = $customPromptName !== '' ? $customPromptName : __('Custom prompt', 'geweb-ai-search');
            }
        }

        return [
            'name' => $name,
            'instruction' => $instruction,
        ];
    }

    private function supportsFrontendAiChatModel(string $model): bool {
        if (strpos($model, 'preview') !== false) {
            return false;
        }

        foreach ([
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ] as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
