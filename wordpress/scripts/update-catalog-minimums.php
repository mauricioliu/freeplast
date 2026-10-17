<?php
/** One-shot staging migration, only after review, paired backup and paused writers. No runtime hooks. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required'); }
$args = $args ?? array();
$local_patch = !empty($fp_quantity_patch);
$patch_option = 'freeplast_public_quantities_pg22';
$help = 'wp eval-file wordpress/scripts/' . ($local_patch ? 'update-public-quantities.php' : 'update-catalog-minimums.php') . ' plan <new-private-receipt.json> | apply <receipt.json> <reviewed-token> | help | version';
if (count($args) === 1 && in_array($args[0], array('version','--version','-v','-V'), true)) { echo "1.0.0\n"; return; }
if (!$args || (count($args) === 1 && in_array($args[0], array('help','--help'), true))) { echo 'usage: ' . json_encode($help) . "\ndefault: no writes\n"; return; }
if (!(($args[0] === 'plan' && count($args) === 2) || ($args[0] === 'apply' && count($args) === 3))) { echo 'error: incorrect arguments' . "\nhelp: " . json_encode($help) . "\n"; WP_CLI::halt(2); }
$transaction = false; $locked = false; $commitAttempted = false; $ids = array();
try {
    require_once __DIR__ . '/lib/catalog-minimums.php';
    if ($local_patch) {
        if (!in_array(wp_get_environment_type(), array('local','development'), true) || getenv('FREEPLAST_DISPOSABLE_CATALOG') !== '1' || !class_exists('WooCommerce')) { throw new RuntimeException('Acknowledged disposable local/development Woo database required'); }
        if (get_option($patch_option)) { echo "status: already applied; merchant data untouched\n"; return; }
        $hash = hash('sha256', 'pg22-quantities-1|' . ABSPATH . '|' . get_option('home') . '|' . get_option('siteurl'));
        $plan = 'fp_public_quantity_plan';
    } else {
        if (untrailingslashit(get_option('home')) !== 'https://freeplast.mliu.site' || untrailingslashit(get_option('siteurl')) !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging' || !class_exists('WooCommerce')) { throw new RuntimeException('Isolated Freeplast staging and WooCommerce required'); }
        $source = __DIR__ . '/../data/products.json';
        $hash = hash_file('sha256', $source);
        if ($hash !== '1b3ef16a027b3f68b4704efb6c9d9f2d5e164a93c19f938fdf85cb0ecb38d088') { throw new RuntimeException('Unreviewed reference bytes'); }
        $catalog = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $plan = static fn()=>fp_minimum_plan($catalog);
    }
    $rows = $plan();
    $token = fp_pdf_token($rows, $hash);
    $parent = realpath(dirname($args[1])); $webroot = realpath(ABSPATH);
    if (!$parent || !$webroot || str_starts_with($parent . '/', $webroot . '/') || (fileperms($parent) & 0077)) { throw new RuntimeException('Receipt requires a private directory outside webroot'); }
    if ($args[0] === 'plan') {
        $mask = umask(0077); $file = fopen($args[1], 'x'); umask($mask);
        if (!$file) { throw new RuntimeException('Choose a new receipt path'); }
        $bytes = json_encode(array('source_hash'=>$hash, 'token'=>$token, 'rows'=>$rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($file, $bytes); $flushed = fflush($file); fclose($file);
        if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; do not apply'); }
        echo 'products: ' . count($rows) . "\nchanged: " . count(array_filter($rows, static fn($r)=>$r['before'] !== $r['after'])) . "\ntoken: $token\nreceipt: " . json_encode($args[1]) . "\n";
        return;
    }
    if (!is_file($args[1]) || is_link($args[1]) || (fileperms($args[1]) & 0077)) { throw new RuntimeException('Receipt must be a private regular file'); }
    $receipt = json_decode(file_get_contents($args[1]), true, 512, JSON_THROW_ON_ERROR);
    if (($receipt['source_hash'] ?? '') !== $hash || !hash_equals($token, $args[2]) || ($receipt['token'] ?? '') !== $token || fp_pdf_token($receipt['rows'] ?? array(), $hash) !== $token) { throw new RuntimeException('Stale/altered plan; review a new plan, no writes'); }
    if (!is_file(ABSPATH . '.maintenance') || getenv('FREEPLAST_CATALOG_BACKUP_VERIFIED') !== '1' || getenv('FREEPLAST_CATALOG_WRITERS_PAUSED') !== '1') { throw new RuntimeException('Maintenance, verified paired backup and paused writers required; flags acknowledge evidence, not proof'); }
    global $wpdb;
    $lockName = 'freeplast_minimums_' . substr(hash('sha256', ABSPATH), 0, 20);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== '1') { throw new RuntimeException('Catalog lock unavailable'); }
    $locked = true;
    $engines = $wpdb->get_col('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'');
    if (!$engines || array_filter($engines, static fn($e)=>strtolower((string)$e) !== 'innodb')) { throw new RuntimeException('InnoDB required'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot begin transaction'); }
    $transaction = true;
    $ids = array_column($rows, 'id');
    foreach ($rows as $row) { $ids = array_merge($ids, array_keys($row['children'])); }
    $list = implode(',', array_map('intval', $ids));
    if ($wpdb->query('SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . $list . ') FOR UPDATE') === false || $wpdb->query('SELECT meta_id FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . $list . ') FOR UPDATE') === false) { throw new RuntimeException('Cannot lock catalog'); }
    foreach ($ids as $id) { clean_post_cache($id); }
    if (!hash_equals($token, fp_pdf_token($plan(), $hash))) { throw new RuntimeException('Catalog drift after review'); }
    foreach ($rows as $row) { if ($row['before'] !== $row['after']) { fp_minimum_apply(wc_get_product($row['id']), $row['after']); } }
    foreach ($ids as $id) { clean_post_cache($id); }
    foreach ($plan() as $i=>$row) {
        if ($row['before'] !== $row['after'] || json_encode($row['children']) !== json_encode($rows[$i]['children']) || json_encode(fp_minimum_protected($row['product'])) !== json_encode(fp_minimum_protected($rows[$i]['product']))) { throw new RuntimeException('Unexpected post-save delta'); }
    }
    if ($local_patch && !add_option($patch_option, 'pg22-quantities-1', '', false)) { throw new RuntimeException('Cannot record completed migration'); }
    $commitAttempted = true;
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit outcome uncertain; inspect, never replay automatically'); }
    $transaction = false;
    echo "status: applied; retain maintenance until independent protected-state verification\n";
} catch (Throwable $error) {
    if ($transaction && !$commitAttempted) {
        $wpdb->query('ROLLBACK');
        if ($local_patch) { wp_cache_delete($patch_option, 'options'); wp_cache_delete('alloptions', 'options'); }
    }
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: retain maintenance; inspect database and paired backup before any retry\n";
    WP_CLI::halt(1);
} finally {
    foreach ($ids as $id) { clean_post_cache($id); wc_delete_product_transients($id); }
    if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)); }
}
