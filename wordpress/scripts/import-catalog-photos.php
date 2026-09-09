<?php
/** Native WordPress media importer, manual/offline entry point, never auto-run.
 * wp eval-file wordpress/scripts/import-catalog-photos.php plan <manifest>
 * See wordpress/docs/catalog-photos-2026-09-09/README.md before any live use.
 */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
$args = $args ?? array();
$help = 'wp eval-file wordpress/scripts/import-catalog-photos.php [plan <manifest> | apply <manifest> <plan-token> <home-url> | help | version]';
if (count($args) === 1 && in_array($args[0], array('version','--version','-v','-V'), true)) { echo "1.0.0\n"; return; }
if (count($args) === 1 && in_array($args[0], array('help','--help'), true)) {
    echo 'usage: ' . json_encode($help) . "\n";
    echo "default: read-only plan\napply_requires: fresh plan token and matching home URL; local environment only until release migration hooks exist\n";
    return;
}
$mode = $args[0] ?? 'plan';
if (!(($mode === 'plan' && count($args) <= 2) || ($mode === 'apply' && count($args) === 4))) {
    echo "error: unknown command or incorrect arguments\nhelp: " . json_encode($help) . "\n";
    WP_CLI::halt(2);
}
try {
    $manifest = $args[1] ?? __DIR__ . '/../data/catalog-photos/manifest.json';
    if (!is_file($manifest)) { throw new RuntimeException('Manifest not found. Run prepare-catalog-photos.py first.'); }
    require_once __DIR__ . '/lib/catalog-photos.php';
    if ($mode === 'plan') {
        $plan = Freeplast_Catalog_Photos::plan($manifest);
        echo 'home: ' . json_encode($plan['home']) . "\ntoken: " . $plan['token'] . "\n";
        echo 'products[' . count($plan['rows']) . "]{slug,status}:\n";
        foreach ($plan['rows'] as $row) { echo '  ' . $row['entry']['slug'] . ',' . $row['status'] . "\n"; }
        return;
    }
    // The installed wp-release driver has no data-migration phase hook yet.
    // Do not turn verifyState into a mutating hook or import outside its backup,
    // trial and maintenance boundaries. Enable live execution only when the
    // driver can run the SAME migration in trial and install with regression tests.
    if (wp_get_environment_type() !== 'local') {
        throw new RuntimeException('Live import blocked: wp-release needs a tested migration hook in trial and install. Local fixture only.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $result = Freeplast_Catalog_Photos::apply($manifest, $args[2], $args[3]);
    echo 'changed: ' . $result['changed'] . "\nunchanged: " . $result['unchanged'] . "\n";
} catch (Throwable $error) {
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\n";
    echo "help: inspect the conflict; rerun plan without changing merchant-owned images\n";
    WP_CLI::halt(1);
}
