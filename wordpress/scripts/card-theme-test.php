<?php
/** Offline render tests for the A · Directa loop card (issue #42).
 * themes/freeplast/woocommerce/content-product.php is rendered against a
 * stubbed Woo product: structure, native destinations, essential-fact
 * fallback chain, pending-photo honesty and the variable card's single
 * Elegir color action. Woo stays responsible for the query, the anchor
 * contract (adapter filter) and the projection slot contract. */
define('ABSPATH', __DIR__);
$filters = array();
$actions = array();
function add_action($hook, $callback, $priority = 10) { $GLOBALS['actions'][$hook][$priority][$callback] = $callback; }
function has_action($hook, $callback) { foreach ($GLOBALS['actions'][$hook] ?? array() as $p => $callbacks) { if (isset($callbacks[$callback])) { return $p; } } return false; }
function remove_action($hook, $callback, $priority = 10) { unset($GLOBALS['actions'][$hook][$priority][$callback]); }
function add_filter($name, $callback, ...$args) { global $filters; $filters[$name][] = $callback; }
function do_action($hook) { $callbacks = $GLOBALS['actions'][$hook] ?? array(); ksort($callbacks); foreach ($callbacks as $group) { foreach ($group as $callback) { $callback(); } } }
// Defaults from pinned WC11.1 wc-template-hooks.php: a no-op do_action hides duplicates.
add_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
add_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5);
add_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
function woocommerce_template_loop_product_link_open() { echo 'UNWANTED-WHOLE-CARD-LINK'; }
function woocommerce_template_loop_product_link_close() { echo 'UNWANTED-WHOLE-CARD-CLOSE'; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) remove_accents_proxy($value))), '-'); }
function remove_accents_proxy($value) { $map = array('á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'); return strtr($value, $map); }
function wc_product_class($class, $product) { echo 'class="product type-product ' . esc_attr($class) . '" data-id="' . esc_attr($product->get_id()) . '"'; }
function wp_get_post_terms($id, $tax, $args) { return array('Agrícola'); }
function woocommerce_template_loop_add_to_cart() { echo 'ADAPTER-LOOP-ADD-MARKER'; }

class CardThemeProduct {
    public function __construct(private array $data) {}
    public function __call($name, $args) {
        $key = 'get_' === substr($name, 0, 4) ? lcfirst(substr($name, 4)) : $name;
        return $this->data[$key] ?? null;
    }
    public function is_visible() { return true; }
    public function is_type($type) { return ($this->data['type'] ?? 'simple') === $type; }
    public function is_purchasable() { return $this->data['purchasable'] ?? true; }
    public function is_in_stock() { return $this->data['in_stock'] ?? true; }
    public function get_attribute($name) { return $this->data['attribute_map'][$name] ?? ''; }
}

function render_card($data) {
    global $product;
    $product = new CardThemeProduct($data);
    ob_start();
    include __DIR__ . '/../wp-content/themes/freeplast/woocommerce/content-product.php';
    return (string) ob_get_clean();
}

$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}

/* Simple card, photo present, full fact chain. */
$simple = render_card(array(
    'id' => 11,
    'type' => 'simple',
    'name' => 'Caja Cosechera 3/4',
    'permalink' => '/producto/caja-cosechera-3-4/',
    'image_id' => 55,
    'attribute_map' => array(),
));
verify(str_contains($simple, 'class="product type-product product-card"'), 'the card renders on Woo\'s own li class surface');
verify(substr_count($simple, '<li') === 1 && str_starts_with(trim($simple), '<li'), 'the override renders one li');
verify(str_contains($simple, 'class="photo-link" href="/producto/caja-cosechera-3-4/"'), 'the photo links the native product destination');
verify(str_contains($simple, 'class="product-photo"'), 'A photo surface');
verify(str_contains($simple, 'class="product-category">Agrícola'), 'the catalog category kicker');
verify(str_contains($simple, 'woocommerce-loop-product__title"><a href="/producto/caja-cosechera-3-4/">Caja Cosechera 3/4</a>'), 'the title links the native destination and keeps Woo\'s title class');
verify(str_contains($simple, 'Ficha técnica por confirmar'), 'no supported fact renders the neutral pending copy, never an invented one');
verify(substr_count($simple, 'ADAPTER-LOOP-ADD-MARKER') === 1, 'the simple card composes controls through the adapter-filtered native template');
verify(!str_contains($simple, 'Elegir color'), 'a simple product never offers a color action');
verify(!str_contains($simple, 'price'), 'no price surface on the card');

/* Spec chain: Medidas first, with the reference's "(exteriores)" normalization. */
$withSpecs = render_card(array(
    'id' => 12, 'type' => 'simple', 'name' => 'Universal', 'permalink' => '/p/', 'image_id' => 1,
    'attribute_map' => array('Medidas' => '625 x 445 x 226 mm (exteriores)', 'Material' => 'PEAD', 'Peso propio' => '1.900 g'),
));
verify(str_contains($withSpecs, 'product-spec">625 x 445 x 226 mm</p>'), 'measures lead the essential fact and drop the exteriores suffix');
$specMaterial = render_card(array('id' => 13, 'type' => 'simple', 'name' => 'X', 'permalink' => '/p/', 'image_id' => 1, 'attribute_map' => array('Material' => 'PEAD reciclado')));
verify(str_contains($specMaterial, 'product-spec">PEAD reciclado'), 'material is the fallback fact when measures are absent');
$specPending = render_card(array('id' => 14, 'type' => 'simple', 'name' => 'X', 'permalink' => '/p/', 'image_id' => 1, 'attribute_map' => array('Medidas' => 'Consultar')));
verify(str_contains($specPending, 'Ficha técnica por confirmar'), 'a Consultar fact is treated as unconfirmed, not published');

/* Pending photograph is explicit, never a fabricated image. */
$noPhoto = render_card(array('id' => 15, 'type' => 'simple', 'name' => 'PaLtera', 'permalink' => '/p/', 'image_id' => 0));
verify(str_contains($noPhoto, 'class="placeholder"') && str_contains($noPhoto, 'Foto pendiente'), 'a missing photograph renders the honest pending placeholder');
verify(!str_contains($noPhoto, '<img'), 'no image element is invented for a photo-less product');

/* Variable card: Elegir color only, projection slot present, no card picker. */
$variable = render_card(array(
    'id' => 21,
    'type' => 'variable',
    'name' => 'Caja Universal Cerrada Color <negro>',
    'permalink' => '/producto/caja-universal-cerrada-color/',
    'image_id' => 66,
    'attribute_map' => array(),
    'variation_attributes' => array('Color' => array('Blanco', 'Rojo', 'Amarillo', 'Azul', 'Verde')),
));
verify(str_contains($variable, 'class="button secondary" href="/producto/caja-universal-cerrada-color/"') && str_contains($variable, 'Elegir color'), 'the variable card\'s single action links the native product page');
verify(str_contains($variable, '5 colores · sujetos a disponibilidad'), 'the color hint counts the named colors');
verify(str_contains($variable, 'data-fpw-loop-add') && str_contains($variable, 'class="fpw-card-selection" data-product-id="21"'), 'the variable card carries the projection slot for its added state');
verify(!str_contains($variable, 'add_to_cart_button') && !str_contains($variable, 'ADAPTER-LOOP-ADD-MARKER'), 'no direct add path bypasses the variation form');
verify(!str_contains($variable, '<select'), 'no card-level color picker is introduced');
verify(str_contains($variable, 'Caja Universal Cerrada Color &lt;negro&gt;'), 'names are escaped');

/* Long names wrap instead of overflowing. */
$long = render_card(array('id' => 22, 'type' => 'simple', 'name' => 'Bases Plásticas para Pediluvios con Nombre Larguísimo de Prueba', 'permalink' => '/p/', 'image_id' => 2));
verify(str_contains($long, 'Bases Plásticas para Pediluvios con Nombre Larguísimo de Prueba'), 'long names render whole');

verify(has_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart') === 10, 'native add hook restored for subsequent components');
verify(has_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open') === 10, 'native link hook restored after the card');
verify(!str_contains($simple, 'UNWANTED-WHOLE-CARD'), 'one native add surface without invalid whole-card nesting');
echo "card theme: $checks PHP checks passed\n";
