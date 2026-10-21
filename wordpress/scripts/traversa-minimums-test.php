<?php
/** Offline native-metadata migration scenarios. Only path containment and IO destinations are fixture-adapted. */
define('WP_CLI', true);
define('ARRAY_A', 'ARRAY_A');
class FPW_Traversa_Halt extends RuntimeException {}
class WP_CLI { static function halt($code) { throw new FPW_Traversa_Halt('halt', $code); } }
function wp_get_environment_type() { return $GLOBALS['env'] ?? 'local'; }
function fpw_local_capture_active() { return true; }
function home_url() { return 'http://mliu:8131'; }
function wp_json_encode($data, $flags=0) { return json_encode($data, $flags); }
$slugs=['traversa-para-bins-tipo-g1', 'traversa-para-bins-tipo-upc', 'traversa-para-bins-tipo-romano'];
function get_posts($query) { return [array_search($query['name'], $GLOBALS['slugs'], true)+1]; }
function get_post_field($key, $id) { return $GLOBALS['slugs'][$id-1]; }
function get_post_meta($id, $key, $single) { return $key === '_fp_local_seed_complete' ? '1' : $GLOBALS['wpdb']->values[$id]; }
function clean_post_cache($id) {}
function wc_delete_product_transients($id) {}
class FPW_Traversa_DB {
    public $postmeta='wp_postmeta', $values=[1=>'50',2=>'50',3=>'50'], $snapshot=[], $commands=[], $fail='', $updates=0;
    function prepare($sql, ...$args) { return [$sql,$args]; }
    function get_results($prepared, $kind) {
        [$sql,$args]=$prepared; $id=$args[0];
        $value=$this->values[$id];
        if ($this->fail==='drift' && str_contains($sql,'FOR UPDATE') && $id===2) { $value='99'; }
        return [['meta_id'=>$id+100,'meta_value'=>$value]];
    }
    function get_col($sql) { return ['InnoDB']; }
    function query($query) {
        if (is_array($query)) {
            [$sql,$args]=$query; [$meta,$id,$before]=$args;
            $this->updates++;
            if ($this->fail==='update' && $this->updates===2) { return false; }
            if ($meta!==$id+100 || $this->values[$id]!==$before || !str_contains($sql,"meta_key='_fp_quote_min_qty'")) { return 0; }
            $this->values[$id]='20'; return 1;
        }
        $this->commands[]=$query;
        if ($query==='START TRANSACTION') { $this->snapshot=$this->values; }
        if ($query==='ROLLBACK') { $this->values=$this->snapshot; }
        if ($query==='COMMIT' && $this->fail==='commit') { return false; }
        return 1;
    }
}
$root=sys_get_temp_dir().'/fpw-traversa-'.bin2hex(random_bytes(6)); mkdir($root,0700);
$source=file_get_contents(__DIR__.'/../local/traversa-minimums.php');
// The real guard is separately exercised through env and invalid invocation;
// the host checkout is not /opt/freeplast, and receipts must stay in this fixture.
$source=str_replace(["realpath(__DIR__) !== '/opt/freeplast'", "'/var/freeplast/receipts'", "hash_file('sha256', __FILE__)"], ['false', var_export($root,true), var_export(hash('sha256',$source),true)], $source);
$checks=0;
function check($ok,$message) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($message); } }
function run_update($argv) {
    global $source,$wpdb;
    $args=$argv; $code=0; ob_start();
    try { eval(substr($source,5)); } catch (FPW_Traversa_Halt $e) { $code=$e->getCode(); }
    return [$code,ob_get_clean()];
}
function plan_token() { [, $output]=run_update(['plan']); preg_match('/token: ([a-f0-9]{64})/', $output,$m); return $m[1]; }
try {
    $wpdb=new FPW_Traversa_DB();
    [$code,$output]=run_update(['plan']);
    check($code===0 && substr_count($output,',50,20')===3 && $wpdb->updates===0,'default plan has exactly three reviewed minima and no writes');
    check(run_update(['apply'])[0]===2 && run_update(['--bogus'])[0]===2,'missing token and unknown input fail with usage error');
    check(run_update(['--version'])===[0,"1.0.0\n"],'version fast path');
    $GLOBALS['env']='staging'; check(run_update(['plan'])[0]===1,'staging is refused'); unset($GLOBALS['env']);
    $token=plan_token();
    check(run_update(['apply',$token])[0]===1 && $wpdb->updates===0,'backup acknowledgement required');
    putenv('FREEPLAST_LOCAL_BACKUP_VERIFIED=1');
    check(run_update(['apply',str_repeat('0',64)])[0]===1 && $wpdb->updates===0,'altered token refused');
    [$code,$output]=run_update(['apply',$token]);
    check($code===0 && str_contains($output,'state: applied') && $wpdb->values===[1=>'20',2=>'20',3=>'20'],'all three native metadata rows updated and verified');
    check(run_update(['apply',$token])[0]===1,'stale token cannot replay');
    $updates=$wpdb->updates; check(run_update(['apply',plan_token()])[0]===0 && $wpdb->updates===$updates,'already-correct catalog is an idempotent no-op');
    foreach (['drift','update','commit'] as $failure) {
        $wpdb=new FPW_Traversa_DB(); $wpdb->fail=$failure; $token=plan_token();
        [$code,$output]=run_update(['apply',$token]);
        check($code===1 && !str_contains($output,'state: applied'),'no false success on '.$failure);
        if ($failure==='commit') { check(!in_array('ROLLBACK',$wpdb->commands,true) && str_contains($output,'uncertain'),'uncertain commit is not claimed rolled back or retried'); }
        else { check($wpdb->values===[1=>'50',2=>'50',3=>'50'] && in_array('ROLLBACK',$wpdb->commands,true),'no partial metadata writes on '.$failure); }
    }
    echo "traversa minimums: $checks offline plan/CAS/rollback checks passed\n";
} finally {
    putenv('FREEPLAST_LOCAL_BACKUP_VERIFIED');
    foreach (glob($root.'/*') as $file) { unlink($file); } rmdir($root);
}
