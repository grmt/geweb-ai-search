<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves Gemini prompt descriptors and built-in prompt variants.
 */
class GeminiPromptResolver {
    private string $optionCustomPrompt;
    private string $optionModelPrompts;
    private string $optionModelPromptNames;
    private string $optionModelPromptModes;
    private string $defaultSystemInstruction;
    private string $defaultSystemInstructionGemini2Appendix;
    private string $defaultSystemInstructionStructuredAppendix;
    private $getModelCallback;
    private $getScopedOptionCallback;
    private $isGemini2ModelCallback;

    /**
     * @param array<string,mixed> $options
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(array $options, array $callbacks) {
        $this->optionCustomPrompt = (string) ($options['option_custom_prompt'] ?? '');
        $this->optionModelPrompts = (string) ($options['option_model_prompts'] ?? '');
        $this->optionModelPromptNames = (string) ($options['option_model_prompt_names'] ?? '');
        $this->optionModelPromptModes = (string) ($options['option_model_prompt_modes'] ?? '');
        $this->defaultSystemInstruction = (string) ($options['default_system_instruction'] ?? '');
        $this->defaultSystemInstructionGemini2Appendix = (string) ($options['default_system_instruction_gemini2_appendix'] ?? '');
        $this->defaultSystemInstructionStructuredAppendix = (string) ($options['default_system_instruction_structured_appendix'] ?? '');
        $this->getModelCallback = isset($callbacks['get_model']) && is_callable($callbacks['get_model']) ? $callbacks['get_model'] : null;
        $this->getScopedOptionCallback = isset($callbacks['get_scoped_option']) && is_callable($callbacks['get_scoped_option']) ? $callbacks['get_scoped_option'] : null;
        $this->isGemini2ModelCallback = isset($callbacks['is_gemini2_model']) && is_callable($callbacks['is_gemini2_model']) ? $callbacks['is_gemini2_model'] : null;
    }

    public function getSystemInstruction(): string {
        $model = $this->getModel();
        $descriptor = $this->getPromptDescriptor($model);
        return apply_filters('geweb_aisearch_gemini_system_instruction', $descriptor['instruction'], $model, $descriptor);
    }

    /**
     * @return array<string,mixed>
     */
    public function getPromptDescriptor(?string $model = null, ?string $promptOverride = null): array {
        $resolvedModel = is_string($model) && $model !== '' ? $model : $this->getModel();
        $promptOverride = is_string($promptOverride) ? trim($promptOverride) : '';
        if ($promptOverride !== '') {
            $baseInstruction = $this->getBasePromptDescriptor($resolvedModel);
            $baseName = trim((string) ($baseInstruction['name'] ?? ''));
            return [
                'instruction' => $promptOverride,
                'name' => $baseName !== '' ? ('Temporary override of ' . $baseName) : 'Temporary prompt override',
                'scope' => 'temporary',
                'is_model_specific' => false,
                'is_custom' => true,
                'is_temporary' => true,
                'mode' => 'override',
                'base_name' => (string) ($baseInstruction['name'] ?? ''),
            ];
        }

        $storedModelPrompts = $this->getStoredModelPrompts();
        $storedModelPromptNames = $this->getStoredModelPromptNames();
        $storedModelPromptModes = $this->getStoredModelPromptModes();
        $baseInstruction = $this->getBasePromptDescriptor($resolvedModel);

        $modelPrompt = trim((string) ($storedModelPrompts[$resolvedModel] ?? ''));
        if ($modelPrompt !== '') {
            $mode = ($storedModelPromptModes[$resolvedModel] ?? 'append') === 'override' ? 'override' : 'append';
            $instruction = $mode === 'override'
                ? $modelPrompt
                : trim((string) ($baseInstruction['instruction'] ?? '') . "\n\n" . $modelPrompt);
            return [
                'instruction' => $instruction,
                'name' => trim((string) ($storedModelPromptNames[$resolvedModel] ?? '')) ?: ('Prompt override for ' . $resolvedModel),
                'scope' => 'model',
                'is_model_specific' => true,
                'is_custom' => true,
                'mode' => $mode,
                'base_name' => (string) ($baseInstruction['name'] ?? ''),
            ];
        }

        return $baseInstruction;
    }

    public function getDefaultSystemInstructionForModel(?string $model = null): string {
        $resolvedModel = is_string($model) && $model !== '' ? $model : $this->getModel();
        if ($this->isGemini2Model($resolvedModel)) {
            return $this->defaultSystemInstruction . $this->defaultSystemInstructionGemini2Appendix;
        }

        return $this->defaultSystemInstruction . $this->defaultSystemInstructionStructuredAppendix;
    }

    public function getDefaultSystemInstruction(): string {
        return $this->getDefaultSystemInstructionForModel($this->getModel());
    }

    public function buildPromptPreview(string $prompt): string {
        $prompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt);
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($prompt, 0, 160, '...');
        }

        return strlen($prompt) > 160 ? substr($prompt, 0, 157) . '...' : $prompt;
    }

    /**
     * @return array<string,mixed>
     */
    private function getBasePromptDescriptor(string $resolvedModel): array {
        $customPrompt = trim((string) $this->getScopedOption($this->optionCustomPrompt, ''));
        if ($customPrompt !== '') {
            return [
                'instruction' => $customPrompt,
                'name' => trim((string) $this->getScopedOption('geweb_aisearch_custom_prompt_name', '')) ?: 'Custom prompt',
                'scope' => 'global',
                'is_model_specific' => false,
                'is_custom' => true,
                'mode' => 'base',
            ];
        }

        return [
            'instruction' => $this->getDefaultSystemInstructionForModel($resolvedModel),
            'name' => $this->isGemini2Model($resolvedModel) ? 'Built-in Gemini 2.x prompt' : 'Built-in structured-model prompt',
            'scope' => $this->isGemini2Model($resolvedModel) ? 'default-gemini-2' : 'default-structured',
            'is_model_specific' => false,
            'is_custom' => false,
            'mode' => 'base',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPrompts(): array {
        $value = $this->getScopedOption($this->optionModelPrompts, []);
        if (!is_array($value)) {
            return [];
        }

        $prompts = array_map('strval', $value);
        return array_filter($prompts, static function (string $item): bool {
            return trim($item) !== '';
        });
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPromptNames(): array {
        $value = $this->getScopedOption($this->optionModelPromptNames, []);
        return is_array($value) ? array_map('strval', $value) : [];
    }

    /**
     * @return array<string,string>
     */
    private function getStoredModelPromptModes(): array {
        $value = $this->getScopedOption($this->optionModelPromptModes, []);
        return is_array($value) ? array_map('strval', $value) : [];
    }

    private function getModel(): string {
        return $this->getModelCallback !== null ? (string) call_user_func($this->getModelCallback) : '';
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getScopedOption(string $optionName, $default = false) {
        return $this->getScopedOptionCallback !== null ? call_user_func($this->getScopedOptionCallback, $optionName, $default) : $default;
    }

    private function isGemini2Model(string $model): bool {
        return $this->isGemini2ModelCallback !== null ? (bool) call_user_func($this->isGemini2ModelCallback, $model) : false;
    }
}
