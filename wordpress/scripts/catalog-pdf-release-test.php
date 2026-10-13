<?php
/** Offline raw-row and full-fingerprint regression; no WP, SQL server or network. */
define('WP_CLI', true); define('ARRAY_A', 'ARRAY_A');
function sanitize_title($s) { return strtolower(strtr(str_replace(' ', '-', $s), array('í'=>'i','ó'=>'o','á'=>'a'))); }
function get_option($name) { return $name === 'blog_public' ? '0' : $GLOBALS['home']; }
function wp_get_environment_type() { return 'staging'; }
function wp_json_encode($value) { return json_encode($value); }
$home = 'https://freeplast.mliu.site';
require __DIR__ . '/catalog-pdf-release/policy.php';
$checks = 0;
function check($ok, $why) { global $checks; if (!$ok) { throw new RuntimeException($why); } $checks++; }
function refused($fn, $why) { try { $fn(); } catch (Throwable $e) { check(true, $why); return; } check(false, $why); }
function attr($name, $value, $variation=0) { return array('name'=>$name,'value'=>$value,'position'=>0,'is_visible'=>1,'is_variation'=>$variation,'is_taxonomy'=>0); }
$entries = fp_pdf_release_catalog();
$products = array();
foreach ($entries as $i=>$entry) {
    $attrs = array('custom'=>attr('Custom','merchant'), 'peso-propio'=>attr('Peso propio','merchant weight'));
    if ($entry['options']) { $attrs['color'] = attr('Color','Blanco | Rojo | Amarillo | Azul | Verde',1); }
    $before = array('post'=>array('ID'=>(string)($i+1),'post_type'=>'product','post_name'=>$entry['slug'],'post_password'=>'','post_content'=>'Original','post_excerpt'=>'Short','post_modified'=>'unchanged'),
        'attributes'=>array('meta_id'=>(string)($i+101),'post_id'=>(string)($i+1),'meta_key'=>'_product_attributes','meta_value'=>serialize($attrs)));
    $after = fp_pdf_release_expected($before, $entry);
    $decoded = unserialize($after['attributes']['meta_value']);
    check($after['post']['post_content'] === $entry['description'] && $after['post']['post_excerpt'] === $entry['excerpt'], 'Reviewed copy');
    check($after['post']['post_modified'] === 'unchanged' && $decoded['custom'] === $attrs['custom'], 'No timestamps or merchant attributes changed');
    if ($entry['options']) { check($decoded['color'] === $attrs['color'], 'Variable color preserved exactly'); }
    if ($entry['specs']['weight'] === null) { check($decoded['peso-propio'] === $attrs['peso-propio'], 'Null never erases merchant weight'); }
    $products[] = array('before'=>$before,'after'=>$after);
}
$baseline = array('products'=>$products);
foreach ($products as $p) {
    foreach (array('posts'=>'post','postmeta'=>'attributes') as $table=>$key) {
        check(fp_pdf_release_project($table,$p['after'][$key],$baseline) === $p['before'][$key], 'Only exact expected row projects');
        $bad=$p['after'][$key]; $bad[array_key_first($bad)]='99999';
        check(fp_pdf_release_project($table,$bad,$baseline) === $bad, 'Foreign identity not projected');
        $bad=$p['after'][$key]; $bad[$key==='post'?'post_content':'meta_value'].='unexpected';
        check(fp_pdf_release_project($table,$bad,$baseline) === $bad, 'Unexpected bytes stay visible');
    }
}
$bad=$products[0]['before']; $a=unserialize($bad['attributes']['meta_value']); $a['material']=attr('Material','x',1); $bad['attributes']['meta_value']=serialize($a);
refused(fn()=>fp_pdf_release_expected($bad,$entries[0]), 'Variation conflict fails closed');
$a['material']['is_variation']=0; $a['material']['is_taxonomy']=1; $bad['attributes']['meta_value']=serialize($a);
refused(fn()=>fp_pdf_release_expected($bad,$entries[0]), 'Taxonomy conflict fails closed');
$home='https://freeplast.cl'; refused(fn()=>fp_pdf_release_target(), 'Production refused'); $home='https://freeplast.mliu.site';
refused(fn()=>fp_pdf_release_validate(array()), 'Missing baseline refused');
class DB {
    public $prefix='wp_', $options='wp_options', $posts='wp_posts', $postmeta='wp_postmeta', $last_error='';
    public array $tables=array();
    function prepare($sql, ...$args) { foreach($args as $arg) { $sql=preg_replace('/%[sd]/', "'" . $arg . "'", $sql, 1); } return $sql; }
    function esc_like($s) { return $s; }
    function get_var($sql) { preg_match("/LIKE 'wp_([^']+)'/", $sql, $m); return isset($this->tables[$m[1]]) ? 'present' : null; }
    function get_results($sql, $format) {
        if (str_contains($sql,'SELECT option_name, option_value')) { return $this->tables['options']; }
        if (preg_match('/FROM `wp_([^`]+)`.*OFFSET (\d+)/', $sql, $m)) { return array_slice($this->tables[$m[1]],(int)$m[2],500); }
        throw new RuntimeException('Unexpected query');
    }
}
$wpdb=new DB();
$wpdb->tables=array('posts'=>array_column(array_column($products,'before'),'post'), 'postmeta'=>array_column(array_column($products,'before'),'attributes'),
    'users'=>array(array('ID'=>'1','user_pass'=>'protected')), 'wc_orders'=>array(array('id'=>'1','total_amount'=>'protected')),
    'options'=>array(array('option_name'=>'fpw_price_list','option_value'=>'protected')));
$beforeDigest=fp_pdf_release_digest(null);
ob_start(); require __DIR__ . '/owner-workspace-fingerprint.php'; $plain=trim(ob_get_clean());
check($beforeDigest === $plain, 'Exact standalone owner fingerprint compatibility');
$wpdb->tables['posts']=array_column(array_column($products,'after'),'post');
$wpdb->tables['postmeta']=array_column(array_column($products,'after'),'attributes');
check(fp_pdf_release_digest($baseline) === $plain, 'Expected delta retains original full digest');
check(fp_pdf_release_digest(null) !== $plain, 'Actual writes remain visible without projection');
foreach (array('posts','postmeta','users','wc_orders','options') as $table) {
    $save=$wpdb->tables[$table]; $wpdb->tables[$table][0][array_key_last($save[0])].='drift';
    check(fp_pdf_release_digest($baseline) !== $plain, 'Protected drift detected: '.$table);
    $wpdb->tables[$table]=$save;
}
$wpdb->tables['terms']=array(array('term_id'=>'123','name'=>'changed category'));
check(fp_pdf_release_digest($baseline) !== $plain, 'Catalog taxonomy not excluded');
echo "catalog PDF release: $checks offline delta/fingerprint checks passed; MariaDB transaction trial unrun\n";
