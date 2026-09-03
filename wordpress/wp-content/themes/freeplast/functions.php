<?php
/**
 * Freeplast theme bootstrap.
 *
 * The theme owns presentation of the approved v6 shell only. It contains no
 * catalog, quote-basket, quote-request, notification or sales logic — those
 * belong to the private freeplast-catalog-quotes plugin.
 *
 * @package Freeplast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FREEPLAST_THEME_VERSION', '0.2.0' );

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
		wp_enqueue_script( 'freeplast-nav', get_template_directory_uri() . '/assets/js/nav.js', array(), FREEPLAST_THEME_VERSION, true );
	}
);
