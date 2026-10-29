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
	 * Why the most recent fetch() failed ('' when it succeeded).
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * How the most recent fetch() obtained its page: 'http' for a
	 * normal HTTP fetch, 'render' for an internal render, '' when
	 * the fetch failed.
	 *
	 * @var string
	 */
	private $last_mode = '';

	/**
	 * Why the most recent fetch() failed, as a machine-readable
	 * kind: 'deferred' when the page could not be fetched over
	 * HTTP and its internal render was postponed because this
	 * request already rendered a page (the caller can retry it in
	 * a later request — it is NOT a failure), 'failed' when both
	 * routes were tried and neither produced the page, '' when the
	 * fetch succeeded.
	 *
	 * @var string
	 */
	private $last_failure = '';

	/**
	 * Whether this request has already rendered a page internally.
	 * Theme template parts (header, footer) load with
	 * require_once, so one PHP process can faithfully render
	 * exactly one page; a second render would silently lose them.
	 * The guard makes that limit explicit instead of subtle.
	 *
	 * @var bool
	 */
	private static $render_used = false;

	/**
	 * Whether a render is executing right now. If page code ends
	 * the request mid-render (an exit no try/catch can intercept),
	 * a shutdown handler can use this to tell a render death from
	 * a normal request end and finish the bookkeeping.
	 *
	 * @var bool
	 */
	private static $render_in_progress = false;

	/**
	 * Whether an internal render is executing at this moment.
	 *
	 * @return bool
	 */
	public static function render_in_progress() {
		return self::$render_in_progress;
	}

	/**
	 * The failure recorded by the most recent fetch() call: a
	 * transport error message or an HTTP status line. Empty when
	 * the last fetch succeeded. The audit stores the first failure
	 * of a run so the results screen can say exactly what went
	 * wrong instead of guessing.
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

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
		// The homepage is the page that matters most: never let
		// archive ordering push it out of the audit set — and if
		// discovery did not surface it at all (a sitemap that omits
		// it), add it explicitly (WP7 Lovable-review find).
		$home  = home_url( '/' );
		$found = false;
		foreach ( $urls as $i => $u ) {
			if ( $u === $home || untrailingslashit( $u ) === untrailingslashit( $home ) ) {
				unset( $urls[ $i ] );
				array_unshift( $urls, $u );
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			array_unshift( $urls, $home );
		}
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
					// <loc> values are XML text: entities in them are
					// markup, not URL characters — decode, or a
					// multi-parameter URL is fetched with a parameter
					// literally named "amp;...".
					$loc = html_entity_decode( trim( $loc ), ENT_QUOTES, 'UTF-8' );
					// Sitemap index: descend one level, into CONTENT
					// sitemaps only (skip taxonomy/author indexes — the
					// audit set belongs to real pages), and only into
					// sitemaps on this site's own host (checked BEFORE
					// fetching, not after).
					if ( preg_match( '#sitemap.*\.xml$#i', $loc ) && ! preg_match( '#(taxonom|users|author)#i', $loc ) && $this->same_host( $loc ) ) {
						$sub = wp_remote_get( $loc, array( 'timeout' => 15 ) );
						if ( ! is_wp_error( $sub ) && 200 === (int) wp_remote_retrieve_response_code( $sub ) ) {
							$sub_body = wp_remote_retrieve_body( $sub );
							if ( preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $sub_body, $m2 ) ) {
								foreach ( $m2[1] as $sub_loc ) {
									$urls[] = html_entity_decode( trim( $sub_loc ), ENT_QUOTES, 'UTF-8' );
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
	/**
	 * Whether a URL belongs to this site's host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function same_host( $url ) {
		$want = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$got  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $want && $want === $got;
	}

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
	 * How the most recent fetch() obtained its page: 'http',
	 * 'render', or '' when it failed.
	 *
	 * @return string
	 */
	public function last_fetch_mode() {
		return $this->last_mode;
	}

	/**
	 * The machine-readable failure kind of the most recent
	 * fetch(): 'deferred', 'failed', or '' on success. See the
	 * $last_failure property for the exact meanings.
	 *
	 * @return string
	 */
	public function last_failure_kind() {
		return $this->last_failure;
	}

	/**
	 * Fetch a page's rendered HTML.
	 *
	 * The HTTP fetch is always tried first. When it fails — the
	 * signature of a host that blocks loopback requests, such as
	 * WordPress Playground or some budget hosts — the page is
	 * rendered inside WordPress instead (see render_internally),
	 * so the audit still measures the real page rather than
	 * reporting a failed scan.
	 *
	 * @param string $url URL.
	 * @return string|false HTML on success, false on failure.
	 */
	public function fetch( $url ) {
		$this->last_mode    = '';
		$this->last_failure = '';
		$guard_error        = '';
		$html               = $this->fetch_http( $url );
		if ( false !== $html ) {
			// 1.0 guard layer: a 200 response is not proof the
			// document is the requested page (soft-404s, login
			// walls, wrong-document rewrites). Verify before the
			// audit may analyse it; on rejection, try the render
			// fallback before failing the page.
			$verdict = EqualFix_Guard::verify( $html, $url, 'http' );
			if ( $verdict['ok'] ) {
				$this->last_mode = 'http';
				return $html;
			}
			$guard_error = $verdict['message'];
			$html        = false;
		}
		$http_error = $this->last_error;
		$html       = $this->render_internally( $url );
		if ( false !== $html ) {
			$verdict = EqualFix_Guard::verify( $html, $url, 'render' );
			if ( $verdict['ok'] ) {
				$this->last_mode    = 'render';
				$this->last_failure = '';
				$this->last_error   = '';
				return $html;
			}
			// The render produced a document, but not verifiably
			// this page — the page fails honestly, with the reason.
			$this->last_failure = 'guard';
			$this->last_error   = $verdict['message'];
			return false;
		}
		// Both paths failed. A 'deferred' render outranks every
		// other failure (the runner continues it in a later
		// request); a guard rejection outranks the transport
		// message (it says what was actually wrong); otherwise the
		// HTTP failure stays the diagnosable one.
		if ( 'deferred' === $this->last_failure ) {
			$this->last_error = $http_error;
			return false;
		}
		if ( '' !== $guard_error ) {
			$this->last_failure = 'guard';
			$this->last_error   = $guard_error;
			return false;
		}
		$this->last_error = $http_error;
		if ( '' === $this->last_failure ) {
			$this->last_failure = 'failed';
		}
		return false;
	}

	/**
	 * Fetch a page over HTTP.
	 *
	 * @param string $url URL.
	 * @return string|false HTML on success, false on failure.
	 */
	private function fetch_http( $url ) {
		$this->last_error = '';
		$response         = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent' => 'EqualFix-Accessibility-Audit/1.0 (+local site audit)',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->last_error = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
			return false;
		}
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type && false === stripos( $content_type, 'text/html' ) ) {
			$this->last_error = 'Unexpected content type: ' . $content_type;
			return false;
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Render a page inside WordPress, without any HTTP request.
	 *
	 * The page's query is run in-process and the theme template
	 * is included under an output buffer, producing the same
	 * HTML a logged-out visitor would receive (the current user
	 * is swapped to logged-out for the render, and the fixers
	 * stand down in admin/cron contexts and for the audit
	 * user-agent, so the audit measures the page's original
	 * HTML — the same contract as the HTTP fetch).
	 *
	 * Limits, by design:
	 * - One render per request (see $render_used). A second
	 *   call reports 'deferred' so the caller can continue in a
	 *   later request instead of accepting a degraded render.
	 * - External page caches and CDNs are not reflected: the
	 *   HTML comes straight from WordPress.
	 * - A page that would 404 (or a feed/robots response) is a
	 *   failure here, matching the HTTP status the crawl would
	 *   have received.
	 * - 'template_redirect' handlers do not run for the render
	 *   (the hook is emptied and restored around it). Several
	 *   plugins answer that hook with a redirect followed by
	 *   exit — WooCommerce sending an empty-cart visitor away
	 *   from checkout is the common case — which would end this
	 *   request mid-render. The trade: a page that redirects
	 *   its visitors elsewhere is audited as its own template
	 *   rather than as the redirect destination.
	 *
	 * All global state the render touches is restored before
	 * returning, on every path.
	 *
	 * @param string $url Same-host page URL.
	 * @return string|false Rendered HTML, or false.
	 */
	private function render_internally( $url ) {
		if ( self::$render_used ) {
			$this->last_failure = 'deferred';
			return false;
		}
		if ( ! $this->same_host( $url ) ) {
			$this->last_failure = 'failed';
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			$this->last_failure = 'failed';
			return false;
		}
		if ( ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			// Bare-domain URLs name the homepage (WP0 line-review
			// find: 0.5.0 bailed here instead of rendering '/').
			$parts['path'] = '/';
		}
		$get = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $get );
		}
		$request_uri = $parts['path'] . ( ! empty( $parts['query'] ) ? '?' . $parts['query'] : '' );

		self::$render_used  = true;
		$this->last_failure = 'failed';

		// Snapshot everything the render is about to move. The
		// globals are declared so the theme template, included
		// below from this scope, sees the same variables it
		// would see in a normal front-end request.
		global $wp, $wp_query, $wp_the_query, $post, $posts, $wp_rewrite, $wpdb, $wp_styles, $wp_scripts, $id, $comment, $user_ID, $authordata, $wp_filter;
		$saved = array(
			'wp'           => clone $wp,
			'wp_query'     => $wp_query,
			'wp_the_query' => $wp_the_query,
			'post'         => $post,
			'posts'        => $posts,
			'wp_styles'    => isset( $wp_styles ) && is_object( $wp_styles ) ? clone $wp_styles : $wp_styles,
			'wp_scripts'   => isset( $wp_scripts ) && is_object( $wp_scripts ) ? clone $wp_scripts : $wp_scripts,
			'get'          => $_GET,
			'request'      => $_REQUEST,
			'post_data'    => $_POST,
			'cookie'       => $_COOKIE,
			'php_self'     => isset( $_SERVER['PHP_SELF'] ) ? $_SERVER['PHP_SELF'] : null,
			'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null,
			'uri'          => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null,
			'ua'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null,
			'user'         => get_current_user_id(),
			'admin_bar'    => isset( $GLOBALS['show_admin_bar'] ) ? $GLOBALS['show_admin_bar'] : null,
			'redirect_hook' => isset( $wp_filter['template_redirect'] ) ? clone $wp_filter['template_redirect'] : null,
		);

		$html = false;
		try {
			// A logged-out view: the HTTP crawl sends no cookies,
			// so this is the same page a visitor (and the crawl)
			// gets — no admin bar, no member-only chrome.
			wp_set_current_user( 0 );
			$GLOBALS['show_admin_bar'] = false;
			// The audit's own bypass signal, mirroring the HTTP
			// fetch's user-agent, so fix engines stand down.
			$_SERVER['HTTP_USER_AGENT'] = 'EqualFix-Accessibility-Audit/1.0 (+local site audit; internal render)';
			$_GET                       = $get;
			$_REQUEST                   = $get;
			// De-authentication, completed (Lovable Mod 3): a
			// front-end GET render must not see the ambient
			// request's POST body or method — in an admin-launched
			// audit that POST data is the admin's, and templates or
			// plugins branching on it render a different page.
			$_POST                      = array();
			// De-authentication completed (WP7 Lovable-review
			// find): the ambient request's cookies are the admin's
			// (wordpress_logged_in_*, WooCommerce session/cart) —
			// templates and plugins branching on $_COOKIE must see
			// the logged-out visitor's empty cookie set, matching
			// the cookie-less HTTP fetch.
			$_COOKIE                    = array();
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['REQUEST_URI']     = $request_uri;
			// Present as the front-end entry point, not as the
			// admin request that launched the audit: WP core's
			// parse_request() deliberately DISCARDS matched
			// permalink query vars whenever PHP_SELF contains
			// 'wp-admin/' (proven in Playground, where the
			// simulation resolved the right rule but an empty
			// query — the 1.0.0 render failure). A front-end
			// PHP_SELF is also the truthful value for a render
			// that impersonates a visitor request.
			$home_path                  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$_SERVER['PHP_SELF']        = ( is_string( $home_path ) ? rtrim( $home_path, '/' ) : '' ) . '/index.php';
			// No 'template_redirect' handlers run for the render:
			// canonical redirects would end this request (the
			// crawl follows them over HTTP instead, and discovery
			// URLs are already canonical), and plugin handlers
			// that answer with redirect-and-exit would kill it
			// outright. The hook is emptied here and restored by
			// restore_render_state().
			$wp_filter['template_redirect'] = new WP_Hook();

			// The template loader renders nothing unless the
			// front-end entry point defined WP_USE_THEMES — admin
			// and cron requests never define it, so define it for
			// the render (it only gates template loading).
			if ( ! defined( 'WP_USE_THEMES' ) ) {
				define( 'WP_USE_THEMES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			}

			// Start from clean front-end asset queues. The
			// ambient admin request has already filled
			// $wp_styles/$wp_scripts with wp-admin assets; if
			// the template prints those queues as-is, the
			// capture carries /wp-admin/js/ URLs and the Guard
			// rightly rejects the document as an admin page —
			// the second half of the 1.0.0 Playground failure
			// (instrumented: correct page, correct content,
			// guard verdict admin_document until the queues
			// were replaced; visitor requests start with fresh
			// queues, and theme/plugin front-end enqueues fire
			// again inside the template's wp_head()).
			if ( class_exists( 'WP_Styles' ) ) {
				$wp_styles = new WP_Styles();
			}
			if ( class_exists( 'WP_Scripts' ) ) {
				$wp_scripts = new WP_Scripts();
			}

			// Resolve the target query. Two routes, in order:
			//
			// 1. QUERY-FIRST for singular targets (the audit's
			//    overwhelming majority: posts, pages, products
			//    from the sitemap/menus). We already know exactly
			//    which object the URL names, so build its query
			//    directly instead of simulating a request. This
			//    is immune to the whole request-simulation
			//    failure class — rewrite matching, scoped URL
			//    prefixes, and WP core's admin-context discard
			//    of matched permalink vars (parse_request()
			//    unsets them when PHP_SELF contains 'wp-admin/',
			//    which emptied the query on every Playground
			//    render in 1.0.0 — instrumented proof: matched
			//    rule, query_vars=[], no queried object).
			// 2. REQUEST SIMULATION for everything else
			//    (archives, search, the blog index), now with a
			//    front-end PHP_SELF so route 2's vars survive.
			//
			// WP2 guard — verified query identity: whichever
			// route ran, when the URL names a specific post the
			// resolved query must be THAT post, or the render
			// fails here; the capture guard in fetch() is the
			// second line of defence.
			$identity_ok = true;
			$want_id     = class_exists( 'EqualFix_Guard' ) ? EqualFix_Guard::singular_id( $url ) : ( function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0 );
			$target_post = ( $want_id > 0 && function_exists( 'get_post' ) ) ? get_post( $want_id ) : null;
			if ( $target_post ) {
				if ( 'page' === $target_post->post_type ) {
					$qv = array( 'page_id' => $want_id );
				} elseif ( 'attachment' === $target_post->post_type ) {
					$qv = array( 'attachment_id' => $want_id );
				} elseif ( 'post' === $target_post->post_type ) {
					$qv = array( 'p' => $want_id );
				} else {
					$qv = array( 'p' => $want_id, 'post_type' => $target_post->post_type );
				}
				$wp_query       = new WP_Query( $qv );
				$wp_the_query   = $wp_query;
				$wp->query_vars = $qv;
				$wp->request    = '';
				$wp->register_globals();
				$qo          = $wp_query->get_queried_object();
				$identity_ok = $qo && isset( $qo->ID ) && (int) $qo->ID === $want_id && ! $wp_query->is_404();
				if ( ! $identity_ok ) {
					$this->last_failure = 'guard';
				}
			} else {
				// The front-end pipeline, minus the parts that
				// only make sense for a real request (WP::main()
				// would re-authenticate the current user and
				// send headers).
				$wp->parse_request();
				$wp->query_posts();
				$wp->handle_404();
				$wp->register_globals();
				if ( $want_id > 0 ) {
					$qo          = $wp_query->get_queried_object();
					$identity_ok = $qo && isset( $qo->ID ) && (int) $qo->ID === $want_id;
					if ( ! $identity_ok ) {
						$this->last_failure = 'guard';
					}
				}
			}

			if ( $identity_ok && ! $wp_query->is_404() && ! $wp_query->is_feed() && ! $wp_query->is_robots() && ! $wp_query->is_trackback() && ! $wp_query->is_favicon() ) {
				$level     = ob_get_level();
				$render_ok = true;
				ob_start();
				// From here until the buffers are drained, an
				// exit anywhere in page code is a render death:
				// the flag lets a shutdown handler finish the
				// bookkeeping this return path never will.
				self::$render_in_progress = true;
				try {
					include ABSPATH . WPINC . '/template-loader.php'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				} catch ( Throwable $e ) {
					$render_ok = false;
				}
				// Drain every buffer opened since ours began —
				// a template or plugin may have left its own
				// open; content order is outermost-first.
				$chunks = array();
				while ( ob_get_level() > $level ) {
					$chunks[] = (string) ob_get_clean();
				}
				self::$render_in_progress = false;
				$html = $render_ok ? implode( '', array_reverse( $chunks ) ) : false;
				if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
					$html = false;
				}
			}
		} catch ( Throwable $e ) {
			$html = false;
		}

		$this->restore_render_state( $saved );
		if ( false !== $html ) {
			$this->last_failure = '';
		}
		return $html;
	}

	/**
	 * Undo everything render_internally() moved: the main query
	 * and its globals, the style/script queues, the request
	 * superglobals, the current user, and the redirect hooks.
	 *
	 * @param array $saved Snapshot taken before the render.
	 */
	private function restore_render_state( $saved ) {
		global $wp, $wp_query, $wp_the_query, $post, $posts, $wp_styles, $wp_scripts, $wp_filter;
		self::$render_in_progress = false;
		$wp           = $saved['wp'];
		$wp_query     = $saved['wp_query'];
		$wp_the_query = $saved['wp_the_query'];
		$post         = $saved['post'];
		$posts        = $saved['posts'];
		$wp_styles    = $saved['wp_styles'];
		$wp_scripts   = $saved['wp_scripts'];
		$_GET         = $saved['get'];
		$_REQUEST     = $saved['request'];
		$_POST        = isset( $saved['post_data'] ) ? $saved['post_data'] : $_POST;
		$_COOKIE      = isset( $saved['cookie'] ) ? $saved['cookie'] : $_COOKIE;
		if ( array_key_exists( 'php_self', $saved ) ) {
			if ( null === $saved['php_self'] ) {
				unset( $_SERVER['PHP_SELF'] );
			} else {
				$_SERVER['PHP_SELF'] = $saved['php_self'];
			}
		}
		if ( isset( $saved['method'] ) ) {
			if ( null === $saved['method'] ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $saved['method'];
			}
		}
		if ( null === $saved['uri'] ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $saved['uri'];
		}
		if ( null === $saved['ua'] ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $saved['ua'];
		}
		wp_set_current_user( $saved['user'] );
		if ( null === $saved['admin_bar'] ) {
			unset( $GLOBALS['show_admin_bar'] );
		} else {
			$GLOBALS['show_admin_bar'] = $saved['admin_bar'];
		}
		if ( null === $saved['redirect_hook'] ) {
			unset( $wp_filter['template_redirect'] );
		} else {
			$wp_filter['template_redirect'] = $saved['redirect_hook'];
		}
	}
}
