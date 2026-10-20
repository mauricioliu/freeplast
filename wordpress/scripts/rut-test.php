<?php
/** Pure RUT rules plus the real Woo posted-data normalization hook. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../.build/wp/wp-includes/plugin.php';
require __DIR__ . '/../wp-content/plugins/freeplast-woo/rut.php';
$checks = 0;
function check_rut( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$checks++;
}
foreach ( array( '144424808'=>'14.442.480-8', '765916291'=>'76.591.629-1', '78117890k'=>'78.117.890-K', '10000013k'=>'10.000.013-K', '100000040'=>'10.000.004-0', '12345674'=>'1.234.567-4' ) as $raw => $formatted ) {
	$raw = (string) $raw;
	check_rut( fpw_rut_is_valid( $raw ), 'valid: ' . $raw );
	check_rut( fpw_rut_format( $raw ) === $formatted, 'format: ' . $raw );
	check_rut( fpw_rut_is_valid( ' ' . $formatted . ' ' ), 'formatted and spaced: ' . $raw );
	check_rut( fpw_rut_normalize( $formatted ) === strtoupper( $raw ), 'identity: ' . $raw );
	$data = apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_fp_rut'=>$raw, 'billing_company'=>'Empresa' ) );
	check_rut( $data === array( 'billing_fp_rut'=>$formatted, 'billing_company'=>'Empresa' ), 'Woo normalizes only RUT' );
}
foreach ( array( '', '14.442.480-3', '76591629', '1234567', '00000000-0', '00.000.000-K', '76123456KK', '76.591.629-1 x2', 'RUT 765916291', '765916291' . str_repeat( ' ', 20 ) . '2', str_repeat( '1', 241 ) ) as $raw ) {
	check_rut( ! fpw_rut_is_valid( $raw ), 'reject: ' . $raw );
	check_rut( apply_filters( 'woocommerce_checkout_posted_data', array( 'billing_fp_rut'=>$raw ) )['billing_fp_rut'] === $raw, 'invalid input preserved for correction' );
}
echo "RUT: $checks checks passed\n";
