<?php
// Standalone guard/privacy smoke tests; does not model WordPress performance.
$mode = $argv[1];
$root = '/tmp/fp-test-' . $mode;
mkdir($root);
define('ABSPATH', $root . '/');
define('WP_PLUGIN_DIR', $root . '/plugins');
$GLOBALS['hooks'] = array();
function add_action($hook, $cb, $priority = 10, $argc = 1) { $GLOBALS['hooks'][$hook][$priority][] = $cb; }
function add_filter($hook, $cb, $priority = 10, $argc = 1) { add_action($hook, $cb, $priority, $argc); }
function get_num_queries() { return 1; }
function getrusage_dummy() { return array(); }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return 200; }
$_SERVER = array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','REQUEST_TIME_FLOAT'=>microtime(true),'HTTP_X_FP_PROBE'=>'test-1');
if ($mode !== 'unauthorized') { $_SERVER['HTTP_X_FP_PERF'] = 'fixture-only'; }
if ($mode === 'cookie') { $_SERVER['HTTP_COOKIE'] = 'customer=private'; }
if ($mode === 'query') { $_SERVER['QUERY_STRING'] = 'email=private'; }
if ($mode === 'post') { $_SERVER['REQUEST_METHOD'] = 'POST'; }
if ($mode === 'route') { $_SERVER['REQUEST_URI'] = '/wp-admin/'; }
if ($mode === 'cleanup') { $_SERVER['HTTP_X_FP_PERF_CLEANUP'] = '1'; }
$file = $root . '/probe.php';
$log = $root . '/probe.jsonl';
$template = file_get_contents('/src/probe.php.template');
$source = str_replace(array('__EXPIRES__','__TOKEN_HASH__','__LOG_PATH__','__REMOVE_DIR__'), array($mode === 'expired' ? time()-1 : time()+100,hash('sha256','fixture-only'),$log,'false'), $template);
file_put_contents($file,$source);
if ($mode === 'cleanup') { file_put_contents($log,'fixture'); }
include $file;
if ($mode === 'capture') {
    $wpdb = new stdClass();
    $wpdb->queries = array(array("SELECT 'PRIVATE-SQL-TEXT'",0.001));
    ksort($GLOBALS['hooks']['shutdown']);
    foreach ($GLOBALS['hooks']['shutdown'] as $callbacks) { foreach ($callbacks as $cb) { $cb(); } }
    $text = file_get_contents($log);
    $data = json_decode($text,true);
    if (!$data || strpos($text,'PRIVATE-SQL-TEXT') !== false || $data['sql']['count'] !== 1 || $data['sql']['total_ms'] !== 1.0 && $data['sql']['total_ms'] !== 1) { throw new Exception('unsafe capture'); }
    if ((fileperms($log) & 0777) !== 0600) { throw new Exception('permissions'); }
} elseif ($mode === 'cleanup') {
    if (file_exists($file) || file_exists($log) || $GLOBALS['hooks']) { throw new Exception('cleanup failed'); }
} elseif ($GLOBALS['hooks'] || file_exists($log) || defined('SAVEQUERIES')) { throw new Exception('guard failed: '.$mode); }
echo "PASS $mode\n";
