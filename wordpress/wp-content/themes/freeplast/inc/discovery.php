<?php
/** RFC 8288 discovery of existing, public machine-readable resources. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep URL resolution install-aware (subdirectories and plain permalinks included). */
function freeplast_discovery_links() {
	$links = array(
		'<' . esc_url_raw( get_rest_url() ) . '>; rel="service-desc"; type="application/json"',
	);
	$object = get_queried_object();
	if ( $object instanceof WP_Post ) {
		$url = rest_get_route_for_post( $object );
		if ( $url ) {
			$links[] = '<' . esc_url_raw( get_rest_url( null, $url ) ) . '>; rel="describedby"; type="application/json"';
		}
	}
	return $links;
}

add_action( 'template_redirect', static function () {
	if ( ! is_front_page() || is_feed() || is_embed() || is_paged() ) {
		return;
	}
	foreach ( freeplast_discovery_links() as $link ) {
		// Append: WordPress and plugins may already advertise other relations.
		header( 'Link: ' . $link, false );
	}
}, 11 );
