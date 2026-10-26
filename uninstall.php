<?php
/**
 * EqualFix Accessibility — Uninstall cleanup
 *
 * Removes the plugin's own options and transients. Deactivation alone needs
 * no cleanup: fixes are render-time only and stop the moment the plugin is
 * inactive — nothing is written to theme files or post content.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'equalfix_settings',
	'equalfix_audit_results',
	'equalfix_purge_log',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove the plugin's own output-cache transients.
global $wpdb;
$like  = $wpdb->esc_like( '_transient_equalfix_out_' ) . '%';
$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $names as $name ) {
	delete_option( $name );
	// Also clear the timeout row.
	delete_option( str_replace( '_transient_', '_transient_timeout_', $name ) );
}
