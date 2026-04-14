<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles optional OCR extraction for uploads-library images.
 */
class ImageOcrService {
    public const OPTION_OCR_ALL_UPLOAD_IMAGES = 'geweb_aisearch_ocr_all_upload_images';
    public const META_ATTACHMENT_IMAGE_PROCESSING_MODE = 'geweb_aisearch_attachment_image_processing_mode';
    public const MODE_NONE = 'none';
    public const MODE_OCR = 'ocr';
    public const MODE_DESCRIBE = 'describe';
    private const META_ATTACHMENT_OCR_TEXT = 'geweb_aisearch_attachment_ocr_text';
    private const META_ATTACHMENT_OCR_HASH = 'geweb_aisearch_attachment_ocr_hash';
    private const META_ATTACHMENT_DESCRIPTION_TEXT = 'geweb_aisearch_attachment_description_text';
    private const META_ATTACHMENT_DESCRIPTION_HASH = 'geweb_aisearch_attachment_description_hash';

    public function shouldOcrAllUploadsImages(): bool {
        return UserScope::getGroupScopedOption(self::OPTION_OCR_ALL_UPLOAD_IMAGES, '0') === '1';
    }

    public function getAttachmentImageProcessingMode(int $attachmentId): string {
        $mode = (string) get_post_meta($attachmentId, self::META_ATTACHMENT_IMAGE_PROCESSING_MODE, true);
        if (!in_array($mode, [self::MODE_NONE, self::MODE_OCR, self::MODE_DESCRIBE], true)) {
            return self::MODE_NONE;
        }

        return $mode;
    }

    public function setAttachmentImageProcessingMode(int $attachmentId, string $mode): void {
        $normalizedMode = in_array($mode, [self::MODE_OCR, self::MODE_DESCRIBE], true) ? $mode : self::MODE_NONE;
        if ($normalizedMode === self::MODE_NONE) {
            delete_post_meta($attachmentId, self::META_ATTACHMENT_IMAGE_PROCESSING_MODE);
            return;
        }

        update_post_meta($attachmentId, self::META_ATTACHMENT_IMAGE_PROCESSING_MODE, $normalizedMode);
    }

    public function isOcrEligibleAttachment(int $attachmentId): bool {
        return $attachmentId > 0 && wp_attachment_is_image($attachmentId);
    }

    public function buildAttachmentMarkdown(int $attachmentId): ?string {
        if (!$this->isOcrEligibleAttachment($attachmentId)) {
            return null;
        }

        $mode = $this->getEffectiveAttachmentImageProcessingMode($attachmentId);
        if ($mode === self::MODE_NONE) {
            return null;
        }

        $processedText = $this->getOrCreateAttachmentProcessedText($attachmentId, $mode);
        if ($processedText === '') {
            return null;
        }

        $title = get_the_title($attachmentId);
        $title = is_string($title) && trim($title) !== '' ? $title : basename((string) get_attached_file($attachmentId));
        $url = wp_get_attachment_url($attachmentId);
        if (!is_string($url)) {
            $url = '';
        }

        $frontmatter = "---\n";
        $frontmatter .= "url: {$url}\n";
        $frontmatter .= "title: {$title}\n";
        $frontmatter .= "post_id: {$attachmentId}\n";
        $frontmatter .= "image_id: {$attachmentId}\n";
        $frontmatter .= "---\n\n";
        $frontmatter .= "# {$title}\n\n";
        $frontmatter .= ($mode === self::MODE_DESCRIBE ? "Image description:\n\n" : "OCR text extracted from image:\n\n");
        $frontmatter .= trim($processedText) . "\n";

        return $frontmatter;
    }

    public function replaceMarkedUploadsImagesWithOcrText(string $content): string {
        return preg_replace_callback(
            '/<img\b[^>]*src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i',
            function (array $matches): string {
                $url = isset($matches[2]) ? trim((string) $matches[2]) : '';
                if ($url === '' || stripos($url, 'data:image/') === 0) {
                    return (string) ($matches[0] ?? '');
                }

                $attachmentId = $this->resolveAttachmentIdFromUrl($url);
                $mode = $attachmentId > 0 ? $this->getEffectiveAttachmentImageProcessingMode($attachmentId) : self::MODE_NONE;
                if ($attachmentId <= 0 || $mode === self::MODE_NONE) {
                    return (string) ($matches[0] ?? '');
                }

                $processedText = $this->getOrCreateAttachmentProcessedText($attachmentId, $mode);
                if ($processedText === '') {
                    return (string) ($matches[0] ?? '');
                }

                return $this->buildProcessedImageReplacementHtml($attachmentId, $processedText, $mode);
            },
            $content
        ) ?? $content;
    }

    private function getEffectiveAttachmentImageProcessingMode(int $attachmentId): string {
        $mode = $this->getAttachmentImageProcessingMode($attachmentId);
        if ($mode !== self::MODE_NONE) {
            return $mode;
        }

        return $this->shouldOcrAllUploadsImages() ? self::MODE_OCR : self::MODE_NONE;
    }

    private function getOrCreateAttachmentProcessedText(int $attachmentId, string $mode): string {
        $filePath = get_attached_file($attachmentId);
        $filePath = is_string($filePath) ? $filePath : '';
        if ($filePath === '' || !is_readable($filePath)) {
            return '';
        }

        $fileHash = hash_file('sha256', $filePath);
        if (!is_string($fileHash) || $fileHash === '') {
            return '';
        }

        $textMetaKey = $mode === self::MODE_DESCRIBE ? self::META_ATTACHMENT_DESCRIPTION_TEXT : self::META_ATTACHMENT_OCR_TEXT;
        $hashMetaKey = $mode === self::MODE_DESCRIBE ? self::META_ATTACHMENT_DESCRIPTION_HASH : self::META_ATTACHMENT_OCR_HASH;
        $storedHash = (string) get_post_meta($attachmentId, $hashMetaKey, true);
        $storedText = (string) get_post_meta($attachmentId, $textMetaKey, true);
        if ($storedHash === $fileHash && trim($storedText) !== '') {
            return $storedText;
        }

        $mimeType = (new DocumentStore())->resolveMimeType($filePath);
        if ($mimeType === '') {
            $mimeType = get_post_mime_type($attachmentId);
            $mimeType = is_string($mimeType) ? trim($mimeType) : '';
        }
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            return '';
        }

        try {
            $provider = ProviderFactory::make();
            $processedText = $mode === self::MODE_DESCRIBE
                ? trim($provider->describeImage($filePath, $mimeType))
                : trim($provider->extractImageText($filePath, $mimeType));
        } catch (\Exception $e) {
            error_log('geweb-ai-search: image processing failed for attachment ' . $attachmentId . ': ' . $e->getMessage());
            return '';
        }

        if ($processedText === '') {
            return '';
        }

        update_post_meta($attachmentId, $textMetaKey, $processedText);
        update_post_meta($attachmentId, $hashMetaKey, $fileHash);
        return $processedText;
    }

    private function resolveAttachmentIdFromUrl(string $url): int {
        $attachmentId = attachment_url_to_postid($url);
        if ($attachmentId > 0) {
            return $attachmentId;
        }

        $normalizedUrl = preg_replace('/-\d+x\d+(?=\.[^.]+$)/', '', $url);
        if (!is_string($normalizedUrl) || $normalizedUrl === $url) {
            return 0;
        }

        return (int) attachment_url_to_postid($normalizedUrl);
    }

    private function buildProcessedImageReplacementHtml(int $attachmentId, string $processedText, string $mode): string {
        $label = basename((string) get_attached_file($attachmentId));
        $escapedLabel = esc_html($label !== '' ? $label : ('image-' . $attachmentId));
        $escapedText = nl2br(esc_html($processedText));
        $prefix = $mode === self::MODE_DESCRIBE ? 'Image description for ' : 'OCR text from image ';

        return '<div class="geweb-ai-ocr-extract">' .
            '<p>' . $prefix . $escapedLabel . ':</p>' .
            '<blockquote>' . $escapedText . '</blockquote>' .
            '</div>';
    }
}
