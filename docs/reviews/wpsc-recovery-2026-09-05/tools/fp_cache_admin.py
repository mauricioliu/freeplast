import json,subprocess
from urllib.parse import urljoin
import requests
from bs4 import BeautifulSoup
class Admin:
    def __init__(self):
        r=subprocess.run(['pass','show','freeplast/wp-admin'],capture_output=True,text=True,check=True)
        creds=json.loads(r.stdout); del r
        self.s=requests.Session(); self.s.headers['User-Agent']='Mozilla/5.0 FreeplastCacheRecovery'
        self.s.get('https://freeplast.cl/wp-login.php',timeout=40)
        self.s.post('https://freeplast.cl/wp-login.php',data={'log':creds['username'],'pwd':creds['password'],'wp-submit':'Acceder','redirect_to':'https://freeplast.cl/wp-admin/','testcookie':'1'},timeout=60); del creds
        if not any(k.startswith('wordpress_logged_in_') for k in self.s.cookies.keys()): raise RuntimeError('WP login failed')
    def soup(self,path):
        r=self.s.get(urljoin('https://freeplast.cl/wp-admin/',path),timeout=60)
        if r.status_code!=200: raise RuntimeError('WP admin GET failed')
        return BeautifulSoup(r.text,'html.parser')
    def row(self):
        row=self.soup('plugins.php').select_one('tr[data-plugin="wp-super-cache/wp-cache.php"]')
        if not row: raise RuntimeError('WPSC plugin row absent')
        return row
    def active(self): return 'active' in self.row().get('class',[])
    def activate(self):
        row=self.row()
        if 'active' in row.get('class',[]): raise RuntimeError('Already active; refuse unexpected state')
        link=row.select_one('.activate a')
        if not link: raise RuntimeError('Activate link absent')
        r=self.s.get(urljoin('https://freeplast.cl/wp-admin/',link['href']),timeout=60)
        if not self.active(): raise RuntimeError('Activation not confirmed')
    def deactivate(self):
        row=self.row()
        if 'active' not in row.get('class',[]): return
        link=row.select_one('.deactivate a')
        if not link: raise RuntimeError('Deactivate link absent')
        self.s.get(urljoin('https://freeplast.cl/wp-admin/',link['href']),timeout=60)
        if self.active(): raise RuntimeError('Deactivation not confirmed')
