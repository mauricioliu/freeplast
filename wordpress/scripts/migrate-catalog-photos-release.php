<?php
/** Sealed wp-release migration entry point. capture is read-only; apply/verify
 * consume the immutable backup baseline via stdin. Never loaded by HTTP. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
$bundled = is_file(__DIR__ . '/catalog-photos.php');
require_once ($bundled ? __DIR__ : __DIR__ . '/lib') . '/catalog-photos.php';
require_once ($bundled ? __DIR__ : __DIR__ . '/lib') . '/photo-release-state.php';
$mode = $args[0] ?? ''; $release = $args[1] ?? '';
if (count($args ?? array()) !== 2 || !in_array($mode, array('capture','apply','verify'), true) || !preg_match('/^[A-Za-z0-9_-]+$/D', $release)) {
    throw new RuntimeException('Expected capture|apply|verify and a release ID.');
}
if (get_option('home') !== 'https://freeplast.mliu.site' && !(defined('FP_PHOTO_FIXTURE') && FP_PHOTO_FIXTURE)) {
    throw new RuntimeException('This photo migration is scoped to Freeplast staging.');
}
$manifest = fp_photo_release_manifest_path();
$plan = Freeplast_Catalog_Photos::plan($manifest);
if ($mode === 'capture') {
    echo wp_json_encode(fp_photo_release_capture($plan, $release));
    return;
}
$baseline = fp_photo_release_baseline(file_get_contents('php://stdin'));
if ($baseline['release'] !== $release || @file_get_contents(ABSPATH . '.wp-release-lock/owner') !== $release
    || !is_file(ABSPATH . '.maintenance') || !is_file(ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php')) {
    throw new RuntimeException('Migration requires its own release baseline and maintenance guard.');
}
if (fp_photo_release_fingerprint($baseline) !== $baseline['original_digest']) {
    throw new RuntimeException('Unexpected record delta before media operation; retain maintenance.');
}
// The native importer can only change the same captured identities. Neither a
// fresh importer plan nor merchant edits can broaden the backup's allowlist.
foreach ($plan['rows'] as $index=>$row) {
    $old = $baseline['products'][$index];
    if ($row['product_id'] !== $old['product_id'] || $row['entry']['source_id'] !== $old['source_id']) {
        throw new RuntimeException('Product identity differs from captured baseline.');
    }
}
if ($mode === 'apply') {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_set_current_user(0);
    $result = Freeplast_Catalog_Photos::apply($manifest, $plan['token'], $baseline['home']);
    echo 'photo_migration_changed: ' . $result['changed'] . "\n";
    $plan = Freeplast_Catalog_Photos::plan($manifest);
}
foreach ($plan['rows'] as $row) {
    if ($row['status'] !== 'unchanged'
        || get_post_meta($row['current_id'], '_wp_attachment_image_alt', true) !== $row['entry']['alt']
        || wp_get_attachment_caption($row['current_id']) !== $row['entry']['caption']) {
        throw new RuntimeException('Native photo verification failed: ' . $row['entry']['slug']);
    }
}
if (fp_photo_release_fingerprint($baseline) !== $baseline['original_digest']) {
    throw new RuntimeException('Unexpected record delta after media operation; retain maintenance.');
}
echo "photo_migration_verified: 17\n";
