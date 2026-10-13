<?php
/** Manual one-shot WP-CLI tool; never bootstrap/synchronize the catalog.
 * Read docs/catalog-pdf-2026/staging-runbook.md before use. No remote execution by this script.
 */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required'); }
$args = $args ?? array();
$help = 'wp eval-file wordpress/scripts/update-catalog-pdf.php plan <new-private-receipt.json> | apply <receipt.json> <reviewed-token> | help | version';
if (count($args) === 1 && in_array($args[0], array('version','--version','-v','-V'), true)) { echo "1.0.0\n"; return; }
if (!$args || (count($args) === 1 && in_array($args[0], array('help','--help'), true))) {
    echo 'usage: ' . json_encode($help) . "\ndefault: no writes; plan creates only a private local receipt\n"; return;
}
$mode = $args[0];
if (!(($mode === 'plan' && count($args) === 2) || ($mode === 'apply' && count($args) === 3))) {
    echo 'error: incorrect arguments' . "\nhelp: " . json_encode($help) . "\n"; WP_CLI::halt(2);
}
$transaction = false;
$locked = false;
try {
    if (untrailingslashit(get_option('home')) !== 'https://freeplast.mliu.site' || untrailingslashit(get_option('siteurl')) !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging') {
        throw new RuntimeException('Only the isolated Freeplast staging identity is allowed');
    }
    if (!class_exists('WooCommerce')) { throw new RuntimeException('WooCommerce required'); }
    $source = __DIR__ . '/catalog-pdf-release/products.json';
    $hash = hash_file('sha256', $source);
    if ($hash !== 'a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a') { throw new RuntimeException('Unreviewed reference bytes; stop and review a new patch'); }
    $catalog = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    require_once __DIR__ . '/lib/catalog-pdf.php';
    $rows = fp_pdf_plan($catalog);
    $token = fp_pdf_token($rows, $hash);
    $path = $args[1];
    $parent = realpath(dirname($path));
    $webroot = realpath(ABSPATH);
    if (!$parent || $parent === $webroot || str_starts_with($parent . '/', $webroot . '/')) { throw new RuntimeException('Receipt must be outside webroot in an existing private directory'); }
    if ($mode === 'plan') {
        $oldMask = umask(0077);
        $file = fopen($path, 'x');
        umask($oldMask);
        if (!$file) { throw new RuntimeException('Receipt exists or cannot be created; choose a fresh path'); }
        $bytes = json_encode(array('source_hash'=>$hash, 'token'=>$token, 'rows'=>$rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($file, $bytes);
        $flushed = fflush($file);
        fclose($file);
        if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; do not apply'); }
        echo "products: 15\nchanged: " . count(array_filter($rows, static fn($r)=>!empty($r['diff']))) . "\ntoken: $token\nreceipt: " . json_encode($path) . "\n";
        return;
    }
    $receipt = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($receipt['source_hash'] ?? '') !== $hash || !hash_equals($token, $args[2]) || ($receipt['token'] ?? '') !== $token || fp_pdf_token($receipt['rows'] ?? array(), $hash) !== $token) {
        throw new RuntimeException('Stale/altered/unreviewed plan; no writes. Generate and review a fresh plan');
    }
    if (!is_file(ABSPATH . '.maintenance') || getenv('FREEPLAST_CATALOG_BACKUP_VERIFIED') !== '1' || getenv('FREEPLAST_CATALOG_WRITERS_PAUSED') !== '1') {
        throw new RuntimeException('Apply requires maintenance, verified paired backup/rehearsal and paused writers; flags acknowledge operator evidence, not proof');
    }
    global $wpdb;
    $lockName = 'freeplast_pdf_2026_' . substr(hash('sha256', ABSPATH), 0, 20);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== '1') { throw new RuntimeException('Another catalog operation owns the lock'); }
    $locked = true;
    // Native saves touch several Woo tables; refuse any non-transactional table in this database.
    $engines = $wpdb->get_col('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'');
    if (!$engines || array_filter($engines, static fn($engine)=>strtolower((string)$engine) !== 'innodb')) { throw new RuntimeException('InnoDB required for all database tables'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot start transaction'); }
    $transaction = true;
    $ids = array_column($rows, 'id');
    foreach ($rows as $row) { $ids = array_merge($ids, array_keys($row['children'])); }
    if ($wpdb->query('SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode(',', array_map('intval', $ids)) . ') FOR UPDATE') === false) { throw new RuntimeException('Cannot lock product rows'); }
    foreach ($ids as $id) { clean_post_cache($id); }
    if (!hash_equals($token, fp_pdf_token(fp_pdf_plan($catalog), $hash))) { throw new RuntimeException('Catalog changed after review; no writes'); }
    $entries = array_column($catalog['products'], null, 'slug');
    $changed = 0;
    foreach ($rows as $row) {
        if (!$row['diff']) { continue; }
        $product = wc_get_product($row['id']);
        $entry = $entries[$row['slug']];
        $patch = fp_pdf_patch($product, $entry);
        $product->set_attributes($patch['attributes']);
        $product->set_description($entry['description']);
        $product->set_short_description($entry['excerpt']);
        if ($product->save() !== $row['id']) { throw new RuntimeException('Native product save failed'); }
        $changed++;
    }
    foreach ($ids as $id) { clean_post_cache($id); }
    $after = fp_pdf_plan($catalog);
    foreach ($after as $i=>$row) {
        if ($row['diff'] || json_encode($row['children']) !== json_encode($rows[$i]['children'])) { throw new RuntimeException('Post-save facts/variation verification failed'); }
        $before = $rows[$i]['before'];
        $current = $row['before'];
        // These are the only intended native fields; timestamps are Woo save bookkeeping.
        foreach (array('description','short_description','attributes','date_modified','date_modified_gmt') as $key) { unset($before[$key], $current[$key]); }
        if (json_encode($before) !== json_encode($current)) { throw new RuntimeException('Unexpected protected product delta'); }
    }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit outcome uncertain; inspect state, never replay automatically'); }
    $transaction = false;
    echo "changed: $changed\nunchanged: " . (15 - $changed) . "\nstatus: applied; keep maintenance until independent protected-state verification\n";
} catch (Throwable $error) {
    if ($transaction) {
        $wpdb->query('ROLLBACK');
        foreach ($ids ?? array() as $id) { clean_post_cache($id); wc_delete_product_transients($id); }
    }
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: retain maintenance; inspect database and backup before any retry\n";
    WP_CLI::halt(1);
} finally {
    if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)); }
}
