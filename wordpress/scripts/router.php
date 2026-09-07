<?php
/**
 * Router for the disposable WordPress server (php -S host:port router.php).
 *
 * Serves real files (assets, core bundles, uploads, PHP endpoints such as
 * wp-admin/admin-post.php) directly and routes everything else through
 * WordPress so pretty permalinks resolve without a web server.
 *
 * Static files are streamed by this script instead of returning false:
 * the built-in server resolves `return false` against its docroot (the
 * repository root), where only the site's own wp-content exists — core
 * assets under /wp-includes/, the WooCommerce plugin bundle under
 * /wp-content/plugins/woocommerce/ and everything under
 * /wp-content/uploads/ live only in the disposable install (.build/wp)
 * and would 404. The one source of truth for what this server serves is
 * the disposable install, same as for PHP templates (re-run bootstrap to
 * pick up repo changes).
 */
$wp_root = dirname( __DIR__ ) . '/.build/wp';
$uri     = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( '/' !== $uri ) {
	$file = $wp_root . $uri;
	if ( is_file( $file ) ) {
		// Containment guard: resolve the real path and refuse anything
		// that escapes the disposable install (.. segments, symlinks).
		$root_real = realpath( $wp_root );
		$real      = realpath( $file );
		if ( false === $real || false === $root_real || ! str_starts_with( $real, $root_real . DIRECTORY_SEPARATOR ) ) {
			http_response_code( 404 );
			exit;
		}

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );

		if ( 'php' !== $ext ) {
			// Real static asset: stream it from the disposable install with
			// an explicit Content-Type (the built-in server would look in its
			// docroot, the repository root, and 404 for most of them).
			static $types = array(
				'css'         => 'text/css; charset=utf-8',
				'js'          => 'text/javascript; charset=utf-8',
				'mjs'         => 'text/javascript; charset=utf-8',
				'json'        => 'application/json; charset=utf-8',
				'map'         => 'application/json',
				'webmanifest' => 'application/manifest+json',
				'png'         => 'image/png',
				'jpg'         => 'image/jpeg',
				'jpeg'        => 'image/jpeg',
				'gif'         => 'image/gif',
				'webp'        => 'image/webp',
				'avif'        => 'image/avif',
				'svg'         => 'image/svg+xml',
				'ico'         => 'image/x-icon',
				'woff'        => 'font/woff',
				'woff2'       => 'font/woff2',
				'ttf'         => 'font/ttf',
				'otf'         => 'font/otf',
				'eot'         => 'application/vnd.ms-fontobject',
				'txt'         => 'text/plain; charset=utf-8',
				'html'        => 'text/html; charset=utf-8',
				'xml'         => 'application/xml',
				'pdf'         => 'application/pdf',
				'mp4'         => 'video/mp4',
				'webm'        => 'video/webm',
			);
			header( 'Content-Type: ' . ( $types[ $ext ] ?? ( function_exists( 'mime_content_type' ) ? mime_content_type( $real ) : 'application/octet-stream' ) ) );
			header( 'Content-Length: ' . filesize( $real ) );
			if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
				readfile( $real );
			}
			exit;
		}

		// Never execute PHP found under the uploads directory — media only,
		// exactly like a hardened production host.
		if ( str_contains( $real, '/wp-content/uploads/' ) ) {
			http_response_code( 403 );
			exit;
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
