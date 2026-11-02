<?php
/**
 * EqualFix — One-click Pro installer (1.2, Workstream B)
 *
 * Lives in the FREE plugin so the path from "I bought Pro" to
 * "Pro is running" is a single paste and a single click, with no
 * manual download, no Plugins → Add New → Upload detour:
 *
 *   1. The owner pastes their licence key on the Pro tab.
 *   2. The key is validated against the licence service
 *      (POST /licence/validate) — verdict-first, like Pro itself.
 *   3. A signed, single-use, five-minute download token is
 *      requested (POST /billing/download-token); the token
 *      endpoint re-checks the licence and the subscription's
 *      standing server-side.
 *   4. WordPress's own plugin upgrader downloads the zip from the
 *      returned URL, installs it, and activates it. The key is
 *      handed to Pro through a short-lived transient (never a
 *      URL), and Pro validates and stores it on first load.
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
		$error = isset( $data['error'] ) ? (string) $data['error'] : '';
		if ( ! empty( $data['valid'] ) && isset( $data['status'] ) && 'active' === $data['status'] ) {
			return array( 'ok' => true, 'code' => 'active', 'message' => '' );
		}
		if ( 'domain_mismatch' === $error || ( isset( $data['domain_match'] ) && false === $data['domain_match'] ) ) {
			$registered = isset( $data['site_url'] ) ? (string) $data['site_url'] : '';
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
		$backend = isset( $data['message'] ) ? trim( (string) $data['message'] ) : '';
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
	 * @return array { ok: bool, code: string, message: string, url: string }
	 */
	public static function request_download( $key ) {
		$data = self::post( '/billing/download-token', array( 'key' => trim( (string) $key ), 'site_url' => home_url( '/' ) ) );
		if ( null === $data ) {
			return array( 'ok' => false, 'code' => 'offline', 'url' => '', 'message' => __( 'The download server could not be reached. Try again in a moment — or download Pro from your EqualFix dashboard and upload it under Plugins → Add New.', 'equalfix' ) );
		}
		if ( ! empty( $data['download_url'] ) ) {
			return array( 'ok' => true, 'code' => 'ok', 'url' => (string) $data['download_url'], 'message' => '' );
		}
		$error = isset( $data['error'] ) ? (string) $data['error'] : '';
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
				$backend = isset( $data['message'] ) ? trim( (string) $data['message'] ) : '';
				$msg     = '' !== $backend ? $backend : __( 'The download could not be authorized. Download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' );
		}
		return array( 'ok' => false, 'code' => '' !== $error ? $error : 'refused', 'url' => '', 'message' => $msg );
	}

	/**
	 * Download, install, and activate Pro from a signed URL,
	 * through WordPress's own upgrader.
	 *
	 * @param string $url Signed download URL (single-use, short-lived).
	 * @return array { ok: bool, code: string, message: string }
	 */
	public static function install_from_url( $url ) {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return array( 'ok' => false, 'code' => 'capability', 'message' => __( 'Your WordPress user cannot install or activate plugins, so the one-click install cannot run. Ask a site administrator, or upload Pro manually under Plugins → Add New.', 'equalfix' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = download_url( $url, 120 );
		if ( is_wp_error( $package ) ) {
			return array( 'ok' => false, 'code' => 'download_failed', 'message' => sprintf( __( 'The Pro download failed (%s). Download Pro from your EqualFix dashboard and upload it under Plugins → Add New instead.', 'equalfix' ), $package->get_error_message() ) );
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
		$result = self::install_from_url( $download['url'] );
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
	private static function post( $path, $fields ) {
		$base = self::api_base();
		if ( '' === $base ) {
			return null;
		}
		$response = wp_remote_post(
			$base . $path,
			array(
				'timeout' => 30,
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
