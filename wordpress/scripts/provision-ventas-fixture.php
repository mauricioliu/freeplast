<?php
/** Operator-only native Woo fixture creation. Never web-load or auto-run.
 * wp eval-file scripts/provision-ventas-fixture.php SYNTHETIC_PRODUCT_ID
 * Pipe stdout to a private receipt file. Independent mail containment REQUIRED.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit(1); }
if ( '1' !== getenv('FREEPLAST_MAIL_CONTAINED') ) { WP_CLI::error('A separately contained mail environment is required.'); }
$product = wc_get_product((int)($args[0] ?? 0));
if ( ! $product || 'caja-universal-prueba' !== $product->get_slug() || $product->managing_stock() ) {
	WP_CLI::error('Use the non-stock-managed synthetic caja-universal-prueba product.');
}
$run = bin2hex(random_bytes(16));
$started = gmdate('Y-m-d\TH:i:s\Z');
$order = new WC_Order();
$order->set_status('pending');
$order->set_created_via('freeplast-verification');
$order->set_payment_method('quotes-gateway');
$order->set_billing_first_name('NO ATENDER '.$run);
$order->set_billing_email('ventas-'.$run.'@example.invalid');
$order->set_billing_phone('+56 9 5555 5555');
$order->update_meta_data('_qwc_quote','1');
$order->update_meta_data('_fp_verification_run',$run);
$order->update_meta_data('_fp_submitted_details',array('billing_first_name'=>'NO ATENDER '.$run,'billing_email'=>'ventas-'.$run.'@example.invalid','billing_fp_dispatch'=>'no'));
$order->save();
try {
	$order->add_product($product,5,array('subtotal'=>0,'total'=>0));
	$order->save();
} catch (Throwable $error) {
	WP_CLI::error('Fixture creation incomplete; operator must inspect new synthetic record '.$order->get_id().'. Details withheld.');
}
echo json_encode(array('order_id'=>$order->get_id(),'run'=>$run,'started_at'=>$started),JSON_THROW_ON_ERROR)."\n";
