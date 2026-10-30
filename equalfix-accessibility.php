<?php
/**
 * Plugin Name:       EqualFix – AI Accessibility Auto-Fixer
 * Plugin URI:        https://equalfix.com
 * Description:       Helps improve your site's accessibility by detecting specific issues and applying safe, reversible fixes to the rendered HTML. Includes a full site audit, Fix Set A, and per-fix-type preview and toggle controls.
 * Version:           1.1.0
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

define( 'EQUALFIX_VERSION', '1.1.0' );
define( 'EQUALFIX_FILE', __FILE__ );
define( 'EQUALFIX_DIR', plugin_dir_path( __FILE__ ) );

// Bundled HTML5 parser (Masterminds/html5-php, namespace-prefixed via
// Strauss as EqualFix\Vendor\Masterminds — see vendor/). Loaded once,
// guarded: if another copy is somehow already registered, ours stands
// down instead of colliding.
if ( ! class_exists( 'EqualFix\\Vendor\\Masterminds\\HTML5', false ) && file_exists( EQUALFIX_DIR . 'vendor/masterminds-prefixed/autoload.php' ) ) {
	require_once EQUALFIX_DIR . 'vendor/masterminds-prefixed/autoload.php';
}
require_once EQUALFIX_DIR . 'includes/class-equalfix-parser.php';
require_once EQUALFIX_DIR . 'includes/class-equalfix-guard.php';
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
		add_action( 'init', array( $this, 'maybe_upgrade' ) );

		// 1.0 fix-delivery tiers 1–2 (WP3): fixes that a native hook
		// or a content filter can deliver never wait for the page
		// buffer. The buffer (tier 3) remains only for what hooks
		// cannot reach, and splices instead of re-serializing.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );
		add_filter( 'get_search_form', array( $this, 'filter_search_form' ) );
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );

		// The output cache keys fixed HTML by URL + settings only, so a
		// content edit changes nothing about the key: without these
		// hooks, visitors would be served pre-edit fixed HTML until
		// the entry expires. Clear the plugin's OWN cache whenever
		// content that can appear on a page changes.
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		foreach ( array( 'deleted_post', 'transition_post_status', 'wp_update_nav_menu', 'edited_term', 'delete_term' ) as $content_hook ) {
			add_action( $content_hook, array( $this, 'invalidate_output_cache' ) );
		}
		// Everything else that changes what a page renders: widgets,
		// theme switches, the Customizer, and comments.
		foreach ( array( 'switch_theme', 'customize_save_after', 'comment_post', 'edit_comment', 'deleted_comment', 'transition_comment_status' ) as $render_hook ) {
			add_action( $render_hook, array( $this, 'invalidate_output_cache' ) );
		}
		add_filter(
			'widget_update_callback',
			function ( $instance ) {
				$this->invalidate_output_cache();
				return $instance;
			}
		);
	}

	/**
	 * save_post handler: skip revisions and autosaves, clear otherwise.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$this->invalidate_output_cache();
	}

	/**
	 * Clear the plugin's own output cache (never third-party caches).
	 */
	public function invalidate_output_cache() {
		$cache = new EqualFix_Cache();
		$cache->clear_own_cache();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'equalfix', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Whether fix delivery is on for this request context. Shared
	 * by the tier filters: master switch on, front-end only, and
	 * never for the audit's own requests (the audit measures the
	 * site's original HTML).
	 *
	 * @return bool
	 */
	private function fixes_active() {
		$settings = EqualFix_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && false !== strpos( $_SERVER['HTTP_USER_AGENT'], 'EqualFix-Accessibility-Audit' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		return true;
	}

	/**
	 * Tier 1: guarantee a lang attribute when the theme asks
	 * WordPress for the <html> attributes. (A theme that hardcodes
	 * its <html> tag is covered by the tier-3 splice instead.)
	 *
	 * @param string $output Attributes string.
	 * @return string
	 */
	public function filter_language_attributes( $output ) {
		$settings = EqualFix_Settings::get();
		if ( ! $this->fixes_active() || empty( $settings['fix_lang'] ) ) {
			return $output;
		}
		if ( false !== stripos( (string) $output, 'lang=' ) ) {
			return $output;
		}
		return $output . ' lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"';
	}

	/**
	 * Tier 1: give the core search form's input an associated
	 * screen-reader label when the form has none. A string-level
	 * insertion into the form fragment, gated by strict canaries:
	 * exactly one search input with an id, and no existing label.
	 *
	 * @param string $form Search form HTML.
	 * @return string
	 */
	public function filter_search_form( $form ) {
		$settings = EqualFix_Settings::get();
		if ( ! $this->fixes_active() || empty( $settings['fix_label_assoc'] ) ) {
			return $form;
		}
		if ( false !== stripos( $form, '<label' ) ) {
			return $form;
		}
		if ( ! preg_match( '/<input\b[^>]*\bname="s"[^>]*>/i', $form, $m ) ) {
			return $form;
		}
		if ( ! preg_match( '/\bid="([^"]+)"/', $m[0], $id ) ) {
			return $form;
		}
		$label = '<label class="screen-reader-text" for="' . esc_attr( $id[1] ) . '">' . esc_html__( 'Search', 'equalfix' ) . '</label>';
		$pos   = strpos( $form, $m[0] );
		if ( false === $pos ) {
			return $form;
		}
		return substr_replace( $form, $label, $pos, 0 );
	}

	/**
	 * Tier 2: fix images, icon links and form fields inside post
	 * content. The mutated surface is the content fragment only;
	 * page-level fixes (lang, skip link) are disabled on the
	 * content fixer instance by construction.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( ! $this->fixes_active() || is_feed() ) {
			return $content;
		}
		// Cheap bail: nothing fixable present.
		if ( ! preg_match( '/<(img|a|input|select|textarea|button)\b/i', $content ) ) {
			return $content;
		}
		$settings = EqualFix_Settings::get();
		if ( empty( $settings['fix_alt'] ) && empty( $settings['fix_aria_label'] ) && empty( $settings['fix_label_assoc'] ) ) {
			return $content;
		}
		$fixer = new EqualFix_Fixer(
			array(
				'fix_lang'           => false,
				'fix_alt'            => (bool) $settings['fix_alt'],
				'fix_aria_label'     => (bool) $settings['fix_aria_label'],
				'fix_label_assoc'    => (bool) $settings['fix_label_assoc'],
				'fix_skip_link'      => false,
				'excluded_selectors' => preg_split( '/\r\n|\r|\n/', $settings['excluded_selectors'] ),
				'locale'             => get_bloginfo( 'language' ),
				'skip_link_text'     => __( 'Skip to content', 'equalfix' ),
			)
		);
		return $fixer->fix_fragment_dom( $content );
	}

	/**
	 * Stamp the data version. 1.0 needs no option migrations —
	 * settings merge over defaults by design and stored audit
	 * results kept their shape — but the stamp gives future
	 * upgrades a reliable foothold (WP6).
	 */
	public function maybe_upgrade() {
		if ( get_option( 'equalfix_db_version' ) !== EQUALFIX_VERSION ) {
			update_option( 'equalfix_db_version', EQUALFIX_VERSION );
		}
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
				// Never buffer the plugin's own audit crawler: the audit must
		// measure the site's original HTML, not the fixer's output.
		// Without this, an audit run while fixes are enabled (the
		// default) reports almost no fixable findings, because the
		// crawl sees pages the fixer has already repaired.
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && false !== strpos( $_SERVER['HTTP_USER_AGENT'], 'EqualFix-Accessibility-Audit' ) ) {
			return false;
		}
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
		$uri  = $this->current_path();
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . ltrim( '' === $path ? '/' : $path, '/' );
		foreach ( preg_split( '/\r\n|\r|\n/', $settings['excluded_urls'] ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			// A pattern containing a query string (plain-permalink
			// sites: /?page_id=5) prefix-matches the full URI at a
			// parameter boundary.
			if ( false !== strpos( $pattern, '?' ) ) {
				if ( 0 === stripos( $uri, $pattern ) ) {
					$rest = substr( $uri, strlen( $pattern ) );
					if ( '' === $rest || '&' === $rest[0] ) {
						return true;
					}
				}
				continue;
			}
			// Path patterns match the START of the path: /checkout
			// covers /checkout/ and /checkout/pay/ but NOT
			// /checkout-info/ (the old substring test did).
			$core = rtrim( '/' . ltrim( $pattern, '/' ), '/' );
			if ( '' === $core ) {
				if ( '/' === $path ) {
					return true;
				}
				continue;
			}
			if ( $path === $core || 0 === strpos( $path, $core . '/' ) ) {
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
		// REQUEST_URI already contains the full path — on a
		// subdirectory install it includes the subdirectory, so
		// home_url() (which prepends the home path) would double it
		// and split the cache keyspace. Build from the home host
		// plus the request path as-is. Used only as a cache key.
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		$host = $home ? $home . ( $port ? ':' . $port : '' ) : ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'localhost' );
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $request_uri;
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
		// WooCommerce session visitors: a page rendered for a guest
		// with a live cart session can carry session-specific markup
		// (mini-cart contents). Serving that render to the next
		// guest from the shared output cache would leak it across
		// visitors, so any WooCommerce session/cart cookie stands
		// the cache down entirely (read and write) for the request.
		if ( $use_cache && ! empty( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $cookie ) {
				if ( 0 === strpos( $cookie, 'wp_woocommerce_session_' ) || 'woocommerce_cart_hash' === $cookie || 'woocommerce_items_in_cart' === $cookie ) {
					$use_cache = false;
					break;
				}
			}
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
			EqualFix_Cache::register_key( $cache_key );
		}

		return $fixed;
	}
}

EqualFix_Accessibility::instance();

register_activation_hook( __FILE__, array( 'EqualFix_Settings', 'activate' ) );
