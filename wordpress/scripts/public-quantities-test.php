<?php
/** Reuse synthetic catalog/CLI harness; no native database. */
require __DIR__ . '/catalog-selection-test.php';
require __DIR__ . '/lib/catalog-minimums.php';
mkdir($root,0700); mkdir(ABSPATH,0700);
class QuantityProduct extends SelectionProduct {
    function get_meta($key,...$args) { $v=$this->data['meta_data'][$key] ?? ''; return ($args[0] ?? true) ? $v : ($v === '' ? array() : array($v)); }
    function update_meta_data($key,$value) { $this->data['meta_data'][$key]=$value; }
    function delete_meta_data($key) { unset($this->data['meta_data'][$key]); }
    function save_meta_data() { $this->save(); }
    function get_data() {
        $data=$this->data; $data['meta_data']=array();
        foreach ($this->data['meta_data'] as $key=>$value) { $data['meta_data'][]=array('key'=>$key,'value'=>$value); }
        return $data;
    }
}
function run_quantities($args) {
    ob_start();
    try { include __DIR__ . '/update-public-quantities.php'; }
    catch (RuntimeException $e) { if (!str_starts_with($e->getMessage(),'halt:')) { ob_end_clean(); throw $e; } }
    return ob_get_clean();
}
try {
    $products=array();
    $slugs=array('caja-frutillera','caja-frutera','caja-merlucera','caja-tomatera','traversa-para-bins-tipo-g1','traversa-para-bins-tipo-upc','traversa-para-bins-tipo-romano','tote');
    foreach ($slugs as $i=>$slug) {
        $products[$i+1]=new QuantityProduct($i+1,$slug);
        $products[$i+1]->data['meta_data']['_fp_quote_min_qty']='73';
    }
    $products[1]->data['children']=array(99); $products[99]=new QuantityProduct(99,'child','variation');
    $before=array_map(static fn($p)=>$p->get_data(),$products);
    $environment='staging';
    check(str_contains(run_quantities(array('plan',$root.'/q.json')),'disposable'),'staging blocked');
    $environment='local'; putenv('FREEPLAST_DISPOSABLE_CATALOG=0');
    check(str_contains(run_quantities(array('plan',$root.'/q.json')),'disposable'),'local requires acknowledgement');
    putenv('FREEPLAST_DISPOSABLE_CATALOG=1');
    check(str_contains(run_quantities(array('plan',$root.'/q.json')),'products: 8'),'native plan no historical import');
    $receipt=json_decode(file_get_contents($root.'/q.json'),true);
    check(str_contains(run_quantities(array('apply',$root.'/q.json',$receipt['token'])),'Maintenance'),'maintenance required');
    touch(ABSPATH.'.maintenance');
    $products[2]->data['price']='999';
    check(str_contains(run_quantities(array('apply',$root.'/q.json',$receipt['token'])),'altered plan'),'daily data drift blocks');
    $products[2]->data['price']=$before[2]['price'];
    check(str_contains(run_quantities(array('apply',$root.'/q.json',$receipt['token'])),'status: applied;'),'local meta patch applies');
    foreach (array(200,5,5,1,1,1,1,10) as $i=>$delta) {
        $p=$products[$i+1];
        check($p->get_meta('_fp_quote_step')===(string)$delta,'button delta');
        check($p->get_meta('_fp_quote_min_qty')===($i===0 ? '2000' : '73'),'only frutillera minimum changes');
        check(fp_minimum_protected($p->get_data())===fp_minimum_protected($before[$i+1]),'all other live fields preserved');
    }
    check($products[99]->get_data()===$before[99],'variation unchanged');
    $products[1]->update_meta_data('_fp_quote_min_qty','2500');
    $products[2]->update_meta_data('_fp_quote_step','10');
    check(str_contains(run_quantities(array('apply',$root.'/q.json',$receipt['token'])),'already applied'),'repeat no-op');
    check($products[1]->get_meta('_fp_quote_min_qty')==='2500' && $products[2]->get_meta('_fp_quote_step')==='10','later owner edits never overwritten');
    echo "PASS public quantities: guarded local meta-only patch, deltas, frutillera 2000, merchant preservation, idempotence\n";
} finally {
    foreach (glob($root.'/*.json') as $file) { unlink($file); }
    if (is_file(ABSPATH.'.maintenance')) { unlink(ABSPATH.'.maintenance'); }
    rmdir(ABSPATH); rmdir($root);
}
