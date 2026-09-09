<?php
/** Execute ONLY in the isolated, network-blocked copy created by the runner. */
if (!defined('WP_CLI') || !WP_CLI || !defined('FP_PHOTO_FIXTURE') || !FP_PHOTO_FIXTURE
    || wp_get_environment_type() !== 'local') { throw new RuntimeException('Isolated photo fixture required.'); }
require __DIR__ . '/lib/catalog-photos.php';
require __DIR__ . '/lib/photo-release-state.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$manifest = __DIR__ . '/../data/catalog-photos/manifest.json';
$count = 0;
$check = static function($ok, $label) use (&$count) { if (!$ok) { throw new RuntimeException($label); } $count++; };
$reject = static function($callback, $message) use ($check) {
    try { $callback(); } catch (RuntimeException $error) { $check(str_contains($error->getMessage(), $message), $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
};
$state = static function() {
    global $wpdb;
    // All existing product/order data, including gallery and variation media;
    // only the intended featured-media pointers are excluded.
    return array(
        $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type <> 'attachment' ORDER BY ID", ARRAY_A),
        $wpdb->get_results("SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE p.post_type <> 'attachment' AND m.meta_key <> '_thumbnail_id' ORDER BY m.meta_id", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_items ORDER BY order_item_id", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_order_itemmeta ORDER BY meta_id", ARRAY_A),
    );
};
// The generic stack fixture is keyed by slug, not the historical import SKU.
// Recreate that migration provenance ONLY in this isolated copy (including the
// two real placeholder attachments and checksum-shared Universal image).
$originals = json_decode(file_get_contents(__DIR__ . '/../data/products.json'), true)['products'];
$seeded_media = array();
foreach ($originals as $entry) {
    $ids = get_posts(array('post_type'=>'product', 'post_status'=>'any', 'name'=>$entry['slug'], 'numberposts'=>-1, 'fields'=>'ids'));
    if (count($ids) !== 1) { throw new RuntimeException('Missing fixture product: ' . $entry['slug']); }
    $product = wc_get_product($ids[0]);
    $product->set_sku($entry['source_id']);
    $product->update_meta_data('_fp_source_id', $entry['source_id']);
    $hash = $entry['image']['checksum'];
    if (!isset($seeded_media[$hash])) {
        $file = __DIR__ . '/../data/' . $entry['image']['file'];
        $tmp = wp_tempnam(basename($file)); copy($file, $tmp);
        $attachment = media_handle_sideload(array('name'=>basename($file), 'tmp_name'=>$tmp), 0);
        if (is_wp_error($attachment)) { throw new RuntimeException('Fixture source media failed.'); }
        $seeded_media[$hash] = $attachment;
    }
    $product->set_image_id($seeded_media[$hash]); $product->save();
}
$before = $state();
$plan = Freeplast_Catalog_Photos::plan($manifest);
$baseline = fp_photo_release_capture($plan, 'photo-fixture');
$baseline = fp_photo_release_baseline(wp_json_encode($baseline));
$check(fp_photo_release_fingerprint($baseline) === $baseline['original_digest'], 'baseline is the original full-record fingerprint');
$check(count($plan['rows']) === 17, '17 native product matches');
$check(count(array_filter($plan['rows'], static fn($r)=>$r['status'] === 'replace')) === 17, '17 replacements initially');
$check(!get_option('fp_catalog_photo_import_lock'), 'plan is read-only');
$reject(static fn()=>Freeplast_Catalog_Photos::apply($manifest, str_repeat('0',64), $plan['home']), 'Plan or target changed');
$reject(static fn()=>Freeplast_Catalog_Photos::apply($manifest, $plan['token'], 'https://wrong.example'), 'Plan or target changed');
$check(!get_option('fp_catalog_photo_import_lock'), 'stale/wrong-target failures release their lock');
add_option('fp_catalog_photo_import_lock', 'other-owner');
$reject(static fn()=>Freeplast_Catalog_Photos::apply($manifest, $plan['token'], $plan['home']), 'Another photo import');
$check(get_option('fp_catalog_photo_import_lock') === 'other-owner', 'foreign lock untouched');
delete_option('fp_catalog_photo_import_lock');

$attachments = get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids'));
$uploads = 0;
$fail_upload = static function($file) use (&$uploads) { if (++$uploads === 3) { $file['error']='Fixture failure'; } return $file; };
add_filter('wp_handle_sideload_prefilter', $fail_upload);
$reject(static fn()=>Freeplast_Catalog_Photos::apply($manifest, $plan['token'], $plan['home']), 'Native media import failed');
remove_filter('wp_handle_sideload_prefilter', $fail_upload);
$check(Freeplast_Catalog_Photos::plan($manifest)['token'] === $plan['token'], 'failed staging never switches products');
$check(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids')) === $attachments, 'failed staging deletes only its new attachments');

// Assignment failure after one successful update exercises rollback, not just
// the no-write staging failure. Core short-circuit filter fails exactly once.
$writes = 0;
$fail_assignment = static function($value, $id, $key, $new) use (&$writes) {
    if ($key === '_thumbnail_id' && ++$writes === 2) { return false; }
    return $value;
};
add_filter('update_post_metadata', $fail_assignment, 10, 4);
$reject(static fn()=>Freeplast_Catalog_Photos::apply($manifest, $plan['token'], $plan['home']), 'Cannot assign native featured image');
remove_filter('update_post_metadata', $fail_assignment, 10);
$check(Freeplast_Catalog_Photos::plan($manifest)['token'] === $plan['token'], 'failed assignment restores all original pointers');
$check(get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids')) === $attachments, 'failed assignment cleans its attachments');
$check($state() === $before, 'failure paths preserve existing product/order data');

$result = Freeplast_Catalog_Photos::apply($manifest, $plan['token'], $plan['home']);
$check($result === array('changed'=>17,'unchanged'=>0), 'native sideload and assignment for all 17 products');
$check($state() === $before, 'all non-media product data, variations and requests unchanged');
$after = Freeplast_Catalog_Photos::plan($manifest);
$check(fp_photo_release_fingerprint() !== $baseline['original_digest'], 'unprojected full fingerprint detects the real media change');
$check(fp_photo_release_fingerprint($baseline) === $baseline['original_digest'], 'ONLY the expected 17 native media changes project to the original baseline');
$check(count(array_filter($after['rows'], static fn($r)=>$r['status'] === 'unchanged')) === 17, 'all new original files match manifest hashes');
$check(Freeplast_Catalog_Photos::apply($manifest, $after['token'], $after['home']) === array('changed'=>0,'unchanged'=>17), 'repeat import is a no-op');
$universals = array();
foreach ($after['rows'] as $row) {
    $id = $row['current_id'];
    $entry = $row['entry'];
    $check(get_post_meta($id, '_wp_attachment_image_alt', true) === $entry['alt'], 'native alt for ' . $entry['slug']);
    $check(wp_get_attachment_caption($id) === $entry['caption'], 'native caption for ' . $entry['slug']);
    $check(json_decode(get_post_meta($id, '_fp_catalog_photo_source', true), true) === $entry['source'], 'native source JSON keeps Unicode/escaping for ' . $entry['slug']);
    $check((bool)wp_get_attachment_image_src($id, 'woocommerce_thumbnail'), 'native card thumbnail for ' . $entry['slug']);
    if (str_starts_with($entry['slug'], 'caja-universal-')) { $universals[] = $id; }
}
$check(count(array_unique($universals)) === 4, 'four distinct native Universal attachments');
$changed_product = $after['rows'][0]['product_id'];
update_post_meta($changed_product, '_photo_unrelated_metadata', 'not allowed');
$check(fp_photo_release_fingerprint($baseline) !== $baseline['original_digest'], 'unrelated product metadata remains protected');
delete_post_meta($changed_product, '_photo_unrelated_metadata');
$original_price = get_post_meta($changed_product, '_regular_price', true);
update_post_meta($changed_product, '_regular_price', '999');
$check(fp_photo_release_fingerprint($baseline) !== $baseline['original_digest'], 'commercial price change remains protected');
update_post_meta($changed_product, '_regular_price', $original_price);
$new_order = wp_insert_post(array('post_type'=>'shop_order','post_status'=>'wc-pending','post_title'=>'Unexpected request'));
$check(fp_photo_release_fingerprint($baseline) !== $baseline['original_digest'], 'a new request cannot be hidden as an expected attachment');
wp_delete_post($new_order, true);
$new_image = $after['rows'][0]['current_id'];
update_post_meta($new_image, '_unexpected_media_note', 'not allowed');
$check(fp_photo_release_fingerprint($baseline) !== $baseline['original_digest'], 'extra new-attachment metadata is not exempted');
delete_post_meta($new_image, '_unexpected_media_note');
$old_image = $plan['rows'][0]['current_id'];
update_post_meta($old_image, '_unexpected_media_note', 'not allowed');
$check(fp_photo_release_fingerprint($baseline) !== $baseline['original_digest'], 'original media stay fully protected');
delete_post_meta($old_image, '_unexpected_media_note');
$check(fp_photo_release_fingerprint($baseline) === $baseline['original_digest'], 'repair restores original expected-delta digest, not a rewritten baseline');
// Merchant replaces a photo: even old product provenance cannot authorize an overwrite.
$row = $after['rows'][0];
update_post_meta($row['product_id'], '_thumbnail_id', 0);
$reject(static fn()=>Freeplast_Catalog_Photos::plan($manifest), 'Photo changed by merchant');
update_post_meta($row['product_id'], '_thumbnail_id', $row['current_id']);
$check(!get_option('fp_catalog_photo_import_lock'), 'successful import releases its lock');
// Leave this private copy on original pointers so the runner can exercise
// the sealed capture/apply/verify entry point over a fresh real migration.
foreach ($plan['rows'] as $original) {
    update_post_meta($original['product_id'], '_thumbnail_id', $original['current_id']);
    wc_delete_product_transients($original['product_id']);
}
echo "native_photo_checks: $count\nnetwork: blocked\nserver: not started\nvisual_acceptance: false\n";
