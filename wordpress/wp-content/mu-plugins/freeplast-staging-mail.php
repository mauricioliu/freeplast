<?php
/** Plugin Name: Freeplast staging mail containment
 * Independent of the legacy plugin: never deliver any WordPress/Woo mail from staging.
 * Count attempts only; do not log bodies, customer data, nonces or recipients.
 */
if (!defined('ABSPATH')) { exit; }
add_filter('pre_wp_mail', static function ($result) {
    $host = wp_parse_url(get_option('home'), PHP_URL_HOST);
    if ('freeplast.mliu.site' !== $host) { return $result; }
    update_option('fpw_suppressed_mail_count', (int)get_option('fpw_suppressed_mail_count', 0) + 1, false);
    return true;
}, PHP_INT_MAX);
