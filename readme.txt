=== EqualFix – AI Accessibility Auto-Fixer ===
Contributors: dhanushv14
Tags: accessibility, a11y, alt text, aria, wcag
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.4
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

The audit runs 18 named checks on each scanned page:

* Images have descriptions (alt text)
* Buttons, links and controls have names
* Form fields have labels
* Page language is set and well-formed
* Page has a title
* A skip link leads to the main content
* Element IDs are unique
* Headings are in order and not empty
* Embedded frames are titled
* Zoom is not disabled
* ARIA references point at elements that exist
* Nothing focusable is hidden from screen readers
* Clickable things are real buttons or links
* Tab order follows the page order
* Personal-data fields offer autocomplete
* Data tables have header cells
* Videos and audio offer captions or a transcript
* Text contrast, from styles written in the page

When a scan completes, the results also list the checks your pages passed, so a clean area is shown as checked-and-passed rather than simply not mentioned. The audit works from the HTML and the styles written in the page itself; it does not run a browser, so it does not judge keyboard operation, focus visibility, motion, or anything that only appears after JavaScript runs — those need human testing, and the results say so.

Every fix type can be previewed (before/after) and enabled or disabled individually. Fixes are applied at render time only — your theme files and post content are never modified, and deactivating the plugin restores your original output immediately.

The built-in audit shows each finding with its severity, WCAG reference, the exact HTML, and a plain-English explanation. Findings are honestly categorized: auto-fixable by this plugin, auto-fixable by the Pro add-on, needs human review, or third-party embed (cannot be auto-fixed).

The free plugin runs entirely on your site: audits and fixes are local, and nothing is sent anywhere automatically. The one exception is yours to trigger: if you buy the Pro add-on, the Pro tab in this dashboard can validate your licence key against the EqualFix service and download Pro for a one-click install — see the External Services section for exactly what is sent. The free plugin is complete as-is and works forever without payment.

This tool assists with identifying and remediating specific accessibility issues in your site's HTML output. It does not provide legal advice and does not guarantee compliance with the ADA, WCAG, or any other law or standard. Accessibility is an ongoing process, and some issues require manual review and fixes. Responsibility for your site's accessibility remains with you, the site owner.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/equalfix-accessibility` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to EqualFix in the admin menu to run your first audit and review the fix types.

== Frequently Asked Questions ==

= Will this plugin make my site ADA compliant? =

No plugin can guarantee compliance with the ADA, WCAG, or any other law or standard — and any tool that claims it can should not be trusted. This plugin helps improve accessibility by detecting specific issues and applying safe, reversible fixes to the rendered HTML. Some issues always need human review, and responsibility for your site's accessibility remains with you.

= Does it change my theme or content? =

No. Fixes are applied to the rendered HTML as pages are served. Theme files, plugin files, and database content are never edited. Deactivate the plugin and your output returns to its original form immediately.

= What data does the plugin send anywhere? =

By default, nothing leaves your site: the audit and fixes run locally. The only transmission is one you start yourself: pasting a Pro licence key on the Pro tab and clicking Install sends that key and your site URL to the EqualFix service to validate the key and fetch the Pro download. Nothing is ever sent automatically, and visitor, customer, order, or personal data is never transmitted. See External Services and Privacy below.

= Why are some findings marked "needs human review"? =

Some issues cannot be fixed safely by automation — for example, writing meaningful alt text for a product image, or renaming duplicate id attributes. The plugin reports these honestly instead of guessing.

== External Services ==

This plugin connects to one external service — the EqualFix licence and download service at equalfix.com — and only when you ask it to:

* **Licence validation** — when you paste a licence key on the Pro tab and click "Install Pro", the plugin sends the key and your site URL to `https://equalfix.com/api/public/licence/validate` to confirm the key is genuine and registered to this site.
* **Pro download** — after validation, the plugin requests a signed, single-use download link from `https://equalfix.com/api/public/billing/download-token` (sending the same key and site URL) and WordPress downloads the Pro add-on from the returned address and installs it.

No other requests are made. The audit, the fixes, and every other screen work fully offline from any EqualFix server. The service's terms and privacy policy: [Terms of Service](https://equalfix.com/terms) · [Privacy Policy](https://equalfix.com/privacy).

== Privacy ==

Default state: this plugin makes no external network requests and stores data only in your own database (settings, audit summaries, dismissal records, and a cache-purge log).

The only exceptions are the owner-initiated calls described under External Services: your licence key and site URL when you paste a key and click Install. The plugin never transmits page content, visitor data, customer data, order data, or personal data, and nothing is sent automatically or in the background.

== Screenshots ==

1. The EqualFix dashboard — run a full audit, then see what is already fixed, what free fixes still cover, and what AI Vision can draft for you.
2. Findings — alt-text coverage with AI Vision drafts waiting, a before/after preview of every fix, and every issue grouped by page with its fix path.
3. The Pro approval queue — AI Vision drafts alt text for images that are missing it, and nothing is published until you approve each draft.

== Changelog ==

= 1.10.4 =
* Fixed: the Issues summary now counts every problem that already has a fix ready (including the computed fixes), so the one-click count matches the Fix all buttons below it.

= 1.10.3 =
* The Issues tab is now one compact, scrollable table.
* Many more problems can now be fixed inside EqualFix instead of only reviewed: low contrast (EqualFix computes the nearest passing shade of your own colour), generic link text, duplicate IDs, clickable elements that are not buttons, missing page titles, embeds without titles, invalid language codes, positive tabindex, missing autocomplete hints, zoom-blocking viewports, broken ARIA references, empty headings, and videos or audio missing captions or transcripts (you supply the captions file or transcript; EqualFix adds it).
* Every fix is still applied at render time and removing the plugin restores your site exactly.

= 1.10.2 =
* The Issues tab is redesigned: problems are shown in plain language, one card per problem with one clear action, a summary strip shows how many problems one click can fix, and the filters are simpler.

= 1.10.1 =
* Clearer labels: counts now say whether they are problems or findings, the last-scan time reads naturally, and Settings shows the delete note once.

= 1.10.0 =
* A much wider audit: 18 named checks, up from the original set. New in this release — page title, data tables (header cells), videos and audio (captions or a transcript offered), text contrast measured from the styles written in the page, autocomplete on personal-data fields, positive tabindex, and empty headings. When a scan finishes, the results also name the checks your pages passed.
* A readability panel on the results: reading level of your page text, shown as information only — never counted as a finding.
* More honest scanning: content visitors cannot see (hidden, aria-hidden, or not presented) is no longer flagged, and hidden text no longer counts as a name, a label, or a transcript. Contrast results now follow the real CSS cascade, including !important, so reported ratios are the ones your styles actually produce.
* Pro installs are now verified end to end: the one-click installer checks the download's SHA-256 fingerprint before installing and refuses — with an explanation and a manual fallback — if the fingerprint is missing or does not match.
* AI alt-text generation is resilient: progress is saved as it goes, so an interrupted run keeps the drafts it already made, and the same image is never sent to the AI service twice in one run. Reported counts always match what is actually waiting in the queue.
* Fixes to the fixers: the WooCommerce quantity-label fix now applies to fields whose only label is empty, exactly as the audit reports them.
* Reports tell one story: a scan that is still running is labelled partial everywhere, including the PDF — it never also claims "no issues found".
* Reliability pass across the audit, fixes, reports, and updates, including a one-time notice that now shows exactly once even when two admin pages load at the same moment.

* Counts you can trust: the Overview and Issues now count distinct problems — the same broken element repeated on ten pages is one problem — and items that need a human decision are shown separately as "for your review" instead of inflating your still-to-do list. The scan-complete message, the coverage ring and every card use the same numbers, and the step cards' receipts add up to the Fixed total exactly.
* The Overview's next step now points at the biggest real pile first, whether that is an unpressed Apply button, drafts waiting for your OK, the review pile, or items waiting for your words.
* Deleting the plugin now removes everything: settings, scan history, approvals and rules are wiped and every fix stops applying, so your site returns to exactly how it was before. Deactivating the plugin keeps your data. (Until now, deletion only removed data if you had opted in.)
* Writing your own fix text in the free plugin is capped at 10 saved fixes — fixes you already saved keep working, and Pro removes the cap. The free AI alt-text preview has been removed: AI drafting is a Pro feature.
* Upgrade prompts no longer appear anywhere once your Pro licence is active, and the card spacing on the Overview is fixed so the next-step card no longer crowds the step above it.

= 1.9.0 =
* A simpler, clearer layout: everything now lives in five tabs — Overview, Issues, Review, History and Settings. Findings and Fixed are one Issues list with status views (To do, Waiting, Fixed, Set aside), and with Pro active the suggestions queue is under Review, reports and scan history are under History, and extra fixes, monitoring and your licence are under Settings.
* The Overview now answers three questions — where you stand, what to do next, and whether anything needs your words — and when the numbers come from an earlier scan it says so ("Showing your last scan from …") instead of repeating the same lists in several places.
* Still-to-do items are grouped by job or by page, and nothing is hidden behind a "Show all" fold.
* Issues the AI could not draft say exactly why and what would unblock them, and the counts at the top of the Issues list agree: how many can be fixed free, how many the AI can still draft, and how many need your words.
* If the drafting service is briefly unavailable, affected issues are marked as not attempted yet — never wrongly stamped "couldn't draft".
* The "updates automatically" announcement no longer appears after activation. Automatic updates still default on, and your Plugins screen choice is always respected.
* Bookmarks and links to the previous tabs land on the right new screen.

= 1.8.0 =
* New fix console on the Overview: nothing is applied until you press the button. "Apply Standard Fixes" switches on the free fix set — and with Pro active, "Apply Structural & Semantic Fixes" does the same for Pro's set — each with a visible progress state and a receipt showing what is now live, and a Pause control to switch a set back off.
* Issues the AI could not draft are no longer a dead end: they are marked "AI skipped — needs your words", with the reason in plain words and the box to write it yourself right there as the main action, and they are listed in Pro's new "Couldn't draft" section.
* Repeated identical elements — two date fields, two identical buttons — now get separate cards ("Copy 1 of 2", "Copy 2 of 2"), and each card's panel opens on its own.
* Still-to-do items always come first and are never hidden behind "Show all".
* The numbers agree everywhere: the fix cards count everything they switch on and off (including fixes you approved in Pro), and the AI drafting result now reports queued, already covered, and couldn't-draft as separate counts.
* Fixed a set of display bugs found in real-site testing, including a licence time shown as a raw number and receipt times that did not refresh after pausing and re-applying.

= 1.7.1 =
* Automatic updates are now on by default, so you always get the latest fixes. You can turn them off any time on the Plugins screen.

= 1.7.0 =
* Findings redesigned: issues are now job cards grouped by type, with one clear action per issue — draft it with AI, add the text yourself, or set it aside.
* You can now add your own alt text, labels, button and link names, and embed titles.
* New: an accessibility statement generator that creates a draft page from your real scan results.
* When Pro is active, "Still to do" on the Overview opens the Pro approval queue.
* Deleting the plugin now keeps your data, unless you opt in to removal in Settings.
* Wording cleaned up throughout.

= 1.6.0 =
* A dashboard you can click: the Overview tiles now take you somewhere — Pages scanned opens the list of scanned pages, Fixed opens the fixed list, and Still to do opens a view of exactly the issues left, with what can be fixed free, what AI Vision can draft, and what needs your words.
* Findings is calmer: issue groups start collapsed, so the page opens as a short summary instead of a long list. In the Still to do view the groups open for you, ready to work through.
* The Fixed tab is simpler: one summary line with the number of issues being fixed, and the detailed lists folded away until you click to see them.
* The header title is visible again (it was white on white), and the Pro tab now opens on its Overview instead of the Licence screen.

= 1.5.0 =
* One set of numbers everywhere: the Overview, the Top issues list, and the Fixed tab now count Pro's work too. Alt text, names, and labels you approved in Pro (and Pro's enabled extra fixes) count as fixed, and suggestions waiting for your approval count as "waiting for your OK" — so approving a suggestion updates your Overview immediately, with no re-audit. Fixed + waiting + still to do always adds up to the total.
* Plainer Overview: the headline and stat tiles now read "Fixed", "Waiting for your OK", and "Still to do", and when AI suggestions are waiting, the Overview's main button takes you straight to them.
* Plainer Findings: issues are grouped biggest first, each group opens with one plain line saying what the issue is and what happens next, pages are named by their titles instead of raw URLs, and the WCAG reference and HTML snippet now sit behind a "Details" toggle on each finding. Dismissing a finding is now labelled "Not an issue for my site".
* The Fixed tab now also lists the alt text you approved in Pro, image by image and page by page.
* The Purge log moved out of the main tab bar — it now lives under Settings as "Cache log — advanced". The log itself is unchanged.
* Fixed: the Overview's "View all" link in Top issues pointed nowhere; it now opens the Findings tab.

= 1.4.0 =
* Honest scans: if most fetched pages return exactly the same page — what a coming-soon, maintenance, or login page looks like to a visitor — the audit now reports itself as limited instead of presenting a near-empty result as a clean, complete scan, and no coverage score is shown for such a run.
* Pages that could not be scanned are now named in the results, so a partial scan can be acted on.
* Page labels in Findings, Fixed, and the approval queue now include the query string when it is what distinguishes the page (for example /?page_id=18), instead of every page reading "/" on plain-permalink sites.
* The Overview now separates issues that genuinely need your words from the ones AI Vision can draft for your approval in the Pro queue.
* The alt-text card is now "Alt text coverage — AI Vision" and says plainly what Pro's AI drafts do.
* The Pro tab now opens on Pro's new Overview hub (with Pro 1.3+), which explains each Pro section and shows its live state, instead of dropping you straight onto the Licence form.


= 1.3.0 =
* Free AI alt-text preview: with a free EqualFix account key (create the account, register your site, paste the key on the Pro tab), the Findings tab can ask the EqualFix AI service to draft alt text for up to 10 of your audit's undescribed images — shown as a read-only preview so you can judge Pro's AI drafts before paying anything. Drafts changed nothing on your site; applying alt text was Pro's approval-queue job. This preview was removed in 1.10.0 — AI drafting is a Pro feature, and the free plugin no longer sends anything for it.

= 1.2.0 =
* One product, one dashboard: the EqualFix screen is now tabbed — Overview, Findings, Fixed, Pro, Settings, Purge log — instead of one long page. When the Pro add-on (1.2+) is installed, its screens live here as Pro tabs; there is no second plugin menu to hunt for.
* One-click Pro install: paste your licence key on the Pro tab and EqualFix validates it, downloads Pro from the EqualFix service, and installs and activates it. Wrong-site keys now say exactly which site the key belongs to. See the new External Services section for what is sent.
* Pro state is reflected everywhere: once Pro is active, every "Upgrade to Pro" call-to-action becomes "Pro is active" / "Open Pro" — the free plugin no longer pitches an upgrade you already have.
* Findings can be grouped by issue type (with affected-page counts) or by page, and the Findings tab opens with an alt-text coverage card: how many images are missing alt text, on how many pages, and how many drafts are waiting in the Pro queue.
* Finding dismissal with a logged reason: dismiss a finding that doesn't apply, was fixed another way, or is accepted as-is; it stays out of the working list across future audits until you restore it, with the reason, note, and date on record.
* The Fixed tab is the persistent fix record: everything currently being fixed at render, with Inspect Fix before/afters and a per-fix-type off switch.
* No changes to the audit engine or fix behaviour — counts and fixes are identical to 1.1.2.

= 1.1.2 =
* Hardened the fragment fixer (post content and before/after previews): when the bundled HTML5 parser is unavailable and the fallback parser is used, script and style contents are now shielded before the fixer's parse step and restored byte-for-byte afterwards, so tag-like text inside inline scripts or styles can no longer be altered. No behaviour change when the bundled parser is active, which is every normal install.

= 1.1.1 =
* Dashboard greeting is now state-aware — no "Welcome back" on your first results.
* Auto-fix switch logic fixed: switching it on when no fix types are selected now enables the free fix set, so the switch always matches what your site is actually doing.
* Findings display refined: a per-page category summary with a proportional bar, severity rails on finding cards, and higher-contrast code snippets.

= 1.1.0 =
* New EqualFix dashboard: EqualFix now has its own top-level menu and a modern results dashboard — a fix-coverage overview, key stats (pages scanned, issues fixed, remaining issues, last scan), an issues trend that builds with each audit, top issues, recent activity, and quick actions.
* New "Fixed — active on your site" section: every finding currently being fixed at render time, grouped by page, each with an Inspect Fix before/after captured from your own pages.
* Findings are now cards grouped by page (severity, category, and WCAG reference at a glance) instead of a wide table.
* The Pro card is now a gap analysis of your own site: the exact issue types Pro would fix, with counts.
* No changes to the audit engine, fix behaviour, or counts — presentation only, plus a record of when free fixes were switched on.

= 1.0.3 =
* Maintenance re-release of 1.0.2: the first 1.0.2 package was built from incomplete files, so 1.0.3 supersedes it with the complete, verified build. Everyone on 1.0.2 should update. No feature changes beyond the 1.0.2 notes below.

= 1.0.2 =
* More free fixes, from your own pages: a form field whose only name is its placeholder text now gets an accessible label using that placeholder text, and an image the page already describes — in its title attribute or its figure caption — gets that text as its alt. Nothing is invented; the words were already on the page.
* Clearer results: one Pro card now states Pro's full coverage — what it fixes automatically plus how many findings its approval queue can draft suggestions for — and the free step explains what the free fixes do even when a particular audit's findings fall outside the free set.
* The Run audit button now shows that the audit is running (spinner and status text) instead of sitting silent until the results load.

= 1.0.1 =
* Fixed the fallback page render (used when a host blocks loopback requests) on sites where the audit runs from wp-admin — including WordPress Playground, where 1.0.0 could not scan at all. Pages are now resolved directly by their identity instead of relying on request simulation, and the simulation path presents itself as the front end. Audits on loopback-blocked hosts should now scan rendered pages instead of reporting them unscanned.

= 1.0.0 =
* Rebuilt scan engine: pages are parsed with a standards-compliant HTML5 parser, and every captured page is verified before it is analysed — a page that cannot be verified is reported as not scanned, never as clean.
* Rebuilt fix delivery: fixes are now applied as small, targeted edits to the page exactly as your theme produced it, instead of rewriting the whole page. Scripts, styles, and templates pass through untouched.
* More accurate results: accessible names are computed the way assistive technology sees them (hidden text no longer counts), skip-link targets are checked reliably, and issues inside page templates are no longer missed.
* Fixes now also reach content your theme prints through WordPress filters (post content, search forms), not only full pages.
* Cache handling: fix changes now clear cached pages reliably across popular caching plugins and object caches.


= 0.5.0 =
* New: when a page cannot be fetched — the signature of a host that blocks loopback requests, including WordPress Playground and some budget hosts — the audit now renders that page inside WordPress instead of failing to scan it. Rendered pages are labelled in the results: they reflect a logged-out view without any external page cache, so their results can differ slightly from a live visit. Pages that can neither be fetched nor rendered are still reported as not scanned, never as clean.

= 0.4.5 =
* Fix: inline scripts and styles are now shielded while a page is being fixed, so JavaScript that builds HTML strings (and any script or style content) passes through byte-for-byte — previously, tag-shaped text inside script strings could be silently removed on pages where a fix applied.
* Fix: the audit no longer reports findings inside <template> elements — template content is inert until script uses it, so those findings were phantoms.
* Fix: deeply nested page content (beyond the HTML parser's default depth limit) is now fully audited and fixed instead of being silently skipped.
* Fix: an audit that discovers no pages at all now says so, instead of displaying "no issues found".

= 0.4.4 =
* Fix: an audit whose page fetches ALL failed (hosts that block loopback requests, local development tools, WordPress Playground) no longer displays as a clean result. It now shows a clear error state — what happened, the exact error your server returned, and how to fix it — instead of "no issues found". The audit completion notice no longer claims success when nothing was scanned.

= 0.4.3 =
* Correctness pass. Untitled embedded frames on your own site are now reported (previously only third-party embeds were), the audit and the fixer agree on which images are decorative, sitemap URLs with encoded characters are followed correctly, and non-ASCII text inside scripts and styles is no longer corrupted when fixes are applied. Heading order is now judged in document order, exactly as assistive technology encounters it (mixed sequences like h1, h3, h2 are scored correctly in both the audit and the Pro heading fix). A skip link whose target does not exist is now reported instead of counted as present. The file-name alt check no longer flags ordinary sentences or a meaningful alt that happens to match the file name, and ignores hidden images. Fixed output is no longer served stale after you edit content — the output cache is invalidated when posts, menus, or terms change. Long audits save their progress as they go and report honestly if a hosting time limit interrupts them (including how many pages could not be fetched). Excluded-URL patterns match by path prefix, so /checkout no longer also excludes /checkout-info. First-run redirect no longer interrupts form submissions, and journey steps renumber when a section is absent.

= 0.4.2 =
* New audit check: alt text that is just the image's file name (for example "IMG_9921_edit_v2.png") is now flagged for human review — screen readers announce a file name verbatim, and it describes the file, not the image.

= 0.4.1 =
* Honesty pass: the "Needs your decision" panel now describes the Pro approval queue accurately — it suggests fixes derived from the page itself for the findings it can name, label, or describe, rather than implying every finding gets one.

= 0.4.0 =
* New: the Audit tab is now a guided journey. Activating the plugin takes you straight to a "Run your first audit" prompt. Audit results are then presented in working order: the free-fixable issues listed first with the one-click "Fix all free issues" button, then the issues the Pro add-on can fix — each with a plain-language note on why it matters and an upgrade button — then the issues that need your decision.

= 0.3.0 =
* New: a guided fix flow on the Audit tab. Fresh installs now start with fixes off — nothing changes on your site until you run an audit and click "Fix all free issues" to switch the free fix set on. A "What's left" panel then shows what the Pro add-on can fix and what needs a human decision.
* Fix: duplicate-id findings no longer cite WCAG 4.1.1, which WCAG 2.2 retired; the WCAG cell now shows a dash for this finding, as it does for findings with no single mapping.

= 0.2.4 =
* Fix: third-party embeds are now only flagged when the iframe is missing a title. A titled embed is already named for assistive technology, so it is no longer reported as a finding; an untitled embed is reported with its WCAG 2.4.1 reference and instructions to add a title where it is embedded.

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
