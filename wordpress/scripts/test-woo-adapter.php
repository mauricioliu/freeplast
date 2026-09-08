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
    public array $messages=array();
    public function add($code,$message,$data=null) { $this->codes[]=$code; $this->messages[]=$message; }
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
// Issue #35: the attempt-identity field is registered so Woo's own checkout
// normalization (get_posted_data) keeps it — in its own fieldset, which none of
// Woo's rendered fieldsets (billing/shipping/account/order) shows as a form row.
check(isset($fields['fpw']['fpw_attempt']),'The attempt-identity field is registered for Woo\'s own checkout normalization (issue #35)');
foreach(array('billing','shipping','account','order') as $rendered_fieldset) { check(!isset($fields[$rendered_fieldset]['fpw_attempt']),'The attempt field never renders as a native form row ('.$rendered_fieldset.')'); }
check(($fields['fpw']['fpw_attempt']['required'] ?? true)===false,'The attempt field carries no requirement at Woo\'s own validation layer');
check(!in_array('fpw_attempt',fpw_draft_keys(),true),'The attempt token never round-trips the draft-value restore: it is identity, not customer data');
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
check(substr_count($header,'fpw-basket-count')===1,'The A · Directa header pill carries the count span (the reference menu row shows no count)');
check(substr_count($header,'{{FREEPLAST_BASKET_COUNT}}')===1,'The header pill server-renders the count token');
check(substr_count($header,'class="count"')===1,'The count badge wrapper keeps A styling across the adapter fragment swap');
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
    public function single_add_to_cart_text(): string { return 'Agregar a Cotización'; }
}
$product=new FPW_Fake_Variable_Product();
ob_start(); require $variation_button; $button_html=ob_get_clean();
check(str_contains($button_html,'wc-variation-selection-needed disabled'),'Initial availability classes render at the origin, matching Woo\'s own variation form state');
check(str_contains($button_html,'aria-disabled="true"'),'The initial delivered state exposes aria-disabled (no false enable)');
check(str_contains($button_html,'aria-describedby="fp-variation-hint-25"'),'The button links its associated instruction through aria-describedby');
check(str_contains($button_html,'Selecciona Color para agregar este producto a Productos a Cotizar.'),'The visible instruction names the real variation attribute');
check(!preg_match('/<button[^>]*\sdisabled[\s=>]/',$button_html),'No real disabled attribute: the no-JS flow stays operable and server validation owns rejection');
check(str_contains($button_html,'Agregar a Cotización'),'The accessible name is the reviewed visible label');
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
check(str_contains($feedback_source,'return operationsPending();') && str_contains($feedback_source,'store.getItemsPendingQuantityUpdate().length > 0 || inflight > 0'),'CTA and verdict consult the same store/response-processing predicate; behavior is tested with streamed Responses');
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

// Issue #33 (SP-03 + ST-01, follow-up of #25/WA-02): the restricted session reads and
// annotates — it never writes the record. The two order caps that open the native editor
// and its note AJAX also pass Woo's own nonce + capability checks on EVERY record-mutation
// surface: the editor save (contact data, status, order actions — posts store and HPOS),
// the orders-list bulk actions, the quick-status AJAX whose nonce Woo itself renders for
// this role, the items/fees/taxes/refunds/downloads AJAX family, the REST orders API (its
// permission map keys 'edit'/'batch' on the same edit_others cap) and the note-deletion
// AJAX. The guards key on the capability boundary alone — they verify no nonce — so a
// request with valid session and valid nonces is still denied, and every denial is
// attributable to permissions, never to the CSRF check.
$GLOBALS['fpw_posts']=array();
if (!function_exists('get_post')) { function get_post($id) { return isset($GLOBALS['fpw_posts'][(int)$id]) ? $GLOBALS['fpw_posts'][(int)$id] : null; } }
$GLOBALS['fpw_posts'][10]=(object)array('post_type'=>'shop_order');
$GLOBALS['fpw_posts'][11]=(object)array('post_type'=>'page');
function fpw_guard_denies(): string {
	try { fpw_deny_sales_record_mutation(); return 'pass'; } catch (FPW_Guard_Die $e) { return $e->getMessage(); }
}
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);

// The front door (admin_init runs before any save machinery) denies the mutation AJAX family.
$_REQUEST=array('action'=>'woocommerce_mark_order_status'); $_GET=array('status'=>'processing','order_id'=>10); $_POST=array();
check(fpw_guard_denies()==='403','The quick-status AJAX is denied for ventas — Woo itself renders its nonce for the role, so this is permissions, not CSRF');
$_REQUEST=array('action'=>'woocommerce_delete_order_note'); $_POST=array('note_id'=>5); $_GET=array();
check(fpw_guard_denies()==='403','A valid delete-order-note request from ventas is denied: the record history stays intact');
foreach (array('woocommerce_save_order_items','woocommerce_add_order_item','woocommerce_add_order_fee','woocommerce_add_order_shipping','woocommerce_add_order_tax','woocommerce_calc_line_taxes','woocommerce_add_coupon_discount','woocommerce_order_add_meta','woocommerce_order_delete_meta','woocommerce_remove_order_item','woocommerce_remove_order_coupon','woocommerce_remove_order_tax','woocommerce_refund_line_items','woocommerce_delete_refund','woocommerce_grant_access_to_download','woocommerce_revoke_access_to_download') as $items_action) {
	$_REQUEST=array('action'=>$items_action); $_POST=array('order_id'=>10); $_GET=array();
	check(fpw_guard_denies()==='403','The record-mutating items AJAX '.$items_action.' is denied for ventas');
}

// Issue #37 (SP-01 + ST-03): core note/metadata mutation routes, scoped to ORDER
// targets. WP maps edit_comment/delete_comment onto edit_post of the order
// (wp-includes/capabilities.php) and this role carries the order edit caps —
// so without the guard, core could rewrite or DELETE existing Sales Notes.
$GLOBALS['fpw_comments']=array();
if (!function_exists('get_comment')) { function get_comment($id) { return isset($GLOBALS['fpw_comments'][(int)$id]) ? $GLOBALS['fpw_comments'][(int)$id] : null; } }
$GLOBALS['fpw_comments'][21]=(object)array('comment_ID'=>21,'comment_post_ID'=>10,'comment_content'=>'NOTA ORIGINAL');   // an order note (Sales Note)
$GLOBALS['fpw_comments'][22]=(object)array('comment_ID'=>22,'comment_post_ID'=>11,'comment_content'=>'un comentario de página');
$GLOBALS['fpw_meta']=array();
if (!function_exists('get_metadata_by_mid')) { function get_metadata_by_mid($type,$id) { return isset($GLOBALS['fpw_meta'][(int)$id]) ? $GLOBALS['fpw_meta'][(int)$id] : null; } }
$GLOBALS['fpw_meta'][31]=(object)array('meta_id'=>31,'post_id'=>10,'meta_key'=>'_fp_submitted_details');
$GLOBALS['fpw_meta'][32]=(object)array('meta_id'=>32,'post_id'=>11,'meta_key'=>'page_field');
function fpw_note_guard_denies(): string {
	try { fpw_deny_sales_order_note_and_meta_mutation(); return 'pass'; } catch (FPW_Guard_Die $e) { return $e->getMessage(); }
}
$_REQUEST=array('action'=>'edit-comment','comment_ID'=>21,'content'=>'NOTA REESCRITA'); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','A valid edit-comment request on an ORDER note is denied before any write (map_meta_cap passes edit_post to this role)');
$_REQUEST=array('action'=>'delete-comment','id'=>21); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','A valid delete-comment request on an ORDER note is denied: Sales Notes are append-only');
$_REQUEST=array('action'=>'replyto-comment','comment_post_ID'=>10); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','replyto-comment on the order post is denied (it would append a core comment to the record)');
$_REQUEST=array('action'=>'add-meta','post_id'=>10,'metakeyinput'=>'x','metavalue'=>'y'); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','Core add-meta on the order post is denied for ventas');
$_REQUEST=array('action'=>'delete-meta','id'=>31); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','Core delete-meta on order metadata is denied for ventas');
// Scope: the same routes on NON-order targets pass untouched; managers and
// anonymous requests never meet the guard.
$_REQUEST=array('action'=>'edit-comment','comment_ID'=>22); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='pass','A page comment edit passes: the guard is scoped to ORDER targets, not to the route');
$_REQUEST=array('action'=>'delete-meta','id'=>32); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='pass','Page metadata deletion passes: unrelated capabilities stay unchanged');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
$_REQUEST=array('action'=>'edit-comment','comment_ID'=>21); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='pass','Managers keep the native comment routes');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
// The admin pages (admin_init runs before their bodies): comment.php WRITE
// actions and edit-comments.php bulk actions on order notes. The read-only
// editcomment VIEW stays allowed; every write trigger — including the
// canonical editedcomment POST field and the action2 bulk select — is denied
// with the handler's OWN target resolution, never a convenient field.
$GLOBALS['pagenow']='comment.php';
$_REQUEST=array('action'=>'editcomment','c'=>21); $_GET=array('action'=>'editcomment','c'=>21); $_POST=array();
check(fpw_note_guard_denies()==='pass','comment.php read-only editcomment VIEW of a note stays allowed');
$_REQUEST=array('action'=>'trash','c'=>21); $_GET=array('action'=>'trash','c'=>21); $_POST=array();
check(fpw_note_guard_denies()==='403','comment.php status write actions on an order note are denied before the page runs');
$_POST=array('action'=>'editedcomment','comment_ID'=>21,'content'=>'NOTA REESCRITA'); $_REQUEST=$_POST; $_GET=array();
check(fpw_note_guard_denies()==='403','comment.php editedcomment resolves its CANONICAL $_POST[comment_ID]: an order note edit is denied');
$_POST=array('action'=>'editedcomment','comment_ID'=>22,'c'=>21); $_REQUEST=$_POST; $_GET=array();
check(fpw_note_guard_denies()==='pass','editedcomment on a NON-order comment passes: c is not that handler\'s target field');
$GLOBALS['pagenow']='edit-comments.php';
$_REQUEST=array('action'=>'trash','delete_comments'=>array(21)); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','edit-comments.php bulk trash of an order note is denied');
$_REQUEST=array('action2'=>'delete','delete_comments'=>array(21)); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','edit-comments.php bulk via the BOTTOM action2 select is denied too');
$_REQUEST=array('action'=>'spam','delete_comments'=>array(22)); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='pass','Bulk actions on ordinary comments pass untouched');
$GLOBALS['pagenow']=null;
// Adversarial contradictory-field controls: a benign decoy field must never
// mask the order-targeted canonical field the native handler reads.
$_REQUEST=array('action'=>'delete-comment','comment_ID'=>22,'id'=>21); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','delete-comment resolves ONLY $_POST[id]: a benign comment_ID decoy cannot mask the order-note target');
$_REQUEST=array('action'=>'edit-comment','comment_ID'=>21,'id'=>22); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','edit-comment resolves ONLY $_POST[comment_ID]: a benign id decoy cannot mask the order-note target');
$_REQUEST=array('action'=>'replyto-comment','comment_ID'=>22,'comment_post_ID'=>10); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','replyto-comment resolves ONLY comment_post_ID: a benign comment_ID decoy cannot mask the order post');
$_REQUEST=array('action'=>'delete-meta','post_id'=>11,'id'=>31); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','delete-meta resolves ONLY the meta id→post: a benign post_id decoy cannot mask the order metadata target');
$_REQUEST=array('action'=>'add-meta','post_id'=>11,'meta'=>array('31'=>'x')); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','add-meta UPDATE branch resolves the posted meta ids: an order metadata rewrite is denied despite the benign post_id');
$_REQUEST=array('action'=>'add-meta','post_id'=>11,'meta'=>array('32'=>'x')); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='pass','add-meta on a page (both branches non-order) passes untouched');
// Lead red-gate 1 regressions: native absint() casting, core action-override
// precedence, and the WP_List_Table '-1' action fallback.
$_REQUEST=array('action'=>'edit-comment','comment_ID'=>'21junk'); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','edit-comment with comment_ID=21junk resolves like native absint(): the ORDER note 21 is the target');
$_REQUEST=array('action'=>'delete-meta','id'=>'31junk'); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','delete-meta with id=31junk resolves like native absint(): the ORDER metadata 31 is the target');
$_REQUEST=array('action'=>'delete-comment','id'=>'21junk'); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','delete-comment with id=21junk resolves like native absint(): the ORDER note 21 is the target');
$GLOBALS['pagenow']='comment.php';
$_POST=array('action'=>'editedcomment','comment_ID'=>22,'c'=>21); $_GET=array('dt'=>'trash'); $_REQUEST=array_merge($_GET,$_POST);
check(fpw_note_guard_denies()==='403','comment.php derives the EFFECTIVE action with core precedence: action=editedcomment + dt=trash is a TRASH of c=21 (the order note), not an edit of the benign comment_ID');
$_POST=array('action'=>'editedcomment','comment_ID'=>22,'deletecomment'=>1); $_REQUEST=array_merge($_GET,$_POST);
check(fpw_note_guard_denies()==='pass','deletecomment override with only a benign non-order comment_ID and no c passes (nothing order-targeted)');
$GLOBALS['pagenow']='edit-comments.php';
$_REQUEST=array('action'=>'-1','action2'=>'trash','delete_comments'=>array(21)); $_POST=$_REQUEST; $_GET=array();
check(fpw_note_guard_denies()==='403','The bulk family falls back to action2 when action is the WP_List_Table no-op -1, not only when empty');
$GLOBALS['pagenow']=null;
check(in_array('fpw_deny_sales_order_note_and_meta_mutation',$registered_actions['admin_init']??array(),true),'The core note/meta guard rides admin_init ahead of the core handlers');
$read_only_style_source=(string)file_get_contents(__DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
check(str_contains($read_only_style_source,'button.calculate-action,button.add-line-item,button.add-coupon,button.refund-items,a.edit-order-item,a.delete-order-item,a.delete-order-tax'),'The items editor\'s denied mutation controls (Recalculate, Add item(s), coupon, refund, per-line edit/delete, tax delete) are hidden for ventas (#37)');
check(str_contains($read_only_style_source,'#woocommerce-order-items div.edit{display:none!important}'),'The per-line EDIT blocks are hidden outright (keyboard-unreachable too) while the native view markup keeps values and quantities readable');
$ventas_guard_source=(string)file_get_contents(__DIR__.'/woo-ventas-guard.py');
check(str_contains($ventas_guard_source,'tax_recalc_allowed_when_guard_removed') && str_contains($ventas_guard_source,'core_note_edit_allowed_when_guard_removed') && str_contains($ventas_guard_source,'coupon_applied_when_guard_removed'),'The native guard-off positive controls for tax, core note rewrite and valid-coupon application EXIST in the operator script (native structural check)');
check(str_contains($ventas_guard_source,'identity_verified_before_mutating'),'The mutating guard-off mode is identity-gated to this run\'s synthetic fixture (native structural check)');
check(str_contains($ventas_guard_source,'order_item_qty[\\d+]') || substr_count($ventas_guard_source,'order_item_qty[')>=3,'The native tax payloads resolve the fixture\'s REAL line ids, not a hardcoded one (native structural check)');
check(str_contains($ventas_guard_source,'comment_php_read_view_allowed'),'The guarded comment.php probe asserts the READ view stays allowed — the write denial is the POST variant (native structural check)');
check(str_contains($ventas_guard_source,'def native_state') && str_contains($ventas_guard_source,'FREEPLAST_VENTAS_STATE_COMMAND'),'The operator compares complete native fixture snapshots through the disposable read-only CLI, not lossy HTML');
check(is_file(__DIR__.'/woo-ventas-guard-selftest.py') && str_contains((string)file_get_contents(__DIR__.'/woo-ventas-guard-selftest.py'),'runpy.run_path'),'The separately executed mocked regression runs the operator module control flow');
check(str_contains($ventas_guard_source,'FREEPLAST_VENTAS_RUN') && str_contains($ventas_guard_source,'ensure_fixture_identity'),'The mutating modes verify the harness-minted per-run binding before any mutation (native structural check)');
check(!str_contains($ventas_guard_source,"editor)), 1)"),'No line-id fallback to 1 remains in the native script');
$guarded_identity_pos=strpos($ventas_guard_source,'ensure_fixture_identity(ventas, ORDER, RUN_EMAIL)');
$guarded_note_pos=strpos($ventas_guard_source,"'action': 'woocommerce_add_order_note'");
check(is_int($guarded_identity_pos) && is_int($guarded_note_pos) && $guarded_identity_pos < $guarded_note_pos,'The guarded mode verifies identity BEFORE its first (allowed) note request — the control flow is real (native structural check)');

// The kept surfaces pass the front door untouched.
$_REQUEST=array('action'=>'woocommerce_get_order_details'); $_GET=array('order_id'=>10); $_POST=array();
check(fpw_guard_denies()==='pass','The items-editor read endpoints stay reachable for ventas');
$_REQUEST=array('action'=>'woocommerce_load_order_items'); $_POST=array('order_id'=>10);
check(fpw_guard_denies()==='pass','load_order_items stays reachable for ventas');
$_REQUEST=array('action'=>'woocommerce_add_order_note'); $_POST=array('post_id'=>10,'note'=>'x','note_type'=>'');
check(fpw_guard_denies()==='pass','The approved private-note flow passes the mutation front door');

// Editor saves in both storage modes: denied BEFORE anything is written. In the posts
// store WP core itself would rewrite the record row (including status) before Woo's save
// hooks fire, so the denial must happen at admin_init, not inside the metabox pipeline.
$_REQUEST=array('action'=>'editpost'); $_POST=array('action'=>'editpost','post_ID'=>10,'order_status'=>'wc-processing'); $_GET=array();
check(fpw_guard_denies()==='403','A valid posts-store editor save (contact + status) from ventas is denied before any write');
$_REQUEST=array('action'=>'editpost'); $_POST=array('action'=>'editpost','post_ID'=>11);
check(fpw_guard_denies()==='pass','Non-order records are left to WordPress itself');
$_REQUEST=array('action'=>'edit_order'); $_POST=array('action'=>'edit_order'); $_GET=array('page'=>'wc-orders');
check(fpw_guard_denies()==='403','A valid HPOS editor save from ventas is denied');

// Reading surfaces stay open.
$_GET=array('page'=>'wc-orders','action'=>'edit','id'=>10); $_POST=array(); $_REQUEST=array();
check(fpw_guard_denies()==='pass','Opening the editor to read stays allowed');
$_GET=array('page'=>'wc-orders'); $_POST=array(); $_REQUEST=array('post_type'=>'shop_order');
check(fpw_guard_denies()==='pass','List and search requests pass the front door');

// The guard keys on the capability boundary: anonymous requests pass through to Woo's own
// checks; managers (manage_woocommerce) keep the native behavior everywhere.
$GLOBALS['fpw_user_caps']=array();
$_REQUEST=array('action'=>'woocommerce_mark_order_status');
check(fpw_guard_denies()==='pass','Anonymous requests pass the ventas guard (native auth owns them)');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
check(fpw_guard_denies()==='pass','Managers keep the native quick-status AJAX');

// Bulk mutations (status changes, trash/delete/untrash, personal-data removal) pass Woo's
// own handler in both storage modes through one chokepoint, after its nonce + cap checks.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
try { fpw_deny_sales_bulk_actions(array(10)); check(false,'A ventas bulk action must be denied'); } catch (FPW_Guard_Die $e) { check($e->getMessage()==='403','Orders-list bulk mutations are denied for ventas (valid bulk nonce included)'); }
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
check(fpw_deny_sales_bulk_actions(array(10,12))===array(10,12),'A manager\'s bulk actions pass the chokepoint unchanged');

// The REST orders API maps 'edit'/'batch' onto the same cap the admin screens use — without
// the boundary a cookie-authed PUT /wc/v3/orders/{id} would rewrite the record.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
foreach (array('create','edit','delete','batch') as $rest_context) {
	check(fpw_deny_sales_rest_mutation(true,$rest_context)===false,'REST '.$rest_context.' on records is denied for ventas');
}
check(fpw_deny_sales_rest_mutation(true,'read')===true && fpw_deny_sales_rest_mutation(false,'read')===false,'REST reads pass through untouched for everyone');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
check(fpw_deny_sales_rest_mutation(true,'edit')===true,'A manager\'s REST order update keeps Woo\'s own verdict');

// The deep backstop: registered on Woo's own save pipeline (both storage modes run it after
// their nonce + capability checks and before any metabox save at priority 10+).
check(in_array('fpw_deny_sales_order_save',$registered_filters['woocommerce_process_shop_order_meta']??array(),true),'The save-pipeline backstop is registered on woocommerce_process_shop_order_meta');
check(in_array('fpw_deny_sales_record_mutation',$registered_actions['admin_init']??array(),true),'The mutation front door rides admin_init (before any save machinery)');
check(in_array('fpw_deny_sales_bulk_actions',$registered_filters['woocommerce_bulk_action_ids']??array(),true),'Bulk mutations pass the adapter chokepoint');
check(in_array('fpw_deny_sales_rest_mutation',$registered_filters['woocommerce_rest_check_permissions']??array(),true),'The REST permission boundary is registered');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
try { fpw_deny_sales_order_save(); check(false,'A ventas editor save must hit the backstop'); } catch (FPW_Guard_Die $e) { check($e->getMessage()==='403','The backstop denies the ventas save with 403'); }

// «Deja el registro sin cambios»: walk a fake save pipeline in registration order — the
// backstop (registered at priority 0 during plugin load) dies before any later write runs.
$wrote=false;
add_filter('woocommerce_process_shop_order_meta', static function($value) use (&$wrote) { $wrote=true; return $value; }, 40, 1);
try { apply_filters('woocommerce_process_shop_order_meta','',10); } catch (FPW_Guard_Die $e) {}
check($wrote===false,'Nothing is written: the backstop answers before any save callback (record unchanged)');

// Authorization-regression probes (issue #33 criterion): the suite must FAIL the world where
// the guard is removed or the improper cap is granted — never pass vacuously.
$registered_filters['woocommerce_process_shop_order_meta']=array_values(array_filter($registered_filters['woocommerce_process_shop_order_meta'],static function($cb){return 'fpw_deny_sales_order_save'!==$cb;}));
$wrote=false;
try { apply_filters('woocommerce_process_shop_order_meta','',10); } catch (FPW_Guard_Die $e) {}
check($wrote===true,'Regression probe: removing the guard lets the write through');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
array_unshift($registered_filters['woocommerce_process_shop_order_meta'],'fpw_deny_sales_order_save');  // priority 0 sorts first
$wrote=false;
try { apply_filters('woocommerce_process_shop_order_meta','',10); } catch (FPW_Guard_Die $e) {}
check($wrote===true,'Regression probe: granting manage_woocommerce reopens the save (the cap boundary IS the guard)');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$wrote=false;
try { apply_filters('woocommerce_process_shop_order_meta','',10); } catch (FPW_Guard_Die $e) {}
check($wrote===false,'Restored: the backstop denies again and the record stays unchanged');
$registered_filters['woocommerce_process_shop_order_meta']=array('fpw_deny_sales_order_save');

// UI honesty: the interface never offers what the server denies.
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$preview=fpw_sales_preview_status_actions(array('status'=>array('group'=>'Change status: ','actions'=>array('processing'=>array())),'edit'=>array()));
check(!isset($preview['status']) && isset($preview['edit']),'The order-preview quick-status buttons (which carry Woo\'s own nonce for the role) are removed for ventas');
$rows=fpw_sales_row_status_actions(array('processing'=>array(),'complete'=>array()));
check($rows===array(),'The list row status buttons are removed for ventas');
$bulk=fpw_sales_list_bulk_actions(array('edit'=>'x','mark_processing'=>'x','mark_completed'=>'x','trash'=>'x','untrash'=>'x','delete'=>'x','remove_personal_data'=>'x'));
check($bulk===array(),'The orders-list bulk select offers ventas no mutating action');
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
$bulk=fpw_sales_list_bulk_actions(array('mark_processing'=>'x','trash'=>'x'));
check(count($bulk)===2,'Managers keep the native bulk actions');
$preview=fpw_sales_preview_status_actions(array('status'=>array()));
check(isset($preview['status']),'Managers keep the preview quick-status buttons');
check(in_array('fpw_sales_preview_status_actions',$registered_filters['woocommerce_admin_order_preview_actions']??array(),true),'The preview actions filter is registered');
check(in_array('fpw_sales_row_status_actions',$registered_filters['woocommerce_admin_order_actions']??array(),true),'The row actions filter is registered');
check(in_array('fpw_sales_list_bulk_actions',$registered_filters['bulk_actions-edit-shop_order']??array(),true),'The list bulk filter is registered');
$GLOBALS['fpw_user_caps']=array();

// Theme versioning contract: the style.css header and the asset cache-busting constant move together.
$style_source=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/style.css');
check(preg_match('/^Version:\s*(\S+)/m',$style_source,$style_version)===1,'style.css declares its Version header');
check($style_version[1]===FREEPLAST_THEME_VERSION,'style.css Version header matches FREEPLAST_THEME_VERSION — a cache-bust bump moves both');

// Persistent navigation (A · Directa, issue #41): the compact header bar is
// sticky on the outer template part, clears the fixed admin toolbar natively
// and never re-creates the retired pill island or a second sticky surface.
// Source contracts only; these do not claim browser layout/scroll verification.
check(preg_match('/\.wp-site-blocks\s*>\s*header\.wp-block-template-part\s*\{([^}]+)\}/',$style_source,$sticky_header)===1,'Outer header template part owns the sticky rule');
check(str_contains($sticky_header[1],'position: sticky;'),'Outer header stays in flow and sticks across the page');
check(str_contains($sticky_header[1],'top: calc(var(--fp-admin-offset));'),'Sticky bar sits at the admin-toolbar offset exactly (A: top 0 on the public site)');
check(str_contains($sticky_header[1],'z-index: 50;'),'Sticky header stays above page content');
check(str_contains($sticky_header[1],'border-bottom: 1px solid var(--fp-chrome-line);'),'Sticky bar carries the A hairline border');
check(!str_contains($style_source,'fp-island') && !str_contains($style_source,'fp-burger') && !str_contains($style_source,'fp-sheet'),'The retired v6 island/burger/sheet chrome is fully retired from the stylesheet');
check(str_contains($style_source,'--fp-nav-h: 76px;'),'A compact header height 76px mobile');
check(preg_match('/@media\s*\(min-width:\s*1000px\)\s*\{\s*:root\s*\{\s*--fp-nav-h:\s*84px;/',$style_source)===1,'A header height 84px from 1000px');
check(str_contains($style_source,'--fp-admin-offset: 0px;'),'Small-screen scrolling admin toolbar reserves no persistent gap');
check(preg_match('/@media\s*\(min-width: 601px\)\s*\{\s*:root\s*\{\s*--fp-admin-offset: var\(--wp-admin--admin-bar--height, 0px\);/',$style_source)===1,'Fixed admin toolbar offset uses WordPress native height above 600px');
check(preg_match('/scroll-padding-top:[^;]+var\(--fp-admin-offset\)/',$style_source)===1,'Anchor and focus scroll clearance includes the admin toolbar');
foreach(glob(__DIR__.'/../wp-content/themes/freeplast/templates/*.html') as $template_file){
	check(str_starts_with(trim(file_get_contents($template_file)),'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'),'Top-level semantic header on '.basename($template_file));
}

// Issue #41: the A · Directa shared chrome contract — real destinations,
// native dialogs, real Manrope load, no review-only prototype tooling.
$footer=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/parts/footer.html');
foreach(array(
	array($header,'class="header-inner"','A header inner row'),
	array($header,'class="brand" href="/"','Brand navigates the real Home destination'),
	array($header,'href="/tienda/"','Catálogo real destination'),
	array($header,'href="/nosotros/"','Nosotros stays available in the shared nav'),
	array($header,'href="/contacto/"','Contacto real destination'),
	array($header,'class="header-selection" href="/cotizacion/"','Productos a Cotizar links the native cart page'),
	array($header,'aria-label="Abrir menú"','Menu trigger is a named control'),
	array($header,'aria-expanded="false"','Menu trigger exposes its collapsed state'),
	array($header,'aria-controls="fp-menu"','Menu trigger controls the menu dialog'),
	array($header,'data-fp-dialog="fp-help"','Help opens the shared help dialog'),
	array($header,'Cómo cotizar','Help carries the reference label'),
	array($header,'Ayuda para cotizar','Mobile menu keeps the reference help row'),
	array($header,'Venta mayorista · Sin registro ni pago en línea.','Menu keeps the reference wholesale note'),
	array($header,'ventas@freeplast.cl','Help shows the authoritative email'),
	array($header,'+56 9 6844 4265','Help shows the authoritative phone'),
	array($header,'Lun–vie · 09:00–13:00 y 14:00–18:00','Help shows the authoritative schedule'),
	array($footer,'class="site-footer"','A footer band'),
	array($footer,'class="footer-content"','A footer content row'),
	array($footer,'href="/politica-de-privacidad/"','Privacy keeps its real destination in the footer'),
	array($footer,'¿Necesitas ayuda?','Footer help keeps the reference label'),
	array($footer,'Productos plásticos. Nuevas posibilidades.','Footer keeps the reference tagline'),
) as $contract){
	check(str_contains($contract[0],$contract[1]),$contract[2]);
}
foreach(array('prototype-bar','prototype-notice','data-scenario','data-switch','Escenarios','state inspector','DEMO ·') as $review_only){
	check(!str_contains($header,$review_only) && !str_contains($footer,$review_only),'No review-only prototype element reaches the chrome: '.$review_only);
}
check(preg_match('/@font-face\s*\{[^}]*font-family:\s*Manrope;[^}]*manrope\.woff2/',$style_source)===1,'Manrope is loaded from the local variable font file');
check(is_file(__DIR__.'/../wp-content/themes/freeplast/assets/fonts/manrope.woff2'),'The Manrope woff2 file ships with the theme');
check(is_file(__DIR__.'/../wp-content/themes/freeplast/assets/fonts/OFL.txt'),'The Manrope OFL license ships with the theme');
check(str_contains($style_source,'--fp-blue-soft: #eeedf8;') && str_contains($style_source,'--fp-chrome-line: #dedfe6;') && str_contains($style_source,'--fp-chrome-muted: #60626d;'),'A · Directa chrome palette tokens are registered');
$navjs=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/assets/js/nav.js');
check(str_contains($navjs,'showModal'),'Chrome dialogs use the native showModal top layer');
check(!str_contains($navjs,'localStorage') && !str_contains($navjs,'sessionStorage'),'Chrome script keeps no parallel state');

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

// Card add-to-cart (2026-09-07): the reviewed label is «Agregar a Cotización»
// on every surface — loop cards (add_to_cart_text), aria descriptions and the
// product sheet (single_add_to_cart_text, which Woo's own templates call
// directly, bypassing add_to_cart_text). Every simple-product card ships Woo's
// OWN quantity input beside the anchor: the loop filter wraps — never
// rewrites — Woo's native anchor markup, and the AJAX quantity stays the
// anchor's own data-quantity attribute (pinned Woo 11.1.0 add-to-cart.js
// prefers the DOM dataset), mirrored from the input by the theme script.
$plugin_source=file_get_contents(__DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
check(str_contains($plugin_source,'Agregar a Cotización'),'The reviewed button label is «Agregar a Cotización»');
check(!str_contains($plugin_source,'Agregar a Productos a Cotizar'),'The retired button label no longer ships from the adapter');
check(str_contains($plugin_source,'woocommerce_product_single_add_to_cart_text'),'The product sheet button carries the label through Woo\'s own single-button filter');
check(str_contains($plugin_source,'woocommerce_loop_add_to_cart_link') && str_contains($plugin_source,'woocommerce_quantity_input'),'The card selector renders Woo\'s own quantity input through the native loop filter');
if (!class_exists('FPW_Fake_Simple_Product')) {
    class FPW_Fake_Simple_Product {
        public function __construct(private int $id) {}
        public function is_type(string $type): bool { return 'simple'===$type; }
        public function is_purchasable(): bool { return true; }
        public function is_in_stock(): bool { return true; }
        public function get_id(): int { return $this->id; }
    }
}
$anchor='<a href="/?add-to-cart=22" data-quantity="1" class="add_to_cart_button ajax_add_to_cart">Agregar a Cotización</a>';
ob_start();
$wrapped=apply_filters('woocommerce_loop_add_to_cart_link',$anchor,new FPW_Fake_Simple_Product(22),array());
$echoed_input=ob_get_clean();
check(str_contains($wrapped,'<div class="fpw-loop-add" data-fpw-loop-add>'),'Simple cards wrap the native anchor in the selector container');
check(str_contains($wrapped,$anchor),'The native anchor markup passes through untouched by the filter');
check(str_contains($echoed_input,'class="qty"'),'The quantity input renders through Woo\'s own hook beside the anchor');
ob_start();
$variable_anchor='<a href="/producto/x/" class="product_type_variable add_to_cart_button">Elegir color</a>';
$passed=apply_filters('woocommerce_loop_add_to_cart_link',$variable_anchor,new FPW_Fake_Variable_Product(),array());
check(ob_get_clean()==='' && $passed===$variable_anchor,'Variable cards keep the native link with no selector');
$loop_quantity_script=__DIR__.'/../wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js';
check(is_file($loop_quantity_script),'The theme owns the card quantity mirror script');
$loop_quantity_source=file_get_contents($loop_quantity_script);
check(str_contains($functions_source,'loop-add-to-cart-quantity.js'),'The card quantity mirror script ships with the theme');
check(str_contains($loop_quantity_source,'data-quantity'),'The mirror writes the attribute the native AJAX reads (data-quantity)');
check(str_contains($loop_quantity_source,'[data-fpw-loop-add]'),'The mirror scopes to the adapter\'s card wrapper');
check(str_contains($woo_css,'fpw-loop-add'),'The card selector row is styled');

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
class FPW_Fake_Session {
	public array $data=array();
	public function get_customer_id() { return $GLOBALS['fpw_session_customer_id']; }
	public function get($key,$default='') { return array_key_exists($key,$this->data)?$this->data[$key]:$default; }
	public function set($key,$value) { $this->data[$key]=$value; }
	public function __unset($key) { unset($this->data[$key]); }
}
class FPW_Fake_Cart_Hash extends FPW_Fake_Cart {
	public function __construct(private string $hash='') {}
	public function get_cart_hash(): string { return $this->hash; }
	public function is_empty(): bool { return (bool) ($GLOBALS['fpw_cart_empty'] ?? false); }
}
class FPW_Fake_Checkout { public function __construct(private array $posted) {} public function get_posted_data(): array { return $this->posted; } }
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
function fpw_attempt_posted(array $overrides=array()): array {
	return array_merge(array('billing_first_name'=>'Cliente','billing_phone'=>'+56 9 1234 5678','billing_email'=>'cliente@example.invalid','billing_company'=>'Empresa','billing_fp_rut'=>'76.123.456-7','billing_fp_giro'=>'Giro','billing_fp_dispatch'=>'no','billing_fp_address'=>'','order_comments'=>'','payment_method'=>'quotes-gateway'),$overrides);
}
// Issue #31 (SP-01): attempt identity is NOT content identity. The attempt is
// the session plus the per-attempt token the form posts; cart contents and
// posted fields never take part, so a completed attempt can never capture a
// later, identical submission (a rebuilt identical selection is a NEW attempt),
// while retries and concurrent submissions of ONE attempt share the identity.
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();  // fresh session: no open token yet
$open_token=fpw_open_attempt_token();
check(fpw_is_attempt_token($open_token),'The first identity need creates the session\'s open attempt token');
check(fpw_open_attempt_token()===$open_token,'The open attempt token is stable for the whole attempt');
$attempt_hash=fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token))));
check(preg_match('/^[a-f0-9]{64}$/',$attempt_hash)===1,'The attempt identity hash is a sha256');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(array_reverse(fpw_attempt_posted(array('fpw_attempt'=>$open_token)),true)))===$attempt_hash,'The attempt hash is independent of the posted field order');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token,'billing_fp_dispatch'=>'si','billing_fp_address'=>'Otra dirección'))))===$attempt_hash,'A changed posted field does NOT change the attempt identity: identity is not content');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('changed-cart');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token))))===$attempt_hash,'A changed cart does NOT change the attempt identity: a rebuilt selection stays a new attempt, never the old one');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
$explicit_token=str_repeat('ab',20);
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$explicit_token))))!==$attempt_hash,'A different attempt token is a different attempt');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$explicit_token))))===hash('sha256',(string)wp_json_encode(array(fpw_session_fingerprint(),$explicit_token))),'The identity hash is exactly session + attempt token');
// Issue #35: the identity is ONLY the submitted token — the session's open
// token is never a fallback, so an unknown or stale form can never silently
// acquire another attempt's identity.
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()))==='','A form posted without its attempt token claims no identity: no session-token substitution (issue #35)');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>'<script>bad-token</script>'))))==='','A malformed posted token claims no identity (issue #35)');
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>str_repeat('cd',20)))))!==$attempt_hash,'An unknown well-formed token is its OWN attempt identity, never the open attempt\'s');
$GLOBALS['fpw_session_customer_id']='other-session';
check(fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token))))!==$attempt_hash,'Another session produces another attempt hash');
$GLOBALS['fpw_session_customer_id']='abc123';
// The token is a random identity, never a WordPress nonce: format-checked, unique per generation.
check(!fpw_is_attempt_token(''),'The empty token is rejected');
check(!fpw_is_attempt_token(str_repeat('AB',20)),'Non-lowercase-hex tokens are rejected');
check(!fpw_is_attempt_token(substr($explicit_token,0,39)),'Short tokens are rejected');
check(!fpw_is_attempt_token($explicit_token.'0'),'Long tokens are rejected');
check(count(array_unique(array_map(static function() { return fpw_open_attempt_token(); },range(1,25))))===1,'Token generation is session-stable (the open attempt keeps its identity)');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open','');
check(fpw_open_attempt_token()!==$open_token && fpw_is_attempt_token($GLOBALS['fpw_woo']->session->get('fpw_attempt_open')),'After clearing, a fresh random token opens the next attempt');
$older_token=$open_token;   // the completed attempt's token, for the identity-gate checks below
$open_token=$GLOBALS['fpw_woo']->session->get('fpw_attempt_open');
$attempt_hash=fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token))));

// Issue #35: the identity gate rides Woo's own after-validation seam. The
// submitted token must be the session's open one; anything else (missing,
// malformed, unknown, or an older form after rotation) is rejected
// recoverably — aliasing no attempt and leaking no reference.
function fpw_gate_errors(array $data): array { $errors=new WP_Error(); fpw_validate_attempt_identity($data,$errors); return $errors->codes; }
check(fpw_gate_errors(array('fpw_attempt'=>$open_token))===array(),'The identity gate accepts the session\'s open attempt token');
check(fpw_gate_errors(fpw_attempt_posted(array('fpw_attempt'=>$open_token)))===array(),'The identity gate accepts a full posted form carrying the open token');
check(fpw_gate_errors(array())!==array(),'A form posted without its attempt token is rejected (issue #35)');
check(fpw_gate_errors(array('fpw_attempt'=>'<script>bad-token</script>'))!==array(),'A malformed attempt token is rejected');
check(fpw_gate_errors(array('fpw_attempt'=>str_repeat('ef',20)))!==array(),'An unknown well-formed token is rejected: it never adopts the open attempt');
check(fpw_gate_errors(array('fpw_attempt'=>''))!==array(),'An empty attempt token is rejected');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$older_token,'hash'=>fpw_attempt_identity_hash($older_token),'order_id'=>68,'at'=>time()));
check(fpw_gate_errors(array('fpw_attempt'=>$older_token))!==array(),'An older form\'s completed token never adopts the newer open attempt\'s identity (issue #35)');
$gate_errors=new WP_Error(); fpw_validate_attempt_identity(array('fpw_attempt'=>str_repeat('ef',20)),$gate_errors);
check(in_array('No pudimos verificar esta solicitud de cotización. Vuelve a abrir la página de Datos y envío y envía el formulario de nuevo; no se creará una solicitud duplicada.',$gate_errors->messages,true),'The gate\'s rejection states the safe recovery path in Spanish and reveals no reference');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array());

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

// Issue #35, defense in depth at the claim itself: even if a submission
// bypassed the identity gate, the claim's identity is the SUBMITTED token and
// nothing else — an unknown well-formed token opens its OWN claim key, so it
// can never fold into the landed attempt, empty the basket through the forced
// payment path, stamp the fold trace or rewrite the landing binding.
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='68';
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$open_token,'hash'=>$attempt_hash,'order_id'=>68,'at'=>time()));
$notes_before=$GLOBALS['fpw_order_notes'];
$unknown_token=str_repeat('ef',20);
$unknown_probe=new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$unknown_token)));
check(fpw_checkout_claim(null,$unknown_probe)===null,'An unknown posted token opens its OWN attempt at the claim — it never folds into the landed attempt (issue #35)');
check(!isset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]),'The landed attempt\'s key is never claimed by an unknown token');
check(isset($GLOBALS['fpw_options_table']['fpw_claim_'.fpw_attempt_identity_hash($unknown_token)]),'The unknown token claims its OWN key, distinct from the landed attempt');
check($GLOBALS['fpw_order_notes']===$notes_before,'No fold trace is stamped for an unknown posted token');
check(fpw_is_folding_attempt()===false,'The cart-emptying fold path never runs for an unknown posted token');
check((int)(($GLOBALS['fpw_woo']->session->get('fpw_attempt_landed'))['order_id'] ?? 0)===68,'The landed binding is not rewritten by an unknown posted token');
fpw_release_attempt_claim(fpw_attempt_identity_hash($unknown_token));
fpw_pending_attempt(''); fpw_pending_attempt_token('');
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]);
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array());

// Owned attempt, end to end through the filter: Woo's short-circuit receives null and proceeds natively.
check(!isset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]),'A fresh site holds no claim for the attempt');
$checkout=new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$open_token)));
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
$landed=$GLOBALS['fpw_woo']->session->get('fpw_attempt_landed');
check(is_array($landed) && $landed['token']===$open_token && $landed['hash']===$attempt_hash && $landed['order_id']===68 && $landed['at']>0,'A recovery completes the attempt: the session keeps the authorized landing binding (token + hash + order)');
check(($GLOBALS['fpw_woo']->session->data['fpw_attempt_landed'] ?? null)===$landed,'The landing binding lives in the customer\'s own session store, not a parallel one');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array());
check(count($GLOBALS['wp_filter']['woocommerce_checkout_order_processed']->callbacks[10]??array())===0,'A folded attempt no longer re-fires the quotes extension\'s request notifications');
check($GLOBALS['fpw_order_notes']===array(array(68,'Solicitud duplicada (reintento concurrente) fusionada en este pedido por el control de intentos de Freeplast.',false)),'The folded attempt leaves one honest private trace on the record it joins');

// The durable binding after the claim row is gone (#36 lead red-gate): a
// COMPLETED attempt is never folded — no wall-clock age is evidence. Only a
// request that itself observed the attempt unfinalized (its validation ran
// before the completion existed, or its claim wait saw the unfinalized row)
// recovers through the durable row.
fpw_attempt_inflight_seen($attempt_hash,false);   // a fresh request carries no in-flight evidence
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]);
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='68';
try {
	fpw_checkout_attempt_claim($attempt_hash,0.0);
	check(false,'A completed attempt must not fold when the request observed only the completed state');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The completed-attempt replay throws the recoverable reload rejection — never the cart-emptying fold');
}
// Genuine concurrency: THIS request's validation ran while the attempt was
// still unfinalized (no durable completion yet) — the observation is recorded
// and its claim folds through the durable row once the winner lands.
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]);
check(fpw_gate_errors(array('fpw_attempt'=>$open_token))===array(),'A validation that runs BEFORE the completion exists passes the gate and records the in-flight observation');
check(fpw_attempt_inflight_seen($attempt_hash)===true,'The gate records the hash-scoped in-flight evidence for the claim seam');
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='68';
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>68,'started'=>time(),'landed_at'=>time()));
check(fpw_checkout_attempt_claim($attempt_hash,0.0)===array('state'=>'recovered','order_id'=>68,'source'=>'durable'),'A request whose validation preceded the completion — the genuine concurrent loser — still folds');
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]);

// Takeover: an unfinalized claim past the grace period belongs to a winner that died mid-flight.
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]);
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1));
$claim=fpw_checkout_attempt_claim($attempt_hash,0.0);
check($claim['state']==='owned','The same session resumes an attempt whose winner died past the grace period');
$row=json_decode($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]??'',true);
check(is_array($row) && $row['order_id']===0 && abs($row['started']-time())<3,'The takeover writes a fresh claim row');
// …and a lookup row reached by a request that never observed the attempt
// unfinalized answers with the safe reload rejection (#36: wall-clock ages
// are not concurrency evidence).
fpw_attempt_inflight_seen($attempt_hash,false);
$GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>0,'started'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1));
$GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]='91';
try {
	fpw_checkout_attempt_claim($attempt_hash,0.0);
	check(false,'A landed-without-finalization lookup reached without in-flight evidence must not fold');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The crash-window replay receives the recoverable reload rejection');
}

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
check($row['order_id']===77 && (int)($row['landed_at']??0)>0,'Finalization records the winner\'s order id and landing time on the claim (landed_at, stamped once)');
check($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]==='77','Finalization writes the durable lookup row (unique option_name → order id)');
fpw_release_attempt_claim($attempt_hash);
check(!isset($GLOBALS['fpw_options_table']['fpw_claim_'.$attempt_hash]),'A released attempt leaves no claim row');
$GLOBALS['fpw_options_table']['fpw_claim_expired']=json_encode(array('session'=>'x','order_id'=>0,'started'=>time()-FPW_CLAIM_MAX_AGE-1));
$GLOBALS['fpw_options_table']['fpw_claim_fresh']=json_encode(array('session'=>'x','order_id'=>0,'started'=>time()));
check(fpw_sweep_attempt_claims()===1,'The opportunistic sweep removes exactly the expired claim rows');
check(isset($GLOBALS['fpw_options_table']['fpw_claim_fresh']) && !isset($GLOBALS['fpw_options_table']['fpw_claim_expired']),'The sweep keeps in-window claims');
check(isset($GLOBALS['fpw_options_table']['fpw_attempt_'.$attempt_hash]),'The sweep never touches the durable lookup rows');

// Issue #31 lifecycle: rotation on the first render after a landing; the form
// carries the identity; the landing binding is written on the order creation.
$rotation_callback=null;
foreach($registered_actions['woocommerce_before_checkout_form']??array() as $callback) { if(is_object($callback)) { $rotation_callback=$callback; break; } }
check(is_object($rotation_callback),'Rotation rides the checkout-form render');
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$first_token=fpw_open_attempt_token();
$rotation_callback();  // no landing: a live attempt keeps its identity across re-renders
check($GLOBALS['fpw_woo']->session->get('fpw_attempt_open')===$first_token,'A re-render inside a live attempt never rotates the token');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$first_token,'hash'=>$attempt_hash,'order_id'=>55,'at'=>time()));
$rotation_callback();  // the open attempt landed: the fresh form opens a NEW attempt
$rotated_token=$GLOBALS['fpw_woo']->session->get('fpw_attempt_open');
check(fpw_is_attempt_token($rotated_token) && $rotated_token!==$first_token,'The first render after a landing rotates the identity: the completed attempt cannot capture a new submission');
$rotation_callback();  // the new open attempt has not landed: it stays
check($GLOBALS['fpw_woo']->session->get('fpw_attempt_open')===$rotated_token,'The new open attempt keeps its token on the next render');
$stale_landed=array('token'=>'outdated','hash'=>'outdated','order_id'=>1,'at'=>time());
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',$stale_landed);
$rotation_callback();
check($GLOBALS['fpw_woo']->session->get('fpw_attempt_open')===$rotated_token,'A landing of an OLDER attempt never rotates the current open one');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array());

$render_callback=null;
foreach($registered_actions['woocommerce_after_order_notes']??array() as $callback) { if(is_object($callback)) { $render_callback=$callback; break; } }
check(is_object($render_callback),'The checkout form renders the attempt identity field');
ob_start(); $render_callback(); $field_html=ob_get_clean();
check((bool)preg_match('/^<input type="hidden" name="fpw_attempt" value="'.$rotated_token.'" \/>$/',$field_html),'The form carries exactly one hidden attempt-identity field with the open token');
$GLOBALS['fpw_woo']->session=null;  // no session available
ob_start(); $render_callback(); check(ob_get_clean()==='','Without a session no identity field is rendered and the claim stays out of the way');
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
fpw_open_attempt_token();  // reopen an attempt for the landing tests

if (!function_exists('wc_get_order')) { $GLOBALS['fpw_orders']=array(); function wc_get_order($id) { return $GLOBALS['fpw_orders'][$id] ?? false; } }
if (!function_exists('wp_send_json')) { $GLOBALS['fpw_json_sent']=null; function wp_send_json($data) { $GLOBALS['fpw_json_sent']=$data; } } // the offline stub returns; the real one exits after sending
if (!function_exists('wp_verify_nonce')) { $GLOBALS['fpw_nonce_valid']=false; function wp_verify_nonce($nonce,$action) { return $GLOBALS['fpw_nonce_valid'] && 'woocommerce-process_checkout'===$action; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }

// The landing binding written by the order creation.
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open','');
fpw_pending_attempt(''); fpw_pending_attempt_token('');
check(fpw_pending_attempt('')==='' && fpw_pending_attempt_token('')==='' ,'Pending attempt and token start empty');
$identity_token=fpw_open_attempt_token();
$identity_hash=fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted(array('fpw_attempt'=>$identity_token))));
$creation_callbacks=$registered_actions['woocommerce_checkout_order_created']??array();
$order=new FPW_Fake_Order(77);
fpw_pending_attempt($identity_hash); fpw_pending_attempt_token($identity_token);
foreach($creation_callbacks as $callback) { if(is_object($callback)) { $callback($order); } }
$landed=$GLOBALS['fpw_woo']->session->get('fpw_attempt_landed');
check(is_array($landed) && $landed['token']===$identity_token && $landed['hash']===$identity_hash && $landed['order_id']===77,'A persisted order marks the attempt landed with its authorized binding');
fpw_mark_attempt_landed($identity_token,'',77); fpw_mark_attempt_landed('',$identity_hash,77); fpw_mark_attempt_landed($identity_token,$identity_hash,0);
check($GLOBALS['fpw_woo']->session->get('fpw_attempt_landed')===$landed,'Landing guards: no hash, no token or no order writes nothing');
fpw_mark_attempt_landed($identity_token,$identity_hash,99);
check(($GLOBALS['fpw_woo']->session->get('fpw_attempt_landed')['order_id'] ?? 0)===99,'A later landing supersedes the previous binding (the last request owns the record)');
// restore the earlier attempt as pending, as the seam checks below expect it
fpw_pending_attempt($attempt_hash); fpw_pending_attempt_token($open_token);

// Retry recovery of the SAME landed attempt (issue #31): Woo emptied the cart,
// the same form is resubmitted — the confirmation of the EXISTING request is
// re-shown; every authorization must agree, and nothing new is created.
function fpw_recovery_attempt(array $posted=array(),bool $empty_cart=true,bool $checkout_ajax=true,bool $nonce_valid=true): ?array {
	$_GET=$checkout_ajax?array('wc-ajax'=>'checkout'):array();
	$_POST=array_merge(array('woocommerce-process-checkout-nonce'=>'nonce-value','fpw_attempt'=>''),$posted);
	$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
	$GLOBALS['fpw_cart_empty']=$empty_cart;
	$GLOBALS['fpw_nonce_valid']=$nonce_valid; $GLOBALS['fpw_json_sent']=null;
	fpw_recover_landed_attempt();
	return $GLOBALS['fpw_json_sent'];
}
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
$GLOBALS['fpw_cart_empty']=true;
$GLOBALS['fpw_orders']=array();
class FPW_Fake_Landed_Order { public function __construct(private int $id) {} public function get_checkout_order_received_url(): string { return 'https://example.invalid/checkout/order-received/'.$this->id; } }
$GLOBALS['fpw_orders'][77]=new FPW_Fake_Landed_Order(77);
$GLOBALS['fpw_session_customer_id']='abc123';
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open','');
$recovery_token=fpw_open_attempt_token();
$recovery_hash=hash('sha256',(string)wp_json_encode(array(fpw_session_fingerprint(),$recovery_token)));
$GLOBALS['fpw_options_table']['fpw_attempt_'.$recovery_hash]='77';
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$recovery_token,'hash'=>$recovery_hash,'order_id'=>77,'at'=>time()));

$json=fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token));
check(is_array($json) && $json['result']==='success' && str_contains($json['redirect'],'/order-received/77'),'An authorized retry of a landed attempt recovers the SAME request\'s confirmation');

check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token),nonce_valid:false)===null,'Recovery never bypasses Woo\'s own process-checkout nonce');

// Issue #32: the recovery answers an authorized retry of the landed attempt even
// when a NEW selection unrelated to that attempt already sits in Productos a
// Cotizar — and the recovery path never mutates the cart, so that selection
// survives (the real-stack preserve probe drives the same contract over native
// HTTP; Woo's fold-in path would empty it through the quotes gateway).
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token),empty_cart:false)),'An authorized retry recovers the original confirmation even with a new selection already in the basket (issue #32)');
check($GLOBALS['fpw_cart_empty']===false,'The recovery path never mutates the cart: the unrelated selection survives');
$GLOBALS['fpw_cart_empty']=true;

check(fpw_recovery_attempt(array('fpw_attempt'=>str_repeat('cd',20)))===null,'A different attempt token never recovers another request');

$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>'other','hash'=>'other','order_id'=>1,'at'=>time()));
check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token))===null,'Recovery demands the session landing record to agree with the durable binding');

$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$recovery_token,'hash'=>$recovery_hash,'order_id'=>77,'at'=>time()));
$GLOBALS['fpw_options_table']=array(); // lookup swept
check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token))===null,'Recovery demands BOTH bindings: the session record alone is not authorized');

$GLOBALS['fpw_options_table']['fpw_attempt_'.$recovery_hash]='77';
check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token),checkout_ajax:false)===null,'Only the native checkout submission endpoint takes the recovery path');

$GLOBALS['fpw_session_customer_id']='other-session';
check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token))===null,'Another session can never recover a request of this session');
$GLOBALS['fpw_session_customer_id']='abc123';

// Issue #32: the recovery has a defined lifetime. A landing older than
// FPW_RECOVERY_MAX_AGE is no longer recoverable — the resubmission falls
// through to Woo's own guards, whose safe answer reveals no reference, key or
// foreign data. Within the lifetime the original confirmation stays recoverable.
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$recovery_token,'hash'=>$recovery_hash,'order_id'=>77,'at'=>time()-FPW_RECOVERY_MAX_AGE-1));
check(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token))===null,'A landing past the recovery lifetime is not recoverable: Woo\'s own safe answer governs, revealing nothing');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$recovery_token,'hash'=>$recovery_hash,'order_id'=>77,'at'=>time()-FPW_RECOVERY_MAX_AGE+60));
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$recovery_token))),'Inside the recovery lifetime the original confirmation is still recoverable');
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$recovery_token,'hash'=>$recovery_hash,'order_id'=>77,'at'=>time()));
unset($_GET,$_POST);

// ---------------------------------------------------------------------
// Issue #36: independent per-attempt recovery lifetime. Each completed
// attempt keeps its OWN durable recovery row with an IMMUTABLE landing time;
// the session keeps only the latest record (rotation signal) — no history
// accumulation and no cap that could evict a still-eligible attempt.
$GLOBALS['fpw_options_table']=array();
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_session_customer_id']='abc123';
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
$GLOBALS['fpw_cart_empty']=true;
$token_a=str_repeat('11',20);
$token_b=str_repeat('22',20);
$hash_a=fpw_attempt_identity_hash($token_a);
$hash_b=fpw_attempt_identity_hash($token_b);
$fpw_backdate_row=function(string $hash,int $at): void {
	$row=json_decode($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash]??'',true);
	if (is_array($row)) { $row['at']=$at; $GLOBALS['fpw_options_table']['fpw_recovery_'.$hash]=json_encode($row); }
};
fpw_mark_attempt_landed($token_a,$hash_a,101);
$row_a=json_decode($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_a]??'',true);
check(is_array($row_a) && $row_a['session']===fpw_session_fingerprint() && $row_a['order_id']===101 && $row_a['at']>0,'Landing A writes its own durable recovery row (session + order + landing time)');
check(($GLOBALS['fpw_woo']->session->get('fpw_attempt_landed'))['order_id']===101,'The session keeps the latest landing record (rotation signal)');
fpw_mark_attempt_landed($token_b,$hash_b,202);
check(isset($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_a]) && isset($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_b]),'Landing B keeps A\'s recovery row: independent bindings, nothing evicted');
check(($GLOBALS['fpw_woo']->session->get('fpw_attempt_landed'))['order_id']===202,'After B lands, the session\'s single latest record is B');
fpw_open_attempt_token();
check(count(array_filter(array_keys($GLOBALS['fpw_woo']->session->data),static fn($key)=>str_starts_with((string)$key,'fpw_attempt_')))===2,'Session attempt state stays bounded: the open token and ONE latest record — no history map, no cap that could evict a binding');
fpw_mark_attempt_landed($token_a,$hash_a,101);
check(json_decode($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_a],true)['at']===$row_a['at'],'Re-landing or replaying the same attempt NEVER extends its vigencia (immutable landing time)');
$GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_a]='101';
$GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_b]='202';
$GLOBALS['fpw_orders'][101]=new FPW_Fake_Landed_Order(101);
$GLOBALS['fpw_orders'][202]=new FPW_Fake_Landed_Order(202);
$GLOBALS['fpw_woo']->session->set('fpw_attempt_landed',array('token'=>$token_b,'hash'=>$hash_b,'order_id'=>202,'at'=>time()));   // latest = B, like a live session
// A recovers AFTER B completed — through its own row, not the latest record.
$json=fpw_recovery_attempt(array('fpw_attempt'=>$token_a));
check(is_array($json) && str_contains($json['redirect'] ?? '','/order-received/101'),'After B completes, A\'s OWN confirmation is still recoverable through its own row (#36)');
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$token_b))) && str_contains(($GLOBALS['fpw_json_sent']['redirect'] ?? ''),'/order-received/202'),'B remains recoverable too — never only the latest');
// Both basket states (criterion 3): empty and a third, unrelated selection.
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$token_a),empty_cart:false)) && str_contains(($GLOBALS['fpw_json_sent']['redirect'] ?? ''),'/order-received/101'),'A recovers with a third, unrelated selection already in the basket');
check($GLOBALS['fpw_cart_empty']===false,'The multi-attempt recovery never mutates the cart: the unrelated selection survives');
// Repeated recovery of A and B creates nothing (criterion 4).
$keys_before=array_keys($GLOBALS['fpw_options_table']);
fpw_recovery_attempt(array('fpw_attempt'=>$token_a)); fpw_recovery_attempt(array('fpw_attempt'=>$token_b)); fpw_recovery_attempt(array('fpw_attempt'=>$token_a));
check($keys_before===array_keys($GLOBALS['fpw_options_table']) && !in_array('fpw_claim_'.$hash_a,$keys_before,true),'Repeated recovery creates no claim rows, no requests, no copies');
// Per-attempt expiry (criterion 5): backdate ONLY A (a synthetic offline backdate of the immutable row).
$fpw_backdate_row($hash_a,time()-FPW_RECOVERY_MAX_AGE-1);
check(fpw_recovery_attempt(array('fpw_attempt'=>$token_a))===null,'An expired attempt is no longer recoverable: the safe answer reveals no reference');
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$token_b))),'Expiry is PER ATTEMPT: B stays recoverable after A expired — no extension, no shortening');
$fpw_backdate_row($hash_a,time()-FPW_RECOVERY_MAX_AGE+120);
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$token_a))),'Inside its own lifetime A recovers again');

// Claim seam (#36 lead red-gate): a completed attempt reached without
// request-observed in-flight evidence must NEVER fold — fresh vigencia,
// fresh landed_at, none of it is evidence. The recoverable reload rejection
// answers instead, with no fold trace and no payment-path forcing.
fpw_attempt_inflight_seen($hash_a,false);
$notes_baseline=$GLOBALS['fpw_order_notes'];
fpw_is_folding_attempt(false);   // reset the request-scoped flag left by the earlier fold checks
try {
	fpw_checkout_attempt_claim($hash_a,0.0);
	check(false,'A completed attempt must not fold even inside its vigencia');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The in-vigencia completed replay throws the recoverable reload rejection — never the fold');
}
$fpw_backdate_row($hash_a,time()-FPW_RECOVERY_MAX_AGE-1);
try {
	fpw_checkout_attempt_claim($hash_a,0.0);
	check(false,'An expired durable replay must not fold into the landed record');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The expired durable replay throws the recoverable reload rejection — never the fold');
}
check($GLOBALS['fpw_order_notes']===$notes_baseline && fpw_is_folding_attempt()===false,'The rejected replay stamps no fold trace and never runs the cart-emptying fold path');
$fpw_backdate_row($hash_a,time()-FPW_RECOVERY_MAX_AGE+60);
// Genuine concurrency, per-attempt: the request's validation ran while A was
// unfinalized (open token, no completion yet) — the fold is authorized then.
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$token_a);
fpw_attempt_inflight_seen($hash_a,false);
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_a]);
check(fpw_gate_errors(array('fpw_attempt'=>$token_a))===array(),'The concurrent loser\'s validation (before A\'s completion) passes the gate');
$GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_a]='101';
$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_a]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>101,'started'=>time(),'landed_at'=>time()));
check(fpw_checkout_attempt_claim($hash_a,0.0)===array('state'=>'recovered','order_id'=>101,'source'=>'durable'),'The concurrent loser — evidence recorded at its own validation — folds through the durable row');
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$hash_a]);
// Claim-row seam: a finalized row whose landing this request never observed
// is a completed replay at ANY age (lead: claim-row age is not concurrency
// evidence either); only request-observed evidence folds.
fpw_attempt_inflight_seen($hash_b,false);
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_b]);
$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_b]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>202,'started'=>time()-3*86400,'landed_at'=>time()-FPW_RECOVERY_MAX_AGE-1));
try {
	fpw_checkout_attempt_claim($hash_b,0.0);
	check(false,'A stale unswept claim row must not fold a completed replay');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The stale claim row answers with the recoverable reload rejection');
}
$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_b]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>202,'started'=>time(),'landed_at'=>time()));
try {
	fpw_checkout_attempt_claim($hash_b,0.0);
	check(false,'A FRESH landed_at is still not concurrency evidence: the finalized-before-arrival row must not fold');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'A claim row finalized before this request arrived answers with the reload rejection whatever its age');
}
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$token_b);
fpw_attempt_inflight_seen($hash_b,false);
unset($GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_b],$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_b]);
check(fpw_gate_errors(array('fpw_attempt'=>$token_b))===array(),'The loser\'s validation on B (before completion — no finalized claim row, no lookup) passes the gate and records evidence');
$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_b]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>202,'started'=>time(),'landed_at'=>time()));
check(fpw_checkout_attempt_claim($hash_b,0.0)['source']==='claim','A request that observed B unfinalized at its own validation still folds through the claim row');
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$hash_b]);

// Gate (#36 lead red-gate): a COMPLETED attempt that still equals the open
// token — FRESH or expired, seconds or days after completion — is rejected
// whenever the read-only recovery did not serve the submission (the no-JS
// plain form POST skips wc-ajax). No wall-clock age or fresh landed_at is
// evidence; the fold stays unreachable. A request whose validation ran
// BEFORE the completion exists passes and records the in-flight observation.
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$token_e=fpw_open_attempt_token();
$hash_e=fpw_attempt_identity_hash($token_e);
$GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_e]='303';
$GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_e]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>303,'at'=>time()));
$GLOBALS['fpw_options_table']['fpw_claim_'.$hash_e]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>303,'started'=>time(),'landed_at'=>time()));
fpw_attempt_inflight_seen($hash_e,false);
check(fpw_gate_errors(array('fpw_attempt'=>$token_e))!==array(),'A FRESH completed attempt (<30 s, claim row freshly stamped) that still equals the open token is rejected at the gate — wall-clock age is not evidence');
$fpw_backdate_row($hash_e,time()-FPW_RECOVERY_MAX_AGE-1);
check(fpw_gate_errors(array('fpw_attempt'=>$token_e))!==array(),'An expired completed attempt that still equals the open token is rejected at the gate');
unset($GLOBALS['fpw_options_table']['fpw_claim_'.$hash_e]);
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open','');
$token_f=fpw_open_attempt_token();
$hash_f=fpw_attempt_identity_hash($token_f);
check(fpw_gate_errors(array('fpw_attempt'=>$token_f))===array(),'An UNCOMPLETED open attempt passes the gate');
check(fpw_attempt_inflight_seen($hash_f)===true,'A passing validation records the hash-scoped in-flight evidence the claim seam requires');

// Recovery-row sweep: bounded durable state, grace past the vigencia — and no
// path back: a swept post-#36 attempt cannot be reauthorized by the legacy
// session fallback or by re-landing the same attempt (immutable times).
$GLOBALS['fpw_options_table']['fpw_recovery_old']=json_encode(array('session'=>'x','order_id'=>1,'at'=>time()-FPW_RECOVERY_MAX_AGE-FPW_RECOVERY_SWEEP_GRACE-1));
$GLOBALS['fpw_options_table']['fpw_recovery_new']=json_encode(array('session'=>'x','order_id'=>2,'at'=>time()));
check(fpw_sweep_recovery_rows()===1,'Expired recovery rows are swept past their grace');
check(isset($GLOBALS['fpw_options_table']['fpw_recovery_new']) && !isset($GLOBALS['fpw_options_table']['fpw_recovery_old']),'The sweep keeps live rows — no eligible binding is ever evicted');
check(isset($GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_a]),'The sweep never touches the permanent attempt lookup rows');
unset($GLOBALS['fpw_options_table']['fpw_recovery_old'],$GLOBALS['fpw_options_table']['fpw_recovery_new']);
$fpw_backdate_row($hash_a,time()-FPW_RECOVERY_MAX_AGE-FPW_RECOVERY_SWEEP_GRACE-1);
check(fpw_sweep_recovery_rows()>=1,'A\'s expired recovery row is swept away');
fpw_mark_attempt_landed($token_a,$hash_a,101);   // fold replay / re-landing must NOT reauthorize or extend
check(!isset($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_a]) || (int)(json_decode($GLOBALS['fpw_options_table']['fpw_recovery_'.$hash_a]??'null',true)['at']??0)<time()-FPW_RECOVERY_MAX_AGE,'Re-landing a swept attempt writes no fresh row (INSERT-only) — the vigencia is never extended');
check(fpw_recovery_fresh($token_a,$hash_a,101)===false,'After expiry and sweep, neither the legacy session fallback nor re-landing reauthorizes the attempt');
check(fpw_recovery_attempt(array('fpw_attempt'=>$token_a))===null,'The swept attempt stays unrecoverable: the safe answer reveals no reference');
$GLOBALS['fpw_options_table']['fpw_attempt_'.$hash_b]='202';   // restore B's durable binding consumed by the claim-seam probes
check(is_array(fpw_recovery_attempt(array('fpw_attempt'=>$token_b))),'B stays recoverable across A\'s sweep — per-attempt vigencia to the end');

// Issue #35: the submitted token must survive the REAL pinned Woo 11.1.0
// normalization — the defect the review reproduced (ST-01/SP-02): the hidden
// field was not registered, get_posted_data() dropped it, and the claim fell
// back to the session's CURRENT open token (identity substitution). The
// vendored, hash-pinned WC_Checkout below IS the pinned release's own method
// (see scripts/vendor/woocommerce-11.1.0-class-wc-checkout.json); nothing here
// stubs the normalized data.
$vendor_dir=__DIR__.'/vendor';
$sidecar_path=$vendor_dir.'/woocommerce-11.1.0-class-wc-checkout.json';
$checkout_source=$vendor_dir.'/woocommerce-11.1.0-class-wc-checkout.php';
check(is_file($sidecar_path) && is_file($checkout_source) && is_file($vendor_dir.'/woocommerce-11.1.0-cogs-aware-trait.php'),'The vendored pinned WC_Checkout source, companion trait and sidecar are committed');
$woo_sidecar=json_decode((string)file_get_contents($sidecar_path),true);
$woo_pinned=json_decode((string)file_get_contents(__DIR__.'/../woo-dependencies.json'),true);
check(is_array($woo_sidecar) && hash_file('sha256',$checkout_source)===$woo_sidecar['file_sha256'],'The vendored WC_Checkout is byte-identical to its pinned sha256 sidecar');
check(hash_file('sha256',$vendor_dir.'/woocommerce-11.1.0-cogs-aware-trait.php')===($woo_sidecar['companion']['file_sha256'] ?? null),'The vendored companion trait matches its pinned sha256');
check((string)($woo_sidecar['zip_sha256'] ?? '')===(string)($woo_pinned['woocommerce']['sha256'] ?? ''),'The sidecar chain still points at the pinned woocommerce zip');
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($value) { return trim(strip_tags((string)$value)); } }
if (!function_exists('wc_clean')) { function wc_clean($value) { return sanitize_text_field((string)$value); } }
if (!function_exists('sanitize_title')) { function sanitize_title($title) { return (string)$title; } }
if (!function_exists('wc_sanitize_textarea')) { function wc_sanitize_textarea($value) { return sanitize_textarea_field((string)$value); } }
if (!function_exists('wc_ship_to_billing_address_only')) { function wc_ship_to_billing_address_only(): bool { return false; } }
require $vendor_dir.'/woocommerce-11.1.0-cogs-aware-trait.php';
require $checkout_source;
class FPW_Fake_Cart_Normalization extends FPW_Fake_Cart { public function needs_shipping_address(): bool { return false; } }
class FPW_Real_Woo_Normalization_Probe extends WC_Checkout {
	public function __construct() {}
	public function is_registration_enabled(): bool { return false; }
	public function get_checkout_fields( $fieldset = '' ) {
		$all = fpw_checkout_fields( array() );
		return $fieldset ? ( $all[ $fieldset ] ?? array() ) : $all;
	}
}
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Normalization();
$real_posted_token=str_repeat('a',40);
$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',str_repeat('b',40));   // a DIFFERENT open token: substitution must be impossible
$_POST=array('fpw_attempt'=>$real_posted_token,'woocommerce-process-checkout-nonce'=>'synthetic','billing_first_name'=>'Cliente','billing_fp_dispatch'=>'no','order_comments'=>'');
$real_probe=new FPW_Real_Woo_Normalization_Probe();
$real_data=$real_probe->get_posted_data();
check(($real_data['fpw_attempt'] ?? null)===$real_posted_token,'The submitted attempt token survives the REAL pinned Woo 11.1.0 get_posted_data() normalization (registered field, not a stub)');
$real_identity=fpw_attempt_identity($real_probe);
check($real_identity['token']===$real_posted_token && $real_identity['hash']===fpw_attempt_identity_hash($real_posted_token),'The claim identity over the REAL normalized data is the SUBMITTED token (issue #35)');
check($real_identity['token']!==$GLOBALS['fpw_woo']->session->get('fpw_attempt_open'),'The session\'s current open token is never substituted for the submitted one');
check(($real_data['billing_fp_dispatch'] ?? null)==='no','The REAL normalization keeps the adapter\'s registered local fields: no collateral drop');
check(!isset($real_data['billing_country']),'The adapter\'s field contract is exactly what the REAL normalization walks');
unset($_POST);

// Issue #35 (rev 3): the native variant fixture must seed through the pinned
// variation-matching seam. The pinned WC_Product_Variation::set_attributes
// strips only the attribute_ prefix and preserves key case, while
// WC_Product_Data_Store_CPT::find_matching_product_variation matches
// 'attribute_' . sanitize_title( parent attribute name ) — a 'Color'-keyed
// variant would persist as attribute_Color and never match the posted
// attribute_color. The seeding therefore posts the normalized key ('color'),
// proves the variant through the matcher itself, repairs a wrong preexisting
// fixture, and fails the bootstrap loudly instead of accepting one.
$bootstrap_source=(string)file_get_contents(__DIR__.'/bootstrap.mjs');
check(str_contains($bootstrap_source,'find_matching_product_variation( $product, array( \'attribute_color\' => \'Rojo\' ) )'),'The variant fixture is verified through the pinned variation matcher seam (attribute_color — the normalized key)');
check(str_contains($bootstrap_source,'$variation->set_attributes( array( \'color\' => \'Rojo\' ) )'),'The seeded variation attribute key is the normalized slug (color), not the display name');
check(!str_contains($bootstrap_source,"'Color' => 'Rojo'"),'No variation is seeded with the unnormalized attribute key (it would never match attribute_color)');
check(str_contains($bootstrap_source,'exit( 1 );'),'An unmatchable fixture fails the bootstrap loudly — a wrong preexisting fixture is never silently accepted');

// Issue #36 rev 2 (lead follow-up 2): STRUCTURAL checks on the operator-only
// native regressions — they are NOT executed here; these verify the flow
// shape the lead's review demanded (snapshot ordering, derived arithmetic,
// originality state checks) so the operator run cannot silently drift.
$race_source=(string)file_get_contents(__DIR__.'/woo-checkout-race.py');
$plain_post_position=strpos($race_source,'plain_code, plain_body = post_checkout_plain(session, stale_values)');
$plain_before_position=strpos($race_source,'plain_snapshot_before = cart_snapshot(session)');
check(is_int($plain_post_position) && is_int($plain_before_position) && $plain_before_position < $plain_post_position,'The plain-route preservation snapshot is read BEFORE the POST (native structural check)');
check(str_contains($race_source,'plain_snapshot_before == stale_before'),'The pre-POST snapshot is asserted equal to the known two-line selection — the baseline is not self-derived (native structural check)');
check(str_contains($race_source,'scenario_ids_unique') && str_contains($race_source,"'new_request_count'") && str_contains($race_source,'NEW_REQUEST_COUNT = 11'),'The native regression derives its new-request total from a distinct scenario-id ledger (11 planned), not a hand-typed mail count');
$stack_harness_source=(string)file_get_contents(__DIR__.'/woo-stack-harness.mjs');
check(str_contains($stack_harness_source,'mails.length === 2 * outcomes.new_request_count'),'The notification-event expectation is derived from the scenario ledger, never hand-typed (native structural check)');
check(str_contains($stack_harness_source,'totalUnits(parsed.lostmulti_a) === 70') && str_contains($stack_harness_source,'totalUnits(parsed.lostmulti_b) === 6') && str_contains($stack_harness_source,'totalUnits(parsed.stale) === 7'),'The WP-CLI state block verifies the lostmulti/stale ORIGINALS by their own quantities (A: 70 units, B: 6, stale: 7)');
check(str_contains($stack_harness_source,'lookup_row_a') && str_contains($stack_harness_source,'exactly one original A'),'The WP-CLI state block proves exactly one original A through A\'s own durable lookup row resolving to the record the retry returned');

// Issue #36: the gating chain in its LIVE order — wp_loaded recovery (0) →
// after_checkout_validation gate → woocommerce_create_order claim — driven
// with the REAL normalized submission. No isolated helper return proves the
// chain: each step runs the callback actually registered on its seam.
$chain_recovery_callback=null;
foreach($registered_actions['wp_loaded']??array() as $callback) { if($callback==='fpw_recover_landed_attempt') { $chain_recovery_callback=$callback; break; } }
$chain_gate_callback=null;
foreach($registered_actions['woocommerce_after_checkout_validation']??array() as $callback) { if($callback==='fpw_validate_attempt_identity') { $chain_gate_callback=$callback; break; } }
$chain_claim_callback=null;
foreach($registered_filters['woocommerce_create_order']??array() as $callback) { if($callback==='fpw_checkout_claim') { $chain_claim_callback=$callback; break; } }
check(is_string($chain_recovery_callback) && is_string($chain_gate_callback) && is_string($chain_claim_callback),'The #36 chain rides its live seams: wp_loaded recovery, after-validation gate, create_order claim');
$chain_tokens=array();
foreach(array('x','y') as $suffix) { $chain_tokens[$suffix]=str_repeat($suffix==='x'?'3':'4',40); }
$chain_hashes=array('x'=>fpw_attempt_identity_hash($chain_tokens['x']),'y'=>fpw_attempt_identity_hash($chain_tokens['y']));
$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['x']]='501';
$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['y']]='502';
$GLOBALS['fpw_orders'][501]=new FPW_Fake_Landed_Order(501);
$GLOBALS['fpw_orders'][502]=new FPW_Fake_Landed_Order(502);
$fpw_chain_run=function(string $posted_token,string $open_token,bool $plain_route=false) use ($chain_recovery_callback,$chain_gate_callback) {
	fpw_attempt_inflight_seen(fpw_attempt_identity_hash($posted_token),false);   // a fresh request carries no hash-scoped in-flight evidence
	$GLOBALS['fpw_json_sent']=null; $GLOBALS['fpw_nonce_valid']=true;
	$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$open_token);
	$_GET=$plain_route?array():array('wc-ajax'=>'checkout');
	$_POST=array('fpw_attempt'=>$posted_token,'woocommerce-process-checkout-nonce'=>'synthetic','billing_first_name'=>'Cliente','billing_fp_dispatch'=>'no','order_comments'=>'');
	$chain_recovery_callback();
	if (is_array($GLOBALS['fpw_json_sent'])) { return array('step'=>'recovery','json'=>$GLOBALS['fpw_json_sent']); }
	$probe=new FPW_Real_Woo_Normalization_Probe();
	$errors=new WP_Error();
	$chain_gate_callback($probe->get_posted_data(),$errors);
	if ($errors->has_errors()) { return array('step'=>'gate','errors'=>$errors); }
	return array('step'=>'claim');
};
// A lost its response; B completed later; retry A with its original form: the
// FIRST seam answers with A's own confirmation — nothing else runs.
$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['x']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>501,'at'=>time()));
$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['y']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>502,'at'=>time()));
$chain=$fpw_chain_run($chain_tokens['x'],$chain_tokens['y']);
check($chain['step']==='recovery' && str_contains($chain['json']['redirect']??'','/order-received/501'),'The live chain answers A\'s retry with A\'s own confirmation after B completed (#36)');
$chain_keys_after_recovery=array_keys($GLOBALS['fpw_options_table']);
check(!in_array('fpw_claim_'.$chain_hashes['x'],$chain_keys_after_recovery,true),'The recovery answer created no claim row: the chain never reached order creation');
// An EXPIRED attempt that still equals the open token: recovery declines, the
// gate rejects (never the cart-emptying fold), and the claim seam itself
// would throw if anything reached it.
$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['x']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>501,'at'=>time()-FPW_RECOVERY_MAX_AGE-1));
$chain=$fpw_chain_run($chain_tokens['x'],$chain_tokens['x']);
check($chain['step']==='gate' && in_array('Tu solicitud anterior ya fue recibida. Vuelve a abrir la página de Datos y envío para una nueva solicitud; no se creará una solicitud duplicada.',$chain['errors']->messages,true),'A completed open attempt — fresh or expired — is rejected at the gate seam with cart-preserving reload guidance: never the fold');
try {
	$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$chain_tokens['x']);
	$_POST=array('fpw_attempt'=>$chain_tokens['x'],'woocommerce-process-checkout-nonce'=>'synthetic','billing_first_name'=>'Cliente','billing_fp_dispatch'=>'no','order_comments'=>'');
	$expired_probe=new FPW_Real_Woo_Normalization_Probe();
	fpw_checkout_claim(null,$expired_probe);
	check(false,'The claim seam must reject the expired open attempt (defense in depth)');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The claim seam throws the recoverable reload rejection for the expired open attempt');
}
check(fpw_is_folding_attempt()===false,'No step of the expired chain ever forced the payment/fold path');
// An unknown token: recovery declines, the gate rejects (#35 identity).
$chain=$fpw_chain_run(str_repeat('ef',20),$chain_tokens['y']);
check($chain['step']==='gate' && $chain['errors']->codes===array('fpw_attempt'),'An unknown token is stopped at the gate seam with the #35 identity rejection');
// THE LEAD PROBE REGRESSION (red-gate 1): a DISTINCT request replaying a
// JUST-COMPLETED attempt (finalized claim row, fresh landed_at, valid state)
// through the PLAIN form route (no wc-ajax — the early recovery never runs)
// over an UNRELATED two-line selection. The gate must reject it — a wall-clock
// age is not concurrency evidence — and the claim seam must not fold: no
// confirmation, no fold path, no private note, every cart line intact.
$chain_tokens['z']=str_repeat('5',40);
$chain_hashes['z']=fpw_attempt_identity_hash($chain_tokens['z']);
$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['z']]='606';
$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['z']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>606,'at'=>time()));
$GLOBALS['fpw_options_table']['fpw_claim_'.$chain_hashes['z']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>606,'started'=>time(),'landed_at'=>time()));
$GLOBALS['fpw_orders'][606]=new FPW_Fake_Landed_Order(606);
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Normalization();
$GLOBALS['fpw_woo']->cart->lines=array('new-simple'=>array('quantity'=>3),'new-variant'=>array('quantity'=>5));
$probe_lines=$GLOBALS['fpw_woo']->cart->lines;
$chain_notes_before=$GLOBALS['fpw_order_notes'];
$chain=$fpw_chain_run($chain_tokens['z'],$chain_tokens['z'],true);   // posted == open == just-completed, PLAIN route (no wc-ajax)
check($chain['step']==='gate' && in_array('Tu solicitud anterior ya fue recibida. Vuelve a abrir la página de Datos y envío para una nueva solicitud; no se creará una solicitud duplicada.',$chain['errors']->messages,true),'A fresh post-completion replay over an unrelated selection is rejected at the gate (lead probe: wall-clock age is not concurrency evidence)');
try {
	$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$chain_tokens['z']);
	$_POST=array('fpw_attempt'=>$chain_tokens['z'],'woocommerce-process-checkout-nonce'=>'synthetic','billing_first_name'=>'Cliente','billing_fp_dispatch'=>'no','order_comments'=>'');
	$replay_probe=new FPW_Real_Woo_Normalization_Probe();
	fpw_checkout_claim(null,$replay_probe);
	check(false,'The claim seam must reject the fresh post-completion replay');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The claim seam throws the recoverable reload rejection for the fresh post-completion replay');
}
check(fpw_is_folding_attempt()===false && $GLOBALS['fpw_order_notes']===$chain_notes_before,'The rejected replay never enables the fold path and stamps no private note');
check($GLOBALS['fpw_woo']->cart->lines===$probe_lines,'Every line, variant and quantity of the unrelated selection survives the rejected replay');
// LEAD RED-GATE 2 (crash between the claim-row UPDATE and the permanent lookup
// INSERT): the finalized claim row alone is completion evidence — an absent
// durable lookup proves nothing. A NEW request validating over an unrelated
// selection must be rejected safely, never folded at the claim seam.
$chain_tokens['w']=str_repeat('6',40);
$chain_hashes['w']=fpw_attempt_identity_hash($chain_tokens['w']);
$GLOBALS['fpw_options_table']['fpw_claim_'.$chain_hashes['w']]=json_encode(array('session'=>fpw_session_fingerprint(),'order_id'=>707,'started'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1,'landed_at'=>time()-FPW_CLAIM_TAKEOVER_SECONDS-1));
$chain=$fpw_chain_run($chain_tokens['w'],$chain_tokens['w'],true);   // NO fpw_attempt_ lookup row exists at all
check($chain['step']==='gate' && $chain['errors']->codes===array('fpw_attempt'),'A finalized claim row with the permanent lookup absent still rejects a new request at the gate (absent lookup is not unfinalized evidence)');
try {
	$GLOBALS['fpw_woo']->session->set('fpw_attempt_open',$chain_tokens['w']);
	$_POST=array('fpw_attempt'=>$chain_tokens['w'],'woocommerce-process-checkout-nonce'=>'synthetic','billing_first_name'=>'Cliente','billing_fp_dispatch'=>'no','order_comments'=>'');
	$crash_probe=new FPW_Real_Woo_Normalization_Probe();
	fpw_checkout_claim(null,$crash_probe);
	check(false,'The claim seam must not fold the crash-window completed record');
} catch (Exception $e) {
	check(str_contains($e->getMessage(),'no se creará una solicitud duplicada'),'The claim seam throws the reload rejection for the finalized-claim-without-lookup record');
}
check(fpw_is_folding_attempt()===false && $GLOBALS['fpw_woo']->cart->lines===$probe_lines,'No fold, no cart damage on the crash-window rejection');
// B stays recoverable while A is expired — the full chain, per attempt.
$chain=$fpw_chain_run($chain_tokens['y'],$chain_tokens['y']);
check($chain['step']==='recovery' && str_contains($chain['json']['redirect']??'','/order-received/502'),'B\'s own confirmation recovers through the chain while A is expired (per-attempt vigencia)');
unset($_GET,$_POST,$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['x']],$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['y']],$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['x']],$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['y']],$GLOBALS['fpw_options_table']['fpw_attempt_'.$chain_hashes['z']],$GLOBALS['fpw_options_table']['fpw_recovery_'.$chain_hashes['z']],$GLOBALS['fpw_options_table']['fpw_claim_'.$chain_hashes['z']],$GLOBALS['fpw_options_table']['fpw_claim_'.$chain_hashes['w']]);

// Woo seam registration: the claim rides Woo's own order-creation short-circuit and its create/exception lifecycle.
check(in_array('fpw_checkout_claim',$registered_filters['woocommerce_create_order']??array(),true),'The claim integrates Woo\'s own woocommerce_create_order short-circuit');
$create_callbacks=$registered_actions['woocommerce_checkout_create_order']??array();
check(count($create_callbacks)>=2,'The order-creation hook carries both the attempt binding and the local field meta');
check(count($registered_actions['woocommerce_checkout_order_created']??array())>=1,'The claim finalizes on woocommerce_checkout_order_created');
check(count($registered_actions['woocommerce_checkout_order_exception']??array())>=1,'The claim releases on woocommerce_checkout_order_exception');
check(in_array('fpw_recover_landed_attempt',$registered_actions['wp_loaded']??array(),true),'The landed-attempt retry recovery rides wp_loaded ahead of Woo\'s own checkout AJAX');
check(in_array('fpw_validate_attempt_identity',$registered_actions['woocommerce_after_checkout_validation']??array(),true),'The attempt-identity gate rides Woo\'s own after-validation seam (issue #35)');
check(defined('FPW_RECOVERY_MAX_AGE') && FPW_RECOVERY_MAX_AGE===DAY_IN_SECONDS,'The recovery lifetime is a defined, deliberate constant: one day (issue #32)');
$plugin_source=file_get_contents(__DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php');
check((bool)preg_match('/fpw_attempt_open|fpw_attempt_landed/',$plugin_source),'The attempt identity lives in the customer\'s own session, not in a parallel store');
// The attempt meta is bound on the created order.
class FPW_Fake_Order { public array $meta=array(); public function __construct(private int $id=0) {} public function update_meta_data($key,$value) { $this->meta[$key]=$value; } public function get_id(): int { return $this->id; } }
$order=new FPW_Fake_Order();
foreach($create_callbacks as $callback) { if (is_object($callback)) { $callback($order,fpw_attempt_posted()); } }
check(($order->meta['_fpw_attempt']??'')===$attempt_hash,'An owned attempt binds its identity durably on the created order');

// ---------------------------------------------------------------------
// Issue #37 (SP-01/ST-03): the REAL pinned order-mutation controllers. The
// audit family (pinned WC_AJAX registration + src/Internal): every order-
// record-mutating wp_ajax member is either denied at the ventas front door or
// natively gated on a capability the role does not carry; the two controllers
// that gate on edit_shop_orders ALONE — TaxesController (saves the submitted
// items before recalculating taxes) and CouponsController (applies coupons to
// the record) — are dispatched here with a VALID nonce fixture and a
// persistence tripwire: the front door must die BEFORE the controller runs,
// and with the front door removed (the positive control) the REAL controller
// must reach the write boundary, proving the probes detect a removed guard.
$mutation_controllers_sidecar=json_decode((string)file_get_contents(__DIR__.'/vendor/woocommerce-11.1.0-order-mutation-controllers.json'),true);
check(is_array($mutation_controllers_sidecar) && (string)($mutation_controllers_sidecar['zip_sha256'] ?? '')===(string)$woo_pinned['woocommerce']['sha256'],'The vendored mutation controllers chain to the pinned woocommerce zip');
foreach (($mutation_controllers_sidecar['files'] ?? array()) as $vendored_controller) {
	check(hash_file('sha256',__DIR__.'/vendor/'.$vendored_controller['file'])===$vendored_controller['file_sha256'],'The vendored '.$vendored_controller['file'].' is byte-identical to its pinned sha256');
}
require __DIR__.'/vendor/woocommerce-11.1.0-taxes-controller.php';
require __DIR__.'/vendor/woocommerce-11.1.0-coupons-controller.php';
if (!function_exists('check_ajax_referer')) { function check_ajax_referer($action=-1,$field='ajax_nonce') { if (('calc-totals'===$action||'order-item'===$action) && (string)($_REQUEST[$field] ?? '')!=='valid-fixture') { throw new RuntimeException('invalid nonce fixture'); } return 1; } }
if (!function_exists('wc_save_order_items')) { function wc_save_order_items($order_id,$items) { $GLOBALS['fpw_persistence'][]=array('boundary'=>'wc_save_order_items','order_id'=>$order_id,'items'=>$items); throw new RuntimeException('STOP: persistence tripwire; nothing actually saved'); } }
if (!function_exists('wc_strtoupper')) { function wc_strtoupper($value) { return strtoupper((string)$value); } }
class FPW_Stub_ArrayUtil { public static function get_value_or_default($array,$key,$default=null) { return is_array($array)&&array_key_exists($key,$array)?$array[$key]:$default; } }
class FPW_Stub_StringUtil { public static function is_null_or_whitespace($value) { return null===$value||''===trim((string)$value); } }
class_alias('FPW_Stub_ArrayUtil','Automattic\WooCommerce\Utilities\ArrayUtil');
class_alias('FPW_Stub_StringUtil','Automattic\WooCommerce\Utilities\StringUtil');
class FPW_Tripwire_Order { public function __construct(private int $id) {} public function get_id(): int { return $this->id; } public function __call($name,$args) { $GLOBALS['fpw_persistence'][]=array('boundary'=>$name,'order_id'=>$this->id); throw new Error('STOP: '.$name.' boundary; nothing actually saved'); } }
$GLOBALS['fpw_persistence']=array();
$GLOBALS['fpw_orders'][401]=new FPW_Tripwire_Order(401);
// The bounded route-to-guard family (pinned WC_AJAX registration, issue #37):
// every order-record-mutating member must sit in the ventas denylist.
$fpw_order_mutating_ajax=array('woocommerce_mark_order_status','woocommerce_delete_order_note','woocommerce_save_order_items','woocommerce_add_order_item','woocommerce_add_order_fee','woocommerce_add_order_shipping','woocommerce_add_order_tax','woocommerce_add_coupon_discount','woocommerce_calc_line_taxes','woocommerce_order_add_meta','woocommerce_order_delete_meta','woocommerce_remove_order_item','woocommerce_remove_order_coupon','woocommerce_remove_order_tax','woocommerce_refund_line_items','woocommerce_delete_refund','woocommerce_grant_access_to_download','woocommerce_revoke_access_to_download');
foreach ($fpw_order_mutating_ajax as $mutating_action) { check(in_array($mutating_action,FPW_SALES_DENIED_AJAX,true),'The family audit closes '.$mutating_action.' for ventas'); }
// Kept surfaces stay open: reads, the approved note flow, and surfaces whose
// native gates already exclude the role (feature_product needs edit_products;
// product/variation/tax/shipping/api-key handlers need manage_woocommerce or
// product caps; order_add_meta/order_delete_meta are ALSO natively
// manage_woocommerce-gated — denied at the front door as defense in depth).
foreach (array('woocommerce_get_order_details','woocommerce_load_order_items','woocommerce_add_order_note','woocommerce_json_search_order_metakeys','woocommerce_get_customer_details','woocommerce_feature_product') as $kept_action) { check(!in_array($kept_action,FPW_SALES_DENIED_AJAX,true),'The kept surface '.$kept_action.' stays off the denylist'); }
// TaxesController: valid nonce + restricted actor → the front door dies BEFORE
// the controller; with the front door removed, the REAL controller saves the
// submitted quantities (the positive control proves the probe detects it).
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$_REQUEST=array('action'=>'woocommerce_calc_line_taxes','security'=>'valid-fixture','order_id'=>10,'items'=>'order_item_id[]=1&order_item_qty[1]=999'); $_POST=$_REQUEST; $_GET=array();
check(fpw_guard_denies()==='403' && $GLOBALS['fpw_persistence']===array(),'A valid-nonce tax recalculation from ventas is denied at the front door before the controller runs');
try {
	(new Automattic\WooCommerce\Internal\Orders\TaxesController())->calc_line_taxes_via_ajax();
	check(false,'the pinned TaxesController must reach the persistence tripwire');
} catch (RuntimeException $tripwire) {
	check(str_contains($tripwire->getMessage(),'STOP: persistence tripwire'),'With the guard removed the REAL pinned controller reaches wc_save_order_items — the probe detects the removed guard');
}
check(($GLOBALS['fpw_persistence'][0]['boundary']??'')==='wc_save_order_items' && (int)($GLOBALS['fpw_persistence'][0]['items']['order_item_qty'][1] ?? 0)===999,'The submitted quantity 999 reached the persistence boundary — the denial evidence is the mutation that never happened, not a response code');
$GLOBALS['fpw_persistence']=array();
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true,'manage_woocommerce'=>true);
check(fpw_guard_denies()==='pass','Managers pass the front door for tax recalculation');
try { (new Automattic\WooCommerce\Internal\Orders\TaxesController())->calc_line_taxes_via_ajax(); check(false,'unreachable'); } catch (RuntimeException $tripwire) { check(str_contains($tripwire->getMessage(),'STOP: persistence tripwire'),'Managers keep the native tax recalculation (the controller still runs)'); }
// CouponsController: same pattern through the coupon boundary.
$GLOBALS['fpw_persistence']=array();
$GLOBALS['fpw_user_caps']=array('edit_shop_orders'=>true);
$_REQUEST=array('action'=>'woocommerce_add_coupon_discount','security'=>'valid-fixture','order_id'=>401,'coupon'=>'VENTAS-PRUEBA'); $_POST=$_REQUEST; $_GET=array();
check(fpw_guard_denies()==='403' && $GLOBALS['fpw_persistence']===array(),'A valid-nonce coupon-discount add from ventas is denied at the front door before the controller runs');
try {
	(new Automattic\WooCommerce\Internal\Orders\CouponsController())->add_coupon_discount_via_ajax();
	check(false,'the pinned CouponsController must reach the order boundary');
} catch (Error $tripwire) {
	check(str_contains($tripwire->getMessage(),'calculate_taxes boundary'),'With the guard removed the REAL pinned controller rewrites the record through the order object — the probe detects the removed guard');
}
check(($GLOBALS['fpw_persistence'][0]['boundary']??'')==='calculate_taxes' && (int)($GLOBALS['fpw_persistence'][0]['order_id']??0)===401,'The coupon flow reached the record boundary for order 401');
unset($GLOBALS['fpw_options_table'],$GLOBALS['wpdb'],$GLOBALS['fpw_order_notes'],$GLOBALS['fpw_session_customer_id'],$GLOBALS['wp_filter']);

echo "checks: {$assertions} local assertions passed (checkout fields + header line count + unpriced review table + variation button state + quantity-change feedback + sales role + featured grid block + attempt identity vs content + submitted attempt identity through the real Woo normalization + landed-attempt retry recovery with lifetime and cart preservation)\n";
