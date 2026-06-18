<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

interface AIVisionProviderInterface {
    public function extractImageText(string $filePath, string $mimeType): string;
    public function describeImage(string $filePath, string $mimeType): string;
}
