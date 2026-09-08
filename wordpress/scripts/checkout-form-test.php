<?php
/** Offline render tests for the A · Directa details form (issue #46).
 * themes/freeplast/woocommerce/checkout/form-checkout.php renders against a
 * stubbed checkout object with the REAL adapter field definitions loaded: one
 * classic native form, the four A groups, the native review table inside the
 * mobile-disclosure summary, the native payment/submit block, and the adapter
 * hook that emits the hidden attempt identity. Field names/classes and the
 * dispatch radio definition come from the adapter itself. */
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../.build/wp/wp-includes/plugin.php';
add_action('woocommerce_after_order_notes', static function () { echo 'HIDDEN-ATTEMPT-INPUT'; });
add_action('woocommerce_checkout_before_customer_details', static function () { echo 'BEFORE-CUSTOMER-DETAILS'; });
add_action('woocommerce_checkout_after_customer_details', static function () { echo 'AFTER-CUSTOMER-DETAILS'; });
add_action('woocommerce_checkout_order_review', 'woocommerce_order_review', 10);
add_action('woocommerce_checkout_order_review', static function () { echo 'REVIEW-EXTENSION'; }, 15);
add_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20);
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return esc_html($text); }
function esc_attr__($text, $domain = null) { return esc_html($text); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function number_format_i18n($number) { return number_format((float) $number, 0, ',', '.'); }
function wc_get_checkout_url() { return '/datos-y-envio/'; }
function wc_get_cart_url() { return '/cotizacion/'; }
function is_user_logged_in() { return false; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_kses_post($value) { return $value; }
function esc_textarea($value) { return esc_html($value); }
function absint($value) { return abs((int) $value); }
function checked($value, $current, $echo = true) { $text = $value === $current ? ' checked="checked"' : ''; if ($echo) echo $text; return $text; }
$native_fields = file_get_contents(__DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/includes/wc-template-functions.php');
if (!preg_match('/\tfunction woocommerce_form_field\(.*?\n\t\}/s', $native_fields, $native_match)) { throw new RuntimeException('Pinned native field renderer missing'); }
eval($native_match[0]);
function checked_stub($value, $current) { return $value === $current ? ' checked="checked"' : ''; }
function woocommerce_order_review() { echo 'REVIEW-TABLE[woocommerce-checkout-review-order-table]'; }
function woocommerce_checkout_payment() { echo 'PAYMENT-BLOCK[#place_order]'; }

class StubCheckout {
	public array $draft = array();
	public function __construct(private array $fields) {}
	public function get_checkout_fields($set = '') { return 'billing' === $set ? $this->fields['billing'] : ('order' === $set ? $this->fields['order'] : $this->fields); }
	public function get_value($key) { return $this->draft[$key] ?? null; }
	public function is_registration_enabled() { return false; }
	public function is_registration_required() { return false; }
	public function checkout_form_billing() { throw new RuntimeException('Native billing layout must be replaced, not duplicated'); }
	public function checkout_form_shipping() { throw new RuntimeException('Native shipping layout must be replaced, not duplicated'); }
}

class StubCart {
	public function get_cart() { return array('k' => array('product_id' => 11, 'variation_id' => 0, 'quantity' => 2)); }
}
function WC() {
	static $wc = null;
	if (null === $wc) { $wc = (object) array('cart' => new StubCart()); }
	return $wc;
}

/* Real adapter field definitions (billing set under test). */
$GLOBALS['fpw_fields_only'] = true;
$adapter_source = file_get_contents(__DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
preg_match('/function fpw_checkout_fields\( \$fields \) \{\s*\$fields\[.billing.\] = array\((.*?)\);\s*\$fields\[.shipping.\]/s', $adapter_source, $billing_block);
eval('function stub_billing_fields() { return array(' . $billing_block[1] . '); }');
$billing = stub_billing_fields();
$order = array('order_comments' => array('label' => 'Mensaje', 'type' => 'textarea', 'required' => false, 'class' => array('form-row-wide'), 'priority' => 10));
$checkout = new StubCheckout(array('billing' => $billing, 'order' => $order));
$checkout->draft = array('billing_first_name' => 'Cliente Guardado', 'billing_fp_dispatch' => 'si');
add_action('woocommerce_checkout_billing', array($checkout, 'checkout_form_billing'));
add_action('woocommerce_checkout_shipping', array($checkout, 'checkout_form_shipping'));
add_action('woocommerce_checkout_billing', static function () { echo 'BILLING-EXTENSION'; }, 20);

ob_start();
include __DIR__ . '/../wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php';
$html = (string) ob_get_clean();

$checks = 0;
function verify($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}

verify(str_contains($html, 'REVIEW-EXTENSION') && str_contains($html, 'BILLING-EXTENSION'), 'native extension hooks still execute');
verify(has_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment') === 20 && has_action('woocommerce_checkout_billing', array($checkout, 'checkout_form_billing')) === 10, 'scoped native callbacks restored after rendering');
verify(substr_count($html, '<form name="checkout"') === 1, 'exactly ONE classic checkout form');
verify(str_contains($html, 'method="post"') && str_contains($html, 'class="checkout woocommerce-checkout"'), 'the native form contract (method/classes) is preserved');
verify(str_contains($html, 'action="/datos-y-envio/"'), 'the native form action targets the checkout route');
verify(!str_contains($html, 'wp-block-woocommerce-checkout') && !str_contains($html, 'Checkout Blocks'), 'no Checkout Blocks migration');

verify(substr_count($html, 'fp-form-section') === 4, 'four A groups');
foreach (array('01</span> Contacto', '02</span> Empresa', '03</span> Despacho', '04</span> Algo más que debamos saber') as $section) {
	verify(str_contains($html, $section), 'group: ' . $section);
}
foreach (array('billing_first_name', 'billing_phone', 'billing_email', 'billing_company', 'billing_fp_rut', 'billing_fp_giro', 'billing_fp_dispatch', 'billing_fp_address', 'order_comments') as $field) {
	verify(str_contains($html, 'name="' . $field . '"'), 'native field name kept: ' . $field);
}
verify(str_contains($html, 'value="Cliente Guardado"') || str_contains($html, 'Cliente Guardado'), 'draft values render through the native get_value path');
verify(substr_count($html, 'HIDDEN-ATTEMPT-INPUT') === 1, 'the adapter attempt-identity hook fires once inside the form');

/* Dispatch: one native radio group, both values, no contradictory inputs. */
verify($billing['billing_fp_dispatch']['type'] === 'radio', 'dispatch is a native radio field');
verify(substr_count($html, 'name="billing_fp_dispatch"') === 2, 'exactly two dispatch inputs share one name');
verify(str_contains($html, 'value="si"') && str_contains($html, 'value="no"'), 'the native si/no values are the only ones');
verify(!str_contains($html, '<select'), 'no competing select remains for dispatch');
verify((bool) preg_match('/value="si"[^>]*checked/', $html), 'the draft dispatch choice renders checked');

/* Required set unchanged. */
$required = array('billing_first_name','billing_phone','billing_email','billing_company','billing_fp_rut','billing_fp_giro','billing_fp_dispatch');
foreach ($required as $field) { verify(!empty($billing[$field]['required']), 'required kept: ' . $field); }
verify(empty($billing['billing_fp_address']['required']), 'the address stays conditionally required (native validation decides)');

/* Summary: disclosure with native review table + Editar productos. */
verify(str_contains($html, 'data-fp-summary-details') && str_contains($html, '1 producto · 2 unidades'), 'the compact summary states native lines/units');
verify(substr_count($html, 'REVIEW-TABLE') === 1, 'the native review table renders exactly once, inside the summary');
verify(str_contains($html, 'woocommerce-checkout-review-order-table'), 'Woo\'s own review-table fragment target is preserved for update_order_review');
verify(str_contains($html, 'href="/cotizacion/"') && str_contains($html, 'Editar productos'), 'Editar productos returns to the native basket');
verify(!str_contains($html, 'REVIEW-TABLE') || strpos($html, 'data-fp-summary-details') < strpos($html, 'REVIEW-TABLE'), 'the summary leads the mobile form flow');

/* Submit: native payment block, privacy link, no new requirements. */
verify(str_contains($html, 'PAYMENT-BLOCK'), 'the native payment/place-order block renders at the form foot');
verify(str_contains($html, 'política de privacidad</a>') && str_contains($html, '/politica-de-privacidad/'), 'privacy links the real policy page');
verify(!str_contains($html, 'checkbox') || !str_contains($html, 'consentimiento'), 'no consent checkbox invented');
verify(!str_contains($html, 'registro') || !str_contains($html, 'Regístrate'), 'no registration requirement invented');
verify(str_contains($html, 'Todos los campos son obligatorios, salvo Mensaje.'), 'the required-fields hint matches the real rules');

/* Steps: step 2 active. */
verify(str_contains($html, 'aria-current="step"') && str_contains($html, 'step-number">2<'), 'the details step is current');
verify(!str_contains($html, 'fpw_attempt" value=""') , 'no literal attempt markup is invented by the theme (the adapter hook owns it)');

/* The enhancement script ships beside the form. */
$functions = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/functions.php');
verify(str_contains($functions, 'checkout-form.js'), 'the checkout enhancement script is enqueued');
$adapter = file_get_contents(__DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
verify(str_contains($adapter, "'1.0.4'"), 'fields.js enqueue version follows its radio change');

if ('1' === getenv('FREEPLAST_TEST_FORM_HTML')) { echo $html; }
else { echo "checkout form: $checks PHP checks passed\n"; }
