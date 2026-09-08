<?php
/** Offline contract tests for the A · Directa Productos a Cotizar page
 * (issue #45): the canonical page markup (scripts/woo-cart.html — the same
 * file the staging migration and the disposable fixture use), the template
 * wrapper and the price-suppression rules. The Cart block itself, its store,
 * settlement and recovery stay native — behavioral coverage lives in
 * woo-cart-store-harness.mjs over the pinned store bundle. */
$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}

$markup = file_get_contents(__DIR__ . '/../scripts/woo-cart.html');

/* A page heading: steps, eyebrow, title, lead. */
verify(preg_match('/class="fp-steps" aria-label="Pasos de la solicitud"/', $markup) === 1, 'the request steps are labelled');
verify(substr_count($markup, '<li') === 3, 'three steps');
verify(str_contains($markup, 'aria-current="step"') && str_contains($markup, 'step-number">1<') && !str_contains($markup, 'step-number">2</span><span>Tus datos</span></li><li class="active"'), 'step 1 (Productos) is the current step on this page');
verify(str_contains($markup, 'Revisa tu selección') && str_contains($markup, '<h1>Productos a Cotizar</h1>'), 'the reference heading');
verify(str_contains($markup, 'Comprueba productos, colores y cantidades antes de continuar.'), 'the reference lead copy');
verify(str_contains($markup, 'Esto no es una compra ni una reserva de stock.'), 'the no-purchase note stays on the page');

/* The REAL cart block with its native inner structure. */
verify(str_contains($markup, '<!-- wp:woocommerce/cart -->') && str_contains($markup, 'wp-block-woocommerce-cart'), 'the native Cart block renders the selection');
verify(str_contains($markup, 'wp:woocommerce/cart-line-items-block'), 'native line items block');
verify(str_contains($markup, 'wp-block-woocommerce-proceed-to-checkout-block'), 'the native proceed block owns the CTA');
verify(!str_contains($markup, '[woocommerce_cart]'), 'no classic cart shortcode replaces the block');

/* A empty state with real destinations. */
verify(str_contains($markup, 'empty-state large') && str_contains($markup, 'Aún no agregas productos.'), 'the A empty state');
verify(str_contains($markup, 'Explora el catálogo y reúne lo que necesitas en una sola solicitud de cotización.'), 'empty-state copy');
verify(str_contains($markup, 'href="/tienda/">Elegir productos'), 'the empty state returns to the native catalog');
verify(str_contains($markup, 'data-fp-dialog="fp-help"'), 'the empty state offers the real help dialog');

/* No public amounts may exist in the delivered page markup. */
verify(!str_contains($markup, '$'), 'no currency amount in the page markup');
foreach (array('wc-block-cart-item__total', 'order-summary', 'subtotal', 'Total') as $marker) {
    verify(!str_contains($markup, $marker), "no total surface in the page markup: {$marker}");
}

/* The template no longer imposes the 1152px Woo page shell (A shells own it). */
$template = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/templates/page-cart.html');
verify(!str_contains($template, 'fp-woo-page'), 'the cart template leaves the shell to the page markup');
verify(str_contains($template, 'wp:template-part {"slug":"header","tagName":"header"} /-->'), 'shared chrome header kept');

/* The stylesheet hides every price/total surface the block could render. */
$woo_css = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/assets/css/woo.css');
foreach (array(
    '.wc-block-cart .wc-block-cart-item__total',
    '.wc-block-cart .wc-block-cart-items__header-total',
    '.wc-block-cart .wc-block-components-product-price',
    '.wc-block-cart__sidebar .wc-block-components-totals-wrapper',
) as $selector) {
    verify(str_contains($woo_css, $selector), "price surface hidden in the stylesheet: {$selector}");
}
verify(str_contains($woo_css, 'display: none !important;'), 'the price suppression cannot be outprioritized by block styles');
/* The block's own quantity selector keeps A's >=44px stepper geometry. */
verify(str_contains($woo_css, 'button.wc-block-components-quantity-selector__button') && str_contains($woo_css, 'input.wc-block-components-quantity-selector__input'), 'the native quantity selector is styled to A geometry');

/* The fixture and the production migration consume THIS file — one source. */
$bootstrap = file_get_contents(__DIR__ . '/bootstrap.mjs');
verify(str_contains($bootstrap, "woo-cart.html"), 'the disposable fixture seeds /cotizacion/ from this canonical markup');
$migrate = file_get_contents(__DIR__ . '/migrate-to-woo.php');
verify(str_contains($migrate, "woo-cart.html"), 'the staging migration reads the same canonical markup');

/* The dock stays OFF the cart page (this page owns its CTA composition). */
$functions = file_get_contents(__DIR__ . '/../wp-content/themes/freeplast/functions.php');
verify(str_contains($functions, "! ( function_exists( 'is_cart' ) && is_cart() )"), 'the dock route excludes the cart page');

echo "cart page: $checks PHP checks passed\n";
