<?php
// Guard tests only. Real WPSC generation/serving/invalidation is tested on the host.
if (isset($argv[1])) {
    $case = json_decode($argv[1], true);
    define('ABSPATH', '/tmp/fp-test/');
    define('WP_CONTENT_DIR', '/tmp/fp-test/wp-content');
    $_SERVER = array_merge(array('REQUEST_METHOD'=>'GET', 'HTTP_HOST'=>'freeplast.cl', 'REQUEST_URI'=>'/producto/totem/'), $case['server'] ?? array());
    $_COOKIE = $case['cookies'] ?? array();
    if (!empty($case['donotcache'])) { define('DONOTCACHEPAGE', true); }
    if (!empty($case['conflict'])) { define('WPSC_CACHE_CONTROL_HEADER', 'public, max-age=3'); }
    require __DIR__ . '/cache-guard.php';
    $allowed = !defined('WPSC_SERVE_DISABLED') && !defined('DONOTCACHEPAGE');
    exit($allowed === $case['load'] ? 0 : 1);
}
$cases = array(
    'product'=>array('load'=>true),
    'home'=>array('load'=>true, 'server'=>array('REQUEST_URI'=>'/')),
    'category'=>array('load'=>true, 'server'=>array('REQUEST_URI'=>'/categoria-producto/agricola/')),
    'post'=>array('load'=>false, 'server'=>array('REQUEST_METHOD'=>'POST')),
    'head'=>array('load'=>false, 'server'=>array('REQUEST_METHOD'=>'HEAD')),
    'cookie'=>array('load'=>false, 'cookies'=>array('woocommerce_items_in_cart'=>'1')),
    'raw-cookie'=>array('load'=>false, 'server'=>array('HTTP_COOKIE'=>'malformed-cookie')),
    'query'=>array('load'=>false, 'server'=>array('QUERY_STRING'=>'s=test')),
    'query-uri'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/producto/totem/?s=test')),
    'auth'=>array('load'=>false, 'server'=>array('HTTP_AUTHORIZATION'=>'synthetic')),
    'redirect-auth'=>array('load'=>false, 'server'=>array('REDIRECT_HTTP_AUTHORIZATION'=>'synthetic')),
    'host'=>array('load'=>false, 'server'=>array('HTTP_HOST'=>'other.invalid')),
    'cart'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/carrito/')),
    'quote'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/cotizacion/')),
    'admin'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/wp-admin/')),
    'api'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/wp-json/wc/store/products')),
    'traversal'=>array('load'=>false, 'server'=>array('REQUEST_URI'=>'/producto/../')),
    'nocache'=>array('load'=>false, 'donotcache'=>true),
    'conflict'=>array('load'=>false, 'conflict'=>true),
);
$failed = 0;
foreach ($cases as $label=>$case) {
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(json_encode($case));
    passthru($cmd, $status);
    echo ($status === 0 ? 'PASS ' : 'FAIL ').$label."\n";
    $failed += $status !== 0;
}
exit($failed ? 1 : 0);
