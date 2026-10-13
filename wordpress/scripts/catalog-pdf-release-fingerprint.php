<?php
/** Read-only, plugins/themes skipped. Unexpected rows remain in the full digest. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__ . '/migration/policy.php';
fp_pdf_release_target();
$stdin = file_get_contents('php://stdin');
$baseline = trim($stdin) === '' ? null : fp_pdf_release_validate(json_decode($stdin, true, 512, JSON_THROW_ON_ERROR));
echo fp_pdf_release_digest($baseline) . "\n";
