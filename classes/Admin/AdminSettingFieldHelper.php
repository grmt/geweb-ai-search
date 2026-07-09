<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminSettingFieldHelper {
    public function numberInputClass($value, $default, string $baseClass = 'small-text'): string {
        return $this->isDefaultValue($value, $default)
            ? $baseClass
            : $baseClass . ' geweb-ai-setting-nondefault';
    }

    /**
     * @param mixed $value
     * @param mixed $default
     * @return array<string,mixed>
     */
    public function numberFieldConfig($value, $default, string $baseClass = 'small-text'): array {
        return [
            'value' => is_numeric($value) ? (string) (int) $value : '',
            'class' => $this->numberInputClass($value, $default, $baseClass),
            'is_default' => $this->isDefaultValue($value, $default),
            'default_label' => (string) $default,
        ];
    }

    public function isDefaultValue($value, $default): bool {
        if (is_int($default) || is_float($default)) {
            return (string) $value === (string) $default;
        }

        return $value === $default;
    }

}
