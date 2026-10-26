<?php
/**
 * EqualFix Accessibility — Cache Purging
 *
 * Purges page caches when fix settings or rules change, so visitors receive
 * the fixed HTML instead of stale cached output. Integrates with known
 * caching plugins where an integration exists, and clears the plugin's own
 * output cache. Every purge is logged for diagnosability.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Cache {

	/**
	 * Purge all known caches and log what was purged.
	 *
	 * @param string $reason Why the purge ran (for the log).
	 * @return array List of purge actions performed.
	 */
	public function purge_all( $reason = '' ) {
		$actions = array();

		// The plugin's own output cache.
		$cleared = $this->clear_own_cache();
		$actions[] = sprintf(
			/* translators: %d: number of cache entries cleared */
			__( 'Own output cache cleared (%d entries).', 'equalfix' ),
			$cleared
		);

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$actions[] = __( 'WP Super Cache cleared.', 'equalfix' );
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$actions[] = __( 'W3 Total Cache flushed.', 'equalfix' );
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$actions[] = __( 'WP Rocket domain cache cleaned.', 'equalfix' );
		}

		// LiteSpeed Cache.
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$actions[] = __( 'LiteSpeed Cache purged.', 'equalfix' );
		}

		// WP-Optimize.
		if ( class_exists( 'WP_Optimize_Cache_Commands' ) ) {
			$commands = new WP_Optimize_Cache_Commands();
			if ( method_exists( $commands, 'purge_all' ) ) {
				$commands->purge_all();
				$actions[] = __( 'WP-Optimize cache purged.', 'equalfix' );
			}
		}

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$actions[] = __( 'SG Optimizer cache purged.', 'equalfix' );
		}

		// Generic object-cache flush signal for hosts (conservative: only page-related groups).
		wp_cache_flush();

		$this->log( $reason, $actions );
		return $actions;
	}

	/**
	 * Clear the plugin's own output-cache transients.
	 *
	 * @return int Number of entries cleared.
	 */
	public function clear_own_cache() {
		global $wpdb;
		$like  = $wpdb->esc_like( '_transient_equalfix_out_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = 0;
		foreach ( $names as $name ) {
			$key = substr( $name, strlen( '_transient_' ) );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Build the output-cache key for a URL + settings hash.
	 *
	 * @param string $url           URL.
	 * @param string $settings_hash Hash of the active fix settings.
	 * @return string Transient key (without the _transient_ prefix).
	 */
	public static function cache_key( $url, $settings_hash ) {
		return 'equalfix_out_' . md5( $url . '|' . $settings_hash );
	}

	/**
	 * Log a purge run.
	 *
	 * @param string $reason  Reason.
	 * @param array  $actions Actions performed.
	 */
	private function log( $reason, $actions ) {
		$log   = get_option( 'equalfix_purge_log', array() );
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'reason'  => sanitize_text_field( $reason ),
			'actions' => array_map( 'sanitize_text_field', $actions ),
		);
		// Keep the last 50 entries.
		$log = array_slice( $log, -50 );
		update_option( 'equalfix_purge_log', $log, false );
	}

	/**
	 * Get the purge log, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( 'equalfix_purge_log', array() );
		return array_reverse( $log );
	}
}
