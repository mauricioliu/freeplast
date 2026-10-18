<?php
/** Bounded CLI-only DEV operator; never boot plugins, cron, mail or HTTP. Output is PRIVATE. */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if (!in_array($mode, array('preview', 'stage', 'cancel', 'confirm'), true)) { throw new RuntimeException('Expected preview|stage|cancel|confirm.'); }
if ($mode !== 'preview' && getenv('FPW_DEV_WRITE_RELEASE') !== 'plan-mvdqvx1t-tlr8') { throw new RuntimeException('Lead write release missing.'); }
define('SHORTINIT', true);
require '/var/www/html/wp-load.php';
$urls = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('home','siteurl')");
if ($urls !== array('http://mliu:8131', 'http://mliu:8131') || DB_NAME !== 'freeplast_local' || !defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE !== 'local') { throw new RuntimeException('Wrong DEV target.'); }
require_once '/var/www/html/wp-content/plugins/freeplast-woo/sales-register.php';
$content = file_get_contents('/var/freeplast/sales-tlr8/source.xlsx');
if (hash('sha256', $content) !== '4b52e5bcba620e8f5a9b2211275f0b182c0f413851ad565c3e8c0ee7f61a7572') { throw new RuntimeException('Workbook hash mismatch.'); }
if (count(fpw_sales_workbook_legacy()) !== 356) { throw new RuntimeException('Authenticated evidence unavailable.'); }
$parsed = fpw_sales_parse_xlsx($content);
if (empty($parsed['ok'])) { throw new RuntimeException('Workbook parse failed.'); }
$register = fpw_sales_register();
$pending = fpw_sales_pending_batch();
$proposal = $pending ?? ($parsed['batch'] + array('filename' => 'Ventas Freeplast.xlsx', 'token' => 'readonly-preview'));
$plan = fpw_sales_workbook_changes($proposal, $register['sales']);
$counts = array(count($plan['new']), count($plan['enrich']), count($plan['conflicts']), count($plan['pending']));
if ($mode === 'preview') {
    echo json_encode(array('sales' => count($register['sales']), 'counts' => $counts, 'plan' => $plan), JSON_THROW_ON_ERROR), "\n";
    exit;
}
if ($mode === 'cancel') {
    $result = fpw_sales_cancel($argv[2] ?? '');
} else {
    if ($counts !== array(966,319,15,161) || count($register['sales']) !== 356) { throw new RuntimeException('Unexpected delta; STOP.'); }
    if ($mode === 'stage') {
        if (fpw_sales_pending_batch() !== null) { throw new RuntimeException('Existing preview must not be replaced.'); }
        $result = fpw_sales_process_upload_string($content, 'Ventas Freeplast.xlsx');
        $result['pending'] = fpw_sales_pending_batch();
        if (!empty($result['ok'])) { $result['preview'] = array('plan' => fpw_sales_workbook_changes($result['pending'], $register['sales'])); }
    } else {
        $pending = fpw_sales_pending_batch();
        if (!is_array($pending) || ($pending['source_sha256'] ?? '') !== hash('sha256', $content) || ($pending['format'] ?? '') !== 'xlsx' || ($pending['rows'] ?? null) !== $parsed['batch']['rows']) { throw new RuntimeException('Staged source differs; STOP.'); }
        $result = fpw_sales_confirm($argv[2] ?? '');
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
if (empty($result['ok'])) { exit(2); } // Inspect database and receipt; NEVER automatically retry.
