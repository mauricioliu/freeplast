#!/usr/bin/env python3
"""Opt-in WPSC read/generation test. Global plugin and its settings stay inactive.
Never prints capability, cookies, full HTML, cPanel URLs, or credentials.
"""
import datetime as dt, hashlib, html, json, re, secrets, signal, sys, time
from pathlib import Path
import requests
from fp_cache_cpanel import Panel
OUT=Path('/home/mauricio-liu/Projects/freeplast/docs/reviews/wpsc-recovery-2026-09-05')
ROOT='/home/freeplast'; CONTENT=ROOT+'/public_html/wp-content'

def main():
    OUT.mkdir(exist_ok=True)
    def emit(event,**data):
        line=json.dumps({'at':dt.datetime.now(dt.timezone.utc).isoformat(),'event':event,**data},ensure_ascii=False)
        print(line,flush=True)
        with (OUT/'canary.jsonl').open('a') as f: f.write(line+'\n')
    p=Panel()
    if p.present(CONTENT,'advanced-cache.php'): raise RuntimeError('Existing advanced-cache; refuse overwrite')
    original_config=p.read(CONTENT,'wp-cache-config.php')
    wpconfig_hash=hashlib.sha256(p.read(ROOT+'/public_html','wp-config.php').encode()).hexdigest()
    ht_hash=hashlib.sha256(p.read(ROOT+'/public_html','.htaccess').encode()).hexdigest()
    api=requests.get('https://freeplast.cl/wp-json/wc/store/products?per_page=100',headers={'User-Agent':'Mozilla/5.0 FreeplastWPSCCanary'},timeout=40).json()
    truth={x['permalink']:html.unescape(x['name']) for x in api}
    assert len(truth)==12
    paths=['/','/categoria-producto/agricola/','/categoria-producto/carnes/','/categoria-producto/otros/','/categoria-producto/productos-del-mar/']
    for url in truth:
        assert re.fullmatch(r'https://freeplast\.cl/producto/[a-z0-9-]+/',url)
        paths.append(url.removeprefix('https://freeplast.cl'))
    cap=secrets.token_hex(32); name='.fp-wpsc-canary-'+secrets.token_hex(6); private=ROOT+'/'+name
    expires=int(time.time())+1200
    config=Path('/tmp/fp-wpsc-source/wp-cache-config-sample.php').read_text().replace('?>','')
    config+='\n// Isolated, bounded canary overrides. No live settings or scheduled work.\n'
    config+=f"$cache_path = '{private}/cache/';\n"
    config+="""$cache_enabled = true;
$super_cache_enabled = true;
$cache_max_time = 600;
$cache_rebuild_files = 0;
$wp_cache_preload_on = 0;
$wp_cache_preload_taxonomies = 0;
$wp_cache_not_logged_in = 2;
$wp_cache_no_cache_for_get = 1;
$wp_cache_mod_rewrite = 0;
$wp_cache_slash_check = 1;
$wp_cache_clear_on_post_edit = 1;
$cache_schedule_type = 'canary-disabled';
$wpsc_served_header = true;
$wp_supercache_cache_list = 0;
$wp_super_cache_debug = 1;
$wp_cache_debug_log = 'canary-debug.log';
"""
    wrapper="""<?php
// Temporary opt-in diagnostic: anonymous public traffic returns untouched.
if (!defined('ABSPATH') || time() > __EXP__) { return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !empty($_COOKIE) || !empty($_SERVER['QUERY_STRING'])) { return; }
if (($_SERVER['HTTP_HOST'] ?? '') !== 'freeplast.cl') { return; }
if (!in_array($_SERVER['REQUEST_URI'] ?? '', __PATHS__, true)) { return; }
$fp_canary_header = $_SERVER['HTTP_X_FP_WPSC_CANARY'] ?? '';
if (!is_string($fp_canary_header) || !hash_equals('__HASH__', hash('sha256', $fp_canary_header))) { unset($fp_canary_header); return; }
unset($fp_canary_header);
header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
header('X-FP-WPSC-Canary: selected');
define('WPCACHEHOME', WP_CONTENT_DIR . '/plugins/wp-super-cache/');
define('WPCACHECONFIGPATH', '__PRIVATE__');
define('WPSC_SUPERCACHE_ONLY', true);
define('WPSC_CACHE_CONTROL_HEADER', 'private, no-store, no-cache, max-age=0, must-revalidate');
register_shutdown_function(function () {
    $e = error_get_last();
    $row = array('uri'=>$_SERVER['REQUEST_URI'], 'phase1'=>!empty($GLOBALS['wp_cache_phase1_loaded']), 'cache_enabled'=>$GLOBALS['cache_enabled'] ?? null, 'config_readable'=>is_readable(WPCACHECONFIGPATH . '/wp-cache-config.php'), 'cache_dir'=>is_dir(WPCACHECONFIGPATH . '/cache'), 'error_type'=>$e['type'] ?? null, 'error_message'=>$e['message'] ?? null);
    file_put_contents(WPCACHECONFIGPATH . '/diagnostic.jsonl', json_encode($row)."\\n", FILE_APPEND | LOCK_EX);
});
if (($_SERVER['HTTP_X_FP_WPSC_CANARY_PURGE'] ?? '') === '1') {
    define('WPSC_SERVE_DISABLED', true);
    define('DONOTCACHEPAGE', true);
    add_action('wp_loaded', function () { wp_cache_post_edit(165); });
}
require WPCACHEHOME . 'wp-cache-phase1.php';
""".replace('__EXP__',str(expires)).replace('__PATHS__',"array("+','.join("'"+x+"'" for x in paths)+")").replace('__HASH__',hashlib.sha256(cap.encode()).hexdigest()).replace('__PRIVATE__',private)
    created=False; staged=False; installed=False
    def get(path,label,selected=True,cookie=False,expect_hit=None,encoding='identity',purge=False):
        headers={'User-Agent':'Mozilla/5.0 FreeplastWPSCCanary','Accept':'text/html','Accept-Encoding':encoding}
        if purge: headers['X-FP-WPSC-Canary-Purge']='1'
        if selected: headers['X-FP-WPSC-Canary']=cap
        if cookie: headers['Cookie']='fp_canary_synthetic=1'
        t=time.monotonic()
        r=requests.get('https://freeplast.cl'+path,headers=headers,timeout=45,allow_redirects=False)
        text=r.text
        clean=lambda x:' '.join(html.unescape(re.sub('<[^>]+>','',x)).split())
        h1=[clean(x) for x in re.findall(r'<h1\b[^>]*>(.*?)</h1>',text,re.S|re.I)]
        canonical=re.findall(r'<link\b[^>]*rel=[\"\']canonical[\"\'][^>]*href=[\"\']([^\"\']+)',text,re.I)
        expected=truth.get('https://freeplast.cl'+path.split('?')[0])
        pairs=[(html.unescape(url),clean(name)) for url,name in re.findall(r'<a\b[^>]*href=[\"\']([^\"\']+)[\"\'][^>]*>\s*<h2\b[^>]*class=[\"\'][^\"\']*woocommerce-loop-product__title[^\"\']*[\"\'][^>]*>(.*?)</h2>\s*</a>',text,re.S|re.I)]
        counts={'/categoria-producto/agricola/':8,'/categoria-producto/carnes/':2,'/categoria-producto/otros/':2,'/categoria-producto/productos-del-mar/':2}
        cards_ok=path not in counts or (len(pairs)==counts[path] and all(truth.get(u)==n for u,n in pairs))
        correct=r.status_code==200 and canonical==['https://freeplast.cl'+path.split('?')[0]] and (not expected or h1==[expected])
        hit='X-WP-Super-Cache' in r.headers
        controls=r.raw.headers.getlist('Cache-Control')
        chosen='X-FP-WPSC-Canary' in r.headers
        should_select=selected and not cookie and '?' not in path
        ok=correct and cards_ok and chosen==should_select and (expect_hit is None or hit==expect_hit) and not r.headers.get('Age')
        if should_select: ok=ok and any('private' in v and 'no-store' in v for v in controls)
        emit('get',label=label,path=path,status=r.status_code,elapsed=round(time.monotonic()-t,3),h1=h1,canonical=canonical,cache_control=controls,content_encoding=r.headers.get('Content-Encoding'),card_count=len(pairs),age=r.headers.get('Age'),hit=hit,selected=chosen,sha256=hashlib.sha256(r.content).hexdigest(),passed=ok)
        if not ok:
            if p.present(private+'/cache','canary-debug.log'):
                log=p.read(private+'/cache','canary-debug.log')
                markers=['Not caching','Not Caching','Caching disabled','Could not','could not','Cannot','not exist','No closing','Fatal error','DONOTCACHEPAGE','Renamed temp','not in cache','is empty','Supercache disabled','Cache has expired']
                selected_lines=[line for line in log.splitlines() if any(m in line for m in markers) and not re.search('cookie|secret|password|token|key',line,re.I)]
                emit('cache_diagnostics',lines=selected_lines[-45:])
            raise RuntimeError('Canary assertion failed: '+label)
        return r
    def interrupted(signum,frame): raise RuntimeError('Interrupted')
    signal.signal(signal.SIGINT,interrupted); signal.signal(signal.SIGTERM,interrupted)
    try:
        created=True; p.mkdir(ROOT,name); p.mkdir(private,'cache')
        p.upload(private+'/cache','canary-debug.log','[canary-only debug log]\n')
        p.upload(private,'wp-cache-config.php',config)
        staged=True; p.upload(CONTENT,'advanced-cache.php.fp-canary-stage',wrapper)
        if p.present(CONTENT,'advanced-cache.php'): raise RuntimeError('Drop-in collision')
        installed=True; p.rename(CONTENT,'advanced-cache.php.fp-canary-stage','advanced-cache.php'); staged=False
        if p.read(CONTENT,'advanced-cache.php')!=wrapper: raise RuntimeError('Drop-in mismatch')
        emit('installed',dropin=CONTENT+'/advanced-cache.php',private=private,expires=expires,public_caching=False)
        get('/producto/totem/','public-control',selected=False,expect_hit=False)
        for round_ in range(2):
            for index,path in enumerate(paths[:3] if '--quick' in sys.argv else paths):
                get(path,f'round-{round_+1}-{index}',expect_hit=bool(round_))
                time.sleep(1.6)
        if '--quick' in sys.argv:
            emit('quick_test_completed')
            return
        for i,path in enumerate(['/producto/traversas-para-bines/','/producto/traversas-para-bins-tipo-w/']*3):
            get(path,f'repro-{i}',expect_hit=True); time.sleep(1.6)
        get('/producto/totem/','cookie-bypass',cookie=True,expect_hit=False)
        get('/producto/totem/?fp_verify=20260905','query-bypass',expect_hit=False)
        get('/producto/totem/','public-post-warm',selected=False,expect_hit=False)
        for path in ['/', '/categoria-producto/agricola/','/producto/totem/','/producto/traversas-para-bins-tipo-w/']:
            get(path,'gzip-hit',expect_hit=True,encoding='gzip')
        get('/producto/totem/','purge-handler',purge=True,expect_hit=False)
        for path in ['/', '/categoria-producto/agricola/','/producto/totem/']:
            get(path,'after-invalidation-miss',expect_hit=False,encoding='gzip')
            get(path,'after-invalidation-hit',expect_hit=True,encoding='gzip')
        if '--no-idle' not in sys.argv:
            emit('idle',seconds=150); time.sleep(150)
            get('/producto/totem/','idle150-hit',expect_hit=True)
        emit('test_completed')
    finally:
        if created and p.present(ROOT,name) and p.present(private,'diagnostic.jsonl'):
            rows=[json.loads(x) for x in p.read(private,'diagnostic.jsonl').splitlines() if x.strip()]
            emit('runtime_diagnostics',rows=rows)
        if created and p.present(ROOT,name) and p.present(private,'cache') and p.present(private+'/cache','canary-debug.log'):
            log=p.read(private+'/cache','canary-debug.log')
            markers=['Not caching','Not Caching','Caching disabled','Could not','could not','Cannot','not exist','No closing','Fatal error','DONOTCACHEPAGE','Renamed temp','not in cache','is empty','Supercache disabled','Cache has expired']
            selected_lines=[line for line in log.splitlines() if any(m in line for m in markers) and not re.search('cookie|secret|password|token|key',line,re.I)]
            emit('final_cache_diagnostics',lines=selected_lines[-45:])
        if installed and p.present(CONTENT,'advanced-cache.php'):
            if p.read(CONTENT,'advanced-cache.php')!=wrapper: raise RuntimeError('Drop-in changed externally; manual cleanup needed')
            p.trash(CONTENT+'/advanced-cache.php')
        if staged and p.present(CONTENT,'advanced-cache.php.fp-canary-stage'): p.trash(CONTENT+'/advanced-cache.php.fp-canary-stage')
        if created and p.present(ROOT,name): p.trash(private)
        unchanged=p.read(CONTENT,'wp-cache-config.php')==original_config and hashlib.sha256(p.read(ROOT+'/public_html','wp-config.php').encode()).hexdigest()==wpconfig_hash and hashlib.sha256(p.read(ROOT+'/public_html','.htaccess').encode()).hexdigest()==ht_hash
        clean=not p.present(CONTENT,'advanced-cache.php') and not p.present(ROOT,name)
        emit('cleanup_verified',dropin_absent=not p.present(CONTENT,'advanced-cache.php'),private_absent=not p.present(ROOT,name),live_config_unchanged=unchanged,disposition='own files moved to cPanel trash outside docroot')
        if not clean or not unchanged: raise RuntimeError('Cleanup/config invariant failed')
if __name__=='__main__':
    if '--authorized-production-replay' not in sys.argv:
        print(json.dumps({'error':'Archived production mutator; do not replay routinely. Read ../README.md and obtain a new GO before --authorized-production-replay.'})); sys.exit(2)
    try: main()
    except Exception as e:
        import traceback
        print(json.dumps({'fatal':type(e).__name__,'stack':[{'file':Path(x.filename).name,'line':x.lineno,'function':x.name} for x in traceback.extract_tb(e.__traceback__)]}),flush=True); sys.exit(1)
