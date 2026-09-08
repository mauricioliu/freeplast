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

define( 'FREEPLAST_THEME_VERSION', '1.0.10' );
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
 * Enqueue the v6 shell stylesheet and the progressive navigation script.
 *
 * The stylesheet is mobile-first: adaptation happens only through
 * min-width media queries (see style.css).
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
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			wp_enqueue_script( 'freeplast-cart-quantity-feedback', get_template_directory_uri() . '/assets/js/cart-quantity-feedback.js', array(), FREEPLAST_THEME_VERSION, true );
		}
	}
);
