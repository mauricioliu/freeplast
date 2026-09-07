<?php
require __DIR__ . '/woo-ventas-state.php';
class FixtureState {
	public function __construct( public array $data ) {}
	public function get_data() { return $this->data; }
}
$data = array( 'billing' => array('email'=>'synthetic@example.invalid','phone'=>'1234'), 'line_items' => array(88=>array('quantity'=>5,'total'=>'0.00','variation_id'=>7,'meta_data'=>array(array('key'=>'color','value'=>'rojo')))), 'meta_data'=>array(array('key'=>'_edit_lock','value'=>'123:1'),array('key'=>'_fp_submitted_details','value'=>array('rut'=>'1234'))));
$notes = array(array('comment'=>array('comment_content'=>str_repeat('a',500).'old','comment_author'=>'ventas','comment_date'=>'2026-09-07 12:00:00'),'meta'=>array('is_customer_note'=>array('0'))));
$base = fpw_verification_digest(new FixtureState($data),$notes);
$tests = 0;
function state_check($condition) { global $tests; ++$tests; if (!$condition) { throw new RuntimeException('State digest regression failed'); } }
foreach (array('quantity','total','variation_id','option','contact','submitted','note_tail','visibility','author') as $field) {
	$d=$data; $n=$notes;
	switch($field) {
		case 'quantity': $d['line_items'][88]['quantity']=9; break;
		case 'total': $d['line_items'][88]['total']='9.00'; break;
		case 'variation_id': $d['line_items'][88]['variation_id']=8; break;
		case 'option': $d['line_items'][88]['meta_data'][0]['value']='azul'; break;
		case 'contact': $d['billing']['phone']='5678'; break;
		case 'submitted': $d['meta_data'][1]['value']['rut']='5678'; break;
		case 'note_tail': $n[0]['comment']['comment_content']=str_repeat('a',500).'new'; break;
		case 'visibility': $n[0]['meta']['is_customer_note']=array('1'); break;
		case 'author': $n[0]['comment']['comment_author']='nobody'; break;
	}
	state_check($base !== fpw_verification_digest(new FixtureState($d),$n));
}
$data['meta_data'][0]['value']='456:2';
state_check($base === fpw_verification_digest(new FixtureState($data),$notes));
echo "native-state digest: $tests offline checks passed\n";
