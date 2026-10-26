<?php
/**
 * Plugin Name:       EqualFix – AI Accessibility Auto-Fixer
 * Plugin URI:        https://equalfix.com
 * Description:       Helps improve your site's accessibility by detecting specific issues and applying safe, reversible fixes to the rendered HTML. Includes a full site audit, Fix Set A, and per-fix approval controls.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EqualFix
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       equalfix
 * Domain Path:       /languages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQUALFIX_VERSION', '0.2.1' );
define( 'EQUALFIX_FILE', __FILE__ );
define( 'EQUALFIX_DIR', plugin_dir_path( __FILE__ ) );

require_once EQUALFIX_DIR . 'includes/class-equalfix-fixer.php';
require_once EQUALFIX_DIR . 'includes/class-equalfix-audit.php';
require_once EQUALFIX_DIR . 'includes/class-equalfix-crawler.php';
require_once EQUALFIX_DIR . 'includes/class-equalfix-cache.php';
require_once EQUALFIX_DIR . 'includes/class-equalfix-settings.php';

/**
 * Main plugin class.
 */
class EqualFix_Accessibility {

	/**
	 * Single instance.
	 *
	 * @var EqualFix_Accessibility|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return EqualFix_Accessibility
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Private — use instance().
	 */
	private function __construct() {
		$settings = new EqualFix_Settings();
		$settings->hooks();

		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'equalfix', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Decide whether to buffer this response, and start buffering.
	 */
	public function maybe_start_buffer() {
		if ( ! $this->should_buffer() ) {
			return;
		}
		ob_start( array( $this, 'filter_output' ) );
	}

	/**
	 * Whether the current request should be buffered and fixed.
	 *
	 * @return bool
	 */
	private function should_buffer() {
		// Never in admin, AJAX, REST, cron, CLI, feeds, or embeds.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		// Never buffer non-idempotent requests: a frontend POST (newsletter
		// signup, contact form handler, search-via-POST) that renders HTML
		// would otherwise get its response cached under the page URL and
		// served to the next visitor's GET.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		// Never break page-builder / customizer editing sessions for editors.
		if ( current_user_can( 'edit_posts' ) ) {
			if ( is_preview() || is_customize_preview() ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview detection.
			foreach ( array( 'elementor-preview', 'fl_builder', 'vc_action', 'et_fb' ) as $param ) {
				if ( isset( $_GET[ $param ] ) ) {
					return false;
				}
			}
		}
		// Respect page exclusions.
		$settings = EqualFix_Settings::get();
		if ( $this->is_url_excluded( $settings ) ) {
			return false;
		}
		if ( $this->is_template_excluded( $settings ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Check URL-path exclusions.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function is_url_excluded( $settings ) {
		if ( '' === trim( $settings['excluded_urls'] ) ) {
			return false;
		}
		$path = $this->current_path();
		foreach ( preg_split( '/\r\n|\r|\n/', $settings['excluded_urls'] ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' !== $pattern && false !== stripos( $path, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current request path and query (for exclusions), e.g. '/?page_id=5'.
	 *
	 * @return string
	 */
	private function current_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $request_uri ? $request_uri : '/';
	}

	/**
	 * Current request URL (for the output cache key).
	 *
	 * @return string
	 */
	private function current_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $request_uri );
	}

	/**
	 * Check template exclusions.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function is_template_excluded( $settings ) {
		if ( '' === trim( $settings['excluded_templates'] ) ) {
			return false;
		}
		$template = get_page_template_slug();
		if ( ! $template ) {
			return false;
		}
		$excluded = array_map( 'trim', explode( ',', $settings['excluded_templates'] ) );
		return in_array( basename( $template ), $excluded, true );
	}

	/**
	 * Output-buffer callback: apply fixes to the full HTML response.
	 *
	 * @param string $buffer Buffered output.
	 * @return string Fixed (or original) HTML.
	 */
	public function filter_output( $buffer ) {
		if ( ! is_string( $buffer ) ) {
			return $buffer;
		}
		$settings = EqualFix_Settings::get();
		if ( ! $settings['enabled'] ) {
			return $buffer;
		}

		// Own output cache: visitors only, never carts/checkouts/accounts.
		$use_cache = $settings['output_cache'] && ! is_user_logged_in() && ! defined( 'DONOTCACHEPAGE' );
		if ( $use_cache && function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			$use_cache = false;
		}
		$cache_key = '';
		if ( $use_cache ) {
			$hash      = md5( wp_json_encode( $settings ) );
			$cache_key = EqualFix_Cache::cache_key( $this->current_url(), $hash );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$fixer = new EqualFix_Fixer(
			array(
				'fix_lang'           => (bool) $settings['fix_lang'],
				'fix_alt'            => (bool) $settings['fix_alt'],
				'fix_aria_label'     => (bool) $settings['fix_aria_label'],
				'fix_label_assoc'    => (bool) $settings['fix_label_assoc'],
				'fix_skip_link'      => (bool) $settings['fix_skip_link'],
				'excluded_selectors' => preg_split( '/\r\n|\r|\n/', $settings['excluded_selectors'] ),
				'locale'             => get_bloginfo( 'language' ),
				'skip_link_text'     => __( 'Skip to content', 'equalfix' ),
			)
		);
		$fixed = $fixer->fix( $buffer );

		if ( $use_cache && ! $fixer->did_bail() && '' !== $cache_key ) {
			set_transient( $cache_key, $fixed, HOUR_IN_SECONDS );
		}

		return $fixed;
	}
}

EqualFix_Accessibility::instance();
