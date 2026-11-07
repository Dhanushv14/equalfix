<?php
/**
 * EqualFix — One-click Pro installer (1.2, Workstream B)
 *
 * Lives in the FREE plugin so the path from "I bought Pro" to
 * "Pro is running" is a single paste and a single click, with no
 * manual download, no Plugins → Add New → Upload detour:
 *
 *   1. The owner pastes their licence key in the Pro section of Settings.
 *   2. The key is validated against the licence service
 *      (POST /licence/validate) — verdict-first, like Pro itself.
 *   3. A signed, single-use, five-minute download token is
 *      requested (POST /billing/download-token); the token
 *      endpoint re-checks the licence and the subscription's
 *      standing server-side, and returns the current release's
 *      SHA-256 checksum (package_sha256) alongside the URL.
 *   4. The zip is downloaded and its SHA-256 verified against
 *      that checksum BEFORE WordPress's own plugin upgrader
 *      installs and activates it (R-12 — a mismatch, or a token
 *      response with no well-formed checksum at all, refuses
 *      the install: fail closed, the same stance the Pro
 *      updater takes on its update record). The key is handed
 *      to Pro through a short-lived transient (never a URL),
 *      and Pro validates and stores it on first load.
 *
 * Guideline posture (.org rules, checked against the plan):
 * nothing here runs without the owner's explicit click on this
 * screen; only the plugin the owner just licensed is downloaded,
 * from EqualFix's own service; no other plugin is installed,
 * recommended, or touched. Every failure is reported in plain
 * language with the manual route (dashboard download + upload)
 * as the fallback — an install that cannot complete never leaves
 * the site in a half state, because the upgrader's own result is
 * what decides success.
 *
 * @package EqualFix
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Pro_Installer {

	/**
	 * The licence-service base URL. Deliberately the same source
	 * Pro uses (the equalfix_pro_api_base option/filter), so a
	 * site that re-pointed Pro re-points the installer too.
	 *
	 * @return string Base URL without trailing slash.
	 */
	public static function api_base() {
		$stored = get_option( 'equalfix_pro_api_base', 'https://equalfix.com/api/public' );
		$base   = apply_filters( 'equalfix_pro_api_base', $stored );
		$base   = trim( (string) $base );
		return rtrim( $base, '/' );
	}

	/**
	 * Validate a licence key for THIS site.
	 *
	 * Verdict-first: the service answers every verdict with a JSON
	 * body (HTTP 200 since backend change B3); the body, not the
	 * status line, decides the outcome.
	 *
	 * @param string $key Licence key.
	 * @return array { ok: bool, code: string, message: string }
	 */
	public static function validate_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'code'    => 'empty_key',
				'message' => __( 'Paste your licence key first — it is in your purchase email and in your EqualFix dashboard.', 'equalfix' ),
			);
		}
		$data = self::post( '/licence/validate', array( 'key' => $key, 'site_url' => home_url( '/' ) ) );
		if ( null === $data ) {
			return array(
				'ok'      => false,
				'code'    => 'offline',
				'message' => __( 'The licence server could not be reached. Check your site’s internet connection and try again — or download Pro from your EqualFix dashboard and upload it under Plugins → Add New.', 'equalfix' ),
			);
		}
		// Scalar gates on every field read from the response
		// (round 4 cast sweep): a non-scalar field is an absent
		// field — casting an array emits an "Array to string
		// conversion" warning and invents data.
		$error = isset( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : '';
		if ( ! empty( $data['valid'] ) && isset( $data['status'] ) && 'active' === $data['status'] ) {
			return array( 'ok' => true, 'code' => 'active', 'message' => '' );
		}
		if ( 'domain_mismatch' === $error || ( isset( $data['domain_match'] ) && false === $data['domain_match'] ) ) {
			$registered = isset( $data['site_url'] ) && is_scalar( $data['site_url'] ) ? (string) $data['site_url'] : '';
			return array(
				'ok'      => false,
				'code'    => 'domain_mismatch',
				'message' => '' !== $registered
					? sprintf( __( 'This licence key is registered to %s, not this site. A key works only on the site it was registered for — register this site in your EqualFix dashboard to get its key, or move the registration to this site.', 'equalfix' ), $registered )
					: __( 'This licence key is registered to a different site. A key works only on the site it was registered for — register this site in your EqualFix dashboard to get its key.', 'equalfix' ),
			);
		}
		switch ( $error ) {
			case 'unknown_key':
				return array( 'ok' => false, 'code' => 'unknown_key', 'message' => __( 'That key was not recognized. Check for typos — the key is in your purchase email and in your EqualFix dashboard under Sites.', 'equalfix' ) );
			case 'revoked':
				return array( 'ok' => false, 'code' => 'revoked', 'message' => __( 'This licence has been revoked, so Pro cannot be installed with it. If you believe this is a mistake, contact EqualFix support.', 'equalfix' ) );
			case 'expired':
				return array( 'ok' => false, 'code' => 'expired', 'message' => __( 'This licence has expired. Renew it in your EqualFix dashboard, then try again.', 'equalfix' ) );
			case 'suspended':
				return array( 'ok' => false, 'code' => 'suspended', 'message' => __( 'This licence is currently suspended. Contact EqualFix support for help.', 'equalfix' ) );
		}
		$backend = isset( $data['message'] ) && is_scalar( $data['message'] ) ? trim( (string) $data['message'] ) : '';
		return array(
			'ok'      => false,
			'code'    => 'not_active',
			'message' => '' !== $backend ? $backend : __( 'This licence is not active, so Pro cannot be installed with it yet.', 'equalfix' ),
		);
	}

	/**
	 * Exchange a valid key for a signed, single-use Pro download.
	 *
	 * The token endpoint re-checks the licence AND the
	 * subscription's standing; its refusals are surfaced verbatim
	 * in plain language.
	 *
	 * @param string $key Licence key (already validated).
	 * @return array { ok: bool, code: string, message: string, url: string, sha256: string, sha256_raw: string }
	 */
	public static function request_download( $key ) {
		$data = self::post( '/billing/download-token', array( 'key' => trim( (string) $key ), 'site_url' => home_url( '/' ) ) );
		if ( null === $data ) {
			return array( 'ok' => false, 'code' => 'offline', 'url' => '', 'sha256' => '', 'message' => __( 'The download server could not be reached. Try again in a moment — or download Pro from your EqualFix dashboard and upload it under Plugins → Add New.', 'equalfix' ) );
		}
		// A non-scalar download URL is an absent one (round 4
		// cast sweep): ! empty() alone let an array through as
		// "present", and the cast below would have warned and
		// produced the URL "Array".
		$download_url = isset( $data['download_url'] ) && is_scalar( $data['download_url'] ) ? (string) $data['download_url'] : '';
		if ( '' !== $download_url ) {
			// R-12: capture the release checksum the token
			// response carries (same release record the Pro
			// updater verifies against). Normalised the same
			// way the updater normalises its record; a missing
			// or malformed value passes through as '' and the
			// install step refuses on it — this step's verdict
			// stays about download standing only.
			$sha = isset( $data['package_sha256'] ) && is_scalar( $data['package_sha256'] ) ? strtolower( trim( (string) $data['package_sha256'] ) ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				$sha = '';
			}
			// LC-6: the raw scalar rides along so a consumer
			// can tell "the response carried a checksum but
			// it was malformed" (a backend schema slip — the
			// T1.5 F-1 class, which must refuse) apart from
			// "the response carried none" (where falling back
			// to another authority is legitimate). A
			// non-scalar field reads as absent, per the
			// round-4 cast discipline, so raw is '' then too.
			return array( 'ok' => true, 'code' => 'ok', 'url' => $download_url, 'sha256' => $sha, 'sha256_raw' => isset( $data['package_sha256'] ) && is_scalar( $data['package_sha256'] ) ? strtolower( trim( (string) $data['package_sha256'] ) ) : '', 'message' => '' );
		}
		$error = isset( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : '';
		switch ( $error ) {
			case 'subscription_past_due':
				$msg = __( 'Your key is valid, but the subscription’s latest payment did not go through, so downloads are paused. Update your payment method in your EqualFix dashboard, then try again.', 'equalfix' );
				break;
			case 'subscription_cancelled':
				$msg = __( 'Your key is valid, but its subscription has been cancelled, so downloads are closed. Resubscribe in your EqualFix dashboard to install Pro.', 'equalfix' );
				break;
			case 'subscription_inactive':
				$msg = __( 'Your key is valid, but its subscription is not active yet — a trial or dashboard-issued key has no downloadable Pro until a subscription starts. Subscribe in your EqualFix dashboard, then install with one click.', 'equalfix' );
				break;
			case 'domain_mismatch':
				$msg = __( 'This licence key is registered to a different site, so the download was refused. Register this site in your EqualFix dashboard to get its key.', 'equalfix' );
				break;
			default:
				$backend = isset( $data['message'] ) && is_scalar( $data['message'] ) ? trim( (string) $data['message'] ) : '';
				$msg     = '' !== $backend ? $backend : __( 'The download could not be authorized. Download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' );
		}
		return array( 'ok' => false, 'code' => '' !== $error ? $error : 'refused', 'url' => '', 'sha256' => '', 'message' => $msg );
	}

	/**
	 * Download, install, and activate Pro from a signed URL,
	 * through WordPress's own upgrader.
	 *
	 * The downloaded zip is verified against the release
	 * checksum from the token response (R-12) before the
	 * upgrader ever sees it: a missing or malformed checksum
	 * refuses the install outright (fail closed — the token
	 * endpoint always sends one, so its absence means the
	 * response cannot be trusted), and a mismatch discards
	 * the download. Nothing is installed on either refusal.
	 *
	 * @param string $url             Signed download URL (single-use, short-lived).
	 * @param string $expected_sha256 SHA-256 of the release zip, from request_download().
	 * @return array { ok: bool, code: string, message: string }
	 */
	public static function install_from_url( $url, $expected_sha256 = '' ) {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return array( 'ok' => false, 'code' => 'capability', 'message' => __( 'Your WordPress user cannot install or activate plugins, so the one-click install cannot run. Ask a site administrator, or upload Pro manually under Plugins → Add New.', 'equalfix' ) );
		}
		$expected_sha256 = strtolower( trim( (string) $expected_sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) ) {
			return array( 'ok' => false, 'code' => 'missing_checksum', 'message' => __( 'The download service did not provide a valid checksum for the Pro package, so the install was refused and nothing was installed. Try again in a little while — or download Pro from your EqualFix dashboard and upload it under Plugins → Add New.', 'equalfix' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = download_url( $url, 120 );
		if ( is_wp_error( $package ) ) {
			return array( 'ok' => false, 'code' => 'download_failed', 'message' => sprintf( __( 'The Pro download failed (%s). Download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' ), $package->get_error_message() ) );
		}
		$actual_sha256 = hash_file( 'sha256', $package );
		if ( false === $actual_sha256 || ! hash_equals( $expected_sha256, (string) $actual_sha256 ) ) {
			unlink( $package ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unverified temp download cleanup.
			return array( 'ok' => false, 'code' => 'checksum_mismatch', 'message' => __( 'The downloaded Pro package did not match the checksum provided by the download service, so it was discarded and nothing was installed. The download may have been corrupted — try the install again, or download Pro from your EqualFix dashboard and upload it under Plugins → Add New.', 'equalfix' ) );
		}
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$installed = $upgrader->install( $package );
		// The downloaded zip is single-use; remove it either way.
		if ( file_exists( $package ) ) {
			unlink( $package ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp download cleanup.
		}
		if ( is_wp_error( $installed ) ) {
			return array( 'ok' => false, 'code' => 'install_failed', 'message' => sprintf( __( 'WordPress could not install the Pro package (%s). This usually means the server cannot write to the plugins folder directly — download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' ), $installed->get_error_message() ) );
		}
		if ( true !== $installed ) {
			return array( 'ok' => false, 'code' => 'install_failed', 'message' => __( 'WordPress could not install the Pro package. This usually means the server cannot write to the plugins folder directly — download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' ) );
		}
		$plugin_file = $upgrader->plugin_info();
		if ( ! $plugin_file ) {
			return array( 'ok' => false, 'code' => 'install_failed', 'message' => __( 'Pro was unpacked but WordPress could not identify the installed plugin. Check Plugins → Installed Plugins — if EqualFix Pro is listed, activate it there.', 'equalfix' ) );
		}
		$activated = activate_plugin( $plugin_file );
		if ( is_wp_error( $activated ) ) {
			return array( 'ok' => false, 'code' => 'activation_failed', 'message' => sprintf( __( 'Pro is installed but could not be activated (%s). Activate it under Plugins → Installed Plugins.', 'equalfix' ), $activated->get_error_message() ) );
		}
		return array( 'ok' => true, 'code' => 'installed', 'message' => '' );
	}

	/**
	 * AI alt-text drafts client (1.3): ask the service to draft
	 * alt text for a batch of image URLs — the same endpoint
	 * Pro's approval queue uses. The free plugin's AI preview
	 * that once called this was removed (DH-4, 2026-11-07:
	 * no AI writing in free), so the free plugin no longer
	 * calls it; the client stays with the installer that owns
	 * the service contract. Drafts only; nothing is applied
	 * anywhere by this call.
	 *
	 * @param string $key   Licence key (free account keys work).
	 * @param array  $items List of {ref, image_url, page_url}.
	 * @return array List of {ref, alt, decorative_candidate,
	 *               confidence}; empty on any failure.
	 */
	public static function vision_drafts( $key, $items ) {
		$key = trim( (string) $key );
		if ( '' === $key || empty( $items ) ) {
			return array();
		}
		$payload_items = array();
		foreach ( $items as $item ) {
			$payload_items[] = array(
				'queue_ref' => (string) $item['ref'],
				'image_url' => (string) $item['image_url'],
				'page_url'  => (string) $item['page_url'],
				'context'   => array( 'caption' => '', 'nearby_text' => '', 'page_title' => '' ),
			);
		}
		$decoded = self::post(
			'/suggestions/alt',
			array(
				'key'      => $key,
				'site_url' => home_url( '/' ),
				'items'    => $payload_items,
			),
			60
		);
		if ( null === $decoded || empty( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded['results'] as $r ) {
			if ( ! is_array( $r ) || isset( $r['error'] ) || ! isset( $r['queue_ref'] ) ) {
				continue;
			}
			// Scalar gates, as in the Pro vision client (round
			// 4 cast sweep): a non-scalar ref has no identity to
			// key on — the row is skipped whole; a non-scalar
			// alt or confidence is absent data.
			if ( ! is_scalar( $r['queue_ref'] ) ) {
				continue;
			}
			$out[] = array(
				'ref'                  => (string) $r['queue_ref'],
				'alt'                  => isset( $r['alt'] ) && is_scalar( $r['alt'] ) ? (string) $r['alt'] : '',
				'decorative_candidate' => ! empty( $r['decorative_candidate'] ),
				'confidence'           => isset( $r['confidence'] ) && is_scalar( $r['confidence'] ) ? (string) $r['confidence'] : '',
			);
		}
		return $out;
	}

	/**
	 * Resolve an image src against its page URL the way a
	 * browser would (mirrors the Pro vision client's helper).
	 *
	 * @param string $src      Raw src attribute.
	 * @param string $page_url Page the image appears on.
	 * @return string Absolute URL, or '' when unresolvable.
	 */
	public static function absolute_url( $src, $page_url ) {
		$src = trim( (string) $src );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $src ) ) {
			return $src;
		}
		$page = wp_parse_url( $page_url );
		if ( empty( $page['scheme'] ) || empty( $page['host'] ) ) {
			$page = wp_parse_url( home_url( '/' ) );
		}
		if ( empty( $page['scheme'] ) || empty( $page['host'] ) ) {
			return '';
		}
		$origin = $page['scheme'] . '://' . $page['host'] . ( isset( $page['port'] ) ? ':' . $page['port'] : '' );
		if ( 0 === strpos( $src, '//' ) ) {
			return $page['scheme'] . ':' . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return $origin . $src;
		}
		$dir = isset( $page['path'] ) ? preg_replace( '#/[^/]*$#', '/', $page['path'] ) : '/';
		return $origin . $dir . $src;
	}

	/**
	 * The full one-click flow: validate → token → install →
	 * hand the key to Pro (transient, never a URL).
	 *
	 * @param string $key Licence key the owner pasted.
	 * @return array { ok: bool, code: string, message: string }
	 */
	public static function install_pro( $key ) {
		$verdict = self::validate_key( $key );
		if ( ! $verdict['ok'] ) {
			return $verdict;
		}
		$download = self::request_download( $key );
		if ( ! $download['ok'] ) {
			return $download;
		}
		$result = self::install_from_url( $download['url'], isset( $download['sha256'] ) ? $download['sha256'] : '' );
		if ( $result['ok'] ) {
			// Hand the key to Pro for first-load validation and
			// storage (EqualFix_Pro::maybe_consume_pending_key()).
			// Ten minutes is ample for the redirect + first load;
			// the transient is deleted by Pro before it validates.
			set_transient( 'equalfix_pro_pending_key', trim( (string) $key ), 10 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * POST JSON to the licence service and decode the verdict body.
	 *
	 * @param string $path   Path under the API base.
	 * @param array  $fields JSON fields.
	 * @return array|null Decoded body, or null on transport failure / unreadable body.
	 */
	private static function post( $path, $fields, $timeout = 30 ) {
		$base = self::api_base();
		if ( '' === $base ) {
			return null;
		}
		$response = wp_remote_post(
			$base . $path,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $fields ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}
