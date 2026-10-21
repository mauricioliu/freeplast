<?php
/** Full protected-record digest, with only the sealed three-row minimum projection. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__.'/migration/policy.php';
$baseline=fp_feedback_baseline(json_decode(file_get_contents('php://stdin'),true,512,JSON_THROW_ON_ERROR));
echo fp_feedback_digest($baseline) . "\n";
