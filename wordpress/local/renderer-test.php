<?php
// Exercise the runtime gate's actual fixture and native lazy loader, without a WordPress boot.
define('ABSPATH', __DIR__ . '/');
function apply_filters($tag, $value, ...$args) { return $value; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wc_attribute_label($name) { return $name; }
function fpw_draft_tax_rate_percent_html($rate) { return (string) ($rate / 10); }
require dirname(__DIR__) . '/wp-content/plugins/freeplast-woo/quotation-approval.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
check(!function_exists('fpw_quotation_dompdf_render'), 'renderer must start unloaded');
$source = file_get_contents(__DIR__ . '/runtime-check.php');
$start = strpos($source, '$version = array(');
$end = strpos($source, '$path = FPW_LOCAL_CAPTURE_DIR', $start);
check(false !== $start && false !== $end, 'runtime PDF fixture boundaries missing');
function fp_local_check($ok, $message) { check($ok, $message); }
eval(substr($source, $start, $end - $start));
check(function_exists('fpw_quotation_dompdf_render'), 'native seam must load document renderer');
check(fpw_quotation_pdf_is_valid($pdf), 'runtime fixture must render a complete real PDF');
echo "local renderer: lazy loader + actual runtime fixture PDF passed offline\n";
