<?php
// Offline hook contract: no WordPress boot, sockets, SMTP or HTTP.
$mode = $argv[1] ?? 'allowed';
$root = sys_get_temp_dir() . '/fp-local-safety-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/www', 0700);
mkdir($root . '/mail', 0700);
define('ABSPATH', $root . '/www/');
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HOME', $mode === 'bad-host' ? 'https://freeplast.mliu.site' : 'http://mliu:8131');
define('FPW_LOCAL_CAPTURE_DIR', $mode === 'bad-dir' ? ABSPATH : $root . '/mail');
putenv('FREEPLAST_LOCAL_URL=' . WP_HOME);
$hooks = array();
function add_filter($tag, $fn, $priority = 10, $argc = 1) { $GLOBALS['hooks'][$tag][] = $fn; }
function add_action($tag, $fn, $priority = 10, $argc = 1) { add_filter($tag, $fn, $priority, $argc); }
function get_option($key) {
    $pre = false;
    foreach ($GLOBALS['hooks']['pre_option_' . $key] ?? array() as $filter) { $pre = $filter($pre); }
    return false !== $pre ? $pre : ($GLOBALS['mode'] === 'uninstalled' ? false : WP_HOME);
}
function wp_parse_url($url, $component) { return parse_url($url, $component); }
class WP_Error { public function __construct(public $code, public $message, public $data) {} }
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
try {
    $source = str_replace("require '/opt/local-review-mail-capture.php';", 'require ' . var_export(dirname(__DIR__) . '/scripts/local-review-mail-capture.php', true) . ';', file_get_contents(__DIR__ . '/safety.php'));
    try { eval(substr($source, 5)); }
    catch (RuntimeException $error) {
        if ($mode !== 'bad-host' || !str_contains($error->getMessage(), 'unknown environment/host')) { throw $error; }
        check(empty($hooks), 'unknown host must fail before loading any capture hooks');
        echo "local safety: unknown host fails closed\n";
        return;
    }
    check($mode !== 'bad-host', 'unknown host did not fail');
    check(get_option('home') === WP_HOME, 'validated canonical home available even before installation');
    check($hooks['pre_http_request'][0](null) instanceof WP_Error, 'HTTP must be blocked unconditionally');
    $result = $hooks['pre_wp_mail'][0](null, array('to'=>'test@example.invalid', 'subject'=>'LOCAL TEST', 'message'=>'synthetic', 'headers'=>array(), 'attachments'=>array()));
    check($result === ($mode !== 'bad-dir'), 'capture verdict must reflect evidence availability');
    if ($mode !== 'bad-dir') {
        check(file_exists($root . '/mail/index.jsonl'), 'accepted capture needs durable index');
        check((fileperms($root . '/mail/index.jsonl') & 0777) === 0600, 'capture evidence private');
    }
    try { $hooks['phpmailer_init'][0](); throw new LogicException('real transport not blocked'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'forbids real mail'), 'transport rejected'); }
    echo "local safety: $mode HTTP/mail containment passed\n";
} finally {
    foreach (glob($root . '/mail/*') ?: array() as $file) {
        if (is_dir($file)) { rmdir($file); } else { unlink($file); }
    }
    rmdir($root . '/mail'); rmdir($root . '/www'); rmdir($root);
}
