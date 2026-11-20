/**
 * EqualFix admin — progressive-enhancement layer (Smooth batch).
 *
 * The main actions on the dashboard used to end in a full
 * WordPress page reload for every click. This layer re-posts
 * the SAME forms (same fields, same nonces, same handlers)
 * with one extra flag — equalfix_ajax=1 — and the server
 * answers with JSON instead of a redirect; the tab body is
 * then re-rendered server-side and swapped in place.
 *
 * Progressive enhancement only: with JS off, with this file
 * missing, or on any transport failure the controls behave
 * exactly as shipped (plain form posts and links). Only the
 * whitelisted actions below are intercepted; everything else
 * submits untouched. The fix console's Apply forms keep their
 * own shipped inline enhancement, which hands its success to
 * window.eqfApplyDone below instead of reloading.
 */
( function () {
	'use strict';
	if ( ! window.fetch || ! window.FormData || ! window.EqualFixAjax ) {
		return;
	}

	// Whitelisted actions by kind. 'settings' = save-style
	// feedback; 'action' = do it, show the outcome, refresh the
	// tab; 'audit' = the stepped run-audit chain.
	var FREE_ACTIONS = {
		save_settings: 'settings',
		apply_standard_fixes: 'action',
		pause_standard_fixes: 'action',
		apply_pro_fixes: 'action',
		pause_pro_fixes: 'action',
		run_audit: 'audit'
	};
	var PRO_ACTIONS = {
		queue_approve: 'action',
		queue_reject: 'action',
		queue_bulk: 'action',
		save_pro_settings: 'settings',
		save_monitor_schedule: 'settings'
	};
	var VIEW_PARAMS = [ 'view', 'only', 'group', 'open', 'show' ];

	function container() {
		return document.getElementById( 'equalfix-tab-body' );
	}

	function currentTab() {
		var box = container();
		return box ? ( box.getAttribute( 'data-eqf-tab' ) || 'overview' ) : 'overview';
	}

	/* ---------------- notices + page chrome ---------------- */

	function clearNotices() {
		var old = document.querySelectorAll( '.equalfix-notices .eqf-ajax-notice' );
		for ( var i = 0; i < old.length; i++ ) {
			old[ i ].remove();
		}
	}

	function showNotice( message, isError ) {
		var region = document.querySelector( '.equalfix-notices' );
		if ( ! region || ! message ) {
			return;
		}
		clearNotices();
		var div = document.createElement( 'div' );
		div.className = 'notice ' + ( isError ? 'notice-error' : 'notice-success' ) + ' is-dismissible eqf-ajax-notice';
		var p = document.createElement( 'p' );
		p.textContent = message;
		div.appendChild( p );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'notice-dismiss';
		var sr = document.createElement( 'span' );
		sr.className = 'screen-reader-text';
		sr.textContent = 'Dismiss this notice.';
		btn.appendChild( sr );
		btn.addEventListener( 'click', function () { div.remove(); } );
		div.appendChild( btn );
		region.appendChild( div );
	}

	function updateBadge( n ) {
		var tabs = document.querySelectorAll( '.nav-tab-wrapper .nav-tab' );
		var reviewTab = null;
		for ( var i = 0; i < tabs.length; i++ ) {
			if ( tabs[ i ].href && tabs[ i ].href.indexOf( 'tab=review' ) !== -1 ) {
				reviewTab = tabs[ i ];
				break;
			}
		}
		if ( ! reviewTab ) {
			return;
		}
		var badge = reviewTab.querySelector( '.eqf-tab-count' );
		if ( n > 0 ) {
			if ( ! badge ) {
				badge = document.createElement( 'span' );
				badge.className = 'eqf-tab-count';
				reviewTab.appendChild( document.createTextNode( ' ' ) );
				reviewTab.appendChild( badge );
			}
			badge.textContent = n;
		} else if ( badge ) {
			badge.remove();
		}
	}

	// The header pill and the Review badge live outside the
	// swapped region; action/fragment responses carry their new
	// state (the pill as server-rendered markup).
	function updateChrome( data ) {
		if ( ! data ) {
			return;
		}
		if ( data.pill_html ) {
			var pill = document.querySelector( '.equalfix-header-status' );
			if ( pill ) {
				pill.innerHTML = data.pill_html;
			}
		}
		if ( typeof data.pending_n !== 'undefined' ) {
			updateBadge( parseInt( data.pending_n, 10 ) || 0 );
		}
	}

	/* ---------------- fragment swap ---------------- */

	// Scripts inserted via innerHTML never run; re-create each
	// one so the swapped region's own inline wiring (toggles,
	// the console's apply enhancement, the queue's check-all)
	// binds to the new DOM exactly as it did on a full load.
	function runScripts( root ) {
		var scripts = root.querySelectorAll( 'script' );
		for ( var i = 0; i < scripts.length; i++ ) {
			var old = scripts[ i ];
			var s = document.createElement( 'script' );
			if ( old.src ) {
				s.src = old.src;
			} else {
				s.textContent = old.textContent;
			}
			old.parentNode.replaceChild( s, old );
		}
	}

	function swapFragment( data ) {
		var box = container();
		if ( ! box || ! data || typeof data.html !== 'string' ) {
			return;
		}
		box.innerHTML = data.html;
		if ( data.tab ) {
			box.setAttribute( 'data-eqf-tab', data.tab );
		}
		runScripts( box );
		updateChrome( data );
	}

	function fetchFragment( params ) {
		var body = new FormData();
		body.append( 'action', 'equalfix_fragment' );
		body.append( '_ajax_nonce', window.EqualFixAjax.nonce );
		body.append( 'eqf_tab', params.tab || currentTab() );
		for ( var i = 0; i < VIEW_PARAMS.length; i++ ) {
			var k = VIEW_PARAMS[ i ];
			if ( params[ k ] ) {
				body.append( 'eqf_' + k, params[ k ] );
			}
		}
		return window.fetch( window.EqualFixAjax.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'fragment failed' );
				}
				return json.data;
			} );
	}

	function refreshFragment( params ) {
		var box = container();
		if ( box ) {
			box.classList.add( 'eqf-swapping' );
		}
		return fetchFragment( params || {} )
			.then( function ( data ) {
				swapFragment( data );
				if ( box ) {
					box.classList.remove( 'eqf-swapping' );
				}
				return data;
			} )
			.catch( function ( err ) {
				if ( box ) {
					box.classList.remove( 'eqf-swapping' );
				}
				throw err;
			} );
	}

	/* ---------------- form posting ---------------- */

	// Post the form exactly as a native submit would — same
	// URL, same fields, the clicked button included — plus the
	// AJAX flag. Buttons are disabled by the CALLER after this
	// snapshot: a disabled button would drop out of FormData.
	function postForm( form, submitter ) {
		var fd = null;
		try {
			fd = submitter ? new FormData( form, submitter ) : new FormData( form );
		} catch ( e ) {
			fd = new FormData( form );
		}
		if ( submitter && submitter.name && ! fd.has( submitter.name ) ) {
			fd.append( submitter.name, submitter.value );
		}
		fd.append( 'equalfix_ajax', '1' );
		var url = form.getAttribute( 'action' ) || window.location.href;
		return window.fetch( url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						json = null;
					}
					if ( ! json ) {
						throw new Error( 'bad response' );
					}
					return json;
				} );
			} );
	}

	// Disable the form's submit controls and mark the clicked
	// one "Working…"; returns a restore function for failures.
	function setBusy( form, submitter ) {
		var btns = form.querySelectorAll( 'button[type="submit"], input[type="submit"]' );
		var saved = [];
		for ( var i = 0; i < btns.length; i++ ) {
			saved.push( { el: btns[ i ], disabled: btns[ i ].disabled } );
			btns[ i ].disabled = true;
		}
		var label = null;
		if ( submitter && ( submitter.tagName === 'BUTTON' || submitter.tagName === 'INPUT' ) ) {
			if ( submitter.tagName === 'BUTTON' ) {
				label = { el: submitter, html: submitter.innerHTML };
				submitter.textContent = 'Working…';
			} else {
				label = { el: submitter, html: submitter.value };
				submitter.value = 'Working…';
			}
		}
		form.setAttribute( 'data-eqf-busy', '1' );
		return function restore() {
			for ( var i = 0; i < saved.length; i++ ) {
				saved[ i ].el.disabled = saved[ i ].disabled;
			}
			if ( label ) {
				if ( label.el.tagName === 'BUTTON' ) {
					label.el.innerHTML = label.html;
				} else {
					label.el.value = label.html;
				}
			}
			form.removeAttribute( 'data-eqf-busy' );
		};
	}

	function genericFailure() {
		showNotice( 'That didn’t go through — nothing was changed. Check your connection and try again.', true );
	}

	/* ---------------- flows ---------------- */

	function actionFlow( form, submitter ) {
		var restore = setBusy( form, submitter );
		postForm( form, submitter ).then( function ( json ) {
			var data = json.data || {};
			updateChrome( data );
			if ( ! json.success || data.ok === false ) {
				restore();
				showNotice( data.message || 'That could not be completed.', true );
				return;
			}
			if ( data.message ) {
				showNotice( data.message, false );
			}
			refreshFragment( {} ).catch( function () {
				// The action itself succeeded; if the refresh
				// fails, a reload still shows the true state.
				window.location.reload();
			} );
		} ).catch( function () {
			restore();
			genericFailure();
		} );
	}

	function settingsFlow( form, submitter ) {
		var status = form.querySelector( '.efx-save-status' );
		var restore = setBusy( form, submitter );
		if ( status ) {
			status.textContent = 'Saving…';
			status.classList.remove( 'eqf-saved', 'eqf-failed' );
		}
		postForm( form, submitter ).then( function ( json ) {
			var data = json.data || {};
			if ( ! json.success || data.ok === false ) {
				throw data;
			}
			// Re-render the tab so every label and stamp matches
			// the just-saved state, then write the outcome into
			// the (new) save bar / notice region.
			return refreshFragment( {} ).then( function () {
				var fresh = document.querySelector( '.efx-save-status' );
				if ( fresh && form.classList.contains( 'efx-settings-form' ) ) {
					fresh.textContent = data.message || 'Saved.';
					fresh.classList.add( 'eqf-saved' );
				} else if ( data.message ) {
					showNotice( data.message, false );
				}
			} ).catch( function () {
				if ( data.message ) {
					showNotice( data.message, false );
				}
			} );
		} ).catch( function ( data ) {
			restore();
			var msg = ( data && data.message ) || 'Saving failed — nothing was changed. Check your connection and try again.';
			var st = form.querySelector( '.efx-save-status' );
			if ( st ) {
				st.textContent = msg;
				st.classList.add( 'eqf-failed' );
			} else {
				showNotice( msg, true );
			}
		} );
	}

	// The stepped audit: the start answers either "continue"
	// (follow the continuation URL — same token, same nonce —
	// until a step finishes) or the finish payload itself.
	function auditStep( json ) {
		var data = json.data || {};
		if ( json.success && data.status === 'continue' && data.continue_url ) {
			return window.fetch( data.continue_url, { credentials: 'same-origin' } )
				.then( function ( res ) { return res.json(); } )
				.then( auditStep );
		}
		return data;
	}

	function auditFlow( form, submitter ) {
		form.setAttribute( 'data-eqf-busy', '1' );
		postForm( form, submitter )
			.then( auditStep )
			.then( function ( data ) {
				showNotice( data.message || 'Audit complete.', ! data.ok );
				return refreshFragment( {} );
			} )
			.catch( function () {
				genericFailure();
				// Restore a working audit form whatever happened.
				refreshFragment( {} ).catch( function () {} );
			} );
	}

	/* ---------------- interception ---------------- */

	document.addEventListener( 'submit', function ( ev ) {
		var form = ev.target;
		if ( ! form || form.nodeName !== 'FORM' ) {
			return;
		}
		// The fix console's Apply forms run their own shipped
		// inline enhancement (progress steps + fetch); it calls
		// window.eqfApplyDone on success. Never double-post.
		if ( form.classList.contains( 'eqf-apply-form' ) ) {
			return;
		}
		if ( form.getAttribute( 'data-eqf-busy' ) ) {
			ev.preventDefault();
			return;
		}
		var field = form.querySelector( 'input[name="equalfix_action"], input[name="equalfix_pro_action"]' );
		if ( ! field ) {
			return;
		}
		var kind = FREE_ACTIONS[ field.value ] || PRO_ACTIONS[ field.value ];
		if ( ! kind ) {
			return;
		}
		ev.preventDefault();
		if ( 'audit' === kind ) {
			auditFlow( form, ev.submitter || null );
		} else if ( 'settings' === kind ) {
			settingsFlow( form, ev.submitter || null );
		} else {
			actionFlow( form, ev.submitter || null );
		}
	} );

	// The console Apply enhancement's success hook: swap the
	// Overview in place instead of reloading the whole page.
	window.eqfApplyDone = function () {
		refreshFragment( { tab: 'overview' } ).catch( function () {
			window.location.reload();
		} );
	};

	// Issues view switching: chips, "More views" links and the
	// By job / By page toggle are plain GET links — fetch just
	// the tab body for the link's view and swap it in. Without
	// JS (or on any failure) the link navigates as shipped.
	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		var a = t && t.closest ? t.closest( 'a.eqf-chip-link, a.eqf-more-link, a.eqf-group-btn' ) : null;
		if ( ! a ) {
			return;
		}
		var box = container();
		if ( ! box || ! box.contains( a ) ) {
			return;
		}
		ev.preventDefault();
		var url = new URL( a.href, window.location.href );
		var params = { tab: 'issues' };
		for ( var i = 0; i < VIEW_PARAMS.length; i++ ) {
			var v = url.searchParams.get( VIEW_PARAMS[ i ] );
			if ( v ) {
				params[ VIEW_PARAMS[ i ] ] = v;
			}
		}
		refreshFragment( params ).then( function () {
			window.history.pushState( { eqf: params }, '', a.href );
		} ).catch( function () {
			window.location.href = a.href;
		} );
	} );

	window.addEventListener( 'popstate', function () {
		var url = new URL( window.location.href );
		if ( ! url.searchParams.get( 'page' ) || url.searchParams.get( 'page' ).indexOf( 'equalfix' ) !== 0 ) {
			return;
		}
		var params = { tab: url.searchParams.get( 'tab' ) || 'overview' };
		for ( var i = 0; i < VIEW_PARAMS.length; i++ ) {
			var v = url.searchParams.get( VIEW_PARAMS[ i ] );
			if ( v ) {
				params[ VIEW_PARAMS[ i ] ] = v;
			}
		}
		refreshFragment( params ).catch( function () {
			window.location.reload();
		} );
	} );
} )();
