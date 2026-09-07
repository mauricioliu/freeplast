<?php
// Offline boundary probe: real adapter guard + pinned Woo tax controller.
// The nonce verifier accepts a synthetic fixture; persistence is a tripwire.
// No WordPress boot, HTTP, database or repository modifications.
$root = '/home/mauricio-liu/Projects/freeplast/wordpress';
require $root . '/scripts/test-woo-adapter.php';
require $root . '/.build/wp/wp-content/plugins/woocommerce/src/Internal/Orders/TaxesController.php';
function check_ajax_referer($action, $field) {
    if ($action !== 'calc-totals' || ($_POST[$field] ?? '') !== 'valid-fixture') { throw new RuntimeException('invalid nonce fixture'); }
    return 1;
}
function wc_save_order_items($order_id, $items) {
    echo json_encode(array('persistence_boundary_reached' => true, 'order_id' => $order_id, 'items' => $items), JSON_PRETTY_PRINT) . "\n";
    throw new RuntimeException('STOP: persistence tripwire; nothing actually saved');
}
$GLOBALS['fpw_user_caps'] = array('read'=>true, 'manage_freeplast_quotes'=>true, 'edit_shop_orders'=>true, 'edit_others_shop_orders'=>true);
$_GET = array();
$_POST = array('action'=>'woocommerce_calc_line_taxes', 'security'=>'valid-fixture', 'order_id'=>77, 'items'=>'order_item_id[]=1&order_item_qty[1]=999');
$_REQUEST = $_POST;
echo 'restricted_actor=' . (fpw_is_order_limited_staff() ? 'true' : 'false') . "\n";
fpw_deny_sales_record_mutation();
echo "adapter_front_door_passed=true\n";
try { (new Automattic\WooCommerce\Internal\Orders\TaxesController())->calc_line_taxes_via_ajax(); }
catch (RuntimeException $e) { echo $e->getMessage() . "\n"; }
