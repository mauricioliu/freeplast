<?php
/** Read-only staging fingerprint with the bounded sales-enrichment projection.
 * Same tables/options as owner-workspace-fingerprint.php, no plugin APIs. With a
 * MIGRATION-BASELINE on stdin it must validate AND re-derive from the closure (fail closed, never fingerprint
 * blind); the register and the one expected receipt project back to the original
 * only in the two exact coupled states. Empty stdin = plain standalone digest. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || get_option( 'home' ) !== 'https://freeplast.mliu.site' ) { throw new RuntimeException( 'Wrong staging target.' ); }
$closure_dir = is_file( __DIR__ . '/migration/policy.php' ) ? __DIR__ . '/migration' : __DIR__ . '/sales-enrichment-migration';
require_once $closure_dir . '/policy.php';
$stdin = (string) file_get_contents( 'php://stdin' );
$baseline = null;
if ( '' !== trim( $stdin ) ) {
	$parsed = json_decode( $stdin, true );
	if ( ! fpw_senr_baseline_valid( $parsed, null, (string) get_option( 'home' ) ) ) { throw new RuntimeException( 'Invalid or foreign MIGRATION-BASELINE on stdin; refusing to fingerprint blind.' ); }
	// Projections are permitted only for a baseline the sealed source itself derives (SEAL.json + enrichment.csv beside policy.php).
	fpw_senr_assert_matches_closure( $parsed, fpw_senr_load_closure( $closure_dir, (string) get_option( 'home' ) ), (string) get_option( 'home' ) );
	$baseline = $parsed;
}
echo fpw_senr_protected_digest( $baseline ) . "\n";
