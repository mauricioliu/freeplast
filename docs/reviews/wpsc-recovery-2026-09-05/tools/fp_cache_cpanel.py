"""cPanel file transport. Credentials/session URLs remain in memory; never log exceptions."""
import json, subprocess
import requests
class Panel:
    def __init__(self):
        r=subprocess.run(['pass','show','freeplast/cpanel'],capture_output=True,text=True,check=True)
        creds=json.loads(r.stdout); del r
        self.session=requests.Session()
        login=self.session.post('https://freeplast.cl:2083/login/?login_only=1',data={'user':creds['username'],'pass':creds['password']},timeout=40).json(); del creds
        if not login.get('status'): raise RuntimeError('login failed')
        self.base='https://freeplast.cl:2083'+login['security_token']
    def api(self,mod,fn,**kw):
        d=self.session.post(self.base+'/execute/'+mod+'/'+fn,data=kw,timeout=60).json()
        if not d.get('status'): raise RuntimeError('API failed '+mod+'/'+fn)
        return d.get('data')
    def api2(self,fn,**kw):
        d=self.session.post(self.base+'/json-api/cpanel',data={'cpanel_jsonapi_apiversion':2,'cpanel_jsonapi_module':'Fileman','cpanel_jsonapi_func':fn,**kw},timeout=60).json()['cpanelresult']
        if not d.get('event',{}).get('result') or d.get('error') or any(x.get('result')==0 for x in (d.get('data') or []) if isinstance(x,dict)): raise RuntimeError('Fileman API2 failed '+fn)
        return d.get('data')
    def present(self,path,name): return any(x.get('file')==name for x in self.api('Fileman','list_files',dir=path,show_hidden=1,include_mime=0))
    def read(self,path,name): return self.api('Fileman','get_file_content',dir=path,file=name)['content']
    def upload(self,path,name,text):
        d=self.session.post(self.base+'/execute/Fileman/upload_files',data={'dir':path,'overwrite':0},files={'file-1':(name,text.encode(),'application/octet-stream')},timeout=60).json()
        if not d.get('status'): raise RuntimeError('Upload failed')
        if self.read(path,name)!=text: raise RuntimeError('Upload verification failed')
    def mkdir(self,path,name): self.api2('mkdir',path=path,name=name,permissions='0700')
    def rename(self,path,name,newname): self.api2('fileop',op='rename',sourcefiles=path+'/'+name,destfiles=path+'/'+newname,doubledecode=0)
    def trash(self,path): self.api2('fileop',op='trash',sourcefiles=path,doubledecode=0)
