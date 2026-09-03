<?php
/**
 * The fp_product record type and its public rendering.
 *
 * Products exist only through reviewed Catalog Source synchronization
 * (Freeplast_CQ_Catalog_Sync). There is no editor UI: the post type is
 * hidden from every WordPress editor menu, so catalog mutations cannot
 * bypass source review.
 *
 * Routing (migration 2+):
 *   /tienda/                  fp_product archive (placeholder page retired)
 *   /producto/<slug>/         single product with a clean canonical URL
 *
 * Public markup comes from the server-rendered dynamic block
 * `freeplast/product-detail`, which reads only synchronized post metadata.
 * The markup is semantic and unstyled under a stock block theme; the
 * freeplast theme provides the final v7-variant-A presentation through the
 * versioned public class prefix `fpcq-` (v1).
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Products {

	/** Customer-facing categories (Todos is a view filter, not a category). */
	public const CATEGORY_LABELS = array(
		'agricola' => 'Agrícola',
		'otros'    => 'Otros',
	);

	public static function register(): void {
		register_post_type(
			'fp_product',
			array(
				'labels'              => array(
					'name'          => 'Productos',
					'singular_name' => 'Producto',
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'exclude_from_search' => false,
				'hierarchical'        => false,
				'has_archive'         => 'tienda',
				'rewrite'             => array(
					'slug'       => 'producto',
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'capability_type'     => 'post',
			)
		);

		self::register_meta();

		register_block_type(
			'freeplast/product-detail',
			array(
				'render_callback' => array( self::class, 'render_detail' ),
			)
		);
	}

	/**
	 * Plugin-owned product metadata (all synchronized, never editor-edited).
	 */
	private static function register_meta(): void {
		$string_meta = static function ( string $key ) {
			register_post_meta(
				'fp_product',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		};

		foreach ( array( '_fp_source_id', '_fp_source_url', '_fp_source_checked_at', '_fp_description', '_fp_category', '_fp_material', '_fp_material_short', '_fp_dimensions', '_fp_weight_text', '_fp_use', '_fp_image_checksum', '_fp_image_source', '_fp_image_alt', '_fp_image_provisional', '_fp_related_ids', '_fp_options', '_fp_lifecycle' ) as $key ) {
			$string_meta( $key );
		}

		$integer_meta = static function ( string $key ) {
			register_post_meta(
				'fp_product',
				$key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
				)
			);
		};
		foreach ( array( '_fp_quote_min_qty', '_fp_quote_step', '_fp_featured_order' ) as $key ) {
			$integer_meta( $key );
		}

		register_post_meta(
			'fp_product',
			'_fp_units_per_pallet',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_post_meta(
			'fp_product',
			'_fp_featured',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ) => (bool) $value,
			)
		);
	}

	/**
	 * Render the product detail page (approved v7 variant A structure) from
	 * synchronized metadata only. No forms, no prototype controls: the quote
	 * action links the sole quotation surface /cotizacion/.
	 */
	public static function render_detail(): string {
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'fp_product' !== $post->post_type ) {
			return '';
		}

		$meta = static fn( string $key ) => (string) get_post_meta( $post->ID, $key, true );

		$title       = get_the_title( $post );
		$description = $meta( '_fp_description' );
		$material    = $meta( '_fp_material' );
		$material_s  = $meta( '_fp_material_short' ) ?: $material;
		$dimensions  = $meta( '_fp_dimensions' );
		$weight      = $meta( '_fp_weight_text' );
		$use         = $meta( '_fp_use' );
		$units       = (int) $meta( '_fp_units_per_pallet' );
		$minimum     = $meta( '_fp_quote_min_qty' );
		$provisional = '1' === $meta( '_fp_image_provisional' );

		ob_start();
		?>
		<article class="fpcq-product" data-fpcq-version="1">
			<nav class="fpcq-breadcrumb" aria-label="Migas de pan">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
				<span aria-hidden="true">/</span>
				<a href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>">Tienda</a>
				<span aria-hidden="true">/</span>
				<span aria-current="page"><?php echo esc_html( $title ); ?></span>
			</nav>

			<section class="fpcq-hero">
				<figure class="fpcq-gallery">
					<?php echo get_the_post_thumbnail( $post, 'large', array( 'class' => 'fpcq-image' ) ); ?>
					<?php if ( $provisional ) : ?>
						<figcaption class="fpcq-image-note">Imagen provisional — fotografía original pendiente</figcaption>
					<?php endif; ?>
				</figure>

				<div class="fpcq-summary">
					<p class="fpcq-kicker">Venta mayorista</p>
					<h1 class="fpcq-title"><?php echo esc_html( $title ); ?></h1>
					<p class="fpcq-description"><?php echo esc_html( $description ); ?></p>

					<dl class="fpcq-quick-specs">
						<div><dt>Medidas</dt><dd><?php echo esc_html( $dimensions ); ?></dd></div>
						<div><dt>Peso</dt><dd><?php echo esc_html( $weight ); ?></dd></div>
						<div><dt>Material</dt><dd><?php echo esc_html( $material_s ); ?></dd></div>
						<div class="fpcq-quick-pallet"><dt>Unidades por pallet</dt><dd><?php echo esc_html( number_format_i18n( $units ) ); ?></dd></div>
					</dl>

					<a class="fpcq-quote-cta" href="<?php echo esc_url( home_url( '/cotizacion/' ) ); ?>">Cotizar este producto</a>
					<p class="fpcq-contact-hint">¿Necesitas ayuda? <a href="tel:+56968444265">+56 9 6844 4265</a></p>
				</div>
			</section>

			<section class="fpcq-detail">
				<p class="fpcq-section-label">Ficha del producto</p>
				<h2 class="fpcq-detail-title">Información clara para decidir.</h2>
				<table class="fpcq-spec-table">
					<caption class="screen-reader-text">Especificaciones de <?php echo esc_html( $title ); ?></caption>
					<tbody>
						<tr><th scope="row">Material</th><td><?php echo esc_html( $material ); ?></td></tr>
						<tr><th scope="row">Medidas</th><td><?php echo esc_html( $dimensions ); ?></td></tr>
						<tr><th scope="row">Peso</th><td><?php echo esc_html( $weight ); ?></td></tr>
						<tr><th scope="row">Uso</th><td><?php echo esc_html( $use ); ?></td></tr>
						<tr><th scope="row">Unidades por pallet</th><td><?php echo esc_html( number_format_i18n( $units ) ); ?></td></tr>
						<tr><th scope="row">Cantidad mínima</th><td><?php echo esc_html( self::minimum_label( $minimum ) ); ?></td></tr>
					</tbody>
				</table>
				<p class="fpcq-pallet-note">Las unidades por pallet son un dato de embalaje; no constituyen un mínimo de compra confirmado.</p>
			</section>
		</article>
		<?php

		$html = (string) ob_get_clean();

		$related = json_decode( $meta( '_fp_related_ids' ) ?: '[]', true );
		if ( is_array( $related ) && array() !== $related ) {
			$html .= self::render_related( $related );
		}

		return $html;
	}

	/**
	 * Unconfirmed facts are shown as "Consultar"; units per pallet are never
	 * presented as a minimum.
	 */
	private static function minimum_label( string $minimum ): string {
		return ( '' === $minimum || '0' === $minimum ) ? 'Consultar' : sprintf( '%s unidades', number_format_i18n( (int) $minimum ) );
	}

	private static function render_related( array $related_ids ): string {
		$related = get_posts(
			array(
				'post_type'        => 'fp_product',
				'post_status'      => 'publish',
				'posts_per_page'   => 3,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fp_source_id',
						'value'   => $related_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		if ( array() === $related ) {
			return '';
		}

		$items = '';
		foreach ( $related as $related_post ) {
			$items .= sprintf(
				'<li class="fpcq-related-item"><a href="%s">%s<span>%s</span></a></li>',
				esc_url( get_permalink( $related_post ) ),
				get_the_post_thumbnail( $related_post, 'thumbnail', array( 'class' => 'fpcq-related-image' ) ),
				esc_html( get_the_title( $related_post ) )
			);
		}

		return sprintf(
			'<section class="fpcq-related"><p class="fpcq-section-label">También puede interesarte</p><h2 class="fpcq-detail-title">Otros productos</h2><ul class="fpcq-related-list">%s</ul></section>',
			$items
		);
	}
}
