<?php
/** Lead-run diagnostic only. No HTTP, no historical records, no role edits.
 * wp --path=wordpress/.build/wp eval-file wordpress/scripts/ventas-guard-native-probe.php
 */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI only'); }
$expected = realpath(__DIR__ . '/../.build/wp');
if (!$expected || realpath(ABSPATH) !== $expected || wp_get_environment_type() !== 'local' || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || !in_array(parse_url(home_url(), PHP_URL_HOST), array('127.0.0.1', 'localhost', 'mliu'), true)) {
    throw new RuntimeException('Exact disposable local worktree fixture required');
}
if (!empty($args)) { throw new RuntimeException('No arguments accepted'); }
add_filter('pre_wp_mail', '__return_true', PHP_INT_MIN);
add_filter('pre_http_request', static fn()=>new WP_Error('blocked', 'Diagnostic fixture has no network'), PHP_INT_MIN);
class FPW_Probe_Denial extends RuntimeException {}
add_filter('wp_die_handler', static fn()=>static function($message, $title='', $args=array()) {
    throw new FPW_Probe_Denial(str_contains((string)$message, 'solo consulta') ? 'sales-read-only' : 'other-denial', (int)($args['response'] ?? 500));
});
add_filter('wp_die_ajax_handler', static fn()=>static function($message, $title='', $args=array()) {
    throw new FPW_Probe_Denial(str_contains((string)$message, 'solo consulta') ? 'sales-read-only' : 'other-denial', (int)($args['response'] ?? 500));
});
$run = bin2hex(random_bytes(8));
$user = 0;
$post = 0;
$originalUser = get_current_user_id();
$failures = array();
function fpw_probe_check($name, $ok) {
    global $failures;
    echo $name . ': ' . ($ok ? 'pass' : 'FAIL') . "\n";
    if (!$ok) { $failures[]=$name; }
}
try {
    $user = wp_insert_user(array('user_login'=>'fp-probe-' . $run, 'user_email'=>'fp-probe-' . $run . '@example.invalid', 'user_pass'=>wp_generate_password(40), 'role'=>'ventas_freeplast'));
    if (is_wp_error($user)) { throw new RuntimeException('Cannot create owned synthetic actor'); }
    $post = wp_insert_post(array('post_type'=>'shop_order', 'post_status'=>'wc-pending', 'post_title'=>'NO ATENDER probe ' . $run, 'meta_input'=>array('_fp_probe_run'=>$run)), true);
    if (is_wp_error($post)) { throw new RuntimeException('Cannot create owned synthetic record'); }
    wp_set_current_user($user);
    $actor = wp_get_current_user();
    $report = array('raw_edit_shop_orders'=>!empty($actor->allcaps['edit_shop_orders']), 'raw_manage_woocommerce'=>!empty($actor->allcaps['manage_woocommerce']), 'effective_edit_shop_orders'=>current_user_can('edit_shop_orders'), 'effective_manage_woocommerce'=>current_user_can('manage_woocommerce'), 'limited'=>fpw_is_order_limited_staff());
    echo 'capabilities: ' . json_encode($report) . "\n";
    echo 'guard_priorities: ' . json_encode(array('front'=>has_action('admin_init','fpw_deny_sales_record_mutation'), 'note_meta'=>has_action('admin_init','fpw_deny_sales_order_note_and_meta_mutation'), 'save'=>has_filter('woocommerce_process_shop_order_meta','fpw_deny_sales_order_save'), 'bulk'=>has_filter('woocommerce_bulk_action_ids','fpw_deny_sales_bulk_actions'))) . "\n";
    fpw_probe_check('restricted_actor', $report['limited'] && !$report['effective_manage_woocommerce']);
    // The comparison removes only native Woo cap callbacks for this one CLI process, then restores them.
    remove_filter('user_has_cap', 'wc_customer_has_capability', 10);
    remove_filter('user_has_cap', 'wc_shop_manager_has_capability', 10);
    remove_filter('map_meta_cap', 'wc_modify_map_meta_cap', 10);
    echo 'without_native_woo_filters: ' . json_encode(array('edit_shop_orders'=>current_user_can('edit_shop_orders'), 'manage_woocommerce'=>current_user_can('manage_woocommerce'), 'limited'=>fpw_is_order_limited_staff())) . "\n";
    add_filter('user_has_cap', 'wc_customer_has_capability', 10, 3);
    add_filter('user_has_cap', 'wc_shop_manager_has_capability', 10, 4);
    add_filter('map_meta_cap', 'wc_modify_map_meta_cap', 10, 4);
    $before = serialize(array(get_post($post), get_post_meta($post)));
    $ajax=false;
    add_filter('wp_doing_ajax', static function() use (&$ajax) { return $ajax; });
    foreach (array('editor','quick_status','note_delete','save','bulk') as $case) {
        $_GET=$_POST=$_REQUEST=array();
        $GLOBALS['pagenow']='post.php';
        $ajax=in_array($case,array('quick_status','note_delete'),true);
        if ($case==='editor') { $_POST=array('action'=>'editpost','post_ID'=>$post); $_REQUEST=$_POST; }
        if ($case==='quick_status') { $_REQUEST=array('action'=>'woocommerce_mark_order_status','order_id'=>$post); }
        if ($case==='note_delete') { $_REQUEST=array('action'=>'woocommerce_delete_order_note'); }
        $denied=false;
        try {
            if ($case==='save') { do_action('woocommerce_process_shop_order_meta',$post,get_post($post)); }
            elseif ($case==='bulk') { apply_filters('woocommerce_bulk_action_ids',array($post),'mark_processing','shop_order'); }
            else { do_action('admin_init'); }
        } catch (FPW_Probe_Denial $error) { $denied=$error->getCode()===403 && $error->getMessage()==='sales-read-only'; }
        fpw_probe_check($case . '_denied', $denied);
        fpw_probe_check($case . '_unchanged', serialize(array(get_post($post),get_post_meta($post))) === $before);
    }
} finally {
    wp_set_current_user($originalUser);
    if (is_int($post) && $post && get_post_meta($post,'_fp_probe_run',true)===$run) { wp_delete_post($post,true); }
    if (is_int($user) && $user) {
        $owned=get_userdata($user);
        if ($owned && $owned->user_login==='fp-probe-' . $run && $owned->user_email==='fp-probe-' . $run . '@example.invalid') { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($user); }
    }
}
echo 'result: ' . ($failures ? 'red' : 'green') . "\n";
if ($failures) { WP_CLI::halt(1); }
