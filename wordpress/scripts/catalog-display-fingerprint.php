<?php
/** Full protected fingerprint with a sealed, exact expected catalog-display projection. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__.'/migration/policy.php';
fp_display_target();$input=file_get_contents('php://stdin');
$baseline=trim($input)===''?null:fp_display_baseline(json_decode($input,true,512,JSON_THROW_ON_ERROR));
echo fp_display_digest($baseline)."\n";
