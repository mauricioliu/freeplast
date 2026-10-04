<?php
/** Read-only staging release fingerprint with the bounded role-delta projection.
 * Standalone twin of owner-workspace-fingerprint.php: the SAME tables/options,
 * HPOS and every commercial row included, no plugin APIs. When the driver pipes
 * a MIGRATION-BASELINE on stdin, it MUST parse and validate (fail closed on any
 * malformed or foreign baseline — never a silent no-projection fallback); the
 * roles option is then projected back to its captured original ONLY while the
 * current raw value is byte-equal to the captured original or to the exact
 * expected delta, so `pre == post` means the protected invariant while a
 * corrupt state stays inspectable as a different digest (valid baseline,
 * divergent current value). Empty stdin (no migration configured) yields the
 * plain standalone digest. Roles are never excluded and the baseline is never
 * recaptured here. Prints exactly one 64-hex digest. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || get_option( 'home' ) !== 'https://freeplast.mliu.site' ) { throw new RuntimeException( 'Wrong staging target.' ); }
require_once is_file( __DIR__ . '/migration/role-policy.php' )
	? __DIR__ . '/migration/role-policy.php'
	: __DIR__ . '/staging-role-migration/role-policy.php';
$stdin = (string) file_get_contents( 'php://stdin' );
$baseline = null;
if ( '' !== trim( $stdin ) ) {
	$parsed = json_decode( $stdin, true );
	if ( ! is_array( $parsed ) || ! fpw_role_policy_baseline_valid( $parsed, null, (string) get_option( 'home' ) ) ) {
		throw new RuntimeException( 'Invalid or foreign MIGRATION-BASELINE on stdin; refusing to fingerprint blind.' );
	}
	$baseline = $parsed;
}
echo fpw_role_policy_protected_digest( $baseline ) . "\n";
