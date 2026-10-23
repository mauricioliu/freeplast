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
	// The shared introduction renders these sections' native description once.
	if ( isset( fp_catalog_sections()[ (string) get_query_var( 'product_cat', '' ) ] ) ) {
		remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
	}
	add_action( 'woocommerce_before_main_content', 'fp_catalog_open_frame', 40 );
	add_action( 'woocommerce_archive_description', 'fp_catalog_intro', 5 );
	add_action( 'woocommerce_after_main_content', 'fp_catalog_close_frame', 5 );
}
function fp_catalog_open_frame(): void { echo '<div class="fp-shell fp-catalog">'; }
function fp_catalog_intro(): void { wc_get_template( 'loop/catalog-intro.php' ); }

/** Native terms own the section title, description, membership and destination. */
function fp_catalog_sections(): array {
	return array( 'agricola' => 'Agrícola', 'carnes' => 'Carnes', 'productos-del-mar' => 'Productos del mar', 'otros' => 'Otros' );
}

function fp_catalog_section_terms(): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'slug' => array_keys( fp_catalog_sections() ), 'hide_empty' => false ) );
	$indexed = array();
	if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $indexed[ $term->slug ] = $term; } }
	return $indexed;
}

function fp_catalog_heading(): array {
	$terms = fp_catalog_section_terms();
	$term = $terms[ (string) get_query_var( 'product_cat', '' ) ] ?? null;
	return array(
		'title' => $term ? $term->name : 'Elige tus productos.',
		'copy' => $term && ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : 'Agrega las cantidades que necesitas. Nosotros preparamos tu cotización.',
	);
}

add_filter( 'render_block', static function ( $html ) {
	if ( ! str_contains( $html, '{{FREEPLAST_CATALOG_LINKS}}' ) ) { return $html; }
	$links = '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Ver todo</a>';
	$terms = fp_catalog_section_terms();
	foreach ( fp_catalog_sections() as $slug => $label ) {
		if ( ! isset( $terms[ $slug ] ) ) { continue; }
		$url = get_term_link( $terms[ $slug ] );
		if ( ! is_wp_error( $url ) ) { $links .= '<a href="' . esc_url( $url ) . '">' . esc_html( $terms[ $slug ]->name ) . '</a>'; }
	}
	return str_replace( '{{FREEPLAST_CATALOG_LINKS}}', $links, $html );
} );
function fp_catalog_close_frame(): void { echo '</div>'; }
add_action( 'wp', 'fp_catalog_setup_frame' );

function fp_catalog_orderby(): string {
	$value = $_GET['orderby'] ?? '';
	return is_string( $value ) && 'title' === $value ? 'title' : 'menu_order';
}

function fp_catalog_filters(): void {
	$by_slug = fp_catalog_section_terms();
	$category = (string) get_query_var( 'product_cat', '' );
	$common = array( 'orderby' => fp_catalog_orderby() );
	$search = get_search_query( false );
	if ( '' !== $search ) { $common['s'] = $search; $common['post_type'] = 'product'; }
	// Counting source for every tab is the same native query the archive lists:
	// published, catalog-visible, distinct products. Cached term counts
	// (wc_term_counts transient / product_count_* meta) go stale after
	// programmatic category edits and then disagree with the listing below them.
	$filters = array( '' => 'Todos' ) + fp_catalog_sections();
	echo '<div class="filter-tabs" aria-label="Categorías">';
	foreach ( $filters as $slug => $label ) {
		$term = $by_slug[$slug] ?? null;
		$url = '' === $slug ? wc_get_page_permalink( 'shop' ) : ( $term ? get_term_link( $term ) : '' );
		if ( ! $url || is_wp_error( $url ) ) { continue; }
		$count = fp_catalog_product_count( '' === $slug ? array() : array( $slug ) );
		echo '<a class="filter" href="' . esc_url( add_query_arg( $common, $url ) ) . '"' . ( $category === $slug ? ' aria-current="page"' : '' ) . '>'
			. esc_html( $label ) . ' <span>' . esc_html( (string) $count ) . '</span></a>';
	}
	echo '</div>';
}

/** Live distinct-product count with the archive's own visibility contract;
 * a product in several categories counts once per tab, never twice in Todos. */
function fp_catalog_product_count( array $category = array() ): int {
	$args = array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 1, 'paginate' => true, 'return' => 'ids' );
	if ( $category ) { $args['category'] = $category; }
	$result = wc_get_products( $args );
	return (int) $result->total;
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
