<?php
/** DEV-only explicit category update. Native terms remain the editable catalog authority. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' ||
    !function_exists('fpw_local_capture_active') || !fpw_local_capture_active() ||
    home_url() !== 'http://mliu:8131' || realpath(__DIR__) !== '/opt/freeplast') {
    throw new RuntimeException('Only the contained Freeplast DEV CLI may run this update.');
}
$mode = $args[0] ?? 'plan';
if (!in_array($mode, ['plan', 'apply'], true)) { throw new RuntimeException('Use plan or apply.'); }
$sections = [
    'agricola' => ['name' => 'Agrícola', 'description' => 'Cajas, totes y traversas para uso agrícola. Revisa los detalles de cada producto y agrega las cantidades que necesitas.'],
    'carnes' => ['name' => 'Carnes', 'description' => 'Cajas universales y cajas polleras. Revisa sus medidas, características y cantidades para solicitar una cotización.'],
    'productos-del-mar' => ['name' => 'Productos del mar', 'description' => 'Cajas merluceras y cajas universales. Consulta los detalles de cada producto y solicita tu cotización mayorista.'],
    'otros' => ['name' => 'Otros', 'description' => 'Ladrillos plásticos y bases para pediluvios. Consulta sus características y agrega los productos que necesitas cotizar.'],
];
$source = json_decode(file_get_contents('/opt/catalog/products.json'), true, 512, JSON_THROW_ON_ERROR);
$products = [];
$before = ['terms' => [], 'products' => []];
foreach ($sections as $slug => $section) {
    $term = get_term_by('slug', $slug, 'product_cat');
    $before['terms'][$slug] = $term ? ['id' => $term->term_id, 'name' => $term->name, 'description' => $term->description] : null;
}
// Resolve and validate ALL products before changing a term or relationship.
foreach ($source['products'] as $item) {
    $slug = $item['slug'];
    $found = get_posts(['post_type' => 'product', 'name' => $slug, 'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids']);
    if (count($found) !== 1 || !get_post_meta($found[0], '_fp_local_seed_complete', true)) { throw new RuntimeException('Missing/ambiguous DEV catalog product: ' . $slug); }
    $id = (int) $found[0];
    if (str_starts_with($slug, 'caja-universal-')) { $desired = ['agricola', 'carnes', 'productos-del-mar']; }
    elseif ($slug === 'caja-merlucera') { $desired = ['productos-del-mar']; }
    elseif ($slug === 'caja-pollera') { $desired = ['carnes']; }
    elseif (in_array($slug, ['bases-plasticas-para-pediluvios', 'ladrillo-plastico'], true)) { $desired = ['otros']; }
    else { $desired = ['agricola']; }
    $current = wp_get_object_terms($id, 'product_cat', ['fields' => 'slugs']);
    if (is_wp_error($current)) { throw new RuntimeException($current->get_error_message()); }
    // Keep any owner-created category unrelated to these four sections.
    $desired = array_values(array_unique(array_merge(array_diff($current, array_keys($sections)), $desired)));
    $products[$slug] = ['id' => $id, 'before' => $current, 'after' => $desired];
    $before['products'][$id] = $current;
}
$plan = ['site' => home_url(), 'sections' => $sections, 'products' => $products];
if ($mode === 'plan') { echo wp_json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"; return; }
if (getenv('FREEPLAST_LOCAL_BACKUP_VERIFIED') !== '1') { throw new RuntimeException('A verified private DEV backup is required before apply.'); }
$receipt_dir = '/var/freeplast/catalog-sections';
if (!is_dir($receipt_dir) && !mkdir($receipt_dir, 0700, true)) { throw new RuntimeException('Private receipt directory unavailable.'); }
$receipt = $receipt_dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
$bytes = wp_json_encode(['before' => $before, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (file_put_contents($receipt, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($receipt, 0600)) { throw new RuntimeException('Private before-state receipt could not be saved.'); }
foreach ($sections as $slug => $section) {
    $existing = get_term_by('slug', $slug, 'product_cat');
    // Existing editorial descriptions are never overwritten by this updater.
    if (!$existing) { $result = wp_insert_term($section['name'], 'product_cat', ['slug' => $slug, 'description' => $section['description']]); }
    elseif ($existing->description === '') { $result = wp_update_term($existing->term_id, 'product_cat', ['description' => $section['description']]); }
    else { continue; }
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message() . '; before-state: ' . $receipt); }
}
foreach ($products as $slug => $product) {
    $result = wp_set_object_terms($product['id'], $product['after'], 'product_cat');
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message() . '; before-state: ' . $receipt); }
    $actual = wp_get_object_terms($product['id'], 'product_cat', ['fields' => 'slugs']);
    $expected = $product['after'];
    if (is_wp_error($actual)) { throw new RuntimeException('Could not verify ' . $slug . '; before-state: ' . $receipt); }
    sort($actual); sort($expected);
    if ($actual !== $expected) { throw new RuntimeException('Category verification failed: ' . $slug . '; before-state: ' . $receipt); }
    clean_post_cache($product['id']);
    wc_delete_product_transients($product['id']);
}
echo wp_json_encode(['state' => 'applied', 'products' => count($products), 'receipt' => $receipt, 'sections' => array_keys($sections)], JSON_UNESCAPED_UNICODE) . "\n";
