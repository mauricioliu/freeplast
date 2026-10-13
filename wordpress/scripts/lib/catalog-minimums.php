<?php
/** Owner-confirmed one-shot quantity policy; never an ongoing catalog synchronizer. */
require_once __DIR__ . '/catalog-pdf.php';
function fp_minimum_values(array $entry): array {
    $minimum = $entry['specs']['minimum_quantity'] ?? null;
    if (($minimum !== null && (!is_int($minimum) || $minimum < 1)) || ($entry['specs']['quantity_step'] ?? null) !== 1) {
        throw new RuntimeException('Unreviewed minimum/step: ' . $entry['source_id']);
    }
    return array('_fp_quote_min_qty' => $minimum === null ? '' : (string)$minimum, '_fp_quote_step' => '1');
}
function fp_minimum_plan(array $catalog): array {
    $rows = array();
    foreach ($catalog['products'] as $entry) {
        $ids = get_posts(array('post_type'=>'product', 'post_status'=>'any', 'fields'=>'ids', 'numberposts'=>-1, 'meta_key'=>'_fp_source_id', 'meta_value'=>$entry['source_id']));
        if (count($ids) !== 1) { throw new RuntimeException('Missing/ambiguous source identity: ' . $entry['source_id']); }
        $product = wc_get_product((int)$ids[0]);
        if (!$product || $product->get_slug('edit') !== $entry['slug'] || $product->get_sku('edit') !== $entry['source_id'] || !$product->is_type($entry['options'] ? 'variable' : 'simple')) {
            throw new RuntimeException('Native identity/type drift: ' . $entry['source_id']);
        }
        $values = fp_minimum_values($entry);
        $before = array();
        foreach ($values as $key=>$value) {
            if (count($product->get_meta($key, false, 'edit')) > 1) { throw new RuntimeException('Duplicate quantity metadata'); }
            $before[$key] = (string)$product->get_meta($key, true, 'edit');
        }
        $children = array();
        foreach ($product->get_children() as $id) {
            $child = wc_get_product($id);
            if (!$child) { throw new RuntimeException('Missing variation'); }
            $children[$id] = fp_pdf_snapshot($child);
        }
        $rows[] = array('id'=>(int)$ids[0], 'slug'=>$entry['slug'], 'before'=>$before, 'after'=>$values, 'product'=>fp_pdf_snapshot($product), 'children'=>$children);
    }
    if (count($rows) !== 17 || count(array_unique(array_column($rows, 'id'))) !== 17) { throw new RuntimeException('Expected 17 distinct catalog identities'); }
    return $rows;
}
function fp_minimum_apply($product, array $values): void {
    foreach ($values as $key=>$value) {
        if (!in_array($key, array('_fp_quote_min_qty', '_fp_quote_step'), true)) { throw new RuntimeException('Unexpected metadata'); }
        if ($value === '') { $product->delete_meta_data($key); }
        else { $product->update_meta_data($key, $value); }
    }
    $product->save_meta_data();
}
function fp_minimum_protected(array $snapshot): array {
    $snapshot['meta_data'] = array_values(array_filter($snapshot['meta_data'] ?? array(), static function($meta) {
        $data = is_object($meta) ? $meta->get_data() : $meta;
        return !in_array($data['key'], array('_fp_quote_min_qty', '_fp_quote_step'), true);
    }));
    return $snapshot;
}
