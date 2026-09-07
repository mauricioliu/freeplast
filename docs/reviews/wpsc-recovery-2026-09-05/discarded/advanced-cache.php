<?php
// WP SUPER CACHE 1.2 — Freeplast guarded Simple-mode loader, 2026-09-05.
// WPSC's normal lifecycle recognizes this marker and removes the loader on deactivate.
// Keep this guard when upgrading/recreating the drop-in; see this directory's README.
if (!defined('ABSPATH')) {
    return;
}

// The hosting's shared cache confuses rewritten URLs. WPSC's disk cache is independent
// of these HTTP directives: never let the shared layer store/reuse PHP responses.
$fp_cache_control = 'private, no-store, no-cache, max-age=0, must-revalidate';
if (!headers_sent()) {
    header('Cache-Control: ' . $fp_cache_control);
}
if (defined('WPSC_CACHE_CONTROL_HEADER') && WPSC_CACHE_CONTROL_HEADER !== $fp_cache_control) {
    unset($fp_cache_control);
    return; // Conflicting settings must fail closed, not emit WPSC's public 3-second TTL.
}
if (!defined('WPSC_CACHE_CONTROL_HEADER')) {
    define('WPSC_CACHE_CONTROL_HEADER', $fp_cache_control);
}
unset($fp_cache_control);

// Only anonymous, query-free GETs for public catalog pages can read or populate cache.
// Bypass ANY raw Cookie header, not just recognized login/cart cookies.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || ($_SERVER['HTTP_HOST'] ?? '') !== 'freeplast.cl'
    || !empty($_SERVER['HTTP_COOKIE'])
    || !empty($_COOKIE)
    || !empty($_SERVER['QUERY_STRING'])
    || !empty($_SERVER['HTTP_AUTHORIZATION'])
    || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE)
    || !preg_match('~^/(?:|producto/[a-z0-9-]+/|categoria-producto/[a-z0-9-]+/)$~D', $_SERVER['REQUEST_URI'] ?? '')
) {
    return;
}

if (!defined('WPCACHEHOME')) {
    define('WPCACHEHOME', WP_CONTENT_DIR . '/plugins/wp-super-cache/');
}
if (!defined('WPSC_SUPERCACHE_ONLY')) {
    define('WPSC_SUPERCACHE_ONLY', true);
}
if (is_readable(WPCACHEHOME . 'wp-cache-phase1.php')) {
    require_once WPCACHEHOME . 'wp-cache-phase1.php';
}
