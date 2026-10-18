#!/usr/bin/env python3
"""Synthetic checks for the operational verifier; no real data or database."""
import copy
import importlib.util
import json
from pathlib import Path

spec = importlib.util.spec_from_file_location('verify', Path(__file__).with_name('sales-workbook-dev-verify.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
old = {'id': '1', 'total': '12', 'lines': None, 'batch': 'old'}
added = {'id': 'new:2', 'total': '5', 'lines': [{'total': '5'}]}
fields = {'lines': [{'total': '12'}]}
receipt = {'token': 'token', 'at': 10, 'aplicadas': 1, 'enriquecidas': 1, 'conflictos': ['3'], 'pendientes': [{'id': '4'}], 'conflictos_total': 1, 'pendientes_total': 1, 'cambios': [{'id': 1, 'campos': fields}]}
preview = {'plan': {'new': [added], 'enrich': {'1': fields}, 'conflicts': ['3'], 'pending': [{'id': '4'}]}}

def snapshot(sales):
    return {'url': 'http://mliu:8131', 'tables': {'all': {'sha256': 'same'}}, 'sales_options': {'fpw_sales_register': {'option_value': json.dumps({'schema': 1, 'sales': sales})}}}

before = snapshot([old])
after = snapshot([old | fields | {'enriched': [{'batch': 'token', 'at': 10, 'fields': ['lines']}]}, added | {'batch': 'token', 'imported_at': 10}])
after['sales_options']['fpw_sales_receipt_token'] = {'option_value': json.dumps(receipt)}
assert module.verify(before, after, preview, receipt)['resulting'] == 2

for mutation in ('protected', 'duplicate', 'populated', 'audit', 'receipt', 'missing', 'pending'):
    bad = copy.deepcopy(after)
    register = json.loads(bad['sales_options']['fpw_sales_register']['option_value'])
    if mutation == 'protected': bad['tables']['all']['sha256'] = 'changed'
    if mutation == 'duplicate': register['sales'].append(register['sales'][0])
    if mutation == 'populated': register['sales'][0]['total'] = '13'
    if mutation == 'audit': register['sales'][0]['enriched'][0]['batch'] = 'other'
    if mutation == 'receipt': bad['sales_options']['fpw_sales_receipt_token']['option_value'] = '{}'
    if mutation == 'missing': register['sales'].pop()
    if mutation == 'pending': bad['sales_options']['fpw_sales_batch'] = {}
    bad['sales_options']['fpw_sales_register']['option_value'] = json.dumps(register)
    try:
        module.verify(before, bad, preview, receipt)
    except ValueError:
        pass
    else:
        raise AssertionError('Accepted ' + mutation)
lock_before = {'tables': {'wp_options': {'sha256': 'old'}, 'wp_posts': {'sha256': 'same'}}, 'tables_except_scheduler_lock_value': {'wp_options': {'sha256': 'same'}, 'wp_posts': {'sha256': 'same'}}, 'operational_lock': {'option_id': '462', 'option_name': 'action_scheduler_lock_async-request-runner', 'autoload': 'no', 'option_value': '6ad4c85596db27.86720367|1000'}, 'captured_at_start': 1001, 'captured_at_end': 1002}
lock_after = copy.deepcopy(lock_before)
lock_after['tables']['wp_options']['sha256'] = 'new'
lock_after['operational_lock']['option_value'] = '6ad4c945963f79.18502709|1070'
lock_after['captured_at_end'] = 1011
assert module.verify_protected(lock_before, lock_after)['scheduler_lock_renewal']['request_at'] == 1010
assert not module.verify_protected(lock_before, lock_after)['strict_equal']
for mutation in ('other-row', 'other-table', 'delete', 'rename', 'id', 'autoload', 'malformed', 'leading-zero', 'backwards', 'before-window', 'after-window', 'same-token', 'clock'):
    bad = copy.deepcopy(lock_after)
    if mutation == 'other-row': bad['tables_except_scheduler_lock_value']['wp_options']['sha256'] = 'changed'
    if mutation == 'other-table': bad['tables']['wp_posts']['sha256'] = 'changed'
    if mutation == 'delete': bad['operational_lock'] = None
    if mutation == 'rename': bad['operational_lock']['option_name'] = '_transient_other'
    if mutation == 'id': bad['operational_lock']['option_id'] = '463'
    if mutation == 'autoload': bad['operational_lock']['autoload'] = 'yes'
    if mutation == 'malformed': bad['operational_lock']['option_value'] = 'arbitrary|1070'
    if mutation == 'leading-zero': bad['operational_lock']['option_value'] = '6ad4c945963f79.18502709|01070'
    if mutation == 'backwards': bad['operational_lock']['option_value'] = '6ad4c945963f79.18502709|999'
    if mutation == 'before-window': bad['operational_lock']['option_value'] = '6ad4c945963f79.18502709|1060'
    if mutation == 'after-window': bad['operational_lock']['option_value'] = '6ad4c945963f79.18502709|1072'
    if mutation == 'same-token': bad['operational_lock']['option_value'] = '6ad4c85596db27.86720367|1070'
    if mutation == 'clock': bad['captured_at_end'] = 999
    try:
        module.verify_protected(lock_before, bad)
    except ValueError:
        pass
    else:
        raise AssertionError('Accepted lock ' + mutation)
print('PASS: exact delta +7 destructive/drift cases; bounded lock renewal +13 rejection cases')
