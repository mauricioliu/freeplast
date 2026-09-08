<?php
/** A discovery is presentation over native product queries, never a catalog copy. */
defined( 'ABSPATH' ) || exit;

/** Woo's ClassicTemplate calls native hooks directly, bypassing the theme's
 * archive-product.php. Install the same frame on that real rendering path;
 * do not replace the block callback, query, asset loading or extension hooks. */
function fp_catalog_setup_frame(): void {
	if ( ! ( is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) ) { return; }
	$priority = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
	if ( false !== $priority ) { remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', $priority ); }
	add_filter( 'woocommerce_show_page_title', '__return_false' );
	add_action( 'woocommerce_before_main_content', 'fp_catalog_open_frame', 40 );
	add_action( 'woocommerce_archive_description', 'fp_catalog_intro', 5 );
	add_action( 'woocommerce_after_main_content', 'fp_catalog_close_frame', 5 );
}
function fp_catalog_open_frame(): void { echo '<div class="fp-shell fp-catalog">'; }
function fp_catalog_intro(): void { wc_get_template( 'loop/catalog-intro.php' ); }
function fp_catalog_close_frame(): void { echo '</div>'; }
add_action( 'wp', 'fp_catalog_setup_frame' );

function fp_catalog_orderby(): string {
	$value = $_GET['orderby'] ?? '';
	return is_string( $value ) && 'title' === $value ? 'title' : 'menu_order';
}

function fp_catalog_filters(): void {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'slug' => array( 'agricola', 'otros' ), 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) { $terms = array(); }
	$by_slug = array();
	foreach ( $terms as $term ) { $by_slug[$term->slug] = $term; }
	$category = (string) get_query_var( 'product_cat', '' );
	$common = array( 'orderby' => fp_catalog_orderby() );
	$search = get_search_query( false );
	if ( '' !== $search ) { $common['s'] = $search; $common['post_type'] = 'product'; }
	// Native distinct-product total, not the sum of overlapping term counts.
	$result = wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
	$filters = array( '' => 'Todos', 'agricola' => 'Agrícola', 'otros' => 'Otros' );
	echo '<div class="filter-tabs" aria-label="Categorías">';
	foreach ( $filters as $slug => $label ) {
		$term = $by_slug[$slug] ?? null;
		$url = '' === $slug ? wc_get_page_permalink( 'shop' ) : ( $term ? get_term_link( $term ) : '' );
		$count = '' === $slug ? (int) $result->total : (int) ( $term->count ?? 0 );
		if ( ! $url || is_wp_error( $url ) ) { continue; }
		echo '<a class="filter" href="' . esc_url( add_query_arg( $common, $url ) ) . '"' . ( $category === $slug ? ' aria-current="page"' : '' ) . '>'
			. esc_html( $label ) . ' <span>' . esc_html( (string) $count ) . '</span></a>';
	}
	echo '</div>';
}

function fp_catalog_result_count( int $total, string $query ): string {
	if ( '' !== $query ) {
		return '<p class="woocommerce-result-count">' . sprintf( '<strong>%d</strong> resultados para «%s»', $total, esc_html( $query ) ) . '</p>';
	}
	return '<p class="woocommerce-result-count">' . sprintf( '<strong>%d</strong> %s', $total, 1 === $total ? 'producto' : 'productos' ) . '</p>';
}

add_filter( 'woocommerce_catalog_orderby', static fn() => array( 'menu_order' => 'Destacados', 'title' => 'Nombre A–Z' ), 20001 );
add_filter( 'search_template_hierarchy', static function ( $templates ) {
	if ( 'product' === get_query_var( 'post_type' ) ) { array_unshift( $templates, 'product-search-results.php' ); }
	return $templates;
} );

function fp_catalog_prepare_query( $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ( 'product_query' !== $query->get( 'wc_query' ) && 'product' !== $query->get( 'post_type' ) ) ) { return; }
	$query->set( 'fp_a_catalog', true );
	$query->set( 'fp_a_orderby', fp_catalog_orderby() );
	$search = $query->get( 's' );
	if ( is_string( $search ) && '' !== $search ) {
		$query->set( 'fp_a_original_search', $search );
		$query->set( 's', mb_strtolower( remove_accents( $search ), 'UTF-8' ) );
	}
}
add_action( 'pre_get_posts', 'fp_catalog_prepare_query', 30 );
add_filter( 'get_search_query', static function ( $search ) {
	global $wp_query;
	return $wp_query && $wp_query->get( 'fp_a_original_search' ) !== '' ? ( $wp_query->get( 'fp_a_original_search' ) ?: $search ) : $search;
} );

/** Native MySQL collations fold accents, but the disposable SQLite LIKE does
 * not. Fold both engines explicitly at the native SQL boundary (values are
 * still parsed/escaped by WP_Query). No separately stored search index. */
function fp_catalog_fold_sql( string $column ): string {
	$folded = 'LOWER(' . $column . ')';
	foreach ( array( 'Á'=>'a', 'É'=>'e', 'Í'=>'i', 'Ó'=>'o', 'Ú'=>'u', 'Ü'=>'u', 'Ñ'=>'n', 'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n' ) as $from => $to ) {
		$folded = "REPLACE($folded, '$from', '$to')";
	}
	return $folded;
}
add_filter( 'posts_search', static function ( $search, $query ) {
	if ( ! $query->get( 'fp_a_catalog' ) ) { return $search; }
	global $wpdb;
	foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) {
		$qualified = $wpdb->posts . '.' . $column;
		$search = str_replace( $qualified, fp_catalog_fold_sql( $qualified ), $search );
	}
	return $search;
}, 20, 2 );

add_filter( 'posts_clauses', static function ( $clauses, $query ) {
	if ( ! $query->get( 'fp_a_catalog' ) || ! $query->is_main_query() ) { return $clauses; }
	global $wpdb;
	$title = fp_catalog_fold_sql( $wpdb->posts . '.post_title' );
	if ( 'title' === $query->get( 'fp_a_orderby' ) ) {
		$clauses['orderby'] = "$title ASC, {$wpdb->posts}.ID ASC";
	} else {
		$visibility = wc_get_product_visibility_term_ids();
		$featured = (int) ( $visibility['featured'] ?? 0 );
		// Menu order alone does NOT mean featured: native featured is a term.
		$clauses['orderby'] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} fp_featured WHERE fp_featured.object_id = {$wpdb->posts}.ID AND fp_featured.term_taxonomy_id = $featured) DESC, {$wpdb->posts}.menu_order ASC, $title ASC, {$wpdb->posts}.ID ASC";
	}
	return $clauses;
}, 30, 2 );
