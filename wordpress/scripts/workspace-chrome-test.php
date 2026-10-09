<?php
/** Shared chrome does not grant access or alter the maintenance workflows. */
define('ABSPATH', __DIR__);
$GLOBALS['caps'] = [];
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
function fpw_can_manage_quotations() { return current_user_can('manage_woocommerce') || current_user_can('fpw_manage_quotations'); }
function fpw_can_manage_data() { return current_user_can('manage_woocommerce') || current_user_can('fpw_manage_data'); }
function fpw_restricted_user() { return !current_user_can('manage_woocommerce'); }
function fpw_workspace_url() { return 'admin.php?page=fpw-quotations'; }
function fpw_data_hub_url() { return 'admin.php?page=fpw-data'; }
function fpw_price_screen_url() { return 'admin.php?page=fpw-price-list'; }
function fpw_sales_import_screen_url() { return 'admin.php?page=fpw-sales-import'; }
function esc_url($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function admin_url() { return '/wp-admin/'; }
function wp_login_url() { return '/wp-login.php'; }
function wp_logout_url($s) { return '/logout'; }
function plugins_url($s,$file) { return '/plugin/'.$s; }
require __DIR__.'/../wp-content/plugins/freeplast-woo/workspace-chrome.php';
$n=0;
function check($ok,$label) { global $n; $n++; if(!$ok) throw new RuntimeException($label); }
foreach ([['manage_woocommerce'=>true],['fpw_manage_quotations'=>true],['fpw_manage_data'=>true],[]] as $caps) {
 $GLOBALS['caps']=$caps;
 foreach (['fpw-quotations','fpw-quote-draft','fpw-data','fpw-price-list','fpw-sales-import','plugins',['evil']] as $page) {
  $_GET=['page'=>$page,'workspace'=>'1'];
  $quote = in_array($page,['fpw-quotations','fpw-quote-draft'],true);
  $data = in_array($page,['fpw-data','fpw-price-list','fpw-sales-import'],true);
  check(fpw_workspace_surface() === (($quote && fpw_can_manage_quotations()) || ($data && fpw_can_manage_data())), 'chrome limited to authorized exact surfaces');
 }
 $_GET=['page'=>'fpw-price-list'];
 $nav=fpw_workspace_navigation();
 check(str_contains($nav,'href="admin.php?page=fpw-quotations"')===fpw_can_manage_quotations(),'quotation link respects permission');
 check(str_contains($nav,'href="admin.php?page=fpw-price-list"')===fpw_can_manage_data(),'data links respect permission');
 check(str_contains($nav,'aria-current="page"')===fpw_can_manage_data(),'active price destination marked');
 check(str_contains($nav,'<details class="fpw-maintenance-menu">')===fpw_can_manage_data(),'one native dropdown only for authorized data accounts');
 check(!str_contains($nav,'href="admin.php?page=fpw-data"'),'maintenance trigger opens options, not a duplicate hub link');
 $header=fpw_workspace_header();
 check(str_contains($header,'Área del dueño') && str_contains($header,'brand.webp'),'same actual logo and banner');
 check(str_contains($header,'Administración')===current_user_can('manage_woocommerce'),'restricted accounts get logout, not administration');
}
$GLOBALS['caps']=['manage_woocommerce'=>true];
$_GET=['page'=>'fpw-quote-draft'];
check(!fpw_workspace_surface(),'legacy draft not opted into workspace remains native');
$_GET=['page'=>'fpw-quote-draft','workspace'=>'1'];
check(str_contains(fpw_workspace_navigation(),'href="admin.php?page=fpw-quotations" aria-current="page"'),'draft belongs to quotation navigation');
echo "workspace chrome: $n checks passed\n";
