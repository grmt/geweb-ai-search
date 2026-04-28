<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ReferencedDocumentGeminiUploadService {
    private const MIME_MARKDOWN = 'text/markdown';
    private const MIME_PDF = 'application/pdf';

    public function upload(AIProviderInterface $gemini, string $filePath, string $displayName, string $mimeType, string $fileHash): string {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $imageProcessingMode = (new ReferencedDocumentManager())->getReferencedDocumentImageProcessingMode($fileHash);

        if ($this->isProcessableImageOrPdf($mimeType, $imageProcessingMode)) {
            $geminiDocName = $this->tryUploadProcessedImageMarkdown($gemini, $filePath, $displayName, $mimeType, $fileHash, $imageProcessingMode);
            if ($geminiDocName !== '') {
                return $geminiDocName;
            }
        }

        if ($extension === 'xlsx') {
            return $this->uploadXlsxWithFallback($gemini, $filePath, $displayName, $mimeType, $fileHash);
        }

        error_log('geweb-ai-search: uploading referenced document to Gemini: ' . $displayName . ' (' . $mimeType . ')');
        $geminiDocName = $gemini->uploadLocalFile($filePath, $displayName, $mimeType);
        error_log('geweb-ai-search: uploaded referenced document to Gemini as ' . $geminiDocName);

        return $geminiDocName;
    }

    public function refreshImageMarkdownCache(string $fileHash, string $filePath, string $mimeType, string $mode): void {
        $markdown = $this->buildReferencedImageMarkdown($filePath, $mimeType, $mode);
        (new ReferencedDocumentMarkdownCacheStore())->saveMarkdown($fileHash, basename($filePath), $markdown);
    }

    private function isProcessableImageOrPdf(string $mimeType, string $imageProcessingMode): bool {
        return $imageProcessingMode !== ImageOcrService::MODE_NONE
            && (strpos($mimeType, 'image/') === 0 || $mimeType === self::MIME_PDF);
    }

    private function tryUploadProcessedImageMarkdown(AIProviderInterface $gemini, string $filePath, string $displayName, string $mimeType, string $fileHash, string $imageProcessingMode): string {
        $markdownPath = '';
        $cacheStore = new ReferencedDocumentMarkdownCacheStore();

        try {
            $markdown = $this->buildReferencedImageMarkdown($filePath, $mimeType, $imageProcessingMode);
            $cacheStore->saveMarkdown($fileHash, basename($filePath), $markdown);
            $markdownPath = $this->createTemporaryMarkdownFile($markdown);
            $markdownDisplayName = $this->buildMarkdownDisplayName($displayName, '/\.[^.]+$/');

            error_log('geweb-ai-search: [DOCUMENT] processing as markdown (' . $imageProcessingMode . ') before Gemini upload: ' . $displayName);
            $geminiDocName = $gemini->uploadLocalFile($markdownPath, $markdownDisplayName, self::MIME_MARKDOWN);
            error_log('geweb-ai-search: [DOCUMENT] markdown upload succeeded as ' . $geminiDocName);
            return $geminiDocName;
        } catch (\Exception $exception) {
            $cacheStore->deleteMarkdown($fileHash);
            error_log('geweb-ai-search: [DOCUMENT] processing and upload failed, falling back to direct upload: ' . $exception->getMessage());
        } finally {
            $this->deleteTemporaryMarkdownFile($markdownPath);
        }

        return '';
    }

    private function uploadXlsxWithFallback(AIProviderInterface $gemini, string $filePath, string $displayName, string $mimeType, string $fileHash): string {
        try {
            error_log('geweb-ai-search: [SPREADSHEET] direct upload starting for ' . $displayName);
            return $gemini->uploadLocalFile($filePath, $displayName, $mimeType);
        } catch (\Exception $directUploadException) {
            return $this->uploadConvertedXlsxMarkdown($gemini, $filePath, $displayName, $fileHash, $directUploadException);
        }
    }

    private function uploadConvertedXlsxMarkdown(AIProviderInterface $gemini, string $filePath, string $displayName, string $fileHash, \Exception $directUploadException): string {
        $markdownPath = '';
        $cacheStore = new ReferencedDocumentMarkdownCacheStore();

        try {
            error_log('geweb-ai-search: [SPREADSHEET] direct upload FAILED: ' . $directUploadException->getMessage() . ' - now attempting markdown conversion');
            $markdown = (new XlsxMarkdownExtractor())->extract($filePath, basename($filePath));
            $cacheStore->saveMarkdown($fileHash, basename($filePath), $markdown);
            $markdownPath = $this->createTemporaryMarkdownFile($markdown, 'geweb_ai_xlsx_');
            $markdownDisplayName = $this->buildMarkdownDisplayName($displayName, '/\.xlsx$/i');

            error_log('geweb-ai-search: [SPREADSHEET] markdown conversion successful, uploading to Gemini: ' . $displayName);
            $geminiDocName = $gemini->uploadLocalFile($markdownPath, $markdownDisplayName, self::MIME_MARKDOWN);
            error_log('geweb-ai-search: [SPREADSHEET] markdown upload succeeded as ' . $geminiDocName);
            return $geminiDocName;
        } catch (\Exception $markdownException) {
            $cacheStore->deleteMarkdown($fileHash);
            error_log('geweb-ai-search: [SPREADSHEET] markdown conversion and upload both failed - ' . $markdownException->getMessage() . ' (original direct upload error: ' . $directUploadException->getMessage() . ')');
            throw $markdownException;
        } finally {
            $this->deleteTemporaryMarkdownFile($markdownPath);
        }
    }

    private function createTemporaryMarkdownFile(string $markdown, string $prefix = 'geweb_ai_md_'): string {
        $tempPath = tempnam(sys_get_temp_dir(), $prefix);
        if (!is_string($tempPath) || $tempPath === '') {
            throw new ReferencedDocumentException('Could not create a temporary Markdown file.');
        }

        $markdownPath = $tempPath . '.md';
        @rename($tempPath, $markdownPath);
        if (file_put_contents($markdownPath, $markdown) === false) {
            @unlink($markdownPath);
            throw new ReferencedDocumentException('Could not write converted Markdown.');
        }

        return $markdownPath;
    }

    private function deleteTemporaryMarkdownFile(string $markdownPath): void {
        if ($markdownPath !== '' && file_exists($markdownPath)) {
            @unlink($markdownPath);
        }
    }

    private function buildMarkdownDisplayName(string $displayName, string $pattern): string {
        $markdownDisplayName = preg_replace($pattern, '.md', $displayName);
        return is_string($markdownDisplayName) && $markdownDisplayName !== '' ? $markdownDisplayName : ($displayName . '.md');
    }

    private function buildReferencedImageMarkdown(string $filePath, string $mimeType, string $mode): string {
        $processedText = $this->processImageDocument($filePath, $mimeType, $mode);
        $documentUrl = $this->resolveDocumentUrl($filePath);

        $frontmatter = "---\n";
        if ($documentUrl !== '') {
            $frontmatter .= "url: {$documentUrl}\n";
        }
        $frontmatter .= "title: " . basename($filePath) . "\n";
        $frontmatter .= "document_name: " . basename($filePath) . "\n";
        $frontmatter .= "---\n\n";
        $frontmatter .= '# ' . basename($filePath) . "\n\n";
        $frontmatter .= ($mode === ImageOcrService::MODE_DESCRIBE ? "Image description:\n\n" : "OCR text extracted from image:\n\n");
        $frontmatter .= $processedText . "\n";

        return $frontmatter;
    }

    private function processImageDocument(string $filePath, string $mimeType, string $mode): string {
        try {
            $provider = ProviderFactory::make();
            $processedText = $mode === ImageOcrService::MODE_DESCRIBE
                ? trim($provider->describeImage($filePath, $mimeType))
                : trim($provider->extractImageText($filePath, $mimeType));
        } catch (\Exception $e) {
            throw new ReferencedDocumentException('Image processing failed: ' . $e->getMessage(), 0, $e);
        }

        if ($processedText === '') {
            throw new ReferencedDocumentException('Image processing returned no text.');
        }

        return $processedText;
    }

    private function resolveDocumentUrl(string $filePath): string {
        $uploadUrl = wp_get_upload_dir();
        if (!is_array($uploadUrl)) {
            return '';
        }

        $baseDir = isset($uploadUrl['basedir']) ? (string) $uploadUrl['basedir'] : '';
        $baseUrl = isset($uploadUrl['baseurl']) ? (string) $uploadUrl['baseurl'] : '';
        if ($baseDir === '' || $baseUrl === '' || !str_starts_with($filePath, $baseDir)) {
            return '';
        }

        $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($baseDir))), '/');
        return trailingslashit($baseUrl) . $relativePath;
    }
}
