=== Snake Image Optimizer ===
Contributors: devwebmahmoud
Tags: webp, images, optimize, lazy load, performance
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later

Snake Image Optimizer is a local-first WebP plugin with safe fallbacks, diagnostics, activity logs, and bulk processing.

== Description ==
Snake Image Optimizer is designed for site owners who want a straightforward, local-first workflow for image optimization.

Instead of depending on an external optimization API, the plugin works with local server capabilities to generate WebP files for uploads and WordPress-generated image sizes. Original files remain available as a safe fallback.

This version is intentionally focused on a small, practical workflow:

* Local WebP conversion for uploaded JPG and PNG images.
* Safe fallback behavior that keeps the original files available.
* Automatic WebP delivery through WordPress image handling.
* Lazy Load controls with a built-in page test tool.
* Diagnostics to help confirm local WebP support.
* Activity logs for troubleshooting conversions and plugin actions.
* Bulk processing for eligible existing Media Library images.

This plugin is best suited for users who want:

* a local-first approach;
* no dependency on a remote compression service;
* visibility into what the plugin is doing through logs and diagnostics; and
* a safer workflow that keeps originals available.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open Snake Image Optimizer → Core.

== Frequently Asked Questions ==
= Does it use an external optimization service? =
No. This version is focused on local server-based WebP conversion and diagnostics.

= Does it delete originals? =
No. Original uploads remain available as a safe fallback.

= What makes this plugin different? =
This plugin is intentionally focused on a local-first workflow: local WebP generation, built-in diagnostics, activity logs, and a lazy-load testing tool, without requiring a remote optimization service.

= What does Bulk include? =
Bulk processes eligible existing Media Library images without a plugin-imposed limit. New uploads are also converted without a plugin-imposed limit.

= Why does Diagnostics matter? =
Diagnostics helps you verify that your server can encode WebP locally.

== Changelog ==

= 1.0.0 =
* First release under the Snake Image Optimizer name.
* Local-first WebP conversion with safe fallbacks.
* Includes lazy-load testing, diagnostics, activity logs, and bulk processing.
