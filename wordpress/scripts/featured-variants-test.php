<?php
/** Offline, actual adapter helpers: featured render scoping and safe variation identity. */
function sanitize_title($value) { return strtolower(str_replace(' ', '-', $value)); }
function taxonomy_exists($name) { return $name === 'pa_color'; }
function get_term_by($field, $value, $tax) { return (object) array('name' => strtoupper($value)); }
function is_wp_error($value) { return false; }
function absint($value) { return abs((int) $value); }
function WC() { return null; }
$source = file_get_contents(__DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
foreach (array('fpw_render_featured_products', 'fpw_in_featured_products', 'fpw_featured_color_options', 'fpw_featured_variation_quote_enabled', 'fpw_variation_color_image') as $name) {
    if (!preg_match('/\nfunction ' . $name . '\(.*?\n\}/s', $source, $match)) { throw new RuntimeException('Missing helper: ' . $name); }
    eval($match[0]);
}
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function do_shortcode($value) {
    check(fpw_in_featured_products(), 'context active inside the exact shortcode');
    if (!empty($GLOBALS['throw_shortcode'])) { throw new RuntimeException('test'); }
    return $value;
}
check(!fpw_in_featured_products(), 'ordinary loops start outside context');
$rendered = fpw_render_featured_products(array());
check(str_contains($rendered, 'cache="false"'), 'featured list must bypass stale Woo shortcode IDs after a sealed catalog migration');
check(!fpw_in_featured_products(), 'context restored after render');
$GLOBALS['fpw_featured_products_context'] = 'outer';
fpw_render_featured_products(array());
check($GLOBALS['fpw_featured_products_context'] === 'outer', 'nested context restored exactly');
$GLOBALS['fpw_featured_products_context'] = false;
$GLOBALS['throw_shortcode'] = true;
try { fpw_render_featured_products(array()); } catch (RuntimeException $exception) {}
check(!fpw_in_featured_products(), 'exceptions cannot leak featured context');

class FeaturedProduct {
    public $attributes = array('Color' => array('Azul', 'Verde', 'Rojo'));
    public $variations = array();
    public function is_type($type) { return $type === 'variable'; }
    public function get_variation_attributes() { return $this->attributes; }
    public function get_available_variations() { return $this->variations; }
}
$p = new FeaturedProduct();
$v = array('variation_id'=>101, 'attributes'=>array('attribute_color'=>'Azul'), 'variation_is_active'=>true, 'is_purchasable'=>true, 'is_in_stock'=>true, 'max_qty'=>20, 'is_sold_individually'=>'no', 'display_price'=>999);
$p->variations = array($v);
$options = fpw_featured_color_options($p);
check($options[0] === array('label'=>'Azul', 'id'=>101, 'max'=>20, 'individual'=>false, 'image'=>null), 'identity/limits from Woo without prices');
check($options[1]['id'] === 0 && $options[2]['id'] === 0, 'unavailable real colors disabled');
foreach (array('variation_is_active', 'is_purchasable', 'is_in_stock') as $flag) {
    $p->variations[0][$flag] = false;
    check(fpw_featured_color_options($p)[0]['id'] === 0, $flag . ' remains authoritative');
    $p->variations[0][$flag] = true;
}
$photo = array('src'=>'/blue.jpg', 'srcset'=>'/blue-2.jpg 2x', 'alt'=>'Caja azul');
$own = new class { public $id = 55; public function get_image_id($context) { check($context === 'edit', 'raw image ID avoids Woo parent inheritance'); return $this->id; } };
$data = fpw_variation_color_image(array('image'=>$photo), $p, $own);
check($data['fpw_color_image'] === $photo, 'explicit variation image preserved');
$p->variations = array(array_merge($v, $data));
check(fpw_featured_color_options($p)[0]['image'] === $photo, 'card receives image without price fields');
$own->id = 0;
check(fpw_variation_color_image(array('image'=>$photo), $p, $own)['fpw_color_image'] === null, 'inherited parent image not a color photo');
$own->id = 55;
check(fpw_variation_color_image(array('image'=>false), $p, $own)['fpw_color_image'] === null, 'missing attachment falls back honestly');
$p->variations = array($v, $v);
check(fpw_featured_color_options($p) === array(), 'ambiguous duplicate color falls back');
$p->variations = array(array_replace($v, array('attributes'=>array('attribute_color'=>''))));
check(fpw_featured_color_options($p) === array(), 'wildcard color falls back');
$p->attributes['Tamaño'] = array('Grande');
check(fpw_featured_color_options($p) === array(), 'multi-attribute products fall back');
$p->attributes = array('pa_color'=>array('azul'));
$p->variations = array(array_replace($v, array('attributes'=>array('attribute_pa_color'=>'azul'))));
check(fpw_featured_color_options($p)[0]['label'] === 'AZUL', 'taxonomy term provides visible color name');
function wp_doing_ajax() { return $GLOBALS['test_ajax'] ?? true; }
function wc_get_product($id) {
    return $id === 101 ? new class {
        public function is_type($type) { return $type === 'variation'; }
        public function get_parent_id() { return 99; }
    } : null;
}
function product_quote_enabled($id, $qty = 1) { $GLOBALS['quote_check'] = array($id, $qty); return $GLOBALS['parent_quotable'] ?? true; }
$_GET['wc-ajax'] = 'add_to_cart';
$_POST = array('fpw_featured_variant'=>'1', 'product_id'=>'101');
check(fpw_featured_variation_quote_enabled(false, 101, 7) === true, 'featured native variation inherits parent quote mode');
check($GLOBALS['quote_check'] === array(99, 7), 'same quantity and parent rules used, no validation bypass');
$GLOBALS['parent_quotable'] = false;
check(fpw_featured_variation_quote_enabled(true, 101, 7) === false, 'non-quotable parent never forced quotable');
$GLOBALS['parent_quotable'] = true;
unset($_POST['fpw_featured_variant']);
check(fpw_featured_variation_quote_enabled(false, 101, 7) === false, 'unmarked shop/sheet operations unchanged');
$_POST['fpw_featured_variant'] = '1';
check(fpw_featured_variation_quote_enabled(false, 99, 7) === false, 'parent checks cannot recurse');
$_GET['wc-ajax'] = 'other';
check(fpw_featured_variation_quote_enabled(false, 101, 7) === false, 'other endpoints unchanged');
echo "featured variants PHP: context, restoration, exact identity, constraints, QWC parent rules and fallbacks passed\n";
