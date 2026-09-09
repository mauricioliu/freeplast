<?php
/** One-off native media import. Never loaded by the public theme/plugin. */
final class Freeplast_Catalog_Photos {
    public static function plan(string $manifest_path): array {
        $manifest = json_decode(file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1 || count($manifest['products'] ?? []) !== 17) {
            throw new RuntimeException('Expected the reviewed 17-product photo manifest.');
        }
        $rows = array(); $seen = array();
        foreach ($manifest['products'] as $entry) {
            $source = $entry['source_id'];
            if (!is_string($source) || !preg_match('/^fp-[a-z0-9-]+$/D', $source)
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['previous_sha256'] ?? '')
                || !is_string($entry['alt'] ?? null) || '' === trim($entry['alt'])
                || !is_string($entry['caption'] ?? null) || '' === trim($entry['caption'])) {
                throw new RuntimeException('Invalid media identity, checksum or caption.');
            }
            if (isset($seen[$source]) || !preg_match('/^[a-z0-9-]+\.webp$/D', $entry['file'])) {
                throw new RuntimeException('Duplicate product or unsafe media filename.');
            }
            $seen[$source] = true;
            $file = dirname($manifest_path) . '/' . $entry['file'];
            $size = is_file($file) ? getimagesize($file) : false;
            if (!$size || $size[0] !== $entry['width'] || $size[1] !== $entry['height'] || $size['mime'] !== 'image/webp'
                || hash_file('sha256', $file) !== $entry['sha256'] || empty($entry['provisional'])) {
                throw new RuntimeException('Invalid photo asset: ' . $entry['slug']);
            }
            $ids = get_posts(array('post_type'=>'product', 'post_status'=>'any', 'numberposts'=>-1,
                'fields'=>'ids', 'meta_key'=>'_fp_source_id', 'meta_value'=>$source));
            if (count($ids) !== 1) { throw new RuntimeException('Expected exactly one product for ' . $source); }
            $product = wc_get_product($ids[0]);
            if (!$product || $product->get_sku() !== $source) { throw new RuntimeException('Product identity changed: ' . $source); }
            $current = (int) $product->get_image_id();
            $original = $current ? wp_get_original_image_path($current) : false;
            $hash = $original && is_file($original) ? hash_file('sha256', $original) : '';
            if (!in_array($hash, array($entry['previous_sha256'], $entry['sha256']), true)) {
                throw new RuntimeException('Photo changed by merchant or missing; refusing overwrite: ' . $source);
            }
            $rows[] = array('product_id'=>(int)$ids[0], 'current_id'=>$current, 'current_sha256'=>$hash,
                'status'=>$hash === $entry['sha256'] ? 'unchanged' : 'replace', 'file'=>$file, 'entry'=>$entry);
        }
        $seal = array('home'=>get_option('home'), 'manifest'=>hash_file('sha256', $manifest_path), 'rows'=>$rows);
        return array('token'=>hash('sha256', json_encode($seal, JSON_THROW_ON_ERROR)), 'home'=>$seal['home'], 'rows'=>$rows);
    }

    public static function apply(string $manifest_path, string $token, string $home): array {
        if (!add_option('fp_catalog_photo_import_lock', '2026-09-09', '', false)) {
            throw new RuntimeException('Another photo import owns the lock. Inspect it before recovery.');
        }
        $created = array(); $changed = array(); $linked = array();
        try {
            $plan = self::plan($manifest_path);
            if ($home !== $plan['home'] || !hash_equals($plan['token'], $token)) {
                throw new RuntimeException('Plan or target changed. Run plan again; no media imported.');
            }
            // Stage all attachments before switching ANY product. A failed sideload
            // cannot leave a half-replaced catalog. Existing media are never deleted.
            foreach ($plan['rows'] as $row) {
                if ('unchanged' === $row['status']) { continue; }
                $entry = $row['entry'];
                $tmp = wp_tempnam($entry['file']);
                if (!$tmp || !copy($row['file'], $tmp)) {
                    if ($tmp && is_file($tmp)) { unlink($tmp); }
                    throw new RuntimeException('Cannot stage photo.');
                }
                try {
                    $id = media_handle_sideload(array('name'=>$entry['file'], 'tmp_name'=>$tmp), 0, $entry['alt'],
                        array('post_excerpt'=>$entry['caption'], 'post_author'=>0, 'comment_status'=>'closed', 'ping_status'=>'closed', 'meta_input'=>array(
                            '_wp_attachment_image_alt'=>$entry['alt'], '_fp_image_provisional'=>'1',
                            '_fp_image_checksum'=>'sha256:' . $entry['sha256'],
                            '_fp_catalog_photo_source'=>wp_slash(json_encode($entry['source'], JSON_THROW_ON_ERROR)))));
                } finally { if (is_file($tmp)) { unlink($tmp); } }
                if (is_wp_error($id)) { throw new RuntimeException('Native media import failed for ' . $entry['slug']); }
                $created[] = (int)$id;
                $path = wp_get_original_image_path($id);
                if (!$path || hash_file('sha256', $path) !== $entry['sha256']) { throw new RuntimeException('Imported media hash mismatch.'); }
                $linked[$row['product_id']] = (int)$id;
            }
            // Recheck the entire plan after staging; do not overwrite a concurrent
            // merchant edit. Live use requires the release maintenance window.
            if (!hash_equals(self::plan($manifest_path)['token'], $token)) { throw new RuntimeException('Catalog changed while staging media.'); }
            foreach ($plan['rows'] as $row) {
                if ('unchanged' === $row['status']) { continue; }
                $id = $row['product_id'];
                $changed[$id] = $row['current_id'];
                if (!update_post_meta($id, '_thumbnail_id', $linked[$id])) { throw new RuntimeException('Cannot assign native featured image.'); }
                wc_delete_product_transients($id);
            }
            foreach (self::plan($manifest_path)['rows'] as $verified) {
                if ($verified['status'] !== 'unchanged') { throw new RuntimeException('Post-import verification failed.'); }
            }
            return array('changed'=>count($changed), 'unchanged'=>count($plan['rows'])-count($changed));
        } catch (Throwable $error) {
            foreach ($changed as $id=>$previous) {
                update_post_meta($id, '_thumbnail_id', $previous);
                wc_delete_product_transients($id);
                if ((int)get_post_meta($id, '_thumbnail_id', true) !== $previous) {
                    // Do not delete referenced media or release the lock on failed
                    // recovery. Operator must retain maintenance and use the backup.
                    throw new RuntimeException('Photo rollback failed; retain maintenance and lock. Use paired recovery.', 0, $error);
                }
            }
            foreach ($created as $id) { wp_delete_attachment($id, true); }
            delete_option('fp_catalog_photo_import_lock');
            throw $error;
        } finally {
            // A successful or pre-write failure is safe to unlock. A failed rollback
            // deliberately retains the lock; the catch above owns that decision.
            if (!isset($error)) { delete_option('fp_catalog_photo_import_lock'); }
        }
    }
}
