"""Bounded staging probe: exactly two concurrent submits + one replay, synthetic only.
Reuses the reviewed staging HTTP regression setup, never prints session/order secrets.
"""
import sys
if sys.argv[1:] in ([], ['--help']):
    print('scope: staging-only concurrency probe; up to three synthetic orders\nhelp: python3 docs/reviews/woo-acceptance-2026-09-05/evidence/concurrency-probe.py --execute-staging\nwarning: backup and confirm staging mail containment before executing\nresult: inspect unique_returned_orders and server records; exit code does not assert uniqueness')
    raise SystemExit(0)
if sys.argv[1:] != ['--execute-staging']:
    print('error: unknown arguments\nhelp: supported flags are --help or --execute-staging')
    raise SystemExit(2)
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import threading, copy
source=Path('wordpress/scripts/verify-woo-http.py').read_text()
cut="status,body=request('/?wc-ajax=checkout',values);result=json.loads(body)\nassert result['result']=='success'"
assert source.count(cut)==1
exec(compile(source.split(cut)[0], 'reviewed-staging-setup', 'exec'))
values['billing_first_name']='PRUEBA CONCURRENCIA 20260905'
values['billing_email']='race-20260905@example.invalid'
values['order_comments']='PRUEBA TECNICA de concurrencia (2 envios + 1 reintento). NO ATENDER. Conservar como evidencia.'
barrier=threading.Barrier(2)
def submit(_):
    cloned=http.cookiejar.CookieJar()
    for cookie in jar: cloned.set_cookie(copy.copy(cookie))
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cloned))
    req=urllib.request.Request(BASE+'/?wc-ajax=checkout',data=urllib.parse.urlencode(values).encode())
    barrier.wait(timeout=10)
    with opener.open(req,timeout=60) as response: return json.loads(response.read())
with ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(submit,range(2)))
# Re-submit before opening any confirmation, as when the user did not receive it.
status,body=request('/?wc-ajax=checkout',values)
replay=json.loads(body)
def safe(r):
    m=re.search(r'/order-received/(\d+)',r.get('redirect',''))
    return {'result':r.get('result'),'order_id':int(m.group(1)) if m else None,'messages_text':re.sub('<[^>]*>',' ',r.get('messages','')).strip()[:500]}
print(json.dumps({'parallel':[safe(r) for r in results],'replay_before_confirmation':safe(replay)},ensure_ascii=False))
ids={safe(r)['order_id'] for r in results+[replay]}-{None}
print('unique_returned_orders:',len(ids))
status,cart=request('/wp-json/wc/store/v1/cart',api=True)
print('cart_items_after:',len(cart.get('items',[])))
# Probe anonymous receipt protection without exposing keys, contacts or complete pages.
for id in sorted(ids):
    status,body=request('/datos-y-envio/order-received/'+str(id)+'/')
    print('receipt_without_key:',json.dumps({'order_id':id,'status':status,'synthetic_name_visible':'PRUEBA CONCURRENCIA 20260905' in body,'product_visible':'Caja Cosechera 3/4' in body}))
