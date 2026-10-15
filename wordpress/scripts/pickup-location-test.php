<?php
/** Pickup selection is an owner fact, frozen with the approved commercial projection. */
require __DIR__ . '/quote-draft-test.php';
$start = $assertions;
$draft = fpw_read_request_draft(92);
$work = fpw_read_draft_work(92);
foreach (fpw_pickup_locations() as $key => $address) {
    $input = array('fpw_work' => array('pickup_location' => $key, 'lines' => array(array('quantity' => '10', 'price' => '990'))));
    $parsed = fpw_parse_draft_work_input($draft, $input);
    check(empty($parsed['errors']) && $parsed['pickup_location'] === $key, 'known pickup is accepted');
    $work['pickup_location'] = $key;
    $projection = fpw_quotation_projection($draft, $work);
    check($projection['complete'] && $projection['pickup_address'] === $address && $projection['dispatch'] === null, 'pickup is frozen without freight');
    $version = fpw_read_quotation_version(92);
    $version['projection'] = $projection;
    foreach (array(fpw_workspace_offer_html($projection), fpw_quotation_email_html($version), fpw_quotation_document_html($version, '', 'sans-serif')) as $html) {
        check(str_contains($html, htmlspecialchars($address, ENT_QUOTES, 'UTF-8')) && str_contains($html, 'Retiro - Sin Despacho'), 'offer, email and PDF show selected pickup');
    }
    $radios = fpw_draft_pickup_html($key);
    check(substr_count($radios, 'type="radio"') === 2 && substr_count($radios, ' checked') === 1, 'two radio cards with one saved selection');
}
$work['pickup_location'] = '';
check(!fpw_quotation_projection($draft, $work)['complete'], 'missing pickup blocks review and approval');
$work['pickup_location'] = 'forged';
check(!fpw_quotation_projection($draft, $work)['complete'], 'unknown stored pickup cannot issue');
$bad = fpw_parse_draft_work_input($draft, array('fpw_work' => array('pickup_location' => '<script>')));
check(!empty($bad['errors']) && $bad['pickup_location'] === '', 'unknown posted pickup is rejected');
$dispatch = fpw_read_request_draft(91);
$parsed = fpw_parse_draft_work_input($dispatch, array('fpw_work' => array('pickup_location' => 'los-paltos')));
check($parsed['pickup_location'] === '', 'dispatch ignores pickup');
check(fpw_quotation_projection($dispatch, fpw_read_draft_work(91))['pickup_address'] === '', 'dispatch has no pickup address');
echo 'pickup locations: ' . ($assertions - $start) . " checks passed\n";
