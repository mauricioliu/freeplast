/**
 * Freeplast Quote Basket — progressive enhancement (issue #6).
 *
 * The server stays authoritative: every add goes through the same
 * admin-post.php POST handler the plain form uses. When JavaScript is
 * available the handler answers JSON and this script mirrors the returned
 * state (header count, mini basket, status notice) in place; without it —
 * or on any fetch/parse failure — the form POSTs normally and the server
 * redirect renders the same state.
 */
( function () {
	'use strict';

	var LABEL_START = 'Cotización (';

	function statusFor( widget, text ) {
		var node = widget.querySelector( '[data-fpcq-basket-status]' );
		if ( ! node ) {
			node = document.createElement( 'p' );
			node.className = 'fpcq-basket-status';
			node.setAttribute( 'role', 'status' );
			node.setAttribute( 'data-fpcq-basket-status', '' );
			widget.appendChild( node );
		}
		node.textContent = text;
	}

	/* Mirror the authoritative server state onto the visible widgets. */
	function apply( payload ) {
		var label = LABEL_START + payload.count + ')';
		document.querySelectorAll( '[data-fpcq-basket-count]' ).forEach( function ( el ) {
			el.textContent = label;
		} );
		document.querySelectorAll( '[data-fpcq-basket-mini]' ).forEach( function ( el ) {
			el.innerHTML = payload.mini;
		} );
		document.querySelectorAll( '.fpcq-basket-widget' ).forEach( function ( widget ) {
			statusFor( widget, payload.message || '' );
		} );
		/* The chooser served its purpose; the confirmation is visible in the header. */
		document.querySelectorAll( 'details.fpcq-card-cta[open]' ).forEach( function ( details ) {
			details.open = false;
		} );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'fpcq-basket-add' ) ) {
			return;
		}
		if ( ! window.fetch || ! window.FormData ) {
			return; // the plain POST flow is fully functional on its own
		}

		event.preventDefault();
		var data = new FormData( form );
		data.set( 'fp_enhanced', '1' );

		fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' }
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( apply )
			.catch( function () {
				form.submit(); // fall back to the authoritative server flow
			} );
	} );
} )();
