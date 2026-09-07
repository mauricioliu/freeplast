#!/usr/bin/env python3
"""Real-stack offline regression (issue #1 — Woo-side ports of #24/#27; issue #31 — new request vs retry).

Runs against a DISPOSABLE local WordPress + SQLite + WooCommerce stack
(bootstrap.mjs, php -S): no staging, no external host, mail is not configured.

Scenarios
  home     — the delivered Home featured grid: every link names itself (the axe
             link-name rule, dependency-free), the product link carries its
             title and no wpautop `</p>`/`<p>` damage inside the anchor (WA-04).
  race     — TWO CONCURRENT checkout POSTs of one attempt, repeated a bounded
             three rounds with fresh sessions: exactly ONE order per round,
             both responses carry the winner's confirmation, the order stays
             pending with the quote meta (WA-01, #31 criterion 3).
  replay   — a sequential re-POST of the same attempt after it landed: the
             landed-attempt recovery returns the SAME request's confirmation
             and no additional order appears (no «sesión caducada» for an
             authorized retry of the same attempt).
  correct  — a pre-save validation error leaves the selection intact: the
             corrected re-submission succeeds on the same rebuilt cart (#31
             criterion 6); the cart only empties on persisted success.
  renew    — THE #31 DEFECT: the same session completes a request, rebuilds
             the IDENTICAL selection (same product, quantity and details) and
             submits again from a fresh checkout page: this must produce a NEW
             reference — never fold into the previous request (criterion 1/2),
             and the attempt token must have rotated.
  lost     — THE #32 DEFECT (criterion 1/3): the first submission's response is
             deliberately lost before it reaches the client; the request
             persists server-side and the SAME form is retried without visiting
             the confirmation. The retry must recover the ORIGINAL reference —
             never the native empty-cart «sesión caducada», never a second
             request, never duplicated receipt notifications.
  inflight — a retry while the first submission of the same attempt is still in
             flight answers recoverably (#32 criterion 5); once it completes,
             the original reference is obtained without creating another
             request.
  preserve — recovering the previous request must not vacate or alter a NEW
             selection unrelated to that attempt (#32 criterion 6): the old
             form's retry returns the original confirmation and the new
             selection survives in Productos a Cotizar untouched.
  stranger — knowing an identifier authorizes nothing (#32 criterion 4): a
             well-formed but unknown attempt token and a stolen form replayed
             from another session both receive Woo's own safe rejection —
             never the recovered reference, never a new request.
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
RACE_ROUNDS = 3  # the bounded repetition of the concurrent test (#31 criterion 3)

import json, re, threading, time, copy
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

class Links(HTMLParser):
    """Dependency-free axe link-name rule over every rendered anchor: named by
    non-empty text, aria-label/title attribute, or an img descendant with a
    non-empty alt (aria-labelledby does not occur in this markup family)."""
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

def check_home(html):
    # (i) the grid must not run through the wp:shortcode wpautop renderer anymore
    ok('home_no_shortcode_block', 'wp-block-shortcode' not in html)
    # (ii) the native product link carries its title and no paragraph-split damage
    # (one card proves the loop pipeline; the name rule below covers all links)
    card = re.search(r'<a href="[^"]*" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">(.*?)</a>', html, re.S)
    if card is not None:
        anchor = card.group(0)
        ok('home_link_no_wpautop_damage', '</p>' not in anchor and '<p></a>' not in anchor)
        ok('home_link_has_title', re.search(r'<h2[^>]*>[^<]+</h2>', anchor) is not None)
    # (iii) every rendered anchor names itself
    parser = Links()
    parser.feed(html)
    total = len(parser.verdicts)
    unnamed = total - sum(1 for named in parser.verdicts if named)
    ok('home_all_links_named', total > 0 and unnamed == 0, f'{unnamed}/{total} unnamed')
    return {'total_links': total, 'unnamed': unnamed, 'no_shortcode_wrapper': 'wp-block-shortcode' not in html}

def order_of(result):
    m = re.search(r'/order-received/(\d+)', result.get('redirect', ''))
    return int(m.group(1)) if m else None

def checkout_form(session):
    """GET the checkout page and return its hidden inputs (nonce + attempt token)."""
    code, html = session.request('/checkout/')
    inputs = HiddenInputs()
    inputs.feed(html)
    return inputs.values

def post_checkout(session, values):
    """POST the native Woo checkout AJAX endpoint and decode its JSON answer."""
    code, body = session.request('/?wc-ajax=checkout', values)
    try:
        return json.loads(body)
    except (TypeError, ValueError):
        return {'result': 'error', 'message': str(body)[:160]}

def add_to_cart(session, name, quantity):
    """Add the featured product to a session's cart; every scenario starts here."""
    code, _ = session.request('/wp-json/wc/store/v1/cart/add-item', {'id': pid, 'quantity': quantity}, api=True)
    ok(name, code in (200, 201), f'HTTP {code}')

RACE_FIELDS = {
    'billing_first_name': 'PRUEBA LOCAL CARRERA', 'billing_phone': '+56 9 1234 5678',
    'billing_email': 'race-local@example.invalid', 'billing_company': 'PRUEBA NO COMERCIAL',
    'billing_fp_rut': '76.123.456-7', 'billing_fp_giro': 'Prueba local',
    'billing_fp_dispatch': 'no', 'billing_fp_address': '',
    'payment_method': 'quotes-gateway', 'order_comments': 'Carrera local automatizada (no atender)'}

# ---------------------------------------------------------------- home (WA-04)
home_session = Session()
code, html = home_session.request('/')
home = check_home(html)

# ------------------------------------------------- race × RACE_ROUNDS (WA-01)
# Each round mirrors verify-woo-http.py: the first Store API call is the cart
# GET, whose Nonce header seeds every later authenticated call. Fresh session
# per round, identical selection — the rounds must never interfere.
session = Session()   # the last round's session is reused by replay/correct/renew
session.request('/wp-json/wc/store/v1/cart', api=True)
code, products = session.request(f'/wp-json/wc/store/v1/products?slug={SLUG}', api=True)
ok('product_exists', code == 200 and isinstance(products, list) and products, f'HTTP {code}')
pid = products[0]['id']

rounds = []
for round_index in range(RACE_ROUNDS):
    add_to_cart(session, f'cart_add_{round_index}', 70)
    values = checkout_form(session)
    ok(f'checkout_form_present_{round_index}', 'woocommerce-process-checkout-nonce' in values)
    values.update(RACE_FIELDS)
    round_token = values.get('fpw_attempt', '')

    barrier = threading.Barrier(2)
    def submit(_):
        opener = session.clone_client()
        req = urllib.request.Request(BASE + '/?wc-ajax=checkout', data=urllib.parse.urlencode(values).encode())
        barrier.wait(timeout=15)
        started = time.monotonic()
        with opener.open(req, timeout=180) as response:
            body = json.loads(response.read())
        body['_elapsed'] = round(time.monotonic() - started, 2)
        return body
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(submit, range(2)))

    def brief(result):
        message = result.get('messages') or result.get('message') or result.get('error') or ''
        text = re.sub(r'<[^>]+>', ' ', str(message))
        return f"{result.get('result')}: {' '.join(text.split())[:200]}"

    orders = {order_of(result) for result in results}
    ok(f'race_all_success_{round_index}', all(result.get('result') == 'success' for result in results),
       str([brief(r) for r in results]))
    ok(f'race_single_order_{round_index}', len(orders) == 1 and None not in orders,
       str(sorted((str(o) for o in orders))))
    ok(f'race_same_confirmation_{round_index}', len({result.get('redirect', '') for result in results}) == 1, 'redirects differ')
    print(f'race round {round_index} timings: {[r.get("_elapsed") for r in results]}', file=sys.stderr)
    round_order = next(iter(orders)) if len(orders) == 1 and None not in orders else None
    rounds.append({'order': round_order, 'token': round_token, 'values': values})
    if round_index < RACE_ROUNDS - 1:
        session = Session()   # fresh session for the next bounded round
        session.request('/wp-json/wc/store/v1/cart', api=True)
        session.request(f'/wp-json/wc/store/v1/products?slug={SLUG}', api=True)

race_order = rounds[-1]['order']
ok('race_distinct_orders', len({r['order'] for r in rounds}) == RACE_ROUNDS, str([r['order'] for r in rounds]))

# ------------------------------------------------------------------- replay
# The same form resubmitted after the attempt landed: the cart is empty, so
# Woo's own flow would answer «sesión caducada» — the landed-attempt recovery
# must instead return the SAME request's confirmation, creating no new order.
replay = post_checkout(session, rounds[-1]['values'])
ok('replay_recovers_same_request', replay.get('result') == 'success' and order_of(replay) == race_order,
   f"{replay.get('result')} redirect={str(replay.get('redirect'))[:60]}")

# ------------------------------------------------------- correct (#31 crit. 6)
# A pre-save validation error (despacho required, address missing) must leave
# the selection and data intact: the corrected re-submission succeeds on the
# SAME rebuilt cart — the cart only empties on persisted success.
add_to_cart(session, 'cart_add_correct', 70)
correct_values = checkout_form(session)
correct_values.update(RACE_FIELDS)
correct_values.update({'billing_fp_dispatch': 'si', 'billing_fp_address': ''})
first_try = post_checkout(session, correct_values)
ok('correct_first_rejected', first_try.get('result') == 'failure', str(first_try.get('result'))[:80])
messages = first_try.get('messages')
ok('correct_error_names_address',
   not isinstance(messages, dict) or any('dirección' in str(message).lower() for message in messages.get('error', [])),
   str(messages)[:120])
correct_values.update({'billing_fp_address': 'Camino de prueba 1, Mostazal, VI Región'})
corrected = post_checkout(session, correct_values)
ok('correct_second_success', corrected.get('result') == 'success', str(corrected)[:120])
correct_order = order_of(corrected) if corrected.get('result') == 'success' else None
ok('correct_distinct_order', correct_order is not None and correct_order != race_order, f'{correct_order} vs {race_order}')

# ---------------------------------------------------------- renew (#31 crit. 1/2)
# THE DEFECT: same session, same product, SAME quantity, SAME details — a new
# request after the previous one was completed. The fresh checkout page must
# carry a ROTATED attempt token, and the submission must produce a NEW request
# with its own reference, never fold into the previous order.
add_to_cart(session, 'cart_add_renew', 70)
renew_values = checkout_form(session)
renew_token = renew_values.get('fpw_attempt', '')
token_rotated = bool(renew_token) and renew_token != rounds[-1]['token']
ok('attempt_token_rotated', token_rotated, 'the completed attempt token must not be reused by a fresh checkout page')
renew_values.update(RACE_FIELDS)   # byte-identical posted content to the race
renewed = post_checkout(session, renew_values)
ok('renew_success', renewed.get('result') == 'success', str(renewed)[:160])
renew_order = order_of(renewed) if renewed.get('result') == 'success' else None
ok('renew_new_reference', renew_order is not None and renew_order != race_order and renew_order != correct_order,
   f'renew {renew_order} must not return the previous requests (race {race_order}, correct {correct_order})')

# ------------------------------------------------- lost (#32 criteria 1/2/3/7)
# The first submission's response is deliberately lost before it reaches the
# client (the client abandons the connection while the server processes): the
# request persists and the basket empties, all unseen. The SAME form is then
# retried, without visiting the confirmation: it must recover the ORIGINAL
# reference — not the native empty-cart rejection — creating no second request.
add_to_cart(session, 'cart_add_lost', 70)
lost_values = checkout_form(session)
ok('lost_form_present', 'woocommerce-process-checkout-nonce' in lost_values and bool(lost_values.get('fpw_attempt')))
lost_values.update(RACE_FIELDS)
lost_result = {}
def fire_and_lose():
    opener = session.clone_client()
    req = urllib.request.Request(BASE + '/?wc-ajax=checkout', data=urllib.parse.urlencode(lost_values).encode())
    try:
        opener.open(req, timeout=0.05)
        lost_result['abandoned'] = False   # the answer arrived: the loss was not simulated
    except Exception:
        lost_result['abandoned'] = True    # the response never reached the client
threading.Thread(target=fire_and_lose).start()
# Persistence proof that never reads the lost response: Woo empties the basket
# on persisted success — poll until the basket is empty (bounded).
cart_empty = False
for _ in range(150):
    code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
    if code == 200 and isinstance(cart, dict) and not cart.get('items'):
        cart_empty = True
        break
    time.sleep(0.2)
ok('lost_persisted_without_response', cart_empty and lost_result.get('abandoned') is True,
   f"cart_empty={cart_empty} abandoned={lost_result.get('abandoned')}")
retried = post_checkout(session, lost_values)
ok('lost_retry_recovers_confirmation', retried.get('result') == 'success', str(retried)[:160])
lost_order = order_of(retried) if retried.get('result') == 'success' else None
ok('lost_retry_original_reference', lost_order is not None and lost_order not in {r['order'] for r in rounds} | {correct_order, renew_order},
   f'lost {lost_order} must be the original request, never another one')

# --------------------------------------------------- inflight (#32 criterion 5)
# A retry while the first submission of the SAME attempt is still in flight
# answers recoverably (it folds into the winner inside the claim budget, or
# receives the recoverable «se está procesando» message); once the attempt
# completes, the original reference is obtained without a second request.
add_to_cart(session, 'cart_add_inflight', 70)
inflight_values = checkout_form(session)
inflight_values.update(RACE_FIELDS)
original_answer = {}
def submit_original():
    opener = session.clone_client()
    req = urllib.request.Request(BASE + '/?wc-ajax=checkout', data=urllib.parse.urlencode(inflight_values).encode())
    with opener.open(req, timeout=180) as response:
        original_answer['body'] = json.loads(response.read())
orig_thread = threading.Thread(target=submit_original)
orig_thread.start()
time.sleep(0.2)   # the original is now in flight
inflight_retry = post_checkout(session, inflight_values)
orig_thread.join(timeout=180)
inflight_messages = ' '.join(str(inflight_retry.get('messages', '')).split())
recoverable = inflight_retry.get('result') == 'success' or 'procesando' in inflight_messages
ok('inflight_retry_recoverable', recoverable, str(inflight_retry)[:140])
ok('inflight_original_success', original_answer.get('body', {}).get('result') == 'success', str(original_answer)[:140])
inflight_order = order_of(original_answer.get('body', {}))
if inflight_retry.get('result') == 'success':
    ok('inflight_retry_same_request', order_of(inflight_retry) == inflight_order,
       f'{order_of(inflight_retry)} vs {inflight_order}')
inflight_replay = post_checkout(session, inflight_values)
ok('inflight_replay_recovers_original', inflight_replay.get('result') == 'success' and order_of(inflight_replay) == inflight_order,
   str(inflight_replay)[:140])
ok('inflight_distinct_request', inflight_order is not None and inflight_order != lost_order, f'{inflight_order} vs {lost_order}')

# --------------------------------------------------- preserve (#32 criterion 6)
# Recovering the previous request must not vacate or alter a NEW selection
# unrelated to that attempt: with the new selection already in Productos a
# Cotizar, the landed form's retry still returns the original confirmation and
# the new selection survives untouched (Woo's own fold-in would empty it).
add_to_cart(session, 'cart_add_preserve', 3)
preserve = post_checkout(session, inflight_values)
ok('preserve_recovers_same_request', preserve.get('result') == 'success' and order_of(preserve) == inflight_order,
   f"{preserve.get('result')} {str(order_of(preserve))}")
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
preserve_lines = cart.get('items', []) if isinstance(cart, dict) else []
ok('preserve_new_selection_intact', code == 200 and len(preserve_lines) == 1 and int(preserve_lines[0].get('quantity', 0)) == 3,
   f"items={[(i.get('id'), i.get('quantity')) for i in preserve_lines]}")
# Empty the unrelated selection again (Store API) so the stranger probes run on
# the native empty-cart path without creating anything.
for item in list(preserve_lines):
    session.request('/wp-json/wc/store/v1/cart/remove-item', {'key': item.get('key', '')}, api=True)
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
ok('probe_cart_cleared', code == 200 and isinstance(cart, dict) and not cart.get('items'))

# --------------------------------------------------- stranger (#32 criterion 4)
# Knowing an identifier authorizes nothing: a well-formed but UNKNOWN attempt
# token and a STOLEN form replayed from ANOTHER session both receive Woo's own
# safe rejection — never the recovered reference, never a new request.
stranger_values = dict(inflight_values)
stranger_values['fpw_attempt'] = 'cd' * 20
unknown = post_checkout(session, stranger_values)
ok('unknown_token_safe_answer', unknown.get('result') == 'failure', str(unknown)[:140])
ok('unknown_token_no_reference', order_of(unknown) is None, str(unknown.get('redirect', ''))[:80])
thief = Session()
thief.request('/wp-json/wc/store/v1/cart', api=True)  # seed its own Store API nonce
stolen = post_checkout(thief, inflight_values)        # the stolen form: same nonce + token
ok('foreign_session_safe_answer', stolen.get('result') == 'failure', str(stolen)[:140])
ok('foreign_session_no_reference', order_of(stolen) is None, str(stolen.get('redirect', ''))[:80])

# ---------------------------------------------------------------- isolation
other = Session()
other.request('/wp-json/wc/store/v1/cart', api=True)  # seed the Store API nonce
code, products = other.request(f'/wp-json/wc/store/v1/products?slug={SLUG}', api=True)
add_to_cart(other, 'cart_add_other', 3)
other_values = checkout_form(other)
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

print(json.dumps({'home': home, 'race_orders': [r['order'] for r in rounds],
                  'replay_recovered_order': order_of(replay) if replay.get('result') == 'success' else None,
                  'correct_order': correct_order, 'renew_order': renew_order,
                  'attempt_token_rotated': token_rotated,
                  'lost_order': lost_order,
                  'inflight_order': inflight_order, 'inflight_retry_recoverable': bool(recoverable),
                  'preserve_recovers_original': order_of(preserve) == inflight_order and preserve.get('result') == 'success',
                  'preserve_cart_lines': len(preserve_lines),
                  'unknown_token_safe': unknown.get('result') == 'failure' and order_of(unknown) is None,
                  'foreign_session_safe': stolen.get('result') == 'failure' and order_of(stolen) is None,
                  'isolate_order': other_order,
                  'failures': failures}, ensure_ascii=False))
raise SystemExit(1 if failures else 0)
