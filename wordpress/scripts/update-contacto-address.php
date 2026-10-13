<?php
/** One-shot guarded /contacto/ address correction on staging: plan/apply/rollback over the exact historically seeded bytes; the write is a single atomic compare-and-swap UPDATE guarded by the expected old bytes (autocommit, no lock window), so concurrent merchant/editor writes can never be silently overwritten. No runtime hooks; the retired legacy plugin is source history, not authority. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required'); }
$args = $args ?? array();
$help = 'wp eval-file wordpress/scripts/update-contacto-address.php plan <new-private-receipt.json> | apply <receipt.json> <reviewed-token> | rollback <receipt.json> <reviewed-token> | help | version';
if (count($args) === 1 && in_array($args[0], array('version','--version','-v','-V'), true)) { echo "1.0.0\n"; return; }
if (!$args || (count($args) === 1 && in_array($args[0], array('help','--help'), true))) { echo 'usage: ' . json_encode($help) . "\ndefault: no writes\n"; return; }
if (!(($args[0] === 'plan' && count($args) === 2) || (in_array($args[0], array('apply','rollback'), true) && count($args) === 3))) { echo 'error: incorrect arguments' . "\nhelp: " . json_encode($help) . "\n"; WP_CLI::halt(2); }
require_once __DIR__ . '/lib/contacto-address.php';

try {
    if (untrailingslashit(get_option('home')) !== 'https://freeplast.mliu.site' || untrailingslashit(get_option('siteurl')) !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging') { throw new RuntimeException('Isolated Freeplast staging required'); }
    if ($args[0] === 'plan') {
        $page = get_page_by_path('contacto');
        if (!$page || (int) $page->ID < 1) { throw new RuntimeException('Published /contacto/ page not found'); }
        $content = (string) $page->post_content;
        $state = fpw_contacto_state($content);
        if ($state === 'approved') { echo "status: already approved; nothing to plan\n"; return; }
        $after = fpw_contacto_transform($content);
        if ($after === null) { throw new RuntimeException('Unrecognized /contacto/ content (' . $state . '); review manually, no plan written'); }
        $receipt = array('page_id'=>(int) $page->ID, 'slug'=>'contacto', 'before'=>$content, 'after'=>$after,
            'before_sha256'=>hash('sha256', $content), 'after_sha256'=>hash('sha256', $after),
            'token'=>hash('sha256', (int) $page->ID . '|contacto|' . hash('sha256', $content) . '|' . hash('sha256', $after)));
        $parent = realpath(dirname($args[1])); $webroot = realpath(ABSPATH);
        if (!$parent || !$webroot || str_starts_with($parent . '/', $webroot . '/') || (fileperms($parent) & 0077)) { throw new RuntimeException('Receipt requires a private directory outside webroot'); }
        $mask = umask(0077); $file = fopen($args[1], 'x'); umask($mask);
        if (!$file) { throw new RuntimeException('Choose a new receipt path'); }
        $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($file, $bytes); $flushed = fflush($file); fclose($file);
        if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; do not apply'); }
        echo "page: {$receipt['page_id']}\nbefore: {$receipt['before_sha256']}\nafter: {$receipt['after_sha256']}\ntoken: {$receipt['token']}\nreceipt: " . json_encode($args[1]) . "\n";
        return;
    }
    if (!is_file($args[1]) || is_link($args[1]) || (fileperms($args[1]) & 0077)) { throw new RuntimeException('Receipt must be a private regular file'); }
    $receipt = json_decode((string) file_get_contents($args[1]), true, 512, JSON_THROW_ON_ERROR);
    /* Full receipt schema: exact identity, self-consistent hashes, token binding, and an after that is ONLY the approved transformation of before. */
    $page_id = (int) ($receipt['page_id'] ?? 0);
    $before = (string) ($receipt['before'] ?? '');
    $after = (string) ($receipt['after'] ?? '');
    if ($page_id < 1 || ($receipt['slug'] ?? '') !== 'contacto' || !is_string($receipt['before'] ?? null) || !is_string($receipt['after'] ?? null)
        || hash('sha256', $before) !== (string) ($receipt['before_sha256'] ?? '') || hash('sha256', $after) !== (string) ($receipt['after_sha256'] ?? '')
        || $after !== fpw_contacto_transform($before)) { throw new RuntimeException('Altered or malformed receipt; review a new plan, no writes'); }
    $expected = hash('sha256', $page_id . '|contacto|' . hash('sha256', $before) . '|' . hash('sha256', $after));
    if (!hash_equals($expected, (string) $args[2]) || !hash_equals($expected, (string) ($receipt['token'] ?? ''))) { throw new RuntimeException('Stale/altered receipt; review a new plan, no writes'); }
    if (!is_file(ABSPATH . '.maintenance') || getenv('FREEPLAST_CONTACTO_BACKUP_VERIFIED') !== '1') { throw new RuntimeException('Maintenance and verified paired backup required; flags acknowledge evidence, not proof'); }
    /* The receipt page must still be the CURRENT published /contacto/ identity. */
    $page = get_page_by_path('contacto');
    if (!$page || (int) $page->ID !== $page_id) { throw new RuntimeException('Receipt page is no longer the current /contacto/ identity; plan again, no writes'); }
    clean_post_cache($page_id);
    $post = get_post($page_id);
    if (!$post || $post->post_type !== 'page' || $post->post_name !== 'contacto') { throw new RuntimeException('Page identity drift; no writes'); }
    $want = $args[0] === 'apply' ? $after : $before;
    $expected_old = $args[0] === 'apply' ? $before : $after;
    $current = (string) $post->post_content;
    if (hash_equals(hash('sha256', $current), hash('sha256', $want))) { echo 'status: already ' . ($args[0] === 'apply' ? 'approved' : 'original') . "; no write\n"; return; }
    /* Single atomic compare-and-swap under autocommit: the row is written only if its bytes are still exactly the reviewed ones. CAST AS BINARY keeps the guard byte-exact under any ci collation; a literal prepared write preserves merchant backslashes and quotes (wp_update_post would strip them). */
    global $wpdb;
    $result = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d AND post_content = CAST(%s AS BINARY)', $want, $page_id, $expected_old));
    if ($result === false) { throw new RuntimeException('Update outcome uncertain; inspect the page and the paired backup, never replay automatically'); }
    clean_post_cache($page_id);
    $saved = get_post($page_id);
    $saved_sha = $saved ? hash('sha256', (string) $saved->post_content) : '';
    if ($result === 1) {
        if ($saved_sha !== hash('sha256', $want)) { throw new RuntimeException('Verification failed after write; inspect against the paired backup'); }
        echo 'status: ' . ($args[0] === 'apply' ? 'applied' : 'rolled back') . "; retain maintenance until independent verification\n";
        return;
    }
    if ($saved_sha === hash('sha256', $want)) { echo 'status: already ' . ($args[0] === 'apply' ? 'approved' : 'original') . "; no write\n"; return; }
    throw new RuntimeException('Content drifted since review (compare-and-swap found other bytes); plan again, no writes');
} catch (Throwable $error) {
    echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: retain maintenance; inspect the page and the paired backup before any retry\n";
    WP_CLI::halt(1);
}
