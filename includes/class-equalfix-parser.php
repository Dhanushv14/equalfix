<?php
/**
 * EqualFix — HTML5 Parse Layer (1.0)
 *
 * Single point of HTML parsing for analysis. Backed by the bundled,
 * namespace-prefixed Masterminds/html5-php parser (spec-compliant
 * HTML5 parsing; returns a DOMDocument, so the rule engine's DOM/XPath
 * code carries over). Replaces direct libxml2 loadHTML() calls, whose
 * HTML4-era tree construction corrupted or misread real-world markup
 * (raw-text elements, <template>, SVG, deep nesting).
 *
 * Deliberate behaviours:
 * - No silent fallback. If the bundled parser is unavailable or a
 *   document cannot be parsed, parse() returns false and records a
 *   status; callers must treat the page as scan-failed, never clean.
 * - Memory guard (Lovable Mod 5): documents above ~1.5MB require
 *   ~32MB of memory headroom, else status 'too_large' — the caller
 *   records a page_too_large warning instead of fataling.
 * - <template> contents live in a separate fragment in the parsed
 *   document and are NOT part of the analysed main document: inert
 *   template markup is not rendered page content (matches axe-core).
 *
 * @package EqualFix
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Parser {

	const STATUS_OK           = 'ok';
	const STATUS_TOO_LARGE    = 'too_large';
	const STATUS_PARSE_FAILED = 'parse_failed';
	const STATUS_UNAVAILABLE  = 'unavailable';

	/**
	 * Large-document threshold in bytes (~1.5MB).
	 */
	const LARGE_DOC_BYTES = 1572864;

	/**
	 * Headroom required before parsing a large document (~32MB).
	 */
	const LARGE_DOC_HEADROOM = 33554432;

	/**
	 * Status of the last parse() call.
	 *
	 * @var string
	 */
	private static $last_status = self::STATUS_OK;

	/**
	 * Status of the most recent parse() call.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function last_status() {
		return self::$last_status;
	}

	/**
	 * Whether the bundled parser class is loadable.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'EqualFix\\Vendor\\Masterminds\\HTML5' );
	}

	/**
	 * Parse a full HTML document.
	 *
	 * @param string $html Full HTML document.
	 * @return DOMDocument|false Parsed document, or false (see last_status()).
	 */
	public static function parse( $html ) {
		self::$last_status = self::STATUS_OK;

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			self::$last_status = self::STATUS_PARSE_FAILED;
			return false;
		}

		if ( ! self::available() ) {
			self::$last_status = self::STATUS_UNAVAILABLE;
			return false;
		}

		if ( strlen( $html ) > self::LARGE_DOC_BYTES && ! self::has_headroom() ) {
			self::$last_status = self::STATUS_TOO_LARGE;
			return false;
		}

		try {
			$parser = new EqualFix\Vendor\Masterminds\HTML5(
				array(
					// Match libxml2's namespace-free tree so the rule
					// engine's unprefixed XPath queries keep working.
					'disable_html_ns' => true,
				)
			);
			$dom    = $parser->loadHTML( $html );
		} catch ( Throwable $e ) {
			self::$last_status = self::STATUS_PARSE_FAILED;
			return false;
		}

		if ( ! $dom instanceof DOMDocument ) {
			self::$last_status = self::STATUS_PARSE_FAILED;
			return false;
		}
		$htmls = $dom->getElementsByTagName( 'html' );
		if ( 0 === $htmls->length ) {
			self::$last_status = self::STATUS_PARSE_FAILED;
			return false;
		}
		return $dom;
	}

	/**
	 * Whether PHP has ~32MB of memory headroom right now.
	 *
	 * @return bool
	 */
	private static function has_headroom() {
		$limit = ini_get( 'memory_limit' );
		if ( false === $limit || '' === $limit || '-1' === trim( (string) $limit ) ) {
			return true; // Unlimited.
		}
		$bytes = self::ini_bytes( (string) $limit );
		if ( $bytes <= 0 ) {
			return true; // Unparseable limit: do not block on a guess.
		}
		return ( $bytes - memory_get_usage( true ) ) >= self::LARGE_DOC_HEADROOM;
	}

	/**
	 * Convert a PHP ini size (e.g. "256M") to bytes.
	 *
	 * @param string $value Ini value.
	 * @return int Bytes (0 when unparseable).
	 */
	private static function ini_bytes( $value ) {
		$value = trim( $value );
		if ( '' === $value || ! preg_match( '/^(\d+)\s*([KMG])?$/i', $value, $m ) ) {
			return 0;
		}
		$n = (int) $m[1];
		switch ( strtoupper( isset( $m[2] ) ? $m[2] : '' ) ) {
			case 'G':
				return $n * 1073741824;
			case 'M':
				return $n * 1048576;
			case 'K':
				return $n * 1024;
			default:
				return $n;
		}
	}
}
