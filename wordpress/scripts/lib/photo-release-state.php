<?php
/** Expected media delta over the ORIGINAL full-record fingerprint, not a new
 * baseline after import. Usable with plugins/themes skipped for recovery. */
function fp_photo_release_manifest_path(): string {
    return is_file(__DIR__ . '/photos/manifest.json') ? __DIR__ . '/photos/manifest.json'
        : __DIR__ . '/../../data/catalog-photos/manifest.json';
}
function fp_photo_release_rows(): array {
    global $wpdb;
    $tables = array('posts'=>'ID', 'postmeta'=>'meta_id', 'comments'=>'comment_ID', 'commentmeta'=>'meta_id',
        'woocommerce_order_items'=>'order_item_id', 'woocommerce_order_itemmeta'=>'meta_id');
    $all = array();
    foreach ($tables as $table=>$id) {
        $where = 'postmeta' === $table ? " WHERE meta_key NOT IN ('_edit_lock','_edit_last')" : '';
        $all[$table] = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table}{$where} ORDER BY {$id}", ARRAY_A);
        if ($wpdb->last_error) { throw new RuntimeException('Protected-record query failed.'); }
    }
    return $all;
}
function fp_photo_release_digest(array $tables): string {
    $hash = hash_init('sha256');
    foreach ($tables as $table=>$rows) { hash_update($hash, $table . wp_json_encode(array_values($rows))); }
    return hash_final($hash);
}
function fp_photo_release_baseline(string $json): array {
    $baseline = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (($baseline['schema'] ?? '') !== 'freeplast-photo-delta-v1'
        || ($baseline['manifest_sha256'] ?? '') !== hash_file('sha256', fp_photo_release_manifest_path())
        || ($baseline['home'] ?? '') !== get_option('home')
        || count($baseline['products'] ?? array()) !== 17
        || !is_int($baseline['max_post_id'] ?? null) || !is_int($baseline['max_meta_id'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $baseline['original_digest'] ?? '')) {
        throw new RuntimeException('Invalid sealed photo baseline or target.');
    }
    return $baseline;
}
function fp_photo_release_capture(array $plan, string $release): array {
    $tables = fp_photo_release_rows();
    $baseline = array('schema'=>'freeplast-photo-delta-v1', 'release'=>$release, 'home'=>get_option('home'),
        'manifest_sha256'=>hash_file('sha256', fp_photo_release_manifest_path()),
        'max_post_id'=>max(array_map('intval', array_column($tables['posts'], 'ID')) ?: array(0)),
        'max_meta_id'=>max(array_map('intval', array_column($tables['postmeta'], 'meta_id')) ?: array(0)),
        'original_digest'=>fp_photo_release_digest($tables), 'products'=>array());
    foreach ($plan['rows'] as $row) {
        $thumbs = array_values(array_filter($tables['postmeta'], static fn($m)=>(int)$m['post_id'] === $row['product_id'] && $m['meta_key'] === '_thumbnail_id'));
        if (count($thumbs) !== 1 || (int)$thumbs[0]['meta_value'] !== $row['current_id']) { throw new RuntimeException('Ambiguous thumbnail metadata.'); }
        $baseline['products'][] = array('product_id'=>$row['product_id'], 'source_id'=>$row['entry']['source_id'],
            'original_image_id'=>$row['current_id'], 'original_image_sha256'=>$row['current_sha256'],
            'thumbnail_meta_id'=>(int)$thumbs[0]['meta_id']);
    }
    return $baseline;
}
/** Only newly-created, exactly expected native image records can be projected
 * away. Unknown or invalid rows stay in the digest so corruption still yields
 * an inspectable fingerprint, including on a failed/partial migration. */
function fp_photo_release_valid_new_image(array $post, array $meta, array $entry, array $baseline): bool {
    if ((int)$post['ID'] <= $baseline['max_post_id'] || $post['post_type'] !== 'attachment'
        || $post['post_mime_type'] !== 'image/webp' || $post['post_status'] !== 'inherit'
        || (int)$post['post_parent'] !== 0 || $post['post_title'] !== $entry['alt']
        || $post['post_excerpt'] !== $entry['caption'] || $post['post_content'] !== ''
        || (int)$post['post_author'] !== 0 || (int)$post['menu_order'] !== 0 || (int)$post['comment_count'] !== 0
        || $post['comment_status'] !== 'closed' || $post['ping_status'] !== 'closed'
        || $post['post_password'] !== '' || $post['to_ping'] !== '' || $post['pinged'] !== '' || $post['post_content_filtered'] !== '') { return false; }
    $values = array();
    foreach ($meta as $m) {
        if ((int)$m['meta_id'] <= $baseline['max_meta_id'] || isset($values[$m['meta_key']])) { return false; }
        $values[$m['meta_key']] = $m['meta_value'];
    }
    $keys = array('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_image_alt','_fp_image_provisional','_fp_image_checksum','_fp_catalog_photo_source');
    if (count($values) !== count($keys) || array_diff($keys, array_keys($values))) { return false; }
    if ($values['_wp_attachment_image_alt'] !== $entry['alt'] || $values['_fp_image_provisional'] !== '1'
        || $values['_fp_image_checksum'] !== 'sha256:' . $entry['sha256']
        || json_decode($values['_fp_catalog_photo_source'], true) !== $entry['source']) { return false; }
    $slug = preg_quote(pathinfo($entry['file'], PATHINFO_FILENAME), '/');
    if (!preg_match('/^(?:[0-9]{4}\/[0-9]{2}\/)?' . $slug . '(?:-[0-9]+)?\.webp$/D', $values['_wp_attached_file'])) { return false; }
    $metadata = @unserialize($values['_wp_attachment_metadata'], array('allowed_classes'=>false));
    if (!is_array($metadata) || ($metadata['width'] ?? 0) !== $entry['width'] || ($metadata['height'] ?? 0) !== $entry['height']
        || ($metadata['file'] ?? '') !== $values['_wp_attached_file']) { return false; }
    $file = wp_get_original_image_path((int)$post['ID']);
    if (!$file || !is_file($file) || hash_file('sha256', $file) !== $entry['sha256']) { return false; }
    // A present original cannot conceal broken/path-escaping native thumbnails.
    foreach ($metadata['sizes'] ?? array() as $size) {
        if (!is_array($size) || !preg_match('/^[a-z0-9-]+[.]webp$/D', $size['file'] ?? '')) { return false; }
        $thumbnail = dirname($file) . '/' . $size['file'];
        $dimensions = is_file($thumbnail) ? getimagesize($thumbnail) : false;
        if (!$dimensions || $dimensions[0] !== $size['width'] || $dimensions[1] !== $size['height'] || $dimensions['mime'] !== 'image/webp') { return false; }
    }
    return true;
}
function fp_photo_release_fingerprint(?array $baseline = null): string {
    $tables = fp_photo_release_rows();
    if (!$baseline) { return fp_photo_release_digest($tables); }
    $manifest = json_decode(file_get_contents(fp_photo_release_manifest_path()), true, 512, JSON_THROW_ON_ERROR);
    $entries = array_column($manifest['products'], null, 'source_id');
    $posts = array_column($tables['posts'], null, 'ID');
    $meta = array_column($tables['postmeta'], null, 'meta_id');
    $by_post = array();
    foreach ($meta as $m) { $by_post[$m['post_id']][] = $m; }
    $allowed = array();
    foreach ($baseline['products'] as $old) {
        $m = $meta[$old['thumbnail_meta_id']] ?? null;
        if (!$m || (int)$m['post_id'] !== $old['product_id'] || $m['meta_key'] !== '_thumbnail_id') { continue; }
        $image_id = (int)$m['meta_value'];
        if ($image_id === $old['original_image_id']) { continue; }
        $entry = $entries[$old['source_id']] ?? null;
        if (!$entry || !isset($posts[$image_id]) || isset($allowed[$image_id])
            || !fp_photo_release_valid_new_image($posts[$image_id], $by_post[$image_id] ?? array(), $entry, $baseline)) { continue; }
        $allowed[$image_id] = true;
        $meta[$old['thumbnail_meta_id']]['meta_value'] = (string)$old['original_image_id'];
    }
    $tables['posts'] = array_filter($tables['posts'], static fn($row)=>!isset($allowed[(int)$row['ID']]));
    $tables['postmeta'] = array_filter($meta, static fn($row)=>!isset($allowed[(int)$row['post_id']]));
    return fp_photo_release_digest($tables);
}
