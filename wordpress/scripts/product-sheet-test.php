<?php
/** Offline render tests for the A · Directa product sheet (issue #44).
 * themes/freeplast/woocommerce/content-single-product.php renders against a
 * stubbed Woo product: breadcrumb/heading/gallery/summary hierarchy, honest
 * pending photos and unconfirmed facts, the neutral pending-description
 * rule, the added-state projection and the technical disclosure. Woo's own
 * form, variation machinery and related query stay native (rendered through
 * the marker stubs). */
define('ABSPATH', __DIR__);
$filters = array();
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) { global $filters; $filters[$name][] = $callback; }
function remove_action(...$args) {}
function do_action(...$args) {}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wpautop($text) { return '<p>' . $text . '</p>'; }
function wp_kses_post($text) { return $text; }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function number_format_i18n($number) { return number_format((float) $number, 0, ',', '.'); }
function get_search_query() { return ''; }
function is_shop() { return false; }
function is_product_taxonomy() { return false; }
function get_queried_object() { return (object) array('slug' => ''); }
function is_wp_error($value) { return false; }
function wc_get_cart_url() { return '/cotizacion/'; }
function the_ID() { echo 21; }
function the_title() { echo 'Caja Universal Cerrada Color'; }
function wc_product_class($class, $product) { echo 'class="product type-product ' . esc_attr($class) . '"'; }
function wp_get_post_terms($id, $tax, $args) { return array('Otros'); }
function get_post_meta($id, $key, $single) { return $id === 999 ? 'Imagen referencial pendiente para un producto' : ''; }
function wp_get_attachment_caption($id) { return $GLOBALS['photo_captions'][$id] ?? ''; }
function post_password_required() { return $GLOBALS['protected_product'] ?? false; }
function get_the_password_form() { return 'NATIVE-PASSWORD-FORM'; }
function woocommerce_breadcrumb() { echo 'BREADCRUMB-MARKUP'; }
function woocommerce_show_product_images() { echo 'GALLERY-MARKUP'; }
function woocommerce_template_single_add_to_cart() { echo 'ADD-TO-CART-FORM'; }
function woocommerce_output_related_products() { echo 'RELATED-BLOCK'; }
class FakeTerm { public function __construct(public string $slug, public int $count) {} }
function get_terms($args) { return array(new FakeTerm('agricola', 12), new FakeTerm('otros', 5)); }
function WC() {
    global $wc_stub;
    return $wc_stub;
}
class SheetCart {
    public array $lines = array();
    public function get_cart() { return $this->lines; }
}
class SheetProduct {
    public function __construct(private array $data) {}
    public function __call($name, $args) {
        $key = 'get_' === substr($name, 0, 4) ? lcfirst(substr($name, 4)) : $name;
        return $this->data[$key] ?? null;
    }
    public function is_visible() { return true; }
    public function get_attribute($name) { return $this->data['attribute_map'][$name] ?? ''; }
}
require __DIR__ . '/../wp-content/themes/freeplast/functions.php';

function render_sheet($data) {
    global $product;
    $product = new SheetProduct($data);
    ob_start();
    include __DIR__ . '/../wp-content/themes/freeplast/woocommerce/content-single-product.php';
    return (string) ob_get_clean();
}

$wc_stub = (object) array('cart' => new SheetCart());
$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}

$base = array(
    'id' => 21,
    'name' => 'Caja Universal Cerrada Color',
    'image_id' => 66,
    'description' => 'Caja resistente para múltiples usos.',
    'short_description' => '',
    'attribute_map' => array(
        'Material' => 'PEAD',
        'Medidas' => '625 x 445 x 226 mm',
        'Peso propio' => 'Consultar',
        'Uso' => 'Transporte de carne',
        'Unidades por pallet' => '48',
    ),
);

$sheet = render_sheet($base);
verify(str_contains($sheet, 'class="product type-product fp-single-product"'), 'the sheet renders on Woo\'s own product div');
verify(str_contains($sheet, 'BREADCRUMB-MARKUP'), 'the native breadcrumb composes the route');
verify(str_contains($sheet, 'Otros · Freeplast</p>'), 'the heading carries the category eyebrow');
verify(str_contains($sheet, 'product_title">Caja Universal Cerrada Color'), 'the reference title hierarchy');
verify(str_contains($sheet, 'GALLERY-MARKUP'), 'the native gallery renders in A\'s image surface');
verify(str_contains($sheet, 'Fotografía referencial. Puede no representar el color o la configuración seleccionados.'), 'a present photo is labeled referential');
verify(str_contains($sheet, 'Detalles que importan.'), 'the summary title');
verify(str_contains($sheet, 'Caja resistente'), 'the description renders');
verify(substr_count($sheet, 'Por confirmar') >= 1, 'an unconfirmed weight renders Por confirmar, not the Consultar marker');
verify(substr_count($sheet, '<div><dt>Material</dt><dd>PEAD</dd>') === 1, 'the quick spec grid renders native attributes');
verify(str_contains($sheet, 'ADD-TO-CART-FORM'), 'the native add-to-cart form composes the options block');
verify(str_contains($sheet, 'Sin compra ni reserva de stock.'), 'the reference no-purchase note');
verify(str_contains($sheet, 'data-fpw-detail-added') && str_contains($sheet, ' hidden'), 'the added state renders hidden with an empty selection');
verify(str_contains($sheet, 'Ficha técnica completa'), 'the technical disclosure');
verify(str_contains($sheet, 'Unidades por pallet'), 'packaging data stays available in the disclosure');
verify(str_contains($sheet, 'no una cantidad mínima de solicitud'), 'pallet size is never framed as a commercial minimum');
verify(str_contains($sheet, 'RELATED-BLOCK'), 'related products render through the native block');
verify(!str_contains($sheet, 'price') && !str_contains($sheet, 'clp'), 'no price surface on the sheet');

/* Pending photo honesty. */
$noPhoto = render_card_helper(array_merge($base, array('image_id' => 0)));
verify(str_contains($noPhoto, 'Fotografía pendiente') && str_contains($noPhoto, 'La fotografía de este producto está por confirmar.'), 'a missing photo renders the honest pending state');
verify(!str_contains($noPhoto, 'GALLERY-MARKUP'), 'no gallery is invented without an image');
$GLOBALS['photo_captions'][66] = 'Fotografía del catálogo 2026 en rojo. Los demás colores no se muestran en esta imagen.';
$captioned = render_card_helper($base);
verify(str_contains($captioned, $GLOBALS['photo_captions'][66]), 'the selected native attachment supplies its honest color caption');
$GLOBALS['photo_captions'][66] = '<b>Foto</b> & referencia';
verify(str_contains(render_card_helper($base), 'Foto &amp; referencia'), 'native captions are stripped and escaped at the presentation boundary');
verify(str_contains(render_card_helper(array_merge($base, array('image_id'=>67))), 'Fotografía referencial. Puede no representar'), 'a replacement attachment never inherits the prior PDF caption');
unset($GLOBALS['photo_captions']);

/* Internal review prose is never presented as a confirmed fact. */
$pendingDesc = render_card_helper(array_merge($base, array('description' => 'Los colores están sujetos a confirmación del cliente, al igual que sus especificaciones.')));
verify(str_contains($pendingDesc, 'pendientes de confirmación. Puedes incluirlo en tu solicitud'), 'review-dependent descriptions render the neutral pending copy');
verify(!str_contains($pendingDesc, 'sujetos a confirmación del cliente'), 'the internal review note itself is not published');

/* No confirmed facts at all: neutral notice instead of an empty grid. */
$noFacts = render_card_helper(array_merge($base, array('attribute_map' => array('Material' => 'Consultar'))));
verify(!str_contains($noFacts, 'fp-spec-grid'), 'no spec grid without any confirmed fact');
verify(str_contains($noFacts, 'Las especificaciones técnicas están por confirmar con ventas.'), 'the neutral unconfirmed notice');

/* Added-state projection: this product's own units, native cart as source. */
$wc_stub->cart->lines = array(
    'a' => array('product_id' => 21, 'variation_id' => 211, 'quantity' => 25),
    'b' => array('product_id' => 22, 'variation_id' => 0, 'quantity' => 70),
);
$added = fp_theme_detail_added(21);
verify(!str_contains($added, ' hidden') && !str_contains($added, 'hidden>'), 'a live selection unhides the added state');
verify(str_contains($added, '25 unidades de este producto en tu selección.'), 'the state aggregates this product\'s units across colors');
verify(str_contains($added, 'href="/cotizacion/"') && str_contains($added, 'Revisar Productos a Cotizar'), 'the state links the native selection page');
$singleUnit = fp_theme_detail_added(22);
verify(str_contains($singleUnit, '70 unidades de este producto'), 'the projection reads the native quantities');
verify(str_contains(fp_theme_detail_added(99), ' hidden'), 'an unrelated product renders the hidden state');

function render_card_helper($data) {
    return render_sheet($data);
}

$excerpt = render_card_helper(array_merge($base, array('short_description' => 'Resumen breve', 'description' => 'Descripción extensa distinta')));
verify(str_contains($excerpt, 'Resumen breve') && !str_contains($excerpt, 'Descripción extensa distinta'), 'A description uses the native short excerpt before the long description');
$placeholder = render_card_helper(array_merge($base, array('image_id' => 999)));
verify(str_contains($placeholder, 'Fotografía pendiente') && !str_contains($placeholder, 'GALLERY-MARKUP'), 'migrated placeholder attachment remains explicitly pending, not a reference photograph');
$GLOBALS['protected_product'] = true;
$protected = render_card_helper($base);
verify($protected === 'NATIVE-PASSWORD-FORM', 'protected product emits the native password form, no private details or controls');
$GLOBALS['protected_product'] = false;
echo "product sheet: $checks PHP checks passed\n";
