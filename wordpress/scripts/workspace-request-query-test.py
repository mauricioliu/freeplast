"""Execute the production inbox SQL against both native storage shapes; synthetic in-memory data only."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BINARY', str(root / '.tools/php/php'))
queries = json.loads(subprocess.check_output([php, str(root / 'scripts/workspace-request-query-fixture.php')]))
checks = 0
for storage, cases in queries.items():
    db = sqlite3.connect(':memory:')
    db.create_function('CONCAT', -1, lambda *args: ''.join(map(str, args)))
    db.executescript('''
      CREATE TABLE wp_posts (ID INTEGER, post_type TEXT, post_status TEXT, post_date_gmt TEXT);
      CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT);
      CREATE TABLE wp_wc_orders (id INTEGER, type TEXT, status TEXT, date_created_gmt TEXT);
      CREATE TABLE wp_wc_orders_meta (order_id INTEGER, meta_key TEXT, meta_value TEXT);
      CREATE TABLE wp_wc_order_addresses (order_id INTEGER, address_type TEXT, company TEXT);
      CREATE TABLE wp_options (option_name TEXT UNIQUE, option_value TEXT);
    ''')
    for id in range(1, 14):
        for table in ['wp_posts', 'wp_wc_orders']:
            db.execute(f'INSERT INTO {table} VALUES (?, ?, ?, ?)', (id, 'shop_order', 'trash' if id == 9 else 'wc-pending', '2024-01-01 12:00:00'))
        for table in ['wp_postmeta', 'wp_wc_orders_meta']:
            if id != 8:  # ordinary Woo order is not a received Freeplast request
                db.execute(f'INSERT INTO {table} VALUES (?, ?, ?)', (id, '_fp_request', 'yes'))
            if id == 1:
                for key, value in [('_billing_fp_rut', '76.543.210-K'), ('_fpq_reference', 'COT-ANTIGUA')]:
                    db.execute(f'INSERT INTO {table} VALUES (?, ?, ?)', (id, key, value))
        if id != 1:
            db.execute('INSERT INTO wp_options VALUES (?, ?)', (f'fpw_draft_{id}', json.dumps({'identity': {'company': f'Empresa {id}'}, 'reference': f'FP-2024-{id:06}'})))
    db.execute('INSERT INTO wp_postmeta VALUES (1, ?, ?)', ('_billing_company', 'Antigua sin borrador'))
    db.execute('INSERT INTO wp_wc_order_addresses VALUES (1, ?, ?)', ('billing', 'Antigua sin borrador'))
    for id, state, document in [(3, 'accepted', 'ready'), (4, 'unknown', 'ready'), (5, 'rejected', 'ready'), (6, None, 'pending'), (11, 'accepted', 'ready'), (12, 'accepted', 'ready'), (13, 'accepted', 'ready')]:
        version = {'schema': 2 if id == 11 else 1, 'order_id': 999 if id == 12 else id, 'version': 1, 'document': document, 'delivery': {'state': state, 'at': 1704110400}}
        db.execute('INSERT INTO wp_options VALUES (?, ?)', (f'fpw_quotation_{id}', json.dumps(version)))
    db.execute('INSERT INTO wp_options VALUES (?, ?)', ('fpw_quotation_10', 'corrupt-json'))
    db.execute('INSERT INTO wp_options VALUES (?, ?)', ('fpw_tracking_7', json.dumps({'events': {'accepted': '2024-01-01', 'paid': '2024-01-01', 'dispatched': '2024-01-01'}})))
    # Quotation/draft order is not the authority for inbox membership, or sending.
    # Pendientes: without any version row (1, 2, 7), or readable but not confirmed-sent (4, 5, 6).
    # Corrupt (10) and misbound/schema-broken rows (11, 12) belong to neither view: only Todas.
    expected = {'pending': [1, 2, 4, 5, 6, 7], 'all': [1, 2, 3, 4, 5, 6, 7, 10, 11, 12, 13], 'sent': [3, 13], 'old-filter': [1, 2, 3, 4, 5, 6, 7, 10, 11, 12, 13], 'company': [1], 'rut': [1], 'reference': [1], 'legacy-reference': [1], 'injection': []}
    before = db.total_changes
    for name, sql in cases.items():
        found = [row[0] for row in db.execute(sql)]
        assert found == expected[name], (storage, name, found)
        checks += 1
    assert db.total_changes == before
    checks += 1
    db.close()
print(f'workspace request queries: {checks} CPT/HPOS SQL checks passed (SQLite fixtures; no live MySQL/HPOS run)')
