<?php
/**
 * EqualFix Accessibility — Uninstall cleanup
 *
 * Delete means delete (owner decision, DH-1, 2026-11-07 —
 * supersedes the Stage 5 opt-in design): deleting the plugin
 * removes ALL EqualFix data unconditionally, and a reinstall
 * starts brand new. Because the free plugin is the base of
 * the product, deleting it also removes the Pro add-on's
 * data — the whole product goes. Deactivation alone needs
 * no cleanup and keeps everything: fixes are render-time
 * only and stop the moment the plugin is inactive — nothing
 * is written to theme files or post content. Version UPDATES
 * never run this file (WordPress runs uninstall.php only on
 * delete), so an update keeps all state untouched.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The statement draft page goes with its record (T1-13):
// purging the record but leaving the page orphaned it, and
// the next Generate — seeing no record — created a second
// draft page beside the orphan.
$equalfix_statement = get_option( 'equalfix_statement', array() );
if ( is_array( $equalfix_statement ) && ! empty( $equalfix_statement['page_id'] ) ) {
	wp_delete_post( (int) $equalfix_statement['page_id'], true );
}

$options = array(
	'equalfix_settings',
	'equalfix_audit_results',
	'equalfix_audit_history',
	'equalfix_dismissed_findings',
	'equalfix_db_version',
	'equalfix_version_seen_at',
	'equalfix_out_keys',
	'equalfix_purge_log',
	// The legacy AI-preview key and preview state (the free
	// preview itself was removed in DH-4): stored rows from
	// older installs are data like everything else and go
	// with the rest.
	'equalfix_preview_key',
	'equalfix_ai_preview',
	// Owner-written fix rules + the statement record.
	'equalfix_manual_alt_rules',
	'equalfix_manual_name_rules',
	'equalfix_manual_label_rules',
	'equalfix_manual_title_rules',
	// Owner-approved attribute rules (Fix-First, 2026-11-07):
	// the fifth rule store, in the same delete contract.
	'equalfix_manual_attr_rules',
	// Waves 3+4 stores (Fix-First, 2026-11-07): the
	// owner-supplied insert rules, the approved page titles
	// and the headings-full approvals — same contract.
	'equalfix_manual_insert_rules',
	'equalfix_manual_pagetitle_rules',
	'equalfix_headings_full_pages',
	'equalfix_statement',
	// Stage 5 one-time auto-update flags (Stage 6): a fresh
	// reinstall must re-apply the default and re-show the
	// notice.
	'equalfix_autoupdate_defaulted',
	'equalfix_autoupdate_notice',
	// The pair's shared showing claim (loop round 2, S-1):
	// owned by whichever plugin's render won it, retired by
	// both plugins' purges.
	'equalfix_autoupdate_notice_claim',
	'equalfix_pro_autoupdate_notice',
	// The Pro add-on's data (DH-1): deleting the base plugin
	// removes the whole product. This list mirrors Pro's own
	// uninstall list exactly.
	'equalfix_pro_settings',
	'equalfix_pro_licence',
	'equalfix_pro_api_base',
	'equalfix_pro_queue',
	'equalfix_pro_alt_rules',
	'equalfix_pro_name_rules',
	'equalfix_pro_label_rules',
	'equalfix_pro_rejected_keys',
	'equalfix_pro_inbox',
	'equalfix_pro_monitor_snapshot',
	'equalfix_pro_monitor_history',
	'equalfix_pro_monitor_progress',
	'equalfix_pro_monitor_claim',
	'equalfix_pro_db_version',
	'equalfix_pro_skip_records',
	'equalfix_pro_last_generation',
	'equalfix_pro_autoupdate_defaulted',
	'equalfix_pro_autoupdate_notice',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// The audit single-run lock (loop round 3, S3-3) and Pro's
// monitor lock are TRANSIENTS, so the option list above
// cannot reach them — and a name sweep is not the contract:
// they are deleted by name (round 5, R5-03).
delete_transient( 'equalfix_audit_lock' );
delete_transient( 'equalfix_pro_monitor_lock' );
delete_transient( 'equalfix_pro_update_check' );
delete_site_transient( 'equalfix_pro_update_check' );

// Pro's scheduled jobs go too: an absent product must not
// leave jobs behind.
if ( function_exists( 'wp_unschedule_hook' ) ) {
	wp_unschedule_hook( 'equalfix_pro_licence_check' );
	wp_unschedule_hook( 'equalfix_pro_monitor_run' );
	wp_unschedule_hook( 'equalfix_pro_monitor_continue' );
}

// The purge also retires BOTH plugins' rows in core's
// auto-update list (loop round 1, suspicion S-4, confirmed):
// core never prunes the list on deletion, and the defaulting
// routine treats "already listed" as "already decided", so a
// leftover row would leave a fresh reinstall half-armed. The
// free basename is derived from this file's location (the
// release folder is 'equalfix', the main file
// 'equalfix-accessibility.php') and needs no constant from
// the main file, which is not loaded when an inactive plugin
// is deleted; Pro's basename rides the same convention (its
// folder is this folder's sibling in every supported layout
// — the one-click installer and the shipped zips both place
// it there).
$equalfix_auto = get_site_option( 'auto_update_plugins', array() );
if ( is_array( $equalfix_auto ) ) {
	$equalfix_basenames = array(
		basename( __DIR__ ) . '/equalfix-accessibility.php',
		'equalfix-pro/equalfix-pro.php',
	);
	$equalfix_kept = array_values( array_diff( $equalfix_auto, $equalfix_basenames ) );
	if ( count( $equalfix_kept ) !== count( $equalfix_auto ) ) {
		update_site_option( 'auto_update_plugins', $equalfix_kept );
	}
}

// One-time handover state — ephemeral, always cleared: the
// first-run redirect transient, the one-click installer's
// short-lived licence-key handover transient (1.2), and the
// legacy preview candidate transient.
delete_transient( 'equalfix_first_run' );
delete_transient( 'equalfix_pro_pending_key' );
delete_transient( 'equalfix_preview_candidate' );

// Remove the plugin's own output-cache transients (caches,
// not data — always cleared).
global $wpdb;
$like  = $wpdb->esc_like( '_transient_equalfix_out_' ) . '%';
$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $names as $name ) {
	delete_option( $name );
	// Also clear the timeout row.
	delete_option( str_replace( '_transient_', '_transient_timeout_', $name ) );
}
