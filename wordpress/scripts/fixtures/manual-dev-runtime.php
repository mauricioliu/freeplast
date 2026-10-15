<?php
// Offline local-runtime fixture: real containment, private sink, no WP boot or network.
$manual_mode = getenv('FREEPLAST_TEST_MANUAL_MODE') ?: 'missing';
function wp_get_environment_type() { return WP_ENVIRONMENT_TYPE; }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component) { return parse_url($url, $component); } }
if (!function_exists('get_option')) { function get_option($key, $default = false) { return $key === 'home' ? WP_HOME : $default; } }
define('WP_ENVIRONMENT_TYPE', in_array($manual_mode, array('staging', 'production'), true) ? $manual_mode : 'local');
define('WP_HOME', $manual_mode === 'bad-host' ? 'http://mliu.evil:8131' : 'http://mliu:8131');
putenv('FREEPLAST_LOCAL_URL=' . ($manual_mode === 'mismatch' ? 'http://localhost:8131' : WP_HOME));
$GLOBALS['fpwp_options']['home'] = WP_HOME;
$_POST['FPW_LOCAL_MANUAL_ADDRESS'] = $_GET['FPW_LOCAL_MANUAL_ADDRESS'] = $_COOKIE['FPW_LOCAL_MANUAL_ADDRESS'] = '1';
$_SERVER['HTTP_HOST'] = 'mliu:8131';
$manual_sink = sys_get_temp_dir() . '/fp-manual-' . bin2hex(random_bytes(8));
mkdir($manual_sink, 0700);
define('FPW_LOCAL_CAPTURE_DIR', $manual_mode === 'bad-sink' ? ABSPATH : $manual_sink);
register_shutdown_function(static function () use ($manual_sink) { rmdir($manual_sink); });
if (in_array($manual_mode, array('missing-guard', 'wrong-marker'), true)) {
    require_once dirname(__DIR__, 2) . '/scripts/local-review-mail-capture.php';
    if ($manual_mode === 'wrong-marker') { define('FPW_LOCAL_MANUAL_ADDRESS', '1'); }
}
elseif ($manual_mode === 'constant-only') { define('FPW_LOCAL_MANUAL_ADDRESS', true); }
elseif ($manual_mode !== 'missing') {
    $source = str_replace("require '/opt/local-review-mail-capture.php';", 'require_once ' . var_export(dirname(__DIR__, 2) . '/scripts/local-review-mail-capture.php', true) . ';', file_get_contents(dirname(__DIR__, 2) . '/local/safety.php'));
    try { eval(substr($source, 5)); }
    catch (RuntimeException $error) {
        if (!in_array($manual_mode, array('staging', 'production', 'bad-host', 'mismatch'), true)) { throw $error; }
        // Even a copied marker and capture library must not override runtime identity.
        define('FPW_LOCAL_MANUAL_ADDRESS', true);
        require_once dirname(__DIR__, 2) . '/scripts/local-review-mail-capture.php';
    }
}
