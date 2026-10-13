<?php
/** Operator gate only: run through the local CLI container after seed; never auto-run. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !fpw_local_capture_active()) {
    throw new RuntimeException('Working local containment required.');
}
function fp_local_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
fp_local_check(is_readable('/run/secrets/db_password') && is_readable('/run/secrets/admin_password'), 'uid33 cannot read private secret binds');
fp_local_check((int) get_current_user_id() >= 0 && is_writable(FPW_LOCAL_CAPTURE_DIR), 'capture not writable');
fp_local_check(is_wp_error(wp_remote_get('https://example.invalid/blocked-by-local-test')), 'HTTP containment missing');
$orders = wc_get_orders(array('limit'=>-1, 'meta_key'=>'_fp_local_fixture', 'meta_compare'=>'EXISTS'));
fp_local_check(count($orders) === 2, 'synthetic requests missing or duplicated');
foreach ($orders as $order) { fp_local_check(null !== fpw_read_request_draft($order->get_id()), 'request draft missing'); }
foreach (array('inicio', 'tienda', 'cotizacion', 'datos-y-envio', 'nosotros', 'contacto', 'politica-de-privacidad') as $slug) {
    fp_local_check((bool) get_page_by_path($slug), 'page missing: ' . $slug);
}
$raw = json_decode(file_get_contents('/opt/catalog/products.json'), true, 512, JSON_THROW_ON_ERROR);
$catalog = $raw['products'] ?? $raw;
foreach ($catalog as $item) {
    $post = get_page_by_path($item['slug'], OBJECT, 'product');
    fp_local_check((bool) $post, 'catalog product missing');
    $product = wc_get_product($post->ID);
    foreach (array_column($item['options'] ?? array(), 'label') as $color) {
        fp_local_check((bool) (new WC_Product_Data_Store_CPT())->find_matching_product_variation($product, array('attribute_color'=>$color)), 'color fixture not selectable');
    }
    if (!empty($item['image']) && !in_array($item['slug'], array('caja-paltera', 'traversa-para-bins-tipo-romano'), true)) {
        fp_local_check((bool) $product->get_image_id(), 'catalog photo missing');
    }
}
$version = array('reference'=>'LOCAL-NO-ATENDER', 'version'=>1, 'approved_at'=>time(), 'buyer'=>array('company'=>'Empresa ficticia'), 'projection'=>array(
    'complete'=>true, 'lines'=>array(array('name'=>'Producto ficticio', 'options'=>array(), 'quantity'=>2, 'price'=>1000, 'line_total'=>2000)),
    'subtotal'=>2000, 'dispatch_requested'=>false, 'dispatch'=>0, 'tax_rate_permille'=>190, 'tax'=>380, 'total'=>2380, 'validity_days'=>15,
));
$pdf = fpw_quotation_render_document($version);
fp_local_check(fpw_quotation_pdf_is_valid($pdf), 'real PDF renderer failed');
$path = FPW_LOCAL_CAPTURE_DIR . '/runtime-check.pdf';
fp_local_check(strlen($pdf) === file_put_contents($path, $pdf), 'PDF write failed');
chmod($path, 0600);
try {
    fp_local_check(wp_mail('local-check@example.invalid', 'LOCAL runtime check', 'Synthetic mail; never delivered.', array(), array($path)), 'capture rejected complete evidence');
    $lines = file(FPW_LOCAL_CAPTURE_DIR . '/index.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
    $record = json_decode(file_get_contents(FPW_LOCAL_CAPTURE_DIR . '/' . $last['file']), true, 512, JSON_THROW_ON_ERROR);
    fp_local_check($record['simulated'] === true && $record['attachments'][0]['sha256'] === hash('sha256', $pdf), 'captured attachment differs from real PDF');
    fp_local_check(file_get_contents($record['attachments'][0]['captured_as']) === $pdf, 'captured PDF bytes differ');
} finally { unlink($path); }
$ids = array_map(static fn($order) => $order->get_id(), $orders);
sort($ids);
echo json_encode(array('status'=>'ok', 'products'=>count($catalog), 'order_ids'=>$ids, 'capture'=>'simulated with exact PDF bytes', 'external_http'=>'blocked'), JSON_THROW_ON_ERROR) . "\n";
