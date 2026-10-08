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
function wc_terms_and_conditions_checkbox_enabled() { return false; }
// The old payment marker missed native terms/privacy output and let the
// duplicate through. Execute Woo's terms template at the payment boundary.
add_action('woocommerce_checkout_terms_and_conditions', static function () {
	echo '<div class="woocommerce-privacy-policy-text"><p>Usaremos tus datos para preparar y responder tu solicitud de cotización. Consulta nuestra <a href="https://example.test/politica-de-privacidad/">política de privacidad</a>.</p></div>';
}, 20);
function woocommerce_checkout_payment() {
	echo 'PAYMENT-BLOCK[#place_order]';
	include __DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/templates/checkout/terms.php';
}

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

/* H2 (2026-10-03 review): the quote-only details form must carry no purchase-coupon
 * invitation, field or action. Woo hangs its coupon form on this exact hook; the
 * override removes that callback for its own render while other callbacks still run. */
function woocommerce_checkout_coupon_form() { echo '<div class="woocommerce-form-coupon-toggle">COUPON-FORM-MARKER</div>'; }
add_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
add_action('woocommerce_before_checkout_form', static function ($checkout) { echo 'BEFORE-CHECKOUT-EXTENSION'; }, 11);

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
verify(!str_contains($html, 'COUPON-FORM-MARKER') && stripos($html, 'coupon') === false, 'H2: no coupon invitation, field or action renders anywhere in the quote-only form');
verify(str_contains($html, 'BEFORE-CHECKOUT-EXTENSION'), 'H2: other before-checkout callbacks keep running without the coupon form');
verify(has_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form') === 10, 'H2: the native coupon callback is restored for every other Woo surface');
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
verify(substr_count($html, 'Usaremos tus datos para preparar') === 1, 'one privacy notice including native terms output');
verify(substr_count($html, 'Esta solicitud no es una compra ni reserva stock. Ventas confirmará precios, disponibilidad y condiciones.') === 1, 'one commercial explanation separate from native privacy');
verify(!str_contains($html, 'El precio se confirma con ventas.'), 'summary does not repeat the commercial explanation');
verify(str_contains(file_get_contents(__DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/templates/checkout/payment.php'), "wc_get_template( 'checkout/terms.php' )"), 'pinned native payment calls the exercised terms template');
verify(!str_contains($html, 'checkbox') || !str_contains($html, 'consentimiento'), 'no consent checkbox invented');
verify(!str_contains($html, 'registro') || !str_contains($html, 'Regístrate'), 'no registration requirement invented');
verify(str_contains($html, 'Los datos de contacto, empresa y la opción de despacho son obligatorios.') && str_contains($html, 'La dirección, con comuna y región, solo se exige con despacho. Mensaje es opcional.'), 'the required-fields hint matches the conditional and optional rules');

/* Steps: step 2 active. */
verify(str_contains($html, 'aria-current="step"') && str_contains($html, 'step-number">2<'), 'the details step is current');
verify(!str_contains($html, 'fpw_attempt" value=""') , 'no literal attempt markup is invented by the theme (the adapter hook owns it)');

/* The enhancement script ships beside the form. */
$functions = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/functions.php');
verify(str_contains($functions, 'checkout-form.js'), 'the checkout enhancement script is enqueued');
$adapter = file_get_contents(__DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
verify(str_contains($adapter, "'1.0.4'"), 'fields.js enqueue version follows its radio change');

/* Native server validation + both native notice shapes, without WP storage/HTTP. */
require_once __DIR__ . '/../.build/wp/wp-includes/class-wp-error.php';
$native_checkout = file_get_contents(__DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/includes/class-wc-checkout.php');
preg_match('/\tprotected function validate_posted_data\(.*?\n\t\}/s', $native_checkout, $native_validation);
verify(!empty($native_validation[0]), 'pinned native field validator found');
eval('class NativeValidationBoundary {' . $native_validation[0] . '}');
$formatting = file_get_contents(__DIR__ . '/../.build/wp/wp-includes/formatting.php');
foreach (array('is_email', 'sanitize_email') as $function) {
	preg_match('/function ' . $function . '\(.*?\n\}/s', $formatting, $match);
	eval($match[0]);
}
foreach (array('fpw_checkout_fields', 'fpw_checkout_required_notice', 'fpw_validate_checkout') as $function) {
	preg_match('/function ' . $function . '\(.*?\n\}/s', $adapter_source, $match);
	eval($match[0]);
}
add_filter('woocommerce_checkout_required_field_notice', 'fpw_checkout_required_notice', 10, 3);
function _x($text, $context, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo esc_html($text); }
function wc_kses_notice($message) { return $message; }
function wc_get_notice_data_attr($notice) { return isset($notice['data']['id']) ? ' data-id="' . esc_attr($notice['data']['id']) . '"' : ''; }
class NativeFieldValidation extends NativeValidationBoundary {
	public function __construct() {}
	public function get_checkout_fields($fieldset = '') { return fpw_checkout_fields(array()); }
	protected function maybe_skip_fieldset($fieldset_key, $data) { return false; }
	public function validate($data) {
		$errors = new WP_Error();
		$this->validate_posted_data($data, $errors);
		fpw_validate_checkout($data, $errors);
		return $errors;
	}
}
$validator = new NativeFieldValidation();
$valid = array('billing_first_name'=>'Persona Prueba', 'billing_phone'=>'+56 9 0000 0000', 'billing_email'=>'prueba@example.invalid', 'billing_company'=>'Empresa Sintética', 'billing_fp_rut'=>'RUT de prueba', 'billing_fp_giro'=>'Actividad de prueba', 'billing_fp_dispatch'=>'no', 'billing_fp_address'=>'', 'order_comments'=>'', 'payment_method'=>'quotes-gateway');
verify(!$validator->validate($valid)->has_errors(), 'complete fixture and empty optional message accepted; no invented RUT/phone rules');
$validation_fixtures = array();
$invalid_cases = array();
foreach ($required as $key) { $invalid_cases[$key] = array($key=>''); }
$invalid_cases['address'] = array('billing_fp_dispatch'=>'si', 'billing_fp_address'=>'   ');
$invalid_cases['dispatch-invalid'] = array('billing_fp_dispatch'=>'otra');
$invalid_cases['email-format'] = array('billing_email'=>'correo-invalido');
$invalid_cases['multiple'] = array('billing_first_name'=>'', 'billing_fp_giro'=>'', 'billing_fp_dispatch'=>'si', 'billing_fp_address'=>'');
foreach (array('billing_first_name','billing_company','billing_fp_rut','billing_fp_giro','billing_phone','billing_email','billing_fp_address') as $key) {
	$invalid_cases[$key . '-long'] = array($key=>'billing_email' === $key ? str_repeat('a', 225) . '@example.invalid' : str_repeat('a', 'billing_fp_address' === $key ? 801 : 241));
	if ('billing_fp_address' === $key) { $invalid_cases[$key . '-long']['billing_fp_dispatch'] = 'si'; }
}
foreach ($invalid_cases as $case=>$overrides) {
	$data = array_replace($valid, $overrides);
	$errors = $validator->validate($data);
	$notices = array();
	foreach ($errors->get_error_codes() as $code) {
		foreach ($errors->get_error_messages($code) as $message) { $notices[] = array('notice'=>$message, 'data'=>$errors->get_error_data($code)); }
	}
	verify(count($notices) === ('multiple' === $case ? 3 : 1), 'one message per rejected field: ' . $case);
	foreach ($notices as $notice) {
		$key = $notice['data']['id'];
		verify(str_contains($notice['notice'], $billing[$key]['label']) || ('billing_fp_dispatch' === $key && str_contains($notice['notice'], 'Despacho:')), 'named actionable message: ' . $case);
	}
	$fixture = array('values'=>$data, 'fields'=>array_keys($GLOBALS['fpw_checkout_field_errors']));
	foreach (array('notices', 'block-notices') as $shape) {
		ob_start(); include __DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/templates/' . $shape . '/error.php';
		$fixture[$shape] = ob_get_clean();
	}
	$checkout->draft = $data;
	ob_start(); include __DIR__ . '/../wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php';
	$rejected_html = ob_get_clean();
	verify(str_contains($rejected_html, 'Revisa ' . count($notices) . (count($notices) === 1 ? ' campo' : ' campos')), 'no-JS summary singular/plural: ' . $case);
	foreach ($fixture['fields'] as $key) {
		verify(str_contains($rejected_html, 'href="#' . ('billing_fp_dispatch' === $key ? $key . '_si' : $key) . '"'), 'no-JS error link: ' . $key);
		verify(str_contains($rejected_html, 'aria-describedby="fp-field-error-' . $key . '"') && str_contains($rejected_html, 'id="fp-field-error-' . $key . '"'), 'no-JS inline association: ' . $key);
	}
	verify(str_contains($rejected_html, 'aria-invalid="true"') && !str_contains($rejected_html, 'Puede que ya se haya guardado'), 'no-JS known validation, never uncertain: ' . $case);
	verify(substr_count($rejected_html, 'HIDDEN-ATTEMPT-INPUT') === 1, 'no-JS attempt still native: ' . $case);
	preg_match('/<textarea[^>]*id="billing_fp_address"[^>]*>/', $rejected_html, $address_input);
	verify(str_contains($address_input[0], 'aria-required="true"') === ('si' === $data['billing_fp_dispatch']), 'no-JS address required semantics follow dispatch: ' . $case);
	verify(str_contains($rejected_html, 'value="' . esc_attr($data['billing_company']) . '"'), 'no-JS keeps entered company: ' . $case);
	$validation_fixtures[$case] = $fixture;
}
foreach (array('billing_first_name','billing_company','billing_fp_rut','billing_fp_giro','billing_phone','billing_email','billing_fp_address') as $key) {
	$limit = 'billing_fp_address' === $key ? 800 : 240;
	$value = 'billing_email' === $key ? str_repeat('a', $limit - 16) . '@example.invalid' : str_repeat('a', $limit);
	$base = 'billing_fp_address' === $key ? array_replace($valid, array('billing_fp_dispatch'=>'si')) : $valid;
	verify(!$validator->validate(array_replace($base, array($key=>$value)))->has_errors(), 'existing byte limit accepts boundary: ' . $key);
	$errors = $validator->validate(array_replace($base, array($key=>'a' . $value)));
	verify(str_contains($errors->get_error_message($key), $billing[$key]['label'] . ': el texto es demasiado largo. Acórtalo'), 'overlength names field and correction: ' . $key);
}
verify(!$validator->validate(array_replace($valid, array('billing_fp_address'=>str_repeat('a', 801))))->has_errors(), 'no-dispatch ignores an unused overlong address draft rather than blocking on a hidden field');
verify(!$validator->validate(array_replace($valid, array('billing_fp_dispatch'=>'si', 'billing_fp_address'=>'Calle Prueba 123, Comuna Prueba, Región Prueba')))->has_errors(), 'manual complete destination accepted without new structured/geocoding rules');
verify(!$validator->validate($valid)->has_errors() && !$GLOBALS['fpw_checkout_field_errors'], 'correction/retry clears request-local errors');

if ('1' === getenv('FREEPLAST_TEST_VALIDATION_JSON')) { echo json_encode($validation_fixtures, JSON_UNESCAPED_UNICODE); }
elseif ('1' === getenv('FREEPLAST_TEST_FORM_HTML')) { echo $html; }
else { echo "checkout form: $checks PHP checks passed\n"; }
