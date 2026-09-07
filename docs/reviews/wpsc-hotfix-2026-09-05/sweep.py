#!/usr/bin/env python3
"""Read-only, paced anonymous production regression probe. No cookies or purge."""
import datetime, hashlib, html, json, pathlib, re, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).parent
BASE = 'https://freeplast.cl'
records = []
def text(s):
    return ' '.join(html.unescape(re.sub('<[^>]+>', '', s)).split())
def get(path, label):
    time.sleep(1.6)
    url = path if path.startswith('https:') else BASE + path
    try:
        r = urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 Freeplast-readonly-verification'}), timeout=35)
    except urllib.error.HTTPError as e:
        r = e
    body = r.read(); decoded = body.decode('utf-8', errors='replace')
    record = {'label': label, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'url':url, 'final_url':r.url, 'status':r.code,
        'cache_control':r.headers.get_all('Cache-Control', []), 'age':r.headers.get('Age'), 'vary':r.headers.get_all('Vary', []),
        'h1': [text(s) for s in re.findall(r'<h1\b[^>]*>(.*?)</h1>', decoded, re.S|re.I)],
        'canonical':re.findall(r'<link\b[^>]*rel=[\"\']canonical[\"\'][^>]*href=[\"\']([^\"\']+)',decoded,re.I),
        'cache_comments':re.findall(r'<!--[^>]*(?:Cached page|super cache|Dynamic page)[^>]*-->', decoded, re.I),
        'sha256':hashlib.sha256(body).hexdigest()}
    (ROOT/(label+'.body')).write_bytes(body)
    records.append(record)
    (ROOT/'results.json').write_text(json.dumps(records,ensure_ascii=False,indent=2))
    return decoded,record
catalog,_=get('/wp-json/wc/store/products?per_page=100','store-api')
products=json.loads(catalog)
truth={p['permalink']:html.unescape(p['name']) for p in products}
print('Store API products:', len(products), flush=True)
for round_ in range(1,3):
    for cat,count in [('agricola',8),('carnes',2),('otros',2),('productos-del-mar',2)]:
        page,c=get('/categoria-producto/'+cat+'/',f'r{round_}-{cat}')
        pairs=[(html.unescape(url),text(name)) for url,name in re.findall(r'<a\b[^>]*href=[\"\']([^\"\']+)[\"\'][^>]*>\s*<h2\b[^>]*class=[\"\'][^\"\']*woocommerce-loop-product__title[^\"\']*[\"\'][^>]*>(.*?)</h2>\s*</a>',page,re.S|re.I)]
        c['card_count']=len(pairs); c['expected_count']=count; c['pass']=c['status']==200 and len(pairs)==count
        print(f'ROUND {round_} {cat}: {len(pairs)}/{count} cards; Cache-Control={c["cache_control"]}',flush=True)
        for i,(url,name) in enumerate(pairs):
            _,r=get(url,f'r{round_}-{cat}-p{i}')
            r['card_name']=name; r['api_name']=truth.get(url)
            r['pass']=r['status']==200 and r['final_url']==url and r['h1']==[name] and r['api_name']==name and r['canonical']==[url]
            print(('PASS' if r['pass'] else 'FAIL'),url,'card=',name,'h1=',r['h1'],'API=',r['api_name'],flush=True)
for i,path in enumerate(['/producto/totem/','/producto/traversas-para-bins-tipo-w/']*3):
    _,r=get(path, f'repeat-{i}')
    r['pass']=r['status']==200 and r['h1']==[truth.get(BASE+path)] and r['canonical']==[BASE+path]
    print('REPEAT', 'PASS' if r['pass'] else 'FAIL',path,r['h1'],r['cache_control'],flush=True)
for i,path in enumerate(['/categoria-producto/agricola/','/producto/totem/','/producto/traversas-para-bins-tipo-w/']):
    _,r=get(path+'?fp_verify=20260905',f'query-{i}')
    if '/producto/' in path:
        r['pass']=r['status']==200 and r['h1']==[truth.get(BASE+path)] and r['canonical']==[BASE+path]
    print('QUERY',path,r['h1'],r['cache_control'],flush=True)
_,legacy=get('/producto/caja-pollera/','legacy-pollera')
print('Legacy Pollera URL:',legacy['status'],flush=True)
(ROOT/'results.json').write_text(json.dumps(records,ensure_ascii=False,indent=2))
checks=[r for r in records if 'pass' in r]
summary={'requests':len(records),'checks':len(checks),'passed':sum(r['pass'] for r in checks),'failed':sum(not r['pass'] for r in checks),'multiple_cache_control':sum(len(r['cache_control'])>1 for r in records)}
print(json.dumps(summary),flush=True)
(ROOT/'summary.json').write_text(json.dumps(summary,indent=2))
