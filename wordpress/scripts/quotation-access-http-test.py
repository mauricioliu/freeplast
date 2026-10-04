#!/usr/bin/env python3
"""Local review only. Credential JSON on stdin; never print it.
Exercises the actual account, route boundaries, current draft save and frozen PDF.
Only saves unchanged inputs on a synthetic local request (revision advances).
Usage: pass show freeplast/local-owner-review | python3 wordpress/scripts/quotation-access-http-test.py
"""
import http.cookiejar
import json
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from html import unescape
from html.parser import HTMLParser

credentials = json.load(sys.stdin)
BASE = 'http://mliu:8096'
assert credentials['url'].startswith(BASE + '/'), 'Only the local reviewer is allowed'
# MagicDNS stub occasionally drops one resolution mid-run; retry instead of failing the suite.
_getaddrinfo = socket.getaddrinfo
def _stable_getaddrinfo(host, port, *rest):
    last = None
    for _ in range(5):
        try:
            return _getaddrinfo(host, port, *rest)
        except socket.gaierror as error:
            last = error
            time.sleep(1)
    raise last
socket.getaddrinfo = _stable_getaddrinfo
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0

def request(path, data=None, headers=None):
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(BASE + path, data, headers or {})
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read(), response.url

def check(value, label):
    global checks
    assert value, label
    checks += 1

for entry in ['/cotizaciones/', '/cotizaciones', '/cotizaciones/?redirect_to=https://example.invalid/']:
    status, body, url = request(entry)
    destination = urllib.parse.urlsplit(url)
    check(status == 200 and destination.path == '/wp-login.php', 'guest entry reaches login')
    check(urllib.parse.parse_qs(destination.query).get('redirect_to') == [BASE + '/cotizaciones/'], 'login return ignores caller-supplied redirects')
check(request('/cotizaciones/', {'action': 'anything'})[0] == 405, 'entry refuses POST')
check(request('/cotizacion/')[0] == 200, 'customer singular quote basket remains available')
status, body, url = request('/mantenedor/')
guest = urllib.parse.urlsplit(url)
check(status == 200 and guest.path == '/wp-login.php' and urllib.parse.parse_qs(guest.query).get('redirect_to') == [BASE + '/mantenedor/'], 'guest data entry reaches login with safe return')
check(request('/mantenedor/', {'action': 'x'})[0] == 405, 'data entry refuses POST')
status, body, url = request('/wp-login.php', {'log': credentials['username'], 'pwd': credentials['password'], 'redirect_to': BASE + '/wp-admin/plugins.php', 'testcookie': '1'})
check(status == 200 and 'page=fpw-quotations' in url, 'login always lands in quotations, not requested technical page')
html = body.decode()
status, _, url = request('/cotizaciones/')
check(status == 200 and 'page=fpw-quotations' in url, 'authenticated friendly entry opens workspace')
status, body, _ = request('/mantenedor/')
check(status == 403 and 'privado del dueño' in body.decode(), 'quotation-only account is denied the data hub')
check('Pendientes' in html and 'Enviadas' in html and 'Todas' in html, 'three owner views render')
check('Agrícola de ejemplo' in html and 'Distribuidora de ejemplo' in html and 'Empresa de ejemplo' not in html, 'default view is pending work only')
status, sent_view, _ = request('/wp-admin/admin.php?page=fpw-quotations&stage=sent-quotes')
check('Empresa de ejemplo' in sent_view.decode() and 'Agrícola de ejemplo' not in sent_view.decode(), 'Enviadas view correct')
status, all_view, _ = request('/wp-admin/admin.php?page=fpw-quotations&stage=all')
check(all(x in all_view.decode() for x in ['Agrícola de ejemplo', 'Distribuidora de ejemplo', 'Empresa de ejemplo']), 'Todas view includes everything')
check('Cerrar sesión' in html and '>Administración<' not in html, 'quotation-only navigation')
status, _, url = request('/wp-admin/index.php')
check(status == 200 and 'page=fpw-quotations' in url, 'dashboard redirects to inbox')
for path in [
    '/wp-admin/plugins.php', '/wp-admin/users.php', '/wp-admin/user-new.php',
    '/wp-admin/options-general.php', '/wp-admin/options.php', '/wp-admin/themes.php',
    '/wp-admin/edit.php?post_type=product', '/wp-admin/post-new.php?post_type=product',
    '/wp-admin/edit.php?post_type=shop_order', '/wp-admin/admin.php?page=wc-settings',
    '/wp-admin/admin.php?page=fpw-price-list', '/wp-admin/admin.php?page=fpw-sales-import',
    '/wp-admin/profile.php', '/wp-admin/admin-ajax.php?action=woocommerce_json_search_products',
    '/wp-admin/admin-post.php?action=anything',
    '/wp-admin/admin.php?page=fpw-quotations&action=anything',
    '/wp-admin/admin.php?page=fpw-quote-draft&request=64',
]:
    check(request(path)[0] == 403, 'direct route denied: ' + path)
check(request('/wp-admin/admin.php?page=fpw-quotations', {'action': 'anything'})[0] == 403, 'POST action cannot ride on allowed screen')
check(request('/wp-admin/admin-post.php?action=fpw_quotation_pdf&request=66&version=1&_wpnonce=forged')[0] == 403, 'PDF nonce enforced')

class Form(HTMLParser):
    def __init__(self):
        super().__init__(); self.active = False; self.fields = {}; self.textarea = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.active = a.get('id') == 'fpw-work-form'
        if not self.active:
            return
        if tag == 'input' and a.get('name') and a.get('type') not in ('submit', 'checkbox', 'radio'):
            self.fields[a['name']] = a.get('value', '')
        if tag == 'textarea' and a.get('name'):
            self.textarea = a['name']; self.fields[self.textarea] = ''
    def handle_data(self, data):
        if self.textarea:
            self.fields[self.textarea] += data
    def handle_endtag(self, tag):
        if tag == 'textarea': self.textarea = None
        if tag == 'form': self.active = False

draft = '/wp-admin/admin.php?page=fpw-quote-draft&request=64&workspace=1'
status, body, _ = request(draft)
check(status == 200 and b'fpw-work-form' in body, 'draft editor available')
check(b'Abrir lista de precios' not in body, 'price maintainer not offered')
form = Form(); form.feed(body.decode())
check('fpw_draft_nonce' in form.fields and 'fpw_work_revision' in form.fields, 'native save inputs available')
original_revision = int(form.fields['fpw_work_revision'])
payload = {**form.fields, 'fpw_work_save': '1'}
check(request(draft, {**payload, 'fpw_draft_nonce': 'forged'})[0] == 403, 'save rejects forged nonce')
status, body, _ = request(draft, payload)
saved = Form(); saved.feed(body.decode())
check(status == 200 and int(saved.fields['fpw_work_revision']) == original_revision + 1, 'authorized unchanged-input save persists one revision')
check(request(draft, {**saved.fields, 'fpw_work_preview': '1'})[0] == 200, 'review action available')
status, body, _ = request('/wp-admin/admin.php?page=fpw-quote-draft&request=66&workspace=1')
check(status == 200, 'issued detail available')
links = [unescape(x) for x in re.findall(r'href="([^"]+)"', body.decode())]
pdf = next(x for x in links if 'action=fpw_quotation_pdf' in x)
u = urllib.parse.urlsplit(pdf)
status, document, _ = request(u.path + '?' + u.query)
check(status == 200 and document.startswith(b'%PDF-'), 'authorized frozen PDF download')
# WordPress REST nonce proves this is an authenticated request (not anonymous cookie fallback).
nonce = re.search(r'<meta name="fpw-local-rest-nonce" content="([^"]+)">', body.decode())
if not nonce:
    raise AssertionError('REST nonce absent; cannot claim authenticated REST denial')
status, rest_body, _ = request('/wp-json/wp/v2/users/me', headers={'X-WP-Nonce': nonce.group(1)})
check(status == 403 and json.loads(rest_body).get('code') == 'fpw_restricted_account', 'authenticated REST blocked by the restricted-account boundary, not an invalid nonce')
print(f'quotation-only local HTTP: {checks} checks passed; unchanged-input save advanced one synthetic revision')
