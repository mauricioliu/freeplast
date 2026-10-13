<?php
/** Plugin Name: Freeplast persistent LOCAL containment */
if (!defined('ABSPATH')) { exit; }
if (!defined('WP_ENVIRONMENT_TYPE') || 'local' !== WP_ENVIRONMENT_TYPE ||
    !defined('WP_HOME') || WP_HOME !== getenv('FREEPLAST_LOCAL_URL') ||
    !preg_match('~\Ahttp://(?:mliu|localhost|127\.0\.0\.1):[1-9][0-9]{3,4}\z~D', WP_HOME)) {
    throw new RuntimeException('Local containment refuses unknown environment/host.');
}
// Installation has no home option yet; every capture hook must see the validated local URL.
add_filter('pre_option_home', static fn() => WP_HOME, PHP_INT_MAX);
require '/opt/local-review-mail-capture.php';
if (!fpw_local_capture_host_allowed()) { throw new RuntimeException('Local capture host rejected.'); }
// Reusing the capture plugin is safe only after the host gate fails closed above.
add_action('phpmailer_init', static function () {
    throw new RuntimeException('Local environment forbids real mail transport.');
}, PHP_INT_MAX);
add_action('wp_body_open', static function () {
    echo '<div role="status" style="padding:8px;text-align:center;background:#100090;color:#fff">LOCAL · Datos ficticios · Correos capturados, nunca enviados · Integraciones externas bloqueadas</div>';
});
add_filter('pre_option_woocommerce_coming_soon', static fn() => 'no');
add_filter('pre_option_woocommerce_store_pages_only', static fn() => 'no');
add_filter('pre_option_woocommerce_private_link', static fn() => 'no');
add_filter('pre_option_woocommerce_allow_tracking', static fn() => 'no');
