<?php
/**
 * EqualFix — Acquisition Guard Layer (1.0, WP2)
 *
 * Every captured page — HTTP fetch or in-process render — must prove
 * it is the page that was requested before the audit may analyse it.
 * A capture that fails verification is a scan failure with a reason,
 * never a clean result.
 *
 * Why this exists: on hosts with a scoped path prefix (WordPress
 * Playground is the proven case), the 0.5.0 in-process render silently
 * resolved to the ambient request and returned the wp-admin dashboard
 * document as if it were the target page — every status signal green,
 * zero findings reported. The guard is the structural countermeasure:
 * identity is verified, not assumed.
 *
 * Checks, in order: minimum size; a <body>; not an admin document;
 * not a login wall; not an error document (error404 body class, or a
 * not-found title with no marker match); and the page's own marker —
 * for singular pages, the post title or slug must appear in the
 * capture (the site name does NOT suffice: admin pages carry it too).
 * Rendered captures must always present a marker; HTTP captures of
 * singular pages must too (a 200 that serves the wrong document is a
 * known false-clean vector). Non-singular HTTP captures rely on the
 * sanity checks — the server answered the exact URL requested.
 *
 * @package EqualFix
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Guard {

	/**
	 * Minimum plausible size of a real page document, bytes.
	 */
	const MIN_BYTES = 1000;

	/**
	 * Verify a captured document.
	 *
	 * @param string $html Captured HTML.
	 * @param string $url  The URL the capture claims to be.
	 * @param string $mode 'http' or 'render'.
	 * @return array{ok:bool, reason:string, message:string}
	 */
	public static function verify( $html, $url, $mode ) {
		if ( ! is_string( $html ) || strlen( $html ) < self::MIN_BYTES ) {
			return self::fail( 'too_small', 'The captured page was empty or too small to be the real page, so it was not scanned.' );
		}
		if ( false === stripos( $html, '<body' ) ) {
			return self::fail( 'no_body', 'The captured page had no body content, so it was not scanned.' );
		}
		// An admin document is never the requested front-end page —
		// this is the exact shape of the Playground failure.
		if ( false !== stripos( $html, 'id="wpadminbar"' ) || false !== stripos( $html, '/wp-admin/css/' ) || false !== stripos( $html, '/wp-admin/js/' ) ) {
			return self::fail( 'admin_document', 'The scan received an admin page instead of this page, so it was not scanned. This can happen on hosts that rewrite admin URLs.' );
		}
		if ( false !== stripos( $html, 'id="loginform"' ) || ( false !== stripos( $html, 'name="log"' ) && false !== stripos( $html, 'wp-login' ) ) ) {
			return self::fail( 'login_wall', 'The scan received a login page instead of this page, so it was not scanned.' );
		}
		$marker = self::marker_match( $html, $url );
		// Error documents: WP marks its own 404 template with an
		// error404 body class; a not-found <title> with no trace of
		// the requested page is the soft-404 shape.
		if ( preg_match( '/<body[^>]*class="[^"]*\berror404\b/', $html ) ) {
			return self::fail( 'error_document', 'This URL returned a "page not found" document, so it was not scanned.' );
		}
		if ( preg_match( '/<title[^>]*>[^<]*(404|page not found|nothing found)[^<]*<\/title>/i', $html ) && ! $marker ) {
			return self::fail( 'error_document', 'This URL returned a "page not found" document, so it was not scanned.' );
		}
		$singular = self::is_singular_url( $url );
		if ( 'render' === $mode && ! $marker ) {
			return self::fail( 'marker_missing', 'The rendered page could not be verified as the requested page, so it was not scanned.' );
		}
		if ( 'http' === $mode && $singular && ! $marker ) {
			return self::fail( 'marker_missing', 'The page served for this URL does not contain the page\'s own content, so it was not scanned.' );
		}
		return array(
			'ok'      => true,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Whether the URL resolves to a singular post/page/product.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_singular_url( $url ) {
		return self::singular_id( $url ) > 0;
	}

	/**
	 * Resolve a URL to a singular post ID. url_to_postid() already
	 * resolves plain query URLs (?p= / ?page_id= — verified against
	 * WP core on the rigs), but host URL mangling (Playground's
	 * scoped prefixes are the proven case) can defeat its rewrite
	 * matching; an explicit query-string fallback closes that gap
	 * so identity checks never silently degrade to "no ID, no
	 * check" (WP7 Lovable-review hardening).
	 *
	 * @param string $url URL.
	 * @return int Post ID, or 0.
	 */
	public static function singular_id( $url ) {
		if ( function_exists( 'url_to_postid' ) ) {
			$id = (int) url_to_postid( $url );
			if ( $id > 0 ) {
				return $id;
			}
		}
		$query = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_QUERY ) : parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$args = array();
			parse_str( $query, $args );
			foreach ( array( 'page_id', 'p' ) as $key ) {
				if ( isset( $args[ $key ] ) && (int) $args[ $key ] > 0 ) {
					return (int) $args[ $key ];
				}
			}
		}
		return 0;
	}

	/**
	 * Whether the capture contains one of the page's own markers:
	 * the post title or slug (singular), or the path slug / site
	 * name (non-singular).
	 *
	 * @param string $html Captured HTML.
	 * @param string $url  Requested URL.
	 * @return bool
	 */
	private static function marker_match( $html, $url ) {
		$candidates = array();
		$post_id    = self::singular_id( $url );
		if ( $post_id > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				if ( '' !== trim( (string) $post->post_title ) ) {
					$candidates[] = $post->post_title;
				}
				// Slug markers must be distinctive: a 1–2 character
				// or purely numeric slug ("10", "a") substring-matches
				// unrelated markup (class="col-10") and could let a
				// wrong document pass (WP7 Lovable-review find).
				if ( '' !== (string) $post->post_name && strlen( (string) $post->post_name ) >= 3 && ! ctype_digit( (string) $post->post_name ) ) {
					$candidates[] = $post->post_name;
				}
			}
		}
		if ( empty( $candidates ) ) {
			$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
			$segs = array_values( array_filter( explode( '/', (string) $path ) ) );
			if ( ! empty( $segs ) ) {
				$last = end( $segs );
				if ( strlen( (string) $last ) >= 3 && ! ctype_digit( (string) $last ) ) {
					$candidates[] = $last;
				}
			}
			if ( function_exists( 'get_bloginfo' ) ) {
				$name = get_bloginfo( 'name' );
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$candidates[] = $name;
				}
			}
		}
		foreach ( $candidates as $c ) {
			$c = trim( html_entity_decode( (string) $c, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $c ) {
				continue;
			}
			if ( false !== stripos( $html, $c ) || false !== stripos( $html, htmlentities( $c, ENT_QUOTES, 'UTF-8' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a failure verdict.
	 *
	 * @param string $reason  Stable machine reason.
	 * @param string $message Human-readable explanation for the report.
	 * @return array
	 */
	private static function fail( $reason, $message ) {
		return array(
			'ok'      => false,
			'reason'  => $reason,
			'message' => $message,
		);
	}
}
