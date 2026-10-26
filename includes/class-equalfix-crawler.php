<?php
/**
 * EqualFix Accessibility — Page Discovery
 *
 * Discovers pages for the local audit from the sitemap, falling back to the
 * homepage, navigation menus, and recent content. Fetches rendered HTML with
 * wp_remote_get. Nothing here modifies the site.
 *
 * @package EqualFix_Accessibility
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EqualFix_Crawler {

	/**
	 * Maximum pages per audit run.
	 */
	const MAX_PAGES = 25;

	/**
	 * Discover page URLs to audit.
	 *
	 * @return array List of URLs.
	 */
	public function discover_urls() {
		$urls = $this->from_sitemap();
		if ( empty( $urls ) ) {
			$urls = $this->from_menus_and_content();
		}
		$urls = array_values( array_unique( $urls ) );
		return array_slice( $urls, 0, self::MAX_PAGES );
	}

	/**
	 * Read URLs from the sitemap.
	 *
	 * @return array
	 */
	private function from_sitemap() {
		$urls = array();
		foreach ( array( 'wp-sitemap.xml', 'sitemap.xml' ) as $file ) {
			$response = wp_remote_get(
				home_url( '/' . $file ),
				array( 'timeout' => 15 )
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = wp_remote_retrieve_body( $response );
			if ( preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $body, $m ) ) {
				foreach ( $m[1] as $loc ) {
					$loc = trim( $loc );
					// Sitemap index: only descend into page/post sitemaps, one level.
					if ( preg_match( '#sitemap.*\.xml$#i', $loc ) ) {
						$sub = wp_remote_get( $loc, array( 'timeout' => 15 ) );
						if ( ! is_wp_error( $sub ) && 200 === (int) wp_remote_retrieve_response_code( $sub ) ) {
							$sub_body = wp_remote_retrieve_body( $sub );
							if ( preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $sub_body, $m2 ) ) {
								foreach ( $m2[1] as $sub_loc ) {
									$urls[] = trim( $sub_loc );
								}
							}
						}
					} else {
						$urls[] = $loc;
					}
				}
			}
			if ( ! empty( $urls ) ) {
				break;
			}
		}
		return $this->same_host_only( $urls );
	}

	/**
	 * Fallback: homepage, menu items, recent posts/pages.
	 *
	 * @return array
	 */
	private function from_menus_and_content() {
		$urls = array( home_url( '/' ) );
		$menus = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! empty( $item->url ) ) {
					$urls[] = $item->url;
				}
			}
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			$urls[] = get_permalink( $post_id );
		}
		return $this->same_host_only( $urls );
	}

	/**
	 * Keep only URLs on this site's host.
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	private function same_host_only( $urls ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( $url ) );
			if ( '' === $url ) {
				continue;
			}
			$url_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $url_host || strtolower( $url_host ) === strtolower( $host ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/**
	 * Fetch a page's rendered HTML.
	 *
	 * @param string $url URL.
	 * @return string|false HTML on success, false on failure.
	 */
	public function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent' => 'EqualFix-Accessibility-Audit/1.0 (+local site audit)',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type && false === stripos( $content_type, 'text/html' ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $response );
	}
}
