<?php
/** Read-only staging verification; no fake quotation, mail, import or manual tracking writes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || get_option( 'home' ) !== 'https://freeplast.mliu.site' ) { throw new RuntimeException( 'Wrong staging target.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
	if ( ! $ok ) { WP_CLI::error( $message ); }
	++$checks;
};
$check( class_exists( 'WooCommerce' ) && class_exists( 'Quotes_WC' ), 'Pinned Woo workflow is active.' );
$check( function_exists( 'fpw_workspace_query' ) && function_exists( 'fpw_handle_tracking_post' ) && function_exists( 'fpw_quotation_approve_and_send' ), 'Workspace and issuance are available.' );
$check( file_exists( WPMU_PLUGIN_DIR . '/freeplast-staging-mail.php' ), 'Independent staging mail containment remains installed.' );
$check( (int) get_option( 'blog_public' ) === 0, 'Staging remains noindex.' );
$sales = get_role( 'ventas_freeplast' );
$check( null !== $sales && empty( $sales->capabilities['manage_woocommerce'] ), 'Restricted staff gained no commercial capability.' );
$result = fpw_workspace_query( '', 'all', 'DESC', 1 );
$check( ! $result['error'] && count( $result['rows'] ) <= 25, 'Bounded inbox query works on the target database.' );
$check( false === apply_filters( 'woocommerce_hold_stock_for_checkout', true ), 'No stock reservation.' );
$check( false === apply_filters( 'woocommerce_can_reduce_order_stock', true ), 'No stock reduction.' );
$check( false !== has_action( 'admin_init', 'fpw_handle_tracking_post' ), 'Tracking guard is registered before rendering.' );
WP_CLI::success( $checks . ' read-only owner workspace checks passed; no human visual acceptance implied.' );
