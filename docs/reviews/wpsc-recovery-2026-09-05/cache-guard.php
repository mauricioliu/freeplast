<?php
// Embedded at the end of wp-cache-config.php; not a separate runtime plugin.
// Freeplast 2026-09-05: keep WPSC's own files, bypass the hosting's shared cache.
if (!defined('ABSPATH')) { return; }
$fp_cache_control = 'private, no-store, no-cache, max-age=0, must-revalidate';
if (!headers_sent()) { header('Cache-Control: ' . $fp_cache_control); }
if (!defined('WPSC_CACHE_CONTROL_HEADER')) {
    define('WPSC_CACHE_CONTROL_HEADER', $fp_cache_control);
}
if (!defined('WPSC_SUPERCACHE_ONLY')) { define('WPSC_SUPERCACHE_ONLY', true); }
if (WPSC_CACHE_CONTROL_HEADER !== $fp_cache_control
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || ($_SERVER['HTTP_HOST'] ?? '') !== 'freeplast.cl'
    || !empty($_SERVER['HTTP_COOKIE'])
    || !empty($_COOKIE)
    || !empty($_SERVER['QUERY_STRING'])
    || !empty($_SERVER['HTTP_AUTHORIZATION'])
    || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE)
    || !preg_match('~^/(?:|producto/[a-z0-9-]+/|categoria-producto/[a-z0-9-]+/)$~D', $_SERVER['REQUEST_URI'] ?? '')
) {
    // Do not turn cache_enabled off here: admin edit hooks still need to invalidate files.
    if (!defined('WPSC_SERVE_DISABLED')) { define('WPSC_SERVE_DISABLED', true); }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
}
unset($fp_cache_control);
