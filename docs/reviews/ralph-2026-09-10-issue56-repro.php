<?php
// Offline fault injection against the ACTUAL issue-56 module (path in argv[1]).
// No WordPress boot, HTTP, DB, SMTP, Google or real customer data. wp_mail is
// intercepted independently; only temporary synthetic attachment bytes exist.
// Run: wordpress/.tools/php/php docs/reviews/ralph-2026-09-10-issue56-repro.php /path/to/quotation-approval.php
if (empty($argv[1])) { fwrite(STDERR, "Supply the issue-56 quotation-approval.php path\n"); exit(2); }
define('ABSPATH', __DIR__);
$GLOBALS['rows'] = []; $GLOBALS['mail_count'] = 0;
$GLOBALS['insert_ok'] = true; $GLOBALS['update_ok'] = false; $GLOBALS['renderer_throws'] = false;
$GLOBALS['projection'] = ['complete'=>true, 'lines'=>[], 'subtotal'=>100, 'total'=>119, 'tax'=>19, 'tax_rate_permille'=>190, 'validity_days'=>7];
function fpw_draft_preview_is_obsolete($preview, $work) { return $preview['revision'] !== $work['revision']; }
function fpw_quotation_projection($draft, $work) { return $GLOBALS['projection']; }
function wp_json_encode($value) { return json_encode($value); }
function fpw_insert_options_row($name, $value) {
    if (!$GLOBALS['insert_ok'] || isset($GLOBALS['rows'][$name])) return false;
    $GLOBALS['rows'][$name] = $value; return true;
}
function fpw_update_options_row($name, $value) {
    if (!$GLOBALS['update_ok']) return false;
    $GLOBALS['rows'][$name] = $value; return true;
}
$GLOBALS['wpdb'] = new class {
    public $options = 'synthetic_options';
    function prepare($sql, $name) { return $name; }
    function get_var($name) { return $GLOBALS['rows'][$name] ?? null; }
};
function apply_filters($tag, $value, ...$args) {
    if ($GLOBALS['renderer_throws']) throw new RuntimeException('synthetic renderer failure');
    return $value;
}
function date_i18n($format, $timestamp) { return '2026-09-10'; }
function get_option($key) { return 'Y-m-d'; }
function fpw_draft_clp_html($amount) { return (string)$amount; }
function fpw_draft_tax_rate_percent_html($amount) { return '19'; }
function esc_html($text) { return htmlspecialchars((string)$text); }
function wp_mail($to, $subject, $body, $headers, $attachments) {
    if (!is_file($attachments[0])) throw new RuntimeException('missing synthetic attachment');
    $GLOBALS['mail_count']++; return true;
}
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid-owner-nonce'; }
function get_current_user_id() { return 1; }
function fpw_read_draft_work($id) { return ['revision'=>2]; }
function fpw_read_draft_preview($id) { return ['revision'=>2, 'projection'=>$GLOBALS['projection']]; }
function fpw_pending_draft_outcome($outcome) { $GLOBALS['outcome'] = $outcome; }
require $argv[1];
$draft = ['reference'=>'FP-SYNTHETIC', 'identity'=>['email'=>'nobody@example.invalid']];
$work = ['revision'=>1];
$preview = ['revision'=>1, 'projection'=>$GLOBALS['projection']];
$failures = 0;
function check($ok, $name, $observed) {
    if (!$ok) $GLOBALS['failures']++;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ': ' . json_encode($observed) . "\n";
}
$result = fpw_quotation_approve_and_send(1, $draft, $work, $preview, 1);
$stored = fpw_read_quotation_version(1);
check($GLOBALS['mail_count'] === 0, 'must not send without persisting frozen PDF',
    ['returned'=>$result['state'], 'mails'=>$GLOBALS['mail_count'], 'stored_document'=>$stored['document'], 'stored_pdf'=>isset($stored['pdf_base64'])]);
check($result['state'] !== 'sent', 'must not claim successful delivery after failed storage', ['returned'=>$result['state']]);
$GLOBALS['insert_ok'] = false;
$result = fpw_quotation_approve_and_send(2, $draft, $work, $preview, 1);
check($result['state'] !== 'already' || is_array($result['version']), 'failed INSERT without existing row is not already approved', $result);
$GLOBALS['insert_ok'] = true; $GLOBALS['renderer_throws'] = true;
try {
    $result = fpw_quotation_approve_and_send(3, $draft, $work, $preview, 1);
    check($result['state'] === 'document-pending', 'renderer exception stays document-pending', ['returned'=>$result['state']]);
} catch (Throwable $error) {
    check(false, 'renderer exception stays document-pending', ['thrown'=>$error->getMessage()]);
}
// Tab A reviewed revision 1. Tab B saved and previewed revision 2. The actual
// approval form carries only order-scoped nonce + action, not the viewed
// revision; both legitimate tabs share that valid nonce. Submit tab A's form.
$GLOBALS['renderer_throws'] = false; $GLOBALS['update_ok'] = true;
$_POST = ['fpw_work_approve'=>'1', 'fpw_approve_nonce'=>'valid-owner-nonce'];
$before = $GLOBALS['mail_count'];
fpw_handle_draft_approve_request(4, $draft);
check($GLOBALS['mail_count'] === $before, 'stale owner tab must not approve a newer unseen preview',
    ['outcome'=>$GLOBALS['outcome']['result']['state'], 'viewed_revision'=>1,
     'approved_revision'=>fpw_read_quotation_version(4)['work_revision'] ?? null,
     'mails'=>$GLOBALS['mail_count']-$before]);
exit($failures ? 1 : 0);
