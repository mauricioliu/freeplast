<?php
/**
 * The customer-facing Catalog discovery journey (issue #5).
 *
 * Home, Tienda and search render from the synchronized Catalog — never from
 * duplicated theme content. Three server-rendered dynamic blocks expose it
 * with semantic markup and the versioned public `fpcq-` class prefix (v1),
 * so the journey stays functional under a stock block theme while the
 * freeplast theme supplies the final v6 presentation:
 *
 *   freeplast/featured-products  Home — the approved eight Featured Products
 *                                in source-controlled featured_order sequence.
 *   freeplast/catalog            Tienda — every Active Product on one page
 *                                with the Todos/Agrícola/Otros category
 *                                filter and a quotation action per card.
 *   freeplast/search-results     ?s= — Products as catalog cards plus
 *                                standard pages, with a clear no-result state.
 *
 * Category filtering uses meaningful URLs backed by a rewrite rule:
 *
 *   /tienda/                        Todos (no filter)
 *   /tienda/categoria/agricola/     Product Category "agricola"
 *   /tienda/categoria/otros/        Product Category "otros"
 *
 * Unknown categories do not match the rule and resolve as 404s. Archived
 * Products are draft records: every query here is publish-only, so archived
 * Products never appear in discovery nor carry quotation actions.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Discovery {

	/** Query var carrying the active Product Category filter (rewrite-backed). */
	public const CATEGORY_QUERY_VAR = 'fp_categoria';

	/**
	 * Customer-facing labels for the Product Categories
	 * (Freeplast_CQ_Catalog_Source::CATEGORIES). "Todos" is a view filter
	 * only and is never stored, so it has no label here.
	 */
	public const CATEGORY_LABELS = array(
		'agricola' => 'Agrícola',
		'otros'    => 'Otros',
	);

	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );

		/* Registered here (not on a nested init hook) so a rewrite flush on any
	   later init — including the flag-based flush after activation/migration
	   3 — always sees the rule. */
		self::register_routes();

		register_block_type(
			'freeplast/featured-products',
			array( 'render_callback' => array( self::class, 'render_featured' ) )
		);
		register_block_type(
			'freeplast/catalog',
			array( 'render_callback' => array( self::class, 'render_catalog' ) )
		);
		register_block_type(
			'freeplast/search-results',
			array( 'render_callback' => array( self::class, 'render_search' ) )
		);
	}

	/** Expose the category filter as a public query var. */
	public static function query_vars( array $vars ): array {
		$vars[] = self::CATEGORY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Meaningful filter URLs for the Tienda archive. The rule is restricted
	 * to the reviewed category vocabulary; anything else falls through to a
	 * 404 instead of an empty grid.
	 */
	public static function register_routes(): void {
		add_rewrite_rule(
			sprintf( '^tienda/categoria/(%s)/?$', implode( '|', array_keys( self::CATEGORY_LABELS ) ) ),
			sprintf( 'index.php?post_type=fp_product&%s=$matches[1]', self::CATEGORY_QUERY_VAR ),
			'top'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Blocks                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Home: the approved eight Featured Products in source-controlled order.
	 */
	public static function render_featured(): string {
		$featured = get_posts(
			array(
				'post_type'        => 'fp_product',
				'post_status'      => 'publish',
				'posts_per_page'   => 8,
				'meta_key'         => '_fp_featured_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'          => 'meta_value_num',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_fp_featured',
						'value' => '1',
					),
				),
			)
		);

		if ( array() === $featured ) {
			return '';
		}

		return sprintf(
			'<section class="fpcq-featured" data-fpcq-version="1"><h2 class="fpcq-featured-title">%s</h2><ul class="fpcq-cards">%s</ul><a class="fpcq-featured-all" href="%s">Ver todo el catálogo</a></section>',
			'Nuestros Productos',
			self::render_cards( $featured, 3 ),
			esc_url( home_url( '/tienda/' ) )
		);
	}

	/**
	 * Tienda: the Todos/Agrícola/Otros filter plus every Active Product with
	 * a quotation action per card. The category state lives in the URL, so
	 * the filters are plain links (shareable, no JavaScript required).
	 */
	public static function render_catalog(): string {
		$category = self::current_category();

		$args = array(
			'post_type'        => 'fp_product',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'ID', // creation order == reviewed source order
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
		if ( null !== $category ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_fp_category',
					'value' => $category,
				),
			);
		}

		$products = get_posts( $args );

		$filters  = self::filter_link( home_url( '/tienda/' ), 'Todos', null === $category );
		foreach ( self::CATEGORY_LABELS as $key => $label ) {
			$filters .= self::filter_link( home_url( '/tienda/categoria/' . $key . '/' ), $label, $category === $key );
		}

		if ( array() === $products ) {
			$body = '<p class="fpcq-catalog-empty">Por ahora no hay productos publicados en esta categoría. Mientras tanto, contáctanos directamente.</p>';
		} else {
			$body = sprintf( '<ul class="fpcq-cards">%s</ul>', self::render_cards( $products, 2 ) );
		}

		return sprintf(
			'<nav class="fpcq-filters" aria-label="Filtrar productos por categoría">%s</nav>%s',
			$filters,
			$body
		);
	}

	/**
	 * Search results: Products as catalog cards (with their quotation
	 * action) plus standard pages, and a clear no-result state with
	 * recovery paths into the catalog.
	 */
	public static function render_search(): string {
		$term = get_search_query();
		$html = sprintf( '<h1 class="fpcq-search-title">%s</h1>', 'Resultados de búsqueda' );

		$found = '' === $term ? array() : get_posts(
			array(
				's'                => $term,
				'post_type'        => array( 'fp_product', 'page' ),
				'post_status'      => 'publish',
				'posts_per_page'   => 20,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$products = array_values( array_filter( $found, static fn( WP_Post $post ) => 'fp_product' === $post->post_type ) );
		$pages    = array_values( array_filter( $found, static fn( WP_Post $post ) => 'page' === $post->post_type ) );

		if ( array() === $found ) {
			$html .= sprintf( '<p class="fpcq-search-empty">%s</p>', '' === $term ? 'Escribe qué estás buscando para encontrar productos y páginas.' : sprintf( 'No encontramos resultados para «%s».', esc_html( $term ) ) );
			$html .= sprintf( '<p class="fpcq-search-hint">%s</p>', 'Prueba con otro término o explora el catálogo:' );
			$html .= sprintf(
				'<ul class="fpcq-search-suggestions"><li><a href="%s">Todo el catálogo</a></li><li><a href="%s">Agrícola</a></li><li><a href="%s">Otros</a></li><li><a href="%s">Contáctanos</a></li></ul>',
				esc_url( home_url( '/tienda/' ) ),
				esc_url( home_url( '/tienda/categoria/agricola/' ) ),
				esc_url( home_url( '/tienda/categoria/otros/' ) ),
				esc_url( home_url( '/contacto/' ) )
			);
			return sprintf( '<section class="fpcq-search" data-fpcq-version="1">%s</section>', $html );
		}

		$html .= sprintf( '<p class="fpcq-search-count">%d resultado%s para «%s»</p>', count( $found ), 1 === count( $found ) ? '' : 's', esc_html( $term ) );

		if ( array() !== $products ) {
			$html .= sprintf( '<h2 class="fpcq-search-sub">Productos</h2><ul class="fpcq-cards">%s</ul>', self::render_cards( $products, 3 ) );
		}
		if ( array() !== $pages ) {
			$items = '';
			foreach ( $pages as $page ) {
				$items .= sprintf( '<li><a class="fpcq-page-link" href="%s">%s</a></li>', esc_url( get_permalink( $page ) ), esc_html( get_the_title( $page ) ) );
			}
			$html .= sprintf( '<h2 class="fpcq-search-sub">Páginas</h2><ul class="fpcq-search-pages">%s</ul>', $items );
		}

		return sprintf( '<section class="fpcq-search" data-fpcq-version="1">%s</section>', $html );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** The active category filter, validated against the reviewed vocabulary. */
	private static function current_category(): ?string {
		$value = (string) get_query_var( self::CATEGORY_QUERY_VAR );
		return isset( self::CATEGORY_LABELS[ $value ] ) ? $value : null;
	}

	/** One accessible filter control: a link whose active state is the URL itself. */
	private static function filter_link( string $url, string $label, bool $active ): string {
		return sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $url ),
			$active ? ' aria-current="true"' : '',
			esc_html( $label )
		);
	}

	/**
	 * Catalog cards: canonical product links, Product Category label,
	 * excerpt and a quotation action into the sole quotation surface.
	 * All server-rendered from synchronized metadata only.
	 */
	private static function render_cards( array $posts, int $heading_level ): string {
		$cards = '';
		foreach ( $posts as $post ) {
			$category_key = (string) get_post_meta( $post->ID, '_fp_category', true );
			$category     = self::CATEGORY_LABELS[ $category_key ] ?? '';
			$image        = get_the_post_thumbnail( $post, 'medium', array( 'class' => 'fpcq-card-image', 'loading' => 'lazy' ) );

			$format = '<li class="fpcq-card"><a class="fpcq-card-main" href="%1$s">%2$s<p class="fpcq-card-category">%3$s</p><h' . $heading_level . ' class="fpcq-card-title">%4$s</h' . $heading_level . '><p class="fpcq-card-excerpt">%5$s</p></a><a class="fpcq-card-cta" href="%6$s">Cotizar</a></li>';

			$cards .= sprintf(
				$format,
				esc_url( get_permalink( $post ) ),
				$image,
				esc_html( $category ),
				esc_html( get_the_title( $post ) ),
				esc_html( wp_trim_words( get_the_excerpt( $post ), 24, '…' ) ),
				esc_url( home_url( '/cotizacion/' ) )
			);
		}
		return $cards;
	}
}
