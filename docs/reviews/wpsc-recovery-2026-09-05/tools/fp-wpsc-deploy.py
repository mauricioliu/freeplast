#!/usr/bin/env python3
"""Controlled deployment: native plugin active, guarded PHP loader, cache OFF at staging.
All rollback originals stay private on the host. No credentials/config secrets printed.
"""
import datetime as dt, hashlib, json, signal, sys
from pathlib import Path
from urllib.parse import urljoin
from fp_cache_cpanel import Panel
from fp_cache_admin import Admin
ROOT='/home/freeplast'; CONTENT=ROOT+'/public_html/wp-content'
BACKUP=ROOT+'/.fp-wpsc-recovery-backup-20260905'
OUT=Path('/home/mauricio-liu/Projects/freeplast/docs/reviews/wpsc-recovery-2026-09-05')
def main():
    def emit(event,**data):
        line=json.dumps({'at':dt.datetime.now(dt.timezone.utc).isoformat(),'event':event,**data})
        print(line,flush=True)
        with (OUT/'deployment.jsonl').open('a') as f: f.write(line+'\n')
    p=Panel(); a=Admin()
    if a.active(): raise RuntimeError('Unexpected active plugin')
    if p.present(CONTENT,'advanced-cache.php') or p.present(ROOT,Path(BACKUP).name) or p.present(ROOT,'wpsc-cache'): raise RuntimeError('Collision')
    original=p.read(CONTENT,'wp-cache-config.php')
    hashes={name:hashlib.sha256(p.read(ROOT+'/public_html',name).encode()).hexdigest() for name in ['wp-config.php','.htaccess']}
    staged=False; moved=False; activated=False; success=False; created_cache=False; dropin=False
    def interrupted(signum,frame): raise RuntimeError('Interrupted')
    signal.signal(signal.SIGINT,interrupted); signal.signal(signal.SIGTERM,interrupted)
    try:
        p.mkdir(ROOT,Path(BACKUP).name)
        p.mkdir(ROOT,'wpsc-cache'); created_cache=True
        p.upload(CONTENT,'wp-cache-config.php.fp-recovery-stage',(OUT/'wp-cache-config.pre-enable.php').read_text()); staged=True
        p.api2('fileop',op='rename',sourcefiles=CONTENT+'/wp-cache-config.php',destfiles=BACKUP+'/wp-cache-config.php',doubledecode=0); moved=True
        p.rename(CONTENT,'wp-cache-config.php.fp-recovery-stage','wp-cache-config.php'); staged=False
        emit('files_staged_cache_off',backup=BACKUP,cache_path=ROOT+'/wpsc-cache',loader='stock WPSC; guard embedded in cache config')
        activated=True; dropin=True; a.activate()
        emit('plugin_active_cache_off')
        soup=a.soup('options-general.php?page=wpsupercache')
        forms=[]
        for f in soup.find_all('form'):
            fields=[]
            for x in f.select('input,button,select'):
                name=x.get('name','')
                if any(v in name.lower() for v in ['nonce','token','secret','password']): continue
                fields.append({'name':name,'type':x.get('type'), 'value':x.get('value') if x.get('type') in ['radio','checkbox','submit'] else None, 'checked':x.has_attr('checked')})
            forms.append({'method':f.get('method'),'fields':fields})
        emit('simple_forms',forms=forms)
        if p.read(CONTENT,'advanced-cache.php')!=Path('/tmp/fp-wpsc-source/advanced-cache.php').read_text(): raise RuntimeError('Not stock WPSC loader')
        ht_unchanged=hashlib.sha256(p.read(ROOT+'/public_html','.htaccess').encode()).hexdigest()==hashes['.htaccess']
        if not ht_unchanged: raise RuntimeError('Unexpected htaccess mutation')
        emit('staged_verified',plugin_active=True,cache_enabled=False,htaccess_unchanged=True,wpconfig_change='native WPSC activation defines WPCACHEHOME')
        success=True
    finally:
        if not success:
            # First remove serving, then use the normal deactivation lifecycle.
            if dropin and p.present(CONTENT,'advanced-cache.php'): p.trash(CONTENT+'/advanced-cache.php')
            if activated: a.deactivate()
            if moved:
                if p.present(CONTENT,'wp-cache-config.php'): p.trash(CONTENT+'/wp-cache-config.php')
                p.api2('fileop',op='rename',sourcefiles=BACKUP+'/wp-cache-config.php',destfiles=CONTENT+'/wp-cache-config.php',doubledecode=0)
            if staged and p.present(CONTENT,'wp-cache-config.php.fp-recovery-stage'): p.trash(CONTENT+'/wp-cache-config.php.fp-recovery-stage')
            if p.present(CONTENT,'advanced-cache.php.fp-recovery-stage'): p.trash(CONTENT+'/advanced-cache.php.fp-recovery-stage')
            if created_cache and p.present(ROOT,'wpsc-cache'): p.trash(ROOT+'/wpsc-cache')
            emit('rollback_verified',plugin_inactive=not a.active(),original_config_restored=p.read(CONTENT,'wp-cache-config.php')==original,dropin_absent=not p.present(CONTENT,'advanced-cache.php'))
if __name__=='__main__':
    if '--authorized-production-replay' not in sys.argv:
        print(json.dumps({'error':'Archived production mutator; do not replay routinely. Read ../README.md and obtain a new GO before --authorized-production-replay.'})); sys.exit(2)
    try: main()
    except Exception as e:
        import traceback
        print(json.dumps({'fatal':type(e).__name__,'stack':[{'file':Path(x.filename).name,'line':x.lineno} for x in traceback.extract_tb(e.__traceback__)]}),flush=True);sys.exit(1)
