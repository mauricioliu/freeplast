#!/usr/bin/env python3
"""Explicit one-shot production probe, authorized 2026-09-05. No background jobs.
Run with uv run --with requests python .../run.py --authorized-production-probe
The request capability exists only in this process; the remote plugin stores its hash.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import secrets
import signal
import subprocess
import sys
import time
import requests

ROOT = Path(__file__).resolve().parent
HOME = '/home/freeplast'
MUDIR = HOME + '/public_html/wp-content/mu-plugins'
OUT = ROOT / ('capture-' + dt.datetime.now(dt.timezone.utc).strftime('%H%M%S'))

def main():
    p = argparse.ArgumentParser()
    p.add_argument('--authorized-production-probe', action='store_true', required=True)
    p.parse_args()
    OUT.mkdir()
    def emit(kind, **data):
        row = {'at': dt.datetime.now(dt.timezone.utc).isoformat(), 'event': kind, **data}
        line = json.dumps(row)
        print(line, flush=True)
        with (OUT/'events.jsonl').open('a') as f: f.write(line+'\n')
    r = subprocess.run(['pass','show','freeplast/cpanel'], capture_output=True, text=True, check=True)
    creds = json.loads(r.stdout)
    del r
    session = requests.Session()
    session.headers['User-Agent'] = 'Mozilla/5.0'
    login = session.post('https://freeplast.cl:2083/login/?login_only=1',data={'user':creds['username'],'pass':creds['password']},timeout=40).json()
    del creds
    if not login.get('status'): raise RuntimeError('cPanel login failed')
    base = 'https://freeplast.cl:2083'+login['security_token']
    del login
    def api(module, func, **params):
        d = session.post(base+'/execute/'+module+'/'+func,data=params,timeout=60).json()
        if not d.get('status'): raise RuntimeError('UAPI failed: '+module+'/'+func)
        return d.get('data')
    def api2(func, **params):
        d = session.post(base+'/json-api/cpanel',data={'cpanel_jsonapi_apiversion':2,'cpanel_jsonapi_module':'Fileman','cpanel_jsonapi_func':func,**params},timeout=60).json()['cpanelresult']
        if not d.get('event',{}).get('result') or d.get('error') or any(x.get('result') == 0 for x in (d.get('data') or []) if isinstance(x,dict)):
            raise RuntimeError('API2 failed: Fileman/'+func)
        return d.get('data')
    def listing(directory):
        return api('Fileman','list_files',dir=directory,show_hidden=1,include_mime=0)
    def present(directory, filename):
        return any(f.get('file') == filename for f in listing(directory))
    def get_content(directory, filename):
        return api('Fileman','get_file_content',dir=directory,file=filename)['content']
    capability = secrets.token_hex(32)
    suffix = secrets.token_hex(5)
    filename = '000-fp-perf-'+suffix+'.php'
    stage = filename+'.stage'
    logname = '.fp-perf-'+suffix+'.jsonl'
    logpath = HOME+'/'+logname
    expires = int(time.time())+1200
    created_dir = not present(HOME+'/public_html/wp-content','mu-plugins')
    source = (ROOT/'probe.php.template').read_text().replace('__EXPIRES__',str(expires)).replace('__TOKEN_HASH__',hashlib.sha256(capability.encode()).hexdigest()).replace('__LOG_PATH__',logpath).replace('__REMOVE_DIR__','true' if created_dir else 'false')
    emit('planned', remote_plugin=MUDIR+'/'+filename, remote_log=logpath, expires_epoch=expires, source_sha256=hashlib.sha256(source.encode()).hexdigest(), created_dir=created_dir)
    staged = False
    active = False
    def snapshot():
        if present(HOME, logname):
            text = get_content(HOME,logname)
            # All serialized fields are selected in the PHP source. No SQL/URLs/cookies.
            rows = [json.loads(l) for l in text.splitlines() if l.strip()]
            (OUT/'server.jsonl').write_text(''.join(json.dumps(x)+'\n' for x in rows))
            emit('logs_downloaded', rows=len(rows))
            return rows
        return []
    def probe(probe_id, path, instrument=True, cleanup=False):
        # Secret header via stdin config, not command line, environment or output.
        cfg = 'url = "https://freeplast.cl'+path+'"\nheader = "User-Agent: Mozilla/5.0 FreeplastPerfProbe"\n'
        if instrument:
            cfg += 'header = "X-FP-Perf: '+capability+'"\nheader = "X-FP-Probe: '+probe_id+'"\n'
        if cleanup: cfg += 'header = "X-FP-Perf-Cleanup: 1"\n'
        result = subprocess.run(['curl','--config','-','--silent','--compressed','--max-time','60','--output',os.devnull,'--write-out','%{json}'],input=cfg,capture_output=True,text=True)
        try: raw = json.loads(result.stdout)
        except ValueError: raw = {}
        data = {k:raw.get(k) for k in ['http_code','time_namelookup','time_connect','time_appconnect','time_starttransfer','time_total','size_download']}
        emit('http',id=probe_id,path=path,instrumented=instrument,cleanup=cleanup,exit=result.returncode,**data)
        if result.returncode or raw.get('http_code') != 200: raise RuntimeError('probe HTTP failure: '+probe_id)
    def interrupted(signum, frame): raise RuntimeError('signal received; rolling back')
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    try:
        probe('before','/',instrument=False)
        if created_dir: api2('mkdir',path=HOME+'/public_html/wp-content',name='mu-plugins',permissions='0755')
        if present(MUDIR, filename) or present(MUDIR,stage) or present(HOME,logname): raise RuntimeError('remote collision')
        # Upload under a non-PHP suffix first; verify exact content, then atomic rename.
        staged = True
        uploaded = session.post(base+'/execute/Fileman/upload_files',data={'dir':MUDIR,'overwrite':0},files={'file-1':(stage,source.encode(),'application/octet-stream')},timeout=60).json()
        if not uploaded.get('status'): raise RuntimeError('upload failed')
        if get_content(MUDIR,stage) != source: raise RuntimeError('stage hash mismatch')
        active = True  # also covers a successful rename followed by a lost response
        api2('fileop',op='rename',sourcefiles=MUDIR+'/'+stage,destfiles=MUDIR+'/'+filename,doubledecode=0)
        staged = False
        if get_content(MUDIR,filename) != source: raise RuntimeError('active hash mismatch')
        emit('installed_verified')
        probe('guard-control','/',instrument=False)
        if snapshot(): raise RuntimeError('unauthorized request unexpectedly logged')
        for ident,path in [('warm-1','/'),('warm-2','/producto/totem/'),('warm-3','/')]:
            probe(ident,path); time.sleep(1)
        if len(snapshot()) != 3: raise RuntimeError('expected 3 captured probes')
        idle_probes = [] if os.environ.get('FP_PROBE_QUICK') == '1' else [(75,'idle75','/producto/totem/'),(150,'idle150','/')]
        for idle,ident,path in idle_probes:
            emit('idle_begin',seconds=idle)
            time.sleep(idle)
            probe(ident,path)
            time.sleep(1)
            probe(ident+'-warm',path)
            snapshot()
    finally:
        # Read evidence before deletion even when a probe fails; don't let that block cleanup.
        if active:
            try: snapshot()
            except Exception as e: emit('capture_error',error_class=type(e).__name__)
            try: probe('cleanup','/',cleanup=True)
            except Exception as e: emit('cleanup_http_error',error_class=type(e).__name__)
            mu_exists = present(HOME+'/public_html/wp-content','mu-plugins')
            still_active = mu_exists and present(MUDIR,filename)
            still_log = present(HOME,logname)
            if still_active:
                # Independent rollback, no PHP execution needed. Recoverable trash only for OUR file.
                api2('fileop',op='trash',sourcefiles=MUDIR+'/'+filename,doubledecode=0)
                emit('fallback_trash',file=MUDIR+'/'+filename)
            if still_log:
                api2('fileop',op='trash',sourcefiles=logpath,doubledecode=0)
                emit('fallback_trash',file=logpath)
            mu_exists = present(HOME+'/public_html/wp-content','mu-plugins')
            if (mu_exists and present(MUDIR,filename)) or present(HOME,logname): raise RuntimeError('ROLLBACK INCOMPLETE')
            emit('rollback_verified',plugin_absent=True,log_absent=True,mu_dir_absent=not mu_exists)
        if staged and present(MUDIR,stage):
            api2('fileop',op='trash',sourcefiles=MUDIR+'/'+stage,doubledecode=0)
            emit('stage_removed')
    probe('after','/',instrument=False)
    emit('completed',artifact=str(OUT))

if __name__ == '__main__':
    try: main()
    except Exception as e:
        # Never print requests exceptions: their URLs contain the cPanel session token.
        print(json.dumps({'fatal':type(e).__name__,'instruction':'Check events and verify explicit remote probe paths are absent.'}),flush=True)
        sys.exit(1)
