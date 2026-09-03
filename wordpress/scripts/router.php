<?php
/**
 * Router for the disposable WordPress server (php -S host:port router.php).
 *
 * Serves real files (assets, core bundles, PHP endpoints such as
 * wp-admin/admin-post.php) directly and routes everything else through
 * WordPress so pretty permalinks resolve without a web server.
 */
$wp_root = dirname( __DIR__ ) . '/.build/wp';
$uri     = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( '/' !== $uri ) {
	$file = $wp_root . $uri;
	if ( file_exists( $file ) && ! is_dir( $file ) && 'php' !== pathinfo( $file, PATHINFO_EXTENSION ) ) {
		return false; // Let the built-in server stream the static file.
	}
	if ( file_exists( $file ) && ! is_dir( $file ) && '/index.php' !== $uri ) {
		// Real PHP endpoints (wp-admin/*.php, wp-login.php) execute directly,
		// exactly as under a configured web server.
		$_SERVER['PHP_SELF'] = $uri;
		return false;
	}
}

$_SERVER['PHP_SELF'] = $uri;
require $wp_root . '/index.php';
wp();
