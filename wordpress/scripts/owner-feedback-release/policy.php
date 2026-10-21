<?php
/** Three minimum metadata edits only; no runtime hooks, Woo saves or imports. */
function fp_feedback_target(): void {
    if (!defined('WP_CLI') || !WP_CLI || get_option('home') !== 'https://freeplast.mliu.site'
        || get_option('siteurl') !== 'https://freeplast.mliu.site' || wp_get_environment_type() !== 'staging'
        || (string)get_option('blog_public') !== '0') { throw new RuntimeException('Non-indexed Freeplast staging CLI only.'); }
}
function fp_feedback_slugs(): array {
    return ['traversa-para-bins-tipo-g1', 'traversa-para-bins-tipo-upc', 'traversa-para-bins-tipo-romano'];
}
function fp_feedback_read(string $slug, bool $lock = false): array {
    global $wpdb;
    $suffix = $lock ? ' FOR UPDATE' : '';
    $posts = $wpdb->get_results($wpdb->prepare("SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type='product' AND post_name=%s" . $suffix, $slug), ARRAY_A);
    if ($wpdb->last_error || count($posts) !== 1 || $posts[0]['post_status'] !== 'publish') { throw new RuntimeException('Missing/ambiguous published traversa: ' . $slug); }
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_fp_quote_min_qty'" . $suffix, (int)$posts[0]['ID']), ARRAY_A);
    if ($wpdb->last_error || count($rows) !== 1) { throw new RuntimeException('Missing/duplicate minimum row: ' . $slug); }
    return ['id'=>$posts[0]['ID'], 'slug'=>$slug, 'meta'=>$rows[0]];
}
function fp_feedback_capture(string $release): array {
    fp_feedback_target();
    $products = [];
    foreach (fp_feedback_slugs() as $slug) {
        $p = fp_feedback_read($slug);
        if ($p['meta']['meta_value'] !== '50') { throw new RuntimeException('Minimum differs from reviewed 50-unit baseline; capture refused: ' . $slug); }
        $after = $p['meta']; $after['meta_value'] = '20';
        $products[] = ['id'=>$p['id'], 'slug'=>$slug, 'before'=>$p['meta'], 'after'=>$after];
    }
    return ['schema'=>'freeplast-owner-feedback-v1', 'release'=>$release, 'home'=>get_option('home'),
        'policy_sha256'=>hash_file('sha256',__FILE__), 'products'=>$products, 'original_digest'=>fp_feedback_digest(null)];
}
function fp_feedback_baseline(array $b, ?string $release = null): array {
    fp_feedback_target();
    if (($b['schema'] ?? '') !== 'freeplast-owner-feedback-v1' || ($b['home'] ?? '') !== get_option('home')
        || !preg_match('/^[A-Za-z0-9_-]+$/D', $b['release'] ?? '') || ($release !== null && $b['release'] !== $release)
        || ($b['policy_sha256'] ?? '') !== hash_file('sha256',__FILE__)
        || !preg_match('/^[a-f0-9]{64}$/D', $b['original_digest'] ?? '') || count($b['products'] ?? []) !== 3) { throw new RuntimeException('Invalid/foreign immutable baseline.'); }
    $ids = []; $meta_ids = [];
    foreach ($b['products'] as $i=>$p) {
        if (($p['slug'] ?? '') !== fp_feedback_slugs()[$i] || !preg_match('/^[1-9][0-9]*$/D', $p['id'] ?? '')
            || !is_array($p['before'] ?? null) || !is_array($p['after'] ?? null)) { throw new RuntimeException('Invalid reviewed product identity.'); }
        $before=$p['before']; $after=$p['after'];
        $columns=array_keys($before); sort($columns);
        if ($columns !== ['meta_id','meta_key','meta_value','post_id'] || !preg_match('/^[1-9][0-9]*$/D', $before['meta_id'])
            || $before['post_id'] !== $p['id'] || $before['meta_key'] !== '_fp_quote_min_qty' || $before['meta_value'] !== '50') { throw new RuntimeException('Unapproved metadata delta.'); }
        $expected=$before; $expected['meta_value']='20';
        if ($after !== $expected || isset($ids[$p['id']]) || isset($meta_ids[$before['meta_id']])) { throw new RuntimeException('Changed/duplicate metadata identity.'); }
        $ids[$p['id']]=true; $meta_ids[$before['meta_id']]=true;
    }
    return $b;
}
/** Partial states never qualify for projection or replay. */
function fp_feedback_state(array $b, bool $lock = false): ?string {
    $before=true; $after=true;
    foreach ($b['products'] as $p) {
        $live=fp_feedback_read($p['slug'], $lock);
        if ($live['id'] !== $p['id']) { return null; }
        $before=$before && $live['meta'] === $p['before'];
        $after=$after && $live['meta'] === $p['after'];
    }
    return $before ? 'before' : ($after ? 'after' : null);
}
function fp_feedback_maintenance(string $release): void {
    if (@file_get_contents(ABSPATH.'.wp-release-lock/owner') !== $release || !is_file(ABSPATH.'.maintenance')
        || !is_file(ABSPATH.'wp-content/mu-plugins/wp-release-guard.php')) { throw new RuntimeException('Release-owned maintenance required.'); }
}
function fp_feedback_apply(array $b): void {
    global $wpdb;
    $engines=$wpdb->get_col("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    if ($wpdb->last_error || !$engines || array_filter($engines,static fn($v)=>strtolower((string)$v) !== 'innodb')) { throw new RuntimeException('InnoDB required.'); }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot begin transaction.'); }
    $commit_attempted=false;
    try {
        $state=fp_feedback_state($b,true);
        if ($state === null || fp_feedback_digest($b) !== $b['original_digest']) { throw new RuntimeException('Partial state or protected drift; refuse replay.'); }
        if ($state === 'before') {
            foreach ($b['products'] as $p) {
                $n=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value='20' WHERE meta_id=%d AND post_id=%d AND meta_key='_fp_quote_min_qty' AND BINARY meta_value=BINARY '50'", (int)$p['before']['meta_id'], (int)$p['id']));
                if ($n !== 1) { throw new RuntimeException('Exact metadata compare-and-swap refused.'); }
            }
        }
        if (fp_feedback_state($b) !== 'after' || fp_feedback_digest($b) !== $b['original_digest']) { throw new RuntimeException('Unexpected post-apply delta.'); }
        $commit_attempted=true;
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('COMMIT uncertain; retain maintenance and inspect before retry.'); }
    } catch (Throwable $error) {
        if (!$commit_attempted) { $wpdb->query('ROLLBACK'); }
        throw $error;
    }
    foreach ($b['products'] as $p) { clean_post_cache((int)$p['id']); if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients((int)$p['id']); } }
}
require_once __DIR__.'/digest.php';
