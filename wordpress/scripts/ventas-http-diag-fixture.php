<?php
/** Lead-run diagnostic fixture (plan-mv6vrtm2-rsaa): run-owned synthetic
 * shop_order plus the CLI-side actor/hook report for ventas-http-diag.py.
 * No HTTP, no historical/client records, no role or policy edits.
 * Independent containment (mail + network blocked) even without the harness
 * mu-plugins. Usage (wp eval-file):
 *   create <run>                       -> {"order":N}  (billing email ventas-<run>@example.invalid)
 *   cleanup <run>                      -> {"deleted":n} (only orders bound to this run)
 *   cleanup-email <run>                -> deletes shop_orders whose _billing_email is ventas-<run>@example.invalid
 *   cli-report <run> <user_id>         -> actor raw/effective caps + guard hooks as seen from WP-CLI
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
$cmd = (string) ( $args[0] ?? '' );
$run = (string) ( $args[1] ?? '' );
if ( ! preg_match( '/^[a-f0-9]{12}$/D', $run ) ) { WP_CLI::error( 'Bad run token.' ); }
if ( realpath( ABSPATH ) !== realpath( dirname( __DIR__ ) . '/.build/wp' ) || wp_get_environment_type() !== 'local' ) {
	WP_CLI::error( 'Exact disposable local worktree fixture required.' );
}
// Independent mail/network shims BEFORE any fixture work (probe may run standalone).
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MIN );
add_filter( 'pre_http_request', static function () {
	return new WP_Error( 'fpw_diag_blocked', 'Diagnostic fixture has no network' );
}, PHP_INT_MIN );

$diag_caps = static function ( WP_User $user ): array {
	$raw = array();
	foreach ( array( 'edit_shop_orders', 'edit_others_shop_orders', 'manage_woocommerce' ) as $cap ) {
		$raw[ $cap ] = ! empty( $user->allcaps[ $cap ] );
	}
	return $raw;
};
$diag_hooks = static function (): array {
	return array(
		'adapter_present' => function_exists( 'fpw_is_order_limited_staff' ),
		'front'           => has_action( 'admin_init', 'fpw_deny_sales_record_mutation' ),
		'note_meta'       => has_action( 'admin_init', 'fpw_deny_sales_order_note_and_meta_mutation' ),
		'save'            => has_filter( 'woocommerce_process_shop_order_meta', 'fpw_deny_sales_order_save' ),
		'bulk'            => has_filter( 'woocommerce_bulk_action_ids', 'fpw_deny_sales_bulk_actions' ),
	);
};

switch ( $cmd ) {
	case 'create':
		$product = get_posts( array( 'post_type' => 'product', 'name' => 'caja-universal-prueba', 'post_status' => 'any', 'numberposts' => 1 ) );
		if ( ! $product ) { WP_CLI::error( 'Fixture product caja-universal-prueba missing (bootstrap the stack first).' ); }
		$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'fpw-http-diag' ) );
		if ( ! is_a( $order, 'WC_Order' ) ) { WP_CLI::error( 'Order create failed.' ); }
		// Explicit setters: the billing array alone may not persist the native
		// email/first name woo-ventas-state.php verifies the run binding by.
		$order->set_billing_email( 'ventas-' . $run . '@example.invalid' );
		$order->set_billing_first_name( 'NO ATENDER DIAG' );
		$order->add_product( wc_get_product( (int) $product[0]->ID ), 5 );
		$order->update_meta_data( '_fp_http_diag_run', $run );
		$order->save();
		WP_CLI::line( wp_json_encode( array( 'order' => $order->get_id() ) ) );
		break;

	case 'cleanup':
		$ids = get_posts( array(
			'post_type' => 'shop_order', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids',
			'meta_key' => '_fp_http_diag_run', 'meta_value' => $run,
		) );
		foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
		WP_CLI::line( wp_json_encode( array( 'deleted' => count( $ids ) ) ) );
		break;

	case 'cleanup-email':
		$email = 'ventas-' . $run . '@example.invalid';
		$ids = get_posts( array(
			'post_type' => 'shop_order', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids',
			'meta_key' => '_billing_email', 'meta_value' => $email,
		) );
		foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
		WP_CLI::line( wp_json_encode( array( 'deleted' => count( $ids ) ) ) );
		break;

	case 'cli-report':
		$user_id = (int) ( $args[2] ?? 0 );
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'ventas_freeplast', (array) $user->roles, true ) ) { WP_CLI::error( 'Run-owned ventas user required.' ); }
		wp_set_current_user( $user_id );
		WP_CLI::line( wp_json_encode( array(
			'context' => 'cli',
			'raw' => $diag_caps( $user ),
			'effective' => array(
				'edit_shop_orders' => current_user_can( 'edit_shop_orders' ),
				'manage_woocommerce' => current_user_can( 'manage_woocommerce' ),
			),
			'limited' => function_exists( 'fpw_is_order_limited_staff' ) ? (bool) fpw_is_order_limited_staff() : null,
			'hooks' => $diag_hooks(),
		) ) );
		break;

	default:
		WP_CLI::error( 'Unknown subcommand (create|cleanup|cli-report).' );
}
