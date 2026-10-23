<?php
/** Local, additive variation-photo import. Never replaces an existing merchant image. */
function fp_color_photos_plan(string $manifest): array {
    if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local environment required.'); }
    $data = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    if (($data['home'] ?? '') !== home_url()) { throw new RuntimeException('Manifest home does not match this site.'); }
    if (empty($data['images']) || count($data['images']) > 12) { throw new RuntimeException('Expected 1–12 images.'); }
    $rows = array(); $seen = array();
    foreach ($data['images'] as $entry) {
        $id = (int) ($entry['variation_id'] ?? 0);
        if (!$id || isset($seen[$id])) { throw new RuntimeException('Invalid or duplicate variation.'); }
        $seen[$id] = true;
        $path = realpath(dirname($manifest) . '/' . ($entry['file'] ?? ''));
        if (!$path || dirname($path) !== realpath(dirname($manifest)) || !is_file($path)) { throw new RuntimeException('Image must be beside manifest.'); }
        $size = getimagesize($path);
        if (!$size || $size['mime'] !== 'image/webp' || $size[0] !== $entry['width'] || $size[1] !== $entry['height'] || hash_file('sha256', $path) !== $entry['sha256']) { throw new RuntimeException('Image hash, format or dimensions differ.'); }
        $variation = wc_get_product($id);
        $parent = $variation && $variation->is_type('variation') ? wc_get_product($variation->get_parent_id()) : null;
        if (!$parent || $parent->get_slug() !== $entry['parent_slug'] || $variation->get_attributes() !== array('color'=>$entry['color'])) { throw new RuntimeException('Variation identity differs: ' . $id); }
        $current = (int) $variation->get_image_id('edit');
        $file = $current ? get_attached_file($current) : false;
        if ($current && (!$file || !is_file($file) || hash_file('sha256', $file) !== $entry['sha256'])) { throw new RuntimeException('Existing image conflict: ' . $id); }
        $rows[] = array('id'=>$id, 'current'=>$current, 'path'=>$path, 'entry'=>$entry);
    }
    return array('home'=>$data['home'], 'rows'=>$rows, 'token'=>hash('sha256', json_encode(array($data['home'], $rows))));
}

function fp_color_photos_apply(string $manifest, string $token, string $home): array {
    $plan = fp_color_photos_plan($manifest);
    if ($home !== $plan['home'] || !hash_equals($plan['token'], $token)) { throw new RuntimeException('Stale plan token or incorrect home.'); }
    $changed = 0; $unchanged = 0;
    foreach ($plan['rows'] as $row) {
        if ($row['current']) { $unchanged++; continue; }
        $entry = $row['entry'];
        // Reuse a verified attachment after an interrupted import; never replay an uncertain upload.
        $attachments = get_posts(array('post_type'=>'attachment', 'post_status'=>'inherit', 'numberposts'=>-1, 'fields'=>'ids', 'meta_key'=>'_fp_color_photo_sha256', 'meta_value'=>$entry['sha256']));
        $attachment = 0;
        foreach ($attachments as $candidate) {
            $file = get_attached_file($candidate);
            if ($file && is_file($file) && hash_file('sha256', $file) === $entry['sha256']) { $attachment = (int) $candidate; break; }
        }
        if (!$attachment) {
            $upload = wp_upload_bits($entry['file'], null, file_get_contents($row['path']));
            if (!empty($upload['error'])) { throw new RuntimeException('Upload failed: ' . $upload['error']); }
            $attachment = wp_insert_attachment(array('post_mime_type'=>'image/webp', 'post_title'=>$entry['parent_slug'] . ' · ' . $entry['color'], 'post_status'=>'inherit', 'meta_input'=>array('_fp_color_photo_sha256'=>$entry['sha256'], '_wp_attachment_image_alt'=>'Caja Universal ' . (str_contains($entry['parent_slug'], 'ventilada') ? 'Ventilada' : 'Cerrada') . ' en ' . $entry['color'] . ' — fotografía referencial')), $upload['file'], 0, true);
            if (is_wp_error($attachment) || !$attachment) { throw new RuntimeException('Attachment creation failed; inspect uploaded file before retry: ' . $upload['file']); }
            $metadata = wp_generate_attachment_metadata($attachment, $upload['file']);
            if (empty($metadata)) { throw new RuntimeException('Attachment metadata failed; inspect attachment ' . $attachment . ' before retry.'); }
            wp_update_attachment_metadata($attachment, $metadata);
        }
        $variation = wc_get_product($row['id']);
        if ((int) $variation->get_image_id('edit') !== 0) { throw new RuntimeException('Concurrent image change: ' . $row['id']); }
        $variation->set_image_id($attachment);
        $variation->save();
        if ((int) wc_get_product($row['id'])->get_image_id('edit') !== (int) $attachment) { throw new RuntimeException('Image save unconfirmed: ' . $row['id']); }
        $changed++;
    }
    return array('changed'=>$changed, 'unchanged'=>$unchanged);
}
