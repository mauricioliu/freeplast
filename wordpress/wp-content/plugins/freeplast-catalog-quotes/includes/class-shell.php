<?php
/**
 * Seeds the Freeplast shell routes on activation.
 *
 * The shell baseline (issue #2) needs stable URLs for the approved v6
 * navigation. Pages are seeded once and never overwritten: content edits
 * remain possible, and later slices take ownership of their routes:
 *
 *   - /tienda/            owned by the fp_product archive since migration 2
 *                          (issue #3). The placeholder page is retired by
 *                          that migration and never seeded again.
 *   - /cotizacion/        stays a WordPress page whose content is owned by
 *                          the plugin's freeplast/basket block since
 *                          migration 4 (issue #6): the full quote-basket
 *                          view. The quote-request form is added by the
 *                          submission slice (issue #8) on this same page.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Shell {

	/**
	 * The /cotizacion/ page content since migration 4: the quote-basket
	 * view block. Seeded for fresh installs and swapped in by migration 4
	 * from the same single source.
	 */
	public const COTIZACION_CONTENT = "<!-- wp:freeplast/basket /-->\n";

	/**
	 * The pre-basket /cotizacion/ placeholder content (issues #2–#5), built
	 * by the same helpers that seeded it. Migration 4 replaces exactly this
	 * content with the basket block — any human edit made meanwhile is
	 * left untouched.
	 */
	public static function legacy_cotizacion_placeholder(): string {
		return self::block_group(
			self::block_heading( 'Tu cotización está vacía', 2 ) .
			self::block_paragraph( 'Explora la tienda y agrega productos para solicitar una cotización mayorista.', array( 'fp-empty-note' ) ),
			array( 'fp-empty' )
		);
	}

	/**
	 * Seed the shell pages. Idempotent: existing slugs are never duplicated
	 * or overwritten.
	 */
	public static function seed(): void {
		$pages = array(
			'cotizacion'             => array(
				'title'   => 'Cotización',
				'content' => self::COTIZACION_CONTENT,
			),
			'tienda'                 => array(
				'title'   => 'Tienda',
				'content' => self::block_group(
					self::block_heading( 'El catálogo se está preparando', 2 ) .
					self::block_paragraph( 'Los productos de Freeplast se publicarán aquí pronto. Mientras tanto, contáctanos directamente.', array( 'fp-empty-note' ) ),
					array( 'fp-empty' )
				),
			),
			'nosotros'               => array(
				'title'   => 'Nosotros',
				'content' => self::block_heading( 'Somos los mejores en el mercado del plástico', 1 ) .
					self::block_paragraph( 'Comercializamos productos de excelente calidad', array() ) .
					self::block_heading( 'Nuestra Misión', 2 ) .
					self::block_paragraph( 'Promover una cultura de cuidado del medio ambiente y de compromiso con la sustentabilidad a través de la comercialización de productos hechos en base a plástico reciclado', array() ) .
					self::block_heading( 'Nuestra Visión', 2 ) .
					self::block_paragraph( 'Ser la principal empresa comercializadora de productos plásticos de Chile, vendiendo productos de alta calidad que ayuden al desarrollo sustentable de las actividades económicas de nuestro país.', array() ),
			),
			'contacto'               => array(
				'title'   => 'Contacto',
				'content' => self::block_heading( 'Contactanos', 1 ) .
					self::block_paragraph( 'Estamos disponibles, para recibir tu solicitud.', array() ) .
					self::block_paragraph( 'Visítanos: Camino El Arrayán 52, San Francisco de Mostazal, VI Región', array() ) .
					self::block_paragraph( 'Llámanos: <a href="tel:+56968444265">+ 56 9 6844 4265</a>', array() ) .
					self::block_paragraph( 'Escríbenos: <a href="mailto:ventas@freeplast.cl">ventas@freeplast.cl</a>', array() ) .
					self::block_paragraph( 'Horario: Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs', array() ),
			),
			'politica-de-privacidad' => array(
				'title'   => 'Política de privacidad',
				'content' => self::block_heading( 'Política de privacidad', 1 ) .
					self::block_paragraph( 'Las cotizaciones que envíes a través de este sitio entregan tus datos de contacto y los productos solicitados a Freeplast, únicamente para preparar y responder tu solicitud. Texto definitivo en revisión.', array() ),
			),
		);

		$seeded = get_option( 'fp_shell_pages', array() );

		/* Since migration 2 the fp_product archive owns /tienda/. */
		if ( (int) get_option( 'fp_db_version', 0 ) >= 2 ) {
			unset( $pages['tienda'] );
		}

		foreach ( $pages as $slug => $page ) {
			if ( self::page_exists( $slug ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page['title'],
					'post_name'    => $slug,
					'post_content' => $page['content'],
				),
				true
			);

			if ( is_int( $page_id ) && $page_id > 0 ) {
				$seeded[ $slug ] = $page_id;
			}
		}

		update_option( 'fp_shell_pages', $seeded );

		/* No direct flush here: a late plugin activation (wp plugin activate)
	   runs this hook before the fp_product post type is registered in the
	   running process. The activation hook requests a flag-based flush that
	   runs on init (Freeplast_CQ_Migrations::flush_if_needed). */
	}

	private static function page_exists( string $slug ): bool {
		$query = new WP_Query(
			array(
				'post_type'              => 'page',
				'name'                   => $slug,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return $query->have_posts();
	}

	private static function block_group( string $inner, array $classes = array() ): string {
		$class_list = esc_attr( implode( ' ', $classes ) );
		$attribute  = $classes ? sprintf( ",\"className\":\"%s\"", $class_list ) : '';
		return sprintf(
			"<!-- wp:group {%s\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group %s\">\n%s</div>\n<!-- /wp:group -->\n",
			$attribute,
			$class_list,
			$inner
		);
	}

	private static function block_heading( string $text, int $level ): string {
		return sprintf(
			"<!-- wp:heading {\"level\":%d} -->\n<h%d class=\"wp-block-heading\">%s</h%d>\n<!-- /wp:heading -->\n",
			$level,
			$level,
			esc_html( $text ),
			$level
		);
	}

	private static function block_paragraph( string $html, array $classes = array() ): string {
		$class = $classes ? sprintf( ' class="%s"', esc_attr( implode( ' ', $classes ) ) ) : '';
		return sprintf(
			"<!-- wp:paragraph -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->\n",
			$class,
			$html
		);
	}
}
