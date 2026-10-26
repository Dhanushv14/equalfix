=== EqualFix – AI Accessibility Auto-Fixer ===
Contributors: dhanushv14
Tags: accessibility, a11y, alt text, aria, wcag
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full on-site accessibility audit plus safe, reversible fixes to the rendered HTML — alt text, form labels, ARIA names, and skip links.

== Description ==

EqualFix Accessibility scans your site's public pages for specific accessibility issues and applies a built-in set of safe, invisible fixes to the rendered HTML:

* Adds the missing page language attribute
* Adds empty alt text to decorative images missing it
* Adds accessible names to icon-only buttons and links (derived from existing titles — never invented)
* Associates form fields with their visible labels where a unique match exists
* Adds a skip-to-content link where your theme lacks one

Every fix type can be previewed (before/after) and enabled or disabled individually. Fixes are applied at render time only — your theme files and post content are never modified, and deactivating the plugin restores your original output immediately.

The built-in audit shows each finding with its severity, WCAG reference, the exact HTML, and a plain-English explanation. Findings are honestly categorized: auto-fixable by this plugin, auto-fixable by the Pro add-on, needs human review, or third-party embed (cannot be auto-fixed).

An optional service connection (off by default, explicit opt-in) enables deeper cloud analysis. The free plugin is complete as-is and works forever without payment.

This tool assists with identifying and remediating specific accessibility issues in your site's HTML output. It does not provide legal advice and does not guarantee compliance with the ADA, WCAG, or any other law or standard. Accessibility is an ongoing process, and some issues require manual review and fixes. Responsibility for your site's accessibility remains with you, the site owner.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/equalfix-accessibility` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → EqualFix to review the fix types and run your first audit.

== Frequently Asked Questions ==

= Will this plugin make my site ADA compliant? =

No plugin can guarantee compliance with the ADA, WCAG, or any other law or standard — and any tool that claims it can should not be trusted. This plugin helps improve accessibility by detecting specific issues and applying safe, reversible fixes to the rendered HTML. Some issues always need human review, and responsibility for your site's accessibility remains with you.

= Does it change my theme or content? =

No. Fixes are applied to the rendered HTML as pages are served. Theme files, plugin files, and database content are never edited. Deactivate the plugin and your output returns to its original form immediately.

= What data does the plugin send anywhere? =

By default, nothing leaves your site: the audit and fixes run locally. Only if you explicitly opt in to the service connection may the plugin send your site URL, page URLs, and rendered HTML for analysis. It never sends visitor, customer, order, or personal data. See the Privacy section below.

= Why are some findings marked "needs human review"? =

Some issues cannot be fixed safely by automation — for example, writing meaningful alt text for a product image, or renaming duplicate id attributes. The plugin reports these honestly instead of guessing.

== Privacy ==

Default state: this plugin makes no external network requests and stores data only in your own database (settings, audit summaries, and a cache-purge log).

If you opt in to the service connection on the settings screen, the plugin may transmit: your site URL, audited page URLs, and rendered HTML of those pages for accessibility analysis. It never transmits visitor data, customer data, order data, or personal data. Scan data retention is documented in the service terms linked on the settings screen.

== Changelog ==

= 0.1.1 =
* Fix: text domain corrected to match the plugin slug for directory translation support.
* Fix: form fixes no longer overwrite an existing field id when associating a label.
* Fix: output buffering now skips non-GET/HEAD requests so POST responses are never cached.

= 0.1.0 =
* Initial release: on-site audit, Fix Set A (lang, decorative alt, aria-labels, label association, skip link), per-fix toggles with before/after previews, exclusions, cache purging, uninstall cleanup.
