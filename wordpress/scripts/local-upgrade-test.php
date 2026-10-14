<?php
/** Offline regressions for the local-only upgrade helper (wordpress/local/upgrade.php): containment guards, pinned catalog provenance, full receipt-transformation validation (rebuilt tokens cannot pass), plan/apply/rollback of the request minimums and the /contacto/ placeholder, compare-and-swap writes with exact row counts, transaction start/lock failures with zero writes, drift preservation and honest uncertain outcomes. No database, no Docker, no network. */
define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);
define('WP_HOME', 'http://mliu:8131');
define('FPW_LOCAL_UPGRADE_TEST', true);
$receipts = sys_get_temp_dir() . '/freeplast-local-upgrade-' . getmypid();
mkdir($receipts . '/receipts', 0700, true);
chmod($receipts . '/receipts', 0700);
define('FPW_LOCAL_RECEIPTS', $receipts . '/receipts');
define('FPW_LOCAL_CATALOG', realpath(__DIR__ . '/../data/products.json'));
class Halt extends RuntimeException {}
class WP_CLI { public static function halt($code) { throw new Halt('halt', (int) $code); } }
$GLOBALS['lu_env'] = 'local';
$GLOBALS['lu_capture'] = true;
$GLOBALS['lu_home'] = 'http://mliu:8131';
$GLOBALS['lu_engines'] = 'InnoDB';
$GLOBALS['lu_dup'] = null;
$GLOBALS['lu_cache_cleaned'] = array();
putenv('FREEPLAST_LOCAL_URL=http://mliu:8131');
function wp_get_environment_type() { return $GLOBALS['lu_env']; }
function fpw_local_capture_active() { return $GLOBALS['lu_capture']; }
function get_option($name, $default = false) { return 'home' === $name ? $GLOBALS['lu_home'] : $default; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function clean_post_cache($id) { $GLOBALS['lu_cache_cleaned'][] = (int) $id; }
$catalog = json_decode((string) file_get_contents(FPW_LOCAL_CATALOG), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['lu_meta'] = array();
$GLOBALS['lu_posts'] = array();
$GLOBALS['lu_by_slug'] = array();
$next_id = 500;
foreach (($catalog['products'] ?? $catalog) as $item) {
    $id = $next_id++;
    $GLOBALS['lu_posts'][$id] = (object) array('ID' => $id, 'post_type' => 'product', 'post_name' => (string) $item['slug'], 'post_status' => 'publish', 'post_title' => (string) $item['title']);
    $GLOBALS['lu_by_slug'][(string) $item['slug']] = $id;
    /* The seeded identity the helper requires. */
    $GLOBALS['lu_meta'][$id]['_fp_local_seed_complete'] = '1';
}
$GLOBALS['lu_pages'] = array('contacto' => (object) array('ID' => 91, 'post_type' => 'page', 'post_name' => 'contacto', 'post_status' => 'publish', 'post_title' => 'Contacto', 'post_content' => '<p>Demostración local. No enviar datos reales.</p>'));
function get_page_by_path($slug) { return $GLOBALS['lu_pages'][$slug] ?? null; }
function get_posts($args) { if (($args['post_type'] ?? '') !== 'product' || !isset($args['name'])) { return array(); } return isset($GLOBALS['lu_by_slug'][$args['name']]) ? array($GLOBALS['lu_by_slug'][$args['name']]) : array(); }
function get_post($id) { $id = (int) $id; return $GLOBALS['lu_posts'][$id] ?? ($id === (int) $GLOBALS['lu_pages']['contacto']->ID ? $GLOBALS['lu_pages']['contacto'] : null); }
function wc_get_product($id) {
    $post = get_post($id);
    if (!$post || 'product' !== $post->post_type) { return false; }
    foreach ($GLOBALS['lu_catalog_flat'] as $item) {
        if ((string) $item['slug'] === $post->post_name) { return new class( empty($item['options']) ? 'simple' : 'variable' ) { public function __construct(private string $type) {} public function get_type(): string { return $this->type; } }; }
    }
    return false;
}
$GLOBALS['lu_catalog_flat'] = $catalog['products'] ?? $catalog;
class LocalDB {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public array $commands = array();
    public bool $fail_commit = false;
    public bool $fail_start = false;
    public bool $fail_lock = false;
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $dpos = strpos($sql, '%d'); $spos = strpos($sql, '%s');
            if (false !== $dpos && (false === $spos || $dpos < $spos)) { $sql = substr($sql, 0, $dpos) . (int) $arg . substr($sql, $dpos + 2); continue; }
            $pos = strpos($sql, '%s'); $sql = substr($sql, 0, $pos) . "'" . $arg . "'" . substr($sql, $pos + 2);
        }
        return $sql;
    }
    public function query($sql) {
        $this->commands[] = $sql;
        if ($this->fail_start && 'START TRANSACTION' === $sql) { return false; }
        if ($this->fail_lock && str_contains($sql, 'FOR UPDATE')) { return false; }
        if ($this->fail_commit && 'COMMIT' === $sql) { return false; }
        if (preg_match("/^INSERT INTO wp_postmeta \( post_id, meta_key, meta_value \) VALUES \( (\d+), '([^']*)', '(.*)' \)$/s", $sql, $m)) {
            if (array_key_exists($m[2], $GLOBALS['lu_meta'][(int) $m[1]] ?? array())) { return false; }
            $GLOBALS['lu_meta'][(int) $m[1]][$m[2]] = $m[3]; return 1;
        }
        if (preg_match("/^UPDATE wp_postmeta SET meta_value = '(.*)' WHERE post_id = (\d+) AND meta_key = '([^']*)' AND meta_value = '(.*)'$/s", $sql, $m)) {
            if (($GLOBALS['lu_meta'][(int) $m[2]][$m[3]] ?? null) !== $m[4]) { return 0; }
            $GLOBALS['lu_meta'][(int) $m[2]][$m[3]] = $m[1]; return 1;
        }
        if (preg_match("/^DELETE FROM wp_postmeta WHERE post_id = (\d+) AND meta_key = '([^']*)' AND meta_value = '(.*)'$/s", $sql, $m)) {
            if (($GLOBALS['lu_meta'][(int) $m[1]][$m[2]] ?? null) !== $m[3]) { return 0; }
            unset($GLOBALS['lu_meta'][(int) $m[1]][$m[2]]); return 1;
        }
        if (preg_match("/^UPDATE wp_posts SET post_content = '(.*)' WHERE ID = (\d+) AND post_content = CAST\('(.*)' AS BINARY\)$/s", $sql, $m)) {
            $page = $GLOBALS['lu_pages']['contacto'] ?? null;
            if (!$page || (int) $page->ID !== (int) $m[2] || $page->post_content !== $m[3]) { return 0; }
            $page->post_content = $m[1]; return 1;
        }
        return 1;
    }
    public function get_var($sql) {
        if (preg_match("/^SELECT meta_value FROM wp_postmeta WHERE post_id = (\d+) AND meta_key = '([^']*)' LIMIT 1$/", $sql, $m)) {
            return $GLOBALS['lu_meta'][(int) $m[1]][$m[2]] ?? null;
        }
        if (str_starts_with($sql, 'SELECT ENGINE FROM information_schema.TABLES')) { return $GLOBALS['lu_engines']; }
        if (str_contains($sql, 'HAVING COUNT(*) > 1')) { return $GLOBALS['lu_dup']; }
        return null;
    }
}
$GLOBALS['wpdb'] = new LocalDB();
$assertions = 0;
function check($ok, $message) { global $assertions; $assertions++; if (!$ok) { throw new RuntimeException($message); } }
function lu_invoke(array $args): array {
    ob_start();
    try { include __DIR__ . '/../local/upgrade.php'; $code = 0; }
    catch (Halt $error) { $code = $error->getCode(); }
    catch (Throwable $error) { $code = 255; }
    return array($code, ob_get_clean());
}
function lu_page_content(): string { return (string) $GLOBALS['lu_pages']['contacto']->post_content; }
function lu_token(array $receipt): string { return hash('sha256', implode('|', array($receipt['mode'], $receipt['url'], $receipt['source'], json_encode($receipt['rows'])))); }
function lu_minima_count(array $receipt): int { return count(array_filter($receipt['rows'], static fn($row) => null !== $row['before_min'])) * 2; }
/** Craft a receipt file with a CORRECTLY REBUILT token over (possibly tampered) rows — only full transformation validation can refuse it. */
function lu_craft_receipt(string $file, array $receipt): void {
    $receipt['token'] = lu_token($receipt);
    $mask = umask(0077); $path = FPW_LOCAL_RECEIPTS . '/' . $file; @unlink($path);
    file_put_contents($path, json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    chmod($path, 0600); umask($mask);
}
try {
    check(hash_file('sha256', FPW_LOCAL_CATALOG) === '1b3ef16a027b3f68b4704efb6c9d9f2d5e164a93c19f938fdf85cb0ecb38d088', 'test reads the pinned repository catalog');
    check(lu_invoke(array('--version')) === array(0, "1.0.0\n"), 'version');
    check(lu_invoke(array('nope'))[0] === 2, 'usage error');
    $status = json_decode(lu_invoke(array('status'))[1], true);
    check(($status['minima_already_exact'] ?? -1) === 2 && ($status['minima_pending_or_drifted'] ?? -1) === 15 && ($status['contacto'] ?? '') === 'placeholder', 'fresh local site: two unknowns already exact, fifteen minima pending, contacto placeholder');
    /* Containment guards refuse before any write. */
    $GLOBALS['lu_env'] = 'staging';
    check(lu_invoke(array('status'))[0] === 1, 'non-local environment refused');
    $GLOBALS['lu_env'] = 'local';
    $GLOBALS['lu_capture'] = false;
    check(lu_invoke(array('status'))[0] === 1, 'capture containment refused');
    $GLOBALS['lu_capture'] = true;
    putenv('FREEPLAST_LOCAL_URL=http://mliu:9999');
    check(lu_invoke(array('status'))[0] === 1, 'canonical URL mismatch refused');
    putenv('FREEPLAST_LOCAL_URL=http://mliu:8131');
    /* Seeded identity is required: a product without the seed marker refuses everything. */
    $GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_local_seed_complete'] = '0';
    check(lu_invoke(array('status'))[0] === 1, 'non-seeded product identity refused');
    $GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_local_seed_complete'] = '1';
    /* Minima: plan (receipt only, no backup needed); apply needs the verified backup flag. */
    $receipt = 'minima.json';
    $result = lu_invoke(array('minima', 'plan', $receipt));
    check($result[0] === 0 && str_contains($result[1], 'changed: 17'), 'minima plan reviews seventeen changed rows (unknowns become explicit): ' . $result[1]);
    check((fileperms(FPW_LOCAL_RECEIPTS . '/' . $receipt) & 0777) === 0600, 'private receipt');
    $rows = json_decode((string) file_get_contents(FPW_LOCAL_RECEIPTS . '/' . $receipt), true);
    check(count($rows['rows']) === 17 && array_filter($rows['rows'], static fn($row) => null !== $row['before_min']) === array(), 'plan records the true absent-before state of all seventeen products');
    $by_slug = array_column($rows['rows'], null, 'slug');
    check($by_slug['caja-cosechera-3-4']['after_min'] === '70' && $by_slug['traversa-para-bins-tipo-upc']['after_min'] === '50' && $by_slug['bases-plasticas-para-pediluvios']['after_min'] === '', 'plan carries the owner-confirmed minima and keeps unknowns explicit');
    $token = $rows['token'];
    check(lu_invoke(array('minima', 'apply', $receipt, str_repeat('0', 64)))[0] === 1, 'wrong token refused');
    check(lu_invoke(array('minima', 'apply', $receipt, $token))[0] === 1, 'apply requires the verified backup flag');
    /* Full transformation validation: correctly REBUILT tokens over tampered rows never pass. */
    $rebuilt = $rows; $rebuilt['rows'][0]['after_min'] = '9999';
    lu_craft_receipt('rebuilt.json', $rebuilt);
    check(lu_invoke(array('minima', 'apply', 'rebuilt.json', $rebuilt['token']))[0] === 1 && !isset($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty']), 'rebuilt token over non-catalog after-value refused without writes');
    $foreign = $rows; $foreign['rows'][0]['id'] = 9999;
    lu_craft_receipt('foreign.json', $foreign);
    check(lu_invoke(array('minima', 'apply', 'foreign.json', $foreign['token']))[0] === 1, 'rebuilt token over a foreign product id refused');
    $duprows = $rows; $duprows['rows'][] = $duprows['rows'][0];
    lu_craft_receipt('dup.json', $duprows);
    check(lu_invoke(array('minima', 'apply', 'dup.json', $duprows['token']))[0] === 1, 'duplicate product rows refused');
    $short = $rows; array_pop($short['rows']);
    lu_craft_receipt('short.json', $short);
    check(lu_invoke(array('minima', 'apply', 'short.json', $short['token']))[0] === 1, 'missing product rows refused');
    $wrongurl = $rows; $wrongurl['url'] = 'http://localhost:8131';
    lu_craft_receipt('url.json', $wrongurl);
    check(lu_invoke(array('minima', 'apply', 'url.json', $wrongurl['token']))[0] === 1, 'receipt bound to another canonical URL refused');
    /* Native engines are required. */
    putenv('FREEPLAST_LOCAL_BACKUP_VERIFIED=1');
    $GLOBALS['lu_engines'] = 'MyISAM';
    check(lu_invoke(array('minima', 'apply', $receipt, $token))[0] === 1 && !in_array('START TRANSACTION', array_slice($GLOBALS['wpdb']->commands, -10), true), 'non-InnoDB native tables refuse before any transaction');
    $GLOBALS['lu_engines'] = 'InnoDB';
    /* Transaction start/lock failures: zero writes, honest message. */
    $GLOBALS['wpdb']->fail_start = true;
    $result = lu_invoke(array('minima', 'apply', $receipt, $token));
    check($result[0] === 1 && str_contains($result[1], 'Cannot begin') && !isset($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty']), 'transaction start failure writes nothing');
    $GLOBALS['wpdb']->fail_start = false;
    $GLOBALS['wpdb']->fail_lock = true;
    $result = lu_invoke(array('minima', 'apply', $receipt, $token));
    check($result[0] === 1 && str_contains($result[1], 'Cannot lock') && !isset($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty']) && in_array('ROLLBACK', $GLOBALS['wpdb']->commands, true), 'lock failure writes nothing and rolls back');
    $GLOBALS['wpdb']->fail_lock = false;
    /* Duplicate quantity meta rows refuse the upgrade. */
    $GLOBALS['lu_dup'] = '501';
    check(lu_invoke(array('minima', 'apply', $receipt, $token))[0] === 1 && str_contains(lu_invoke(array('minima', 'apply', $receipt, $token))[1], 'Duplicate quantity'), 'duplicate meta rows refuse');
    $GLOBALS['lu_dup'] = null;
    /* Drift after plan: a merchant-edited minimum is never overwritten. */
    $GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty'] = '999';
    $result = lu_invoke(array('minima', 'apply', $receipt, $token));
    check($result[0] === 1 && str_contains($result[1], 'drifted'), 'post-plan merchant drift refused: ' . $result[1]);
    check(in_array('ROLLBACK', $GLOBALS['wpdb']->commands, true) && array_filter($GLOBALS['lu_meta'], static fn($meta) => isset($meta['_fp_quote_step'])) === array(), 'the refused transaction rolled back with no partial writes');
    check($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty'] === '999', 'the merchant drift itself is preserved untouched');
    unset($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty']);
    /* Happy apply: seventeen products get both keys; unknowns get the explicit empty string. */
    $GLOBALS['lu_cache_cleaned'] = array();
    $result = lu_invoke(array('minima', 'apply', $receipt, $token));
    check($result[0] === 0 && str_contains($result[1], 'writes: 34'), 'apply writes thirty-four compare-and-swap meta rows: ' . $result[1]);
    check(($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['caja-cosechera-3-4']]['_fp_quote_min_qty'] ?? null) === '70' && ($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['traversa-para-bins-tipo-romano']]['_fp_quote_min_qty'] ?? null) === '50', 'confirmed minima applied');
    check(($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['ladrillo-plastico']]['_fp_quote_min_qty'] ?? 'x') === '' && ($GLOBALS['lu_meta'][$GLOBALS['lu_by_slug']['bases-plasticas-para-pediluvios']]['_fp_quote_min_qty'] ?? 'x') === '', 'unknown minimums stay explicit empty strings');
    check(array_intersect($GLOBALS['lu_cache_cleaned'], array_map('intval', array_column($rows['rows'], 'id'))) !== array(), 'native caches cleaned after commit');
    $status = json_decode(lu_invoke(array('status'))[1], true);
    check(($status['minima_already_exact'] ?? -1) === 17, 'status reports all seventeen exact after apply');
    check(lu_invoke(array('minima', 'apply', $receipt, $token))[1] === "status: applied; writes: 0; verify with status\n", 're-apply is a verified no-op');
    /* Uncertain COMMIT: reported, never claimed back with a rollback, never replayed. */
    $GLOBALS['wpdb']->fail_commit = true; $GLOBALS['wpdb']->commands = array();
    $result = lu_invoke(array('minima', 'apply', $receipt, $token));
    check($result[0] === 1 && str_contains($result[1], 'uncertain'), 'commit uncertainty is reported: ' . $result[1]);
    check(!in_array('ROLLBACK', $GLOBALS['wpdb']->commands, true) && in_array('COMMIT', $GLOBALS['wpdb']->commands, true), 'an uncertain commit is never claimed back with a rollback');
    $GLOBALS['wpdb']->fail_commit = false;
    /* Rollback restores the exact absent state and cleans caches. */
    $GLOBALS['lu_cache_cleaned'] = array();
    $result = lu_invoke(array('minima', 'rollback', $receipt, $token));
    check($result[0] === 0 && str_contains($result[1], 'writes: 34'), 'rollback removes the reviewed rows: ' . $result[1]);
    check(array_filter($GLOBALS['lu_meta'], static fn($meta) => isset($meta['_fp_quote_min_qty']) || isset($meta['_fp_quote_step'])) === array(), 'quantity metadata back to the pre-upgrade absent state');
    check(array_intersect($GLOBALS['lu_cache_cleaned'], array_map('intval', array_column($rows['rows'], 'id'))) !== array(), 'native caches cleaned after rollback');
    /* Receipt root privacy is enforced on READ too. */
    lu_invoke(array('minima', 'plan', 'again.json'));
    chmod(FPW_LOCAL_RECEIPTS, 0750);
    check(lu_invoke(array('minima', 'apply', 'again.json', json_decode((string) file_get_contents(FPW_LOCAL_RECEIPTS . '/again.json'), true)['token']))[0] === 1, 'a receipts root with group/other access refuses reads');
    chmod(FPW_LOCAL_RECEIPTS, 0700);
    unlink(FPW_LOCAL_RECEIPTS . '/again.json');
    /* Contacto: plan/apply/rollback with byte-exact CAS. */
    $result = lu_invoke(array('contacto', 'plan', 'contacto.json'));
    check($result[0] === 0, 'contacto plan');
    $crows = json_decode((string) file_get_contents(FPW_LOCAL_RECEIPTS . '/contacto.json'), true);
    check($crows['rows'][0]['before'] === '<p>Demostración local. No enviar datos reales.</p>' && str_contains($crows['rows'][0]['after'], 'P Picarquín sitio 52, San Fco de Mostazal, VI Región'), 'contacto plan replaces exactly the local placeholder with the approved address');
    check(lu_invoke(array('contacto', 'apply', 'contacto.json', 'wrong'))[0] === 1 && lu_page_content() === '<p>Demostración local. No enviar datos reales.</p>', 'wrong token leaves the page untouched');
    /* Contacto receipt validation: rebuilt tokens over foreign ids or arbitrary content never pass. */
    $ctampered = $crows; $ctampered['rows'][0]['id'] = 999;
    lu_craft_receipt('ct-foreign.json', $ctampered);
    check(lu_invoke(array('contacto', 'apply', 'ct-foreign.json', $ctampered['token']))[0] === 1, 'contacto receipt with a foreign page id refused');
    $cevil = $crows; $cevil['rows'][0]['after'] = '<p>Contenido arbitrario</p>';
    lu_craft_receipt('ct-evil.json', $cevil);
    check(lu_invoke(array('contacto', 'apply', 'ct-evil.json', $cevil['token']))[0] === 1, 'contacto receipt with arbitrary after-content refused');
    $cplaceholder = $crows; $cplaceholder['rows'][0]['before'] = '<p>Otro contenido</p>';
    lu_craft_receipt('ct-before.json', $cplaceholder);
    check(lu_invoke(array('contacto', 'apply', 'ct-before.json', $cplaceholder['token']))[0] === 1, 'contacto receipt with a non-placeholder before-state refused');
    $result = lu_invoke(array('contacto', 'apply', 'contacto.json', $crows['token']));
    check($result[0] === 0 && str_contains(lu_page_content(), 'P Picarquín sitio 52') && str_contains(lu_page_content(), 'correos se capturan y nunca se envían'), 'contacto applied: ' . $result[1]);
    check(lu_invoke(array('contacto', 'apply', 'contacto.json', $crows['token']))[1] === "status: already approved; no write\n", 'contacto re-apply is a no-op');
    $result = lu_invoke(array('contacto', 'rollback', 'contacto.json', $crows['token']));
    check($result[0] === 0 && lu_page_content() === '<p>Demostración local. No enviar datos reales.</p>', 'contacto rollback restores the exact placeholder bytes');
    /* Concurrent edit between review and write: CAS refuses, merchant content survives. */
    lu_invoke(array('contacto', 'plan', 'contacto2.json'));
    $crows2 = json_decode((string) file_get_contents(FPW_LOCAL_RECEIPTS . '/contacto2.json'), true);
    $GLOBALS['lu_pages']['contacto']->post_content .= '<p>Edición del comercio.</p>';
    $result = lu_invoke(array('contacto', 'apply', 'contacto2.json', $crows2['token']));
    check($result[0] === 1 && str_contains(lu_page_content(), 'Edición del comercio.'), 'concurrent merchant edit survives the refused compare-and-swap');
    $GLOBALS['lu_pages']['contacto']->post_content = '<p>Contenido desconocido.</p>';
    check(lu_invoke(array('contacto', 'plan', 'contacto3.json'))[0] === 1 && !file_exists(FPW_LOCAL_RECEIPTS . '/contacto3.json'), 'unknown page content refuses to plan');
    $cas = array_filter($GLOBALS['wpdb']->commands, static fn($sql) => str_starts_with($sql, 'UPDATE wp_posts SET post_content'));
    check($cas !== array() && !array_filter($cas, static fn($sql) => !str_contains($sql, 'CAST(') || !str_contains($sql, ' AS BINARY)')), 'page writes are single byte-exact compare-and-swap statements');
    /* Seed-completeness markers, synthetic requests and numbers are never touched by the helper. */
    check(array_filter($GLOBALS['lu_meta'], static fn($meta) => ($meta['_fp_local_seed_complete'] ?? null) === '1') !== array(), 'seed markers preserved');
    putenv('FREEPLAST_LOCAL_BACKUP_VERIFIED');
} finally {
    foreach (array('minima.json', 'rebuilt.json', 'foreign.json', 'dup.json', 'short.json', 'url.json', 'again.json', 'contacto.json', 'contacto2.json', 'ct-foreign.json', 'ct-evil.json', 'ct-before.json', 'tampered.json') as $file) { @unlink(FPW_LOCAL_RECEIPTS . '/' . $file); }
    @chmod(FPW_LOCAL_RECEIPTS, 0700);
    @rmdir(FPW_LOCAL_RECEIPTS); @rmdir($receipts);
}
/* Pinned provenance: an altered catalog source is refused in an isolated process. */
$temp = tempnam(sys_get_temp_dir(), 'lu-catalog-');
file_put_contents($temp, str_replace('"minimum_quantity": 70', '"minimum_quantity": 71', (string) file_get_contents(FPW_LOCAL_CATALOG)));
$probe = PHP_BINARY . ' -r ' . escapeshellarg('define("ABSPATH", ' . var_export(__DIR__ . '/', true) . '); define("WP_CLI", true); define("WP_HOME", "http://mliu:8131"); define("FPW_LOCAL_UPGRADE_TEST", true); define("FPW_LOCAL_RECEIPTS", ' . var_export($receipts, true) . '); define("FPW_LOCAL_CATALOG", ' . var_export($temp, true) . '); $args = array("status"); try { include ' . var_export(__DIR__ . '/../local/upgrade.php', true) . '; exit(0); } catch (Throwable $e) { exit(1); }');
exec($probe, $output, $code);
unlink($temp);
check(1 === $code, 'altered catalog source refused (pinned hash)');
echo "local upgrade: $assertions offline checks passed; no Docker, no database, no network\n";
