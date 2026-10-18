#!/usr/bin/env python3
"""Validate private DEV snapshots and exact planned delta; never connects or writes."""
import json
import re
import sys


def verify_protected(before, after):
    """Keep strict evidence; audit only the pinned Woo60s lock-value renewal."""
    if before['tables'] == after['tables']:
        return {'strict_equal': True, 'scheduler_lock_renewal': None}
    changed_tables = {t for t in before['tables'].keys() | after['tables'].keys() if before['tables'].get(t) != after['tables'].get(t)}
    if changed_tables != {'wp_options'} or before.get('tables_except_scheduler_lock_value') is None or before['tables_except_scheduler_lock_value'] != after.get('tables_except_scheduler_lock_value'):
        raise ValueError('Non-sales database drift beyond scheduler lock value')
    old, new = before.get('operational_lock'), after.get('operational_lock')
    if not isinstance(old, dict) or not isinstance(new, dict):
        raise ValueError('Scheduler lock absent/deleted')
    if {k: v for k, v in old.items() if k != 'option_value'} != {k: v for k, v in new.items() if k != 'option_value'} or old.get('option_name') != 'action_scheduler_lock_async-request-runner':
        raise ValueError('Scheduler lock identity/metadata changed')
    pattern = r'([0-9a-f]{13}[0-9]\.[0-9]{8})\|([1-9][0-9]*)'
    a = re.fullmatch(pattern, old.get('option_value', ''))
    b = re.fullmatch(pattern, new.get('option_value', ''))
    start, end = before.get('captured_at_start'), after.get('captured_at_end')
    if not a or not b or type(start) is not int or type(end) is not int or start > end:
        raise ValueError('Invalid scheduler lock or observation clock')
    previous_expiry, expiry = int(a[2]), int(b[2])
    request_at = expiry - 60  # Pinned Woo ActionScheduler_OptionLock duration.
    if a[1] == b[1] or not previous_expiry < request_at or not start <= request_at <= end:
        raise ValueError('Scheduler lock renewal non-monotonic or outside observed request window')
    return {'strict_equal': False, 'scheduler_lock_renewal': {'before': old, 'after': new, 'observed_start': start, 'observed_end': end, 'request_at': request_at, 'expiry_delta': expiry - previous_expiry}}


def verify(before, after, preview, receipt):
    def require(ok, message):
        if not ok:
            raise ValueError(message)

    require(before['url'] == after['url'] == 'http://mliu:8131', 'Wrong target')
    protected = verify_protected(before, after)
    options_before, options_after = before['sales_options'], after['sales_options']
    require('fpw_sales_batch' not in options_after, 'Pending slot not consumed')
    require({k: v for k, v in options_before['fpw_sales_register'].items() if k != 'option_value'} == {k: v for k, v in options_after['fpw_sales_register'].items() if k != 'option_value'}, 'Register SQL metadata changed')
    old = json.loads(options_before['fpw_sales_register']['option_value'])
    new = json.loads(options_after['fpw_sales_register']['option_value'])
    require({k: v for k, v in old.items() if k != 'sales'} == {k: v for k, v in new.items() if k != 'sales'}, 'Register envelope drift')
    old_sales = {str(s['id']): s for s in old['sales']}
    new_sales = {str(s['id']): s for s in new['sales']}
    require(len(old_sales) == len(old['sales']), 'Duplicate old IDs')
    require(len(new_sales) == len(new['sales']), 'Duplicate final IDs')
    plan = preview['plan']
    token = receipt['token']
    added = {str(s['id']): s for s in plan['new']}
    require(set(new_sales) == set(old_sales) | set(added), 'Unexpected or missing IDs')
    require(not (set(old_sales) & set(added)), 'Plan duplicates old sale')
    delta = {}
    for key, sale in old_sales.items():
        fields = plan['enrich'].get(key, {})
        expected = dict(sale)
        for field, value in fields.items():
            require(sale.get(field) in (None, ''), 'Populated field overwritten')
            expected[field] = value
        if fields:
            audit = new_sales[key].get('enriched', [])
            require(len(audit) == len(sale.get('enriched', [])) + 1, 'Missing audit')
            require(audit[:-1] == sale.get('enriched', []), 'Old audit overwritten')
            require(audit[-1] == {'batch': token, 'at': receipt['at'], 'fields': list(fields)}, 'Wrong audit')
            expected['enriched'] = audit
            delta[key] = fields
        require(expected == new_sales[key], 'Old sale changed outside planned empty fields')
    for key, sale in added.items():
        actual = dict(new_sales[key])
        require(actual.pop('batch', None) == token, 'Wrong new provenance')
        require(isinstance(actual.pop('imported_at', None), int), 'Missing import timestamp')
        require(actual == sale, 'New sale differs from preview')
    receipt_key = 'fpw_sales_receipt_' + token
    require(set(options_after) == (set(options_before) - {'fpw_sales_batch'}) | {receipt_key}, 'Unexpected sales options')
    require(json.loads(options_after[receipt_key]['option_value']) == receipt, 'Stored receipt differs')
    for key in options_before:
        if key not in ('fpw_sales_register', 'fpw_sales_batch'):
            require(options_before[key] == options_after[key], 'Old receipt changed')
    require(receipt['aplicadas'] == len(added) and receipt['enriquecidas'] == len(delta), 'Receipt counts differ')
    require(receipt['conflictos'] == plan['conflicts'] and receipt['pendientes'] == plan['pending'], 'Pending/conflicts differ')
    require(receipt['conflictos_total'] == len(plan['conflicts']) and receipt['pendientes_total'] == len(plan['pending']), 'Pending/conflict counts differ')
    require([{**change, 'id': str(change['id'])} for change in receipt['cambios']] == [{'id': key, 'campos': fields} for key, fields in plan['enrich'].items()], 'Receipt delta differs')
    return {'old_preserved': len(old_sales), 'new': len(added), 'enriched': len(delta), 'resulting': len(new_sales), 'protected': protected, 'delta': delta}


if __name__ == '__main__':
    if len(sys.argv) != 5:
        sys.exit('Usage: sales-workbook-dev-verify.py BEFORE.json AFTER.json PREVIEW.json CONFIRM.json')
    before, after, preview, confirm = [json.load(open(path)) for path in sys.argv[1:]]
    print(json.dumps(verify(before, after, preview, confirm['receipt']), ensure_ascii=False))
