<?php
/** Core/$wpdb only: safe with plugins and themes skipped, including rollback. */
if (!defined('WP_CLI') || !WP_CLI || get_option('home') !== 'https://freeplast.mliu.site') { throw new RuntimeException('Wrong target'); }
if ('yes' === get_option('woocommerce_custom_orders_table_enabled')) { WP_CLI::error('Snapshot requires current CPT storage.'); }
$state_module = is_file(__DIR__ . '/migration/photo-release-state.php')
    ? __DIR__ . '/migration/photo-release-state.php' : __DIR__ . '/lib/photo-release-state.php';
require_once $state_module;
$input = file_get_contents('php://stdin');
$baseline = '' === trim($input) ? null : fp_photo_release_baseline($input);
echo fp_photo_release_fingerprint($baseline) . "\n";
