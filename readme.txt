=== Geweb AI Search ===
Contributors: gavrilovweb
Tags: search, ai, gemini, artificial intelligence, semantic search
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: geweb-ai-search

AI-powered search for WordPress using Google Gemini. Smart answers, source links, and instant autocomplete — all in one modal.

== Description ==

Live demo: https://aisearch.mygeweb.com/

Geweb AI Search transforms your WordPress search into an intelligent assistant powered by Google Gemini AI. Instead of returning a plain list of matching posts, it understands the user's question and provides a direct, contextual answer — along with links to the source pages.

The plugin intercepts the standard WordPress search form and opens a modal with two modes: instant autocomplete suggestions (via WP_Query) and a full AI chat powered by Google Gemini File Search.

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
4. The answer is displayed in a chat modal along with source links

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
2. Search for "Geweb AI Search"
3. Click **Install Now**, then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Activate the plugin

= Configuration =

1. Go to **Settings → Geweb AI Search**
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

= Can I customize the AI prompt? =

Yes. Use the `geweb_aisearch_gemini_system_instruction` filter to modify the system instruction sent to Gemini.

= Can I customize the list of available models? =

Yes. Use the `geweb_aisearch_gemini_models` filter to add or remove models from the selector.

= Which Gemini models are supported? =

Currently: gemini-2.5-flash, gemini-2.5-pro, gemini-3-flash-preview, gemini-3-pro-preview. Gemini 3 models support structured JSON responses with source attribution. Gemini 2.5 models return plain text answers.

== Changelog ==

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
Major rewrite. After upgrading, please go to Settings → Geweb AI Search and click "Generate Library" to re-index your content. Your API key and settings will be preserved.

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