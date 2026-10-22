<?php
/** DEV measurement mode: deterministic related selection/order and quantity ids.
 * Active ONLY in the guarded LOCAL runtime bound to the exact DEV origin
 * http://mliu:8131 (environment type + canonical WP_HOME + matching
 * FREEPLAST_LOCAL_URL env, mirroring local/safety.php guards) AND with an
 * explicit ?fp-measure=1 request. Host/header alone can never activate it;
 * other ports (e.g. 8890) or hosts never match. Outside the mode every filter
 * is a passthrough: normal behavior is byte-identical.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gate: trusted LOCAL/server-side signals PLUS explicit measurement request. */
function fp_measure_mode_active() {
	return defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE
		&& defined( 'WP_HOME' ) && 'http://mliu:8131' === WP_HOME
		&& 'http://mliu:8131' === getenv( 'FREEPLAST_LOCAL_URL' )
		&& isset( $_GET['fp-measure'] ) && is_string( $_GET['fp-measure'] ) && hash_equals( '1', $_GET['fp-measure'] );
}

/** Related display order: replace Woo's rand with id asc (same count/columns). */
function fp_measure_related_args( $args ) {
	if ( ! fp_measure_mode_active() ) {
		return $args;
	}
	$args['orderby'] = 'id';
	$args['order']   = 'asc';
	return $args;
}

/** Keep our selection order instead of Woo's per-request shuffle. */
function fp_measure_keep_related_order( $shuffle ) {
	return fp_measure_mode_active() ? false : $shuffle;
}

/**
 * Deterministic related selection, independent of Woo's transient cache.
 * Core caches a candidate pool (arbitrary membership/order when produced by a
 * normal-mode request with no ORDER BY) and then shuffles+slices it per call,
 * so a cached pool and a cold pool can never feed the same selection. In mode
 * we recompute from the FULL eligible set via public read-only APIs (same
 * cats/tags relatedness, publish status, exclude list, visibility), sorted by
 * ID ascending, sliced to the same limit. Cache state cannot change the result.
 */
function fp_measure_deterministic_related( $related_posts, $product_id, $args = array() ) {
	if ( ! fp_measure_mode_active() ) {
		return $related_posts;
	}
	$product_id = absint( $product_id );
	$limit      = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 4;
	$exclude    = array_values( array_filter( array_map( 'absint', (array) ( $args['excluded_ids'] ?? array() ) ) ) );

	$cats = wc_get_product_term_ids( $product_id, 'product_cat' );
	$tags = wc_get_product_term_ids( $product_id, 'product_tag' );
	$tax_query = array( 'relation' => 'OR' );
	if ( $cats ) {
		$tax_query[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats );
	}
	if ( $tags ) {
		$tax_query[] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $tags );
	}
	if ( count( $tax_query ) < 2 ) {
		return array(); // No relatedness terms: core shows no related products.
	}

	$query    = new WP_Query( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'post__not_in'   => $exclude,
		'tax_query'      => $tax_query,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$products = array_filter( array_map( 'wc_get_product', (array) $query->posts ), 'wc_products_array_filter_visible' );
	$ids      = array_map( static fn( $product ) => (int) $product->get_id(), $products );
	sort( $ids, SORT_NUMERIC );
	return array_slice( $ids, 0, $limit );
}

/** Deterministic quantity input ids: uniqid() is per-request by design. */
function fp_measure_quantity_args( $args, $product = null ) {
	if ( ! fp_measure_mode_active() ) {
		return $args;
	}
	static $sequence = 0;
	$product_id = ( is_object( $product ) && method_exists( $product, 'get_id' ) ) ? (int) $product->get_id() : 0;
	$args['input_id'] = 'quantity_fpm_' . $product_id . '_' . (string) ++$sequence;
	return $args;
}

add_filter( 'woocommerce_output_related_products_args', 'fp_measure_related_args' );
add_filter( 'woocommerce_product_related_posts_shuffle', 'fp_measure_keep_related_order' );
add_filter( 'woocommerce_related_products', 'fp_measure_deterministic_related', 10, 3 );
add_filter( 'woocommerce_quantity_input_args', 'fp_measure_quantity_args', 10, 2 );
