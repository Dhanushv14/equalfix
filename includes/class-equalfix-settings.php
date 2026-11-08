<?php
/**
 * EqualFix Accessibility — Admin Settings & Audit UI
 *
 * One dashboard with five tabs: Overview, Issues, Review, History, Settings.
 * All actions require manage_options and a valid nonce. All output escaped,
 * all input sanitized.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Settings {

	/**
	 * The Issues view implied by a legacy route (IA batch):
	 * set by render() from resolve_route(), read by the Issues
	 * renderer when no explicit ?view= is present.
	 *
	 * @var string|null
	 */
	private $ia_view = null;

	/**
	 * Whether the stored audit predates the current version's
	 * first run (IA batch): set by render_audit(), read by the
	 * audit form so its button reads "Run a fresh scan".
	 *
	 * @var bool
	 */
	private $audit_stale = false;

	/**
	 * Settings option name.
	 */
	const OPTION = 'equalfix_settings';

	/**
	 * Audit results option name.
	 */
	const AUDIT_OPTION = 'equalfix_audit_results';

	/**
	 * Single-run lock for audits (loop round 3, S3-3): a
	 * transient held while an audit run is in flight, the
	 * audit-side analogue of the Pro suggestions' generation
	 * lock. Ten minutes — a stepped run that has made no
	 * progress in that window is abandoned, not live, and
	 * must not block audits forever.
	 */
	const AUDIT_LOCK = 'equalfix_audit_lock';

	/**
	 * Dismissed findings store (1.2): fingerprint => record.
	 * Dismissal hides a finding from the working list with a
	 * logged reason; it never changes what the audit found.
	 */
	const DISMISSED_OPTION = 'equalfix_dismissed_findings';

	/**
	 * Legacy option names from the removed free AI preview
	 * (1.3, removed DH-4 2026-11-07): no code reads or writes
	 * them any more; the names stay defined only so the
	 * uninstall purge list can keep retiring stored rows from
	 * older installs.
	 */
	const PREVIEW_KEY_OPTION = 'equalfix_preview_key';

	/** Legacy AI preview drafts option (see above). */
	const AI_PREVIEW_OPTION = 'equalfix_ai_preview';

	/**
	 * Fix-type constant => settings key map. Referenced by
	 * fix_type_enabled() since 1.0 but never defined — the call
	 * was unreachable with real audit data (its only caller
	 * short-circuits first), so the missing constant stayed
	 * dormant until the next-batch survey found it (2026-11-04).
	 * Defined now, with a probe that calls the helper directly.
	 */
	const FIX_SETTING_KEYS = array(
		EqualFix_Fixer::FIX_LANG        => 'fix_lang',
		EqualFix_Fixer::FIX_ALT         => 'fix_alt',
		EqualFix_Fixer::FIX_ARIA_LABEL  => 'fix_aria_label',
		EqualFix_Fixer::FIX_LABEL_ASSOC => 'fix_label_assoc',
		EqualFix_Fixer::FIX_SKIP_LINK   => 'fix_skip_link',
	);

	/**
	 * Owner-written fix rules (next batch, Findings redesign):
	 * the free counterpart of Pro's approval-rule stores, in the
	 * SAME shapes, written from the Findings rows' "write it
	 * myself" forms and applied by the free fixer at render.
	 */
	const MANUAL_ALT_OPTION   = 'equalfix_manual_alt_rules';
	const MANUAL_NAME_OPTION  = 'equalfix_manual_name_rules';
	const MANUAL_LABEL_OPTION = 'equalfix_manual_label_rules';
	const MANUAL_TITLE_OPTION = 'equalfix_manual_title_rules';

	/**
	 * Owner-approved attribute rules (Fix-First, 2026-11-07):
	 * deterministic fixes the owner approved from a proposal
	 * panel, in the same economy as the four manual stores —
	 * applied by the free fixer at render, gated on the free
	 * master switch, counted in the free hand-written cap.
	 */
	const MANUAL_ATTR_OPTION = 'equalfix_manual_attr_rules';

	/**
	 * Waves 3+4 insert rules (Fix-First, 2026-11-07): content
	 * the owner supplies — a captions file for a video, a
	 * transcript for a video or audio clip — inserted beside
	 * or inside the targeted media element at render. Same
	 * manual-store trust shape as the attr store.
	 */
	const MANUAL_INSERT_OPTION = 'equalfix_manual_insert_rules';

	/**
	 * Waves 3+4 page-title rules (Fix-First, 2026-11-07): the
	 * title the owner approved for one page, supplied through
	 * WordPress's document title filters only while that
	 * page's served title is empty.
	 */
	const MANUAL_PAGETITLE_OPTION = 'equalfix_manual_pagetitle_rules';

	/**
	 * Waves 3+4 headings-full approvals (Fix-First,
	 * 2026-11-07): page patterns the owner approved for the
	 * Pro headings walk to correct EVERY skipped level on the
	 * page, not only the first. An approval, not a rule — it
	 * carries no content of its own.
	 */
	const HEADINGS_FULL_OPTION = 'equalfix_headings_full_pages';

	/**
	 * Fix types with labels (Fix Set A — the complete free fix set).
	 *
	 * @return array
	 */
	/**
	 * THE name of the free-scope fix action (Stage 6, two-button
	 * fix model). Working label — the owner's final pick is
	 * pending, so this method is the single rename point: every
	 * surface reads it here.
	 *
	 * @return string
	 */
	public static function apply_standard_label() {
		return __( 'Apply Standard Fixes', 'equalfix' );
	}

	public static function fix_types() {
		return array(
			EqualFix_Fixer::FIX_LANG        => __( 'Add missing page language (lang attribute)', 'equalfix' ),
			EqualFix_Fixer::FIX_ALT         => __( 'Add empty alt text to decorative images', 'equalfix' ),
			EqualFix_Fixer::FIX_ARIA_LABEL  => __( 'Label icon-only buttons and links', 'equalfix' ),
			EqualFix_Fixer::FIX_LABEL_ASSOC => __( 'Associate form fields with their visible labels', 'equalfix' ),
			EqualFix_Fixer::FIX_SKIP_LINK   => __( 'Add skip-to-content link where the theme lacks one', 'equalfix' ),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => 1,
			'fix_lang'           => 1,
			'fix_alt'            => 1,
			'fix_aria_label'     => 1,
			'fix_label_assoc'    => 1,
			'fix_skip_link'      => 1,
			'connect_opt_in'     => 0,
			'excluded_urls'      => '',
			'excluded_selectors' => '',
			'excluded_templates' => '',
			'auto_purge'         => 1,
			'output_cache'       => 1,
			// Stage 6 (adversarial F5a): has the owner ever saved
			// the fix-type configuration themselves? Distinguishes
			// a legacy/never-touched all-off state (Apply may
			// configure the full set) from a DELIBERATE all-off
			// (Apply must respect it exactly).
			'types_configured' => 0,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get() {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Register hooks.
	 */
	/**
	 * Pro's published state, as the free plugin sees it (1.2,
	 * Workstream B). Pro 1.2 exposes EqualFix_Pro::state(); Pro
	 * 1.1 has no state() but its licence class can be read the
	 * same way; with no Pro at all, every field is empty. The
	 * upgrade CTAs render from this and only this, so an active
	 * Pro never leaves a stale "Upgrade to Pro" on screen.
	 *
	 * @return array { present: bool, version: string, licence_active: bool, licence_status: string, tier: string, shell: bool }
	 */
	public static function pro_state() {
		$state = array(
			'present'         => false,
			'version'         => '',
			'licence_active'  => false,
			'licence_status'  => '',
			'tier'            => '',
			// Stage 5: display-ready plan label (never the raw
			// tier key, never "free" on an active licence) —
			// computed by Pro's licence class when available.
			'tier_label'      => '',
			'sites_allowed'   => 0,
			'shell'           => false,
			// Pro updater bridge (next batch, design §5.4):
			// absent on older Pro builds — defaults keep every
			// consumer on the no-update behaviour.
			'update_available' => false,
			'update_latest'    => '',
			'update_allowed'   => true,
			'update_reason'    => '',
		);
		if ( class_exists( 'EqualFix_Pro' ) && method_exists( 'EqualFix_Pro', 'state' ) ) {
			$s                = EqualFix_Pro::state();
			$state['present'] = true;
			$state['version'] = (string) $s['version'];
			$state['licence_active'] = ! empty( $s['licence_active'] );
			$state['licence_status'] = (string) $s['licence_status'];
			$state['tier']    = (string) $s['tier'];
			$state['sites_allowed'] = isset( $s['sites_allowed'] ) ? (int) $s['sites_allowed'] : 0;
			if ( class_exists( 'EqualFix_Pro_Licence' ) && method_exists( 'EqualFix_Pro_Licence', 'tier_label' ) ) {
				$state['tier_label'] = EqualFix_Pro_Licence::tier_label(
					array(
						'tier'          => (string) $s['tier'],
						'status'        => (string) $s['licence_status'],
						'sites_allowed' => $state['sites_allowed'],
					)
				);
			}
			$state['shell']   = ! empty( $s['shell'] );
			$state['update_available'] = ! empty( $s['update_available'] );
			$state['update_latest']    = isset( $s['update_latest'] ) ? (string) $s['update_latest'] : '';
			$state['update_allowed']   = isset( $s['update_allowed'] ) ? (bool) $s['update_allowed'] : true;
			$state['update_reason']    = isset( $s['update_reason'] ) ? (string) $s['update_reason'] : '';
			return $state;
		}
		if ( class_exists( 'EqualFix_Pro_Licence' ) ) {
			// Pro 1.1 fallback: no state bridge, no shell — read
			// the licence record directly so the CTA still flips.
			$lic              = EqualFix_Pro_Licence::get();
			$state['present'] = true;
			$state['version'] = defined( 'EQUALFIX_PRO_VERSION' ) ? EQUALFIX_PRO_VERSION : '';
			$state['licence_active'] = EqualFix_Pro_Licence::is_active();
			$state['licence_status'] = (string) $lic['status'];
			$state['tier']    = (string) $lic['tier'];
			$state['sites_allowed'] = isset( $lic['sites_allowed'] ) ? (int) $lic['sites_allowed'] : 0;
			if ( method_exists( 'EqualFix_Pro_Licence', 'tier_label' ) ) {
				$state['tier_label'] = EqualFix_Pro_Licence::tier_label( $lic );
			}
		}
		return $state;
	}

	/**
	 * A short, distinguishing label for a page URL (1.4). The
	 * path alone collapses every page to "/" on plain-permalink
	 * sites (/?page_id=18), which made every Findings group read
	 * "/" in a real-host manual test — so the query string is
	 * kept whenever it is what distinguishes the page.
	 *
	 * @param string $url Full page URL.
	 * @return string Path plus query when present.
	 */
	public static function page_label( $url ) {
		$url   = (string) $url;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $path ) {
			$path = '/';
		}
		$label = $path . ( '' !== $query ? '?' . $query : '' );
		if ( strlen( $label ) > 64 ) {
			$label = substr( $label, 0, 61 ) . '...';
		}
		return $label;
	}

	/**
	 * A human label for a page URL (1.5): the title of the post
	 * or page the URL belongs to when WordPress can resolve it,
	 * so headings read "Test Storefront" instead of "?page_id=25"
	 * on plain-permalink sites. Falls back to page_label() —
	 * the compact path+query form — whenever there is no title.
	 *
	 * @param string $url Full page URL.
	 * @return string Page title, or the path+query label.
	 */
	public static function page_title_label( $url ) {
		$id = url_to_postid( (string) $url );
		if ( $id ) {
			$title = get_the_title( $id );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return $title;
			}
		}
		return self::page_label( $url );
	}

	/**
	 * A page's visible-text signature (1.4): the text a visitor
	 * actually reads, with the document title removed (titles
	 * legitimately differ per page — a coming-soon stub's title
	 * is the requested page's name), scripts and styles dropped,
	 * entities decoded and whitespace collapsed. Holding pages
	 * (coming soon, maintenance, login walls) repeat this text
	 * exactly even when their raw HTML differs per request —
	 * nonces, injected analytics, and per-page body classes all
	 * defeat a raw body hash, as the first real-host wall test
	 * proved. Pages with almost no visible text fall back to
	 * their raw body hash.
	 *
	 * @param string $html Fetched page HTML.
	 * @return string Signature key.
	 */
	private function page_signature( $html ) {
		$text = preg_replace( '#<title[^>]*>.*?</title>#si', ' ', (string) $html );
		$text = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#si', ' ', (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', trim( mb_strtolower( (string) $text ) ) );
		if ( strlen( (string) $text ) >= 40 ) {
			return 't:' . md5( (string) $text );
		}
		return 'h:' . md5( (string) $html );
	}

	/**
	 * Detect a scan that mostly fetched one repeated page
	 * (1.4): when at least 3 pages and at least half of the
	 * successfully fetched pages share a visible-text signature,
	 * the site was almost certainly showing a coming-soon,
	 * maintenance, or login page to visitors, and the run must
	 * be reported as limited rather than as a clean result.
	 *
	 * @param array $pages Per-page audit records (url/status/hash).
	 * @param int   $ok    Pages successfully fetched.
	 * @return array|null { repeated: int, of: int } or null.
	 */
	private function detect_wall( $pages, $ok ) {
		if ( (int) $ok < 3 ) {
			return null;
		}
		$counts = array();
		foreach ( (array) $pages as $page ) {
			if ( ! isset( $page['status'] ) || 'ok' !== $page['status'] ) {
				continue;
			}
			$key = '';
			if ( ! empty( $page['sig'] ) ) {
				$key = $page['sig'];
			} elseif ( ! empty( $page['hash'] ) ) {
				$key = 'h:' . $page['hash'];
			}
			if ( '' !== $key ) {
				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
			}
		}
		if ( empty( $counts ) ) {
			return null;
		}
		$top = max( $counts );
		if ( $top >= 3 && $top * 2 >= (int) $ok ) {
			return array(
				'repeated' => $top,
				'of'       => (int) $ok,
			);
		}
		return null;
	}

	/**
	 * A finding's stable identity for dismissal: the same issue
	 * (same rule, same page, same element markup) keeps the same
	 * fingerprint across audits, so a dismissal survives re-scans.
	 *
	 * @param array $finding Finding.
	 * @return string
	 */
	public static function finding_fingerprint( $finding ) {
		return md5( (string) $finding['title'] . '|' . (string) $finding['url'] . '|' . (string) $finding['snippet'] );
	}

	/**
	 * Finding titles renamed by a release (current title => the
	 * title stored state may still be keyed by). The fingerprint
	 * formula above NEVER changes — but because the title is one
	 * of its inputs, renaming a title re-keys that family, and
	 * dismissals, skip records and matches recorded under the
	 * old title would silently stop matching the same issue
	 * (T1-05: the coverage batch renamed the filename-alt
	 * title and orphaned exactly that state). For one release,
	 * lookups against stored state accept BOTH fingerprints;
	 * the legacy entries below are removed in the next batch.
	 *
	 * @var array
	 */
	const RENAMED_TITLES = array(
		'Alt text contains a file name or redundant phrase' => 'Alt text is just a file name',
	);

	/**
	 * Every fingerprint a finding may legitimately be recorded
	 * under: its own, plus — for a renamed title — the
	 * fingerprint the same issue carried before the rename.
	 *
	 * @param array $finding Finding.
	 * @return array Fingerprints, current first.
	 */
	public static function finding_fingerprint_variants( $finding ) {
		$fps = array( self::finding_fingerprint( $finding ) );
		if ( is_array( $finding ) && isset( $finding['title'] ) && isset( self::RENAMED_TITLES[ (string) $finding['title'] ] ) ) {
			$legacy          = $finding;
			$legacy['title'] = self::RENAMED_TITLES[ (string) $finding['title'] ];
			$fps[]           = self::finding_fingerprint( $legacy );
		}
		return $fps;
	}

	/**
	 * Whether a fingerprint-keyed store (dismissals, skip
	 * records) holds an entry for this finding — under its
	 * current fingerprint or a pre-rename one (T1-05).
	 *
	 * @param array $map     Fingerprint => record.
	 * @param array $finding Finding.
	 * @return bool
	 */
	public static function map_has_finding( $map, $finding ) {
		if ( ! is_array( $map ) || ! is_array( $finding ) ) {
			return false;
		}
		foreach ( self::finding_fingerprint_variants( $finding ) as $fp ) {
			if ( isset( $map[ $fp ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The dismissal reasons an owner can log (1.2). Free text is
	 * deliberately not offered for the reason itself: a fixed list
	 * keeps the log reviewable, and the optional note covers the
	 * rest.
	 *
	 * @return array slug => label
	 */
	public static function dismissal_reasons() {
		// Slugs are stable (stored records reference them); the
		// labels are the Findings redesign's plain reasons.
		return array(
			'not_applicable'  => __( 'This element doesn’t need it', 'equalfix' ),
			'fixed_elsewhere' => __( 'I fixed it another way (theme, builder, or content)', 'equalfix' ),
			'accepted'        => __( 'I’ve reviewed it — it’s fine as it is', 'equalfix' ),
			'other'           => __( 'Something else', 'equalfix' ),
		);
	}

	/**
	 * All dismissal records, keyed by fingerprint.
	 *
	 * @return array
	 */
	public static function dismissed_map() {
		$map = get_option( self::DISMISSED_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Dismiss a finding with a logged reason.
	 *
	 * @param string $fingerprint Finding fingerprint.
	 * @param string $reason      Reason slug (whitelisted).
	 * @param string $note        Optional owner note.
	 * @param array  $finding     The finding (for the log display).
	 * @return bool
	 */
	public static function dismiss_finding( $fingerprint, $reason, $note, $finding ) {
		$reasons = self::dismissal_reasons();
		if ( ! isset( $reasons[ $reason ] ) ) {
			$reason = 'other';
		}
		$map                  = self::dismissed_map();
		$map[ $fingerprint ]  = array(
			'title'  => isset( $finding['title'] ) ? (string) $finding['title'] : '',
			'category' => isset( $finding['category'] ) ? (string) $finding['category'] : '',
			'url'    => isset( $finding['url'] ) ? (string) $finding['url'] : '',
			'reason' => $reason,
			'note'   => mb_substr( trim( (string) $note ), 0, 140 ),
			'time'   => current_time( 'mysql' ),
		);
		if ( update_option( self::DISMISSED_OPTION, $map, false ) ) {
			return true;
		}
		// update_option() also returns false when the stored value
		// is identical (Stage 6, S6-02): success is the END STATE,
		// not the write's return value.
		$stored = self::dismissed_map();
		return isset( $stored[ $fingerprint ] ) && $stored[ $fingerprint ] == $map[ $fingerprint ];
	}

	/**
	 * Whether an option now holds exactly the intended entry
	 * (Stage 6, S6-02): update_option() returns false for an
	 * unchanged value, so callers that report success/failure to
	 * the owner must verify the stored end state instead.
	 *
	 * @param string $option   Option name.
	 * @param string $key      Entry key inside the option array.
	 * @param mixed  $intended Intended entry value.
	 * @return bool
	 */
	private static function option_entry_matches( $option, $key, $intended ) {
		$stored = get_option( $option, array() );
		return is_array( $stored ) && isset( $stored[ $key ] ) && $stored[ $key ] == $intended;
	}

	/**
	 * Restore a dismissed finding to the working list.
	 *
	 * @param string $fingerprint Finding fingerprint.
	 * @return bool Whether a record existed.
	 */
	public static function restore_finding( $fingerprint ) {
		$map = self::dismissed_map();
		if ( ! isset( $map[ $fingerprint ] ) ) {
			return false;
		}
		unset( $map[ $fingerprint ] );
		update_option( self::DISMISSED_OPTION, $map, false );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Owner-written fix rules (Findings redesign, next batch).           */
	/*                                                                    */
	/* The free counterpart of Pro's approval-rule stores — the SAME      */
	/* shapes, so a finding fixed by the owner's own words reads          */
	/* identically in the coverage model and the Fixed tab whichever      */
	/* plugin's store holds the rule. Written only from the Findings      */
	/* rows ("write it myself" / "It's just decorative"), applied by      */
	/* the free fixer at render like every other fix: nothing is          */
	/* written to theme files or post content.                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Stored owner-written alt-text rules (src key => rule).
	 *
	 * @return array
	 */
	public static function manual_alt_rules() {
		$rules = get_option( self::MANUAL_ALT_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Stored owner-written control-name rules (hash => rule).
	 *
	 * @return array
	 */
	public static function manual_name_rules() {
		$rules = get_option( self::MANUAL_NAME_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Stored owner-written field-label rules (hash => rule).
	 *
	 * @return array
	 */
	public static function manual_label_rules() {
		$rules = get_option( self::MANUAL_LABEL_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Stored owner-written frame-title rules (hash => rule).
	 *
	 * @return array
	 */
	public static function manual_title_rules() {
		$rules = get_option( self::MANUAL_TITLE_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * The free hand-written fix allowance (owner decision
	 * DH-3, 2026-11-07): without an active Pro licence, the
	 * four manual stores together hold at most this many
	 * rules. Rules already saved beyond the cap stay live
	 * (grandfathered) — the cap blocks only NEW saves.
	 */
	const MANUAL_FREE_CAP = 10;

	/**
	 * Issues groups whose findings are JUDGEMENT CALLS, not
	 * fix tasks (final pass, F-2, 2026-11-07): the finding's
	 * own guidance says "look and judge — this may be fine
	 * as-is" (contrast is a bounded static estimate; an
	 * aria-hidden focusable element is usually a correctly
	 * hidden template duplicate; a generic link text may be
	 * disambiguated by its context; a media element may
	 * already carry text alternatives the static audit
	 * cannot see). These groups leave the "Still to do"
	 * headline and get their own "For your review" figure,
	 * so a pile of judgement calls can never again present
	 * as a pile of unfinished fix work. Guidance groups
	 * with a definite source edit (autocomplete, duplicate
	 * IDs, interactive elements, …) stay in "Still to do" —
	 * they are real tasks, just manual ones.
	 */
	const REVIEW_GROUPS = array( 'contrast', 'aria-hidden-focus', 'link-generic', 'media-video', 'media-audio', 'media-embed' );

	/**
	 * Total rules across all four manual stores. Store
	 * ENTRIES are the unit: a mark-decorative entry carries
	 * no written words, but it is a stored manual fix like
	 * any other (same store, same fixer path, same Fixed
	 * count), so it counts toward the cap exactly like a
	 * written rule.
	 *
	 * @return int
	 */
	public static function manual_rule_count() {
		return count( self::manual_alt_rules() ) + count( self::manual_name_rules() )
			+ count( self::manual_label_rules() ) + count( self::manual_title_rules() )
			+ count( self::manual_attr_rules() ) + count( self::manual_insert_rules() )
			+ count( self::manual_pagetitle_rules() );
	}

	/**
	 * Whether the free cap currently blocks a NEW save of the
	 * given kind/key. Replacing a rule that already exists in
	 * its store is never a new save, so edits to an existing
	 * rule always pass. With an active Pro licence there is
	 * no cap at all.
	 *
	 * @param string $kind alt|label|name|title.
	 * @param string $key  The store key the save would write
	 *                     ('' when it cannot be derived, which
	 *                     counts as a new save).
	 * @return bool
	 */
	public static function manual_cap_blocks( $kind, $key ) {
		$pro = self::pro_state();
		if ( ! empty( $pro['licence_active'] ) ) {
			return false;
		}
		if ( self::MANUAL_FREE_CAP > self::manual_rule_count() ) {
			return false;
		}
		if ( '' !== $key ) {
			$stores = array(
				'alt'       => self::manual_alt_rules(),
				'label'     => self::manual_label_rules(),
				'name'      => self::manual_name_rules(),
				'title'     => self::manual_title_rules(),
				'attr'      => self::manual_attr_rules(),
				'insert'    => self::manual_insert_rules(),
				'pagetitle' => self::manual_pagetitle_rules(),
			);
			if ( isset( $stores[ $kind ][ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The store key a save for this finding would write, or
	 * '' when it cannot be derived (mirrors the key logic in
	 * save_manual_for_finding()).
	 *
	 * @param string $kind    alt|label|name|title.
	 * @param array  $finding The finding.
	 * @return string
	 */
	public static function manual_key_for_finding( $kind, $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['snippet'] ) ) {
			return '';
		}
		if ( 'alt' === $kind ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			if ( '' === $src ) {
				return '';
			}
			$abs = EqualFix_Pro_Installer::absolute_url( $src, isset( $finding['url'] ) ? $finding['url'] : '' );
			return self::manual_src_key( '' !== $abs ? $abs : $src );
		}
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		if ( '' === $target['tag'] ) {
			return '';
		}
		return self::manual_rule_hash( $kind, $target );
	}

	/**
	 * The cap refusal copy (DH-3): short, plain, and framed
	 * as the upgrade it is.
	 *
	 * @return string
	 */
	public static function manual_cap_message() {
		return __( 'You have used your 10 free hand-written fixes. EqualFix Pro writes and applies these for you.', 'equalfix' );
	}

	/**
	 * All four manual stores, keyed for the fixer's settings array.
	 *
	 * @return array
	 */
	public static function manual_rules_for_fixer() {
		return array(
			'manual_alt_rules'    => self::manual_alt_rules(),
			'manual_name_rules'   => self::manual_name_rules(),
			'manual_label_rules'  => self::manual_label_rules(),
			'manual_title_rules'  => self::manual_title_rules(),
			'manual_attr_rules'   => self::manual_attr_rules(),
			'manual_insert_rules' => self::manual_insert_rules(),
		);
	}

	/**
	 * The identity key for an image src (mirrors the Pro
	 * suggestions store): the src's path without host or leading
	 * slash, so the same image matches across schemes/hosts.
	 *
	 * @param string $src Image src (absolute or resolvable).
	 * @return string
	 */
	public static function manual_src_key( $src ) {
		$path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );
		return ltrim( '' !== $path ? $path : (string) $src, '/' );
	}

	/**
	 * Page pattern for scoping a rule (mirrors Pro): path plus
	 * query, so plain-permalink pages (?page_id=25) scope by
	 * their query exactly like Pro's rules do.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function manual_page_pattern( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	/**
	 * Extract an element target from a finding snippet (mirrors
	 * the Pro queue's extractor — same attribute set, so targets
	 * mean the same thing in both stores).
	 *
	 * @param string $snippet Snippet HTML.
	 * @return array Target attributes.
	 */
	public static function manual_target_from_snippet( $snippet ) {
		$target = array( 'tag' => '' );
		if ( ! is_string( $snippet ) || '' === trim( $snippet ) || ! class_exists( 'DOMDocument' ) ) {
			return $target;
		}
		$dom = EqualFix_Parser::parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $snippet . '</body></html>' );
		if ( ! $dom ) {
			return $target;
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		$el   = null;
		if ( $body ) {
			foreach ( $body->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					$el = $child;
					break;
				}
			}
		}
		if ( ! $el ) {
			return $target;
		}
		$target['tag'] = strtolower( $el->tagName );
		foreach ( array( 'id', 'class', 'onclick', 'type', 'placeholder', 'name', 'href', 'src' ) as $attr ) {
			if ( $el->hasAttribute( $attr ) && '' !== trim( $el->getAttribute( $attr ) ) ) {
				$target[ $attr ] = trim( $el->getAttribute( $attr ) );
			}
		}
		if ( preg_match( '/\bfa[srlbd]?-[a-z0-9-]+|\bbi-[a-z0-9-]+|\bdashicons-[a-z0-9-]+/', (string) $snippet, $im ) ) {
			$target['icon'] = $im[0];
		}
		return $target;
	}

	/**
	 * The store hash for a name/label/title rule target.
	 *
	 * @param string $kind   Rule kind.
	 * @param array  $target Target attributes.
	 * @return string
	 */
	public static function manual_rule_hash( $kind, $target ) {
		return md5( $kind . '|' . wp_json_encode( $target ) );
	}

	/**
	 * Whether a rule's page scope covers a URL.
	 *
	 * @param array  $rule Rule (may carry 'pages').
	 * @param string $url  Page URL.
	 * @return bool
	 */
	private static function manual_rule_page_matches( $rule, $url ) {
		if ( empty( $rule['pages'] ) || ! is_array( $rule['pages'] ) ) {
			return true;
		}
		$pattern = self::manual_page_pattern( $url );
		foreach ( $rule['pages'] as $p ) {
			$have = '/' . ltrim( (string) $p, '/' );
			$want = '/' . ltrim( $pattern, '/' );
			$norm = function ( $s ) {
				$q = explode( '?', $s, 2 );
				return rtrim( $q[0], '/' ) . ( isset( $q[1] ) ? '?' . $q[1] : '' );
			};
			if ( $norm( $have ) === $norm( $want ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The manual rule (if any) that fixes one finding.
	 *
	 * @param array $finding Finding.
	 * @return array|null { kind, key, text, decorative }
	 */
	public static function manual_match_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['title'], $finding['url'], $finding['snippet'] ) ) {
			return null;
		}
		// Manual kinds live on review-category findings —
		// all four writable issue types are review findings.
		// Fix-First Wave 2 (2026-11-07): third-party embeds are
		// the one embed-category writable — the frame's title
		// attribute sits in the OWNER's page (only the frame's
		// contents are the provider's), so an embed finding can
		// hold a title rule exactly like an own embed can.
		if ( EqualFix_Audit::CAT_REVIEW !== $finding['category'] && EqualFix_Audit::CAT_EMBED !== $finding['category'] ) {
			return null;
		}
		$title = (string) $finding['title'];
		// Both filename-alt titles (T1-05): the current one and
		// the pre-rename one a stored audit may still carry.
		if ( false !== stripos( $title, 'missing alt text' ) || 'Alt text contains a file name or redundant phrase' === $title || 'Alt text is just a file name' === $title ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			if ( '' === $src ) {
				return null;
			}
			$abs   = EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] );
			$key   = self::manual_src_key( '' !== $abs ? $abs : $src );
			$rules = self::manual_alt_rules();
			if ( isset( $rules[ $key ] ) && self::manual_rule_page_matches( $rules[ $key ], $finding['url'] ) ) {
				return array(
					'kind'       => 'alt',
					'key'        => $key,
					'text'       => isset( $rules[ $key ]['alt'] ) ? (string) $rules[ $key ]['alt'] : '',
					'decorative' => ! empty( $rules[ $key ]['decorative'] ),
				);
			}
			return null;
		}
		if ( false !== stripos( $title, 'has no associated label' ) ) {
			$target = self::manual_target_from_snippet( $finding['snippet'] );
			$hash   = self::manual_rule_hash( 'label', $target );
			$rules  = self::manual_label_rules();
			if ( isset( $rules[ $hash ] ) ) {
				return array( 'kind' => 'label', 'key' => $hash, 'text' => isset( $rules[ $hash ]['label'] ) ? (string) $rules[ $hash ]['label'] : '', 'decorative' => false );
			}
			return null;
		}
		// Symbol-only names (T1-06) take written names through
		// the same store: the job meta offers the name
		// write-box for that family, so a saved name must match
		// here or the card would promise a fix that never counts.
		if ( false !== stripos( $title, 'has no accessible name' ) || false !== stripos( $title, 'name is only a symbol' ) ) {
			$target = self::manual_target_from_snippet( $finding['snippet'] );
			$hash   = self::manual_rule_hash( 'name', $target );
			$rules  = self::manual_name_rules();
			if ( isset( $rules[ $hash ] ) ) {
				return array( 'kind' => 'name', 'key' => $hash, 'text' => isset( $rules[ $hash ]['aria_label'] ) ? (string) $rules[ $hash ]['aria_label'] : '', 'decorative' => false );
			}
			return null;
		}
		if ( 'Embedded frame missing a title' === $title || 'Third-party embed missing a title' === $title ) {
			$target = self::manual_target_from_snippet( $finding['snippet'] );
			$hash   = self::manual_rule_hash( 'title', $target );
			$rules  = self::manual_title_rules();
			if ( isset( $rules[ $hash ] ) ) {
				return array( 'kind' => 'title', 'key' => $hash, 'text' => isset( $rules[ $hash ]['title'] ) ? (string) $rules[ $hash ]['title'] : '', 'decorative' => false );
			}
			return null;
		}
		return null;
	}

	/**
	 * Every manual-fixed finding in an audit, by fingerprint.
	 * Dismissed findings never count as fixed (the model's rule).
	 *
	 * @param array $result Stored audit result.
	 * @return array fingerprint => match (see manual_match_for_finding).
	 */
	public static function manual_matches_map( $result ) {
		$map = array();
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return $map;
		}
		$dismissed = self::dismissed_map();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$match = self::manual_match_for_finding( $finding );
			if ( null !== $match ) {
				$map[ $fp ] = $match;
			}
		}
		return $map;
	}

	/**
	 * Save (or replace) the owner's written fix for one finding.
	 *
	 * @param string $kind       alt|label|name|title.
	 * @param array  $finding    The finding (from the stored audit).
	 * @param string $text       The owner's words ('' + decorative for alt).
	 * @param bool   $decorative Alt only: mark the image decorative.
	 * @return bool
	 */
	public static function save_manual_for_finding( $kind, $finding, $text, $decorative = false ) {
		$text = trim( (string) $text );
		if ( 'alt' === $kind ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			if ( '' === $src ) {
				return false;
			}
			$abs   = EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] );
			$key   = self::manual_src_key( '' !== $abs ? $abs : $src );
			$rules = self::manual_alt_rules();
			$rule  = array(
				'alt'        => $decorative ? '' : $text,
				'pages'      => array( self::manual_page_pattern( $finding['url'] ) ),
				'decorative' => $decorative ? true : false,
			);
			$rules[ $key ] = $rule;
			// S6-02: an identical re-save is a success, not a
			// failure — verify the stored end state.
			return update_option( self::MANUAL_ALT_OPTION, $rules, false )
				|| self::option_entry_matches( self::MANUAL_ALT_OPTION, $key, $rule );
		}
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		if ( '' === $target['tag'] ) {
			return false;
		}
		if ( 'label' === $kind ) {
			$rules = self::manual_label_rules();
			$key   = self::manual_rule_hash( 'label', $target );
			$rule  = array( 'label' => $text, 'target' => $target );
			$rules[ $key ] = $rule;
			return update_option( self::MANUAL_LABEL_OPTION, $rules, false )
				|| self::option_entry_matches( self::MANUAL_LABEL_OPTION, $key, $rule );
		}
		if ( 'name' === $kind ) {
			$rules = self::manual_name_rules();
			$key   = self::manual_rule_hash( 'name', $target );
			$rule  = array( 'aria_label' => $text, 'target' => $target );
			$rules[ $key ] = $rule;
			return update_option( self::MANUAL_NAME_OPTION, $rules, false )
				|| self::option_entry_matches( self::MANUAL_NAME_OPTION, $key, $rule );
		}
		if ( 'title' === $kind ) {
			$rules = self::manual_title_rules();
			$key   = self::manual_rule_hash( 'title', $target );
			$rule  = array( 'title' => $text, 'target' => $target );
			$rules[ $key ] = $rule;
			return update_option( self::MANUAL_TITLE_OPTION, $rules, false )
				|| self::option_entry_matches( self::MANUAL_TITLE_OPTION, $key, $rule );
		}
		return false;
	}

	/**
	 * Remove one manual rule by kind + store key.
	 *
	 * @param string $kind alt|label|name|title.
	 * @param string $key  Store key.
	 * @return bool Whether a rule was removed.
	 */
	public static function remove_manual_rule( $kind, $key ) {
		$options = array(
			'alt'   => self::MANUAL_ALT_OPTION,
			'label' => self::MANUAL_LABEL_OPTION,
			'name'  => self::MANUAL_NAME_OPTION,
			'title' => self::MANUAL_TITLE_OPTION,
		);
		if ( ! isset( $options[ $kind ] ) ) {
			return false;
		}
		$rules = get_option( $options[ $kind ], array() );
		if ( ! is_array( $rules ) || ! isset( $rules[ $key ] ) ) {
			return false;
		}
		unset( $rules[ $key ] );
		update_option( $options[ $kind ], $rules, false );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Fix-First attribute-rule engine (2026-11-07, Waves 1+2).          */
	/* Owner-approved deterministic fixes for the review/guidance        */
	/* groups: a proposal is computed locally from the finding (no       */
	/* backend, no AI), the owner approves it with one click, and the    */
	/* approval is stored as an attribute rule the free fixer applies    */
	/* at render — exactly the manual stores' economy: the rules ride    */
	/* the free master switch, count toward the free 10-rule cap, and    */
	/* are purged on uninstall like every other store.                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The Issues groups the attribute-rule engine serves.
	 *
	 * @return string[]
	 */
	public static function attr_groups() {
		return array( 'contrast', 'tabindex', 'autocomplete', 'viewport', 'aria-ref', 'heading-empty', 'aria-hidden-focus', 'link-generic', 'dup-id', 'interactive' );
	}

	/**
	 * Stored owner-approved attribute rules (key => rule).
	 *
	 * @return array
	 */
	public static function manual_attr_rules() {
		$rules = get_option( self::MANUAL_ATTR_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * All attributes of the first element in a finding snippet.
	 *
	 * @param string $snippet Snippet HTML.
	 * @return array { tag: string, attrs: array<string,string> }
	 */
	private static function snippet_element( $snippet ) {
		$out = array( 'tag' => '', 'attrs' => array() );
		if ( ! is_string( $snippet ) || '' === trim( $snippet ) || ! class_exists( 'DOMDocument' ) ) {
			return $out;
		}
		$dom = EqualFix_Parser::parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $snippet . '</body></html>' );
		if ( ! $dom ) {
			return $out;
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $out;
		}
		foreach ( $body->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$out['tag'] = strtolower( $child->tagName );
				if ( $child->attributes ) {
					foreach ( $child->attributes as $attr ) {
						$out['attrs'][ strtolower( $attr->name ) ] = $attr->value;
					}
				}
				break;
			}
		}
		return $out;
	}

	/**
	 * The first element of a finding snippet as a DOM node
	 * (Waves 3+4): proposers that must inspect structure —
	 * descendants, referenced names — rather than just the
	 * element's own attributes.
	 *
	 * @param string $snippet Snippet HTML.
	 * @return DOMElement|null
	 */
	private static function snippet_dom_element( $snippet ) {
		if ( ! is_string( $snippet ) || '' === trim( $snippet ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = EqualFix_Parser::parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $snippet . '</body></html>' );
		if ( ! $dom ) {
			return null;
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return null;
		}
		foreach ( $body->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * The identity normalisation for a style attribute value,
	 * shared in spirit with the fixer's copy: whitespace runs
	 * collapse and spaces around ':' and ';' vanish, so the
	 * same declaration written two ways keys identically.
	 *
	 * @param string $style Style attribute value.
	 * @return string
	 */
	public static function normalize_style_value( $style ) {
		$style = trim( (string) preg_replace( '/\s+/', ' ', (string) $style ) );
		$style = (string) preg_replace( '/\s*;\s*/', ';', $style );
		$style = (string) preg_replace( '/\s*:\s*/', ':', $style );
		return rtrim( $style, ';' );
	}

	/**
	 * The target descriptor for an attribute rule: the manual
	 * target (same attribute set as the manual stores, so
	 * targets mean the same thing everywhere) PLUS the style
	 * attribute when the element carries one (a contrast
	 * element's inline style IS its identity — without it a
	 * tag-only target would match every such element) and any
	 * ARIA reference attributes (their values identify the
	 * broken-reference element and make application
	 * idempotent: once fixed, the element no longer matches).
	 *
	 * @param string $snippet Snippet HTML.
	 * @return array Target attributes.
	 */
	public static function attr_target_from_snippet( $snippet ) {
		$target = self::manual_target_from_snippet( $snippet );
		$el     = self::snippet_element( $snippet );
		// The style string is deliberately NOT part of the
		// target: declaration order and spacing are the owner's
		// to change, and the contrast op's own guard already
		// verifies the one declaration it touches (the colour)
		// is still the audited value before writing.
		foreach ( array( 'aria-describedby', 'aria-labelledby', 'aria-controls', 'aria-owns', 'aria-flowto', 'aria-activedescendant' ) as $attr ) {
			if ( isset( $el['attrs'][ $attr ] ) && '' !== trim( $el['attrs'][ $attr ] ) ) {
				$target[ $attr ] = trim( $el['attrs'][ $attr ] );
			}
		}
		return $target;
	}

	/**
	 * The store key for an attribute rule: group + target +
	 * page scope (the manual alt store's page-scoped identity
	 * discipline — the same element on two pages is two
	 * records, each scoped to its own page, so a Fix all over
	 * several pages never collapses them into one rule whose
	 * scope silently moves).
	 *
	 * @param string $group  Attr group slug.
	 * @param array  $target Target descriptor.
	 * @param array  $pages  Page patterns the rule is scoped to.
	 * @return string
	 */
	/**
	 * A CSS colour value as '#rrggbb' when it is written as a
	 * hex or rgb()/rgba() functional value (alpha ignored —
	 * the channels are the identity); null for named colours
	 * and anything else.
	 *
	 * @param string $value Colour value.
	 * @return string|null
	 */
	public static function css_color_hex( $value ) {
		$v = strtolower( trim( (string) $value ) );
		if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v, $m ) ) {
			$hex = $m[1];
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return '#' . $hex;
		}
		if ( 1 === preg_match( '/^rgba?\(([^)]*)\)$/', $v, $m ) ) {
			$parts = array_map( 'trim', explode( ',', $m[1] ) );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$out = '#';
			for ( $i = 0; $i < 3; $i++ ) {
				if ( '%' === substr( $parts[ $i ], -1 ) ) {
					$n = (int) round( (float) substr( $parts[ $i ], 0, -1 ) * 255 / 100 );
				} elseif ( is_numeric( $parts[ $i ] ) ) {
					$n = (int) round( (float) $parts[ $i ] );
				} else {
					return null;
				}
				$out .= sprintf( '%02x', max( 0, min( 255, $n ) ) );
			}
			return $out;
		}
		return null;
	}

	/**
	 * The target a rule for one finding is stored under: the
	 * snippet descriptor, plus — for contrast — the colour
	 * itself ('style-color'). A bare element's descriptor can
	 * be identical for two different low-contrast elements on
	 * one page; without the colour in the target, one rule
	 * would claim both in the coverage model while the fixer
	 * (rightly) recolours only the audited one. The colour is
	 * the raw inline value when we can normalise it, else the
	 * measured foreground.
	 *
	 * @param array      $finding  Finding.
	 * @param array|null $proposal The finding's proposal.
	 * @return array
	 */
	public static function attr_target_for_proposal( $finding, $proposal ) {
		$target = self::attr_target_from_snippet( $finding['snippet'] );
		if ( is_array( $proposal ) && isset( $proposal['group'] ) && 'contrast' === $proposal['group'] ) {
			$target['style-color'] = ! empty( $proposal['fg_raw'] ) ? $proposal['fg_raw'] : ( isset( $proposal['fg'] ) ? $proposal['fg'] : '' );
		}
		return $target;
	}

	public static function attr_rule_key( $group, $target, $pages = array() ) {
		$scope = array_values( (array) $pages );
		sort( $scope );
		return md5( 'attr|' . $group . '|' . wp_json_encode( $target ) . '|' . implode( ',', $scope ) );
	}

	/**
	 * Parse a hex colour (#rgb / #rrggbb) to channels.
	 *
	 * @param string $hex Colour.
	 * @return array|null {r,g,b} 0–255 floats.
	 */
	private static function color_channels( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( 1 !== preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $hex ) ) {
			return null;
		}
		$hex = substr( $hex, 1 );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array(
			'r' => (float) hexdec( substr( $hex, 0, 2 ) ),
			'g' => (float) hexdec( substr( $hex, 2, 2 ) ),
			'b' => (float) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * The WCAG contrast ratio between two hex colours — the
	 * audit's own maths (sRGB relative luminance), ported so
	 * proposals are computed with the same ruler the audit
	 * measures with.
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 * @return float Ratio 1.0–21.0 (0 when a colour is unreadable).
	 */
	public static function contrast_ratio_hex( $a, $b ) {
		$ca = self::color_channels( $a );
		$cb = self::color_channels( $b );
		if ( null === $ca || null === $cb ) {
			return 0.0;
		}
		$lum = function ( $c ) {
			$f = function ( $channel ) {
				$s = $channel / 255;
				return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
			};
			return 0.2126 * $f( $c['r'] ) + 0.7152 * $f( $c['g'] ) + 0.0722 * $f( $c['b'] );
		};
		$l1 = $lum( $ca );
		$l2 = $lum( $cb );
		if ( $l1 < $l2 ) {
			$tmp = $l1;
			$l1  = $l2;
			$l2  = $tmp;
		}
		return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
	}

	/**
	 * RGB (0–255) -> HSL (h 0–360, s 0–1, l 0–1).
	 *
	 * @param array $c {r,g,b}.
	 * @return array {h,s,l}
	 */
	private static function rgb_to_hsl( $c ) {
		$r   = $c['r'] / 255;
		$g   = $c['g'] / 255;
		$b   = $c['b'] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return array( 'h' => 0.0, 's' => 0.0, 'l' => $l );
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		return array( 'h' => $h * 60, 's' => $s, 'l' => $l );
	}

	/**
	 * HSL -> hex colour.
	 *
	 * @param float $h Hue 0–360.
	 * @param float $s Saturation 0–1.
	 * @param float $l Lightness 0–1.
	 * @return string #rrggbb.
	 */
	private static function hsl_to_hex( $h, $s, $l ) {
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		if ( $h < 60 ) {
			$rgb = array( $c, $x, 0 );
		} elseif ( $h < 120 ) {
			$rgb = array( $x, $c, 0 );
		} elseif ( $h < 180 ) {
			$rgb = array( 0, $c, $x );
		} elseif ( $h < 240 ) {
			$rgb = array( 0, $x, $c );
		} elseif ( $h < 300 ) {
			$rgb = array( $x, 0, $c );
		} else {
			$rgb = array( $c, 0, $x );
		}
		return sprintf( '#%02x%02x%02x', (int) round( ( $rgb[0] + $m ) * 255 ), (int) round( ( $rgb[1] + $m ) * 255 ), (int) round( ( $rgb[2] + $m ) * 255 ) );
	}

	/**
	 * THE contrast fix (Fix-First flagship): the nearest
	 * passing shade of the owner's own colour. Hue and
	 * saturation are preserved exactly; only lightness moves,
	 * in whichever direction (toward black or toward white)
	 * reaches the needed ratio with the smaller change, plus
	 * a small safety margin so rounding never lands a hair
	 * under the line. Deterministic — the same inputs always
	 * produce the same colour, and the audit measures it with
	 * the same maths, so a re-audit agrees it passes.
	 *
	 * @param string $fg   Foreground hex.
	 * @param string $bg   Background hex.
	 * @param float  $need Required ratio (4.5, or 3.0 for large text).
	 * @return string Passing #rrggbb, or the input when unreadable.
	 */
	public static function contrast_new_color( $fg, $bg, $need ) {
		$channels = self::color_channels( $fg );
		if ( null === $channels || null === self::color_channels( $bg ) ) {
			return strtolower( (string) $fg );
		}
		$need   = (float) $need;
		$target = $need * 1.03; // The small margin.
		$hsl    = self::rgb_to_hsl( $channels );
		$best   = null;
		foreach ( array( -1, 1 ) as $dir ) {
			$lo = -1 === $dir ? 0.0 : $hsl['l'];
			$hi = -1 === $dir ? $hsl['l'] : 1.0;
			// The far end of this direction must be able to pass.
			if ( self::contrast_ratio_hex( self::hsl_to_hex( $hsl['h'], $hsl['s'], -1 === $dir ? 0.0 : 1.0 ), $bg ) < $target ) {
				continue;
			}
			// Binary search for the lightness closest to the
			// original that reaches the target ratio.
			for ( $i = 0; $i < 40; $i++ ) {
				$mid = ( $lo + $hi ) / 2;
				if ( self::contrast_ratio_hex( self::hsl_to_hex( $hsl['h'], $hsl['s'], $mid ), $bg ) >= $target ) {
					if ( -1 === $dir ) {
						$lo = $mid;
					} else {
						$hi = $mid;
					}
				} else {
					if ( -1 === $dir ) {
						$hi = $mid;
					} else {
						$lo = $mid;
					}
				}
			}
			$candidate = -1 === $dir ? $lo : $hi;
			$delta     = abs( $candidate - $hsl['l'] );
			if ( null === $best || $delta < $best['delta'] - 1e-9 ) {
				$best = array( 'l' => $candidate, 'delta' => $delta, 'dir' => $dir );
			}
		}
		if ( null === $best ) {
			// Neither direction reaches the target (possible
			// only against a mid-luminance background at AAA
			// ratios): take whichever end scores higher.
			$dark  = self::hsl_to_hex( $hsl['h'], $hsl['s'], 0.0 );
			$light = self::hsl_to_hex( $hsl['h'], $hsl['s'], 1.0 );
			return self::contrast_ratio_hex( $dark, $bg ) >= self::contrast_ratio_hex( $light, $bg ) ? $dark : $light;
		}
		$l   = $best['l'];
		$out = self::hsl_to_hex( $hsl['h'], $hsl['s'], $l );
		// Rounding to 8-bit channels can dip a hair under the
		// requirement: walk outward in small steps until the
		// ROUNDED colour genuinely passes.
		$guard = 0;
		while ( self::contrast_ratio_hex( $out, $bg ) < $need && $guard < 200 ) {
			$l += $best['dir'] * 0.004;
			if ( $l < 0 || $l > 1 ) {
				break;
			}
			$out = self::hsl_to_hex( $hsl['h'], $hsl['s'], $l );
			$guard++;
		}
		return $out;
	}

	/**
	 * The deterministic fix proposal for one finding, or null
	 * when the engine has no safe fix for it (the row then
	 * keeps its shipped guidance treatment). Computed locally
	 * at render/click time from the finding alone — no
	 * backend, no AI. Shape: { group, label, ops, before,
	 * after } plus group-specific display fields. Each op:
	 * { attr, kind: set|style-color, value, guard, data } —
	 * the guard names the don't-stomp test the fixer runs
	 * before applying, so a page that changed since the audit
	 * is never overwritten.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	public function attr_proposal_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['title'], $finding['snippet'] ) ) {
			return null;
		}
		$meta  = $this->finding_job_meta( $finding );
		$group = $meta['group'];
		if ( ! in_array( $group, self::attr_groups(), true ) ) {
			return null;
		}
		switch ( $group ) {
			case 'contrast':
				return $this->proposal_contrast( $finding );
			case 'tabindex':
				return $this->proposal_tabindex( $finding );
			case 'autocomplete':
				return $this->proposal_autocomplete( $finding );
			case 'viewport':
				return $this->proposal_viewport( $finding );
			case 'aria-ref':
				return $this->proposal_aria_ref( $finding );
			case 'heading-empty':
				return array(
					'group'  => 'heading-empty',
					'label'  => __( 'Empty heading hidden', 'equalfix' ),
					'ops'    => array( array( 'attr' => 'hidden', 'kind' => 'set', 'value' => '', 'guard' => 'still-empty', 'data' => array() ) ),
					'before' => __( 'An empty heading sits in the page outline', 'equalfix' ),
					'after'  => __( 'The empty heading is hidden — out of view and out of the outline', 'equalfix' ),
				);
			case 'aria-hidden-focus':
				return array(
					'group'  => 'aria-hidden-focus',
					'label'  => __( 'Control taken out of the tab order', 'equalfix' ),
					'ops'    => array( array( 'attr' => 'tabindex', 'kind' => 'set', 'value' => '-1', 'guard' => 'focusable-open', 'data' => array() ) ),
					'before' => __( 'Keyboard users can tab to a control screen readers never announce', 'equalfix' ),
					'after'  => __( 'The control leaves the tab order (tabindex="-1") — the standard fix for hidden duplicates, sliders and popups', 'equalfix' ),
				);
			case 'link-generic':
				return $this->proposal_link_generic( $finding );
			case 'dup-id':
				return $this->proposal_dup_id( $finding );
			case 'interactive':
				return $this->proposal_interactive( $finding );
		}
		return null;
	}

	/**
	 * Waves 3+4 — generic link text ("click here", "read
	 * more"): the fix is a real accessible NAME delivered as
	 * an aria-label — the words visitors see never change.
	 * The name comes only from deterministic sources, in
	 * order: (a) the link's own title attribute, when it holds
	 * meaningful words; (b) an internal destination resolved
	 * with url_to_postid() → that page's title; (c) nothing —
	 * the proposal is flagged needs-text and the panel asks
	 * the owner for the words (a save without them is
	 * refused). No network fetch happens anywhere: both
	 * sources are already on this server.
	 *
	 * A name that comes from aria-labelledby cannot be
	 * overridden by an aria-label, so those links get no
	 * proposal at all.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_link_generic( $finding ) {
		$el = self::snippet_element( $finding['snippet'] );
		if ( 'a' !== $el['tag'] || isset( $el['attrs']['aria-labelledby'] ) ) {
			return null;
		}
		$generic = array( 'click here', 'here', 'read more', 'learn more', 'more', 'link', 'this link', 'go', 'continue' );
		$norm    = static function ( $text ) {
			$text = strtolower( trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $text ) ) );
			return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $text ) ) );
		};
		$value      = '';
		$needs_text = false;
		if ( isset( $el['attrs']['title'] ) && '' !== trim( $el['attrs']['title'] ) && ! in_array( $norm( $el['attrs']['title'] ), $generic, true ) ) {
			$value = trim( $el['attrs']['title'] );
		} elseif ( isset( $el['attrs']['href'] ) && '' !== trim( $el['attrs']['href'] ) && class_exists( 'EqualFix_Pro_Installer' ) ) {
			$abs     = EqualFix_Pro_Installer::absolute_url( trim( $el['attrs']['href'] ), isset( $finding['url'] ) ? $finding['url'] : '' );
			$post_id = '' !== $abs ? url_to_postid( $abs ) : 0;
			if ( $post_id > 0 ) {
				$title = get_the_title( $post_id );
				if ( is_string( $title ) && '' !== trim( $title ) && ! in_array( $norm( $title ), $generic, true ) ) {
					$value = trim( $title );
				}
			}
		}
		if ( '' === $value ) {
			$needs_text = true;
		}
		return array(
			'group'      => 'link-generic',
			'label'      => '' !== $value
				? sprintf( __( 'Link name for screen readers: “%s”', 'equalfix' ), $value )
				: __( 'Link name for screen readers (your words)', 'equalfix' ),
			'ops'        => array( array( 'attr' => 'aria-label', 'kind' => 'set', 'value' => $value, 'guard' => 'generic-name', 'data' => array() ) ),
			'before'     => __( 'Screen readers announce this link as its visible words — “click here”, “read more” and the like', 'equalfix' ),
			'after'      => __( 'Screen readers announce the name below instead. Only the name changes — the words visitors see stay the same.', 'equalfix' ),
			'needs_text' => $needs_text,
			'text_value' => $value,
		);
	}

	/**
	 * Waves 3+4 — duplicate ids: the audit's finding element
	 * is always the SECOND presented copy of the id, so the
	 * proposal renames that later copy (to id-2, or the first
	 * free id-N at render). References are never rewritten —
	 * they keep pointing at the first copy, which keeps the
	 * original id.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_dup_id( $finding ) {
		$id = '';
		if ( isset( $finding['title'] ) && 1 === preg_match( '/Duplicate id attribute: "([^"]+)"/', (string) $finding['title'], $m ) ) {
			$id = $m[1];
		}
		if ( '' === $id ) {
			$el = self::snippet_element( $finding['snippet'] );
			if ( isset( $el['attrs']['id'] ) && '' !== trim( $el['attrs']['id'] ) ) {
				$id = trim( $el['attrs']['id'] );
			}
		}
		if ( '' === $id ) {
			return null;
		}
		return array(
			'group'  => 'dup-id',
			'label'  => sprintf( __( 'Later copy renamed: %1$s → %2$s', 'equalfix' ), $id, $id . '-2' ),
			'ops'    => array( array( 'attr' => 'id', 'kind' => 'rename-dup', 'value' => '', 'guard' => 'dup-id', 'data' => array( 'from' => $id, 'occurrence' => 2 ) ) ),
			'before' => sprintf( __( 'Two elements on this page share the id “%s” — links and scripts that point at it can only ever reach the first one', 'equalfix' ), $id ),
			'after'  => sprintf( __( 'The later copy is renamed to “%1$s” (or the next free number). Anything pointing at “%2$s” still reaches the first copy, unchanged.', 'equalfix' ), $id . '-2', $id ),
			'dup_id' => $id,
		);
	}

	/**
	 * Waves 3+4 — a clickable element that is not a button:
	 * the all-or-nothing kit (role + tabindex + the marker
	 * the delegated key handler listens for). The audit only
	 * flags elements carrying a click handler attribute, and
	 * that attribute is part of the recorded target — no click
	 * evidence in the audit data, no proposal. Elements that
	 * already carry a role or tabindex, or that wrap a real
	 * control, are excluded here AND again at render.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_interactive( $finding ) {
		$el = self::snippet_dom_element( $finding['snippet'] );
		if ( ! $el ) {
			return null;
		}
		if ( in_array( strtolower( $el->tagName ), array( 'a', 'button', 'input', 'select', 'textarea', 'summary', 'audio', 'video' ), true ) ) {
			return null;
		}
		if ( ! $el->hasAttribute( 'onclick' ) || '' === trim( $el->getAttribute( 'onclick' ) ) ) {
			return null;
		}
		if ( $el->hasAttribute( 'role' ) || $el->hasAttribute( 'tabindex' ) ) {
			return null;
		}
		foreach ( $el->getElementsByTagName( '*' ) as $desc ) {
			if ( in_array( strtolower( $desc->tagName ), array( 'a', 'button', 'input', 'select', 'textarea', 'summary' ), true )
				|| $desc->hasAttribute( 'role' ) || $desc->hasAttribute( 'tabindex' ) || $desc->hasAttribute( 'onclick' ) ) {
				return null;
			}
		}
		return array(
			'group' => 'interactive',
			'label' => __( 'Made into a real button for keyboards', 'equalfix' ),
			'ops'   => array(
				array( 'attr' => 'role', 'kind' => 'set', 'value' => 'button', 'guard' => 'no-role', 'data' => array() ),
				array( 'attr' => 'tabindex', 'kind' => 'set', 'value' => '0', 'guard' => 'no-tabindex', 'data' => array() ),
				array( 'attr' => 'data-equalfix-interactive', 'kind' => 'set', 'value' => '', 'guard' => 'absent', 'data' => array( 'marker' => true ) ),
			),
			'before' => __( 'This element reacts to a mouse click, but keyboard users cannot reach it or trigger it', 'equalfix' ),
			'after'  => __( 'It joins the tab order as a button, and Enter or Space triggers the same click — the full kit, or nothing is applied.', 'equalfix' ),
		);
	}

	/**
	 * Contrast proposal: fg/bg/need from the finding's
	 * structured keys (new audits) or the explanation text
	 * (stored audits — the explanation carries the same
	 * measured values). Whether the colour was inline, and
	 * whether the winning declaration was !important, comes
	 * from the audit's fg_imp key or, for stored findings,
	 * from the snippet's own inline style.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_contrast( $finding ) {
		$fg    = null;
		$bg    = null;
		$need  = null;
		$imp   = false;
		$ratio = null;
		if ( isset( $finding['fg'], $finding['bg'], $finding['need'] ) && null !== self::color_channels( $finding['fg'] ) && null !== self::color_channels( $finding['bg'] ) ) {
			$fg   = strtolower( (string) $finding['fg'] );
			$bg   = strtolower( (string) $finding['bg'] );
			$need = (float) $finding['need'];
			$imp  = ! empty( $finding['fg_imp'] );
		} elseif ( isset( $finding['explanation'] ) && 1 === preg_match( '/(#[0-9a-fA-F]{6}) text on (#[0-9a-fA-F]{6})\), below the ([0-9.]+):1/', (string) $finding['explanation'], $m ) ) {
			$fg   = strtolower( $m[1] );
			$bg   = strtolower( $m[2] );
			$need = (float) $m[3];
		}
		if ( null === $fg || null === $bg || null === $need ) {
			return null;
		}
		if ( isset( $finding['explanation'] ) && 1 === preg_match( '/measure ([0-9.]+):1/', (string) $finding['explanation'], $rm ) ) {
			$ratio = (float) $rm[1];
		}
		// Inline origin: the snippet element itself carries a
		// color declaration. (For stored findings this is also
		// where !important is re-derived from.)
		$el        = self::snippet_element( $finding['snippet'] );
		$inline    = false;
		$inlineimp = false;
		$inlineval = '';
		if ( isset( $el['attrs']['style'] ) ) {
			foreach ( explode( ';', $el['attrs']['style'] ) as $decl ) {
				$kv = explode( ':', $decl, 2 );
				if ( 2 === count( $kv ) && 'color' === strtolower( trim( $kv[0] ) ) ) {
					$inline    = true;
					$inlineimp = 1 === preg_match( '/!\s*important/i', $kv[1] );
					$inlineval = trim( (string) preg_replace( '/!\s*important.*$/i', '', $kv[1] ) );
				}
			}
		}
		if ( ! isset( $finding['fg_imp'] ) ) {
			$imp = $inline && $inlineimp;
		}
		// The RAW inline colour (uncomposited), when the
		// declaration is written in a form we can normalise:
		// the fixer's don't-stomp guard compares raw-to-raw,
		// so an alpha colour (whose measured fg is a composite
		// over the background) still matches its own element,
		// and a DIFFERENT colour on a lookalike element never
		// does. Named colours return null here — for those the
		// measured fg (opaque, so identical) is the comparison.
		$fg_raw = $inline ? self::css_color_hex( $inlineval ) : null;
		$new = self::contrast_new_color( $fg, $bg, $need );
		return array(
			'group'     => 'contrast',
			'label'     => sprintf( __( 'Text colour %1$s → %2$s', 'equalfix' ), $fg, $new ),
			'ops'       => array( array( 'attr' => 'style', 'kind' => 'style-color', 'value' => $new, 'guard' => 'style-color', 'data' => array( 'fg' => $fg, 'fg_raw' => $fg_raw, 'inline' => $inline, 'imp' => $imp ) ) ),
			'fg_raw'    => $fg_raw,
			'before'    => $fg,
			'after'     => $new,
			'fg'        => $fg,
			'bg'        => $bg,
			'need'      => $need,
			'large'     => $need < 4.5,
			'old_ratio' => null !== $ratio ? $ratio : self::contrast_ratio_hex( $fg, $bg ),
			'new_color' => $new,
			'new_ratio' => self::contrast_ratio_hex( $new, $bg ),
		);
	}

	/**
	 * Tabindex proposal: 0 (join the natural order) — the
	 * panel also offers -1 (focusable by scripts only), which
	 * arrives as the save's value override.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_tabindex( $finding ) {
		$el = self::snippet_element( $finding['snippet'] );
		if ( ! isset( $el['attrs']['tabindex'] ) || 1 !== preg_match( '/^\d+$/', trim( $el['attrs']['tabindex'] ) ) || (int) $el['attrs']['tabindex'] <= 0 ) {
			return null;
		}
		return array(
			'group'   => 'tabindex',
			'label'   => __( 'Tab order: join the natural order (tabindex 0)', 'equalfix' ),
			'ops'     => array( array( 'attr' => 'tabindex', 'kind' => 'set', 'value' => '0', 'guard' => 'tabindex-positive', 'data' => array() ) ),
			'before'  => 'tabindex="' . trim( $el['attrs']['tabindex'] ) . '"',
			'after'   => 'tabindex="0"',
			'choices' => array( '0', '-1' ),
		);
	}

	/**
	 * Autocomplete proposal: the WCAG 1.3.5 token the audit's
	 * own inference signals point at — type first, then the
	 * name/id purpose words the check itself uses. Ambiguous
	 * name signals resolve to "name", exactly as the plan
	 * specifies; no signal, no proposal.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_autocomplete( $finding ) {
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		$type   = isset( $target['type'] ) ? strtolower( trim( $target['type'] ) ) : '';
		$token  = null;
		if ( 'email' === $type ) {
			$token = 'email';
		} elseif ( 'tel' === $type ) {
			$token = 'tel';
		} elseif ( 'url' === $type ) {
			$token = 'url';
		}
		if ( null === $token ) {
			$values = array();
			foreach ( array( 'name', 'id' ) as $attr ) {
				if ( isset( $target[ $attr ] ) && '' !== trim( $target[ $attr ] ) ) {
					$values[] = strtolower( trim( $target[ $attr ] ) );
				}
			}
			$segments = array();
			foreach ( $values as $value ) {
				foreach ( preg_split( '/[_\-\s]+/', $value ) as $segment ) {
					$segments[] = $segment;
				}
			}
			$whole = implode( ' ', $values );
			if ( in_array( 'given', $segments, true ) || false !== strpos( $whole, 'first_name' ) || false !== strpos( $whole, 'firstname' ) || in_array( 'fname', $segments, true ) ) {
				$token = 'given-name';
			} elseif ( in_array( 'family', $segments, true ) || in_array( 'surname', $segments, true ) || false !== strpos( $whole, 'last_name' ) || false !== strpos( $whole, 'lastname' ) || in_array( 'lname', $segments, true ) ) {
				$token = 'family-name';
			} elseif ( in_array( 'email', $segments, true ) || in_array( 'mail', $segments, true ) ) {
				$token = 'email';
			} elseif ( in_array( 'tel', $segments, true ) || in_array( 'phone', $segments, true ) || in_array( 'mobile', $segments, true ) ) {
				$token = 'tel';
			} elseif ( in_array( 'address', $segments, true ) ) {
				$token = 'street-address';
			} elseif ( in_array( 'city', $segments, true ) ) {
				$token = 'address-level2';
			} elseif ( in_array( 'state', $segments, true ) ) {
				$token = 'address-level1';
			} elseif ( in_array( 'zip', $segments, true ) || in_array( 'postal', $segments, true ) ) {
				$token = 'postal-code';
			} elseif ( in_array( 'country', $segments, true ) ) {
				$token = 'country-name';
			} elseif ( in_array( 'username', $segments, true ) || in_array( 'user_name', $segments, true ) ) {
				$token = 'username';
			} elseif ( in_array( 'name', $segments, true ) ) {
				$token = 'name';
			}
		}
		if ( null === $token ) {
			return null;
		}
		return array(
			'group'  => 'autocomplete',
			'label'  => sprintf( __( 'Autocomplete hint "%s"', 'equalfix' ), $token ),
			'ops'    => array( array( 'attr' => 'autocomplete', 'kind' => 'set', 'value' => $token, 'guard' => 'no-autocomplete', 'data' => array() ) ),
			'before' => __( 'No autocomplete hint', 'equalfix' ),
			'after'  => 'autocomplete="' . $token . '"',
		);
	}

	/**
	 * Viewport proposal: the held content string with the
	 * zoom-blocking parts removed — user-scalable=no/0 dropped,
	 * a maximum-scale below 2 dropped (the cap goes away
	 * entirely, which is the clean fix the audit's own guidance
	 * names). Everything else is kept verbatim.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_viewport( $finding ) {
		$el = self::snippet_element( $finding['snippet'] );
		if ( 'meta' !== $el['tag'] || ! isset( $el['attrs']['content'] ) ) {
			return null;
		}
		$content = (string) $el['attrs']['content'];
		$kept    = array();
		$blocked = false;
		foreach ( preg_split( '/[,;]/', $content ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$kv = explode( '=', $part, 2 );
			if ( 2 === count( $kv ) ) {
				$key   = strtolower( trim( $kv[0] ) );
				$value = strtolower( trim( $kv[1] ) );
				if ( 'user-scalable' === $key && ( 'no' === $value || '0' === $value ) ) {
					$blocked = true;
					continue;
				}
				if ( 'maximum-scale' === $key && is_numeric( $value ) && (float) $value < 2 ) {
					$blocked = true;
					continue;
				}
			}
			$kept[] = $part;
		}
		if ( ! $blocked ) {
			return null;
		}
		$after = implode( ', ', $kept );
		if ( '' === $after ) {
			$after = 'width=device-width, initial-scale=1';
		}
		return array(
			'group'  => 'viewport',
			'label'  => __( 'Zoom re-enabled (viewport)', 'equalfix' ),
			'ops'    => array( array( 'attr' => 'content', 'kind' => 'set', 'value' => $after, 'guard' => 'zoom-disabled', 'data' => array() ) ),
			'before' => $content,
			'after'  => $after,
		);
	}

	/**
	 * Broken-ARIA-reference proposal: per affected attribute,
	 * the dangling ids (named in the finding's explanation —
	 * the audit's own words for which references resolve
	 * nowhere) dropped, the valid ids kept. An attribute left
	 * with no ids becomes the empty string: the tiered engine
	 * has no attribute-removal op, and an empty reference is
	 * inert.
	 *
	 * @param array $finding Finding.
	 * @return array|null
	 */
	private function proposal_aria_ref( $finding ) {
		if ( ! isset( $finding['explanation'] ) || ! preg_match_all( '/(aria-[a-z-]+)="([^"]+)"/', (string) $finding['explanation'], $pairs, PREG_SET_ORDER ) ) {
			return null;
		}
		$dangling = array();
		foreach ( $pairs as $pair ) {
			$dangling[ strtolower( $pair[1] ) ][] = $pair[2];
		}
		$el       = self::snippet_element( $finding['snippet'] );
		$ops      = array();
		$before   = array();
		$after    = array();
		foreach ( $dangling as $attr => $ids ) {
			if ( ! isset( $el['attrs'][ $attr ] ) ) {
				continue;
			}
			$current = preg_split( '/\s+/', trim( $el['attrs'][ $attr ] ) );
			$kept    = array_values( array_diff( $current, $ids ) );
			$ops[]   = array( 'attr' => $attr, 'kind' => 'set', 'value' => implode( ' ', $kept ), 'guard' => 'dangling-present', 'data' => array( 'ids' => array_values( $ids ) ) );
			$before[] = $attr . '="' . trim( $el['attrs'][ $attr ] ) . '"';
			$after[]  = $attr . '="' . implode( ' ', $kept ) . '"';
		}
		if ( empty( $ops ) ) {
			return null;
		}
		return array(
			'group'  => 'aria-ref',
			'label'  => __( 'Broken ARIA reference repaired', 'equalfix' ),
			'ops'    => $ops,
			'before' => implode( ' ', $before ),
			'after'  => implode( ' ', $after ),
		);
	}

	/**
	 * Save (or replace) the owner's approved attribute rule
	 * for one finding — the write the save_attr_rule and
	 * fix-all handlers perform. The proposal is recomputed
	 * here from the stored finding, never taken from POST, so
	 * what applies is always the deterministic fix. The free
	 * cap applies exactly as for the manual stores.
	 *
	 * @param array       $finding        The finding (from the stored audit).
	 * @param string|null $value_override Panel choice (tabindex "-1").
	 * @return bool
	 */
	public static function save_attr_rule_for_finding( $finding, $value_override = null, $text_override = null ) {
		$inst     = new self();
		$proposal = $inst->attr_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			return false;
		}
		// Waves 3+4 link-generic: the owner may supply or
		// correct the announced name. A proposal flagged
		// needs-text has NO deterministic name — a save
		// without owner words is refused outright (nothing
		// invented, nothing stored empty). Supplied words
		// must be real words, not the generic phrase again.
		if ( 'link-generic' === $proposal['group'] ) {
			$text = null !== $text_override ? trim( (string) $text_override ) : '';
			if ( '' !== $text ) {
				$generic = array( 'click here', 'here', 'read more', 'learn more', 'more', 'link', 'this link', 'go', 'continue' );
				$norm    = strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ) ) ) );
				if ( in_array( $norm, $generic, true ) || strlen( $text ) > 200 ) {
					return false;
				}
				$proposal['ops'][0]['value'] = $text;
				$proposal['label']           = sprintf( __( 'Link name for screen readers: “%s”', 'equalfix' ), $text );
				$proposal['needs_text']      = false;
				$proposal['text_value']      = $text;
			} elseif ( ! empty( $proposal['needs_text'] ) ) {
				return false;
			}
		}
		if ( null !== $value_override && ! empty( $proposal['choices'] ) && in_array( (string) $value_override, $proposal['choices'], true ) ) {
			$proposal['ops'][0]['value'] = (string) $value_override;
			if ( 'tabindex' === $proposal['group'] ) {
				$proposal['label'] = '-1' === (string) $value_override
					? __( 'Tab order: scripts only (tabindex -1)', 'equalfix' )
					: __( 'Tab order: join the natural order (tabindex 0)', 'equalfix' );
				$proposal['after'] = 'tabindex="' . (string) $value_override . '"';
			}
		}
		$target = self::attr_target_for_proposal( $finding, $proposal );
		if ( '' === $target['tag'] ) {
			return false;
		}
		$pages = array( self::manual_page_pattern( $finding['url'] ) );
		$key   = self::attr_rule_key( $proposal['group'], $target, $pages );
		if ( self::manual_cap_blocks( 'attr', $key ) ) {
			return false;
		}
		// Waves 3+4 interactive kit: the marker attribute's
		// value IS the rule key, so the delegated key handler
		// and the Fixed-tab listing can name the exact rule
		// that stamped an element. Substituted here, once the
		// key exists.
		if ( 'interactive' === $proposal['group'] ) {
			foreach ( $proposal['ops'] as $i => $op ) {
				if ( isset( $op['data']['marker'] ) ) {
					$proposal['ops'][ $i ]['value'] = $key;
				}
			}
		}
		$rules         = self::manual_attr_rules();
		$rule          = array(
			'target' => $target,
			'pages'  => $pages,
			'group'  => $proposal['group'],
			'ops'    => $proposal['ops'],
			'label'  => $proposal['label'],
		);
		$rules[ $key ] = $rule;
		return update_option( self::MANUAL_ATTR_OPTION, $rules, false )
			|| self::option_entry_matches( self::MANUAL_ATTR_OPTION, $key, $rule );
	}

	/**
	 * The attribute rule (if any) that fixes one finding.
	 *
	 * @param array $finding Finding.
	 * @return array|null { key, group, label }
	 */
	public static function attr_match_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['title'], $finding['url'], $finding['snippet'] ) ) {
			return null;
		}
		$inst  = new self();
		$meta  = $inst->finding_job_meta( $finding );
		$group = $meta['group'];
		if ( ! in_array( $group, self::attr_groups(), true ) ) {
			return null;
		}
		$target = self::attr_target_for_proposal( $finding, $inst->attr_proposal_for_finding( $finding ) );
		if ( '' === $target['tag'] ) {
			return null;
		}
		$key   = self::attr_rule_key( $group, $target, array( self::manual_page_pattern( $finding['url'] ) ) );
		$rules = self::manual_attr_rules();
		if ( isset( $rules[ $key ] ) && self::manual_rule_page_matches( $rules[ $key ], $finding['url'] ) ) {
			return array(
				'key'   => $key,
				'group' => $group,
				'label' => isset( $rules[ $key ]['label'] ) ? (string) $rules[ $key ]['label'] : '',
			);
		}
		return null;
	}

	/**
	 * Every attribute-fixed finding in an audit, by fingerprint.
	 *
	 * @param array $result Stored audit result.
	 * @return array fingerprint => match (see attr_match_for_finding).
	 */
	public static function attr_matches_map( $result ) {
		$map = array();
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return $map;
		}
		$dismissed = self::dismissed_map();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$match = self::attr_match_for_finding( $finding );
			if ( null !== $match ) {
				$map[ $fp ] = $match;
			}
		}
		return $map;
	}

	/**
	 * Remove one attribute rule by store key.
	 *
	 * @param string $key Store key.
	 * @return bool Whether a rule was removed.
	 */
	public static function remove_attr_rule( $key ) {
		$rules = self::manual_attr_rules();
		if ( ! isset( $rules[ $key ] ) ) {
			return false;
		}
		unset( $rules[ $key ] );
		update_option( self::MANUAL_ATTR_OPTION, $rules, false );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Waves 3+4 insert rules: content the owner supplies —
	 * a captions file for a self-hosted video, a transcript
	 * for a video or audio clip — inserted at render by the
	 * free fixer's insert applier. The plugin delivers the
	 * owner's content; it never invents a caption or a
	 * transcript. Same store discipline as the attr store:
	 * server-side recompute at save, page-scoped keys, the
	 * free cap counts these rules, removal is one form post.
	 * ------------------------------------------------------------------ */

	/**
	 * Stored owner-supplied insert rules (key => rule).
	 *
	 * @return array
	 */
	public static function manual_insert_rules() {
		$rules = get_option( self::MANUAL_INSERT_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * The store key for an insert rule.
	 *
	 * @param string $group  Media group slug.
	 * @param string $kind   track|transcript.
	 * @param array  $target Target descriptor.
	 * @param array  $pages  Page patterns the rule is scoped to.
	 * @return string
	 */
	public static function insert_rule_key( $group, $kind, $target, $pages = array() ) {
		$scope = array_values( (array) $pages );
		sort( $scope );
		return md5( 'insert|' . $group . '|' . $kind . '|' . wp_json_encode( $target ) . '|' . implode( ',', $scope ) );
	}

	/**
	 * Whether an insert URL is acceptable: http(s) only, and
	 * — for caption tracks — a .vtt path (the one format
	 * browsers read for <track>).
	 *
	 * @param string $url  Candidate URL.
	 * @param bool   $vtt  Require a .vtt path.
	 * @return bool
	 */
	private static function insert_url_ok( $url, $vtt ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}
		if ( ! preg_match( '#^https?://#i', trim( $url ) ) ) {
			return false;
		}
		if ( $vtt ) {
			$path = (string) wp_parse_url( trim( $url ), PHP_URL_PATH );
			if ( '.vtt' !== strtolower( substr( $path, -4 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate and store one insert rule for a media finding.
	 * The target is recomputed from the stored finding's
	 * snippet — the POST carries only the owner's content.
	 *
	 * @param array  $finding The finding.
	 * @param string $kind    track|transcript.
	 * @param array  $data    Raw owner input:
	 *                        track: { url, label, srclang };
	 *                        transcript text: { mode:'text', text };
	 *                        transcript link: { mode:'link', url }.
	 * @return bool
	 */
	public static function save_insert_rule_for_finding( $finding, $kind, $data ) {
		if ( ! is_array( $finding ) || ! isset( $finding['snippet'], $finding['url'] ) || ! is_array( $data ) ) {
			return false;
		}
		$inst  = new self();
		$meta  = $inst->finding_job_meta( $finding );
		$group = $meta['group'];
		if ( ! in_array( $group, array( 'media-video', 'media-audio' ), true ) ) {
			return false;
		}
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		$tag    = isset( $target['tag'] ) ? $target['tag'] : '';
		if ( ! in_array( $tag, array( 'video', 'audio' ), true ) ) {
			return false;
		}
		$clean = null;
		$label = '';
		if ( 'track' === $kind ) {
			if ( 'media-video' !== $group || 'video' !== $tag ) {
				return false;
			}
			$url = isset( $data['url'] ) ? trim( (string) $data['url'] ) : '';
			if ( ! self::insert_url_ok( $url, true ) ) {
				return false;
			}
			$track_label = isset( $data['label'] ) ? trim( (string) $data['label'] ) : '';
			if ( '' === $track_label ) {
				$track_label = 'Captions';
			}
			if ( strlen( $track_label ) > 100 ) {
				return false;
			}
			$lang = isset( $data['srclang'] ) ? trim( (string) $data['srclang'] ) : '';
			if ( '' === $lang ) {
				$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
				$lang   = strtolower( str_replace( '_', '-', strtok( $locale, '_' ) ) );
				if ( '' === $lang ) {
					$lang = 'en';
				}
			}
			if ( 1 !== preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $lang ) ) {
				return false;
			}
			$clean = array( 'url' => $url, 'label' => $track_label, 'srclang' => $lang );
			$label = sprintf( __( 'Captions track added to the video (%s)', 'equalfix' ), $track_label );
		} elseif ( 'transcript' === $kind ) {
			$mode = isset( $data['mode'] ) ? (string) $data['mode'] : 'text';
			if ( 'link' === $mode ) {
				$url = isset( $data['url'] ) ? trim( (string) $data['url'] ) : '';
				if ( ! self::insert_url_ok( $url, false ) ) {
					return false;
				}
				$clean = array( 'mode' => 'link', 'url' => $url );
				$label = __( 'Transcript link added after the media', 'equalfix' );
			} else {
				$text = isset( $data['text'] ) ? (string) $data['text'] : '';
				if ( strlen( $text ) > 20000 ) {
					return false;
				}
				$html = wp_kses_post( $text );
				if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
					return false;
				}
				$clean = array( 'mode' => 'text', 'html' => $html );
				$label = __( 'Transcript added after the media', 'equalfix' );
			}
		} else {
			return false;
		}
		$pages = array( self::manual_page_pattern( $finding['url'] ) );
		$key   = self::insert_rule_key( $group, $kind, $target, $pages );
		if ( self::manual_cap_blocks( 'insert', $key ) ) {
			return false;
		}
		$rules         = self::manual_insert_rules();
		$rules[ $key ] = array(
			'target' => $target,
			'pages'  => $pages,
			'group'  => $group,
			'kind'   => $kind,
			'data'   => $clean,
			'label'  => $label,
		);
		return update_option( self::MANUAL_INSERT_OPTION, $rules, false )
			|| self::option_entry_matches( self::MANUAL_INSERT_OPTION, $key, $rules[ $key ] );
	}

	/**
	 * The insert rule (if any) that satisfies one media
	 * finding. ONE video finding is satisfied by a captions
	 * track OR a transcript; an audio finding by a transcript
	 * only — the coverage matcher's discipline.
	 *
	 * @param array $finding Finding.
	 * @return array|null { key, group, kind, label }
	 */
	public static function insert_match_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['title'], $finding['url'], $finding['snippet'] ) ) {
			return null;
		}
		$inst  = new self();
		$meta  = $inst->finding_job_meta( $finding );
		$group = $meta['group'];
		if ( ! in_array( $group, array( 'media-video', 'media-audio' ), true ) ) {
			return null;
		}
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		if ( '' === $target['tag'] ) {
			return null;
		}
		$kinds = 'media-video' === $group ? array( 'track', 'transcript' ) : array( 'transcript' );
		$rules = self::manual_insert_rules();
		foreach ( $kinds as $kind ) {
			$key = self::insert_rule_key( $group, $kind, $target, array( self::manual_page_pattern( $finding['url'] ) ) );
			if ( isset( $rules[ $key ] ) && self::manual_rule_page_matches( $rules[ $key ], $finding['url'] ) ) {
				return array(
					'key'   => $key,
					'group' => $group,
					'kind'  => $kind,
					'label' => isset( $rules[ $key ]['label'] ) ? (string) $rules[ $key ]['label'] : '',
				);
			}
		}
		return null;
	}

	/**
	 * Every insert-satisfied finding in an audit, by fingerprint.
	 *
	 * @param array $result Stored audit result.
	 * @return array fingerprint => match (see insert_match_for_finding).
	 */
	public static function insert_matches_map( $result ) {
		$map = array();
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return $map;
		}
		$dismissed = self::dismissed_map();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$match = self::insert_match_for_finding( $finding );
			if ( null !== $match ) {
				$map[ $fp ] = $match;
			}
		}
		return $map;
	}

	/**
	 * Remove one insert rule by store key.
	 *
	 * @param string $key Store key.
	 * @return bool Whether a rule was removed.
	 */
	public static function remove_insert_rule( $key ) {
		$rules = self::manual_insert_rules();
		if ( ! isset( $rules[ $key ] ) ) {
			return false;
		}
		unset( $rules[ $key ] );
		update_option( self::MANUAL_INSERT_OPTION, $rules, false );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Waves 3+4 page-title rules: the title the owner approved
	 * for one page, delivered ONLY through WordPress's
	 * document title filters and ONLY while that page's
	 * served title is empty (the finding's exact assertion).
	 * A real title — the owner's or an SEO plugin's — is
	 * never overridden.
	 * ------------------------------------------------------------------ */

	/**
	 * Stored owner-approved page-title rules (key => rule).
	 *
	 * @return array
	 */
	public static function manual_pagetitle_rules() {
		$rules = get_option( self::MANUAL_PAGETITLE_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * The title proposal for a page-title finding: the page's
	 * own post title (url_to_postid), or the site name when
	 * the URL is not a post. Null when neither yields words.
	 *
	 * @param array $finding Finding.
	 * @return array|null { group, title, post_id, label }
	 */
	public function pagetitle_proposal_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['url'] ) ) {
			return null;
		}
		$meta = $this->finding_job_meta( $finding );
		if ( 'page-title' !== $meta['group'] ) {
			return null;
		}
		$post_id = url_to_postid( $finding['url'] );
		$title   = '';
		if ( $post_id > 0 ) {
			$t     = get_the_title( $post_id );
			$title = is_string( $t ) ? trim( $t ) : '';
		}
		if ( '' === $title ) {
			$name  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			$title = trim( $name );
			if ( '' !== $title ) {
				$post_id = 0;
			}
		}
		if ( '' === $title ) {
			return null;
		}
		return array(
			'group'   => 'page-title',
			'title'   => $title,
			'post_id' => (int) $post_id,
			'label'   => sprintf( __( 'Page title set: “%s”', 'equalfix' ), $title ),
		);
	}

	/**
	 * Store one page-title rule (the owner may correct the
	 * proposed title in the panel; blank or oversized text is
	 * refused).
	 *
	 * @param array       $finding       The finding.
	 * @param string|null $title_override Owner-edited title.
	 * @return bool
	 */
	public static function save_pagetitle_rule_for_finding( $finding, $title_override = null ) {
		$inst     = new self();
		$proposal = $inst->pagetitle_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			return false;
		}
		$title = $proposal['title'];
		if ( null !== $title_override ) {
			$title = trim( wp_strip_all_tags( (string) $title_override ) );
			if ( '' === $title || strlen( $title ) > 300 ) {
				return false;
			}
		}
		$pattern = self::manual_page_pattern( $finding['url'] );
		$key     = md5( 'pagetitle|' . $pattern );
		if ( self::manual_cap_blocks( 'pagetitle', $key ) ) {
			return false;
		}
		$rules         = self::manual_pagetitle_rules();
		$rules[ $key ] = array(
			'page'    => $pattern,
			'post_id' => $proposal['post_id'],
			'title'   => $title,
			'label'   => sprintf( __( 'Page title set: “%s”', 'equalfix' ), $title ),
		);
		return update_option( self::MANUAL_PAGETITLE_OPTION, $rules, false )
			|| self::option_entry_matches( self::MANUAL_PAGETITLE_OPTION, $key, $rules[ $key ] );
	}

	/**
	 * The page-title rule (if any) covering one finding.
	 *
	 * @param array $finding Finding.
	 * @return array|null { key, title, label }
	 */
	public static function pagetitle_match_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['url'] ) ) {
			return null;
		}
		$rules = self::manual_pagetitle_rules();
		$key   = md5( 'pagetitle|' . self::manual_page_pattern( $finding['url'] ) );
		if ( isset( $rules[ $key ] ) ) {
			return array(
				'key'   => $key,
				'title' => isset( $rules[ $key ]['title'] ) ? (string) $rules[ $key ]['title'] : '',
				'label' => isset( $rules[ $key ]['label'] ) ? (string) $rules[ $key ]['label'] : '',
			);
		}
		return null;
	}

	/**
	 * Every page-title-fixed finding in an audit, by fingerprint.
	 *
	 * @param array $result Stored audit result.
	 * @return array fingerprint => match.
	 */
	public static function pagetitle_matches_map( $result ) {
		$map = array();
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return $map;
		}
		$dismissed = self::dismissed_map();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$meta = ( new self() )->finding_job_meta( $finding );
			if ( 'page-title' !== $meta['group'] ) {
				continue;
			}
			$match = self::pagetitle_match_for_finding( $finding );
			if ( null !== $match ) {
				$map[ $fp ] = $match;
			}
		}
		return $map;
	}

	/**
	 * Remove one page-title rule by store key.
	 *
	 * @param string $key Store key.
	 * @return bool Whether a rule was removed.
	 */
	public static function remove_pagetitle_rule( $key ) {
		$rules = self::manual_pagetitle_rules();
		if ( ! isset( $rules[ $key ] ) ) {
			return false;
		}
		unset( $rules[ $key ] );
		update_option( self::MANUAL_PAGETITLE_OPTION, $rules, false );
		return true;
	}

	/**
	 * The ruled title for the page being served right now,
	 * or '' when no rule covers it. Matching is exacting: on
	 * a singular view the queried object must BE the ruled
	 * post; elsewhere the request URL's pattern must equal
	 * the ruled page's pattern. The main file's title filters
	 * call this and fill ONLY an empty title.
	 *
	 * @return string
	 */
	public static function pagetitle_title_for_current_page() {
		$rules = self::manual_pagetitle_rules();
		if ( empty( $rules ) ) {
			return '';
		}
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_queried_object_id' ) ) {
			$pid = (int) get_queried_object_id();
			if ( $pid > 0 ) {
				foreach ( $rules as $rule ) {
					if ( isset( $rule['post_id'] ) && (int) $rule['post_id'] === $pid && ! empty( $rule['title'] ) ) {
						return (string) $rule['title'];
					}
				}
				return '';
			}
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && function_exists( 'home_url' ) ) {
			$uri     = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$pattern = self::manual_page_pattern( home_url( $uri ) );
			$key     = md5( 'pagetitle|' . $pattern );
			if ( isset( $rules[ $key ]['title'] ) ) {
				return (string) $rules[ $key ]['title'];
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Waves 3+4 headings-full approvals: per-page owner
	 * approvals for the Pro headings walk to correct EVERY
	 * skipped level on the page (the existing first-skip
	 * Apply semantics are untouched). An approval carries no
	 * content and is not a manual rule — it does not count
	 * toward the free cap.
	 * ------------------------------------------------------------------ */

	/**
	 * Stored headings-full approvals (key => { page, label }).
	 *
	 * @return array
	 */
	public static function headings_full_pages() {
		$pages = get_option( self::HEADINGS_FULL_OPTION, array() );
		return is_array( $pages ) ? $pages : array();
	}

	/**
	 * The store key for one page's headings-full approval.
	 *
	 * @param string $pattern Page pattern.
	 * @return string
	 */
	public static function headings_full_key( $pattern ) {
		return md5( 'headingsfull|' . $pattern );
	}

	/**
	 * Whether this finding's page is approved for the full
	 * headings correction.
	 *
	 * @param array $finding Finding.
	 * @return bool
	 */
	public static function headings_full_approved_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['url'] ) ) {
			return false;
		}
		$pages = self::headings_full_pages();
		return isset( $pages[ self::headings_full_key( self::manual_page_pattern( $finding['url'] ) ) ] );
	}

	/**
	 * Approve one finding's page for the full headings
	 * correction.
	 *
	 * @param array $finding Finding (a headings finding).
	 * @return bool
	 */
	public static function save_headings_full_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['url'] ) ) {
			return false;
		}
		$pattern       = self::manual_page_pattern( $finding['url'] );
		$key           = self::headings_full_key( $pattern );
		$pages         = self::headings_full_pages();
		$pages[ $key ] = array(
			'page'  => $pattern,
			'label' => sprintf( __( 'All skipped heading levels corrected on %s', 'equalfix' ), $pattern ),
		);
		return update_option( self::HEADINGS_FULL_OPTION, $pages, false )
			|| self::option_entry_matches( self::HEADINGS_FULL_OPTION, $key, $pages[ $key ] );
	}

	/**
	 * Remove one headings-full approval by store key.
	 *
	 * @param string $key Store key.
	 * @return bool Whether an approval was removed.
	 */
	public static function remove_headings_full( $key ) {
		$pages = self::headings_full_pages();
		if ( ! isset( $pages[ $key ] ) ) {
			return false;
		}
		unset( $pages[ $key ] );
		update_option( self::HEADINGS_FULL_OPTION, $pages, false );
		return true;
	}

	/**
	 * The approved page patterns, for the Pro fixer's settings.
	 *
	 * @return string[]
	 */
	public static function headings_full_patterns() {
		$out = array();
		foreach ( self::headings_full_pages() as $entry ) {
			if ( isset( $entry['page'] ) && '' !== $entry['page'] ) {
				$out[] = (string) $entry['page'];
			}
		}
		return $out;
	}

	/**
	 * The fix-all write for one attr group: a rule for every
	 * still-open finding in the group (the group's band
	 * button). Identical inputs produce identical rules, so
	 * re-running changes nothing.
	 *
	 * @param string $group Attr group slug.
	 * @return int Rules saved.
	 */
	public function apply_attr_group( $group ) {
		if ( ! in_array( $group, self::attr_groups(), true ) ) {
			return 0;
		}
		if ( 'interactive' === $group ) {
			// The button kit is approved per item, never in
			// bulk (Waves 3+4): each element's shape differs
			// and the owner confirms the kit element by
			// element.
			return 0;
		}
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return 0;
		}
		$dismissed = self::dismissed_map();
		$saved     = 0;
		foreach ( $result['findings'] as $finding ) {
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$meta = $this->finding_job_meta( $finding );
			if ( $meta['group'] !== $group ) {
				continue;
			}
			if ( null !== self::attr_match_for_finding( $finding ) ) {
				continue;
			}
			if ( self::save_attr_rule_for_finding( $finding ) ) {
				$saved++;
			}
		}
		return $saved;
	}

	/**
	 * The prefilled title for an untitled frame, derived from
	 * the embed's provider — the words the write panel opens
	 * with for the embed groups (Wave 2). The owner can edit
	 * before saving; nothing is invented beyond naming what
	 * the frame plainly is.
	 *
	 * @param array $finding Finding.
	 * @return string
	 */
	public static function embed_title_proposal( $finding ) {
		$el   = self::snippet_element( isset( $finding['snippet'] ) ? $finding['snippet'] : '' );
		$src  = isset( $el['attrs']['src'] ) ? (string) $el['attrs']['src'] : '';
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		if ( false !== strpos( $host, 'youtube' ) || 'youtu.be' === $host ) {
			return __( 'YouTube video player', 'equalfix' );
		}
		if ( false !== strpos( $host, 'vimeo' ) ) {
			return __( 'Vimeo video player', 'equalfix' );
		}
		if ( false !== strpos( $host, 'dailymotion' ) ) {
			return __( 'Dailymotion video player', 'equalfix' );
		}
		if ( false !== strpos( $host, 'spotify' ) ) {
			return __( 'Spotify player', 'equalfix' );
		}
		if ( false !== strpos( $host, 'soundcloud' ) ) {
			return __( 'SoundCloud player', 'equalfix' );
		}
		if ( false !== strpos( $src, 'google.com/maps' ) || false !== strpos( $src, 'maps.google' ) ) {
			return __( 'Embedded map', 'equalfix' );
		}
		if ( false !== strpos( $src, '/embed' ) || false !== stripos( $src, 'video' ) ) {
			return __( 'Embedded video player', 'equalfix' );
		}
		return __( 'Embedded content', 'equalfix' );
	}

	/**
	 * The prefilled words a write panel opens with (Wave 2):
	 * the provider title for the embed groups, and Pro's
	 * deterministic name suggester for symbol-named controls
	 * (the same derivation the AI queue uses — icon, click
	 * handler, or id; never invented). '' when no honest
	 * prefill exists.
	 *
	 * @param array $finding Finding.
	 * @return string
	 */
	public static function manual_prefill_for_finding( $finding ) {
		if ( ! is_array( $finding ) || ! isset( $finding['snippet'] ) ) {
			return '';
		}
		$inst  = new self();
		$meta  = $inst->finding_job_meta( $finding );
		if ( in_array( $meta['group'], array( 'embed-3p', 'embed-title' ), true ) ) {
			return self::embed_title_proposal( $finding );
		}
		if ( 'name-symbol' === $meta['group'] && class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			return EqualFix_Pro_Suggestions::suggest_name( self::manual_target_from_snippet( $finding['snippet'] ) );
		}
		return '';
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'autoupdate_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_first_run_redirect' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_legacy_fragment_redirect' ), 1 );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_init', array( $this, 'maybe_continue_audit' ), 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * One-time 1.7.1 default: turn automatic updates ON for this
	 * plugin, the same way the Plugins screen toggle does — by
	 * adding this plugin's own basename to the auto_update_plugins
	 * site option. Runs on activation and once from the upgrade
	 * routine. Guarded by its own flag: once the default has been
	 * applied it is NEVER applied again, so an owner who later
	 * turns auto-updates off on the Plugins screen is never
	 * silently overridden. (Tier 1.6, G-6 — owner decision
	 * 2026-11-06, vetting round 2 claim 8: the one-time admin
	 * notice the IA batch removed is restored. The defaulting
	 * itself is unchanged; it is simply no longer silent. The
	 * notice is armed here, under the equalfix_autoupdate_notice
	 * option: 'pending' until its one showing, 'shown' forever
	 * after — the render itself retires it, so it appears on
	 * exactly one admin screen view and is then gone on its
	 * own (owner clarification 2026-11-06; there is no dismiss
	 * action). add_option arms it at most once ever — a shown
	 * notice can never be re-armed.)
	 */
	public static function maybe_default_auto_updates() {
		if ( get_option( 'equalfix_autoupdate_defaulted' ) ) {
			// Existing install whose defaulting predates the
			// restored notice: arm it once from the upgrade
			// path — but only while the default is still in
			// effect. An owner who turned auto-updates off is
			// neither re-enabled (the flag above) nor told the
			// plugin updates itself when it does not.
			$plugins = get_site_option( 'auto_update_plugins', array() );
			if ( is_array( $plugins ) && in_array( plugin_basename( EQUALFIX_FILE ), $plugins, true ) ) {
				if ( add_option( 'equalfix_autoupdate_notice', 'pending' ) ) {
					self::arm_notice_claim();
				}
			}
			return;
		}
		update_option( 'equalfix_autoupdate_defaulted', 1 );
		$basename = plugin_basename( EQUALFIX_FILE );
		$plugins  = get_site_option( 'auto_update_plugins', array() );
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}
		if ( in_array( $basename, $plugins, true ) ) {
			return; // Already on (the owner had chosen it): nothing to apply.
		}
		$plugins[] = $basename;
		update_site_option( 'auto_update_plugins', $plugins );
		if ( add_option( 'equalfix_autoupdate_notice', 'pending' ) ) {
			self::arm_notice_claim();
		}
	}

	/**
	 * Leave the pair's shared showing claim UNCLAIMED for a
	 * notice that has just been freshly armed (loop round 2,
	 * S-1 created the claim; round 3, S3-1 hardened its
	 * arming). A fresh arming means no showing has been
	 * claimed for THIS notice — so a claim row that already
	 * holds a value cannot belong to it. No plugin write path
	 * leaves such a row behind (every path either creates the
	 * claim unclaimed or stamps it at a genuine claim, and
	 * the purge deletes claim and notices together), but the
	 * failure would be silent and permanent — the notice
	 * would retire on its first render without ever printing
	 * — so a stale or garbage row is reset rather than
	 * trusted: create the claim when absent, clear it when
	 * it holds anything.
	 */
	private static function arm_notice_claim() {
		$claim = get_option( 'equalfix_autoupdate_notice_claim', null );
		if ( null === $claim ) {
			add_option( 'equalfix_autoupdate_notice_claim', '', '', 'no' );
		} elseif ( '' !== $claim ) {
			update_option( 'equalfix_autoupdate_notice_claim', '' );
		}
	}

	/**
	 * The one-time auto-update notice (G-6): the owner's exact
	 * sentence, nothing else. It shows on ONE admin screen
	 * view only: the render retires the armed state to the
	 * terminal 'shown' in this same request, before the markup
	 * is echoed, so no later request can render it again.
	 * There is no dismiss link — the owner chose a single
	 * showing over a notice that waits to be dismissed
	 * (clarification 2026-11-06).
	 */
	public function autoupdate_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'pending' !== get_option( 'equalfix_autoupdate_notice' ) ) {
			return;
		}
		// The pair shows the sentence ONCE total (loop round 1,
		// H-15): free and Pro arm the identical notice under
		// their own options, and on a Pro site both fire on the
		// same screen — stacked duplicates of the same sentence.
		// Whichever of the pair has already shown retires the
		// other silently; a plugin running on its own (the
		// other option absent or still pending) is unaffected,
		// and whichever renders first in the request wins the
		// single showing.
		if ( 'shown' === get_option( 'equalfix_pro_autoupdate_notice' ) ) {
			update_option( 'equalfix_autoupdate_notice', 'shown' );
			return;
		}
		// The single showing is CLAIMED atomically (loop
		// round 2, S-1 — confirmed by reproduction on a real
		// rig: two admin loads fired at the same moment each
		// read both options as 'pending' before either flip
		// landed, and the sentence printed in both responses,
		// 29 of 30 trials). The reads above cannot serialise
		// two requests, and neither can add_option(): traced
		// on the rig, two racing inserts BOTH reported
		// success. The claim is therefore a conditional
		// UPDATE on a pre-existing shared row — the database
		// flips it for exactly one caller, on MySQL and
		// SQLite alike (see claim_autoupdate_notice()).
		// A render that loses the claim retires its own
		// state silently: the winning request owns this
		// view's showing. If the winner dies before
		// printing, the sentence is never shown at all —
		// the same safe direction the flip-before-print
		// order already chose (never twice beats never
		// once), and the render-once semantics are
		// unchanged.
		if ( ! $this->claim_autoupdate_notice() ) {
			update_option( 'equalfix_autoupdate_notice', 'shown' );
			return;
		}
		update_option( 'equalfix_autoupdate_notice', 'shown' );
		if ( 'pending' === get_option( 'equalfix_pro_autoupdate_notice' ) ) {
			update_option( 'equalfix_pro_autoupdate_notice', 'shown' );
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'EqualFix updates itself automatically.', 'equalfix' ) . '</p></div>';
	}

	/**
	 * Claim the pair's single notice showing (loop round 2,
	 * S-1): a conditional UPDATE flips the shared claim row
	 * from unclaimed ('') to claimed, and the rows-affected
	 * count is the verdict — the database applies the flip
	 * for at most one caller, atomically, on MySQL and
	 * SQLite alike. (add_option() was tried first and is NOT
	 * a claim: traced on the SQLite-backed rig, two racing
	 * inserts both reported success.) The claim row is
	 * created unclaimed at arming time; a missing row — an
	 * install armed before the claim existed — is created
	 * on the spot and the claim retried once, with the
	 * UPDATE still the only decider. The Pro plugin carries
	 * the identical helper against the same shared row.
	 *
	 * @return bool True when this caller owns the showing.
	 */
	private function claim_autoupdate_notice() {
		global $wpdb;
		$flip = function () use ( $wpdb ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					gmdate( 'Y-m-d H:i:s' ),
					'equalfix_autoupdate_notice_claim',
					''
				)
			);
		};
		if ( 1 === $flip() ) {
			wp_cache_delete( 'equalfix_autoupdate_notice_claim', 'options' );
			return true;
		}
		if ( false === get_option( 'equalfix_autoupdate_notice_claim' ) ) {
			add_option( 'equalfix_autoupdate_notice_claim', '', '', 'no' );
			if ( 1 === $flip() ) {
				wp_cache_delete( 'equalfix_autoupdate_notice_claim', 'options' );
				return true;
			}
		}
		return false;
	}

	/**
	 * First-run experience: right after a fresh activation, take the admin
	 * straight to the audit prompt — once. Never fires on bulk activation,
	 * AJAX, or when the admin is already heading to the plugin page.
	 */
	public function maybe_first_run_redirect() {
		if ( ! get_transient( 'equalfix_first_run' ) ) {
			return;
		}
		// Never hijack a POST: if the admin's first action after
		// activation is submitting another form, redirecting here
		// would silently discard it. The prompt waits for a GET.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; bulk activation sets this flag.
		if ( isset( $_GET['activate-multi'] ) ) {
			delete_transient( 'equalfix_first_run' );
			return;
		}
		delete_transient( 'equalfix_first_run' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( isset( $_GET['page'] ) && 'equalfix-accessibility' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=equalfix-accessibility' ) );
		exit;
	}

	/**
	 * The canonical URL a legacy fragment route redirects to
	 * (IA-12 fix round), or '' when the slug carries no section
	 * fragment. The fragment routes are the legacy slugs whose
	 * IA home is a SECTION of another tab — pro-licence and
	 * pro-fixb both live inside Settings, under the anchors
	 * tab_url() appends for generated links — so a direct
	 * legacy visit must be sent to the very same URL. Notice
	 * and error args ride along: a message addressed to the
	 * legacy URL is never dropped by the hop.
	 *
	 * @param string $raw Sanitized tab slug from the request.
	 * @return string
	 */
	private function legacy_fragment_target( $raw ) {
		if ( ! in_array( $raw, array( 'pro-licence', 'pro-fixb' ), true ) ) {
			return '';
		}
		$url = $this->tab_url( $raw );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notice args, carried verbatim.
		$carry = array();
		foreach ( array( 'equalfix_notice', 'equalfix_error' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) {
				$carry[ $arg ] = sanitize_text_field( wp_unslash( $_GET[ $arg ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $carry ) ) {
			$url = add_query_arg( $carry, $url );
		}
		return $url;
	}

	/**
	 * Legacy fragment routes redirect to their canonical URL
	 * (IA-12 fix round). render() resolves pro-licence and
	 * pro-fixb to the Settings tab in place, but a server-side
	 * render cannot add the section fragment to the browser's
	 * URL, so a direct visit to a pre-IA bookmark never
	 * scrolled to the section it asked for (the gt.tc gate
	 * journey's pro-licence partial). Redirecting here —
	 * before any output, GET only, with the same guards as
	 * maybe_first_run_redirect — makes the legacy visit land
	 * exactly where a generated link lands, fragment included.
	 * The canonical URL carries tab=settings, never a legacy
	 * slug, so the redirect cannot loop; POSTs are never
	 * hijacked (a form posting to a legacy URL still reaches
	 * its handler).
	 */
	public function maybe_legacy_fragment_redirect() {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( ! isset( $_GET['page'] ) || 'equalfix-accessibility' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || ! isset( $_GET['tab'] ) ) {
			return;
		}
		$target = $this->legacy_fragment_target( sanitize_key( wp_unslash( $_GET['tab'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Add the menu pages (1.1 round 2): EqualFix is a top-level
	 * menu with a Dashboard home, like a standalone product —
	 * not a page buried under Settings.
	 */
	public function menu() {
		add_menu_page(
			__( 'EqualFix Accessibility', 'equalfix' ),
			__( 'EqualFix', 'equalfix' ),
			'manage_options',
			'equalfix-accessibility',
			array( $this, 'render' ),
			'dashicons-universal-access',
			58
		);
		add_submenu_page(
			'equalfix-accessibility',
			__( 'EqualFix Dashboard', 'equalfix' ),
			__( 'Dashboard', 'equalfix' ),
			'manage_options',
			'equalfix-accessibility',
			array( $this, 'render' )
		);
		add_submenu_page(
			'equalfix-accessibility',
			__( 'EqualFix Settings', 'equalfix' ),
			__( 'Settings', 'equalfix' ),
			'manage_options',
			'equalfix-settings',
			array( $this, 'render_settings_tab' )
		);
		// IA batch: the Purge log submenu is gone — the purge log
		// lives inside the History tab now (tab=history).
	}

	/**
	 * Drop a finding's Pro skip record (Stage 6): the owner has
	 * resolved the finding another way, so "AI skipped" must not
	 * outlive the resolution. Guarded — Pro may be absent.
	 *
	 * @param string $fp Finding fingerprint.
	 */
	private function clear_skip_record( $fp ) {
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) && method_exists( 'EqualFix_Pro_Suggestions', 'clear_skip_record' ) ) {
			EqualFix_Pro_Suggestions::clear_skip_record( $fp );
		}
	}

	/**
	 * Render with the Settings tab forced (submenu entry point).
	 */
	public function render_settings_tab() {
		$_GET['tab'] = 'settings';
		$this->render();
	}

	/**
	 * Enqueue admin CSS on our screens only. The version carries
	 * a content hash of the stylesheet (IA batch): a build that
	 * reuses a version string can never serve a stale cached
	 * stylesheet under a new UI again.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_equalfix-accessibility', 'equalfix_page_equalfix-settings' ), true ) ) {
			return;
		}
		$css_file = EQUALFIX_DIR . 'assets/css/admin.css';
		$css_ver  = EQUALFIX_VERSION;
		if ( file_exists( $css_file ) ) {
			$css_ver .= '.' . substr( (string) md5_file( $css_file ), 0, 8 );
		}
		wp_enqueue_style(
			'equalfix-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css',
			array(),
			$css_ver
		);
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_post() {
		if ( ! isset( $_POST['equalfix_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'equalfix' ) );
		}
		check_admin_referer( 'equalfix_settings' );

		$action = sanitize_key( $_POST['equalfix_action'] );

		if ( 'save_settings' === $action ) {
			$this->save_settings();
		} elseif ( 'run_audit' === $action ) {
			$this->run_audit();
		} elseif ( 'fix_all_free' === $action ) {
			$this->fix_all_free();
		} elseif ( 'toggle_master' === $action ) {
			$this->toggle_master();
		} elseif ( 'apply_standard_fixes' === $action ) {
			$this->apply_standard_fixes();
		} elseif ( 'pause_standard_fixes' === $action ) {
			$this->pause_standard_fixes();
		} elseif ( 'apply_pro_fixes' === $action ) {
			$this->apply_pro_fixes();
		} elseif ( 'pause_pro_fixes' === $action ) {
			$this->pause_pro_fixes();
		} elseif ( 'install_pro' === $action ) {
			$this->install_pro();
		} elseif ( 'dismiss_finding' === $action ) {
			$this->dismiss_finding_action();
		} elseif ( 'restore_finding' === $action ) {
			$this->restore_finding_action();
		} elseif ( 'disable_fix_type' === $action ) {
			$this->disable_fix_type();
		} elseif ( 'enable_fix_type' === $action ) {
			$this->enable_fix_type();
		} elseif ( 'draft_with_ai' === $action ) {
			$this->draft_with_ai( false );
		} elseif ( 'draft_all_with_ai' === $action ) {
			$this->draft_with_ai( true );
		} elseif ( 'mark_decorative' === $action ) {
			$this->mark_decorative();
		} elseif ( 'save_manual_text' === $action ) {
			$this->save_manual_text();
		} elseif ( 'remove_manual_rule' === $action ) {
			$this->remove_manual_rule_action();
		} elseif ( 'save_attr_rule' === $action ) {
			$this->save_attr_rule_action();
		} elseif ( 'apply_attr_fixes' === $action ) {
			$this->apply_attr_fixes_action();
		} elseif ( 'apply_group_titles' === $action ) {
			$this->apply_group_titles_action();
		} elseif ( 'remove_attr_rule' === $action ) {
			$this->remove_attr_rule_action();
		} elseif ( 'save_insert_rule' === $action ) {
			$this->save_insert_rule_action();
		} elseif ( 'remove_insert_rule' === $action ) {
			$this->remove_insert_rule_action();
		} elseif ( 'save_pagetitle_rule' === $action ) {
			$this->save_pagetitle_rule_action();
		} elseif ( 'remove_pagetitle_rule' === $action ) {
			$this->remove_pagetitle_rule_action();
		} elseif ( 'fix_headings_full_page' === $action ) {
			$this->fix_headings_full_page_action();
		} elseif ( 'remove_headings_full' === $action ) {
			$this->remove_headings_full_action();
		} elseif ( 'generate_statement' === $action ) {
			$this->statement_action( false );
		} elseif ( 'refresh_statement' === $action ) {
			$this->statement_action( true );
		} elseif ( 'purge_now' === $action ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Manual purge from settings.', 'equalfix' ) );
			$this->redirect( 'purge', __( 'Caches purged.', 'equalfix' ) );
		}
	}

	/**
	 * Save settings from POST.
	 */
	private function save_settings() {
		// 1.0 (WP0 fold-in): merge into the STORED settings, not the
		// defaults — rebuilding from defaults() silently wiped any key
		// the form does not post (extension keys, future settings) back
		// to its default on every save.
		$settings = self::get();
		$was_enabled                 = ! empty( $settings['enabled'] );
		$settings['enabled']         = isset( $_POST['equalfix_enabled'] ) ? 1 : 0;
		$settings['fix_lang']        = isset( $_POST['equalfix_fix_lang'] ) ? 1 : 0;
		$settings['fix_alt']         = isset( $_POST['equalfix_fix_alt'] ) ? 1 : 0;
		$settings['fix_aria_label']  = isset( $_POST['equalfix_fix_aria_label'] ) ? 1 : 0;
		$settings['fix_label_assoc'] = isset( $_POST['equalfix_fix_label_assoc'] ) ? 1 : 0;
		$settings['fix_skip_link']   = isset( $_POST['equalfix_fix_skip_link'] ) ? 1 : 0;
		$settings['connect_opt_in']  = isset( $_POST['equalfix_connect_opt_in'] ) ? 1 : 0;
		$settings['auto_purge']      = isset( $_POST['equalfix_auto_purge'] ) ? 1 : 0;
		$settings['output_cache']    = isset( $_POST['equalfix_output_cache'] ) ? 1 : 0;
		// The free-text fields are scalar by contract: a
		// crafted array-shaped field (round 3, R3-06) made
		// sanitize_lines() fatal on the raw value, and the
		// templates field was silently wiped by the sanitize
		// call's array handling. Non-scalar input for a
		// scalar field keeps the STORED value — never a
		// fatal, never a silent change.
		$posted_urls = isset( $_POST['equalfix_excluded_urls'] ) ? wp_unslash( $_POST['equalfix_excluded_urls'] ) : '';
		$settings['excluded_urls'] = is_string( $posted_urls ) ? $this->sanitize_lines( $posted_urls ) : ( isset( $settings['excluded_urls'] ) ? $settings['excluded_urls'] : '' );
		$posted_selectors = isset( $_POST['equalfix_excluded_selectors'] ) ? wp_unslash( $_POST['equalfix_excluded_selectors'] ) : '';
		$settings['excluded_selectors'] = is_string( $posted_selectors ) ? $this->sanitize_lines( $posted_selectors ) : ( isset( $settings['excluded_selectors'] ) ? $settings['excluded_selectors'] : '' );
		$posted_templates = isset( $_POST['equalfix_excluded_templates'] ) ? wp_unslash( $_POST['equalfix_excluded_templates'] ) : '';
		$settings['excluded_templates'] = is_string( $posted_templates ) ? sanitize_text_field( $posted_templates ) : ( isset( $settings['excluded_templates'] ) ? $settings['excluded_templates'] : '' );
		// The owner has now stated their configuration (F5a):
		// the fix console's Apply must respect it exactly,
		// including a deliberate all-off.
		$settings['types_configured'] = 1;

		// A Settings save can flip the master too — the F4
		// stamp rule applies to it identically: off→on starts
		// a new applied period, on→off ends it.
		if ( ! empty( $settings['enabled'] ) && ! $was_enabled ) {
			$settings['fixes_activated_at'] = time();
		} elseif ( empty( $settings['enabled'] ) && $was_enabled ) {
			$settings['fixes_activated_at'] = 0;
		}

		update_option( self::OPTION, $settings );

		// 1.0 (Lovable Mod 4): a settings save can toggle fixes, and
		// a fix toggle MUST purge — otherwise visitors keep receiving
		// pre-toggle output from every cache layer. This purge is
		// unconditional; auto_purge governs content-change purges,
		// not fix-state purges.
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Settings changed.', 'equalfix' ) );
		$this->redirect( 'settings', __( 'Settings saved and caches purged.', 'equalfix' ) );
	}

	/**
	 * Guided flow: switch the whole free fix set on from the Overview.
	 * Starts from the current settings so exclusions and every other
	 * preference carry over untouched.
	 */
	private function fix_all_free() {
		$settings            = self::get();
		$settings['enabled'] = 1;
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			$settings[ $key ] = 1;
		}
		// Stamp the activation so the results screen can say when
		// the free fix set went live (1.1 UI batch). One admin-side
		// write inside the existing settings array — no new option.
		$settings['fixes_activated_at'] = time();
		$settings['types_configured']  = 1;
		update_option( self::OPTION, $settings );

		// Fix toggles always purge (see save_settings).
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Free fixes enabled from the audit tab.', 'equalfix' ) );
		$this->redirect( 'audit', __( 'Free fixes are on — the free fix set is now being applied to your site’s rendered pages.', 'equalfix' ) );
	}

	/**
	 * Flip the master fix switch (dashboard hero toggle, 1.1;
	 * the handler remains for back-compat — the console owns
	 * the surface now). Purges like every fix-state change;
	 * follows the F4 stamp rule: switching on stamps the
	 * activation time, switching off clears it.
	 */
	private function toggle_master() {
		$settings    = self::get();
		$type_keys   = array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' );
		$any_type_on = false;
		foreach ( $type_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$any_type_on = true;
				break;
			}
		}
		// The switch acts on the state the dashboard DISPLAYS
		// (master on AND at least one fix type on). Flipping the
		// master alone could never change the displayed state when
		// no type was selected — the switch looked broken (1.1.1).
		$is_active = ! empty( $settings['enabled'] ) && $any_type_on;
		if ( $is_active ) {
			$settings['enabled'] = 0;
			// Period ended (F4 stamp rule).
			$settings['fixes_activated_at'] = 0;
		} else {
			$settings['enabled'] = 1;
			if ( ! $any_type_on ) {
				foreach ( $type_keys as $key ) {
					$settings[ $key ] = 1;
				}
			}
			// Off→on transition: a new applied period starts
			// (F4 stamp rule — unconditional, like Apply).
			$settings['fixes_activated_at'] = time();
		}
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Master fix switch toggled from the dashboard.', 'equalfix' ) );
		$this->redirect(
			'audit',
			$settings['enabled']
				? __( 'Auto-fix is on — fixes are being applied to your site’s rendered pages.', 'equalfix' )
				: __( 'Auto-fix is off — your pages are being served exactly as written.', 'equalfix' )
		);
	}

	/**
	 * Fix console, free scope (Stage 6, decision D5): Apply is an
	 * explicit press, never a side effect of anything else. It
	 * switches the master on and respects the configured fix
	 * types — the Settings toggles are the configuration for what
	 * Apply includes; if none is configured, Apply includes the
	 * full standard set (the master toggle's long-standing
	 * behaviour). Purges like every fix-state change.
	 */
	private function apply_standard_fixes() {
		$settings = self::get();
		$type_keys = array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' );
		$any_type_on = false;
		foreach ( $type_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$any_type_on = true;
				break;
			}
		}
		$settings['enabled'] = 1;
		if ( ! $any_type_on && empty( $settings['types_configured'] ) ) {
			// Never-configured legacy state only (F5a): an owner
			// who deliberately saved every type OFF has
			// types_configured set, and Apply respects their
			// configuration exactly — master on, zero types on,
			// and the receipt honestly counts what that fixes.
			foreach ( $type_keys as $key ) {
				$settings[ $key ] = 1;
			}
		}
		$settings['types_configured'] = 1;
		// Stamp rule (Stage 6.1, F4 — ONE rule for both
		// scopes): the stamp marks the start of the CURRENT
		// applied period. Every off→on Apply stamps it fresh,
		// unconditionally; Pause clears it. (The old
		// stamp-only-if-empty guard kept the FIRST period's
		// date after a Pause → Apply cycle, so the receipt's
		// "since" disagreed with the Pro card's.)
		$settings['fixes_activated_at'] = time();
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Standard fixes applied from the fix console.', 'equalfix' ) );
		$this->redirect( 'overview', __( 'Standard fixes applied — the fix set is now live on your rendered pages.', 'equalfix' ) );
	}

	/**
	 * Fix console, free scope: Pause — the same surface switches
	 * the scope back off. Configuration (fix types) is kept.
	 */
	private function pause_standard_fixes() {
		$settings            = self::get();
		$settings['enabled'] = 0;
		// The applied period ends here (F4 stamp rule): the
		// next Apply starts a new one and re-stamps.
		$settings['fixes_activated_at'] = 0;
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Standard fixes paused from the fix console.', 'equalfix' ) );
		$this->redirect( 'overview', __( 'Standard fixes paused — your pages are served exactly as written. Your settings are kept for when you apply them again.', 'equalfix' ) );
	}

	/**
	 * Fix console, Pro scope: Apply. The licence is re-checked
	 * server-side at press time — the locked card is a display
	 * state, never the only guard — and unlocking a licence never
	 * reaches this handler by itself: only this explicit press
	 * switches the Pro scope on.
	 */
	private function apply_pro_fixes() {
		if ( ! class_exists( 'EqualFix_Pro_Settings' ) || ! method_exists( 'EqualFix_Pro_Settings', 'set_fix_master' ) ) {
			$this->redirect_error( 'overview', __( 'Pro is not available on this site.', 'equalfix' ) );
		}
		if ( ! class_exists( 'EqualFix_Pro_Licence' ) || ! EqualFix_Pro_Licence::is_active() ) {
			$this->redirect_error( 'overview', __( 'Pro fixes need an active licence first — add your key in Settings → Licence, then press Apply.', 'equalfix' ) );
		}
		EqualFix_Pro_Settings::set_fix_master( true );
		$this->redirect( 'overview', __( 'Structural & semantic fixes applied — the Pro fix set is now live on your rendered pages.', 'equalfix' ) );
	}

	/**
	 * Fix console, Pro scope: Pause. Always available when Pro
	 * is present — pausing must never depend on licence state.
	 */
	private function pause_pro_fixes() {
		if ( ! class_exists( 'EqualFix_Pro_Settings' ) || ! method_exists( 'EqualFix_Pro_Settings', 'set_fix_master' ) ) {
			$this->redirect_error( 'overview', __( 'Pro is not available on this site.', 'equalfix' ) );
		}
		EqualFix_Pro_Settings::set_fix_master( false );
		$this->redirect( 'overview', __( 'Pro fixes paused — the Pro fix set no longer changes your rendered pages. Your settings are kept.', 'equalfix' ) );
	}

	/**
	 * Whether one fix type's toggle is on in a given settings
	 * array (findings with no mappable fix type count as off).
	 *
	 * @param string $fix      Fix type constant value.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	/**
	 * One-click Pro install (1.2, Workstream B): the whole flow
	 * lives in EqualFix_Pro_Installer; here we only route the
	 * outcome back to the dashboard with an honest notice.
	 */
	private function install_pro() {
		$key     = isset( $_POST['equalfix_pro_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['equalfix_pro_key'] ) ) ) : '';
		$result  = EqualFix_Pro_Installer::install_pro( $key );
		if ( $result['ok'] ) {
			$this->redirect( 'pro-licence', __( 'Pro is installed and active. Your licence key was handed to Pro and is being validated — if Pro does not show as active within a moment, paste the key once more under Settings → Licence.', 'equalfix' ) );
		}
		$this->redirect_error( 'pro', $result['message'] );
	}

	/**
	 * The image src inside a finding's snippet HTML.
	 *
	 * @param string $snippet Snippet HTML.
	 * @return string Src, or ''.
	 */
	private static function img_src_from_snippet( $snippet ) {
		if ( 1 === preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $snippet, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Dismiss one finding from the Issues view, with its reason.
	 * The finding itself arrives as POSTed display fields; the
	 * fingerprint is recomputed here from the stored audit, so a
	 * forged fingerprint can only ever dismiss a real finding.
	 */
	private function dismiss_finding_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$reason      = isset( $_POST['equalfix_reason'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_reason'] ) ) : 'other';
		$note        = isset( $_POST['equalfix_note'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_note'] ) ) : '';
		$result      = get_option( self::AUDIT_OPTION, null );
		$found       = null;
		if ( is_array( $result ) && ! empty( $result['findings'] ) ) {
			foreach ( $result['findings'] as $finding ) {
				if ( self::finding_fingerprint( $finding ) === $fingerprint ) {
					$found = $finding;
					break;
				}
			}
		}
		if ( null === $found ) {
			$this->redirect_findings( __( 'That finding is no longer in the latest audit, so it could not be set aside.', 'equalfix' ), true );
		}
		self::dismiss_finding( $fingerprint, $reason, $note, $found );
		$this->redirect_findings( __( 'Set aside — it’s out of your counts now. You can bring it back any time from the Set aside section below.', 'equalfix' ) );
	}

	/**
	 * Restore one dismissed finding.
	 */
	private function restore_finding_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		if ( self::restore_finding( $fingerprint ) ) {
			$this->redirect( 'findings', __( 'Finding restored to the working list.', 'equalfix' ) );
		}
		$this->redirect_error( 'findings', __( 'That dismissal record no longer exists.', 'equalfix' ) );
	}

	/**
	 * Switch one free fix type off from the Fixed tab — the
	 * one-click disable for the persistent fix record. Settings
	 * themselves are the record; this is their direct control.
	 */
	private function disable_fix_type() {
		$type = isset( $_POST['equalfix_fix_type'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_fix_type'] ) ) : '';
		if ( ! isset( self::fix_types()[ $type ] ) ) {
			$this->redirect_error( 'fixed', __( 'That fix type is not recognized.', 'equalfix' ) );
		}
		$settings = self::get();
		$settings[ 'fix_' . $type ] = 0;
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Fix type disabled from the Fixed view.', 'equalfix' ) );
		$this->redirect( 'fixed', sprintf( __( '%s fixes are off. The findings they covered are back in your Findings list, and your original output is restored.', 'equalfix' ), self::fix_types()[ $type ] ) );
	}

	/**
	 * Find a finding in the stored audit by fingerprint — the
	 * only source row actions trust (a forged fingerprint can
	 * only ever name a real finding).
	 *
	 * @param string $fingerprint Finding fingerprint.
	 * @return array|null
	 */
	private function finding_by_fingerprint( $fingerprint ) {
		$result = get_option( self::AUDIT_OPTION, null );
		if ( is_array( $result ) && ! empty( $result['findings'] ) ) {
			foreach ( $result['findings'] as $finding ) {
				if ( self::finding_fingerprint( $finding ) === $fingerprint ) {
					return $finding;
				}
			}
		}
		return null;
	}

	/**
	 * Redirect to the Issues view after a row action, keeping
	 * the still-to-do filter when the row was acted on there.
	 *
	 * @param string $message Notice message.
	 * @param bool   $error   Render as an error notice.
	 */
	private function redirect_findings( $message, $error = false ) {
		$url = $this->tab_url( 'findings' );
		if ( ! empty( $_POST['equalfix_show_todo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- value only picks the landing view; the action itself was nonce-checked in handle_post().
			$url = add_query_arg( 'show', 'todo', $url );
		}
		$url = add_query_arg( array( $error ? 'equalfix_error' : 'equalfix_notice' => rawurlencode( $message ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Turn one free fix type on from a Findings row (Direction
	 * A): the type AND the master switch — a type switched on
	 * under a dark master would be another dead button.
	 */
	private function enable_fix_type() {
		$type = isset( $_POST['equalfix_fix_type'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_fix_type'] ) ) : '';
		if ( ! isset( self::fix_types()[ $type ] ) ) {
			$this->redirect_findings( __( 'That fix type is not recognized.', 'equalfix' ), true );
		}
		$settings = self::get();
		$was_off  = empty( $settings['enabled'] );
		$settings[ 'fix_' . $type ] = 1;
		$settings['enabled']        = 1;
		if ( $was_off ) {
			// Off→on transition: a new applied period starts
			// (F4 stamp rule — unconditional, like Apply).
			$settings['fixes_activated_at'] = time();
		}
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Fix type enabled from Findings.', 'equalfix' ) );
		$this->redirect_findings( sprintf( __( 'Fix on: %s is now applied to your pages as they load.', 'equalfix' ), self::fix_types()[ $type ] ) );
	}

	/**
	 * Draft one finding (or a whole job-card group) with AI
	 * Vision, via Pro's existing queue generation — no new
	 * endpoint; the row's state flips to Waiting on the next
	 * render because the coverage model reads the queue.
	 *
	 * @param bool $all Group bulk action when true.
	 */
	private function draft_with_ai( $all ) {
		$pro = self::pro_state();
		if ( empty( $pro['licence_active'] ) || ! class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$this->redirect_findings( __( 'AI drafting needs Pro with an active licence. You can write the words yourself on the finding instead.', 'equalfix' ), true );
		}
		$result = get_option( self::AUDIT_OPTION, null );
		EqualFix_Pro_Suggestions::generate_from_audit();
		if ( $all ) {
			$pending = 0;
			foreach ( EqualFix_Pro_Suggestions::get_queue() as $item ) {
				if ( isset( $item['status'] ) && EqualFix_Pro_Suggestions::STATUS_PENDING === $item['status'] ) {
					$pending++;
				}
			}
			if ( $pending > 0 ) {
				$this->redirect_findings( sprintf( _n( '%d draft is waiting in Pro → AI suggestions — approve the ones you want.', '%d drafts are waiting in Pro → AI suggestions — approve the ones you want.', $pending, 'equalfix' ), $pending ) );
			}
			$this->redirect_findings( __( 'AI Vision couldn’t draft these — there wasn’t enough in the page to go on. You can write the words yourself on each finding.', 'equalfix' ), true );
		}
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$coverage    = is_array( $result ) ? $this->pro_coverage( $result ) : null;
		if ( null !== $coverage && ! empty( $coverage['waiting'][ $fingerprint ] ) ) {
			$this->redirect_findings( __( 'Draft ready — it’s waiting in Pro → AI suggestions.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'AI Vision couldn’t draft this one — there wasn’t enough in the page to go on. Write it yourself instead.', 'equalfix' ), true );
	}

	/**
	 * "It's just decorative" (Direction A): a FIX, not a
	 * dismissal. With Pro active it rides the queue's own
	 * decorative path; without Pro it is a free manual rule
	 * with alt="". Either way the finding leaves Still to do
	 * and appears on the Fixed tab.
	 */
	private function mark_decorative() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding ) {
			$this->redirect_findings( __( 'That finding is no longer in the latest audit.', 'equalfix' ), true );
		}
		$pro = self::pro_state();
		if ( ! empty( $pro['licence_active'] ) && class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			EqualFix_Pro_Suggestions::generate_from_audit();
			$src     = self::img_src_from_snippet( $finding['snippet'] );
			$abs     = '' !== $src ? EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] ) : '';
			$key     = self::manual_src_key( '' !== $abs ? $abs : $src );
			$pattern = self::manual_page_pattern( $finding['url'] );
			foreach ( EqualFix_Pro_Suggestions::get_queue() as $item ) {
				if ( ! isset( $item['kind'], $item['status'] ) || 'alt' !== $item['kind'] || EqualFix_Pro_Suggestions::STATUS_PENDING !== $item['status'] ) {
					continue;
				}
				$item_src  = isset( $item['img_src'] ) ? (string) $item['img_src'] : '';
				$item_page = isset( $item['page_url'] ) ? (string) $item['page_url'] : '';
				if ( self::manual_src_key( $item_src ) === $key && self::manual_page_pattern( $item_page ) === $pattern ) {
					EqualFix_Pro_Suggestions::mark_decorative( $item['id'] );
					$this->clear_skip_record( $fingerprint );
					$this->redirect_findings( __( 'Marked decorative — screen readers will skip this image (alt="").', 'equalfix' ) );
				}
			}
			// No queue item could be created for it (no signal):
			// fall through to the free manual rule below.
		}
		// The free cap (DH-3) covers this path too: a
		// mark-decorative entry is a manual rule in the same
		// store. The Pro queue path above never reaches here,
		// and with an active licence the cap does not apply.
		if ( self::manual_cap_blocks( 'alt', self::manual_key_for_finding( 'alt', $finding ) ) ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		if ( self::save_manual_for_finding( 'alt', $finding, '', true ) ) {
			$this->clear_skip_record( $fingerprint );
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Image marked decorative from Findings.', 'equalfix' ) );
			// DH-6: same gate honesty as save_manual_text().
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( __( 'Done — this image is now treated as decorative (alt="") on that page.', 'equalfix' ) );
			}
			$this->redirect_findings( __( 'Saved. This image will be treated as decorative (alt="") when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That image could not be marked decorative.', 'equalfix' ), true );
	}

	/**
	 * Save the owner's own words for one finding (Direction A
	 * "write it myself"): a manual rule in the free store,
	 * applied at render by the free fixer.
	 */
	private function save_manual_text() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$kind        = isset( $_POST['equalfix_manual_kind'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_manual_kind'] ) ) : '';
		$text        = isset( $_POST['equalfix_manual_text'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_manual_text'] ) ) : '';
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding ) {
			$this->redirect_findings( __( 'That finding is no longer in the latest audit.', 'equalfix' ), true );
		}
		if ( ! in_array( $kind, array( 'alt', 'label', 'name', 'title' ), true ) ) {
			$this->redirect_findings( __( 'That kind of written fix is not recognized.', 'equalfix' ), true );
		}
		if ( '' === $text ) {
			$this->redirect_findings( __( 'Write the words first — an empty fix would change nothing.', 'equalfix' ), true );
		}
		// The free hand-written cap (DH-3): enforced HERE, in
		// the handler — the form's locked state is only the
		// surface. Rules already saved stay live; only a NEW
		// rule beyond the cap is refused, and only while no
		// Pro licence is active.
		if ( self::manual_cap_blocks( $kind, self::manual_key_for_finding( $kind, $finding ) ) ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		if ( self::save_manual_for_finding( $kind, $finding, $text ) ) {
			$this->clear_skip_record( $fingerprint );
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Owner-written fix saved from Findings.', 'equalfix' ) );
			// DH-6: the message must respect the master gate —
			// a saved rule applies only while the standard
			// fixes are on, so say exactly that when they are
			// not, instead of claiming it is already applied.
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( __( 'Saved — your words are now applied to that element as the page loads.', 'equalfix' ) );
			}
			$this->redirect_findings( __( 'Saved. Your words will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That fix could not be saved.', 'equalfix' ), true );
	}

	/**
	 * Save the deterministic fix a proposal panel offered
	 * (Fix-First): the proposal is recomputed server-side
	 * from the stored finding — the POST carries only the
	 * fingerprint and, for tabindex, the owner's choice
	 * between the offered values — so what applies is always
	 * the computed fix, never posted data. The free cap is
	 * enforced here exactly as in save_manual_text().
	 */
	private function save_attr_rule_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$choice      = isset( $_POST['equalfix_attr_value'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_attr_value'] ) ) : null;
		// Waves 3+4 link-generic: the announced name is the
		// ONE proposal value the owner may type (the panel's
		// name field) — it is still validated server-side
		// against the generic list before anything is stored.
		$text = isset( $_POST['equalfix_attr_text'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_attr_text'] ) ) : null;
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding ) {
			$this->redirect_findings( __( 'That finding is no longer in the latest audit.', 'equalfix' ), true );
		}
		$proposal = $this->attr_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			$this->redirect_findings( __( 'EqualFix has no safe automatic fix for this one — it stays a manual edit.', 'equalfix' ), true );
		}
		$target = self::attr_target_for_proposal( $finding, $proposal );
		$key    = self::attr_rule_key( $proposal['group'], $target, array( self::manual_page_pattern( $finding['url'] ) ) );
		if ( self::manual_cap_blocks( 'attr', $key ) ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		if ( self::save_attr_rule_for_finding( $finding, $choice, $text ) ) {
			$this->clear_skip_record( $fingerprint );
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Deterministic fix approved from Findings.', 'equalfix' ) );
			// DH-6 discipline: the message respects the master
			// gate — a saved rule applies only while the
			// standard fixes are on.
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( __( 'Fix applied — it is live on that element as the page loads.', 'equalfix' ) );
			}
			$this->redirect_findings( __( 'Saved. The fix will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That fix could not be saved.', 'equalfix' ), true );
	}

	/**
	 * A group's Fix all (Fix-First): one confirm applies the
	 * computed fix to every still-open finding in the group.
	 */
	private function apply_attr_fixes_action() {
		$group = isset( $_POST['equalfix_attr_group'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_attr_group'] ) ) : '';
		if ( ! in_array( $group, self::attr_groups(), true ) ) {
			$this->redirect_findings( __( 'That fix group is not recognized.', 'equalfix' ), true );
		}
		$saved = $this->apply_attr_group( $group );
		if ( $saved > 0 ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Group fixes approved from Findings.', 'equalfix' ) );
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( sprintf( _n( '%d fix applied — it is live as the pages load.', '%d fixes applied — they are live as the pages load.', $saved, 'equalfix' ), $saved ) );
			}
			$this->redirect_findings( sprintf( _n( '%d fix saved. It will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', '%d fixes saved. They will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', $saved, 'equalfix' ), $saved ) );
		}
		// Nothing saved: either everything already had a rule,
		// or the free cap stopped the saves — say which.
		$pro = self::pro_state();
		if ( empty( $pro['licence_active'] ) && self::MANUAL_FREE_CAP <= self::manual_rule_count() ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		$this->redirect_findings( __( 'Nothing to apply — every problem in this group already has its fix saved.', 'equalfix' ) );
	}

	/**
	 * The embed groups' band action (Fix-First Wave 2): save
	 * the provider-derived title for every still-open embed
	 * finding in the group, through the manual title store.
	 */
	private function apply_group_titles_action() {
		$group = isset( $_POST['equalfix_attr_group'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_attr_group'] ) ) : '';
		if ( ! in_array( $group, array( 'embed-3p', 'embed-title' ), true ) ) {
			$this->redirect_findings( __( 'That group is not recognized.', 'equalfix' ), true );
		}
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			$this->redirect_findings( __( 'There is no audit to work from.', 'equalfix' ), true );
		}
		$dismissed = self::dismissed_map();
		$saved     = 0;
		$capped    = false;
		foreach ( $result['findings'] as $finding ) {
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			$meta = $this->finding_job_meta( $finding );
			if ( $meta['group'] !== $group || null !== self::manual_match_for_finding( $finding ) ) {
				continue;
			}
			if ( self::manual_cap_blocks( 'title', self::manual_key_for_finding( 'title', $finding ) ) ) {
				$capped = true;
				break;
			}
			if ( self::save_manual_for_finding( 'title', $finding, self::embed_title_proposal( $finding ) ) ) {
				$saved++;
			}
		}
		if ( $saved > 0 ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Embed titles saved from Findings.', 'equalfix' ) );
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( sprintf( _n( '%d embed title applied — it is live as the pages load.', '%d embed titles applied — they are live as the pages load.', $saved, 'equalfix' ), $saved ) );
			}
			$this->redirect_findings( sprintf( _n( '%d embed title saved. It will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', '%d embed titles saved. They will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', $saved, 'equalfix' ), $saved ) );
		}
		if ( $capped ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		$this->redirect_findings( __( 'Nothing to apply — every embed in this group already has a title saved.', 'equalfix' ) );
	}

	/**
	 * Remove one owner-approved attribute rule from the Fixed tab.
	 */
	private function remove_attr_rule_action() {
		$key = isset( $_POST['equalfix_attr_key'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_attr_key'] ) ) : '';
		if ( self::remove_attr_rule( $key ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Approved fix removed.', 'equalfix' ) );
			$this->redirect( 'fixed', __( 'Fix removed — the original output is restored.', 'equalfix' ) );
		}
		$this->redirect_error( 'fixed', __( 'That fix could not be found (it may already have been removed).', 'equalfix' ) );
	}

	/**
	 * Save one owner-supplied insert rule (Waves 3+4): the
	 * captions file or transcript for one media finding. The
	 * POST carries the fingerprint and the owner's content;
	 * the target is recomputed from the stored finding.
	 * Validation failures come back as plain messages, never
	 * a silent no-save.
	 */
	private function save_insert_rule_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$kind        = isset( $_POST['equalfix_insert_kind'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_insert_kind'] ) ) : '';
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding || ! in_array( $kind, array( 'track', 'transcript' ), true ) ) {
			$this->redirect_findings( __( 'That media fix could not be saved.', 'equalfix' ), true );
		}
		if ( 'track' === $kind ) {
			$data = array(
				'url'     => isset( $_POST['equalfix_track_url'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_track_url'] ) ) : '',
				'label'   => isset( $_POST['equalfix_track_label'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_track_label'] ) ) : '',
				'srclang' => isset( $_POST['equalfix_track_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_track_lang'] ) ) : '',
			);
			if ( ! self::insert_url_ok( $data['url'], true ) ) {
				$this->redirect_findings( __( 'The captions file must be a web address (http or https) ending in .vtt — that is the captions format browsers read.', 'equalfix' ), true );
			}
		} else {
			$mode = isset( $_POST['equalfix_transcript_mode'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_transcript_mode'] ) ) : 'text';
			if ( 'link' === $mode ) {
				$data = array(
					'mode' => 'link',
					'url'  => isset( $_POST['equalfix_transcript_url'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_transcript_url'] ) ) : '',
				);
				if ( ! self::insert_url_ok( $data['url'], false ) ) {
					$this->redirect_findings( __( 'The transcript link must be a web address (http or https).', 'equalfix' ), true );
				}
			} else {
				// Transcript text is HTML-bearing owner content:
				// read it raw (no wp_kses here) — the save
				// sanitises with wp_kses_post and enforces the
				// 20,000-character cap.
				$data = array(
					'mode' => 'text',
					'text' => isset( $_POST['equalfix_transcript_text'] ) ? wp_unslash( $_POST['equalfix_transcript_text'] ) : '',
				);
				if ( strlen( (string) $data['text'] ) > 20000 ) {
					$this->redirect_findings( __( 'That transcript is too long — the limit is 20,000 characters. For a longer transcript, publish it as a page and use the link option instead.', 'equalfix' ), true );
				}
			}
		}
		$insert_target = self::manual_target_from_snippet( $finding['snippet'] );
		$insert_meta   = $this->finding_job_meta( $finding );
		$insert_key    = self::insert_rule_key( $insert_meta['group'], $kind, $insert_target, array( self::manual_page_pattern( $finding['url'] ) ) );
		if ( self::manual_cap_blocks( 'insert', $insert_key ) ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		if ( self::save_insert_rule_for_finding( $finding, $kind, $data ) ) {
			$this->clear_skip_record( $fingerprint );
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Owner-supplied media content saved from Findings.', 'equalfix' ) );
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( __( 'Saved — your content is live on that media as the page loads.', 'equalfix' ) );
			}
			$this->redirect_findings( __( 'Saved. Your content will appear when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That media fix could not be saved.', 'equalfix' ), true );
	}

	/**
	 * Remove one owner-supplied insert rule from the Fixed tab.
	 */
	private function remove_insert_rule_action() {
		$key = isset( $_POST['equalfix_insert_key'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_insert_key'] ) ) : '';
		if ( self::remove_insert_rule( $key ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Supplied media content removed.', 'equalfix' ) );
			$this->redirect( 'fixed', __( 'Removed — the media shows without it again.', 'equalfix' ) );
		}
		$this->redirect_error( 'fixed', __( 'That fix could not be found (it may already have been removed).', 'equalfix' ) );
	}

	/**
	 * Save one owner-approved page title (Waves 3+4): the
	 * title is recomputed from the stored finding (post title
	 * or site name) unless the owner corrected it in the
	 * panel's field.
	 */
	private function save_pagetitle_rule_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$text        = isset( $_POST['equalfix_pagetitle_text'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_pagetitle_text'] ) ) : null;
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding ) {
			$this->redirect_findings( __( 'That page title could not be saved.', 'equalfix' ), true );
		}
		$proposal = $this->pagetitle_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			$this->redirect_findings( __( 'No title can be proposed for this page.', 'equalfix' ), true );
		}
		// An unchanged posted value means "the proposal" —
		// pass null so the stored title is the recomputed one.
		$override = ( null !== $text && $text !== $proposal['title'] ) ? $text : null;
		$pattern  = self::manual_page_pattern( $finding['url'] );
		if ( self::manual_cap_blocks( 'pagetitle', md5( 'pagetitle|' . $pattern ) ) ) {
			$this->redirect_findings( self::manual_cap_message(), true );
		}
		if ( self::save_pagetitle_rule_for_finding( $finding, $override ) ) {
			$this->clear_skip_record( $fingerprint );
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Page title approved from Findings.', 'equalfix' ) );
			if ( $this->standard_scope_on( self::get() ) ) {
				$this->redirect_findings( __( 'Page title set — it applies while the page itself has no title.', 'equalfix' ) );
			}
			$this->redirect_findings( __( 'Saved. The title will apply when the standard fixes are on — press “Apply Standard Fixes” on the Overview to switch them on.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That page title could not be saved.', 'equalfix' ), true );
	}

	/**
	 * Remove one page-title rule from the Fixed tab.
	 */
	private function remove_pagetitle_rule_action() {
		$key = isset( $_POST['equalfix_pagetitle_key'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_pagetitle_key'] ) ) : '';
		if ( self::remove_pagetitle_rule( $key ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Page title rule removed.', 'equalfix' ) );
			$this->redirect( 'fixed', __( 'Page title removed — the page goes back to having no title until one is set.', 'equalfix' ) );
		}
		$this->redirect_error( 'fixed', __( 'That fix could not be found (it may already have been removed).', 'equalfix' ) );
	}

	/**
	 * Approve the full headings correction for one page
	 * (Waves 3+4): the Pro headings walk will correct every
	 * skipped level on the finding's page. Separate from the
	 * headings Apply semantics, which stay first-skip.
	 */
	private function fix_headings_full_page_action() {
		$fingerprint = isset( $_POST['equalfix_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_fingerprint'] ) ) : '';
		$finding     = $this->finding_by_fingerprint( $fingerprint );
		if ( null === $finding ) {
			$this->redirect_findings( __( 'That page could not be approved.', 'equalfix' ), true );
		}
		$meta = $this->finding_job_meta( $finding );
		if ( 'headings' !== $meta['group'] ) {
			$this->redirect_findings( __( 'That page could not be approved.', 'equalfix' ), true );
		}
		if ( self::save_headings_full_for_finding( $finding ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Full heading correction approved for a page.', 'equalfix' ) );
			$this->redirect_findings( __( 'Approved — every skipped heading level on that page is corrected as it loads. The headings themselves look the same; their outline levels change.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That page could not be approved.', 'equalfix' ), true );
	}

	/**
	 * Remove one headings-full approval from the Fixed tab.
	 */
	private function remove_headings_full_action() {
		$key = isset( $_POST['equalfix_headings_key'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_headings_key'] ) ) : '';
		if ( self::remove_headings_full( $key ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Full heading correction approval removed.', 'equalfix' ) );
			$this->redirect( 'fixed', __( 'Approval removed — the page is back to the standard first-skip correction.', 'equalfix' ) );
		}
		$this->redirect_error( 'fixed', __( 'That approval could not be found (it may already have been removed).', 'equalfix' ) );
	}

	/**
	 * Remove one owner-written rule from the Fixed tab.
	 */
	private function remove_manual_rule_action() {
		$kind = isset( $_POST['equalfix_manual_kind'] ) ? sanitize_key( wp_unslash( $_POST['equalfix_manual_kind'] ) ) : '';
		$key  = isset( $_POST['equalfix_manual_key'] ) ? sanitize_text_field( wp_unslash( $_POST['equalfix_manual_key'] ) ) : '';
		if ( self::remove_manual_rule( $kind, $key ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Owner-written fix removed.', 'equalfix' ) );
			$this->redirect( 'fixed', __( 'Written fix removed — the original output is restored.', 'equalfix' ) );
		}
		$this->redirect_error( 'fixed', __( 'That written fix could not be found (it may already have been removed).', 'equalfix' ) );
	}

	/**
	 * Create or refresh the accessibility statement draft page.
	 *
	 * @param bool $refresh True for the refresh action.
	 */
	private function statement_action( $refresh ) {
		if ( ! class_exists( 'EqualFix_Statement' ) ) {
			$this->redirect_error( 'overview', __( 'The statement generator is unavailable.', 'equalfix' ) );
		}
		$result = $refresh ? EqualFix_Statement::refresh() : EqualFix_Statement::generate();
		if ( empty( $result['ok'] ) ) {
			$this->redirect_error( 'overview', $result['message'] );
		}
		$this->redirect( 'overview', $result['message'] );
	}

	private function fix_type_enabled( $fix, $settings ) {
		if ( '' === $fix || ! isset( self::FIX_SETTING_KEYS[ $fix ] ) ) {
			return false;
		}
		return ! empty( $settings[ self::FIX_SETTING_KEYS[ $fix ] ] );
	}

	/**
	 * Whether every free fix TYPE toggle is on in a given
	 * settings array (regardless of the master switch).
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function all_free_fixes_on_types( $settings ) {
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the master switch and every free fix type are on.
	 *
	 * @return bool
	 */
	private function all_free_fixes_on() {
		$settings = self::get();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Findings from the last audit that are FIXED right now (1.1 UI
	 * batch): category fix-a findings whose fix type is enabled.
	 *
	 * Fixes apply at render time, so "fixed" is a derived state —
	 * audit findings ∩ active toggles — never a stored event log
	 * that can drift from the site. When the master switch or a
	 * fix type is off, the finding is not fixed; it is fixable.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $settings Current settings.
	 * @return array Findings currently remediated at render.
	 */
	private function fixed_findings( $result, $settings ) {
		$parts = $this->partition_fix_a( $result, $settings );
		return $parts[0];
	}

	/**
	 * Split the audit's fix-a findings into [fixed, remaining] by
	 * whether each finding's fix type is currently enabled.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $settings Current settings.
	 * @return array{0: array, 1: array} Fixed findings, then the rest.
	 */
	private function partition_fix_a( $result, $settings ) {
		$fixed     = array();
		$remaining = array();
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return array( $fixed, $remaining );
		}
		$master = ! empty( $settings['enabled'] );
		foreach ( $result['findings'] as $finding ) {
			// Invalid language rides the lang fix type too
			// (Fix-First Wave 2): review-category, but fixed by
			// the same type through the same delivery paths —
			// the partition the Fixed tab and the to-do banner
			// read must agree with the coverage model.
			$is_lang_fixable = EqualFix_Audit::CAT_FIX_A === $finding['category']
				|| ( EqualFix_Audit::CAT_REVIEW === $finding['category'] && 'Language attribute looks invalid' === $finding['title'] );
			if ( ! $is_lang_fixable ) {
				continue;
			}
			$type = $this->finding_fix_type( $finding );
			if ( $master && null !== $type && ! empty( $settings[ 'fix_' . $type ] ) ) {
				$fixed[] = $finding;
			} else {
				$remaining[] = $finding;
			}
		}
		return array( $fixed, $remaining );
	}

	/**
	 * Pro's coverage of this audit (1.5), supplied through the
	 * equalfix_pro_coverage filter by Pro 1.4+: which findings
	 * Pro is already fixing (approved rules, approved suggestions,
	 * enabled Extra fixes), which have a suggestion waiting for
	 * the owner's OK, and the approved alt-text rows the Fixed
	 * tab displays. Null when no Pro build answers — every
	 * caller then falls back to the free-only behaviour.
	 *
	 * @param array $result Stored audit result.
	 * @return array|null { fixed: array, waiting: array, approved_rows: array, fixed_if_on: array } or null.
	 */
	private function pro_coverage( $result ) {
		$coverage = apply_filters( 'equalfix_pro_coverage', null, $result );
		if ( ! is_array( $coverage ) ) {
			return null;
		}
		return array(
			'fixed'         => isset( $coverage['fixed'] ) && is_array( $coverage['fixed'] ) ? $coverage['fixed'] : array(),
			'waiting'       => isset( $coverage['waiting'] ) && is_array( $coverage['waiting'] ) ? $coverage['waiting'] : array(),
			'approved_rows' => isset( $coverage['approved_rows'] ) && is_array( $coverage['approved_rows'] ) ? $coverage['approved_rows'] : array(),
			// Stage 6.1 (F1): findings the Pro scope would fix
			// if its master were on (older Pro builds do not
			// send it — the model then reports no gated count).
			'fixed_if_on'   => isset( $coverage['fixed_if_on'] ) && is_array( $coverage['fixed_if_on'] ) ? $coverage['fixed_if_on'] : array(),
		);
	}

	/**
	 * THE count model (1.5): one partition of the audit's working
	 * list — the findings that are not dismissed, exactly the
	 * list the Findings tab shows — into Fixed / Waiting / To do,
	 * shared by the Overview hero, the KPI tiles, the Top issues
	 * badges and the Fixed tab so every surface tells the same
	 * story and approving a Pro suggestion moves the numbers
	 * without a re-audit. A finding is FIXED when its free fix
	 * type is enabled (the partition_fix_a rule) or Pro coverage
	 * says Pro is fixing it; WAITING when Pro coverage says a
	 * suggestion for it is pending; otherwise TO DO. Invariant
	 * over the working list: total = fixed + waiting + todo.
	 * Dismissed findings are counted separately and never enter
	 * the three buckets.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $settings Current settings.
	 * @return array Model counts, per-finding state maps, and the
	 *               Pro approved rows (see keys in the code).
	 */
	/**
	 * Public accessor for the coverage model (Stage 6, F6): the
	 * statement (and any future surface) must quote the SAME
	 * fixed/waiting/to-do counts as the dashboard, never a
	 * recount of its own.
	 *
	 * @param array $result Audit result.
	 * @return array Model (totals + per-finding partitions).
	 */
	public static function coverage_counts( $result ) {
		$inst = new self();
		return $inst->coverage_model( $result, self::get() );
	}

	private function coverage_model( $result, $settings ) {
		$model = array(
			'total'         => 0,
			'dismissed'     => 0,
			'fixed'         => 0,
			'waiting'       => 0,
			'todo'          => 0,
			'fixed_fps'     => array(),
			'waiting_fps'   => array(),
			'approved_rows' => array(),
			'has_coverage'  => false,
			// Stage 6.1 (F1) attribution + gated counts, all
			// computed HERE so every surface quotes the same
			// partition: fixed_pro = findings the Pro coverage
			// branch fixed (split into the structural set
			// itself and approved-rule findings); pro_gated_off
			// = findings the Pro scope would fix if applied
			// (its coverage fixed_if_on map) that are not
			// currently fixed by anything — pro_rules_off is
			// the approved-rule part of that; manual_gated_off
			// = owner-written fixes waiting on the free master.
			'fixed_pro'       => 0,
			'fixed_pro_rules' => 0,
			'fixed_pro_set'   => 0,
			'pro_gated_off'   => 0,
			'pro_rules_off'   => 0,
			'manual_gated_off' => 0,
			// Final pass (F-1/F-2): the DISTINCT-problem
			// layer. The occurrence counts above stay — the
			// Issues lists and per-finding rows are
			// occurrence-shaped — but every HEADLINE count
			// (hero, KPI tiles, console receipts, next step)
			// reads the d_* keys: one problem = one Issues
			// group + one element identity (problem_identity()),
			// however many pages it appears on. Review-group
			// problems (REVIEW_GROUPS) leave "still to do" for
			// their own d_review figure. Invariant, asserted
			// by wp52: d_total = d_fixed + d_waiting + d_todo
			// + d_review, and the group-header distinct
			// counts sum to these totals per bucket.
			'd_total'          => 0,
			'd_fixed'          => 0,
			'd_waiting'        => 0,
			'd_todo'           => 0,
			'd_review'         => 0,
			'd_dismissed'      => 0,
			'd_fixed_pro'      => 0,
			'd_fixed_pro_rules' => 0,
			'd_pro_gated_off'  => 0,
			'd_pro_rules_off'  => 0,
			'd_free_gated_off' => 0,
			'problem_buckets'  => array(),
			'problem_pages'    => array(),
		);
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return $model;
		}
		$coverage = $this->pro_coverage( $result );
		if ( null !== $coverage ) {
			$model['has_coverage']  = true;
			$model['approved_rows'] = $coverage['approved_rows'];
		}
		$dismissed = self::dismissed_map();
		$master    = ! empty( $settings['enabled'] );
		// Owner-written rules (next batch) are the third FIXED
		// source, alongside the free fix types and Pro coverage —
		// same partition, same invariant.
		$manual = self::manual_matches_map( $result );
		// Owner-approved attribute rules (Fix-First, 2026-11-07)
		// are the fourth source, in the same partition: they
		// ride the free fixer's buffer exactly like the manual
		// stores, so they count as fixed only while the master
		// switch is on.
		$attr = self::attr_matches_map( $result );
		// Owner-supplied insert rules and page-title rules
		// (Fix-First Waves 3+4) are the fifth and sixth
		// sources, same partition, same master gate: both ride
		// the free plugin's delivery (the fixer's buffer and
		// the title filters, both gated by fixes_active()).
		$insert    = self::insert_matches_map( $result );
		$pagetitle = self::pagetitle_matches_map( $result );
		// Distinct-layer accumulators (F-1): per problem
		// identity, the occurrence states seen, whether the
		// group is a review group, and the pages it appears on.
		$occ           = array();
		$occ_pages     = array();
		$dismissed_ids = array();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			list( $group, $identity ) = $this->problem_identity( $finding );
			$is_review_group = in_array( $group, self::REVIEW_GROUPS, true );
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				$model['dismissed']++;
				$dismissed_ids[ $identity ] = true;
				continue;
			}
			$model['total']++;
			if ( ! isset( $occ[ $identity ] ) ) {
				$occ[ $identity ] = array(
					'fixed'     => 0,
					'waiting'   => 0,
					'open'      => 0,
					'review'    => $is_review_group,
					'pro'       => false,
					'pro_rules' => false,
				);
				$occ_pages[ $identity ] = array();
			}
			$occ_pages[ $identity ][ isset( $finding['url'] ) ? (string) $finding['url'] : '' ] = true;
			$fixed     = false;
			$fixed_pro = false;
			if ( EqualFix_Audit::CAT_FIX_A === $finding['category'] ) {
				$type  = $this->finding_fix_type( $finding );
				$fixed = $master && null !== $type && ! empty( $settings[ 'fix_' . $type ] );
			}
			// Manual rules ride the FREE fixer's buffer, so —
			// exactly like the free fix types — they count as
			// fixed only while the master switch is on. (Pro's
			// approved rules are exempt from this: Pro's buffer
			// runs independently of the free master.)
			if ( ! $fixed && $master && isset( $manual[ $fp ] ) ) {
				$fixed = true;
			}
			// Attribute rules: the same master gate, the same
			// reasoning (Fix-First, 2026-11-07).
			if ( ! $fixed && $master && isset( $attr[ $fp ] ) ) {
				$fixed = true;
			}
			// Insert and page-title rules (Waves 3+4): the
			// same master gate again — both deliver through
			// the free plugin's render paths.
			if ( ! $fixed && $master && ( isset( $insert[ $fp ] ) || isset( $pagetitle[ $fp ] ) ) ) {
				$fixed = true;
			}
			// Invalid language (Fix-First Wave 2): the finding
			// is review-category, but its fix is the lang fix
			// type itself — the fixer replaces a malformed
			// value with the site language through the same
			// paths that add a missing one — so with the type
			// on and the master on, it is fixed like its
			// missing-language sibling.
			if ( ! $fixed && $master && EqualFix_Audit::CAT_REVIEW === $finding['category'] && 'Language attribute looks invalid' === $finding['title'] && ! empty( $settings['fix_lang'] ) ) {
				$fixed = true;
			}
			if ( ! $fixed && null !== $coverage && ! empty( $coverage['fixed'][ $fp ] ) ) {
				$fixed     = true;
				$fixed_pro = true;
			}
			if ( $fixed ) {
				$model['fixed']++;
				$model['fixed_fps'][ $fp ] = true;
				$occ[ $identity ]['fixed']++;
				if ( $fixed_pro ) {
					// Attribution (F1): the finding counts
					// toward the Pro console card, split by
					// HOW Pro fixes it — its own structural
					// set, or a rule the owner approved.
					$model['fixed_pro']++;
					$occ[ $identity ]['pro'] = true;
					if ( EqualFix_Audit::CAT_FIX_B === $finding['category'] ) {
						$model['fixed_pro_set']++;
					} else {
						$model['fixed_pro_rules']++;
						$occ[ $identity ]['pro_rules'] = true;
					}
				}
				continue;
			}
			if ( null !== $coverage && ! empty( $coverage['waiting'][ $fp ] ) ) {
				$model['waiting']++;
				$model['waiting_fps'][ $fp ] = true;
				$occ[ $identity ]['waiting']++;
				continue;
			}
			$model['todo']++;
			$occ[ $identity ]['open']++;
		}
		// Finalize the distinct layer (F-1/F-2): a problem is
		// "fixed" only when it is fixed on EVERY page it
		// appears on; one open occurrence anywhere keeps it
		// open (in "still to do", or in "for your review" for
		// review groups); otherwise one waiting occurrence
		// makes the problem waiting. The precedence keeps the
		// four headline buckets a true partition of d_total.
		foreach ( $occ as $identity => $o ) {
			if ( $o['open'] > 0 ) {
				$bucket = $o['review'] ? 'review' : 'todo';
			} elseif ( $o['waiting'] > 0 ) {
				$bucket = 'waiting';
			} else {
				$bucket = 'fixed';
			}
			$model['problem_buckets'][ $identity ] = $bucket;
			$model['problem_pages'][ $identity ]   = count( $occ_pages[ $identity ] );
			$model['d_total']++;
			switch ( $bucket ) {
				case 'fixed':
					$model['d_fixed']++;
					if ( $o['pro'] ) {
						$model['d_fixed_pro']++;
					}
					if ( $o['pro_rules'] ) {
						$model['d_fixed_pro_rules']++;
					}
					break;
				case 'waiting':
					$model['d_waiting']++;
					break;
				case 'todo':
					$model['d_todo']++;
					break;
				case 'review':
					$model['d_review']++;
					break;
			}
		}
		$model['d_dismissed'] = count( $dismissed_ids );
		// Gated counts (F1): findings NOT currently fixed that
		// a paused scope would fix if applied — the fix
		// console's paused-state copy quotes these, so a paused
		// card can never claim "nothing needs this set" while
		// work it gates is sitting unfixed. Dismissed findings
		// never count (they are outside the working list).
		$pro_ids       = array();
		$pro_rule_ids  = array();
		$manual_ids    = array();
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) || isset( $model['fixed_fps'][ $fp ] ) ) {
				continue;
			}
			list( , $identity ) = $this->problem_identity( $finding );
			if ( null !== $coverage && ! empty( $coverage['fixed_if_on'][ $fp ] ) ) {
				$model['pro_gated_off']++;
				$pro_ids[ $identity ] = true;
				if ( EqualFix_Audit::CAT_FIX_B !== $finding['category'] ) {
					$model['pro_rules_off']++;
					$pro_rule_ids[ $identity ] = true;
				}
			}
			if ( isset( $manual[ $fp ] ) || isset( $attr[ $fp ] ) || isset( $insert[ $fp ] ) || isset( $pagetitle[ $fp ] ) ) {
				$model['manual_gated_off']++;
				$manual_ids[ $identity ] = true;
			}
		}
		// Distinct gated counts (F-1): problems an unpressed
		// Apply would fix — the fix console's paused copy and
		// the Next step card quote these, in the same problem
		// unit as the headline. The free side is the distinct
		// union of the paused fix-type partition and the
		// owner-written rules waiting on the free master.
		$model['d_pro_gated_off'] = count( $pro_ids );
		$model['d_pro_rules_off'] = count( $pro_rule_ids );
		$free_ids                 = $manual_ids;
		$parts                    = $this->partition_fix_a( $result, $settings );
		foreach ( $parts[1] as $remaining_finding ) {
			if ( isset( $model['fixed_fps'][ self::finding_fingerprint( $remaining_finding ) ] ) ) {
				continue;
			}
			list( , $remaining_identity ) = $this->problem_identity( $remaining_finding );
			$free_ids[ $remaining_identity ] = true;
		}
		$model['d_free_gated_off'] = count( $free_ids );
		return $model;
	}

	/**
	 * A finding's DISTINCT-problem identity (final pass,
	 * F-1): its Issues "By job" group plus the element the
	 * finding is about, recognised across pages. The
	 * element key is the finding's snippet with the finding's
	 * own page URL (and its path) stripped and whitespace
	 * collapsed — the same header control, mini-cart button
	 * or footer link therefore keys identically on every
	 * page, while a genuinely different element (different
	 * markup) keys differently. Findings with an empty
	 * snippet key on the group alone (page-level problems
	 * like a missing page title are one problem per site
	 * section, still counted once, with "on M pages"
	 * carrying the spread). Per-finding identity is NOT
	 * touched: fingerprints stay md5(title|url|snippet).
	 *
	 * @param array $finding Finding.
	 * @return array{0: string, 1: string} Group slug, identity key.
	 */
	private function problem_identity( $finding ) {
		$meta    = $this->finding_job_meta( $finding );
		$snippet = isset( $finding['snippet'] ) ? (string) $finding['snippet'] : '';
		$url     = isset( $finding['url'] ) ? (string) $finding['url'] : '';
		if ( '' !== $url && '' !== $snippet ) {
			$snippet = str_replace( $url, '', $snippet );
			$path    = wp_parse_url( $url, PHP_URL_PATH );
			// Only a real sub-path is stripped: the home
			// page's path is "/", and stripping that would
			// delete every slash in the snippet (URLs, tags)
			// — differently per page, splitting one problem
			// into several (wp52's first run caught exactly
			// this).
			if ( is_string( $path ) && strlen( $path ) > 1 ) {
				$snippet = str_replace( $path, '', $snippet );
			}
		}
		$snippet = trim( (string) preg_replace( '/\s+/', ' ', $snippet ) );
		return array( $meta['group'], $meta['group'] . '|' . md5( $snippet ) );
	}

	/**
	 * The audit's TO DO findings under a computed coverage
	 * model: the working list minus dismissed, fixed and
	 * waiting findings. Surfaces that talk about what is LEFT
	 * (the hero's AI Vision clause, the journey's Pro panel
	 * and "Needs your words" card) count and list from this
	 * set, so they can never quote findings Pro already
	 * fixed as if they were still open.
	 *
	 * @param array $result Stored audit result.
	 * @param array $model  A coverage_model() result.
	 * @return array Findings in the TO DO bucket.
	 */
	private function todo_findings( $result, $model ) {
		$todo      = array();
		$dismissed = self::dismissed_map();
		foreach ( (array) $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( self::map_has_finding( $dismissed, $finding ) || isset( $model['fixed_fps'][ $fp ] ) || isset( $model['waiting_fps'][ $fp ] ) ) {
				continue;
			}
			$todo[] = $finding;
		}
		return $todo;
	}

	/**
	 * Count findings in one audit category.
	 *
	 * @param array  $findings Findings.
	 * @param string $cat      Category constant value.
	 * @return int
	 */
	private function count_category( $findings, $cat ) {
		$n = 0;
		foreach ( (array) $findings as $finding ) {
			if ( isset( $finding['category'] ) && $cat === $finding['category'] ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * How many DISTINCT problems in a to-do list generation
	 * already tried and could not draft (Stage 6 skip records) —
	 * the hero sentence's unit, and (loop fix round, test-4,
	 * BUG 1) the ONE couldn't-draft number every surface states.
	 * The Review tab's queue-side count used to sum skip-record
	 * OCCURRENCES instead (one record per page a problem appears
	 * on), so the two surfaces contradicted each other by the
	 * number of extra pages (live: hero 31 vs Review 32).
	 *
	 * @param array $todo_list To-do findings from todo_findings().
	 * @return int
	 */
	private function skipped_problem_count( $todo_list ) {
		if ( ! class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			return 0;
		}
		$skip_records = EqualFix_Pro_Suggestions::skip_records();
		if ( empty( $skip_records ) ) {
			return 0;
		}
		$skipped_ids = array();
		foreach ( (array) $todo_list as $todo_finding ) {
			if ( $this->is_queue_eligible( $todo_finding ) && self::map_has_finding( $skip_records, $todo_finding ) ) {
				list( , $skipped_identity ) = $this->problem_identity( $todo_finding );
				$skipped_ids[ $skipped_identity ] = true;
			}
		}
		return count( $skipped_ids );
	}

	/**
	 * The couldn't-draft count for a stored audit, in distinct
	 * problems — the public seam the Pro Review tab reads so its
	 * "Couldn't draft" section states the same number, in the
	 * same unit, as the Overview hero (BUG 1, test-4).
	 *
	 * @param array $result Stored audit result.
	 * @return int
	 */
	public static function ai_skipped_problem_count( $result ) {
		if ( ! is_array( $result ) || empty( $result['findings'] ) || ! class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			return 0;
		}
		$inst  = new self();
		$model = $inst->coverage_model( $result, self::get() );
		return $inst->skipped_problem_count( $inst->todo_findings( $result, $model ) );
	}

	/**
	 * Findings the Pro approval queue can draft a page-derived
	 * suggestion for (alt text, labels, control names). The queue
	 * builder only processes review-category findings, so count
	 * exactly those — the number must never exceed the "Need your
	 * words" count it annotates (1.0.2).
	 *
	 * @param array $findings Findings.
	 * @return int
	 */
	private function queue_eligible_count( $findings ) {
		$n = 0;
		foreach ( (array) $findings as $finding ) {
			if ( $this->is_queue_eligible( $finding ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Whether one finding is queue-draftable (the per-finding
	 * form of queue_eligible_count()).
	 *
	 * @param array $finding Finding.
	 * @return bool
	 */
	private function is_queue_eligible( $finding ) {
		return is_array( $finding ) && isset( $finding['category'], $finding['title'] ) && EqualFix_Audit::CAT_REVIEW === $finding['category'] && ( false !== strpos( $finding['title'], 'missing alt text' ) || false !== strpos( $finding['title'], 'has no associated label' ) || false !== strpos( $finding['title'], 'has no accessible name' ) );
	}

	/**
	 * The honest before/after pair for one fix type, from a finding:
	 * the real pair captured at audit time when one exists for the
	 * type, otherwise a fragment fix of the finding's own snippet
	 * (marked as illustrative by the caller).
	 *
	 * @param string $type    Fix type constant value.
	 * @param array  $finding Representative finding of that type.
	 * @param array  $result  Stored audit result.
	 * @return array{0: string, 1: string, 2: bool} Before, after, is-real.
	 */
	private function fix_preview_pair( $type, $finding, $result ) {
		$stored = isset( $result['previews'] ) && is_array( $result['previews'] ) ? $result['previews'] : array();
		if ( isset( $stored[ $type ] ) ) {
			return array( $stored[ $type ]['before'], $stored[ $type ]['after'], true );
		}
		$fixer = new EqualFix_Fixer(
			array(
				'fix_lang'           => true,
				'fix_alt'            => true,
				'fix_aria_label'     => true,
				'fix_label_assoc'    => true,
				'fix_skip_link'      => true,
				'excluded_selectors' => array(),
				'locale'             => get_bloginfo( 'language' ),
				'skip_link_text'     => __( 'Skip to content', 'equalfix' ),
			)
		);
		return array( $finding['snippet'], $fixer->fix_fragment( $finding['snippet'] ), false );
	}

	/**
	 * First-activation seeding: a fresh install starts with fixes OFF, so
	 * nothing changes on the site until the owner runs an audit and
	 * chooses to fix. Existing installs keep whatever they already have —
	 * this only runs when no settings have ever been stored.
	 */
	public static function activate() {
		self::maybe_default_auto_updates();
		if ( false === get_option( self::OPTION, false ) ) {
			$starter                    = self::defaults();
			$starter['enabled']         = 0;
			$starter['fix_lang']        = 0;
			$starter['fix_alt']         = 0;
			$starter['fix_aria_label']  = 0;
			$starter['fix_label_assoc'] = 0;
			$starter['fix_skip_link']   = 0;
			add_option( self::OPTION, $starter );
			set_transient( 'equalfix_first_run', 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Sanitize a textarea into newline-separated trimmed lines.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function sanitize_lines( $text ) {
		// Type-safe at the boundary (round 3, R3-06): any
		// future caller that hands this a non-string gets an
		// empty field, not a TypeError on the preg_split.
		if ( ! is_string( $text ) ) {
			return '';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$clean = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}
		return implode( "\n", $clean );
	}

	/**
	 * Run the local audit.
	 */
	private function run_audit() {
		$previous = get_option( self::AUDIT_OPTION, null );
		// One audit at a time (loop round 3, S3-3 — the same
		// class as the round-1 generation lock, proven on the
		// rig: a second start read the FIRST run's partial
		// totals as its own previous-run comparison and then
		// overwrote the shared step state, silently killing
		// the first run mid-flight). A start is refused while
		// a run is in flight — its step state is live, or the
		// run lock is held. A run that has made no progress
		// for ten minutes is abandoned, not live: the new run
		// proceeds, and it takes its previous-run baseline
		// from the abandoned run's own step state (which
		// carries the true previous totals), never from the
		// in-flight snapshot's partial numbers.
		$in_flight = is_array( $previous ) && ! empty( $previous['step_state'] ) && ( ! isset( $previous['complete'] ) || ! $previous['complete'] );
		if ( $in_flight || false !== get_transient( self::AUDIT_LOCK ) ) {
			$fresh = false;
			if ( $in_flight ) {
				if ( isset( $previous['updated_epoch'] ) ) {
					$stamp = (int) $previous['updated_epoch'];
					$fresh = $stamp > 0 && ( time() - $stamp ) < 10 * MINUTE_IN_SECONDS;
				} else {
					// Pre-LC-3 record: it carries no epoch, only
					// a site-local time string. Resolve it in
					// the SITE's timezone (round 4, R4-08 — a
					// bare strtotime() reads it as server-local,
					// so east of the server the stamp landed in
					// the future and a dead run read "fresh"),
					// and a stamp that still lands in the future
					// is stale, never fresh.
					$stamp = isset( $previous['time'] ) ? self::legacy_stamp_epoch( $previous['time'] ) : 0;
					$fresh = $stamp > 0 && $stamp <= time() && ( time() - $stamp ) < 10 * MINUTE_IN_SECONDS;
				}
			}
			if ( $fresh || ! $in_flight ) {
				$this->redirect( 'audit', __( 'An audit is already running. Wait for it to finish, then run it again.', 'equalfix' ) );
				return;
			}
		}
		$prev_total = null;
		$prev_time  = null;
		// Loop fix round (test-4), BUG 2: the previous run's
		// DISTINCT-problem total rides the same chain, so the
		// change-since line can compare problems with problems.
		// The previous run's findings are still stored at this
		// point (in the result, or in an abandoned run's seed),
		// so its distinct total is computable right here; a
		// chain link without findings leaves it null and the
		// delta line stays silent rather than mixing units.
		$prev_problems = null;
		if ( is_array( $previous ) ) {
			if ( $in_flight && isset( $previous['step_state']['previous_total'] ) ) {
				// The abandoned run's chain: ITS previous
				// totals are the last FINISHED run's — the
				// snapshot's own total is a partial and must
				// never pose as a previous result.
				$prev_total = null !== $previous['step_state']['previous_total'] ? (int) $previous['step_state']['previous_total'] : null;
				$prev_time  = isset( $previous['step_state']['previous_time'] ) ? $previous['step_state']['previous_time'] : null;
				if ( isset( $previous['step_state']['previous_problems'] ) && null !== $previous['step_state']['previous_problems'] ) {
					$prev_problems = (int) $previous['step_state']['previous_problems'];
				}
			} elseif ( ! $in_flight ) {
				$prev_total = isset( $previous['total'] ) ? (int) $previous['total'] : null;
				$prev_time  = isset( $previous['time'] ) ? $previous['time'] : null;
				if ( ! empty( $previous['findings'] ) && is_array( $previous['findings'] ) ) {
					$prev_model    = $this->coverage_model( $previous, self::get() );
					$prev_problems = (int) $prev_model['d_total'];
				}
			}
		}
		// The lock carries the run's own token (round 4, S4-1):
		// finish_audit() releases only its own run's lock, so a
		// superseded run finishing late cannot unlock the run
		// that replaced it.
		$run_token = wp_generate_password( 12, false );
		set_transient( self::AUDIT_LOCK, $run_token, 10 * MINUTE_IN_SECONDS );
		$crawler = new EqualFix_Crawler();
		$state   = array(
			'token'          => $run_token,
			'urls'           => $crawler->discover_urls(),
			'pages'          => array(),
			'findings'       => array(),
			'total'          => 0,
			'ok'             => 0,
			'rendered'       => 0,
			'fetch_error'    => '',
			'previews'       => array(),
			'preview_tried'  => array(),
			'previous_total' => $prev_total,
			'previous_time'  => $prev_time,
			'previous_problems' => $prev_problems,
			'steps'          => 0,
			// Tier 2 (T2-02/T2-05): per-check outcomes and
			// readability counts, accumulated page by page.
			// Additive state — nothing else reads these keys,
			// and results stored before this batch simply lack
			// the finished forms (the renderers check isset()).
			'checks'         => array(),
			'readability'    => array( 'words' => 0, 'sentences' => 0, 'syllables' => 0, 'pages' => 0 ),
		);
		$this->audit_step( $state );
	}

	/**
	 * Continue a stepped audit run in a fresh request. A run only
	 * spans requests when pages had to be rendered internally —
	 * one render per request is a hard platform limit (theme
	 * template parts load with require_once) — so the browser is
	 * redirected through the remaining steps, each carrying a
	 * nonce tied to the run's token.
	 */
	public function maybe_continue_audit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( ! isset( $_GET['equalfix_continue'] ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['equalfix_continue'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the verification.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'equalfix_continue_' . $token ) ) {
			return;
		}
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['step_state'] ) || ! hash_equals( (string) $result['step_state']['token'], $token ) ) {
			return;
		}
		$this->audit_step( $result['step_state'] );
	}

	/**
	 * Process audit pages until the run is done or a page's
	 * internal render has to wait for a later request.
	 *
	 * The crawl is up to ~25 sequential fetches. Progress is
	 * saved after every page (see save_audit_progress) so a
	 * host-imposed kill leaves a partial audit marked incomplete
	 * instead of nothing at all. When every page can be fetched
	 * over HTTP — the normal case — a run completes inside this
	 * one request, exactly as it always has.
	 *
	 * @param array $state Run state (see run_audit()).
	 */
	private function audit_step( $state ) {
		// This request's run token, for the render-death
		// handler's ownership check (round 4, S4-1).
		self::$watch_token = isset( $state['token'] ) ? (string) $state['token'] : '';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$this->register_render_death_watch();
		// Persist the state before processing anything, so the
		// queue in the stored state always names the page being
		// worked on — that is what lets the render-death handler
		// identify (and honestly fail) a page whose code ends
		// the request mid-render.
		$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
		$crawler = new EqualFix_Crawler();
		$audit   = new EqualFix_Audit();
		while ( ! empty( $state['urls'] ) ) {
			$url  = $state['urls'][0];
			$html = $crawler->fetch( $url );
			if ( false === $html && 'deferred' === $crawler->last_failure_kind() ) {
				// This page needs an internal render, but this
				// request already rendered one. Leave it queued
				// and continue the run in a fresh request.
				break;
			}
			array_shift( $state['urls'] );
			if ( false === $html ) {
				if ( '' === $state['fetch_error'] ) {
					$state['fetch_error'] = $crawler->last_error();
				}
				$state['pages'][] = array(
					'url'      => $url,
					'status'   => 'fetch_failed',
					'findings' => 0,
				);
			} else {
				$mode = $crawler->last_fetch_mode();
				if ( 'render' === $mode ) {
					$state['rendered']++;
				}
				$state['ok']++;
				$page_findings = $audit->audit_page( $html, $url );
				// Tier 2 (T2-02): this page's per-check outcomes
				// (slug => findings produced; a slug present with
				// 0 means the check ran and passed on this page).
				if ( ! isset( $state['checks'] ) || ! is_array( $state['checks'] ) ) {
					$state['checks'] = array();
				}
				foreach ( $audit->last_page_checks() as $slug => $n ) {
					if ( ! isset( $state['checks'][ $slug ] ) ) {
						$state['checks'][ $slug ] = array( 'findings' => 0, 'pages' => 0 );
					}
					$state['checks'][ $slug ]['findings'] += (int) $n;
					$state['checks'][ $slug ]['pages']++;
				}
				// Tier 2 (T2-05): readability counts for this
				// page (null when its text is too short to judge).
				if ( ! isset( $state['readability'] ) || ! is_array( $state['readability'] ) ) {
					$state['readability'] = array( 'words' => 0, 'sentences' => 0, 'syllables' => 0, 'pages' => 0 );
				}
				$text_counts = $audit->page_text_counts( $html );
				if ( null !== $text_counts ) {
					$state['readability']['words']     += $text_counts['words'];
					$state['readability']['sentences'] += $text_counts['sentences'];
					$state['readability']['syllables'] += $text_counts['syllables'];
					$state['readability']['pages']++;
				}
				$state['total'] += count( $page_findings );
				$state['pages'][] = array(
					'url'      => $url,
					'status'   => 'ok',
					'findings' => count( $page_findings ),
					'mode'     => $mode,
					// Body hash + visible-text signature (1.4):
					// lets finish_audit detect a scan that fetched
					// one repeated holding page.
					'hash'     => md5( $html ),
					'sig'      => $this->page_signature( $html ),
				);
				// Cap stored findings per run to keep the option small.
				foreach ( $page_findings as $finding ) {
					if ( count( $state['findings'] ) < 300 ) {
						$state['findings'][] = $finding;
					}
				}
				$this->accumulate_previews( $page_findings, $html, $state );
			}
			$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
		}

		if ( ! empty( $state['urls'] ) ) {
			$state['steps']++;
			if ( $state['steps'] <= 40 ) {
				$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
				wp_safe_redirect( $this->continue_url( $state ) );
				exit;
			}
			// Defensive cap (unreachable at 25 pages max: each step
			// completes at least one render or finishes the queue).
			// Count everything left as failed and finish honestly.
			foreach ( $state['urls'] as $url ) {
				$state['pages'][] = array(
					'url'      => $url,
					'status'   => 'fetch_failed',
					'findings' => 0,
				);
			}
			$state['urls'] = array();
		}
		$this->finish_audit( $state );
	}

	/**
	 * The URL that continues a stepped run in a fresh request.
	 *
	 * @param array $state Run state.
	 * @return string
	 */
	private function continue_url( $state ) {
		return $this->audit_url(
			array(
				'equalfix_continue' => $state['token'],
				'_wpnonce'          => wp_create_nonce( 'equalfix_continue_' . $state['token'] ),
			)
		);
	}

	/**
	 * Whether the render-death watch has been registered in this
	 * request, and the output-buffer level when it was armed
	 * (buffers above it belong to the render and are discarded
	 * if the render dies).
	 *
	 * @var bool
	 */
	private static $watch_registered = false;

	/**
	 * Output-buffer level recorded when the watch was armed.
	 *
	 * @var int
	 */
	private static $watch_ob_level = 0;

	/**
	 * The token of the audit run THIS request is stepping
	 * (round 4, S4-1): the render-death handler compares it
	 * against the stored step state's token and acts only on
	 * its own run — a page render that ends late, after the
	 * run was abandoned and a new run started, must not mark
	 * or finish the new run's state.
	 *
	 * @var string
	 */
	private static $watch_token = '';

	/**
	 * Resolve a stored site-local datetime string (the
	 * pre-LC-3 audit record's only stamp) to an epoch, in the
	 * SITE's timezone (round 4, R4-08). current_time('mysql')
	 * writes site-local time; a bare strtotime() reads it as
	 * server-local, so on a site east of its server the stamp
	 * landed hours in the future and a long-dead run read
	 * "fresh" for the offset plus ten minutes. WP's
	 * get_gmt_from_date() interprets the string in the site's
	 * timezone — the clock that wrote it. A value that cannot
	 * be resolved returns 0, which the caller treats as
	 * stale, never fresh.
	 *
	 * @param mixed $value Stored time value.
	 * @return int Epoch, or 0 when unresolvable.
	 */
	private static function legacy_stamp_epoch( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}
		$gmt   = get_gmt_from_date( $value );
		$epoch = is_string( $gmt ) && '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
		return $epoch ? (int) $epoch : 0;
	}

	/**
	 * Arm the shutdown handler that finishes an audit's
	 * bookkeeping if page code ends the request mid-render.
	 * An exit inside a theme or plugin cannot be caught, but
	 * it must never stall a run: the handler fails the page
	 * honestly and points the browser at the next step.
	 */
	private function register_render_death_watch() {
		if ( self::$watch_registered ) {
			return;
		}
		self::$watch_registered = true;
		self::$watch_ob_level   = ob_get_level();
		register_shutdown_function( array( $this, 'handle_render_death' ) );
	}

	/**
	 * Shutdown handler: if the request died inside an internal
	 * render, mark the page being rendered as failed in the
	 * stored run state and continue (or finish) the run. Does
	 * nothing on any normal request end — successful renders
	 * clear the crawler's in-progress flag before returning,
	 * and every intentional redirect happens with it cleared.
	 */
	public function handle_render_death() {
		if ( ! EqualFix_Crawler::render_in_progress() ) {
			return;
		}
		// Discard whatever the dying render left in its buffers.
		while ( ob_get_level() > self::$watch_ob_level ) {
			ob_end_clean();
		}
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['step_state'] ) || empty( $result['step_state']['urls'] ) ) {
			return;
		}
		$state = $result['step_state'];
		// Ownership (round 4, S4-1): act only on THIS request's
		// own run. If the run this watch belongs to was
		// abandoned (ten minutes without progress) and a new
		// run has since started, the stored state is the NEW
		// run's — marking its page failed, or finishing it,
		// from the old run's shutdown would corrupt a live
		// run's bookkeeping.
		if ( '' === self::$watch_token || empty( $state['token'] ) || ! hash_equals( (string) $state['token'], self::$watch_token ) ) {
			return;
		}
		$url = array_shift( $state['urls'] );
		if ( '' === $state['fetch_error'] ) {
			$state['fetch_error'] = 'The page ended the request while it was being rendered internally.';
		}
		$state['pages'][] = array(
			'url'      => $url,
			'status'   => 'fetch_failed',
			'findings' => 0,
		);
		if ( ! empty( $state['urls'] ) && $state['steps'] <= 40 ) {
			$state['steps']++;
			$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
			header( 'Location: ' . $this->continue_url( $state ) );
			return;
		}
		$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
		$this->finish_audit( $state );
	}

	/**
	 * The finished 'checks' value for a stored audit result
	 * (Tier 2, T2-02): one row per catalogue check that ran on
	 * at least one page of the run, in catalogue order, with
	 * the run-wide findings total and its pass state. A check
	 * passes when it produced zero findings across the run —
	 * and when it actually RAN on every page the run scanned:
	 * a check that completed on only some pages (an earlier
	 * check threw on the rest) has not passed the pages it
	 * never saw, so it is marked 'partial' and counted
	 * neither as passed nor as finding issues (loop round 1,
	 * H-08). The 'partial' key is present only on partial
	 * rows, so the stored format for ordinary rows is
	 * unchanged.
	 *
	 * @param array $accumulated slug => { findings, pages }.
	 * @param int   $run_pages   Pages the run scanned (0 =
	 *                           derive as the widest page
	 *                           count any check recorded).
	 * @return array List of { slug, name, findings, pages, passed[, partial] }.
	 */
	private function audit_checks_summary( $accumulated, $run_pages = 0 ) {
		if ( $run_pages < 1 ) {
			foreach ( (array) $accumulated as $row ) {
				if ( isset( $row['pages'] ) ) {
					$run_pages = max( $run_pages, (int) $row['pages'] );
				}
			}
		}
		$out = array();
		foreach ( EqualFix_Audit::check_catalogue() as $slug => $name ) {
			if ( ! isset( $accumulated[ $slug ] ) || (int) $accumulated[ $slug ]['pages'] < 1 ) {
				continue;
			}
			$pages   = (int) $accumulated[ $slug ]['pages'];
			$partial = $run_pages > 0 && $pages < $run_pages;
			$row     = array(
				'slug'     => $slug,
				'name'     => $name,
				'findings' => (int) $accumulated[ $slug ]['findings'],
				'pages'    => $pages,
				'passed'   => 0 === (int) $accumulated[ $slug ]['findings'] && ! $partial,
			);
			if ( $partial ) {
				$row['partial'] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The finished 'readability' value for a stored audit
	 * result (Tier 2, T2-05): the Flesch-Kincaid grade
	 * computed from the run's aggregated counts, or null when
	 * the run collected too little text to judge (under 100
	 * words — a grade from a handful of words would be a junk
	 * number, and the panel simply does not render).
	 *
	 * @param array $counts { words, sentences, syllables, pages }.
	 * @return array|null { grade, words, pages } or null.
	 */
	private function audit_readability_summary( $counts ) {
		if ( ! is_array( $counts ) || (int) ( isset( $counts['words'] ) ? $counts['words'] : 0 ) < 100
			|| (int) ( isset( $counts['sentences'] ) ? $counts['sentences'] : 0 ) < 1 ) {
			return null;
		}
		$words     = (int) $counts['words'];
		$sentences = (int) $counts['sentences'];
		$syllables = (int) $counts['syllables'];
		$grade     = 0.39 * ( $words / $sentences ) + 11.8 * ( $syllables / $words ) - 15.59;
		return array(
			'grade' => round( max( 0.0, $grade ), 1 ),
			'words' => $words,
			'pages' => (int) $counts['pages'],
		);
	}

	/**
	 * Assemble and store the final result of a completed run and
	 * report back to the Audit tab.
	 *
	 * @param array $state Run state (see run_audit()).
	 */
	private function finish_audit( $state ) {
		$result = array(
			'time'          => current_time( 'mysql' ),
			// Only pages actually fetched count as scanned; failures
			// are reported separately instead of inflating the number.
			'pages_scanned' => $state['ok'],
			'pages_failed'  => count( $state['pages'] ) - $state['ok'],
			'pages'         => $state['pages'],
			'findings'      => $state['findings'],
			// 'total' is the TRUE number of findings across all pages;
			// 'stored' is how many are kept for display (capped at 300).
			'total'         => $state['total'],
			'stored'        => count( $state['findings'] ),
			'previews'      => $state['previews'],
			// Pages obtained by internal rendering because they
			// could not be fetched (labelled in the results).
			'pages_rendered' => $state['rendered'],
			'complete'      => true,
			// A run that scanned ZERO pages is a FAILED run,
			// not a clean one (loop round 3, R3-03): the
			// results screen already said so ("Audit failed:
			// none of your pages could be fetched"), but the
			// stored record read as an unqualified complete,
			// clean scan — and the documents built from it
			// (the remediation report, the accessibility
			// statement) printed "found no issues" for a site
			// that was never read. The marker is the monitor's
			// own vocabulary for this exact state; readers
			// also derive it for records stored before this
			// batch (a finished audit-source result with
			// pages_scanned 0).
			'failed'        => 0 === (int) $state['ok'],
			// The first fetch failure of the run, verbatim (transport
			// message or HTTP status). The results screen shows it
			// when nothing could be scanned, so a failed audit is
			// diagnosable instead of mysterious.
			'fetch_error'   => mb_substr( wp_strip_all_tags( (string) $state['fetch_error'] ), 0, 300 ),
			// Repeated-page detection (1.4): non-null when most
			// fetched pages were one identical holding page.
			'wall'          => $this->detect_wall( $state['pages'], $state['ok'] ),
			// Tier 2 (T2-02): the full checklist for this run —
			// every check that ran on at least one page, with
			// its findings total and whether it passed (ran
			// everywhere it ran and found nothing). Passes are
			// presentation data only: they are NOT findings and
			// never enter the coverage model or any count.
			'checks'        => $this->audit_checks_summary( isset( $state['checks'] ) ? $state['checks'] : array(), (int) $state['ok'] ),
			// Tier 2 (T2-05): readability of the run's page
			// text, or null when too little text was collected
			// to judge (under 100 words in total).
			'readability'   => $this->audit_readability_summary( isset( $state['readability'] ) ? $state['readability'] : array() ),
		);
		if ( null !== $state['previous_total'] ) {
			$result['previous_total'] = $state['previous_total'];
			$result['previous_time']  = $state['previous_time'];
		}
		if ( isset( $state['previous_problems'] ) && null !== $state['previous_problems'] ) {
			$result['previous_problems'] = (int) $state['previous_problems'];
		}
		update_option( self::AUDIT_OPTION, $result, false );
		// The run is over: release the single-run lock (round
		// 3, S3-3). The render-death path reaches this method
		// too, so an interrupted run frees the lock here; a
		// hard kill leaves it to its ten-minute expiry. The
		// release is ownership-checked (round 4, S4-1): the
		// lock carries the owning run's token, and a run that
		// was superseded must not unlock its replacement. A
		// lock holding the pre-LC-4 value (an int 1 from a run
		// started before this batch, gone within its
		// ten-minute expiry) is still released.
		$lock = get_transient( self::AUDIT_LOCK );
		if ( false === $lock || '1' === (string) $lock || ( ! empty( $state['token'] ) && hash_equals( (string) $lock, (string) $state['token'] ) ) ) {
			delete_transient( self::AUDIT_LOCK );
		}
		// Next batch: the one audit-completion signal. Pro's
		// statement auto-refresh listens here; nothing else in
		// the free plugin depends on it, and listeners must never
		// be able to break the audit's own redirect, so it fires
		// after the result + history are stored.
		do_action( 'equalfix_audit_complete', $result );
		// Trend history (1.1 round 2): one compact summary per
		// completed audit, capped at 12 — powers the dashboard
		// trend chart. Counts come from the stored findings, the
		// total is the run's true total.
		// A failed run appends NO history point (round 3,
		// R3-03): the trend chart has no status field, so a
		// zero-total point from a run that read nothing is
		// indistinguishable from a clean scan there. The run
		// record itself (AUDIT_OPTION, marked failed) is the
		// record of the attempt.
		if ( empty( $result['failed'] ) ) {
			$history = get_option( 'equalfix_audit_history', array() );
			if ( ! is_array( $history ) ) {
				$history = array();
			}
			$history[] = array(
				'time'  => strtotime( $result['time'] ),
				'total' => (int) $state['total'],
				'a'     => $this->count_category( $state['findings'], EqualFix_Audit::CAT_FIX_A ),
				'b'     => $this->count_category( $state['findings'], EqualFix_Audit::CAT_FIX_B ),
				'q'     => $this->queue_eligible_count( $state['findings'] ),
			);
			update_option( 'equalfix_audit_history', array_slice( $history, -12 ), false );
		}
		if ( 0 === $state['ok'] ) {
			// Nothing was scanned: never report that as a completed
			// audit. The Audit tab renders the full explanation.
			$this->redirect( 'audit', __( 'Audit failed: none of your pages could be fetched, so nothing was scanned. This is not a clean result — see the Overview for what to do next.', 'equalfix' ) );
		}
		if ( ! empty( $result['wall'] ) ) {
			$this->redirect( 'audit', sprintf( /* translators: 1: repeated pages, 2: pages fetched */ __( 'Audit limited: %1$d of the %2$d pages fetched returned exactly the same page — your site is likely showing a coming-soon or maintenance page to visitors, so most pages were not really checked. See the Overview for details.', 'equalfix' ), (int) $result['wall']['repeated'], (int) $result['wall']['of'] ) );
		}
		// Loop fix round (test-4), BUG 2: the completion notice
		// speaks in PROBLEMS — the distinct unit the Overview
		// hero uses — with the findings total named secondarily.
		// In findings alone it read as contradicting the hero
		// (live: "263 findings" in the notice vs "189 problems"
		// on the same screen).
		$finish_model = $this->coverage_model( $result, self::get() );
		$message = sprintf(
			/* translators: 1: problems phrase, 2: pages phrase, 3: findings phrase */
			__( 'Audit complete: %1$s across %2$s (%3$s).', 'equalfix' ),
			sprintf( _n( '%d problem', '%d problems', (int) $finish_model['d_total'], 'equalfix' ), (int) $finish_model['d_total'] ),
			sprintf( _n( '%d page', '%d pages', $state['ok'], 'equalfix' ), $state['ok'] ),
			sprintf( _n( '%d finding', '%d findings', $state['total'], 'equalfix' ), $state['total'] )
		);
		if ( $state['rendered'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of pages rendered inside WordPress */
				_n( '%d of those pages could not be fetched, so it was rendered inside WordPress instead.', '%d of those pages could not be fetched, so they were rendered inside WordPress instead.', $state['rendered'], 'equalfix' ),
				$state['rendered']
			);
		}
		$this->redirect( 'audit', $message );
	}

	/**
	 * Persist an in-progress audit snapshot. If the request is killed
	 * (hosting time limit), this partial result — marked incomplete —
	 * is what the Audit tab shows, with an explanation, instead of
	 * the run vanishing entirely.
	 *
	 * @param array $pages     Per-page records so far.
	 * @param array $findings  Stored findings so far.
	 * @param int   $total_all True findings total so far.
	 * @param int   $ok_count  Pages successfully fetched so far.
	 */
	private function save_audit_progress( $pages, $findings, $total_all, $ok_count, $state = null ) {
		$snapshot = array(
			'time'          => current_time( 'mysql' ),
			// Epoch twin of 'time' (round 3, S3-3): the
			// single-run lock's staleness test compares epochs,
			// which a timezone offset between the site's
			// stored local time and the server clock cannot
			// skew the way strtotime() on a local-time string
			// could.
			'updated_epoch' => time(),
			'pages_scanned' => $ok_count,
			'pages_failed'  => count( $pages ) - $ok_count,
			'pages'         => $pages,
			'findings'      => $findings,
			'total'         => $total_all,
			'stored'        => count( $findings ),
			'previews'      => $state ? $state['previews'] : array(),
			'complete'      => false,
		);
		if ( $state ) {
			$snapshot['pages_rendered'] = $state['rendered'];
			// The resumable run state: the queue of unprocessed
			// URLs, accumulated previews, and the continuation
			// token. Present only while a stepped run is alive.
			$snapshot['step_state'] = $state;
		}
		update_option( self::AUDIT_OPTION, $snapshot, false );
	}

	/**
	 * Redirect back to the settings page with a notice.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $message Notice message.
	 */
	private function redirect( $tab, $message ) {
		$url = ( 'audit' === $tab )
			? $this->audit_url( array( 'equalfix_notice' => rawurlencode( $message ) ) )
			: add_query_arg( array( 'equalfix_notice' => rawurlencode( $message ) ), $this->tab_url( $tab ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirect back with an error notice (1.2): installer and
	 * dismissal failures are reported as errors, not successes.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $message Notice message.
	 */
	private function redirect_error( $tab, $message ) {
		$url = add_query_arg( array( 'equalfix_error' => rawurlencode( $message ) ), $this->tab_url( $tab ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	/**
	 * Resolve a requested tab slug to its canonical IA home
	 * (IA batch). Legacy slugs keep working: every pre-IA link,
	 * bookmark, redirect and Pro post-action lands on the tab
	 * that owns the job now. Returns { tab, view } — view is an
	 * Issues-view override implied by the legacy slug, or null.
	 *
	 * @param string $raw Sanitized tab slug from the request.
	 * @return array
	 */
	private function resolve_route( $raw ) {
		switch ( $raw ) {
			case 'audit': // The pre-1.2 name: the audit continuation chain lands here.
				return array( 'tab' => 'overview', 'view' => null );
			case 'findings':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selection.
				$todo = isset( $_GET['show'] ) && 'todo' === sanitize_key( wp_unslash( $_GET['show'] ) );
				return array( 'tab' => 'issues', 'view' => $todo ? 'todo' : null );
			case 'fixed':
				return array( 'tab' => 'issues', 'view' => 'fixed' );
			case 'purge':
				return array( 'tab' => 'history', 'view' => null );
			case 'pro':
				return array( 'tab' => 'pro', 'view' => null );
			case 'pro-overview':
				return array( 'tab' => 'overview', 'view' => null );
			case 'pro-queue':
				return array( 'tab' => 'review', 'view' => null );
			case 'pro-fixb':
			case 'pro-licence':
				return array( 'tab' => 'settings', 'view' => null );
			case 'pro-monitoring':
			case 'pro-reports':
				return array( 'tab' => 'history', 'view' => null );
			case 'pro-inbox':
				return array( 'tab' => 'issues', 'view' => 'flagged' );
			case 'issues':
			case 'review':
			case 'history':
			case 'settings':
			case 'overview':
				return array( 'tab' => $raw, 'view' => null );
			default:
				return array( 'tab' => 'overview', 'view' => null );
		}
	}

	/**
	 * The ONE definition of "the standard fixes are on" (IA
	 * batch): the master switch AND at least one Fix Set A type.
	 * The header pill and the fix console's Standard card both
	 * read this, so they can never disagree again.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function standard_scope_on( $settings ) {
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'equalfix' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$raw_tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$route          = $this->resolve_route( $raw_tab );
		$tab            = $route['tab'];
		$this->ia_view  = $route['view'];
		$settings       = self::get();
		$pro            = self::pro_state();
		if ( 'pro' === $tab && $pro['shell'] ) {
			// In shell mode there is no separate Pro home: the
			// old Pro landing IS the Overview now.
			$tab = 'overview';
		}
		$pill_on    = $this->standard_scope_on( $settings );
		$pending_n  = 0;
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) && method_exists( 'EqualFix_Pro_Suggestions', 'split_pending_by_audit' ) ) {
			$split     = EqualFix_Pro_Suggestions::split_pending_by_audit();
			$pending_n = isset( $split['current'] ) ? count( $split['current'] ) : 0;
		}
		?>
		<div class="wrap equalfix-wrap">
			<div class="equalfix-header">
				<img class="equalfix-logo" src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/equalfix-logo-128.png' ); ?>" alt="" width="52" height="52">
				<div class="equalfix-header-text">
					<h1><?php esc_html_e( 'EqualFix Accessibility', 'equalfix' ); ?> <span class="equalfix-version">v<?php echo esc_html( EQUALFIX_VERSION ); ?></span></h1>
					<p><?php esc_html_e( 'Detect accessibility issues on your site and apply safe, reversible fixes to the rendered HTML. Nothing is written to your theme files or database content.', 'equalfix' ); ?></p>
				</div>
				<div class="equalfix-header-status">
					<?php if ( $pill_on ) : ?>
						<span class="equalfix-status equalfix-status-on"><span class="equalfix-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixes active', 'equalfix' ); ?></span>
					<?php else : ?>
						<span class="equalfix-status equalfix-status-off"><span class="equalfix-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixes off', 'equalfix' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php // Notice args are display-only and scalar-gated (round 5, R5-04): a non-scalar arg is ignored — never cast, so it can neither warn nor render as the literal text "Array". ?>
			<?php if ( isset( $_GET['equalfix_notice'] ) && is_scalar( $_GET['equalfix_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['equalfix_notice'] ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['equalfix_error'] ) && is_scalar( $_GET['equalfix_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['equalfix_error'] ) ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'EqualFix sections', 'equalfix' ); ?>">
				<a href="<?php echo esc_url( $this->tab_url( 'overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'issues' ) ); ?>" class="nav-tab <?php echo 'issues' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Issues', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'review' ) ); ?>" class="nav-tab <?php echo 'review' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Review', 'equalfix' ); ?><?php if ( $pending_n > 0 ) : ?> <span class="eqf-tab-count"><?php echo (int) $pending_n; ?></span><?php endif; ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'history' ) ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'History', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'equalfix' ); ?><?php if ( ! empty( $pro['update_available'] ) ) : ?> <span class="eqf-tab-update"><?php esc_html_e( 'Update', 'equalfix' ); ?></span><?php endif; ?></a>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings( $settings );
			} elseif ( 'history' === $tab ) {
				$this->render_history( $pro );
			} elseif ( 'review' === $tab ) {
				$this->render_review_tab( $pro );
			} elseif ( 'pro' === $tab ) {
				$this->render_pro_tab( $pro );
			} elseif ( 'issues' === $tab ) {
				$this->render_audit( 'issues' );
			} else {
				$this->render_audit( 'overview' );
			}
			?>

			<hr>
			<p class="description"><?php esc_html_e( 'This tool assists with identifying and remediating specific accessibility issues in your site’s HTML output. It does not provide legal advice and does not guarantee compliance with the ADA, WCAG, or any other law or standard. Accessibility is an ongoing process, and some issues require manual review and fixes. Responsibility for your site’s accessibility remains with you, the site owner.', 'equalfix' ); ?></p>
		</div>
		<?php
	}

	/**
	 * URL for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function tab_url( $tab, $extra = array() ) {
		// Settings is its own submenu page (it forces its tab
		// when rendering); every other IA tab lives on the main
		// dashboard page as a ?tab= view.
		if ( 'settings' === $tab && empty( $extra ) ) {
			return add_query_arg( array( 'page' => 'equalfix-settings' ), admin_url( 'admin.php' ) );
		}
		// IA batch: legacy slugs translate to their canonical
		// homes, so every existing internal link, redirect and
		// stored URL lands where the job lives now.
		$map = array(
			'audit'          => array(),
			'overview'       => array(),
			'pro-overview'   => array(),
			'pro'            => array( 'tab' => 'pro' ),
			'issues'         => array( 'tab' => 'issues' ),
			'findings'       => array( 'tab' => 'issues' ),
			'fixed'          => array( 'tab' => 'issues', 'view' => 'fixed' ),
			'review'         => array( 'tab' => 'review' ),
			'pro-queue'      => array( 'tab' => 'review' ),
			'history'        => array( 'tab' => 'history' ),
			'purge'          => array( 'tab' => 'history' ),
			'pro-monitoring' => array( 'tab' => 'history' ),
			'pro-reports'    => array( 'tab' => 'history' ),
			'settings'       => array( 'tab' => 'settings' ),
			'pro-fixb'       => array( 'tab' => 'settings' ),
			'pro-licence'    => array( 'tab' => 'settings' ),
			'pro-inbox'      => array( 'tab' => 'issues', 'view' => 'flagged' ),
		);
		$args = array( 'page' => 'equalfix-accessibility' );
		if ( isset( $map[ $tab ] ) ) {
			$args = array_merge( $args, $map[ $tab ] );
		} else {
			$args['tab'] = $tab;
		}
		if ( ! empty( $extra ) ) {
			$args = array_merge( $args, $extra );
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		if ( 'pro-licence' === $tab ) {
			// The licence section's anchor on the Settings tab.
			$url .= '#eqp-licence-section';
		} elseif ( 'pro-fixb' === $tab ) {
			// The Fix Set B section's anchor on the Settings tab
			// (IA-12: generated links land on the section, like
			// pro-licence's always did).
			$url .= '#eqp-fixb-section';
		}
		return $url;
	}

	/**
	 * The Dashboard (audit tab) URL, with optional extra args —
	 * the audit continuation chain and post-action redirects land
	 * here, under the top-level menu (admin.php since 1.1).
	 *
	 * @param array $extra Extra query args.
	 * @return string
	 */
	private function audit_url( $extra = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => 'equalfix-accessibility', 'tab' => 'audit' ), $extra ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the settings form.
	 *
	 * @param array $settings Settings.
	 */
	private function render_settings( $settings ) {
		$fix_types = self::fix_types();
		$pro       = self::pro_state();
		// IA batch: when Pro is present and shell-capable, its
		// sections (Fix Set B, monitoring schedule, licence)
		// render inside this tab — the Pro pitch cards below
		// then stand down.
		$pro_sections = ! empty( $pro['present'] ) && class_exists( 'EqualFix_Pro_Settings' ) && method_exists( 'EqualFix_Pro_Settings', 'render_settings_sections' );
		$au_plugins   = get_site_option( 'auto_update_plugins', array() );
		$au_on        = is_array( $au_plugins ) && in_array( plugin_basename( EQUALFIX_FILE ), $au_plugins, true );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="save_settings">

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Automatic fixes', 'equalfix' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable fixes', 'equalfix' ); ?></th>
					<td>
						<label for="equalfix_enabled">
							<input type="checkbox" id="equalfix_enabled" name="equalfix_enabled" value="1" <?php checked( $settings['enabled'], 1 ); ?>>
							<?php esc_html_e( 'Apply the enabled fix types to the rendered HTML of public pages.', 'equalfix' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'This is the same switch as the fix console on the Overview (Apply Standard Fixes / Pause) — one gate, two surfaces. Fixes are applied at render time only. Deactivating this plugin restores your original output immediately.', 'equalfix' ); ?></p>
					</td>
				</tr>
			</table>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Fix types (the complete set of free fixes)', 'equalfix' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These toggles choose what “Apply Standard Fixes” on the Overview includes. Each fix type can be previewed on the Overview and enabled or disabled individually; while fixes are on, changing a type updates what is applied right away, and disabling a fix type returns that output to its original form immediately.', 'equalfix' ); ?></p>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Fix types', 'equalfix' ); ?></legend>
				<table class="form-table" role="presentation">
					<?php foreach ( $fix_types as $key => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label for="equalfix_fix_<?php echo esc_attr( $key ); ?>">
									<input type="checkbox" id="equalfix_fix_<?php echo esc_attr( $key ); ?>" name="equalfix_fix_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $settings[ 'fix_' . $key ], 1 ); ?>>
									<?php echo esc_html( $settings[ 'fix_' . $key ] ? __( 'Enabled', 'equalfix' ) : __( 'Disabled', 'equalfix' ) ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</fieldset>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Exclusions', 'equalfix' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="equalfix_excluded_urls"><?php esc_html_e( 'Excluded pages', 'equalfix' ); ?></label></th>
					<td>
						<textarea id="equalfix_excluded_urls" name="equalfix_excluded_urls" rows="4" cols="60" class="large-text code" aria-describedby="equalfix_excluded_urls_desc"><?php echo esc_textarea( $settings['excluded_urls'] ); ?></textarea>
						<p class="description" id="equalfix_excluded_urls_desc"><?php esc_html_e( 'One URL path per line, e.g. /checkout/ or /my-custom-page/. A path matches that page and everything under it (but not a different page that merely starts alike, like /checkout-info/).', 'equalfix' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="equalfix_excluded_selectors"><?php esc_html_e( 'Excluded elements', 'equalfix' ); ?></label></th>
					<td>
						<textarea id="equalfix_excluded_selectors" name="equalfix_excluded_selectors" rows="3" cols="60" class="large-text code" aria-describedby="equalfix_excluded_selectors_desc"><?php echo esc_textarea( $settings['excluded_selectors'] ); ?></textarea>
						<p class="description" id="equalfix_excluded_selectors_desc"><?php esc_html_e( 'One CSS selector per line, e.g. #my-slider or .no-fix. Fixes are skipped inside matching elements.', 'equalfix' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="equalfix_excluded_templates"><?php esc_html_e( 'Excluded templates', 'equalfix' ); ?></label></th>
					<td>
						<input type="text" id="equalfix_excluded_templates" name="equalfix_excluded_templates" value="<?php echo esc_attr( $settings['excluded_templates'] ); ?>" class="large-text" aria-describedby="equalfix_excluded_templates_desc">
						<p class="description" id="equalfix_excluded_templates_desc"><?php esc_html_e( 'Comma-separated page template file names, e.g. template-landing.php. Fixes are skipped on pages using these templates.', 'equalfix' ); ?></p>
					</td>
				</tr>
			</table>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Cache behavior', 'equalfix' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic purge', 'equalfix' ); ?></th>
					<td>
						<label for="equalfix_auto_purge">
							<input type="checkbox" id="equalfix_auto_purge" name="equalfix_auto_purge" value="1" <?php checked( $settings['auto_purge'], 1 ); ?>>
							<?php esc_html_e( 'Purge page caches automatically when settings change.', 'equalfix' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Output cache', 'equalfix' ); ?></th>
					<td>
						<label for="equalfix_output_cache">
							<input type="checkbox" id="equalfix_output_cache" name="equalfix_output_cache" value="1" <?php checked( $settings['output_cache'], 1 ); ?>>
							<?php esc_html_e( 'Cache fixed output for visitors (never for logged-in users, carts, or checkouts).', 'equalfix' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Deleting EqualFix', 'equalfix' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Deactivating EqualFix keeps your settings and history. Deleting EqualFix removes everything — settings, scan history, approvals and rules — and all fixes stop applying, so your site returns to exactly how it was before.', 'equalfix' ); ?></p>
					</td>
				</tr>
			</table>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Service connection', 'equalfix' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'equalfix' ); ?></th>
					<td><?php esc_html_e( 'Local mode. The audit and fixes run entirely on your site; nothing is sent anywhere automatically.', 'equalfix' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When the service is contacted', 'equalfix' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Only when you ask it to: pasting a licence key in the Pro section below and clicking Install sends that key and your site URL to the EqualFix service to validate the key and download the Pro add-on. If Pro is installed, Pro also validates its licence and — only for images you ask it to draft alt text for — uses the EqualFix vision service (see Pro\'s own disclosure). The free plugin never sends page content, visitor, customer, order, or personal data.', 'equalfix' ); ?> <a href="https://equalfix.com/privacy" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'equalfix' ); ?></a> · <a href="https://equalfix.com/terms" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of Service', 'equalfix' ); ?></a></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic updates', 'equalfix' ); ?></th>
					<td><?php echo esc_html( sprintf( __( 'Automatic updates: %s (WordPress setting)', 'equalfix' ), $au_on ? __( 'on', 'equalfix' ) : __( 'off', 'equalfix' ) ) ); ?> <span class="description"><?php esc_html_e( 'Change it on the Plugins screen.', 'equalfix' ); ?></span></td>
				</tr>
			</table>
			</div>

			<?php submit_button( __( 'Save settings', 'equalfix' ) ); ?>
		</form>

		<div class="equalfix-card">
			<h2><?php esc_html_e( 'Advanced', 'equalfix' ); ?></h2>
			<p><a href="<?php echo esc_url( $this->tab_url( 'purge' ) ); ?>"><?php esc_html_e( 'Cache purge log', 'equalfix' ); ?></a> — <?php esc_html_e( 'the record of every cache purge on this site, kept in the History tab for troubleshooting.', 'equalfix' ); ?></p>
		</div>

		<?php if ( $pro_sections ) : ?>
		<div class="equalfix-pro-wrap">
			<?php ( new EqualFix_Pro_Settings() )->render_settings_sections(); ?>
		</div>
		<?php endif; ?>

		<?php if ( $pro['licence_active'] && ! $pro_sections ) : ?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Pro is active on this site', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'Extra fixes, the approval queue, monitoring, and reports are running — manage them from the Pro sections of this dashboard.', 'equalfix' ); ?></p>
			</div>
			<div class="equalfix-pro-side">
				<p><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-licence' : 'pro' ) ); ?>" class="button equalfix-pro-cta"><?php esc_html_e( 'Open Pro', 'equalfix' ); ?></a></p>
			</div>
		</div>
		<?php elseif ( $pro['present'] && ! $pro_sections ) : ?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Pro is installed — activate it to finish', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'The Pro add-on is on this site, but its licence is not active, so Pro features are off. Paste your key to switch everything on.', 'equalfix' ); ?></p>
			</div>
			<div class="equalfix-pro-side">
				<p><a href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>" class="button equalfix-pro-cta"><?php esc_html_e( 'Activate Pro', 'equalfix' ); ?></a></p>
			</div>
		</div>
		<?php elseif ( ! $pro_sections ) : ?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Need more than the free fixes?', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'The Pro add-on picks up where the free fix set stops — and keeps watching your site after you fix it.', 'equalfix' ); ?></p>
				<ul class="equalfix-pro-features">
					<li><?php esc_html_e( 'Extra fixes: heading order, menu & accordion states, table headers', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'WooCommerce-specific fixes for product and checkout pages', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Approval queue for judgment-call suggestions — nothing changes without your OK', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Scheduled monitoring with emailed summaries', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Dated PDF remediation reports — your proof-of-effort record', 'equalfix' ); ?></li>
				</ul>
			</div>
			<div class="equalfix-pro-side">
				<p class="equalfix-pro-price"><span class="equalfix-pro-price-num">$39</span><?php esc_html_e( '/mo', 'equalfix' ); ?></p>
				<p class="equalfix-pro-price-note"><?php esc_html_e( 'One site · $99/mo for 10 sites · $199/mo for 25 sites', 'equalfix' ); ?><br><?php esc_html_e( 'Annual billing saves 15%', 'equalfix' ); ?></p>
				<p><a href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" class="button equalfix-pro-cta" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro pricing', 'equalfix' ); ?></a></p>
			</div>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the audit tab.
	 */
	private function render_audit( $section = 'overview' ) {
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) ) {
			if ( 'overview' === $section ) {
				$this->render_dashboard_empty();
			} else {
				?>
				<div class="equalfix-card">
					<h3><?php esc_html_e( 'Run an audit first', 'equalfix' ); ?></h3>
					<p><?php esc_html_e( 'There are no audit results yet. Run your first audit and this tab fills with what it finds.', 'equalfix' ); ?></p>
					<?php $this->render_audit_form(); ?>
				</div>
				<?php
			}
			return;
		}
		if ( isset( $result['complete'] ) && ! $result['complete'] ) {
			?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This audit did not finish — it was interrupted, most likely by a hosting time limit. The results below cover only the pages scanned before it stopped. Run the audit again to complete it.', 'equalfix' ); ?></p></div>
			<?php
		}
		if ( 0 === (int) $result['pages_scanned'] && 0 === (int) $result['pages_failed'] ) {
			// Nothing was scanned and nothing failed: discovery found
			// no pages at all. That is not a clean bill of health
			// either — say so instead of rendering "no issues found".
			?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'This audit did not scan any pages.', 'equalfix' ); ?></strong></p>
				<p><?php esc_html_e( 'EqualFix could not find any public pages to check on your site, so nothing was verified. If your site has public pages, something is preventing EqualFix from discovering them — check that your site is not blocking crawlers and that your pages are published, then run the audit again.', 'equalfix' ); ?></p>
			</div>
			<?php
			return;
		}
		if ( 0 === (int) $result['pages_scanned'] && (int) $result['pages_failed'] > 0 ) {
			// Total fetch failure. A scan of nothing is NOT a clean
			// site: render only the failure state — no counts, no
			// journey, no findings, no upsell — because every one of
			// those would read as "no issues found".
			?>
			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'This audit did not scan anything.', 'equalfix' ); ?></strong></p>
				<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of pages that could not be fetched */
						__( 'EqualFix tried to fetch %d page(s) from your site and every request failed, so no page was checked. Your site has NOT been given a clean bill of health — the issues below, if any were listed, would be meaningless.', 'equalfix' ),
						(int) $result['pages_failed']
					)
				);
				?>
				</p>
				<p><?php esc_html_e( 'This usually means your hosting blocks "loopback" requests — WordPress asking its own site for its pages. It is the same check WordPress shows under Site Health, and some hosts, local development tools, and WordPress Playground block it.', 'equalfix' ); ?></p>
				<p><?php esc_html_e( 'EqualFix also tried its fallback for exactly this situation — rendering your pages inside WordPress, which needs no loopback — and that did not succeed either.', 'equalfix' ); ?></p>
				<?php if ( ! empty( $result['fetch_error'] ) ) : ?>
				<p><?php esc_html_e( 'What your server answered:', 'equalfix' ); ?> <code><?php echo esc_html( $result['fetch_error'] ); ?></code></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'What to do: check Site Health in your WordPress dashboard for a loopback warning, or ask your host to allow loopback requests for your site. Then run the audit again.', 'equalfix' ); ?></p>
			</div>
			<?php
			return;
		}
		$settings = self::get();
		$notes    = array();
		if ( isset( $result['pages_failed'] ) && (int) $result['pages_failed'] > 0 ) {
			$failed_labels = array();
			if ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) {
				foreach ( $result['pages'] as $page ) {
					if ( isset( $page['status'] ) && 'fetch_failed' === $page['status'] && ! empty( $page['url'] ) ) {
						$failed_labels[] = self::page_label( $page['url'] );
					}
				}
			}
			$notes[] = sprintf(
				/* translators: 1: number of pages that could not be scanned at all, 2: the failed pages, when known */
				__( '%1$d page(s) could not be scanned at all — they could not be fetched, and the in-WordPress render fallback did not succeed for them either.%2$s', 'equalfix' ),
				(int) $result['pages_failed'],
				$failed_labels ? ' ' . sprintf( /* translators: %s: comma-separated page labels */ __( 'The page(s) were: %s.', 'equalfix' ), implode( ', ', array_slice( $failed_labels, 0, 10 ) ) ) : ''
			);
		}
		if ( ! empty( $result['wall'] ) ) {
			$notes[] = sprintf(
				/* translators: 1: pages that returned the same page, 2: pages fetched */
				__( 'This scan was limited: %1$d of the %2$d pages fetched returned exactly the same page. That is what a coming-soon, maintenance, or login page looks like to a visitor — your real pages were probably not checked. Turn off any coming-soon or maintenance mode and run the audit again.', 'equalfix' ),
				(int) $result['wall']['repeated'],
				(int) $result['wall']['of']
			);
		}
		if ( isset( $result['pages_rendered'] ) && (int) $result['pages_rendered'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of pages rendered inside WordPress */
				_n( '%d page could not be fetched from the server, so it was rendered inside WordPress instead. A rendered page reflects a logged-out view without any external page cache, so its results can differ slightly from a live visit.', '%d pages could not be fetched from the server, so they were rendered inside WordPress instead. Rendered pages reflect a logged-out view without any external page cache, so their results can differ slightly from a live visit.', (int) $result['pages_rendered'], 'equalfix' ),
				(int) $result['pages_rendered']
			);
		}
		if ( isset( $result['stored'] ) && (int) $result['total'] > (int) $result['stored'] ) {
			$notes[] = sprintf(
				/* translators: %d: number of findings stored for display */
				__( 'Showing the first %d below — the counts and lists are based on those.', 'equalfix' ),
				(int) $result['stored']
			);
		}
		if ( isset( $result['previous_total'] ) ) {
			// Loop fix round (test-4), BUG 2: the change-since
			// line compares PROBLEMS with problems when the
			// previous run's distinct total rode the chain;
			// results stored before this round carry only the
			// findings chain and keep the findings wording.
			// Labelling polish (LP-3): print the previous run's
			// time the friendly way the rest of the UI does
			// (History table, fix console) instead of the raw
			// stored datetime. Display only — the stored value
			// and the delta computation are untouched, and an
			// unparseable stored value prints as stored.
			$prev_time_raw = isset( $result['previous_time'] ) ? (string) $result['previous_time'] : '';
			$prev_time_ts  = '' !== $prev_time_raw ? strtotime( $prev_time_raw ) : false;
			$prev_time_lbl = false !== $prev_time_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $prev_time_ts ) : $prev_time_raw;
			if ( isset( $result['previous_problems'] ) ) {
				$delta_model = $this->coverage_model( $result, self::get() );
				$notes[] = sprintf(
					/* translators: 1: change, 2: date */
					__( 'Change since %2$s: %+d problems.', 'equalfix' ),
					(int) $delta_model['d_total'] - (int) $result['previous_problems'],
					$prev_time_lbl
				);
			} else {
				$notes[] = sprintf(
					/* translators: 1: change, 2: date */
					__( 'Change since %2$s: %+d findings.', 'equalfix' ),
					$result['total'] - $result['previous_total'],
					$prev_time_lbl
				);
			}
		}
		$all_on = $this->all_free_fixes_on();
		// IA batch: does the stored audit predate the current
		// version's first run? Drives the Overview provenance
		// line and the "Run a fresh scan" CTA.
		$seen              = get_option( 'equalfix_version_seen_at', null );
		$this->audit_stale = false;
		if ( is_array( $seen ) && isset( $seen['time'], $seen['version'] ) && (string) $seen['version'] === EQUALFIX_VERSION && ! empty( $result['time'] ) ) {
			$audit_ts          = strtotime( (string) $result['time'] );
			$this->audit_stale = $audit_ts && $audit_ts < (int) $seen['time'];
		}
		?>
		<?php
		$counts = array( 'fix-a' => 0, 'fix-b' => 0, 'human' => 0 );
		foreach ( (array) $result['findings'] as $finding ) {
			if ( isset( $counts[ $finding['category'] ] ) ) {
				$counts[ $finding['category'] ]++;
			} else {
				$counts['human']++;
			}
		}
		// Findings the Pro approval queue can draft a page-derived
		// suggestion for — counted by the shared helper so the
		// stat, the Pro card, and the trend history never disagree.
		$queue_eligible = $this->queue_eligible_count( $result['findings'] );
		?>
		<?php if ( 'overview' === $section ) : ?>
			<?php $this->render_dashboard( $result, $counts, $queue_eligible, $settings, $notes ); ?>
			<?php return; ?>
		<?php endif; ?>

		<?php
		// IA batch: everything else is the Issues tab (the old
		// Findings + Fixed tabs merged — see render_issues()).
		$this->render_issues( $result, $settings, $notes, $all_on );
	}

	/**
	 * The Issues tab (IA batch): the old Findings and Fixed
	 * tabs merged into one surface. A status-chip row picks the
	 * view — all / todo / waiting / fixed / aside (+ flagged
	 * when Pro is present) — and every count and list comes
	 * from the ONE coverage model, so the views are a partition
	 * of the same audit, never separate tallies. The views
	 * replace the old "Show all N" burial fold: nothing is
	 * folded behind a count any more.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $settings Settings.
	 * @param array $notes    Scan caveat notes.
	 * @param bool  $all_on   Whether every free fix type is on.
	 */
	private function render_issues( $result, $settings, $notes, $all_on ) {
		$pro   = self::pro_state();
		$model = $this->coverage_model( $result, $settings );

		// Scan caveats travel with the findings they describe.
		foreach ( (array) $notes as $note ) {
			?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $note ); ?></p></div>
			<?php
		}

		// View resolution: an explicit ?view= wins, then the
		// view a legacy route implied, then the shipped
		// default (all).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( '' === $view && null !== $this->ia_view ) {
			$view = $this->ia_view;
		}
		if ( '' === $view && isset( $_GET['show'] ) && 'todo' === sanitize_key( wp_unslash( $_GET['show'] ) ) ) {
			$view = 'todo';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $view, array( 'all', 'todo', 'waiting', 'fixed', 'aside', 'flagged' ), true ) ) {
			$view = 'all';
		}
		if ( 'flagged' === $view && empty( $pro['present'] ) ) {
			$view = 'all';
		}
		// Issues redesign (2026-11-07, owner-approved mockup):
		// the quick filter the mockup's "One-click fixes" chip
		// applies — a render-layer list filter only (like the
		// existing group/page filters); no view, handler or
		// count changes ride on it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection.
		$only = isset( $_GET['only'] ) ? sanitize_key( wp_unslash( $_GET['only'] ) ) : '';
		if ( 'oneclick' !== $only ) {
			$only = '';
		}
		$oneclick_n = $this->issues_oneclick_count( $result, $model, $settings );

		if ( 'flagged' === $view || 'aside' === $view || 'fixed' === $view ) {
			$this->render_issues_chip_row( $model, $view, $only, $oneclick_n, $pro );
		}

		if ( 'flagged' === $view ) {
			?>
			<div class="equalfix-pro-wrap">
				<?php ( new EqualFix_Pro_Settings() )->render_flagged_panel(); ?>
			</div>
			<?php
			return;
		}
		if ( 'aside' === $view ) {
			if ( empty( self::dismissed_map() ) ) {
				?>
				<div class="equalfix-card">
					<h3><?php esc_html_e( 'Nothing set aside', 'equalfix' ); ?></h3>
					<p><?php esc_html_e( 'Findings you set aside from the Issues list appear here with their reason — and restoring one brings it back to the list.', 'equalfix' ); ?></p>
				</div>
				<?php
			} else {
				$this->render_dismissed_section();
			}
			return;
		}
		if ( 'fixed' === $view ) {
			$this->render_fixed_tab( $result, $settings );
			return;
		}

		// Issues redesign (2026-11-07; v2 cleanup same day,
		// owner-approved mockup v2): the working views open
		// with the heading and the summary strip; the fix
		// previews live in ONE slim collapsed bar directly
		// under the strip (All view, same condition as the
		// shipped section); and the two v1 chip rows are
		// merged into ONE chip row — the main views as chips,
		// Waiting / Flagged / Set aside behind "More views".
		$this->render_issues_heading( $result );
		if ( $model['d_total'] > 0 ) {
			$this->render_issues_summary( $result, $model, $settings, $oneclick_n );
		}
		if ( 'all' === $view && ! $all_on ) {
			$this->render_issues_previews_bar();
		}
		$this->render_issues_chip_row( $model, $view, $only, $oneclick_n, $pro );

		// The working lists for all / todo / waiting — slices of
		// the model's partition, exactly like the shipped
		// Findings list (non-dismissed) and to-do list were.
		$dismissed_map = self::dismissed_map();
		if ( 'todo' === $view ) {
			$list = $this->todo_findings( $result, $model );
		} elseif ( 'waiting' === $view ) {
			$list = $this->waiting_findings( $result, $model );
		} else {
			$list = array();
			foreach ( (array) $result['findings'] as $finding ) {
				if ( ! self::map_has_finding( $dismissed_map, $finding ) ) {
					$list[] = $finding;
				}
			}
		}
		$show_todo = ( 'todo' === $view );

		if ( $show_todo ) {
			// The to-do view's header IS the old todo banner
			// (family split + actions), and the two guidance
			// cards live at the top of this view only.
			$this->render_todo_banner( $list, $settings );
			$this->render_alt_coverage_card( $result );
		}
		if ( empty( $result['findings'] ) ) {
			?>
			<p><?php esc_html_e( 'No issues found by the automated checks. Note: automated checks cannot catch everything — some issues still need human review.', 'equalfix' ); ?></p>
			<?php
			// T2-02: a clean audit is exactly where the passed
			// checklist matters most — it is the proof of what
			// "no issues" actually covered.
			$this->render_checks_card( $result );
			return;
		}
		// Pages audited via in-process simulation (Q2 badge):
		$rendered_urls = array();
		if ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) {
			foreach ( $result['pages'] as $page ) {
				if ( isset( $page['mode'] ) && 'render' === $page['mode'] && ! empty( $page['url'] ) ) {
					$rendered_urls[] = $page['url'];
				}
			}
		}
		// Grouping: by job (the shipped family cards) or by page
		// (the page browser that replaces the old Pages card).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only grouping selection.
		$group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : 'job';
		if ( 'page' !== $group ) {
			$group = 'job';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page filter.
		$page_filter = isset( $_GET['page_url'] ) ? esc_url_raw( wp_unslash( $_GET['page_url'] ) ) : '';
		?>
		<p class="eqf-group-toggle" role="group" aria-label="<?php esc_attr_e( 'Group issues', 'equalfix' ); ?>">
			<a class="eqf-group-btn<?php echo 'job' === $group ? ' eqf-group-on' : ''; ?>" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $view, 'group' => 'job' ) ) ); ?>"><?php esc_html_e( 'By job', 'equalfix' ); ?></a>
			<a class="eqf-group-btn<?php echo 'page' === $group ? ' eqf-group-on' : ''; ?>" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $view, 'group' => 'page' ) ) ); ?>"><?php esc_html_e( 'By page', 'equalfix' ); ?></a>
		</p>
		<?php
		if ( 'page' === $group && '' === $page_filter ) {
			$this->render_pages_browser( $result, $model, $view );
			return;
		}
		if ( '' !== $page_filter ) {
			$filtered = array();
			foreach ( $list as $finding ) {
				if ( $finding['url'] === $page_filter ) {
					$filtered[] = $finding;
				}
			}
			$list = $filtered;
			?>
			<p class="eqf-page-filter"><?php echo esc_html( sprintf( __( 'Showing issues on %s only.', 'equalfix' ), self::page_label( $page_filter ) ) ); ?> <a href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $view ) ) ); ?>"><?php esc_html_e( 'Show all pages', 'equalfix' ); ?></a></p>
			<?php
		}
		if ( 'all' === $view && $model['d_total'] > 0 ) {
			?>
			<p class="eqf-findings-intro"><?php echo esc_html( sprintf( __( '%1$d fixed · %2$d waiting for your OK · %3$d still to do · %4$d for your review · %5$d set aside. Counts are distinct problems — the same element repeated on several pages counts once here and is listed once per page below. Fixed counts as fixed only when the fix is actually switched on — the Fixed view is the record.', 'equalfix' ), $model['d_fixed'], $model['d_waiting'], $model['d_todo'], $model['d_review'], $model['d_dismissed'] ) ); ?></p>
			<?php
		}
		if ( empty( $list ) ) {
			if ( 'todo' === $view ) {
				?>
				<p><?php esc_html_e( 'Nothing is still to do — everything from the last audit is fixed, waiting for your OK, or set aside.', 'equalfix' ); ?></p>
				<?php
			} elseif ( 'waiting' === $view ) {
				?>
				<p><?php esc_html_e( 'Nothing is waiting for your OK right now. When AI drafts are ready for your decision, the findings they cover wait here — approve them in Review.', 'equalfix' ); ?></p>
				<?php
			} else {
				?>
				<p><?php esc_html_e( 'Everything in the latest audit is currently set aside. The Set aside view lists each one with its reason, and restoring one brings it back to this list.', 'equalfix' ); ?></p>
				<?php
			}
			if ( 'all' === $view ) {
				$this->render_checks_card( $result );
			}
			return;
		}
		if ( 'all' === $view && 0 === $model['d_todo'] && 0 === $model['d_review'] && $model['d_total'] > 0 ) {
			$this->render_all_done_card( $result, $model );
		}
		$cards_list = $list;
		if ( 'oneclick' === $only ) {
			// The "One-click fixes" filter (redesign): only the
			// findings the summary strip's count is made of —
			// the same predicate, so the chip, the tile and
			// this list can never disagree.
			$cards_list = array();
			$manual_map = self::manual_matches_map( $result );
			$attr_map   = self::attr_matches_map( $result );
			foreach ( $list as $oneclick_finding ) {
				if ( $this->is_oneclick_finding( $oneclick_finding, $model, $settings, $manual_map, $attr_map ) ) {
					$cards_list[] = $oneclick_finding;
				}
			}
			?>
			<p class="eqf-filter-note"><?php esc_html_e( 'Showing only the problems the free fix set can fix in one click.', 'equalfix' ); ?> <a href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $view ) ) ); ?>"><?php esc_html_e( 'Show everything', 'equalfix' ); ?></a></p>
			<?php
		}
		if ( ! empty( $cards_list ) ) {
			$this->render_job_cards( $cards_list, $model, $settings, $show_todo, $rendered_urls, $result );
		} elseif ( 'oneclick' === $only ) {
			?>
			<p><?php esc_html_e( 'Nothing in this view can be fixed in one click right now.', 'equalfix' ); ?></p>
			<?php
		}
		if ( $model['d_fixed'] > 0 ) {
			$this->render_issues_fixed_strip( $model );
		}
		$this->render_toggle_script();
		if ( 'all' === $view ) {
			// T2-02: the full checklist, passes included, closes
			// the All view — failures above, the rest of what
			// the scan did below.
			$this->render_checks_card( $result );
		}
	}

	/**
	 * The ONE Issues chip row (redesign v2, 2026-11-07,
	 * owner-approved mockup v2): the IA status chips and
	 * the redesign's filter chips merged into a single row.
	 * The main views are chips — All problems (the All view,
	 * counting open problems: to do + waiting + review, the
	 * v1 filter chip's documented count), To do, One-click
	 * fixes (the to-do view through the render-layer
	 * filter), Needs your review (the to-do view, where the
	 * review groups live — the shipped "For review" chip
	 * and the v1 chip were the same view; one chip now),
	 * Fixed — and the remaining views (Waiting for your OK,
	 * Flagged by you, Set aside) sit behind one "More
	 * views" disclosure at the row's end. Every view that
	 * existed stays reachable with its existing URL; chip
	 * counts are DISTINCT problems from the coverage model
	 * (the dismissed map's count for Set aside, open flags
	 * for Flagged), exactly as the two rows counted them.
	 * The active view is marked, never hidden; when a More
	 * views entry is the current view the disclosure is
	 * marked instead (Needs your review is a shortcut onto
	 * the to-do view, never the current view itself — the
	 * same was true of the shipped "For review" chip).
	 *
	 * @param array  $model      A coverage_model() result.
	 * @param string $view       Active view slug.
	 * @param string $only       Active list filter (''|'oneclick').
	 * @param int    $oneclick_n One-click problem count.
	 * @param array  $pro        Pro state.
	 */
	private function render_issues_chip_row( $model, $view, $only, $oneclick_n, $pro ) {
		$open_n = (int) $model['d_todo'] + (int) $model['d_waiting'] + (int) $model['d_review'];
		$chips  = array(
			array( __( 'All problems', 'equalfix' ), $open_n, $this->tab_url( 'issues' ), 'all' === $view && '' === $only ),
			array( __( 'To do', 'equalfix' ), (int) $model['d_todo'], $this->tab_url( 'issues', array( 'view' => 'todo' ) ), 'todo' === $view && '' === $only ),
			array( __( 'One-click fixes', 'equalfix' ), (int) $oneclick_n, $this->tab_url( 'issues', array( 'view' => 'todo', 'only' => 'oneclick' ) ), 'oneclick' === $only ),
			array( __( 'Needs your review', 'equalfix' ), (int) $model['d_review'], $this->tab_url( 'issues', array( 'view' => 'todo' ) ), false ),
			array( __( 'Fixed', 'equalfix' ), (int) $model['d_fixed'], $this->tab_url( 'issues', array( 'view' => 'fixed' ) ), 'fixed' === $view ),
		);
		$more   = array(
			'waiting' => array( __( 'Waiting for your OK', 'equalfix' ), (int) $model['d_waiting'], 'waiting' ),
		);
		if ( ! empty( $pro['present'] ) && class_exists( 'EqualFix_Pro_Inbox' ) ) {
			$open_flags = 0;
			foreach ( EqualFix_Pro_Inbox::get_flags() as $flag ) {
				if ( isset( $flag['status'] ) && EqualFix_Pro_Inbox::STATUS_OPEN === $flag['status'] ) {
					$open_flags++;
				}
			}
			$more['flagged'] = array( __( 'Flagged by you', 'equalfix' ), $open_flags, 'flagged' );
		}
		$more['aside'] = array( __( 'Set aside', 'equalfix' ), (int) $model['d_dismissed'], 'aside' );
		$more_on       = in_array( $view, array( 'waiting', 'flagged', 'aside' ), true );
		?>
		<nav class="eqf-view-chips eqf-chiprow" aria-label="<?php esc_attr_e( 'Issue views', 'equalfix' ); ?>">
			<?php foreach ( $chips as $chip ) : ?>
				<a class="eqf-chip-link<?php echo $chip[3] ? ' eqf-chip-on' : ''; ?>"<?php echo $chip[3] ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $chip[2] ); ?>"><?php echo esc_html( $chip[0] ); ?> <span class="eqf-chip-n"><?php echo (int) $chip[1]; ?></span></a>
			<?php endforeach; ?>
			<details class="eqf-moreviews<?php echo $more_on ? ' eqf-more-on' : ''; ?>">
				<summary class="eqf-chip-link eqf-more-summary"><?php esc_html_e( 'More views', 'equalfix' ); ?> <span class="eqf-more-chev" aria-hidden="true">▾</span></summary>
				<div class="eqf-more-panel">
					<?php foreach ( $more as $slug => $chip ) : ?>
						<a class="eqf-chip-link eqf-more-link<?php echo $slug === $view ? ' eqf-chip-on' : ''; ?>"<?php echo $slug === $view ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $chip[2] ) ) ); ?>"><?php echo esc_html( $chip[0] ); ?> <span class="eqf-chip-n"><?php echo (int) $chip[1]; ?></span></a>
					<?php endforeach; ?>
				</div>
			</details>
		</nav>
		<?php
	}

	/**
	 * The fix previews as ONE slim collapsed bar (redesign
	 * v2, 2026-11-07): the shipped "Fix previews (before /
	 * after)" section, unchanged inside, moved out of the
	 * main flow into a disclosure directly under the summary
	 * strip. Renders only where the section rendered (All
	 * view, fixes not all on — the caller gates it) and only
	 * when the section itself has content.
	 */
	private function render_issues_previews_bar() {
		ob_start();
		$this->render_fix_previews();
		$previews = ob_get_clean();
		if ( '' === trim( (string) $previews ) ) {
			return;
		}
		?>
		<details class="eqf-pvbar">
			<summary class="eqf-pvbar-summary">
				<span class="eqf-pvbar-ic" aria-hidden="true">◐</span>
				<span class="eqf-pvbar-txt"><?php esc_html_e( 'See exactly what each fix changes', 'equalfix' ); ?> — <strong><?php esc_html_e( 'before / after previews', 'equalfix' ); ?></strong></span>
				<span class="eqf-pvbar-chev" aria-hidden="true">›</span>
			</summary>
			<div class="eqf-pvbar-body"><?php echo $previews; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_fix_previews() escapes every string it prints. ?></div>
		</details>
		<?php
	}

	/**
	 * The Issues heading + plain-language subtitle (redesign,
	 * 2026-11-07): the mockup's title block. The h3 keeps its
	 * long-standing id — post-action redirects and older links
	 * land on it.
	 *
	 * @param array $result Stored audit result.
	 */
	private function render_issues_heading( $result ) {
		?>
		<h3 id="equalfix-findings" class="eqf-issues-title"><?php esc_html_e( 'Issues', 'equalfix' ); ?></h3>
		<p class="eqf-issues-sub"><?php esc_html_e( 'Everything your last scan found, in plain language.', 'equalfix' ); ?>
		<?php if ( ! empty( $result['time'] ) && ! empty( $result['pages_scanned'] ) ) : ?>
			<?php $scan_ts = strtotime( (string) $result['time'] ); ?>
			<?php if ( $scan_ts ) : ?>
				<span class="eqf-issues-meta"><?php echo esc_html( sprintf( /* translators: 1: time since the scan, e.g. "41 minutes", 2: pages scanned */ __( 'Last scan %1$s ago · %2$d pages scanned', 'equalfix' ), human_time_diff( $scan_ts, current_time( 'timestamp' ) ), (int) $result['pages_scanned'] ) ); ?></span>
			<?php endif; ?>
		<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * The fix types one press of Apply Standard Fixes would
	 * switch on, read exactly the way the handler itself
	 * decides (apply_standard_fixes()): the configured types,
	 * or the full standard set when nothing was ever
	 * configured and nothing is on. Render-layer mirror of
	 * the handler's branch — the handler stays the only
	 * writer.
	 *
	 * @param array $settings Settings.
	 * @return array Fix type values.
	 */
	private function oneclick_types( $settings ) {
		$on = array();
		foreach ( array_keys( self::fix_types() ) as $type ) {
			if ( ! empty( $settings[ 'fix_' . $type ] ) ) {
				$on[] = $type;
			}
		}
		if ( empty( $on ) && empty( $settings['types_configured'] ) ) {
			return array_keys( self::fix_types() );
		}
		return $on;
	}

	/**
	 * Whether one press of Apply Standard Fixes would fix
	 * this finding: a free fix whose type the press switches
	 * on, or an owner-written rule waiting on the master
	 * switch the press turns on. Fixed and dismissed findings
	 * never qualify (the model has them out of the working
	 * partition already).
	 *
	 * @param array $finding    Finding.
	 * @param array $model      Coverage model.
	 * @param array $settings   Settings.
	 * @param array $manual_map manual_matches_map() result.
	 * @return bool
	 */
	private function is_oneclick_finding( $finding, $model, $settings, $manual_map, $attr_map = array() ) {
		$fp = self::finding_fingerprint( $finding );
		if ( isset( $model['fixed_fps'][ $fp ] ) || isset( $model['waiting_fps'][ $fp ] ) ) {
			return false;
		}
		if ( isset( $manual_map[ $fp ] ) ) {
			return true;
		}
		// An approved attribute rule (Fix-First) rides the
		// same master switch the press turns on.
		if ( isset( $attr_map[ $fp ] ) ) {
			return true;
		}
		// Invalid language (Fix-First Wave 2): review-category,
		// but the press fixes it whenever the lang type is one
		// the press switches on — the same promise the coverage
		// model keeps.
		if ( EqualFix_Audit::CAT_REVIEW === $finding['category'] && 'Language attribute looks invalid' === $finding['title'] ) {
			return in_array( EqualFix_Fixer::FIX_LANG, $this->oneclick_types( $settings ), true );
		}
		if ( EqualFix_Audit::CAT_FIX_A !== $finding['category'] ) {
			return false;
		}
		$type = $this->finding_fix_type( $finding );
		return null !== $type && in_array( $type, $this->oneclick_types( $settings ), true );
	}

	/**
	 * The DISTINCT problems one press of Apply Standard Fixes
	 * would clear — the summary strip's hero number and the
	 * "One-click fixes" chip count. Same predicate as the
	 * filtered list (is_oneclick_finding()), counted in the
	 * model's problem unit, so tile, chip and list agree.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $model    Coverage model.
	 * @param array $settings Settings.
	 * @return int
	 */
	private function issues_oneclick_count( $result, $model, $settings ) {
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return 0;
		}
		$manual_map = self::manual_matches_map( $result );
		$attr_map   = self::attr_matches_map( $result );
		$dismissed  = self::dismissed_map();
		$ids        = array();
		foreach ( $result['findings'] as $finding ) {
			if ( self::map_has_finding( $dismissed, $finding ) ) {
				continue;
			}
			if ( $this->is_oneclick_finding( $finding, $model, $settings, $manual_map, $attr_map ) ) {
				list( , $identity ) = $this->problem_identity( $finding );
				$ids[ $identity ] = true;
			}
		}
		return count( $ids );
	}

	/**
	 * The summary strip (redesign, 2026-11-07): the mockup's
	 * three tiles over the ONE coverage model — problems
	 * still to do (d_todo), the one-click count with the real
	 * Apply Standard Fixes form, and the review count
	 * (d_review) — over a progress bar, with the fixed note
	 * and the problems-vs-findings footnote in plain words.
	 * No number here is computed anywhere else.
	 *
	 * @param array $result     Stored audit result.
	 * @param array $model      Coverage model.
	 * @param array $settings   Settings.
	 * @param int   $oneclick_n issues_oneclick_count().
	 */
	private function render_issues_summary( $result, $model, $settings, $oneclick_n ) {
		$pct = $model['d_total'] > 0 ? (int) round( 100 * $model['d_fixed'] / $model['d_total'] ) : 0;
		?>
		<div class="eqf-summary">
			<div class="eqf-progress-row">
				<div class="eqf-progress" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: fixed problems, 2: total problems */ __( '%1$d of %2$d problems fixed', 'equalfix' ), (int) $model['d_fixed'], (int) $model['d_total'] ) ); ?>"><div class="eqf-progress-fill" style="width:<?php echo (int) $pct; ?>%"></div></div>
				<p class="eqf-progress-label"><?php echo esc_html( sprintf( /* translators: 1: fixed problems, 2: total problems */ __( '%1$d of %2$d problems fixed', 'equalfix' ), (int) $model['d_fixed'], (int) $model['d_total'] ) ); ?> <span><?php esc_html_e( '— you’re on your way', 'equalfix' ); ?></span></p>
			</div>
			<div class="eqf-tiles">
				<div class="eqf-tile">
					<div class="eqf-tile-big"><?php echo (int) $model['d_todo']; ?></div>
					<div class="eqf-tile-lbl"><?php echo esc_html( _n( 'problem still to do', 'problems still to do', (int) $model['d_todo'], 'equalfix' ) ); ?></div>
					<div class="eqf-tile-sub"><?php esc_html_e( 'Grouped below by what they are — start anywhere.', 'equalfix' ); ?></div>
				</div>
				<div class="eqf-tile eqf-tile-hero">
					<?php if ( $oneclick_n > 0 ) : ?>
						<div class="eqf-tile-big"><?php echo esc_html( sprintf( /* translators: %d: problems one press fixes */ _n( '%d can be fixed in one click', '%d can be fixed in one click', $oneclick_n, 'equalfix' ), $oneclick_n ) ); ?></div>
						<div class="eqf-tile-lbl"><?php esc_html_e( 'EqualFix already knows how to fix these safely.', 'equalfix' ); ?></div>
						<div class="eqf-tile-act">
							<form method="post" action="">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="apply_standard_fixes">
								<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: problems one press fixes */ _n( 'Fix all %d now', 'Fix all %d now', $oneclick_n, 'equalfix' ), $oneclick_n ) ); ?></button>
							</form>
						</div>
					<?php else : ?>
						<div class="eqf-tile-big"><?php esc_html_e( '0 can be fixed in one click', 'equalfix' ); ?></div>
						<div class="eqf-tile-lbl"><?php esc_html_e( 'Nothing here is waiting on the free fix set right now — the rest needs a look, or your own words.', 'equalfix' ); ?></div>
					<?php endif; ?>
				</div>
				<div class="eqf-tile">
					<div class="eqf-tile-big"><?php echo (int) $model['d_review']; ?></div>
					<div class="eqf-tile-lbl"><?php echo esc_html( _n( 'needs a quick look from you — no automatic fix is safe here', 'need a quick look from you — no automatic fix is safe here', (int) $model['d_review'], 'equalfix' ) ); ?></div>
					<div class="eqf-tile-act"><a class="button eqf-btn-ghost" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => 'todo' ) ) ); ?>"><?php esc_html_e( 'Start reviewing', 'equalfix' ); ?></a></div>
				</div>
			</div>
			<p class="eqf-fixed-note"><?php echo esc_html( sprintf( /* translators: %d: fixed problems */ _n( '✓ %d already fixed', '✓ %d already fixed', (int) $model['d_fixed'], 'equalfix' ), (int) $model['d_fixed'] ) ); ?> <span><?php esc_html_e( '— nice progress since your first scan.', 'equalfix' ); ?></span><?php if ( $model['d_waiting'] > 0 ) : ?> <span><?php echo esc_html( sprintf( /* translators: %d: problems with a draft waiting */ _n( '%d is waiting for your OK in Review.', '%d are waiting for your OK in Review.', (int) $model['d_waiting'], 'equalfix' ), (int) $model['d_waiting'] ) ); ?></span><?php endif; ?></p>
			<p class="eqf-count-note"><?php echo esc_html( sprintf( /* translators: %d: individual findings in the stored audit */ __( 'Counts show problems — one per element that needs attention. Your scan found %d individual findings in total; problems are what you act on.', 'equalfix' ), count( (array) $result['findings'] ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * The fixed strip (redesign, 2026-11-07): the mockup's
	 * closing green strip under the cards — what is fixed,
	 * and where the record (and undo) lives.
	 *
	 * @param array $model Coverage model.
	 */
	private function render_issues_fixed_strip( $model ) {
		?>
		<div class="eqf-fixed-strip">
			<span class="eqf-fixed-check" aria-hidden="true">✓</span>
			<span><?php echo esc_html( sprintf( /* translators: %d: fixed problems */ _n( '%d fixed', '%d fixed', (int) $model['d_fixed'], 'equalfix' ), (int) $model['d_fixed'] ) ); ?> <?php esc_html_e( '— see them in History. Undo lives there too.', 'equalfix' ); ?></span>
			<a href="<?php echo esc_url( $this->tab_url( 'history' ) ); ?>"><?php esc_html_e( 'View in History →', 'equalfix' ); ?></a>
		</div>
		<?php
	}

	/**
	 * A job group's DISPLAY title (redesign, 2026-11-07):
	 * the mockup's plain statement for the group, mapped
	 * from the group slug in the render layer only. Stored
	 * finding titles and finding_job_meta()'s names are
	 * untouched — receipts, the Set aside record and every
	 * other surface keep the shipped names.
	 *
	 * @param string $group    Group slug (finding_job_meta()).
	 * @param string $fallback The meta name, used unmapped.
	 * @return string
	 */
	private function issue_display_title( $group, $fallback ) {
		$map = array(
			'alt'                => __( 'Images are missing descriptions', 'equalfix' ),
			'alt-auto'           => __( 'Images that can be described automatically', 'equalfix' ),
			'alt-filename'       => __( 'Image descriptions that are just a file name', 'equalfix' ),
			'name-auto'          => __( 'Buttons and links that can be named automatically', 'equalfix' ),
			'name-button'        => __( 'Buttons have no name for screen readers', 'equalfix' ),
			'name-link'          => __( 'Links have no name for screen readers', 'equalfix' ),
			'name-symbol'        => __( 'Controls named only by a symbol', 'equalfix' ),
			'label'              => __( 'Form fields are missing labels', 'equalfix' ),
			'label-auto'         => __( 'Form fields not connected to their labels', 'equalfix' ),
			'lang'               => __( 'Pages missing a language', 'equalfix' ),
			'lang-invalid'       => __( 'Pages with an invalid language code', 'equalfix' ),
			'skip'               => __( 'Pages with no skip link', 'equalfix' ),
			'headings'           => __( 'Heading order skips a level', 'equalfix' ),
			'contrast'           => __( 'Text is hard to read (low contrast)', 'equalfix' ),
			'dup-id'             => __( 'Pages with duplicate IDs', 'equalfix' ),
			'embed-title'        => __( 'Embeds with no title', 'equalfix' ),
			'embed-3p'           => __( 'Third-party embeds with no title', 'equalfix' ),
			'page-title'         => __( 'Pages with no title', 'equalfix' ),
			'link-generic'       => __( 'Links whose text says nothing', 'equalfix' ),
			'aria-ref'           => __( 'Broken ARIA references', 'equalfix' ),
			'aria-hidden-focus'  => __( 'Focusable controls hidden from screen readers', 'equalfix' ),
			'interactive'        => __( 'Clickable elements that are not buttons or links', 'equalfix' ),
			'tabindex'           => __( 'Elements pulled out of the tab order', 'equalfix' ),
			'autocomplete'       => __( 'Fields missing an autocomplete hint', 'equalfix' ),
			'viewport'           => __( 'Pages where zoom is disabled', 'equalfix' ),
			'heading-empty'      => __( 'Empty headings', 'equalfix' ),
			'table-headers'      => __( 'Data tables with no header cells', 'equalfix' ),
			'table-header-empty' => __( 'Empty table header cells', 'equalfix' ),
			'media-video'        => __( 'Videos with no captions found', 'equalfix' ),
			'media-audio'        => __( 'Audio with no transcript found', 'equalfix' ),
			'media-embed'        => __( 'Embedded videos to check for captions', 'equalfix' ),
		);
		return isset( $map[ $group ] ) ? $map[ $group ] : $fallback;
	}

	/**
	 * A job group's severity dot (redesign, 2026-11-07):
	 * render-layer display only, following the mockup —
	 * the name/description groups a screen reader cannot
	 * get past are Serious, the structural and writable
	 * groups Moderate, and the review groups (which never
	 * promise a fix) carry the grey Review dot.
	 *
	 * @param string $group Group slug.
	 * @param bool   $review Whether the group is a REVIEW_GROUPS member.
	 * @return array{0: string, 1: string} CSS suffix, word.
	 */
	private function issue_severity( $group, $review ) {
		if ( $review ) {
			return array( 'review', __( 'Review', 'equalfix' ) );
		}
		if ( in_array( $group, array( 'alt', 'name-button', 'name-link' ), true ) ) {
			return array( 'serious', __( 'Serious', 'equalfix' ) );
		}
		return array( 'moderate', __( 'Moderate', 'equalfix' ) );
	}

	/**
	 * A preview fragment shortened for the card strip
	 * (redesign): whitespace collapsed, cut at 80 characters.
	 * Display only — the Fixed tab keeps the full fragments.
	 *
	 * @param string $text Fragment.
	 * @return string
	 */
	private static function preview_short( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 80 ) . '…';
		}
		return $text;
	}

	/**
	 * The model's WAITING bucket as a findings list (IA batch):
	 * findings with a pending Pro draft, in audit order — the
	 * same fingerprint set the coverage model counted.
	 *
	 * @param array $result Stored audit result.
	 * @param array $model  A coverage_model() result.
	 * @return array
	 */
	private function waiting_findings( $result, $model ) {
		$out = array();
		foreach ( (array) $result['findings'] as $finding ) {
			if ( isset( $model['waiting_fps'][ self::finding_fingerprint( $finding ) ] ) ) {
				$out[] = $finding;
			}
		}
		return $out;
	}

	/**
	 * The By-page browser (IA batch): replaces the old "Pages
	 * in this audit" card. One row per scanned page — title,
	 * path, finding/fixed counts from the coverage model, and
	 * a fixed-share bar. Clicking a page filters the current
	 * view's job list to that page (page_url param).
	 *
	 * @param array  $result Stored audit result.
	 * @param array  $model  A coverage_model() result.
	 * @param string $view   Active Issues view (kept on filter).
	 */
	private function render_pages_browser( $result, $model, $view ) {
		$pages = array();
		if ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) {
			foreach ( $result['pages'] as $page ) {
				if ( ! empty( $page['url'] ) ) {
					$pages[] = $page;
				}
			}
		}
		$per_page = array();
		foreach ( (array) $result['findings'] as $finding ) {
			$u = $finding['url'];
			if ( ! isset( $per_page[ $u ] ) ) {
				$per_page[ $u ] = array( 0, 0 );
			}
			$per_page[ $u ][0]++;
			if ( isset( $model['fixed_fps'][ self::finding_fingerprint( $finding ) ] ) ) {
				$per_page[ $u ][1]++;
			}
		}
		if ( empty( $pages ) ) {
			foreach ( $per_page as $u => $pair ) {
				$pages[] = array(
					'url'    => $u,
					'status' => 'ok',
				);
			}
		}
		if ( empty( $pages ) ) {
			return;
		}
		?>
		<div class="equalfix-card eqf-pages-browser" id="equalfix-pages">
			<h3><?php echo esc_html( sprintf( __( 'Pages in this audit (%d)', 'equalfix' ), count( $pages ) ) ); ?></h3>
			<p class="description"><?php esc_html_e( 'Pick a page to see its issues in this view.', 'equalfix' ); ?></p>
			<?php // Fix-First table UI (2026-11-07): the By page view renders through the same table panel — one band per page. ?>
			<div class="eqf-tpanel"><div class="eqf-tpanel-scroll">
			<table class="eqf-itable eqf-ptable">
				<colgroup><col style="width:46%"><col style="width:16%"><col style="width:13%"><col style="width:25%"></colgroup>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Page', 'equalfix' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Address', 'equalfix' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Findings', 'equalfix' ); ?></th>
					<th scope="col" class="eqf-th-action"><?php esc_html_e( 'Fixed', 'equalfix' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $pages as $page ) : ?>
					<?php $pair = isset( $per_page[ $page['url'] ] ) ? $per_page[ $page['url'] ] : array( 0, 0 ); ?>
					<?php $share = $pair[0] > 0 ? (int) round( 100 * $pair[1] / $pair[0] ) : 0; ?>
					<tr class="eqf-band eqf-pband"><td colspan="4"><div class="eqf-band-in">
						<a class="eqf-pages-title eqf-band-name" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => $view, 'page_url' => $page['url'] ) ) ); ?>"><?php echo esc_html( self::page_title_label( $page['url'] ) ); ?></a>
						<span class="eqf-pages-url"><?php echo esc_html( self::page_label( $page['url'] ) ); ?></span>
						<span class="eqf-pages-counts eqf-band-count">
							<?php if ( isset( $page['status'] ) && 'fetch_failed' === $page['status'] ) : ?>
								<?php esc_html_e( 'Could not be scanned', 'equalfix' ); ?>
							<?php else : ?>
								<?php echo esc_html( sprintf( _n( '%1$d finding · %2$d fixed', '%1$d findings · %2$d fixed', $pair[0], 'equalfix' ), $pair[0], $pair[1] ) ); ?>
							<?php endif; ?>
						</span>
						<span class="eqf-pages-bar" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d%% of this page’s findings are fixed', 'equalfix' ), $share ) ); ?>"><span class="eqf-pages-bar-fill" style="width:<?php echo (int) $share; ?>%"></span></span>
					</div></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div></div>
		</div>
		<?php
	}

	/**
	 * The collapsible-group + Inspect Fix wiring script (1.1),
	 * shared by the Findings and Fixed tabs. Progressive
	 * enhancement only: with JS off, everything stays visible.
	 */
	private function render_toggle_script() {
		?>
		<script>
		( function () {
			function wire( selector ) {
				var btns = document.querySelectorAll( selector );
				for ( var i = 0; i < btns.length; i++ ) {
					btns[ i ].addEventListener( 'click', function () {
						var panel = document.getElementById( this.getAttribute( 'aria-controls' ) );
						if ( ! panel ) { return; }
						var open = this.getAttribute( 'aria-expanded' ) === 'true';
						this.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
						if ( open ) { panel.setAttribute( 'hidden', '' ); } else { panel.removeAttribute( 'hidden' ); }
					} );
				}
			}
			wire( '.eqf-toggle' );
			wire( '.eqf-inspect-btn' );
		} )();
		</script>
		<?php
	}

	/**
	 * One finding's technical detail (1.5): the WCAG reference
	 * and the HTML snippet, behind a collapsed "Details" toggle
	 * so the plain-language issue leads the card and the codes
	 * are one click away for anyone who wants them.
	 *
	 * @param array $finding Finding.
	 */
	private function render_finding_details( $finding ) {
		?>
		<details class="eqf-details">
			<summary><?php esc_html_e( 'Details', 'equalfix' ); ?></summary>
			<?php if ( '' !== $finding['wcag'] ) : ?>
				<p class="eqf-details-wcag"><?php echo esc_html( sprintf( __( 'WCAG %s', 'equalfix' ), $finding['wcag'] ) ); ?></p>
			<?php endif; ?>
			<pre class="eqf-snippet"><code><?php echo esc_html( $finding['snippet'] ); ?></code></pre>
			<p class="eqf-details-links"><a href="<?php echo esc_url( $finding['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Find it on the page ↗', 'equalfix' ); ?></a></p>
		</details>
		<?php
	}

	/**
	 * The job-card facts for one finding (Findings redesign,
	 * Direction A): the plain-words group name, the per-instance
	 * problem line, the one-sentence why, and which action family
	 * the row belongs to. Built from the audit's category + title
	 * — the same signals every other surface uses — so any rule
	 * the audit can produce lands in a named family. WCAG codes
	 * and severity never appear here: they live in Details.
	 *
	 * @param array $finding Finding.
	 * @return array { group, name, problem, why, kind, fix_type,
	 *                 draft_kind, manual_kind, steps }
	 */
	private function finding_job_meta( $finding ) {
		$meta = array(
			'group'       => 'other',
			'name'        => __( 'Findings that need a person', 'equalfix' ),
			'problem'     => __( 'This needs a person to look at it.', 'equalfix' ),
			'why'         => isset( $finding['explanation'] ) ? (string) $finding['explanation'] : '',
			'kind'        => 'guidance',
			'fix_type'    => null,
			'draft_kind'  => null,
			'manual_kind' => null,
			'steps'       => array(),
		);
		$title = isset( $finding['title'] ) ? (string) $finding['title'] : '';
		$cat   = isset( $finding['category'] ) ? (string) $finding['category'] : '';
		if ( EqualFix_Audit::CAT_FIX_A === $cat ) {
			$meta['kind']     = 'fix-type';
			$meta['fix_type'] = $this->finding_fix_type( $finding );
			switch ( $meta['fix_type'] ) {
				case EqualFix_Fixer::FIX_LANG:
					$meta['group']   = 'lang';
					$meta['name']    = __( 'Pages missing a language', 'equalfix' );
					$meta['problem'] = __( 'This page does not say what language it is in.', 'equalfix' );
					$meta['why']     = __( 'Screen readers may pronounce the whole page with the wrong voice and rules.', 'equalfix' );
					break;
				case EqualFix_Fixer::FIX_ALT:
					$meta['group']   = 'alt-auto';
					$meta['name']    = __( 'Images with an automatic description', 'equalfix' );
					$meta['problem'] = __( 'This image has no description, but EqualFix can derive one safely.', 'equalfix' );
					$meta['why']     = __( 'People using screen readers otherwise hear the file name — or nothing at all.', 'equalfix' );
					break;
				case EqualFix_Fixer::FIX_ARIA_LABEL:
					$meta['group']   = 'name-auto';
					$meta['name']    = __( 'Buttons and links with a derivable name', 'equalfix' );
					$meta['problem'] = __( 'This button or link has no name, but one can be derived from its title.', 'equalfix' );
					$meta['why']     = __( 'Screen readers announce only “button” or “link” — the visitor cannot tell what it does.', 'equalfix' );
					break;
				case EqualFix_Fixer::FIX_LABEL_ASSOC:
					$meta['group']   = 'label-auto';
					$meta['name']    = __( 'Form fields with a nearby label', 'equalfix' );
					$meta['problem'] = __( 'This field is not connected to its label.', 'equalfix' );
					$meta['why']     = __( 'Screen readers cannot tell what the field is for, and clicking the label does not focus the field.', 'equalfix' );
					break;
				case EqualFix_Fixer::FIX_SKIP_LINK:
					$meta['group']   = 'skip';
					$meta['name']    = __( 'Pages with no skip link', 'equalfix' );
					$meta['problem'] = __( 'This page has no working skip-to-content link.', 'equalfix' );
					$meta['why']     = __( 'Keyboard users must tab through the whole menu on every page before reaching the content.', 'equalfix' );
					break;
				default:
					$meta['kind'] = 'guidance';
					break;
			}
			return $meta;
		}
		if ( EqualFix_Audit::CAT_FIX_B === $cat ) {
			$meta['group']   = 'headings';
			$meta['name']    = __( 'Skipped heading levels', 'equalfix' );
			$meta['problem'] = __( 'The headings on this page skip a level.', 'equalfix' );
			$meta['why']     = __( 'Screen-reader users navigate by headings; a skipped level makes the page structure confusing.', 'equalfix' );
			$meta['kind']    = 'fix-b';
			$meta['steps']   = array(
				__( 'Open the page in your editor.', 'equalfix' ),
				__( 'Find the heading that jumps a level — for example, an H2 followed directly by an H4.', 'equalfix' ),
				__( 'Change the later heading to the next level down, or add the missing level above it.', 'equalfix' ),
			);
			return $meta;
		}
		if ( EqualFix_Audit::CAT_EMBED === $cat ) {
			// Fix-First Wave 2 (2026-11-07): the frame's title
			// attribute lives in the owner's page, so this is a
			// writable fix like an own embed's — manual-first
			// with a provider-derived prefill. Only the frame's
			// CONTENTS are the provider's, and no finding here
			// is about those.
			$meta['group']       = 'embed-3p';
			$meta['name']        = __( 'Third-party embeds', 'equalfix' );
			$meta['problem']     = __( 'This third-party embed has no title.', 'equalfix' );
			$meta['why']         = __( 'Screen readers announce an unnamed frame; visitors cannot tell what it contains before entering it. The title sits in your page — EqualFix can add it.', 'equalfix' );
			$meta['kind']        = 'manual-first';
			$meta['manual_kind'] = 'title';
			return $meta;
		}
		// Review findings: the writable kinds.
		if ( false !== stripos( $title, 'missing alt text' ) ) {
			$meta['group']       = 'alt';
			$meta['name']        = __( 'Images with no description', 'equalfix' );
			$meta['problem']     = __( 'This image has no description (alt text).', 'equalfix' );
			$meta['why']         = __( 'People using screen readers hear the file name instead of what the picture shows.', 'equalfix' );
			$meta['kind']        = 'draft';
			$meta['draft_kind']  = 'alt';
			$meta['manual_kind'] = 'alt';
			return $meta;
		}
		// Both filename-alt titles (T1-05): the current one and
		// the pre-rename one a stored audit may still carry.
		if ( 'Alt text contains a file name or redundant phrase' === $title || 'Alt text is just a file name' === $title ) {
			$meta['group']       = 'alt-filename';
			$meta['name']        = __( 'Descriptions that are just a file name', 'equalfix' );
			$meta['problem']     = __( 'This image’s description is just its file name.', 'equalfix' );
			$meta['why']         = __( 'A file name tells a screen-reader user nothing about what the picture shows.', 'equalfix' );
			$meta['kind']        = 'manual-first';
			$meta['manual_kind'] = 'alt';
			return $meta;
		}
		if ( false !== stripos( $title, 'has no associated label' ) ) {
			$meta['group']       = 'label';
			$meta['name']        = __( 'Form fields with no label', 'equalfix' );
			$meta['problem']     = __( 'This field has no label.', 'equalfix' );
			$meta['why']         = __( 'Screen readers cannot tell what this field is for, and voice-control users cannot target it by name.', 'equalfix' );
			$meta['kind']        = 'draft';
			$meta['draft_kind']  = 'label';
			$meta['manual_kind'] = 'label';
			return $meta;
		}
		if ( false !== stripos( $title, 'has no accessible name' ) ) {
			$is_link              = 0 === stripos( $title, 'Link' );
			$is_button            = 0 === stripos( $title, 'Button' );
			$meta['group']        = $is_link ? 'name-link' : 'name-button';
			$meta['name']         = $is_link ? __( 'Links with no name', 'equalfix' ) : __( 'Buttons with no name', 'equalfix' );
			$meta['problem']      = $is_link ? __( 'This link has no name.', 'equalfix' ) : ( $is_button ? __( 'This button has no name.', 'equalfix' ) : __( 'This control has no name.', 'equalfix' ) );
			$meta['why']          = $is_link ? __( 'Screen readers announce only “link” — visitors cannot tell where it goes.', 'equalfix' ) : __( 'Screen readers announce only “button” — visitors cannot tell what it does.', 'equalfix' );
			$meta['kind']         = 'draft';
			$meta['draft_kind']   = 'name';
			$meta['manual_kind']  = 'name';
			return $meta;
		}
		if ( 0 === stripos( $title, 'Duplicate id attribute' ) ) {
			$meta['group']   = 'dup-id';
			$meta['name']    = __( 'Pages with duplicate IDs', 'equalfix' );
			$meta['problem'] = __( 'Two elements on this page use the same ID.', 'equalfix' );
			$meta['why']     = __( 'Labels, menus, and scripts attach to the first match — the second element can break silently.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Open the page in your editor (or your theme/builder settings).', 'equalfix' ),
				__( 'Find both elements using this ID — the Details below show the code.', 'equalfix' ),
				__( 'Give one of them a different, unique ID, and update anything that points at it.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Embedded frame missing a title' === $title ) {
			$meta['group']       = 'embed-title';
			$meta['name']        = __( 'Embeds with no title', 'equalfix' );
			$meta['problem']     = __( 'This embedded frame has no title.', 'equalfix' );
			$meta['why']         = __( 'Screen readers announce an unnamed frame; visitors cannot tell what it contains before entering it.', 'equalfix' );
			$meta['kind']        = 'manual-first';
			$meta['manual_kind'] = 'title';
			return $meta;
		}
		// Coverage-batch families (T1-06): every title the audit
		// can emit resolves to a named job with steps or a
		// write-box — never the generic default. The wp44 title
		// census fails the build if a future title lands here
		// without its branch (the pair-contract gate, T1-16).
		if ( false !== strpos( $title, 'name is only a symbol' ) ) {
			$meta['group']       = 'name-symbol';
			$meta['name']        = __( 'Controls named only by a symbol', 'equalfix' );
			$meta['problem']     = __( 'This control’s name is only a symbol or emoji.', 'equalfix' );
			$meta['why']         = __( 'Screen readers announce a symbol name inconsistently, or skip it entirely — visitors cannot tell what the control does.', 'equalfix' );
			$meta['kind']        = 'manual-first';
			$meta['manual_kind'] = 'name';
			return $meta;
		}
		if ( 'Page title is missing or empty' === $title ) {
			$meta['group']   = 'page-title';
			$meta['name']    = __( 'Pages with no title', 'equalfix' );
			$meta['problem'] = __( 'This page has no title.', 'equalfix' );
			$meta['why']     = __( 'The title is the first thing a screen reader announces on arrival, and the label browsers show in tabs, history and search results.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Open the page in your editor, or your theme / SEO plugin’s settings for it.', 'equalfix' ),
				__( 'Give the page a short title that describes what it is.', 'equalfix' ),
				__( 'Run the audit again — the finding clears when the title is there.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Language attribute looks invalid' === $title ) {
			// Fix-First Wave 2 (2026-11-07): the lang fix type
			// now replaces an invalid value with the site
			// language (the value WordPress itself declares),
			// so this group rides the fix-type machinery like
			// its missing-language sibling.
			$meta['group']    = 'lang-invalid';
			$meta['name']     = __( 'Pages with an invalid language code', 'equalfix' );
			$meta['problem']  = __( 'This page’s language code is not a valid language tag.', 'equalfix' );
			$meta['why']      = __( 'Screen readers may ignore an invalid code and fall back to the wrong pronunciation rules.', 'equalfix' );
			$meta['kind']     = 'fix-type';
			$meta['fix_type'] = EqualFix_Fixer::FIX_LANG;
			return $meta;
		}
		if ( 'Link text is generic' === $title ) {
			$meta['group']   = 'link-generic';
			$meta['name']    = __( 'Links whose text says nothing', 'equalfix' );
			$meta['problem'] = __( 'This link’s text does not say where it goes.', 'equalfix' );
			$meta['why']     = __( 'Screen-reader users who list the page’s links hear this one with no purpose or context.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Read the link in its sentence: if the destination is already obvious from the words around it, this is fine as-is — set it aside.', 'equalfix' ),
				__( 'If not, edit the link text so it names the destination (for example, "See Studio pricing" instead of "Click here").', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'ARIA reference refers to an element that does not exist' === $title ) {
			$meta['group']   = 'aria-ref';
			$meta['name']    = __( 'Broken ARIA references', 'equalfix' );
			$meta['problem'] = __( 'This element points at another element that does not exist on the page.', 'equalfix' );
			$meta['why']     = __( 'Assistive technology gets nothing from the reference — the description or label it was meant to provide is missing.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the element in your page or template — the Details below show its code.', 'equalfix' ),
				__( 'Point the attribute (aria-describedby, aria-labelledby, …) at an element that exists, or remove the attribute.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Interactive element is hidden from assistive technology but can still be focused' === $title ) {
			$meta['group']   = 'aria-hidden-focus';
			$meta['name']    = __( 'Focusable controls hidden from screen readers', 'equalfix' );
			$meta['problem'] = __( 'Keyboard users can tab to this control, but screen readers never announce it.', 'equalfix' );
			$meta['why']     = __( 'A keyboard user who relies on a screen reader lands on a control they cannot identify or use with confidence.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the container carrying aria-hidden="true" around this control (often a decorative wrapper added by a slider or popup).', 'equalfix' ),
				__( 'Remove the aria-hidden if the control is meant to be used — or take the control out of the tab order as well (tabindex="-1") if it is truly decorative.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Clickable element is not a real button or link' === $title ) {
			$meta['group']   = 'interactive';
			$meta['name']    = __( 'Clickable elements that are not buttons or links', 'equalfix' );
			$meta['problem'] = __( 'This element responds to clicks, but it is not a real button or link.', 'equalfix' );
			$meta['why']     = __( 'Keyboard users cannot reach it or operate it, and screen readers do not announce it as a control at all.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the element in your page or template — the Details below show its code.', 'equalfix' ),
				__( 'Replace it with a real <button> (for an action) or <a> (for a destination), keeping its text and styling classes.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Positive tabindex breaks the natural focus order' === $title ) {
			$meta['group']   = 'tabindex';
			$meta['name']    = __( 'Elements pulled out of the tab order', 'equalfix' );
			$meta['problem'] = __( 'This element has a positive tabindex, which pulls it ahead of everything else in the keyboard order.', 'equalfix' );
			$meta['why']     = __( 'Keyboard users reach it out of sequence — before content that appears earlier on the page.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the element’s tabindex attribute in your page or template.', 'equalfix' ),
				__( 'Remove it, or set it to 0 (joins the natural order) or -1 (focusable by scripts only).', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Form field is missing an autocomplete hint' === $title ) {
			$meta['group']   = 'autocomplete';
			$meta['name']    = __( 'Fields missing an autocomplete hint', 'equalfix' );
			$meta['problem'] = __( 'This field collects personal information, but browsers cannot fill it in automatically.', 'equalfix' );
			$meta['why']     = __( 'Autocomplete is how many users — including people who type slowly, with one hand, or with assistive technology — complete forms at all.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Open the form in your form builder or editor.', 'equalfix' ),
				__( 'Add the matching autocomplete value to the field — for example autocomplete="email" on an email field, "tel" on a phone field, "name" on a name field.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Zoom is disabled on this page' === $title ) {
			$meta['group']   = 'viewport';
			$meta['name']    = __( 'Pages where zoom is disabled', 'equalfix' );
			$meta['problem'] = __( 'This page’s viewport settings prevent or severely limit zooming.', 'equalfix' );
			$meta['why']     = __( 'Low-vision users cannot enlarge the content on phones and tablets — for many, zoom is how they read at all.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the viewport meta tag in your theme’s header (or your SEO/framework plugin’s settings).', 'equalfix' ),
				__( 'Remove user-scalable=no, and remove any maximum-scale below 2.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Heading is empty' === $title ) {
			$meta['group']   = 'heading-empty';
			$meta['name']    = __( 'Empty headings', 'equalfix' );
			$meta['problem'] = __( 'This heading contains no text.', 'equalfix' );
			$meta['why']     = __( 'Screen-reader users navigating by headings land on a blank entry in the page outline.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the empty heading in your page editor — the Details below show its code.', 'equalfix' ),
				__( 'Remove it, or give it the text it was meant to carry.', 'equalfix' ),
			);
			return $meta;
		}
		// Tier 2 families (T2-01/T2-03/T2-04): same rule as
		// T1-06 — every title the audit can emit resolves to a
		// named job with steps, never the generic default; the
		// wp44 census fails the build otherwise.
		if ( 'Data table has no header cells' === $title ) {
			$meta['group']   = 'table-headers';
			$meta['name']    = __( 'Data tables with no header cells', 'equalfix' );
			$meta['problem'] = __( 'This table shows data, but no cell is marked as a header.', 'equalfix' );
			$meta['why']     = __( 'Screen readers read every value on its own — without headers, a price or a size has nothing saying what it belongs to.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Open the page and find the table — the Details below show its code.', 'equalfix' ),
				__( 'Decide which row or column labels the data (usually the first row).', 'equalfix' ),
				__( 'Mark those cells as headers — most page builders and table blocks have a "header row" or "header cell" setting; in raw HTML they become <th> elements.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Table header cell is empty' === $title ) {
			$meta['group']   = 'table-header-empty';
			$meta['name']    = __( 'Empty table header cells', 'equalfix' );
			$meta['problem'] = __( 'This header cell contains no text.', 'equalfix' );
			$meta['why']     = __( 'The values in its row or column are announced with an empty label — visitors hear numbers with no meaning attached.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Find the empty header cell in your table — the Details below show its code.', 'equalfix' ),
				__( 'Write the label it was meant to carry (for example "Price" or "Size"), or remove the empty cell.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Video has no caption track or transcript' === $title ) {
			$meta['group']   = 'media-video';
			$meta['name']    = __( 'Videos with no captions found', 'equalfix' );
			$meta['problem'] = __( 'No caption track or transcript was found for this video.', 'equalfix' );
			$meta['why']     = __( 'Visitors who cannot hear the audio — or who read instead of listen — get none of the video’s content.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Play the video with the sound off and captions on: if captions already exist (burned in or in the player), this is fine as-is — set it aside.', 'equalfix' ),
				__( 'If there are none, add a captions file to the video, or publish a written transcript next to it.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Audio has no transcript' === $title ) {
			$meta['group']   = 'media-audio';
			$meta['name']    = __( 'Audio with no transcript found', 'equalfix' );
			$meta['problem'] = __( 'No transcript was found near this audio.', 'equalfix' );
			$meta['why']     = __( 'Visitors who cannot hear the audio get none of its content without a text version.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'If a transcript is published somewhere, link it next to the player with the word "transcript" in the link text.', 'equalfix' ),
				__( 'If none exists, write one from the recording and publish it on or next to this page.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Embedded video captions cannot be checked from the page code' === $title ) {
			$meta['group']   = 'media-embed';
			$meta['name']    = __( 'Embedded videos to check for captions', 'equalfix' );
			$meta['problem'] = __( 'This embedded video’s captions are set in the provider’s player, so the page code cannot show whether it has any.', 'equalfix' );
			$meta['why']     = __( 'An embedded video without captions shuts out every visitor who cannot hear it — and only a play-through can tell you whether it has them.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Play the embedded video and turn captions on: if they exist and are accurate, this is fine as-is — set it aside.', 'equalfix' ),
				__( 'If the video is yours and has no captions, add them in the provider’s studio (for example YouTube Studio) — that is where embedded captions are managed.', 'equalfix' ),
			);
			return $meta;
		}
		if ( 'Low text contrast (from styles written in this page)' === $title ) {
			$meta['group']   = 'contrast';
			$meta['name']    = __( 'Text with low contrast (page styles)', 'equalfix' );
			$meta['problem'] = __( 'This text’s colours, as written in the page, are too close together to read easily.', 'equalfix' );
			$meta['why']     = __( 'Low-vision visitors — and anyone in bright light — may not be able to read text whose contrast is too low.', 'equalfix' );
			$meta['kind']    = 'guidance';
			$meta['steps']   = array(
				__( 'Look at the text on the real page first: this check only sees styles written in the page itself, so confirm the colours it measured are the ones visitors see (the Details below name them).', 'equalfix' ),
				__( 'Darken the text or lighten the background where the text is styled — in your theme, builder, or the block’s own colour settings — until the pair is clearly distinct.', 'equalfix' ),
				__( 'Run the audit again — the finding clears when the page’s own styles measure at least 4.5:1 (3:1 for large text).', 'equalfix' ),
			);
			return $meta;
		}
		return $meta;
	}

	/**
	 * The full-checklist card (Tier 2, T2-02): every check the
	 * audit ran, passes included — the failures above are only
	 * half of what the scan did, and a results screen that
	 * shows just failures undersells (and misdescribes) the
	 * scan. Renders only for results stored by this batch or
	 * later: older results carry no 'checks' value and the
	 * card stays absent rather than guessing a checklist.
	 * Passes are presentation only — nothing here touches the
	 * coverage model or any finding count.
	 *
	 * @param array $result Stored audit result.
	 */
	private function render_checks_card( $result ) {
		if ( ! isset( $result['checks'] ) || ! is_array( $result['checks'] ) || empty( $result['checks'] ) ) {
			return;
		}
		$passed  = array();
		$failed  = array();
		$partial = array();
		foreach ( $result['checks'] as $check ) {
			if ( ! empty( $check['partial'] ) ) {
				$partial[] = $check;
			} elseif ( ! empty( $check['passed'] ) ) {
				$passed[] = $check;
			} else {
				$failed[] = $check;
			}
		}
		$ran         = count( $passed ) + count( $failed ) + count( $partial );
		$run_pages   = isset( $result['pages_scanned'] ) ? (int) $result['pages_scanned'] : 0;
		if ( $run_pages < 1 ) {
			foreach ( $result['checks'] as $check ) {
				$run_pages = max( $run_pages, isset( $check['pages'] ) ? (int) $check['pages'] : 0 );
			}
		}
		$intro = sprintf( __( '%1$d checks ran on the scanned pages: %2$d passed, %3$d found issues. The issues are listed above; the passes are the rest of the story — this is everything the audit looked at.', 'equalfix' ), $ran, count( $passed ), count( $failed ) );
		if ( ! empty( $partial ) ) {
			// A check that never reached some pages is not a
			// pass and not a failure — the card says so
			// plainly instead of folding it into either
			// count (loop round 1, H-08).
			$intro .= ' ' . sprintf( _n( '%d check ran on only some of the scanned pages, so it is not counted as passed.', '%d checks ran on only some of the scanned pages, so they are not counted as passed.', count( $partial ), 'equalfix' ), count( $partial ) );
		}
		?>
		<div class="equalfix-card eqf-checks-card">
			<h3><?php esc_html_e( 'What this audit checked', 'equalfix' ); ?></h3>
			<p><?php echo esc_html( $intro ); ?></p>
			<?php if ( ! empty( $passed ) ) : ?>
			<h4><?php esc_html_e( 'Checks that passed', 'equalfix' ); ?></h4>
			<ul class="eqf-checks-passed">
				<?php foreach ( $passed as $check ) : ?>
				<li>✓ <?php echo esc_html( $check['name'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php if ( ! empty( $partial ) ) : ?>
			<h4><?php esc_html_e( 'Checks that ran on some pages only', 'equalfix' ); ?></h4>
			<ul class="eqf-checks-partial">
				<?php foreach ( $partial as $check ) : ?>
				<li><?php echo esc_html( sprintf( __( '%1$s — ran on %2$d of %3$d pages', 'equalfix' ), $check['name'], isset( $check['pages'] ) ? (int) $check['pages'] : 0, $run_pages ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php if ( ! empty( $failed ) ) : ?>
			<h4><?php esc_html_e( 'Checks that found issues', 'equalfix' ); ?></h4>
			<ul class="eqf-checks-failed">
				<?php foreach ( $failed as $check ) : ?>
				<li><?php echo esc_html( sprintf( _n( '%1$s — %2$d finding', '%1$s — %2$d findings', (int) $check['findings'], 'equalfix' ), $check['name'], (int) $check['findings'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The readability panel (Tier 2, T2-05): an audit-level
	 * reading-grade summary for the run's page text — not a
	 * finding, never in any count. Renders only when the run
	 * collected enough text to judge (the stored value is
	 * null otherwise, so the panel simply does not appear).
	 *
	 * @param array $result Stored audit result.
	 */
	private function render_readability_card( $result ) {
		if ( ! isset( $result['readability'] ) || ! is_array( $result['readability'] ) || ! isset( $result['readability']['grade'] ) ) {
			return;
		}
		$grade = (float) $result['readability']['grade'];
		if ( $grade <= 6 ) {
			$line = __( 'Easy reading — most visitors will manage this comfortably, including people reading in a second language.', 'equalfix' );
		} elseif ( $grade <= 9 ) {
			$line = __( 'Comfortable for most readers — about the level of a good newspaper.', 'equalfix' );
		} elseif ( $grade <= 12 ) {
			$line = __( 'Takes a focused reader — fine for specialist topics, hard work for a quick visit.', 'equalfix' );
		} else {
			$line = __( 'Difficult, specialist text — shorter sentences and plainer words would open it up to far more visitors.', 'equalfix' );
		}
		?>
		<div class="equalfix-card eqf-readability-card">
			<h3><?php esc_html_e( 'How easy is your text to read?', 'equalfix' ); ?></h3>
			<p><?php echo esc_html( sprintf( __( 'Reading grade: %s (Flesch–Kincaid). %s', 'equalfix' ), number_format_i18n( $grade, 1 ), $line ) ); ?></p>
			<p class="description"><?php echo esc_html( sprintf( __( 'Measured from %1$s words of page text across %2$d pages — menus and navigation left out. A guide, not a judgement: some topics honestly need harder words.', 'equalfix' ), number_format_i18n( (int) $result['readability']['words'] ), (int) $result['readability']['pages'] ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * The all-done state (Direction A §3.3): shown when the
	 * model's TO DO bucket is empty over a non-empty audit.
	 *
	 * @param array $result Stored audit result.
	 * @param array $model  Coverage model.
	 */
	private function render_all_done_card( $result, $model ) {
		$parts = array();
		if ( $model['d_fixed'] > 0 ) {
			$parts[] = sprintf( _n( '%d fixed', '%d fixed', $model['d_fixed'], 'equalfix' ), $model['d_fixed'] );
		}
		if ( $model['d_waiting'] > 0 ) {
			$parts[] = sprintf( _n( '%d waiting for your OK in Pro → AI suggestions', '%d waiting for your OK in Pro → AI suggestions', $model['d_waiting'], 'equalfix' ), $model['d_waiting'] );
		}
		if ( $model['d_dismissed'] > 0 ) {
			$parts[] = sprintf( _n( '%d set aside', '%d set aside', $model['d_dismissed'], 'equalfix' ), $model['d_dismissed'] );
		}
		?>
		<div class="equalfix-card eqf-alldone">
			<h3><?php esc_html_e( '🎉 Everything from your last scan is handled', 'equalfix' ); ?></h3>
			<?php if ( ! empty( $parts ) ) : ?>
				<p><?php echo esc_html( implode( ' · ', $parts ) . '.' ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'New pages and changes won’t appear until you scan again.', 'equalfix' ); ?></p>
			<p class="eqf-alldone-actions">
				<?php $this->render_audit_form(); ?>
				<a class="button" href="<?php echo esc_url( $this->tab_url( 'fixed' ) ); ?>"><?php esc_html_e( 'See what’s fixed →', 'equalfix' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * A job card's ONE primary action (redesign, 2026-11-07):
	 * rendered in the card head, exactly one per card, and
	 * every form posts to an EXISTING handler under the
	 * existing nonce — the redesign moves buttons, it does
	 * not add mechanics. Fix-a groups post enable_fix_type
	 * for the group's type; fix-b groups post apply_pro_fixes
	 * (or link to the licence / pricing surfaces when Pro
	 * cannot run); draft groups carry the shipped bulk-draft
	 * form (moved here from the card body — same fields, same
	 * label) or the single-draft form; review, manual-first,
	 * guidance and embed groups NEVER get a fix action — they
	 * get the "Show me where" link onto the open card.
	 *
	 * @param array $group     Job group (render_job_cards()).
	 * @param array $meta      finding_job_meta() for the group.
	 * @param array $ctx       Job context (render_job_cards()).
	 * @param array $skip_map  AI-skip map.
	 * @param bool  $show_todo Whether the to-do rows show.
	 * @param array $pro       pro_state().
	 */
	private function render_card_action( $group, $meta, $ctx, $skip_map, $show_todo, $pro ) {
		$todo     = (int) $group['d_todo'];
		$waiting  = isset( $group['d_waiting'] ) ? (int) $group['d_waiting'] : 0;
		$kind     = $meta['kind'];
		$open_url = $this->tab_url( 'issues', array( 'view' => 'todo', 'open' => $meta['group'] ) ) . '#eqf-group-' . $meta['group'];
		?>
		<span class="eqf-card-action">
		<?php if ( 'fix-type' === $kind && $todo > 0 ) : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="enable_fix_type">
				<input type="hidden" name="equalfix_fix_type" value="<?php echo esc_attr( $meta['fix_type'] ); ?>">
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: open problems in this group */ _n( 'Fix all %d', 'Fix all %d', $todo, 'equalfix' ), $todo ) ); ?></button>
			</form>
			<span class="eqf-card-sub"><?php echo $ctx['type_on'] ? esc_html__( 'Fix set is paused — this turns it back on', 'equalfix' ) : esc_html__( 'Safe, automatic fix ›', 'equalfix' ); ?></span>
		<?php elseif ( 'fix-b' === $kind && $todo > 0 ) : ?>
			<?php
			$fixb_on      = false;
			$fixb_type_on = false;
			if ( class_exists( 'EqualFix_Pro_Settings' ) ) {
				$fixb_state   = EqualFix_Pro_Settings::fix_set_state();
				$fixb_on      = ! empty( $fixb_state['master'] );
				$fixb_type_on = ! empty( $fixb_state['types'][ $meta['fix_type'] ] );
			}
			?>
			<?php if ( ! empty( $pro['licence_active'] ) && ! ( $fixb_on && $fixb_type_on ) ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="apply_pro_fixes">
					<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: open problems in this group */ _n( 'Fix all %d', 'Fix all %d', $todo, 'equalfix' ), $todo ) ); ?></button>
				</form>
				<span class="eqf-card-sub"><?php esc_html_e( 'Applies the Pro fix set', 'equalfix' ); ?></span>
			<?php elseif ( ! empty( $pro['licence_active'] ) ) : ?>
				<span class="eqf-card-sub"><?php esc_html_e( 'Already on — one skip per page is corrected; the rest need a manual edit', 'equalfix' ); ?></span>
			<?php elseif ( ! empty( $pro['present'] ) ) : ?>
				<a class="button eqf-btn-ghost" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %d: open problems in this group */ __( '🔒 Fix all %d', 'equalfix' ), $todo ) ); ?></a>
				<span class="eqf-card-sub"><?php esc_html_e( 'Included with Pro — unlocks when your licence is active', 'equalfix' ); ?></span>
			<?php else : ?>
				<a class="button eqf-btn-ghost" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: open problems in this group */ __( '🔒 Fix all %d', 'equalfix' ), $todo ) ); ?></a>
				<span class="eqf-card-sub"><?php esc_html_e( 'Included with Pro', 'equalfix' ); ?></span>
			<?php endif; ?>
		<?php elseif ( 'draft' === $kind && ! empty( $pro['licence_active'] ) && (int) $group['todo_unskipped'] >= 2 ) : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="draft_all_with_ai">
				<?php if ( $show_todo ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: findings AI will draft for */ __( 'Draft all %d findings with AI', 'equalfix' ), (int) $group['todo_unskipped'] ) ); ?></button>
			</form>
			<span class="eqf-card-sub"><?php esc_html_e( 'Drafts go to Review for your approval — nothing goes live without you.', 'equalfix' ); ?></span>
		<?php elseif ( 'draft' === $kind && ! empty( $pro['licence_active'] ) && 1 === (int) $group['todo_unskipped'] ) : ?>
			<?php
			$draft_first  = null;
			$draft_labels = array(
				'alt'   => __( 'Draft descriptions with AI', 'equalfix' ),
				'label' => __( 'Draft labels with AI', 'equalfix' ),
				'name'  => __( 'Draft names with AI', 'equalfix' ),
				'title' => __( 'Draft titles with AI', 'equalfix' ),
			);
			foreach ( $group['items'] as $draft_item ) {
				if ( 'todo' === $draft_item['state'] && ! self::map_has_finding( $skip_map, $draft_item['finding'] ) ) {
					$draft_first = $draft_item['finding'];
					break;
				}
			}
			?>
			<?php if ( null !== $draft_first ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="draft_with_ai">
					<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( self::finding_fingerprint( $draft_first ) ); ?>">
					<?php if ( $show_todo ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
					<button type="submit" class="button button-primary"><?php echo esc_html( isset( $draft_labels[ $meta['draft_kind'] ] ) ? $draft_labels[ $meta['draft_kind'] ] : __( 'Draft with AI', 'equalfix' ) ); ?></button>
				</form>
				<span class="eqf-card-sub"><?php esc_html_e( 'Drafts only — nothing changes until you approve', 'equalfix' ); ?></span>
			<?php endif; ?>
		<?php elseif ( 'draft' === $kind && empty( $pro['licence_active'] ) && $todo > 0 ) : ?>
			<?php if ( ! empty( $pro['present'] ) ) : ?>
				<a class="button eqf-btn-ghost" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( '🔒 Draft with AI', 'equalfix' ); ?></a>
			<?php else : ?>
				<a class="button eqf-btn-ghost" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>"><?php esc_html_e( '🔒 Draft with AI', 'equalfix' ); ?></a>
			<?php endif; ?>
			<span class="eqf-card-sub"><?php esc_html_e( 'Included with Pro — drafts only, nothing changes until you approve', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && 'interactive' === $meta['group'] ) : ?>
			<?php // Waves 3+4: the button kit is approved element by element — there is no bulk version of an all-or-nothing change like this. ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php esc_html_e( 'Each one is approved on its own, in its row', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && 'page-title' === $meta['group'] ) : ?>
			<?php // Waves 3+4: each page gets its own title (its post title or the site name), approved per page in the row. ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php esc_html_e( 'Add each page’s missing title from its row', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && in_array( $meta['group'], array( 'media-video', 'media-audio' ), true ) ) : ?>
			<?php // Waves 3+4: captions and transcripts are the owner's own content — supplied per item, never invented, so no bulk action exists. ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php echo esc_html( 'media-video' === $meta['group'] ? __( 'Add a captions file or a transcript in each row — EqualFix never invents them', 'equalfix' ) : __( 'Add a transcript in each row — EqualFix never invents one', 'equalfix' ) ); ?></span>
		<?php elseif ( $todo > 0 && in_array( $meta['group'], self::attr_groups(), true ) && ! empty( $group['has_proposals'] ) ) : ?>
			<?php // Fix-First Wave 1: the band action IS the fix — one confirm applies the computed fix to every open problem in the group (each element still gets its own rule record). ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="apply_attr_fixes">
				<input type="hidden" name="equalfix_attr_group" value="<?php echo esc_attr( $meta['group'] ); ?>">
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: open problems in this group */ _n( 'Fix all %d', 'Fix all %d', $todo, 'equalfix' ), $todo ) ); ?></button>
			</form>
			<span class="eqf-card-sub"><?php esc_html_e( 'Applies the computed fix to every problem in this group', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && in_array( $meta['group'], self::REVIEW_GROUPS, true ) ) : ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php esc_html_e( 'See each spot on the page — no fix promised', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && 'manual-first' === $kind && in_array( $meta['group'], array( 'embed-3p', 'embed-title' ), true ) ) : ?>
			<?php // Fix-First Wave 2: save the provider-derived title for every embed in the group at once; each row's title stays editable. ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="apply_group_titles">
				<input type="hidden" name="equalfix_attr_group" value="<?php echo esc_attr( $meta['group'] ); ?>">
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: open embeds in this group */ _n( 'Add a title to %d embed', 'Add titles to all %d embeds', $todo, 'equalfix' ), $todo ) ); ?></button>
			</form>
			<span class="eqf-card-sub"><?php esc_html_e( 'Titles come from the embed provider — edit any one in its row', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && 'manual-first' === $kind ) : ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php esc_html_e( 'Write the words yourself in the card — EqualFix applies what you write.', 'equalfix' ); ?></span>
		<?php elseif ( $todo > 0 && 'guidance' === $kind ) : ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php esc_html_e( 'A short checklist lives in the card — no automatic fix exists for this one.', 'equalfix' ); ?></span>
		<?php elseif ( $waiting > 0 ) : ?>
			<a class="button eqf-btn-ghost" href="<?php echo esc_url( $ctx['queue_url'] ); ?>"><?php esc_html_e( 'Review the drafts', 'equalfix' ); ?></a>
			<span class="eqf-card-sub"><?php echo esc_html( sprintf( /* translators: %d: problems with a draft waiting */ _n( '%d is waiting for your OK', '%d are waiting for your OK', $waiting, 'equalfix' ), $waiting ) ); ?></span>
		<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Job cards (Direction A): findings grouped by issue type,
	 * one card per job. A card's header names the job and counts
	 * what is left in the model's own words; the body carries
	 * the bulk action (where one exists) and the rows. Groups
	 * with nothing left sink to one-line receipts at the bottom.
	 *
	 * @param array $findings      Active findings (working list).
	 * @param array $model         Coverage model.
	 * @param array $settings      Current settings.
	 * @param bool  $open          Render cards expanded (todo view).
	 * @param array $rendered_urls URLs audited via simulation.
	 * @param array $result        Stored audit result (previews).
	 */
	private function render_job_cards( $findings, $model, $settings, $open, $rendered_urls, $result = array() ) {
		// Skip records (Stage 6.2, F9): findings generation
		// already tried and could not draft carry a Pro skip
		// record. The group "Draft all" bar below counts only
		// to-dos WITHOUT one — a group whose draftable items
		// are all recorded skips must not keep offering the
		// same futile retry one level up from the cards (which
		// already dropped their per-card AI buttons).
		$skip_map = array();
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$skip_map = EqualFix_Pro_Suggestions::skip_records();
		}
		// Occurrence indexing (Stage 6, decision D1): the stored
		// fingerprint stays canonical — identical elements on one
		// page share it, and dismissals / manual rules / queue
		// items are keyed by it on purpose. But RENDERING must be
		// occurrence-aware: two identical elements get two cards,
		// and each card's panels, inputs and anchor need their own
		// identity or the second card's controls collide with the
		// first's (the stranded duplicate date inputs). Occurrence
		// numbers follow audit order, which is page order.
		$occ_totals = array();
		foreach ( $findings as $finding ) {
			$fp                = self::finding_fingerprint( $finding );
			$occ_totals[ $fp ] = isset( $occ_totals[ $fp ] ) ? $occ_totals[ $fp ] + 1 : 1;
		}
		$occ_seen = array();
		$groups   = array();
		foreach ( $findings as $idx => $finding ) {
			$meta = $this->finding_job_meta( $finding );
			$key  = $meta['group'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'meta' => $meta, 'items' => array(), 'todo' => 0, 'todo_unskipped' => 0, 'waiting' => 0, 'fixed' => 0, 'pages' => array(), 'd_states' => array(), 'd_todo' => 0, 'd_waiting' => 0, 'd_fixed' => 0 );
			}
			$fp               = self::finding_fingerprint( $finding );
			$occ_seen[ $fp ]  = isset( $occ_seen[ $fp ] ) ? $occ_seen[ $fp ] + 1 : 1;
			$occ              = array(
				'n'   => $occ_seen[ $fp ],
				'of'  => $occ_totals[ $fp ],
				'idx' => $idx,
			);
			if ( isset( $model['fixed_fps'][ $fp ] ) ) {
				$state = 'fixed';
				$groups[ $key ]['fixed']++;
			} elseif ( isset( $model['waiting_fps'][ $fp ] ) ) {
				$state = 'waiting';
				$groups[ $key ]['waiting']++;
			} else {
				$state = 'todo';
				$groups[ $key ]['todo']++;
				if ( ! self::map_has_finding( $skip_map, $finding ) ) {
					$groups[ $key ]['todo_unskipped']++;
				}
			}
			// Distinct layer (F-1): record the problem
			// identity's states within THIS list; the header
			// counts distinct problems with the model's
			// precedence (open anywhere → open; else waiting
			// anywhere → waiting; else fixed). On the full
			// (All) list these per-group distinct counts sum
			// exactly to the headline d_* totals.
			list( , $problem_id ) = $this->problem_identity( $finding );
			$groups[ $key ]['d_states'][ $problem_id ][ $state ] = true;
			$groups[ $key ]['items'][]            = array(
				'finding' => $finding,
				'occ'     => $occ,
				'state'   => $state,
			);
			$groups[ $key ]['pages'][ $finding['url'] ] = true;
		}
		// Strict state order INSIDE each group (Stage 6): to-do
		// rows lead, waiting rows next, fixed rows last — a mixed
		// group can no longer bury its live work under finished
		// rows. Audit order is the tiebreak within a state class.
		foreach ( $groups as $key => $group ) {
			$rank = array( 'todo' => 0, 'waiting' => 1, 'fixed' => 2 );
			usort(
				$group['items'],
				function ( $a, $b ) use ( $rank ) {
					if ( $a['state'] !== $b['state'] ) {
						return $rank[ $a['state'] ] <=> $rank[ $b['state'] ];
					}
					return $a['occ']['idx'] <=> $b['occ']['idx'];
				}
			);
			$groups[ $key ] = $group;
		}
		uasort(
			$groups,
			function ( $a, $b ) {
				$rank = function ( $g ) {
					if ( $g['todo'] > 0 ) {
						return array( 0, -$g['todo'] );
					}
					if ( $g['waiting'] > 0 ) {
						return array( 1, -$g['waiting'] );
					}
					return array( 2, -count( $g['items'] ) );
				};
				$ra   = $rank( $a );
				$rb   = $rank( $b );
				if ( $ra[0] !== $rb[0] ) {
					return $ra[0] <=> $rb[0];
				}
				return $ra[1] <=> $rb[1];
			}
		);
		// Finalize per-group distinct counts (F-1).
		foreach ( $groups as $key => $group ) {
			foreach ( $group['d_states'] as $states ) {
				if ( isset( $states['todo'] ) ) {
					$groups[ $key ]['d_todo']++;
				} elseif ( isset( $states['waiting'] ) ) {
					$groups[ $key ]['d_waiting']++;
				} else {
					$groups[ $key ]['d_fixed']++;
				}
			}
			// Fix-First: a Wave 1 band offers its Fix all only
			// when at least one finding in the group actually
			// has a computed proposal — a stored finding whose
			// data cannot yield one (e.g. a contrast finding
			// from before the explanation carried the colours)
			// must not be promised a fix the save would refuse.
			$groups[ $key ]['has_proposals'] = false;
			if ( in_array( $key, self::attr_groups(), true ) && 'interactive' !== $key ) {
				// Waves 3+4: the interactive kit is per-item
				// only, so its band never offers a Fix all.
				// For link-generic, only a DETERMINISTIC
				// proposal counts — a link whose name must
				// come from the owner cannot be bulk-fixed.
				foreach ( $group['items'] as $prop_item ) {
					if ( 'todo' !== $prop_item['state'] ) {
						continue;
					}
					$prop_proposal = $this->attr_proposal_for_finding( $prop_item['finding'] );
					if ( null !== $prop_proposal && empty( $prop_proposal['needs_text'] ) ) {
						$groups[ $key ]['has_proposals'] = true;
						break;
					}
				}
			}
		}
		$pro = self::pro_state();
		// The card a "Show me where" link points at (redesign):
		// that card renders open even on the collapsed views.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only card selection.
		$open_group = isset( $_GET['open'] ) ? sanitize_key( wp_unslash( $_GET['open'] ) ) : '';
		$ctx        = array(
			'pro'           => $pro,
			'settings'      => $settings,
			'show_todo'     => $open,
			'queue_url'     => $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ),
			'licence_url'   => $this->tab_url( $pro['shell'] ? 'pro-licence' : 'pro' ),
			'fixb_url'      => $this->tab_url( $pro['shell'] ? 'pro-fixb' : 'pro' ),
			'rendered_urls' => $rendered_urls,
		);
		$receipts = array();
		?>
		<div class="equalfix-findings eqf-jobcards">
		<?php // Fix-First table UI (2026-11-07): the working list is ONE table in a bordered, internally scrolling panel — group bands replace card heads, rows are compact one-liners that expand in place. Everything above this list is untouched. ?>
		<div class="eqf-tpanel"><div class="eqf-tpanel-scroll">
		<table class="eqf-itable">
			<colgroup><col style="width:46%"><col style="width:16%"><col style="width:13%"><col style="width:25%"></colgroup>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Problem', 'equalfix' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Page', 'equalfix' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Severity', 'equalfix' ); ?></th>
				<th scope="col" class="eqf-th-action"><?php esc_html_e( 'Action', 'equalfix' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $groups as $group ) : ?>
				<?php
				if ( 0 === $group['todo'] && 0 === $group['waiting'] ) {
					$receipts[] = $group;
					continue;
				}
				$meta  = $group['meta'];
				$pages = count( $group['pages'] );
				// Count line (Stage 6.1, F6b; final pass F-1):
				// DISTINCT problems, state first and the page
				// span LAST — "3 problems still to do · on 11
				// pages". The rows inside still list every
				// occurrence; the header counts the problem
				// once, however many pages repeat it, so the
				// group headers sum to the Overview headline.
				$is_review_group = in_array( $group['meta']['group'], self::REVIEW_GROUPS, true );
				if ( $group['d_todo'] > 0 ) {
					$count_line = $is_review_group
						? sprintf( _n( '%d problem for your review', '%d problems for your review', $group['d_todo'], 'equalfix' ), $group['d_todo'] )
						: sprintf( _n( '%d problem still to do', '%d problems still to do', $group['d_todo'], 'equalfix' ), $group['d_todo'] );
				} else {
					$count_line = sprintf( _n( '%d problem waiting for your OK', '%d problems waiting for your OK', $group['d_waiting'], 'equalfix' ), $group['d_waiting'] );
				}
				if ( $group['d_todo'] > 0 && $group['d_waiting'] > 0 ) {
					$count_line .= ' · ' . sprintf( _n( '%d waiting', '%d waiting', $group['d_waiting'], 'equalfix' ), $group['d_waiting'] );
				}
				if ( $group['d_fixed'] > 0 ) {
					$count_line .= ' · ' . sprintf( _n( '%d already fixed', '%d already fixed', $group['d_fixed'], 'equalfix' ), $group['d_fixed'] );
				}
				$count_line .= ' · ' . sprintf( _n( 'on %d page', 'on %d pages', $pages, 'equalfix' ), $pages );
				?>
				<?php
				// Redesign (2026-11-07): the head carries the
				// severity dot, the plain display title, the
				// F-1 count line (verbatim — the count
				// contracts read it), the visitor-impact line
				// and the ONE primary action; the bulk draft
				// form moved into that action (see
				// render_card_action) with its shipped fields.
				list( $sev_class, $sev_word ) = $this->issue_severity( $meta['group'], $is_review_group );
				$card_tag  = '';
				if ( 'fix-type' === $meta['kind'] && $group['d_todo'] > 0 ) {
					$card_tag = 'oneclick';
				} elseif ( in_array( $meta['kind'], array( 'fix-b', 'draft' ), true ) ) {
					$card_tag = 'pro';
				}
				$ctx_group            = $ctx;
				$ctx_group['type_on'] = 'fix-type' === $meta['kind'] && ! empty( $settings[ 'fix_' . $meta['fix_type'] ] );
				// Table UI: rows carry the group's severity and
				// the expansion state the card used to carry —
				// rows render open in the to-do view, and the
				// open= group's rows render open on every view.
				$ctx_group['sev']       = array( $sev_class, $sev_word );
				$ctx_group['rows_open'] = ( $open || $open_group === $meta['group'] );
				// When the card head carries the single-draft
				// form (a draft group with exactly one
				// unattempted finding), the row for that
				// finding does not repeat the same button —
				// one draft action per finding, as shipped.
				$ctx_group['head_draft_fp'] = '';
				if ( 'draft' === $meta['kind'] && ! empty( $pro['licence_active'] ) && 1 === $group['todo_unskipped'] ) {
					foreach ( $group['items'] as $hd_item ) {
						if ( 'todo' === $hd_item['state'] && ! self::map_has_finding( $skip_map, $hd_item['finding'] ) ) {
							$ctx_group['head_draft_fp'] = self::finding_fingerprint( $hd_item['finding'] );
							break;
						}
					}
				}
				?>
				<tr class="eqf-band" id="eqf-group-<?php echo esc_attr( $meta['group'] ); ?>"><td colspan="4"><div class="eqf-band-in">
					<span class="eqf-band-name"><?php echo esc_html( $this->issue_display_title( $meta['group'], $meta['name'] ) ); ?></span><?php if ( 'oneclick' === $card_tag ) : ?><span class="eqf-tag eqf-tag-oneclick"><?php esc_html_e( 'One-click fix', 'equalfix' ); ?></span><?php elseif ( 'pro' === $card_tag ) : ?><span class="eqf-tag eqf-tag-pro"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span><?php endif; ?><?php if ( $is_review_group ) : ?><span class="eqf-badge eqf-badge-review"><?php esc_html_e( 'Needs human review', 'equalfix' ); ?></span><?php endif; ?>
					<span class="eqf-band-count"><?php echo esc_html( $count_line ); ?></span>
					<?php $this->render_card_action( $group, $meta, $ctx_group, $skip_map, $open, $pro ); ?>
				</div></td></tr>
				<?php if ( 'fix-type' === $meta['kind'] && $group['d_todo'] > 0 && ! empty( $result ) ) : ?>
					<?php
					$pv_finding = null;
					foreach ( $group['items'] as $pv_item ) {
						if ( 'todo' === $pv_item['state'] ) {
							$pv_finding = $pv_item['finding'];
							break;
						}
					}
					?>
					<?php if ( null !== $pv_finding ) : ?>
						<?php list( $pv_before, $pv_after, $pv_real ) = $this->fix_preview_pair( $meta['fix_type'], $pv_finding, $result ); ?>
						<tr class="eqf-tpreview"><td colspan="4">
							<div class="eqf-preview">
								<span class="eqf-pv-lbl"><?php esc_html_e( 'Preview', 'equalfix' ); ?></span>
								<code class="eqf-pv-from"><?php echo esc_html( self::preview_short( $pv_before ) ); ?></code>
								<span class="eqf-pv-arrow" aria-hidden="true">→</span>
								<code class="eqf-pv-to"><?php echo esc_html( self::preview_short( $pv_after ) ); ?></code>
								<?php if ( ! $pv_real ) : ?>
									<span class="eqf-pv-note"><?php esc_html_e( 'example from this finding', 'equalfix' ); ?></span>
								<?php endif; ?>
							</div>
						</td></tr>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( 'draft' === $meta['kind'] && $open && $group['todo'] > 0 && 0 === $group['todo_unskipped'] ) : ?>
					<tr class="eqf-tpreview"><td colspan="4"><p class="eqf-bulk-note"><?php esc_html_e( 'AI has already tried every finding in this group and found nothing safe to suggest — write your own words on a row below, or set a finding aside.', 'equalfix' ); ?></p></td></tr>
				<?php endif; ?>
				<?php
				// Every row in the current view renders in
				// place (the IA batch's no-burial rule carries
				// into the table unchanged).
				foreach ( $group['items'] as $item ) :
					$this->render_job_row( $item['finding'], $item['state'], $meta, $ctx_group, $item['occ'] );
				endforeach;
				?>
			<?php endforeach; ?>
			<?php foreach ( $receipts as $group ) : ?>
				<tr class="eqf-band eqf-receipt"><td colspan="4">
					<span class="eqf-receipt-text"><?php echo esc_html( sprintf( __( '✓ %1$s — %2$d fixed', 'equalfix' ), $group['meta']['name'], $group['d_fixed'] ) ); ?></span>
					<a href="<?php echo esc_url( $this->tab_url( 'fixed' ) ); ?>"><?php esc_html_e( 'See them', 'equalfix' ); ?></a>
				</td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div></div>
		</div>
		<?php
	}

	/**
	 * One finding row in a job card (Direction A §2.1): the
	 * actual element, the plain problem line, one why sentence,
	 * and — unless it is already handled — exactly one primary
	 * action. Technical material lives in Details; dismissal is
	 * the demoted "Not an issue?" panel.
	 *
	 * @param array  $finding Finding.
	 * @param string $state   fixed|waiting|todo (coverage model).
	 * @param array  $meta    Job meta (finding_job_meta()).
	 * @param array  $ctx     Render context (render_job_cards()).
	 * @param array  $occ     Occurrence info { n, of, idx } (Stage 6).
	 */
	private function render_job_row( $finding, $state, $meta, $ctx, $occ = array( 'n' => 1, 'of' => 1, 'idx' => 0 ) ) {
		$fp     = self::finding_fingerprint( $finding );
		$occ_id = $fp . '-' . (int) $occ['n'];
		// A skipped finding (Stage 6): generation ran and could
		// not draft this one. The record exists only when Pro is
		// present; it shows only while the finding is still to do.
		$skip = null;
		if ( 'todo' === $state && class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$skip = EqualFix_Pro_Suggestions::skip_record_for( $fp );
		}
		list( $sev_class, $sev_word ) = isset( $ctx['sev'] ) ? $ctx['sev'] : $this->issue_severity( $meta['group'], in_array( $meta['group'], self::REVIEW_GROUPS, true ) );
		?>
		<tr class="eqf-trow eqf-row-<?php echo esc_attr( $state ); ?>"><td colspan="4">
		<details class="eqf-titem" id="eqf-card-<?php echo esc_attr( $occ_id ); ?>"<?php echo ! empty( $ctx['rows_open'] ) ? ' open' : ''; ?>>
			<summary class="eqf-tsummary">
				<span class="eqf-tc eqf-tc-problem"><?php echo esc_html( $meta['problem'] ); ?><?php $suffix = $this->row_identity_suffix( $finding, $meta ); if ( '' !== $suffix ) : ?> <span class="eqf-tfn">· <?php echo esc_html( $suffix ); ?></span><?php endif; ?></span>
				<span class="eqf-tc eqf-tc-page"><a href="<?php echo esc_url( $finding['url'] ); ?>"><?php echo esc_html( self::page_title_label( $finding['url'] ) ); ?></a></span>
				<span class="eqf-tc eqf-tc-sev"><span class="eqf-sev eqf-sev-<?php echo esc_attr( $sev_class ); ?>"><span class="eqf-sev-dot" aria-hidden="true"></span><?php echo esc_html( $sev_word ); ?></span></span>
				<span class="eqf-tc eqf-tc-action"><?php $this->render_row_compact_action( $finding, $state, $meta, $ctx, $occ, $skip ); ?><span class="eqf-tdetails"><?php esc_html_e( 'Details', 'equalfix' ); ?></span></span>
			</summary>
			<div class="eqf-tdetail eqf-row">
			<div class="eqf-row-main">
				<?php $this->render_row_element( $finding, $meta ); ?>
				<div class="eqf-row-text">
					<p class="eqf-row-problem"><?php echo esc_html( $meta['problem'] ); ?></p>
					<?php if ( '' !== $meta['why'] ) : ?>
						<p class="eqf-row-why"><?php echo esc_html( $meta['why'] ); ?></p>
					<?php endif; ?>
					<p class="eqf-row-page">
						<?php esc_html_e( 'On:', 'equalfix' ); ?> <a href="<?php echo esc_url( $finding['url'] ); ?>"><?php echo esc_html( self::page_title_label( $finding['url'] ) ); ?></a>
						<?php if ( in_array( $finding['url'], $ctx['rendered_urls'], true ) ) : ?>
							<span class="equalfix-pill"><?php esc_html_e( 'Audited via in-process simulation', 'equalfix' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<?php if ( (int) $occ['of'] > 1 ) : ?>
				<p class="eqf-row-dup"><?php echo esc_html( sprintf( __( 'Copy %1$d of %2$d on this page — the elements are identical, so a fix (or setting this aside) covers all %2$d.', 'equalfix' ), (int) $occ['n'], (int) $occ['of'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( null !== $skip ) : ?>
				<p class="eqf-skip-note"><span class="eqf-badge eqf-badge-skip"><?php esc_html_e( 'AI skipped — needs your words', 'equalfix' ); ?></span> <?php echo esc_html( EqualFix_Pro_Suggestions::skip_reason_label( isset( $skip['reason'] ) ? (string) $skip['reason'] : '' ) ); ?></p>
			<?php endif; ?>
			<?php if ( 'fixed' === $state ) : ?>
				<p class="eqf-row-fixed"><?php echo esc_html( sprintf( __( '✓ Fixed — %s', 'equalfix' ), $this->fixed_how_text( $finding ) ) ); ?></p>
			<?php elseif ( 'waiting' === $state ) : ?>
				<div class="eqf-row-actions">
					<span class="eqf-row-waiting"><?php esc_html_e( 'Draft ready — waiting for your OK', 'equalfix' ); ?></span>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['queue_url'] ); ?>"><?php esc_html_e( 'Review the draft', 'equalfix' ); ?></a>
				</div>
			<?php else : ?>
				<?php $this->render_row_actions( $finding, $meta, $ctx, $occ, $skip ); ?>
			<?php endif; ?>
			<div class="eqf-row-quiet">
				<?php $this->render_finding_details( $finding ); ?>
				<?php $this->render_set_aside_panel( $finding, $ctx ); ?>
			</div>
			</div>
		</details>
		</td></tr>
		<?php
	}

	/**
	 * The short element identifier a compact table row carries
	 * beside its problem line (the mockup's "· photo-…" suffix):
	 * the image's file name, the frame's host, or the element's
	 * own id / first class / name — enough to tell identical
	 * problems apart at a glance.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @return string
	 */
	private function row_identity_suffix( $finding, $meta ) {
		if ( in_array( $meta['group'], array( 'alt', 'alt-auto', 'alt-filename' ), true ) ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			if ( '' !== $src ) {
				return basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			}
		}
		$el = self::snippet_element( $finding['snippet'] );
		if ( '' === $el['tag'] ) {
			return '';
		}
		$attrs = $el['attrs'];
		if ( ! empty( $attrs['id'] ) ) {
			return '#' . $attrs['id'];
		}
		if ( ! empty( $attrs['src'] ) ) {
			$host = wp_parse_url( $attrs['src'], PHP_URL_HOST );
			return '' !== (string) $host ? (string) $host : basename( (string) wp_parse_url( $attrs['src'], PHP_URL_PATH ) );
		}
		if ( ! empty( $attrs['class'] ) ) {
			$classes = preg_split( '/\s+/', trim( $attrs['class'] ) );
			return '.' . $classes[0];
		}
		if ( ! empty( $attrs['name'] ) ) {
			return $attrs['name'];
		}
		return $el['tag'];
	}

	/**
	 * A table row's ONE compact action (Fix-First table UI):
	 * the same primary the row's detail carries, in small form
	 * — a real fix where one exists (the Wave 1 Apply, the
	 * fix-type switch, the AI draft), the queue link while
	 * waiting, and nothing when the fix lives in the detail
	 * (the owner's own words) or the state needs no action.
	 * Forms inside <summary> follow the shipped card-head
	 * pattern.
	 *
	 * @param array      $finding Finding.
	 * @param string     $state   fixed|waiting|todo.
	 * @param array      $meta    Job meta.
	 * @param array      $ctx     Render context.
	 * @param array      $occ     Occurrence info.
	 * @param array|null $skip    Pro skip record, if any.
	 */
	private function render_row_compact_action( $finding, $state, $meta, $ctx, $occ = array( 'n' => 1 ), $skip = null ) {
		if ( 'fixed' === $state ) {
			return;
		}
		$pro = $ctx['pro'];
		$fp  = self::finding_fingerprint( $finding );
		if ( 'waiting' === $state ) {
			?>
			<a class="button button-small" href="<?php echo esc_url( $ctx['queue_url'] ); ?>"><?php esc_html_e( 'Review the draft', 'equalfix' ); ?></a>
			<?php
			return;
		}
		// Saved-but-gated fixes (owner's words or an approved
		// attribute rule, master off): the switch is the action.
		if ( null !== self::manual_match_for_finding( $finding ) || null !== self::attr_match_for_finding( $finding ) ) {
			?>
			<form method="post" action="" class="eqf-tform">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="toggle_master">
				<button type="submit" class="button button-small"><?php esc_html_e( 'Turn on fixes', 'equalfix' ); ?></button>
			</form>
			<?php
			return;
		}
		switch ( $meta['kind'] ) {
			case 'fix-type':
				?>
				<form method="post" action="" class="eqf-tform">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="enable_fix_type">
					<input type="hidden" name="equalfix_fix_type" value="<?php echo esc_attr( $meta['fix_type'] ); ?>">
					<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
					<button type="submit" class="button button-small"><?php esc_html_e( 'Turn on this fix', 'equalfix' ); ?></button>
				</form>
				<?php
				break;
			case 'fix-b':
				if ( ! empty( $pro['licence_active'] ) ) {
					// F-3 discipline, mirrored from the detail:
					// when the set AND this type are already on,
					// "Turn on this fix" would be a dead action —
					// the row offers none (the detail explains).
					$set_on  = false;
					$type_on = false;
					if ( class_exists( 'EqualFix_Pro_Settings' ) && method_exists( 'EqualFix_Pro_Settings', 'fix_set_state' ) && class_exists( 'EqualFix_Pro_Fixer' ) ) {
						$fix_set = EqualFix_Pro_Settings::fix_set_state();
						$set_on  = ! empty( $fix_set['master_on'] );
						// The detail's own read: the fix-b meta
						// carries no per-finding type today (the
						// one Fix Set B audit check is headings),
						// so the headings type is the verdict.
						$type_on = ! empty( $fix_set['types'][ EqualFix_Pro_Fixer::FIX_HEADINGS ] );
					}
					if ( ! ( $set_on && $type_on ) ) {
						?>
						<a class="button button-small" href="<?php echo esc_url( $ctx['fixb_url'] ); ?>"><?php esc_html_e( 'Turn on this fix', 'equalfix' ); ?></a>
						<?php
					}
				} elseif ( ! empty( $pro['present'] ) ) {
					?>
					<a class="button button-small" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a>
					<?php
				} else {
					?>
					<a class="button button-small" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a>
					<?php
				}
				break;
			case 'draft':
				if ( null !== $skip ) {
					// The owner's words are the action; they live
					// in the detail, pre-opened (as shipped).
					break;
				}
				if ( ! empty( $pro['licence_active'] ) ) {
					if ( empty( $ctx['head_draft_fp'] ) || $ctx['head_draft_fp'] !== $fp ) {
						$labels = array(
							'alt'   => __( 'Write it with AI', 'equalfix' ),
							'label' => __( 'Suggest a label with AI', 'equalfix' ),
							'name'  => __( 'Draft a name with AI', 'equalfix' ),
							'title' => __( 'Draft a title with AI', 'equalfix' ),
						);
						?>
						<form method="post" action="" class="eqf-tform">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="draft_with_ai">
							<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
							<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
							<button type="submit" class="button button-small"><?php echo esc_html( isset( $labels[ $meta['draft_kind'] ] ) ? $labels[ $meta['draft_kind'] ] : __( 'Draft with AI', 'equalfix' ) ); ?></button>
						</form>
						<?php
					}
				} elseif ( ! empty( $pro['present'] ) ) {
					?>
					<a class="button button-small" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a>
					<?php
				} else {
					?>
					<a class="button button-small" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a>
					<?php
				}
				break;
			case 'guidance':
				// A Wave 1 group with a computed proposal: the
				// row's action IS the fix (the panel in the
				// detail shows the before → after). A Waves 3+4
				// proposal flagged needs-text has no computed
				// value to save — the owner's words live in the
				// detail panel, so the row offers no one-click.
				$compact_proposal = in_array( $meta['group'], self::attr_groups(), true ) ? $this->attr_proposal_for_finding( $finding ) : null;
				if ( null !== $compact_proposal && empty( $compact_proposal['needs_text'] ) ) {
					$key    = self::attr_rule_key( $meta['group'], self::attr_target_for_proposal( $finding, $compact_proposal ), array( self::manual_page_pattern( $finding['url'] ) ) );
					if ( ! self::manual_cap_blocks( 'attr', $key ) ) {
						?>
						<form method="post" action="" class="eqf-tform">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="save_attr_rule">
							<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
							<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Fix', 'equalfix' ); ?></button>
						</form>
						<?php
					}
				} else {
					?>
					<a class="button-link eqf-tshow" href="<?php echo esc_url( $finding['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Show me where', 'equalfix' ); ?></a>
					<?php
				}
				break;
			default:
				// manual-first: the owner's words live in the
				// detail (the write form is the row's action).
				break;
		}
	}

	/**
	 * The row's element block: a thumbnail for images, an inert
	 * code chip for controls/frames, "Whole page" for page-level
	 * findings. Snippets are ALWAYS escaped text — the audited
	 * page's HTML is never echoed raw into the dashboard.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 */
	private function render_row_element( $finding, $meta ) {
		if ( in_array( $meta['group'], array( 'alt', 'alt-auto', 'alt-filename' ), true ) ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			$abs = '' !== $src ? EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] ) : '';
			if ( '' !== $abs ) {
				?>
				<figure class="eqf-row-elem eqf-row-thumb">
					<img src="<?php echo esc_url( $abs ); ?>" alt="" width="64" height="64" loading="lazy">
					<figcaption><?php echo esc_html( basename( (string) wp_parse_url( $abs, PHP_URL_PATH ) ) ); ?></figcaption>
				</figure>
				<?php
				return;
			}
		}
		if ( in_array( $meta['group'], array( 'lang', 'skip' ), true ) ) {
			?>
			<span class="eqf-row-elem eqf-row-wholepage"><?php esc_html_e( 'Whole page', 'equalfix' ); ?></span>
			<?php
			return;
		}
		$chip = trim( preg_replace( '/\s+/', ' ', (string) $finding['snippet'] ) );
		if ( mb_strlen( $chip ) > 110 ) {
			$chip = mb_substr( $chip, 0, 110 ) . '…';
		}
		?>
		<code class="eqf-row-elem eqf-row-chip"><?php echo esc_html( $chip ); ?></code>
		<?php
	}

	/**
	 * How a fixed finding is being fixed, in the Fixed tab's
	 * own source words (§2.3 taxonomy).
	 *
	 * @param array $finding Finding.
	 * @return string
	 */
	private function fixed_how_text( $finding ) {
		if ( null !== self::manual_match_for_finding( $finding ) ) {
			return __( 'Your written fix — live at render', 'equalfix' );
		}
		if ( null !== self::attr_match_for_finding( $finding ) ) {
			return __( 'Your chosen fix — live at render', 'equalfix' );
		}
		if ( null !== self::insert_match_for_finding( $finding ) ) {
			return __( 'Content you added — live at render', 'equalfix' );
		}
		if ( null !== self::pagetitle_match_for_finding( $finding ) ) {
			return __( 'Your chosen page title — live at render', 'equalfix' );
		}
		if ( EqualFix_Audit::CAT_FIX_A === $finding['category'] ) {
			return __( 'Free fix is on', 'equalfix' );
		}
		if ( EqualFix_Audit::CAT_FIX_B === $finding['category'] ) {
			return __( 'Extra fixes (Pro) is on', 'equalfix' );
		}
		return __( 'Approved in Pro — live at render', 'equalfix' );
	}

	/**
	 * A TO DO row's one primary action (+ quiet secondaries),
	 * per the redesign's per-type/per-entitlement matrix (§2.2).
	 *
	 * @param array      $finding Finding.
	 * @param array      $meta    Job meta.
	 * @param array      $ctx     Render context.
	 * @param array      $occ     Occurrence info { n, of, idx }.
	 * @param array|null $skip    Pro skip record for this finding, if any.
	 */
	private function render_row_actions( $finding, $meta, $ctx, $occ = array( 'n' => 1, 'of' => 1, 'idx' => 0 ), $skip = null ) {
		$pro = $ctx['pro'];
		$fp  = self::finding_fingerprint( $finding );
		?>
		<div class="eqf-row-actions">
		<?php
		// A finding with a saved written fix can only be TO DO
		// when the master switch is off (the model counts it
		// fixed otherwise) — say exactly that, with the switch.
		$manual = self::manual_match_for_finding( $finding );
		if ( null !== $manual && in_array( $meta['kind'], array( 'draft', 'manual-first' ), true ) ) :
			?>
			<p class="eqf-row-note"><?php esc_html_e( 'Your written fix is saved — it applies when fixes are switched on.', 'equalfix' ); ?></p>
			<form method="post" action="" class="eqf-row-primary">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="toggle_master">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on fixes', 'equalfix' ); ?></button>
			</form>
			</div>
			<?php
			return;
		endif;
		// Same story for an approved attribute rule (Fix-First):
		// saved, waiting on the master switch.
		$attr = self::attr_match_for_finding( $finding );
		if ( null !== $attr ) :
			?>
			<p class="eqf-row-note"><?php esc_html_e( 'Your fix is saved — it applies when fixes are switched on.', 'equalfix' ); ?></p>
			<form method="post" action="" class="eqf-row-primary">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="toggle_master">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on fixes', 'equalfix' ); ?></button>
			</form>
			</div>
			<?php
			return;
		endif;
		// And for the Waves 3+4 stores (owner-supplied media
		// content, approved page titles): same saved state,
		// same master-switch note.
		if ( null !== self::insert_match_for_finding( $finding ) || null !== self::pagetitle_match_for_finding( $finding ) ) :
			?>
			<p class="eqf-row-note"><?php esc_html_e( 'Your fix is saved — it applies when fixes are switched on.', 'equalfix' ); ?></p>
			<form method="post" action="" class="eqf-row-primary">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="toggle_master">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on fixes', 'equalfix' ); ?></button>
			</form>
			</div>
			<?php
			return;
		endif;
		switch ( $meta['kind'] ) {
			case 'fix-type':
				?>
				<form method="post" action="" class="eqf-row-primary">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="enable_fix_type">
					<input type="hidden" name="equalfix_fix_type" value="<?php echo esc_attr( $meta['fix_type'] ); ?>">
					<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on this fix', 'equalfix' ); ?></button>
				</form>
				<?php
				break;
			case 'fix-b':
				if ( ! empty( $pro['licence_active'] ) ) {
					// Final pass (F-3): read the set's applied
					// state through the ONE shared read. When
					// the set AND the heading-order type are
					// both on, a heading finding can still be
					// to do for exactly one reason: the set
					// already corrected the first skipped
					// heading on this page — it corrects at
					// most one skip per page, because a second
					// correction would mean rewriting the
					// page's heading structure (a design
					// change, not a render fix). "Turn on this
					// fix" here would be a dead action — it is
					// already on — so state the real reason and
					// offer the manual steps instead.
					$set_on  = false;
					$type_on = false;
					if ( class_exists( 'EqualFix_Pro_Settings' ) && method_exists( 'EqualFix_Pro_Settings', 'fix_set_state' ) && class_exists( 'EqualFix_Pro_Fixer' ) ) {
						$fix_set = EqualFix_Pro_Settings::fix_set_state();
						$set_on  = $fix_set['master_on'];
						$type_on = ! empty( $fix_set['types'][ EqualFix_Pro_Fixer::FIX_HEADINGS ] );
					}
					if ( $set_on && $type_on ) {
						if ( 'headings' === $meta['group'] && self::headings_full_approved_for_finding( $finding ) ) {
							// Waves 3+4: this page is approved
							// for the full correction — every
							// skip on it counts fixed (Pro
							// coverage), so a row still showing
							// states exactly that.
							?>
							<p class="eqf-row-note"><?php esc_html_e( 'This page is approved for the full heading correction — every skipped level on it is corrected as the page loads.', 'equalfix' ); ?></p>
							<?php
							$this->render_guidance_toggle( $finding, $meta, false, $occ );
						} elseif ( 'headings' === $meta['group'] ) {
							// Waves 3+4: the separate per-page
							// owner action. Approving changes
							// the page's heading OUTLINE (the
							// levels screen readers navigate
							// by); the headings look the same.
							// The standard first-skip behaviour
							// is untouched until this is pressed.
							?>
							<p class="eqf-row-note"><?php esc_html_e( 'Extra fixes is on — it already corrected the first skipped heading on this page. The set corrects one skipped level per page.', 'equalfix' ); ?></p>
							<form method="post" action="" class="eqf-row-primary">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="fix_headings_full_page">
								<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Fix all skipped levels on this page', 'equalfix' ); ?></button>
							</form>
							<p class="eqf-row-note"><?php esc_html_e( 'This corrects every skipped level on the page, one after another. The headings look the same — their outline levels change, which is how screen readers navigate the page.', 'equalfix' ); ?></p>
							<?php
							$this->render_guidance_toggle( $finding, $meta, false, $occ );
						} else {
						?>
						<p class="eqf-row-note"><?php esc_html_e( 'Extra fixes is on — it already corrected the first skipped heading on this page. The set corrects one skipped level per page; a second one needs the heading levels changed in the page itself, so this one stays a manual edit.', 'equalfix' ); ?></p>
						<?php
						$this->render_guidance_toggle( $finding, $meta, false, $occ );
						}
					} else {
						?>
						<a class="button button-primary" href="<?php echo esc_url( $ctx['fixb_url'] ); ?>"><?php esc_html_e( 'Turn on this fix', 'equalfix' ); ?></a>
						<?php
					}
				} elseif ( ! empty( $pro['present'] ) ) {
					?>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a>
					<?php $this->render_guidance_toggle( $finding, $meta, false, $occ );
				} else {
					?>
					<div class="eqf-upsell">
						<p><?php esc_html_e( 'Included in Pro: heading order fixed automatically, plus monitoring and reports.', 'equalfix' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a>
					</div>
					<?php
					$this->render_guidance_toggle( $finding, $meta, false, $occ );
				}
				break;
			case 'draft':
				if ( null !== $skip ) {
					// Stage 6 (decision D2): generation already
					// tried this finding and could not draft it —
					// the per-card AI button would just re-run the
					// same derivation that already failed, so it
					// is NOT offered again here (no retry loop).
					// The owner's own words become the primary,
					// pre-expanded action; group-level generation
					// remains the retry path.
					$this->render_write_toggle( $finding, $meta, $ctx, true, $occ, true );
					if ( 'alt' === $meta['manual_kind'] ) {
						$this->render_decorative_toggle( $finding, $ctx, $occ );
					}
					break;
				}
				if ( ! empty( $pro['licence_active'] ) ) {
					// Redesign: when the card head already
					// carries this finding's draft form (the
					// group's only unattempted finding), the
					// row does not repeat the button.
					if ( empty( $ctx['head_draft_fp'] ) || $ctx['head_draft_fp'] !== $fp ) {
						$labels = array(
							'alt'   => __( 'Write it with AI', 'equalfix' ),
							'label' => __( 'Suggest a label with AI', 'equalfix' ),
							'name'  => __( 'Draft a name with AI', 'equalfix' ),
						);
						?>
						<form method="post" action="" class="eqf-row-primary">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="draft_with_ai">
							<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
							<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
							<button type="submit" class="button button-primary"><?php echo esc_html( $labels[ $meta['draft_kind'] ] ); ?></button>
						</form>
						<?php
					}
				} elseif ( ! empty( $pro['present'] ) ) {
					?>
					<p class="eqf-upsell-line"><?php esc_html_e( 'Drafting is a Pro feature — add your licence key to switch it on. Your own words below work either way.', 'equalfix' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a>
					<?php
				} else {
					?>
					<div class="eqf-upsell">
						<p><?php esc_html_e( 'Pro’s AI Vision can draft this for you — you approve every word.', 'equalfix' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a>
					</div>
					<?php
				}
				$this->render_write_toggle( $finding, $meta, $ctx, false, $occ );
				if ( 'alt' === $meta['manual_kind'] ) {
					$this->render_decorative_toggle( $finding, $ctx, $occ );
				}
				break;
			case 'manual-first':
				$this->render_write_toggle( $finding, $meta, $ctx, true, $occ );
				if ( 'alt' === $meta['manual_kind'] ) {
					$this->render_decorative_toggle( $finding, $ctx, $occ );
				}
				break;
			case 'guidance':
				// Fix-First Wave 1: a guidance group with a
				// computed proposal gets the proposal panel —
				// the fix, not a checklist. Groups without a
				// proposal keep the honest checklist. Waves
				// 3+4: page-title and media findings get their
				// own input panels (the fix is owner-supplied
				// there, not computed).
				if ( 'page-title' === $meta['group'] && null !== $this->pagetitle_proposal_for_finding( $finding ) ) {
					$this->render_pagetitle_panel( $finding, $meta, $ctx, $occ );
				} elseif ( in_array( $meta['group'], array( 'media-video', 'media-audio' ), true ) ) {
					$this->render_insert_panel( $finding, $meta, $ctx, $occ );
				} elseif ( null !== $this->attr_proposal_for_finding( $finding ) ) {
					$this->render_attr_proposal_panel( $finding, $meta, $ctx, $occ );
				} else {
					$this->render_guidance_toggle( $finding, $meta, true, $occ );
				}
				break;
			// The old 'embed' case (and its "Only the embed
			// provider can fix inside their code" note) is gone
			// in Fix-First Wave 2: embed findings are manual-first
			// now — the note was about the frame's contents and
			// misled on the title finding, which is the owner's
			// own page and fixable.
		}
		?>
		</div>
		<?php
	}

	/**
	 * The Waves 3+4 media input panel: the owner supplies the
	 * captions file (video) or a transcript (video or audio).
	 * Every field name here is read by save_insert_rule_action()
	 * — the pair is probe-exercised through the real handler.
	 * No content is ever prefilled or suggested: the plugin
	 * delivers the owner's words, it does not write them.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param array $ctx     Render context.
	 * @param array $occ     Occurrence info { n, of, idx }.
	 */
	private function render_insert_panel( $finding, $meta, $ctx, $occ = array( 'n' => 1 ) ) {
		unset( $occ );
		$fp     = self::finding_fingerprint( $finding );
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		$pages  = array( self::manual_page_pattern( $finding['url'] ) );
		$is_video = 'media-video' === $meta['group'];
		$track_key = $is_video ? self::insert_rule_key( $meta['group'], 'track', $target, $pages ) : '';
		$transcript_key = self::insert_rule_key( $meta['group'], 'transcript', $target, $pages );
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$lang   = strtolower( str_replace( '_', '-', strtok( $locale, '_' ) ) );
		?>
		<div class="eqf-proposal">
			<?php if ( $is_video ) : ?>
				<p class="eqf-proposal-line"><?php esc_html_e( 'Add a captions file for this video — the written version of what is said, saved as a .vtt file.', 'equalfix' ); ?></p>
				<?php if ( self::manual_cap_blocks( 'insert', $track_key ) ) : ?>
					<p class="eqf-upsell-line"><?php echo esc_html( self::manual_cap_message() ); ?></p>
				<?php else : ?>
					<form method="post" action="" class="eqf-row-primary">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="save_insert_rule">
						<input type="hidden" name="equalfix_insert_kind" value="track">
						<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
						<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
						<p>
							<label for="eqf-track-url-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Captions file address (.vtt)', 'equalfix' ); ?></label><br>
							<input type="url" class="regular-text" id="eqf-track-url-<?php echo esc_attr( $fp ); ?>" name="equalfix_track_url" placeholder="https://…" required>
						</p>
						<p>
							<label for="eqf-track-label-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Caption label (shown in the player)', 'equalfix' ); ?></label><br>
							<input type="text" id="eqf-track-label-<?php echo esc_attr( $fp ); ?>" name="equalfix_track_label" value="Captions" maxlength="100">
							<label for="eqf-track-lang-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Language', 'equalfix' ); ?></label>
							<input type="text" id="eqf-track-lang-<?php echo esc_attr( $fp ); ?>" name="equalfix_track_lang" value="<?php echo esc_attr( '' !== $lang ? $lang : 'en' ); ?>" size="5" maxlength="12">
						</p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add the captions track', 'equalfix' ); ?></button>
					</form>
				<?php endif; ?>
				<details class="eqf-insert-alt">
					<summary><?php esc_html_e( 'Or add a transcript instead', 'equalfix' ); ?></summary>
					<?php $this->render_transcript_forms( $finding, $meta, $ctx, $transcript_key ); ?>
				</details>
			<?php else : ?>
				<p class="eqf-proposal-line"><?php esc_html_e( 'Add a transcript for this audio — the written version of what is said. It appears right after the player on the page.', 'equalfix' ); ?></p>
				<?php $this->render_transcript_forms( $finding, $meta, $ctx, $transcript_key ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The transcript half of the media panel: paste the text,
	 * or link a page that holds it. Two small forms (one per
	 * mode) so no script decides what gets posted.
	 *
	 * @param array  $finding       Finding.
	 * @param array  $meta          Job meta.
	 * @param array  $ctx           Render context.
	 * @param string $transcript_key Prospective store key (cap check).
	 */
	private function render_transcript_forms( $finding, $meta, $ctx, $transcript_key ) {
		unset( $meta );
		$fp = self::finding_fingerprint( $finding );
		if ( self::manual_cap_blocks( 'insert', $transcript_key ) ) {
			?>
			<p class="eqf-upsell-line"><?php echo esc_html( self::manual_cap_message() ); ?></p>
			<?php
			return;
		}
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="save_insert_rule">
			<input type="hidden" name="equalfix_insert_kind" value="transcript">
			<input type="hidden" name="equalfix_transcript_mode" value="text">
			<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
			<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
			<p>
				<label for="eqf-transcript-text-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Transcript text', 'equalfix' ); ?></label><br>
				<textarea class="large-text" rows="5" id="eqf-transcript-text-<?php echo esc_attr( $fp ); ?>" name="equalfix_transcript_text" maxlength="20000"></textarea>
			</p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add this transcript', 'equalfix' ); ?></button>
		</form>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="save_insert_rule">
			<input type="hidden" name="equalfix_insert_kind" value="transcript">
			<input type="hidden" name="equalfix_transcript_mode" value="link">
			<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
			<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
			<p>
				<label for="eqf-transcript-url-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Or link a page that holds the transcript', 'equalfix' ); ?></label><br>
				<input type="url" class="regular-text" id="eqf-transcript-url-<?php echo esc_attr( $fp ); ?>" name="equalfix_transcript_url" placeholder="https://…">
			</p>
			<button type="submit" class="button"><?php esc_html_e( 'Link the transcript page', 'equalfix' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The Waves 3+4 page-title panel: the proposed title (the
	 * page's own post title, or the site name), editable, with
	 * the delivery terms stated plainly — it fills the title
	 * only while the page has none, and a real title (yours
	 * or an SEO plugin's) always wins.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param array $ctx     Render context.
	 * @param array $occ     Occurrence info { n, of, idx }.
	 */
	private function render_pagetitle_panel( $finding, $meta, $ctx, $occ = array( 'n' => 1 ) ) {
		unset( $meta, $occ );
		$proposal = $this->pagetitle_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			return;
		}
		$fp     = self::finding_fingerprint( $finding );
		$key    = md5( 'pagetitle|' . self::manual_page_pattern( $finding['url'] ) );
		$capped = self::manual_cap_blocks( 'pagetitle', $key );
		?>
		<div class="eqf-proposal">
			<p class="eqf-proposal-line"><?php esc_html_e( 'This page is served with no title at all — browser tabs and screen readers have no name for it.', 'equalfix' ); ?></p>
			<?php if ( $capped ) : ?>
				<p class="eqf-upsell-line"><?php echo esc_html( self::manual_cap_message() ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="" class="eqf-row-primary">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="save_pagetitle_rule">
					<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
					<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
					<p>
						<label for="eqf-pagetitle-text-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Page title', 'equalfix' ); ?></label><br>
						<input type="text" class="regular-text" id="eqf-pagetitle-text-<?php echo esc_attr( $fp ); ?>" name="equalfix_pagetitle_text" value="<?php echo esc_attr( $proposal['title'] ); ?>" maxlength="300" required>
					</p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Set this page title', 'equalfix' ); ?></button>
				</form>
				<p class="eqf-row-note"><?php esc_html_e( 'This title fills in only while the page itself has no title. If the page — or an SEO plugin — ever sets a real one, that title wins and this one steps aside.', 'equalfix' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Fix-First proposal panel (Wave 1): the computed fix
	 * shown before → after, one Apply press to save it as an
	 * attribute rule. Contrast gets its colour treatment —
	 * old chip → new chip, old ratio → new ratio, threshold.
	 * The proposal is recomputed server-side on save; the
	 * form carries only the fingerprint (and, for tabindex,
	 * the owner's choice between the offered values).
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param array $ctx     Render context.
	 * @param array $occ     Occurrence info { n, of, idx }.
	 */
	private function render_attr_proposal_panel( $finding, $meta, $ctx, $occ = array( 'n' => 1 ) ) {
		$proposal = $this->attr_proposal_for_finding( $finding );
		if ( null === $proposal ) {
			return;
		}
		$fp     = self::finding_fingerprint( $finding );
		$key    = self::attr_rule_key( $proposal['group'], self::attr_target_for_proposal( $finding, $proposal ), array( self::manual_page_pattern( $finding['url'] ) ) );
		$capped = self::manual_cap_blocks( 'attr', $key );
		$notes  = array(
			'heading-empty'     => __( 'Applying this hides the empty heading — it leaves the page and the heading outline. Nothing else changes.', 'equalfix' ),
			'aria-hidden-focus' => __( 'For hidden duplicates, sliders and popups: the control stays hidden, and keyboards skip it instead of landing on something invisible.', 'equalfix' ),
			'viewport'          => __( 'Visitors can zoom the page again — nothing else in the tag changes.', 'equalfix' ),
			'aria-ref'          => __( 'Only the references pointing nowhere are dropped; the ones that work stay.', 'equalfix' ),
			'autocomplete'      => __( 'Browsers can offer the visitor’s saved detail for this field.', 'equalfix' ),
			'tabindex'          => __( 'The element joins the tab order in its place on the page.', 'equalfix' ),
		);
		?>
		<div class="eqf-proposal">
			<?php if ( 'contrast' === $proposal['group'] ) : ?>
				<p class="eqf-proposal-colors">
					<span class="eqf-swatch" style="background:<?php echo esc_attr( $proposal['fg'] ); ?>"></span> <?php echo esc_html( $proposal['fg'] ); ?>
					<span class="eqf-proposal-arrow" aria-hidden="true">→</span>
					<span class="eqf-swatch" style="background:<?php echo esc_attr( $proposal['new_color'] ); ?>"></span> <strong><?php echo esc_html( $proposal['new_color'] ); ?></strong>
				</p>
				<p class="eqf-proposal-line"><?php
					echo esc_html( sprintf(
						/* translators: 1: current ratio (or a note), 2: new ratio, 3: required ratio */
						__( 'Contrast %1$s → %2$s (needs %3$s%4$s)', 'equalfix' ),
						null !== $proposal['old_ratio'] ? number_format_i18n( (float) $proposal['old_ratio'], 2 ) . ':1' : __( 'too low', 'equalfix' ),
						number_format_i18n( (float) $proposal['new_ratio'], 2 ) . ':1',
						number_format_i18n( (float) $proposal['need'], 1 ) . ':1',
						! empty( $proposal['large'] ) ? __( ' for large text', 'equalfix' ) : ''
					) );
				?></p>
				<p class="eqf-row-note"><?php esc_html_e( 'Only the text colour changes — the nearest shade of the same colour that passes.', 'equalfix' ); ?></p>
			<?php elseif ( in_array( $proposal['group'], array( 'link-generic', 'dup-id', 'interactive' ), true ) ) : ?>
				<?php // Waves 3+4: these proposals read as sentences, not attribute dumps — a rename's op carries no literal value, and the kit's marker is an internal detail. ?>
				<p class="eqf-proposal-line"><?php echo esc_html( $proposal['before'] ); ?></p>
				<p class="eqf-proposal-line"><?php echo esc_html( $proposal['after'] ); ?></p>
				<?php if ( 'dup-id' === $proposal['group'] ) : ?>
					<p class="eqf-row-note"><?php esc_html_e( 'One caveat: if the site’s stylesheet styles this id, that styling covered every copy — after the rename it covers the first copy only.', 'equalfix' ); ?></p>
				<?php elseif ( 'interactive' === $proposal['group'] ) : ?>
					<p class="eqf-row-note"><?php esc_html_e( 'Enter and Space will work like a click on this element. The kit applies in full or not at all — if the element has picked up a role, a tabindex or a wrapped control since the check, nothing is stamped.', 'equalfix' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php foreach ( $proposal['ops'] as $op ) : ?>
					<p class="eqf-proposal-line"><code><?php echo esc_html( $op['attr'] ); ?></code>:
						<?php if ( isset( $op['before'] ) && '' !== (string) $op['before'] ) : ?><code><?php echo esc_html( (string) $op['before'] ); ?></code> <span class="eqf-proposal-arrow" aria-hidden="true">→</span> <?php endif; ?><code><?php echo esc_html( '' === (string) $op['value'] ? '(emptied)' : (string) $op['value'] ); ?></code></p>
				<?php endforeach; ?>
				<?php if ( isset( $notes[ $proposal['group'] ] ) ) : ?>
					<p class="eqf-row-note"><?php echo esc_html( $notes[ $proposal['group'] ] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $capped ) : ?>
				<p class="eqf-upsell-line"><?php echo esc_html( self::manual_cap_message() ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="" class="eqf-row-primary">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="save_attr_rule">
					<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
					<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
					<?php if ( 'link-generic' === $proposal['group'] ) : ?>
						<p>
							<label for="eqf-attr-text-<?php echo esc_attr( $fp ); ?>"><?php esc_html_e( 'Name screen readers announce', 'equalfix' ); ?></label><br>
							<input type="text" class="regular-text" id="eqf-attr-text-<?php echo esc_attr( $fp ); ?>" name="equalfix_attr_text" value="<?php echo esc_attr( isset( $proposal['text_value'] ) ? $proposal['text_value'] : '' ); ?>" maxlength="200" <?php echo ! empty( $proposal['needs_text'] ) ? 'required' : ''; ?>>
						</p>
						<?php if ( ! empty( $proposal['needs_text'] ) ) : ?>
							<p class="eqf-row-note"><?php esc_html_e( 'Nothing on the site says what this link leads to, so the words are yours to write — a few words naming the destination.', 'equalfix' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply this fix', 'equalfix' ); ?></button>
				</form>
				<?php if ( ! empty( $proposal['choices'] ) && count( $proposal['choices'] ) > 1 ) : ?>
					<form method="post" action="" class="eqf-proposal-alt">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="save_attr_rule">
						<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
						<input type="hidden" name="equalfix_attr_value" value="<?php echo esc_attr( $proposal['choices'][1] ); ?>">
						<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
						<button type="submit" class="button-link"><?php esc_html_e( 'Use -1 instead — keep it clickable, but out of the tab order', 'equalfix' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The "write it myself" toggle + inline form (Direction A):
	 * one labelled input, saved to the free manual store by
	 * save_manual_text. Once the free hand-written cap (DH-3)
	 * is reached, the panel shows the locked upgrade state
	 * instead of the form (the handler enforces the cap too).
	 *
	 * @param array $finding    Finding.
	 * @param array $meta       Job meta.
	 * @param array $ctx        Render context.
	 * @param bool  $primary    Render the toggle as the primary button.
	 * @param array $occ        Occurrence info { n, of, idx } — the panel
	 *                          and input ids carry the occurrence number
	 *                          so identical elements never collide.
	 * @param bool  $force_open Render the panel already open (skipped
	 *                          findings: the owner's words are the action).
	 */
	private function render_write_toggle( $finding, $meta, $ctx, $primary, $occ = array( 'n' => 1 ), $force_open = false ) {
		$kind   = $meta['manual_kind'];
		if ( null === $kind ) {
			return;
		}
		$fp     = self::finding_fingerprint( $finding );
		// F-6b: the "name" action label follows the ELEMENT,
		// read from the finding's own title ("Link …" /
		// "Button …" / "Control …"). The old test keyed on
		// the name-button GROUP only, so symbol-named buttons
		// (group name-symbol) were offered "Name this link"
		// on a <button> element.
		$name_title = isset( $finding['title'] ) ? (string) $finding['title'] : '';
		if ( 0 === strpos( $name_title, 'Button' ) ) {
			$name_label = __( 'Name this button', 'equalfix' );
		} elseif ( 0 === strpos( $name_title, 'Control' ) ) {
			$name_label = __( 'Name this control', 'equalfix' );
		} else {
			$name_label = __( 'Name this link', 'equalfix' );
		}
		$labels = array(
			'alt'   => __( 'Write the description', 'equalfix' ),
			'label' => __( 'Add the label', 'equalfix' ),
			'name'  => $name_label,
			'title' => __( 'Add a title', 'equalfix' ),
		);
		if ( 'alt-filename' === $meta['group'] ) {
			$labels['alt'] = __( 'Write a real description', 'equalfix' );
		}
		$fields = array(
			'alt'   => array( __( 'Description (alt text)', 'equalfix' ), __( 'e.g. Team packing orders in the warehouse', 'equalfix' ) ),
			'label' => array( __( 'Label', 'equalfix' ), __( 'e.g. Email address', 'equalfix' ) ),
			'name'  => array( __( 'Name', 'equalfix' ), __( 'e.g. Open the menu', 'equalfix' ) ),
			'title' => array( __( 'Frame title', 'equalfix' ), __( 'e.g. Product demo video', 'equalfix' ) ),
		);
		$occ_n    = isset( $occ['n'] ) ? (int) $occ['n'] : 1;
		$panel_id = 'eqf-write-' . $fp . '-' . $occ_n;
		$input_id = 'eqf-text-' . $fp . '-' . $occ_n;
		$capped   = self::manual_cap_blocks( $kind, self::manual_key_for_finding( $kind, $finding ) );
		// Fix-First Wave 2: embed and symbol-name groups open
		// with the computed proposal already in the box —
		// accept it as-is or edit the words before saving.
		$prefill = self::manual_prefill_for_finding( $finding, $meta );
		?>
		<button type="button" class="<?php echo $primary ? 'button button-primary' : 'button-link eqf-quiet-toggle'; ?> eqf-toggle" aria-expanded="<?php echo $force_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php echo esc_html( $labels[ $kind ] ); ?></button>
		<div class="eqf-write" id="<?php echo esc_attr( $panel_id ); ?>"<?php echo $force_open ? '' : ' hidden'; ?>>
			<?php if ( $capped ) : ?>
				<p class="eqf-upsell-line"><?php echo esc_html( self::manual_cap_message() ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a></p>
			<?php else : ?>
			<form method="post" action="" class="eqf-write-form">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="save_manual_text">
				<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
				<input type="hidden" name="equalfix_manual_kind" value="<?php echo esc_attr( $kind ); ?>">
				<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
				<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $fields[ $kind ][0] ); ?></label>
				<input type="text" id="<?php echo esc_attr( $input_id ); ?>" name="equalfix_manual_text" value="<?php echo esc_attr( $prefill ); ?>" placeholder="<?php echo esc_attr( $fields[ $kind ][1] ); ?>" maxlength="300">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and apply', 'equalfix' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The "It's just decorative" toggle + confirm panel
	 * (Direction A): a fix with its own confirmation, never a
	 * dismissal reason.
	 *
	 * @param array $finding Finding.
	 * @param array $ctx     Render context.
	 * @param array $occ     Occurrence info { n, of, idx }.
	 */
	private function render_decorative_toggle( $finding, $ctx, $occ = array( 'n' => 1 ) ) {
		$fp       = self::finding_fingerprint( $finding );
		$panel_id = 'eqf-deco-' . $fp . '-' . ( isset( $occ['n'] ) ? (int) $occ['n'] : 1 );
		?>
		<button type="button" class="button-link eqf-deco-link eqf-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'It’s just decorative', 'equalfix' ); ?></button>
		<div class="eqf-deco" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<p><?php esc_html_e( 'If this image is purely decorative, screen readers should skip it (alt="").', 'equalfix' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="mark_decorative">
				<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
				<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Yes — it’s decorative', 'equalfix' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * The guidance toggle: "Show me how to fix it" + honest,
	 * rule-specific steps. Guidance rows never pretend a fix
	 * exists.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param bool  $primary Render as the primary button.
	 * @param array $occ     Occurrence info { n, of, idx }.
	 */
	private function render_guidance_toggle( $finding, $meta, $primary = false, $occ = array( 'n' => 1 ) ) {
		if ( empty( $meta['steps'] ) ) {
			return;
		}
		$fp       = self::finding_fingerprint( $finding );
		$panel_id = 'eqf-guide-' . $fp . '-' . ( isset( $occ['n'] ) ? (int) $occ['n'] : 1 );
		?>
		<button type="button" class="<?php echo $primary ? 'button button-primary' : 'button-link eqf-quiet-toggle'; ?> eqf-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Show me how to fix it', 'equalfix' ); ?></button>
		<div class="eqf-guide" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<ol class="eqf-guide-steps">
				<?php foreach ( $meta['steps'] as $step ) : ?>
					<li><?php echo esc_html( $step ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * The demoted dismissal (Direction A): a quiet "Not an
	 * issue?" link opening the inline Set aside panel — plain
	 * reasons as radios, the consequence stated on the panel,
	 * the submit named for what it does.
	 *
	 * @param array $finding Finding.
	 * @param array $ctx     Render context.
	 */
	private function render_set_aside_panel( $finding, $ctx ) {
		$fp = self::finding_fingerprint( $finding );
		?>
		<details class="eqf-setaside">
			<summary><?php esc_html_e( 'Not an issue?', 'equalfix' ); ?></summary>
			<form method="post" class="eqf-setaside-form">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="dismiss_finding">
				<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
				<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
				<p class="eqf-setaside-why"><?php esc_html_e( 'Setting something aside removes it from your counts. You can bring it back any time from the Set aside section below.', 'equalfix' ); ?></p>
				<?php $first = true; ?>
				<?php foreach ( self::dismissal_reasons() as $slug => $label ) : ?>
					<label class="eqf-setaside-reason"><input type="radio" name="equalfix_reason" value="<?php echo esc_attr( $slug ); ?>"<?php echo $first ? ' checked' : ''; ?>> <?php echo esc_html( $label ); ?></label>
					<?php $first = false; ?>
				<?php endforeach; ?>
				<input type="text" name="equalfix_note" maxlength="140" placeholder="<?php esc_attr_e( 'Note (optional)', 'equalfix' ); ?>" aria-label="<?php esc_attr_e( 'Optional note', 'equalfix' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Set aside', 'equalfix' ); ?></button>
			</form>
		</details>
		<?php
	}

	/**
	 * The dismissed-findings record (1.2): every dismissal with
	 * its logged reason, note, and date, each restorable in one
	 * click. Rendered in the Issues Set aside view; empty = silent.
	 */
	private function render_dismissed_section() {
		$map = self::dismissed_map();
		if ( empty( $map ) ) {
			return;
		}
		$reasons = self::dismissal_reasons();
		?>
		<h3><?php echo esc_html( sprintf( __( 'Set aside (%d)', 'equalfix' ), count( $map ) ) ); ?></h3>
		<p class="description"><?php esc_html_e( 'Things you set aside stay out of your counts, across audits of the same issue. Bring one back and it returns to your list the next time it is found.', 'equalfix' ); ?></p>
		<div class="equalfix-findings">
			<?php foreach ( $map as $fp => $rec ) : ?>
				<article class="eqf-finding eqf-dismissed-row">
					<strong class="eqf-finding-title"><?php echo esc_html( $this->finding_job_meta( array( 'title' => $rec['title'], 'category' => isset( $rec['category'] ) ? $rec['category'] : '', 'explanation' => '', 'url' => $rec['url'], 'snippet' => '' ) )['name'] ); ?></strong>
					<span class="eqf-dismissed-meta">
						<a href="<?php echo esc_url( $rec['url'] ); ?>"><?php echo esc_html( self::page_label( $rec['url'] ) ); ?></a> ·
						<?php echo esc_html( sprintf( __( 'Reason: %s', 'equalfix' ), isset( $reasons[ $rec['reason'] ] ) ? $reasons[ $rec['reason'] ] : $rec['reason'] ) ); ?> ·
						<?php echo esc_html( sprintf( __( 'Set aside %s', 'equalfix' ), $rec['time'] ) ); ?>
						<?php if ( '' !== $rec['note'] ) : ?> · <?php echo esc_html( sprintf( __( 'Note: %s', 'equalfix' ), $rec['note'] ) ); ?><?php endif; ?>
					</span>
					<form method="post" class="eqf-restore">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="restore_finding">
						<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
						<button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'equalfix' ); ?></button>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The "Pages in this audit" card (final touch-up): the
	 * Overview's "Pages scanned" tile links here. One row per
	 * scanned page — title, URL, how many findings the page
	 * has and how many of those the coverage model counts as
	 * fixed — collapsed by default so it never pushes the
	 * findings down. Counts come from the audit + the model,
	 * exactly like every other surface.
	 *
	 * @param array $result Stored audit result.
	 * @param array $model  A coverage_model() result.
	 */
	private function render_pages_card( $result, $model ) {
		$pages = array();
		if ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) {
			foreach ( $result['pages'] as $page ) {
				if ( ! empty( $page['url'] ) ) {
					$pages[] = $page;
				}
			}
		}
		$per_page = array();
		foreach ( (array) $result['findings'] as $finding ) {
			$u = $finding['url'];
			if ( ! isset( $per_page[ $u ] ) ) {
				$per_page[ $u ] = array( 0, 0 );
			}
			$per_page[ $u ][0]++;
			if ( isset( $model['fixed_fps'][ self::finding_fingerprint( $finding ) ] ) ) {
				$per_page[ $u ][1]++;
			}
		}
		if ( empty( $pages ) ) {
			// Stored audits from before the page list existed:
			// derive the rows from the findings themselves.
			foreach ( $per_page as $u => $pair ) {
				$pages[] = array(
					'url'    => $u,
					'status' => 'ok',
				);
			}
		}
		if ( empty( $pages ) ) {
			return;
		}
		?>
		<details class="equalfix-card eqf-pages-card" id="equalfix-pages">
			<summary><?php echo esc_html( sprintf( __( 'Pages in this audit (%d)', 'equalfix' ), count( $pages ) ) ); ?></summary>
			<ul class="eqf-pages-list">
				<?php foreach ( $pages as $page ) : ?>
					<?php $pair = isset( $per_page[ $page['url'] ] ) ? $per_page[ $page['url'] ] : array( 0, 0 ); ?>
					<li class="eqf-pages-row">
						<a class="eqf-pages-title" href="<?php echo esc_url( $page['url'] ); ?>"><?php echo esc_html( self::page_title_label( $page['url'] ) ); ?></a>
						<span class="eqf-pages-url"><?php echo esc_html( self::page_label( $page['url'] ) ); ?></span>
						<span class="eqf-pages-counts">
							<?php if ( isset( $page['status'] ) && 'fetch_failed' === $page['status'] ) : ?>
								<?php esc_html_e( 'Could not be scanned', 'equalfix' ); ?>
							<?php else : ?>
								<?php echo esc_html( sprintf( _n( '%1$d finding · %2$d fixed', '%1$d findings · %2$d fixed', $pair[0], 'equalfix' ), $pair[0], $pair[1] ) ); ?>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * The show=todo banner + action summary (final touch-up):
	 * the "still to do" list opens by saying what can happen
	 * next — free fixes that are one click away, drafts AI
	 * Vision can write, and the remainder that needs the
	 * owner's own words. Every number is computed over the
	 * model's TO DO set only, and the draftable number is
	 * skip-aware (IA-12): a finding with a Pro skip record
	 * counts toward "need your words", never toward drafts.
	 *
	 * @param array $todo_list The model's TO DO findings.
	 * @param array $settings  Current settings.
	 */
	private function render_todo_banner( $todo_list, $settings ) {
		$todo_result = array( 'findings' => $todo_list );
		$parts       = $this->partition_fix_a( $todo_result, $settings );
		$free_now    = count( $parts[1] );
		// Skip-aware draftable count (IA-12 fix round): the raw
		// queue-eligible total counts every to-do in a draftable
		// category — including findings generation already tried
		// and could not draft (Pro skip records). Promising those
		// as "can be drafted by AI" is exactly backwards (the
		// gt.tc gate state: 11 to-dos, all 11 skip-recorded, and
		// this banner read "11 can be drafted by AI · 0 need your
		// words"). Mirror the accounting render_job_cards() uses
		// ($todo_unskipped, Stage 6 F9): draftable = queue-
		// eligible AND no skip record. Every skip-recorded to-do
		// then lands in $words, and the "drafted for you" action
		// block below stands down when nothing untried remains.
		$skip_map = array();
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$skip_map = EqualFix_Pro_Suggestions::skip_records();
		}
		$unskipped_todo = array();
		foreach ( $todo_list as $todo_finding ) {
			if ( ! self::map_has_finding( $skip_map, $todo_finding ) ) {
				$unskipped_todo[] = $todo_finding;
			}
		}
		$draftable   = $this->queue_eligible_count( $unskipped_todo );
		$words       = max( 0, count( $todo_list ) - $free_now - $draftable );
		$pro         = self::pro_state();
		// Final pass (F-1/F-2): the banner's headline counts
		// DISTINCT problems like every other headline (the
		// family split after it still counts listed findings —
		// it describes the rows and their actions below).
		$banner_ids        = array();
		$banner_review_ids = array();
		foreach ( $todo_list as $banner_finding ) {
			list( $banner_group, $banner_id ) = $this->problem_identity( $banner_finding );
			if ( in_array( $banner_group, self::REVIEW_GROUPS, true ) ) {
				$banner_review_ids[ $banner_id ] = true;
			} else {
				$banner_ids[ $banner_id ] = true;
			}
		}
		$banner_headline = sprintf( _n( 'Still to do: %d problem', 'Still to do: %d problems', count( $banner_ids ), 'equalfix' ), count( $banner_ids ) );
		if ( count( $banner_review_ids ) > 0 ) {
			$banner_headline .= ' ' . sprintf( _n( '· %d for your review', '· %d for your review', count( $banner_review_ids ), 'equalfix' ), count( $banner_review_ids ) );
		}
		$banner_headline .= ' — ' . sprintf( __( '%1$d can be fixed free · %2$d can be drafted by AI · %3$d need your words', 'equalfix' ), $free_now, $draftable, $words );
		?>
		<div class="eqf-todo-banner">
			<strong><?php echo esc_html( $banner_headline ); ?></strong>
			<a href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>"><?php esc_html_e( 'Show everything', 'equalfix' ); ?></a>
		</div>
		<div class="equalfix-card eqf-todo-actions">
			<?php if ( empty( $todo_list ) ) : ?>
				<p><?php esc_html_e( 'Nothing left to do — everything from the last audit is fixed, waiting for your OK, or dismissed.', 'equalfix' ); ?></p>
			<?php endif; ?>
			<?php if ( $free_now > 0 ) : ?>
				<div class="eqf-todo-action">
					<p><?php echo esc_html( sprintf( _n( '%d can be fixed free right now — switch on the free fix set and EqualFix fixes it at render time.', '%d can be fixed free right now — switch on the free fix set and EqualFix fixes them at render time.', $free_now, 'equalfix' ), $free_now ) ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="fix_all_free">
						<?php submit_button( __( 'Fix all free issues', 'equalfix' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
			<?php if ( $draftable > 0 ) : ?>
				<p class="eqf-todo-action"><?php echo esc_html( sprintf( _n( '%d can be drafted for you by AI Vision — you review and approve each draft before anything goes live.', '%d can be drafted for you by AI Vision — you review and approve each draft before anything goes live.', $draftable, 'equalfix' ), $draftable ) ); ?> <a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Review AI suggestions', 'equalfix' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $words > 0 ) : ?>
				<p class="eqf-todo-action"><?php echo esc_html( sprintf( _n( '%d needs your words — no automatic fix or draft exists for it; the finding below explains what to write.', '%d need your words — no automatic fix or draft exists for them; the findings below explain what to write.', $words, 'equalfix' ), $words ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The alt-coverage card (1.2): the missing-alt picture from
	 * the latest audit, with the Pro queue's draft counts read
	 * from its stored queue when Pro is present.
	 *
	 * @param array $result Stored audit result.
	 */
	private function render_alt_coverage_card( $result ) {
		$missing = 0;
		$pages   = array();
		foreach ( (array) $result['findings'] as $finding ) {
			if ( EqualFix_Audit::CAT_REVIEW === $finding['category'] && false !== stripos( $finding['title'], 'missing alt text' ) ) {
				$missing++;
				$pages[ $finding['url'] ] = true;
			}
		}
		$pro    = self::pro_state();
		$ai     = 0;
		$local  = 0;
		if ( $pro['present'] ) {
			// Count only drafts whose finding is in the latest
			// audit (Stage 6 adversarial pass, F2): this card
			// previously counted every pending alt item in
			// storage, so it could claim drafts were waiting
			// while the same page's header — correctly — said
			// zero were waiting for the current findings.
			$items = null;
			if ( class_exists( 'EqualFix_Pro_Suggestions' ) && method_exists( 'EqualFix_Pro_Suggestions', 'split_pending_by_audit' ) ) {
				$items = EqualFix_Pro_Suggestions::split_pending_by_audit()['current'];
			} else {
				$items = (array) get_option( 'equalfix_pro_queue', array() );
			}
			foreach ( $items as $item ) {
				if ( ! isset( $item['status'] ) || 'pending' !== $item['status'] ) {
					continue;
				}
				$kind = isset( $item['kind'] ) && '' !== $item['kind'] ? $item['kind'] : 'alt';
				if ( 'alt' !== $kind ) {
					continue;
				}
				if ( isset( $item['draft_source'] ) && 'ai' === $item['draft_source'] ) {
					$ai++;
				} else {
					$local++;
				}
			}
		}
		?>
		<div class="eqd-card eqf-altcov">
			<div class="eqd-card-head"><h3><?php esc_html_e( 'Alt text coverage — AI Vision', 'equalfix' ); ?></h3></div>
			<?php if ( 0 === $missing ) : ?>
				<p class="eqf-altcov-main"><?php esc_html_e( 'No images missing alt text in the latest audit — every image the audit found already carries alt text.', 'equalfix' ); ?></p>
			<?php else : ?>
				<p class="eqf-altcov-main"><?php echo esc_html( sprintf( _n( '%1$d image is missing alt text, across %2$d page.', '%1$d images are missing alt text, across %2$d pages.', $missing, 'equalfix' ), $missing, count( $pages ) ) ); ?></p>
				<?php if ( $pro['present'] && ( $ai + $local ) > 0 ) : ?>
					<p><?php echo esc_html( sprintf( __( '%1$d draft(s) are waiting in the Pro approval queue (%2$d AI drafts, %3$d local drafts) — approve them and the fixes apply at render.', 'equalfix' ), $ai + $local, $ai, $local ) ); ?> <a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Open the approval queue', 'equalfix' ); ?></a></p>
				<?php elseif ( $pro['present'] ) : ?>
					<p><?php esc_html_e( 'Pro\'s AI Vision can draft alt text for these images — including the ones whose file names say nothing — in its approval queue.', 'equalfix' ); ?> <a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Open the approval queue', 'equalfix' ); ?></a></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Writing alt text for every image by hand is slow. Pro\'s AI Vision drafts it for you — it reads each image in the context of your page — into an approval queue. Nothing is applied until you approve it.', 'equalfix' ); ?> <a href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>"><?php esc_html_e( 'See the Pro screen', 'equalfix' ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Fixed tab (1.2): the persistent record of what is being
	 * fixed right now — the derived fixed section, a per-fix-type
	 * off switch, and Pro's approved-rule record when Pro is
	 * present. The record is derived from the audit ∩ settings
	 * (plus Pro's stored rules), so it cannot drift from reality.
	 *
	 * @param array $result   Stored audit result.
	 * @param array $settings Current settings.
	 */
	private function render_fixed_tab( $result, $settings ) {
		$fixed_list = $this->fixed_findings( $result, $settings );
		// 1.5: Pro's approved alt text is part of this record too,
		// via the coverage model — the same source the Overview
		// counts read, so the two can never disagree.
		$model         = $this->coverage_model( $result, $settings );
		$approved_rows = $model['approved_rows'];
		$rules_stored  = 0;
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$rules_stored = count( EqualFix_Pro_Suggestions::get_rules() ) + count( EqualFix_Pro_Suggestions::get_name_rules() ) + count( EqualFix_Pro_Suggestions::get_label_rules() );
		}
		// "Active" must mean in effect (Stage 6 adversarial minor):
		// stored rules count as active only while the Pro coverage
		// is live (licence active AND the Pro fix scope on) — the
		// same gate the coverage model and the output buffer use.
		$rules_n = 0;
		if ( $rules_stored > 0 && class_exists( 'EqualFix_Pro_Licence' ) && EqualFix_Pro_Licence::is_active()
			&& class_exists( 'EqualFix_Pro_Settings' ) ) {
			// F-3: the master verdict via the shared read.
			$pro_on = method_exists( 'EqualFix_Pro_Settings', 'fix_set_state' ) ? EqualFix_Pro_Settings::fix_set_state()['master_on'] : ! empty( EqualFix_Pro_Settings::get()['enabled'] );
			if ( $pro_on ) {
				$rules_n = $rules_stored;
			}
		}
		// Owner-written fixes (next batch): the fourth source in
		// this record — listed only where the model counts them
		// fixed, with a remove control each.
		$manual_map = array_intersect_key( self::manual_matches_map( $result ), $model['fixed_fps'] );
		// Owner-approved attribute rules (Fix-First, 2026-11-07):
		// the fifth source in this record, on the same terms.
		$attr_map = array_intersect_key( self::attr_matches_map( $result ), $model['fixed_fps'] );
		// Waves 3+4 stores on the same terms again: the media
		// content the owner supplied and the page titles they
		// approved — plus the headings-full approvals, which
		// are page-level and listed from their own store.
		$insert_map    = array_intersect_key( self::insert_matches_map( $result ), $model['fixed_fps'] );
		$pagetitle_map = array_intersect_key( self::pagetitle_matches_map( $result ), $model['fixed_fps'] );
		$headings_full = self::headings_full_pages();
		if ( empty( $fixed_list ) && empty( $approved_rows ) && 0 === $model['fixed'] ) {
			?>
			<div class="equalfix-card">
				<h3><?php esc_html_e( 'Nothing is being fixed right now', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'When fixes are on, every finding they cover is recorded here with its before/after. Switch the free fix set on from the Overview and this tab becomes your fix record.', 'equalfix' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $this->tab_url( 'overview' ) ); ?>"><?php esc_html_e( 'Go to the Overview', 'equalfix' ); ?></a></p>
			</div>
			<?php
		} else {
			// Final touch-up: the tab opens with the answer, not
			// the archive — one summary line in the model's own
			// count, then where those fixes come from. The lists
			// themselves live in the folds below.
			?>
			<div class="equalfix-card eqf-fixed-summary">
				<h3><?php echo esc_html( sprintf( _n( '%d issue is being fixed right now', '%d issues are being fixed right now', $model['fixed'], 'equalfix' ), $model['fixed'] ) ); ?></h3>
				<p class="eqf-fixed-chips">
					<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Free fixes: %d finding', 'Free fixes: %d findings', count( $fixed_list ), 'equalfix' ), count( $fixed_list ) ) ); ?></span>
					<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Alt text approved in Pro: %d', 'Alt texts approved in Pro: %d', count( $approved_rows ), 'equalfix' ), count( $approved_rows ) ) ); ?></span>
					<?php if ( $rules_n > 0 ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Pro approval rule: %d', 'Pro approval rules: %d', $rules_n, 'equalfix' ), $rules_n ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $manual_map ) ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Written by you: %d', 'Written by you: %d', count( $manual_map ), 'equalfix' ), count( $manual_map ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $attr_map ) ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Chosen fix: %d', 'Chosen fixes: %d', count( $attr_map ), 'equalfix' ), count( $attr_map ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $insert_map ) ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Content you added: %d', 'Content you added: %d', count( $insert_map ), 'equalfix' ), count( $insert_map ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $pagetitle_map ) ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Page title set: %d', 'Page titles set: %d', count( $pagetitle_map ), 'equalfix' ), count( $pagetitle_map ) ) ); ?></span>
					<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Why the numbers differ: the headline counts distinct problems from the latest scan, while the approval counts are rules you have kept — one approved rule can fix the same element on every page it appears on, and a kept rule still counts even when the latest scan did not include its element. Neither number is expected to match the other.', 'equalfix' ); ?></p>
			</div>
			<?php
		}
		if ( ! empty( $fixed_list ) ) {
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php esc_html_e( 'See the fixed items', 'equalfix' ); ?></summary>
				<?php $this->render_fixed_section( $result, $fixed_list, $settings, true ); ?>
			</details>
			<?php
			// Per-fix-type off switches: the record's own controls.
			$by_type = array();
			foreach ( $fixed_list as $finding ) {
				$type = $this->finding_fix_type( $finding );
				if ( null !== $type ) {
					$by_type[ $type ] = isset( $by_type[ $type ] ) ? $by_type[ $type ] + 1 : 1;
				}
			}
			$labels = self::fix_types();
			?>
			<div class="equalfix-card">
				<h3><?php esc_html_e( 'Fix types in this record', 'equalfix' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Turning a fix type off restores the original output for every finding it covers, immediately.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-types">
					<?php foreach ( $by_type as $type => $n ) : ?>
						<li>
							<span><?php echo esc_html( sprintf( '%1$s — %2$d fixed', isset( $labels[ $type ] ) ? $labels[ $type ] : $type, $n ) ); ?></span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Turn this fix type off? The original output is restored for all its findings.', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="disable_fix_type">
								<input type="hidden" name="equalfix_fix_type" value="<?php echo esc_attr( $type ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Turn off', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
		if ( ! empty( $approved_rows ) ) {
			// IA batch: the approved-alt list itself lives in
			// Review now (the drafts' home) — this view carries
			// the summary and the way there, not a second copy.
			?>
			<div class="equalfix-card">
				<h3><?php echo esc_html( sprintf( __( 'Alt text you approved (%d)', 'equalfix' ), count( $approved_rows ) ) ); ?></h3>
				<p><?php esc_html_e( 'Alt text you approved in Review, applied at render time like the automatic fixes. The approved wording and dates are listed in Review, with the rest of your approvals.', 'equalfix' ); ?></p>
				<p><a href="<?php echo esc_url( $this->tab_url( 'review' ) ); ?>"><?php esc_html_e( 'See them in Review', 'equalfix' ); ?></a></p>
			</div>
			<?php
		}
		if ( ! empty( $manual_map ) ) {
			$by_fp = array();
			foreach ( (array) $result['findings'] as $f ) {
				$by_fp[ self::finding_fingerprint( $f ) ] = $f;
			}
			$kind_labels = array(
				'alt'   => __( 'Description', 'equalfix' ),
				'label' => __( 'Label', 'equalfix' ),
				'name'  => __( 'Name', 'equalfix' ),
				'title' => __( 'Frame title', 'equalfix' ),
			);
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Fixes you wrote yourself (%d)', 'equalfix' ), count( $manual_map ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'Words you wrote on the Issues tab, applied at render time like the automatic fixes. Removing one restores the original output.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-manual-list">
					<?php foreach ( $manual_map as $fp => $match ) : ?>
						<?php if ( ! isset( $by_fp[ $fp ] ) ) { continue; } ?>
						<?php $f = $by_fp[ $fp ]; ?>
						<li class="eqf-fixed-row eqf-manual-row">
							<?php if ( 'alt' === $match['kind'] ) : ?>
								<?php $src = self::img_src_from_snippet( $f['snippet'] ); $abs = '' !== $src ? EqualFix_Pro_Installer::absolute_url( $src, $f['url'] ) : ''; ?>
								<?php if ( '' !== $abs ) : ?>
									<img class="eqf-approved-thumb" src="<?php echo esc_url( $abs ); ?>" alt="<?php echo esc_attr( $match['text'] ); ?>" width="64" height="64" loading="lazy">
								<?php endif; ?>
							<?php endif; ?>
							<span class="eqf-approved-text">
								<?php echo esc_html( sprintf( __( '%1$s on %2$s', 'equalfix' ), isset( $kind_labels[ $match['kind'] ] ) ? $kind_labels[ $match['kind'] ] : $match['kind'], self::page_title_label( $f['url'] ) ) ); ?>
								<br><span class="eqf-approved-alt"><?php echo $match['decorative'] ? esc_html__( 'Marked decorative (alt="")', 'equalfix' ) : '“' . esc_html( $match['text'] ) . '”'; ?></span>
							</span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this written fix? The original output is restored.', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="remove_manual_rule">
								<input type="hidden" name="equalfix_manual_kind" value="<?php echo esc_attr( $match['kind'] ); ?>">
								<input type="hidden" name="equalfix_manual_key" value="<?php echo esc_attr( $match['key'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		}
		if ( ! empty( $attr_map ) ) {
			$by_fp = array();
			foreach ( (array) $result['findings'] as $f ) {
				$by_fp[ self::finding_fingerprint( $f ) ] = $f;
			}
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Fixes you chose (%d)', 'equalfix' ), count( $attr_map ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'Fixes EqualFix computed and you approved on the Issues tab — a colour, a value, a reference cleanup — applied at render time like the automatic fixes. Removing one restores the original output.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-manual-list">
					<?php foreach ( $attr_map as $fp => $match ) : ?>
						<?php if ( ! isset( $by_fp[ $fp ] ) ) { continue; } ?>
						<?php $f = $by_fp[ $fp ]; ?>
						<li class="eqf-fixed-row eqf-manual-row">
							<span class="eqf-approved-text">
								<?php echo esc_html( sprintf( __( '%1$s on %2$s', 'equalfix' ), $match['label'], self::page_title_label( $f['url'] ) ) ); ?>
							</span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this fix? The original output is restored.', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="remove_attr_rule">
								<input type="hidden" name="equalfix_attr_key" value="<?php echo esc_attr( $match['key'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		}
		if ( ! empty( $insert_map ) ) {
			$by_fp = array();
			foreach ( (array) $result['findings'] as $f ) {
				$by_fp[ self::finding_fingerprint( $f ) ] = $f;
			}
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Content you added (%d)', 'equalfix' ), count( $insert_map ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'Captions files and transcripts you supplied on the Issues tab, added to the page as it loads. Removing one takes the content back off the page.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-manual-list">
					<?php foreach ( $insert_map as $fp => $match ) : ?>
						<?php if ( ! isset( $by_fp[ $fp ] ) ) { continue; } ?>
						<?php $f = $by_fp[ $fp ]; ?>
						<li class="eqf-fixed-row eqf-manual-row">
							<span class="eqf-approved-text">
								<?php echo esc_html( sprintf( __( '%1$s on %2$s', 'equalfix' ), $match['label'], self::page_title_label( $f['url'] ) ) ); ?>
							</span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this content? The media shows without it again.', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="remove_insert_rule">
								<input type="hidden" name="equalfix_insert_key" value="<?php echo esc_attr( $match['key'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		}
		if ( ! empty( $pagetitle_map ) ) {
			$by_fp = array();
			foreach ( (array) $result['findings'] as $f ) {
				$by_fp[ self::finding_fingerprint( $f ) ] = $f;
			}
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Page titles you set (%d)', 'equalfix' ), count( $pagetitle_map ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'Titles you approved for pages that were served with none. Each one fills in only while its page still has no title of its own. Removing one restores the page to having no title.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-manual-list">
					<?php foreach ( $pagetitle_map as $fp => $match ) : ?>
						<?php if ( ! isset( $by_fp[ $fp ] ) ) { continue; } ?>
						<?php $f = $by_fp[ $fp ]; ?>
						<li class="eqf-fixed-row eqf-manual-row">
							<span class="eqf-approved-text">
								<?php echo esc_html( sprintf( __( '%1$s on %2$s', 'equalfix' ), $match['label'], self::page_title_label( $f['url'] ) ) ); ?>
							</span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this page title?', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="remove_pagetitle_rule">
								<input type="hidden" name="equalfix_pagetitle_key" value="<?php echo esc_attr( $match['key'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		}
		if ( ! empty( $headings_full ) ) {
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Pages approved for the full heading correction (%d)', 'equalfix' ), count( $headings_full ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'On these pages, Pro corrects every skipped heading level — not just the first — each time the page loads. Removing an approval returns the page to the standard first-skip correction.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-manual-list">
					<?php foreach ( $headings_full as $hf_key => $hf_entry ) : ?>
						<li class="eqf-fixed-row eqf-manual-row">
							<span class="eqf-approved-text">
								<?php echo esc_html( isset( $hf_entry['label'] ) ? (string) $hf_entry['label'] : (string) $hf_entry['page'] ); ?>
							</span>
							<form method="post" class="eqf-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this approval? The page goes back to the standard first-skip correction.', 'equalfix' ) ); ?>');">
								<?php wp_nonce_field( 'equalfix_settings' ); ?>
								<input type="hidden" name="equalfix_action" value="remove_headings_full">
								<input type="hidden" name="equalfix_headings_key" value="<?php echo esc_attr( $hf_key ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'equalfix' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<?php
		}
		// Pro's approved rules are the other half of the record
		// ($rules_n was computed at the top of this tab — it is
		// also one of the summary chips above).
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$pro = self::pro_state();
			?>
			<div class="equalfix-card">
				<h3><?php esc_html_e( 'Pro approval rules', 'equalfix' ); ?></h3>
				<?php if ( $rules_n > 0 ) : ?>
					<p><?php echo esc_html( sprintf( _n( '%d approved Pro rule is active — alt text, names, and labels you approved in the queue, applied at render.', '%d approved Pro rules are active — alt text, names, and labels you approved in the queue, applied at render.', $rules_n, 'equalfix' ), $rules_n ) ); ?></p>
					<p><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Manage rules in Review', 'equalfix' ); ?></a></p>
				<?php elseif ( $rules_stored > 0 ) : ?>
					<p><?php echo esc_html( sprintf( _n( 'You have %d approved Pro rule, but it is not being applied right now — the Pro licence is not active or the Pro fix scope is paused. Your approvals are kept and apply again as soon as the scope is live.', 'You have %d approved Pro rules, but none is being applied right now — the Pro licence is not active or the Pro fix scope is paused. Your approvals are kept and apply again as soon as the scope is live.', $rules_stored, 'equalfix' ), $rules_stored ) ); ?></p>
					<p><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Manage rules in Review', 'equalfix' ); ?></a></p>
				<?php else : ?>
					<p><?php esc_html_e( 'No approved Pro rules yet. Suggestions you approve in the Pro queue become rules and are recorded here.', 'equalfix' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}
		$this->render_toggle_script();
	}

	/**
	 * The Pro sub-navigation inside the merged shell (1.2):
	 * Pro's own tab labels, hosted by the free dashboard.
	 *
	 * @param string $current Current Pro sub-tab slug.
	 */
	private function render_pro_subnav( $current ) {
		if ( ! class_exists( 'EqualFix_Pro_Settings' ) ) {
			return;
		}
		$tabs = ( new EqualFix_Pro_Settings() )->tabs();
		?>
		<nav class="eqf-subnav" aria-label="<?php esc_attr_e( 'EqualFix Pro sections', 'equalfix' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( $this->tab_url( 'pro-' . $slug ) ); ?>" class="eqf-subnav-link <?php echo $slug === $current ? 'eqf-subnav-on' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render a Pro screen inside the shell (1.2, Workstream B).
	 *
	 * @param string $sub Current Pro sub-tab slug.
	 * @param array  $pro Pro state (see pro_state()).
	 */
	private function render_pro_shell( $sub, $pro ) {
		if ( empty( $pro['shell'] ) || ! class_exists( 'EqualFix_Pro_Settings' ) ) {
			$this->render_pro_tab( $pro );
			return;
		}
		$tabs = ( new EqualFix_Pro_Settings() )->tabs();
		if ( ! isset( $tabs[ $sub ] ) ) {
			// Final touch-up: the shell's landing is Pro's
			// Overview — an unknown sub falls back there, not
			// to Licence, so the sub-nav highlight the free
			// side computes matches what Pro renders.
			$sub = 'overview';
		}
		$this->render_pro_subnav( $sub );
		// Pro's stylesheet scopes its rules to .equalfix-pro-wrap;
		// the shell hosts Pro's panel inside that class so every
		// Pro style applies unchanged in its new home.
		echo '<div class="equalfix-pro-wrap">';
		$settings = new EqualFix_Pro_Settings();
		$settings->render_shell_tab( $sub );
		echo '</div>';
	}

	/**
	 * The Pro tab when Pro's screens are NOT hosted here (1.2):
	 * three honest states — Pro active on an older build (its own
	 * menu still exists), Pro absent (pitch + one-click install),
	 * and the shell case is handled by render_pro_shell().
	 *
	 * @param array $pro Pro state (see pro_state()).
	 */
	private function render_pro_tab( $pro ) {
		if ( ! empty( $pro['shell'] ) ) {
			// A bare ?tab=pro visit lands on Pro's Overview
			// (final touch-up); Licence is reached explicitly
			// via ?tab=pro-licence and is unaffected.
			$this->render_pro_shell( 'overview', $pro );
			return;
		}
		if ( $pro['present'] ) {
			?>
			<div class="equalfix-card">
				<h3><?php echo esc_html( sprintf( __( 'EqualFix Pro %s is installed', 'equalfix' ), $pro['version'] ) ); ?></h3>
				<?php if ( $pro['licence_active'] ) : ?>
					<p><strong><?php esc_html_e( 'Pro is active on this site.', 'equalfix' ); ?></strong> <?php echo esc_html( '' !== $pro['tier_label'] ? sprintf( __( 'Plan: %s.', 'equalfix' ), $pro['tier_label'] ) : '' ); ?></p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Pro is installed, but its licence is not active.', 'equalfix' ); ?></strong> <?php esc_html_e( 'Pro features stay off until the licence validates — open Pro to check its status.', 'equalfix' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'This Pro version keeps its own menu and screens. Updating Pro to 1.2 brings its screens into this dashboard as tabs — one product, one place.', 'equalfix' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=equalfix-pro' ) ); ?>"><?php esc_html_e( 'Open Pro', 'equalfix' ); ?></a></p>
			</div>
			<?php
			return;
		}
		$pro_url = apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' );
		?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Need more than the free fixes?', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'The Pro add-on picks up where the free fix set stops — and keeps watching your site after you fix it.', 'equalfix' ); ?></p>
				<ul class="equalfix-pro-features">
					<li><?php esc_html_e( 'Extra fixes: heading order, menu & accordion states, table headers', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'AI-vision alt-text drafts for images no file name can describe', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Approval queue for judgment-call suggestions — nothing changes without your OK', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Scheduled monitoring with emailed summaries', 'equalfix' ); ?></li>
					<li><?php esc_html_e( 'Dated PDF remediation reports — your proof-of-effort record', 'equalfix' ); ?></li>
				</ul>
			</div>
			<div class="equalfix-pro-side">
				<p class="equalfix-pro-price"><span class="equalfix-pro-price-num">$39</span><?php esc_html_e( '/mo', 'equalfix' ); ?></p>
				<p class="equalfix-pro-price-note"><?php esc_html_e( 'One site · $99/mo for 10 sites · $199/mo for 25 sites', 'equalfix' ); ?><br><?php esc_html_e( 'Annual billing saves 15%', 'equalfix' ); ?></p>
				<p><a href="<?php echo esc_url( $pro_url ); ?>" class="button equalfix-pro-cta" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro pricing', 'equalfix' ); ?></a></p>
			</div>
		</div>

		<div class="equalfix-card">
			<h3><?php esc_html_e( 'Already have a licence key? Install Pro in one click', 'equalfix' ); ?></h3>
			<p><?php esc_html_e( 'Paste the key from your purchase email. EqualFix validates it for this site, downloads Pro from the EqualFix service, and installs and activates it — no manual download, no upload step. Your key is sent only to the EqualFix licence service, only for this install.', 'equalfix' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="install_pro">
				<p>
					<label for="equalfix_pro_key"><?php esc_html_e( 'Licence key', 'equalfix' ); ?></label><br>
					<input type="text" id="equalfix_pro_key" name="equalfix_pro_key" class="regular-text code" autocomplete="off" placeholder="AA-XXXX-XXXX-XXXX-XXXX">
				</p>
				<?php submit_button( __( 'Install Pro', 'equalfix' ), 'primary', 'submit', false ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'Prefer to do it by hand? Download Pro from your EqualFix dashboard and upload it under Plugins → Add New → Upload Plugin — the result is the same.', 'equalfix' ); ?></p>
		</div>
		<?php
	}

	/**
	 * A small stroke icon (inline SVG, no external assets).
	 *
	 * @param string $name Icon name.
	 * @return string SVG markup (fixed safe strings, no user data).
	 */
	private function icon( $name ) {
		$paths = array(
			'check'   => '<circle cx="12" cy="12" r="9"/><path d="M8.2 12.4l2.6 2.6 5-6.2"/>',
			'doc'     => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
			'alert'   => '<path d="M12 4L21.5 20h-19L12 4z"/><path d="M12 10v4.2"/><path d="M12 17.2v.3"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
			'bolt'    => '<path d="M13 2.5L4.5 14H11l-1 7.5L18.5 10H12l1-7.5z"/>',
			'sliders' => '<path d="M5 7h9M18 7h1M5 12h3M12 12h7M5 17h11M20 17h-1"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="17" r="2"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/>',
			'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 3v5h-5"/>',
			'chart'   => '<path d="M4 4v16h16"/><path d="M7 14l4-5 3.5 3L18 7"/>',
			'list'    => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6v.3M4.5 12v.3M4.5 18v.3"/>',
		);
		$inner = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['check'];
		return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	/**
	 * The Run audit form, with its submit feedback script (1.0.2).
	 * Lives in the dashboard hero in every state (1.1 round 2).
	 */
	private function render_audit_form() {
		?>
		<form method="post" action="" id="equalfix-audit-form" class="eqd-audit-form">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="run_audit">
			<?php submit_button( $this->audit_stale ? __( 'Run a fresh scan', 'equalfix' ) : __( 'Run audit', 'equalfix' ), 'primary', 'submit', false ); ?>
			<span id="equalfix-audit-status" class="equalfix-audit-status" style="display:none;margin-left:10px;"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span><?php esc_html_e( 'Auditing your site — scanning your pages now. This usually takes under a minute; keep this tab open.', 'equalfix' ); ?></span>
		</form>
		<script>
		document.getElementById( 'equalfix-audit-form' ).addEventListener( 'submit', function () {
			var btn = this.querySelector( 'input[type="submit"]' );
			if ( btn ) { btn.disabled = true; btn.value = '<?php echo esc_js( __( 'Auditing…', 'equalfix' ) ); ?>'; }
			document.getElementById( 'equalfix-audit-status' ).style.display = 'inline-block';
		} );
		</script>
		<?php
	}

	/**
	 * Dashboard before the first audit: welcome hero + features.
	 */
	private function render_dashboard_empty() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="eqd-hero">
			<div class="eqd-hero-main">
				<h2 class="eqd-hero-title"><?php esc_html_e( 'Welcome to EqualFix', 'equalfix' ); ?></h2>
				<p class="eqd-hero-sub"><?php esc_html_e( 'Run your first audit and EqualFix will scan your public pages for accessibility issues — then fix many of them for you, right here. Nothing on your site changes until you choose to fix it.', 'equalfix' ); ?></p>
				<div class="eqd-hero-actions">
					<?php $this->render_audit_form(); ?>
				</div>
				<p class="eqd-hero-meta"><span class="eqd-meta-chip"><?php echo esc_html( $host ); ?></span><span class="eqd-meta-chip"><?php esc_html_e( 'Scans up to 25 public pages', 'equalfix' ); ?></span><span class="eqd-meta-chip"><?php esc_html_e( 'Usually under a minute', 'equalfix' ); ?></span></p>
			</div>
		</div>
		<?php $this->render_features_card(); ?>
		<?php $this->render_statement_card(); ?>
		<?php
	}

	/**
	 * The accessibility statement card (next batch): generate,
	 * view, and refresh the statement draft — written only from
	 * a real stored audit, saved only as a draft page, with the
	 * out-of-date state named when a newer audit exists.
	 */
	private function render_statement_card() {
		if ( ! class_exists( 'EqualFix_Statement' ) ) {
			return;
		}
		$st  = EqualFix_Statement::status();
		$pro = self::pro_state();
		?>
		<div class="eqd-card eqf-statement-card">
			<div class="eqd-card-head"><h3><?php esc_html_e( 'Accessibility statement', 'equalfix' ); ?></h3></div>
			<?php if ( ! $st['has_audit'] ) : ?>
				<p><?php esc_html_e( 'Run a scan first — the statement is written from a real audit of your site, never from boilerplate.', 'equalfix' ); ?></p>
			<?php elseif ( ! $st['exists'] ) : ?>
				<p><?php esc_html_e( 'Turn your latest audit into an accessibility statement page: what was checked, what was found, what you’re doing about it, and how visitors can report a barrier. It’s saved as a draft — you review, edit, and publish it yourself.', 'equalfix' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="generate_statement">
					<?php submit_button( __( 'Generate statement', 'equalfix' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( __( 'Statement draft created from your audit of %1$s (%2$d findings). It is a draft page — nothing is public until you publish it.', 'equalfix' ), date_i18n( get_option( 'date_format' ), strtotime( $st['generated_at'] ) ), (int) $st['source_total'] ) ); ?></p>
				<?php if ( $st['out_of_date'] ) : ?>
					<p class="eqf-statement-stale">⚠ <?php echo esc_html( sprintf( __( 'Your latest scan found %1$d findings — this statement was written from the earlier scan (%2$d). Refresh it so the page matches your site today.', 'equalfix' ), (int) $st['current_total'], (int) $st['source_total'] ) ); ?></p>
				<?php endif; ?>
				<p class="eqf-statement-actions">
					<?php if ( '' !== $st['edit_url'] ) : ?>
						<a class="button" href="<?php echo esc_url( $st['edit_url'] ); ?>"><?php esc_html_e( 'View draft', 'equalfix' ); ?></a>
					<?php endif; ?>
					<form method="post" action="" class="eqf-inline-form">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="refresh_statement">
						<?php submit_button( __( 'Refresh statement', 'equalfix' ), 'secondary', 'submit', false ); ?>
					</form>
				</p>
				<?php if ( ! empty( $pro['licence_active'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Pro is active: the statement refreshes itself after each new audit, so the draft never drifts from your latest scan.', 'equalfix' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'The contact email in the draft is your WordPress admin email — edit the draft if your public contact differs. The statement describes your checks and your effort; it never claims compliance.', 'equalfix' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The fix console (Stage 6, decision D5): the two explicit
	 * fix actions, side by side, each with live counts and a
	 * full state machine — locked (Pro scope without an active
	 * licence), ready to apply, running (visible steps while the
	 * press is processed), and applied (a persistent receipt with
	 * the live fixed count, the since timestamp, and Pause).
	 * Nothing in either scope applies merely because Pro is
	 * installed or a licence validates: only these presses drive
	 * the master switches, and the existing per-type toggles are
	 * the configuration for what a press includes. The AI
	 * approval queue stays a separate, third step beneath.
	 *
	 * @param array $result      Latest audit result.
	 * @param array $model       Coverage model.
	 * @param array $settings    Free settings.
	 * @param array $pro         Pro state bridge.
	 * @param int   $fixed_a_n   Findings fixed by the free scope.
	 * @param int   $remaining_a Findings the free scope can fix.
	 * @param int   $waiting_n   Drafts waiting for the owner.
	 */
	private function render_fix_console( $result, $model, $settings, $pro, $fixed_a_n, $remaining_a, $waiting_n ) {
		// The ONE on-definition (IA batch): the header pill
		// reads the same helper, so pill and card never disagree.
		$standard_on = $this->standard_scope_on( $settings );

		// Pro scope state, read through Pro's own classes when
		// they exist — never assumed. The COUNTS come from the
		// ONE coverage model (Stage 6.1, F1): the card used to
		// recount only the structural set's own findings, so it
		// read "0 findings fixed" while the scope was gating
		// dozens of approved-rule fixes, and its paused copy
		// claimed nothing needed the set. The model attributes
		// every Pro-fixed finding (set + approved rules) and
		// counts what a paused scope still gates.
		$pro_master_on = false;
		$pro_since     = 0;
		if ( ! empty( $pro['present'] ) && class_exists( 'EqualFix_Pro_Settings' ) ) {
			// F-3: master + activation stamp come from the ONE
			// applied-state read (fix_set_state()) — the same
			// read the front-end gate, the coverage bridge,
			// the guided panel and the Issues rows use, so
			// this card cannot disagree with them.
			if ( method_exists( 'EqualFix_Pro_Settings', 'fix_set_state' ) ) {
				$fix_set       = EqualFix_Pro_Settings::fix_set_state();
				$pro_master_on = $fix_set['master_on'];
				$pro_since     = $fix_set['activated_at'];
			} else {
				$pro_settings  = EqualFix_Pro_Settings::get();
				$pro_master_on = ! empty( $pro_settings['enabled'] );
				$pro_since     = isset( $pro_settings['fix_b_activated_at'] ) ? (int) $pro_settings['fix_b_activated_at'] : 0;
			}
		}
		// Final pass (F-1/F-6a): the console counts DISTINCT
		// problems, and the two cards PARTITION the headline
		// Fixed count by construction — Step 2 takes the
		// Pro-attributed problems (d_fixed_pro), Step 1 takes
		// the rest (the free scope, including owner-written
		// fixes, which ride the free fixer and belonged to
		// NEITHER card in the occurrence model: the live
		// site's 73rd fixed finding). Step 1 + Step 2 =
		// headline Fixed, always.
		$pro_fixed_n   = (int) $model['d_fixed_pro'];
		$pro_rules_n   = (int) $model['d_fixed_pro_rules'];
		$fixed_free_n  = max( 0, (int) $model['d_fixed'] - $pro_fixed_n );
		$pro_would_n   = (int) $model['d_pro_gated_off'];
		$pro_rules_off = (int) $model['d_pro_rules_off'];
		// The standard card's "nothing needs this set" guard
		// gets the same honesty (F1 family): owner-written
		// fixes waiting on the free master count as gated work
		// even when no fix-a finding remains. Distinct form:
		// the model's d_free_gated_off (paused fix types ∪
		// master-gated written rules).
		$std_would_n = (int) $model['d_free_gated_off'];
		$pro_label = class_exists( 'EqualFix_Pro_Settings' ) && method_exists( 'EqualFix_Pro_Settings', 'apply_pro_label' )
			? EqualFix_Pro_Settings::apply_pro_label()
			: __( 'Apply Structural & Semantic Fixes', 'equalfix' );
		$fmt_ts = function ( $ts ) {
			return $ts > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
		};
		?>
		<div class="eqd-console" id="eqf-fix-console">
			<div class="eqd-console-card<?php echo $standard_on ? ' eqd-console-on' : ''; ?>">
				<p class="eqd-console-step"><?php esc_html_e( 'Step 1 · Free', 'equalfix' ); ?></p>
				<h3><?php esc_html_e( 'Standard fixes', 'equalfix' ); ?></h3>
				<?php if ( $standard_on ) : ?>
					<p class="eqd-console-receipt"><span class="eqd-autofix-dot" aria-hidden="true"></span><?php echo esc_html( sprintf( _n( 'On — %d problem fixed', 'On — %d problems fixed', $fixed_free_n, 'equalfix' ), $fixed_free_n ) ); ?><?php echo '' !== $fmt_ts( isset( $settings['fixes_activated_at'] ) ? (int) $settings['fixes_activated_at'] : 0 ) ? esc_html( sprintf( __( ' · since %s', 'equalfix' ), $fmt_ts( (int) $settings['fixes_activated_at'] ) ) ) : ''; ?></p>
					<p class="description"><?php esc_html_e( 'Applied at render time only — nothing is written to your files or content.', 'equalfix' ); ?></p>
					<div class="eqd-console-actions">
						<form method="post" action="">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="pause_standard_fixes">
							<button type="submit" class="button"><?php esc_html_e( 'Pause', 'equalfix' ); ?></button>
						</form>
						<a class="button button-secondary" href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Choose what’s included', 'equalfix' ); ?></a>
					</div>
				<?php else : ?>
					<p><?php echo esc_html( $std_would_n > 0 ? sprintf( _n( '%d problem from your last scan can be fixed by this set.', '%d problems from your last scan can be fixed by this set.', $std_would_n, 'equalfix' ), $std_would_n ) : __( 'Nothing in the last scan needs this set — it still guards every page at render time.', 'equalfix' ) ); ?></p>
					<p class="description"><?php esc_html_e( 'Nothing is applied until you press the button. Fixes happen at render time — no file or content edits.', 'equalfix' ); ?></p>
					<form method="post" action="" class="eqf-apply-form">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="apply_standard_fixes">
						<button type="submit" class="button button-primary button-hero"><?php echo esc_html( self::apply_standard_label() ); ?></button>
					</form>
					<ol class="eqf-apply-progress" hidden>
						<li><?php esc_html_e( 'Switching the standard fixes on…', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Clearing caches so visitors see the fixed pages…', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Rechecking your counts…', 'equalfix' ); ?></li>
					</ol>
					<p class="eqf-apply-error" role="alert" hidden><?php esc_html_e( 'That didn’t go through — nothing was changed. Check your connection and try again.', 'equalfix' ); ?></p>
					<p class="description"><a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Choose what’s included', 'equalfix' ); ?></a></p>
				<?php endif; ?>
			</div>
			<div class="eqd-console-card<?php echo ( ! empty( $pro['licence_active'] ) && $pro_master_on ) ? ' eqd-console-on' : ''; ?><?php echo empty( $pro['licence_active'] ) ? ' eqd-console-locked' : ''; ?>">
				<p class="eqd-console-step"><?php esc_html_e( 'Step 2 · Pro', 'equalfix' ); ?></p>
				<h3><?php esc_html_e( 'Structural & semantic fixes', 'equalfix' ); ?></h3>
				<?php if ( empty( $pro['present'] ) ) : ?>
					<p class="eqd-console-lock"><?php esc_html_e( '🔒 Locked — Pro isn’t installed yet.', 'equalfix' ); ?></p>
					<p><?php esc_html_e( 'Heading order, menu states, table headers, redundant links, and WooCommerce labelling — applied at render, like the standard set.', 'equalfix' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>"><?php esc_html_e( 'See Pro and install', 'equalfix' ); ?></a></p>
				<?php elseif ( empty( $pro['licence_active'] ) ) : ?>
					<p class="eqd-console-lock"><?php esc_html_e( '🔒 Locked — your licence isn’t active.', 'equalfix' ); ?></p>
					<p><?php esc_html_e( 'Activating a licence unlocks this button — it never presses it for you. The fixes start only when you apply them here.', 'equalfix' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-licence' : 'pro' ) ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a></p>
				<?php elseif ( $pro_master_on ) : ?>
					<p class="eqd-console-receipt"><span class="eqd-autofix-dot" aria-hidden="true"></span><?php echo esc_html( sprintf( _n( 'On — %d problem fixed', 'On — %d problems fixed', $pro_fixed_n, 'equalfix' ), $pro_fixed_n ) ); ?><?php echo '' !== $fmt_ts( $pro_since ) ? esc_html( sprintf( __( ' · since %s', 'equalfix' ), $fmt_ts( $pro_since ) ) ) : ''; ?><?php echo esc_html( sprintf( _n( ' · %d problem still to do', ' · %d problems still to do', (int) $model['d_todo'], 'equalfix' ), (int) $model['d_todo'] ) ); ?></p>
					<?php if ( $pro_rules_n > 0 ) : ?>
						<p class="description"><?php echo esc_html( sprintf( _n( '%d of these problems is fixed by a rule you approved in AI suggestions — the rule stays live only while this set is on, and pausing this set pauses it too.', '%d of these problems are fixed by rules you approved in AI suggestions — the rules stay live only while this set is on, and pausing this set pauses them too.', $pro_rules_n, 'equalfix' ), $pro_rules_n ) ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Applied at render time only — nothing is written to your files or content.', 'equalfix' ); ?></p>
					<div class="eqd-console-actions">
						<form method="post" action="">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="pause_pro_fixes">
							<button type="submit" class="button"><?php esc_html_e( 'Pause', 'equalfix' ); ?></button>
						</form>
						<a class="button button-secondary" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-fixb' : 'pro' ) ); ?>"><?php esc_html_e( 'Choose what’s included', 'equalfix' ); ?></a>
					</div>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'You’ve upgraded to Pro.', 'equalfix' ); ?></strong></p>
					<?php if ( $pro_would_n > 0 ) : ?>
						<p><?php echo esc_html( sprintf( _n( '%d problem from your last scan can be fixed by this set.', '%d problems from your last scan can be fixed by this set.', $pro_would_n, 'equalfix' ), $pro_would_n ) ); ?><?php echo $pro_rules_off > 0 ? ' ' . esc_html( sprintf( _n( '%d of them would be fixed by a rule you already approved — it stays off until you apply this set.', '%d of them would be fixed by rules you already approved — they stay off until you apply this set.', $pro_rules_off, 'equalfix' ), $pro_rules_off ) ) : ''; ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing in the last scan needs this set — it still guards every page at render time.', 'equalfix' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Your licence is active, and still nothing is applied — fixes start only when you press the button.', 'equalfix' ); ?></p>
					<form method="post" action="" class="eqf-apply-form">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="apply_pro_fixes">
						<button type="submit" class="button button-primary button-hero"><?php echo esc_html( $pro_label ); ?></button>
					</form>
					<ol class="eqf-apply-progress" hidden>
						<li><?php esc_html_e( 'Switching the Pro fixes on…', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Clearing caches so visitors see the fixed pages…', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Rechecking your counts…', 'equalfix' ); ?></li>
					</ol>
					<p class="eqf-apply-error" role="alert" hidden><?php esc_html_e( 'That didn’t go through — nothing was changed. Check your connection and try again.', 'equalfix' ); ?></p>
					<p class="description"><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-fixb' : 'pro' ) ); ?>"><?php esc_html_e( 'Choose what’s included (Extra fixes)', 'equalfix' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>
		<div class="eqd-console-step3">
			<?php if ( $waiting_n > 0 ) : ?>
				<p><strong><?php esc_html_e( 'Step 3 · AI suggestions', 'equalfix' ); ?></strong> — <?php echo esc_html( sprintf( _n( '%d draft is waiting for your OK.', '%d drafts are waiting for your OK.', $waiting_n, 'equalfix' ), $waiting_n ) ); ?> <a class="button button-primary" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Review AI suggestions', 'equalfix' ); ?></a></p>
			<?php elseif ( ! empty( $pro['licence_active'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Step 3 · AI suggestions', 'equalfix' ); ?></strong> — <?php esc_html_e( 'For the findings no fix set can reach, AI drafts the words and you approve them one by one.', 'equalfix' ); ?> <a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Open AI suggestions', 'equalfix' ); ?></a></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Step 3 · AI suggestions (Pro)', 'equalfix' ); ?></strong> — <?php esc_html_e( 'For the findings no fix set can reach, AI drafts the words and you approve them one by one. Nothing is applied without your OK.', 'equalfix' ); ?></p>
			<?php endif; ?>
		</div>
		<script>
		// Apply progress (Stage 6.1, F5): the press must be
		// VISIBLE while it works. The steps mirror the server's
		// real phases (switch the scope on, purge caches, then
		// the reload re-renders the counts). Progressive
		// enhancement only: without fetch/FormData the form
		// posts exactly as it always did. With JS, the POST runs
		// via fetch so the outcome is known — success holds the
		// steps for a short minimum display (1.2s, so the state
		// is perceptible, not theatrical) and then reloads, so
		// the receipt the owner sees is rendered by the SERVER
		// from the new state; a failed request lands on the
		// card's error line with the button restored, never on
		// a receipt.
		( function () {
			var forms = document.querySelectorAll( '.eqf-apply-form' );
			for ( var i = 0; i < forms.length; i++ ) {
				( function ( form ) {
					form.addEventListener( 'submit', function ( ev ) {
						var card = form.closest( '.eqd-console-card' );
						if ( ! card || ! window.fetch || ! window.FormData ) { return; }
						var steps = card.querySelector( '.eqf-apply-progress' );
						if ( ! steps ) { return; }
						ev.preventDefault();
						if ( form.getAttribute( 'data-eqf-running' ) ) { return; }
						form.setAttribute( 'data-eqf-running', '1' );
						var btn = form.querySelector( 'button[type="submit"]' );
						var err = card.querySelector( '.eqf-apply-error' );
						if ( btn ) { btn.disabled = true; }
						if ( err ) { err.hidden = true; }
						steps.hidden = false;
						var items = steps.querySelectorAll( 'li' );
						var started = Date.now();
						var MIN_MS = 1200;
						if ( items.length ) { items[0].classList.add( 'on' ); }
						var n = 0;
						var timer = setInterval( function () {
							n++;
							if ( n < items.length - 1 ) { items[ n ].classList.add( 'on' ); }
						}, 400 );
						var url = form.getAttribute( 'action' ) || window.location.href;
						window.fetch( url, { method: 'POST', body: new window.FormData( form ), credentials: 'same-origin', redirect: 'follow' } )
							.then( function ( res ) {
								if ( ! res.ok ) { throw new Error( 'http ' + res.status ); }
								return res.text();
							} )
							.then( function () {
								var wait = Math.max( 0, MIN_MS - ( Date.now() - started ) );
								window.setTimeout( function () {
									clearInterval( timer );
									for ( var j = 0; j < items.length; j++ ) { items[ j ].classList.add( 'on' ); }
									window.location.reload();
								}, wait );
							} )
							.catch( function () {
								clearInterval( timer );
								steps.hidden = true;
								if ( err ) { err.hidden = false; }
								if ( btn ) { btn.disabled = false; }
								form.removeAttribute( 'data-eqf-running' );
							} );
					} );
				} )( forms[ i ] );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * The Overview's next-action card (IA batch): exactly one
	 * most important next step, computed from real state — a
	 * stale scan first (nothing else on the page can be
	 * trusted), then waiting drafts, then the items AI could
	 * not draft (they need the owner's words), then any
	 * remaining to-dos; otherwise the all-clear.
	 *
	 * Final pass (F-5): the order changed — an UNPRESSED
	 * Apply that would fix problems now outranks everything
	 * except a stale scan (it is the highest-value click on
	 * the page), and the review pile outranks the
	 * owner-words items (the pre-pass card led with the 32
	 * "AI skipped" items on the live site while better
	 * actions sat below them). All counts are the model's
	 * distinct-problem counts (F-1/F-2).
	 *
	 * @param array $model        A coverage_model() result.
	 * @param array $pro          Pro state.
	 * @param int   $skipped_todo To-do problems with a skip record (distinct).
	 */
	private function render_next_action_card( $model, $pro, $skipped_todo, $limited = false ) {
		$waiting     = (int) $model['d_waiting'];
		$todo        = (int) $model['d_todo'];
		$review      = (int) $model['d_review'];
		$free_would  = (int) $model['d_free_gated_off'];
		$pro_would   = (int) $model['d_pro_gated_off'];
		if ( $this->audit_stale ) {
			$title = __( 'Next step: run a fresh scan', 'equalfix' );
			$text  = __( 'The scan behind these numbers predates the current EqualFix version. A fresh scan takes under a minute and makes everything on this page current.', 'equalfix' );
			$href  = '#equalfix-audit-form';
			$cta   = __( 'Run a fresh scan', 'equalfix' );
		} elseif ( $free_would > 0 || $pro_would > 0 ) {
			$apply_n = $free_would + $pro_would;
			$title   = __( 'Next step: apply the fixes that are ready', 'equalfix' );
			$text    = sprintf( _n( '%d problem can be fixed right now — press Apply on the fix console above and EqualFix fixes it at render time. Nothing else you could do fixes more in one press.', '%d problems can be fixed right now — press Apply on the fix console above and EqualFix fixes them at render time. Nothing else you could do fixes more in one press.', $apply_n, 'equalfix' ), $apply_n );
			$href    = '#eqf-fix-console';
			$cta     = __( 'Go to the fix console', 'equalfix' );
		} elseif ( $waiting > 0 ) {
			$title = __( 'Next step: review the waiting drafts', 'equalfix' );
			$text  = sprintf( _n( '%d AI draft is waiting for your OK. Nothing goes live until you approve it.', '%d AI drafts are waiting for your OK. Nothing goes live until you approve them.', $waiting, 'equalfix' ), $waiting );
			$href  = $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' );
			$cta   = __( 'Review drafts', 'equalfix' );
		} elseif ( $review > 0 ) {
			$title = __( 'Next step: look over the review items', 'equalfix' );
			$text  = sprintf( _n( '%d problem is a judgement call, not a fix task — things like contrast estimates and hidden template controls. Each one in Issues explains what to check, and many will be fine exactly as they are.', '%d problems are judgement calls, not fix tasks — things like contrast estimates and hidden template controls. Each one in Issues explains what to check, and many will be fine exactly as they are.', $review, 'equalfix' ), $review );
			$href  = $this->tab_url( 'issues', array( 'view' => 'todo' ) );
			$cta   = __( 'Review them in Issues', 'equalfix' );
		} elseif ( $skipped_todo > 0 ) {
			$title = __( 'Next step: write the items AI could not draft', 'equalfix' );
			$text  = sprintf( _n( '%d problem was tried by AI and could not be drafted — it needs your words. Each one in the to-do list says exactly what to write.', '%d problems were tried by AI and could not be drafted — they need your words. Each one in the to-do list says exactly what to write.', $skipped_todo, 'equalfix' ), $skipped_todo );
			$href  = $this->tab_url( 'issues', array( 'view' => 'todo' ) );
			$cta   = __( 'Open the to-do list', 'equalfix' );
		} elseif ( $todo > 0 ) {
			$title = __( 'Next step: work through what is still to do', 'equalfix' );
			$text  = sprintf( _n( '%d problem from your last scan still needs attention. The to-do list groups it by job, each with its next action.', '%d problems from your last scan still need attention. The to-do list groups them by job, each with its next action.', $todo, 'equalfix' ), $todo );
			$href  = $this->tab_url( 'issues', array( 'view' => 'todo' ) );
			$cta   = __( 'Open the to-do list', 'equalfix' );
		} elseif ( $limited ) {
			// A limited (walled) scan never earns an "all clear":
			// the pages it reached are done, the rest was never
			// checked (wp21's wall rule).
			$title = __( 'Nothing left on the pages scanned', 'equalfix' );
			$text  = __( 'Everything the last scan reached is fixed or set aside, but that scan was limited, so some pages were never checked. Run a fresh scan to cover the whole site.', 'equalfix' );
			$href  = '#equalfix-audit-form';
			$cta   = __( 'Run a fresh scan', 'equalfix' );
		} else {
			$title = __( 'All clear', 'equalfix' );
			$text  = __( 'Nothing is waiting on you: everything from the last scan is fixed or set aside. Re-run the scan after your next content change.', 'equalfix' );
			$href  = '';
			$cta   = '';
		}
		?>
		<div class="eqd-card eqf-next-action">
			<div class="eqd-card-head"><h3><?php echo esc_html( $title ); ?></h3></div>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( '' !== $cta ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $cta ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Overview's monitoring status line (IA batch; Pro,
	 * licence active only): the schedule and the last run,
	 * linking to the full record in History. Read through
	 * Pro's own classes — never assumed.
	 *
	 * @param array $pro Pro state.
	 */
	private function render_monitoring_line( $pro ) {
		if ( empty( $pro['present'] ) || empty( $pro['licence_active'] ) ) {
			return;
		}
		if ( ! class_exists( 'EqualFix_Pro_Settings' ) || ! class_exists( 'EqualFix_Pro_Monitor' ) ) {
			return;
		}
		$ps       = EqualFix_Pro_Settings::get();
		$schedule = isset( $ps['monitor_schedule'] ) ? (string) $ps['monitor_schedule'] : 'off';
		$history  = EqualFix_Pro_Monitor::get_history();
		$last     = ! empty( $history ) && is_array( $history[0] ) && ! empty( $history[0]['time'] ) ? (string) $history[0]['time'] : '';
		?>
		<p class="eqf-monitoring-line">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: monitoring schedule (weekly/monthly/off), 2: last run description */
					__( 'Monitoring: %1$s · %2$s', 'equalfix' ),
					'off' === $schedule ? __( 'off', 'equalfix' ) : ucfirst( $schedule ),
					'' !== $last ? sprintf( __( 'last run %s', 'equalfix' ), date_i18n( get_option( 'date_format' ), strtotime( $last ) ) ) : __( 'no runs yet', 'equalfix' )
				)
			);
			?>
			<a href="<?php echo esc_url( $this->tab_url( 'history' ) ); ?>"><?php esc_html_e( 'See monitoring history', 'equalfix' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The Review tab (IA batch): Pro's approval queue inside
	 * the shell. With no usable Pro (absent, or a pre-shell
	 * build), the old Pro tab renders instead — the pitch and
	 * the one-click install live there.
	 *
	 * @param array $pro Pro state.
	 */
	private function render_review_tab( $pro ) {
		if ( empty( $pro['shell'] ) || ! class_exists( 'EqualFix_Pro_Settings' ) || ! method_exists( 'EqualFix_Pro_Settings', 'render_review_panel' ) ) {
			$this->render_pro_tab( $pro );
			return;
		}
		?>
		<div class="equalfix-pro-wrap">
			<?php ( new EqualFix_Pro_Settings() )->render_review_panel(); ?>
		</div>
		<?php
	}

	/**
	 * The History tab (IA batch): the record of what happened —
	 * the findings trend, every audit run, Pro's monitoring
	 * runs, the remediation-report download, and the cache
	 * purge log (moved here from its own tab).
	 *
	 * @param array $pro Pro state.
	 */
	private function render_history( $pro ) {
		$result = get_option( self::AUDIT_OPTION, null );
		?>
		<div class="eqd-card eqd-trend-card">
			<div class="eqd-card-head"><h3><?php esc_html_e( 'Issues trend', 'equalfix' ); ?></h3><span class="eqd-legend"><span class="eqd-legend-dot eqd-dot-total"></span><?php esc_html_e( 'Total issues', 'equalfix' ); ?> <span class="eqd-legend-dot eqd-dot-addr"></span><?php esc_html_e( 'Fixable findings', 'equalfix' ); ?></span></div>
			<?php
			if ( is_array( $result ) && ! empty( $result['findings'] ) ) {
				$counts = array( 'fix-a' => 0, 'fix-b' => 0, 'human' => 0 );
				foreach ( (array) $result['findings'] as $finding ) {
					if ( isset( $counts[ $finding['category'] ] ) ) {
						$counts[ $finding['category'] ]++;
					} else {
						$counts['human']++;
					}
				}
				$this->render_trend( $result, $counts, $this->queue_eligible_count( $result['findings'] ) );
			} else {
				?>
				<p><?php esc_html_e( 'No audits yet — the trend appears once you have run a scan.', 'equalfix' ); ?></p>
				<?php
			}
			?>
		</div>
		<div class="eqd-card">
			<div class="eqd-card-head"><h3><?php esc_html_e( 'Audit history', 'equalfix' ); ?></h3></div>
			<?php
			$history = get_option( 'equalfix_audit_history', array() );
			if ( ! is_array( $history ) || empty( $history ) ) :
				?>
				<p><?php esc_html_e( 'No audits recorded yet.', 'equalfix' ); ?></p>
				<?php
			else :
				?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'equalfix' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Findings', 'equalfix' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fixable findings', 'equalfix' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $history ) as $run ) : ?>
							<?php if ( ! is_array( $run ) ) { continue; } ?>
							<tr>
								<td><?php echo esc_html( isset( $run['time'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), is_numeric( $run['time'] ) ? (int) $run['time'] : (int) strtotime( (string) $run['time'] ) ) : '—' ); ?></td>
								<td><?php echo isset( $run['total'] ) ? (int) $run['total'] : '—'; ?></td>
								<td><?php echo (int) ( ( isset( $run['a'] ) ? (int) $run['a'] : 0 ) + ( isset( $run['b'] ) ? (int) $run['b'] : 0 ) + ( isset( $run['q'] ) ? (int) $run['q'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			endif;
			?>
		</div>
		<?php if ( ! empty( $pro['present'] ) && class_exists( 'EqualFix_Pro_Settings' ) ) : ?>
			<div class="equalfix-pro-wrap">
				<div class="eqp-panel eqp-shell">
					<h2><?php esc_html_e( 'Monitoring runs', 'equalfix' ); ?></h2>
					<?php ( new EqualFix_Pro_Settings() )->render_monitor_history_panel(); ?>
				</div>
				<?php if ( ! empty( $pro['licence_active'] ) && method_exists( 'EqualFix_Pro_Settings', 'render_report_panel' ) ) : ?>
					<?php ( new EqualFix_Pro_Settings() )->render_report_panel(); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php $this->render_purge(); ?>
		<?php
	}

	/**
	 * The dashboard (1.1 round 2), modelled on the owner's
	 * reference design: hero with an honest fix-coverage ring,
	 * KPI tiles, a trend chart from stored audit history, top
	 * issues, recent activity from real records, quick actions,
	 * and the features card. Every number is computed from the
	 * latest audit and the stored history — nothing decorative.
	 *
	 * @param array $result         Latest audit result.
	 * @param array $counts         Category counts.
	 * @param int   $queue_eligible Queue-draftable review findings.
	 * @param array $settings       Settings.
	 * @param array $notes          Audit honesty notes.
	 */
	private function render_dashboard( $result, $counts, $queue_eligible, $settings, $notes ) {
		$total       = (int) $result['total'];
		$fixed_list  = $this->fixed_findings( $result, $settings );
		$fixed_a_n   = count( $fixed_list );
		// 1.5: the ONE count model (coverage_model) — the hero,
		// the KPI tiles and the Top issues badges all read these
		// numbers, so a Pro approval moves them with no re-audit.
		$model       = $this->coverage_model( $result, $settings );
		// Final pass (F-1/F-2): the hero, the tiles and the
		// console quote the model's DISTINCT-problem counts —
		// the same problem on eleven pages is one problem.
		// The occurrence counts stay in the model for the
		// Issues lists, which are occurrence-shaped.
		$fixed_n     = $model['d_fixed'];
		$waiting_n   = $model['d_waiting'];
		$todo_n      = $model['d_todo'];
		$review_n    = $model['d_review'];
		// Draftable-and-still-to-do: the only findings the AI
		// Vision clause below may count. The raw queue-eligible
		// total also counts findings Pro already fixed via
		// approved suggestions, which would contradict the
		// model numbers in the same sentence (found on the
		// real-host verification of this batch: 41 of 45 fixed,
		// yet the clause claimed 40 draftable). Counted in
		// distinct problems too (F-1), so the clause speaks
		// the hero's unit.
		$todo_list      = $this->todo_findings( $result, $model );
		$draftable_ids  = array();
		foreach ( $todo_list as $todo_finding ) {
			if ( $this->is_queue_eligible( $todo_finding ) ) {
				list( , $todo_identity ) = $this->problem_identity( $todo_finding );
				$draftable_ids[ $todo_identity ] = true;
			}
		}
		$draftable_todo = count( $draftable_ids );
		// Of the draftable to-dos, how many generation already
		// tried and could not draft (Stage 6 skip records)? The
		// hero sentence must not promise drafts for those. One
		// shared computation (BUG 1, test-4): the Review tab
		// states this same distinct-problem number.
		$skipped_todo = $this->skipped_problem_count( $todo_list );
		$remaining_a = max( 0, (int) $counts['fix-a'] - $fixed_a_n );
		$pro         = self::pro_state();
		// F-4: the coverage ring is recomputed from the SAME
		// distinct counts the headline shows — (fixed +
		// waiting) / total problems — and its label carries
		// the fraction, so anyone can recompute it from this
		// screen. The old "actionable coverage" divided a
		// category-sum by the scan total and matched nothing
		// visible (41% against 73/263 = 27.7% on the live
		// site).
		$coverage    = $model['d_total'] > 0 ? (int) round( 100 * ( $model['d_fixed'] + $model['d_waiting'] ) / $model['d_total'] ) : null;
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$scan_ts    = strtotime( $result['time'] );
		// "Welcome back" only when this genuinely is a return visit:
		// a previous audit exists to come back to (1.1.1 — the first
		// results screen is not a homecoming).
		$history   = get_option( 'equalfix_audit_history', array() );
		$returning = isset( $result['previous_total'] ) || ( is_array( $history ) && count( $history ) > 1 );
		?>
		<p class="eqf-provenance"><?php echo esc_html( sprintf( __( 'Showing your last scan from %1$s · %2$d pages', 'equalfix' ), date_i18n( get_option( 'date_format' ), $scan_ts ), (int) $result['pages_scanned'] ) ); ?><?php if ( $this->audit_stale ) : ?> <span class="eqf-provenance-stale"><?php esc_html_e( 'This scan predates the current EqualFix version — run a fresh scan for results you can trust.', 'equalfix' ); ?></span><?php endif; ?></p>
		<div class="eqd-hero">
			<div class="eqd-hero-main">
				<h2 class="eqd-hero-title"><?php echo esc_html( $returning ? __( 'Welcome back', 'equalfix' ) : __( 'Your audit results', 'equalfix' ) ); ?></h2>
				<p class="eqd-hero-sub">
				<?php
				if ( ! empty( $result['wall'] ) ) {
					echo esc_html( sprintf( /* translators: 1: pages that returned the same page, 2: pages fetched */ __( 'Your last scan was limited: %1$d of the %2$d pages fetched returned exactly the same page, so most of your site was not really checked. Turn off any coming-soon or maintenance mode and run the audit again.', 'equalfix' ), (int) $result['wall']['repeated'], (int) $result['wall']['of'] ) );
				} elseif ( 0 === $total ) {
					echo esc_html( sprintf( __( 'Your last scan checked %d pages and found no issues. Re-run the audit after content changes to keep it that way.', 'equalfix' ), (int) $result['pages_scanned'] ) );
				} else {
					// 1.5: the hero sentence is the count model in
					// plain words — the same numbers as the tiles
					// below, so the page never contradicts itself
					// or the Pro screens. Final pass (F-1/F-2):
					// every number here is a DISTINCT-problem
					// count from the model's d_* layer, and the
					// parts — fixed + waiting + still to do +
					// for your review, plus the set-aside clause
					// — sum EXACTLY to the "found" number. (The
					// pre-pass sentence quoted the scan's raw
					// occurrence total as "found" while its
					// parts counted stored occurrences; with
					// template elements repeated per page that
					// read as 190 tasks on the live site. When
					// the stored findings cap (300) bites, the
					// cap note on this screen discloses that the
					// stored set is capped — the headline counts
					// the stored problems, as the Issues lists
					// do.)
					$found_n  = (int) $model['d_total'] + (int) $model['d_dismissed'];
					$sentence = sprintf( __( 'Your last scan found %1$d problems across %2$d pages: %3$d fixed · %4$d waiting for your OK · %5$d still to do.', 'equalfix' ), $found_n, (int) $result['pages_scanned'], $fixed_n, $waiting_n, $todo_n );
					if ( $review_n > 0 ) {
						$sentence .= ' ' . sprintf( _n( '%d is for your review.', '%d are for your review.', $review_n, 'equalfix' ), $review_n );
					}
					if ( ! empty( $model['d_dismissed'] ) ) {
						// The headline total counts set-aside
						// problems (they were found), while the
						// model's parts exclude them — so the
						// sentence names the set-aside count too,
						// exactly as the Issues intro does, and
						// the parts sum to the headline (loop
						// round 1, J-2; distinct since F-1).
						$sentence .= ' ' . sprintf( _n( '%d is set aside.', '%d are set aside.', (int) $model['d_dismissed'], 'equalfix' ), (int) $model['d_dismissed'] );
					}
					if ( $draftable_todo > 0 && 0 === $waiting_n ) {
						$untried = $draftable_todo - $skipped_todo;
						if ( $untried > 0 ) {
							$sentence .= ' ' . sprintf( _n( '%d of them can be drafted for you by AI Vision — review and approve the drafts in the approval queue.', '%d of them can be drafted for you by AI Vision — review and approve the drafts in the approval queue.', $untried, 'equalfix' ), $untried );
						}
						if ( $skipped_todo > 0 ) {
							$sentence .= ' ' . sprintf( _n( '%d was tried by AI but could not be drafted — it is marked “AI skipped” in Issues, ready for your words.', '%d were tried by AI but could not be drafted — they are marked “AI skipped” in Issues, ready for your words.', $skipped_todo, 'equalfix' ), $skipped_todo );
						}
					}
					echo esc_html( $sentence );
				}
				?>
				</p>
				<div class="eqd-hero-actions">
					<?php $this->render_audit_form(); ?>
					<?php if ( $waiting_n > 0 ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php echo esc_html( sprintf( __( 'Review AI suggestions (%d)', 'equalfix' ), $waiting_n ) ); ?></a>
					<?php endif; ?>
					<a class="button eqd-btn-ghost" href="<?php echo esc_url( $this->tab_url( 'issues' ) ); ?>"><?php esc_html_e( 'View issues', 'equalfix' ); ?></a>
					<a class="button eqd-btn-ghost" href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Plugin settings', 'equalfix' ); ?></a>
				</div>
				<p class="eqd-hero-meta">
					<span class="eqd-meta-chip"><?php echo esc_html( $host ); ?></span>
					<span class="eqd-meta-chip"><?php echo esc_html( sprintf( __( 'Last scan %s ago', 'equalfix' ), human_time_diff( $scan_ts, current_time( 'timestamp' ) ) ) ); ?></span>
					<?php if ( empty( $result['complete'] ) ) : ?>
						<span class="eqd-meta-chip eqd-meta-warn"><?php esc_html_e( 'Partial run', 'equalfix' ); ?></span>
					<?php endif; ?>
					<?php if ( (int) $result['pages_failed'] > 0 ) : ?>
						<span class="eqd-meta-chip eqd-meta-warn"><?php echo esc_html( sprintf( __( '%d pages could not be scanned', 'equalfix' ), (int) $result['pages_failed'] ) ); ?></span>
					<?php endif; ?>
				</p>
			</div>
			<div class="eqd-hero-side">
				<div class="eqd-ring-box">
					<?php if ( ! empty( $result['wall'] ) ) : ?>
						<p class="eqd-ring-label"><?php esc_html_e( 'Scan limited', 'equalfix' ); ?></p>
						<p class="eqd-ring-sub"><?php esc_html_e( 'Most pages returned the same holding page, so no coverage score is shown — it would not mean anything.', 'equalfix' ); ?></p>
					<?php elseif ( null !== $coverage ) : ?>
						<?php $circ = 326.73; $off = $circ * ( 1 - $coverage / 100 ); ?>
						<svg class="eqd-ring" width="132" height="132" viewBox="0 0 132 132" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Fixed or waiting: %1$d percent — %2$d of %3$d problems are fixed or waiting for your OK', 'equalfix' ), $coverage, (int) $model['d_fixed'] + (int) $model['d_waiting'], (int) $model['d_total'] ) ); ?>">
							<defs>
								<linearGradient id="eqd-ring-grad" x1="0" y1="0" x2="1" y2="1">
									<stop offset="0%" stop-color="#205fde"/><stop offset="100%" stop-color="#12b76a"/>
								</linearGradient>
							</defs>
							<circle cx="66" cy="66" r="52" fill="none" stroke="#e6ecf9" stroke-width="13"/>
							<circle cx="66" cy="66" r="52" fill="none" stroke="url(#eqd-ring-grad)" stroke-width="13" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( $circ ); ?>" stroke-dashoffset="<?php echo esc_attr( round( $off, 1 ) ); ?>" transform="rotate(-90 66 66)"/>
						</svg>
						<div class="eqd-ring-center"><span class="eqd-ring-num"><?php echo esc_html( $coverage ); ?>%</span></div>
						<p class="eqd-ring-label"><?php esc_html_e( 'Fixed or waiting', 'equalfix' ); ?></p>
						<p class="eqd-ring-sub"><?php echo esc_html( sprintf( __( '%1$d of %2$d problems fixed or waiting for your OK', 'equalfix' ), (int) $model['d_fixed'] + (int) $model['d_waiting'], (int) $model['d_total'] ) ); ?></p>
						<?php if ( isset( $result['previous_total'] ) ) : ?>
							<?php // BUG 2 (test-4): the ring delta speaks in problems when the previous run's distinct total is on record; legacy results keep the findings wording. ?>
							<?php $delta_in_problems = isset( $result['previous_problems'] ); ?>
							<?php $delta = $delta_in_problems ? ( (int) $model['d_total'] - (int) $result['previous_problems'] ) : ( $total - (int) $result['previous_total'] ); ?>
							<?php if ( $delta < 0 ) : ?>
								<p class="eqd-delta eqd-delta-good"><?php echo esc_html( sprintf( $delta_in_problems ? _n( '%d fewer problem than the last scan', '%d fewer problems than the last scan', abs( $delta ), 'equalfix' ) : _n( '%d fewer finding than the last scan', '%d fewer findings than the last scan', abs( $delta ), 'equalfix' ), abs( $delta ) ) ); ?></p>
							<?php elseif ( $delta > 0 ) : ?>
								<p class="eqd-delta eqd-delta-warn"><?php echo esc_html( sprintf( $delta_in_problems ? _n( '%d more problem than the last scan', '%d more problems than the last scan', $delta, 'equalfix' ) : _n( '%d more finding than the last scan', '%d more findings than the last scan', $delta, 'equalfix' ), $delta ) ); ?></p>
							<?php else : ?>
								<p class="eqd-delta"><?php echo esc_html( $delta_in_problems ? __( 'Same problem count as the last scan', 'equalfix' ) : __( 'Same findings count as the last scan', 'equalfix' ) ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					<?php else : ?>
						<p class="eqd-ring-label"><?php esc_html_e( 'No issues found', 'equalfix' ); ?></p>
						<p class="eqd-ring-sub"><?php esc_html_e( 'The automated checks found nothing to fix on the scanned pages.', 'equalfix' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $notes ) ) : ?>
			<p class="eqf-audit-notes"><?php echo esc_html( implode( ' ', $notes ) ); ?></p>
		<?php endif; ?>

		<?php $this->render_fix_console( $result, $model, $settings, $pro, $fixed_a_n, $remaining_a, $waiting_n ); ?>

		<?php $this->render_next_action_card( $model, $pro, $skipped_todo, ! empty( $result['wall'] ) ); ?>
		<?php $this->render_monitoring_line( $pro ); ?>

		<div class="eqd-kpis">
			<div class="eqd-tile">
				<a class="eqd-tile-link" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'group' => 'page' ) ) ); ?>">
					<span class="eqd-tile-icon eqd-tint-blue"><?php echo $this->icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-tile-num"><?php echo esc_html( (int) $result['pages_scanned'] ); ?></span>
					<span class="eqd-tile-label"><?php esc_html_e( 'Pages scanned', 'equalfix' ); ?></span>
					<span class="eqd-tile-sub"><?php echo esc_html( (int) $result['pages_failed'] > 0 ? sprintf( __( '%d could not be scanned', 'equalfix' ), (int) $result['pages_failed'] ) : __( 'Public pages checked', 'equalfix' ) ); ?></span>
				</a>
			</div>
			<div class="eqd-tile">
				<a class="eqd-tile-link" href="<?php echo esc_url( $this->tab_url( 'fixed' ) ); ?>">
					<span class="eqd-tile-icon eqd-tint-green"><?php echo $this->icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-tile-num"><?php echo esc_html( ! empty( $result['wall'] ) ? '—' : $fixed_n ); ?></span>
					<span class="eqd-tile-label"><?php esc_html_e( 'Fixed', 'equalfix' ); ?></span>
					<span class="eqd-tile-sub"><?php echo esc_html( ! empty( $result['wall'] ) ? __( 'Limited scan — not counted', 'equalfix' ) : ( $fixed_n > 0 ? ( ! empty( $settings['fixes_activated_at'] ) ? sprintf( __( 'Active since %s', 'equalfix' ), date_i18n( get_option( 'date_format' ), (int) $settings['fixes_activated_at'] ) ) : __( 'Applied at render by EqualFix', 'equalfix' ) ) : ( (int) $counts['fix-a'] > 0 ? __( 'Use “Apply Standard Fixes” below', 'equalfix' ) : __( 'Nothing to fix here', 'equalfix' ) ) ) ); ?></span>
				</a>
			</div>
			<div class="eqd-tile">
				<?php if ( $waiting_n > 0 ) : ?>
				<a class="eqd-tile-link" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>">
				<?php endif; ?>
					<span class="eqd-tile-icon eqd-tint-amber"><?php echo $this->icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-tile-num"><?php echo esc_html( ! empty( $result['wall'] ) ? '—' : $waiting_n ); ?></span>
					<span class="eqd-tile-label"><?php esc_html_e( 'Waiting for your OK', 'equalfix' ); ?></span>
					<span class="eqd-tile-sub"><?php echo esc_html( ! empty( $result['wall'] ) ? __( 'Limited scan — not counted', 'equalfix' ) : ( $waiting_n > 0 ? __( 'AI suggestions ready to review', 'equalfix' ) : __( 'Nothing waiting', 'equalfix' ) ) ); ?></span>
				<?php if ( $waiting_n > 0 ) : ?>
				</a>
				<?php endif; ?>
			</div>
			<div class="eqd-tile">
				<?php
				// State-aware routing (Stage 6, decision D4 —
				// replaces the Stage 5 rule): "Still to do" goes to
				// the Pro queue ONLY when pending drafts actually
				// cover part of the to-do set — the waiting bucket
				// IS the pending-covered to-dos, so waiting > 0 is
				// the exact test. The Stage 5 rule routed on
				// draftABLE (which includes never-drafted and
				// could-not-draft findings) and stranded the owner
				// on a queue claiming "all caught up" while 8
				// findings had no path at all. With nothing waiting,
				// the Findings to-do view is the route: every to-do
				// is there, each with its action (or its "AI
				// skipped" note). Mixed state: the queue comes first
				// (drafts are the actionable work) and the queue
				// itself banners the un-drafted remainder with a
				// link here.
				$todo_href = $this->tab_url( 'issues', array( 'view' => 'todo' ) );
				if ( ! empty( $pro['licence_active'] ) && $waiting_n > 0 ) {
					$todo_href = $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' );
				}
				?>
				<a class="eqd-tile-link" href="<?php echo esc_url( $todo_href ); ?>">
					<span class="eqd-tile-icon eqd-tint-violet"><?php echo $this->icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-tile-num"><?php echo esc_html( ! empty( $result['wall'] ) ? '—' : $todo_n ); ?></span>
					<span class="eqd-tile-label"><?php esc_html_e( 'Still to do', 'equalfix' ); ?></span>
					<span class="eqd-tile-sub"><?php echo esc_html( ! empty( $result['wall'] ) ? __( 'Limited scan — not counted', 'equalfix' ) : ( 0 === $todo_n ? __( 'All clear', 'equalfix' ) : __( 'Needs attention', 'equalfix' ) ) ); ?></span>
				</a>
			</div>
			<div class="eqd-tile">
				<?php // F-2: review problems get their own tile — they are judgement calls, not fix tasks, and never count toward "Still to do". ?>
				<a class="eqd-tile-link" href="<?php echo esc_url( $this->tab_url( 'issues', array( 'view' => 'todo' ) ) ); ?>">
					<span class="eqd-tile-icon eqd-tint-blue"><?php echo $this->icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-tile-num"><?php echo esc_html( ! empty( $result['wall'] ) ? '—' : $review_n ); ?></span>
					<span class="eqd-tile-label"><?php esc_html_e( 'For your review', 'equalfix' ); ?></span>
					<span class="eqd-tile-sub"><?php echo esc_html( ! empty( $result['wall'] ) ? __( 'Limited scan — not counted', 'equalfix' ) : ( 0 === $review_n ? __( 'Nothing needs review', 'equalfix' ) : __( 'Judgement calls, not fix tasks', 'equalfix' ) ) ); ?></span>
				</a>
			</div>
			<div class="eqd-tile">
				<span class="eqd-tile-icon eqd-tint-violet"><?php echo $this->icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
				<span class="eqd-tile-num eqd-tile-num-sm"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $scan_ts ) ); ?></span>
				<span class="eqd-tile-label"><?php esc_html_e( 'Last scan', 'equalfix' ); ?></span>
				<span class="eqd-tile-sub"><?php echo esc_html( sprintf( __( '%1$s ago — %2$s', 'equalfix' ), human_time_diff( $scan_ts, current_time( 'timestamp' ) ), empty( $result['complete'] ) ? __( 'partial', 'equalfix' ) : __( 'completed', 'equalfix' ) ) ); ?></span>
			</div>
		</div>

		<?php // IA batch: the trend card moved to History; the top-issues, activity and quick-actions cards and the with-audit features card are deleted from the Overview (GAP-MAP §1 — the Overview answers "where do I stand, what next", nothing else). ?>
		<?php $this->render_readability_card( $result ); ?>
		<?php $this->render_checks_card( $result ); ?>
		<?php $this->render_statement_card(); ?>
		<?php
	}

	/**
	 * The trend chart: totals + EqualFix-addressable counts per
	 * stored audit (inline SVG, no chart library). With a single
	 * point there is no line to draw yet — say so and show it.
	 *
	 * @param array $result         Latest audit result.
	 * @param array $counts         Category counts.
	 * @param int   $queue_eligible Queue-draftable count.
	 */
	private function render_trend( $result, $counts, $queue_eligible ) {
		$history = get_option( 'equalfix_audit_history', array() );
		if ( ! is_array( $history ) || empty( $history ) ) {
			$history = array(
				array(
					'time'  => strtotime( $result['time'] ),
					'total' => (int) $result['total'],
					'a'     => (int) $counts['fix-a'],
					'b'     => (int) $counts['fix-b'],
					'q'     => (int) $queue_eligible,
				),
			);
		}
		if ( count( $history ) < 2 ) {
			$point = $history[0];
			?>
			<p class="eqd-trend-single"><strong><?php echo esc_html( (int) $point['total'] ); ?></strong> <?php esc_html_e( 'issues in the latest scan.', 'equalfix' ); ?></p>
			<p class="eqd-trend-note"><?php esc_html_e( 'Run the audit again after changes and your trend line will build here, scan by scan.', 'equalfix' ); ?></p>
			<?php
			return;
		}
		$w    = 560;
		$h    = 190;
		$pad  = 26;
		$max  = 1;
		foreach ( $history as $p ) {
			$max = max( $max, (int) $p['total'] );
		}
		$n        = count( $history );
		$coords   = function ( $key ) use ( $history, $w, $h, $pad, $max, $n ) {
			$pts = array();
			foreach ( $history as $i => $p ) {
				$val   = 'total' === $key ? (int) $p['total'] : ( (int) $p['a'] + (int) $p['b'] + (int) $p['q'] );
				$x     = $pad + ( $w - 2 * $pad ) * ( $n > 1 ? $i / ( $n - 1 ) : 0.5 );
				$y     = ( $h - $pad ) - ( $h - 2 * $pad ) * ( $val / $max );
				$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
			}
			return $pts;
		};
		$total_pts = $coords( 'total' );
		$addr_pts  = $coords( 'addr' );
		?>
		<svg class="eqd-trend" viewBox="0 0 <?php echo esc_attr( $w ); ?> <?php echo esc_attr( $h ); ?>" width="100%" role="img" aria-label="<?php esc_attr_e( 'Line chart of total issues and EqualFix-fixable issues across recent scans', 'equalfix' ); ?>">
			<line x1="<?php echo esc_attr( $pad ); ?>" y1="<?php echo esc_attr( $h - $pad ); ?>" x2="<?php echo esc_attr( $w - $pad ); ?>" y2="<?php echo esc_attr( $h - $pad ); ?>" stroke="#e2e8f0" stroke-width="1"/>
			<text class="eqd-trend-max" x="<?php echo esc_attr( $pad ); ?>" y="15" font-size="11" fill="#64748b"><?php echo esc_html( sprintf( __( 'Max %d', 'equalfix' ), $max ) ); ?></text>
			<polyline points="<?php echo esc_attr( implode( ' ', $addr_pts ) ); ?>" fill="none" stroke="#12b76a" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
			<polyline points="<?php echo esc_attr( implode( ' ', $total_pts ) ); ?>" fill="none" stroke="#205fde" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
			<?php foreach ( $total_pts as $pt ) : $xy = explode( ',', $pt ); ?>
				<circle cx="<?php echo esc_attr( $xy[0] ); ?>" cy="<?php echo esc_attr( $xy[1] ); ?>" r="3.5" fill="#205fde"/>
			<?php endforeach; ?>
			<?php foreach ( $addr_pts as $pt ) : $xy = explode( ',', $pt ); ?>
				<circle cx="<?php echo esc_attr( $xy[0] ); ?>" cy="<?php echo esc_attr( $xy[1] ); ?>" r="3.5" fill="#12b76a"/>
			<?php endforeach; ?>
		</svg>
		<p class="eqd-trend-axis"><span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $history[0]['time'] ) ); ?></span><span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $history[ $n - 1 ]['time'] ) ); ?></span></p>
		<?php
	}

	/**
	 * Top issues by count, with an honest per-group status badge.
	 *
	 * @param array $result   Latest audit result.
	 * @param array $settings Settings.
	 */
	private function render_top_issues( $result, $settings ) {
		$groups = array();
		foreach ( (array) $result['findings'] as $finding ) {
			$title = $finding['title'];
			if ( ! isset( $groups[ $title ] ) ) {
				$groups[ $title ] = array( 'count' => 0, 'category' => $finding['category'], 'fix' => isset( $finding['fix'] ) ? $finding['fix'] : '', 'fps' => array() );
			}
			$groups[ $title ]['count']++;
			$groups[ $title ]['fps'][] = self::finding_fingerprint( $finding );
		}
		uasort( $groups, function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$groups = array_slice( $groups, 0, 5, true );
		if ( empty( $groups ) ) {
			?>
			<p class="eqd-top-empty"><?php esc_html_e( 'No issues found — nothing in the top list.', 'equalfix' ); ?></p>
			<?php
			return;
		}
		$enabled = ! empty( $settings['enabled'] );
		// 1.5: badges read the same count model as the hero and
		// tiles — a group Pro is already fixing, or has drafts
		// waiting for, says so instead of contradicting Pro.
		$model = $this->coverage_model( $result, $settings );
		?>
		<ul class="eqd-top-list">
			<?php foreach ( $groups as $title => $g ) : ?>
				<?php
				$g_fixed = 0;
				$g_wait  = 0;
				foreach ( $g['fps'] as $gfp ) {
					if ( isset( $model['fixed_fps'][ $gfp ] ) ) {
						$g_fixed++;
					} elseif ( isset( $model['waiting_fps'][ $gfp ] ) ) {
						$g_wait++;
					}
				}
				if ( $g_fixed >= $g['count'] ) {
					$badge = array( 'eqd-badge-on', __( 'Fixed', 'equalfix' ) );
					$tint  = 'eqd-tint-green';
				} elseif ( $g_wait > 0 ) {
					$badge = array( 'eqd-badge-words', __( 'Waiting for your OK', 'equalfix' ) );
					$tint  = 'eqd-tint-amber';
				} elseif ( EqualFix_Audit::CAT_FIX_A === $g['category'] ) {
					$is_fixed = $enabled && '' !== $g['fix'] && $this->fix_type_enabled( $g['fix'], $settings );
					$badge    = $is_fixed ? array( 'eqd-badge-on', __( 'Auto-fixed', 'equalfix' ) ) : array( 'eqd-badge-free', __( 'Free fix', 'equalfix' ) );
					$tint     = 'eqd-tint-green';
				} elseif ( EqualFix_Audit::CAT_FIX_B === $g['category'] ) {
					$badge = array( 'eqd-badge-pro', __( 'Pro', 'equalfix' ) );
					$tint  = 'eqd-tint-blue';
				} elseif ( EqualFix_Audit::CAT_EMBED === $g['category'] ) {
					$badge = array( 'eqd-badge-embed', __( 'Embed', 'equalfix' ) );
					$tint  = 'eqd-tint-violet';
				} else {
					$badge = array( 'eqd-badge-words', __( 'Your words', 'equalfix' ) );
					$tint  = 'eqd-tint-amber';
				}
				?>
				<li class="eqd-top-row">
					<span class="eqd-tile-icon <?php echo esc_attr( $tint ); ?>"><?php echo $this->icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-top-title"><?php echo esc_html( $title ); ?></span>
					<span class="eqd-top-count"><?php echo esc_html( $g['count'] ); ?></span>
					<span class="eqd-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Recent activity from real records only: the latest audit,
	 * the fix-activation stamp, and the newest cache purges.
	 *
	 * @param array $result   Latest audit result.
	 * @param array $settings Settings.
	 */
	private function render_activity( $result, $settings ) {
		$events = array();
		$events[] = array(
			'ts'   => strtotime( $result['time'] ),
			'icon' => 'search',
			'tint' => 'eqd-tint-blue',
			// BUG 2 (test-4): the activity line leads with the
			// distinct-problem count like every other headline
			// surface; the findings total rides secondarily.
			'text' => sprintf( __( 'Audit completed — %1$d problems across %2$d pages (%3$d findings)', 'equalfix' ), (int) self::coverage_counts( $result )['d_total'], (int) $result['pages_scanned'], (int) $result['total'] ),
		);
		if ( ! empty( $settings['fixes_activated_at'] ) ) {
			$events[] = array(
				'ts'   => (int) $settings['fixes_activated_at'],
				'icon' => 'bolt',
				'tint' => 'eqd-tint-green',
				'text' => __( 'Free fixes switched on', 'equalfix' ),
			);
		}
		foreach ( array_slice( EqualFix_Cache::get_log(), 0, 2 ) as $entry ) {
			$events[] = array(
				'ts'   => strtotime( $entry['time'] ),
				'icon' => 'refresh',
				'tint' => 'eqd-tint-amber',
				'text' => sprintf( __( 'Cache purge — %s', 'equalfix' ), $entry['reason'] ),
			);
		}
		usort( $events, function ( $a, $b ) { return $b['ts'] - $a['ts']; } );
		$events = array_slice( $events, 0, 4 );
		?>
		<ul class="eqd-activity-list">
			<?php foreach ( $events as $e ) : ?>
				<li class="eqd-activity-row">
					<span class="eqd-tile-icon <?php echo esc_attr( $e['tint'] ); ?>"><?php echo $this->icon( $e['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
					<span class="eqd-activity-text"><?php echo esc_html( $e['text'] ); ?></span>
					<span class="eqd-activity-time"><?php echo esc_html( sprintf( __( '%s ago', 'equalfix' ), human_time_diff( $e['ts'], current_time( 'timestamp' ) ) ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The Free vs Pro features card (dashboard, 1.1 round 2) —
	 * every claim on it is a shipped capability.
	 */
	private function render_features_card() {
		$pro_url = apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' );
		$pro     = self::pro_state();
		?>
		<div class="eqd-card eqd-features">
			<div class="eqd-features-head">
				<h3><?php esc_html_e( 'Plugin features', 'equalfix' ); ?></h3>
			</div>
			<div class="eqd-features-grid">
				<div class="eqd-feature-col">
					<p class="eqd-feature-plan"><?php esc_html_e( 'Free version', 'equalfix' ); ?> <span class="eqd-badge eqd-badge-on"><?php esc_html_e( 'Active', 'equalfix' ); ?></span></p>
					<ul class="eqd-feature-list">
						<li><?php esc_html_e( 'Full-site audit — up to 25 public pages', 'equalfix' ); ?></li>
						<?php foreach ( self::fix_types() as $label ) : ?>
							<li><?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
						<li><?php esc_html_e( 'Fixes applied at render — no database or theme edits', 'equalfix' ); ?></li>
					</ul>
				</div>
				<div class="eqd-feature-col">
					<p class="eqd-feature-plan"><?php esc_html_e( 'Pro add-on', 'equalfix' ); ?> <?php if ( $pro['licence_active'] ) : ?><span class="eqd-badge eqd-badge-on"><?php esc_html_e( 'Active', 'equalfix' ); ?></span><?php elseif ( $pro['present'] ) : ?><span class="eqd-badge eqd-badge-pro"><?php esc_html_e( 'Installed', 'equalfix' ); ?></span><?php else : ?><span class="eqd-badge eqd-badge-pro"><?php esc_html_e( 'Available', 'equalfix' ); ?></span><?php endif; ?></p>
					<ul class="eqd-feature-list eqd-feature-list-pro">
						<li><?php esc_html_e( 'Extra fixes — more automatic fixes', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Approval queue with drafted suggestions', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Scheduled monitoring with email summaries', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'PDF remediation reports — your proof-of-effort record', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Per-page findings inbox', 'equalfix' ); ?></li>
					</ul>
					<?php if ( $pro['licence_active'] ) : ?>
						<p class="eqd-feature-cta"><a class="button button-primary" href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Open Pro', 'equalfix' ); ?></a></p>
					<?php elseif ( $pro['present'] ) : ?>
						<p class="eqd-feature-cta"><a class="button button-primary" href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>"><?php esc_html_e( 'Activate Pro', 'equalfix' ); ?></a></p>
					<?php else : ?>
						<p class="eqd-feature-cta"><a class="button button-primary" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro — $39/mo', 'equalfix' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The guided journey under the audit summary, in the order the owner
	 * works through it: the free-fixable issues (listed, with the one-click
	 * fix), then the Pro-fixable issues (listed, each with a plain-language
	 * note on why it matters, and the upgrade button), then the issues
	 * that need a human decision.
	 *
	 * @param array $result Stored audit result.
	 * @param array $counts Finding counts by bucket (fix-a, fix-b, human).
	 */
	private function render_journey( $result, $counts, $queue_eligible = 0 ) {
		$fix_a = (int) $counts['fix-a'];
		$fix_b = (int) $counts['fix-b'];
		$human = (int) $counts['human'];
		if ( 0 === $fix_a && 0 === $fix_b && 0 === $human ) {
			return;
		}
		$pro_url = apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' );

		$by_cat = array( 'fix-a' => array(), 'fix-b' => array(), 'human' => array() );
		foreach ( (array) $result['findings'] as $finding ) {
			$cat = isset( $by_cat[ $finding['category'] ] ) ? $finding['category'] : 'human';
			$by_cat[ $cat ][] = $finding;
		}

		$settings = self::get();
		list( $fixed_list, $remaining_list ) = $this->partition_fix_a( $result, $settings );
		// 1.5: the Pro panel and the "Needs your words" card
		// below speak about what is LEFT, so they read the
		// same coverage model as the hero — a finding Pro
		// already fixed is neither pitched again as a Pro
		// gap nor listed as still needing the owner's words.
		$model          = $this->coverage_model( $result, $settings );
		$todo_list      = $this->todo_findings( $result, $model );
		$fix_b_todo     = $this->count_category( $todo_list, EqualFix_Audit::CAT_FIX_B );
		$draftable_todo = $this->queue_eligible_count( $todo_list );
		$fix_b_todo_list = array();
		$human_todo      = array();
		foreach ( $todo_list as $todo_finding ) {
			if ( EqualFix_Audit::CAT_FIX_B === $todo_finding['category'] ) {
				$fix_b_todo_list[] = $todo_finding;
			} elseif ( EqualFix_Audit::CAT_FIX_A !== $todo_finding['category'] ) {
				$human_todo[] = $todo_finding;
			}
		}
		if ( ! empty( $fixed_list ) ) {
			$this->render_fixed_section( $result, $fixed_list, $settings );
		}
		if ( 0 === $fix_a ) {
			// Zero free-fixable findings must not read as "the
			// free plugin does nothing" (1.0.2): the free fix set
			// works at render time across the whole site, not
			// only on audited findings.
			$all_on = $this->all_free_fixes_on();
			?>
			<div class="equalfix-card equalfix-flow">
				<h3><?php esc_html_e( 'Free fixes', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'None of these findings fall in the free fix set — but the free fixes still work for you: they apply at render time across your whole site, including pages this audit did not sample, whenever a missing language attribute, a decorative image without empty alt text, an unlabelled icon control or search field, a form field living on its placeholder text, or a missing skip link appears.', 'equalfix' ); ?></p>
				<?php if ( $all_on ) : ?>
					<p><strong><?php esc_html_e( 'Your free fixes are on.', 'equalfix' ); ?></strong> <a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Fine-tune each fix type in Settings', 'equalfix' ); ?></a></p>
				<?php else : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="fix_all_free">
						<?php submit_button( __( 'Turn on free fixes', 'equalfix' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
			<?php
		}
		if ( ! empty( $remaining_list ) ) {
			?>
			<div class="equalfix-card equalfix-flow">
				<h3><?php esc_html_e( 'Fix these free', 'equalfix' ); ?></h3>
				<?php $this->render_journey_list( $remaining_list ); ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="fix_all_free">
					<?php submit_button( __( 'Fix all free issues', 'equalfix' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'Switches on the free fix set. Fixes apply at render time only — nothing is written to your files or content, and you can switch any fix type off again in Settings.', 'equalfix' ); ?></p>
			</div>
			<?php
		}
		if ( $fix_b_todo > 0 || $draftable_todo > 0 ) {
			// ONE Pro card with Pro's full, honest coverage
			// (1.0.2): what it fixes automatically, plus what
			// its approval queue drafts suggestions for. The
			// scattered per-section Pro links are gone.
			?>
			<div class="equalfix-card equalfix-flow eqf-pro-panel">
				<h3><?php esc_html_e( 'Fix more with Pro', 'equalfix' ); ?></h3>
				<?php if ( $fix_b_todo > 0 ) : ?>
					<p><strong><?php echo esc_html( sprintf( _n( 'Pro fixes %d finding automatically', 'Pro fixes %d findings automatically', $fix_b_todo, 'equalfix' ), $fix_b_todo ) ); ?></strong> <?php esc_html_e( '(headings, tables, menus, and more in Extra fixes):', 'equalfix' ); ?></p>
					<?php $this->render_pro_gap_rows( $fix_b_todo_list ); ?>
				<?php endif; ?>
				<?php if ( $draftable_todo > 0 ) : ?>
					<p><strong><?php echo esc_html( sprintf( _n( 'Pro drafts an approval-ready suggestion for %d more', 'Pro drafts approval-ready suggestions for %d more', $draftable_todo, 'equalfix' ), $draftable_todo ) ); ?></strong> <?php esc_html_e( '— AI Vision drafts alt text by reading your images, alongside page-derived labels and names, all in an approval queue. Nothing is applied until you approve it, and you can edit any suggestion first.', 'equalfix' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Pro also adds scheduled monitoring (new issues are caught without re-running audits by hand) and dated PDF remediation reports — the proof-of-effort record for your files.', 'equalfix' ); ?></p>
				<?php $jpro = self::pro_state(); ?>
				<?php if ( $jpro['licence_active'] ) : ?>
					<p><a href="<?php echo esc_url( $this->tab_url( $jpro['shell'] ? 'pro-fixb' : 'pro' ) ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Pro is active — open Extra fixes', 'equalfix' ); ?></a> <a href="https://equalfix.com/education" target="_blank" rel="noopener"><?php esc_html_e( 'Learn why these matter', 'equalfix' ); ?></a></p>
				<?php elseif ( $jpro['present'] ) : ?>
					<p><a href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Activate your Pro licence', 'equalfix' ); ?></a> <a href="https://equalfix.com/education" target="_blank" rel="noopener"><?php esc_html_e( 'Learn why these matter', 'equalfix' ); ?></a></p>
				<?php else : ?>
					<p><a href="<?php echo esc_url( $pro_url ); ?>" class="button button-primary button-hero" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro — $39/mo', 'equalfix' ); ?></a> <a href="https://equalfix.com/education" target="_blank" rel="noopener"><?php esc_html_e( 'Learn why these matter', 'equalfix' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
		}

		if ( ! empty( $human_todo ) ) {
			?>
			<div class="equalfix-card equalfix-flow">
				<h3><?php esc_html_e( 'Needs your words', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'These need a name, a description, or a judgment no plugin should invent. Each one below names the page and the exact element, and the findings list explains what to write:', 'equalfix' ); ?></p>
				<?php if ( $draftable_todo > 0 ) : ?>
					<p class="eqf-queue-note"><?php echo esc_html( sprintf( __( 'Pro\'s AI Vision drafts suggestions for %d of these — alt text read from the images themselves, plus labels and names from your pages — which you approve or edit before anything goes live.', 'equalfix' ), $draftable_todo ) ); ?></p>
				<?php endif; ?>
				<?php $this->render_journey_list( $human_todo ); ?>
			</div>
			<?php
		}
	}

	/**
	 * The Fixed section (1.1 UI batch): the findings from the last
	 * audit that are being remediated at render time right now,
	 * grouped by page, each with an Inspect Fix before/after. This
	 * is a derived view of audit findings ∩ active toggles — the
	 * record can never drift from what the site is actually doing.
	 *
	 * @param array $result     Stored audit result.
	 * @param array $fixed_list Findings currently fixed.
	 * @param array $settings   Current settings.
	 */
	private function render_fixed_section( $result, $fixed_list, $settings, $compact = false ) {
		$labels  = self::fix_types();
		$by_page = array();
		foreach ( $fixed_list as $finding ) {
			$by_page[ $finding['url'] ][] = $finding;
		}
		// Duplicate page titles (Stage 6.1, F6a): two pages
		// can share one title (the test site has two "test3"
		// pages) and their groups then head identically with
		// nothing to tell them apart — groups whose heading
		// repeats get the page path appended as distinguisher.
		$label_counts = array();
		foreach ( $by_page as $page_url => $page_items ) {
			$lbl                  = self::page_title_label( $page_url );
			$label_counts[ $lbl ] = isset( $label_counts[ $lbl ] ) ? $label_counts[ $lbl ] + 1 : 1;
		}
		$k = 0;
		?>
		<?php if ( $compact ) : ?>
			<p class="description"><?php esc_html_e( 'Fixed at render time — nothing is written to your files or content, and switching a fix type off restores the original output.', 'equalfix' ); ?></p>
		<?php else : ?>
		<div class="equalfix-card equalfix-flow eqf-fixed-card">
			<h3><?php esc_html_e( '✓ Fixed — active on your site', 'equalfix' ); ?></h3>
			<p><?php esc_html_e( 'These findings are being fixed at render time. Fixes apply to rendered HTML only — nothing is written to your files or content, and switching a fix type off restores the original output.', 'equalfix' ); ?><?php
			if ( ! empty( $settings['fixes_activated_at'] ) ) {
				echo ' ' . esc_html( sprintf( __( 'Free fixes active since %s.', 'equalfix' ), date_i18n( get_option( 'date_format' ), (int) $settings['fixes_activated_at'] ) ) );
			}
			?></p>
		<?php endif; ?>
			<?php foreach ( $by_page as $url => $items ) : ?>
				<?php $page_label_text = self::page_title_label( $url ); ?>
				<h4 class="eqf-fixed-page"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $page_label_text ); ?></a><?php echo $label_counts[ $page_label_text ] > 1 ? ' <span class="eqf-fixed-pagepath">' . esc_html( self::page_label( $url ) ) . '</span>' : ''; ?> <span class="eqf-pagecount"><?php echo esc_html( sprintf( _n( '%d fixed', '%d fixed', count( $items ), 'equalfix' ), count( $items ) ) ); ?></span></h4>
				<ul class="eqf-fixed-list">
					<?php foreach ( $items as $finding ) : ?>
						<?php
						$k++;
						$type = $this->finding_fix_type( $finding );
						list( $before, $after, $is_real ) = $this->fix_preview_pair( $type, $finding, $result );
						?>
						<li class="eqf-fixed-row">
							<span class="eqf-fixed-title"><?php echo esc_html( $finding['title'] ); ?></span>
							<?php if ( null !== $type && isset( $labels[ $type ] ) ) : ?>
								<span class="eqf-fixtype"><?php echo esc_html( $labels[ $type ] ); ?></span>
							<?php endif; ?>
							<button type="button" class="eqf-inspect-btn" aria-expanded="false" aria-controls="eqf-insp-<?php echo esc_attr( $k ); ?>"><?php esc_html_e( 'Inspect fix', 'equalfix' ); ?></button>
							<div class="eqf-inspect" id="eqf-insp-<?php echo esc_attr( $k ); ?>" hidden>
								<?php if ( $is_real ) : ?>
									<p class="description"><?php esc_html_e( 'Real before/after, captured from your site during the audit (one example of this fix type).', 'equalfix' ); ?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Illustrative only — this preview is generated from the finding alone, without its page context, so it may not show the full change. Re-run the audit to capture the real before/after from your pages.', 'equalfix' ); ?></p>
								<?php endif; ?>
								<div class="equalfix-preview-cols">
									<div>
										<strong><?php esc_html_e( 'Before', 'equalfix' ); ?></strong>
										<pre><code><?php echo esc_html( $before ); ?></code></pre>
									</div>
									<div>
										<strong><?php esc_html_e( 'After', 'equalfix' ); ?></strong>
										<pre><code><?php echo esc_html( $after ); ?></code></pre>
									</div>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<?php if ( ! $compact ) : ?>
			<p><a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Fine-tune each fix type in Settings', 'equalfix' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Re-run the audit after changing your content to refresh this list.', 'equalfix' ); ?></p>
		</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * Pro gap rows (1.1 UI batch): the site's Pro-fixable findings
	 * grouped by issue type with counts and a why line — a gap
	 * analysis of what Pro would close, not a generic upsell list.
	 *
	 * @param array $findings Fix-b findings.
	 */
	private function render_pro_gap_rows( $findings ) {
		$by_title = array();
		foreach ( $findings as $finding ) {
			$t = $finding['title'];
			if ( ! isset( $by_title[ $t ] ) ) {
				$by_title[ $t ] = array( 'count' => 0, 'first' => $finding );
			}
			$by_title[ $t ]['count']++;
		}
		$shown = array_slice( $by_title, 0, 5, true );
		?>
		<ul class="eqf-gap-list">
			<?php foreach ( $shown as $title => $info ) : ?>
				<li>
					<strong><?php echo esc_html( $title ); ?></strong>
					<?php if ( $info['count'] > 1 ) : ?>
						<span class="eqf-gap-count"><?php echo esc_html( sprintf( '×%d', $info['count'] ) ); ?></span>
					<?php endif; ?>
					<br><span class="eqf-gap-why"><?php echo esc_html( $this->journey_why( $info['first'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		$shown_count = 0;
		foreach ( $shown as $info ) {
			$shown_count += $info['count'];
		}
		$extra = count( $findings ) - $shown_count;
		if ( $extra > 0 ) {
			echo '<p class="description">' . esc_html( sprintf( __( '…and %d more in the findings list below.', 'equalfix' ), $extra ) ) . '</p>';
		}
	}

	/**
	 * A compact itemised list of findings for a journey section: the issue
	 * and the page it is on, capped, with a pointer to the full table.
	 *
	 * @param array $findings Findings in one bucket.
	 * @param bool  $why      Whether to add a "why it matters" line per item.
	 */
	private function render_journey_list( $findings, $why = false ) {
		$shown = array_slice( $findings, 0, 8 );
		?>
		<ul class="equalfix-journey-list">
			<?php foreach ( $shown as $finding ) : ?>
				<li>
					<?php echo esc_html( $finding['title'] ); ?> — <a href="<?php echo esc_url( $finding['url'] ); ?>"><?php echo esc_html( self::page_label( $finding['url'] ) ); ?></a>
					<?php if ( $why ) : ?>
						<br><span class="description"><?php echo esc_html( $this->journey_why( $finding ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		if ( count( $findings ) > count( $shown ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( '…and %d more in the findings list below.', 'equalfix' ), count( $findings ) - count( $shown ) ) ) . '</p>';
		}
	}

	/**
	 * One plain-language line on why a Pro-fixable finding matters.
	 *
	 * @param array $finding The finding.
	 * @return string
	 */
	private function journey_why( $finding ) {
		if ( false !== stripos( $finding['title'], 'heading' ) ) {
			return __( 'Screen readers navigate page by page through headings. A skipped level breaks that map — and automated accessibility audits flag it immediately.', 'equalfix' );
		}
		return __( 'Pro fixes this automatically at render time — no code changes, and nothing is written to your theme or content.', 'equalfix' );
	}

	/**
	 * Render before/after previews, one example per fix type.
	 */
	private function render_fix_previews() {
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return;
		}
		$settings = self::get();
		$by_type  = array();
		foreach ( $result['findings'] as $finding ) {
			if ( EqualFix_Audit::CAT_FIX_A !== $finding['category'] ) {
				continue;
			}
			$type = $this->finding_fix_type( $finding );
			if ( $type && ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = $finding;
			}
		}
		if ( empty( $by_type ) ) {
			return;
		}
		$labels = self::fix_types();
		echo '<h3>' . esc_html__( 'Fix previews (before / after)', 'equalfix' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Exactly what each fix type changes, taken from real findings on your site.', 'equalfix' ) . '</p>';
		foreach ( $by_type as $type => $finding ) {
			// Real pair captured at audit time when available;
			// otherwise an illustrative fragment fix.
			list( $before, $after, $is_real ) = $this->fix_preview_pair( $type, $finding, $result );
			$fallback = ! $is_real;
			?>
			<div class="equalfix-preview">
				<h4><?php echo esc_html( $labels[ $type ] ); ?></h4>
				<?php if ( $fallback ) : ?>
					<p class="description"><?php esc_html_e( 'Illustrative only — this preview is generated from the finding alone, without its page context, so it may not show the full change. Re-run the audit to capture the real before/after from your pages.', 'equalfix' ); ?></p>
				<?php endif; ?>
				<div class="equalfix-preview-cols">
					<div>
						<strong><?php esc_html_e( 'Before', 'equalfix' ); ?></strong>
						<pre><code><?php echo esc_html( $before ); ?></code></pre>
					</div>
					<div>
						<strong><?php esc_html_e( 'After', 'equalfix' ); ?></strong>
						<pre><code><?php echo esc_html( $after ); ?></code></pre>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Build honest before/after preview pairs at audit time.
	 *
	 * Previews used to run the fixer on the lone snippet, without the
	 * page context the fix depends on, so several fix types showed
	 * no change. Capturing the pair from the real page fixes that.
	 *
	 * @param array $findings Findings.
	 * @param array $page_html Page HTML by URL.
	 * @return array Preview pairs by fix type.
	 */
	/**
	 * Fold one page's findings into the run's before/after
	 * previews. Each fix type's preview comes from the FIRST
	 * finding of that type in the run — the same selection the
	 * old whole-run builder made — and a type is attempted only
	 * once, even if that attempt yields no visible change.
	 * Building per page means a stepped run never has to hold
	 * every page's HTML until the end.
	 *
	 * @param array $page_findings Findings from one page.
	 * @param string $html         That page's HTML.
	 * @param array $state         Run state (updated in place).
	 */
	private function accumulate_previews( $page_findings, $html, &$state ) {
		foreach ( $page_findings as $finding ) {
			if ( EqualFix_Audit::CAT_FIX_A !== $finding['category'] ) {
				continue;
			}
			$type = $this->finding_fix_type( $finding );
			if ( ! $type || isset( $state['preview_tried'][ $type ] ) ) {
				continue;
			}
			$state['preview_tried'][ $type ] = true;
			$pair = $this->preview_pair_for( $html, $finding, $type );
			if ( null !== $pair ) {
				$state['previews'][ $type ] = $pair;
			}
		}
	}

	/**
	 * Build the before/after pair for one finding by running the
	 * fixer over its page, or null when the fix produces no
	 * extractable change (or the fixer bails on the page).
	 *
	 * @param string $html    Page HTML.
	 * @param array $finding  The finding to preview.
	 * @param string $type    Fix type key.
	 * @return array|null Before/after pair.
	 */
	private function preview_pair_for( $html, $finding, $type ) {
		$fixer = new EqualFix_Fixer(
			array(
				'fix_lang' => true,
				'fix_alt' => true,
				'fix_aria_label' => true,
				'fix_label_assoc' => true,
				'fix_skip_link' => true,
				'excluded_selectors' => array(),
				'locale' => get_bloginfo( 'language' ),
				'skip_link_text' => __( 'Skip to content', 'equalfix' ),
			)
		);
		$fixed = $fixer->fix( $html );
		if ( $fixer->did_bail() ) {
			return null;
		}
		return $this->extract_preview_pair( $html, $fixed, $finding, $type );
	}

	/**
	 * Extract one before/after pair for a finding from a full page.
	 *
	 * @param string $html Original page HTML.
	 * @param string $fixed Fixed page HTML.
	 * @param array $finding Finding.
	 * @param string $type Fix type key.
	 * @return array|null Before/after pair, or null when no change is found.
	 */
	private function extract_preview_pair( $html, $fixed, $finding, $type ) {
		$orig = $this->parse_html( $html );
		$new = $this->parse_html( $fixed );
		if ( false === $orig || false === $new ) {
			return null;
		}
		if ( EqualFix_Fixer::FIX_LANG === $type ) {
			$before = $this->opening_tag( $orig->getElementsByTagName( 'html' )->item( 0 ) );
			$after = $this->opening_tag( $new->getElementsByTagName( 'html' )->item( 0 ) );
			if ( '' !== $before && '' !== $after && $before !== $after ) {
				return array(
					'before' => $before,
					'after' => $after,
				);
			}
			return null;
		}
		if ( EqualFix_Fixer::FIX_SKIP_LINK === $type ) {
			$xpath = new DOMXPath( $new );
			$links = $xpath->query( '//a[contains(concat(" ", normalize-space(@class), " "), " skip ")] | //a[starts-with(@href, "#")]' );
			foreach ( $links as $a ) {
				if ( false !== stripos( $a->textContent, 'skip' ) ) {
					return array(
						'before' => '<body>',
						'after' => $new->saveHTML( $a ),
					);
				}
			}
			return null;
		}
		$snippet = isset( $finding['snippet'] ) ? trim( (string) $finding['snippet'] ) : '';
		if ( '' === $snippet ) {
			return null;
		}
		$truncated = '…' === substr( $snippet, -3 ) || '…' === substr( $snippet, -1 );
		$target = null;
		$tag = '';
		$ordinal = 0;
		$seen = array();
		foreach ( $orig->getElementsByTagName( '*' ) as $el ) {
			$t = strtolower( $el->tagName );
			if ( ! isset( $seen[ $t ] ) ) {
				$seen[ $t ] = 0;
			}
			$mine = $seen[ $t ];
			$seen[ $t ]++;
			$outer = trim( $orig->saveHTML( $el ) );
			if ( $truncated ) {
				$match = 0 === strpos( $outer, substr( $snippet, 0, -1 ) ) || 0 === strpos( $outer, rtrim( $snippet, '…' ) );
			} else {
				$match = $outer === $snippet;
			}
			if ( $match ) {
				$target = $el;
				$tag = $t;
				$ordinal = $mine;
				break;
			}
		}
		if ( null === $target ) {
			return null;
		}
		$before = $orig->saveHTML( $target );
		$keys = array();
		foreach ( array( 'id', 'src', 'href', 'name' ) as $attr ) {
			if ( $target->hasAttribute( $attr ) && '' !== $target->getAttribute( $attr ) ) {
				$keys[ $attr ] = $target->getAttribute( $attr );
			}
		}
		$counterpart = null;
		$candidates = $new->getElementsByTagName( $tag );
		foreach ( $candidates as $cand ) {
			$all = true;
			foreach ( $keys as $attr => $val ) {
				if ( ! $cand->hasAttribute( $attr ) || $cand->getAttribute( $attr ) !== $val ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				$counterpart = $cand;
				break;
			}
		}
		if ( null === $counterpart && $ordinal < $candidates->length ) {
			$counterpart = $candidates->item( $ordinal );
		}
		if ( null === $counterpart ) {
			return null;
		}
		$after = $new->saveHTML( $counterpart );
		if ( $before !== $after ) {
			return array(
				'before' => $before,
				'after' => $after,
			);
		}
		return null;
	}

	/**
	 * Parse HTML into a DOMDocument, or false on failure.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|false
	 */
	private function parse_html( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || ! is_string( $html ) || '' === trim( $html ) ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $dom : false;
	}

	/**
	 * Opening tag of an element ('' when none).
	 *
	 * @param DOMElement|null $el Element.
	 * @return string
	 */
	private function opening_tag( $el ) {
		if ( ! $el ) {
			return '';
		}
		$out = '<' . strtolower( $el->tagName );
		foreach ( $el->attributes as $attr ) {
			$out .= ' ' . $attr->nodeName . '="' . $attr->nodeValue . '"';
		}
		return $out . '>';
	}

	/**
	 * Map a Fix Set A finding to its fix type for previews.
	 *
	 * @param array $finding Finding.
	 * @return string|null Fix type key.
	 */
	private function finding_fix_type( $finding ) {
		$title = $finding['title'];
		if ( false !== stripos( $title, 'language attribute' ) ) {
			return EqualFix_Fixer::FIX_LANG;
		}
		if ( false !== stripos( $title, 'alt text' ) ) {
			return EqualFix_Fixer::FIX_ALT;
		}
		if ( false !== stripos( $title, 'accessible name' ) ) {
			return EqualFix_Fixer::FIX_ARIA_LABEL;
		}
		if ( false !== stripos( $title, 'label' ) ) {
			return EqualFix_Fixer::FIX_LABEL_ASSOC;
		}
		if ( false !== stripos( $title, 'skip-to-content' ) || false !== stripos( $title, 'skip link' ) ) {
			return EqualFix_Fixer::FIX_SKIP_LINK;
		}
		return null;
	}

	/**
	 * Render the purge log tab.
	 */
	private function render_purge() {
		?>
		<h2><?php esc_html_e( 'Purge log', 'equalfix' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Every time caches are purged — automatically when settings change, or manually below — it is recorded here so "I enabled it but nothing changed" cases are diagnosable.', 'equalfix' ); ?></p>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="purge_now">
			<?php submit_button( __( 'Purge caches now', 'equalfix' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$log = EqualFix_Cache::get_log();
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No purges recorded yet.', 'equalfix' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'equalfix' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'equalfix' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'equalfix' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><?php echo esc_html( $entry['reason'] ); ?></td>
						<td><?php echo esc_html( implode( ' ', $entry['actions'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
