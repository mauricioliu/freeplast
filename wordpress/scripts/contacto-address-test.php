<?php
/** Offline guarded /contacto/ migration boundaries: exact seeded bytes replaced, arbitrary merchant content (incl. backslashes) preserved, atomic compare-and-swap write, drift/identity/schema tampering refused; no database or transport. */
require __DIR__ . '/catalog-pdf-migration-test.php';
require_once __DIR__ . '/lib/contacto-address.php';
mkdir(ABSPATH, 0700);
function get_page_by_path($slug) { return $GLOBALS['contacto_pages'][$slug] ?? null; }
function get_post($id) { return isset($GLOBALS['contacto_posts'][(int)$id]) ? clone $GLOBALS['contacto_posts'][(int)$id] : null; }
/** The atomic write must never route through wp_update_post: it would strip merchant backslashes. */
function wp_update_post($arr, $wp_error = false) { throw new RuntimeException('wp_update_post must not be used: ' . json_encode(array_keys($arr))); }
/** Mirrors the real protocol: prepare captures the literal arguments, UPDATE is a byte-exact compare-and-swap whose miss is 0 and transport failure is false. */
class ContactoDB extends DB {
    public array $last_args = array();
    public bool $fail_update = false;
    function prepare($sql, ...$args) { $this->last_args = $args; return $sql; }
    function query($sql) {
        $this->commands[] = $sql;
        if (str_starts_with($sql, 'UPDATE')) {
            list($want, $id, $expected) = $this->last_args;
            $post = $GLOBALS['contacto_posts'][(int) $id] ?? null;
            if (!$post || $post->post_content !== $expected) { return 0; }
            if ($this->fail_update) { return false; }
            $post->post_content = $want; return 1;
        }
        return parent::query($sql);
    }
}
function contacto_invoke($args) { ob_start(); try { include __DIR__ . '/update-contacto-address.php'; $code = 0; } catch (Halt $e) { $code = $e->getCode(); } catch (Throwable $e) { $code = 255; } return array($code, ob_get_clean()); }
function contacto_set(string $content): void { $GLOBALS['contacto_posts'][215]->post_content = $content; }
function contacto_receipt(array $overrides = array()): array {
    global $seeded;
    $after = fpw_contacto_transform($seeded);
    $receipt = array('page_id'=>215, 'slug'=>'contacto', 'before'=>$seeded, 'after'=>$after,
        'before_sha256'=>hash('sha256', $seeded), 'after_sha256'=>hash('sha256', $after),
        'token'=>hash('sha256', '215|contacto|' . hash('sha256', $seeded) . '|' . hash('sha256', $after)));
    return array_replace_recursive($receipt, $overrides);
}
$heading = fn($text, $level) => sprintf("<!-- wp:heading {\"level\":%d} -->\n<h%d class=\"wp-block-heading\">%s</h%d>\n<!-- /wp:heading -->\n", $level, $level, $text, $level);
$paragraph = fn($html) => sprintf("<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->\n", $html);
/* Merchant bytes with backslashes and quotes: the write boundary must return them untouched. */
$merchant_tail = $heading('Llámanos', 2) . $paragraph('<a href="tel:+56968444265">+ 56 9 6844 4265</a>') . $paragraph("Ruta interna C:\\Exportaciones\\v3 \"final\" y barra \\ conservadas.") . $heading('Horario', 2);
$seeded = $heading('Contactanos', 1) . $paragraph('Estamos disponibles, para recibir tu solicitud.') . $heading('Visítanos', 2) . FPW_CONTACTO_OLD_ADDRESS . FPW_CONTACTO_OLD_MAP . $merchant_tail;
$GLOBALS['contacto_writes'] = 0;
$GLOBALS['contacto_posts'] = array(215 => (object) array('ID'=>215, 'post_type'=>'page', 'post_name'=>'contacto', 'post_status'=>'publish', 'post_title'=>'Contacto', 'post_content'=>$seeded));
$GLOBALS['contacto_pages'] = array('contacto' => $GLOBALS['contacto_posts'][215]);
$wpdb = new ContactoDB();
$dir = sys_get_temp_dir() . '/freeplast-contacto-receipt-' . getmypid(); mkdir($dir, 0700); $receipt = $dir . '/review.json';
putenv('FREEPLAST_CONTACTO_BACKUP_VERIFIED=1');
function contacto_write_receipt(array $data, string $path): void {
    $mask = umask(0077); $file = fopen($path, 'x'); umask($mask);
    fwrite($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"); fclose($file);
}
try {
    check(contacto_invoke(array('--version')) === array(0, "1.0.0\n"), 'version');
    check(contacto_invoke(array('oops'))[0] === 2, 'usage error');
    $home = 'https://freeplast.cl';
    check(contacto_invoke(array('plan', $receipt))[0] === 1 && !file_exists($receipt), 'production refused');
    $home = 'https://freeplast.mliu.site';
    check(contacto_invoke(array('plan', $receipt))[0] === 0 && contacto_invoke(array('plan', $receipt))[0] === 1, 'plan reads staging, refuses a reused path');
    check((fileperms($receipt) & 0777) === 0600, 'private receipt');
    $r = json_decode((string) file_get_contents($receipt), true);
    check($r === contacto_receipt(), 'receipt carries only the reviewed transformation');
    check(str_contains($r['after'], 'P Picarquín sitio 52, San Fco de Mostazal, VI Región') && !str_contains($r['after'], 'Camino El Arrayán') && !str_contains($r['after'], 'maps.app.goo.gl'), 'after carries approved address only');
    check(str_contains($r['after'], 'C:\\Exportaciones\\v3 "final" y barra \\ conservadas.') && substr_count($r['after'], 'wp:heading') === substr_count($r['before'], 'wp:heading'), 'arbitrary merchant content, slashes included, preserved block by block');
    check(contacto_invoke(array('noop'))[0] === 2 && $GLOBALS['contacto_posts'][215]->post_content === $seeded, 'plan writes only the receipt');
    check(contacto_invoke(array('apply', $receipt, str_repeat('0', 64)))[0] === 1, 'wrong token no writes');
    putenv('FREEPLAST_CONTACTO_BACKUP_VERIFIED=');
    check(contacto_invoke(array('apply', $receipt, $r['token']))[0] === 1, 'backup flag required');
    putenv('FREEPLAST_CONTACTO_BACKUP_VERIFIED=1');
    check(contacto_invoke(array('apply', $receipt, $r['token']))[0] === 1, 'maintenance required');
    touch(ABSPATH . '.maintenance');
    /* Schema and identity tampering: every variant refuses before any write. */
    $tampered = $dir . '/tampered.json';
    foreach (array(
        'hash mismatch' => array('before_sha256'=>str_repeat('0', 64)),
        'content/hash drift' => array('after'=>$seeded . $paragraph('inyectado'), 'after_sha256'=>hash('sha256', $seeded . $paragraph('inyectado'))),
        'non-approved transformation' => array('after'=>str_replace('Camino El Arrayán 52', 'Otra', $seeded), 'after_sha256'=>hash('sha256', str_replace('Camino El Arrayán 52', 'Otra', $seeded))),
        'wrong slug' => array('slug'=>'contacto-2'),
        'foreign page id with rebuilt token' => array('page_id'=>999, 'token'=>hash('sha256', '999|contacto|' . hash('sha256', $seeded) . '|' . hash('sha256', fpw_contacto_transform($seeded)))),
        'missing fields' => array('after'=>null),
    ) as $label => $overrides) {
        @unlink($tampered); contacto_write_receipt(contacto_receipt($overrides), $tampered);
        $token = ($overrides['token'] ?? null) ?? $r['token'];
        check(contacto_invoke(array('apply', $tampered, $token))[0] === 1 && $GLOBALS['contacto_posts'][215]->post_content === $seeded, 'receipt refused: ' . $label);
    }
    @unlink($tampered);
    $GLOBALS['contacto_posts'][215]->post_name = 'contacto-2';
    check(contacto_invoke(array('apply', $receipt, $r['token']))[0] === 1 && $GLOBALS['contacto_posts'][215]->post_content === $seeded, 'page identity drift refuses writes');
    $GLOBALS['contacto_posts'][215]->post_name = 'contacto';
    $saved = $GLOBALS['contacto_pages']; $GLOBALS['contacto_pages'] = array();
    check(contacto_invoke(array('apply', $receipt, $r['token']))[0] === 1 && $GLOBALS['contacto_posts'][215]->post_content === $seeded, 'deleted page refuses writes');
    $GLOBALS['contacto_pages'] = $saved;
    /* Concurrency at the write boundary: a concurrent edit between review and CAS must lose nothing. */
    contacto_set($seeded . $paragraph('Edición concurrente del comercio.'));
    check(contacto_invoke(array('apply', $receipt, $r['token']))[0] === 1 && $GLOBALS['contacto_posts'][215]->post_content === $seeded . $paragraph('Edición concurrente del comercio.'), 'concurrent edit survives: compare-and-swap refuses, never overwrites');
    contacto_set($seeded);
    $result = contacto_invoke(array('apply', $receipt, $r['token']));
    check($result[0] === 0 && str_contains($result[1], 'status: applied') && $GLOBALS['contacto_posts'][215]->post_content === $r['after'], 'apply writes exactly the reviewed bytes: ' . $result[1]);
    check(str_contains($GLOBALS['contacto_posts'][215]->post_content, 'C:\\Exportaciones\\v3 "final" y barra \\ conservadas.') && $GLOBALS['contacto_posts'][215]->post_title === 'Contacto' && $GLOBALS['contacto_posts'][215]->post_status === 'publish' && $GLOBALS['contacto_posts'][215]->post_name === 'contacto', 'slashes and identity intact after the literal write');
    $again = contacto_invoke(array('apply', $receipt, $r['token']));
    check($again[0] === 0 && $again[1] === "status: already approved; no write\n", 're-apply is a no-op, never a second write');
    $rolled = contacto_invoke(array('rollback', $receipt, $r['token']));
    check($rolled[0] === 0 && str_contains($rolled[1], 'status: rolled back') && $GLOBALS['contacto_posts'][215]->post_content === $seeded && str_contains($GLOBALS['contacto_posts'][215]->post_content, 'C:\\Exportaciones'), 'rollback restores the exact seeded bytes, slashes included');
    check(contacto_invoke(array('rollback', $receipt, $r['token']))[1] === "status: already original; no write\n", 're-rollback is a no-op');
    contacto_set($r['after']);
    $wpdb->fail_update = true; $wpdb->commands = array();
    $uncertain = contacto_invoke(array('rollback', $receipt, $r['token']));
    check($uncertain[0] === 1 && str_contains($uncertain[1], 'never replay') && $GLOBALS['contacto_posts'][215]->post_content === $r['after'], 'uncertain update outcome reports, never claims or replays');
    $wpdb->fail_update = false;
    contacto_invoke(array('rollback', $receipt, $r['token']));
    unlink($receipt);
    contacto_set(FPW_CONTACTO_NEW_ADDRESS . $merchant_tail);
    $fresh = contacto_invoke(array('plan', $receipt));
    check($fresh[0] === 0 && $fresh[1] === "status: already approved; nothing to plan\n" && !file_exists($receipt), 'approved content plans nothing');
    contacto_set(FPW_CONTACTO_OLD_ADDRESS . $merchant_tail);
    check(contacto_invoke(array('plan', $receipt))[0] === 1 && !file_exists($receipt), 'partial seeded state refuses, no invented merge');
    contacto_set($paragraph('Contenido desconocido del comercio.'));
    check(contacto_invoke(array('plan', $receipt))[0] === 1 && !file_exists($receipt), 'unknown content refuses, manual review');
    contacto_set($seeded);
    contacto_invoke(array('plan', $receipt));
    symlink($receipt, $dir . '/link.json');
    check(contacto_invoke(array('apply', $dir . '/link.json', $r['token']))[0] === 1 && $GLOBALS['contacto_posts'][215]->post_content === $seeded, 'symlinked receipt refused');
    unlink($dir . '/link.json'); unlink($receipt);
    /* The write boundary must stay one atomic guarded statement: no transaction window, no advisory locks. */
    $updates = array_filter($wpdb->commands, static fn($sql) => str_starts_with($sql, 'UPDATE'));
    check($updates !== array() && !array_filter($wpdb->commands, static fn($sql) => str_contains($sql, 'GET_LOCK') || str_contains($sql, 'FOR UPDATE') || str_contains($sql, 'TRANSACTION')), 'single compare-and-swap statement, no lock window');
    check(all_str_contains(implode("\n", $updates), array('UPDATE wp_posts SET post_content = %s WHERE ID = %d AND post_content = CAST(%s AS BINARY)')), 'guarded literal SQL shape, byte-exact under any collation');
} finally {
    @unlink($dir . '/link.json'); @unlink($receipt); @unlink($dir . '/tampered.json'); @rmdir($dir); @unlink(ABSPATH . '.maintenance'); @rmdir(ABSPATH); putenv('FREEPLAST_CONTACTO_BACKUP_VERIFIED');
}
function all_str_contains(string $haystack, array $needles): bool { foreach ($needles as $needle) { if (!str_contains($haystack, $needle)) { return false; } } return true; }
echo "contacto address: $checks cumulative offline checks passed; native transaction trial withheld\n";
