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
             selection unrelated to that attempt (#32 criterion 6): with a
             two-line selection (simple product + Rojo variant) in Productos a
             Cotizar, the old form's retry returns the original confirmation
             and the selection survives EXACTLY — full identity/options/
             quantity snapshot equality, not a line count (#35).
  replayunknown — THE #35 DEFECT (criteria 2/3/5): after an attempt landed AND
             a two-line selection (simple product + Rojo variant) sits in the
             basket, submitting the form with an UNKNOWN well-formed attempt
             token must be rejected safely by the identity gate — no
             confirmation, no new request, no fold into the landed attempt,
             and EVERY line, variant and quantity survives exactly (full
             identity/options/quantity snapshot equality; the pre-fix code
             substituted the open token, folded into the landed request and
             emptied the basket through the quotes gateway).
  lostmulti — THE #36 DEFECT (criteria 2/3): A saves but its response is lost
             before the customer receives or visits the confirmation; B then
             completes in the same session; A is retried inside its lifetime
             with its ORIGINAL form and a valid nonce. A's OWN reference and
             confirmation return — not B, not «sesión caducada» — first over
             an empty basket, then over a THIRD unrelated two-line selection
             whose every line, variant and quantity survives unchanged
             (pre-#36: only the latest binding survived, so A was refused).
  stale     — #36 criterion 2/5 (supersedes the #35-planned expectation): an
             OLDER completed form recovers ITS OWN attempt throughout its
             lifetime even after a NEWER attempt completed and rotated the
             open token — read-only, with the rebuilt two-line selection
             exactly intact — and the newer form recovers its own request
             the same way; the fresh submission of the rotated attempt still
             produced its own NEW reference.
  plainreplay — the MANDATORY no-JS boundary (#36 lead red-gate): a completed
             attempt replayed through the PLAIN form route (no wc-ajax, so
             the read-only recovery never runs) over the two-line selection
             gets the cart-preserving identity-gate rejection — never the
             fold, whatever the landing's age; every line/variant/quantity
             survives.
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

def post_checkout_plain(session, values):
    """POST the native NON-AJAX checkout form route (the page itself, no
    wc-ajax): Woo's own no-JS fallback — the #36 mandatory cart-preserving
    rejection seam. The COPIED payload carries the native PLACE-ORDER submit
    trigger (woocommerce_checkout_place_order): the pinned
    WC_Form_Handler::checkout_action dispatches process_checkout ONLY on that
    trigger or on update_totals — which is deliberately NOT used, because it
    skips order processing entirely and would make the probe vacuous. The
    caller's values dict is never mutated."""
    submitted = dict(values)
    submitted['woocommerce_checkout_place_order'] = 'Solicitar cotización'
    return session.request('/checkout/', submitted)

def post_checkout_in_background(session, values, timeout):
    """POST the native checkout endpoint in a background thread on an
    independent connection (cookies cloned): the answer lands in
    result['body'] when read, in result['error'] when lost or failed."""
    result = {}
    def run():
        opener = session.clone_client()
        req = urllib.request.Request(BASE + '/?wc-ajax=checkout', data=urllib.parse.urlencode(values).encode())
        try:
            with opener.open(req, timeout=timeout) as response:
                result['body'] = json.loads(response.read())
        except Exception as error:
            result['error'] = error
    thread = threading.Thread(target=run)
    thread.start()
    return thread, result

def add_to_cart(session, name, quantity):
    """Add the featured product to a session's cart; every scenario starts here."""
    code, _ = session.request('/wp-json/wc/store/v1/cart/add-item', {'id': pid, 'quantity': quantity}, api=True)
    ok(name, code in (200, 201), f'HTTP {code}')

def add_to_cart_variant(session, name, quantity):
    """Add the seeded VARIABLE fixture's Rojo variant — a distinct variant line."""
    code, _ = session.request('/wp-json/wc/store/v1/cart/add-item',
                              {'id': VARIANT_ID, 'quantity': quantity,
                               'variation': [{'attribute': 'attribute_color', 'value': 'Rojo'}]}, api=True)
    ok(name, code in (200, 201), f'HTTP {code}')

def normalized_variation(item):
    """The pinned Woo 11.1.0 Store API answers `variation` as a list of
    {raw_attribute, attribute, value} objects (dict-shaped in other eras):
    normalize both into one stable option set."""
    raw = item.get('variation') or []
    if isinstance(raw, dict):
        return sorted((str(key), str(value)) for key, value in raw.items())
    return sorted((str(entry.get('raw_attribute') or entry.get('attribute') or ''), str(entry.get('value') or ''))
                  for entry in raw if isinstance(entry, dict))

def cart_snapshot(session):
    """Stable identity of EVERY selection line — product/variant identity,
    chosen options and quantity — compared exactly across operations (issue
    #35: preservation of every line, variant and quantity, never a mere
    line/quantity count). Returns None when the cart cannot be read."""
    code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
    items = cart.get('items') if isinstance(cart, dict) else None
    if code != 200 or items is None:
        return None
    lines = [{'type': item.get('type'), 'id': item.get('id'), 'name': item.get('name'),
              'quantity': int(item.get('quantity', 0)),
              'variation': normalized_variation(item)}
             for item in items]
    return sorted(lines, key=lambda line: json.dumps(line, sort_keys=True))

def seed_selection(session, tag, simple_quantity, variant_quantity):
    """Build the two-line synthetic selection (distinct simple product + the
    Rojo variant) and return its full snapshot — the #35 preservation unit."""
    add_to_cart(session, f'cart_add_simple_{tag}', simple_quantity)
    add_to_cart_variant(session, f'cart_add_variant_{tag}', variant_quantity)
    snapshot = cart_snapshot(session)
    ok(f'selection_seeded_{tag}', snapshot is not None and len(snapshot) == 2
       and any(line['type'] == 'variation' and line['variation'] for line in snapshot)
       and any(line['type'] == 'simple' for line in snapshot),
       f'snapshot={json.dumps(snapshot)}')
    return snapshot

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
# The #35 variant fixture (seeded idempotently by bootstrap on every run):
# a variable product whose Rojo variant gives the preservation snapshots a
# distinct variant line next to the simple featured product.
code, variant_products = session.request('/wp-json/wc/store/v1/products?slug=caja-variable-color-prueba', api=True)
ok('variant_fixture_present', code == 200 and isinstance(variant_products, list) and variant_products, f'HTTP {code}')
VARIANT_ID = variant_products[0]['id'] if isinstance(variant_products, list) and variant_products else 0

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
lost_thread, lost_result = post_checkout_in_background(session, lost_values, timeout=0.05)
# Persistence proof that never reads the lost response: Woo empties the basket
# on persisted success — poll until the basket is empty (bounded).
cart_empty = False
for _ in range(150):
    code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
    if code == 200 and isinstance(cart, dict) and not cart.get('items'):
        cart_empty = True
        break
    time.sleep(0.2)
lost_thread.join()   # the abandoned client has certainly given up by now
ok('lost_persisted_without_response', cart_empty and 'error' in lost_result,
   f"cart_empty={cart_empty} answer_read={'body' in lost_result}")
retried = post_checkout(session, lost_values)
ok('lost_retry_recovers_confirmation', retried.get('result') == 'success', str(retried)[:160])
lost_order = order_of(retried) if retried.get('result') == 'success' else None
ok('lost_retry_original_reference', lost_order is not None and lost_order not in {r['order'] for r in rounds} | {correct_order, renew_order},
   f'lost {lost_order} must be the original request, never another one')

# ------------------------------------------------ lostmulti (#36 crit. 2/3)
# THE DEFECT: A saves but its response is lost before the customer receives
# or visits the confirmation; B then completes in the same session; A is
# retried inside its lifetime with its ORIGINAL form and a valid nonce. A's
# own reference must return — not B's, not «sesión caducada» — first over
# the emptied basket, then over a THIRD unrelated selection (two lines:
# simple + variant) that survives exactly.
add_to_cart(session, 'cart_add_lostmulti_a', 70)
lostmulti_a_values = checkout_form(session)
ok('lostmulti_form_present', 'woocommerce-process-checkout-nonce' in lostmulti_a_values and bool(lostmulti_a_values.get('fpw_attempt')))
lostmulti_a_values.update(RACE_FIELDS)
lostmulti_thread, lostmulti_result = post_checkout_in_background(session, lostmulti_a_values, timeout=0.05)   # A's response is lost
lostmulti_persisted = False
for _ in range(150):
    code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
    if code == 200 and isinstance(cart, dict) and not cart.get('items'):
        lostmulti_persisted = True
        break
    time.sleep(0.2)
lostmulti_thread.join()   # A's answer stays unread: the customer never received it
ok('lostmulti_a_persisted_unseen', lostmulti_persisted and 'error' in lostmulti_result,
   f"persisted={lostmulti_persisted} answer_read={'body' in lostmulti_result}")
add_to_cart(session, 'cart_add_lostmulti_b', 6)   # B: a second request completes in the same session
lostmulti_b_values = checkout_form(session)   # rotation: A closed
ok('lostmulti_token_rotated', bool(lostmulti_b_values.get('fpw_attempt', '')) and lostmulti_b_values.get('fpw_attempt', '') != lostmulti_a_values.get('fpw_attempt', ''),
   'B opens on a rotated token after A landed unseen')
lostmulti_b_values.update(RACE_FIELDS)
lostmulti_b = post_checkout(session, lostmulti_b_values)
ok('lostmulti_b_success', lostmulti_b.get('result') == 'success', str(lostmulti_b)[:140])
lostmulti_b_order = order_of(lostmulti_b) if lostmulti_b.get('result') == 'success' else None
lostmulti_a_retry = post_checkout(session, lostmulti_a_values)   # retry A, ORIGINAL form, valid nonce, empty basket
ok('lostmulti_retry_recovers_a', lostmulti_a_retry.get('result') == 'success' and order_of(lostmulti_a_retry) is not None and order_of(lostmulti_a_retry) != lostmulti_b_order,
   f"{lostmulti_a_retry.get('result')} {str(order_of(lostmulti_a_retry))} vs B {lostmulti_b_order}")
lostmulti_a_order = order_of(lostmulti_a_retry)
lostmulti_before = seed_selection(session, 'lostmulti_third', 7, 2)   # a THIRD, unrelated selection
lostmulti_a_retry2 = post_checkout(session, lostmulti_a_values)
ok('lostmulti_retry_recovers_a_with_selection', lostmulti_a_retry2.get('result') == 'success' and order_of(lostmulti_a_retry2) == lostmulti_a_order,
   str(lostmulti_a_retry2)[:140])
lostmulti_after = cart_snapshot(session)
ok('lostmulti_selection_preserved', lostmulti_after == lostmulti_before and len(lostmulti_after or []) == 2
   and any(line['type'] == 'variation' and line['variation'] for line in lostmulti_after or []),
   f'before={json.dumps(lostmulti_before)} after={json.dumps(lostmulti_after)}')
# Empty the third selection again so the inflight scenario starts from a clean basket.
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
for item in list(cart.get('items', [])):
    session.request('/wp-json/wc/store/v1/cart/remove-item', {'key': item.get('key', '')}, api=True)
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
ok('lostmulti_cart_cleared', code == 200 and isinstance(cart, dict) and not cart.get('items'))

# --------------------------------------------------- inflight (#32 criterion 5)
# A retry while the first submission of the SAME attempt is still in flight
# answers recoverably (it folds into the winner inside the claim budget, or
# receives the recoverable «se está procesando» message); once the attempt
# completes, the original reference is obtained without a second request.
add_to_cart(session, 'cart_add_inflight', 70)
inflight_values = checkout_form(session)
inflight_values.update(RACE_FIELDS)
orig_thread, original_answer = post_checkout_in_background(session, inflight_values, timeout=180)
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

# --------------------------------------------------- preserve (#32 crit. 6, #35)
# Recovering the previous request must not vacate or alter a NEW selection
# unrelated to that attempt: with a TWO-LINE selection (distinct simple
# product + Rojo variant) already in Productos a Cotizar, the landed form's
# retry still returns the original confirmation and the selection survives
# untouched — proven by full identity/options/quantity snapshot equality
# (Woo's own fold-in would empty it).
preserve_before = seed_selection(session, 'preserve', 3, 4)
preserve = post_checkout(session, inflight_values)
ok('preserve_recovers_same_request', preserve.get('result') == 'success' and order_of(preserve) == inflight_order,
   f"{preserve.get('result')} {str(order_of(preserve))}")
preserve_after = cart_snapshot(session)
ok('preserve_selection_identical', preserve_after == preserve_before and len(preserve_after or []) == 2,
   f'before={json.dumps(preserve_before)} after={json.dumps(preserve_after)}')
preserve_cart_lines = len(preserve_after or [])

# ------------------------------------------- replayunknown (#35 crit. 2/3/5)
# THE DEFECT: the attempt landed, a NEW selection already sits in Productos a
# Cotizar, and the submitted form carries an UNKNOWN well-formed token. The
# identity gate must reject the submission safely: no confirmation in the
# answer, no new request, no fold — and every line, variant and quantity
# survives exactly (full snapshot equality; the pre-fix fold emptied the
# basket through the quotes gateway).
replay_before = cart_snapshot(session)
replay_unknown = dict(inflight_values)
replay_unknown['fpw_attempt'] = 'ef' * 20
rejected = post_checkout(session, replay_unknown)
ok('replayunknown_rejected_safely', rejected.get('result') == 'failure', str(rejected)[:140])
ok('replayunknown_no_confirmation', order_of(rejected) is None, str(rejected.get('redirect', ''))[:80])
replay_after = cart_snapshot(session)
ok('replayunknown_selection_preserved', replay_after == replay_before and len(replay_after or []) == 2
   and any(line['type'] == 'variation' and line['variation'] for line in replay_after or []),
   f'before={json.dumps(replay_before)} after={json.dumps(replay_after)}')

# ----------------------------------------------------------------- stale (#36)
# Supersedes the #35-planned expectation (the old code lost A, so rejection
# was the safe answer): a NEWER attempt completed and rotated the open token
# away from A's. A's form now recovers A's OWN confirmation — read-only,
# first over the emptied basket, then over a rebuilt two-line selection with
# full snapshot equality — and the newer form recovers its own request the
# same way. The fresh submission of the rotated attempt still had its own
# NEW reference.
stale_values = checkout_form(session)   # the render ROTATES: landed attempt closed
stale_token = stale_values.get('fpw_attempt', '')
ok('stale_token_rotated', bool(stale_token) and stale_token != inflight_values.get('fpw_attempt', ''),
   'the render after a landing must rotate the open token away from the landed one')
stale_values.update(RACE_FIELDS)
stale_fresh = post_checkout(session, stale_values)
ok('stale_fresh_form_success', stale_fresh.get('result') == 'success', str(stale_fresh)[:140])
stale_order = order_of(stale_fresh) if stale_fresh.get('result') == 'success' else None
ok('stale_new_reference', stale_order is not None and stale_order not in {r['order'] for r in rounds} | {correct_order, renew_order, lost_order, inflight_order},
   f'stale {stale_order} must be its own new request')
stale_empty_replay = post_checkout(session, inflight_values)   # A's form, basket emptied by B's success
ok('stale_older_form_recovers_original', stale_empty_replay.get('result') == 'success' and order_of(stale_empty_replay) == inflight_order,
   f"{stale_empty_replay.get('result')} {str(order_of(stale_empty_replay))} vs A {inflight_order}")
stale_before = seed_selection(session, 'stale_replay', 2, 5)
stale_replay = post_checkout(session, inflight_values)   # the OLDER form over a rebuilt selection
ok('stale_older_form_recovers_again', stale_replay.get('result') == 'success' and order_of(stale_replay) == inflight_order,
   str(stale_replay)[:140])
stale_after = cart_snapshot(session)
ok('stale_selection_preserved', stale_after == stale_before and len(stale_after or []) == 2
   and any(line['type'] == 'variation' and line['variation'] for line in stale_after or []),
   f'before={json.dumps(stale_before)} after={json.dumps(stale_after)}')
stale_b_replay = post_checkout(session, stale_values)   # the NEWER form recovers its own request the same way
ok('stale_b_form_recovers_own', stale_b_replay.get('result') == 'success' and order_of(stale_b_replay) == stale_order,
   f"{stale_b_replay.get('result')} {str(order_of(stale_b_replay))} vs B {stale_order}")
check_snapshot_after_b = cart_snapshot(session)
ok('stale_b_selection_preserved', check_snapshot_after_b == stale_before,
   f'before={json.dumps(stale_before)} after={json.dumps(check_snapshot_after_b)}')
stale_repeat = post_checkout(session, inflight_values)   # repeated recovery creates nothing (#36 crit. 4)
ok('stale_repeat_recovers_original', stale_repeat.get('result') == 'success' and order_of(stale_repeat) == inflight_order,
   str(stale_repeat)[:140])

# ------------------------------------------------------------ plainreplay (#36)
# The MANDATORY no-JS boundary (lead red-gate): a completed attempt replayed
# through the PLAIN form route (the page itself — no wc-ajax, so the read-only
# recovery never runs) over the two-line selection. The identity gate must
# answer with the cart-preserving rejection — never the fold, whatever the
# landing's age: the page carries the Spanish reload guidance and every line,
# variant and quantity survives.
plain_snapshot_before = cart_snapshot(session)   # read BEFORE the POST: the pre-state the replay must preserve
ok('plainreplay_baseline_known', plain_snapshot_before == stale_before and len(plain_snapshot_before or []) == 2,
   f'baseline={json.dumps(plain_snapshot_before)} (must equal the known two-line selection)')
plain_code, plain_body = post_checkout_plain(session, stale_values)   # B's completed form, posted == open
ok('plainreplay_rejected_safely', plain_code == 200 and 'ya fue recibida' in plain_body,
   f'HTTP {plain_code}, guidance-present={"ya fue recibida" in plain_body}')
ok('plainreplay_no_confirmation', 'order-received' not in plain_body and 'pedido recibido' not in plain_body,
   'the plain-route replay must not render a confirmation')
plain_snapshot_after = cart_snapshot(session)
ok('plainreplay_selection_preserved', plain_snapshot_after == plain_snapshot_before and len(plain_snapshot_after or []) == 2
   and any(line['type'] == 'variation' and line['variation'] for line in plain_snapshot_after or []),
   f'before={json.dumps(plain_snapshot_before)} after={json.dumps(plain_snapshot_after)}')

# Empty the unrelated selection again (Store API) so the stranger probes run on
# the native empty-cart path without creating anything.
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
for item in list(cart.get('items', [])):
    session.request('/wp-json/wc/store/v1/cart/remove-item', {'key': item.get('key', '')}, api=True)
code, cart = session.request('/wp-json/wc/store/v1/cart', api=True)
ok('probe_cart_cleared', code == 200 and isinstance(cart, dict) and not cart.get('items'))

# --------------------------------------------------- stranger (#32 criterion 4)
# Knowing an identifier authorizes nothing: a well-formed but UNKNOWN attempt
# token and a STOLEN form replayed from ANOTHER session both receive the safe
# rejection — never the recovered reference, never a new request.
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

# ------------------------------------------------------- scenario ledger (#36 rev 2)
# Every new request of the whole run, by scenario id: the mail-event
# expectation and the originality proofs are DERIVED from this ledger, never
# hand-typed (3 race rounds + correct + renew + lost + lostmulti A + lostmulti B
# + inflight + stale + isolate = 11 new requests).
scenario_orders = {'race_0': rounds[0]['order'], 'race_1': rounds[1]['order'], 'race_2': rounds[2]['order'],
                   'correct': correct_order, 'renew': renew_order, 'lost': lost_order,
                   'lostmulti_a': lostmulti_a_order, 'lostmulti_b': lostmulti_b_order,
                   'inflight': inflight_order, 'stale': stale_order, 'isolate': other_order}
scenario_ids = list(scenario_orders.values())
ok('scenario_ids_unique', all(isinstance(i, int) and i > 0 for i in scenario_ids) and len(set(scenario_ids)) == len(scenario_ids),
   f'duplicated or missing scenario ids: {scenario_orders}')
NEW_REQUEST_COUNT = 11
new_request_count = len(set(scenario_ids))
ok('new_request_count', new_request_count == NEW_REQUEST_COUNT,
   f'{new_request_count} new requests across the run, expected {NEW_REQUEST_COUNT}')

print(json.dumps({'home': home, 'race_orders': [r['order'] for r in rounds],
                  'replay_recovered_order': order_of(replay) if replay.get('result') == 'success' else None,
                  'correct_order': correct_order, 'renew_order': renew_order,
                  'attempt_token_rotated': token_rotated,
                  'lost_order': lost_order,
                  'inflight_order': inflight_order, 'inflight_retry_recoverable': bool(recoverable),
                  'preserve_recovers_original': order_of(preserve) == inflight_order and preserve.get('result') == 'success',
                  'preserve_selection_identical': preserve_after == preserve_before,
                  'preserve_cart_lines': preserve_cart_lines,
                  'replayunknown_rejected': rejected.get('result') == 'failure' and order_of(rejected) is None,
                  'replayunknown_selection_identical': replay_after == replay_before,
                  'replayunknown_cart_lines': len(replay_after or []),
                  'stale_order': stale_order,
                  'stale_older_form_recovers_original': stale_empty_replay.get('result') == 'success' and order_of(stale_empty_replay) == inflight_order,
                  'stale_selection_identical': stale_after == stale_before,
                  'stale_b_form_recovers_own': stale_b_replay.get('result') == 'success' and order_of(stale_b_replay) == stale_order,
                  'stale_repeat_recovers_original': stale_repeat.get('result') == 'success' and order_of(stale_repeat) == inflight_order,
                  'plainreplay_rejected': plain_code == 200 and 'ya fue recibida' in plain_body and 'order-received' not in plain_body,
                  'plainreplay_selection_identical': plain_snapshot_after == plain_snapshot_before,
                  'plainreplay_cart_lines': len(plain_snapshot_after or []),
                  'lostmulti_a_order': lostmulti_a_order,
                  'lostmulti_b_order': lostmulti_b_order,
                  'lostmulti_retry_recovers_a': lostmulti_a_retry.get('result') == 'success' and lostmulti_a_order is not None and lostmulti_a_order != lostmulti_b_order,
                  'lostmulti_selection_identical': lostmulti_after == lostmulti_before,
                  'lostmulti_cart_lines': len(lostmulti_after or []),
                  'unknown_token_safe': unknown.get('result') == 'failure' and order_of(unknown) is None,
                  'foreign_session_safe': stolen.get('result') == 'failure' and order_of(stolen) is None,
                  'isolate_order': other_order,
                  'scenario_ids_unique': len(set(scenario_ids)) == len(scenario_ids) and all(isinstance(i, int) and i > 0 for i in scenario_ids),
                  'new_request_count': new_request_count,
                  'scenario_orders': scenario_orders,
                  'failures': failures}, ensure_ascii=False))
raise SystemExit(1 if failures else 0)
