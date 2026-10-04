<?php
/** Sealed wp-release migration entry point: the tier-DATA staging roles delta
 * (fpw_quotation_manager + fpw_data_manager, exactly as quotation-access.php
 * registers them on boot). capture is read-only and prints the immutable
 * baseline (raw serialized roles policy before/after, target, release, schema,
 * full original digest identical to the standalone fingerprint on the same
 * state); apply/verify consume that exact snapshot via stdin, require the
 * release-owned maintenance guard, write ONLY the roles option, tolerate the
 * plugin init having added the roles first (idempotent, no second write), and
 * fail closed on any deviation, drift or conflicting preexisting role.
 * Never loaded by HTTP. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'WP-CLI required.' ); }
require_once __DIR__ . '/role-policy.php';
$mode = $args[0] ?? '';
$release = $args[1] ?? '';
if ( count( $args ?? array() ) !== 2 || ! in_array( $mode, array( 'capture', 'apply', 'verify' ), true ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $release ) ) {
	throw new RuntimeException( 'Expected capture|apply|verify and a release ID.' );
}
$home = (string) get_option( 'home' );
if ( 'https://freeplast.mliu.site' !== $home ) {
	throw new RuntimeException( 'This role migration is scoped to Freeplast staging.' );
}

if ( 'capture' === $mode ) {
	echo wp_json_encode( fpw_role_policy_capture_baseline( $release, $home ) );
	return;
}

$baseline = json_decode( (string) file_get_contents( 'php://stdin' ), true );
if ( ! fpw_role_policy_baseline_valid( $baseline, $release, $home ) ) {
	throw new RuntimeException( 'Invalid or foreign migration baseline; refusing.' );
}
if ( @file_get_contents( ABSPATH . '.wp-release-lock/owner' ) !== $release
	|| ! is_file( ABSPATH . '.maintenance' ) || ! is_file( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' ) ) {
	throw new RuntimeException( 'Migration requires its own release-owned maintenance guard.' );
}
if ( fpw_role_policy_protected_digest( $baseline ) !== $baseline['original_digest'] ) {
	throw new RuntimeException( 'Unexpected record delta before the role operation; retain maintenance.' );
}

if ( 'apply' === $mode ) {
	wp_set_current_user( 0 );
	$current_raw = fpw_role_policy_roles_option_raw();
	if ( null === $current_raw ) { throw new RuntimeException( 'The user_roles option is missing.' ); }
	if ( $current_raw === $baseline['roles_original_raw'] ) {
		$expected = @unserialize( $baseline['roles_expected_raw'], array( 'allowed_classes' => false ) );
		if ( ! is_array( $expected ) || serialize( $expected ) !== $baseline['roles_expected_raw'] ) {
			throw new RuntimeException( 'Unusable expected policy in baseline.' );
		}
		update_option( $GLOBALS['wpdb']->prefix . 'user_roles', $expected );
		echo 'role_migration_applied: ' . count( (array) $baseline['added_roles'] ) . "\n";
	} elseif ( $current_raw === $baseline['roles_expected_raw'] ) {
		// The plugin init already registered the same two roles before this apply:
		// the durable state already IS the approved delta — idempotent, no write.
		echo 'role_migration_already: ' . count( (array) $baseline['added_roles'] ) . "\n";
	} else {
		throw new RuntimeException( 'Current roles are neither the captured original nor the exact expected delta; refusing (conflict).' );
	}
}

if ( fpw_role_policy_roles_option_raw() !== $baseline['roles_expected_raw'] ) {
	throw new RuntimeException( 'Role verification failed: expected policy not in place.' );
}
if ( fpw_role_policy_protected_digest( $baseline ) !== $baseline['original_digest'] ) {
	throw new RuntimeException( 'Unexpected record delta after the role operation; retain maintenance.' );
}
echo 'role_migration_verified: ' . count( (array) $baseline['added_roles'] ) . "\n";
