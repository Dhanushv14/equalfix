<?php
/**
 * EqualFix Accessibility — Admin Settings & Audit UI
 *
 * One settings screen with three tabs: Settings, Audit, Purge log.
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
	 * Settings option name.
	 */
	const OPTION = 'equalfix_settings';

	/**
	 * Audit results option name.
	 */
	const AUDIT_OPTION = 'equalfix_audit_results';

	/**
	 * Dismissed findings store (1.2): fingerprint => record.
	 * Dismissal hides a finding from the working list with a
	 * logged reason; it never changes what the audit found.
	 */
	const DISMISSED_OPTION = 'equalfix_dismissed_findings';

	/** Option holding the free account key used for the AI preview (1.3). */
	const PREVIEW_KEY_OPTION = 'equalfix_preview_key';

	/** Option holding the latest AI preview drafts (1.3). */
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
	 * Fix types with labels (Fix Set A — the complete free fix set).
	 *
	 * @return array
	 */
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
			// Stage 5: data belongs to the site, not the plugin
			// files — uninstall preserves it unless the owner
			// explicitly opts into removal (off by default).
			'remove_data_on_uninstall' => 0,
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
		return update_option( self::DISMISSED_OPTION, $map, false );
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
	 * All four manual stores, keyed for the fixer's settings array.
	 *
	 * @return array
	 */
	public static function manual_rules_for_fixer() {
		return array(
			'manual_alt_rules'   => self::manual_alt_rules(),
			'manual_name_rules'  => self::manual_name_rules(),
			'manual_label_rules' => self::manual_label_rules(),
			'manual_title_rules' => self::manual_title_rules(),
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
		// Manual kinds live on review-category findings only —
		// all four writable issue types are review findings.
		if ( EqualFix_Audit::CAT_REVIEW !== $finding['category'] ) {
			return null;
		}
		$title = (string) $finding['title'];
		if ( false !== stripos( $title, 'missing alt text' ) || 'Alt text is just a file name' === $title ) {
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
		if ( false !== stripos( $title, 'has no accessible name' ) ) {
			$target = self::manual_target_from_snippet( $finding['snippet'] );
			$hash   = self::manual_rule_hash( 'name', $target );
			$rules  = self::manual_name_rules();
			if ( isset( $rules[ $hash ] ) ) {
				return array( 'kind' => 'name', 'key' => $hash, 'text' => isset( $rules[ $hash ]['aria_label'] ) ? (string) $rules[ $hash ]['aria_label'] : '', 'decorative' => false );
			}
			return null;
		}
		if ( 'Embedded frame missing a title' === $title ) {
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
			if ( isset( $dismissed[ $fp ] ) ) {
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
			$abs           = EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] );
			$key           = self::manual_src_key( '' !== $abs ? $abs : $src );
			$rules         = self::manual_alt_rules();
			$rules[ $key ] = array(
				'alt'        => $decorative ? '' : $text,
				'pages'      => array( self::manual_page_pattern( $finding['url'] ) ),
				'decorative' => $decorative ? true : false,
			);
			return update_option( self::MANUAL_ALT_OPTION, $rules, false );
		}
		$target = self::manual_target_from_snippet( $finding['snippet'] );
		if ( '' === $target['tag'] ) {
			return false;
		}
		if ( 'label' === $kind ) {
			$rules                                   = self::manual_label_rules();
			$rules[ self::manual_rule_hash( 'label', $target ) ] = array( 'label' => $text, 'target' => $target );
			return update_option( self::MANUAL_LABEL_OPTION, $rules, false );
		}
		if ( 'name' === $kind ) {
			$rules                                   = self::manual_name_rules();
			$rules[ self::manual_rule_hash( 'name', $target ) ] = array( 'aria_label' => $text, 'target' => $target );
			return update_option( self::MANUAL_NAME_OPTION, $rules, false );
		}
		if ( 'title' === $kind ) {
			$rules                                   = self::manual_title_rules();
			$rules[ self::manual_rule_hash( 'title', $target ) ] = array( 'title' => $text, 'target' => $target );
			return update_option( self::MANUAL_TITLE_OPTION, $rules, false );
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

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_first_run_redirect' ), 1 );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_init', array( $this, 'maybe_continue_audit' ), 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
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
		add_submenu_page(
			'equalfix-accessibility',
			__( 'EqualFix Purge log', 'equalfix' ),
			__( 'Purge log', 'equalfix' ),
			'manage_options',
			'equalfix-purge-log',
			array( $this, 'render_purge_tab' )
		);
	}

	/**
	 * Render with the Settings tab forced (submenu entry point).
	 */
	public function render_settings_tab() {
		$_GET['tab'] = 'settings';
		$this->render();
	}

	/**
	 * Render with the Purge log tab forced (submenu entry point).
	 */
	public function render_purge_tab() {
		$_GET['tab'] = 'purge';
		$this->render();
	}

	/**
	 * Enqueue admin CSS on our screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_equalfix-accessibility', 'equalfix_page_equalfix-settings', 'equalfix_page_equalfix-purge-log' ), true ) ) {
			return;
		}
		wp_enqueue_style(
			'equalfix-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css',
			array(),
			EQUALFIX_VERSION
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
		} elseif ( 'install_pro' === $action ) {
			$this->install_pro();
		} elseif ( 'use_preview_key' === $action ) {
			$this->use_preview_key();
		} elseif ( 'generate_ai_preview' === $action ) {
			$this->generate_ai_preview_action();
		} elseif ( 'clear_ai_preview' === $action ) {
			$this->clear_ai_preview_action();
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
		$settings['enabled']         = isset( $_POST['equalfix_enabled'] ) ? 1 : 0;
		$settings['fix_lang']        = isset( $_POST['equalfix_fix_lang'] ) ? 1 : 0;
		$settings['fix_alt']         = isset( $_POST['equalfix_fix_alt'] ) ? 1 : 0;
		$settings['fix_aria_label']  = isset( $_POST['equalfix_fix_aria_label'] ) ? 1 : 0;
		$settings['fix_label_assoc'] = isset( $_POST['equalfix_fix_label_assoc'] ) ? 1 : 0;
		$settings['fix_skip_link']   = isset( $_POST['equalfix_fix_skip_link'] ) ? 1 : 0;
		$settings['connect_opt_in']  = isset( $_POST['equalfix_connect_opt_in'] ) ? 1 : 0;
		$settings['auto_purge']      = isset( $_POST['equalfix_auto_purge'] ) ? 1 : 0;
		$settings['output_cache']    = isset( $_POST['equalfix_output_cache'] ) ? 1 : 0;
		$settings['remove_data_on_uninstall'] = isset( $_POST['equalfix_remove_data_on_uninstall'] ) ? 1 : 0;

		$settings['excluded_urls'] = $this->sanitize_lines( isset( $_POST['equalfix_excluded_urls'] ) ? wp_unslash( $_POST['equalfix_excluded_urls'] ) : '' );
		$settings['excluded_selectors'] = $this->sanitize_lines( isset( $_POST['equalfix_excluded_selectors'] ) ? wp_unslash( $_POST['equalfix_excluded_selectors'] ) : '' );
		$settings['excluded_templates'] = sanitize_text_field( isset( $_POST['equalfix_excluded_templates'] ) ? wp_unslash( $_POST['equalfix_excluded_templates'] ) : '' );

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
	 * Guided flow: switch the whole free fix set on from the Audit tab.
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
		update_option( self::OPTION, $settings );

		// Fix toggles always purge (see save_settings).
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Free fixes enabled from the audit tab.', 'equalfix' ) );
		$this->redirect( 'audit', __( 'Free fixes are on — the free fix set is now being applied to your site’s rendered pages.', 'equalfix' ) );
	}

	/**
	 * Flip the master fix switch (dashboard hero toggle, 1.1).
	 * Purges like every fix-state change; stamps the activation
	 * time when switching on with the full free set enabled.
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
		} else {
			$settings['enabled'] = 1;
			if ( ! $any_type_on ) {
				foreach ( $type_keys as $key ) {
					$settings[ $key ] = 1;
				}
			}
			if ( $this->all_free_fixes_on_types( $settings ) && empty( $settings['fixes_activated_at'] ) ) {
				$settings['fixes_activated_at'] = time();
			}
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
	 * outcome back to the Pro tab with an honest notice.
	 */
	private function install_pro() {
		$key     = isset( $_POST['equalfix_pro_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['equalfix_pro_key'] ) ) ) : '';
		$result  = EqualFix_Pro_Installer::install_pro( $key );
		if ( $result['ok'] ) {
			$this->redirect( 'pro-licence', __( 'Pro is installed and active. Your licence key was handed to Pro and is being validated — if Pro does not show as active within a moment, paste the key once more under Pro → Licence.', 'equalfix' ) );
		}
		if ( isset( $result['code'] ) && 'subscription_inactive' === $result['code'] && '' !== $key ) {
			// The key is real and active, but its plan cannot
			// download Pro (a free account key). Offer it a
			// second job instead: the free AI preview (1.3).
			// Held in a short-lived transient, never in a URL.
			set_transient( 'equalfix_preview_candidate', $key, 10 * MINUTE_IN_SECONDS );
		}
		$this->redirect_error( 'pro', $result['message'] );
	}

	/**
	 * Adopt the candidate key (a validated free account key
	 * that could not download Pro) as the AI preview key.
	 */
	private function use_preview_key() {
		$key = (string) get_transient( 'equalfix_preview_candidate' );
		delete_transient( 'equalfix_preview_candidate' );
		if ( '' === $key ) {
			$this->redirect_error( 'pro', __( 'That offer expired — paste your key above and try again.', 'equalfix' ) );
		}
		$check = EqualFix_Pro_Installer::validate_key( $key );
		if ( empty( $check['ok'] ) ) {
			$this->redirect_error( 'pro', $check['message'] );
		}
		update_option( self::PREVIEW_KEY_OPTION, $key );
		$this->redirect( 'findings', __( 'Free AI preview is on for this site. Use the AI preview card below the alt-text coverage to draft alt text for your undescribed images.', 'equalfix' ) );
	}

	/**
	 * Generate (or regenerate) the AI preview drafts.
	 */
	private function generate_ai_preview_action() {
		$result = self::generate_ai_preview();
		if ( $result['ok'] ) {
			$this->redirect( 'findings', sprintf( __( 'AI preview ready: %d draft(s) below. They are a preview only — Pro applies alt text properly through its approval queue.', 'equalfix' ), $result['count'] ) );
		}
		$this->redirect_error( 'findings', $result['message'] );
	}

	/**
	 * Remove the preview key and any stored drafts.
	 */
	private function clear_ai_preview_action() {
		delete_option( self::PREVIEW_KEY_OPTION );
		delete_option( self::AI_PREVIEW_OPTION );
		$this->redirect( 'findings', __( 'AI preview removed — the key and drafts were deleted from this site.', 'equalfix' ) );
	}

	/**
	 * The images the AI preview can draft for: the latest
	 * audit's missing-alt findings, deduped by image URL,
	 * capped at the service's batch size.
	 *
	 * @return array List of {ref, image_url, page_url}.
	 */
	public static function ai_preview_candidates() {
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $result['findings'] as $finding ) {
			if ( ! isset( $finding['category'], $finding['title'] ) || 'review' !== $finding['category'] ) {
				continue;
			}
			if ( false === stripos( $finding['title'], 'missing alt text' ) ) {
				continue;
			}
			$src = self::img_src_from_snippet( isset( $finding['snippet'] ) ? $finding['snippet'] : '' );
			$abs = EqualFix_Pro_Installer::absolute_url( $src, isset( $finding['url'] ) ? $finding['url'] : '' );
			if ( '' === $abs || isset( $seen[ $abs ] ) ) {
				continue;
			}
			$seen[ $abs ] = true;
			$out[]        = array(
				'ref'       => 'pv' . count( $out ),
				'image_url' => $abs,
				'page_url'  => isset( $finding['url'] ) ? $finding['url'] : '',
			);
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Run one AI preview generation against the service and
	 * store the drafts. Drafts only — nothing is applied.
	 *
	 * @return array {ok, count, message}.
	 */
	public static function generate_ai_preview() {
		$key = trim( (string) get_option( self::PREVIEW_KEY_OPTION, '' ) );
		if ( '' === $key ) {
			return array( 'ok' => false, 'count' => 0, 'message' => __( 'Add a free account key first — paste it on the Pro tab.', 'equalfix' ) );
		}
		$candidates = self::ai_preview_candidates();
		if ( empty( $candidates ) ) {
			return array( 'ok' => false, 'count' => 0, 'message' => __( 'The latest audit has no images missing alt text to preview.', 'equalfix' ) );
		}
		$drafts = EqualFix_Pro_Installer::vision_drafts( $key, $candidates );
		if ( empty( $drafts ) ) {
			return array( 'ok' => false, 'count' => 0, 'message' => __( 'The AI service did not return any drafts just now. Your key and site registration are the usual causes — check the key is registered to this site in your EqualFix dashboard, then try again.', 'equalfix' ) );
		}
		$by_ref = array();
		foreach ( $candidates as $cand ) {
			$by_ref[ $cand['ref'] ] = $cand;
		}
		$items = array();
		foreach ( $drafts as $draft ) {
			if ( ! isset( $by_ref[ $draft['ref'] ] ) ) {
				continue;
			}
			$items[] = array(
				'page_url'             => $by_ref[ $draft['ref'] ]['page_url'],
				'img_src'              => $by_ref[ $draft['ref'] ]['image_url'],
				'alt'                  => $draft['alt'],
				'confidence'           => $draft['confidence'],
				'decorative_candidate' => $draft['decorative_candidate'],
			);
		}
		$audit = get_option( self::AUDIT_OPTION, null );
		update_option(
			self::AI_PREVIEW_OPTION,
			array(
				'time'       => current_time( 'mysql' ),
				'audit_time' => is_array( $audit ) && isset( $audit['time'] ) ? $audit['time'] : '',
				'items'      => $items,
			)
		);
		return array( 'ok' => true, 'count' => count( $items ), 'message' => '' );
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
	 * Dismiss one finding from the Findings tab, with its reason.
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
		$cache->purge_all( __( 'Fix type disabled from the Fixed tab.', 'equalfix' ) );
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
	 * Redirect to the Findings tab after a row action, keeping
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
		if ( $was_off && empty( $settings['fixes_activated_at'] ) ) {
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
					$this->redirect_findings( __( 'Marked decorative — screen readers will skip this image (alt="").', 'equalfix' ) );
				}
			}
			// No queue item could be created for it (no signal):
			// fall through to the free manual rule below.
		}
		if ( self::save_manual_for_finding( 'alt', $finding, '', true ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Image marked decorative from Findings.', 'equalfix' ) );
			$this->redirect_findings( __( 'Done — this image is now treated as decorative (alt="") on that page.', 'equalfix' ) );
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
		if ( self::save_manual_for_finding( $kind, $finding, $text ) ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Owner-written fix saved from Findings.', 'equalfix' ) );
			$this->redirect_findings( __( 'Saved — your words are now applied to that element as the page loads.', 'equalfix' ) );
		}
		$this->redirect_findings( __( 'That fix could not be saved.', 'equalfix' ), true );
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
			if ( EqualFix_Audit::CAT_FIX_A !== $finding['category'] ) {
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
	 * @return array|null { fixed: array, waiting: array, approved_rows: array } or null.
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
		foreach ( $result['findings'] as $finding ) {
			$fp = self::finding_fingerprint( $finding );
			if ( isset( $dismissed[ $fp ] ) ) {
				$model['dismissed']++;
				continue;
			}
			$model['total']++;
			$fixed = false;
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
			if ( ! $fixed && null !== $coverage && ! empty( $coverage['fixed'][ $fp ] ) ) {
				$fixed = true;
			}
			if ( $fixed ) {
				$model['fixed']++;
				$model['fixed_fps'][ $fp ] = true;
				continue;
			}
			if ( null !== $coverage && ! empty( $coverage['waiting'][ $fp ] ) ) {
				$model['waiting']++;
				$model['waiting_fps'][ $fp ] = true;
				continue;
			}
			$model['todo']++;
		}
		return $model;
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
			if ( isset( $dismissed[ $fp ] ) || isset( $model['fixed_fps'][ $fp ] ) || isset( $model['waiting_fps'][ $fp ] ) ) {
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
			if ( EqualFix_Audit::CAT_REVIEW === $finding['category'] && ( false !== strpos( $finding['title'], 'missing alt text' ) || false !== strpos( $finding['title'], 'has no associated label' ) || false !== strpos( $finding['title'], 'has no accessible name' ) ) ) {
				$n++;
			}
		}
		return $n;
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
		$crawler  = new EqualFix_Crawler();
		$state    = array(
			'token'          => wp_generate_password( 12, false ),
			'urls'           => $crawler->discover_urls(),
			'pages'          => array(),
			'findings'       => array(),
			'total'          => 0,
			'ok'             => 0,
			'rendered'       => 0,
			'fetch_error'    => '',
			'previews'       => array(),
			'preview_tried'  => array(),
			'previous_total' => ( is_array( $previous ) && isset( $previous['total'] ) ) ? (int) $previous['total'] : null,
			'previous_time'  => ( is_array( $previous ) && isset( $previous['time'] ) ) ? $previous['time'] : null,
			'steps'          => 0,
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
		$url   = array_shift( $state['urls'] );
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
			// The first fetch failure of the run, verbatim (transport
			// message or HTTP status). The results screen shows it
			// when nothing could be scanned, so a failed audit is
			// diagnosable instead of mysterious.
			'fetch_error'   => mb_substr( wp_strip_all_tags( (string) $state['fetch_error'] ), 0, 300 ),
			// Repeated-page detection (1.4): non-null when most
			// fetched pages were one identical holding page.
			'wall'          => $this->detect_wall( $state['pages'], $state['ok'] ),
		);
		if ( null !== $state['previous_total'] ) {
			$result['previous_total'] = $state['previous_total'];
			$result['previous_time']  = $state['previous_time'];
		}
		update_option( self::AUDIT_OPTION, $result, false );
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
		if ( 0 === $state['ok'] ) {
			// Nothing was scanned: never report that as a completed
			// audit. The Audit tab renders the full explanation.
			$this->redirect( 'audit', __( 'Audit failed: none of your pages could be fetched, so nothing was scanned. This is not a clean result — see the Audit tab for what to do next.', 'equalfix' ) );
		}
		if ( ! empty( $result['wall'] ) ) {
			$this->redirect( 'audit', sprintf( /* translators: 1: repeated pages, 2: pages fetched */ __( 'Audit limited: %1$d of the %2$d pages fetched returned exactly the same page — your site is likely showing a coming-soon or maintenance page to visitors, so most pages were not really checked. See the Overview for details.', 'equalfix' ), (int) $result['wall']['repeated'], (int) $result['wall']['of'] ) );
		}
		$message = sprintf(
			/* translators: 1: findings phrase, 2: pages phrase */
			__( 'Audit complete: %1$s across %2$s.', 'equalfix' ),
			sprintf( _n( '%d finding', '%d findings', $state['total'], 'equalfix' ), $state['total'] ),
			sprintf( _n( '%d page', '%d pages', $state['ok'], 'equalfix' ), $state['ok'] )
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
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'equalfix' ) );
		}
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		if ( 'audit' === $tab ) {
			// The pre-1.2 tab name: the audit continuation chain and
			// older links land here; it is the Overview tab now.
			$tab = 'overview';
		}
		$settings = self::get();
		$pro      = self::pro_state();
		?>
		<div class="wrap equalfix-wrap">
			<div class="equalfix-header">
				<img class="equalfix-logo" src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/equalfix-logo-128.png' ); ?>" alt="" width="52" height="52">
				<div class="equalfix-header-text">
					<h1><?php esc_html_e( 'EqualFix Accessibility', 'equalfix' ); ?> <span class="equalfix-version">v<?php echo esc_html( EQUALFIX_VERSION ); ?></span></h1>
					<p><?php esc_html_e( 'Detect accessibility issues on your site and apply safe, reversible fixes to the rendered HTML. Nothing is written to your theme files or database content.', 'equalfix' ); ?></p>
				</div>
				<div class="equalfix-header-status">
					<?php if ( $settings['enabled'] ) : ?>
						<span class="equalfix-status equalfix-status-on"><span class="equalfix-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixes active', 'equalfix' ); ?></span>
					<?php else : ?>
						<span class="equalfix-status equalfix-status-off"><span class="equalfix-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixes off', 'equalfix' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( isset( $_GET['equalfix_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['equalfix_notice'] ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['equalfix_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['equalfix_error'] ) ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'EqualFix sections', 'equalfix' ); ?>">
				<a href="<?php echo esc_url( $this->tab_url( 'overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>" class="nav-tab <?php echo 'findings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Findings', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'fixed' ) ); ?>" class="nav-tab <?php echo 'fixed' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Fixed', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-overview' : 'pro' ) ); ?>" class="nav-tab <?php echo ( 'pro' === $tab || 0 === strpos( $tab, 'pro-' ) ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Pro', 'equalfix' ); ?><?php if ( $pro['licence_active'] ) : ?> <span class="eqf-tab-dot" aria-hidden="true"></span><?php endif; ?><?php if ( ! empty( $pro['update_available'] ) ) : ?> <span class="eqf-tab-update"><?php esc_html_e( 'Update', 'equalfix' ); ?></span><?php endif; ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'equalfix' ); ?></a>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings( $settings );
			} elseif ( 'purge' === $tab ) {
				$this->render_purge();
			} elseif ( 'pro' === $tab ) {
				$this->render_pro_tab( $pro );
			} elseif ( 0 === strpos( $tab, 'pro-' ) ) {
				$this->render_pro_shell( substr( $tab, 4 ), $pro );
			} elseif ( 'findings' === $tab || 'fixed' === $tab ) {
				$this->render_audit( $tab );
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
	private function tab_url( $tab ) {
		// Settings and Purge log are their own submenu pages (they
		// force their tab when rendering); every 1.2 tab lives on
		// the main dashboard page as a ?tab= view.
		$slugs = array(
			'settings' => 'equalfix-settings',
			'purge'    => 'equalfix-purge-log',
		);
		if ( isset( $slugs[ $tab ] ) ) {
			return add_query_arg( array( 'page' => $slugs[ $tab ] ), admin_url( 'admin.php' ) );
		}
		if ( 'audit' === $tab || 'overview' === $tab ) {
			return add_query_arg( array( 'page' => 'equalfix-accessibility' ), admin_url( 'admin.php' ) );
		}
		return add_query_arg( array( 'page' => 'equalfix-accessibility', 'tab' => $tab ), admin_url( 'admin.php' ) );
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
						<p class="description"><?php esc_html_e( 'Fixes are applied at render time only. Deactivating this plugin restores your original output immediately.', 'equalfix' ); ?></p>
					</td>
				</tr>
			</table>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Fix types (the complete set of free fixes)', 'equalfix' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each fix type can be previewed on the Audit tab and enabled or disabled individually. Disabling a fix type returns that output to its original form immediately.', 'equalfix' ); ?></p>
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
					<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'equalfix' ); ?></th>
					<td>
						<label for="equalfix_remove_data_on_uninstall">
							<input type="checkbox" id="equalfix_remove_data_on_uninstall" name="equalfix_remove_data_on_uninstall" value="1" <?php checked( $settings['remove_data_on_uninstall'], 1 ); ?>>
							<?php esc_html_e( 'Erase all EqualFix data when the plugin is deleted.', 'equalfix' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default: your audits, settings, dismissed findings, and your own fix rules are kept when you delete the plugin, so reinstalling picks up where you left off. Turn this on only if you want deleting the plugin to erase everything.', 'equalfix' ); ?></p>
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
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Only when you ask it to: pasting a licence key on the Pro tab and clicking Install sends that key and your site URL to the EqualFix service to validate the key and download the Pro add-on. If you store a free account key and click "Generate AI preview" on the Findings tab, the plugin also sends that key, your site URL, and the URLs of audited images missing alt text to the same service to draft preview alt text. If Pro is installed, Pro also validates its licence and — only for images you ask it to draft alt text for — uses the EqualFix vision service (see Pro\'s own disclosure). The free plugin never sends page content, visitor, customer, order, or personal data.', 'equalfix' ); ?> <a href="https://equalfix.com/privacy" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'equalfix' ); ?></a> · <a href="https://equalfix.com/terms" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of Service', 'equalfix' ); ?></a></p>
					</td>
				</tr>
			</table>
			</div>

			<?php submit_button( __( 'Save settings', 'equalfix' ) ); ?>
		</form>

		<div class="equalfix-card">
			<h2><?php esc_html_e( 'Advanced', 'equalfix' ); ?></h2>
			<p><a href="<?php echo esc_url( $this->tab_url( 'purge' ) ); ?>"><?php esc_html_e( 'Cache log — advanced', 'equalfix' ); ?></a> — <?php esc_html_e( 'the record of every cache purge on this site, kept for troubleshooting.', 'equalfix' ); ?></p>
		</div>

		<?php if ( $pro['licence_active'] ) : ?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Pro is active on this site', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'Extra fixes, the approval queue, monitoring, and reports are running — manage them from the Pro tab in this dashboard.', 'equalfix' ); ?></p>
			</div>
			<div class="equalfix-pro-side">
				<p><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-licence' : 'pro' ) ); ?>" class="button equalfix-pro-cta"><?php esc_html_e( 'Open Pro', 'equalfix' ); ?></a></p>
			</div>
		</div>
		<?php elseif ( $pro['present'] ) : ?>
		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Pro is installed — activate it to finish', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'The Pro add-on is on this site, but its licence is not active, so Pro features are off. Paste your key in the Pro tab to switch everything on.', 'equalfix' ); ?></p>
			</div>
			<div class="equalfix-pro-side">
				<p><a href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>" class="button equalfix-pro-cta"><?php esc_html_e( 'Activate Pro', 'equalfix' ); ?></a></p>
			</div>
		</div>
		<?php else : ?>
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
				<p class="equalfix-pro-price-note"><?php esc_html_e( 'One site · $99/mo for 5 sites · $199/mo for 15', 'equalfix' ); ?><br><?php esc_html_e( 'Annual billing saves 15%', 'equalfix' ); ?></p>
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
			$notes[] = sprintf(
				/* translators: 1: change, 2: date */
				__( 'Change since %2$s: %+d findings.', 'equalfix' ),
				$result['total'] - $result['previous_total'],
				$result['previous_time']
			);
		}
		$all_on = $this->all_free_fixes_on();
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
			<?php if ( empty( $result['wall'] ) ) : ?>
				<?php $this->render_journey( $result, $counts, $queue_eligible ); ?>
			<?php endif; ?>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( 'fixed' === $section ) : ?>
			<?php $this->render_fixed_tab( $result, $settings ); ?>
			<?php return; ?>
		<?php endif; ?>

		<?php
		// Final touch-up: the Findings tab's drill-downs read the
		// SAME coverage model as the Overview — the pages card's
		// per-page fixed counts and the show=todo filter are the
		// model's buckets, never a second partition.
		$findings_model = $this->coverage_model( $result, $settings );
		$show_todo      = isset( $_GET['show'] ) && 'todo' === sanitize_key( wp_unslash( $_GET['show'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view filter.
		$this->render_pages_card( $result, $findings_model );
		?>

		<?php $this->render_alt_coverage_card( $result ); ?>
		<?php $this->render_ai_preview_card( $result ); ?>

		<?php if ( ! $all_on ) : ?>
			<?php $this->render_fix_previews(); ?>
		<?php endif; ?>

		<h3 id="equalfix-findings"><?php esc_html_e( 'Findings', 'equalfix' ); ?></h3>
		<?php
		// Next batch (Findings redesign, Direction A): the working
		// list is the coverage model's partition — dismissed
		// findings leave the list (they live in the Set aside
		// section below), and every count here is the model's.
		$dismissed_map   = self::dismissed_map();
		$active_findings = array();
		foreach ( (array) $result['findings'] as $finding ) {
			if ( ! isset( $dismissed_map[ self::finding_fingerprint( $finding ) ] ) ) {
				$active_findings[] = $finding;
			}
		}
		// show=todo (the Overview's "Still to do" tile lands here)
		// narrows the working list to the model's TO DO bucket —
		// todo_findings() over the same model, so the list below
		// always matches the tile's number.
		$todo_list = array();
		if ( $show_todo ) {
			$todo_list       = $this->todo_findings( $result, $findings_model );
			$active_findings = $todo_list;
		}
		?>
		<?php if ( $show_todo ) : ?>
			<?php $this->render_todo_banner( $todo_list, $settings ); ?>
		<?php endif; ?>
		<?php if ( empty( $result['findings'] ) ) : ?>
			<p><?php esc_html_e( 'No issues found by the automated checks. Note: automated checks cannot catch everything — some issues still need human review.', 'equalfix' ); ?></p>
		<?php else : ?>
			<?php
			// Pages audited via in-process simulation (Q2 badge):
			$rendered_urls = array();
			if ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) {
				foreach ( $result['pages'] as $page ) {
					if ( isset( $page['mode'] ) && 'render' === $page['mode'] && ! empty( $page['url'] ) ) {
						$rendered_urls[] = $page['url'];
					}
				}
			}
			?>
			<?php if ( ! $show_todo && $findings_model['total'] > 0 ) : ?>
				<p class="eqf-findings-intro"><?php echo esc_html( sprintf( __( '%1$d fixed · %2$d waiting for your OK · %3$d set aside. Fixed counts as fixed only when the fix is actually switched on — the Fixed tab is the record.', 'equalfix' ), $findings_model['fixed'], $findings_model['waiting'], $findings_model['dismissed'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $active_findings ) ) : ?>
				<?php if ( $show_todo ) : ?>
					<p><?php esc_html_e( 'Nothing is still to do — everything from the last audit is fixed, waiting for your OK, or set aside.', 'equalfix' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Everything in the latest audit is currently set aside. The Set aside section below lists each one with its reason, and restoring one brings it back to this list.', 'equalfix' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( ! $show_todo && 0 === $findings_model['todo'] && $findings_model['total'] > 0 ) : ?>
					<?php $this->render_all_done_card( $result, $findings_model ); ?>
				<?php endif; ?>
				<?php $this->render_job_cards( $active_findings, $findings_model, $settings, $show_todo, $rendered_urls ); ?>
			<?php endif; ?>
			<?php $this->render_toggle_script(); ?>
		<?php endif; ?>
		<?php $this->render_dismissed_section(); ?>

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
			$meta['group']   = 'embed-3p';
			$meta['name']    = __( 'Third-party embeds', 'equalfix' );
			$meta['problem'] = __( 'This third-party embed has no title.', 'equalfix' );
			$meta['kind']    = 'embed';
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
		if ( 'Alt text is just a file name' === $title ) {
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
		return $meta;
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
		if ( $model['fixed'] > 0 ) {
			$parts[] = sprintf( _n( '%d fixed', '%d fixed', $model['fixed'], 'equalfix' ), $model['fixed'] );
		}
		if ( $model['waiting'] > 0 ) {
			$parts[] = sprintf( _n( '%d waiting for your OK in Pro → AI suggestions', '%d waiting for your OK in Pro → AI suggestions', $model['waiting'], 'equalfix' ), $model['waiting'] );
		}
		if ( $model['dismissed'] > 0 ) {
			$parts[] = sprintf( _n( '%d set aside', '%d set aside', $model['dismissed'], 'equalfix' ), $model['dismissed'] );
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
	 */
	private function render_job_cards( $findings, $model, $settings, $open, $rendered_urls ) {
		$groups = array();
		foreach ( $findings as $finding ) {
			$meta = $this->finding_job_meta( $finding );
			$key  = $meta['group'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'meta' => $meta, 'items' => array(), 'todo' => 0, 'waiting' => 0, 'fixed' => 0, 'pages' => array() );
			}
			$fp = self::finding_fingerprint( $finding );
			if ( isset( $model['fixed_fps'][ $fp ] ) ) {
				$groups[ $key ]['fixed']++;
			} elseif ( isset( $model['waiting_fps'][ $fp ] ) ) {
				$groups[ $key ]['waiting']++;
			} else {
				$groups[ $key ]['todo']++;
			}
			$groups[ $key ]['items'][]            = $finding;
			$groups[ $key ]['pages'][ $finding['url'] ] = true;
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
		$pro = self::pro_state();
		// Free AI-preview drafts (when the owner generated them)
		// prefill the matching write-it-myself inputs.
		$preview_map = array();
		$preview     = get_option( self::AI_PREVIEW_OPTION, null );
		$audit       = get_option( self::AUDIT_OPTION, null );
		if ( is_array( $preview ) && ! empty( $preview['items'] ) && is_array( $audit ) && isset( $preview['audit_time'], $audit['time'] ) && $preview['audit_time'] === $audit['time'] ) {
			foreach ( $preview['items'] as $item ) {
				if ( ! empty( $item['img_src'] ) && isset( $item['alt'] ) ) {
					$preview_map[ self::manual_src_key( $item['img_src'] ) ] = (string) $item['alt'];
				}
			}
		}
		$ctx = array(
			'pro'           => $pro,
			'settings'      => $settings,
			'show_todo'     => $open,
			'preview_map'   => $preview_map,
			'queue_url'     => $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ),
			'licence_url'   => $this->tab_url( $pro['shell'] ? 'pro-licence' : 'pro' ),
			'fixb_url'      => $this->tab_url( $pro['shell'] ? 'pro-fixb' : 'pro' ),
			'rendered_urls' => $rendered_urls,
		);
		$receipts = array();
		?>
		<div class="equalfix-findings eqf-jobcards">
			<?php foreach ( $groups as $group ) : ?>
				<?php
				if ( 0 === $group['todo'] && 0 === $group['waiting'] ) {
					$receipts[] = $group;
					continue;
				}
				$meta  = $group['meta'];
				$pages = count( $group['pages'] );
				if ( $group['todo'] > 0 ) {
					$count_line = sprintf( _n( '%1$d still to do · on %2$d page', '%1$d still to do · on %2$d pages', $group['todo'], 'equalfix' ), $group['todo'], $pages );
				} else {
					$count_line = sprintf( _n( '%1$d waiting for your OK · on %2$d page', '%1$d waiting for your OK · on %2$d pages', $group['waiting'], 'equalfix' ), $group['waiting'], $pages );
				}
				if ( $group['todo'] > 0 && $group['waiting'] > 0 ) {
					$count_line .= ' · ' . sprintf( _n( '%d waiting', '%d waiting', $group['waiting'], 'equalfix' ), $group['waiting'] );
				}
				if ( $group['fixed'] > 0 ) {
					$count_line .= ' · ' . sprintf( _n( '%d already fixed', '%d already fixed', $group['fixed'], 'equalfix' ), $group['fixed'] );
				}
				?>
				<details class="eqf-group eqf-pagegroup eqf-jobcard"<?php echo $open ? ' open' : ''; ?>>
					<summary class="eqf-pagehead">
						<span class="eqf-rule-title"><?php echo esc_html( $meta['name'] ); ?></span>
						<span class="eqf-pagecount"><?php echo esc_html( $count_line ); ?></span>
					</summary>
					<div class="eqf-pagebody">
						<?php if ( 'draft' === $meta['kind'] && $group['todo'] >= 2 && ! empty( $pro['licence_active'] ) ) : ?>
							<div class="eqf-bulkbar">
								<form method="post" action="">
									<?php wp_nonce_field( 'equalfix_settings' ); ?>
									<input type="hidden" name="equalfix_action" value="draft_all_with_ai">
									<?php if ( $open ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
									<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( __( 'Draft all %d with AI', 'equalfix' ), $group['todo'] ) ); ?></button>
								</form>
								<span class="eqf-bulk-note"><?php esc_html_e( 'Drafts go to Pro → AI suggestions for your approval — nothing goes live without you.', 'equalfix' ); ?></span>
							</div>
						<?php endif; ?>
						<?php
						$shown = 0;
						$total = count( $group['items'] );
						foreach ( $group['items'] as $finding ) :
							$fp    = self::finding_fingerprint( $finding );
							$state = isset( $model['fixed_fps'][ $fp ] ) ? 'fixed' : ( isset( $model['waiting_fps'][ $fp ] ) ? 'waiting' : 'todo' );
							if ( 3 === $shown && $total > 4 ) :
								?>
								<details class="eqf-group-more">
									<summary><?php echo esc_html( sprintf( __( 'Show all %d', 'equalfix' ), $total ) ); ?></summary>
								<?php
							endif;
							$this->render_job_row( $finding, $state, $meta, $ctx );
							$shown++;
						endforeach;
						if ( $total > 4 ) :
							?>
							</details>
							<?php
						endif;
						?>
					</div>
				</details>
			<?php endforeach; ?>
			<?php foreach ( $receipts as $group ) : ?>
				<div class="eqf-receipt">
					<span class="eqf-receipt-text"><?php echo esc_html( sprintf( __( '✓ %1$s — %2$d fixed', 'equalfix' ), $group['meta']['name'], $group['fixed'] ) ); ?></span>
					<a href="<?php echo esc_url( $this->tab_url( 'fixed' ) ); ?>"><?php esc_html_e( 'See them', 'equalfix' ); ?></a>
				</div>
			<?php endforeach; ?>
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
	 */
	private function render_job_row( $finding, $state, $meta, $ctx ) {
		$fp = self::finding_fingerprint( $finding );
		?>
		<article class="eqf-row eqf-row-<?php echo esc_attr( $state ); ?>">
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
			<?php if ( 'fixed' === $state ) : ?>
				<p class="eqf-row-fixed"><?php echo esc_html( sprintf( __( '✓ Fixed — %s', 'equalfix' ), $this->fixed_how_text( $finding ) ) ); ?></p>
			<?php elseif ( 'waiting' === $state ) : ?>
				<div class="eqf-row-actions">
					<span class="eqf-row-waiting"><?php esc_html_e( 'Draft ready — waiting for your OK', 'equalfix' ); ?></span>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['queue_url'] ); ?>"><?php esc_html_e( 'Review the draft', 'equalfix' ); ?></a>
				</div>
			<?php else : ?>
				<?php $this->render_row_actions( $finding, $meta, $ctx ); ?>
			<?php endif; ?>
			<div class="eqf-row-quiet">
				<?php $this->render_finding_details( $finding ); ?>
				<?php $this->render_set_aside_panel( $finding, $ctx ); ?>
			</div>
		</article>
		<?php
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
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param array $ctx     Render context.
	 */
	private function render_row_actions( $finding, $meta, $ctx ) {
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
					?>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['fixb_url'] ); ?>"><?php esc_html_e( 'Turn on this fix', 'equalfix' ); ?></a>
					<?php
				} elseif ( ! empty( $pro['present'] ) ) {
					?>
					<a class="button button-primary" href="<?php echo esc_url( $ctx['licence_url'] ); ?>"><?php esc_html_e( 'Add your licence key', 'equalfix' ); ?></a>
					<?php $this->render_guidance_toggle( $finding, $meta );
				} else {
					?>
					<div class="eqf-upsell">
						<p><?php esc_html_e( 'Included in Pro: heading order fixed automatically, plus monitoring and reports.', 'equalfix' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro plans', 'equalfix' ); ?></a>
					</div>
					<?php
					$this->render_guidance_toggle( $finding, $meta );
				}
				break;
			case 'draft':
				if ( ! empty( $pro['licence_active'] ) ) {
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
				$this->render_write_toggle( $finding, $meta, $ctx, false );
				if ( 'alt' === $meta['manual_kind'] ) {
					$this->render_decorative_toggle( $finding, $ctx );
				}
				break;
			case 'manual-first':
				$this->render_write_toggle( $finding, $meta, $ctx, true );
				if ( 'alt' === $meta['manual_kind'] ) {
					$this->render_decorative_toggle( $finding, $ctx );
				}
				break;
			case 'guidance':
				$this->render_guidance_toggle( $finding, $meta, true );
				break;
			case 'embed':
				?>
				<p class="eqf-row-note"><?php esc_html_e( 'Only the embed provider can fix inside their code.', 'equalfix' ); ?> <a href="<?php echo esc_url( $finding['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See it on the page ↗', 'equalfix' ); ?></a></p>
				<?php
				break;
		}
		?>
		</div>
		<?php
	}

	/**
	 * The "write it myself" toggle + inline form (Direction A):
	 * one labelled input, prefilled from a free AI-preview draft
	 * when one exists for this element, saved to the free manual
	 * store by save_manual_text.
	 *
	 * @param array $finding Finding.
	 * @param array $meta    Job meta.
	 * @param array $ctx     Render context.
	 * @param bool  $primary Render the toggle as the primary button.
	 */
	private function render_write_toggle( $finding, $meta, $ctx, $primary ) {
		$kind   = $meta['manual_kind'];
		if ( null === $kind ) {
			return;
		}
		$fp     = self::finding_fingerprint( $finding );
		$labels = array(
			'alt'   => __( 'Write the description', 'equalfix' ),
			'label' => __( 'Add the label', 'equalfix' ),
			'name'  => 'name-button' === $meta['group'] ? __( 'Name this button', 'equalfix' ) : __( 'Name this link', 'equalfix' ),
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
		$prefill = '';
		if ( 'alt' === $kind ) {
			$src = self::img_src_from_snippet( $finding['snippet'] );
			$abs = '' !== $src ? EqualFix_Pro_Installer::absolute_url( $src, $finding['url'] ) : '';
			$key = self::manual_src_key( '' !== $abs ? $abs : $src );
			if ( isset( $ctx['preview_map'][ $key ] ) ) {
				$prefill = $ctx['preview_map'][ $key ];
			}
		}
		$panel_id = 'eqf-write-' . $fp;
		?>
		<button type="button" class="button <?php echo $primary ? 'button-primary' : 'button-secondary'; ?> eqf-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php echo esc_html( $labels[ $kind ] ); ?></button>
		<div class="eqf-write" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<form method="post" action="" class="eqf-write-form">
				<?php wp_nonce_field( 'equalfix_settings' ); ?>
				<input type="hidden" name="equalfix_action" value="save_manual_text">
				<input type="hidden" name="equalfix_fingerprint" value="<?php echo esc_attr( $fp ); ?>">
				<input type="hidden" name="equalfix_manual_kind" value="<?php echo esc_attr( $kind ); ?>">
				<?php if ( $ctx['show_todo'] ) : ?><input type="hidden" name="equalfix_show_todo" value="1"><?php endif; ?>
				<label for="eqf-text-<?php echo esc_attr( $fp ); ?>"><?php echo esc_html( $fields[ $kind ][0] ); ?></label>
				<input type="text" id="eqf-text-<?php echo esc_attr( $fp ); ?>" name="equalfix_manual_text" value="<?php echo esc_attr( $prefill ); ?>" placeholder="<?php echo esc_attr( $fields[ $kind ][1] ); ?>" maxlength="300">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and apply', 'equalfix' ); ?></button>
				<?php if ( '' !== $prefill ) : ?>
					<p class="description"><?php esc_html_e( 'Prefilled from your free AI preview draft — edit it freely before saving.', 'equalfix' ); ?></p>
				<?php endif; ?>
			</form>
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
	 */
	private function render_decorative_toggle( $finding, $ctx ) {
		$fp       = self::finding_fingerprint( $finding );
		$panel_id = 'eqf-deco-' . $fp;
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
	 */
	private function render_guidance_toggle( $finding, $meta, $primary = false ) {
		if ( empty( $meta['steps'] ) ) {
			return;
		}
		$fp       = self::finding_fingerprint( $finding );
		$panel_id = 'eqf-guide-' . $fp;
		?>
		<button type="button" class="button <?php echo $primary ? 'button-primary' : 'button-secondary'; ?> eqf-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Show me how to fix it', 'equalfix' ); ?></button>
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
	 * click. Rendered on the Findings tab; empty = silent.
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
	 * model's TO DO set only.
	 *
	 * @param array $todo_list The model's TO DO findings.
	 * @param array $settings  Current settings.
	 */
	private function render_todo_banner( $todo_list, $settings ) {
		$todo_result = array( 'findings' => $todo_list );
		$parts       = $this->partition_fix_a( $todo_result, $settings );
		$free_now    = count( $parts[1] );
		$draftable   = $this->queue_eligible_count( $todo_list );
		$words       = max( 0, count( $todo_list ) - $free_now - $draftable );
		$pro         = self::pro_state();
		?>
		<div class="eqf-todo-banner">
			<strong><?php echo esc_html( sprintf( __( 'Still to do: %1$d — %2$d can be fixed free · %3$d can be drafted by AI · %4$d need your words', 'equalfix' ), count( $todo_list ), $free_now, $draftable, $words ) ); ?></strong>
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
			foreach ( (array) get_option( 'equalfix_pro_queue', array() ) as $item ) {
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
					<p><?php esc_html_e( 'Writing alt text for every image by hand is slow. Pro\'s AI Vision drafts it for you — it reads each image in the context of your page — into an approval queue. Nothing is applied until you approve it.', 'equalfix' ); ?> <a href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>"><?php esc_html_e( 'See the Pro tab', 'equalfix' ); ?></a></p>
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
	/**
	 * The free AI preview card (1.3): with a free account key,
	 * the service drafts alt text for up to 10 of the audit's
	 * undescribed images, shown here as a read-only preview.
	 * Applying drafts is Pro's job (the approval queue) — this
	 * card never changes the site. Hidden once Pro is active,
	 * because the queue supersedes it.
	 *
	 * @param array $result Stored audit result.
	 */
	private function render_ai_preview_card( $result ) {
		$missing = 0;
		foreach ( (array) $result['findings'] as $finding ) {
			if ( isset( $finding['category'], $finding['title'] ) && 'review' === $finding['category'] && false !== stripos( $finding['title'], 'missing alt text' ) ) {
				$missing++;
			}
		}
		if ( 0 === $missing ) {
			return;
		}
		$pro = self::pro_state();
		if ( ! empty( $pro['licence_active'] ) ) {
			return;
		}
		$key     = trim( (string) get_option( self::PREVIEW_KEY_OPTION, '' ) );
		$preview = get_option( self::AI_PREVIEW_OPTION, null );
		$items   = array();
		if ( is_array( $preview ) && isset( $preview['audit_time'], $preview['items'] ) && isset( $result['time'] ) && $preview['audit_time'] === $result['time'] ) {
			$items = (array) $preview['items'];
		}
		?>
		<div class="equalfix-card eqf-aiprev">
			<h3><?php esc_html_e( 'AI alt-text preview', 'equalfix' ); ?> <span class="eqf-badge eqf-badge-ai"><?php esc_html_e( 'Free preview', 'equalfix' ); ?></span></h3>
			<?php if ( '' === $key ) : ?>
				<p><?php echo esc_html( sprintf( __( 'Your audit found %d image(s) with no alt text. With a free EqualFix account key, the EqualFix AI service will draft alt text for up to 10 of them, right here — so you can see exactly what Pro\'s approval queue works with before paying anything.', 'equalfix' ), $missing ) ); ?></p>
				<p><?php esc_html_e( 'Create the free account at equalfix.com, register this site, then paste the key on the Pro tab — a free key cannot download Pro, but it unlocks this preview.', 'equalfix' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $this->tab_url( 'pro' ) ); ?>"><?php esc_html_e( 'Add a key on the Pro tab', 'equalfix' ); ?></a></p>
			<?php elseif ( empty( $items ) ) : ?>
				<p><?php echo esc_html( sprintf( __( 'Your free key is on file. Generate a preview and the service will draft alt text for up to 10 of the %d undescribed image(s) in this audit. Only the image URLs are sent — never the image files — and the drafts change nothing on your site.', 'equalfix' ), $missing ) ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="generate_ai_preview">
					<?php submit_button( __( 'Generate AI preview', 'equalfix' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post" class="eqf-aiprev-remove">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="clear_ai_preview">
					<button type="submit" class="button-link"><?php esc_html_e( 'Remove the key from this site', 'equalfix' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( __( 'Drafts for %d image(s), generated %s. Read them like a reviewer: a draft is only useful if it describes the image accurately. Applying alt text is Pro\'s job — its approval queue writes these as reviewed fix rules.', 'equalfix' ), count( $items ), $preview['time'] ) ); ?></p>
				<div class="eqf-aiprev-list">
					<?php foreach ( $items as $item ) : ?>
						<article class="eqf-aiprev-item">
							<div class="eqf-aiprev-meta">
								<strong><?php echo esc_html( basename( wp_parse_url( $item['img_src'], PHP_URL_PATH ) ) ); ?></strong>
								<a href="<?php echo esc_url( $item['page_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::page_label( $item['page_url'] ) ); ?></a>
								<?php if ( ! empty( $item['decorative_candidate'] ) ) : ?><span class="eqf-badge eqf-badge-deco"><?php esc_html_e( 'Possibly decorative', 'equalfix' ); ?></span><?php endif; ?>
								<?php if ( isset( $item['confidence'] ) && 'low' === $item['confidence'] ) : ?><span class="eqf-badge eqf-badge-low"><?php esc_html_e( 'Low confidence', 'equalfix' ); ?></span><?php endif; ?>
							</div>
							<p class="eqf-aiprev-draft"><?php echo esc_html( '' !== $item['alt'] ? $item['alt'] : __( '(the service thinks this image may be decorative — decorative images should get empty alt text)', 'equalfix' ) ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
				<form method="post" class="eqf-aiprev-actions">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="generate_ai_preview">
					<?php submit_button( __( 'Regenerate', 'equalfix' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" class="eqf-aiprev-remove">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="clear_ai_preview">
					<button type="submit" class="button-link"><?php esc_html_e( 'Remove the key and drafts', 'equalfix' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_fixed_tab( $result, $settings ) {
		$fixed_list = $this->fixed_findings( $result, $settings );
		// 1.5: Pro's approved alt text is part of this record too,
		// via the coverage model — the same source the Overview
		// counts read, so the two can never disagree.
		$model         = $this->coverage_model( $result, $settings );
		$approved_rows = $model['approved_rows'];
		$rules_n       = 0;
		if ( class_exists( 'EqualFix_Pro_Suggestions' ) ) {
			$rules_n = count( EqualFix_Pro_Suggestions::get_rules() ) + count( EqualFix_Pro_Suggestions::get_name_rules() ) + count( EqualFix_Pro_Suggestions::get_label_rules() );
		}
		// Owner-written fixes (next batch): the fourth source in
		// this record — listed only where the model counts them
		// fixed, with a remove control each.
		$manual_map = array_intersect_key( self::manual_matches_map( $result ), $model['fixed_fps'] );
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
					<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Pro approval rule: %d', 'Pro approval rules: %d', $rules_n, 'equalfix' ), $rules_n ) ); ?></span>
					<?php if ( ! empty( $manual_map ) ) : ?>
						<span class="eqf-chip"><?php echo esc_html( sprintf( _n( 'Written by you: %d', 'Written by you: %d', count( $manual_map ), 'equalfix' ), count( $manual_map ) ) ); ?></span>
					<?php endif; ?>
				</p>
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
			?>
			<details class="equalfix-card eqf-fold">
				<summary><?php echo esc_html( sprintf( __( 'Alt text you approved (%d)', 'equalfix' ), count( $approved_rows ) ) ); ?></summary>
				<p class="description"><?php esc_html_e( 'Alt text you approved in the AI suggestions queue, applied at render time like the automatic fixes.', 'equalfix' ); ?></p>
				<ul class="eqf-fixed-list eqf-approved-list">
					<?php foreach ( $approved_rows as $row ) : ?>
						<?php if ( ! is_array( $row ) || ! isset( $row['img_src'], $row['alt'], $row['page_url'] ) ) { continue; } ?>
						<li class="eqf-fixed-row eqf-approved-row">
							<img class="eqf-approved-thumb" src="<?php echo esc_url( $row['img_src'] ); ?>" alt="<?php echo esc_attr( $row['alt'] ); ?>" width="64" height="64" loading="lazy">
							<span class="eqf-approved-text">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: page title (or label) the image appears on */
										__( 'Alt text approved for this image on %s', 'equalfix' ),
										self::page_title_label( $row['page_url'] )
									)
								);
								?>
								<br><span class="eqf-approved-alt">“<?php echo esc_html( $row['alt'] ); ?>”</span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
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
				<p class="description"><?php esc_html_e( 'Words you wrote on the Findings tab, applied at render time like the automatic fixes. Removing one restores the original output.', 'equalfix' ); ?></p>
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
					<p><a href="<?php echo esc_url( $this->tab_url( $pro['shell'] ? 'pro-queue' : 'pro' ) ); ?>"><?php esc_html_e( 'Manage rules in the approval queue', 'equalfix' ); ?></a></p>
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
				<p class="equalfix-pro-price-note"><?php esc_html_e( 'One site · $99/mo for 5 sites · $199/mo for 15', 'equalfix' ); ?><br><?php esc_html_e( 'Annual billing saves 15%', 'equalfix' ); ?></p>
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
		<?php if ( '' !== (string) get_transient( 'equalfix_preview_candidate' ) ) : ?>
			<div class="equalfix-card">
				<h3><?php esc_html_e( 'That key can still do something free', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'The key you just pasted belongs to a free account, so it cannot download Pro — but it can power the free AI alt-text preview: the service drafts alt text for your undescribed images on the Findings tab, so you can judge the drafts before paying anything.', 'equalfix' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'equalfix_settings' ); ?>
					<input type="hidden" name="equalfix_action" value="use_preview_key">
					<?php submit_button( __( 'Use this key for the free AI preview', 'equalfix' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		<?php elseif ( '' !== trim( (string) get_option( self::PREVIEW_KEY_OPTION, '' ) ) ) : ?>
			<div class="equalfix-card">
				<h3><?php esc_html_e( 'Free AI preview is on', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'Your free account key is stored on this site and powers the AI alt-text preview.', 'equalfix' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>"><?php esc_html_e( 'Open the preview on the Findings tab', 'equalfix' ); ?></a></p>
			</div>
		<?php endif; ?>
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
			<?php submit_button( __( 'Run audit', 'equalfix' ), 'primary', 'submit', false ); ?>
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
		$fixed_n     = $model['fixed'];
		$waiting_n   = $model['waiting'];
		$todo_n      = $model['todo'];
		// Draftable-and-still-to-do: the only findings the AI
		// Vision clause below may count. The raw queue-eligible
		// total also counts findings Pro already fixed via
		// approved suggestions, which would contradict the
		// model numbers in the same sentence (found on the
		// real-host verification of this batch: 41 of 45 fixed,
		// yet the clause claimed 40 draftable).
		$draftable_todo = $this->queue_eligible_count( $this->todo_findings( $result, $model ) );
		$remaining_a = max( 0, (int) $counts['fix-a'] - $fixed_a_n );
		$pro         = self::pro_state();
		$addressable = (int) $counts['fix-a'] + (int) $counts['fix-b'] + (int) $queue_eligible;
		$coverage    = $total > 0 ? (int) round( 100 * $addressable / $total ) : null;
		$any_type_on = false;
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$any_type_on = true;
				break;
			}
		}
		$autofix_on = ! empty( $settings['enabled'] ) && $any_type_on;
		$host       = wp_parse_url( home_url(), PHP_URL_HOST );
		$scan_ts    = strtotime( $result['time'] );
		// "Welcome back" only when this genuinely is a return visit:
		// a previous audit exists to come back to (1.1.1 — the first
		// results screen is not a homecoming).
		$history   = get_option( 'equalfix_audit_history', array() );
		$returning = isset( $result['previous_total'] ) || ( is_array( $history ) && count( $history ) > 1 );
		?>
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
					// plain words — the same three numbers as the
					// tiles below, so the page never contradicts
					// itself or the Pro screens.
					$sentence = sprintf( __( 'Your last scan found %1$d issues across %2$d pages: %3$d fixed · %4$d waiting for your OK · %5$d still to do.', 'equalfix' ), $model['total'], (int) $result['pages_scanned'], $fixed_n, $waiting_n, $todo_n );
					if ( $draftable_todo > 0 && 0 === $waiting_n ) {
						$sentence .= ' ' . sprintf( _n( '%d of them can be drafted for you by AI Vision — review and approve the drafts in the approval queue.', '%d of them can be drafted for you by AI Vision — review and approve the drafts in the approval queue.', $draftable_todo, 'equalfix' ), $draftable_todo );
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
					<a class="button eqd-btn-ghost" href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>"><?php esc_html_e( 'View findings', 'equalfix' ); ?></a>
					<a class="button eqd-btn-ghost" href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Plugin settings', 'equalfix' ); ?></a>
				</div>
				<p class="eqd-hero-meta">
					<span class="eqd-meta-chip"><?php echo esc_html( $host ); ?></span>
					<span class="eqd-meta-chip"><?php echo esc_html( sprintf( __( 'Last scan %s', 'equalfix' ), human_time_diff( $scan_ts, current_time( 'timestamp' ) ) ) ); ?></span>
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
						<svg class="eqd-ring" width="132" height="132" viewBox="0 0 132 132" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Fix coverage: %d percent of findings EqualFix can fix or draft', 'equalfix' ), $coverage ) ); ?>">
							<defs>
								<linearGradient id="eqd-ring-grad" x1="0" y1="0" x2="1" y2="1">
									<stop offset="0%" stop-color="#205fde"/><stop offset="100%" stop-color="#12b76a"/>
								</linearGradient>
							</defs>
							<circle cx="66" cy="66" r="52" fill="none" stroke="#e6ecf9" stroke-width="13"/>
							<circle cx="66" cy="66" r="52" fill="none" stroke="url(#eqd-ring-grad)" stroke-width="13" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( $circ ); ?>" stroke-dashoffset="<?php echo esc_attr( round( $off, 1 ) ); ?>" transform="rotate(-90 66 66)"/>
						</svg>
						<div class="eqd-ring-center"><span class="eqd-ring-num"><?php echo esc_html( $coverage ); ?>%</span></div>
						<p class="eqd-ring-label"><?php esc_html_e( 'Fix coverage', 'equalfix' ); ?></p>
						<p class="eqd-ring-sub"><?php esc_html_e( 'of findings EqualFix can fix or draft for you', 'equalfix' ); ?></p>
						<?php if ( isset( $result['previous_total'] ) ) : ?>
							<?php $delta = $total - (int) $result['previous_total']; ?>
							<?php if ( $delta < 0 ) : ?>
								<p class="eqd-delta eqd-delta-good"><?php echo esc_html( sprintf( __( '%d fewer findings than the last scan', 'equalfix' ), abs( $delta ) ) ); ?></p>
							<?php elseif ( $delta > 0 ) : ?>
								<p class="eqd-delta eqd-delta-warn"><?php echo esc_html( sprintf( __( '%d more findings than the last scan', 'equalfix' ), $delta ) ); ?></p>
							<?php else : ?>
								<p class="eqd-delta"><?php esc_html_e( 'Same findings count as the last scan', 'equalfix' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					<?php else : ?>
						<p class="eqd-ring-label"><?php esc_html_e( 'No issues found', 'equalfix' ); ?></p>
						<p class="eqd-ring-sub"><?php esc_html_e( 'The automated checks found nothing to fix on the scanned pages.', 'equalfix' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="eqd-autofix <?php echo $autofix_on ? 'eqd-autofix-on' : ''; ?>">
					<p class="eqd-autofix-title"><span class="eqd-autofix-dot" aria-hidden="true"></span><?php echo esc_html( $autofix_on ? __( 'Auto-fix is active', 'equalfix' ) : __( 'Auto-fix is off', 'equalfix' ) ); ?></p>
					<p class="eqd-autofix-sub"><?php echo esc_html( $autofix_on ? __( 'Fixes are applied live as your pages render — no overlays, no manual work.', 'equalfix' ) : __( 'Your pages are served exactly as written until you switch fixes on.', 'equalfix' ) ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="toggle_master">
						<button type="submit" class="eqd-switch" role="switch" aria-checked="<?php echo $autofix_on ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Toggle automatic fixes', 'equalfix' ); ?>"><span class="eqd-switch-knob" aria-hidden="true"></span></button>
					</form>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $notes ) ) : ?>
			<p class="eqf-audit-notes"><?php echo esc_html( implode( ' ', $notes ) ); ?></p>
		<?php endif; ?>

		<div class="eqd-kpis">
			<div class="eqd-tile">
				<a class="eqd-tile-link" href="<?php echo esc_url( $this->tab_url( 'findings' ) . '#equalfix-pages' ); ?>">
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
					<span class="eqd-tile-sub"><?php echo esc_html( ! empty( $result['wall'] ) ? __( 'Limited scan — not counted', 'equalfix' ) : ( $fixed_n > 0 ? ( ! empty( $settings['fixes_activated_at'] ) ? sprintf( __( 'Active since %s', 'equalfix' ), date_i18n( get_option( 'date_format' ), (int) $settings['fixes_activated_at'] ) ) : __( 'Applied at render by EqualFix', 'equalfix' ) ) : ( (int) $counts['fix-a'] > 0 ? __( 'Use “Fix all free issues” below', 'equalfix' ) : __( 'Nothing to fix here', 'equalfix' ) ) ) ); ?></span>
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
				// Batch-plan addendum (Stage 5): with Pro active and
				// draftable work waiting, "Still to do" goes straight
				// to the Pro AI-suggestions flow — the one place
				// every draftable finding can be drafted, reviewed,
				// and approved — instead of the Findings list the
				// owner found confusing. Without Pro, or with
				// nothing draftable, the Findings to-do view
				// remains the route.
				$todo_href = add_query_arg( 'show', 'todo', $this->tab_url( 'findings' ) );
				if ( ! empty( $pro['licence_active'] ) && $draftable_todo > 0 ) {
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
				<span class="eqd-tile-icon eqd-tint-violet"><?php echo $this->icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
				<span class="eqd-tile-num eqd-tile-num-sm"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $scan_ts ) ); ?></span>
				<span class="eqd-tile-label"><?php esc_html_e( 'Last scan', 'equalfix' ); ?></span>
				<span class="eqd-tile-sub"><?php echo esc_html( sprintf( __( '%1$s ago — %2$s', 'equalfix' ), human_time_diff( $scan_ts, current_time( 'timestamp' ) ), empty( $result['complete'] ) ? __( 'partial', 'equalfix' ) : __( 'completed', 'equalfix' ) ) ); ?></span>
			</div>
		</div>

		<div class="eqd-grid2">
			<div class="eqd-card eqd-trend-card">
				<div class="eqd-card-head"><h3><?php esc_html_e( 'Issues trend', 'equalfix' ); ?></h3><span class="eqd-legend"><span class="eqd-legend-dot eqd-dot-total"></span><?php esc_html_e( 'Total issues', 'equalfix' ); ?> <span class="eqd-legend-dot eqd-dot-addr"></span><?php esc_html_e( 'Fixable by EqualFix', 'equalfix' ); ?></span></div>
				<?php $this->render_trend( $result, $counts, $queue_eligible ); ?>
			</div>
			<div class="eqd-card eqd-top-card">
				<div class="eqd-card-head"><h3><?php esc_html_e( 'Top issues', 'equalfix' ); ?></h3><a href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>"><?php esc_html_e( 'View all', 'equalfix' ); ?></a></div>
				<?php $this->render_top_issues( $result, $settings ); ?>
			</div>
		</div>

		<div class="eqd-grid2">
			<div class="eqd-card eqd-activity-card">
				<div class="eqd-card-head"><h3><?php esc_html_e( 'Recent activity', 'equalfix' ); ?></h3></div>
				<?php $this->render_activity( $result, $settings ); ?>
			</div>
			<div class="eqd-card eqd-quick-card">
				<div class="eqd-card-head"><h3><?php esc_html_e( 'Quick actions', 'equalfix' ); ?></h3></div>
				<div class="eqd-quick-list">
					<?php if ( $remaining_a > 0 ) : ?>
						<form method="post" action="" class="eqd-quick-form">
							<?php wp_nonce_field( 'equalfix_settings' ); ?>
							<input type="hidden" name="equalfix_action" value="fix_all_free">
							<button type="submit" class="eqd-quick-row">
								<span class="eqd-tile-icon eqd-tint-green"><?php echo $this->icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
								<span class="eqd-quick-text"><strong><?php esc_html_e( 'Fix all free issues', 'equalfix' ); ?></strong><small><?php echo esc_html( sprintf( __( '%d findings fixed in one click', 'equalfix' ), $remaining_a ) ); ?></small></span>
								<span class="eqd-quick-chevron" aria-hidden="true">›</span>
							</button>
						</form>
					<?php endif; ?>
					<a class="eqd-quick-row" href="<?php echo esc_url( $this->tab_url( 'findings' ) ); ?>">
						<span class="eqd-tile-icon eqd-tint-blue"><?php echo $this->icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
						<span class="eqd-quick-text"><strong><?php esc_html_e( 'View findings', 'equalfix' ); ?></strong><small><?php esc_html_e( 'Every issue, grouped by page', 'equalfix' ); ?></small></span>
						<span class="eqd-quick-chevron" aria-hidden="true">›</span>
					</a>
					<a class="eqd-quick-row" href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>">
						<span class="eqd-tile-icon eqd-tint-violet"><?php echo $this->icon( 'sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
						<span class="eqd-quick-text"><strong><?php esc_html_e( 'Plugin settings', 'equalfix' ); ?></strong><small><?php esc_html_e( 'Fix types, exclusions, cache', 'equalfix' ); ?></small></span>
						<span class="eqd-quick-chevron" aria-hidden="true">›</span>
					</a>
					<form method="post" action="" class="eqd-quick-form">
						<?php wp_nonce_field( 'equalfix_settings' ); ?>
						<input type="hidden" name="equalfix_action" value="purge_now">
						<button type="submit" class="eqd-quick-row">
							<span class="eqd-tile-icon eqd-tint-amber"><?php echo $this->icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
							<span class="eqd-quick-text"><strong><?php esc_html_e( 'Purge caches', 'equalfix' ); ?></strong><small><?php esc_html_e( 'Serve the latest fixed output now', 'equalfix' ); ?></small></span>
							<span class="eqd-quick-chevron" aria-hidden="true">›</span>
						</button>
					</form>
				</div>
			</div>
		</div>

		<?php $this->render_features_card(); ?>
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
			<polyline points="<?php echo esc_attr( implode( ' ', $addr_pts ) ); ?>" fill="none" stroke="#12b76a" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
			<polyline points="<?php echo esc_attr( implode( ' ', $total_pts ) ); ?>" fill="none" stroke="#205fde" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
			<?php foreach ( $total_pts as $pt ) : $xy = explode( ',', $pt ); ?>
				<circle cx="<?php echo esc_attr( $xy[0] ); ?>" cy="<?php echo esc_attr( $xy[1] ); ?>" r="3.5" fill="#205fde"/>
			<?php endforeach; ?>
			<?php foreach ( $addr_pts as $pt ) : $xy = explode( ',', $pt ); ?>
				<circle cx="<?php echo esc_attr( $xy[0] ); ?>" cy="<?php echo esc_attr( $xy[1] ); ?>" r="3.5" fill="#12b76a"/>
			<?php endforeach; ?>
		</svg>
		<p class="eqd-trend-axis"><span><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $history[0]['time'] ) ); ?></span><span><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $history[ $n - 1 ]['time'] ) ); ?></span></p>
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
			'text' => sprintf( __( 'Audit completed — %1$d findings across %2$d pages', 'equalfix' ), (int) $result['total'], (int) $result['pages_scanned'] ),
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
				<h4 class="eqf-fixed-page"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( self::page_title_label( $url ) ); ?></a> <span class="eqf-pagecount"><?php echo esc_html( sprintf( _n( '%d fixed', '%d fixed', count( $items ), 'equalfix' ), count( $items ) ) ); ?></span></h4>
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
