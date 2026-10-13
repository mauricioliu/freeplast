<?php
/** One-off PDF technical patch. No registration, hooks or ongoing synchronization. */
function fp_pdf_fields() {
    return array('material'=>'Material', 'dimensions'=>'Medidas', 'weight'=>'Peso propio', 'use'=>'Uso', 'units_per_pallet'=>'Unidades por pallet', 'capacity'=>'Capacidad', 'interior_dimensions'=>'Medidas interiores', 'color'=>'Color', 'features'=>'Características', 'pallet_dimensions'=>'Medidas del pallet');
}
function fp_pdf_patch($product, array $entry) {
    $attributes = $product->get_attributes();
    $diff = array();
    foreach (fp_pdf_fields() as $key=>$label) {
        $value = $entry['specs'][$key] ?? null;
        // Missing PDF facts never erase merchant data; native variation attributes stay untouched.
        if ($value === null || $value === 'Consultar' || ($key === 'color' && $product->is_type('variable'))) { continue; }
        $name = sanitize_title($label);
        $existing = $attributes[$name] ?? null;
        if ($existing && ($existing->is_taxonomy() || $existing->get_variation())) {
            throw new RuntimeException('Technical attribute conflicts with taxonomy/variation: ' . $entry['slug'] . '/' . $label);
        }
        $before = $existing ? $existing->get_options() : array();
        $after = array((string)$value);
        if ($before === $after) { continue; }
        $attribute = $existing ? clone $existing : new WC_Product_Attribute();
        $attribute->set_name($label);
        $attribute->set_options($after);
        if (!$existing) { $attribute->set_visible(true); $attribute->set_position(count($attributes)); }
        $attributes[$name] = $attribute;
        $diff[$label] = array('before'=>$before, 'after'=>$after);
    }
    foreach (array('description'=>'description', 'excerpt'=>'short_description') as $key=>$property) {
        $before = $product->{'get_' . $property}('edit');
        if ($before !== $entry[$key]) { $diff[$property] = array('before'=>$before, 'after'=>$entry[$key]); }
    }
    return array('attributes'=>$attributes, 'diff'=>$diff);
}
function fp_pdf_snapshot($product) {
    $data = $product->get_data();
    if (!$product->is_type('variation')) {
        $data['attributes'] = array_map(static fn($attribute) => $attribute->get_data(), $product->get_attributes());
    }
    return $data;
}
function fp_pdf_plan(array $catalog) {
    $rows = array();
    foreach ($catalog['products'] as $entry) {
        if (empty($entry['review']['spec_sources'])) { continue; }
        $ids = get_posts(array('post_type'=>'product', 'post_status'=>'any', 'fields'=>'ids', 'numberposts'=>-1, 'meta_key'=>'_fp_source_id', 'meta_value'=>$entry['source_id']));
        if (count($ids) !== 1) { throw new RuntimeException('Missing/ambiguous source identity: ' . $entry['source_id']); }
        $id = (int)$ids[0];
        $product = wc_get_product($id);
        if (!$product || $product->get_slug('edit') !== $entry['slug'] || $product->get_sku('edit') !== $entry['source_id'] || !$product->is_type($entry['options'] ? 'variable' : 'simple')) {
            throw new RuntimeException('Native identity/type drift: ' . $entry['source_id']);
        }
        $children = array();
        foreach ($product->get_children() as $child) {
            $variation = wc_get_product($child);
            if (!$variation) { throw new RuntimeException('Missing variation'); }
            $children[$child] = fp_pdf_snapshot($variation);
        }
        $patch = fp_pdf_patch($product, $entry);
        $rows[] = array('id'=>$id, 'slug'=>$entry['slug'], 'before'=>fp_pdf_snapshot($product), 'children'=>$children, 'diff'=>$patch['diff']);
    }
    if (count($rows) !== 15 || count(array_unique(array_column($rows, 'id'))) !== 15) { throw new RuntimeException('Expected exactly 15 distinct native products'); }
    return $rows;
}
function fp_pdf_token(array $rows, string $sourceHash) {
    return hash('sha256', json_encode(array($sourceHash, $rows), JSON_THROW_ON_ERROR));
}
