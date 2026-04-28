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

        $instruction = $this->resolveFrontendPromptInstruction($provider, $model, $instruction);

        if ($name === '') {
            $name = $this->resolveFrontendPromptName();
        }

        return [
            'name' => $name,
            'instruction' => $instruction,
        ];
    }

    private function resolveFrontendPromptInstruction(AIProviderInterface $provider, string $model, string $instruction): string {
        if ($instruction === '' && method_exists($provider, 'getDefaultSystemInstructionForModel')) {
            $instruction = trim((string) $provider->getDefaultSystemInstructionForModel($model !== '' ? $model : null));
        }

        return $instruction !== '' ? $instruction : trim((string) $provider->getDefaultSystemInstruction());
    }

    private function resolveFrontendPromptName(): string {
        $customPrompt = trim((string) UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt', ''));
        $customPromptName = trim((string) UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt_name', ''));
        $name = __('Built-in prompt', 'geweb-ai-search');
        if ($customPrompt !== '') {
            $name = $customPromptName !== '' ? $customPromptName : __('Custom prompt', 'geweb-ai-search');
        }

        return $name;
    }

    private function supportsFrontendAiChatModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        $supportsChat = false;
        if ($normalizedModel !== '') {
            $supportsChat = in_array($normalizedModel, ['gemini-flash-latest', 'gemini-pro-latest'], true);
            if (!$supportsChat && !$this->isExcludedFrontendAiModel($normalizedModel)) {
                $supportsChat = preg_match('/^gemini-\d[a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $normalizedModel) === 1;
            }
        }

        return $supportsChat;
    }

    private function isExcludedFrontendAiModel(string $normalizedModel): bool {
        $isExcluded = false;
        foreach ([
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
            'image',
            'video',
            'live',
            'robotics',
            'deep-research',
            'computer-use',
        ] as $fragment) {
            if (strpos($normalizedModel, $fragment) !== false) {
                $isExcluded = true;
                break;
            }
        }

        return $isExcluded;
    }
}
