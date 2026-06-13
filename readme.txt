=== ZWH Multilingual Translate ===
Contributors: ziv
Tags: multilingual, translation, language, ai, deepl
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage multilingual content with AI/API auto-translation, manual editing, a frontend language switcher, and clean language-prefixed URLs.

== Description ==

ZWH Multilingual Translate lets you run a fully multilingual WordPress site. Each language version is a standard WordPress post. Translations are linked via a shared group ID so the language switcher always resolves to the correct post.

**Core features:**

* Configure any combination of 39 languages in Settings
* Each translation is its own WordPress post (draft and publish independently)
* Auto-translate via DeepL, Google Translate, OpenAI (GPT-4o), Claude, or Gemini
* "Duplicate" option copies content as-is for manual translation
* Language management meta box on every post/page editor
* Frontend language switcher — shortcode [zwh_language_switcher] and widget
* Clean URLs: /fr/post-slug, /de/post-slug for non-default languages; default language keeps /post-slug
* Automatic hreflang tags in head for SEO
* RTL support for Arabic, Hebrew, and other right-to-left languages
* Language column in the post list screen

== Installation ==

1. Upload the zwh-ml-translate folder to /wp-content/plugins/.
2. Activate the plugin in Plugins > Installed Plugins.
3. Go to Settings > Multilingual and:
   a. Set your default language.
   b. Add the languages you want to translate into.
   c. Choose which post types should be translatable.
   d. Optionally configure a translation API and key.
4. Go to Settings > Permalinks and click Save Changes to flush rewrite rules.
5. Open any post or page — the Translations meta box appears in the right sidebar.

== How to translate a post ==

1. Open any post in the editor.
2. In the Translations sidebar, confirm "This post is written in" is set correctly.
3. Save. A translation group UUID is created automatically.
4. For each target language, click Duplicate (copy as-is) or AI translate (requires API key).
5. The new translation opens as a draft. Edit and publish when ready.

== Shortcode ==

[zwh_language_switcher] — place anywhere on a page.

Attributes:
* style="inline" (default) or style="dropdown"
* show="name" (default), show="code", or show="both"

== Translation APIs ==

DeepL — Best for European languages, 500k chars/month free
Google Translate — Broad language support
OpenAI GPT-4o — Excellent nuance, handles HTML well
Claude (Anthropic) — Excellent nuance, handles HTML well
Google Gemini — Good quality, free tier available

== Frequently Asked Questions ==

= After saving settings, language URLs return 404 =

Go to Settings > Permalinks and click Save Changes to flush WordPress rewrite rules.

= Can I translate custom post types? =

Yes — enable any public post type in Settings > Multilingual > Translatable post types.

= Does the default language URL change? =

No. Default language posts keep their normal WordPress URLs (/post-slug). Only translations get a language prefix (/fr/post-slug).

== Changelog ==

= 1.0.0 =
* Initial release.
* 39 supported languages.
* Auto-translate via DeepL, Google Translate, OpenAI, Claude, Gemini.
* Duplicate-and-edit workflow for manual translations.
* Language switcher shortcode and widget.
* Language-prefixed URLs with hreflang SEO tags.
* RTL support for Arabic, Hebrew, and similar languages.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
