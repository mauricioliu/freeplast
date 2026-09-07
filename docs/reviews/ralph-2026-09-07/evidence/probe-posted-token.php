<?php
// Offline only: real Woo 11.1.0 get_posted_data(), adapter's real field contract,
// no WordPress boot, database, HTTP, or writes to the repository.
$root = '/home/mauricio-liu/Projects/freeplast/wordpress';
require $root . '/scripts/test-woo-adapter.php';
require $root . '/.build/wp/wp-content/plugins/woocommerce/src/Internal/CostOfGoodsSold/CogsAwareTrait.php';
require $root . '/.build/wp/wp-content/plugins/woocommerce/includes/class-wc-checkout.php';
function wc_clean($v) { return $v; }
function sanitize_title($v) { return $v; }
class ReviewCheckout extends WC_Checkout {
    public function __construct() {}
    public function is_registration_enabled() { return false; }
    public function get_checkout_fields($fieldset = '') { return array('billing' => fpw_checkout_fields(array())['billing']); }
}
$GLOBALS['fpw_session_customer_id'] = 'review-session';
WC()->session = new FPW_Fake_Session();
$posted_token = str_repeat('a', 40);
$open_token = str_repeat('b', 40);
WC()->session->set('fpw_attempt_open', $open_token);
$_POST = array('fpw_attempt' => $posted_token, 'woocommerce-process-checkout-nonce' => 'synthetic');
$checkout = new ReviewCheckout();
$data = $checkout->get_posted_data();
$identity = fpw_attempt_identity($checkout);
echo json_encode(array(
    'raw_post_has_attempt' => isset($_POST['fpw_attempt']),
    'native_posted_data_has_attempt' => isset($data['fpw_attempt']),
    'claim_uses_posted_attempt' => $identity['token'] === $posted_token,
    'claim_instead_uses_current_open_attempt' => $identity['token'] === $open_token,
), JSON_PRETTY_PRINT) . "\n";
