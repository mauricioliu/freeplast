/**
 * Freeplast Quote Basket — progressive enhancement (issues #6–#7).
 *
 * The server stays authoritative: every basket form (add, update, remove)
 * POSTs to the same admin-post.php handlers the plain flow uses. When
 * JavaScript is available the handler answers JSON and this script mirrors
 * the returned state (header count, mini basket, full Cotización view,
 * status notice) in place; without it — or on any fetch/parse failure —
 * the form POSTs normally and the server redirect renders the same state.
 */
( function () {
	'use strict';

	var LABEL_START = 'Cotización (';
	var FORM_CLASSES = [ 'fpcq-basket-add', 'fpcq-basket-update', 'fpcq-basket-remove' ];

	function formClass( form ) {
		for ( var i = 0; i < FORM_CLASSES.length; i++ ) {
			if ( form.classList.contains( FORM_CLASSES[ i ] ) ) {
				return FORM_CLASSES[ i ];
			}
		}
		return null;
	}

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

	/* Mirror the authoritative server state onto the visible widgets. A
	   recoverable failure answers with a message only — the count, the mini
	   basket and the view keep their last state until a successful mutation
	   returns them. */
	function apply( payload, formClass ) {
		if ( payload.ok ) {
			var label = LABEL_START + payload.count + ')';
			document.querySelectorAll( '[data-fpcq-basket-count]' ).forEach( function ( el ) {
				el.textContent = label;
			} );
			document.querySelectorAll( '[data-fpcq-basket-mini]' ).forEach( function ( el ) {
				el.innerHTML = payload.mini;
			} );
			var view = document.querySelector( '[data-fpcq-basket-view]' );
			if ( view && payload.view ) {
				view.outerHTML = payload.view; /* the server re-rendered it, fresh nonces included */
			}
		}
		document.querySelectorAll( '.fpcq-basket-widget' ).forEach( function ( widget ) {
			statusFor( widget, payload.message || '' );
		} );
		/* The chooser served its purpose; the confirmation is visible in the header. */
		if ( 'fpcq-basket-add' === formClass ) {
			document.querySelectorAll( 'details.fpcq-card-cta[open]' ).forEach( function ( details ) {
				details.open = false;
			} );
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}
		var klass = formClass( form );
		if ( ! klass ) {
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
			.then( function ( payload ) {
				apply( payload, klass );
			} )
			.catch( function () {
				form.submit(); // fall back to the authoritative server flow
			} );
	} );
} )();
