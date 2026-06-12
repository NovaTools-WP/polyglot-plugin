# NovaTools - Polyglot

![WordPress Version](https://img.shields.io/badge/WordPress-Requires_6.0+-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-%3E%3D_8.1-blue.svg)
![License](https://img.shields.io/badge/License-GPLv2-green.svg)

**NovaTools - Polyglot** is a comprehensive multilingual add-on for NovaTools. It provides advanced language management, content and string translation, PO/MO editing, seamless WooCommerce integration, and WPML migration features to fully internationalize your WordPress website.

⚠️ **IMPORTANT:** This is an add-on plugin. **NovaTools Core is required** to be installed and active for this plugin to function properly.

## 🚀 Key Features

*   **Advanced String Translation**: Easily discover, manage, and translate hard-coded strings from themes and plugins directly in the WordPress admin area. Includes automatic string scanning and a Translation Memory.
*   **PO/MO File Editing & Management**: Powerful built-in PO/MO file editor. Extract strings, parse, compile, and manage `.po` and `.mo` files without leaving your dashboard. Say goodbye to external desktop tools!
*   **Comprehensive WooCommerce Multilingual Support**: Deep integration with WooCommerce. Translate products, variations, custom fields, and WooCommerce emails seamlessly. Keep product synchronization intact across languages.
*   **WPML Migration Compatibility**: Provides an API shim (`WpmlApiShim`) to ensure smooth migration from WPML and ensure third-party plugins looking for WPML still function smoothly.
*   **Content Translation**: Translate posts, pages, custom post types, terms, and custom fields with a user-friendly interface.
*   **Flexible Language Switchers**: Add language switchers to your site via blocks, widgets, shortcodes, nav menus, or the admin bar.
*   **REST API Integration**: Complete REST API support for headless setups or advanced external integrations.

## ⚙️ System Requirements

*   **WordPress**: Version 6.0 or higher
*   **PHP**: Version 8.1 or higher
*   **NovaTools**: Must be installed and activated.
*   *(Optional but recommended)* WooCommerce: Required for WooCommerce translation features.

## 📥 Installation

1.  Ensure you have **NovaTools Core** installed and activated on your WordPress site.
2.  Download the `novatools-polyglot` plugin zip file.
3.  Go to your WordPress Admin dashboard -> **Plugins** -> **Add New** -> **Upload Plugin**.
4.  Upload the zip file and click **Install Now**.
5.  Click **Activate Plugin**.
6.  *If NovaTools Core is not active, you will see an admin notice prompting you to install/activate it.*

## 📖 Basic Usage

Once activated, you can access the NovaTools Polyglot features via the NovaTools menu in your WordPress dashboard:

*   **Languages**: Configure the languages you want to support on your website and define default locales.
*   **String Translation**: Navigate to the String Translation interface to scan themes/plugins and translate static strings.
*   **PO/MO Editor**: Use the File Translation area to discover, import, export, and manually edit `.po`/`.mo` bundle files.
*   **Language Switcher**: Use the provided Gutenberg Block, Widget, Shortcode (`[novatools_language_switcher]`), or Navigation Menu options to place language switchers on the front end.

### Content Translation
When editing posts, pages, or custom post types, you will see new options provided by NovaTools Polyglot to add translations and manage linked content across your configured languages.

## 🛒 WooCommerce Compatibility

NovaTools Polyglot includes native support for WooCommerce. Once both plugins are active, you can:
*   Translate WooCommerce Products (Simple, Variable, Grouped, etc.).
*   Translate Product Variations independently.
*   Translate WooCommerce transactional emails.
*   Override specific product data per language or keep inventory/prices synced across translations.

## 🤝 WPML Migration & API Shim

If you are migrating from WPML, or if you use plugins that specifically check for WPML functions, NovaTools Polyglot provides a compatibility layer (`WpmlApiShim.php`). It seamlessly intercepts many standard WPML hooks and functions, allowing dependent plugins to continue working without issues.

## 📄 License

This project is licensed under the GPLv2 License - see the [LICENSE](LICENSE) file for details.
