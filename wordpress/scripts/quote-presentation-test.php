<?php
/** Execute presentation hooks with WP's real dispatcher; no HTTP or DB. */
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../.build/wp/wp-includes/plugin.php';
require_once __DIR__ . '/../wp-content/themes/freeplast/inc/quote-presentation.php';
$cart_route = true;
function is_cart() { global $cart_route; return $cart_route; }
$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$old = '<p class="fp-page-lead">Comprueba productos, colores y cantidades antes de continuar. Esto no es una compra ni una reserva de stock.</p>';
$new = '<p class="fp-page-lead">Comprueba productos, colores y cantidades antes de continuar.</p>';
$fixture = file_get_contents(__DIR__ . '/woo-cart.html');
verify(str_contains($fixture, $old), 'exercise the actual historical saved-page lead, not invented markup');
$rendered = apply_filters('render_block_core/html', $fixture);
verify($rendered === str_replace($old, $new, $fixture), 'only the exact shipped lead changes; native blocks and merchant content stay byte-identical');
verify(apply_filters('render_block_core/html', $rendered) === $rendered, 'normalization is idempotent');
$custom = str_replace('Comprueba productos', 'Revisa los productos', $fixture);
verify(apply_filters('render_block_core/html', $custom) === $custom, 'merchant-written lead is not overwritten');
$cart_route = false;
verify(apply_filters('render_block_core/html', $fixture) === $fixture, 'no changes outside the cart route');
verify(apply_filters('render_block_core/paragraph', $old) === $old, 'other block types remain untouched');
$functions = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/functions.php');
verify(str_contains($functions, "require_once __DIR__ . '/inc/quote-presentation.php';"), 'theme loads the actual presentation hook');
if (in_array('--fixture', $argv, true)) { echo $rendered; exit; }
echo "quote presentation: $checks native-hook checks passed (offline)\n";
