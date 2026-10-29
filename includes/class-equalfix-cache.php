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

		// WP-Optimize — public API (the internal commands class is
		// a CLI implementation detail and can move between releases).
		if ( class_exists( 'WP_Optimize' ) && function_exists( 'WP_Optimize' ) ) {
			$wpo = WP_Optimize();
			if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
				$page_cache = $wpo->get_page_cache();
				if ( is_object( $page_cache ) && method_exists( $page_cache, 'purge' ) ) {
					$page_cache->purge();
					$actions[] = __( 'WP-Optimize cache purged.', 'equalfix' );
				}
			}
		}

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$actions[] = __( 'SG Optimizer cache purged.', 'equalfix' );
		}

		// (No generic wp_cache_flush() here: it flushes EVERY plugin's
		// object cache, which on Redis/Memcached hosts turns each purge
		// into a self-inflicted cache stampede. The targeted
		// integrations above are the supported host signals.)

		$this->log( $reason, $actions );
		return $actions;
	}

	/**
	 * Clear the plugin's own output-cache transients.
	 *
	 * Two passes, because the old SQL-only sweep silently cleared
	 * nothing on object-cache hosts (Redis/Memcached), where
	 * transients never touch wp_options:
	 *  1. The key registry — every key the output cache recorded
	 *     when it was set. delete_transient() goes through the
	 *     object cache, so this pass works on every host type.
	 *  2. A database sweep as backstop (keys written before the
	 *     registry existed), which also removes orphaned
	 *     _transient_timeout_ rows the first pass cannot see.
	 *
	 * @return int Number of entries cleared.
	 */
	public function clear_own_cache() {
		$count = 0;
		$keys  = get_option( 'equalfix_out_keys', array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				if ( delete_transient( $key ) ) {
					$count++;
				}
			}
		}
		delete_option( 'equalfix_out_keys' );

		global $wpdb;
		$like  = $wpdb->esc_like( '_transient_equalfix_out_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $names as $name ) {
			$key = substr( $name, strlen( '_transient_' ) );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}
		// Orphaned timeout rows (their value rows are gone) can only
		// be removed directly.
		$like_timeout = $wpdb->esc_like( '_transient_timeout_equalfix_out_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $count;
	}

	/**
	 * Record an output-cache key in the registry clear_own_cache()
	 * purges by. Capped so a high-traffic site cannot grow the
	 * registry without bound (the oldest keys fall off; their
	 * transients still expire on their own TTL).
	 *
	 * @param string $key Transient key (without prefix).
	 */
	public static function register_key( $key ) {
		$keys = get_option( 'equalfix_out_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( in_array( $key, $keys, true ) ) {
			return;
		}
		$keys[] = $key;
		if ( count( $keys ) > 500 ) {
			$keys = array_slice( $keys, -500 );
		}
		update_option( 'equalfix_out_keys', $keys, false );
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
