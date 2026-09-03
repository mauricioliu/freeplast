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
 *   - /contacto/ and /politica-de-privacidad/ carry the complete v6
 *                          content since migration 5 (issue #12):
 *                          Contacto = current contact details + one CTA
 *                          into Cotización (never an inquiry form);
 *                          Política de privacidad = the basic
 *                          collection/submission disclosure (no consent
 *                          checkbox).
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
	 * The /contacto/ page content since migration 5: the current contact
	 * details (phone, email, WhatsApp, warehouse/map, hours) plus exactly
	 * one CTA into Cotización — never a form and never an Inquiry record.
	 */
	public static function contacto_content(): string {
		return self::block_heading( 'Contactanos', 1 ) .
			self::block_paragraph( 'Estamos disponibles, para recibir tu solicitud.' ) .
			self::block_heading( 'Visítanos', 2 ) .
			self::block_paragraph( 'Estamos en la caletera Camino El Arrayán 52, San Francisco de Mostazal, VI Región.' ) .
			self::block_paragraph( '<a href="https://maps.app.goo.gl/QtGSdagB55W7rnRj7" target="_blank" rel="noopener">Ver en el mapa</a>' ) .
			self::block_heading( 'Llámanos', 2 ) .
			self::block_paragraph( '<a href="tel:+56968444265">+ 56 9 6844 4265</a>' ) .
			self::block_paragraph( '<a href="https://api.whatsapp.com/send?phone=56968444265">WhatsApp + 56 9 6844 4265</a>' ) .
			self::block_heading( 'Escríbenos', 2 ) .
			self::block_paragraph( '<a href="mailto:ventas@freeplast.cl">ventas@freeplast.cl</a>' ) .
			self::block_heading( 'Horario', 2 ) .
			self::block_paragraph( 'Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs' ) .
			self::block_paragraph( '¿Necesitas precios mayoristas? Arma tu cotización con los productos que necesitas y envíala en una sola solicitud.' ) .
			self::block_button( 'Cotiza Online', home_url( '/cotizacion/' ) );
	}

	/**
	 * The pre-v6 /contacto/ placeholder content (issues #2–#11). Migration 5
	 * replaces exactly this content — human edits are never clobbered.
	 */
	public static function legacy_contacto_placeholder(): string {
		return self::block_heading( 'Contactanos', 1 ) .
			self::block_paragraph( 'Estamos disponibles, para recibir tu solicitud.' ) .
			self::block_paragraph( 'Visítanos: Camino El Arrayán 52, San Francisco de Mostazal, VI Región' ) .
			self::block_paragraph( 'Llámanos: <a href="tel:+56968444265">+ 56 9 6844 4265</a>' ) .
			self::block_paragraph( 'Escríbenos: <a href="mailto:ventas@freeplast.cl">ventas@freeplast.cl</a>' ) .
			self::block_paragraph( 'Horario: Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs' );
	}

	/**
	 * The /politica-de-privacidad/ page content since migration 5: the
	 * agreed basic collection/submission disclosure. Deliberately without
	 * any acknowledgement checkbox — answering a Quote Request never
	 * requires a standalone consent interaction (PRD #1).
	 */
	public static function privacy_content(): string {
		return self::block_heading( 'Política de privacidad', 1 ) .
			self::block_paragraph( 'Este sitio recopila únicamente la información necesaria para preparar y responder tu solicitud de cotización mayorista.' ) .
			self::block_heading( 'Qué información recopilamos', 2 ) .
			self::block_paragraph( 'Al enviar una cotización desde este sitio entregas tu nombre, teléfono, email, nombre de empresa, RUT, giro y, si solicitas despacho, la dirección de despacho confirmada, además de tu mensaje opcional y de los productos y cantidades de tu cotización.' ) .
			self::block_heading( 'Para qué la usamos', 2 ) .
			self::block_paragraph( 'Usamos estos datos exclusivamente para preparar y responder tu solicitud. La solicitud llega a Freeplast (ventas@freeplast.cl); este sitio no publica precios en línea ni realiza cobros.' ) .
			self::block_heading( 'Cotización y sesiones', 2 ) .
			self::block_paragraph( 'Tu selección de productos se guarda en una cotización anónima: una cookie de sesión que expira tras 30 días sin actividad. La cookie no contiene datos personales; los productos se guardan en el sitio hasta que envías tu solicitud o la sesión expira.' ) .
			self::block_heading( 'Consultas', 2 ) .
			self::block_paragraph( 'Para consultar o corregir tus datos escríbenos a <a href="mailto:ventas@freeplast.cl">ventas@freeplast.cl</a>.' ) .
			self::block_paragraph( 'Texto definitivo en revisión.', array( 'fp-note' ) );
	}

	/**
	 * The pre-v6 /politica-de-privacidad/ placeholder content (issues
	 * #2–#11). Migration 5 replaces exactly this content — human edits are
	 * never clobbered.
	 */
	public static function legacy_privacy_placeholder(): string {
		return self::block_heading( 'Política de privacidad', 1 ) .
			self::block_paragraph( 'Las cotizaciones que envíes a través de este sitio entregan tus datos de contacto y los productos solicitados a Freeplast, únicamente para preparar y responder tu solicitud. Texto definitivo en revisión.' );
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
				'content' => self::contacto_content(),
			),
			'politica-de-privacidad' => array(
				'title'   => 'Política de privacidad',
				'content' => self::privacy_content(),
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

	/** A core button block styled by the theme's primary control (v6 contract). */
	private static function block_button( string $label, string $url ): string {
		return sprintf(
			"<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->\n",
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
