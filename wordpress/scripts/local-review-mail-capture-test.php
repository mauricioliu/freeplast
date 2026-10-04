<?php
/** H0 (2026-10-03 review) offline self-test for the local review capture mu-plugin.
 * Verifies, without WordPress: the host allowlist; the webroot/document-root/
 * uploads/symlink/permission boundaries of the capture dir (the 2026-10-04 lead
 * review RED repro: a dir under the WP root was accepted); exact-evidence
 * capture (recipient shape, attachment bytes + SHA-256, homonymous attachments,
 * index completeness) with honest capture-failed rejection — never a simulated
 * acceptance without complete evidence; the foreign pre_wp_mail warning; and the
 * visible simulated-mode banner that does not rely on .notice markup. */
define('ABSPATH', __DIR__);
$GLOBALS['fpw_capture_test_home'] = 'http://mliu:8096/';
$GLOBALS['fpw_capture_docroot'] = null;
$GLOBALS['wp_filter'] = array();
function add_filter(...$args) {}
function add_action(...$args) {}
function get_option($key) { return $GLOBALS['fpw_capture_test_home']; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_get_upload_dir() { return array('basedir' => $GLOBALS['fpw_capture_uploads']); }
require __DIR__ . '/local-review-mail-capture.php';

$count = 0;
function check($ok, $label) { global $count; $count++; if (!$ok) { throw new RuntimeException($label); } }

/* --- Boundaries (lead RED repro generalized) --- */
$stage = sys_get_temp_dir() . '/fpw-capture-root-' . getmypid() . '-' . bin2hex(random_bytes(4));
$webroot = $stage . '/webroot';
mkdir($webroot . '/wp-content/uploads', 0777, true);
$GLOBALS['fpw_capture_uploads'] = $webroot . '/wp-content/uploads';
mkdir($stage . '/private-ok', 0700, true);
mkdir($stage . '/private-loose', 0755, true);
mkdir($webroot . '/private', 0755, true);
mkdir($webroot . '/wp-content/uploads/inbox', 0755, true);
symlink($webroot . '/private', $stage . '/symlink-into-webroot');
mkdir($stage . '/target-outside', 0700, true);
symlink($stage . '/target-outside', $stage . '/symlink-outside');
$GLOBALS['fpw_capture_docroot'] = $webroot;
$_SERVER['DOCUMENT_ROOT'] = $webroot;

$roots = fpw_local_capture_forbidden_roots();
check(in_array($webroot, $roots, true), 'the gathered forbidden roots include the real document root');
check(fpw_local_capture_dir_within($webroot . '/private', array($webroot)), 'a dir under the webroot is contained (the accepted-by-mistake case)');
check(!fpw_local_capture_dir_within($stage . '/private-ok', array($webroot)), 'a sibling dir outside the webroot is not contained');
check(fpw_local_capture_dir_within($webroot . '/wp-content/uploads/inbox', array($webroot)), 'upload dirs are inside the webroot too');
check(fpw_local_capture_dir_within($stage . '/symlink-into-webroot', array($webroot)) && !fpw_local_capture_dir_within($stage . '/symlink-outside', array($webroot)), 'symlinks resolve to their real target before deciding');
check(!fpw_local_capture_dir_within('/tmp/other-root/private', array('/var/www')), 'a completely different root never contains it');

function try_dir($path) { return fpw_local_capture_dir_for($path); }
check(null === fpw_local_capture_dir_for($webroot . '/private'), 'RED repro: a capture dir under the WP/document root is REJECTED');
check(null === fpw_local_capture_dir_for($webroot . '/wp-content/uploads/inbox'), 'a capture dir under uploads is rejected');
check(null === fpw_local_capture_dir_for($stage . '/symlink-into-webroot'), 'a symlink into the webroot is rejected');
check(null === fpw_local_capture_dir_for($stage . '/private-loose'), 'a world/group-readable dir (0755) is not private enough');
check(null === fpw_local_capture_dir_for($stage . '/no-such-dir'), 'a missing dir is rejected, not auto-trusted');
check($stage . '/private-ok' === fpw_local_capture_dir_for($stage . '/private-ok'), 'a private dir outside every root is accepted');
check($stage . '/symlink-outside' === fpw_local_capture_dir_for($stage . '/symlink-outside') || $stage . '/target-outside' === fpw_local_capture_dir_for($stage . '/symlink-outside'), 'a symlink to a private dir outside the roots resolves and is accepted');

/* --- Capture: exact evidence, completeness, honest failure --- */
$tmp = $stage . '/capture';
mkdir($tmp, 0700, true);
define('FPW_LOCAL_CAPTURE_DIR', $tmp);
check(fpw_local_capture_dir() === $tmp && fpw_local_capture_active(), 'the valid dir activates capture');

$source = $stage . '/cotizacion.pdf';
$pdf = "%PDF-1.4 fake quotation bytes for the audit\n%%EOF";
check(strlen($pdf) === file_put_contents($source, $pdf), 'pdf fixture written');
$source_same_name = $stage . '/other' . '/cotizacion.pdf';
mkdir(dirname($source_same_name), 0700, true);
file_put_contents($source_same_name, strrev($pdf));

$first = fpw_local_capture_mail(array('compras@prueba.invalid', 'contacto@prueba.invalid'), 'Cotización FP-2026-000091 · versión 1', '<p>oferta</p>', array('Content-Type: text/html; charset=UTF-8'), array($source, $source_same_name), 7);
check($first['state'] === 'captured-simulated', 'a complete send is captured and answered as simulation');
$record = json_decode((string) file_get_contents($first['file']), true);
check($record['to'] === array('compras@prueba.invalid', 'contacto@prueba.invalid'), 'array recipients keep their exact shape (not concatenated)');
check(json_decode((string) file_get_contents($first['file']), true)['attachments'][0]['sha256'] === hash('sha256', $pdf) && $record['attachments'][1]['sha256'] === hash('sha256', strrev($pdf)), 'both attachment payloads keep exact bytes + SHA-256');
check($record['attachments'][0]['captured_as'] !== $record['attachments'][1]['captured_as'] && is_file($record['attachments'][0]['captured_as']) && is_file($record['attachments'][1]['captured_as']), 'homonymous attachments are captured as separate files, none overwritten');
check(!((fileperms($record['attachments'][0]['captured_as']) & 0o077) || (fileperms($first['file']) & 0o077)), 'captured evidence files are private (no group/world access)');
$index_perms = fileperms($tmp . '/index.jsonl');
check(($index_perms & 0o077) === 0, 'the audit index itself is private');

$string_to = fpw_local_capture_mail('ventas@prueba.invalid', 'Nueva solicitud FP-2026-000092', 'aviso', array(), array(), null);
check($string_to['state'] === 'captured-simulated', 'the owner notice is captured too');
$string_record = json_decode((string) file_get_contents($string_to['file']), true);
check($string_record['to'] === 'ventas@prueba.invalid', 'a single string recipient is preserved exactly');
check(trim((string) substr_count((string) file_get_contents($tmp . '/index.jsonl'), "\n")) >= 2, 'the index lists every capture');

$status = fpw_local_capture_status();
check($status['count'] === 2 && $status['capture_ready'] === true, 'status reports the capture count');

/* Unreadable attachment: reject honestly, leave no partial record. */
mkdir($stage . '/a-directory.pdf', 0700);
$bad_attach = fpw_local_capture_mail('x@prueba.invalid', 's', 'm', array(), array($stage . '/a-directory.pdf'), null);
check($bad_attach['state'] === 'capture-failed', 'an unreadable attachment (file_get_contents false, not "") is a failure');
$records_after_bad = count(glob($tmp . '/mail-*.json'));
check($records_after_bad === 2, 'a failed capture leaves no partial record behind');

/* Incomplete evidence: an unusable index makes the whole capture fail. */
$keep_index = $tmp . '/index.jsonl';
rename($keep_index, $stage . '/index-kept');
mkdir($keep_index, 0700); // append will fail
$index_fail = fpw_local_capture_mail('y@prueba.invalid', 'sin índice', 'm', array(), array(), null);
check($index_fail['state'] === 'capture-failed', 'a send whose index line cannot be written is NOT accepted as simulated');
check(count(glob($tmp . '/mail-*.json')) === $records_after_bad, 'the unusable-index capture is fully rolled back');
rmdir($keep_index);
rename($stage . '/index-kept', $keep_index);

/* Capture dir disappears: rejection + request-scoped failure data + honest banner. */
$gone = $stage . '/capture-gone';
rename($tmp, $gone);
check(null === fpw_local_capture_dir() && !fpw_local_capture_active(), 'a disappeared capture dir deactivates capture');
check(fpw_local_capture_mail('x@prueba.invalid', 's', 'm', array(), array(), null)['state'] === 'capture-failed', 'an unusable dir REJECTS the send instead of faking or delivering it');
$no_global = sys_get_temp_dir() . '/fpw-local-capture-failures.json';
check(!is_file($no_global), 'no shared predictable global failure trace exists outside the private dir');
$status_no_dir = fpw_local_capture_status();
check(($status_no_dir['failures']['count'] ?? 0) >= 2, 'rejections stay observable through request-scoped data when no valid dir exists');

/* Banner: in-flow (never an overlay), simulated mode, last capture, foreign pre_wp_mail warning. */
$GLOBALS['wp_filter']['pre_wp_mail'] = null;
rename($gone, $tmp);
$clean_banner = fpw_local_capture_banner_html();
check(str_contains($clean_banner, 'NO se envía') && str_contains($clean_banner, 'role="status"') && !str_contains($clean_banner, 'class="notice'), 'the banner identifies simulation without .notice markup');
check(str_contains($clean_banner, 'position:static') && !str_contains($clean_banner, 'position:fixed') && !str_contains($clean_banner, 'z-index'), 'the banner flows with the page — never a fixed overlay over the mobile dock');

/* H0 banner follow-up (2026-10-04 handoff, pendiente 2): reproduce the mobile
 * overflow NATURALLY before any fix. The lead's Chrome measured width436 on
 * viewport412: the banner's own inline style declared width:100% PLUS horizontal
 * padding under the default content-box, so the box grew past the viewport and
 * the text was cut on the right. This applies the browser's own box model to the
 * banner's REAL inline style at viewport 412 — same arithmetic Chrome performed. */
function banner_outer_width_at(string $banner_html, float $viewport): ?float {
    if (!preg_match('/style="([^"]+)"/', $banner_html, $style_match)) { return null; }
    $styles = array();
    foreach (explode(';', $style_match[1]) as $declaration) {
        if (preg_match('/^([a-z-]+)\s*:\s*(.+)$/i', trim($declaration), $decl)) { $styles[strtolower($decl[1])] = strtolower(trim($decl[2])); }
    }
    if ('100%' !== ($styles['width'] ?? '')) { return null; }
    $box = $styles['box-sizing'] ?? 'content-box';
    $horizontal_padding = 0.0;
    if (isset($styles['padding'])) {
        $parts = preg_split('/\s+/', $styles['padding']);
        if (2 === count($parts)) { $horizontal_padding = 2.0 * (float) $parts[1]; }
        elseif (1 === count($parts)) { $horizontal_padding = 2.0 * (float) $parts[0]; }
    }
    return 'border-box' === $box ? $viewport : $viewport + $horizontal_padding;
}
check(banner_outer_width_at($clean_banner, 412.0) <= 412.0, 'the banner never exceeds the 412px mobile viewport (outer width computed from its own inline style: ' . banner_outer_width_at($clean_banner, 412.0) . 'px — the lead measured 436px)');
check(banner_outer_width_at($clean_banner, 1440.0) <= 1440.0, 'desktop stays within its viewport too');

check(str_contains($clean_banner, 'pre_wp_mail') === false, 'no foreign-filter warning without a foreign filter');
/* Rejections recorded INSIDE the private dir feed the banner. */
check(is_file($tmp . '/failures.json') && (json_decode((string) file_get_contents($tmp . '/failures.json'), true)['count'] ?? 0) >= 1, 'failures traced while the dir was valid stay in the private dir');
$GLOBALS['wp_filter']['pre_wp_mail'] = array('callbacks' => array(10 => array('fpw_local_capture_pre_wp_mail' => array('function' => 'fpw_local_capture_pre_wp_mail')), PHP_INT_MAX => array('local_review_safety_suppress' => array('function' => static function () { return true; }))));
$warn_banner = fpw_local_capture_banner_html();
check(str_contains($warn_banner, 'pre_wp_mail') && str_contains($warn_banner, '1 otro filtro'), 'the banner warns about a foreign pre_wp_mail that could override the honest verdict');

/* Host gate stays the outer wall. */
check(fpw_local_capture_host_allowed('localhost') && fpw_local_capture_host_allowed('127.0.0.1') && fpw_local_capture_host_allowed('mliu'), 'loopback and review workstation hosts are allowed');
check(!fpw_local_capture_host_allowed('freeplast.mliu.site') && !fpw_local_capture_host_allowed('example.com'), 'production and foreign hosts stay inert');

/* No wp_* functions are defined by this plugin (name-collision hygiene). */
$defined = get_defined_functions()['user'];
$ours = array_filter($defined, static fn($name) => str_starts_with($name, 'fpw_local_capture_') || str_contains($name, 'local_review_mail_capture'));
check(!array_filter($ours, static fn($name) => str_starts_with($name, 'wp_')), 'the plugin defines no wp_* helpers of its own');

/* Cleanup. */
foreach (array($stage . '/a-directory.pdf', $source, $source_same_name, $tmp . '/failures.json') as $path) { if (is_file($path)) { @unlink($path); } elseif (is_dir($path)) { @rmdir($path); } }
foreach (glob($tmp . '/attachments/*') ?: array() as $child) { @unlink($child); }
@unlink($tmp . '/index.jsonl');
foreach (glob($tmp . '/mail-*.json') ?: array() as $child) { @unlink($child); }
foreach (array($tmp, $stage . '/private-ok', $stage . '/private-loose', $stage . '/target-outside', $stage . '/other', dirname($source_same_name), $webroot . '/wp-content/uploads/inbox', $webroot . '/private', $webroot . '/wp-content/uploads', $webroot . '/wp-content', $webroot, $stage) as $dir) { @unlink($dir) ?: @rmdir($dir); }
@unlink($stage . '/symlink-into-webroot'); @unlink($stage . '/symlink-outside');
echo "local review capture: {$count} offline checks passed\n";
