<?php
/** Owner-approved selection patch. Woo remains authoritative; never loaded at runtime. */
require_once __DIR__ . '/catalog-pdf.php';
const FP_SELECTION_VERSION = 'pg22-1';
const FP_SELECTION_OPTION = 'freeplast_catalog_selection_pg22';
function fp_selection_featured(): array {
    return array('caja-universal-ventilada-negra', 'caja-universal-ventilada-color', 'caja-cosechera-3-4', 'traversa-para-bins-tipo-g1', 'traversa-para-bins-tipo-upc', 'traversa-para-bins-tipo-romano', 'caja-pollera', 'caja-frutera', 'caja-merlucera');
}
function fp_selection_plan(): array {
    if (get_option(FP_SELECTION_OPTION)) { return array(); }
    $agricola = get_term_by('slug', 'agricola', 'product_cat');
    if (!$agricola || is_wp_error($agricola)) { throw new RuntimeException('Existing Agrícola category required'); }
    $featured = fp_selection_featured();
    $required = array_merge($featured, array('tote', 'bases-plasticas-para-pediluvios'));
    $seen = array(); $rows = array();
    $ids = get_posts(array('post_type'=>'product', 'post_status'=>'any', 'fields'=>'ids', 'numberposts'=>-1, 'orderby'=>'ID', 'order'=>'ASC'));
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product) { throw new RuntimeException('Missing product'); }
        $slug = $product->get_slug('edit');
        $before = fp_pdf_snapshot($product);
        $after = array('featured'=>false);
        if (in_array($slug, $required, true)) {
            if (isset($seen[$slug]) || $product->get_meta('_fp_source_id', true, 'edit') !== 'fp-' . $slug || $product->get_sku('edit') !== 'fp-' . $slug) { throw new RuntimeException('Missing/ambiguous native identity: ' . $slug); }
            $seen[$slug] = true;
            if ($slug !== 'bases-plasticas-para-pediluvios' && $product->get_status('edit') !== 'publish') { throw new RuntimeException('Expected published product: ' . $slug); }
        }
        $rank = array_search($slug, $featured, true);
        if ($rank !== false) { $after = array('featured'=>true, 'menu_order'=>$rank + 1); }
        if (in_array($slug, array('tote', 'traversa-para-bins-tipo-g1', 'traversa-para-bins-tipo-upc', 'traversa-para-bins-tipo-romano'), true)) {
            $after['category_ids'] = array((int)$agricola->term_id);
        }
        if ($slug === 'traversa-para-bins-tipo-romano') { $after['name'] = 'Traversa para Bins Tipo G2'; }
        if ($slug === 'bases-plasticas-para-pediluvios') { $after['status'] = 'draft'; $after['catalog_visibility'] = 'hidden'; }
        $children = array();
        foreach ($product->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child) { throw new RuntimeException('Missing variation'); }
            $children[$child_id] = fp_pdf_snapshot($child);
        }
        $rows[] = array('id'=>(int)$id, 'slug'=>$slug, 'before'=>$before, 'after'=>$after, 'children'=>$children);
    }
    if (count($seen) !== count($required)) { throw new RuntimeException('Required catalog identities missing'); }
    return $rows;
}
function fp_selection_apply(array $rows): void {
    foreach ($rows as $row) {
        $product = wc_get_product($row['id']);
        $changed = false;
        foreach ($row['after'] as $key=>$value) {
            if ($product->{'get_' . $key}('edit') !== $value) { $product->{'set_' . $key}($value); $changed = true; }
        }
        if ($changed) { $product->save(); }
    }
}
function fp_selection_verify(array $rows): void {
    foreach ($rows as $row) {
        $actual = fp_pdf_snapshot(wc_get_product($row['id']));
        $expected = array_replace($row['before'], $row['after']);
        // Native saves update timestamps; all commercial/identity/relationship fields stay exact.
        unset($actual['date_modified'], $actual['date_modified_gmt'], $expected['date_modified'], $expected['date_modified_gmt']);
        if (json_encode($actual) !== json_encode($expected)) { throw new RuntimeException('Unexpected product delta: ' . $row['slug']); }
        foreach ($row['children'] as $id=>$snapshot) {
            if (json_encode(fp_pdf_snapshot(wc_get_product($id))) !== json_encode($snapshot)) { throw new RuntimeException('Variation changed'); }
        }
    }
}
