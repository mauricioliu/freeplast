<?php
/** Sealed wp-release migration entry point: complete the sales history with the
 * approved enrichment (missing detail fields only) plus one deterministic,
 * consultable enrichment receipt. See policy.php for the closure contract.
 *
 * capture  read-only; works with the OLD plugin loaded; verifies target, seal and
 *          source reconciliation and prints the immutable baseline (private raw
 *          register inside: store it ONLY in the private backup, never log it).
 * apply    baseline on stdin; owned maintenance guard; re-derives from the sealed
 *          closure; one transaction with compare-and-swap on the exact raw rows;
 *          idempotent when the exact expected state already exists.
 * verify   read-only exact-state check.
 * Counts and hashes only on stdout/stderr; no request, mail or status side effects. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'WP-CLI required.' ); }
require_once __DIR__ . '/policy.php';
$mode = $args[0] ?? '';
$release = $args[1] ?? '';
if ( count( $args ?? array() ) !== 2 || ! in_array( $mode, array( 'capture', 'apply', 'verify' ), true ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $release ) ) {
	throw new RuntimeException( 'Expected capture|apply|verify and a release ID.' );
}
$home = (string) get_option( 'home' );
if ( fpw_senr_home() !== $home ) { throw new RuntimeException( 'This sales enrichment is scoped to Freeplast staging.' ); }

if ( 'capture' === $mode ) {
	echo wp_json_encode( fpw_senr_capture_baseline( __DIR__, $release, $home ) );
	return;
}

$baseline = json_decode( (string) file_get_contents( 'php://stdin' ), true );
if ( ! fpw_senr_baseline_valid( $baseline, $release, $home ) ) { throw new RuntimeException( 'Invalid or foreign migration baseline; refusing.' ); }
if ( @file_get_contents( ABSPATH . '.wp-release-lock/owner' ) !== $release
	|| ! is_file( ABSPATH . '.maintenance' ) || ! is_file( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' ) ) {
	throw new RuntimeException( 'Migration requires its own release-owned maintenance guard.' );
}
fpw_senr_assert_matches_closure( $baseline, fpw_senr_load_closure( __DIR__, $home ), $home );
if ( fpw_senr_protected_digest( $baseline ) !== $baseline['original_digest'] ) { throw new RuntimeException( 'Unexpected record delta before the operation; retain maintenance.' ); }

/** Exact-state report: 'original' | 'expected' | null (anything else). */
$state_of = static function () use ( $baseline ): ?string {
	$reg = fpw_senr_option_raw( fpw_senr_register_name() );
	$rec = fpw_senr_option_raw( $baseline['receipt_name'] );
	if ( $reg === $baseline['register_original_raw'] && null === $rec ) { return 'original'; }
	if ( $reg === $baseline['register_expected_raw'] && $rec === $baseline['receipt_raw'] ) { return 'expected'; }
	return null;
};

if ( 'apply' === $mode ) {
	wp_set_current_user( 0 );
	$state = $state_of();
	if ( 'original' === $state ) {
		if ( null !== fpw_senr_option_raw( fpw_senr_pending_name() ) ) { throw new RuntimeException( 'A pending import appeared; refusing.' ); }
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
		if ( ! is_string( $engine ) || 'innodb' !== strtolower( $engine ) ) { throw new RuntimeException( 'The options table is not transactional (InnoDB required); refusing.' ); }
		$fail = static function ( string $why ) use ( $wpdb ) { $wpdb->query( 'ROLLBACK' ); throw new RuntimeException( $why ); };
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'Unable to start a transaction; nothing applied.' ); }
		try {
			// Compare-and-swap on the exact raw bytes (BINARY: collation-proof); locked read first.
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", fpw_senr_register_name() ) );
			if ( $locked !== $baseline['register_original_raw'] ) { $fail( 'The register changed since capture; nothing applied.' ); }
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$baseline['register_expected_raw'], fpw_senr_register_name(), $baseline['register_original_raw']
			) );
			if ( 1 !== $updated ) { $fail( 'Register compare-and-swap lost; nothing applied.' ); }
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$baseline['receipt_name'], $baseline['receipt_raw']
			) );
			if ( 1 !== $inserted ) { $fail( 'Receipt insert failed; nothing applied.' ); }
			if ( 'expected' !== $state_of() ) { $fail( 'Post-write state is not the exact expected delta; rolled back.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { $fail( 'COMMIT failed; rolled back, nothing applied.' ); }
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		echo 'sales_enrichment_applied: ' . (int) $baseline['counts']['enrich'] . ' sales, ' . (int) $baseline['counts']['fields'] . " fields\n";
	} elseif ( 'expected' === $state ) {
		echo 'sales_enrichment_already: ' . (int) $baseline['counts']['enrich'] . " sales\n";
	} else {
		throw new RuntimeException( 'Register/receipt are neither the captured original nor the exact expected result; refusing (conflict).' );
	}
}

if ( 'expected' !== $state_of() ) { throw new RuntimeException( 'Verification failed: expected register and receipt are not in place.' ); }
if ( null !== fpw_senr_option_raw( fpw_senr_pending_name() ) ) { throw new RuntimeException( 'Verification failed: a pending import exists.' ); }
if ( fpw_senr_protected_digest( $baseline ) !== $baseline['original_digest'] ) { throw new RuntimeException( 'Unexpected record delta after the operation; retain maintenance.' ); }
echo 'sales_enrichment_verified: ' . (int) $baseline['counts']['enrich'] . " sales\n";
