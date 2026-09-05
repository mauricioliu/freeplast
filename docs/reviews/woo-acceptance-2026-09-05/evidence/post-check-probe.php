<?php
if(!defined('WP_CLI')||get_option('home')!=='https://freeplast.mliu.site') throw new RuntimeException('Staging only');
$orders=[];
foreach([66,67,68] as $id){
 $o=wc_get_order($id);if(!$o || !str_ends_with($o->get_billing_email(),'@example.invalid')) WP_CLI::error('Not the synthetic order');
 $lines=[];foreach($o->get_items() as $item) $lines[]=['product'=>$item->get_product_id(),'variation'=>$item->get_variation_id(),'qty'=>$item->get_quantity()];
 $orders[]=['id'=>$id,'status'=>$o->get_status(),'quote_status'=>$o->get_meta('_quote_status'),'paid'=>$o->is_paid(),'needs_payment'=>$o->needs_payment(),'stock_reduced'=>(bool)$o->get_meta('_order_stock_reduced'),'dispatch'=>$o->get_meta('_billing_fp_dispatch'),'address_empty'=>$o->get_meta('_billing_fp_address')==='','lines'=>$lines];
}
echo wp_json_encode(['test_orders'=>$orders])."\n";
$a=wc_get_order(67);$b=wc_get_order(68);
echo wp_json_encode(['duplicate_details'=>$a->get_meta('_fp_submitted_details')===$b->get_meta('_fp_submitted_details'),'duplicate_cart_hash'=>$a->get_cart_hash()===$b->get_cart_hash(),'mail_suppressed_count'=>get_option('fpw_suppressed_mail_count'),'mu_sha256'=>hash_file('sha256',WPMU_PLUGIN_DIR.'/freeplast-staging-mail.php')])."\n";
$private=false;foreach(wc_get_order_notes(['order_id'=>66,'type'=>'internal']) as $n) if(str_contains($n->content,'PRUEBA TÉCNICA 2026-09-05: nota privada')) $private=true;
echo wp_json_encode(['private_note_persisted'=>$private])."\n";
foreach(WC()->mailer()->get_emails() as $email){
 if(in_array($email->id,['qwc_req_new_quote','qwc_request_sent'],true)){
  $email->object=wc_get_order(66);$html=$email->get_content_html();$text=wp_strip_all_tags($html);
  echo wp_json_encode(['email'=>$email->id,'rendered'=>strlen($html)>100,'has_product'=>str_contains($text,'Caja Cosechera'),'has_fiscal'=>str_contains($text,'RUT Empresa'),'has_currency'=>str_contains($text,'$'),'template'=>$email->template_html])."\n";
 }
}
