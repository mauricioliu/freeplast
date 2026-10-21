<?php
/** Offline full-digest, exact-delta and transactional tests; real SQL is tested by restored trial. */
define('WP_CLI',true); define('ARRAY_A','ARRAY_A');
$home='https://freeplast.mliu.site'; $public='0'; $environment='staging';
function get_option($k) { return $k==='blog_public' ? $GLOBALS['public'] : $GLOBALS['home']; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_json_encode($v) { return json_encode($v); }
function clean_post_cache($id) {}
function wc_delete_product_transients($id) {}
require __DIR__.'/owner-feedback-release/policy.php';
$checks=0;
function check($ok,$why) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($why); } }
function refused($fn,$why) { try { $fn(); } catch (Throwable $e) { check(true,$why); return; } check(false,$why); }
class FeedbackDB {
    public $prefix='wp_', $posts='wp_posts', $postmeta='wp_postmeta', $options='wp_options', $last_error='', $fail='';
    public array $tables=[], $snapshot=[], $commands=[];
    public int $updates=0;
    function prepare($sql,...$args) { foreach($args as $a) { $sql=preg_replace('/%[sd]/',"'".$a."'",$sql,1); } return $sql; }
    function esc_like($s) { return $s; }
    function get_var($sql) { preg_match("/LIKE 'wp_([^']+)'/",$sql,$m); return isset($this->tables[$m[1]]) ? 'present' : null; }
    function get_col($sql) { return ['InnoDB']; }
    function get_results($sql,$format) {
        if (str_contains($sql,'SELECT option_name, option_value')) { return $this->tables['options']; }
        if (preg_match('/FROM `wp_([^`]+)`.*OFFSET (\d+)/',$sql,$m)) { return array_slice($this->tables[$m[1]],(int)$m[2],500); }
        if (preg_match("/FROM wp_posts WHERE post_type='product' AND post_name='([^']+)'/",$sql,$m)) {
            return array_map(static fn($r)=>array_intersect_key($r,array_flip(['ID','post_name','post_status'])),array_values(array_filter($this->tables['posts'],static fn($r)=>$r['post_name']===$m[1])));
        }
        if (preg_match("/FROM wp_postmeta WHERE post_id='(\d+)'/",$sql,$m)) {
            return array_values(array_filter($this->tables['postmeta'],static fn($r)=>$r['post_id']===$m[1] && $r['meta_key']==='_fp_quote_min_qty'));
        }
        throw new RuntimeException('Unexpected fixture query: '.$sql);
    }
    function query($sql) {
        $this->commands[]=$sql;
        if ($sql==='START TRANSACTION') { $this->snapshot=$this->tables; return 0; }
        if ($sql==='ROLLBACK') { $this->tables=$this->snapshot; return 0; }
        if ($sql==='COMMIT') { return $this->fail==='commit' ? false : 0; }
        if (preg_match("/UPDATE wp_postmeta SET meta_value='20' WHERE meta_id='(\d+)' AND post_id='(\d+)'/",$sql,$m)) {
            $this->updates++;
            if ($this->fail==='update' && $this->updates===2) { return false; }
            foreach ($this->tables['postmeta'] as &$row) {
                if ($row['meta_id']===$m[1] && $row['post_id']===$m[2] && $row['meta_key']==='_fp_quote_min_qty' && $row['meta_value']==='50') { $row['meta_value']='20'; return 1; }
            }
            return 0;
        }
        throw new RuntimeException('Unexpected fixture write');
    }
}
$wpdb=new FeedbackDB();
foreach(fp_feedback_slugs() as $i=>$slug) {
    $id=(string)($i+1);
    $wpdb->tables['posts'][]=['ID'=>$id,'post_name'=>$slug,'post_status'=>'publish','post_type'=>'product'];
    $wpdb->tables['postmeta'][]=['meta_id'=>$id,'post_id'=>$id,'meta_key'=>'_fp_quote_min_qty','meta_value'=>'50'];
}
$wpdb->tables['postmeta'][]=['meta_id'=>'4','post_id'=>'1','meta_key'=>'_fp_quote_step','meta_value'=>'1'];
$wpdb->tables['users']=[['ID'=>'1','user_pass'=>'protected']];
$wpdb->tables['comments']=[['comment_ID'=>'1','comment_content'=>'protected']];
$wpdb->tables['wc_orders']=[['id'=>'1','total_amount'=>'protected']];
$wpdb->tables['wc_orders_meta']=[['id'=>'1','meta_value'=>'protected']];
$wpdb->tables['options']=[['option_name'=>'fpw_quotation_1','option_value'=>'protected'],['option_name'=>'wp_user_roles','option_value'=>'protected']];
$original=$wpdb->tables;
$b=fp_feedback_capture('fixture'); $digest=$b['original_digest'];
ob_start(); require __DIR__.'/owner-workspace-fingerprint.php'; $plain=trim(ob_get_clean());
check($digest===$plain,'Before digest exactly matches original full owner-workspace fingerprint');
check(fp_feedback_baseline($b,'fixture')===$b,'Immutable baseline accepted');
check(fp_feedback_state($b)==='before' && fp_feedback_digest($b)===$digest,'Before state has original digest');
fp_feedback_apply($b);
check(fp_feedback_state($b)==='after','Three and only three minimums changed');
check(fp_feedback_digest(null)!==$digest && fp_feedback_digest($b)===$digest,'Exact full after-state projects back to original digest');
check($wpdb->updates===3,'Exactly three SQL row updates');
$updates=$wpdb->updates; fp_feedback_apply($b); check($wpdb->updates===$updates,'Idempotent apply does not replay writes');
$after=$wpdb->tables;
foreach(['posts','users','comments','wc_orders','wc_orders_meta','options'] as $table) {
    $key=array_key_last($wpdb->tables[$table][0]); $wpdb->tables[$table][0][$key].='drift';
    check(fp_feedback_digest($b)!==$digest,'Unrelated protected record remains visible: '.$table);
    $wpdb->tables=$after;
}
$wpdb->tables['postmeta'][3]['meta_value']='5';
check(fp_feedback_digest($b)!==$digest,'Quantity steps are not excluded from protected fingerprint'); $wpdb->tables=$after;
$wpdb->tables['postmeta'][0]['meta_value']='21';
check(fp_feedback_state($b)===null && fp_feedback_digest($b)!==$digest,'Unexpected minimum is not projected');
refused(fn()=>fp_feedback_apply($b),'Partial/foreign state cannot replay'); $wpdb->tables=$after;
array_shift($wpdb->tables['postmeta']); check(fp_feedback_digest($b)!==$digest,'Missing metadata is inspectable as raw drift'); $wpdb->tables=$after;
$wpdb->tables['postmeta'][]=$wpdb->tables['postmeta'][0]; check(fp_feedback_digest($b)!==$digest,'Duplicate metadata is inspectable as raw drift'); $wpdb->tables=$after;
$wpdb->tables['posts'][0]['post_name']='renamed'; check(fp_feedback_digest($b)!==$digest,'Renamed product is inspectable as raw drift'); $wpdb->tables=$after;
foreach(['schema','home','release','policy_sha256','original_digest'] as $key) { $bad=$b; $bad[$key]='invalid'; refused(fn()=>fp_feedback_baseline($bad,'fixture'),'Invalid baseline: '.$key); }
refused(fn()=>fp_feedback_baseline($b,'foreign'),'Foreign release refused');
foreach(['meta_key','meta_id','post_id','meta_value'] as $key) { $bad=$b; $bad['products'][0]['after'][$key]='forged'; refused(fn()=>fp_feedback_baseline($bad),'Expanded metadata delta refused: '.$key); }
$bad=$b; $bad['products'][1]=$bad['products'][0]; refused(fn()=>fp_feedback_baseline($bad),'Duplicate product refused');
$wpdb->tables=$original; $wpdb->tables['postmeta'][0]['meta_value']='40'; refused(fn()=>fp_feedback_capture('fixture'),'Unreviewed before minimum refused');
foreach(['update','commit'] as $failure) {
    $wpdb->tables=$original; $wpdb->commands=[]; $wpdb->updates=0; $wpdb->fail=$failure;
    refused(fn()=>fp_feedback_apply($b),'Write failure is explicit: '.$failure);
    if ($failure==='update') { check($wpdb->tables===$original && in_array('ROLLBACK',$wpdb->commands,true),'Failed update rolls back all writes'); }
    else { check(!in_array('ROLLBACK',$wpdb->commands,true),'Uncertain commit is not falsely claimed rolled back'); }
}
$home='https://freeplast.cl'; refused(fn()=>fp_feedback_target(),'Production refused'); $home='https://freeplast.mliu.site';
$environment='local'; refused(fn()=>fp_feedback_target(),'DEV helper cannot be used as a staging migration'); $environment='staging';
$public='1'; refused(fn()=>fp_feedback_target(),'Indexed site refused');
echo "owner feedback release: $checks offline full-digest/delta/transaction checks passed; real SQL trial still required\n";
