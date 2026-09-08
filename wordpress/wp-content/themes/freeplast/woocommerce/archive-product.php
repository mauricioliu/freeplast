<?php
/**
 * Classic PHP fallback for the A catalog. Woo's legacy-template block does
 * not include this file; both paths share the native hook-based frame in
 * inc/catalog.php and the loop/catalog-intro.php partial.
 * Queries, notices, pagination and extension hooks remain native.
 * @package Freeplast
 */
defined( 'ABSPATH' ) || exit;
get_header( 'shop' );
do_action( 'woocommerce_before_main_content' );
do_action( 'woocommerce_archive_description' );
if ( woocommerce_product_loop() ) {
	do_action( 'woocommerce_before_shop_loop' );
	woocommerce_product_loop_start();
	if ( wc_get_loop_prop( 'total' ) ) {
		while ( have_posts() ) {
			the_post();
			do_action( 'woocommerce_shop_loop' );
			wc_get_template_part( 'content', 'product' );
		}
	}
	woocommerce_product_loop_end();
	do_action( 'woocommerce_after_shop_loop' );
} else {
	do_action( 'woocommerce_no_products_found' );
}
do_action( 'woocommerce_after_main_content' );
do_action( 'woocommerce_sidebar' );
get_footer( 'shop' );
