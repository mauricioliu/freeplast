<?php
/**
 * Offline fixture tests for the theme DEV measurement mode (inc/measure-mode.php).
 * No WordPress, no DB, no network: every scenario runs in a fresh PHP process
 * (constants/env cannot be redefined), sets its signals, includes the mode file
 * with stubs for the public APIs the mode uses, and asserts gate + filter
 * behavior, including cache-state independence of the controlled selection
 * (warm arbitrary-subset pool vs cold pool) and ordinary-mode passthrough.
 *
 * Usage: php measure-mode-test.php            (runs all scenarios, aggregates)
 *        php measure-mode-test.php <scenario> (single scenario)
 */

$scenario = isset( $argv[1] ) ? $argv[1] : null;
$scenarios = array( 'local_measure', 'warm_cold', 'port8890', 'localhost8131', 'local_no_flag', 'forged_prod', 'env_mismatch', 'array_injection' );

if ( null === $scenario ) {
	$failures = 0;
	foreach ( $scenarios as $name ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) . ' 2>&1';
		exec( $cmd, $output, $code );
		echo implode( "\n", $output ) . "\n";
		if ( 0 !== $code ) { $failures++; }
		$output = array();
	}
	exit( $failures === 0 ? 0 : 1 );
}

if ( ! in_array( $scenario, $scenarios, true ) ) {
	fwrite( STDERR, "unknown scenario: $scenario\n" );
	exit( 2 );
}

define( 'ABSPATH', '/tmp/' );

// ---- Stub layer for public APIs used by the mode (no WP bootstrap) ----
$GLOBALS['fp_test_filters'] = array();
function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['fp_test_filters'][ $tag ][] = $callback;
}
function apply_test_filter( $tag, $value, ...$rest ) {
	foreach ( $GLOBALS['fp_test_filters'][ $tag ] as $callback ) {
		$value = $callback( $value, ...$rest );
	}
	return $value;
}

// Catalog fixture: viewed product 100 (cat 5, tag 7); eligible 11..40 share
// cat 5; 27 is hidden (not visible), 13 is an excluded upsell; 50 unrelated.
$GLOBALS['FP_TEST_CATALOG'] = array( 'products' => array() );
for ( $id = 11; $id <= 40; $id++ ) {
	$GLOBALS['FP_TEST_CATALOG']['products'][ $id ] = array(
		'status'  => 'publish',
		'visible' => true,
		'cats'    => array( 5 ),
		'tags'    => array( 7 ),
		'href'    => "/producto/p{$id}/",
		'img'     => "/img/{$id}.jpg",
	);
}
$GLOBALS['FP_TEST_CATALOG']['products'][27]['visible'] = false;
$GLOBALS['FP_TEST_CATALOG']['products'][100] = array( 'status' => 'publish', 'visible' => true, 'cats' => array( 5 ), 'tags' => array( 7 ), 'href' => '/producto/p100/', 'img' => '/img/100.jpg' );
$GLOBALS['FP_TEST_CATALOG']['products'][50] = array( 'status' => 'publish', 'visible' => true, 'cats' => array( 9 ), 'tags' => array( 8 ), 'href' => '/producto/p50/', 'img' => '/img/50.jpg' );

class WP_Query {
	public $posts;
	public function __construct( $args = array() ) {
		$ids = array();
		$clauses = array();
		foreach ( ( $args['tax_query'] ?? array() ) as $key => $clause ) {
			if ( 'relation' === $key ) { continue; }
			$clauses[] = $clause;
		}
		foreach ( $GLOBALS['FP_TEST_CATALOG']['products'] as $id => $p ) {
			if ( 'publish' !== $p['status'] ) { continue; }
			if ( in_array( $id, (array) ( $args['post__not_in'] ?? array() ), true ) ) { continue; }
			$match = false;
			foreach ( $clauses as $clause ) {
				$field = 'product_cat' === $clause['taxonomy'] ? 'cats' : 'tags';
				if ( array_intersect( $p[ $field ], (array) $clause['terms'] ) ) { $match = true; break; }
			}
			if ( $match ) { $ids[] = $id; }
		}
		sort( $ids, SORT_NUMERIC );
		$limit = $args['posts_per_page'] ?? 10;
		$this->posts = -1 === $limit ? $ids : array_slice( $ids, 0, $limit );
	}
}

class FP_Test_Product {
	public function __construct( private readonly int $id, public readonly bool $visible = true ) {}
	public function get_id() { return $this->id; }
	public function is_visible() { return $this->visible; }
}

function wc_get_product( $id ) {
	$p = $GLOBALS['FP_TEST_CATALOG']['products'][ (int) $id ] ?? null;
	return $p ? new FP_Test_Product( (int) $id, $p['visible'] ) : false;
}
function absint( $n ) { return abs( (int) $n ); }
function wc_products_array_filter_visible( $product ) { return $product->is_visible(); }
function wc_get_product_term_ids( $product_id, $taxonomy ) {
	$field = 'product_cat' === $taxonomy ? 'cats' : 'tags';
	return $GLOBALS['FP_TEST_CATALOG']['products'][ (int) $product_id ][ $field ];
}

// Faithful offline replica of wc_get_related_products' cache semantics
// (wc-product-functions.php:1469-1511): cached pool (warm, arbitrary
// membership/order) or query (cold), filter, shuffle filter, slice.
function simulate_wc_get_related_products( $pool ) {
	if ( false === $pool ) {
		$query = new WP_Query( array(
			'post_type' => 'product', 'post_status' => 'publish',
			'post__not_in' => array( 0, 100, 13 ),
			'tax_query' => array( 'relation' => 'OR', array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( 5 ) ), array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => array( 7 ) ) ),
			'posts_per_page' => -1,
		) );
		$pool = $query->posts;
	}
	$posts = apply_test_filter( 'woocommerce_related_products', $pool, 100, array( 'limit' => 4, 'excluded_ids' => array( 0, 100, 13 ) ) );
	if ( apply_test_filter( 'woocommerce_product_related_posts_shuffle', true ) ) {
		shuffle( $posts );
	}
	return array_slice( $posts, 0, 4 );
}
function resources_of( $ids ) {
	$out = array( 'hrefs' => array(), 'imgs' => array() );
	foreach ( $ids as $id ) {
		$out['hrefs'][] = $GLOBALS['FP_TEST_CATALOG']['products'][ $id ]['href'];
		$out['imgs'][]  = $GLOBALS['FP_TEST_CATALOG']['products'][ $id ]['img'];
	}
	return $out;
}

$failures = 0;
function check( $ok, $label ) {
	global $failures;
	if ( $ok ) { echo "  ok: $label\n"; return; }
	$failures++;
	fwrite( STDERR, "  FAIL: $label\n" );
}

// Scenario signals, set BEFORE including the mode file.
switch ( $scenario ) {
	case 'local_measure':
	case 'warm_cold':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'http://mliu:8131' );
		putenv( 'FREEPLAST_LOCAL_URL=http://mliu:8131' );
		$_GET = array( 'fp-measure' => '1' );
		break;
	case 'port8890':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'http://mliu:8890' );
		putenv( 'FREEPLAST_LOCAL_URL=http://mliu:8890' );
		$_GET = array( 'fp-measure' => '1' );
		break;
	case 'localhost8131':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'http://localhost:8131' );
		putenv( 'FREEPLAST_LOCAL_URL=http://localhost:8131' );
		$_GET = array( 'fp-measure' => '1' );
		break;
	case 'local_no_flag':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'http://mliu:8131' );
		putenv( 'FREEPLAST_LOCAL_URL=http://mliu:8131' );
		$_GET = array();
		break;
	case 'forged_prod':
		define( 'WP_ENVIRONMENT_TYPE', 'production' );
		define( 'WP_HOME', 'https://freeplast.mliu.site' );
		$_GET = array( 'fp-measure' => '1' );
		$_SERVER['HTTP_HOST'] = 'mliu:8131';
		$_SERVER['HTTP_X_FREEPLAST_MEASURE'] = '1';
		break;
	case 'env_mismatch':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'https://freeplast.mliu.site' );
		putenv( 'FREEPLAST_LOCAL_URL=http://mliu:8131' );
		$_GET = array( 'fp-measure' => '1' );
		$_SERVER['HTTP_HOST'] = 'mliu:8131';
		break;
	case 'array_injection':
		define( 'WP_ENVIRONMENT_TYPE', 'local' );
		define( 'WP_HOME', 'http://mliu:8131' );
		putenv( 'FREEPLAST_LOCAL_URL=http://mliu:8131' );
		$_GET = array( 'fp-measure' => array( '1' ) );
		break;
}

require __DIR__ . '/../wp-content/themes/freeplast/inc/measure-mode.php';

foreach ( array( 'woocommerce_output_related_products_args', 'woocommerce_product_related_posts_shuffle', 'woocommerce_related_products', 'woocommerce_quantity_input_args' ) as $tag ) {
	check( ! empty( $GLOBALS['fp_test_filters'][ $tag ] ), "hook registered: $tag" );
}

$active = fp_measure_mode_active();
$rand_args = array( 'posts_per_page' => 4, 'columns' => 4, 'orderby' => 'rand', 'order' => 'desc' );
// Warm cache fixture: arbitrary random-subset pool from a prior normal request.
// Contains NONE of the expected controlled selection [11,12,14,15].
$warm_pool = array( 33, 21, 38, 17, 25 );
$expected_selection = array( 11, 12, 14, 15 );
$qty_args = array( 'input_id' => 'quantity_abc123def4567', 'input_name' => 'quantity' );

switch ( $scenario ) {
	case 'local_measure':
		check( $active === true, 'gate active: exact mliu:8131 signals + explicit request' );

		$out = apply_test_filter( 'woocommerce_output_related_products_args', $rand_args );
		check( $out['orderby'] === 'id' && $out['order'] === 'asc', 'related args: rand -> id/asc' );
		check( $out['posts_per_page'] === 4 && $out['columns'] === 4, 'related args: count/columns preserved' );

		check( apply_test_filter( 'woocommerce_product_related_posts_shuffle', true ) === false, 'shuffle disabled' );

		$selection = simulate_wc_get_related_products( $warm_pool );
		check( $selection === $expected_selection, 'warm cache: selection = lowest-ID eligible (' . implode( ',', $selection ) . ')' );
		check( count( $selection ) === 4, 'warm cache: card count preserved (limit 4)' );
		$again = simulate_wc_get_related_products( array( 29, 40, 11, 22, 16 ) );
		check( $again === $expected_selection, 'selection independent of which pool the cache holds' );

		$q1 = apply_test_filter( 'woocommerce_quantity_input_args', $qty_args, new FP_Test_Product( 12 ) );
		$q2 = apply_test_filter( 'woocommerce_quantity_input_args', $qty_args, new FP_Test_Product( 12 ) );
		$q3 = apply_test_filter( 'woocommerce_quantity_input_args', $qty_args, new FP_Test_Product( 13 ) );
		check( $q1['input_id'] === 'quantity_fpm_12_1', "quantity id deterministic ({$q1['input_id']})" );
		check( $q2['input_id'] === 'quantity_fpm_12_2', 'quantity id unique per repeated product' );
		check( $q3['input_id'] === 'quantity_fpm_13_3', 'quantity id unique per product' );
		check( (bool) preg_match( '~\A[A-Za-z][A-Za-z0-9_-]*\z~D', $q1['input_id'] ), 'quantity id is a valid HTML id (label for binding)' );
		$no_product = apply_test_filter( 'woocommerce_quantity_input_args', $qty_args, null );
		check( $no_product['input_id'] === 'quantity_fpm_0_4', 'quantity id deterministic without product context' );
		break;

	case 'warm_cold':
		check( $active === true, 'gate active' );
		check( empty( array_intersect( $warm_pool, $expected_selection ) ), 'fixture discriminates: warm pool lacks the expected selection' );

		$warm = simulate_wc_get_related_products( $warm_pool );
		$cold = simulate_wc_get_related_products( false );
		check( $warm === $cold, 'controlled selection identical across warm-subset cache and cold cache' );
		check( $warm === $expected_selection, 'both cache states select lowest-ID eligible: ' . implode( ',', $warm ) );
		$rw = resources_of( $warm );
		$rc = resources_of( $cold );
		check( $rw === $rc && $rw['hrefs'] === array( '/producto/p11/', '/producto/p12/', '/producto/p14/', '/producto/p15/' ), 'ordered hrefs identical across cache states' );
		check( $rw['imgs'] === array( '/img/11.jpg', '/img/12.jpg', '/img/14.jpg', '/img/15.jpg' ), 'ordered media resources identical across cache states' );
		check( ! in_array( 27, $warm, true ) && ! in_array( 13, $warm, true ) && ! in_array( 50, $warm, true ), 'hidden product, excluded upsell and unrelated product never selected' );

		// Red-capability of this fixture: a naive sort-the-cached-pool fix
		// (the old hole) yields a different, pool-dependent selection.
		$GLOBALS['fp_test_filters']['woocommerce_related_products'] = array( static function ( $pool ) { $p = $pool; sort( $p, SORT_NUMERIC ); return $p; } );
		$GLOBALS['fp_test_filters']['woocommerce_product_related_posts_shuffle'] = array( static fn() => false );
		$naive_warm = simulate_wc_get_related_products( $warm_pool );
		$naive_cold = simulate_wc_get_related_products( false );
		check( $naive_warm !== $expected_selection, 'naive pool-sort fix would fail this warm-cache case (' . implode( ',', $naive_warm ) . ')' );
		check( $naive_warm !== $naive_cold, 'naive pool-sort fix is cache-state dependent (detected)' );
		break;

	default:
		// Every inactive scenario: gate false and ALL filters passthrough.
		check( $active === false, "gate inactive: $scenario" );
		$out = apply_test_filter( 'woocommerce_output_related_products_args', $rand_args );
		check( $out === $rand_args, "$scenario: related args untouched (rand preserved)" );
		check( apply_test_filter( 'woocommerce_product_related_posts_shuffle', true ) === true, "$scenario: shuffle preserved" );
		$out_pool = apply_test_filter( 'woocommerce_related_products', $warm_pool, 100, array( 'limit' => 4, 'excluded_ids' => array( 0, 100, 13 ) ) );
		check( $out_pool === $warm_pool, "$scenario: cached pool returned unchanged (membership and order untouched)" );
		$out_qty = apply_test_filter( 'woocommerce_quantity_input_args', $qty_args, new FP_Test_Product( 12 ) );
		check( $out_qty === $qty_args, "$scenario: quantity id untouched (uniqid preserved)" );
		break;
}

echo ( 0 === $failures ? "PASS $scenario\n" : "FAIL $scenario ($failures)\n" );
exit( 0 === $failures ? 0 : 1 );
