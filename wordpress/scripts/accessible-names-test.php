<?php
/** H3 (2026-10-03 review) offline checks: Spanish accessible names for the quote
 * journey's WooCommerce-rendered controls. The adapter's translation map, the
 * variation reset link and the cart-page script wiring are contract-checked
 * without WordPress: the map's pure helpers are extracted from the plugin
 * source exactly like checkout-form-test.php extracts the field definitions. */
define('ABSPATH', __DIR__);
$count = 0;
function check($ok, $label) { global $count; $count++; if (!$ok) { throw new RuntimeException($label); } }

$adapter = file_get_contents(__DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
foreach (array('function fpw_accessible_name_translations(): array {', 'function fpw_accessible_name_spanish_text( string $text ): string {', 'function fpw_reset_variations_link_es(): string {') as $signature) {
    $at = strpos($adapter, $signature);
    check(false !== $at, 'adapter declares ' . substr($signature, 9, 40));
}
preg_match('/function fpw_accessible_name_translations\(\): array \{.*?\n\}/s', $adapter, $map_match);
preg_match('/function fpw_accessible_name_spanish_text\( string \$text \): string \{.*?\n\}/s', $adapter, $helper_match);
preg_match('/function fpw_reset_variations_link_es\(\): string \{.*?\n\}/s', $adapter, $reset_match);
check(isset($map_match[0], $helper_match[0], $reset_match[0]), 'the three pure helpers are extractable for offline checks');
eval($map_match[0]);
eval($helper_match[0]);
eval($reset_match[0]);

$map = fpw_accessible_name_translations();
foreach (array('Product quantity', 'optional', 'Products in cart', 'Quantity of %s in your cart.', 'Reduce quantity of %s', 'Increase quantity of %s', 'Remove %s from cart') as $original) {
    check(isset($map[$original]) && '' !== trim($map[$original]), 'Spanish translation exists for: ' . $original);
    check(substr_count($original, '%s') === substr_count($map[$original], '%s'), 'sprintf placeholders survive translation: ' . $original);
}
check(fpw_accessible_name_spanish_text('Product quantity') === 'Cantidad del producto', 'known strings translate through the pure helper');
check(fpw_accessible_name_spanish_text('Some untranslated Woo sentence') === 'Some untranslated Woo sentence', 'unknown strings pass through untouched');
$reset = fpw_reset_variations_link_es();
check(str_contains($reset, 'class="reset_variations"') && str_contains($reset, 'aria-label="Limpiar opciones elegidas"') && str_contains($reset, '>Limpiar</a>'), 'variation reset link renders Spanish text and label on Woo\'s own classes');
check(!preg_match('/>Clear</', $reset), 'the English reset label is fully replaced, not styled over');

/* Cart-page script wiring: the theme loads it only on the cart route, after the
 * block runtime, and the runtime handle is a real dependency. */
$functions = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/functions.php');
check(str_contains($functions, "assets/js/cart-accessible-names.js', array( 'wc-cart-checkout-base', 'wc-blocks-checkout' )"), 'cart names script declares the block runtime AND wc-blocks-checkout dependencies, so its globals exist and no undeclared-access warning fires');
$cart_names = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/assets/js/cart-accessible-names.js');
check(str_contains($cart_names, "setLocaleData") && str_contains($cart_names, "'woocommerce'"), 'the cart page supplies its Spanish strings through the public wp.i18n seam');
foreach (array('Products in cart', 'Quantity of %s in your cart.', 'Reduce quantity of %s', 'Increase quantity of %s', 'Remove %s from cart') as $original) {
    check(str_contains($cart_names, "'{$original}'"), 'cart script maps the exact original Woo string: ' . $original);
    check(str_contains($cart_names, "'" . $map[$original] . "'"), 'cart script and adapter map agree on the Spanish value: ' . $map[$original]);
}
check(str_contains($cart_names, "registerCheckoutFilters('freeplast/variant-names'") && str_contains($cart_names, 'itemName'), 'variant context is added through the checkout-filter API, not a vendor patch');

/* Combined PHP→JS contract: the stepper must recognize BOTH the untranslated generic
 * ("Product quantity") and the Spanish generic this adapter's gettext map produces
 * ("Cantidad del producto"), so a translated runtime still gets product-named controls. */
$stepper = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js');
check(str_contains($stepper, 'Product quantity') && str_contains($stepper, $map['Product quantity']), 'the stepper recognizes the untranslated AND the adapter-translated generic quantity label');

/* Pinned-call-site proof (2026-10-04 lead review): the checked WooCommerce 11.1.0 cart
 * bundle feeds the itemName checkout-filter result (ue) into the quantity input, both
 * stepper buttons and the remove link, and registerCheckoutFilters feeds the registry
 * applyCheckoutFilter reads — the seam above reaches the REAL accessible names. */
$bundle_path = __DIR__ . '/../.build/wp/wp-content/plugins/woocommerce/assets/client/blocks/wc-cart-checkout-base-frontend.js';
$bundle = is_file($bundle_path) ? file_get_contents($bundle_path) : '';
check('' !== $bundle, 'the pinned WooCommerce cart bundle is present for call-site verification');
check(str_contains($bundle, 'itemName:ue') && str_contains($bundle, 'filterName:"itemName"'), 'pinned cart: the line passes the itemName-filtered name into its quantity controls');
check(str_contains($bundle, '"Quantity of %s in your cart.","woocommerce"),p)') && str_contains($bundle, '"Reduce quantity of %s","woocommerce"),p)') && str_contains($bundle, '"Increase quantity of %s","woocommerce"),p)'), 'pinned cart: quantity input and both stepper buttons build their labels from the itemName prop');
check(str_contains($bundle, '"Remove %s from cart","woocommerce"),ue)'), 'pinned cart: the remove link aria-label uses the same filtered name');
check(str_contains($bundle, 'X={...X,[e]:t}') && str_contains($bundle, 'Object.keys(X).map(t=>X[t][e])'), 'pinned cart: registerCheckoutFilters stores into the registry applyCheckoutFilter consumes');
foreach (array('Products in cart', 'Quantity of %s in your cart.', 'Reduce quantity of %s', 'Increase quantity of %s', 'Remove %s from cart') as $original) {
    check(str_contains($bundle, '"' . $original . '"'), 'pinned cart: the translation key matches the bundle string exactly: ' . $original);
}

echo "accessible names: {$count} offline checks passed\n";
