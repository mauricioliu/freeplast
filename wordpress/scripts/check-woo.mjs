#!/usr/bin/env node
// Offline checks only. Never starts a server or sends a quote request.
import {readdirSync,readFileSync,existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root=fileURLToPath(new URL('..',import.meta.url));
const php=process.env.PHP_BINARY || path.join(root,'.tools/php/php');
if(!existsSync(php)) throw Error('PHP missing. Set PHP_BINARY to your PHP executable.');
function walk(dir){return readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]);}
function run(bin,args){const result=spawnSync(bin,args,{encoding:'utf8'});if(result.status!==0)throw Error(result.stderr||result.stdout);return result.stdout.trim();}
const files=[...walk(path.join(root,'wp-content/plugins/freeplast-woo')),...walk(path.join(root,'wp-content/themes/freeplast')),...walk(path.join(root,'wp-content/mu-plugins')),...['migrate-to-woo.php','verify-woo-state.php'].map(p=>path.join(root,'scripts',p))];
let checks=0;
for(const file of files){
 if(file.endsWith('.php')) {run(php,['-l',file]);checks++;}
 if(file.endsWith('.js')) {run(process.execPath,['--check',file]);checks++;}
 if(file.endsWith('.json')) {JSON.parse(readFileSync(file,'utf8'));checks++;}
 if(file.endsWith('.html') && /wp:freeplast\//.test(readFileSync(file,'utf8'))) throw Error('Legacy block in active theme: '+file);
}
const deps=JSON.parse(readFileSync(path.join(root,'woo-dependencies.json'),'utf8'));
for(const dep of Object.values(deps)){if(!/^[a-f0-9]{64}$/.test(dep.sha256)||!dep.url.startsWith('https://downloads.wordpress.org/plugin/'))throw Error('Unpinned dependency');checks++;}
console.log(run(php,[path.join(root,'scripts/test-woo-adapter.php')]));
run('bash',['-n',path.join(root,'infra/deploy-woo.sh')]);
console.log(`checks: ${checks+1} syntax, dependency and deployment checks passed`);
console.log('scope: offline only; no Woo runtime or visual approval implied');
