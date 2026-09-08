<?php
/**
 * Freeplast theme bootstrap.
 *
 * The theme owns presentation of the approved v6 shell only. It contains no
 * catalog, quote-basket, quote-request, notification or sales logic — those
 * belong to WooCommerce, Quotes for WooCommerce and the small freeplast-woo adapter.
 *
 * @package Freeplast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FREEPLAST_THEME_VERSION', '1.0.14' );
add_action('after_setup_theme', static function () {
	add_theme_support('woocommerce');
	add_theme_support('wc-product-gallery-lightbox');
});
add_action('wp', static function () {
	remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
	remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
	remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
});

/**
 * Resolve the position-independent theme-asset token in block markup.
 *
 * Block templates and parts are static HTML — they cannot call PHP — so
 * theme-owned asset URLs are written as "{{FREEPLAST_THEME_URL}}/assets/…".
 * At render time the token is replaced with the theme directory URL computed
 * by WordPress itself (get_theme_file_uri()), so the same markup renders
 * correct asset URLs on any install path (root or subdirectory) instead of
 * breaking on anything but a root install.
 *
 * The Site Editor previews static wp:html blocks from their saved markup
 * without a server render, so editors see the literal token there; the
 * rendered site — the approved v6 shell — is always resolved.
 */
add_filter(
	'render_block',
	static function ( $block_content ) {
		if ( ! str_contains( $block_content, '{{FREEPLAST_THEME_URL}}' ) ) {
			return $block_content;
		}

		return str_replace(
			'{{FREEPLAST_THEME_URL}}',
			wp_make_link_relative( get_theme_file_uri() ),
			$block_content
		);
	}
);

/**
 * Resolve the Productos a Cotizar line-count token in block markup.
 *
 * The header part is static wp:html, so the basket count is written as
 * "{{FREEPLAST_BASKET_COUNT}}" and replaced at render time with the distinct
 * line count of the WooCommerce cart supplied by the freeplast-woo adapter
 * (fpw_cart_line_count()). Every public route therefore server-renders the
 * current number on first paint — including without JavaScript — and the
 * documented empty state is (0): coherent and never a stale flash. With
 * JavaScript, classic AJAX adds refresh the span through Woo's native
 * add-to-cart fragments and assets/js/basket-count.js bridges the cart
 * block's own Store API mutations.
 */
add_filter(
	'render_block',
	static function ( $block_content ) {
		if ( ! str_contains( $block_content, '{{FREEPLAST_BASKET_COUNT}}' ) ) {
			return $block_content;
		}

		$count = function_exists( 'fpw_cart_line_count' ) ? fpw_cart_line_count() : 0;
		return str_replace( '{{FREEPLAST_BASKET_COUNT}}', (string) $count, $block_content );
	}
);

/**
 * Enqueue the shell stylesheet and the progressive chrome scripts.
 *
 * The stylesheet is mobile-first: adaptation happens only through
 * min-width media queries (see style.css). The shared chrome (persistent
 * header, menu/help dialogs, footer) implements the frozen A · Directa
 * quote-journey contract; Manrope loads from the local OFL-licensed file
 * declared by the stylesheet and is preloaded so first paint uses it.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'freeplast-shell', get_stylesheet_uri(), array(), FREEPLAST_THEME_VERSION );
		wp_enqueue_style( 'freeplast-woo-theme', get_template_directory_uri().'/assets/css/woo.css', array('freeplast-shell'), FREEPLAST_THEME_VERSION );
		wp_enqueue_script( 'freeplast-nav', get_template_directory_uri() . '/assets/js/nav.js', array(), FREEPLAST_THEME_VERSION, true );
		wp_enqueue_script( 'freeplast-basket-count', get_template_directory_uri() . '/assets/js/basket-count.js', array(), FREEPLAST_THEME_VERSION, true );
		// Product-card quantity mirror: any route may render a product loop (home
		// featured grid, shop archive, search results), and the script is inert
		// wherever the adapter's [data-fpw-loop-add] wrapper is absent.
		wp_enqueue_script( 'freeplast-loop-quantity', get_template_directory_uri() . '/assets/js/loop-add-to-cart-quantity.js', array(), FREEPLAST_THEME_VERSION, true );
		// Per-product quantities/removal: native Woo handlers own mutations and
		// fragment refresh owns session restoration on cached/navigation pages.
		wp_enqueue_script( 'freeplast-added-count', get_template_directory_uri() . '/assets/js/loop-added-count.js', array( 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ), FREEPLAST_THEME_VERSION, true );
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'freeplast-variation-state', get_template_directory_uri() . '/assets/js/variation-button-state.js', array(), FREEPLAST_THEME_VERSION, true );
			wp_enqueue_script( 'freeplast-color-options', get_template_directory_uri() . '/assets/js/product-color-options.js', array( 'jquery', 'wc-add-to-cart-variation' ), FREEPLAST_THEME_VERSION, true );
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			wp_enqueue_script( 'freeplast-checkout-form', get_template_directory_uri() . '/assets/js/checkout-form.js', array( 'jquery', 'wc-checkout' ), FREEPLAST_THEME_VERSION, true );
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			wp_enqueue_script( 'freeplast-cart-quantity-feedback', get_template_directory_uri() . '/assets/js/cart-quantity-feedback.js', array(), FREEPLAST_THEME_VERSION, true );
		}
	}
);

// Native checkout's generic transport fallback must not imply payment or a
// definitely unpersisted request. Keep the native response/recovery path.
add_filter( 'woocommerce_get_script_data', static function ( $params, $handle ) {
	if ( 'wc-checkout' === $handle && is_array( $params ) ) {
		$params['i18n_checkout_error'] = 'No pudimos confirmar tu solicitud. Puede que ya se haya guardado. Reintenta desde este mismo formulario para conservar el intento original.';
	}
	return $params;
}, 10, 2 );

/* A · Directa control copy (#42): Woo's own loop/single add-to-cart text
 * filters carry the reference labels; contexts stay distinguishable because
 * Woo routes loop text and single-form text through separate filters. */
add_filter(
	'woocommerce_product_add_to_cart_text',
	static function ( $text, $product ) {
		return ( $product && $product->is_type( 'simple' ) ) ? 'Agregar' : $text;
	},
	10,
	2
);
add_filter(
	'woocommerce_product_single_add_to_cart_text',
	static function () {
		return 'Agregar a cotización';
	}
);

require_once __DIR__ . '/inc/catalog.php';

/** Recognize the migrated pending-photo attachment, not a product-id list.
 * Replacing that attachment in Woo immediately restores the real photograph. */
function fp_theme_has_product_photo( $product ): bool {
	$id = $product->get_image_id();
	if ( ! $id ) { return false; }
	$alt = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
	return ! str_starts_with( $alt, 'Imagen referencial pendiente para ' );
}

/**
 * A · Directa mobile selection dock (issue #42): a projection of Woo's cart
 * rendered on catalog/product routes. The number always comes from the same
 * native cart the header count reads; classic AJAX adds refresh the dock
 * through Woo's own fragment mechanism and the Cart block's Store API
 * mutations are bridged by assets/js/basket-count.js. No second basket.
 */
function fp_theme_catalog_view(): bool {
	// The cart route owns its own CTA composition; A shows no dock there.
	return ( function_exists( 'is_woocommerce' ) && is_woocommerce() && ! ( function_exists( 'is_cart' ) && is_cart() ) )
		|| ( function_exists( 'is_search' ) && is_search() && 'product' === get_query_var( 'post_type' ) );
}

function fp_theme_selection_totals(): array {
	$lines = 0;
	$units = 0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['quantity'] <= 0 ) { continue; }
			$lines++;
			$units += (int) $item['quantity'];
		}
	}
	return array( $lines, $units );
}

function fp_theme_selection_dock_markup(): string {
	list( $lines, $units ) = fp_theme_selection_totals();
	$plural_lines = 1 === $lines ? 'producto seleccionado' : 'productos seleccionados';
	$plural_units = 1 === $units ? 'unidad' : 'unidades';
	$inner = '<div><strong>' . esc_html( $lines . ' ' . $plural_lines ) . '</strong><span>' . esc_html( number_format_i18n( $units ) . ' ' . $plural_units . ' · sin pago en línea' ) . '</span></div>'
			. '<a class="button" href="' . esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cotizacion/' ) . '">Revisar <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><path d="M4 12h15M13 5l7 7-7 7"/></svg></a>'
		;
	return '<div class="fpw-selection-dock" data-fpw-selection-dock' . ( $lines > 0 ? '' : ' hidden' ) . '>' . $inner . '</div>';
}

add_action(
	'wp_footer',
	static function () {
		if ( fp_theme_catalog_view() ) {
			echo fp_theme_selection_dock_markup();
		}
	},
	7
);

/* The dock joins the native fragment refresh (same request the header count
 * and the card projections ride); it renders empty+hidden when nothing is
 * selected so a stale bar can never survive an authoritative empty state.
 * The product sheet's own added-state projection rides the same mechanism. */
function fp_theme_detail_added( int $product_id ): string {
	$units = 0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) { $units += (int) $item['quantity']; }
		}
	}
	$inner = '<p class="fp-notice-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="m5 12 4 4L19 6"/></svg><strong>' . esc_html( number_format_i18n( $units ) . ( 1 === $units ? ' unidad de este producto' : ' unidades de este producto' ) . ' en tu selección.' ) . '</strong></p>'
			. '<a class="fp-detail-link" href="' . esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cotizacion/' ) . '">Revisar Productos a Cotizar</a>'
		;
	return '<div class="fpw-detail-added" data-fpw-detail-added data-product-id="' . esc_attr( $product_id ) . '"' . ( $units > 0 ? '' : ' hidden' ) . '>' . $inner . '</div>';
}

add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( $fragments ) {
		// wc-ajax has no page conditional. Return the complete native projection
		// regardless of the calling route; absent DOM targets remain untouched.
		$fragments['div.fpw-selection-dock'] = fp_theme_selection_dock_markup();
		// Product detail consumes the adapter's complete per-product snapshot,
		// including an authoritative empty state, not an AJAX queried-object ID.
		return $fragments;
	}
);

/* Body classes reserve the dock's viewport space only where it can appear. */
add_filter(
	'body_class',
	static function ( $classes ) {
		if ( fp_theme_catalog_view() ) {
			$classes[] = 'fpw-has-dock';
			list( $lines ) = fp_theme_selection_totals();
			if ( $lines > 0 ) { $classes[] = 'fpw-has-selection'; }
		}
		return $classes;
	}
);

/* A · Directa breadcrumb and related framing (issue #44). */
function fp_theme_place_product_breadcrumb() {
	if ( ! is_product() ) { return; }
	// ClassicTemplate renders this hook BEFORE our sheet's own breadcrumb.
	// Suppress only that outer duplicate, at its actual registered priority.
	$priority = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
	if ( false !== $priority ) { remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', $priority ); }
}
add_action( 'wp', 'fp_theme_place_product_breadcrumb' );
add_filter(
	'woocommerce_breadcrumb_defaults',
	static function ( $defaults ) {
		$defaults['delimiter'] = '<span class="fp-breadcrumb-sep" aria-hidden="true">›</span> ';
		$defaults['home'] = false;
		return $defaults;
	}
);
add_filter( 'woocommerce_related_products_columns', static fn() => 3 );

/**
 * Preload the local Manrope file the stylesheet declares (A · Directa
 * contract: the typeface must actually load, not silently fall back).
 * Fonts are same-origin and crossorigin-exempt from double fetch only with
 * the crossorigin attribute, matching the stylesheet's @font-face request.
 */
add_action(
	'wp_head',
	static function () {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
			esc_url( get_theme_file_uri( '/assets/fonts/manrope.woff2' ) )
		);
	},
	5
);
