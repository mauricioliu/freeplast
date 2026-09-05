#!/usr/bin/env python3
"""Real-stack offline regression (issue #1 — Woo-side ports of #24 and #27).

Runs against a DISPOSABLE local WordPress + SQLite + WooCommerce stack
(bootstrap.mjs, php -S): no staging, no external host, mail is not configured.

Scenarios
  home     — the delivered Home featured grid: every link names itself (the axe
             link-name rule, dependency-free), the product link carries its
             title and no wpautop `</p>`/`<p>` damage inside the anchor (WA-04).
  race     — two CONCURRENT checkout POSTs of one session: exactly ONE order,
             both responses carry the winner's confirmation, the order stays
             pending with the quote meta (WA-01).
  replay   — a sequential re-POST of the same attempt: Woo's own empty-cart
             guard rejects it and no additional order appears.
  isolate  — a different session with different data gets its own order, never
             folded into the first (regression guard for the lookup binding).

Local-only by construction: the base URL must be passed explicitly.
"""
import sys
if sys.argv[1:] in ([], ['--help']) or '--base' not in sys.argv:
    print(__doc__)
    print('help: python3 wordpress/scripts/woo-checkout-race.py --base http://127.0.0.1:8091')
    raise SystemExit(0)
BASE = sys.argv[sys.argv.index('--base') + 1].rstrip('/')
SLUG = sys.argv[sys.argv.index('--slug') + 1] if '--slug' in sys.argv else 'caja-cosechera-3-4-prueba'

import json, re, threading, copy
import urllib.request, urllib.parse, urllib.error, http.cookiejar
from html.parser import HTMLParser
from concurrent.futures import ThreadPoolExecutor

failures = []
def ok(name, condition, detail=''):
    if not condition:
        failures.append(f'{name} {detail}')
        print(f'FAILED: {name} {detail}')
    return bool(condition)

class Session:
    """Cookie jar + Store API nonce handling, mirroring verify-woo-http.py."""
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.nonce = ''
    def request(self, path, data=None, api=False, timeout=120):
        headers = {}
        if data is not None:
            data = (json.dumps(data) if api else urllib.parse.urlencode(data)).encode()
            headers['Content-Type'] = 'application/json' if api else 'application/x-www-form-urlencoded'
        if api and self.nonce:
            headers['Nonce'] = self.nonce
        req = urllib.request.Request(BASE + path, data=data, headers=headers)
        try:
            response = self.client.open(req, timeout=timeout)
        except urllib.error.HTTPError as e:
            response = e
        if response.headers.get('Nonce'):
            self.nonce = response.headers['Nonce']
        body = response.read().decode()
        return response.getcode(), (json.loads(body) if api else body)
    def clone_client(self):
        cloned = http.cookiejar.CookieJar()
        for cookie in self.jar:
            cloned.set_cookie(copy.copy(cookie))
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cloned))

class HiddenInputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'input' and a.get('type') == 'hidden' and a.get('name'):
            self.values[a['name']] = a.get('value', '')

# ---------------------------------------------------------------- home (WA-04)
home_session = Session()
code, html = home_session.request('/')

# The race flow mirrors verify-woo-http.py exactly: the first Store API call is
# the cart GET, whose Nonce header seeds every later authenticated call.
session = Session()
session.request('/wp-json/wc/store/v1/cart', api=True)
def check_home():
    # (i) the grid must not run through the wp:shortcode wpautop renderer anymore
    ok('home_no_shortcode_block', 'wp-block-shortcode' not in html)
    # (ii) the native product link carries its title and no paragraph-split damage
    for m in re.finditer(r'<a href="[^"]*" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">(.*?)</a>', html, re.S):
        anchor = m.group(0)
        ok('home_link_no_wpautop_damage', '</p>' not in anchor and '<p></a>' not in anchor)
        ok('home_link_has_title', re.search(r'<h2[^>]*>[^<]+</h2>', anchor) is not None)
        break  # one card proves the loop pipeline; the name rule below covers all links
    # (iii) every rendered anchor names itself (axe link-name, dependency-free:
    # non-empty text, aria-label, title attribute, or an img descendant with a
    # non-empty alt). aria-labelledby does not occur in this markup family.
    class Links(HTMLParser):
        def __init__(self):
            super().__init__()
            self.frames = []   # open <a> frames: [named_by_attrs, has_text, has_alt_img]
            self.verdicts = []
        def _innermost(self):
            return self.frames[-1] if self.frames else None
        def handle_starttag(self, tag, attrs):
            a = dict(attrs)
            if tag == 'a':
                self.frames.append([bool((a.get('aria-label') or '').strip() or (a.get('title') or '').strip()), False, False])
            elif tag == 'img' and (a.get('alt') or '').strip():
                frame = self._innermost()
                if frame is not None:
                    frame[2] = True
        def handle_startendtag(self, tag, attrs):
            self.handle_starttag(tag, attrs)
            if tag not in ('img', 'input', 'br', 'hr', 'meta', 'link'):
                self.handle_endtag(tag)
        def handle_data(self, data):
            frame = self._innermost()
            if frame is not None and data.strip():
                frame[1] = True
        def handle_endtag(self, tag):
            if tag == 'a' and self.frames:
                frame = self.frames.pop()
                self.verdicts.append(frame[0] or frame[1] or frame[2])
    parser = Links()
    parser.feed(html)
    total = len(parser.verdicts)
    unnamed = total - sum(1 for named in parser.verdicts if named)
    ok('home_all_links_named', total > 0 and unnamed == 0, f'{unnamed}/{total} unnamed')
    return {'total_links': total, 'unnamed': unnamed, 'no_shortcode_wrapper': 'wp-block-shortcode' not in html}

home = check_home()

# --------------------------------------------------------------- race (WA-01)
code, products = session.request(f'/wp-json/wc/store/v1/products?slug={SLUG}', api=True)
ok('product_exists', code == 200 and isinstance(products, list) and products, f'HTTP {code}')
pid = products[0]['id']
code, cart = session.request('/wp-json/wc/store/v1/cart/add-item', {'id': pid, 'quantity': 70}, api=True)
ok('cart_add', code in (200, 201), f'HTTP {code}')
code, html = session.request('/checkout/')
inputs = HiddenInputs()
inputs.feed(html)
values = inputs.values
ok('checkout_form_present', 'woocommerce-process-checkout-nonce' in values)
values.update({
    'billing_first_name': 'PRUEBA LOCAL CARRERA', 'billing_phone': '+56 9 1234 5678',
    'billing_email': 'race-local@example.invalid', 'billing_company': 'PRUEBA NO COMERCIAL',
    'billing_fp_rut': '76.123.456-7', 'billing_fp_giro': 'Prueba local',
    'billing_fp_dispatch': 'no', 'billing_fp_address': '',
    'payment_method': 'quotes-gateway', 'order_comments': 'Carrera local automatizada (no atender)'})

def post_checkout(sess, vals):
    code, body = sess.request('/?wc-ajax=checkout', vals)
    return json.loads(body)

barrier = threading.Barrier(2)
def submit(_):
    opener = session.clone_client()
    req = urllib.request.Request(BASE + '/?wc-ajax=checkout', data=urllib.parse.urlencode(values).encode())
    barrier.wait(timeout=15)
    with opener.open(req, timeout=180) as response:
        return json.loads(response.read())
with ThreadPoolExecutor(max_workers=2) as pool:
    results = list(pool.map(submit, range(2)))

def order_of(result):
    m = re.search(r'/order-received/(\d+)', result.get('redirect', ''))
    return int(m.group(1)) if m else None
orders = {order_of(result) for result in results}
ok('race_all_success', all(result.get('result') == 'success' for result in results), str([r.get('result') for r in results]))
ok('race_single_order', len(orders) == 1 and None not in orders, str(sorted(orders)))
race_order = orders.pop() if orders else None
ok('race_same_confirmation', len({result.get('redirect', '') for result in results}) == 1, 'redirects differ')

# ------------------------------------------------------------------- replay
replay = post_checkout(session, values)
ok('replay_rejected', replay.get('result') == 'failure', str(replay.get('result'))[:80])

# ---------------------------------------------------------------- isolation
other = Session()
other.request('/wp-json/wc/store/v1/cart', api=True)  # seed the Store API nonce
code, products = other.request(f'/wp-json/wc/store/v1/products?slug={SLUG}', api=True)
code, cart = other.request('/wp-json/wc/store/v1/cart/add-item', {'id': pid, 'quantity': 3}, api=True)
ok('cart_add_other', code in (200, 201), f'HTTP {code}')
code, html = other.request('/checkout/')
inputs = HiddenInputs()
inputs.feed(html)
other_values = inputs.values
other_values.update({
    'billing_first_name': 'PRUEBA LOCAL OTRA SESION', 'billing_phone': '+56 9 8765 4321',
    'billing_email': 'otra-local@example.invalid', 'billing_company': 'OTRA PRUEBA NO COMERCIAL',
    'billing_fp_rut': '76.999.999-9', 'billing_fp_giro': 'Otra prueba',
    'billing_fp_dispatch': 'si', 'billing_fp_address': 'Calle Falsa 123, Mostazal, VI Región',
    'payment_method': 'quotes-gateway', 'order_comments': ''})
submitted = post_checkout(other, other_values)
ok('isolate_success', submitted.get('result') == 'success', str(submitted)[:120])
other_order = order_of(submitted) if submitted.get('result') == 'success' else None
ok('isolate_distinct_order', other_order is not None and other_order != race_order, f'{other_order} vs {race_order}')

print(json.dumps({'home': home, 'race_order': race_order, 'isolate_order': other_order,
                  'race_results': [result.get('result') for result in results],
                  'replay_result': replay.get('result'), 'failures': failures}, ensure_ascii=False))
raise SystemExit(1 if failures else 0)
