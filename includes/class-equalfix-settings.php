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

	const OPTION = 'equalfix_settings';
	const AUDIT_OPTION = 'equalfix_audit_results';

	public static function fix_types() {
		return array(
			EqualFix_Fixer::FIX_LANG        => __( 'Add missing page language (lang attribute)', 'equalfix' ),
			EqualFix_Fixer::FIX_ALT         => __( 'Add empty alt text to decorative images', 'equalfix' ),
			EqualFix_Fixer::FIX_ARIA_LABEL  => __( 'Label icon-only buttons and links', 'equalfix' ),
			EqualFix_Fixer::FIX_LABEL_ASSOC => __( 'Associate form fields with their visible labels', 'equalfix' ),
			EqualFix_Fixer::FIX_SKIP_LINK   => __( 'Add skip-to-content link where the theme lacks one', 'equalfix' ),
		);
	}

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

	public static function get() {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_first_run_redirect' ), 1 );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_init', array( $this, 'maybe_continue_audit' ), 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function maybe_first_run_redirect() {
		if ( ! get_transient( 'equalfix_first_run' ) ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) {
			delete_transient( 'equalfix_first_run' );
			return;
		}
		delete_transient( 'equalfix_first_run' );
		if ( isset( $_GET['page'] ) && 'equalfix-accessibility' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=equalfix-accessibility&tab=audit' ) );
		exit;
	}

	public function menu() {
		add_options_page( __( 'EqualFix Accessibility', 'equalfix' ), __( 'EqualFix', 'equalfix' ), 'manage_options', 'equalfix-accessibility', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( 'settings_page_equalfix-accessibility' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'equalfix-admin', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css', array(), EQUALFIX_VERSION );
	}

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
		} elseif ( 'purge_now' === $action ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Manual purge from settings.', 'equalfix' ) );
			$this->redirect( 'purge', __( 'Caches purged.', 'equalfix' ) );
		}
	}
	private function save_settings() {
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
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Settings changed.', 'equalfix' ) );
		$this->redirect( 'settings', __( 'Settings saved and caches purged.', 'equalfix' ) );
	}

	private function fix_all_free() {
		$settings            = self::get();
		$settings['enabled'] = 1;
		foreach ( array( 'fix_lang', 'fix_alt', 'fix_aria_label', 'fix_label_assoc', 'fix_skip_link' ) as $key ) {
			$settings[ $key ] = 1;
		}
		update_option( self::OPTION, $settings );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Free fixes enabled from the audit tab.', 'equalfix' ) );
		$this->redirect( 'audit', __( 'Free fixes are on — the free fix set is now being applied to your site’s rendered pages.', 'equalfix' ) );
	}

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
	public function maybe_continue_audit() {
		if ( ! isset( $_GET['equalfix_continue'] ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['equalfix_continue'] ) );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'equalfix_continue_' . $token ) ) {
			return;
		}
		$result = get_option( self::AUDIT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['step_state'] ) || ! hash_equals( (string) $result['step_state']['token'], $token ) ) {
			return;
		}
		$this->audit_step( $result['step_state'] );
	}

	private function audit_step( $state ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$this->register_render_death_watch();
		$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
		$crawler = new EqualFix_Crawler();
		$audit   = new EqualFix_Audit();
		while ( ! empty( $state['urls'] ) ) {
			$url  = $state['urls'][0];
			$html = $crawler->fetch( $url );
			if ( false === $html && 'deferred' === $crawler->last_failure_kind() ) {
				break;
			}
			array_shift( $state['urls'] );
			if ( false === $html ) {
				if ( '' === $state['fetch_error'] ) {
					$state['fetch_error'] = $crawler->last_error();
				}
				$state['pages'][] = array( 'url' => $url, 'status' => 'fetch_failed', 'findings' => 0 );
			} else {
				$mode = $crawler->last_fetch_mode();
				if ( 'render' === $mode ) {
					$state['rendered']++;
				}
				$state['ok']++;
				$page_findings = $audit->audit_page( $html, $url );
				$state['total'] += count( $page_findings );
				$state['pages'][] = array( 'url' => $url, 'status' => 'ok', 'findings' => count( $page_findings ), 'mode' => $mode );
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
			foreach ( $state['urls'] as $url ) {
				$state['pages'][] = array( 'url' => $url, 'status' => 'fetch_failed', 'findings' => 0 );
			}
			$state['urls'] = array();
		}
		$this->finish_audit( $state );
	}

	private function continue_url( $state ) {
		return add_query_arg( array( 'page' => 'equalfix-accessibility', 'tab' => 'audit', 'equalfix_continue' => $state['token'], '_wpnonce' => wp_create_nonce( 'equalfix_continue_' . $state['token'] ) ), admin_url( 'options-general.php' ) );
	}

	private static $watch_registered = false;
	private static $watch_ob_level = 0;

	private function register_render_death_watch() {
		if ( self::$watch_registered ) {
			return;
		}
		self::$watch_registered = true;
		self::$watch_ob_level   = ob_get_level();
		register_shutdown_function( array( $this, 'handle_render_death' ) );
	}

	public function handle_render_death() {
		if ( ! EqualFix_Crawler::render_in_progress() ) {
			return;
		}
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
		$state['pages'][] = array( 'url' => $url, 'status' => 'fetch_failed', 'findings' => 0 );
		if ( ! empty( $state['urls'] ) && $state['steps'] <= 40 ) {
			$state['steps']++;
			$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
			header( 'Location: ' . $this->continue_url( $state ) );
			return;
		}
		$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state );
		$this->finish_audit( $state );
	}
	private function finish_audit( $state ) {
		if ( $state['fetch_error'] && 0 === $state['ok'] ) {
			$this->save_audit_progress( $state['pages'], $state['findings'], $state['total'], $state['ok'], $state, $state['fetch_error'] );
			$this->redirect( 'audit', __( 'Audit could not fetch any pages. Check the details on the Audit tab.', 'equalfix' ) );
		}
		$counts = array( 'fix_a' => 0, 'fix_b' => 0, 'review' => 0 );
		foreach ( $state['findings'] as $finding ) {
			if ( isset( $counts[ $finding['category'] ] ) ) {
				$counts[ $finding['category'] ]++;
			}
		}
		$result = array(
			'time'       => current_time( 'mysql' ),
			'pages'      => $state['pages'],
			'findings'   => $state['findings'],
			'total'      => $state['total'],
			'ok'         => $state['ok'],
			'rendered'   => $state['rendered'],
			'counts'     => $counts,
			'previews'   => $state['previews'],
			'fetch_error'=> $state['fetch_error'],
		);
		update_option( self::AUDIT_OPTION, $result );
		$cache = new EqualFix_Cache();
		$cache->purge_all( __( 'Audit finished.', 'equalfix' ) );
		$this->redirect( 'audit', __( 'Audit complete.', 'equalfix' ) );
	}

	private function save_audit_progress( $pages, $findings, $total, $ok, $state, $fetch_error = '' ) {
		$existing = get_option( self::AUDIT_OPTION, array() );
		$progress = array(
			'time'       => current_time( 'mysql' ),
			'pages'      => $pages,
			'findings'   => $findings,
			'total'      => $total,
			'ok'         => $ok,
			'rendered'   => isset( $state['rendered'] ) ? $state['rendered'] : 0,
			'counts'     => array( 'fix_a' => 0, 'fix_b' => 0, 'review' => 0 ),
			'previews'   => isset( $state['previews'] ) ? $state['previews'] : array(),
			'fetch_error'=> $fetch_error ? $fetch_error : $state['fetch_error'],
			'in_progress'=> true,
			'step_state' => $state,
		);
		foreach ( $findings as $finding ) {
			if ( isset( $progress['counts'][ $finding['category'] ] ) ) {
				$progress['counts'][ $finding['category'] ]++;
			}
		}
		$progress['previous_total'] = isset( $state['previous_total'] ) ? $state['previous_total'] : null;
		$progress['previous_time']  = isset( $state['previous_time'] ) ? $state['previous_time'] : null;
		update_option( self::AUDIT_OPTION, $progress );
	}

	private function redirect( $tab, $message = '' ) {
		$args = array( 'page' => 'equalfix-accessibility', 'tab' => $tab );
		if ( '' !== $message ) {
			$args['equalfix_notice'] = rawurlencode( $message );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'equalfix' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'audit', 'purge' ), true ) ) {
			$tab = 'settings';
		}
		$notice = '';
		if ( isset( $_GET['equalfix_notice'] ) ) {
			$notice = sanitize_text_field( wp_unslash( $_GET['equalfix_notice'] ) );
		}
		echo '<div class="wrap equalfix-admin">';
		echo '<h1>' . esc_html__( 'EqualFix Accessibility', 'equalfix' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'equalfix' ) . '</a>';
		echo '<a class="nav-tab' . ( 'audit' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'audit' ) ) . '">' . esc_html__( 'Audit', 'equalfix' ) . '</a>';
		echo '<a class="nav-tab' . ( 'purge' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->tab_url( 'purge' ) ) . '">' . esc_html__( 'Purge log', 'equalfix' ) . '</a>';
		echo '</h2>';
		if ( 'settings' === $tab ) {
			$this->render_settings();
		} elseif ( 'audit' === $tab ) {
			$this->render_audit();
		} else {
			$this->render_purge();
		}
		echo '</div>';
	}

	private function tab_url( $tab ) {
		return add_query_arg( array( 'page' => 'equalfix-accessibility', 'tab' => $tab ), admin_url( 'options-general.php' ) );
	}
	private function render_settings() {
		$settings = self::get();
		$types    = self::fix_types();
		$checks   = array( 'fix_lang' => __( 'Page language', 'equalfix' ), 'fix_alt' => __( 'Decorative images', 'equalfix' ), 'fix_aria_label' => __( 'Icon-only controls', 'equalfix' ), 'fix_label_assoc' => __( 'Form labels', 'equalfix' ), 'fix_skip_link' => __( 'Skip link', 'equalfix' ) );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="save_settings" />
			<h2><?php esc_html_e( 'Fixes applied at render time', 'equalfix' ); ?></h2>
			<p><?php esc_html_e( 'EqualFix fixes the rendered HTML of every public page — your theme and database are never touched. Turn the master switch off and your site returns to exactly how it was.', 'equalfix' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable EqualFix', 'equalfix' ); ?></th>
					<td><label><input type="checkbox" name="equalfix_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Apply fixes to rendered pages', 'equalfix' ); ?></label></td>
				</tr>
				<?php foreach ( $checks as $key => $label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><label><input type="checkbox" name="equalfix_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> /> <?php echo esc_html( $types[ 'fix_' . substr( $key, 4 ) ] ?? $types[ $key ] ?? '' ); ?></label></td>
				</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="equalfix-excluded-urls"><?php esc_html_e( 'Exclude URLs', 'equalfix' ); ?></label></th>
					<td><textarea id="equalfix-excluded-urls" name="equalfix_excluded_urls" rows="4" cols="60"><?php echo esc_textarea( $settings['excluded_urls'] ); ?></textarea><p class="description"><?php esc_html_e( 'One URL fragment per line. Any page whose URL contains a fragment is left untouched.', 'equalfix' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="equalfix-excluded-selectors"><?php esc_html_e( 'Exclude selectors', 'equalfix' ); ?></label></th>
					<td><textarea id="equalfix-excluded-selectors" name="equalfix_excluded_selectors" rows="4" cols="60"><?php echo esc_textarea( $settings['excluded_selectors'] ); ?></textarea><p class="description"><?php esc_html_e( 'One CSS selector per line. Elements matching a selector are never altered.', 'equalfix' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="equalfix-excluded-templates"><?php esc_html_e( 'Exclude templates', 'equalfix' ); ?></label></th>
					<td><input id="equalfix-excluded-templates" type="text" name="equalfix_excluded_templates" value="<?php echo esc_attr( $settings['excluded_templates'] ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Comma-separated template slugs, e.g. page-contact, single-product.', 'equalfix' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache behaviour', 'equalfix' ); ?></th>
					<td>
						<label><input type="checkbox" name="equalfix_auto_purge" value="1" <?php checked( ! empty( $settings['auto_purge'] ) ); ?> /> <?php esc_html_e( 'Purge caches automatically when content or settings change', 'equalfix' ); ?></label><br />
						<label><input type="checkbox" name="equalfix_output_cache" value="1" <?php checked( ! empty( $settings['output_cache'] ) ); ?> /> <?php esc_html_e( 'Cache fixed output in memory for repeated requests', 'equalfix' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connect (optional)', 'equalfix' ); ?></th>
					<td><label><input type="checkbox" name="equalfix_connect_opt_in" value="1" <?php checked( ! empty( $settings['connect_opt_in'] ) ); ?> /> <?php esc_html_e( 'Let EqualFix check this site’s license and fetch Pro suggestions', 'equalfix' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. Nothing is sent anywhere until you opt in.', 'equalfix' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'equalfix' ) ); ?>
		</form>
		<div class="equalfix-pro-card">
			<h2><?php esc_html_e( 'EqualFix Pro', 'equalfix' ); ?></h2>
			<p><?php esc_html_e( 'Pro drafts the words you still need — alt text, labels and accessible names — and applies them site-wide.', 'equalfix' ); ?></p>
			<p><strong><?php esc_html_e( '$39/mo', 'equalfix' ); ?></strong> <?php esc_html_e( 'One site · $99/mo for 5 sites · $199/mo for 15', 'equalfix' ); ?></p>
			<p><?php esc_html_e( 'Annual billing saves 15%', 'equalfix' ); ?></p>
			<p><a class="button button-primary" href="https://equalfix.com/pro" target="_blank" rel="noopener"><?php esc_html_e( 'Get EqualFix Pro', 'equalfix' ); ?></a></p>
		</div>
		<?php
	}
	private function render_audit() {
		$result = get_option( self::AUDIT_OPTION, null );
		?>
		<h2><?php esc_html_e( 'Site audit', 'equalfix' ); ?></h2>
		<p><?php esc_html_e( 'The audit crawls your public pages and lists every accessibility issue it can see, grouped by what EqualFix can fix for free, what needs your words (Pro), and what still needs a human review.', 'equalfix' ); ?></p>
		<form id="equalfix-audit-form" method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="run_audit" />
			<?php submit_button( __( 'Run audit', 'equalfix' ), 'primary', 'submit', false ); ?>
			<span id="equalfix-audit-status" class="equalfix-audit-status" aria-live="polite" hidden></span>
		</form>
		<script>
		// Immediate feedback on submit (1.0.2)
		(function(){
			var form = document.getElementById('equalfix-audit-form');
			if (!form) return;
			form.addEventListener('submit', function(){
				var btn = form.querySelector('input[type="submit"]');
				var status = document.getElementById('equalfix-audit-status');
				if (btn) { btn.disabled = true; btn.value = 'Auditing…'; }
				if (status) { status.hidden = false; status.textContent = 'Auditing…'; }
			});
		})();
		</script>
		<?php
		if ( ! is_array( $result ) || empty( $result['findings'] ) && empty( $result['pages'] ) ) {
			echo '<p>' . esc_html__( 'No audit has been run yet. Run an audit to see your site’s accessibility issues.', 'equalfix' ) . '</p>';
			return;
		}
		if ( ! empty( $result['in_progress'] ) ) {
			echo '<p>' . esc_html__( 'Audit in progress…', 'equalfix' ) . '</p>';
		}
		$counts = isset( $result['counts'] ) ? $result['counts'] : array( 'fix_a' => 0, 'fix_b' => 0, 'review' => 0 );
		$fix_a  = (int) ( $counts['fix_a'] ?? 0 );
		$fix_b  = (int) ( $counts['fix_b'] ?? 0 );
		$review = (int) ( $counts['review'] ?? 0 );
		$total  = (int) ( $result['total'] ?? 0 );
		$pages_checked = count( (array) ( $result['pages'] ?? array() ) );
		$ok_pages = (int) ( $result['ok'] ?? 0 );
		// Count review findings that Pro could draft words for: missing alt, missing label, missing accessible name.
		$queue_eligible = 0;
		foreach ( (array) ( $result['findings'] ?? array() ) as $finding ) {
			if ( ( $finding['category'] ?? '' ) !== EqualFix_Audit::CAT_REVIEW ) {
				continue;
			}
			$title = strtolower( (string) ( $finding['title'] ?? '' ) );
			if ( false !== strpos( $title, 'missing alt text' ) || false !== strpos( $title, 'has no associated label' ) || false !== strpos( $title, 'has no accessible name' ) ) {
				$queue_eligible++;
			}
		}
		echo '<h3>' . esc_html__( 'Latest audit', 'equalfix' ) . '</h3>';
		echo '<p>' . sprintf( esc_html__( 'Checked %1$d pages (%2$d fetched successfully) · %3$d issues found', 'equalfix' ), $pages_checked, $ok_pages, $total ) . '</p>';
		if ( isset( $result['previous_total'] ) && null !== $result['previous_total'] ) {
			$delta = $total - (int) $result['previous_total'];
			if ( $delta < 0 ) {
				echo '<p>' . sprintf( esc_html__( '%d fewer issues than the previous audit.', 'equalfix' ), abs( $delta ) ) . '</p>';
			} elseif ( $delta > 0 ) {
				echo '<p>' . sprintf( esc_html__( '%d more issues than the previous audit.', 'equalfix' ), $delta ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Same number of issues as the previous audit.', 'equalfix' ) . '</p>';
			}
		}
		if ( ! empty( $result['fetch_error'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $result['fetch_error'] ) . '</p></div>';
		}
		$this->render_journey( $result, $counts, $queue_eligible );
		$this->render_fix_previews( $result );
		if ( ! empty( $result['findings'] ) ) {
			echo '<h3>' . esc_html__( 'All findings', 'equalfix' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Issue', 'equalfix' ) . '</th><th>' . esc_html__( 'Page', 'equalfix' ) . '</th><th>' . esc_html__( 'What happens', 'equalfix' ) . '</th></tr></thead><tbody>';
			foreach ( $result['findings'] as $finding ) {
				$cat = $finding['category'] ?? '';
				$why = '';
				if ( EqualFix_Audit::CAT_FIX_A === $cat ) {
					$why = __( 'Fixed for free at render time.', 'equalfix' );
				} elseif ( EqualFix_Audit::CAT_FIX_B === $cat ) {
					$why = __( 'Needs your words — Pro can draft them.', 'equalfix' );
				} else {
					$why = __( 'Needs a human review.', 'equalfix' );
				}
				echo '<tr><td>' . esc_html( $finding['title'] ?? '' ) . '</td><td>' . esc_html( $finding['url'] ?? '' ) . '</td><td>' . esc_html( $why ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
	private function render_journey( $result, $counts, $queue_eligible = 0 ) {
		$fix_a = (int) ( $counts['fix_a'] ?? 0 );
		$fix_b = (int) ( $counts['fix_b'] ?? 0 );
		$review = (int) ( $counts['review'] ?? 0 );
		$steps = array();
		$step = 1;
		if ( $fix_a > 0 ) {
			$steps[] = array( 'title' => sprintf( __( 'Step %d — Free fixes', 'equalfix' ), $step++ ), 'text' => sprintf( _n( '%d issue is fixed for free at render time.', '%d issues are fixed for free at render time.', $fix_a, 'equalfix' ), $fix_a ) );
		} else {
			$steps[] = array( 'title' => sprintf( __( 'Step %d — Free fixes', 'equalfix' ), $step++ ), 'text' => __( 'No issues in this audit are fixed for free, but free fixes still apply at render time site-wide whenever they are on.', 'equalfix' ) );
		}
		if ( $fix_b > 0 || $queue_eligible > 0 ) {
			$text = '';
			if ( $fix_b > 0 ) {
				$text .= sprintf( _n( '%d issue needs your words.', '%d issues need your words.', $fix_b, 'equalfix' ), $fix_b ) . ' ';
			}
			if ( $queue_eligible > 0 ) {
				$text .= sprintf( __( 'Pro drafts suggestions for %d of these.', 'equalfix' ), $queue_eligible );
			}
			$steps[] = array( 'title' => sprintf( __( 'Step %d — Pro drafts your words', 'equalfix' ), $step++ ), 'text' => trim( $text ), 'pro' => true );
		}
		if ( $review > 0 ) {
			$steps[] = array( 'title' => sprintf( __( 'Step %d — Need your words', 'equalfix' ), $step++ ), 'text' => sprintf( _n( '%d issue needs a human review.', '%d issues need a human review.', $review, 'equalfix' ), $review ) );
		}
		echo '<div class="equalfix-journey">';
		foreach ( $steps as $s ) {
			echo '<div class="equalfix-journey-card' . ( ! empty( $s['pro'] ) ? ' equalfix-journey-pro' : '' ) . '">';
			echo '<h4>' . esc_html( $s['title'] ) . '</h4>';
			echo '<p>' . esc_html( $s['text'] ) . '</p>';
			if ( ! empty( $s['pro'] ) ) {
				echo '<p><a class="button button-primary" href="https://equalfix.com/pro" target="_blank" rel="noopener">' . esc_html__( 'Get EqualFix Pro', 'equalfix' ) . '</a></p>';
			}
			echo '</div>';
		}
		if ( 0 === $fix_a && ! $this->all_free_fixes_on() ) {
			echo '<div class="equalfix-journey-card"><h4>' . esc_html__( 'Turn on free fixes', 'equalfix' ) . '</h4><p>' . esc_html__( 'Your free fixes are off. Turn them on to apply the free fix set to your rendered pages.', 'equalfix' ) . '</p>';
			echo '<form method="post" action="">';
			wp_nonce_field( 'equalfix_settings' );
			echo '<input type="hidden" name="equalfix_action" value="fix_all_free" />';
			submit_button( __( 'Turn on free fixes', 'equalfix' ), 'primary', 'submit', false );
			echo '</form></div>';
		} elseif ( 0 === $fix_a ) {
			echo '<p>' . esc_html__( 'Your free fixes are on.', 'equalfix' ) . '</p>';
		}
		echo '</div>';
	}

	private function render_journey_list() {
		// Kept for backward compatibility; render_journey() renders the journey cards.
	}

	private function journey_why( $category ) {
		if ( EqualFix_Audit::CAT_FIX_A === $category ) {
			return __( 'Fixed for free at render time.', 'equalfix' );
		}
		if ( EqualFix_Audit::CAT_FIX_B === $category ) {
			return __( 'Needs your words — Pro can draft them.', 'equalfix' );
		}
		return __( 'Needs a human review.', 'equalfix' );
	}
	private function render_fix_previews( $result ) {
		$previews = isset( $result['previews'] ) ? $result['previews'] : array();
		if ( empty( $previews ) ) {
			return;
		}
		echo '<h3>' . esc_html__( 'What the free fixes change', 'equalfix' ) . '</h3>';
		echo '<p>' . esc_html__( 'Before and after examples from your own pages, showing exactly what EqualFix changes at render time.', 'equalfix' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Before', 'equalfix' ) . '</th><th>' . esc_html__( 'After', 'equalfix' ) . '</th><th>' . esc_html__( 'Page', 'equalfix' ) . '</th></tr></thead><tbody>';
		foreach ( $previews as $preview ) {
			echo '<tr><td><code>' . esc_html( $preview['before'] ?? '' ) . '</code></td><td><code>' . esc_html( $preview['after'] ?? '' ) . '</code></td><td>' . esc_html( $preview['url'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function accumulate_previews( $page_findings, $html, &$state ) {
		if ( count( $state['previews'] ) >= 12 ) {
			return;
		}
		foreach ( $page_findings as $finding ) {
			if ( count( $state['previews'] ) >= 12 ) {
				break;
			}
			$fix_type = $this->finding_fix_type( $finding );
			if ( '' === $fix_type || in_array( $fix_type, $state['preview_tried'], true ) ) {
				continue;
			}
			$state['preview_tried'][] = $fix_type;
			$pair = $this->preview_pair_for( $finding, $html );
			if ( null !== $pair ) {
				$state['previews'][] = $pair;
			}
		}
	}

	private function preview_pair_for( $finding, $html ) {
		$pair = $this->extract_preview_pair( $finding, $html );
		if ( null === $pair ) {
			return null;
		}
		return array( 'before' => $pair[0], 'after' => $pair[1], 'url' => $finding['url'] ?? '' );
	}

	private function extract_preview_pair( $finding, $html ) {
		$fixer = new EqualFix_Fixer();
		$fixed = $fixer->fix_fragment( $html, $finding['url'] ?? '' );
		$before = $this->opening_tag( $finding, $html );
		if ( '' === $before ) {
			return null;
		}
		$after = $this->opening_tag( $finding, $fixed );
		if ( '' === $after || $before === $after ) {
			return null;
		}
		return array( $before, $after );
	}

	private function parse_html( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		return $dom;
	}

	private function opening_tag( $finding, $html ) {
		$needle = '';
		if ( ! empty( $finding['snippet'] ) ) {
			$needle = trim( (string) $finding['snippet'] );
		} elseif ( ! empty( $finding['element'] ) ) {
			$needle = trim( (string) $finding['element'] );
		}
		if ( '' !== $needle && false !== strpos( $html, $needle ) ) {
			return $needle;
		}
		// Fall back to the first tag of the finding's element type, if known.
		$tag = '';
		if ( ! empty( $finding['tag'] ) ) {
			$tag = strtolower( (string) $finding['tag'] );
		} elseif ( false !== strpos( (string) ( $finding['title'] ?? '' ), 'image' ) ) {
			$tag = 'img';
		} elseif ( false !== strpos( (string) ( $finding['title'] ?? '' ), 'link' ) || false !== strpos( (string) ( $finding['title'] ?? '' ), 'button' ) ) {
			$tag = 'a';
		}
		if ( '' !== $tag && preg_match( '/<' . $tag . '\b[^>]*>/i', $html, $m ) ) {
			return $m[0];
		}
		return '';
	}

	private function finding_fix_type( $finding ) {
		if ( ( $finding['category'] ?? '' ) !== EqualFix_Audit::CAT_FIX_A ) {
			return '';
		}
		$title = strtolower( (string) ( $finding['title'] ?? '' ) );
		if ( false !== strpos( $title, 'language' ) ) {
			return EqualFix_Fixer::FIX_LANG;
		}
		if ( false !== strpos( $title, 'alt' ) || false !== strpos( $title, 'image' ) ) {
			return EqualFix_Fixer::FIX_ALT;
		}
		if ( false !== strpos( $title, 'label' ) ) {
			return EqualFix_Fixer::FIX_LABEL_ASSOC;
		}
		if ( false !== strpos( $title, 'skip' ) ) {
			return EqualFix_Fixer::FIX_SKIP_LINK;
		}
		return EqualFix_Fixer::FIX_ARIA_LABEL;
	}

	private function render_purge() {
		$cache = new EqualFix_Cache();
		$log   = $cache->get_log();
		?>
		<h2><?php esc_html_e( 'Purge log', 'equalfix' ); ?></h2>
		<p><?php esc_html_e( 'Every time EqualFix purges a cache — automatically or manually — it is recorded here.', 'equalfix' ); ?></p>
		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="purge_now" />
			<?php submit_button( __( 'Purge caches now', 'equalfix' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No purges recorded yet.', 'equalfix' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'equalfix' ) . '</th><th>' . esc_html__( 'Reason', 'equalfix' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $entry ) {
			echo '<tr><td>' . esc_html( $entry['time'] ?? '' ) . '</td><td>' . esc_html( $entry['reason'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
