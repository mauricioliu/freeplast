<?php
/** Company RUT validation for NEW requests; historical imports keep their own shape-only policy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Presentation is not identity. Reject unexpected characters rather than guessing a RUT. */
function fpw_rut_normalize( string $raw ): string {
	if ( strlen( $raw ) > 40 || preg_match( '/[^0-9kK.\s-]/', $raw ) ) { return ''; }
	$clean = strtoupper( preg_replace( '/[.\s-]/', '', $raw ) ?? '' );
	return preg_match( '/^[0-9]{7,8}[0-9K]$/D', $clean ) ? $clean : '';
}

function fpw_rut_format( string $raw ): string {
	$rut = fpw_rut_normalize( $raw );
	if ( '' === $rut ) { return $raw; }
	return preg_replace( '/\B(?=(\d{3})+(?!\d))/', '.', substr( $rut, 0, -1 ) ) . '-' . substr( $rut, -1 );
}

function fpw_rut_is_valid( string $raw ): bool {
	$rut = fpw_rut_normalize( $raw );
	if ( '' === $rut || 0 === (int) substr( $rut, 0, -1 ) ) { return false; }
	$sum = 0;
	$factor = 2;
	for ( $i = strlen( $rut ) - 2; $i >= 0; $i-- ) {
		$sum += (int) $rut[$i] * $factor;
		$factor = 7 === $factor ? 2 : $factor + 1;
	}
	$digit = 11 - ( $sum % 11 );
	$expected = 11 === $digit ? '0' : ( 10 === $digit ? 'K' : (string) $digit );
	return substr( $rut, -1 ) === $expected;
}

/** Woo uses this data for AJAX and plain POST, including the submitted-details snapshot. */
function fpw_checkout_format_rut( array $data ): array {
	if ( fpw_rut_is_valid( (string) ( $data['billing_fp_rut'] ?? '' ) ) ) {
		$data['billing_fp_rut'] = fpw_rut_format( $data['billing_fp_rut'] );
	}
	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'fpw_checkout_format_rut' );
