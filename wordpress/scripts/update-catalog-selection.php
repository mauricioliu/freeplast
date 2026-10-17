<?php
/** Explicit, receipt-bound one-shot update. No runtime hooks or historical JSON import. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required'); }
$args = $args ?? array();
$help = 'wp eval-file wordpress/scripts/update-catalog-selection.php plan <new-private-receipt.json> | apply <receipt.json> <reviewed-token> | help | version';
if (count($args) === 1 && in_array($args[0], array('version','--version','-v','-V'), true)) { echo "1.0.0\n"; return; }
if (!$args || (count($args) === 1 && in_array($args[0], array('help','--help'), true))) { echo 'usage: ' . json_encode($help) . "\ndefault: no writes\n"; return; }
if (!(($args[0] === 'plan' && count($args) === 2) || ($args[0] === 'apply' && count($args) === 3))) { echo "error: incorrect arguments\nhelp: " . json_encode($help) . "\n"; WP_CLI::halt(2); }
$transaction = false; $locked = false; $commitAttempted = false; $ids = array();
try {
    if (!in_array(wp_get_environment_type(), array('local','development'), true) || getenv('FREEPLAST_DISPOSABLE_CATALOG') !== '1' || !class_exists('WooCommerce')) { throw new RuntimeException('Explicitly acknowledged disposable local/development Woo database required'); }
    require_once __DIR__ . '/lib/catalog-selection.php';
    if (get_option(FP_SELECTION_OPTION)) { echo "status: already applied; current merchant data untouched\n"; return; }
    $identity = FP_SELECTION_VERSION . '|' . ABSPATH . '|' . get_option('home') . '|' . get_option('siteurl');
    $rows = fp_selection_plan();
    $token = fp_pdf_token($rows, $identity);
    $parent = realpath(dirname($args[1])); $webroot = realpath(ABSPATH);
    if (!$parent || !$webroot || str_starts_with($parent . '/', $webroot . '/') || (fileperms($parent) & 0077)) { throw new RuntimeException('Receipt requires a private directory outside webroot'); }
    if ($args[0] === 'plan') {
        $mask = umask(0077); $file = fopen($args[1], 'x'); umask($mask);
        if (!$file) { throw new RuntimeException('Choose a new receipt path'); }
        $bytes = json_encode(array('token'=>$token, 'rows'=>$rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($file, $bytes); $flushed = fflush($file); fclose($file);
        if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; do not apply'); }
        echo 'products: ' . count($rows) . "\ntoken: $token\nreceipt: " . json_encode($args[1]) . "\n";
        return;
    }
    if (!is_file($args[1]) || is_link($args[1]) || (fileperms($args[1]) & 0077)) { throw new RuntimeException('Receipt must be a private regular file'); }
    $receipt = json_decode(file_get_contents($args[1]), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals($token, $args[2]) || ($receipt['token'] ?? '') !== $token || fp_pdf_token($receipt['rows'] ?? array(), $identity) !== $token) { throw new RuntimeException('Stale/altered plan; review a new plan, no writes'); }
    if (!is_file(ABSPATH . '.maintenance') || getenv('FREEPLAST_CATALOG_BACKUP_VERIFIED') !== '1' || getenv('FREEPLAST_CATALOG_WRITERS_PAUSED') !== '1') { throw new RuntimeException('Maintenance, verified paired backup and paused writers required; flags acknowledge evidence, not proof'); }
    global $wpdb;
    $lockName = 'freeplast_selection_' . substr(hash('sha256', ABSPATH), 0, 20);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== '1') { throw new RuntimeException('Catalog lock unavailable'); }
    $locked = true;
    $engines = $wpdb->get_col('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'');
    if (!$engines || array_filter($engines, static fn($e)=>strtolower((string)$e) !== 'innodb')) { throw new RuntimeException('InnoDB required'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot begin transaction'); }
    $transaction = true;
    $ids = array_column($rows, 'id');
    foreach ($rows as $row) { $ids = array_merge($ids, array_keys($row['children'])); }
    $list = implode(',', array_map('intval', $ids));
    foreach (array($wpdb->posts=>'ID', $wpdb->postmeta=>'post_id', $wpdb->term_relationships=>'object_id') as $table=>$column) {
        if ($wpdb->query("SELECT $column FROM $table WHERE $column IN ($list) FOR UPDATE") === false) { throw new RuntimeException('Cannot lock catalog'); }
    }
    foreach ($ids as $id) { clean_post_cache($id); }
    if (!hash_equals($token, fp_pdf_token(fp_selection_plan(), $identity))) { throw new RuntimeException('Catalog drift after review'); }
    fp_selection_apply($rows);
    foreach ($ids as $id) { clean_post_cache($id); }
    fp_selection_verify($rows);
    if (!add_option(FP_SELECTION_OPTION, FP_SELECTION_VERSION, '', false)) { throw new RuntimeException('Cannot record completed migration'); }
    $commitAttempted = true;
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit outcome uncertain; inspect, never replay automatically'); }
    $transaction = false;
    echo "status: applied; retain maintenance until protected-state verification\n";
} catch (Throwable $error) {
    if ($transaction && !$commitAttempted) { $wpdb->query('ROLLBACK'); wp_cache_delete(FP_SELECTION_OPTION, 'options'); wp_cache_delete('alloptions', 'options'); }
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: retain maintenance; inspect database and paired backup before any retry\n";
    WP_CLI::halt(1);
} finally {
    foreach ($ids as $id) { clean_post_cache($id); wc_delete_product_transients($id); }
    if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)); }
}
