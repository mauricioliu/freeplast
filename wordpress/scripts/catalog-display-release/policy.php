<?php
/** Sealed public-catalog row delta; no Woo saves, imports or product metadata writes. */
function fp_display_target(): void {
    if (get_option('home') !== 'https://freeplast.mliu.site' || get_option('siteurl') !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging' || (string)get_option('blog_public') !== '0') { throw new RuntimeException('Non-indexed Freeplast staging only.'); }
}
function fp_display_keys(): array { return ['posts'=>['ID'], 'terms'=>['term_id'], 'term_taxonomy'=>['term_taxonomy_id'], 'term_relationships'=>['object_id','term_taxonomy_id']]; }
function fp_display_identity(string $table, array $row): string {
    return implode(':', array_map(static fn($key)=>(string)$row[$key], fp_display_keys()[$table]));
}
function fp_display_review(): array {
    $r=json_decode(file_get_contents(__DIR__.'/review.json'),true,512,JSON_THROW_ON_ERROR);
    if (($r['schema']??'') !== 'freeplast-catalog-display-v1' || ($r['home']??'') !== 'https://freeplast.mliu.site' || count($r['catalog']??[]) !== 17 || count($r['changes']??[]) !== 54) { throw new RuntimeException('Invalid sealed display review.'); }
    $ids=array_column($r['catalog'],'target_id'); $seen=[];
    foreach ($r['changes'] as $c) {
        $table=$c['table'];$b=$c['before'];$a=$c['after'];
        if (!isset(fp_display_keys()[$table]) || (!$b && !$a)) { throw new RuntimeException('Unapproved table or empty delta.'); }
        $identity=fp_display_identity($table,$b??$a);
        if (isset($seen[$table.':'.$identity]) || ($b && $a && fp_display_identity($table,$a)!==$identity)) { throw new RuntimeException('Duplicate or changed identity.'); }
        $seen[$table.':'.$identity]=true;
        foreach (fp_display_keys()[$table] as $key) { if (!preg_match('/^[1-9][0-9]*$/D',(string)($b??$a)[$key])) { throw new RuntimeException('Invalid row identity.'); } }
        if ($table==='posts') {
            if (!$b || !$a || $b['post_type']!=='product' || !in_array((int)$b['ID'],$ids,true)) { throw new RuntimeException('Existing approved products only.'); }
            $rest=$a; $rest['menu_order']=$b['menu_order'];$rest['post_title']=$b['post_title'];
            $p=$r['catalog'][array_search((int)$b['ID'],$ids,true)];
            if ($rest!==$b || $p['slug']!==$b['post_name'] || $a['menu_order']!==(string)$p['menu_order'] || $a['post_title']!==$p['name']) { throw new RuntimeException('Unexpected product-field delta.'); }
        } elseif ($table==='term_relationships') {
            if (!in_array((int)($b??$a)['object_id'],$ids,true) || ($a && $a['term_order']!=='0')) { throw new RuntimeException('Only reviewed product relationships.'); }
            foreach ([$b,$a] as $row) { if ($row && !in_array($row['term_taxonomy_id'],['8','16','17','18','19'],true)) { throw new RuntimeException('Foreign taxonomy relationship.'); } }
        } elseif ($table==='terms') {
            if ($b!==null || !$a || !in_array($a['slug'],['carnes','productos-del-mar'],true) || $a['term_group']!=='0') { throw new RuntimeException('Only two missing category terms may be added.'); }
        } else {
            if (!$a || !in_array($a['term_taxonomy_id'],['8','16','17','18','19'],true) || !in_array($a['taxonomy'],['product_cat','product_visibility'],true)) { throw new RuntimeException('Foreign taxonomy delta.'); }
            if ($b) { $rest=$a;$rest['count']=$b['count'];$rest['description']=$b['description'];if ($rest!==$b) { throw new RuntimeException('Taxonomy identity changed.'); } }
            elseif ($a['taxonomy']!=='product_cat' || $a['parent']!=='0') { throw new RuntimeException('Only flat category creation.'); }
        }
    }
    return $r;
}
function fp_display_read(array $change, bool $lock=false): ?array {
    global $wpdb;
    $table=$change['table'];$row=$change['before']??$change['after'];$parts=[];$values=[];
    foreach (fp_display_keys()[$table] as $key) { $parts[]="`$key` = %d";$values[]=(int)$row[$key]; }
    $sql="SELECT * FROM `{$wpdb->prefix}$table` WHERE ".implode(' AND ',$parts).($lock?' FOR UPDATE':'');
    $rows=$wpdb->get_results($wpdb->prepare($sql,...$values),ARRAY_A);
    if ($wpdb->last_error || count($rows)>1) { throw new RuntimeException('Cannot inspect reviewed row.'); }
    return $rows[0]??null;
}
function fp_display_state(array $review, bool $lock=false): ?string {
    $before=true;$after=true;
    foreach ($review['changes'] as $c) { $row=fp_display_read($c,$lock);$before=$before && $row===$c['before'];$after=$after && $row===$c['after']; }
    return $before?'before':($after?'after':null);
}
function fp_display_baseline(array $b, ?string $release=null): array {
    fp_display_target();fp_display_review();
    if (($b['schema']??'')!=='freeplast-catalog-display-v1' || !preg_match('/^[A-Za-z0-9_-]+$/D',$b['release']??'') || ($release!==null && $b['release']!==$release) || ($b['home']??'')!=='https://freeplast.mliu.site' || ($b['review_sha256']??'')!==hash_file('sha256',__DIR__.'/review.json') || !preg_match('/^[a-f0-9]{64}$/D',$b['original_digest']??'')) { throw new RuntimeException('Invalid/foreign immutable baseline.'); }
    return $b;
}
/** Reconstruct original ordering, including removed rows. Only a globally exact after-state qualifies. */
function fp_display_project(string $table, array $rows, array $review, bool $after): array {
    if (!$after || !isset(fp_display_keys()[$table])) { return $rows; }
    $map=[];foreach ($rows as $row) { $map[fp_display_identity($table,$row)]=$row; }
    foreach ($review['changes'] as $c) {
        if ($c['table']!==$table) { continue; }
        $id=fp_display_identity($table,$c['before']??$c['after']);
        if (($map[$id]??null)!==$c['after']) { throw new RuntimeException('Projection changed during fingerprint.'); }
        unset($map[$id]);if ($c['before']!==null) { $map[$id]=$c['before']; }
    }
    $keys=fp_display_keys()[$table];$rows=array_values($map);
    usort($rows,static function($a,$b)use($keys){foreach($keys as $k){$n=(int)$a[$k]<=>(int)$b[$k];if($n)return $n;}return 0;});
    return $rows;
}
/** Same full protected digest as owner-workspace-fingerprint, with only the exact sealed delta reversed. */
function fp_display_digest(?array $baseline): string {
    global $wpdb;
    $review=fp_display_review();$after=$baseline!==null && fp_display_state($review)==='after';
    $hash=hash_init('sha256');
    $tables=['posts'=>'ID','postmeta'=>'meta_id','comments'=>'comment_ID','commentmeta'=>'meta_id','terms'=>'term_id','termmeta'=>'meta_id','term_taxonomy'=>'term_taxonomy_id','term_relationships'=>'object_id,term_taxonomy_id','users'=>'ID','usermeta'=>'umeta_id','woocommerce_order_items'=>'order_item_id','woocommerce_order_itemmeta'=>'meta_id','wc_orders'=>'id','wc_orders_meta'=>'id','wc_order_addresses'=>'id','wc_order_operational_data'=>'id'];
    foreach ($tables as $suffix=>$key) {
        $table=$wpdb->prefix.$suffix;$present=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if ($wpdb->last_error) { throw new RuntimeException('Cannot inspect protected table.'); }
        if (!$present) { hash_update($hash,$suffix.':absent\n');continue; }
        hash_update($hash,$suffix."\n");$all=[];
        for($offset=0;;$offset+=500){
            $rows=$wpdb->get_results("SELECT * FROM `$table` ORDER BY $key LIMIT 500 OFFSET ".(int)$offset,ARRAY_A);
            if($wpdb->last_error){throw new RuntimeException('Cannot read protected records.');}
            foreach($rows as $row){$all[]=$row;}if(count($rows)<500)break;
        }
        foreach(fp_display_project($suffix,$all,$review,$after) as $row){hash_update($hash,wp_json_encode($row)."\n");}
    }
    $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ('home','siteurl','blog_public','".$wpdb->prefix."user_roles') ORDER BY option_name",$wpdb->esc_like('fpw_').'%', $wpdb->esc_like('woocommerce_').'%', $wpdb->esc_like('qwc_').'%'),ARRAY_A);
    if($wpdb->last_error){throw new RuntimeException('Cannot read protected commercial settings.');}
    foreach($rows as $row){hash_update($hash,wp_json_encode($row)."\n");}
    return hash_final($hash);
}
