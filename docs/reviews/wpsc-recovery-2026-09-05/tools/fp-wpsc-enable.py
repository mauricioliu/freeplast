#!/usr/bin/env python3
import datetime as dt, hashlib, html, json, re, signal, sys, time
from pathlib import Path
from urllib.parse import urljoin
import requests
from fp_cache_cpanel import Panel
from fp_cache_admin import Admin
OUT=Path('/home/mauricio-liu/Projects/freeplast/docs/reviews/wpsc-recovery-2026-09-05')
CONTENT='/home/freeplast/public_html/wp-content'; BACKUP='/home/freeplast/.fp-wpsc-recovery-backup-20260905'
def main():
    def emit(event,**data):
        line=json.dumps({'at':dt.datetime.now(dt.timezone.utc).isoformat(),'event':event,**data},ensure_ascii=False)
        print(line,flush=True)
        with (OUT/'public-rollout.jsonl').open('a') as f: f.write(line+'\n')
    p=Panel(); a=Admin(); success=False
    if not a.active() or p.read(CONTENT,'advanced-cache.php')!=Path('/tmp/fp-wpsc-source/advanced-cache.php').read_text(): raise RuntimeError('Unexpected staged state')
    original_other={f:hashlib.sha256(p.read('/home/freeplast/public_html',f).encode()).hexdigest() for f in ['wp-config.php','.htaccess']}
    def interrupted(signum,frame): raise RuntimeError('Interrupted')
    signal.signal(signal.SIGINT,interrupted); signal.signal(signal.SIGTERM,interrupted)
    def submit(form,data=None):
        fields={}
        for x in form.select('input,textarea,select'):
            name=x.get('name'); typ=x.get('type','')
            if not name or x.has_attr('disabled') or typ in ['submit','button','file']: continue
            if typ in ['radio','checkbox'] and not x.has_attr('checked'): continue
            if x.name=='textarea': value=x.get_text()
            elif x.name=='select':
                opt=x.find('option',selected=True) or x.find('option'); value=opt.get('value',opt.get_text()) if opt else ''
            else: value=x.get('value','')
            fields[name]=value
        if data: fields.update(data)
        url=urljoin('https://freeplast.cl/wp-admin/options-general.php?page=wpsupercache&tab=settings',form.get('action') or '')
        if not url.startswith('https://freeplast.cl/wp-admin/'): raise RuntimeError('Unexpected form target')
        r=a.s.post(url,data=fields,timeout=60)
        if r.status_code!=200: raise RuntimeError('Admin submit failed')
    UA='Mozilla/5.0 FreeplastCacheRecovery'
    truth={x['permalink']:html.unescape(x['name']) for x in requests.get('https://freeplast.cl/wp-json/wc/store/products?per_page=100',headers={'User-Agent':UA},timeout=40).json()}
    counts={'/categoria-producto/agricola/':8,'/categoria-producto/carnes/':2,'/categoria-producto/otros/':2,'/categoria-producto/productos-del-mar/':2}
    def get(path,label,expect_hit=None,cookie=None,encoding='gzip',canonical_check=True):
        headers={'User-Agent':UA,'Accept':'text/html','Accept-Encoding':encoding}
        if cookie: headers['Cookie']=cookie
        t=time.monotonic(); r=requests.get('https://freeplast.cl'+path,headers=headers,timeout=45,allow_redirects=False)
        text=r.text; clean=lambda x:' '.join(html.unescape(re.sub('<[^>]+>','',x)).split())
        h1=[clean(x) for x in re.findall(r'<h1\b[^>]*>(.*?)</h1>',text,re.S|re.I)]
        canonical=re.findall(r'<link\b[^>]*rel=[\"\']canonical[\"\'][^>]*href=[\"\']([^\"\']+)',text,re.I)
        pairs=[(html.unescape(u),clean(n)) for u,n in re.findall(r'<a\b[^>]*href=[\"\']([^\"\']+)[\"\'][^>]*>\s*<h2\b[^>]*class=[\"\'][^\"\']*woocommerce-loop-product__title[^\"\']*[\"\'][^>]*>(.*?)</h2>\s*</a>',text,re.S|re.I)]
        basepath=path.split('?')[0]; expected=truth.get('https://freeplast.cl'+basepath)
        hit='X-WP-Super-Cache' in r.headers; controls=r.raw.headers.getlist('Cache-Control')
        ok=r.status_code==200 and (not canonical_check or canonical==['https://freeplast.cl'+basepath]) and (not expected or h1==[expected]) and (expect_hit is None or hit==expect_hit) and not r.headers.get('Age') and any('private' in v and 'no-store' in v for v in controls)
        if basepath in counts: ok=ok and len(pairs)==counts[basepath] and all(truth.get(u)==n for u,n in pairs)
        emit('get',label=label,path=path,status=r.status_code,elapsed=round(time.monotonic()-t,3),h1=h1,canonical=canonical,cards=len(pairs),hit=hit,age=r.headers.get('Age'),cache_control=controls,content_encoding=r.headers.get('Content-Encoding'),sha256=hashlib.sha256(r.content).hexdigest(),passed=ok)
        if not ok: raise RuntimeError('Regression failed '+label)
        return r
    try:
        soup=a.soup('options-general.php?page=wpsupercache&tab=settings')
        checkbox=soup.find('input',{'name':'wp_cache_enabled'})
        if not checkbox or checkbox.has_attr('checked'): raise RuntimeError('Unexpected enable state')
        form=checkbox.find_parent('form')
        submit(form,{'wp_cache_enabled':'1'})
        cfg=p.read(CONTENT,'wp-cache-config.php')
        expected={'cache_enabled':'true','super_cache_enabled':'true','cache_rebuild_files':'0','wp_cache_preload_on':'0','wp_cache_preload_taxonomies':'0','wp_cache_clear_on_post_edit':'1','wp_cache_mod_rewrite':'0','wp_cache_no_cache_for_get':'1','wp_cache_not_logged_in':'2','cache_compression':'0','wp_cache_make_known_anon':'0'}
        emit('settings_readback',settings={k:re.findall(r'^\$'+re.escape(k)+r'\s*=\s*([^;]+);',cfg,re.M) for k in expected})
        for k,v in expected.items():
            m=re.findall(r'^\$'+re.escape(k)+r'\s*=\s*([^;]+);',cfg,re.M)
            if not m or any(x.strip()!=v for x in m): raise RuntimeError('Unsafe config '+k)
        if p.read(CONTENT,'advanced-cache.php')!=Path('/tmp/fp-wpsc-source/advanced-cache.php').read_text(): raise RuntimeError('Loader changed')
        if (OUT/'cache-guard.php').read_text().removeprefix('<?php\n') not in cfg: raise RuntimeError('Guard altered')
        emit('enabled_verified',settings=expected,cache_path='/home/freeplast/wpsc-cache/',ttl_seconds=1800,garbage_collection_seconds=600)
        paths=['/']+list(counts)+[u.removeprefix('https://freeplast.cl') for u in truth]
        for round_ in range(2):
            for i,path in enumerate(paths):
                get(path,f'round-{round_+1}-{i}',expect_hit=True if round_ else None)
                time.sleep(1.6)
        for i,path in enumerate(['/producto/traversas-para-bines/','/producto/traversas-para-bins-tipo-w/']*3):
            get(path,f'original-repro-{i}',expect_hit=True); time.sleep(1.6)
        for cookie in ['fp_synthetic=1','woocommerce_items_in_cart=1','wordpress_logged_in_synthetic=1','malformed-cookie']:
            get('/producto/totem/','cookie-bypass',expect_hit=False,cookie=cookie)
        get('/producto/totem/?fp_verify=20260905','query-bypass',expect_hit=False)
        get('/wp-json/wc/store/products?per_page=1','api-bypass',expect_hit=False,canonical_check=False)
        get('/producto/totem/','identity-hit',expect_hit=True,encoding='identity')
        # Native admin cache-clear form. No product edits or business-form submissions.
        simple=a.soup('options-general.php?page=wpsupercache')
        purge=simple.find('input',{'name':'wp_delete_cache'})
        if not purge: raise RuntimeError('Native purge form absent')
        submit(purge.find_parent('form'))
        emit('native_purge_submitted')
        get('/producto/totem/','after-native-purge-miss',expect_hit=False)
        get('/producto/totem/','after-native-purge-hit',expect_hit=True)
        for path in ['/', '/categoria-producto/agricola/','/producto/traversas-para-bins-tipo-w/']:
            get(path,'rewarm-after-purge')
        emit('idle',seconds=150); time.sleep(150)
        get('/producto/totem/','idle150-hit',expect_hit=True)
        get('/producto/traversas-para-bins-tipo-w/','idle150-g1-hit',expect_hit=True)
        if not a.active(): raise RuntimeError('Plugin inactive after test')
        unchanged=all(hashlib.sha256(p.read('/home/freeplast/public_html',name).encode()).hexdigest()==h for name,h in original_other.items())
        emit('completed',plugin_active=True,cache_on=True,wpconfig_htaccess_unchanged=unchanged)
        success=True
    finally:
        if not success:
            if p.present(CONTENT,'advanced-cache.php'): p.trash(CONTENT+'/advanced-cache.php')
            a.deactivate()
            if p.present(CONTENT,'wp-cache-config.php'): p.trash(CONTENT+'/wp-cache-config.php')
            p.api2('fileop',op='rename',sourcefiles=BACKUP+'/wp-cache-config.php',destfiles=CONTENT+'/wp-cache-config.php',doubledecode=0)
            emit('rollback_verified',plugin_inactive=not a.active(),dropin_absent=not p.present(CONTENT,'advanced-cache.php'))
if __name__=='__main__':
    if '--authorized-production-replay' not in sys.argv:
        print(json.dumps({'error':'Archived production mutator; do not replay routinely. Read ../README.md and obtain a new GO before --authorized-production-replay.'})); sys.exit(2)
    try: main()
    except Exception as e:
        import traceback
        print(json.dumps({'fatal':type(e).__name__,'stack':[{'file':Path(x.filename).name,'line':x.lineno} for x in traceback.extract_tb(e.__traceback__)]}),flush=True);sys.exit(1)
