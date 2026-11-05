<?php
/**
 * EqualFix Accessibility — Uninstall cleanup
 *
 * Data preservation (Stage 5 fix, matching Pro): deleting the
 * plugin files must NOT destroy the site's data. Audits, audit
 * history, dismissals, owner-written fix rules, settings, and the
 * statement record survive a delete + reinstall by default; full
 * removal happens only when the owner has opted in via the
 * "Remove data on uninstall" setting (off by default).
 * Deactivation alone needs no cleanup: fixes are render-time
 * only and stop the moment the plugin is inactive — nothing is
 * written to theme files or post content.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$equalfix_settings = get_option( 'equalfix_settings', array() );
$equalfix_purge    = is_array( $equalfix_settings ) && ! empty( $equalfix_settings['remove_data_on_uninstall'] );

if ( $equalfix_purge ) {
	$options = array(
		'equalfix_settings',
		'equalfix_audit_results',
		'equalfix_audit_history',
		'equalfix_dismissed_findings',
		'equalfix_db_version',
		'equalfix_out_keys',
		'equalfix_purge_log',
		// Next batch: owner-written fix rules + the statement record.
		'equalfix_manual_alt_rules',
		'equalfix_manual_name_rules',
		'equalfix_manual_label_rules',
		'equalfix_manual_title_rules',
		'equalfix_statement',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

// One-time handover state — ephemeral, always cleared: the
// first-run redirect transient and the one-click installer's
// short-lived licence-key handover transient (1.2).
delete_transient( 'equalfix_first_run' );
delete_transient( 'equalfix_pro_pending_key' );
delete_option( 'equalfix_preview_key' );
delete_option( 'equalfix_ai_preview' );
delete_transient( 'equalfix_preview_candidate' );

// Remove the plugin's own output-cache transients (caches, not
// data — always cleared).
global $wpdb;
$like  = $wpdb->esc_like( '_transient_equalfix_out_' ) . '%';
$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $names as $name ) {
	delete_option( $name );
	// Also clear the timeout row.
	delete_option( str_replace( '_transient_', '_transient_timeout_', $name ) );
}
