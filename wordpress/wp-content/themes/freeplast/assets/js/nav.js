/**
 * Freeplast v6 shell — progressive navigation enhancement.
 *
 * Toggles the mobile sheet (burger menu). The shell renders and navigates
 * fine without this script; it only adds the compact mobile sheet control.
 */
( function () {
	'use strict';

	var burger = document.querySelector( '[data-fp-burger]' );
	var sheet = document.querySelector( '[data-fp-sheet]' );

	if ( ! burger || ! sheet ) {
		return;
	}

	function setOpen( open ) {
		sheet.hidden = ! open;
		burger.setAttribute( 'aria-expanded', String( open ) );
		burger.setAttribute( 'aria-label', open ? 'Cerrar menú' : 'Abrir menú' );
	}

	burger.addEventListener( 'click', function () {
		setOpen( sheet.hidden );
	} );

	sheet.addEventListener( 'click', function ( event ) {
		if ( event.target === sheet ) {
			setOpen( false );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			setOpen( false );
		}
	} );

	sheet.querySelectorAll( 'a' ).forEach( function ( link ) {
		link.addEventListener( 'click', function () {
			setOpen( false );
		} );
	} );
} )();
