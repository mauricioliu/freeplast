<?php
/** Offline render tests for the A · Directa confirmation (issue #47).
 * themes/freeplast/woocommerce/checkout/thankyou.php renders against a
 * stubbed STORED order: the reference, lines/options/quantities and dispatch
 * facts must come from the persisted record — never from any cart, demo
 * reference or timer. */
define('ABSPATH', __DIR__);
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function number_format_i18n($number) { return number_format((float) $number, 0, ',', '.'); }
function wc_get_page_permalink($page) { return '/tienda/'; }
function wp_kses_post($value) { return $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function make_clickable($value) { return $value; }
function apply_filters($tag, $value, ...$args) { return $value; }
// Execute the pinned native formatter, not an invented display helper.
$native = file_get_contents(__DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/includes/wc-template-functions.php');
if (!preg_match('/\tfunction wc_display_item_meta\\(.*?\\n\t\\}/s', $native, $match)) { throw new RuntimeException('Pinned native item-meta formatter missing'); }
eval($match[0]);

class FakeMeta {
	public function __construct(private array $data) {}
	public function get_data() { return $this->data; }
}
class FakeItem {
	public function __construct(private string $name, private int $qty, private array $meta = array()) {}
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
	public function get_formatted_meta_data() {
		return array_map(static fn($row) => (object) array('display_key' => 'pa_color' === $row['key'] ? 'Color' : $row['key'], 'display_value' => $row['value']), array_filter($this->meta, static fn($row) => !str_starts_with($row['key'], '_') && is_scalar($row['value'])));
	}
}
class FakeOrder {
	public function __construct(private string $number, private array $items, private array $details) {}
	public function get_order_number() { return $this->number; }
	public function get_items() { return $this->items; }
	public function get_meta($key) { return '_fp_submitted_details' === $key ? $this->details : ''; }
}

function render_confirmation($order) {
	ob_start();
	include __DIR__ . '/../wp-content/themes/freeplast/woocommerce/checkout/thankyou.php';
	return (string) ob_get_clean();
}

$checks = 0;
function verify($condition, $message) {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}

$order = new FakeOrder('FP-2026-000042', array(
	new FakeItem('Caja Cosechera 3/4', 70),
	new FakeItem('Caja Universal Cerrada Color', 25, array(array('key' => 'Color', 'value' => 'Azul'), array('key' => '_reduced_stock', 'value' => '1'))),
), array('billing_fp_dispatch' => 'si', 'billing_fp_address' => 'Camino de prueba 123, Mostazal', 'billing_first_name' => 'Pilar'));

$html = render_confirmation($order);
verify(str_contains($html, 'Solicitud recibida.'), 'the A hero headline');
verify(str_contains($html, 'Gracias, Pilar.'), 'the greeting uses the submitted name');
verify(str_contains($html, 'FP-2026-000042</strong>'), 'the REAL stored Request Reference renders');
verify(!str_contains($html, 'DEMO'), 'no demo reference');
verify(!str_contains($html, 'FP-2026-000001'), 'no invented reference');
verify(str_contains($html, 'step-number" aria-hidden="true">3<') && str_contains($html, 'aria-current="step"'), 'step 3 is current');
verify(str_contains($html, 'Ventas revisa tu solicitud.'), 'the sales-review next step');
verify(str_contains($html, 'Revisará los productos, cantidades y despacho solicitados.'), 'the review step describes work without repeating commercial terms');
verify(substr_count($html, 'Ventas confirmará precios, disponibilidad y condiciones.') === 1, 'commercial explanation appears once');
verify(!preg_match('/enviamos un correo|email enviado|te enviamos/i', $html), 'no delivery claim for merely persisting');
verify(substr_count($html, 'Esta solicitud no es una compra ni reserva stock.') === 1, 'one no-purchase/no-reservation guard stays');
verify(str_contains($html, '2</strong><span>productos distintos') && str_contains($html, '95</strong><span>unidades en total'), 'stored lines and units are the summary truth');
verify(str_contains($html, 'Caja Cosechera 3/4</span><b>× 70</b>'), 'stored simple line with quantity');
verify(str_contains($html, 'Caja Universal Cerrada Color · Color: Azul</span><b>× 25</b>'), 'the chosen color persists with the stored line');
verify(!str_contains($html, '_reduced_stock'), 'internal meta never reaches the customer');
verify(str_contains($html, 'Despacho solicitado: Camino de prueba 123, Mostazal.'), 'the stored dispatch destination is summarized');
verify(str_contains($html, 'href="/tienda/"') && str_contains($html, 'Volver al catálogo'), 'the catalog return');
verify(!str_contains($html, 'data-fp-dialog'), 'no invented confirmation action substitutes for demo controls; shared chrome still owns help');
verify(!str_contains($html, '$'), 'no currency amounts on the confirmation');
verify(str_contains($html, 'wp-block-woocommerce') === false, 'no block-checkout surface');

/* Without dispatch: the stored record decides, not a session cart. */
$noDispatch = new FakeOrder('FP-2026-000043', array(new FakeItem('Tote', 1)), array('billing_fp_dispatch' => 'no', 'billing_fp_address' => 'Dirección vieja', 'billing_first_name' => ''));
$no = render_confirmation($noDispatch);
verify(str_contains($no, 'Solicitud sin despacho.'), 'a stored no-dispatch omits the destination');
verify(!str_contains($no, 'Dirección vieja'), 'a previously typed address is not persisted as a destination');
verify(str_contains($no, '1</strong><span>unidad en total') && str_contains($no, '1</strong><span>producto distinto'), 'singular copy follows the stored record');
verify(!str_contains($no, 'Gracias, .'), 'an empty name greets without a dangling comma');

/* No order context: honest minimal state. */
$none = render_confirmation(null);
verify(!str_contains($none, 'Solicitud recibida.') && !str_contains($none, 'Ventas se pondrá en contacto') && str_contains($none, 'No pudimos verificar'), 'invalid/absent native order context MUST NOT assert a received request');
verify(!str_contains($none, 'Referencia'), 'no reference is invented without a record');

$unknown = render_confirmation(new FakeOrder('FP-2026-000044', array(new FakeItem('Caja', 1, array(array('key'=>'pa_color','value'=>'Azul'), array('key'=>'Internal object','value'=>array('a'=>'b'))))), array()));
verify(str_contains($unknown, 'Despacho por confirmar') && !str_contains($unknown, 'Solicitud sin despacho'), 'missing historical dispatch is unknown, not invented no-dispatch');
verify(str_contains($unknown, 'Color: Azul') && !str_contains($unknown, 'pa_color') && !str_contains($unknown, 'Internal object'), 'native formatted metadata supplies readable labels and omits nonscalar data');
echo "confirmation: $checks PHP checks passed\n";
