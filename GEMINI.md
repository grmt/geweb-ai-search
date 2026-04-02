# Geweb AI Search - AI Guidelines and Context

This file provides essential context, architecture overview, and development guidelines for this repository. As an AI assistant, use this information to align your generated code, fixes, and recommendations with the project's standards.

## Project Overview

**Geweb AI Search** is a WordPress plugin that provides an AI-powered search experience using Google Gemini. It offers smart answers, source links, and instant autocomplete—all within a unified modal interface.

## Tech Stack

- **Language**: PHP (7.4+ recommended), JavaScript (Vanilla JS), CSS.
- **Platform**: WordPress.
- **External Dependencies**: Managed via Composer (e.g., `league/html-to-markdown`).

## Architecture & File Structure

- **Namespace**: `Geweb\AISearch\`
- **Autoloader**: The plugin uses a custom autoloader (or Composer's PSR-4) mapping the `Geweb\AISearch\` namespace to the `classes/` directory.
- `geweb-ai-search.php`: Main plugin file containing plugin headers, constants, and initialization.
- `classes/`: Contains all PHP classes organized into subdirectories by domain:
  - `Admin/`: Admin dashboard pages, settings, and tables.
  - `Conversation/`: Handling of AI conversations and prompt history.
  - `Core/`: Core functionalities, integrations, and wrappers.
  - `Documents/`: Document management and file processing.
  - `Frontend/`: Frontend display, modals, and user interactions.
  - `Indexing/`: Post/content indexing and attachment processing.
  - `Providers/`: AI Provider interfaces and implementations (e.g., Gemini).
- `assets/`: Contains JS, CSS, and image files for both admin and frontend.
- `libs/`: External libraries and dependencies (e.g., `md` for Markdown processing).

## Development Guidelines

1. **Coding Standards**:
   - Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
   - Ensure all output is properly escaped using functions like `esc_html()`, `esc_attr()`, `wp_kses()`, etc.
   - Ensure all input is properly sanitized.

2. **Namespace and Class Conventions**:
   - All new classes must belong to the `Geweb\AISearch\` namespace or a relevant sub-namespace (e.g., `Geweb\AISearch\Admin`).
   - File names should match the class name exactly (e.g., `AdminPageRenderer.php` for `AdminPageRenderer` class).

3. **Security**:
   - Protect all files from direct access (`defined('ABSPATH') || exit;`).
   - Use WordPress Nonces for all AJAX and form submissions.
   - Perform capability checks (e.g., `current_user_can('manage_options')`) before executing admin-level logic.

4. **Extensibility**:
   - Use WordPress actions and filters (`do_action()`, `apply_filters()`) to make the plugin extensible where appropriate.

5. **Dependencies**:
   - Do not assume third-party frameworks are available unless present in the repository or managed via Composer. Stick to Vanilla JS and Vanilla CSS.

6. **Documentation**:
   - Provide clear PHPDoc blocks for classes, methods, and properties to explain their purpose and parameters.

## Handling Modals & UI

- The search interface is built to be a responsive modal. Keep CSS lightweight and avoid conflicts with other themes by heavily scoping CSS classes (e.g., prefixing with `geweb-ai-` or similar).

## Context Usage

When asked to implement features, fix bugs, or investigate issues:

- ALWAYS check existing implementations in the `classes/` directory before writing new patterns.
- Stick to the established architectural patterns (e.g., using specific AjaxControllers for AJAX requests).
