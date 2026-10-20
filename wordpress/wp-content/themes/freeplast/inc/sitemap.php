<?php
/** Live discovery of public content; no stored artifact to become stale on publish. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Collect canonical content URLs, excluding customer workflows and hidden products. */
function freeplast_sitemap_urls() {
	$urls = array( home_url( '/' ) );
	$excluded = array();
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$excluded[] = wc_get_page_id( $page );
		}
	}
	$types = array_values( array_intersect( array( 'page', 'post', 'product' ), get_post_types( array( 'public' => true ) ) ) );
	for ( $page = 1; ; $page++ ) {
		$query = new WP_Query( array(
			'post_type' => $types,
			'post_status' => 'publish',
			'has_password' => false,
			'post__not_in' => array_filter( $excluded, static fn( $id ) => $id > 0 ),
			'posts_per_page' => 500,
			'paged' => $page,
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
		) );
		foreach ( $query->posts as $post ) {
			// Also omit legacy workflow pages no longer assigned in Woo settings.
			if ( 'page' === $post->post_type ) {
				foreach ( array( 'cart', 'checkout', 'my-account' ) as $workflow ) {
					$shortcode = 'my-account' === $workflow ? 'my_account' : $workflow;
					if ( has_block( 'woocommerce/' . $workflow, $post ) || has_shortcode( $post->post_content, 'woocommerce_' . $shortcode ) ) {
						continue 2;
					}
				}
			}
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post->ID );
				if ( ! $product || 'hidden' === $product->get_catalog_visibility() ) {
					continue;
				}
			}
			$url = get_permalink( $post );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		if ( count( $query->posts ) < 500 ) {
			break;
		}
	}
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( ! is_wp_error( $url ) ) {
					$urls[] = $url;
				}
			}
		}
	}
	return array_values( array_unique( $urls ) );
}

add_action( 'template_redirect', static function () {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( wp_parse_url( home_url( '/sitemap.xml' ), PHP_URL_PATH ) !== $path ) {
		return;
	}
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: application/xml; charset=UTF-8' );
	// Discovery on private previews must not enable search engine indexing.
	if ( ! get_option( 'blog_public' ) ) {
		header( 'X-Robots-Tag: noindex, follow' );
	}
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( freeplast_sitemap_urls() as $url ) {
		echo '<url><loc>' . htmlspecialchars( $url, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</loc></url>' . "\n";
	}
	echo '</urlset>';
	exit;
}, 0 );

add_filter( 'robots_txt', static function ( $output ) {
	return rtrim( $output ) . "\n\nSitemap: " . home_url( '/sitemap.xml' ) . "\n";
} );
