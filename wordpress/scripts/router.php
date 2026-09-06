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
	if ( is_file( $file ) ) {
		if ( 'php' !== pathinfo( $file, PATHINFO_EXTENSION ) ) {
			return false; // Let the built-in server stream the static file.
		}
		if ( '/index.php' !== $uri ) {
			// Real PHP endpoints (wp-admin/*.php, wp-login.php, admin-ajax.php)
			// execute directly, exactly as under a configured web server. The
			// built-in server resolves `return false` against its docroot (the
			// repository root, not the disposable install), so the file is
			// required from here; its own requires are __DIR__-based and the
			// working directory matches the file's, as under a real docroot.
			$_SERVER['PHP_SELF'] = $uri;
			chdir( dirname( $file ) );
			require $file;
			exit;
		}
	}
}

$_SERVER['PHP_SELF'] = $uri;
// index.php runs wp() and renders the template itself.
require $wp_root . '/index.php';
