/**
 * Settings screen behaviour.
 *
 * Everything here is an enhancement. The panel switcher is a list of real links
 * and every panel is in the DOM, so with JavaScript off the screen still works:
 * a click loads the same page at ?tab=<panel>, and the form still submits every
 * field. This file only removes the page load.
 *
 * Configuration (ajaxUrl, nonce, action, labels) comes from wp_localize_script.
 */
(function () {
	'use strict';

	var config = window.aiVisibilityAdmin || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '.aivis' );

		if ( ! root ) {
			return;
		}

		setUpPanels( root );
		setUpRegenerate();
		setUpCopyButtons();
	} );

	/* ------------------------------------------------------------- panels */

	function setUpPanels( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );
		var saveBar = root.querySelector( '.aivis__savebar' );

		if ( ! tabs.length ) {
			return;
		}

		function activate( name, moveFocus ) {
			var matched = false;

			tabs.forEach( function ( tab ) {
				var isTarget = tab.dataset.panel === name;
				var panel = document.getElementById( 'aivis-panel-' + tab.dataset.panel );

				tab.setAttribute( 'aria-selected', isTarget ? 'true' : 'false' );
				tab.tabIndex = isTarget ? 0 : -1;

				if ( panel ) {
					panel.hidden = ! isTarget;
				}

				if ( isTarget ) {
					matched = true;

					if ( moveFocus ) {
						tab.focus();
					}
				}
			} );

			if ( ! matched ) {
				return;
			}

			root.dataset.activePanel = name;

			// The save bar belongs to the form, not the dashboard.
			if ( saveBar ) {
				saveBar.hidden = name === 'dashboard';
			}

			rememberPanel( name );
		}

		/**
		 * Keep the panel in the address bar, and in the referer WordPress
		 * redirects to after saving — otherwise saving always lands back on
		 * the first panel, which is exactly where the user was not.
		 */
		function rememberPanel( name ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', name );
			window.history.replaceState( null, '', url.toString() );

			var referer = document.querySelector( 'input[name="_wp_http_referer"]' );

			if ( referer ) {
				var target = new URL( referer.value, window.location.origin );
				target.searchParams.set( 'tab', name );
				referer.value = target.pathname + target.search;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				// Let modified clicks open a new tab, as any link should.
				if ( event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0 ) {
					return;
				}

				event.preventDefault();
				activate( tab.dataset.panel, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var index = tabs.indexOf( tab );
				var next = null;

				if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( event.key === 'ArrowUp' || event.key === 'ArrowLeft' ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( event.key === 'Home' ) {
					next = tabs[ 0 ];
				} else if ( event.key === 'End' ) {
					next = tabs[ tabs.length - 1 ];
				}

				if ( next ) {
					event.preventDefault();
					activate( next.dataset.panel, true );
				}
			} );
		} );

		// The server already rendered the right panel; sync the save bar and
		// the referer without touching focus.
		activate( root.dataset.activePanel || tabs[ 0 ].dataset.panel, false );

		window.addEventListener( 'popstate', function () {
			var requested = new URL( window.location.href ).searchParams.get( 'tab' );
			activate( requested || 'dashboard', false );
		} );
	}

	/* -------------------------------------------------------- regenerate */

	function setUpRegenerate() {
		var button = document.getElementById( 'ai-visibility-regenerate' );
		var status = document.getElementById( 'ai-visibility-status' );

		if ( ! button || ! status || ! config.ajaxUrl ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.dataset.state = 'loading';
			status.dataset.state = '';
			status.textContent = config.working || '';

			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action: config.action,
					_ajax_nonce: config.nonce
				} )
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return { success: false };
					} );
				} )
				.then( function ( payload ) {
					var ok = !! ( payload && payload.success );
					var message = ( payload && payload.data && payload.data.message ) || config.failed;

					status.dataset.state = ok ? 'success' : 'error';
					status.textContent = message;

					if ( ok ) {
						// The dashboard reports file sizes and timestamps that
						// have just changed; re-read them from the server
						// rather than guessing at them here.
						window.location.reload();
					}
				} )
				.catch( function ( error ) {
					status.dataset.state = 'error';
					status.textContent = error.message || config.failed;
				} )
				.finally( function () {
					button.disabled = false;
					delete button.dataset.state;
				} );
		} );
	}

	/* ------------------------------------------------------------- copy */

	function setUpCopyButtons() {
		document.querySelectorAll( '.aivis-copy' ).forEach( function ( button ) {
			var original = button.textContent;

			button.addEventListener( 'click', function () {
				var value = button.dataset.copy || '';

				write( value )
					.then( function () {
						button.textContent = config.copied || 'Copied';
					} )
					.catch( function () {
						button.textContent = config.copyFailed || '';
					} )
					.finally( function () {
						window.setTimeout( function () {
							button.textContent = original;
						}, 1600 );
					} );
			} );
		} );
	}

	/**
	 * navigator.clipboard is unavailable outside a secure context, which a
	 * local WordPress on plain http very often is.
	 */
	function write( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( value );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );
			field.value = value;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy rejected' ) );
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( field );
			}
		} );
	}
})();
