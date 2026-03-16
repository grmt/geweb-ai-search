# Geweb AI Search

**Keep native WordPress search results, and add optional AI answers powered by Google Gemini.**

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777BB4?logo=php)
![License](https://img.shields.io/badge/License-GPLv2-green)
![Version](https://img.shields.io/badge/Version-2.1.4.1-orange)

---

Geweb AI Search adds an AI layer to your existing WordPress search powered by Google Gemini AI. Visitors can keep using the standard WordPress search results page from your theme, including excerpts and surrounding context, and optionally continue their search with AI for a direct, contextual answer with source links.

**[Official WordPress Plugin Page](https://wordpress.org/plugins/geweb-ai-search/)**  
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
4. Visitors can optionally open the AI assistant from the search form and get an answer with source links

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

1. Download the ZIP from the [Official WordPress Plugin Page](https://wordpress.org/plugins/geweb-ai-search/)
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

## Customization

### Modify AI Behavior
```php
// Customize AI system instruction
add_filter('geweb_aisearch_gemini_system_instruction', function($instruction) {
    return $instruction . "\nAlways respond in a friendly, conversational tone.";
});

// Limit available models in settings
add_filter('geweb_aisearch_gemini_models', function($models) {
    return ['gemini-2.5-flash', 'gemini-2.5-pro'];
});
```

### Translate Interface Texts
```php
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
```

### Available Filters

**AI Behavior:**
- `geweb_aisearch_gemini_system_instruction` - Modify AI prompt/behavior
- `geweb_aisearch_gemini_models` - Customize available model list in settings

**Interface Texts:**
- `geweb_aisearch_search_placeholder` - Main search input placeholder
- `geweb_aisearch_ask_ai_button_text` - "Ask AI" button label
- `geweb_aisearch_ai_modal_title` - AI chat modal header
- `geweb_aisearch_ai_textarea_placeholder` - AI question textarea placeholder

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

## Changelog

### 2.1.3
- Improved: Modal overlay background opacity
- Added: Filters for customizing interface text labels
- Updated: Model gemini-3-pro-preview → gemini-3.1-pro-preview

### 2.1.2
- Improved: AI response display and formatting

### 2.1.1
- Fixed: Changed method visibility from private to public for better extensibility

### 2.1.0
- Added: AI Indexed status column in post list for enabled post types
- Added: Re-upload button to manually re-index individual posts

### 2.0.0
- Complete rewrite with modern architecture
- PSR-4 namespace support
- WordPress HTTP API instead of cURL
- Gemini 3 models support with structured JSON responses
- API key encryption with libsodium
- Auto document sync on save/delete
- Conversation history support

## License

GPLv2 or later — see [LICENSE](LICENSE)
