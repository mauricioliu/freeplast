<?php
/** Offline synthetic Woo/CLI contract: never opens a database. */
define('WP_CLI', true);
$root = __DIR__ . '/../.build/selection-test-' . getmypid();
mkdir($root, 0700, true); mkdir($root . '/wp', 0700);
define('ABSPATH', $root . '/wp/');
class WooCommerce {}
class WP_CLI { static function halt($code) { throw new RuntimeException('halt:' . $code); } }
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class SelectionProduct {
    public array $data; public int $saves = 0;
    function __construct($id, $slug, $type = 'simple') {
        $this->data = array('id'=>$id, 'slug'=>$slug, 'sku'=>'fp-' . $slug, 'name'=>$slug, 'status'=>'publish', 'type'=>$type, 'featured'=>true, 'menu_order'=>42, 'category_ids'=>array(77), 'catalog_visibility'=>'visible', 'price'=>'123', 'image_id'=>789, 'upsell_ids'=>array(91), 'description'=>'merchant copy', 'attributes'=>array(), 'meta_data'=>array('_fp_source_id'=>'fp-' . $slug), 'children'=>array());
    }
    function __call($method, $args) {
        if (str_starts_with($method, 'get_')) { return $this->data[substr($method, 4)]; }
        if (str_starts_with($method, 'set_')) { $this->data[substr($method, 4)] = $args[0]; return; }
        throw new RuntimeException('Unexpected method');
    }
    function get_data() { return $this->data; }
    function get_meta($key, ...$args) { return $this->data['meta_data'][$key] ?? ''; }
    function is_type($type) { return $this->data['type'] === $type; }
    function save() { ++$this->saves; }
}
$options = array('home'=>'http://synthetic.test', 'siteurl'=>'http://synthetic.test');
$environment = 'local'; $products = array();
function get_option($key) { return $GLOBALS['options'][$key] ?? false; }
function add_option($key, $value, ...$args) { if (get_option($key)) { return false; } $GLOBALS['options'][$key]=$value; return true; }
function get_term_by(...$args) { return !empty($GLOBALS['hide_agricola']) ? null : (object)array('term_id'=>12); }
function is_wp_error($value) { return false; }
function get_posts($args) { return array_keys(array_filter($GLOBALS['products'], static fn($p)=>!$p->is_type('variation'))); }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function clean_post_cache($id) {}
function wc_delete_product_transients($id) {}
function wp_cache_delete(...$args) {}
class SelectionDB {
    public $posts='posts', $postmeta='postmeta', $term_relationships='terms';
    function prepare($sql, ...$args) { return $sql; }
    function get_var($sql) { return 1; }
    function get_col($sql) { return array('InnoDB'); }
    function query($sql) { return 1; }
}
$wpdb = new SelectionDB();
require_once __DIR__ . '/lib/catalog-selection.php';
function run_selection($arguments): string {
    $args=$arguments;
    ob_start();
    try { include __DIR__ . '/update-catalog-selection.php'; }
    catch (RuntimeException $error) { if (!str_starts_with($error->getMessage(), 'halt:')) { ob_end_clean(); throw $error; } }
    return ob_get_clean();
}
try {
    $slugs = array_merge(fp_selection_featured(), array('tote', 'bases-plasticas-para-pediluvios', 'merchant-new-product'));
    foreach ($slugs as $i=>$slug) { $products[$i+1] = new SelectionProduct($i+1, $slug); }
    $products[1]->data['children'] = array(99);
    $products[99] = new SelectionProduct(99, 'color-child', 'variation');
    $products[7]->data['category_ids'] = array(77, 55); // pollera: owner multi-category membership plan must preserve
    $products[4]->data['category_ids'] = array(77, 88); // traversa G1: selection owns the assignment
    $products[100] = new SelectionProduct(100, 'archived-merchant-product');
    $products[100]->data['status'] = 'private'; $products[100]->data['catalog_visibility'] = 'hidden';
    $original = array_map(static fn($p)=>$p->get_data(), $products);
    check(str_contains(run_selection(array()), 'default: no writes'), 'help is safe');
    check(trim(run_selection(array('--version'))) === '1.0.0', 'version fast path');
    check(str_contains(run_selection(array('bad')), 'incorrect arguments'), 'unknown commands rejected');
    $environment='production';
    check(str_contains(run_selection(array('plan', $root . '/blocked.json')), 'disposable'), 'production rejected');
    $environment='local'; putenv('FREEPLAST_DISPOSABLE_CATALOG=1');
    $GLOBALS['hide_agricola'] = true;
    try { fp_selection_plan(); throw new RuntimeException('missing category accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Existing Agrícola category required'), 'plan refuses when the section category is missing'); }
    unset($GLOBALS['hide_agricola']);
    check(array_sum(array_column($products, 'saves'))===0, 'refused plan performs no writes');
    $rows=fp_selection_plan();
    check(count($rows)===13, 'full product inventory including merchant and archived additions');
    $products[1]->data['sku']='wrong';
    try { fp_selection_plan(); throw new RuntimeException('identity accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'identity:'), 'identity drift rejected'); }
    $products[1]->data['sku']=$original[1]['sku'];
    run_selection(array('plan', $root . '/receipt.json'));
    $receipt=json_decode(file_get_contents($root . '/receipt.json'), true);
    check(array_sum(array_column($products, 'saves'))===0, 'plan performs no writes');
    check(str_contains(run_selection(array('apply', $root . '/receipt.json', $receipt['token'])), 'Maintenance'), 'apply requires maintenance/backup/writers');
    touch(ABSPATH . '.maintenance'); putenv('FREEPLAST_CATALOG_BACKUP_VERIFIED=1'); putenv('FREEPLAST_CATALOG_WRITERS_PAUSED=1');
    $products[1]->data['price']='999';
    $drift = run_selection(array('apply', $root . '/receipt.json', $receipt['token']));
    check(str_contains($drift, 'altered plan'), 'merchant drift rejected before writes: ' . $drift);
    $products[1]->data['price']=$original[1]['price'];
    check(str_contains(run_selection(array('apply', $root . '/receipt.json', $receipt['token'])), 'status: applied;'), 'reviewed migration applies');
    fp_selection_verify($rows);
    $selected=array_filter($products, static fn($p)=>!$p->is_type('variation') && $p->get_featured());
    uasort($selected, static fn($a,$b)=>$a->get_menu_order()<=>$b->get_menu_order());
    check(array_values(array_map(static fn($p)=>$p->get_slug(), $selected))===fp_selection_featured(), 'exact nine in approved order; traversas consecutive');
    foreach (array(4,5,6,10) as $id) { check($products[$id]->get_category_ids()===array(12), 'traversas and tote Agricola'); }
    check($products[7]->get_category_ids()===array(77,55), 'multi-category membership survives the selection untouched');
    check($products[4]->get_category_ids()===array(12) && !in_array(88,$products[4]->get_category_ids(),true), 'selection-owned assignment replaces cleanly');
    check($products[100]->get_status()==='private' && $products[100]->get_catalog_visibility()==='hidden', 'archived product stays archived');
    check($products[6]->get_name()==='Traversa para Bins Tipo G2' && $products[6]->get_slug()===$original[6]['slug'] && $products[6]->get_id()===6, 'G2 preserves link and ID');
    check($products[11]->get_status()==='draft' && $products[11]->get_catalog_visibility()==='hidden', 'pediluvios natively unpublished and hidden from catalog/search');
    check(count($products)===14 && $products[99]->get_data()===$original[99] && $products[100]->get_id()===100, 'archive deletes nothing and preserves variations');
    $saves=array_sum(array_column($products, 'saves'));
    $products[6]->data['name']='later merchant name'; $products[1]->data['featured']=false;
    check(str_contains(run_selection(array('apply', $root . '/receipt.json', $receipt['token'])), 'already applied'), 'repeat is no-op');
    check($products[6]->get_name()==='later merchant name' && !$products[1]->get_featured() && $saves===array_sum(array_column($products, 'saves')), 'repeat never resets daily Woo edits');
    echo "PASS catalog selection: guards, nine featured, grouping, stable identity, archive, protected data, merchant edits, idempotence\n";
} finally {
    foreach (glob($root . '/*.json') as $file) { unlink($file); }
    if (is_file(ABSPATH . '.maintenance')) { unlink(ABSPATH . '.maintenance'); }
    rmdir(ABSPATH); rmdir($root);
}
