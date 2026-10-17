<?php
/** Offline boundary tests reuse the catalog's native product fakes; no database or transport. */
require __DIR__ . '/catalog-pdf-migration-test.php';
require __DIR__ . '/lib/catalog-minimums.php';
mkdir(ABSPATH, 0700);
class MinimumProduct extends Product {
    public array $meta = array('merchant_note'=>'keep');
    function get_meta($key, $single=true, $context='view') { return $single ? ($this->meta[$key] ?? '') : (isset($this->meta[$key]) ? array($this->meta[$key]) : array()); }
    function update_meta_data($key, $value) { $this->meta[$key]=$value; }
    function delete_meta_data($key) { unset($this->meta[$key]); }
    function save_meta_data() { return $this->save(); }
    function get_data() {
        $data = parent::get_data(); $data['meta_data']=array();
        foreach ($this->meta as $key=>$value) { $data['meta_data'][]=array('key'=>$key, 'value'=>$value); }
        return $data;
    }
}
class MinimumDB extends DB {
    public $postmeta='wp_postmeta';
    public array $saved=array();
    public bool $failCommit=false;
    function query($sql) {
        if ($sql === 'START TRANSACTION') { $this->saved=unserialize(serialize($GLOBALS['products'])); }
        if ($sql === 'ROLLBACK') { $GLOBALS['products']=$this->saved; }
        if ($sql === 'COMMIT' && $this->failCommit) { $this->commands[]=$sql; return false; }
        return parent::query($sql);
    }
}
$wpdb=new MinimumDB(); $products=array(); $identities=array(); $saves=0;
foreach ($catalog['products'] as $i=>$entry) {
    $id=$i+1; $p=new MinimumProduct($id, $entry, $entry['options'] ? 'variable' : 'simple');
    $p->attrs['custom']=attribute('Custom', array('merchant-owned'));
    $products[$id]=$p; $identities[$entry['source_id']]=array($id);
    if ($entry['options']) { $products[$id+100]=new MinimumProduct($id+100, $entry, 'variation'); }
}
function minimum_invoke($args) {
    ob_start(); try { include __DIR__ . '/update-catalog-minimums.php'; $code=0; } catch (Halt $e) { $code=$e->getCode(); }
    return array($code, ob_get_clean());
}
$dir=sys_get_temp_dir() . '/freeplast-minimum-receipt-' . getmypid(); mkdir($dir,0700); $receipt=$dir.'/review.json';
try {
    check(count(fp_minimum_plan($catalog)) === 17, '17 reviewed identities');
    check(fp_minimum_values($catalog['products'][0])['_fp_quote_min_qty'] === '70', 'cosechera minimum one pallet');
    check(fp_minimum_values($catalog['products'][12])['_fp_quote_min_qty'] === '50', 'traversa exception');
    check(fp_minimum_values($catalog['products'][15]) === array('_fp_quote_min_qty'=>'','_fp_quote_step'=>'1'), 'unknown stays unknown, no multiples');
    $invalid=$catalog['products'][0]; $invalid['specs']['quantity_step']=70;
    try { fp_minimum_values($invalid); check(false,'multiples refused'); } catch (RuntimeException $e) { check(true,'multiples refused'); }
    check(minimum_invoke(array('--version')) === array(0,"1.0.0\n"), 'version');
    check(minimum_invoke(array('oops'))[0] === 2, 'usage error');
    $home='https://freeplast.cl';
    check(minimum_invoke(array('plan',$receipt))[0] === 1 && !file_exists($receipt), 'production refused');
    $home='https://freeplast.mliu.site';
    check(minimum_invoke(array('plan',$receipt))[0] === 0 && $saves === 0, 'review writes only receipt');
    check((fileperms($receipt)&0777)===0600, 'private receipt');
    $r=json_decode(file_get_contents($receipt),true);
    check(minimum_invoke(array('apply',$receipt,str_repeat('0',64)))[0] === 1 && $saves === 0, 'wrong token no writes');
    check(minimum_invoke(array('apply',$receipt,$r['token']))[0] === 1 && $saves === 0, 'maintenance required');
    touch(ABSPATH.'.maintenance');
    $products[1]->description='merchant changed';
    check(minimum_invoke(array('apply',$receipt,$r['token']))[0] === 1 && $saves === 0, 'merchant drift refused');
    $products[1]->description='Old prose';
    $before=fp_minimum_plan($catalog);
    $result=minimum_invoke(array('apply',$receipt,$r['token']));
    check($result[0] === 0 && $saves === 17, 'apply exact reviewed minima: '.$result[1]);
    foreach (fp_minimum_plan($catalog) as $i=>$row) {
        check($row['before']===$row['after'] && fp_minimum_protected($row['product']) === fp_minimum_protected($before[$i]['product']) && $row['children'] === $before[$i]['children'], 'only parent quantity fields changed');
    }
    check($products[16]->get_meta('_fp_quote_min_qty')==='' && $products[16]->entry['lifecycle']==='active', 'unknown product not archived or assigned invented minimum');
    check(minimum_invoke(array('apply',$receipt,$r['token']))[0] === 1 && $saves === 17, 'old receipt refused after change');
    unlink($receipt);
    check(minimum_invoke(array('plan',$receipt))[0] === 0, 'fresh idempotent review');
    $r=json_decode(file_get_contents($receipt),true);
    check(minimum_invoke(array('apply',$receipt,$r['token']))[0] === 0 && $saves === 17, 'current no-op never resaves');
    $wpdb->commands=array(); $wpdb->failCommit=true;
    check(minimum_invoke(array('apply',$receipt,$r['token']))[0] === 1 && !in_array('ROLLBACK',$wpdb->commands,true), 'uncertain commit never claims rollback or replays');
} finally { unlink($receipt); rmdir($dir); unlink(ABSPATH.'.maintenance'); rmdir(ABSPATH); }
// The PG22 patch may change only a button delta, including on unknown-minimum products.
$unknown_before = fp_minimum_protected(fp_pdf_snapshot($products[16]));
fp_minimum_apply($products[16], array('_fp_quote_step'=>'10'));
check($products[16]->get_meta('_fp_quote_min_qty') === '' && $products[16]->get_meta('_fp_quote_step') === '10', 'delta-only patch never invents an unknown minimum');
check(fp_minimum_protected(fp_pdf_snapshot($products[16])) === $unknown_before, 'delta-only patch preserves commercial data');
echo "catalog minimums: $checks cumulative offline checks passed; native transaction trial withheld\n";
