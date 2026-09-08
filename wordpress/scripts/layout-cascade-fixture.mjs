#!/usr/bin/env node
/** Build inert file:// replays of observed native markup + CURRENT theme CSS.
 * No server, DB, browser launch or external mutation. See fixtures/visual-cascade/README.md.
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {spawnSync} from 'node:child_process';
import {JSDOM} from 'jsdom';
const root=fileURLToPath(new URL('..',import.meta.url));
const out=fs.mkdtempSync(path.join(os.tmpdir(),'freeplast-layout-'));
const php=process.env.PHP_BINARY||path.join(root,'.tools/php/php');
const rendered=spawnSync(php,[path.join(root,'scripts/catalog-native-frame-test.php')],{encoding:'utf8',env:{...process.env,FREEPLAST_TEST_CATALOG_HTML:'1'}});
if(rendered.status!==0)throw Error(rendered.stderr||rendered.stdout);
const intro=new JSDOM(rendered.stdout).window.document.querySelector('.fp-catalog');
const paths=[];
for(const name of ['catalog','product','cart','checkout']){
 const fixture=JSON.parse(fs.readFileSync(path.join(root,'scripts/fixtures/visual-cascade',name+'.json'),'utf8'));
 const doc=new JSDOM(fixture.html).window.document;
 if(name==='catalog'){
  const main=doc.querySelector('main'), grid=main.querySelector('ul.products');
  main.querySelector('.woocommerce-breadcrumb')?.remove();
  main.querySelector('.woocommerce-products-header')?.remove();
  const shell=doc.createElement('div');shell.className='fp-shell fp-catalog';
  shell.innerHTML=intro.querySelector('.catalog-intro').outerHTML+intro.querySelector('.catalog-tools').outerHTML;
  while(main.firstChild)shell.append(main.firstChild);main.append(shell);
  if(!grid)throw Error('Captured native grid missing');
 }
 if(name==='checkout'){
  // Only the deleted, theme-owned duplicate is removed; native #payment is
  // replayed intact. The PHP test separately exercises native terms output.
  const source=fs.readFileSync(path.join(root,'wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php'),'utf8');
  if(!source.includes('class="fp-privacy-copy"'))doc.querySelector('.fp-privacy-copy')?.remove();
 }
 for(const e of doc.querySelectorAll('form'))e.setAttribute('onsubmit','return false');
 for(const e of doc.querySelectorAll('img')){e.src=new URL(e.getAttribute('src'),'https://freeplast.mliu.site/').href;e.removeAttribute('srcset');}
 let css=fixture.inline.map(s=>`<style>${s}</style>`).join('\n');
 for(const asset of fixture.assets){
  const local=path.join(root,asset.href.startsWith('/wp-content/themes/freeplast/')?'':'.build/wp',asset.href);
  if(!fs.existsSync(local))throw Error('Missing pinned stylesheet '+local);
  let raw=fs.readFileSync(local,'utf8');
  if(process.env.FREEPLAST_LAYOUT_BASELINE&&asset.href.startsWith('/wp-content/themes/freeplast/')){
   const old=spawnSync('git',['show',`${process.env.FREEPLAST_LAYOUT_BASELINE}:wordpress${asset.href}`],{cwd:root,encoding:'utf8'});
   if(old.status!==0)throw Error(old.stderr);raw=old.stdout;
  }
  let text=raw.replace(/url\((['"]?)([^)'"\s]+)\1\)/g,(all,q,url)=>url.startsWith('data:')?all:`url("${new URL(url,pathToFileURL(local)).href}")`);
  css+=`<style data-source="${asset.href}"${asset.media?` media="${asset.media}"`:''}>${text}</style>`;
 }
 // Explicit adversarial order: Woo's cart.css may arrive after hydration.
 if(name==='cart')css+=`<style>${fs.readFileSync(path.join(root,'.build/wp/wp-content/plugins/woocommerce/assets/client/blocks/cart.css'),'utf8')}</style>`;
 const html=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src https://freeplast.mliu.site data:; font-src file: data:; connect-src 'none'; form-action 'none'; base-uri 'none'"><title>Layout replay — ${name}</title>${css}</head><body class="${fixture.bodyClass}">${doc.body.innerHTML}</body></html>`;
 const file=path.join(out,name+'.html');fs.writeFileSync(file,html);paths.push({name,path:file,url:pathToFileURL(file).href});
}
fs.writeFileSync(path.join(out,'manifest.json'),JSON.stringify(paths,null,2));
console.log(JSON.stringify({out,pages:paths},null,2));
