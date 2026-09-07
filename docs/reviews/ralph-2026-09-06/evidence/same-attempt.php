<?php
// Offline reuse of the project's in-memory test doubles; no WordPress boot or DB.
require dirname(__DIR__,4).'/wordpress/scripts/test-woo-adapter.php';
$GLOBALS['fpw_options_table']=array();
$GLOBALS['wpdb']=new FPW_Fake_wpdb();
$GLOBALS['fpw_session_customer_id']='review-same-browser';
$GLOBALS['fpw_woo']->session=new FPW_Fake_Session();
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('same-products-options-quantities');
$checkout=new FPW_Fake_Checkout(fpw_attempt_posted());
$first=fpw_checkout_attempt_hash($checkout);
fpw_checkout_attempt_claim($first,0);
fpw_finalize_attempt_claim($first,12345);
// A completion + empty + rebuilding the identical cart does not rotate this session.
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart_Hash('same-products-options-quantities');
$next=fpw_checkout_attempt_hash(new FPW_Fake_Checkout(fpw_attempt_posted()));
echo json_encode(array('scenario'=>'new identical request in same session','same_hash'=>$first===$next,'result'=>fpw_checkout_attempt_claim($next,0))),"\n";
