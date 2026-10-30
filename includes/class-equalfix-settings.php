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
		$settings            = self::get();
		$settings['enabled'] = empty( $settings['enabled'] ) ? 1 : 0;
		if ( 1 === $settings['enabled'] && $this->all_free_fixes_on_types( $settings ) && empty( $settings['fixes_activated_at'] ) ) {
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
	 * Whether one fix type's toggle is on in a given settings
	 * array (findings with no mappable fix type count as off).
	 *
	 * @param string $fix      Fix type constant value.
	 * @param array  $settings Settings.
	 * @return bool
	 */
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
		);
		if ( null !== $state['previous_total'] ) {
			$result['previous_total'] = $state['previous_total'];
			$result['previous_time']  = $state['previous_time'];
		}
		update_option( self::AUDIT_OPTION, $result, false );
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
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'equalfix' ) );
		}
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'audit';
		$settings = self::get();
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

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'EqualFix sections', 'equalfix' ); ?>">
				<a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'audit' ) ); ?>" class="nav-tab <?php echo 'audit' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Audit', 'equalfix' ); ?></a>
				<a href="<?php echo esc_url( $this->tab_url( 'purge' ) ); ?>" class="nav-tab <?php echo 'purge' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Purge log', 'equalfix' ); ?></a>
			</nav>

			<?php
			if ( 'audit' === $tab ) {
				$this->render_audit();
			} elseif ( 'purge' === $tab ) {
				$this->render_purge();
			} else {
				$this->render_settings( $settings );
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
		$slugs = array(
			'audit'    => 'equalfix-accessibility',
			'settings' => 'equalfix-settings',
			'purge'    => 'equalfix-purge-log',
		);
		$slug  = isset( $slugs[ $tab ] ) ? $slugs[ $tab ] : $slugs['audit'];
		return add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
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
			<h2><?php esc_html_e( 'Fix types (Fix Set A — the complete free fix set)', 'equalfix' ); ?></h2>
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
			</table>
			</div>

			<div class="equalfix-card">
			<h2><?php esc_html_e( 'Service connection', 'equalfix' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'equalfix' ); ?></th>
					<td><?php echo $settings['connect_opt_in'] ? esc_html__( 'Opted in — waiting for service configuration.', 'equalfix' ) : esc_html__( 'Not connected. The plugin works fully in local mode: on-site audit and built-in fixes.', 'equalfix' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Opt in', 'equalfix' ); ?></th>
					<td>
						<label for="equalfix_connect_opt_in">
							<input type="checkbox" id="equalfix_connect_opt_in" name="equalfix_connect_opt_in" value="1" <?php checked( $settings['connect_opt_in'], 1 ); ?>>
							<?php esc_html_e( 'Allow this plugin to send page data to the EqualFix scanning service for deeper analysis.', 'equalfix' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only with this box checked, the plugin may send: your site URL, page URLs, and rendered HTML for analysis. It never sends visitor, customer, order, or personal data. See the readme privacy section for details.', 'equalfix' ); ?> <a href="https://equalfix.com/privacy" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'equalfix' ); ?></a> · <a href="https://equalfix.com/terms" target="_blank" rel="noopener"><?php esc_html_e( 'Terms of Service', 'equalfix' ); ?></a></p>
					</td>
				</tr>
			</table>
			</div>

			<?php submit_button( __( 'Save settings', 'equalfix' ) ); ?>
		</form>

		<div class="equalfix-pro-card">
			<div class="equalfix-pro-main">
				<span class="equalfix-pro-badge"><?php esc_html_e( 'Pro', 'equalfix' ); ?></span>
				<h2><?php esc_html_e( 'Need more than the free fixes?', 'equalfix' ); ?></h2>
				<p><?php esc_html_e( 'The Pro add-on picks up where the free fix set stops — and keeps watching your site after you fix it.', 'equalfix' ); ?></p>
				<ul class="equalfix-pro-features">
					<li><?php esc_html_e( 'Fix Set B: heading order, menu & accordion states, table headers', 'equalfix' ); ?></li>
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
		<?php
	}

	/**
	 * Render the audit tab.
	 */
	private function render_audit() {
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) ) {
			$this->render_dashboard_empty();
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
			$notes[] = sprintf(
				/* translators: %d: number of pages that could not be scanned at all */
				__( '%d page(s) could not be scanned at all — they could not be fetched, and the in-WordPress render fallback did not succeed for them either.', 'equalfix' ),
				(int) $result['pages_failed']
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
		<?php $this->render_dashboard( $result, $counts, $queue_eligible, $settings, $notes ); ?>

		<?php $this->render_journey( $result, $counts, $queue_eligible ); ?>

		<?php if ( ! $all_on ) : ?>
			<?php $this->render_fix_previews(); ?>
		<?php endif; ?>

		<h3 id="equalfix-findings"><?php esc_html_e( 'Findings', 'equalfix' ); ?></h3>
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
			// Findings as cards grouped by page (1.1 UI batch).
			$by_page = array();
			foreach ( $result['findings'] as $finding ) {
				$by_page[ $finding['url'] ][] = $finding;
			}
			$g = 0;
			?>
			<div class="equalfix-findings">
				<?php foreach ( $by_page as $url => $items ) : ?>
					<?php $g++; ?>
					<section class="eqf-pagegroup">
						<h4 class="eqf-pagehead">
							<button type="button" class="eqf-toggle" aria-expanded="true" aria-controls="eqf-pg-<?php echo esc_attr( $g ); ?>"><span class="eqf-caret" aria-hidden="true">▾</span></button>
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( wp_parse_url( $url, PHP_URL_PATH ) ); ?></a>
							<span class="eqf-pagecount"><?php echo esc_html( sprintf( _n( '%d finding', '%d findings', count( $items ), 'equalfix' ), count( $items ) ) ); ?></span>
						</h4>
						<div class="eqf-pagebody" id="eqf-pg-<?php echo esc_attr( $g ); ?>">
							<?php foreach ( $items as $finding ) : ?>
								<article class="eqf-finding">
									<div class="eqf-finding-tags">
										<span class="equalfix-pill equalfix-sev-<?php echo esc_attr( sanitize_html_class( strtolower( $finding['severity'] ) ) ); ?>"><?php echo esc_html( ucfirst( $finding['severity'] ) ); ?></span>
										<span class="equalfix-pill equalfix-cat-<?php echo esc_attr( sanitize_html_class( $finding['category'] ) ); ?>"><?php echo esc_html( EqualFix_Audit::category_label( $finding['category'] ) ); ?></span>
										<?php if ( '' !== $finding['wcag'] ) : ?>
											<span class="eqf-wcag"><?php echo esc_html( sprintf( __( 'WCAG %s', 'equalfix' ), $finding['wcag'] ) ); ?></span>
										<?php endif; ?>
										<?php if ( in_array( $finding['url'], $rendered_urls, true ) ) : ?>
											<span class="equalfix-pill"><?php esc_html_e( 'Audited via in-process simulation', 'equalfix' ); ?></span>
										<?php endif; ?>
									</div>
									<strong class="eqf-finding-title"><?php echo esc_html( $finding['title'] ); ?></strong>
									<p class="eqf-finding-expl"><?php echo esc_html( $finding['explanation'] ); ?></p>
									<pre class="eqf-snippet"><code><?php echo esc_html( $finding['snippet'] ); ?></code></pre>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
			<script>
			// Collapsible page groups + Inspect Fix disclosures (1.1).
			// Progressive enhancement only: with JS off, everything
			// stays visible (groups start expanded, panels unhidden
			// by their buttons' handlers only when JS runs).
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
		$fixed_n     = count( $fixed_list );
		$remaining_n = max( 0, $total - $fixed_n );
		$remaining_a = max( 0, (int) $counts['fix-a'] - $fixed_n );
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
		?>
		<div class="eqd-hero">
			<div class="eqd-hero-main">
				<h2 class="eqd-hero-title"><?php esc_html_e( 'Welcome back', 'equalfix' ); ?></h2>
				<p class="eqd-hero-sub">
				<?php
				if ( 0 === $total ) {
					echo esc_html( sprintf( __( 'Your last scan checked %d pages and found no issues. Re-run the audit after content changes to keep it that way.', 'equalfix' ), (int) $result['pages_scanned'] ) );
				} else {
					echo esc_html( sprintf( __( 'Your last scan found %1$d issues across %2$d pages — %3$d already fixed, %4$d more fixable free, %5$d fixable by Pro, and %6$d need your words.', 'equalfix' ), $total, (int) $result['pages_scanned'], $fixed_n, $remaining_a, (int) $counts['fix-b'], (int) $counts['human'] ) );
				}
				?>
				</p>
				<div class="eqd-hero-actions">
					<?php $this->render_audit_form(); ?>
					<a class="button eqd-btn-ghost" href="#equalfix-findings"><?php esc_html_e( 'View findings', 'equalfix' ); ?></a>
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
					<?php if ( null !== $coverage ) : ?>
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
				<span class="eqd-tile-icon eqd-tint-blue"><?php echo $this->icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
				<span class="eqd-tile-num"><?php echo esc_html( (int) $result['pages_scanned'] ); ?></span>
				<span class="eqd-tile-label"><?php esc_html_e( 'Pages scanned', 'equalfix' ); ?></span>
				<span class="eqd-tile-sub"><?php echo esc_html( (int) $result['pages_failed'] > 0 ? sprintf( __( '%d could not be scanned', 'equalfix' ), (int) $result['pages_failed'] ) : __( 'Public pages checked', 'equalfix' ) ); ?></span>
			</div>
			<div class="eqd-tile">
				<span class="eqd-tile-icon eqd-tint-green"><?php echo $this->icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
				<span class="eqd-tile-num"><?php echo esc_html( $fixed_n ); ?></span>
				<span class="eqd-tile-label"><?php esc_html_e( 'Issues fixed', 'equalfix' ); ?></span>
				<span class="eqd-tile-sub"><?php echo esc_html( $fixed_n > 0 ? ( ! empty( $settings['fixes_activated_at'] ) ? sprintf( __( 'Active since %s', 'equalfix' ), date_i18n( get_option( 'date_format' ), (int) $settings['fixes_activated_at'] ) ) : __( 'Applied at render by EqualFix', 'equalfix' ) ) : ( (int) $counts['fix-a'] > 0 ? __( 'Use “Fix all free issues” below', 'equalfix' ) : __( 'Nothing to fix here', 'equalfix' ) ) ); ?></span>
			</div>
			<div class="eqd-tile">
				<span class="eqd-tile-icon eqd-tint-amber"><?php echo $this->icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal SVG strings. ?></span>
				<span class="eqd-tile-num"><?php echo esc_html( $remaining_n ); ?></span>
				<span class="eqd-tile-label"><?php esc_html_e( 'Remaining issues', 'equalfix' ); ?></span>
				<span class="eqd-tile-sub"><?php echo esc_html( 0 === $remaining_n ? __( 'All clear', 'equalfix' ) : __( 'Needs attention', 'equalfix' ) ); ?></span>
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
				<div class="eqd-card-head"><h3><?php esc_html_e( 'Top issues', 'equalfix' ); ?></h3><a href="#equalfix-findings"><?php esc_html_e( 'View all', 'equalfix' ); ?></a></div>
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
					<a class="eqd-quick-row" href="#equalfix-findings">
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
				$groups[ $title ] = array( 'count' => 0, 'category' => $finding['category'], 'fix' => isset( $finding['fix'] ) ? $finding['fix'] : '' );
			}
			$groups[ $title ]['count']++;
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
		?>
		<ul class="eqd-top-list">
			<?php foreach ( $groups as $title => $g ) : ?>
				<?php
				if ( EqualFix_Audit::CAT_FIX_A === $g['category'] ) {
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
					<p class="eqd-feature-plan"><?php esc_html_e( 'Pro add-on', 'equalfix' ); ?> <span class="eqd-badge eqd-badge-pro"><?php esc_html_e( 'Available', 'equalfix' ); ?></span></p>
					<ul class="eqd-feature-list eqd-feature-list-pro">
						<li><?php esc_html_e( 'Fix Set B — more automatic fixes', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Approval queue with drafted suggestions', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Scheduled monitoring with email summaries', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'PDF remediation reports — your proof-of-effort record', 'equalfix' ); ?></li>
						<li><?php esc_html_e( 'Per-page findings inbox', 'equalfix' ); ?></li>
					</ul>
					<p class="eqd-feature-cta"><a class="button button-primary" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro — $39/mo', 'equalfix' ); ?></a></p>
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
		if ( $fix_b > 0 || $queue_eligible > 0 ) {
			// ONE Pro card with Pro's full, honest coverage
			// (1.0.2): what it fixes automatically, plus what
			// its approval queue drafts suggestions for. The
			// scattered per-section Pro links are gone.
			?>
			<div class="equalfix-card equalfix-flow eqf-pro-panel">
				<h3><?php esc_html_e( 'Fix more with Pro', 'equalfix' ); ?></h3>
				<?php if ( $fix_b > 0 ) : ?>
					<p><strong><?php echo esc_html( sprintf( _n( 'Pro fixes %d finding automatically', 'Pro fixes %d findings automatically', $fix_b, 'equalfix' ), $fix_b ) ); ?></strong> <?php esc_html_e( '(headings, tables, menus, and more in Fix Set B):', 'equalfix' ); ?></p>
					<?php $this->render_pro_gap_rows( $by_cat['fix-b'] ); ?>
				<?php endif; ?>
				<?php if ( $queue_eligible > 0 ) : ?>
					<p><strong><?php echo esc_html( sprintf( _n( 'Pro drafts an approval-ready suggestion for %d more', 'Pro drafts approval-ready suggestions for %d more', $queue_eligible, 'equalfix' ), $queue_eligible ) ); ?></strong> <?php esc_html_e( '— alt text, form labels, and control names derived from your own pages, in an approval queue. Nothing is applied until you approve it, and you can edit any suggestion first.', 'equalfix' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Pro also adds scheduled monitoring (new issues are caught without re-running audits by hand) and dated PDF remediation reports — the proof-of-effort record for your files.', 'equalfix' ); ?></p>
				<p><a href="<?php echo esc_url( $pro_url ); ?>" class="button button-primary button-hero" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro — $39/mo', 'equalfix' ); ?></a> <a href="https://equalfix.com/education" target="_blank" rel="noopener"><?php esc_html_e( 'Learn why these matter', 'equalfix' ); ?></a></p>
			</div>
			<?php
		}

		if ( $human > 0 ) {
			?>
			<div class="equalfix-card equalfix-flow">
				<h3><?php esc_html_e( 'Needs your words', 'equalfix' ); ?></h3>
				<p><?php esc_html_e( 'These need a name, a description, or a judgment no plugin should invent. Each one below names the page and the exact element, and the findings list explains what to write:', 'equalfix' ); ?></p>
				<?php if ( $queue_eligible > 0 ) : ?>
					<p class="eqf-queue-note"><?php echo esc_html( sprintf( __( 'Pro drafts suggestions for %d of these — page-derived alt text, labels, and names you approve or edit before anything goes live.', 'equalfix' ), $queue_eligible ) ); ?></p>
				<?php endif; ?>
				<?php $this->render_journey_list( $by_cat['human'] ); ?>
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
	private function render_fixed_section( $result, $fixed_list, $settings ) {
		$labels  = self::fix_types();
		$by_page = array();
		foreach ( $fixed_list as $finding ) {
			$by_page[ $finding['url'] ][] = $finding;
		}
		$k = 0;
		?>
		<div class="equalfix-card equalfix-flow eqf-fixed-card">
			<h3><?php esc_html_e( '✓ Fixed — active on your site', 'equalfix' ); ?></h3>
			<p><?php esc_html_e( 'These findings are being fixed at render time. Fixes apply to rendered HTML only — nothing is written to your files or content, and switching a fix type off restores the original output.', 'equalfix' ); ?><?php
			if ( ! empty( $settings['fixes_activated_at'] ) ) {
				echo ' ' . esc_html( sprintf( __( 'Free fixes active since %s.', 'equalfix' ), date_i18n( get_option( 'date_format' ), (int) $settings['fixes_activated_at'] ) ) );
			}
			?></p>
			<?php foreach ( $by_page as $url => $items ) : ?>
				<h4 class="eqf-fixed-page"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( wp_parse_url( $url, PHP_URL_PATH ) ); ?></a> <span class="eqf-pagecount"><?php echo esc_html( sprintf( _n( '%d fixed', '%d fixed', count( $items ), 'equalfix' ), count( $items ) ) ); ?></span></h4>
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
			<p><a href="<?php echo esc_url( $this->tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Fine-tune each fix type in Settings', 'equalfix' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Re-run the audit after changing your content to refresh this list.', 'equalfix' ); ?></p>
		</div>
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
					<?php echo esc_html( $finding['title'] ); ?> — <a href="<?php echo esc_url( $finding['url'] ); ?>"><?php echo esc_html( wp_parse_url( $finding['url'], PHP_URL_PATH ) ); ?></a>
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
