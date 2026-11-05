<?php
/**
 * EqualFix — Accessibility statement generator (next batch).
 *
 * Builds an honest accessibility statement for the site from the
 * LATEST STORED AUDIT — real numbers from a real scan of this
 * site, never boilerplate claims — and saves it as a DRAFT page
 * the owner reviews, edits, and publishes themselves. The plugin
 * never publishes it, never touches it again unless the owner (or
 * Pro's auto-refresh) regenerates it, and the wording never
 * claims compliance, conformance, certification, or legal
 * protection: it describes what was checked, what was found,
 * what is being done, and how to report a barrier.
 *
 * The draft page's content is the [equalfix_statement] shortcode;
 * the generated statement HTML is stored in an option and the
 * shortcode renders that snapshot, so what the owner reviewed in
 * the draft is exactly what publishing would show, and a refresh
 * is a deliberate snapshot replacement — never a silent rewrite
 * of a live page the owner may have edited since.
 *
 * @package EqualFix
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Statement {

	/** Option holding the statement record + HTML snapshot. */
	const OPTION = 'equalfix_statement';

	/**
	 * The stored statement record.
	 *
	 * @return array { page_id, source_total, source_time, generated_at, html }
	 */
	public static function record() {
		$rec = get_option( self::OPTION, null );
		return is_array( $rec ) ? $rec : array();
	}

	/**
	 * Status for the Overview card.
	 *
	 * @return array { has_audit, exists, page_id, edit_url, preview_url,
	 *                 source_total, current_total, out_of_date, generated_at }
	 */
	public static function status() {
		$audit = get_option( EqualFix_Settings::AUDIT_OPTION, null );
		$rec   = self::record();
		$out   = array(
			'has_audit'     => is_array( $audit ) && isset( $audit['total'] ),
			'exists'        => false,
			'page_id'       => 0,
			'edit_url'      => '',
			'preview_url'   => '',
			'source_total'  => null,
			'current_total' => is_array( $audit ) && isset( $audit['total'] ) ? (int) $audit['total'] : null,
			'out_of_date'   => false,
			'generated_at'  => '',
		);
		if ( ! empty( $rec['page_id'] ) ) {
			$post = get_post( (int) $rec['page_id'] );
			if ( $post && 'page' === $post->post_type ) {
				$out['exists']       = true;
				$out['page_id']      = (int) $rec['page_id'];
				$out['edit_url']     = (string) get_edit_post_link( (int) $rec['page_id'], 'raw' );
				$out['preview_url']  = (string) get_preview_post_link( $post );
				$out['source_total'] = isset( $rec['source_total'] ) ? (int) $rec['source_total'] : null;
				$out['generated_at'] = isset( $rec['generated_at'] ) ? (string) $rec['generated_at'] : '';
				$out['out_of_date']  = null !== $out['current_total'] && null !== $out['source_total'] && $out['current_total'] !== $out['source_total'];
			}
		}
		return $out;
	}

	/**
	 * Generate the statement draft from the latest stored audit.
	 * Refuses without one — a statement built on no data would be
	 * exactly the boilerplate this feature exists to avoid.
	 *
	 * @return array { ok, message }
	 */
	public static function generate() {
		$audit = get_option( EqualFix_Settings::AUDIT_OPTION, null );
		if ( ! is_array( $audit ) || ! isset( $audit['total'] ) ) {
			return array( 'ok' => false, 'message' => __( 'Run an audit first — the statement is written from a real audit of your site, and there isn’t one yet.', 'equalfix' ) );
		}
		$status = self::status();
		if ( $status['exists'] ) {
			return array( 'ok' => false, 'message' => __( 'Your statement draft already exists — use Refresh to rebuild it from the latest audit, or edit the draft directly.', 'equalfix' ) );
		}
		$html = self::build_html( $audit );
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => __( 'Accessibility Statement', 'equalfix' ),
				'post_content' => '<!-- wp:shortcode -->[equalfix_statement]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return array( 'ok' => false, 'message' => __( 'The statement draft page could not be created. Check that your user can create pages, then try again.', 'equalfix' ) );
		}
		update_option(
			self::OPTION,
			array(
				'page_id'      => (int) $page_id,
				'source_total' => (int) $audit['total'],
				'source_time'  => isset( $audit['time'] ) ? (string) $audit['time'] : '',
				'generated_at' => current_time( 'mysql' ),
				'html'         => $html,
			),
			false
		);
		return array( 'ok' => true, 'message' => __( 'Statement draft created — it’s a draft page only, nothing is published. Review it, edit the contact details if needed, and publish it yourself when you’re happy.', 'equalfix' ) );
	}

	/**
	 * Rebuild the statement snapshot from the latest audit
	 * (owner-clicked refresh, or Pro's auto-refresh after a
	 * re-audit). The page stays a draft; only the snapshot the
	 * shortcode renders is replaced.
	 *
	 * @return array { ok, message }
	 */
	public static function refresh() {
		$status = self::status();
		if ( ! $status['exists'] ) {
			return self::generate();
		}
		$audit = get_option( EqualFix_Settings::AUDIT_OPTION, null );
		if ( ! is_array( $audit ) || ! isset( $audit['total'] ) ) {
			return array( 'ok' => false, 'message' => __( 'There is no audit to refresh the statement from yet.', 'equalfix' ) );
		}
		$rec                 = self::record();
		$rec['source_total'] = (int) $audit['total'];
		$rec['source_time']  = isset( $audit['time'] ) ? (string) $audit['time'] : '';
		$rec['generated_at'] = current_time( 'mysql' );
		$rec['html']         = self::build_html( $audit );
		update_option( self::OPTION, $rec, false );
		return array( 'ok' => true, 'message' => __( 'Statement refreshed from your latest audit. The draft page still shows your edits to its title and layout — only the statement text was rebuilt.', 'equalfix' ) );
	}

	/**
	 * The [equalfix_statement] shortcode: renders the stored
	 * snapshot, or nothing when no statement exists.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		$rec = self::record();
		if ( empty( $rec['html'] ) ) {
			return '';
		}
		return '<div class="equalfix-statement">' . $rec['html'] . '</div>';
	}

	/**
	 * Plain-language group counts for an audit's findings.
	 *
	 * @param array $audit Stored audit result.
	 * @return array List of { label, count } with count > 0, biggest first.
	 */
	private static function plain_groups( $audit ) {
		$groups = array(
			'alt'      => array( __( 'images with no description', 'equalfix' ), 0 ),
			'label'    => array( __( 'form fields with no label', 'equalfix' ), 0 ),
			'name'     => array( __( 'buttons or links with no name', 'equalfix' ), 0 ),
			'headings' => array( __( 'heading-order problems', 'equalfix' ), 0 ),
			'other'    => array( __( 'other issues', 'equalfix' ), 0 ),
		);
		foreach ( (array) $audit['findings'] as $finding ) {
			$title = isset( $finding['title'] ) ? (string) $finding['title'] : '';
			if ( false !== stripos( $title, 'alt text' ) ) {
				$groups['alt'][1]++;
			} elseif ( false !== stripos( $title, 'label' ) ) {
				$groups['label'][1]++;
			} elseif ( false !== stripos( $title, 'accessible name' ) ) {
				$groups['name'][1]++;
			} elseif ( false !== stripos( $title, 'heading' ) ) {
				$groups['headings'][1]++;
			} else {
				$groups['other'][1]++;
			}
		}
		$out = array();
		foreach ( $groups as $g ) {
			if ( $g[1] > 0 ) {
				$out[] = array( 'label' => $g[0], 'count' => $g[1] );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $out;
	}

	/**
	 * How many of the audit's findings are being fixed right now
	 * by the free fix set (title-derived fix types that are
	 * switched on, master included — the partition rule the
	 * dashboard uses, restated here from the stored settings).
	 *
	 * @param array $audit Stored audit result.
	 * @return int
	 */
	private static function fixed_now_count( $audit ) {
		// One source of truth (Stage 6 adversarial pass, F6):
		// the statement used to recount only free fix-a findings
		// by title matching, so the published statement disagreed
		// with the dashboard's own fixed count on any site with
		// Pro fixes or approved rules in effect. The coverage
		// model is the count every other surface uses.
		if ( class_exists( 'EqualFix_Settings' ) && method_exists( 'EqualFix_Settings', 'coverage_counts' ) ) {
			$counts = EqualFix_Settings::coverage_counts( $audit );
			return isset( $counts['fixed'] ) ? (int) $counts['fixed'] : 0;
		}
		return 0;
	}

	/**
	 * Legacy title-matching counter, retained only as a fallback
	 * for a free-only install where the model is unavailable.
	 *
	 * @param array $audit Audit result.
	 * @return int
	 */
	private static function fixed_now_count_legacy( $audit ) {
		$settings = EqualFix_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return 0;
		}
		$map = array(
			'language attribute' => 'fix_lang',
			'alt text'           => 'fix_alt',
			'accessible name'    => 'fix_aria_label',
			'label'              => 'fix_label_assoc',
			'skip'               => 'fix_skip_link',
		);
		$n = 0;
		foreach ( (array) $audit['findings'] as $finding ) {
			if ( ! isset( $finding['category'] ) || EqualFix_Audit::CAT_FIX_A !== $finding['category'] ) {
				continue;
			}
			foreach ( $map as $needle => $key ) {
				if ( false !== strpos( (string) $finding['title'], $needle ) ) {
					if ( ! empty( $settings[ $key ] ) ) {
						$n++;
					}
					break;
				}
			}
		}
		return $n;
	}

	/**
	 * Build the statement HTML from a stored audit. Every number
	 * comes from that audit; every sentence is effort language,
	 * never a compliance claim.
	 *
	 * @param array $audit Stored audit result.
	 * @return string HTML (already escaped where dynamic).
	 */
	public static function build_html( $audit ) {
		$site_name  = get_bloginfo( 'name' );
		$host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$email      = (string) get_option( 'admin_email', '' );
		$audit_date = isset( $audit['time'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $audit['time'] ) ) : '';
		$today      = date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) );
		$total      = (int) $audit['total'];
		$pages      = isset( $audit['pages_scanned'] ) ? (int) $audit['pages_scanned'] : 0;
		$groups     = self::plain_groups( $audit );
		$fixed_now  = self::fixed_now_count( $audit );
		$remaining  = max( 0, $total - $fixed_now );

		$out   = array();
		$out[] = '<h2>' . esc_html( sprintf( __( 'Accessibility statement for %s', 'equalfix' ), $site_name ) ) . '</h2>';
		$out[] = '<p>' . esc_html( sprintf( __( '%1$s wants everyone to be able to use %2$s. We check the site for accessibility problems, fix what we find, and keep checking as the site changes.', 'equalfix' ), $site_name, $host ) ) . '</p>';

		$out[] = '<h3>' . esc_html__( 'What we checked', 'equalfix' ) . '</h3>';
		if ( $total > 0 ) {
			$out[] = '<p>' . esc_html( sprintf( __( 'On %1$s we ran an automated accessibility audit of %2$d public pages. It found %3$d issues:', 'equalfix' ), $audit_date, $pages, $total ) ) . '</p>';
			$items = '';
			foreach ( $groups as $g ) {
				$items .= '<li>' . esc_html( sprintf( _n( '%1$d × %2$s', '%1$d × %2$s', $g['count'], 'equalfix' ), $g['count'], $g['label'] ) ) . '</li>';
			}
			$out[] = '<ul>' . $items . '</ul>';
		} else {
			$out[] = '<p>' . esc_html( sprintf( __( 'On %1$s we ran an automated accessibility audit of %2$d public pages, and it found no issues in the areas it covers.', 'equalfix' ), $audit_date, $pages ) ) . '</p>';
		}
		$out[] = '<p>' . esc_html__( 'Automated checks cover a portion of what accessibility involves — they cannot catch everything — so we also review pages by hand when content changes.', 'equalfix' ) . '</p>';

		$out[] = '<h3>' . esc_html__( 'What we are doing about it', 'equalfix' ) . '</h3>';
		if ( $fixed_now > 0 ) {
			$out[] = '<p>' . esc_html( sprintf( _n( '%d of those issues is already being fixed automatically, every time a page loads.', '%d of those issues are already being fixed automatically, every time a page loads.', $fixed_now, 'equalfix' ), $fixed_now ) ) . '</p>';
		}
		if ( $remaining > 0 && $total > 0 ) {
			$top = array_slice( $groups, 0, 2 );
			$names = array();
			foreach ( $top as $g ) {
				$names[] = $g['label'];
			}
			$out[] = '<p>' . esc_html( sprintf( __( 'The rest need manual work rather than an automatic fix — at the moment that is mostly %s. We are working through these, starting with the pages people use most.', 'equalfix' ), implode( __( ' and ', 'equalfix' ), $names ) ) ) . '</p>';
		}
		$out[] = '<p>' . esc_html__( 'Accessibility is an ongoing process, not a one-time project: new content can introduce new problems, so re-checking is part of how this site is maintained.', 'equalfix' ) . '</p>';

		$out[] = '<h3>' . esc_html__( 'If something doesn’t work for you', 'equalfix' ) . '</h3>';
		if ( '' !== $email ) {
			$out[] = '<p>' . esc_html( sprintf( __( 'If you have trouble using any part of this site, contact us at %s and we will help you get the same information or service another way, and fix the barrier where we can.', 'equalfix' ), $email ) ) . '</p>';
		} else {
			$out[] = '<p>' . esc_html__( 'If you have trouble using any part of this site, please contact us through the site and we will help you get the same information or service another way, and fix the barrier where we can.', 'equalfix' ) . '</p>';
		}

		$out[] = '<p><em>' . esc_html( sprintf( __( 'This statement was prepared on %1$s from the audit of %2$s, and is reviewed whenever the site is re-audited.', 'equalfix' ), $today, $audit_date ) ) . '</em></p>';
		return implode( "\n", $out );
	}
}
