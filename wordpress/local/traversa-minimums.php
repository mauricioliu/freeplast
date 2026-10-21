<?php
/** Explicit DEV-only catalog edit: set the three traversa minimums to 20.
 * Native Woo metadata remains authoritative; no runtime override or seed/reset. */
$args = $args ?? [];
if (count($args) === 1 && in_array($args[0], ['version', '--version', '-v', '-V'], true)) { echo "1.0.0\n"; return; }
if (count($args) === 1 && in_array($args[0], ['help', '--help'], true)) {
    echo "description: Set DEV traversa minimums to 20 without rewriting other catalog data\nusage: wp eval-file /opt/freeplast/traversa-minimums.php plan | apply <reviewed-token>\ndefault: plan (no writes)\nhelp: Apply requires a verified private DEV backup; retain the generated before-state receipt\n";
    return;
}
$mode = $args[0] ?? 'plan';
if (!in_array($mode, ['plan', 'apply'], true) || ($mode === 'plan' && count($args) > 1) || ($mode === 'apply' && count($args) !== 2)) {
    echo "error: Invalid arguments\nhelp: wp eval-file /opt/freeplast/traversa-minimums.php plan | apply <reviewed-token> | --help | --version\n";
    WP_CLI::halt(2);
}
try {
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || !function_exists('fpw_local_capture_active') || !fpw_local_capture_active()
    || home_url() !== 'http://mliu:8131' || realpath(__DIR__) !== '/opt/freeplast') {
    throw new RuntimeException('Only the contained Freeplast DEV CLI may run this update.');
}
global $wpdb;
$rows = [];
foreach (['traversa-para-bins-tipo-g1', 'traversa-para-bins-tipo-upc', 'traversa-para-bins-tipo-romano'] as $slug) {
    $ids = get_posts(['post_type'=>'product', 'post_status'=>'publish', 'name'=>$slug, 'numberposts'=>2, 'fields'=>'ids']);
    if (count($ids) !== 1 || !get_post_meta($ids[0], '_fp_local_seed_complete', true)
        || get_post_field('post_name', $ids[0]) !== $slug) {
        throw new RuntimeException('Missing/ambiguous seeded DEV identity: ' . $slug);
    }
    $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_fp_quote_min_qty'", $ids[0]), ARRAY_A);
    if (count($meta) !== 1 || !ctype_digit($meta[0]['meta_value']) || (int)$meta[0]['meta_value'] < 1) {
        throw new RuntimeException('Missing/duplicate/invalid minimum: ' . $slug);
    }
    $rows[] = ['id'=>(int)$ids[0], 'slug'=>$slug, 'meta_id'=>(int)$meta[0]['meta_id'], 'before'=>$meta[0]['meta_value'], 'after'=>'20'];
}
$token = hash('sha256', home_url() . '|' . hash_file('sha256', __FILE__) . '|' . wp_json_encode($rows));
if ($mode === 'plan') {
    echo "products[3]{slug,before,after}:\n";
    foreach ($rows as $row) { echo '  ' . $row['slug'] . ',' . $row['before'] . ",20\n"; }
    echo "token: $token\nhelp: wp eval-file /opt/freeplast/traversa-minimums.php apply <reviewed-token> (verified private DEV backup required)\n";
    return;
}
if (!hash_equals($token, (string)($args[1] ?? '')) || getenv('FREEPLAST_LOCAL_BACKUP_VERIFIED') !== '1') {
    throw new RuntimeException('Reviewed current token and verified private DEV backup required.');
}
$root = '/var/freeplast/receipts';
if (!is_dir($root) && !mkdir($root, 0700, true)) { throw new RuntimeException('Private receipt directory unavailable.'); }
if (is_link($root) || (fileperms($root) & 0077)) { throw new RuntimeException('Receipt directory must be private and not a symlink.'); }
$receipt = $root . '/traversa-minimums-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
$mask = umask(0077);
$file = fopen($receipt, 'x');
umask($mask);
if (!$file) { throw new RuntimeException('Cannot create before-state receipt.'); }
$bytes = wp_json_encode(['site'=>home_url(), 'rows'=>$rows, 'token'=>$token], JSON_PRETTY_PRINT);
$written = fwrite($file, $bytes); $flushed = fflush($file); fclose($file);
if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; no writes.'); }
$transaction = false; $commit_attempted = false;
try {
    $engines = $wpdb->get_col('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'');
    if (!$engines || array_filter($engines, static fn($e)=>strtolower($e) !== 'innodb')) { throw new RuntimeException('InnoDB required.'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot begin transaction.'); }
    $transaction = true;
    foreach ($rows as $row) {
        $current = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_fp_quote_min_qty' FOR UPDATE", $row['id']), ARRAY_A);
        if (count($current) !== 1 || (int)$current[0]['meta_id'] !== $row['meta_id'] || $current[0]['meta_value'] !== $row['before']) { throw new RuntimeException('Minimum changed since plan; no writes.'); }
        if ($row['before'] === '20') { continue; }
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value='20' WHERE meta_id=%d AND post_id=%d AND meta_key='_fp_quote_min_qty' AND BINARY meta_value=BINARY %s", $row['meta_id'], $row['id'], $row['before']));
        if ($changed !== 1) { throw new RuntimeException('Compare-and-swap refused; no partial writes.'); }
    }
    $commit_attempted = true;
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('COMMIT uncertain; inspect, never replay automatically.'); }
    $transaction = false;
} catch (Throwable $error) {
    if ($transaction && !$commit_attempted) { $wpdb->query('ROLLBACK'); }
    throw $error;
} finally {
    foreach ($rows as $row) { clean_post_cache($row['id']); wc_delete_product_transients($row['id']); }
}
foreach ($rows as $row) {
    if (get_post_meta($row['id'], '_fp_quote_min_qty', true) !== '20') { throw new RuntimeException('Post-commit verification failed; inspect receipt: ' . $receipt); }
}
echo "state: applied\nproducts: 3\nreceipt: $receipt\n";
} catch (Throwable $error) {
    echo 'error: ' . json_encode($error->getMessage()) . "\nhelp: Inspect current metadata and the private receipt before any retry; default plan makes no changes\n";
    WP_CLI::halt(1);
}
