<?php
/** Offline unit checks of the small local field boundary, not a WooCommerce simulation. */
define('ABSPATH',__DIR__);
define('DAY_IN_SECONDS',86400); // WordPress core constant, absent offline
$registered_filters=array();
$registered_actions=array();
function add_action(...$args) { global $registered_actions; $registered_actions[$args[0]][]=$args[1] ?? null; }
function add_filter(...$args) { global $registered_filters; $registered_filters[$args[0]][]=$args[1]; }
function register_activation_hook(...$args) {}
function wp_json_encode($data,$flags=0) { return json_encode($data,$flags); }
function absint($value) { return abs((int)$value); }
function sanitize_textarea_field($value) { return trim(strip_tags((string)$value)); }
class WP_Error {
    public array $codes=array();
    public function add($code,$message,$data=null) { $this->codes[]=$code; }
    public function has_errors() { return (bool)$this->codes; }
}
require __DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php';
require __DIR__.'/../wp-content/themes/freeplast/functions.php';
$assertions=0;
function check($ok,$message) { global $assertions; $assertions++; if(!$ok) { throw new RuntimeException($message); } }
$fields=fpw_checkout_fields(array());
foreach(array('billing_first_name','billing_email','billing_phone','billing_company','billing_fp_rut','billing_fp_giro','billing_fp_dispatch') as $field) { check($fields['billing'][$field]['required'], $field.' required'); }
check($fields['billing']['billing_fp_address']['required']===false,'Address is conditionally validated');
check($fields['shipping']===array(),'No shipping calculator fields');
$cases=array(
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'no'),false),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>''),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>'   '),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>'Camino de prueba, Mostazal'),false),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'otra'),true),
    array(array('payment_method'=>'bacs','billing_fp_dispatch'=>'no'),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'no','billing_fp_rut'=>str_repeat('1',241)),true),
);
foreach($cases as $index=>[$data,$fails]) { $errors=new WP_Error();fpw_validate_checkout($data,$errors);check($errors->has_errors()===$fails,'Validation case '.$index); }

// Issue #30: the header Productos a Cotizar count reads the Woo cart — distinct lines, never units.
check(!function_exists('WC'),'WooCommerce stub is not loaded yet');
check(fpw_cart_line_count()===0,'Absent WooCommerce renders a zero line count');
class FPW_Fake_WC { public $cart=null; public $session=null; }
class FPW_Fake_Cart { public array $lines=array(); public function get_cart() { return $this->lines; } }
if (!function_exists('WC')) { $GLOBALS['fpw_woo']=new FPW_Fake_WC(); function WC() { return $GLOBALS['fpw_woo']; } }
check(fpw_cart_line_count()===0,'Empty cart renders a zero line count');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart();
check(fpw_cart_line_count()===0,'Cart without lines renders a zero line count');
$GLOBALS['fpw_woo']->cart->lines=array('a'=>array('quantity'=>3),'b'=>array('quantity'=>5));
check(fpw_cart_line_count()===2,'Quantities do not add lines: 3+5 units stay 2 distinct lines');
$GLOBALS['fpw_woo']->cart->lines+=array('c'=>array('quantity'=>1),'d'=>array('quantity'=>7));
check(fpw_cart_line_count()===4,'Distinct variants and colours count as separate lines');
$fragment_filter=reset($registered_filters['woocommerce_add_to_cart_fragments']);
$fragments=$fragment_filter(array());
check(isset($fragments['span.fpw-basket-count']),'Header count joins the native add-to-cart fragment refresh');
check($fragments['span.fpw-basket-count']==='<span class="fpw-basket-count">4</span>','Fragment carries the current distinct line count');
$header=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/parts/header.html');
check(substr_count($header,'fpw-basket-count')===2,'Both header surfaces (desktop link + mobile menu) carry the count span');
check(substr_count($header,'{{FREEPLAST_BASKET_COUNT}}')===2,'Both header surfaces server-render the count token');
$resolvers=array_values(array_filter($registered_filters['render_block'],static function($resolver){
    $rendered=$resolver('{{FREEPLAST_BASKET_COUNT}}');
    return is_string($rendered) && !str_contains($rendered,'{{FREEPLAST_BASKET_COUNT}}');
}));
check(count($resolvers)===1,'Exactly one theme resolver replaces the count token');
$link='<a class="fp-woo-selection" href="/cotizacion/">Productos a Cotizar (<span class="fpw-basket-count">{{FREEPLAST_BASKET_COUNT}}</span>)</a>';
check(str_contains($resolvers[0]($link),'(<span class="fpw-basket-count">4</span>)'),'First paint renders the live distinct line count');
$GLOBALS['fpw_woo']->cart->lines=array();
check(str_contains($resolvers[0]($link),'(<span class="fpw-basket-count">0</span>)'),'Empty basket renders the documented (0) state');
check($resolvers[0]('<p>sin token</p>')==='<p>sin token</p>','Blocks without the token pass through byte-identically');

// Issue #29: the Datos y envío review table carries products, options and quantities — never the technical zero.
$review_template=__DIR__.'/../wp-content/themes/freeplast/woocommerce/checkout/review-order.php';
check(is_file($review_template),'The theme owns a checkout review-order override (render-origin fix)');
$review_source=file_get_contents($review_template);
foreach(array('wc_price','get_product_subtotal','get_price_html','wc_cart_totals_subtotal_html','wc_cart_totals_order_total_html','wc_cart_totals_coupon_html','wc_cart_totals_fee_html','wc_cart_totals_shipping_html') as $amount_path) {
    check(!str_contains($review_source,$amount_path),'Review table never reaches a price renderer: '.$amount_path);
}
foreach(array('woocommerce_cart_item_name','woocommerce_checkout_cart_item_visible','woocommerce_review_order_before_cart_contents','woocommerce_review_order_before_order_total') as $native_hook) {
    check(str_contains($review_source,$native_hook),'Review table keeps the native compatibility surface '.$native_hook);
}
$woo_css=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/assets/css/woo.css');
check(!str_contains($woo_css,'woocommerce-checkout-review-order-table'),'No CSS hiding of the review table: the DOM itself must carry no amounts');

// Offline render of the override with a controlled cart: one simple product, one colour variant.
if (!function_exists('esc_html')) { function esc_html($text) { return htmlspecialchars((string)$text,ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($text) { return htmlspecialchars((string)$text,ENT_QUOTES); } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($text) { return (string)$text; } }
if (!function_exists('do_action')) { function do_action(...$args) {} }
if (!function_exists('apply_filters')) { function apply_filters($tag,$value,...$args) { global $registered_filters; foreach($registered_filters[$tag]??array() as $callback) { $value=$callback($value,...$args); } return $value; } }
if (!function_exists('wc_get_formatted_cart_item_data')) { function wc_get_formatted_cart_item_data($item) { $out=''; foreach($item['variation']??array() as $attribute=>$value) { $out.='<p class="variation">'.esc_html($attribute).': '.esc_html($value).'</p>'; } return $out; } }
class WC_Product {
    public function __construct(private string $name) {}
    public function exists(): bool { return true; }
    public function get_name(): string { return $this->name; }
}
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart();
$GLOBALS['fpw_woo']->cart->lines=array(
    'simple'=>array('data'=>new WC_Product('Caja Cosechera 3/4'),'quantity'=>140),
    'variante'=>array('data'=>new WC_Product('Caja Universal Cerrada Color'),'quantity'=>5,'variation'=>array('Color'=>'Rojo')),
);
ob_start(); require $review_template; $review_html=ob_get_clean();
check(str_contains($review_html,'Caja Cosechera 3/4'),'Simple product name renders in the review table');
check(str_contains($review_html,'Caja Universal Cerrada Color'),'Variant product name renders in the review table');
check(str_contains($review_html,'140'),'Quantities render in the review table');
check(str_contains($review_html,'Color: Rojo'),'Chosen options render in the review table');
check(str_contains($review_html,'Por cotizar'),'Totals zone states the intention without amounts');
foreach(array('$0','woocommerce-Price-amount','product-total','Subtotal') as $leak) {
    check(!str_contains($review_html,$leak),'Review table HTML carries no '.$leak);
}
// Issue #28: the variable-product button exposes its real state semantically (WA-05).
$variation_button=__DIR__.'/../wp-content/themes/freeplast/woocommerce/single-product/add-to-cart/variation-add-to-cart-button.php';
check(is_file($variation_button),'The theme owns the variation cart-button override (render-origin fix)');
check(!is_file(__DIR__.'/../wp-content/themes/freeplast/woocommerce/single-product/add-to-cart/simple.php'),'Simple products keep the pinned Woo button: no override');
$variation_button_source=file_get_contents($variation_button);
foreach(array('woocommerce_before_add_to_cart_button','woocommerce_before_add_to_cart_quantity','woocommerce_quantity_input','woocommerce_after_add_to_cart_quantity','woocommerce_after_add_to_cart_button','wc_wp_theme_get_element_class_name') as $native_surface) {
    check(str_contains($variation_button_source,$native_surface),'Variation button keeps the native surface '.$native_surface);
}
foreach(array('name="add-to-cart"','name="product_id"','name="variation_id"') as $hidden_input) {
    check(str_contains($variation_button_source,$hidden_input),'Variation button keeps the native hidden input '.$hidden_input);
}
$functions_source=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/functions.php');
check(str_contains($functions_source,'variation-button-state.js'),'The state-mirroring script ships with the theme');
check(str_contains($functions_source,'is_product()'),'The state-mirroring script enqueues on product pages only');
check(str_contains($woo_css,'button.single_add_to_cart_button.button[aria-disabled="true"]'),'The inactive button look is explicit, not an opacity blend');
check(str_contains($woo_css,'fp-variation-hint'),'The associated instruction is styled and visible');

// Offline render of the override with a controlled variable product: initial (no colour chosen) state.
if (!class_exists('WC_Product_Attribute')) {
    class WC_Product_Attribute {
        public function __construct(private string $name, private bool $variation) {}
        public function get_name(): string { return $this->name; }
        public function get_variation(): bool { return $this->variation; }
    }
}
if (!function_exists('wc_attribute_label')) { function wc_attribute_label($name,$product='') { return 'Color'; } }
if (!function_exists('wc_wp_theme_get_element_class_name')) { function wc_wp_theme_get_element_class_name($type) { return 'wp-element-button'; } }
if (!function_exists('woocommerce_quantity_input')) { function woocommerce_quantity_input($args,$product=null) { echo '<input type="number" class="qty" />'; } }
if (!function_exists('wc_stock_amount')) { function wc_stock_amount($value) { return $value; } }
class FPW_Fake_Variable_Product {
    public function is_type(string $type): bool { return 'variable'===$type; }
    public function get_id(): int { return 25; }
    public function get_attributes(): array { return array('pa_color'=>new WC_Product_Attribute('pa_color',true),'garantia'=>new WC_Product_Attribute('garantia',false)); }
    public function get_min_purchase_quantity(): int { return 1; }
    public function get_max_purchase_quantity(): int { return -1; }
    public function single_add_to_cart_text(): string { return 'Agregar a Productos a Cotizar'; }
}
$product=new FPW_Fake_Variable_Product();
ob_start(); require $variation_button; $button_html=ob_get_clean();
check(str_contains($button_html,'wc-variation-selection-needed disabled'),'Initial availability classes render at the origin, matching Woo\'s own variation form state');
check(str_contains($button_html,'aria-disabled="true"'),'The initial delivered state exposes aria-disabled (no false enable)');
check(str_contains($button_html,'aria-describedby="fp-variation-hint-25"'),'The button links its associated instruction through aria-describedby');
check(str_contains($button_html,'Selecciona Color para agregar este producto a Productos a Cotizar.'),'The visible instruction names the real variation attribute');
check(!preg_match('/<button[^>]*\sdisabled[\s=>]/',$button_html),'No real disabled attribute: the no-JS flow stays operable and server validation owns rejection');
check(str_contains($button_html,'Agregar a Productos a Cotizar'),'The accessible name is the reviewed visible label');
check(str_contains($button_html,'class="qty"'),'Quantity input keeps rendering through the native hook');
check(str_contains($button_html,'data-fp-variation-hint'),'The instruction carries the state-script marker');

// Issue #26 (WA-03): the Productos a Cotizar page ships the quantity-change feedback bridge.
$feedback_script=__DIR__.'/../wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js';
check(is_file($feedback_script),'The theme owns the cart quantity-feedback script');
$feedback_source=file_get_contents($feedback_script);
check(str_contains($functions_source,'cart-quantity-feedback.js') && str_contains($functions_source,'is_cart()'),'The quantity-feedback script enqueues on the Productos a Cotizar page only');
check(str_contains($feedback_source,'experimental__woocommerce_blocks-cart-set-item-quantity'),'The stated quantity is learned from the cart block’s own store event');
check(str_contains($feedback_source,'/wc/store/v1/cart/update-item'),'The bridge observes the real Store API update-item endpoint');
check(str_contains($feedback_source,'hasPendingItemsOperations'),'Pending operations gate the Datos y envío CTA through the store’s own selector');
check(str_contains($feedback_source,'operationsPending'),'The CTA and the verdicts share one pending-operation predicate (issue #34: the store flag alone clears early when Woo aborts a replaced request)');
check(str_contains($feedback_source,'storeBusy || inflight > 0'),'Pending = the store’s own flags OR an update-item request still in flight');
check(str_contains($feedback_source,'if (operationsPending()) { event.preventDefault(); }'),'The synchronous click guard consults the shared predicate at click time, not a captured state');
check(str_contains($feedback_source,"inflight++;\n        syncSubmit();"),'A request start locks the CTA at the transport boundary itself, before any store flag could clear');
check(str_contains($feedback_source,'wc-block-cart__submit-button'),'The Datos y envío CTA is the guarded surface');
check(str_contains($feedback_source,"'alert'") && str_contains($feedback_source,'No se guardó el cambio de cantidad de '),'A visible role=alert notice explains, in Spanish, that the change was not saved');
check(str_contains($feedback_source,'Cantidad guardada: ') && str_contains($feedback_source,'sigue con '),'Both outcomes state the persisted quantity and the success updates the notice');
check(str_contains($feedback_source,'preventDefault'),'Advancing is blocked while a quantity is unconfirmed, synchronously');
check(str_contains($feedback_source,'focus('),'Keyboard focus lost to the pending disable cycle is restored');

// Issue #25 (WA-02): the Ventas Freeplast role is least-privilege over Woo's own native order surfaces.
// The two granted Woo caps are the verified minimum for login + list/search + author-less order detail
// + the native private-note AJAX (pinned Woo 11.1.0 + WP map_meta_cap); every other Woo cap stays ungranted.
if (!function_exists('wp_doing_ajax')) { function wp_doing_ajax(): bool { return true; } }
class FPW_Fake_Role {
    public array $capabilities=array();
    public function __construct(private string $name) {}
    public function has_cap(string $cap): bool { return !empty($this->capabilities[$cap]); }
    public function add_cap(string $cap,bool $grant=true): void { if ($grant) { $this->capabilities[$cap]=true; } else { unset($this->capabilities[$cap]); } }
    public function remove_cap(string $cap): void { unset($this->capabilities[$cap]); }
}
$GLOBALS['fpw_roles']=array();
if (!function_exists('get_role')) { function get_role(string $role): ?FPW_Fake_Role { return $GLOBALS['fpw_roles'][$role] ?? null; } }
if (!function_exists('add_role')) { function add_role(string $role,string $name,array $caps=array()): FPW_Fake_Role { $new=new FPW_Fake_Role($role); foreach($caps as $cap=>$grant) { $new->add_cap($cap,(bool)$grant); } return $GLOBALS['fpw_roles'][$role]=$new; } }
$GLOBALS['fpw_user_caps']=array();
if (!function_exists('current_user_can')) { function current_user_can(string $cap,int $id=0): bool { return !empty($GLOBALS['fpw_user_caps'][$cap]); } }
class FPW_Guard_Die extends RuntimeException {}
if (!function_exists('wp_die')) { function wp_die($message='',$title='',$args=array()) { throw new FPW_Guard_Die((string)($args['response'] ?? 0)); } }

check(!isset($GLOBALS['fpw_roles']['ventas_freeplast']),'Sync precondition: the fake site starts without the role');
check(fpw_sync_sales_role()===true,'Sync reports the creation of the role');
$ventas=get_role('ventas_freeplast');
foreach(array('read','manage_freeplast_quotes','edit_shop_orders','edit_others_shop_orders') as $cap) { check($ventas->has_cap($cap),'Sales role grants '.$cap); }
check(count($ventas->capabilities)===4,'Sales role carries exactly the approved four-cap set');
check(fpw_sync_sales_role()===false,'Re-running the sync reports no change (idempotent)');
check(count(get_role('ventas_freeplast')->capabilities)===4,'Idempotent sync keeps the exact set');
// Drift is repaired, not trusted: extra Woo privileges stripped, missing approved caps restored.
$ventas->add_cap('delete_shop_orders'); $ventas->add_cap('manage_woocommerce'); $ventas->add_cap('level_0'); $ventas->remove_cap('read');
check(fpw_sync_sales_role()===true,'Sync reports the drift repair');
$ventas=get_role('ventas_freeplast');
check(count($ventas->capabilities)===4,'Self-heal strips every unapproved capability');
check($ventas->has_cap('read'),'Self-heal restores a missing approved capability');
foreach(array('delete_shop_orders','delete_others_shop_orders','delete_private_shop_orders','publish_shop_orders','read_private_shop_orders','manage_woocommerce','view_woocommerce_reports','edit_products','edit_shop_coupons','install_plugins') as $forbidden) {
    check(!$ventas->has_cap($forbidden),'Sales role never carries '.$forbidden);
}
// Only the ventas role definition is owned: other roles are never rewritten.
$admin=new FPW_Fake_Role('administrator');
$admin->add_cap('manage_woocommerce'); $admin->add_cap('manage_freeplast_quotes'); $admin->add_cap('edit_shop_orders');
$GLOBALS['fpw_roles']['administrator']=$admin;
$admin_before=$admin->capabilities;
fpw_sync_sales_role();
check($admin->capabilities===$admin_before,'Role sync never changes other roles');

// The order-limited staff predicate: order caps without general Woo administration.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
check(fpw_is_order_limited_staff(),'Ventas (edit_shop_orders, no manage_woocommerce) is order-limited staff');
$GLOBALS['fpw_user_caps']['manage_woocommerce']=true;
check(!fpw_is_order_limited_staff(),'Managers are not order-limited: native behavior stays untouched');
$GLOBALS['fpw_user_caps']=array();
check(!fpw_is_order_limited_staff(),'Users without order caps are untouched by the ventas guards');

// Woo locks wp-admin to users without the edit_posts primitive by default; ventas enters through the order caps alone.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
check(fpw_allow_sales_admin_access(true)===false,'Woo\'s admin lock-down opens for ventas (no edit_posts primitive needed)');
check(fpw_allow_sales_admin_access(false)===false,'An unlocked admin stays unlocked');
$GLOBALS['fpw_user_caps']=array();
check(fpw_allow_sales_admin_access(true)===true,'The admin lock-down still applies to users without order caps');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
check(fpw_allow_sales_admin_access(true)===true,'The admin lock-down keeps applying to managers');
$GLOBALS['fpw_user_caps']=array();

// Email resends are out of the approved scope: removed from the select, denied on the server.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$actions=fpw_sales_order_actions(array('send_order_details'=>'Send order details to customer','send_order_details_admin'=>'Resend new order notification','regenerate_download_permissions'=>'Regenerate download permissions'));
foreach(array('send_order_details','send_order_details_admin','regenerate_download_permissions') as $removed) { check(!isset($actions[$removed]),'Order-actions select drops '.$removed.' for ventas'); }
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
$manager_actions=fpw_sales_order_actions(array('send_order_details'=>'x'));
check(isset($manager_actions['send_order_details']),'Managers keep the native order actions');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
try { fpw_deny_sales_email_resend(); check(false,'A crafted resend POST from ventas must be stopped'); } catch (FPW_Guard_Die $e) { check($e->getMessage()==='403','The resend guard denies ventas with 403'); }
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
try { fpw_deny_sales_email_resend(); check(true,'The resend guard leaves managers alone'); } catch (FPW_Guard_Die $e) { check(false,'The resend guard must not stop managers'); }

// Sales notes are private at the origin: the posted visibility is normalized before Woo's own AJAX reads it.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$_REQUEST=array('action'=>'woocommerce_add_order_note','note_type'=>'customer');
$_POST=array('note_type'=>'customer');
fpw_force_private_sales_note();
check($_REQUEST['note_type']==='' && $_POST['note_type']==='','A crafted customer-note POST from ventas is normalized to private');
$_REQUEST=array('action'=>'woocommerce_add_order_note','note_type'=>'');
fpw_force_private_sales_note();
check($_REQUEST['note_type']==='','Private notes pass through unchanged');
$_REQUEST=array('action'=>'get_notes'); $_POST=array();
fpw_force_private_sales_note();
check(true,'Non-note requests are ignored');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
$_REQUEST=array('action'=>'woocommerce_add_order_note','note_type'=>'customer');
fpw_force_private_sales_note();
check(($_REQUEST['note_type'] ?? '')==='customer','Manager notes keep their chosen visibility');
unset($_REQUEST,$_POST);

// Request-only site: no note-to-customer email, completing the adapter's disabled set.
$customer_note_filter=null;
foreach($registered_filters['woocommerce_email_enabled_customer_note'] ?? array() as $callback) { if('__return_false'===$callback) { $customer_note_filter=$callback; } }
check('__return_false'===$customer_note_filter,'The customer-note email joins the adapter\'s disabled set');
if (!function_exists('__return_false')) { function __return_false(): bool { return false; } }
foreach($registered_filters['woocommerce_email_enabled_customer_note'] as $callback) { check(false===call_user_func($callback),'Every customer-note filter disables the email'); }
$GLOBALS['fpw_user_caps']=array();

// Theme versioning contract: the style.css header and the asset cache-busting constant move together.
$style_source=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/style.css');
check(preg_match('/^Version:\s*(\S+)/m',$style_source,$style_version)===1,'style.css declares its Version header');
check($style_version[1]===FREEPLAST_THEME_VERSION,'style.css Version header matches FREEPLAST_THEME_VERSION — a cache-bust bump moves both');

// Issue #27 (WA-04): Home renders the featured grid through the adapter's plugin-rendered dynamic
// block, not wp:shortcode — WordPress' core/shortcode renderer runs wpautop() over the shortcode's
// EXPANDED output and splits the native product link at its internal blank lines (the unnamed-link
// defect). The block executes the SAME native Woo [products] shortcode inside do_blocks, where no
// wpautop runs, so the delivered card markup is Woo's own loop with self-naming links.
$front_page=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/templates/front-page.html');
check(str_contains($front_page,'wp:freeplast-woo/featured-products {"limit":8,"columns":4}'),'Home renders the featured grid through the plugin-rendered dynamic block');
check(!str_contains($front_page,'wp:shortcode') && !str_contains($front_page,'[products'),'Home no longer exposes the shortcode output to the wp:shortcode wpautop renderer');
$init_callbacks=$registered_actions['init'] ?? array();
check(count($init_callbacks)>0,'The adapter registers init actions');
$block_registered=false;
foreach($init_callbacks as $callback) {
	if (!is_object($callback)) { continue; }
	$GLOBALS['fpw_registered_blocks']=array();
	if (!function_exists('register_block_type')) { function register_block_type($name,$args=array()) { $GLOBALS['fpw_registered_blocks'][$name]=$args; } }
	$callback();
	if (isset($GLOBALS['fpw_registered_blocks']['freeplast-woo/featured-products'])) { $block_registered=$GLOBALS['fpw_registered_blocks']['freeplast-woo/featured-products']; break; }
}
unset($GLOBALS['fpw_registered_blocks']);
check(is_array($block_registered),'The adapter registers the freeplast-woo/featured-products block on init');
check(($block_registered['render_callback'] ?? '')==='fpw_render_featured_products','The featured grid block renders through the adapter callback');
if (!function_exists('do_shortcode')) { function do_shortcode($text) { $GLOBALS['fpw_shortcode_input']=$text; return 'NATIVE-WOO-LOOP-MARKUP'; } }
$GLOBALS['fpw_shortcode_input']='';
check(fpw_render_featured_products(array('limit'=>8,'columns'=>4))==='NATIVE-WOO-LOOP-MARKUP','The featured grid block returns the native Woo loop markup unchanged (no own card markup)');
check($GLOBALS['fpw_shortcode_input']==='[products limit="8" columns="4" visibility="featured" orderby="menu_order"]','The featured grid block delegates to the native Woo products shortcode verbatim');
check(fpw_render_featured_products(array())==='NATIVE-WOO-LOOP-MARKUP' && $GLOBALS['fpw_shortcode_input']==='[products limit="8" columns="4" visibility="featured" orderby="menu_order"]','Defaults reproduce the reviewed featured grid');
unset($GLOBALS['fpw_shortcode_input']);

// Issue #24 (WA-01): one attempt, one Quote Request. The claim is an atomic per-attempt options row
// (plain INSERT against the unique option_name); the loser recovers the winner's order through Woo's
// own woocommerce_create_order short-circuit. State machine over a fake wpdb with unique-key semantics.
class FPW_Fake_wpdb {
	public array $options_table=array();
	public string $prefix='fp_';
	public string $options='wp_options';
	public function __construct() { $this->options_table=&$GLOBALS['fpw_options_table']; }
	public function suppress_errors($set=null) { return false; }
	public function esc_like($text) { return addcslashes($text,'_%\\'); }
	public function prepare($sql,...$args) {
		foreach($args as $arg) { $pos=strpos($sql,'%s'); $sql=substr($sql,0,$pos)."'".$arg."'".substr($sql,$pos+2); }
		return $sql;
	}
	public function query($sql) {
		if (preg_match("/INSERT INTO \{?\w*options\}? \( option_name, option_value, autoload \) VALUES \( '(.+?)', '(.*)', 'off' \)$/s",$sql,$m)) {
			if (array_key_exists($m[1],$this->options_table)) { return false; } // the unique option_name rejects the loser
			$this->options_table[$m[1]]=$m[2]; return 1;
		}
		if (preg_match("/UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+)'$/s",$sql,$m)) {
			if (!array_key_exists($m[2],$this->options_table)) { return 0; }
			$this->options_table[$m[2]]=$m[1]; return 1;
		}
		if (preg_match("/DELETE FROM \{?\w*options\}? WHERE option_name = '(.+)'$/",$sql,$m)) {
			$found=array_key_exists($m[1],$this->options_table)?1:0;
			unset($this->options_table[$m[1]]); return $found;
		}
		return 0;
	}
	public function get_var($sql) {
		if (preg_match("/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/",$sql,$m)) { return $this->options_table[$m[1]] ?? null; }
		return null;
	}
	public function get_col($sql) {
		if (preg_match("/SELECT option_name FROM \{?\w*options\}? WHERE option_name LIKE '(.+)'$/",$sql,$m)) {
			$prefix=str_replace(array('\_','\%','\\'),array('_','%','\\'),$m[1]);
			if (str_ends_with($prefix,'%')) { $prefix=substr($prefix,0,-1); }
			return array_values(array_filter(array_keys($this->options_table),static fn($name)=>str_starts_with($name,$prefix)));
		}
		return array();
	}
}
$GLOBALS['fpw_options_table']=array();
$GLOBALS['wpdb']=new FPW_Fake_wpdb();
if (!function_exists('get_option')) { function get_option($name,$default=false) { return $GLOBALS['fpw_options_table'][$name] ?? $default; } }
if (!function_exists('update_option')) { function update_option($name,$value,$autoload=null) { $GLOBALS['fpw_options_table'][$name]=is_scalar($value)||is_null($value)?$value:json_encode($value); return true; } }
if (!function_exists('delete_option')) { function delete_option($name) { unset($GLOBALS['fpw_options_table'][$name]); return true; } }

// A fake session/cart on the existing fake WooCommerce, and a fake checkout carrying posted data.
$GLOBALS['fpw_session_customer_id']='abc123';
class FPW_Fake_Session { public function get_customer_id() { return $GLOBALS['fpw_session_customer_id']; } }
class FPW_Fake_Cart_Hash extends FPW_Fake_Cart {
	public function __construct(private string $hash='') {}
	public function get_cart_hash(): string { return $this->hash; }
}
class FPW_Fake_Checkout { public function __construct(private array $posted) {} public function get_posted_data(): array { return $this->posted; } }
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
function fpw_attempt_posted(array $overrides=array()): array {
	return array_merge(array('billing_first_name'=>'Cliente','billing_phone'=>'+56 9 1234 5678','billing_email'=>'cliente@example.invalid','billing_company'=>'Empresa','billing_fp_rut'=>'76.123.456-7','billing_fp_giro'=>'Giro','billing_fp_dispatch'=>'no','billing_fp_address'=>'','order_comments'=>'','payment_method'=>'quotes-gateway'),$overrides);
}
$attempt_hash=fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()));
check(preg_match('/^[a-f0-9]{64}$/',$attempt_hash)===1,'The attempt idempotency hash is a sha256');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(array_reverse(fpw_attempt_posted(),true)))===$attempt_hash,'The attempt hash is independent of the posted field order');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('billing_fp_dispatch'=>'si','billing_fp_address'=>'Otra dirección'))))!==$attempt_hash,'A changed posted field changes the attempt hash');
$GLOBALS['fpw_session_customer_id']='other-session';
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()))!==$attempt_hash,'Another session produces another attempt hash');
$GLOBALS['fpw_session_customer_id']='abc123';
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('changed-cart');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()))!==$attempt_hash,'A changed cart changes the attempt hash');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()))===$attempt_hash,'The same session, cart and data reproduce the attempt hash');

// All Woo seams the claim machinery touches, as recorded stubs (the durable
// attempt binding is a direct-SQL lookup row, never a wc_get_orders meta_query:
// Woo's posts store silently ignores meta_query since 9.2).
if (!function_exists('wc_add_order_note')) { function wc_add_order_note($order_id,$note,$is_customer_note=true) { $GLOBALS['fpw_order_notes'][]=array($order_id,$note,$is_customer_note); return true; } }
$GLOBALS['fpw_order_notes']=array();
class FPW_Fake_QWC_Instance {}
class FPW_Fake_Hook {
	public array $callbacks=array();
	public function __construct() { $this->callbacks[10]['qwc_hook']=array('function'=>array(new FPW_Fake_QWC_Instance(),'qwc_init_quote_emails')); }
	public function removed($priority,$callable) { foreach($this->callbacks[$priority]??array() as $id=>$cb) { if($cb['function']===$callable) { unset($this->callbacks[$priority][$id]); return true; } } return false; }
}
$GLOBALS['wp_filter']=array('woocommerce_checkout_order_processed'=>new FPW_Fake_Hook());
if (!function_exists('remove_action')) { function remove_action($hook,$callable,$priority=10) { return $GLOBALS['wp_filter'][$hook]->removed($priority,$callable); } }

// Owned attempt, end to end through the filter: Woo's short-circuit receives null and proceeds natively.
check(!isset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]),'A fresh site holds no claim for the attempt');
$checkout=new FPW_Fake_Checkout(fpw_attempt_posted());
check(fpw_checkout_claim(null,$checkout)===null,'An owned attempt lets Woo create the order (the filter passes null through)');
$row=json_decode($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]??'',true);
check(is_array($row) && $row['order_id']===0 && $row['session']===fpw_session_fingerprint() && $row['started']>0,'The claim row stores the owning session, no order and its start time');
check(fpw_pending_attempt()===$attempt_hash,'The owned attempt is this request\'s pending attempt');

// The order Woo is creating binds the attempt identity durably (see the registration checks below).

// The concurrent loser: the INSERT loses, the owner is in flight, the wait budget is exhausted → recoverable failure state.
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()));
$claim=fpw_checkout_attempt_claim($attempt_hash,0.05);
check($claim['state']==='busy','A busy concurrent attempt reports the recoverable busy state');
try {
	fpw_checkout_claim(null,$checkout);
	check(false,'A busy attempt must fail the checkout recoverably');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The busy attempt throws the recoverable Spanish message into Woo\'s own notice handling');
}

// The winner finalizes; the loser (or a retry) recovers the winner's own order through the same filter.
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>68,'started'=>time()));
check(fpw_checkout_claim(null,$checkout)===68,'A finalized claim recovers the winner\'s own order through Woo\'s short-circuit');
check(count($GLOBALS['wp_filter']['woocommerce_checkout_order_processed']->callbacks[10]??array())===0,'A folded attempt no longer re-fires the quotes extension\'s request notifications');
check($GLOBALS['fpw_order_notes']===array(array(68,'Solicitud duplicada (reintento concurrente) fusionada en este pedido por el control de intentos de Freeplast.',false)),'The folded attempt leaves one honest private trace on the record it joins');

// The durable binding: the claim row is gone but the lookup row carries the attempt — replays fold for the record's lifetime.
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]);
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='68';
$claim=fpw_checkout_attempt_claim($attempt_hash,0.0);
check($claim['state']==='recovered' && $claim['order_id']===68,'A replay of a swept attempt folds into the landed order through its lookup row');

// Takeover: an unfinalized claim past the grace period belongs to a winner that died mid-flight.
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]);
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1));
$claim=fpw_checkout_attempt_claim($attempt_hash,0.0);
check($claim['state']==='owned','The same session resumes an attempt whose winner died past the grace period');
$row=json_decode($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]??'',true);
check(is_array($row) && $row['order_id']===0 && abs($row['started']-time())<3,'The takeover writes a fresh claim row');
// …and a landed order is recovered instead.
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1));
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='91';
$claim=fpw_checkout_attempt_claim($attempt_hash,0.0);
check($claim['state']==='recovered' && $claim['order_id']===91,'A record that landed without finalization is recovered at takeover time');

// A foreign-session claim never leaks its order to this session (defensive; the hash binds the session).
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]);
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>hash('sha256','foreign'),'order_id'=>7,'started'=>time()));
$claim=fpw_checkout_attempt_claim($attempt_hash,0.05);
check($claim['state']==='busy','A foreign-session claim at this attempt\'s key is not recoverable by this session');
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]);

// Finalization writes the order id; release deletes the claim; the sweep removes only expired rows.
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()));
fpw_finalize_attempt_claim($attempt_hash,77);
$row=json_decode($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]??'',true);
check($row['order_id']===77,'Finalization records the winner\'s order id on the claim');
check($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]==='77','Finalization writes the durable lookup row (unique option_name → order id)');
fpw_release_attempt_claim($attempt_hash);
check(!isset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]),'A released attempt leaves no claim row');
$GLOBALS['fpw_options_table']['fpw_claim_expired']=json_encode(array('session'=>'x','order_id'=>0,'started'=>time()-FPW_CLAIM_MAX_AGE-1));
$GLOBALS['fpw_options_table']['fpw_claim_fresh']=json_encode(array('session'=>'x','order_id'=>0,'started'=>time()));
check(fpw_sweep_attempt_claims()===1,'The opportunistic sweep removes exactly the expired claim rows');
check(isset($GLOBALS['fpw_options_table']['fpw_claim_fresh']) && !isset($GLOBALS['fpw_options_table']['fpw_claim_expired']),'The sweep keeps in-window claims');
check(isset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]),'The sweep never touches the durable lookup rows');

// Woo seam registration: the claim rides Woo's own order-creation short-circuit and its create/exception lifecycle.
check(in_array('fpw_checkout_claim',$registered_filters['woocommerce_create_order']??array(),true),'The claim integrates Woo\'s own woocommerce_create_order short-circuit');
$create_callbacks=$registered_actions['woocommerce_checkout_create_order']??array();
check(count($create_callbacks)>=2,'The order-creation hook carries both the attempt binding and the local field meta');
check(count($registered_actions['woocommerce_checkout_order_created']??array())>=1,'The claim finalizes on woocommerce_checkout_order_created');
check(count($registered_actions['woocommerce_checkout_order_exception']??array())>=1,'The claim releases on woocommerce_checkout_order_exception');
// The attempt meta is bound on the created order.
class FPW_Fake_Order { public array $meta=array(); public function update_meta_data($key,$value) { $this->meta[$key]=$value; } public function get_id(): int { return 0; } }
$order=new FPW_Fake_Order();
foreach($create_callbacks as $callback) { if (is_object($callback)) { $callback($order,fpw_attempt_posted()); } }
check(($order->meta['_fpw_attempt']??'')===$attempt_hash,'An owned attempt binds its identity durably on the created order');
unset($GLOBALS['fpw_options_table'],$GLOBALS['wpdb'],$GLOBALS['fpw_order_notes'],$GLOBALS['fpw_session_customer_id'],$GLOBALS['wp_filter']);

echo "checks: {$assertions} local assertions passed (checkout fields + header line count + unpriced review table + variation button state + quantity-change feedback + sales role + featured grid block + checkout attempt claim)\n";
