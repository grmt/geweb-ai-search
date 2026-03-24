<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Always remove stored secrets on uninstall.
delete_option('geweb_aisearch_encryption_key');
delete_option('geweb_aisearch_api_key_encrypted');

if (get_option('geweb_aisearch_preserve_data_on_uninstall', '0') === '1') {
    return;
}

$encryptionFile = __DIR__ . '/classes/Encryption.php';
$providerInterfaceFile = __DIR__ . '/classes/AIProviderInterface.php';
$providerFactoryFile = __DIR__ . '/classes/ProviderFactory.php';
$geminiFile = __DIR__ . '/classes/Gemini.php';
if (file_exists($encryptionFile)) {
    require_once $encryptionFile;
}
if (file_exists($providerInterfaceFile)) {
    require_once $providerInterfaceFile;
}
if (file_exists($providerFactoryFile)) {
    require_once $providerFactoryFile;
}
if (file_exists($geminiFile)) {
    require_once $geminiFile;
}

if (class_exists('\\Geweb\\AISearch\\ProviderFactory')) {
    try {
        $provider = \Geweb\AISearch\ProviderFactory::make();
        $provider->deleteStore();
    } catch (\Exception $e) {
        // Ignore remote deletion failures during uninstall cleanup.
    }
}

// Drop custom tables
global $wpdb;
$documentsTable = $wpdb->prefix . 'geweb_ai_documents';
$refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';
$wpdb->query("DROP TABLE IF EXISTS {$refsTable}");
$wpdb->query("DROP TABLE IF EXISTS {$documentsTable}");

// Delete options
delete_option('geweb_aisearch_model');
delete_option('geweb_aisearch_model_status');
delete_option('geweb_aisearch_post_types');
delete_option('geweb_aisearch_provider');
delete_option('geweb_aisearch_gemini_store');
delete_option('geweb_aisearch_custom_prompt');
delete_option('geweb_aisearch_prompt_history');
delete_option('geweb_aisearch_prompt_history_limit');
delete_option('geweb_aisearch_include_referenced_documents');
delete_option('geweb_aisearch_preserve_data_on_uninstall');

// Delete post meta
delete_post_meta_by_key('geweb_aisearch_document_name');
delete_post_meta_by_key('geweb_aisearch_exclude');
delete_post_meta_by_key('geweb_aisearch_status');
delete_post_meta_by_key('geweb_aisearch_last_indexed');
delete_post_meta_by_key('geweb_aisearch_last_error');
