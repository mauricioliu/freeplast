<?php
/**
 * Native breadcrumb data, A sheet presentation: Catalog → category links.
 * Current product is already the page heading; retain other native trails.
 * @version 2.3.0
 */
defined( 'ABSPATH' ) || exit;
$fp_sheet = is_product();
if ( $fp_sheet ) {
	$fp_shop = wc_get_page_permalink( 'shop' );
	$breadcrumb = array_values( array_filter( $breadcrumb, static fn( $crumb ) => ! empty( $crumb[1] ) && $crumb[1] !== $fp_shop ) );
	array_unshift( $breadcrumb, array( 'Catálogo', $fp_shop ) );
}
if ( empty( $breadcrumb ) ) { return; }
// Wrappers/delimiter come from Woo's native breadcrumb arguments.
echo $wrap_before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
foreach ( $breadcrumb as $fp_index => $fp_crumb ) {
	echo $before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! empty( $fp_crumb[1] ) && ( $fp_sheet || count( $breadcrumb ) !== $fp_index + 1 ) ) {
		echo '<a href="' . esc_url( $fp_crumb[1] ) . '">' . esc_html( $fp_crumb[0] ) . '</a>';
	} else { echo esc_html( $fp_crumb[0] ); }
	echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( count( $breadcrumb ) !== $fp_index + 1 ) { echo $delimiter; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
echo $wrap_after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
