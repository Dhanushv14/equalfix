=== EqualFix – AI Accessibility Auto-Fixer ===
Contributors: dhanushv14
Tags: accessibility, a11y, alt text, aria, wcag
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full on-site accessibility audit plus safe, reversible fixes to the rendered HTML — alt text, form labels, ARIA names, and skip links.

== Description ==

EqualFix Accessibility scans your site's public pages for specific accessibility issues and applies a built-in set of safe, invisible fixes to the rendered HTML:

* Adds the missing page language attribute
* Adds empty alt text to decorative images missing it
* Adds accessible names to icon-only buttons and links (derived from existing title attributes and inline SVG titles/descriptions — never invented)
* Associates form fields with their visible labels where a unique match exists
* Adds a skip-to-content link where your theme lacks one

Every fix type can be previewed (before/after) and enabled or disabled individually. Fixes are applied at render time only — your theme files and post content are never modified, and deactivating the plugin restores your original output immediately.

The built-in audit shows each finding with its severity, WCAG reference, the exact HTML, and a plain-English explanation. Findings are honestly categorized: auto-fixable by this plugin, auto-fixable by the Pro add-on, needs human review, or third-party embed (cannot be auto-fixed).

A service connection is planned for a future release: the opt-in appears in settings but is not active yet, and the plugin sends nothing anywhere today. The free plugin is complete as-is and works forever without payment.

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

== Screenshots ==

1. Audit results — every finding with its severity, WCAG reference, the exact HTML, and an honest category: auto-fixable by the free plugin, auto-fixable by the Pro add-on, needs human review, or a third-party embed to fix at the source.
2. Before/after previews for each fix type, generated from real findings on your own site, so you see exactly what a fix changes before enabling it.
3. Settings — each Fix Set A fix type can be enabled or disabled individually, with page, template, and selector exclusions.

== Changelog ==

= 0.2.4 =
* Fix: third-party embeds are now only flagged when the iframe is missing a title. A titled embed is already named for assistive technology, so it is no longer reported as a finding; an untitled embed is reported with its WCAG 2.4.1 reference and instructions to add a title where it is embedd.
= 0.2.3 =
* Updated: the plugin directory screenshots now show the current interface, including the real before/after fix previews.
* Internal: small robustness fix in preview generation. No behaviour change.

= 0.2.2 =
* Fix: before/after fix previews now show the real change taken from your actual pages. Previously several fix types showed identical before and after text because the preview ran without the page context the fix depends on.
* Fix: the audit now always measures your site's original HTML. Previously, running an audit while fixes were enabled could miss fixable issues, because the audit saw pages the fixer had already repaired.
* Fix: an aria-labelledby that points at missing or empty content no longer hides a missing accessible name or form label — in the audit or in the fix engine.
* Fix: images already hidden from assistive technology (role="presentation" or aria-hidden) are no longer reported as missing alt text.
* Fix: when an audit finds more issues than the 300 stored for display, the totals now report the true count and label the list as partial.
* Docs: plugin description and service-connection wording corrected (the connection is planned, not active).

= 0.2.1 =
* Fix: the word beside each fix-type switch now reflects its actual state (Enabled/Disabled) instead of always reading "Enabled".
* Fix: admin success notices stay readable when WordPress places them inside the header band.
* Fix: correct singular/plural wording for audits with exactly one finding or one page.
* Fix: findings with no single WCAG mapping (third-party embeds) now show a dash in the WCAG column instead of a blank cell.

= 0.2.0 =
* Added: audit results now open with a plain summary — how many findings the free plugin can fix, how many the Pro add-on can fix, and how many need a human decision — with a link to Pro pricing when Pro-fixable findings exist.
* Added: a note in the audit results about Pro's dated remediation reports (PDF).
* Changed: the Pro box on the settings screen now shows Pro pricing and links to the pricing page.
* Changed: redesigned admin screens — branded header with live status, at-a-glance audit stat cards, toggle switches, severity and category badges, and a full Pro upgrade card with pricing.

= 0.1.3 =
* Fix: a control whose accessible name comes from a contained image's alt text (or an image input's own alt) is no longer reported as having no accessible name.
* Fix: controls hidden with aria-hidden are no longer flagged for missing names, and the fix engine no longer adds labels to them.
* Fix: a control whose only text is a non-breaking space is now correctly reported as having no accessible name.
* Fix: the fix engine no longer overrides an image-alt-derived name with a title-derived label.

= 0.1.2 =
* Fix: the audit and fix engines now handle pages whose element ids contain quote characters instead of stopping early.
* Fix: the admin stylesheet cache-busting version now tracks the plugin version.
* Added: plugin directory screenshots.

= 0.1.1 =
* Fix: text domain corrected to match the plugin slug for directory translation support.
* Fix: form fixes no longer overwrite an existing field id when associating a label.
* Fix: output buffering now skips non-GET/HEAD requests so POST responses are never cached.

= 0.1.0 =
* Initial release: on-site audit, Fix Set A (lang, decorative alt, aria-labels, label association, skip link), per-fix toggles with before/after previews, exclusions, cache purging, uninstall cleanup.
