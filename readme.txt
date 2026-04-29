=== Workspace AI Search ===
Contributors: grmt
Tags: search, ai, gemini, artificial intelligence, semantic search
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.1.4.32
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: geweb-ai-search

Keep native WordPress search results, and add optional AI answers powered by Google Gemini.

== Description ==

Live demo: https://aisearch.mygeweb.com/

Workspace AI Search adds an AI layer to your existing WordPress search powered by Google Gemini AI. Visitors can keep using the standard WordPress search results page from your theme, including excerpts and surrounding context, and optionally continue their search with AI for a direct, contextual answer with source links.

Workspace AI Search is a spinoff of geweb, expanded into a broader AI search workspace with chats, prompts, document handling, and source-grounded answers.

= Key Features =

* **AI-Powered Answers** — Uses Google Gemini File Search to find relevant content and generate natural-language answers
* **Conversation History** — Users can ask follow-up questions; the context is maintained across the session
* **Source Attribution** — Every AI answer includes links to the pages it was based on
* **Instant Autocomplete** — Traditional keyword search with live suggestions while typing
* **Automatic Indexing** — Posts are automatically uploaded to Gemini when published or updated
* **Bulk Library Generation** — Index all existing content with one click and a live progress indicator
* **Multiple AI Models** — Choose between Gemini 2.5 Flash, 2.5 Pro, and Gemini 3 models
* **Multiple Post Types** — Index any public post type: posts, pages, or custom post types
* **Secure API Key Storage** — API key is encrypted with libsodium before being stored in the database

= How It Works =

1. The plugin converts your WordPress posts to Markdown format (with URL and title in frontmatter)
2. Each document is uploaded to a Google Gemini File Search Store
3. When a user submits a search query, Gemini searches the indexed documents and generates an answer
4. Visitors can optionally open the AI assistant from the search form and get an answer with source links

= Third-Party Services =

This plugin connects to the **Google Gemini API** to index your content and answer user queries.

* API endpoint: https://generativelanguage.googleapis.com/
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

**Data sent to Google:**

* Your post content (title and body), converted to Markdown, is uploaded to Gemini for indexing
* User search queries are sent to Gemini to generate answers

By using this plugin you agree to Google's Terms of Service and Privacy Policy. You are responsible for the content you index.

= Requirements =

* PHP 7.2 or higher (libsodium is bundled with PHP 7.2+)
* WordPress 6.0 or higher
* Google Gemini API key (free tier available at https://aistudio.google.com/app/apikey)

== Installation ==

= Automatic Installation =

1. Go to **Plugins → Add New**
2. Search for "Workspace AI Search"
3. Click **Install Now**, then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Activate the plugin

= Configuration =

1. Go to **Settings → Workspace AI Search**
2. Enter your Google Gemini API key — get one free at https://aistudio.google.com/app/apikey
3. Select the AI model (recommended: gemini-2.5-flash for most sites)
4. Choose which post types to index
5. Click **Save Settings** — this will create a Gemini File Search Store automatically
6. Click **Generate Library** to index all existing published content
7. Your visitors can now use AI-powered search on your site

== Frequently Asked Questions ==

= Is it free? =

The plugin itself is free and open source (GPLv2). The Google Gemini API has a generous free tier — check current limits and pricing at https://ai.google.dev/pricing.

= What content is sent to Google? =

The title and body of your published posts and pages are converted to Markdown and uploaded to Google Gemini for indexing. User search queries are also sent to Gemini to generate answers. No personal user data is collected or sent.

= Does it work with WooCommerce products? =

Yes. Select "Products" in the post types settings and regenerate the library. Customers can then ask questions about your products and get AI-generated answers.

= Does it work with custom post types? =

Yes, any public post type registered in WordPress can be selected for indexing.

= What happens when I update a post? =

The plugin automatically re-indexes the post when it is saved as published. If the post is unpublished or deleted, the corresponding document is removed from Gemini.

= What happens if the API quota is exceeded? =

The AI search will return an error. The standard autocomplete search will continue to work normally since it uses WordPress's built-in search.

= Which Gemini models are supported? =

Currently: gemini-2.5-flash, gemini-2.5-pro, gemini-3-flash-preview, gemini-3.1-pro-preview. Gemini 3 models support structured JSON responses with source attribution. Gemini 2.5 models return plain text answers.

== Customization ==

= How do I customize the AI behavior? =

You can modify the AI system instruction and available models:

`
// Customize AI system instruction
add_filter('geweb_aisearch_gemini_system_instruction', function($instruction) {
    return $instruction . "\nAlways respond in a friendly, conversational tone.";
});

// Limit available models in settings
add_filter('geweb_aisearch_gemini_models', function($models) {
    return ['gemini-2.5-flash', 'gemini-2.5-pro'];
});
`

Available filters:
* `geweb_aisearch_gemini_system_instruction` - Modify AI prompt/behavior
* `geweb_aisearch_gemini_models` - Customize available model list in settings

= How do I translate the interface texts? =

You can customize all search interface labels using filters in your theme's functions.php:

`
// Customize search placeholder
add_filter('geweb_aisearch_search_placeholder', function($text) {
    return 'What would you like to know?';
});

// Customize "Ask AI" button text
add_filter('geweb_aisearch_ask_ai_button_text', function($text) {
    return 'Ask Pythia';
});

// Customize AI modal title
add_filter('geweb_aisearch_ai_modal_title', function($text) {
    return 'Oracle Pythia';
});

// Customize AI textarea placeholder
add_filter('geweb_aisearch_ai_textarea_placeholder', function($text) {
    return 'Write your detailed question to Pythia';
});
`

Available filters:
* `geweb_aisearch_search_placeholder` - Main search input placeholder
* `geweb_aisearch_ask_ai_button_text` - "Ask AI" button label
* `geweb_aisearch_ai_modal_title` - AI chat modal header
* `geweb_aisearch_ai_textarea_placeholder` - AI question textarea placeholder

== Changelog ==

= 2.1.4.31 =
* Added: Persistent Markdown cache storage, inspection modal, and shared Markdown renderer
* Improved: HTML-to-Markdown conversion for WordPress tables, headings, anchors, and source reconstruction
* Added: OCR/describe support for selected media-library images used during indexing
* Improved: Gemini upload logging, model administration, and storage estimation tooling
* Improved: Admin and frontend workspace rendering, status feedback, and source-context display

= 2.1.4 =
* Fixed: Nonce compatibility with page caching plugins

= 2.1.4 =
* Fixed: Nonce compatibility with page caching plugins

= 2.1.3 =
* Improved: Modal overlay background opacity
* Added: Filters for customizing interface text labels
* Updated: Model gemini-3-pro-preview → gemini-3.1-pro-preview

= 2.1.2 =
* Improved: AI response display and formatting
* Changed: Restored native WordPress search results flow and made AI search optional from the search form

= 2.1.1 =
* Fixed: Changed method visibility from private to public for better extensibility

= 2.1.0 =
* Added: AI Indexed status column in post list for enabled post types
* Added: Re-upload button to manually re-index individual posts

= 2.0.0 =
* Complete rewrite with modern architecture
* Added PSR-4 namespace support (Geweb\AISearch)
* Replaced cURL calls with WordPress HTTP API (wp_remote_request)
* Added support for Gemini 3 models with structured JSON responses
* API key is now encrypted with libsodium before storage
* Improved document sync: auto-upload on save, auto-delete on unpublish/delete
* Bulk library generation with pagination and live progress indicator
* Conversation history support in AI chat modal
* Source attribution with links to indexed pages
* Improved error handling and logging

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Major rewrite. After upgrading, please go to Settings → Workspace AI Search and click "Generate Library" to re-index your content. Your API key and settings will be preserved.

== Third-Party Libraries ==

This plugin bundles the following open-source library:

**league/html-to-markdown**
* Version: 5.x
* Author: The League of Extraordinary Packages
* License: MIT License
* Repository: https://github.com/thephpleague/html-to-markdown
* Purpose: Converts WordPress post HTML content to Markdown for AI indexing

== Support ==

* GitHub: https://github.com/mygeweb/geweb-ai-search
