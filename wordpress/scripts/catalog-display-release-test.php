<?php
/** Offline full-digest/row-projection tests. Real SQL writes are certified by wp-release trial. */
define('WP_CLI',true);define('ARRAY_A','ARRAY_A');
$home='https://freeplast.mliu.site';$public='0';$environment='staging';
function get_option($k){return $k==='blog_public'?$GLOBALS['public']:$GLOBALS['home'];}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function wp_json_encode($x){return json_encode($x);}
require __DIR__.'/catalog-display-release/policy.php';
$checks=0;
function check($ok,$why){global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function refused($fn,$why){try{$fn();}catch(Throwable $e){check(true,$why);return;}check(false,$why);}
class DisplayDB {
    public $prefix='wp_',$options='wp_options',$last_error='';public array $tables=[];
    function prepare($sql,...$args){foreach($args as $a){$sql=preg_replace('/%[sd]/',"'".$a."'",$sql,1);}return $sql;}
    function esc_like($s){return $s;}
    function get_var($sql){preg_match("/LIKE 'wp_([^']+)'/",$sql,$m);return isset($this->tables[$m[1]])?'present':null;}
    function get_results($sql,$format){
        if(str_contains($sql,'SELECT option_name, option_value'))return $this->tables['options'];
        if(preg_match('/FROM `wp_([^`]+)`.*OFFSET (\d+)/',$sql,$m))return array_slice($this->tables[$m[1]],(int)$m[2],500);
        if(preg_match('/FROM `wp_([^`]+)` WHERE (.*)/',$sql,$m)){
            preg_match_all("/`([^`]+)` = '(\d+)'/",$m[2],$conditions,PREG_SET_ORDER);
            return array_values(array_filter($this->tables[$m[1]]??[],static function($r)use($conditions){foreach($conditions as $c){if($r[$c[1]]!==$c[2])return false;}return true;}));
        }throw new RuntimeException('Unexpected test query');
    }
}
$review=fp_display_review();$wpdb=new DisplayDB();
foreach(fp_display_keys() as $t=>$keys)$wpdb->tables[$t]=[];
foreach($review['changes'] as $c)if($c['before'])$wpdb->tables[$c['table']][]=$c['before'];
$wpdb->tables['postmeta']=[['meta_id'=>'1','post_id'=>'14','meta_key'=>'_product_attributes','meta_value'=>'unchanged variants']];
$wpdb->tables['users']=[['ID'=>'1','user_pass'=>'protected']];
$wpdb->tables['wc_orders']=[['id'=>'1','total_amount'=>'protected']];
$wpdb->tables['wc_orders_meta']=[['id'=>'1','meta_value'=>'protected']];
$wpdb->tables['comments']=[['comment_ID'=>'1','comment_content'=>'protected']];
$wpdb->tables['options']=[['option_name'=>'fpw_price_list','option_value'=>'protected'],['option_name'=>'wp_user_roles','option_value'=>'protected']];
function sort_tables(){global $wpdb;foreach(fp_display_keys() as $t=>$keys){usort($wpdb->tables[$t],static function($a,$b)use($keys){foreach($keys as $k){$n=(int)$a[$k]<=>(int)$b[$k];if($n)return $n;}return 0;});}}
sort_tables();$original=$wpdb->tables;
check(fp_display_state($review)==='before','Exact initial state');
$digest=fp_display_digest(null);ob_start();require __DIR__.'/owner-workspace-fingerprint.php';$plain=trim(ob_get_clean());
check($digest===$plain,'Original digest matches standalone full protected fingerprint');
$b=['schema'=>'freeplast-catalog-display-v1','home'=>$home,'release'=>'fixture','review_sha256'=>hash_file('sha256',__DIR__.'/catalog-display-release/review.json'),'original_digest'=>$digest];
check(fp_display_baseline($b,'fixture')===$b,'Bound baseline');
check(fp_display_digest($b)===$digest,'Before state remains original');
foreach($review['changes'] as $c){
    $table=$c['table'];$id=fp_display_identity($table,$c['before']??$c['after']);
    $wpdb->tables[$table]=array_values(array_filter($wpdb->tables[$table],static fn($row)=>fp_display_identity($table,$row)!==$id));
    if($c['after'])$wpdb->tables[$table][]=$c['after'];
}
sort_tables();check(fp_display_state($review)==='after','Exact after state');
check(fp_display_digest(null)!==$digest,'Real catalog writes visible without projection');
check(fp_display_digest($b)===$digest,'Only full exact expected delta retains original digest');
foreach(fp_display_keys() as $t=>$keys){check(fp_display_project($t,$wpdb->tables[$t],$review,true)===$original[$t],'Restores original ordering and deleted rows: '.$t);}
foreach(['posts','terms','term_taxonomy','term_relationships','postmeta','users','wc_orders','wc_orders_meta','comments','options'] as $table){
    $saved=$wpdb->tables[$table];$key=array_key_last($saved[0]);$wpdb->tables[$table][0][$key].='drift';
    check(fp_display_digest($b)!==$digest,'Unexpected field remains protected: '.$table);$wpdb->tables[$table]=$saved;
}
$saved=$wpdb->tables['terms'];array_pop($wpdb->tables['terms']);
check(fp_display_state($review)===null && fp_display_digest($b)!==$digest,'Missing added term is not projected');$wpdb->tables['terms']=$saved;
$saved=$wpdb->tables['term_relationships'];$wpdb->tables['term_relationships'][]=['object_id'=>'999','term_taxonomy_id'=>'18','term_order'=>'0'];
check(fp_display_digest($b)!==$digest,'Unreviewed relationship remains protected');$wpdb->tables['term_relationships']=$saved;
foreach(['review_sha256','home','original_digest','release','schema'] as $key){$bad=$b;$bad[$key]='invalid';refused(fn()=>fp_display_baseline($bad,'fixture'),'Invalid baseline refused: '.$key);}
refused(fn()=>fp_display_baseline($b,'foreign'),'Foreign release refused');
$home='https://freeplast.cl';refused(fn()=>fp_display_target(),'Production refused');$home='https://freeplast.mliu.site';
$public='1';refused(fn()=>fp_display_target(),'Indexed site refused');$public='0';
$environment='production';refused(fn()=>fp_display_target(),'Wrong environment refused');
echo "catalog display release: $checks offline checks passed; real MariaDB apply/rollback requires restored trial\n";
