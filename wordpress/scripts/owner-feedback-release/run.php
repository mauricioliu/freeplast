<?php
/** Fixed wp-release migration protocol; no standalone writes or HTTP entry point. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
require_once __DIR__.'/policy.php';
$mode=$args[0] ?? ''; $release=$args[1] ?? '';
if (count($args ?? []) !== 2 || !in_array($mode,['capture','apply','verify'],true) || !preg_match('/^[A-Za-z0-9_-]+$/D',$release)) { throw new RuntimeException('Expected mode and release ID.'); }
fp_feedback_target();
if ($mode === 'capture') { echo wp_json_encode(fp_feedback_capture($release)); return; }
$baseline=fp_feedback_baseline(json_decode(file_get_contents('php://stdin'),true,512,JSON_THROW_ON_ERROR),$release);
fp_feedback_maintenance($release);
if (fp_feedback_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Protected drift; retain maintenance.'); }
if ($mode === 'apply') { fp_feedback_apply($baseline); }
if (fp_feedback_state($baseline) !== 'after' || fp_feedback_digest($baseline) !== $baseline['original_digest']) { throw new RuntimeException('Expected three 20-unit minimums missing; retain maintenance.'); }
echo "owner_feedback_verified: 3 traversa minimums at 20; all other protected records unchanged\n";
