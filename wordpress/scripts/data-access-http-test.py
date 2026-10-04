#!/usr/bin/env python3
"""Local review only. Credential JSON on stdin; never print it.
Exercises the dedicated data-maintainer account: the hub and its two screens
are reachable; quotation surfaces and the rest of wp-admin are not.
Usage: pass show freeplast/local-data-manager | python3 wordpress/scripts/data-access-http-test.py
"""
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

credentials = json.load(sys.stdin)
BASE = 'http://mliu:8096'
assert credentials['url'].startswith(BASE + '/mantenedor'), 'Only the local data reviewer is allowed'
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

request('/wp-login.php')
status, body, url = request('/wp-login.php', {'log': credentials['username'], 'pwd': credentials['password'], 'redirect_to': BASE + '/mantenedor/', 'testcookie': '1'})
check(status == 200 and 'page=fpw-data' in url, 'login lands directly on the data hub')
html = body.decode()
check('Mantenedor de datos' in html and 'Privado del dueño' in html, 'the hub names itself and its privacy')
check('page=fpw-price-list' in html and 'page=fpw-sales-import' in html, 'both maintainer links are offered')
status, body, _ = request('/wp-admin/admin.php?page=fpw-price-list')
rest_nonce = re.search(r'<meta name="fpw-local-rest-nonce" content="([^"]+)">', body.decode())
check(status == 200 and rest_nonce and 'Precios por producto y opción' in body.decode(), 'price maintainer opens for the data role')
check('Referencias por volumen' in body.decode(), 'volume reference editors render')
status, body, _ = request('/wp-admin/admin.php?page=fpw-sales-import')
check(status == 200 and b'fpw-sales' in body, 'sales importer opens for the data role')
for path in [
    '/wp-admin/admin.php?page=fpw-quotations',
    '/wp-admin/admin.php?page=fpw-quote-draft&request=64&workspace=1',
    '/wp-admin/plugins.php', '/wp-admin/users.php', '/wp-admin/options-general.php',
    '/wp-admin/edit.php?post_type=product', '/wp-admin/edit.php?post_type=shop_order',
    '/wp-admin/admin.php?page=wc-settings', '/wp-admin/admin-post.php?action=fpw_quotation_pdf&request=66&version=1&_wpnonce=forged',
    '/wp-admin/admin.php?page=fpw-data&action=evil',
]:
    check(request(path)[0] == 403, 'denied for the data role: ' + path)
status, body, url = request('/cotizaciones/')
check(status == 403 and 'gestionar cotizaciones' in body.decode(), 'quotation entry refuses the data role')
status, body, _ = request('/wp-json/wp/v2/users/me', headers={'X-WP-Nonce': rest_nonce.group(1)})
check(status == 403, 'authenticated REST is blocked for the data role')
print(f'data-maintainer local HTTP: {checks} checks passed; hub, prices and sales only')
