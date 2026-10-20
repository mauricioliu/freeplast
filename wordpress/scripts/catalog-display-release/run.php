<?php
/** wp-release capture/apply/verify; sealed catalog-display delta, never an HTTP entry point. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__.'/policy.php';
$mode=$args[0]??'';$release=$args[1]??'';
if(count($args??[])!==2 || !in_array($mode,['capture','apply','verify'],true) || !preg_match('/^[A-Za-z0-9_-]+$/D',$release)){throw new RuntimeException('Expected mode and release ID.');}
fp_display_target();$review=fp_display_review();
if($mode==='capture'){
    if(fp_display_state($review)!=='before'){throw new RuntimeException('Staging differs from reviewed before-state; capture refused.');}
    echo wp_json_encode(['schema'=>'freeplast-catalog-display-v1','release'=>$release,'home'=>get_option('home'),'review_sha256'=>hash_file('sha256',__DIR__.'/review.json'),'original_digest'=>fp_display_digest(null)]);return;
}
$baseline=fp_display_baseline(json_decode(file_get_contents('php://stdin'),true,512,JSON_THROW_ON_ERROR),$release);
if(@file_get_contents(ABSPATH.'.wp-release-lock/owner')!==$release || !is_file(ABSPATH.'.maintenance') || !is_file(ABSPATH.'wp-content/mu-plugins/wp-release-guard.php')){throw new RuntimeException('Release-owned maintenance required.');}
if(fp_display_digest($baseline)!==$baseline['original_digest']){throw new RuntimeException('Protected drift before display migration; retain maintenance.');}
if($mode==='apply'){
    global $wpdb;
    $engines=$wpdb->get_col("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    if($wpdb->last_error || !$engines || array_filter($engines,static fn($v)=>strtolower((string)$v)!=='innodb')){throw new RuntimeException('InnoDB required.');}
    if($wpdb->query('START TRANSACTION')===false){throw new RuntimeException('Cannot begin transaction.');}
    try{
        $state=fp_display_state($review,true);
        if($state===null || fp_display_digest($baseline)!==$baseline['original_digest']){throw new RuntimeException('Partial/foreign state or protected drift; refuse replay.');}
        if($state==='before')foreach($review['changes'] as $c){
            $table=$wpdb->prefix.$c['table'];$b=$c['before'];$a=$c['after'];$where=[];
            foreach(fp_display_keys()[$c['table']] as $key){$where[$key]=($b??$a)[$key];}
            if($b===null){$n=$wpdb->insert($table,$a);}
            elseif($a===null){$n=$wpdb->delete($table,$where);}
            else{$fields=[];foreach($a as $key=>$value){if($value!==$b[$key])$fields[$key]=$value;}$n=$wpdb->update($table,$fields,$where);}
            if($n!==1){throw new RuntimeException('Exact row operation failed; abort.');}
        }
        if(fp_display_state($review)!=='after' || fp_display_digest($baseline)!==$baseline['original_digest']){throw new RuntimeException('Unexpected post-apply delta; abort.');}
        if($wpdb->query('COMMIT')===false){throw new RuntimeException('Commit outcome uncertain; inspect before retry.');}
    }catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    // No product saves/hooks; invalidate only this catalog's derived caches after commit.
    foreach($review['catalog'] as $p){clean_post_cache($p['target_id']);if(function_exists('wc_delete_product_transients'))wc_delete_product_transients($p['target_id']);}
    clean_term_cache([16,17,18,19],'product_cat');clean_term_cache([8],'product_visibility');
    delete_transient('wc_featured_products');
}
if(fp_display_state($review)!=='after' || fp_display_digest($baseline)!==$baseline['original_digest']){throw new RuntimeException('Expected display state missing; retain maintenance.');}
echo "catalog_display_verified: 17 existing products, 9 featured, 4 categories; commercial records protected\n";
