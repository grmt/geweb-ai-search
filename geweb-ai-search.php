<?php
/*
Plugin Name: Geweb AI Search
Plugin URI: https://aisearch.mygeweb.com/
Description: AI-powered search for WordPress using Google Gemini. Smart answers, source links, and instant autocomplete — all in one modal.
Version: 2.1.4.22
Author: gavrilovweb
Author URI: https://www.linkedin.com/in/evgengavrilov
License: GPL2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: geweb-ai-search
Domain Path: /languages
*/

defined('ABSPATH') || exit;

// Plugin version
if (!defined('GEWEB_AI_SEARCH_VERSION')) {
    define('GEWEB_AI_SEARCH_VERSION', '2.1.4.22');
}

// Plugin directory path
if (!defined('GEWEB_AI_SEARCH_PATH')) {
    define('GEWEB_AI_SEARCH_PATH', plugin_dir_path(__FILE__));
}

// Plugin directory URL
if (!defined('GEWEB_AI_SEARCH_URL')) {
    define('GEWEB_AI_SEARCH_URL', plugin_dir_url(__FILE__));
}

// Load HTML to Markdown library
require_once GEWEB_AI_SEARCH_PATH . 'libs/md/vendor/autoload.php';

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'Geweb\\AISearch\\';
    $baseDir = GEWEB_AI_SEARCH_PATH . 'classes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize plugin
add_action('plugins_loaded', function () {
    static $html2md = null;
    static $plugin = null;

    if ($html2md === null) {
        $html2md = new \Geweb\AISearch\HTML2MD();
    }

    if ($plugin === null) {
        $plugin = new \Geweb\AISearch\WP();
    }
});

// Plugin activation hook
register_activation_hook(__FILE__, function () {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Geweb AI Search requires PHP 7.2 or higher (for Sodium support). Your current version is ' . PHP_VERSION);
    }

    $documentStoreFile = GEWEB_AI_SEARCH_PATH . 'classes/DocumentStore.php';
    if (!class_exists('\\Geweb\\AISearch\\DocumentStore') && file_exists($documentStoreFile)) {
        require_once $documentStoreFile;
    }

    if (!class_exists('\\Geweb\\AISearch\\DocumentStore')) {
        wp_die('Geweb AI Search could not load the DocumentStore class during activation.');
    }

    // Create custom database tables
    \Geweb\AISearch\DocumentStore::install();

    if (!class_exists('\\Geweb\\AISearch\\WP')) {
        require_once GEWEB_AI_SEARCH_PATH . 'classes/WP.php';
    }

    \Geweb\AISearch\WP::ensureFrontendAiPageExists();
    \Geweb\AISearch\WP::registerFrontendAiRewrite();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
