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
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Add the menu page.
	 */
	public function menu() {
		add_options_page(
			__( 'EqualFix Accessibility', 'equalfix' ),
			__( 'EqualFix', 'equalfix' ),
			'manage_options',
			'equalfix-accessibility',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue admin CSS on our screen only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( 'settings_page_equalfix-accessibility' !== $hook ) {
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
		$settings = self::defaults();
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

		if ( $settings['auto_purge'] ) {
			$cache = new EqualFix_Cache();
			$cache->purge_all( __( 'Settings changed.', 'equalfix' ) );
		}
		$this->redirect( 'settings', __( 'Settings saved and caches purged.', 'equalfix' ) );
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
		$crawler = new EqualFix_Crawler();
		$audit   = new EqualFix_Audit();
		$urls    = $crawler->discover_urls();

		$pages    = array();
		$findings = array();
		foreach ( $urls as $url ) {
			$html = $crawler->fetch( $url );
			if ( false === $html ) {
				$pages[] = array(
					'url'      => $url,
					'status'   => 'fetch_failed',
					'findings' => 0,
				);
				continue;
			}
			$page_findings = $audit->audit_page( $html, $url );
			$pages[]       = array(
				'url'      => $url,
				'status'   => 'ok',
				'findings' => count( $page_findings ),
			);
			// Cap stored findings per run to keep the option small.
			foreach ( $page_findings as $finding ) {
				if ( count( $findings ) < 300 ) {
					$findings[] = $finding;
				}
			}
		}

		$previous = get_option( self::AUDIT_OPTION, null );
		$result   = array(
			'time'          => current_time( 'mysql' ),
			'pages_scanned' => count( $pages ),
			'pages'         => $pages,
			'findings'      => $findings,
			'total'         => count( $findings ),
		);
		if ( is_array( $previous ) && isset( $previous['total'] ) ) {
			$result['previous_total'] = (int) $previous['total'];
			$result['previous_time']  = $previous['time'];
		}
		update_option( self::AUDIT_OPTION, $result, false );
		$this->redirect( 'audit', sprintf(
			/* translators: %d: number of findings */
			__( 'Audit complete: %d findings across %d pages.', 'equalfix' ),
			count( $findings ),
			count( $pages )
		) );
	}

	/**
	 * Redirect back to the settings page with a notice.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $message Notice message.
	 */
	private function redirect( $tab, $message ) {
		$url = add_query_arg(
			array(
				'page'                => 'equalfix-accessibility',
				'tab'                 => $tab,
				'equalfix_notice'  => rawurlencode( $message ),
			),
			admin_url( 'options-general.php' )
		);
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
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
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
						<span class="equalfix-status equalfix-status-off"><span class="equalfix-dot" aria-hidden="true"></span><?php esc_html_e( 'Fixes paused', 'equalfix' ); ?></span>
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
		return add_query_arg(
			array( 'page' => 'equalfix-accessibility', 'tab' => $tab ),
			admin_url( 'options-general.php' )
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
									<?php esc_html_e( 'Enabled', 'equalfix' ); ?>
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
						<p class="description" id="equalfix_excluded_urls_desc"><?php esc_html_e( 'One URL path per line, e.g. /checkout/ or /my-custom-page/. Fixes are skipped on matching pages.', 'equalfix' ); ?></p>
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
			<h2><?php esc_html_e( 'Cache behaviour', 'equalfix' ); ?></h2>
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
		?>
		<h2><?php esc_html_e( 'Site audit', 'equalfix' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Scans your site’s public pages for specific accessibility issues. Each finding shows its severity, WCAG reference, the exact HTML, and whether the free plugin can fix it automatically. Before/after previews show exactly what each fix type changes.', 'equalfix' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'equalfix_settings' ); ?>
			<input type="hidden" name="equalfix_action" value="run_audit">
			<?php submit_button( __( 'Run audit', 'equalfix' ), 'primary', 'submit', false ); ?>
		</form>

		<?php
		if ( ! is_array( $result ) ) {
			echo '<p>' . esc_html__( 'No audit has been run yet.', 'equalfix' ) . '</p>';
			return;
		}
		?>
		<h3><?php esc_html_e( 'Latest audit', 'equalfix' ); ?></h3>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: pages, 3: findings */
					__( '%1$s — %2$d pages scanned, %3$d findings.', 'equalfix' ),
					$result['time'],
					$result['pages_scanned'],
					$result['total']
				)
			);
			if ( isset( $result['previous_total'] ) ) {
				$delta = $result['total'] - $result['previous_total'];
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: change, 2: date */
						__( 'Change since %2$s: %+d findings.', 'equalfix' ),
						$delta,
						$result['previous_time']
					)
				);
			}
			?>
		</p>

		<?php
		$counts = array( 'fix-a' => 0, 'fix-b' => 0, 'human' => 0 );
		foreach ( (array) $result['findings'] as $finding ) {
			if ( isset( $counts[ $finding['category'] ] ) ) {
				$counts[ $finding['category'] ]++;
			} else {
				$counts['human']++;
			}
		}
		?>
		<div class="equalfix-stats">
			<div class="equalfix-stat equalfix-stat-free">
				<span class="equalfix-stat-num"><?php echo esc_html( $counts['fix-a'] ); ?></span>
				<span class="equalfix-stat-label"><?php esc_html_e( 'Can be fixed free', 'equalfix' ); ?></span>
			</div>
			<div class="equalfix-stat equalfix-stat-pro">
				<span class="equalfix-stat-num"><?php echo esc_html( $counts['fix-b'] ); ?></span>
				<span class="equalfix-stat-label"><?php esc_html_e( 'The Pro add-on can fix', 'equalfix' ); ?></span>
				<?php if ( $counts['fix-b'] > 0 ) : ?>
					<a href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" class="button button-primary equalfix-stat-cta" target="_blank" rel="noopener"><?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of Pro-fixable findings */
							_n( 'Fix this finding with Pro — $39/mo', 'Fix these %d findings with Pro — $39/mo', $counts['fix-b'], 'equalfix' ),
							$counts['fix-b']
						)
					);
					?></a>
				<?php endif; ?>
			</div>
			<div class="equalfix-stat equalfix-stat-human">
				<span class="equalfix-stat-num"><?php echo esc_html( $counts['human'] ); ?></span>
				<span class="equalfix-stat-label"><?php esc_html_e( 'Need a human decision', 'equalfix' ); ?></span>
			</div>
		</div>

		<?php $this->render_fix_previews(); ?>

		<h3><?php esc_html_e( 'Findings', 'equalfix' ); ?></h3>
		<?php if ( empty( $result['findings'] ) ) : ?>
			<p><?php esc_html_e( 'No issues found by the automated checks. Note: automated checks cannot catch everything — some issues still need human review.', 'equalfix' ); ?></p>
		<?php else : ?>
			<table class="widefat striped equalfix-findings">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Severity', 'equalfix' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Issue', 'equalfix' ); ?></th>
						<th scope="col"><?php esc_html_e( 'WCAG', 'equalfix' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Page', 'equalfix' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'equalfix' ); ?></th>
						<th scope="col"><?php esc_html_e( 'HTML', 'equalfix' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['findings'] as $finding ) : ?>
						<tr>
							<td><span class="equalfix-pill equalfix-sev-<?php echo esc_attr( sanitize_html_class( strtolower( $finding['severity'] ) ) ); ?>"><?php echo esc_html( ucfirst( $finding['severity'] ) ); ?></span></td>
							<td>
								<strong><?php echo esc_html( $finding['title'] ); ?></strong><br>
								<span class="description"><?php echo esc_html( $finding['explanation'] ); ?></span>
							</td>
							<td><?php echo esc_html( $finding['wcag'] ); ?></td>
							<td><a href="<?php echo esc_url( $finding['url'] ); ?>"><?php echo esc_html( wp_parse_url( $finding['url'], PHP_URL_PATH ) ); ?></a></td>
							<td><span class="equalfix-pill equalfix-cat-<?php echo esc_attr( sanitize_html_class( $finding['category'] ) ); ?>"><?php echo esc_html( EqualFix_Audit::category_label( $finding['category'] ) ); ?></span></td>
							<td><code><?php echo esc_html( $finding['snippet'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="equalfix-report-note">
			<p><?php esc_html_e( 'Need this as a dated document? Pro generates a remediation report (PDF) of every scan, fix, and approval — the proof-of-effort record for your files.', 'equalfix' ); ?> <a href="<?php echo esc_url( apply_filters( 'equalfix_pro_url', 'https://equalfix.com/pricing' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro — $39/mo', 'equalfix' ); ?></a></p>
		</div>
		<?php
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
		$fixer    = new EqualFix_Fixer(
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
			$before = $finding['snippet'];
			$after  = $fixer->fix_fragment( $before );
			?>
			<div class="equalfix-preview">
				<h4><?php echo esc_html( $labels[ $type ] ); ?></h4>
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
		if ( false !== stripos( $title, 'skip-to-content' ) ) {
			return EqualFix_Fixer::FIX_SKIP_LINK;
		}
		return null;
	}

	/**
	 * Render the purge log tab.
	 */
	private function render_purge() {
		?>
		<h2><?php esc_html_e( 'Cache purge log', 'equalfix' ); ?></h2>
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
