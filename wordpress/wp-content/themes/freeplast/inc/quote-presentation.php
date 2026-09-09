<?php
/** Presentation normalization for already-published quote pages; no DB edits. */
defined( 'ABSPATH' ) || exit;

// The Cart page's historical lead lives in saved wp:html, not a PHP template.
// Normalize only the exact shipped paragraph on the native cart route. Leave
// merchant edits, other blocks and other routes untouched. The summary owns
// the commercial explanation; Woo still owns rendering the actual Cart block.
add_filter( 'render_block_core/html', static function ( $html ) {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) { return $html; }
	return str_replace(
		'<p class="fp-page-lead">Comprueba productos, colores y cantidades antes de continuar. Esto no es una compra ni una reserva de stock.</p>',
		'<p class="fp-page-lead">Comprueba productos, colores y cantidades antes de continuar.</p>',
		$html
	);
} );
