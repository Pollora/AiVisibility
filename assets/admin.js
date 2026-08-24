/**
 * Settings screen: regenerate the AI visibility files without leaving the page.
 *
 * Configuration (ajaxUrl, nonce, action, labels) comes from wp_localize_script.
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'ai-visibility-regenerate' );
		var status = document.getElementById( 'ai-visibility-status' );

		if ( ! button || ! status || typeof aiVisibilityAdmin === 'undefined' ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			status.textContent = aiVisibilityAdmin.working;

			var body = new URLSearchParams( {
				action: aiVisibilityAdmin.action,
				_ajax_nonce: aiVisibilityAdmin.nonce
			} );

			window.fetch( aiVisibilityAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					var message = ( payload && payload.data && payload.data.message ) || aiVisibilityAdmin.failed;

					status.textContent = ( payload && payload.success ? '✓ ' : '✗ ' ) + message;

					if ( payload && payload.success ) {
						window.location.reload();
					}
				} )
				.catch( function ( error ) {
					status.textContent = '✗ ' + ( error.message || aiVisibilityAdmin.failed );
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
})();
