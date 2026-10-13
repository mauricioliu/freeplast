<?php
/** Offline native-boundary fakes: no WP bootstrap, database, network or listener. */
define('WP_CLI', true);
define('ABSPATH', sys_get_temp_dir() . '/freeplast-pdf-test-' . getmypid() . '/');
mkdir(ABSPATH, 0700);
class Halt extends RuntimeException {}
class WP_CLI { static function halt($code) { throw new Halt('halt', $code); } }
class WooCommerce {}
class WC_Product_Attribute {
    private array $data = array('name'=>'', 'options'=>array(), 'visible'=>false, 'position'=>0, 'variation'=>false, 'id'=>0);
    function __call($name, $args) { $key = substr($name, 4); if (str_starts_with($name, 'set_')) { $this->data[$key]=$args[0]; return; } return $this->data[$key]; }
    function is_taxonomy() { return $this->data['id'] > 0; }
    function get_data() { return $this->data; }
}
function sanitize_title($s) { return strtolower(strtr(str_replace(' ', '-', $s), array('í'=>'i', 'ó'=>'o', 'á'=>'a'))); }
function get_option($key) { return $GLOBALS['home']; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function wp_get_environment_type() { return 'staging'; }
function clean_post_cache($id) {}
function wc_delete_product_transients($id) {}
function get_posts($args) { return $GLOBALS['identities'][$args['meta_value']] ?? array(); }
function wc_get_product($id) { return isset($GLOBALS['products'][$id]) ? clone $GLOBALS['products'][$id] : false; }
class Product {
    public array $attrs = array();
    public string $description='Old prose', $short_description='Old excerpt';
    function __construct(public int $id, public array $entry, public string $type) {}
    function is_type($type) { return $type === $this->type; }
    function get_attributes() { return $this->attrs; }
    function set_attributes($attrs) { $this->attrs=$attrs; }
    function get_slug(...$args) { return $this->entry['slug']; }
    function get_sku(...$args) { return $this->entry['source_id']; }
    function get_children() { return $this->type === 'variable' ? array($this->id + 100) : array(); }
    function get_description(...$args) { return $this->description; }
    function get_short_description(...$args) { return $this->short_description; }
    function set_description($s) { $this->description=$s; }
    function set_short_description($s) { $this->short_description=$s; }
    function get_data() { return array('id'=>$this->id, 'slug'=>$this->get_slug(), 'sku'=>$this->get_sku(), 'attributes'=>$this->attrs, 'description'=>$this->description, 'short_description'=>$this->short_description, 'price'=>'protected', 'image_id'=>777); }
    function save() { $GLOBALS['products'][$this->id]=clone $this; $GLOBALS['saves']++; return $this->id; }
}
class DB {
    public $posts='wp_posts';
    public array $commands=array();
    function prepare($sql, ...$args) { return $sql; }
    function get_var($sql) { return '1'; }
    function get_col($sql) { return array('InnoDB'); }
    function query($sql) { $this->commands[]=$sql; return 1; }
}
$wpdb = new DB();
$home = 'https://freeplast.mliu.site';
$saves=0;
$catalog = json_decode(file_get_contents(__DIR__ . '/../data/products.json'), true, 512, JSON_THROW_ON_ERROR);
require __DIR__ . '/lib/catalog-pdf.php';
function attribute($name, $options, $variation=false) {
    $a=new WC_Product_Attribute(); $a->set_name($name); $a->set_options($options); $a->set_variation($variation); return $a;
}
foreach ($catalog['products'] as $i=>$entry) {
    $id=$i+1;
    $p=new Product($id, $entry, $entry['options'] ? 'variable' : 'simple');
    $p->attrs['custom'] = attribute('Custom', array('merchant-owned'));
    if ($entry['options']) {
        $p->attrs['color'] = attribute('Color', array_column($entry['options'], 'label'), true);
        $products[$id+100] = new Product($id+100, $entry, 'variation');
    }
    $products[$id]=$p;
    $identities[$entry['source_id']]=array($id);
}
$checks=0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function invoke($args) {
    ob_start();
    try { include __DIR__ . '/update-catalog-pdf.php'; $code=0; }
    catch (Halt $error) { $code=$error->getCode(); }
    $out=ob_get_clean(); return array($code, $out);
}
$receipt = sys_get_temp_dir() . '/freeplast-pdf-receipt-' . getmypid() . '.json';
try {
    check(count(fp_pdf_plan($catalog)) === 15, '15 native identities');
    $variable=$products[4];
    $patch=fp_pdf_patch($variable, $catalog['products'][3]);
    check($patch['attributes']['color'] === $variable->attrs['color'], 'native variation attribute untouched');
    check($patch['attributes']['custom'] === $variable->attrs['custom'], 'merchant attribute preserved');
    $romano=$products[15];
    $romano->attrs['peso-propio']=attribute('Peso propio', array('merchant weight'));
    check(!isset(fp_pdf_patch($romano, $catalog['products'][14])['diff']['Peso propio']), 'unknown PDF weight never erases merchant value');
    $bad=clone $products[1]; $bad->attrs['material']=attribute('Material', array('x'), true);
    try { fp_pdf_patch($bad, $catalog['products'][0]); check(false, 'variation conflict must fail'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'conflicts'), 'variation conflict refused'); }
    $bad->attrs['material']->set_variation(false); $bad->attrs['material']->set_id(9);
    try { fp_pdf_patch($bad, $catalog['products'][0]); check(false, 'taxonomy conflict must fail'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'conflicts'), 'taxonomy conflict refused'); }
    check(invoke(array('oops'))[0] === 2, 'unknown args rejected');
    check(invoke(array('version')) === array(0, "1.0.0\n"), 'version path');
    $home='https://freeplast.cl';
    check(invoke(array('plan', $receipt))[0] === 1 && !file_exists($receipt), 'production rejected before receipt');
    $home='https://freeplast.mliu.site';
    check(invoke(array('plan', $receipt))[0] === 0 && $saves === 0, 'plan does not save products');
    check((fileperms($receipt) & 0777) === 0600, 'receipt private');
    $r=json_decode(file_get_contents($receipt), true);
    check(count($r['rows']) === 15 && isset($r['rows'][0]['diff']['Medidas interiores']), 'receipt contains full diff and before snapshot');
    check(invoke(array('apply', $receipt, str_repeat('0',64)))[0] === 1 && $saves === 0, 'wrong token no writes');
    check(invoke(array('apply', $receipt, $r['token']))[0] === 1 && $saves === 0, 'missing maintenance/backup refuses');
    touch(ABSPATH . '.maintenance');
    putenv('FREEPLAST_CATALOG_BACKUP_VERIFIED=1'); putenv('FREEPLAST_CATALOG_WRITERS_PAUSED=1');
    $products[1]->short_description='concurrent edit';
    check(invoke(array('apply', $receipt, $r['token']))[0] === 1 && $saves === 0, 'stale receipt no writes');
    $products[1]->short_description='Old excerpt';
    $result=invoke(array('apply', $receipt, $r['token']));
    check($result[0] === 0 && $saves === 15, 'apply 15 existing records: ' . $result[1]);
    check(in_array('COMMIT', $wpdb->commands, true), 'transaction committed');
    check($products[1]->attrs['custom']->get_options() === array('merchant-owned'), 'unreviewed attribute survived apply');
    check($products[4]->attrs['color']->get_variation(), 'color variation survived apply');
    check($products[15]->attrs['peso-propio']->get_options() === array('merchant weight'), 'unknown remained native');
    check(!array_filter(fp_pdf_plan($catalog), static fn($row)=>$row['diff']), 'fresh plan idempotent');
    check(invoke(array('apply', $receipt, $r['token']))[0] === 1 && $saves === 15, 'old receipt cannot replay changed state');
    $identities['fp-tote'][]=999;
    try { fp_pdf_plan($catalog); check(false, 'duplicate must fail'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ambiguous'), 'duplicate identity refused'); }
} finally {
    @unlink($receipt); @unlink(ABSPATH . '.maintenance'); rmdir(ABSPATH);
}
echo "catalog PDF migration: $checks offline boundary checks passed; native DB trial unrun\n";
