<?php
/** Offline importer tests: real sealed files, mocked WordPress persistence. */
require __DIR__ . '/lib/color-photos.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejects($fn, $message) { try { $fn(); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), $message), $e->getMessage()); return; } throw new RuntimeException('Expected rejection: ' . $message); }
function wp_get_environment_type() { return $GLOBALS['environment'] ?? 'local'; }
function home_url() { return $GLOBALS['home'] ?? 'http://mliu:8131'; }
class ColorPhotoProduct {
    public $image = 0;
    function __construct(public $type, public $parent, public $slug, public $color) {}
    function is_type($type) { return $this->type === $type; }
    function get_parent_id() { return $this->parent; }
    function get_slug() { return $this->slug; }
    function get_attributes() { return array('color'=>$this->color); }
    function get_image_id($context) { check($context === 'edit', 'Must not inherit parent photo'); return $this->image; }
    function set_image_id($id) { $this->image = $id; }
    function save() { $GLOBALS['saves']++; }
}
$manifest = __DIR__ . '/../data/color-photos/manifest.json';
$data = json_decode(file_get_contents($manifest), true);
$products = array(); $attachments = array(); $saves = 0; $uploads = 0;
foreach ($data['images'] as $row) {
    $parent = str_contains($row['parent_slug'], 'ventilada') ? 23 : 16;
    $products[$parent] = new ColorPhotoProduct('variable', 0, $row['parent_slug'], '');
    $products[$row['variation_id']] = new ColorPhotoProduct('variation', $parent, '', $row['color']);
}
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
function get_attached_file($id) { return $GLOBALS['attachments'][$id]['file'] ?? false; }
function get_posts($args) { return array_keys(array_filter($GLOBALS['attachments'], fn($a) => $a['hash'] === $args['meta_value'])); }
function wp_upload_bits($name, $unused, $bytes) { $GLOBALS['uploads']++; return array('file'=>__DIR__ . '/../data/color-photos/' . $name, 'error'=>false); }
function wp_insert_attachment($data, $file, $parent, $error) { $id = 100 + count($GLOBALS['attachments']); $GLOBALS['attachments'][$id] = array('file'=>$file, 'hash'=>$data['meta_input']['_fp_color_photo_sha256']); return $id; }
function is_wp_error($value) { return false; }
function wp_generate_attachment_metadata($id, $file) { return array('width'=>960); }
function wp_update_attachment_metadata($id, $metadata) {}
$plan = fp_color_photos_plan($manifest);
check(count($plan['rows']) === 10, 'Both five-color families validated');
check($uploads === 0 && $saves === 0, 'Planning is read only');
rejects(fn()=>fp_color_photos_apply($manifest, 'stale', $plan['home']), 'Stale');
rejects(fn()=>fp_color_photos_apply($manifest, $plan['token'], 'https://example.com'), 'incorrect home');
$environment = 'production'; rejects(fn()=>fp_color_photos_plan($manifest), 'Local'); $environment = 'local';
$home = 'http://other'; rejects(fn()=>fp_color_photos_plan($manifest), 'home'); $home = $plan['home'];
$products[17]->color = 'Negro'; rejects(fn()=>fp_color_photos_plan($manifest), 'identity'); $products[17]->color = 'Blanco';
$products[17]->image = 999; rejects(fn()=>fp_color_photos_plan($manifest), 'conflict'); $products[17]->image = 0;
$result = fp_color_photos_apply($manifest, $plan['token'], $plan['home']);
check($result === array('changed'=>10, 'unchanged'=>0), 'All missing photos attached');
check($uploads === 10 && $saves === 10, 'Exactly one write per variation');
check($products[16]->image === 0 && $products[23]->image === 0, 'Parent records untouched');
rejects(fn()=>fp_color_photos_apply($manifest, $plan['token'], $plan['home']), 'Stale');
$next = fp_color_photos_plan($manifest);
check(fp_color_photos_apply($manifest, $next['token'], $next['home']) === array('changed'=>0, 'unchanged'=>10), 'Fresh repeat is a no-op');
$products[17]->image = 0;
$next = fp_color_photos_plan($manifest);
fp_color_photos_apply($manifest, $next['token'], $next['home']);
check($uploads === 10 && $saves === 11, 'Existing verified attachment reused after interrupted assignment');
echo "color photos: 10 sealed WebP files; local/home/identity/conflict/token guards, apply, no-op and attachment reuse passed\n";
