<?php
/** Step 3 red-review regressions: REAL pinned WooCommerce 11.1.0 StoreApi code (QuantityLimits + CartController, byte-copied into .build) drives the Cart block's quantity validation — proving the ACTUAL update-item seam enforces the owner-confirmed minimums: parent-resolved for variations, prospective aggregation across sibling lines, persisted below-floor historical lines never silently clamped, unknowns requestable. Classic add/update boundaries evaluate the RESULTING aggregate, and the native presentation seam (woocommerce_get_item_data) carries the basket wording. */
require __DIR__ . '/quote-draft-test.php';

/* --- Harness additions: WC() cart fake, WP bits the pinned code needs, the pinned sources themselves. --- */
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ) { return $thing instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $args, $defaults ) { return array_merge( is_array( $defaults ) ? $defaults : array(), is_array( $args ) ? $args : array() ); } }
if ( ! function_exists( 'wc_stock_amount' ) ) { function wc_stock_amount( $amount ) { return (int) $amount; } }
if ( ! function_exists( 'wc_is_stock_amount_integer' ) ) { function wc_is_stock_amount_integer() { return true; } }
if ( ! class_exists( 'WC_Product' ) ) { class WC_Product {} }
class FPW_StoreAPI_Product extends WC_Product {
	public function __construct( public int $id, public int $parent = 0, public string $name = 'Producto de prueba' ) {}
	public function get_id(): int { return $this->id; }
	public function get_parent_id(): int { return $this->parent; }
	public function get_title(): string { return $this->name; }
	public function get_name(): string { return $this->name; }
	public function is_sold_individually(): bool { return false; }
	public function managing_stock(): bool { return false; }
	public function backorders_allowed(): bool { return false; }
	public function get_min_purchase_quantity(): int { return 1; }
	public function get_max_purchase_quantity(): int { return -1; }
	public function get_purchase_quantity_step(): int { return 1; }
	public function is_purchasable(): bool { return true; }
}
class FPW_StoreAPI_Cart {
	public array $cart_contents = array();
	public array $set_calls = array();
	public function get_cart() { return $this->cart_contents; }
	public function set_quantity( $key, $quantity = 1 ) { $this->set_calls[] = array( $key, (int) $quantity ); $this->cart_contents[ $key ]['quantity'] = (int) $quantity; return true; }
}
$GLOBALS['fpw_storeapi_cart'] = new FPW_StoreAPI_Cart();
if ( ! function_exists( 'WC' ) ) {
	function WC() { return (object) array( 'cart' => $GLOBALS['fpw_storeapi_cart'] ); }
}
$woo = realpath( __DIR__ . '/../.build/wp/wp-content/plugins/woocommerce' );
foreach ( array( '/src/Utilities/NumberUtil.php', '/src/StoreApi/Exceptions/RouteException.php', '/src/StoreApi/Utilities/DraftOrderTrait.php', '/src/StoreApi/Utilities/QuantityLimits.php', '/src/StoreApi/Utilities/CartController.php' ) as $file ) {
	check( is_file( $woo . $file ), 'pinned source present: ' . $file );
	require $woo . $file;
}
/* The pinned quantity-input args builder, extracted and evaluated like every other pinned native function these suites run. */
$template_functions = file_get_contents( $woo . '/includes/wc-template-functions.php' );
check( 1 === preg_match( '/\nfunction wc_get_quantity_input_args\( .*?\n\}/s', $template_functions, $native_match ), 'pinned wc_get_quantity_input_args found' );
eval( str_replace( 'function wc_get_quantity_input_args', 'function fpw_pinned_wc_get_quantity_input_args', $native_match[0] ) );
class FPW_Test_Cart_Controller extends Automattic\WooCommerce\StoreApi\Utilities\CartController {
	public function get_cart_instance() { return $GLOBALS['fpw_storeapi_cart']; }
	public function get_cart_item( $item_id ) { return $GLOBALS['fpw_storeapi_cart']->cart_contents[ $item_id ] ?? array(); }
}
use Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
/** Monkey-patch: QuantityLimits calls wc_get_quantity_input_args by name; expose the pinned implementation under it. */
if ( ! function_exists( 'wc_get_quantity_input_args' ) ) { function wc_get_quantity_input_args( $args, $product = null ) { return fpw_pinned_wc_get_quantity_input_args( $args, $product ); } }

/** Product meta: parent 22 carries the confirmed minimum 70; parent 25 carries 100; 999 is an explicit unknown. */
$GLOBALS['fpwd_post_meta'][22]['_fp_quote_min_qty'] = '70';
$GLOBALS['fpwd_post_meta'][25]['_fp_quote_min_qty'] = '100';
function fpw_storeapi_item( string $key, FPW_StoreAPI_Product $product, int $product_id, int $quantity ): array {
	return array( 'key' => $key, 'product_id' => $product_id, 'variation_id' => $product->get_parent_id() > 0 ? $product->get_id() : 0, 'quantity' => $quantity, 'data' => $product );
}

/* ===== 1. Native hooks wired on the exact seams the pinned code invokes — not helper-only. ===== */
foreach ( array( 'woocommerce_store_api_product_quantity_minimum', 'woocommerce_get_item_data', 'woocommerce_update_cart_validation', 'woocommerce_add_to_cart_validation' ) as $hook ) {
	check( ! empty( $registered_filters[ $hook ] ), 'native hook wired: ' . $hook );
}
$schema_source = file_get_contents( $woo . '/src/StoreApi/Schemas/V1/CartItemSchema.php' );
check( str_contains( $schema_source, "apply_filters( 'woocommerce_get_item_data'" ), 'the pinned CartItemSchema really renders item data through the native seam' );

/* ===== 2. REAL QuantityLimits: per-item floors at the update seam, parent-resolved for variations. ===== */
$limits = new QuantityLimits();
$variation_a = new FPW_StoreAPI_Product( 310, 22, 'Caja Universal Azul' );
$item_a = fpw_storeapi_item( 'a', $variation_a, 22, 80 );
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 'a' => $item_a );
check( ( new QuantityLimits() )->get_cart_item_quantity_limits( $item_a )['minimum'] === 70, 'the block quantity_limits minimum resolves the PARENT minimum for a variation line' );
$rejected = $limits->validate_cart_item_quantity( 50, $item_a );
check( is_wp_error( $rejected ) && 'invalid_quantity' === $rejected->get_error_code(), 'REAL StoreApi validation rejects a below-minimum update on a variation line' );
check( true === $limits->validate_cart_item_quantity( 70, $item_a ) && true === $limits->validate_cart_item_quantity( 71, $item_a ), 'the exact minimum and any higher non-multiple quantity pass the REAL validation' );

/* ===== 3. Prospective aggregation across sibling variations — the actual update-item seam. ===== */
$variation_b = new FPW_StoreAPI_Product( 311, 22, 'Caja Universal Roja' );
$GLOBALS['fpw_storeapi_cart']->cart_contents = array(
	'a' => fpw_storeapi_item( 'a', $variation_a, 22, 60 ),
	'b' => fpw_storeapi_item( 'b', $variation_b, 22, 60 ),
);
check( true === $limits->validate_cart_item_quantity( 35, $GLOBALS['fpw_storeapi_cart']->cart_contents['a'] ), 'a sibling line at 60 leaves only the remainder (10) required: 35 passes (final 95 ≥ 70)' );
check( is_wp_error( $limits->validate_cart_item_quantity( 5, $GLOBALS['fpw_storeapi_cart']->cart_contents['a'] ) ), 'an update leaving the final aggregation below the minimum (5 + 60 = 65 < 70) is rejected at the seam' );
$controller = new FPW_Test_Cart_Controller();
$GLOBALS['fpw_storeapi_cart']->set_calls = array();
try { $controller->set_cart_item_quantity( 'a', 5 ); check( false, 'the REAL CartController update seam throws on below-aggregate quantities' ); }
catch ( RouteException $error ) { check( 'invalid_quantity' === $error->error_code, 'the REAL CartController update seam rejects with the native invalid_quantity error' ); }
check( array() === $GLOBALS['fpw_storeapi_cart']->set_calls, 'the rejected update never mutated the persisted quantity' );
$controller->set_cart_item_quantity( 'a', 35 );
check( $GLOBALS['fpw_storeapi_cart']->set_calls === array( array( 'a', 35 ) ), 'a valid update reaches the native set_quantity with exactly the user quantity' );

/* ===== 4. Historical under-minimum lines: full enforcement, read preserves the stored quantity. ===== */
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 'h' => fpw_storeapi_item( 'h', new FPW_StoreAPI_Product( 22, 0, 'Caja Cosechera 3/4' ), 22, 30 ) );
$historical = $GLOBALS['fpw_storeapi_cart']->cart_contents['h'];
check( ( new QuantityLimits() )->get_cart_item_quantity_limits( $historical )['minimum'] === 70, 'a historical under-minimum line carries the FULL floor as its limit — no relaxation to 30' );
check( is_wp_error( $limits->validate_cart_item_quantity( 35, $historical ) ) && is_wp_error( $limits->validate_cart_item_quantity( 30, $historical ) ), 'explicit updates of a historical 30-line to 35 (and to 30) are rejected below the 70 floor' );
check( true === $limits->validate_cart_item_quantity( 70, $historical ), 'an explicit update of the historical line to the full minimum is accepted' );
$GLOBALS['fpw_storeapi_cart']->set_calls = array();
try { $controller->set_cart_item_quantity( 'h', 35 ); check( false, 'the REAL CartController rejects a historical 30 → 35 update' ); }
	catch ( RouteException $error ) { check( 'invalid_quantity' === $error->error_code, 'the REAL CartController rejects 35 with the native invalid_quantity error' ); }
check( array() === $GLOBALS['fpw_storeapi_cart']->set_calls && 30 === $GLOBALS['fpw_storeapi_cart']->cart_contents['h']['quantity'], 'the rejected update mutates nothing: the stored quantity stays 30' );
$controller->set_cart_item_quantity( 'h', 70 );
check( $GLOBALS['fpw_storeapi_cart']->set_calls === array( array( 'h', 70 ) ), 'the REAL CartController applies the accepted 70 update' );
/* Read preservation is structural, not a weakened limit: the pinned schema reports the stored quantity verbatim and our seam only supplies a minimum. */
check( str_contains( $schema_source, "'quantity'             => wc_stock_amount( \$cart_item['quantity'] )" ), 'the pinned CartItemSchema reports the stored quantity verbatim — the read path carries no clamp' );
$plugin_source = file_get_contents( __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php' );
$seam_start = (int) strpos( $plugin_source, "add_filter( 'woocommerce_store_api_product_quantity_minimum'" );
$seam_end = (int) strpos( $plugin_source, '}, 10, 3 );', $seam_start );
$seam_source = substr( $plugin_source, $seam_start, $seam_end - $seam_start );
check( false !== $seam_start && false !== $seam_end && ! str_contains( $seam_source, 'persisted' ), 'the StoreApi minimum seam itself never relaxes to a persisted quantity (enforcement is the true product-minus-sibling floor)' );

/* ===== 5. Explicit unknowns stay requestable; native value untouched. ===== */
$unknown = fpw_storeapi_item( 'u', new FPW_StoreAPI_Product( 999, 0, 'Pediluvio' ), 999, 1 );
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 'u' => $unknown );
check( ( new QuantityLimits() )->get_cart_item_quantity_limits( $unknown )['minimum'] === 1 && true === $limits->validate_cart_item_quantity( 1, $unknown ), 'an unknown minimum keeps the native one-unit rules — never reinterpreted' );

/* ===== 6. Add boundary evaluates the RESULTING aggregate: top-up of 1 onto a satisfied basket passes. ===== */
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 't' => fpw_storeapi_item( 't', new FPW_StoreAPI_Product( 22, 0, 'Caja Cosechera 3/4' ), 22, 70 ) );
$add = $registered_filters['woocommerce_add_to_cart_validation'][0];
$notices = count( $GLOBALS['fpwd_notices'] );
check( true === $add( true, 22, 1, 0 ) && count( $GLOBALS['fpwd_notices'] ) === $notices, 'a top-up of 1 onto an existing 70-of-70 basket is accepted (resulting 71 ≥ minimum)' );
check( false === $add( true, 25, 30, 0 ) && count( $GLOBALS['fpwd_notices'] ) === $notices + 1, 'a fresh add of 30 against minimum 100 is refused with a notice' );
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 't' => fpw_storeapi_item( 't', new FPW_StoreAPI_Product( 22, 0, 'Caja Cosechera 3/4' ), 22, 30 ) );
check( false === $add( true, 22, 1, 0 ), 'an unsatisfied historical basket still cannot add below the resulting minimum (31 < 70)' );

/* ===== 7. Classic multi-row cart update: prospective posted quantities, not stale basket state. ===== */
$update = $registered_filters['woocommerce_update_cart_validation'][0] ?? null;
check( is_callable( $update ), 'the classic cart-update validation hook is registered' );
$variation_25a = new FPW_StoreAPI_Product( 320, 25, 'Caja Universal Cerrada Azul' );
$variation_25b = new FPW_StoreAPI_Product( 321, 25, 'Caja Universal Cerrada Roja' );
$GLOBALS['fpw_storeapi_cart']->cart_contents = array(
	'x' => fpw_storeapi_item( 'x', $variation_25a, 25, 60 ),
	'y' => fpw_storeapi_item( 'y', $variation_25b, 25, 60 ),
);
$_POST['cart'] = array( 'x' => array( 'qty' => '35' ), 'y' => array( 'qty' => '65' ) );
check( true === $update( true, 'x', $GLOBALS['fpw_storeapi_cart']->cart_contents['x'], 35 ), 'the NATIVE cart[key][qty] payload passes when the PROSPECTIVE aggregation satisfies the minimum (35 + 65 = 100)' );
$_POST['cart'] = array( 'x' => array( 'qty' => '35' ), 'y' => array( 'qty' => '60' ) );
check( false === $update( true, 'x', $GLOBALS['fpw_storeapi_cart']->cart_contents['x'], 35 ), 'the NATIVE cart[key][qty] payload is rejected when the prospective aggregation stays below (35 + 60 = 95 < 100)' );
$_POST['cart'] = array();
$GLOBALS['fpw_storeapi_cart']->cart_contents = array( 'x' => fpw_storeapi_item( 'x', $variation_25a, 25, 100 ) );
check( true === $update( true, 'x', $GLOBALS['fpw_storeapi_cart']->cart_contents['x'], 100 ) && false === $update( true, 'x', $GLOBALS['fpw_storeapi_cart']->cart_contents['x'], 99 ), 'a lone line validates the full minimum: 100 passes, 99 is rejected' );

/* ===== 8. Basket wording rides the native item-data presentation seam the Cart block renders. ===== */
$item_data = apply_filters( 'woocommerce_get_item_data', array(), fpw_storeapi_item( 'd', new FPW_StoreAPI_Product( 22, 0, 'Caja Cosechera 3/4' ), 22, 70 ) );
check( in_array( array( 'key' => 'Mínimo por solicitud', 'display' => '70 unidades' ), $item_data, true ), 'a confirmed minimum renders as item data on the basket line' );
$pending = apply_filters( 'woocommerce_get_item_data', array(), fpw_storeapi_item( 'p', new FPW_StoreAPI_Product( 999, 0, 'Pediluvio' ), 999, 1 ) );
check( (bool) array_filter( $pending, static fn( $row ) => 'Mínimo' === $row['key'] && str_contains( (string) $row['display'], 'por confirmar' ) ), 'an unknown minimum renders its explicit pending wording on the basket line' );
echo "storeapi minimums: $assertions cumulative offline checks passed; pinned 11.1.0 seam behavior, no server\n";
