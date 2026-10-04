<?php
/** Staging role-migration closure regressions (authorized 2026-10 scope).
 * Drives run.php (capture/apply/verify) and staging-release-fingerprint.php as
 * REAL php processes (wp-eval-file shaped: stubs injected through
 * auto_prepend_file, baseline piped on real stdin) over an in-memory WordPress:
 * exact two-role delta, raw-serialized byte-equality, fail-closed deviations,
 * release-owned maintenance guards, idempotent plugin-init case, equivalence
 * with the current standalone fingerprint, and the bounded expected-delta
 * projection over HPOS/commercial rows. No HTTP, no real staging, no secrets.
 *
 * Usage: php staging-role-migration-test.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
$count = 0;
function check( $ok, $label ) { global $count; $count++; if ( ! $ok ) { throw new RuntimeException( $label ); } }

/* ---- worker bootstrap: a temporary auto_prepend script (no repo file, no builtin shadowing) ---- */
$BOOT = tempnam( sys_get_temp_dir(), 'fpwmigboot' );
$FIXTURE = rtrim( sys_get_temp_dir(), '/' ) . '/fpwmig-fixture-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
@mkdir( $FIXTURE, 0700, true );
file_put_contents( $BOOT, <<<'PHP'
<?php
$fpw_spec = json_decode( (string) file_get_contents( getenv( 'FPW_MIG_TEST_STATE' ) ), true );
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' ); // WP core constant the fingerprint loop expects from the wp-eval-file environment
define( 'ABSPATH', getenv( 'FPW_MIG_TEST_ABSPATH' ) . '/' );
@mkdir( ABSPATH, 0777, true );
class FakeWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $state;
	public function __construct( array &$state ) { $this->state = &$state; }
	public function esc_like( $text ) { return $text; }
	public function prepare( $query, ...$params ) {
		foreach ( $params as $param ) { $query = preg_replace( '/%s/', "'" . $param . "'", $query, 1 ); }
		return $query;
	}
	public function get_var( $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '(\w+)'/", $query, $m ) ) { return isset( $this->state['tables'][ $m[1] ] ) ? $m[1] : null; }
		if ( preg_match( "/SELECT option_value FROM wp_options WHERE option_name = '(\w+)'/", $query, $m ) ) { return $this->state['options'][ $m[1] ] ?? null; }
		throw new RuntimeException( 'Unexpected get_var: ' . $query );
	}
	public function get_results( $query, $output = ARRAY_A ) {
		if ( preg_match( '/SELECT \* FROM `(\w+)` ORDER BY ([\w,]+) LIMIT 500 OFFSET (\d+)/', $query, $m ) ) {
			$rows = $this->state['tables'][ $m[1] ] ?? array();
			$keys = explode( ',', $m[2] );
			usort( $rows, static function ( $a, $b ) use ( $keys ) {
				foreach ( $keys as $key ) { $cmp = $a[ $key ] <=> $b[ $key ]; if ( $cmp ) { return $cmp; } }
				return 0;
			} );
			return array_slice( $rows, (int) $m[3], 500 );
		}
		if ( str_starts_with( $query, 'SELECT option_name, option_value FROM wp_options' ) ) {
			$rows = array();
			foreach ( $this->state['options'] as $name => $value ) {
				if ( in_array( $name, array( 'home', 'siteurl', 'blog_public', 'wp_user_roles' ), true )
					|| str_starts_with( $name, 'fpw_' ) || str_starts_with( $name, 'woocommerce_' ) || str_starts_with( $name, 'qwc_' ) ) { $rows[] = array( 'option_name' => $name, 'option_value' => $value ); }
			}
			usort( $rows, static fn( $a, $b ) => $a['option_name'] <=> $b['option_name'] );
			return $rows;
		}
		throw new RuntimeException( 'Unexpected get_results: ' . $query );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb( $fpw_spec );
$GLOBALS['fpw_test_options'] = &$fpw_spec['options'];
$GLOBALS['fpw_test_written'] = &$fpw_spec['written'];
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $name, $default = false ) { return $GLOBALS['fpw_test_options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['fpw_test_written'][ $name ] = true; $GLOBALS['fpw_test_options'][ $name ] = serialize( $value ); return true; }
function maybe_unserialize( $value ) { $u = @unserialize( (string) $value, array( 'allowed_classes' => false ) ); return false === $u ? $value : $u; }
function wp_set_current_user( $id ) { return $id; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
$GLOBALS['fpw_lock'] = ABSPATH . '.wp-release-lock/owner';
if ( is_string( $fpw_spec['guard'] ?? null ) && '' !== $fpw_spec['guard'] ) {
	@mkdir( ABSPATH . '.wp-release-lock', 0777, true );
	@mkdir( ABSPATH . 'wp-content/mu-plugins', 0777, true );
	file_put_contents( $GLOBALS['fpw_lock'], (string) $fpw_spec['guard'] );
	touch( ABSPATH . '.maintenance' );
	touch( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' );
}
$args = array_slice( $GLOBALS['argv'], 1 );
register_shutdown_function( static function () use ( &$fpw_spec ) {
	file_put_contents( getenv( 'FPW_MIG_TEST_STATE_OUT' ), json_encode( array( 'tables' => $fpw_spec['tables'], 'options' => $fpw_spec['options'], 'written' => array_keys( $fpw_spec['written'] ) ) ) );
	@unlink( $GLOBALS['fpw_lock'] ); @unlink( ABSPATH . '.maintenance' ); @unlink( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' );
	@rmdir( ABSPATH . '.wp-release-lock' ); @rmdir( ABSPATH . 'wp-content/mu-plugins' ); @rmdir( ABSPATH . 'wp-content' );
} );
PHP
);

function fpw_state() {
	return array(
		'tables' => array(
			'wp_posts' => array( array( 'ID' => 5, 'post_title' => 'Producto', 'post_status' => 'publish' ) ),
			'wp_postmeta' => array( array( 'meta_id' => 9, 'post_id' => 5, 'meta_key' => '_price', 'meta_value' => '1500' ) ),
			'wp_comments' => array(), 'wp_commentmeta' => array(), 'wp_terms' => array( array( 'term_id' => 2, 'name' => 'Agrícola', 'slug' => 'agricola' ) ),
			'wp_termmeta' => array(), 'wp_term_taxonomy' => array( array( 'term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'product_cat' ) ),
			'wp_term_relationships' => array( array( 'object_id' => 5, 'term_taxonomy_id' => 2 ) ),
			'wp_users' => array( array( 'ID' => 1, 'user_login' => 'owner', 'user_email' => 'owner@example.invalid' ) ),
			'wp_usermeta' => array( array( 'umeta_id' => 3, 'user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}' ) ),
			'wp_woocommerce_order_items' => array( array( 'order_item_id' => 4, 'order_id' => 70, 'order_item_name' => 'Caja' ) ),
			'wp_woocommerce_order_itemmeta' => array( array( 'meta_id' => 11, 'order_item_id' => 4, 'meta_key' => '_qty', 'meta_value' => '3' ) ),
			'wp_wc_orders' => array( array( 'id' => 70, 'status' => 'wc-pending', 'type' => 'shop_order' ) ),
			'wp_wc_orders_meta' => array( array( 'id' => 1, 'order_id' => 70, 'meta_key' => '_fpw_attempt', 'meta_value' => 'abc' ) ),
			'wp_wc_order_addresses' => array( array( 'id' => 1, 'order_id' => 70, 'address_type' => 'billing' ) ),
			'wp_wc_order_operational_data' => array( array( 'id' => 1, 'order_id' => 70, 'cart_hash' => 'x' ) ),
		),
		'options' => array(
			'home' => 'https://freeplast.mliu.site', 'siteurl' => 'https://freeplast.mliu.site', 'blog_public' => '1',
			'wp_user_roles' => serialize( array(
				'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'read' => true, 'manage_options' => true ) ),
				'subscriber' => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
			) ),
			'fpw_draft_70' => '{"schema":1}', 'woocommerce_currency' => 'CLP', 'qwc_mode' => 'quotes',
		),
		'written' => array(), 'guard' => null,
	);
}

function fpw_exec( string $script, array $state, string $stdin, array $argv_args = array() ) {
	global $BOOT, $FIXTURE;
	$state_file = tempnam( sys_get_temp_dir(), 'fpwmigstate' );
	$out_file = tempnam( sys_get_temp_dir(), 'fpwmigout' );
	file_put_contents( $state_file, json_encode( array( 'tables' => $state['tables'], 'options' => $state['options'], 'written' => array(), 'guard' => $state['guard'] ?? null ) ) );
	$command = array( PHP_BINARY, '-d', 'auto_prepend_file=' . $BOOT, $script );
	foreach ( $argv_args as $arg ) { $command[] = $arg; }
	$env = array_merge( getenv(), array( 'FPW_MIG_TEST_STATE' => $state_file, 'FPW_MIG_TEST_STATE_OUT' => $out_file, 'FPW_MIG_TEST_ABSPATH' => rtrim( $FIXTURE, '/' ) ) );
	$proc = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fwrite( $pipes[0], $stdin );
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$exit = proc_close( $proc );
	$result_state = json_decode( (string) file_get_contents( $out_file ), true );
	@unlink( $state_file ); @unlink( $out_file );
	return array( 'ok' => 0 === $exit, 'out' => (string) $stdout, 'error' => trim( (string) $stderr ), 'state' => is_array( $result_state ) ? $result_state : array( 'tables' => array(), 'options' => array(), 'written' => array() ) );
}
function fpw_run( array $state, array $args, string $stdin = '' ) { return fpw_exec( __DIR__ . '/staging-role-migration/run.php', $state, $stdin, $args ); }
function fpw_fp( array $state, string $stdin = '' ) { return fpw_exec( __DIR__ . '/staging-release-fingerprint.php', $state, $stdin ); }
function fpw_legacy_fp( array $state ) { return fpw_exec( __DIR__ . '/owner-workspace-fingerprint.php', $state, '' ); }

$RELEASE = '20261005T120000Z';
$TARGETS = array(
	'fpw_quotation_manager' => array( 'name' => 'Gestión de cotizaciones', 'capabilities' => array( 'read' => true, 'fpw_manage_quotations' => true ) ),
	'fpw_data_manager' => array( 'name' => 'Mantenedor de datos', 'capabilities' => array( 'read' => true, 'fpw_manage_data' => true ) ),
);

/* 1 · capture is read-only and yields the immutable baseline contract. */
$state = fpw_state();
$capture = fpw_run( $state, array( 'capture', $RELEASE ) );
if ( ! $capture['ok'] ) { fwrite( STDERR, 'capture worker stderr: ' . $capture['error'] . "\n" ); }
check( $capture['ok'], 'capture runs' );
check( array() === $capture['state']['written'], 'capture writes nothing' );
$state['options'] = $capture['state']['options'];
$baseline = json_decode( $capture['out'], true );
check( is_array( $baseline ) && $baseline['schema'] === 1 && $baseline['target'] === 'https://freeplast.mliu.site' && $baseline['release'] === $RELEASE, 'baseline carries schema, exact target and release' );
check( $baseline['added_roles'] === array( 'fpw_quotation_manager', 'fpw_data_manager' ), 'baseline names exactly the two target roles' );
$original = unserialize( $baseline['roles_original_raw'], array( 'allowed_classes' => false ) );
$expected = unserialize( $baseline['roles_expected_raw'], array( 'allowed_classes' => false ) );
check( is_array( $original ) && is_array( $expected ), 'baseline raw serialized policies decode' );
check( $baseline['roles_original_raw'] === $state['options']['wp_user_roles'], 'roles_original_raw is the EXACT raw serialized DB value (byte-equal, no canonical form)' );
check( ! isset( $original['fpw_quotation_manager'] ) && ! isset( $original['fpw_data_manager'] ), 'captured original has no target roles yet' );
check( $expected === $original + $TARGETS && $baseline['roles_expected_raw'] === serialize( $original + $TARGETS ), 'expected raw is the exact quotation-access init delta appended to the untouched original' );
check( array_keys( $expected ) === array_merge( array_keys( $original ), array( 'fpw_quotation_manager', 'fpw_data_manager' ) ), 'the delta appends only the two roles; every other role key keeps its place' );
check( preg_match( '/^[0-9a-f]{64}$/', $baseline['original_digest'] ) === 1, 'baseline stores a full sha256 original digest' );
$baseline_json = json_encode( $baseline );

/* Lead equivalence requirement: on the SAME state, capture's original_digest is
 * byte-identical to the CURRENT standalone fingerprint (owner-workspace-fingerprint):
 * the projection must not have changed the hash algorithm at all. */
$legacy = fpw_legacy_fp( $state );
check( $legacy['ok'] && trim( $legacy['out'] ) === $baseline['original_digest'], 'EQUIVALENCE: original_digest equals the plain standalone fingerprint on the same state' );
$fOriginal = fpw_fp( $state, $baseline_json );
check( $fOriginal['ok'] && $fOriginal['out'] === $baseline['original_digest'] . "\n", 'fingerprint of the original state reproduces the baseline digest without any normalization effect' );

/* 2 · projection: original and expected hash identically; deviations differ. */
$migrated = $state;
$migrated['options']['wp_user_roles'] = $baseline['roles_expected_raw'];
$fMigrated = fpw_fp( $migrated, $baseline_json );
check( $fMigrated['ok'] && trim( $fMigrated['out'] ) === $baseline['original_digest'], 'the exact expected delta projects back to the SAME digest (bounded invariant pre==post)' );
$foreign = $state;
$foreign['options']['wp_user_roles'] = serialize( array(
	'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'read' => true, 'manage_options' => true, 'evil_cap' => true ) ),
	'subscriber' => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
) );
$fForeign = fpw_fp( $foreign, $baseline_json );
check( $fForeign['ok'] && trim( $fForeign['out'] ) !== $baseline['original_digest'], 'a foreign cap change is NOT normalized away: digest differs for diagnosis' );
$wrongOwn = $state;
$wrongOwn['options']['wp_user_roles'] = serialize( $original + array( 'fpw_quotation_manager' => array( 'name' => 'Gestión de cotizaciones', 'capabilities' => array( 'read' => true, 'manage_woocommerce' => true ) ) ) );
$fWrongOwn = fpw_fp( $wrongOwn, $baseline_json );
check( $fWrongOwn['ok'] && trim( $fWrongOwn['out'] ) !== $baseline['original_digest'], 'an INCORRECT own-role cap set is not the expected delta: digest differs' );
$noBase = fpw_fp( $state, '' );
check( $noBase['ok'] && preg_match( '/^[0-9a-f]{64}$/', trim( $noBase['out'] ) ) === 1, 'fingerprint without baseline still emits one digest' );
check( trim( $noBase['out'] ) === $baseline['original_digest'], 'without a baseline the digest is the plain standalone value on the original state' );
$noBaseMigrated = fpw_fp( $migrated, '' );
check( $noBaseMigrated['ok'] && trim( $noBaseMigrated['out'] ) !== trim( $noBase['out'] ), 'empty stdin performs no projection: the raw migrated value hashes differently' );
/* Nonempty INVALID stdin must FAIL CLOSED — never a silent no-projection fallback. */
check( ! fpw_fp( $state, 'not json' )['ok'], 'hook: malformed baseline text on stdin refuses (fail closed)' );
check( ! fpw_fp( $migrated, 'not json' )['ok'], 'hook: malformed baseline refuses regardless of the state beneath' );
check( ! fpw_fp( $state, '{}' )['ok'], 'hook: empty baseline object refuses' );
/* The standalone hook has NO release argument of its own: release binding is
 * enforced by run.php apply/verify (section 7). Its own foreign check is the
 * TARGET: a baseline sealed for another site must never project this one. */
$foreignTarget = json_decode( $baseline_json, true );
$foreignTarget['target'] = 'https://elsewhere.example';
check( ! fpw_fp( $state, json_encode( $foreignTarget ) )['ok'], 'hook: a baseline bound to another target refuses' );
$tamperedHook = json_decode( $baseline_json, true );
$tamperedHook['roles_expected_raw'] = serialize( $original + array( 'fpw_quotation_manager' => array( 'name' => 'X', 'capabilities' => array( 'read' => true ) ) ) );
check( ! fpw_fp( $state, json_encode( $tamperedHook ) )['ok'], 'hook: a tampered expected delta refuses (no projection, no digest)' );
$tamperedHookDigest = json_decode( $baseline_json, true );
$tamperedHookDigest['original_digest'] = str_repeat( 'z', 64 ); // not hex: structurally invalid
check( ! fpw_fp( $state, json_encode( $tamperedHookDigest ) )['ok'], 'hook: a structurally invalid digest field refuses (validation is structural, sealing is external)' );

/* 3 · protected surface: HPOS, users and commercial rows change the digest. */
$clean = trim( fpw_fp( $state, $baseline_json )['out'] );
$hposDrift = $state;
$hposDrift['tables']['wp_wc_orders'][0]['status'] = 'wc-completed';
$hposResult = fpw_fp( $hposDrift, $baseline_json );
check( $hposResult['ok'] && trim( $hposResult['out'] ) !== $clean, 'an HPOS order row change breaks the protected digest without crashing the hook' );
$userDrift = $state;
$userDrift['tables']['wp_users'][0]['user_email'] = 'changed@example.invalid';
$userResult = fpw_fp( $userDrift, $baseline_json );
check( $userResult['ok'] && trim( $userResult['out'] ) !== $clean, 'a users-table change breaks the protected digest without crashing the hook' );
$optionDrift = $state;
$optionDrift['options']['fpw_quotation_70'] = '{"schema":1,"version":2}';
$optionResult = fpw_fp( $optionDrift, $baseline_json );
check( $optionResult['ok'] && trim( $optionResult['out'] ) !== $clean, 'a commercial fpw_ option change breaks the protected digest without crashing the hook' );

/* 4 · apply: guarded, exact delta, nothing else written; verify agrees. */
$guarded = $state;
$guarded['guard'] = $RELEASE;
$apply = fpw_run( $guarded, array( 'apply', $RELEASE ), $baseline_json );
check( $apply['ok'], 'apply runs under the release-owned guard' );
check( $apply['state']['written'] === array( 'wp_user_roles' ), 'apply wrote ONLY the roles option' );
check( $apply['state']['options']['wp_user_roles'] === $baseline['roles_expected_raw'], 'apply wrote the exact expected raw serialized policy' );
check( $apply['state']['options']['fpw_draft_70'] === $state['options']['fpw_draft_70'] && $apply['state']['tables'] === $state['tables'], 'no other table or option was touched' );
$verifyState = array( 'tables' => $apply['state']['tables'], 'options' => $apply['state']['options'], 'written' => array(), 'guard' => $RELEASE );
$verify = fpw_run( $verifyState, array( 'verify', $RELEASE ), $baseline_json );
check( $verify['ok'] && str_contains( $verify['out'], 'role_migration_verified' ), 'verify passes after apply' );
check( $verify['state']['written'] === array(), 'verify writes nothing' );

/* 5 · idempotence: plugin init already added the roles before apply. */
$alreadyState = $state;
$alreadyState['options']['wp_user_roles'] = $baseline['roles_expected_raw'];
$alreadyState['guard'] = $RELEASE;
$already = fpw_run( $alreadyState, array( 'apply', $RELEASE ), $baseline_json );
check( $already['ok'] && array() === $already['state']['written'], 'apply with plugin-init roles already present performs no write and still succeeds' );
check( str_contains( $already['out'], 'role_migration_already' ), 'the idempotent path names itself honestly' );
check( fpw_run( $alreadyState, array( 'verify', $RELEASE ), $baseline_json )['ok'], 'verify accepts the already-migrated state' );

/* 6 · fail closed: conflicting preexisting own role, foreign drift, post drift. */
$conflict = $state;
$conflict['options']['wp_user_roles'] = serialize( $original + array( 'fpw_quotation_manager' => array( 'name' => 'Otro', 'capabilities' => array( 'read' => true ) ) ) );
$conflict['guard'] = $RELEASE;
$r = fpw_run( $conflict, array( 'apply', $RELEASE ), $baseline_json );
check( ! $r['ok'] && array() === $r['state']['written'], 'a conflicting preexisting role refuses apply (fail closed, no writes)' );
$drift = $state;
$drift['options']['fpw_draft_70'] = '{"schema":2}';
$drift['guard'] = $RELEASE;
$r = fpw_run( $drift, array( 'apply', $RELEASE ), $baseline_json );
check( ! $r['ok'] && array() === $r['state']['written'], 'any other protected drift before apply fails closed (pre-guard)' );
$postDrift = $state;
$postDrift['options']['wp_user_roles'] = $baseline['roles_expected_raw'];
$postDrift['options']['fpw_quotation_70'] = '{"v":9}';
$postDrift['guard'] = $RELEASE;
check( ! fpw_run( $postDrift, array( 'apply', $RELEASE ), $baseline_json )['ok'], 'a drifted non-roles option blocks even the already-migrated path (pre-guard holds everywhere)' );

/* 7 · baseline/target/release/guard rejections. */
check( ! fpw_run( $state, array( 'apply', $RELEASE ), $baseline_json )['ok'], 'apply without the release-owned maintenance guard refuses' );
check( ! fpw_run( $state, array( 'verify', $RELEASE ), $baseline_json )['ok'], 'verify also requires the maintenance guard' );
$otherOwner = $state;
$otherOwner['guard'] = 'someone-else';
check( ! fpw_run( $otherOwner, array( 'apply', $RELEASE ), $baseline_json )['ok'], 'a different release owner refuses' );
check( ! fpw_run( $guarded, array( 'apply', '../evil' ), $baseline_json )['ok'], 'malformed release id refuses' );
$wrongRelease = json_decode( $baseline_json, true );
$wrongRelease['release'] = 'someone-elses';
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), json_encode( $wrongRelease ) )['ok'], 'baseline bound to another release refuses' );
$tampered = json_decode( $baseline_json, true );
$tampered['roles_expected_raw'] = serialize( $original + array( 'fpw_quotation_manager' => array( 'name' => 'X', 'capabilities' => array( 'read' => true ) ) ) );
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), json_encode( $tampered ) )['ok'], 'a tampered expected delta cannot smuggle caps (raw derivation check)' );
$tamperedDigest = json_decode( $baseline_json, true );
$tamperedDigest['original_digest'] = str_repeat( 'a', 64 );
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), json_encode( $tamperedDigest ) )['ok'], 'a doctored digest cannot pass the pre-guard' );
$preexisting = json_decode( $baseline_json, true );
$preexisting['roles_original_raw'] = serialize( $original + array( 'fpw_data_manager' => $TARGETS['fpw_data_manager'] ) );
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), json_encode( $preexisting ) )['ok'], 'baseline whose original already contains a target role refuses' );
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), 'not json' )['ok'], 'invalid stdin baseline refuses' );
check( ! fpw_run( $guarded, array( 'apply', $RELEASE ), '{}' )['ok'], 'empty baseline object refuses' );
$elsewhere = $state;
$elsewhere['options']['home'] = 'https://elsewhere.example';
$elsewhere['options']['siteurl'] = 'https://elsewhere.example';
check( ! fpw_run( $elsewhere, array( 'apply', $RELEASE ), $baseline_json )['ok'], 'wrong target refuses' );
check( ! fpw_run( $state, array( 'capture' ), '' )['ok'] && ! fpw_run( $state, array( 'nonsense', $RELEASE ), '' )['ok'], 'exactly capture|apply|verify plus release id are accepted' );

/* 8 · no secrets in outputs. */
check( ! str_contains( $baseline_json, 'owner@example.invalid' ) && ! str_contains( $baseline_json, 'wp_capabilities' ), 'baseline exposes no user rows or credentials' );
check( ! str_contains( $fOriginal['out'], 'owner@example.invalid' ) && preg_match( '/^[0-9a-f]{64}\n$/', $fOriginal['out'] ) === 1, 'fingerprint output is exactly one digest, no secrets' );

@unlink( $BOOT );
@rmdir( $FIXTURE );
echo "staging role migration: {$count} offline checks passed\n";
