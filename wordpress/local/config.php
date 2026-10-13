<?php
// Loaded by wp-config before WordPress, including installation and CLI.
$url = getenv('FREEPLAST_LOCAL_URL');
if (!is_string($url) || !preg_match('~\Ahttp://(?:mliu|localhost|127\.0\.0\.1):[1-9][0-9]{3,4}\z~D', $url)) {
    throw new RuntimeException('Local URL missing or not allowlisted.');
}
if (PHP_SAPI !== 'cli') {
    $port = parse_url($url, PHP_URL_PORT);
    if (!in_array(strtolower($_SERVER['HTTP_HOST'] ?? ''), array("mliu:$port", "localhost:$port", "127.0.0.1:$port"), true)) {
        http_response_code(421);
        exit('Local host rejected.');
    }
}
if (!is_file('/var/www/html/wp-content/mu-plugins/000-freeplast-local.php') || !is_file('/opt/local-review-mail-capture.php')) {
    throw new RuntimeException('Local containment missing; refusing WordPress startup.');
}
define('WP_HOME', $url);
define('WP_SITEURL', $url);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', !(defined('WP_CLI') && WP_CLI));
define('WP_CACHE', false);
if (!defined('WP_DEBUG')) { define('WP_DEBUG', true); }
define('WP_DEBUG_DISPLAY', false);
define('FPW_LOCAL_CAPTURE_DIR', '/var/freeplast/mail');
