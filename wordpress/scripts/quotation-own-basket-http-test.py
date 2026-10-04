#!/usr/bin/env python3
"""H1 native scenario (2026-10-03 review) — PREPARED, run by the lead on a
DISPOSABLE COPY with an isolated port. Never run against staging/production.

The quotation-only account must be able to operate ITS OWN basket lines through
the Store API routes Woo's basket page itself uses, while every other REST
surface (coupons, checkout, orders, products, batch, core admin) stays denied.
This script exercises exactly that boundary over real WordPress HTTP:

  positives: GET cart, POST add-item/update-item/remove-item on a synthetic
             product line the script itself adds and removes;
  negatives: apply-coupon, checkout, update-customer, products, batch,
             wp/v2/users, wc/v3/orders — all must answer 403
             fpw_restricted_account, never a wider surface.

Usage (credentials JSON on stdin, never printed):
  pass show freeplast/local-owner-review \\
    | python3 wordpress/scripts/quotation-own-basket-http-test.py \\
        --base http://mliu:8123 --product-id <synthetic simple product id>

Requires: the disposable copy serving --base, a quotation-only account
(fpw_quotation_manager role) reachable by the supplied credentials, and a
synthetic simple product that this run may add and remove freely.
"""
import argparse
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from urllib.parse import urlsplit

parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
parser.add_argument('--base', required=True, help='disposable local base URL, e.g. http://mliu:8123')
parser.add_argument('--product-id', type=int, required=True, help='synthetic simple product id this run may add/remove')
parser.add_argument('--allow-host', action='append', default=[], help='additional exact hostname allowed for --base (repeatable)')
args = parser.parse_args()

# Exact-hostname guard: string prefixes would admit http://mliu.evil — parse and compare.
base_parts = urlsplit(args.base if '//' in args.base else '//' + args.base)
base_host = (base_parts.hostname or '').lower()
allowed_hosts = {'127.0.0.1', 'localhost', '::1', 'mliu'} | {h.lower() for h in args.allow_host}
if base_host not in allowed_hosts:
    sys.exit('Refusing: --base host %r is not an allowed local/disposable host (exact match only)' % base_host)
if not base_parts.scheme or base_parts.netloc == '':
    sys.exit('Refusing: --base must be an absolute URL like http://mliu:8123')

credentials = json.load(sys.stdin)
cred_url = credentials.get('url')
if cred_url:
    cred_host = (urlsplit(cred_url).hostname or '').lower()
    if cred_host != base_host:
        sys.exit('Refusing: credentials belong to host %r, not this disposable host %r' % (cred_host, base_host))

client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0


def request(path, data=None, headers=None):
    body = None
    if data is not None:
        body = json.dumps(data).encode()
        headers = dict(headers or {})
        headers.setdefault('Content-Type', 'application/json')
    req = urllib.request.Request(args.base + path, body, headers or {})
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    payload = None
    if response.status not in (204,):
        try:
            payload = json.loads(response.read().decode() or 'null')
        except ValueError:
            payload = None
    return response.status, payload


def check(value, label):
    global checks
    assert value, label
    checks += 1


def restricted(status, payload, label):
    check(status == 403 and isinstance(payload, dict) and payload.get('code') == 'fpw_restricted_account', label)


# Login the quotation-only account through the native form, then PROVE the session
# is authenticated on an owner-visible page — every later check runs as this user,
# never degraded to guest. WordPress requires the wordpress_test_cookie from a
# GET before accepting the testcookie=1 POST.
client.open(args.base + '/wp-login.php', timeout=30)
login = urllib.parse.urlencode({'log': credentials['username'], 'pwd': credentials['password'], 'wp-submit': 'Log In', 'redirect_to': args.base + '/wp-admin/', 'testcookie': '1'}).encode()
try:
    response = client.open(urllib.request.Request(args.base + '/wp-login.php', login), timeout=30)
    assert 'wp-login.php' not in response.url, 'login did not complete (check the synthetic account on the disposable copy)'
except urllib.error.HTTPError as error:
    sys.exit('login failed: HTTP %d (check the synthetic fpw_quotation_manager account)' % error.code)
workspace = client.open(args.base + '/wp-admin/admin.php?page=fpw-quotations', timeout=30)
workspace_html = workspace.read().decode()
check(workspace.status == 200 and 'Solicitudes de clientes' in workspace_html and 'wp-login.php' not in workspace.url, 'the quotation account is explicitly authenticated into its workspace')

# The basket page embeds Woo's own Store API nonce in the pinned format
# (wcBlocksMiddlewareConfig inline script: storeApiNonce: '<nonce>', with single
# quotes and free whitespace — not a JSON-style "storeApiNonce":"...").
page = client.open(args.base + '/cotizacion/', timeout=30).read().decode()
nonce = re.search(r"storeApiNonce['\"]?\s*[:=]\s*['\"]([0-9a-zA-Z_-]+)['\"]", page)
check(nonce is not None, 'basket page exposes the native wc_store_api nonce')
API_HEADERS = {'X-WC-Store-API-Nonce': nonce.group(1), 'Nonce': nonce.group(1)}

# Core REST (wp/v2, wc/v3) degrades a cookie session to guest without the REAL
# wp_rest nonce — extract it from the AUTHENTICATED admin page (wp-api-fetch
# prints wp.apiFetch.createNonceMiddleware( '<nonce>' )) and send X-WP-Nonce on
# every core negative, so the only possible denial cause is fpw_restricted_account.
rest_nonce = re.search(r"createNonceMiddleware\(\s*['\"]([0-9a-zA-Z_-]+)['\"]", workspace_html) \
    or re.search(r'"nonce"\s*:\s*"([0-9a-fA-F]+)"', workspace_html)
check(rest_nonce is not None, 'an authenticated admin page exposes the real wp_rest nonce')
CORE_HEADERS = {'X-WP-Nonce': rest_nonce.group(1)}

# Positive: read the own cart.
status, cart = request('/wp-json/wc/store/v1/cart', headers=API_HEADERS)
check(status == 200 and isinstance(cart, dict) and 'items' in cart, 'H1 positive: own cart reads without 403')

# Positive: add one unit of the synthetic product, then operate that line only.
status, added = request('/wp-json/wc/store/v1/cart/add-item', {'id': args.product_id, 'quantity': 2}, API_HEADERS)
check(status in (200, 201) and any(item.get('id') == args.product_id for item in (added or {}).get('items', [])), 'H1 positive: own line added')
item_key = next((item.get('key') for item in (added or {}).get('items', []) if item.get('id') == args.product_id), None)
check(bool(item_key), 'H1: the added line carries its cart item key')

status, updated = request('/wp-json/wc/store/v1/cart/update-item', {'key': item_key, 'quantity': 3}, API_HEADERS)
line = next((item for item in (updated or {}).get('items', []) if item.get('key') == item_key), {})
check(status == 200 and line.get('quantity') == 3, 'H1 positive: quantity update persists (the audited 403 is gone for the own basket)')

# Negatives, family A — the same authenticated session WITH valid nonces: a 403
# fpw_restricted_account can then only come from the REST boundary under test.
restricted(*request('/wp-json/wc/store/v1/cart/apply-coupon', {'code': 'nada'}, API_HEADERS), 'H1 negative A: coupons stay denied (valid Store nonce, authenticated session)')
restricted(*request('/wp-json/wc/store/v1/cart/update-customer', {'billing_address': {'email': 'x@prueba.invalid'}}, API_HEADERS), 'H1 negative A: customer update stays denied')
restricted(*request('/wp-json/wc/store/v1/checkout', {}, API_HEADERS), 'H1 negative A: checkout stays denied')
restricted(*request('/wp-json/wc/store/v1/products', headers=API_HEADERS), 'H1 negative A: products route stays denied')
restricted(*request('/wp-json/wc/store/batch', {}, API_HEADERS), 'H1 negative A: batch stays denied')
restricted(*request('/wp-json/wp/v2/users', headers=CORE_HEADERS), 'H1 negative A: core admin REST stays denied with a VALID wp_rest nonce (guest degradation would answer rest_not_logged_in)')
restricted(*request('/wp-json/wc/v3/orders', headers=CORE_HEADERS), 'H1 negative A: third-party order data stays denied with a valid wp_rest nonce')

# Negatives, family B — Store mutations carry NO Store nonce at all: still denied
# (Woo's own transport check or the boundary; never a silent success).
def denied(status, payload, label):
    global checks
    assert status in (401, 403), label + ' (got %s)' % status
    checks += 1


denied(*request('/wp-json/wc/store/v1/cart/apply-coupon', {'code': 'nada'}), 'H1 negative B: coupon mutation without Store nonce stays denied')
denied(*request('/wp-json/wc/store/v1/cart/update-item', {'key': 'missing', 'quantity': 1}), 'H1 negative B: update-item without Store nonce stays denied')
denied(*request('/wp-json/wc/store/v1/cart/remove-item', {'key': 'missing'}), 'H1 negative B: remove-item without Store nonce stays denied')

# Cleanup exactly this run's line.
status, _ = request('/wp-json/wc/store/v1/cart/remove-item', {'key': item_key}, API_HEADERS)
check(status == 200, 'H1 positive: own line removed (run leaves no basket residue)')

print('quotation own-basket native boundary: %d checks passed' % checks)
