<?php
/** Offline projection tests; Woo remains responsible for mutations/session. */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$filters = array();
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) { global $filters; $filters[$name][] = $callback; }
function register_activation_hook(...$args) {}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wc_get_cart_url() { return '/cotizacion/'; }
function wc_get_cart_remove_url($key) { return '/cotizacion/?remove_item=' . rawurlencode($key) . '&_wpnonce=woo-nonce'; }
class CardTestProduct {
    public function __construct(private string $name) {}
    public function get_name() { return $this->name; }
}
class CardTestCart {
    public array $lines = array();
    public function get_cart() { return $this->lines; }
}
$wc = (object) array('cart' => new CardTestCart());
function WC() { global $wc; return $wc; }
require __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php';
function item($id, $quantity, $variation_id = 0, $name = 'Caja <azul> "A"') {
    return array('product_id' => $id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'data' => new CardTestProduct($name));
}
$wc->cart->lines = array('key-11' => item(11, 3), 'key-22' => item(22, 2));
if (in_array('--fixture', $argv, true)) {
    echo json_encode(array('span.fpw-basket-count' => '<span class="fpw-basket-count">2</span>', 'div.fpw-card-selections' => fpw_card_selections_fragment()));
    exit;
}
$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
$a = fpw_card_selection(11, $wc->cart->lines);
$b = fpw_card_selection(22, $wc->cart->lines);
verify(str_contains($a, 'fp-added-pill__badge">3</span>'), 'A shows 3 units');
verify(str_contains($b, 'fp-added-pill__badge">2</span>'), 'B shows 2 units');
verify(fpw_cart_line_count() === 2, 'Header still counts distinct lines, not units');
verify(str_contains($a, 'data-cart_item_key="key-11"') && !str_contains($a, 'key-22'), 'Remove is bound only to A');
verify(str_contains($a, 'remove_from_cart_button') && str_contains($a, 'role="button"'), 'Use the native accessible Woo removal handler');
verify(str_contains($a, 'woocommerce-mini-cart-item'), 'Native removal can block its row while pending');
verify(str_contains($a, 'remove_item=key-11&amp;_wpnonce=woo-nonce'), 'Native signed URL survives as no-JS fallback');
verify(str_contains($a, 'Caja &lt;azul&gt; &quot;A&quot;') && !str_contains($a, '<azul>'), 'Product names are escaped in accessible labels');
verify(str_contains($a, '>Quitar</a>'), 'Visible explicit Quitar control');
verify(str_contains($a, 'aria-label="3 en cotización'), 'Accessible name starts with visible text');
$empty = fpw_card_selection(33, $wc->cart->lines);
verify(!str_contains($empty, '<a ') && str_contains($empty, 'data-product-id="33"'), 'Empty product keeps a render slot, without controls');
$wc->cart->lines['key-11']['quantity'] += 4;
verify(str_contains(fpw_card_selection(11, $wc->cart->lines), 'fp-added-pill__badge">7</span>'), 'Repeated add reads the new Woo quantity');
unset($wc->cart->lines['key-11']);
verify(!str_contains(fpw_card_selection(11, $wc->cart->lines), '<a '), 'Removed product has no controls');
verify(str_contains(fpw_card_selections_fragment(), 'data-product-id="22"') && !str_contains(fpw_card_selections_fragment(), 'data-product-id="11"'), 'Complete snapshot excludes the removed product');
$wc->cart->lines['variant'] = item(11, 8, 111);
verify(!str_contains(fpw_card_selection(11, $wc->cart->lines), '<a '), 'Variation units are not attributed to a simple product');
verify(str_contains(fpw_card_selection(111, $wc->cart->lines), 'fp-added-pill__badge">8</span>'), 'Variation keeps its own identity');
$wc->cart->lines['split'] = item(22, 5);
$split = fpw_card_selection(22, $wc->cart->lines);
verify(str_contains($split, 'fp-added-pill__badge">7</span>'), 'Split lines sum quantities for the same product');
verify(substr_count($split, 'remove_from_cart_button') === 2, 'Extension-created split lines keep each native removal key');
$wc->cart->lines = array();
$fragment = $filters['woocommerce_add_to_cart_fragments'][0](array('other' => 'retained'));
verify($fragment['other'] === 'retained', 'Other native fragments preserved');
verify($fragment['div.fpw-card-selections'] === '<div class="fpw-card-selections" data-fpw-cart-state="1" hidden></div>', 'Empty snapshot is explicit, not a missing payload');
$wc->cart = null;
verify(!str_contains(fpw_card_selections_fragment(), '<a '), 'Unavailable Woo cart renders an empty snapshot');
echo "card projection: $checks PHP checks passed\n";
