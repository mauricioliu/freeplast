<?php
/** One-way, resumable migration of the approved staging. Run only after paired DB/files backup.
 * wp eval-file /bundle/migrate-to-woo.php
 * Original quote posts and their metadata remain untouched; product IDs/media are retained.
 * After migration Woo is the editable authority. This is NOT a recurring JSON synchronizer.
 */
if (!defined('WP_CLI') || !WP_CLI || 'https://freeplast.mliu.site' !== untrailingslashit(get_option('home'))) { throw new RuntimeException('Staging WP-CLI only.'); }
if (!class_exists('WooCommerce') || !class_exists('Quotes_WC')) { WP_CLI::error('Activate WooCommerce and Quotes for WooCommerce first.'); }
function fpw_decode_meta($id, $key) { return json_decode((string)get_post_meta($id,$key,true),true) ?: array(); }
$category_ids = array();
foreach (array('agricola'=>'Agrícola','otros'=>'Otros') as $slug=>$label) {
    $term = term_exists($slug,'product_cat');
    if (!$term) { $term = wp_insert_term($label,'product_cat',array('slug'=>$slug)); }
    if (is_wp_error($term)) { WP_CLI::error($term->get_error_message()); }
    $category_ids[$slug] = (int)$term['term_id'];
}
$aliases = get_option('fpw_legacy_paths', array());
$posts = get_posts(array('post_type'=>array('fp_product','product'),'post_status'=>'any','numberposts'=>-1,'meta_key'=>'_fp_source_id'));
foreach ($posts as $post) {
    $id = $post->ID;
    if (get_post_meta($id,'_fpw_migrated',true)) { continue; }
    $meta = static fn($key) => get_post_meta($id,$key,true);
    $options = fpw_decode_meta($id,'_fp_options');
    wp_update_post(array('ID'=>$id,'post_type'=>'product'));
    wp_set_object_terms($id, $options ? 'variable' : 'simple', 'product_type');
    clean_post_cache($id);
    $product = $options ? new WC_Product_Variable($id) : new WC_Product_Simple($id);
    $product->set_name($post->post_title);
    $product->set_description($meta('_fp_description') ?: $post->post_content);
    $product->set_short_description($post->post_excerpt);
    $product->set_sku($meta('_fp_source_id'));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock'); // Technical eligibility only, not a public availability promise.
    $product->set_catalog_visibility('visible');
    $product->set_category_ids(array($category_ids[$meta('_fp_category')] ?? $category_ids['otros']));
    $product->set_featured('1' === $meta('_fp_featured'));
    $product->set_menu_order((int)$meta('_fp_featured_order'));
    $product->set_reviews_allowed(false);
    $attributes = array();
    foreach (array('_fp_material'=>'Material','_fp_dimensions'=>'Medidas','_fp_weight_text'=>'Peso propio','_fp_use'=>'Uso','_fp_units_per_pallet'=>'Unidades por pallet') as $key=>$label) {
        $value = $meta($key);
        if ($value === '' || $value === '0') { continue; }
        $attribute = new WC_Product_Attribute(); $attribute->set_name($label); $attribute->set_options(array((string)$value)); $attribute->set_visible(true); $attributes[]=$attribute;
    }
    if ($options) {
        $attribute = new WC_Product_Attribute(); $attribute->set_name('Color'); $attribute->set_options(array_column($options,'label')); $attribute->set_visible(true); $attribute->set_variation(true); $attributes[]=$attribute;
    } else {
        $product->set_regular_price('0'); // Unpriced sentinel required by Woo; never a commercial offer.
    }
    $product->set_attributes($attributes);
    $product->update_meta_data('_fp_unpriced','yes');
    $product->save();
    foreach ($options as $option) {
        $sku = $meta('_fp_source_id').'-'.$option['id'];
        $variation_id = wc_get_product_id_by_sku($sku);
        $variation = new WC_Product_Variation($variation_id ?: 0);
        $variation->set_parent_id($id); $variation->set_sku($sku); $variation->set_attributes(array('color'=>$option['label']));
        $variation->set_regular_price('0'); $variation->set_manage_stock(false); $variation->set_stock_status('instock'); $variation->set_status('publish');
        $variation->update_meta_data('_fp_unpriced','yes'); $variation->save();
    }
    if ($options) { WC_Product_Variable::sync($id); }
    foreach (fpw_decode_meta($id,'_fp_legacy_paths') as $path) { $aliases[$path]=$id; }
    update_post_meta($id,'_fpw_migrated',gmdate('c'));
    wc_delete_product_transients($id);
}
update_option('fpw_legacy_paths',$aliases,false);

// Preserve historical requests inside Woo Orders, leaving original private posts intact.
$legacy_ids = get_posts(array('post_type'=>'fp_quote','post_status'=>'any','numberposts'=>-1,'fields'=>'ids'));
$map = get_option('fpw_legacy_order_map',array());
foreach ($legacy_ids as $id) {
    if (isset($map[$id]) && wc_get_order($map[$id])) { continue; }
    $existing = wc_get_orders(array('limit'=>1,'meta_key'=>'_fp_legacy_post_id','meta_value'=>$id));
    if ($existing) { $order=$existing[0]; $map[$id]=$order->get_id(); continue; }
    $submitted = fpw_decode_meta($id,'_fpq_customer');
    $customer = fpw_decode_meta($id,'_fpq_current') ?: $submitted;
    $order = new WC_Order();
    $order->set_created_via('freeplast-legacy-migration');
    $order->set_payment_method('quotes-gateway');
    $order->set_billing_first_name($customer['nombre'] ?? '');
    $order->set_billing_company($customer['empresa'] ?? '');
    $order->set_billing_email($customer['email'] ?? '');
    $order->set_billing_phone($customer['telefono_normalizado'] ?? $customer['telefono'] ?? '');
    $order->set_customer_note($submitted['mensaje'] ?? '');
    $order->set_date_created(get_post_field('post_date_gmt',$id).' UTC');
    $order->update_meta_data('_fp_legacy_post_id',$id);
    $order->update_meta_data('_fp_request','yes');
    $order->update_meta_data('_qwc_quote','1');
    $order->update_meta_data('_quote_status','quote-pending');
    $order->update_meta_data('_fp_submitted_details',$submitted);
    foreach (get_post_meta($id) as $key=>$values) {
        if (str_starts_with($key,'_fpq_')) { $order->update_meta_data($key,maybe_unserialize($values[0])); }
    }
    foreach (array('rut'=>'rut','giro'=>'giro','dispatch'=>'con_despacho','address'=>'direccion_despacho') as $target=>$source) { $order->update_meta_data('_billing_fp_'.$target,$customer[$source] ?? $submitted[$source] ?? ''); }
    foreach (fpw_decode_meta($id,'_fpq_items') as $line) {
        $item = new WC_Order_Item_Product();
        $item->set_name($line['title']); $item->set_product_id((int)$line['post_id']); $item->set_quantity((int)$line['quantity']);
        if (!empty($line['option_label'])) { $item->add_meta_data('Color',$line['option_label']); }
        $item->add_meta_data('_fp_original_line',$line); $order->add_item($item);
    }
    $order->set_status('pending'); $order->calculate_totals(); $order->save();
    $order->add_order_note('Solicitud histórica importada. Estado comercial original: '.get_post_meta($id,'_fpq_status',true));
    foreach (fpw_decode_meta($id,'_fpq_notes') as $note) { $order->add_order_note(($note['time'] ?? '').' — '.($note['text'] ?? '')); }
    $map[$id]=$order->get_id(); update_option('fpw_legacy_order_map',$map,false);
}
update_option('fpw_legacy_order_map',$map,false);

function fpw_page($slug,$title,$content) {
    $page=get_page_by_path($slug);
    if ($page && get_post_meta($page->ID,'_fpw_page_migrated',true)) { return $page->ID; }
    if ($page) { update_post_meta($page->ID,'_fpw_previous_content',$page->post_content); }
    $id=wp_insert_post(array('ID'=>$page ? $page->ID : 0,'post_type'=>'page','post_status'=>'publish','post_name'=>$slug,'post_title'=>$title,'post_content'=>$content),true);
    if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
    update_post_meta($id,'_fpw_page_migrated','1'); return $id;
}
$checkout=fpw_page('datos-y-envio','Datos y envío', '<!-- wp:paragraph --><p>Solicita tu cotización sin registro ni pago. Los datos de empresa se utilizarán para una eventual facturación.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->');
$cart_content=file_get_contents(__DIR__.'/woo-cart.html');
$cart=fpw_page('cotizacion','Productos a Cotizar',$cart_content);
$shop=fpw_page('tienda','Catálogo','');
foreach (array('woocommerce_shop_page_id'=>$shop,'woocommerce_cart_page_id'=>$cart,'woocommerce_checkout_page_id'=>$checkout,'woocommerce_currency'=>'CLP','woocommerce_price_num_decimals'=>'0','woocommerce_default_country'=>'CL','woocommerce_calc_taxes'=>'no','woocommerce_manage_stock'=>'no','woocommerce_hold_stock_minutes'=>'0','woocommerce_enable_coupons'=>'no','woocommerce_enable_guest_checkout'=>'yes','woocommerce_enable_signup_and_login_from_checkout'=>'no','woocommerce_enable_signup_from_checkout'=>'no','woocommerce_enable_myaccount_registration'=>'no','woocommerce_coming_soon'=>'no','woocommerce_store_pages_only'=>'no','qwc_enable_global_quote'=>'on','qwc_enable_global_prices'=>'off','qwc_hide_address_fields'=>'on','qwc_add_to_cart_button_text'=>'Agregar a Productos a Cotizar','qwc_cart_page_name'=>'Productos a Cotizar','qwc_checkout_page_name'=>'Datos y envío','qwc_place_order_text'=>'Solicitar cotización','qwc_proceed_checkout_btn_label'=>'Datos y envío','qwc_menu_notice'=>'dismissed') as $key=>$value) { update_option($key,$value); }
update_option('woocommerce_quotes-gateway_settings',array('enabled'=>'yes','title'=>'Solicitud de cotización — sin pago'));
update_option('woocommerce_permalinks',array('product_base'=>'/producto','category_base'=>'categoria-producto','tag_base'=>'etiqueta-producto'));
$privacy = get_page_by_path('politica-de-privacidad');
if ($privacy) {
    update_option('wp_page_for_privacy_policy',$privacy->ID);
    if (!get_post_meta($privacy->ID,'_fpw_privacy_migrated',true)) {
        update_post_meta($privacy->ID,'_fpw_previous_content',$privacy->post_content);
        $content = preg_replace('/<p>Tu selección de productos se guarda.*?<\/p>/s', '<p>Los Productos a Cotizar se conservan temporalmente mediante cookies técnicas. Estas permiten mantener los productos y cantidades mientras navegas y preparas tu solicitud.</p>', $privacy->post_content);
        wp_update_post(array('ID'=>$privacy->ID,'post_content'=>$content));
        update_post_meta($privacy->ID,'_fpw_privacy_migrated','1');
    }
}
update_option('blog_public','0');
update_option('fpw_migration_version',1,false);
flush_rewrite_rules(false);
WP_CLI::success('Migrated catalog: '.count($posts).'; historical requests preserved/mapped: '.count($map).'. No original quotes deleted.');
