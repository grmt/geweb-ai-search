<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Renders the plugin admin settings page from prepared context data.
 */
class AdminPageRenderer {
    /**
     * @param array<string,mixed> $context
     * @return void
     */
    public function render(array $context): void {
        extract($context, EXTR_SKIP);
        include_once __DIR__ . '/templates/admin-page.php';
    }
}
