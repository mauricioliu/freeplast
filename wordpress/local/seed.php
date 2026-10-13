<?php
/** Local-only, resumable demo seed. Completed records stay editable until explicit reset. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' ||
    !function_exists('fpw_local_capture_active') || !fpw_local_capture_active()) {
    throw new RuntimeException('Local CLI with working mail containment required.');
}
$raw = json_decode(file_get_contents('/opt/catalog/products.json'), true, 512, JSON_THROW_ON_ERROR);
$items = $raw['products'] ?? $raw;
// Verify every source photo before any database write; never fetch external media.
foreach ($items as $item) {
    if (empty($item['image'])) { continue; }
    $path = realpath('/opt/catalog/' . $item['image']['file']);
    if (!$path || !str_starts_with($path, '/opt/catalog/') ||
        'sha256:' . hash_file('sha256', $path) !== $item['image']['checksum']) {
        throw new RuntimeException('Local photo missing or checksum mismatch: ' . $item['slug']);
    }
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$cats = array();
foreach (array('agricola' => 'Agrícola', 'otros' => 'Otros') as $slug => $label) {
    $term = term_exists($slug, 'product_cat') ?: wp_insert_term($label, 'product_cat', array('slug' => $slug));
    if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); }
    $cats[$slug] = (int) (is_array($term) ? $term['term_id'] : $term);
}
$specs = array('material'=>'Material', 'dimensions'=>'Medidas', 'weight'=>'Peso propio', 'use'=>'Uso', 'units_per_pallet'=>'Unidades por pallet', 'capacity'=>'Capacidad', 'interior_dimensions'=>'Medidas interiores', 'color'=>'Color', 'features'=>'Características', 'pallet_dimensions'=>'Medidas del pallet');
foreach ($items as $item) {
    $found = get_posts(array('post_type'=>'product', 'name'=>$item['slug'], 'post_status'=>'any', 'numberposts'=>1, 'fields'=>'ids'));
    $id = (int) ($found[0] ?? 0);
    if ($id && get_post_meta($id, '_fp_local_seed_complete', true)) { continue; }
    $colors = array_column($item['options'] ?? array(), 'label');
    $product = $colors ? new WC_Product_Variable($id) : new WC_Product_Simple($id);
    $product->set_name($item['title']);
    $product->set_slug($item['slug']);
    $product->set_description($item['description']);
    $product->set_short_description($item['excerpt']);
    $product->set_status('publish');
    $product->set_category_ids(array($cats[$item['category']] ?? $cats['otros']));
    $product->set_featured(!empty($item['featured']));
    $product->set_reviews_allowed(false);
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');
    $product->update_meta_data('_fp_unpriced', 'yes');
    $product->update_meta_data('qwc_enable_quotes', 'on');
    $attributes = array();
    foreach ($specs as $key => $label) {
        if (empty($item['specs'][$key]) || ($key === 'color' && $colors)) { continue; }
        $attribute = new WC_Product_Attribute();
        $attribute->set_name($label);
        $attribute->set_options(array((string) $item['specs'][$key]));
        $attribute->set_visible(true);
        $attributes[] = $attribute;
    }
    if ($colors) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Color');
        $attribute->set_options($colors);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    } else { $product->set_regular_price('0'); }
    $product->set_attributes($attributes);
    $product->save();
    foreach ($colors as $color) {
        $store = new WC_Product_Data_Store_CPT();
        if ($store->find_matching_product_variation($product, array('attribute_color'=>$color))) { continue; }
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_attributes(array('color'=>$color));
        $variation->set_status('publish');
        $variation->set_regular_price('0');
        $variation->update_meta_data('_fp_unpriced', 'yes');
        $variation->save();
    }
    if ($colors) { WC_Product_Variable::sync($product->get_id()); }
    if (!empty($item['image']) && !$product->get_image_id() && !in_array($item['slug'], array('caja-paltera', 'traversa-para-bins-tipo-romano'), true)) {
        $source = '/opt/catalog/' . $item['image']['file'];
        $temp = wp_tempnam(basename($source));
        if (!$temp || !copy($source, $temp)) { throw new RuntimeException('Local photo copy failed.'); }
        $attachment = media_handle_sideload(array('name'=>basename($source), 'tmp_name'=>$temp), $product->get_id());
        if (file_exists($temp)) { unlink($temp); }
        if (is_wp_error($attachment)) { throw new RuntimeException($attachment->get_error_message()); }
        update_post_meta($attachment, '_wp_attachment_image_alt', $item['image']['alt']);
        $product->set_image_id($attachment);
    }
    $product->update_meta_data('_fp_local_seed_complete', '1');
    $product->save();
}
$pages = array(
    'inicio'=>array('Inicio', ''), 'tienda'=>array('Catálogo', ''),
    'cotizacion'=>array('Productos a Cotizar', file_get_contents('/opt/woo-cart.html')),
    'datos-y-envio'=>array('Datos y envío', '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->'),
    'nosotros'=>array('Nosotros', '<p>Contenido sintético local.</p>'),
    'contacto'=>array('Contacto', '<p>Demostración local. No enviar datos reales.</p>'),
    'politica-de-privacidad'=>array('Política de privacidad', '<p>Prueba local con datos ficticios.</p>'),
);
$ids = array();
foreach ($pages as $slug => [$title, $content]) {
    $page = get_page_by_path($slug);
    $id = $page ? $page->ID : wp_insert_post(array('post_type'=>'page', 'post_status'=>'publish', 'post_name'=>$slug, 'post_title'=>$title, 'post_content'=>$content), true);
    if (is_wp_error($id) || !$id) { throw new RuntimeException('Local page seed failed.'); }
    $ids[$slug] = $id;
}
if (!get_option('fp_local_options_seeded')) {
    foreach (array(
        'blog_public'=>0, 'show_on_front'=>'page', 'page_on_front'=>$ids['inicio'],
        'wp_page_for_privacy_policy'=>$ids['politica-de-privacidad'],
        'woocommerce_shop_page_id'=>$ids['tienda'], 'woocommerce_cart_page_id'=>$ids['cotizacion'], 'woocommerce_checkout_page_id'=>$ids['datos-y-envio'],
        'woocommerce_currency'=>'CLP', 'woocommerce_price_num_decimals'=>'0', 'woocommerce_default_country'=>'CL',
        'woocommerce_calc_taxes'=>'no', 'woocommerce_manage_stock'=>'no', 'woocommerce_hold_stock_minutes'=>'0',
        'woocommerce_enable_coupons'=>'no', 'woocommerce_enable_guest_checkout'=>'yes',
        'woocommerce_enable_signup_and_login_from_checkout'=>'no', 'woocommerce_enable_signup_from_checkout'=>'no', 'woocommerce_enable_myaccount_registration'=>'no',
        'qwc_enable_global_quote'=>'on', 'qwc_enable_global_prices'=>'off', 'qwc_hide_address_fields'=>'on',
        'qwc_add_to_cart_button_text'=>'Agregar a Productos a Cotizar', 'qwc_cart_page_name'=>'Productos a Cotizar',
        'qwc_checkout_page_name'=>'Datos y envío', 'qwc_place_order_text'=>'Solicitar cotización', 'qwc_proceed_checkout_btn_label'=>'Continuar con mis datos', 'qwc_menu_notice'=>'dismissed',
        'woocommerce_quotes-gateway_settings'=>array('enabled'=>'yes', 'title'=>'Solicitud de cotización sin pago'),
        'woocommerce_permalinks'=>array('product_base'=>'/producto', 'category_base'=>'categoria-producto', 'tag_base'=>'etiqueta-producto'),
    ) as $key => $value) { update_option($key, $value); }
    update_option('fp_local_options_seeded', '1');
}
delete_transient('_wc_activation_redirect');
$product = wc_get_products(array('type'=>'simple', 'status'=>'publish', 'limit'=>1))[0] ?? null;
if (!$product) { throw new RuntimeException('No demo product available.'); }
foreach (array('retiro', 'despacho') as $kind) {
    $orders = wc_get_orders(array('limit'=>1, 'meta_key'=>'_fp_local_fixture', 'meta_value'=>$kind));
    $order = $orders[0] ?? new WC_Order();
    if (!$order->get_id()) {
        $order->set_status('pending');
        $order->set_created_via('freeplast-local');
        $order->set_payment_method('quotes-gateway');
        $order->set_billing_first_name('PRUEBA LOCAL ' . $kind);
        $order->set_billing_company('Empresa ficticia');
        $order->set_billing_email($kind . '@example.invalid');
        $order->set_billing_phone('+56 9 5555 5555');
        foreach (array('_fp_local_fixture'=>$kind, '_fp_request'=>'yes', '_qwc_quote'=>'1', '_quote_status'=>'quote-pending', '_billing_fp_dispatch'=>$kind === 'despacho' ? 'si' : 'no', '_billing_fp_address'=>$kind === 'despacho' ? 'Dirección ficticia de prueba' : '') as $key => $value) { $order->update_meta_data($key, $value); }
        $order->update_meta_data('_fp_submitted_details', array('billing_first_name'=>'PRUEBA LOCAL ' . $kind, 'billing_email'=>$kind . '@example.invalid', 'billing_fp_dispatch'=>$kind === 'despacho' ? 'si' : 'no'));
        $order->save();
    }
    if (!$order->get_items()) { $order->add_product($product, 10, array('subtotal'=>0, 'total'=>0)); $order->save(); }
    fpw_create_request_draft($order);
    if (!fpw_read_request_draft($order->get_id())) { throw new RuntimeException('Local draft seed failed.'); }
}
wp_cache_flush();
WP_CLI::success('Local catalog, pages and two synthetic requests ready; existing completed records preserved.');
