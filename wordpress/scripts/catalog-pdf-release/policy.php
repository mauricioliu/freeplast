<?php
/** One-shot raw-row delta. No Woo saves/hooks, timestamps, taxonomy or lookup writes. */
require_once is_file(__DIR__ . '/catalog-pdf.php') ? __DIR__ . '/catalog-pdf.php' : __DIR__ . '/../lib/catalog-pdf.php';
function fp_pdf_release_catalog(): array {
    $path = is_file(__DIR__ . '/products.json') ? __DIR__ . '/products.json' : __DIR__ . '/../../data/products.json';
    if (hash_file('sha256', $path) !== 'a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a') {
        throw new RuntimeException('Unreviewed PDF reference.');
    }
    return array_values(array_filter(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['products'], static fn($p)=>!empty($p['review']['spec_sources'])));
}
function fp_pdf_release_target(): void {
    if (get_option('home') !== 'https://freeplast.mliu.site' || get_option('siteurl') !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging' || (string)get_option('blog_public') !== '0') {
        throw new RuntimeException('Only non-indexed Freeplast staging is permitted.');
    }
}
function fp_pdf_release_read(int $id, bool $lock = false): array {
    global $wpdb;
    $suffix = $lock ? ' FOR UPDATE' : '';
    $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d" . $suffix, $id), ARRAY_A);
    if ($wpdb->last_error || !$post) { throw new RuntimeException('Unable to read product.'); }
    $attrs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_product_attributes' ORDER BY meta_id" . $suffix, $id), ARRAY_A);
    if ($wpdb->last_error || count($attrs) !== 1) { throw new RuntimeException('Exactly one existing attributes row required.'); }
    return array('post'=>$post, 'attributes'=>$attrs[0]);
}
function fp_pdf_release_hash(array $row): string {
    return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
/** Re-derive only the allowlisted fact fields, keeping unknown facts and all flags. */
function fp_pdf_release_expected(array $before, array $entry): array {
    if (($before['post']['post_type'] ?? '') !== 'product' || ($before['post']['post_name'] ?? '') !== $entry['slug'] || ($before['post']['post_password'] ?? '') !== '' || ($before['attributes']['meta_key'] ?? '') !== '_product_attributes' || $before['attributes']['post_id'] !== $before['post']['ID']) {
        throw new RuntimeException('Product identity mismatch.');
    }
    $attrs = unserialize($before['attributes']['meta_value'], array('allowed_classes'=>false));
    if (!is_array($attrs)) { throw new RuntimeException('Invalid native attributes.'); }
    foreach (fp_pdf_fields() as $key=>$label) {
        $value = $entry['specs'][$key] ?? null;
        if ($value === null || $value === 'Consultar' || ($key === 'color' && $entry['options'])) { continue; }
        $name = sanitize_title($label);
        if (isset($attrs[$name])) {
            if (!is_array($attrs[$name]) || !empty($attrs[$name]['is_taxonomy']) || !empty($attrs[$name]['is_variation'])) { throw new RuntimeException('Technical/variation attribute conflict.'); }
            $attrs[$name]['value'] = (string)$value;
        } else {
            $attrs[$name] = array('name'=>$label, 'value'=>(string)$value, 'position'=>count($attrs), 'is_visible'=>1, 'is_variation'=>0, 'is_taxonomy'=>0);
        }
    }
    $expected = $before;
    $expected['post']['post_content'] = $entry['description'];
    $expected['post']['post_excerpt'] = $entry['excerpt'];
    $expected['attributes']['meta_value'] = serialize($attrs);
    return $expected;
}
function fp_pdf_release_validate(array $baseline, ?string $release = null): array {
    fp_pdf_release_target();
    if (($baseline['schema'] ?? '') !== 'freeplast-pdf-2026-v1' || !preg_match('/^[A-Za-z0-9_-]+$/D', $baseline['release'] ?? '') || ($release !== null && $baseline['release'] !== $release) || !preg_match('/^[a-f0-9]{64}$/D', $baseline['original_digest'] ?? '')) {
        throw new RuntimeException('Invalid release baseline.');
    }
    $review = json_decode(file_get_contents(__DIR__ . '/review.json'), true, 512, JSON_THROW_ON_ERROR);
    $entries = fp_pdf_release_catalog();
    if (count($entries) !== 15 || count($baseline['products'] ?? array()) !== 15 || count($review) !== 15) { throw new RuntimeException('Expected 15 reviewed identities.'); }
    foreach ($entries as $i=>$entry) {
        $p = $baseline['products'][$i];
        if ($review[$i]['source_id'] !== $entry['source_id'] || (int)$p['before']['post']['ID'] !== $review[$i]['id'] || fp_pdf_release_hash($p['before']) !== $review[$i]['before_sha256'] || $p['after'] !== fp_pdf_release_expected($p['before'], $entry)) {
            throw new RuntimeException('Baseline differs from sealed reviewed delta.');
        }
    }
    return $baseline;
}
function fp_pdf_release_capture(string $release): array {
    fp_pdf_release_target();
    $review = json_decode(file_get_contents(__DIR__ . '/review.json'), true, 512, JSON_THROW_ON_ERROR);
    $baseline = array('schema'=>'freeplast-pdf-2026-v1', 'release'=>$release, 'original_digest'=>str_repeat('0',64), 'products'=>array());
    foreach (fp_pdf_release_catalog() as $i=>$entry) {
        $before = fp_pdf_release_read($review[$i]['id']);
        $baseline['products'][] = array('before'=>$before, 'after'=>fp_pdf_release_expected($before, $entry));
    }
    fp_pdf_release_validate($baseline, $release);
    $baseline['original_digest'] = fp_pdf_release_digest(null);
    return $baseline;
}
/** Partial or foreign states never qualify for apply/retry. */
function fp_pdf_release_state(array $baseline, bool $lock = false): ?string {
    $before = true; $after = true;
    foreach ($baseline['products'] as $p) {
        $current = fp_pdf_release_read((int)$p['before']['post']['ID'], $lock);
        $before = $before && $current === $p['before'];
        $after = $after && $current === $p['after'];
    }
    return $before ? 'before' : ($after ? 'after' : null);
}
/** Exact expected whole rows project back; every unexpected byte stays inspectable. */
function fp_pdf_release_project(string $table, array $row, ?array $baseline): array {
    if ($baseline && in_array($table, array('posts','postmeta'), true)) {
        $key = $table === 'posts' ? 'post' : 'attributes';
        foreach ($baseline['products'] as $p) { if ($row === $p['after'][$key]) { return $p['before'][$key]; } }
    }
    return $row;
}
/** Byte-for-byte owner-workspace fingerprint contract, plus the bounded projection above. */
function fp_pdf_release_digest(?array $baseline): string {
    global $wpdb;
    $hash = hash_init('sha256');
    $tables = array(
        'posts'=>'ID', 'postmeta'=>'meta_id', 'comments'=>'comment_ID', 'commentmeta'=>'meta_id',
        'terms'=>'term_id', 'termmeta'=>'meta_id', 'term_taxonomy'=>'term_taxonomy_id',
        'term_relationships'=>'object_id,term_taxonomy_id', 'users'=>'ID', 'usermeta'=>'umeta_id',
        'woocommerce_order_items'=>'order_item_id', 'woocommerce_order_itemmeta'=>'meta_id',
        'wc_orders'=>'id', 'wc_orders_meta'=>'id', 'wc_order_addresses'=>'id', 'wc_order_operational_data'=>'id',
    );
    foreach ($tables as $suffix=>$key) {
        $table = $wpdb->prefix . $suffix;
        $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error) { throw new RuntimeException('Unable to inspect protected table.'); }
        if (!$present) { hash_update($hash, $suffix . ':absent\n'); continue; }
        hash_update($hash, $suffix . "\n");
        for ($offset=0; ; $offset+=500) {
            $rows = $wpdb->get_results("SELECT * FROM `$table` ORDER BY $key LIMIT 500 OFFSET " . (int)$offset, ARRAY_A);
            if ($wpdb->last_error) { throw new RuntimeException('Unable to read protected records.'); }
            foreach ($rows as $row) { hash_update($hash, wp_json_encode(fp_pdf_release_project($suffix, $row, $baseline)) . "\n"); }
            if (count($rows) < 500) { break; }
        }
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ('home','siteurl','blog_public','" . $wpdb->prefix . "user_roles') ORDER BY option_name",
        $wpdb->esc_like('fpw_') . '%', $wpdb->esc_like('woocommerce_') . '%', $wpdb->esc_like('qwc_') . '%'
    ), ARRAY_A);
    if ($wpdb->last_error) { throw new RuntimeException('Unable to read protected commercial settings.'); }
    foreach ($rows as $row) { hash_update($hash, wp_json_encode($row) . "\n"); }
    return hash_final($hash);
}
