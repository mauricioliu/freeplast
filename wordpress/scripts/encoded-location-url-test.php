<?php
/** SP-1: actual pinned Woo normalization + WordPress sanitizers, synthetic storage only. */
$wp = __DIR__ . '/../.build/wp';
$formatting = file_get_contents($wp . '/wp-includes/formatting.php');
foreach (array('sanitize_text_field', 'sanitize_textarea_field', '_sanitize_text_fields', 'wp_check_invalid_utf8', 'wp_strip_all_tags', 'wp_pre_kses_less_than', 'wp_pre_kses_less_than_callback') as $function) {
    if (!preg_match('/\nfunction ' . $function . '\(.*?\n\}/s', $formatting, $match)) { throw new RuntimeException('Missing native sanitizer: ' . $function); }
    eval($match[0]);
}
function is_utf8_charset() { return true; }
function wp_is_valid_utf8($text) { return mb_check_encoding($text, 'UTF-8'); }
function sanitize_title($text) { return $text; } // Checkout field types in this fixture are already slugs.
function wc_ship_to_billing_address_only() { return false; }
$woo = $wp . '/wp-content/plugins/woocommerce';
$formatting = file_get_contents($woo . '/includes/wc-formatting-functions.php');
foreach (array('wc_clean', 'wc_sanitize_textarea') as $function) {
    if (!preg_match('/\nfunction ' . $function . '\(.*?\n\}/s', $formatting, $match)) { throw new RuntimeException('Missing native Woo sanitizer'); }
    eval($match[0]);
}
require __DIR__ . '/checkout-places-test.php';
$native = file_get_contents($woo . '/includes/class-wc-checkout.php');
if (!preg_match('/\tpublic function get_posted_data\(.*?\n\t\}/s', $native, $match)) { throw new RuntimeException('Missing native normalization'); }
eval('class EncodedCheckoutBoundary { public $legacy_posted_data; ' . $match[0] . '
    public function is_registration_enabled() { return false; }
    public function get_checkout_fields() { return array("billing" => fpw_checkout_fields(array())["billing"]); }
    protected function maybe_skip_fieldset($key, $data) { return false; }
}');
$checkout = new EncodedCheckoutBoundary();
$url = 'https://maps.example.test/Predio%20Ana%2Fsur?q=a%26b&z=1';
check(wc_clean($url) !== $url && sanitize_textarea_field($url) !== $url, 'native sanitizers demonstrate original percent-escape loss');
$_POST = array_replace($rural, array('billing_fp_location_url'=>$url));
$data = $checkout->get_posted_data();
check($data['billing_fp_location_url'] === $url, 'real Woo posted-data method preserves encoded URL via field filter');
$order = fpwp_create($data);
check($order->meta['_billing_fp_location_url'] === $url && $order->meta['_fp_submitted_details']['billing_fp_location_url'] === $url, 'native normalization through order and submitted details preserves exact bytes');
$draft = fpw_build_request_draft_payload(new FPWP_Order($order->meta));
check(fpw_quotation_projection($draft, null)['location_url'] === $url, 'encoded URL survives draft projection');
foreach ($registered_actions['woocommerce_checkout_update_order_review'] as $callback) { $callback(http_build_query($_POST)); }
check(apply_filters('woocommerce_checkout_get_value', null, 'billing_fp_location_url') === $url, 'back-navigation restores exact encoded URL');
foreach (array('javascript:alert(1)', 'https://user:pass@example.test/', 'https://example.test/ bad', str_repeat('a',2049), array('bad')) as $bad) {
    $_POST['billing_fp_location_url'] = $bad;
    $data = $checkout->get_posted_data();
    $errors = new WP_Error(); fpw_validate_checkout($data, $errors);
    check(in_array('billing_fp_location_url', $errors->codes, true), 'native URL path retains invalid input for explicit validation');
    try { fpwp_create($data); throw new LogicException('unsafe URL persisted'); }
    catch (Exception $error) { check(!($error instanceof LogicException), $error->getMessage()); }
}
$_POST = array_replace($rural, array('billing_fp_dispatch'=>'no', 'billing_fp_location_url'=>$url));
$order = fpwp_create($checkout->get_posted_data());
check($order->meta['_billing_fp_location_url'] === '', 'native no-dispatch clears location');
foreach ($registered_actions['woocommerce_checkout_update_order_review'] as $callback) { $callback(http_build_query($_POST)); }
check(apply_filters('woocommerce_checkout_get_value', null, 'billing_fp_location_url') === '', 'no-dispatch clears restored location too');
$_POST = $rural; unset($_POST['billing_fp_location_url']);
check($checkout->get_posted_data()['billing_fp_location_url'] === '', 'missing historical/optional URL remains empty');
echo "encoded location: $assertions cumulative native-normalization/synthetic-storage checks passed\n";
