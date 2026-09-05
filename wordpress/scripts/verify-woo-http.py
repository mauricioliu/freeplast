#!/usr/bin/env python3
"""Anonymous staging-only regression. Creates ONE marked test request; mail MUST be contained.
Never point at production. No cookies, nonces, order keys or personal data are printed.
"""
import urllib.request, urllib.parse, urllib.error, http.cookiejar, json, re, sys
from html.parser import HTMLParser
if sys.argv[1:] in ([], ['--help']):
    print('scope: staging-only test; creates one marked request\nhelp: python3 wordpress/scripts/verify-woo-http.py --execute-staging\nwarning: verify staging mail containment before executing')
    raise SystemExit(0)
if sys.argv[1:] != ['--execute-staging']:
    print('error: unknown arguments\nhelp: supported flags are --help or --execute-staging')
    raise SystemExit(2)
BASE='https://freeplast.mliu.site'
jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
nonce=''
def request(path,data=None,api=False):
    global nonce
    headers={}
    if data is not None:
        data=(json.dumps(data) if api else urllib.parse.urlencode(data)).encode()
        headers['Content-Type']='application/json' if api else 'application/x-www-form-urlencoded'
    if api and nonce: headers['Nonce']=nonce
    req=urllib.request.Request(BASE+path,data=data,headers=headers)
    try: response=client.open(req,timeout=60)
    except urllib.error.HTTPError as e: response=e
    if response.headers.get('Nonce'): nonce=response.headers['Nonce']
    body=response.read().decode()
    return response.status, json.loads(body) if api else body
class Inputs(HTMLParser):
    def __init__(self): super().__init__();self.values={}
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('type')=='hidden' and a.get('name'): self.values[a['name']]=a.get('value','')
status, cart=request('/wp-json/wc/store/v1/cart',api=True);assert status==200
status, products=request('/wp-json/wc/store/v1/products?slug=caja-cosechera-3-4',api=True);simple=products[0]['id']
status, parents=request('/wp-json/wc/store/v1/products?slug=caja-universal-cerrada-color',api=True);parent=parents[0]['id']
# The native Store API validates variant selection and quantities.
status,bad=request('/wp-json/wc/store/v1/cart/add-item',{'id':parent,'quantity':1},True);assert status==400,(status,bad)
status,cart=request('/wp-json/wc/store/v1/cart/add-item',{'id':simple,'quantity':70},True);assert status in (200,201),(status,cart)
key=cart['items'][0]['key']
status,cart=request('/wp-json/wc/store/v1/cart/update-item',{'key':key,'quantity':140},True);assert status==200
assert cart['items'][0]['quantity']==140
status,cart=request('/wp-json/wc/store/v1/cart/add-item',{'id':parent,'quantity':5,'variation':[{'attribute':'Color','value':'Rojo'}]},True);assert status in (200,201),(status,cart)
assert len(cart['items'])==2
status,html=request('/datos-y-envio/');assert status==200
parser=Inputs();parser.feed(html)
values=parser.values
values.update({'billing_first_name':'PRUEBA TÉCNICA MIGRACIÓN','billing_phone':'+56 9 1234 5678','billing_email':'quote-probe@example.invalid','billing_company':'PRUEBA NO COMERCIAL','billing_fp_rut':'76.123.456-7','billing_fp_giro':'Prueba técnica','billing_fp_dispatch':'si','billing_fp_address':'','payment_method':'quotes-gateway','order_comments':'Prueba automatizada de migración. No atender ni enviar correos reales.'})
status,body=request('/?wc-ajax=checkout',values);result=json.loads(body)
assert result['result']=='failure' and 'dirección' in result['messages'].lower(),result
values['billing_fp_dispatch']='no';values['billing_fp_rut']=''
status,body=request('/?wc-ajax=checkout',values);result=json.loads(body)
assert result['result']=='failure' and 'rut' in result['messages'].lower(),result
values['billing_fp_rut']='76.123.456-7';values['billing_fp_address']='Dirección descartada al elegir sin despacho'
status,body=request('/?wc-ajax=checkout',values);result=json.loads(body)
assert result['result']=='success',result
url=urllib.parse.urlparse(result['redirect']);status,confirmation=request(url.path+'?'+url.query)
assert status==200 and 'Solicitud recibida' in confirmation
assert 'Caja Cosechera' in confirmation and '140' in confirmation and 'Rojo' in confirmation
status,cart=request('/wp-json/wc/store/v1/cart',api=True);assert not cart['items']
order_id=int(re.search(r'/order-received/(\d+)',url.path).group(1))
for name, value in {'anonymous':True,'missing_color_rejected':True,'quantity_saved':140,'red_units':5,'dispatch_address_required':True,'rut_required':True,'submission':True,'confirmation':True,'cart_cleared':True,'test_order_id':order_id}.items():
    print(f'{name}: {json.dumps(value)}')
