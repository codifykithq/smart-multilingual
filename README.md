=== Smart Multilingual ===
Contributors: alivanaei
Tags: multilingual, translation, rtl, elementor, woocommerce
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, configurable multilingual toolkit for WordPress, Gutenberg, Elementor and WooCommerce websites.

== Description ==

Smart Multilingual provides language routing, linked translations and language-aware output without forcing theme-specific layout changes.

Core output is intentionally minimal:

* Correct HTML lang and dir attributes.
* One body class per active language, for example sml-lang-fa.
* Language-prefixed URLs for secondary languages.

Typography remains opt-in. RTL render metadata is always emitted for RTL languages, while theme-specific layout helpers remain isolated. Woodmart is auto-detected so Persian direction and Elementor rendering work without changing the English layout.

Main features:

* Source language plus Persian on a fresh installation.
* Add, edit, enable or disable more languages from the dashboard.
* Linked translations for public post types and taxonomy terms.
* Language-aware menus, media metadata, strings and HTML attributes.
* Gutenberg, Elementor and WooCommerce integration.
* Complete per-language Typography Studio with font registration and target-specific controls.
* Duplicate-module mapping for Slider Revolution 6 and early Slider Revolution 7 releases.
* Optional compatibility profiles for Elementor RTL, Header & Footer Builder and Persian dates.
* Portable Site Profile with custom classes, CSS, notes and JSON import/export.
* Migration from 0.9.x without deleting existing translations or settings.

== Installation ==

1. Upload the smart-multilingual folder to /wp-content/plugins/ or install the ZIP from Plugins > Add New.
2. Activate Smart Multilingual.
3. Open Languages > Manage Languages and verify the source language and Persian.
4. Open Languages > Settings to configure menus, switcher, typography and optional compatibility profiles.
5. Visit Settings > Permalinks and save once if a host-level cache prevents automatic rewrite refresh.

== Upgrade Notice ==

= 1.0.8 =
Woodmart typography reliability update. Applies the detected language font even when a custom theme omits WordPress body/language classes, and adds deterministic HTML language metadata without affecting English pages.

= 1.0.6 =
Woodmart compatibility update. Fixes Persian font propagation through Woodmart typography variables, restores RTL direction classes on fresh installs and enables scoped Elementor RTL rendering only for Woodmart RTL pages.

= 1.0.5 =
SkyLenses/Aitechfy compatibility update. Strict language separation for post/product lists, RTL fixes for the supplied SkyLenses sections, breadcrumb icon replacement and a stable click-open language switcher.

= 1.0.4 =
Frontend Performance update. Removes source-language locale overhead, caches settings per request and skips multilingual bootstrap for missing static assets routed through WordPress.

= 1.0.3 =
Frontend Recovery update. Prevents locale-filter recursion when a fresh or partially migrated site has no persisted language registry, and repairs that registry from the dashboard without deleting data.

= 1.0.2 =
Safe Boot update. Fixes a recursive singleton/gettext bootstrap loop that could exhaust PHP workers and return HTTP 503 immediately after activation.

= 1.0.1 =
Reliability update for production sites. Activation no longer flushes rewrite rules, scans all translated posts, or enables heavy compatibility adapters automatically. Existing data is preserved.

= 1.0.0 =
Existing 0.9.x data is migrated in place. Historical layout behavior is retained only through the Legacy 0.9.x compatibility profile so it can be disabled after site-specific CSS has been moved into Site Profile.

== Frequently Asked Questions ==

= Does the plugin force RTL layout changes? =

Core emits the correct RTL language metadata on RTL pages. General layout helpers stay opt-in; Woodmart receives a narrowly scoped automatic adapter so Persian Elementor/Woodmart content renders RTL while English/LTR pages remain unchanged.

= Can I keep Elementor typography for the source language? =

Yes. Typography rules emit no CSS until an individual language target is enabled. The source language can remain entirely controlled by Elementor or the theme.

= How are Slider Revolution modules translated? =

For Slider Revolution versions without native multilingual integration, duplicate the source module, translate the duplicate and map its alias under Languages > Sliders.

= What does Site Profile contain? =

It stores site-level notes, extra body classes, custom CSS and portable plugin configuration. It never exports posts, products, users or media files.

= Is uninstall destructive? =

Not by default. Plugin data is removed only when Delete plugin data on uninstall is enabled before uninstalling.

== Changelog ==

= 1.0.8 =

* Fixed Persian typography on customized Woodmart layouts that omit `body_class()` and/or `language_attributes()`.
* Added a request-local typography layer keyed to SML's server-side detected language, so `/fa/` can use the configured Persian font without depending on theme body classes.
* Added a tiny frontend metadata bootstrap that restores `lang`, `dir`, `data-sml-lang` and SML language classes on `<html>`/`<body>` when the theme does not output them.
* Made `language_attributes` insert missing `lang` and `dir` attributes instead of only replacing attributes that already exist.
* Expanded scoped typography selectors to match SML metadata on `<html>` as a fallback.
* Removed the duplicate typography compiler from the enqueued inline stylesheet; uploaded font faces are now emitted once in the late typography layer instead of twice.
* Preserved the source-language/English layout and only emits active typography rules configured for the detected request language.

= 1.0.6 =

* Added automatic Woodmart/child-theme detection and a Woodmart-only RTL compatibility stylesheet.
* Made language direction body classes core metadata instead of hiding them behind the Legacy 0.9.x profile.
* Enabled Elementor RTL render helpers automatically on Woodmart without affecting LTR/English pages.
* Made an enabled Body/general typography family propagate to normal text descendants while preserving target-specific font sizes and weights.
* Mapped the active language font family to Woodmart typography CSS variables so theme-defined titles, widgets and entities use the selected Persian font.
* Preserved English/LTR layout and typography when Persian rules are active.

= 1.0.5 =

* Made frontend post and WooCommerce product lists strictly language-specific, including Elementor/Aitechfy secondary loops.
* Kept automatic query filtering limited to `post` and `product` so Elementor templates, media and internal framework queries are not pulled into the language meta query.
* Added a SkyLenses-only compatibility stylesheet for Hero 3 RTL, mirrored Why Choose Us background, gallery title sizing, Aitechfy heading alignment and service thumbnail placement.
* Replaced SkyLenses breadcrumb separators with `ic11.svg`, rotated -90 degrees and vertically centered them with breadcrumb text.
* Added click/keyboard handling for the language dropdown, removed the hover dead-zone and increased dropdown padding.
* Added SkyLenses-specific WooCommerce breadcrumb delimiter output without affecting other plugin installations.

= 1.0.4 =

* Stopped registering the locale filter on the source language and in wp-admin.
* Made secondary-language locale resolution constant-time after early URL detection.
* Cached normalized plugin settings for the duration of each request.
* Skipped multilingual bootstrap for static asset paths incorrectly routed through WordPress by hosting rewrite rules.
* Reduced repeated language registry and default-settings construction during Elementor and theme rendering.
* Preserved all languages, translations, typography, Slider mappings and Site Profile data.

= 1.0.3 =

* Fixed an infinite locale-filter recursion that could return HTTP 503 on the public site and Elementor preview while wp-admin remained available.
* Made first-run language discovery read the unfiltered WordPress site locale.
* Skipped language-registry resolution for source-language and dashboard locale requests.
* Added self-healing for a missing or empty language registry even when an earlier database version marker already exists.
* Preserved all existing settings, translations, typography rules, Slider mappings and Site Profile data.

= 1.0.2 =

* Fixed the recursive plugin bootstrap that could repeatedly construct SML_Plugin while gettext filters were being registered.
* Assigned the singleton before hooks and integrations can invoke callbacks.
* Made compatibility flag lookup independent from translated settings labels.
* Deferred option migration from activation to a guarded administrator request.
* Added a migration lock and failure capture to prevent retry storms.
* Loaded dashboard modules only in WordPress admin and frontend integrations only when configured.
* Made public taxonomy rewrite registration and translated media metadata explicit Compatibility profiles.
* Preserved existing languages, translations, typography, strings, Slider mappings and Site Profile data.

= 1.0.1 =

* Prevented rewrite flushing on activation, frontend requests and ordinary admin page loads.
* Added an explicit, nonce-protected language permalink refresh action and admin notice.
* Removed the automatic all-post taxonomy relationship repair that could time out on large sites.
* Made language-aware lists, archives, widgets and public taxonomy filtering an opt-in Compatibility profile.
* Stopped legacy migrations from automatically enabling layout and query adapters.
* Added a recovery migration for sites where version 1.0.0 had already enabled those adapters.
* Restricted database migration fallback to administrator requests.
* Preserved all existing translations, language registry, typography, strings and Site Profile data.

= 1.0.0 =

* Established the official Smart Multilingual release by Ali Vanaei / novinmaster.com.
* Raised minimum requirements to WordPress 6.5 and PHP 8.0.
* Generalized source-language handling instead of assuming English.
* Added a safe source-language-plus-Persian first-run registry.
* Added opt-in Compatibility profiles and isolated legacy 0.9.x styling.
* Added portable Site Profile import and export.
* Added comprehensive, opt-in per-language typography and font management.
* Generalized menu, WooCommerce attribute, media, taxonomy and Slider Revolution translation interfaces.
* Added non-destructive migration for existing installations.
* Rebuilt the administration interface with a responsive glass-inspired design.
* Removed fresh-install dependencies on site-specific selectors and Elementor element IDs.

== Author ==

Smart Multilingual is developed by Ali Vanaei.
https://novinmaster.com/
