# Geweb AI Search

**AI-powered search for WordPress using Google Gemini. Smart answers, source links, and instant autocomplete — all in one modal.**

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777BB4?logo=php)
![License](https://img.shields.io/badge/License-GPLv2-green)
![Version](https://img.shields.io/badge/Version-2.0.0-orange)

---

Geweb AI Search transforms your WordPress search into an intelligent assistant powered by Google Gemini AI. Instead of returning a plain list of matching posts, it understands the user's question and provides a direct, contextual answer — along with links to the source pages.

The plugin intercepts the standard WordPress search form and opens a modal with two modes: instant autocomplete suggestions (via WP_Query) and a full AI chat powered by Google Gemini File Search.

**[Official WordPress directory](https://wordpress.org/plugins/geweb-ai-search/)**

**[Live Demo](https://aisearch.mygeweb.com/)**

## Features

- **AI-Powered Answers** — Uses Google Gemini File Search to find relevant content and generate natural-language answers
- **Conversation History** — Users can ask follow-up questions; the context is maintained across the session
- **Source Attribution** — Every AI answer includes links to the pages it was based on
- **Instant Autocomplete** — Traditional keyword search with live suggestions while typing
- **Automatic Indexing** — Posts are automatically uploaded to Gemini when published or updated
- **Bulk Library Generation** — Index all existing content with one click and a live progress indicator
- **Multiple AI Models** — Choose between Gemini 2.5 Flash, 2.5 Pro, and Gemini 3 models
- **Multiple Post Types** — Index any public post type: posts, pages, or custom post types
- **Secure API Key Storage** — API key is encrypted with libsodium before being stored in the database

## How It Works

1. The plugin converts your WordPress posts to Markdown format (with URL and title in frontmatter)
2. Each document is uploaded to a Google Gemini File Search Store
3. When a user submits a search query, Gemini searches the indexed documents and generates an answer
4. The answer is displayed in a chat modal along with source links

## Requirements

- PHP 7.2 or higher (libsodium is bundled with PHP 7.2+)
- WordPress 6.0 or higher
- Google Gemini API key — free at [aistudio.google.com](https://aistudio.google.com/app/apikey)

## Installation

### From WordPress.org

1. Go to **Plugins → Add New**
2. Search for "Geweb AI Search"
3. Click **Install Now**, then **Activate**

### Manual

1. Download the ZIP from the [Official WordPress directory](https://wordpress.org/plugins/geweb-ai-search/)
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP and click **Install Now**
4. Activate the plugin

## Configuration

1. Go to **Settings → Geweb AI Search**
2. Enter your Google Gemini API key
3. Select the AI model (recommended: `gemini-2.5-flash`)
4. Choose which post types to index
5. Click **Save Settings** — a Gemini File Search Store will be created automatically
6. Click **Generate Library** to index all existing published content

## Filters

Customize the plugin behaviour with WordPress filters:

```php
// Modify the AI system instruction
add_filter('geweb_aisearch_gemini_system_instruction', function($instruction) {
    return $instruction . "\nAlways respond in French.";
});

// Add or remove available models
add_filter('geweb_aisearch_gemini_models', function($models) {
    return ['gemini-2.5-flash', 'gemini-2.5-pro'];
});
```

## Third-Party Services

This plugin connects to the **Google Gemini API** to index your content and answer user queries.

- API endpoint: https://generativelanguage.googleapis.com/
- [Terms of Service](https://ai.google.dev/gemini-api/terms)
- [Privacy Policy](https://policies.google.com/privacy)

**Data sent to Google:**
- Your post content (title and body), converted to Markdown, is uploaded to Gemini for indexing
- User search queries are sent to Gemini to generate answers

## Third-Party Libraries

This plugin bundles [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) (MIT License) — used to convert WordPress post HTML to Markdown for AI indexing.

## License

GPLv2 or later — see [LICENSE](LICENSE)
