<?php
/** Native Hetzner staging: read-only checks, also run on its isolated restored copy. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || get_option( 'home' ) !== 'https://freeplast.mliu.site' ) {
	throw new RuntimeException( 'Wrong staging target.' );
}
$checks = 0;
$check = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) { WP_CLI::error( $label ); }
	++$checks;
};
$check( class_exists( 'WooCommerce' ) && class_exists( 'Quotes_WC' ), 'Woo workflow active.' );
$check( defined( 'WC_VERSION' ) && WC_VERSION === '11.1.0', 'Pinned Woo version.' );
$check( get_option( 'stylesheet' ) === 'freeplast', 'Freeplast theme active.' );
$check( function_exists( 'fpw_workspace_query' ) && function_exists( 'fpw_quotation_approve_and_send' ) && function_exists( 'fpw_can_manage_data' ), 'Full workspace/data/issuance modules loaded.' );
$check( (int) get_option( 'blog_public' ) === 0, 'Staging stays noindex.' );
$shim = WPMU_PLUGIN_DIR . '/freeplast-staging-mail.php';
$check( is_file( $shim ) && hash_file( 'sha256', $shim ) === '71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1', 'Independent staging mail containment unchanged.' );
$check( ! function_exists( 'fpw_local_capture_active' ), 'Disposable local mail capture not installed.' );
$check( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes', 'Original HPOS storage retained.' );
$check( (int) wp_count_posts( 'product' )->publish === 17 && (int) wp_count_posts( 'product_variation' )->publish === 10, 'Existing catalog/variations retained.' );
$check( count( get_option( 'fpw_legacy_order_map', array() ) ) === 0, 'Fresh staging has no invented legacy mappings.' );
foreach ( array( 'fpw_quotation_manager' => 'fpw_manage_quotations', 'fpw_data_manager' => 'fpw_manage_data' ) as $name => $cap ) {
	$role = get_role( $name );
	$check( null !== $role && count( $role->capabilities ) === 2 && ( $role->capabilities['read'] ?? false ) === true && ( $role->capabilities[$cap] ?? false ) === true, 'Exact restricted role: ' . $name );
}
$sales = get_role( 'ventas_freeplast' );
$check( null !== $sales && count( $sales->capabilities ) === 4 && empty( $sales->capabilities['manage_woocommerce'] ), 'Legacy sales privileges retained.' );
$result = fpw_workspace_query( '', 'all', 'DESC', 1 );
$check( ! $result['error'] && count( $result['rows'] ) <= 25, 'Bounded inbox query on MariaDB/HPOS.' );
foreach ( wc_get_orders( array( 'limit' => -1 ) ) as $order ) {
	$check( ! $order->is_paid() && ! $order->needs_payment() && ! $order->get_meta( '_order_stock_reduced' ), 'Quote requests remain unpaid and do not reduce stock.' );
}
$check( false === apply_filters( 'woocommerce_hold_stock_for_checkout', true ), 'No stock reservations.' );
$check( false === apply_filters( 'woocommerce_can_reduce_order_stock', true ), 'No stock reduction.' );
$check( false !== has_action( 'admin_init', 'fpw_handle_tracking_post' ), 'Tracking guard registered.' );
$check( str_contains( get_post_field( 'post_content', wc_get_page_id( 'cart' ) ), 'wp:woocommerce/cart' ), 'Native basket retained.' );
$check( str_contains( get_post_field( 'post_content', wc_get_page_id( 'checkout' ) ), '[woocommerce_checkout]' ), 'Native classic intake retained.' );
WP_CLI::success( $checks . ' native staging checks passed; read-only, no email or visual acceptance.' );
