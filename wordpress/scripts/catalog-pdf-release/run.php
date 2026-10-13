<?php
/** wp-release protocol entry point; baseline stays private and sealed by the driver. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__ . '/policy.php';
$mode = $args[0] ?? ''; $release = $args[1] ?? '';
if (count($args ?? array()) !== 2 || !in_array($mode, array('capture','apply','verify'), true) || !preg_match('/^[A-Za-z0-9_-]+$/D', $release)) {
    throw new RuntimeException('Expected capture|apply|verify and release ID.');
}
fp_pdf_release_target();
if ($mode === 'capture') {
    $native = fp_pdf_plan(array('products'=>fp_pdf_release_catalog()));
    $baseline = fp_pdf_release_capture($release);
    if (array_column($native, 'id') !== array_map(static fn($p)=>(int)$p['before']['post']['ID'], $baseline['products'])) { throw new RuntimeException('Native identity drift.'); }
    echo wp_json_encode($baseline);
    return;
}
$baseline = fp_pdf_release_validate(json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR), $release);
if (@file_get_contents(ABSPATH . '.wp-release-lock/owner') !== $release || !is_file(ABSPATH . '.maintenance') || !is_file(ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php')) {
    throw new RuntimeException('Release-owned maintenance guard required.');
}
if (fp_pdf_release_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Protected records changed; retain maintenance.'); }
if ($mode === 'apply') {
    global $wpdb;
    $engines = $wpdb->get_col('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'');
    if ($wpdb->last_error || !$engines || array_filter($engines, static fn($v)=>strtolower((string)$v) !== 'innodb')) { throw new RuntimeException('InnoDB required for all tables.'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot begin transaction.'); }
    try {
        $state = fp_pdf_release_state($baseline, true);
        if ($state === null) { throw new RuntimeException('Partial or foreign product state; inspect, never replay.'); }
        if (fp_pdf_release_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Protected drift under lock.'); }
        if ($state === 'before') {
            foreach ($baseline['products'] as $p) {
                if ($p['before']['post'] !== $p['after']['post']) {
                    $count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_content = %s, post_excerpt = %s WHERE ID = %d AND BINARY post_content = BINARY %s AND BINARY post_excerpt = BINARY %s",
                        $p['after']['post']['post_content'], $p['after']['post']['post_excerpt'], (int)$p['before']['post']['ID'], $p['before']['post']['post_content'], $p['before']['post']['post_excerpt']
                    ));
                    if ($count !== 1) { throw new RuntimeException('Product compare-and-swap failed.'); }
                }
                if ($p['before']['attributes'] !== $p['after']['attributes']) {
                    $count = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND BINARY meta_value = BINARY %s",
                        $p['after']['attributes']['meta_value'], (int)$p['before']['attributes']['meta_id'], $p['before']['attributes']['meta_value']
                    ));
                    if ($count !== 1) { throw new RuntimeException('Attributes compare-and-swap failed.'); }
                }
            }
        }
        if (fp_pdf_release_state($baseline) !== 'after' || fp_pdf_release_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Unexpected delta; abort transaction.'); }
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit outcome uncertain; inspect, never replay automatically.'); }
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        throw $error;
    } finally {
        foreach ($baseline['products'] as $p) { clean_post_cache((int)$p['before']['post']['ID']); }
    }
}
if (fp_pdf_release_state($baseline) !== 'after' || fp_pdf_release_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Expected state missing; retain maintenance.'); }
foreach (fp_pdf_plan(array('products'=>fp_pdf_release_catalog())) as $row) {
    if ($row['diff']) { throw new RuntimeException('Native Woo facts disagree with expected delta.'); }
}
echo "catalog_pdf_verified: 15 products; protected fingerprint unchanged\n";
