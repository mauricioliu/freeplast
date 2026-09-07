#!/usr/bin/env python3
"""Temporary synthetic GET-only cache-key test; never loads or changes WordPress."""
import datetime as dt, hashlib, json, re, secrets, signal, subprocess, sys, time
from pathlib import Path
import requests
OUT=Path('/home/mauricio-liu/Projects/freeplast/docs/reviews/wpsc-recovery-2026-09-05')

def main():
    OUT.mkdir(exist_ok=True)
    def emit(event,**data):
        line=json.dumps({'at':dt.datetime.now(dt.timezone.utc).isoformat(),'event':event,**data})
        print(line,flush=True)
        with (OUT/'synthetic.jsonl').open('a') as f: f.write(line+'\n')
    r=subprocess.run(['pass','show','freeplast/cpanel'],capture_output=True,text=True,check=True)
    creds=json.loads(r.stdout); del r
    s=requests.Session()
    login=s.post('https://freeplast.cl:2083/login/?login_only=1',data={'user':creds['username'],'pass':creds['password']},timeout=40).json(); del creds
    if not login.get('status'): raise RuntimeError('login failed')
    base='https://freeplast.cl:2083'+login['security_token']; del login
    def api(mod,fn,**kw):
        d=s.post(base+'/execute/'+mod+'/'+fn,data=kw,timeout=60).json()
        if not d.get('status'): raise RuntimeError('API failed '+mod+'/'+fn)
        return d.get('data')
    def api2(fn,**kw):
        d=s.post(base+'/json-api/cpanel',data={'cpanel_jsonapi_apiversion':2,'cpanel_jsonapi_module':'Fileman','cpanel_jsonapi_func':fn,**kw},timeout=60).json()['cpanelresult']
        if not d.get('event',{}).get('result') or d.get('error') or any(x.get('result')==0 for x in (d.get('data') or []) if isinstance(x,dict)): raise RuntimeError('Fileman API2 failed '+fn)
        return d.get('data')
    def listing(path): return api('Fileman','list_files',dir=path,show_hidden=1,include_mime=0)
    def present(path,name): return any(x.get('file')==name for x in listing(path))
    def upload(path,name,text):
        d=s.post(base+'/execute/Fileman/upload_files',data={'dir':path,'overwrite':0},files={'file-1':(name,text.encode(),'application/octet-stream')},timeout=60).json()
        if not d.get('status'): raise RuntimeError('Upload failed')
        if api('Fileman','get_file_content',dir=path,file=name)['content']!=text: raise RuntimeError('Upload verification failed')
    name='fp-cache-lab-'+secrets.token_hex(6); root='/home/freeplast/public_html'; remote=root+'/'+name
    expires=int(time.time())+900
    # Cacheability is the only intended variable; all routes rewrite to the same index.php.
    php='''<?php
if (time() > __EXP__) { header('Cache-Control: no-store'); http_response_code(410); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Cache-Control: no-store'); http_response_code(405); exit; }
$uri = $_SERVER['REQUEST_URI'];
if (!preg_match('~/fp-cache-lab-[a-f0-9]+/(nostore|short|private)/(alpha|beta)/$~D', $uri, $m)) { header('Cache-Control: no-store'); http_response_code(404); exit; }
$policies = array('nostore'=>'no-store, private', 'short'=>'max-age=3, must-revalidate', 'private'=>'private, max-age=3, must-revalidate');
header('Content-Type: application/json');
header('Cache-Control: '.$policies[$m[1]]);
echo json_encode(array('policy'=>$m[1], 'page'=>$m[2], 'generated'=>microtime(true)));
'''.replace('__EXP__',str(expires))
    ht='Options -Indexes\nRewriteEngine On\nRewriteRule ^index\\.php$ - [L]\nRewriteRule ^(?:nostore|short|private)/(?:alpha|beta)/$ index.php [L]\n'
    created=False
    def interrupted(signum,frame): raise RuntimeError('Interrupted')
    signal.signal(signal.SIGINT,interrupted); signal.signal(signal.SIGTERM,interrupted)
    try:
        if present(root,name): raise RuntimeError('Collision')
        created=True
        api2('mkdir',path=root,name=name,permissions='0755')
        upload(remote,'.htaccess',ht); upload(remote,'index.php',php)
        emit('installed',remote=remote,expires=expires,php_sha256=hashlib.sha256(php.encode()).hexdigest())
        for policy in ['nostore','short','private']:
            # wait beyond the preceding test's advertised max-age
            time.sleep(4)
            for page in ['alpha','beta','alpha','beta']:
                url='https://freeplast.cl/'+name+'/'+policy+'/'+page+'/'
                start=time.monotonic()
                response=requests.get(url,headers={'User-Agent':'Mozilla/5.0 FreeplastCacheIsolation'},timeout=40)
                try: body=response.json()
                except ValueError: body={'not_json':True}
                headers={k:response.raw.headers.getlist(k) for k in ['Cache-Control','Age','Vary','Server','X-Cache','X-Cache-Status','Expires'] if k in response.headers}
                emit('get',policy=policy,page=page,status=response.status_code,elapsed=round(time.monotonic()-start,3),body=body,headers=headers,correct=body.get('page')==page and body.get('policy')==policy)
                time.sleep(1.6)
    finally:
        if created and present(root,name):
            api2('fileop',op='trash',sourcefiles=remote,doubledecode=0)
        if present(root,name): raise RuntimeError('Cleanup incomplete')
        emit('cleanup_verified',remote_absent=True,disposition='own synthetic directory moved to cPanel trash outside docroot')
if __name__=='__main__':
    if '--authorized-production-replay' not in sys.argv:
        print(json.dumps({'error':'Archived production mutator; do not replay routinely. Read ../README.md and obtain a new GO before --authorized-production-replay.'})); sys.exit(2)
    try: main()
    except Exception as e: print(json.dumps({'fatal':type(e).__name__}),flush=True); sys.exit(1)
