<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Normalizes Gemini File Search Store document list items.
 */
class GeminiStoreDocumentNormalizer {
    /**
     * @param mixed $document
     * @return array<string,string>
     */
    public static function normalize($document): array {
        if (!is_array($document)) {
            return [];
        }

        $name = isset($document['name']) ? (string) $document['name'] : '';
        if ($name === '') {
            return [];
        }

        return [
            'name' => $name,
            'display_name' => self::pickFirstFieldValue($document, ['displayName', 'display_name']),
            'mime_type' => self::pickFirstFieldValue($document, ['mimeType', 'mime_type']),
            'size_bytes' => (string) self::pickFirstFieldValue($document, ['sizeBytes', 'size_bytes']),
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @param array<int,string> $keys
     */
    private static function pickFirstFieldValue(array $document, array $keys): string {
        foreach ($keys as $key) {
            if (!empty($document[$key])) {
                return (string) $document[$key];
            }
        }

        return '';
    }
}
