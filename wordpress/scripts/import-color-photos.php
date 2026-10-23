<?php
/** Lead only, after verified local DB/uploads backup. No original image is removed. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
$args = $args ?? array();
$usage = 'wp eval-file wordpress/scripts/import-color-photos.php [plan | apply <token> <home>]';
if ($args === array('help') || $args === array('--help')) { echo 'usage: ' . json_encode($usage) . "\n"; return; }
try {
    $mode = $args[0] ?? 'plan';
    if (!(($mode === 'plan' && count($args) <= 1) || ($mode === 'apply' && count($args) === 3))) { throw new RuntimeException('Unknown command or incorrect arguments.'); }
    require_once __DIR__ . '/lib/color-photos.php';
    $manifest = __DIR__ . '/../data/color-photos/manifest.json';
    if ($mode === 'plan') {
        $plan = fp_color_photos_plan($manifest);
        echo 'home: ' . json_encode($plan['home']) . "\ntoken: " . $plan['token'] . "\n";
        foreach ($plan['rows'] as $row) { echo 'variation_' . $row['id'] . ': ' . ($row['current'] ? 'unchanged' : 'attach') . "\n"; }
    } else {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $result = fp_color_photos_apply($manifest, $args[1], $args[2]);
        echo 'changed: ' . $result['changed'] . "\nunchanged: " . $result['unchanged'] . "\n";
    }
} catch (Throwable $error) {
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: inspect current state before retry; " . json_encode($usage) . "\n";
    WP_CLI::halt(1);
}
