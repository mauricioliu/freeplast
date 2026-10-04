<?php
/** Sealed staging role-policy library (tier DATA release). Shared by
 * staging-role-migration/run.php and staging-release-fingerprint.php.
 *
 * No plugin APIs: only WP-CLI-era core helpers ($wpdb, wp_json_encode,
 * get_option). It defines the exact two-role delta the quotation-access init
 * creates on boot — nothing else — and the protected digest contract:
 *
 * The digest replicates owner-workspace-fingerprint.php BYTE FOR BYTE (same
 * tables/options, same wp_json_encode row hashing, same raw serialized option
 * values). The baseline carries the RAW serialized roles option both before
 * (roles_original_raw) and after (roles_expected_raw = serialize(original +
 * the two targets)) the delta. The ONLY projection: while the CURRENT raw
 * value is byte-equal to roles_original_raw or roles_expected_raw, the hashed
 * row carries roles_original_raw — so the original state hashes exactly like
 * the plain standalone fingerprint and the migrated state projects back to
 * the same digest. Any other value hashes as it stands (a deliberately
 * different, inspectable digest). Roles are never excluded and the baseline
 * is never recaptured here.
 *
 * Baseline validation here is STRUCTURAL ONLY (shape, target, release,
 * derivation). It is not tamper-proofing: the baseline's integrity comes from
 * the driver's sealed checksum manifest (SHA256SUMS covering MIGRATION-BASELINE) —
 * a checksum seal, not a cryptographic signature. JSON canonical forms are not
 * kept as a second authority: the raw serialized values are the authority. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The exact role shapes quotation-access.php's init registers on every boot. */
function fpw_role_policy_targets(): array {
	return array(
		'fpw_quotation_manager' => array( 'name' => 'Gestión de cotizaciones', 'capabilities' => array( 'read' => true, 'fpw_manage_quotations' => true ) ),
		'fpw_data_manager'      => array( 'name' => 'Mantenedor de datos', 'capabilities' => array( 'read' => true, 'fpw_manage_data' => true ) ),
	);
}

/** original + the two targets, appended; null on conflict/undecodable original. */
function fpw_role_policy_expected_from_original_raw( string $original_raw ): ?array {
	$original = @unserialize( $original_raw, array( 'allowed_classes' => false ) );
	if ( ! is_array( $original ) ) { return null; }
	$expected = $original;
	foreach ( fpw_role_policy_targets() as $key => $shape ) {
		if ( array_key_exists( $key, $original ) ) { return null; } // preexisting target role: fail closed
		$expected[ $key ] = $shape;
	}
	return $expected;
}

/** Raw serialized option value of the site's user_roles row, or null when absent. */
function fpw_role_policy_roles_option_raw(): ?string {
	global $wpdb;
	$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read the roles option.' ); }
	return is_string( $row ) ? $row : null;
}

/** Structural baseline validation (integrity itself comes from the signed manifest). */
function fpw_role_policy_baseline_valid( $baseline, ?string $release, string $home ): bool {
	if ( ! is_array( $baseline ) || 1 !== ( $baseline['schema'] ?? null ) ) { return false; }
	if ( (string) ( $baseline['target'] ?? '' ) !== $home ) { return false; }
	$baseline_release = (string) ( $baseline['release'] ?? '' );
	if ( '' === $baseline_release || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $baseline_release ) ) { return false; }
	if ( null !== $release && $baseline_release !== $release ) { return false; }
	if ( array_keys( fpw_role_policy_targets() ) !== array_values( (array) ( $baseline['added_roles'] ?? null ) ) ) { return false; }
	$original_raw = $baseline['roles_original_raw'] ?? null;
	$expected_raw = $baseline['roles_expected_raw'] ?? null;
	if ( ! is_string( $original_raw ) || '' === $original_raw || ! is_string( $expected_raw ) || '' === $expected_raw ) { return false; }
	$expected = fpw_role_policy_expected_from_original_raw( $original_raw );
	if ( null === $expected ) { return false; }
	if ( serialize( $expected ) !== $expected_raw ) { return false; }
	return is_string( $baseline['original_digest'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $baseline['original_digest'] );
}

/** One release baseline from the CURRENT (must be unmigrated) state. */
function fpw_role_policy_capture_baseline( string $release, string $home ): array {
	$original_raw = fpw_role_policy_roles_option_raw();
	if ( null === $original_raw ) { throw new RuntimeException( 'The user_roles option is missing.' ); }
	$expected = fpw_role_policy_expected_from_original_raw( $original_raw );
	if ( null === $expected ) { throw new RuntimeException( 'A target role already exists in the original policy; refusing to capture a conflicting baseline.' ); }
	$baseline = array(
		'schema'             => 1,
		'target'             => $home,
		'release'            => $release,
		'added_roles'        => array_keys( fpw_role_policy_targets() ),
		'roles_original_raw' => $original_raw,
		'roles_expected_raw' => serialize( $expected ),
	);
	$baseline['original_digest'] = fpw_role_policy_protected_digest( $baseline );
	return $baseline;
}

/**
 * The protected digest: EXACTLY owner-workspace-fingerprint.php's algorithm —
 * same tables and options (posts…users/usermeta, HPOS tables, fpw_/woocommerce_/
 * qwc_ options, home/siteurl/blog_public/user_roles), same wp_json_encode($row)
 * hashing over RAW serialized option values.
 *
 * PRECONDITION: $baseline is either (a) already structurally validated by the
 * caller (fpw_role_policy_baseline_valid), or (b) the just-captured baseline
 * whose original_digest is still being computed. Only the two raw serialized
 * policy fields are read here; an explicitly null baseline performs no
 * projection at all (the plain standalone digest). Callers that cannot or
 * will not validate must fail closed BEFORE calling this with a non-null
 * value — silently degrading a non-null baseline to null is not supported. */
function fpw_role_policy_protected_digest( ?array $baseline ): string {
	global $wpdb;
	$hash = hash_init( 'sha256' );
	$tables = array(
		'posts' => 'ID', 'postmeta' => 'meta_id', 'comments' => 'comment_ID', 'commentmeta' => 'meta_id',
		'terms' => 'term_id', 'termmeta' => 'meta_id', 'term_taxonomy' => 'term_taxonomy_id',
		'term_relationships' => 'object_id,term_taxonomy_id', 'users' => 'ID', 'usermeta' => 'umeta_id',
		'woocommerce_order_items' => 'order_item_id', 'woocommerce_order_itemmeta' => 'meta_id',
		'wc_orders' => 'id', 'wc_orders_meta' => 'id', 'wc_order_addresses' => 'id', 'wc_order_operational_data' => 'id',
	);
	foreach ( $tables as $suffix => $key ) {
		$table = $wpdb->prefix . $suffix;
		$present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to inspect protected table.' ); }
		if ( ! $present ) { hash_update( $hash, $suffix . ':absent\n' ); continue; }
		hash_update( $hash, $suffix . "\n" );
		for ( $offset = 0; ; $offset += 500 ) {
			// Identifiers are exclusively the fixed allowlist above and WP's configured prefix.
			$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY $key LIMIT 500 OFFSET " . (int) $offset, ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected records.' ); }
			foreach ( $rows as $row ) { hash_update( $hash, wp_json_encode( $row ) . "\n" ); }
			if ( count( $rows ) < 500 ) { break; }
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ('home','siteurl','blog_public','" . $wpdb->prefix . "user_roles') ORDER BY option_name",
		$wpdb->esc_like( 'fpw_' ) . '%', $wpdb->esc_like( 'woocommerce_' ) . '%', $wpdb->esc_like( 'qwc_' ) . '%'
	), ARRAY_A );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected commercial settings.' ); }
	$roles_option = $wpdb->prefix . 'user_roles';
	foreach ( $rows as $row ) {
		if ( null !== $baseline && (string) $row['option_name'] === $roles_option
			&& ( (string) $row['option_value'] === $baseline['roles_original_raw'] || (string) $row['option_value'] === $baseline['roles_expected_raw'] ) ) {
			$row['option_value'] = $baseline['roles_original_raw'];
		}
		hash_update( $hash, wp_json_encode( $row ) . "\n" );
	}
	return hash_final( $hash );
}
