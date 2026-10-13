<?php
require __DIR__ . '/config.php';
$deps = json_decode(file_get_contents('/opt/vendor/dependencies.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array('woocommerce', 'quotes-for-woocommerce') as $slug) {
    $dep = $deps[$slug] ?? null;
    if (!is_array($dep) || !preg_match('/\A[a-f0-9]{64}\z/D', $dep['sha256'] ?? '') ||
        !hash_equals($dep['sha256'], (string) @hash_file('sha256', '/opt/vendor/' . $slug . '.zip'))) {
        throw new RuntimeException('Pinned local vendor archive missing or changed: ' . $slug);
    }
}
