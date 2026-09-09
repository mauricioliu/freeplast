import {readFileSync,writeFileSync,mkdtempSync,mkdirSync,existsSync,rmSync} from 'node:fs';
import {tmpdir,homedir} from 'node:os';
import {join,isAbsolute} from 'node:path';
import {spawnSync} from 'node:child_process';
const skill=process.env.WP_RELEASE_SCRIPT_DIR || join(homedir(),'.agents/skills/wp-release/scripts');
const work=mkdtempSync(join(tmpdir(),'wp-release-safety-'));
const failures=[];
try {
  const mock=join(work,'mock-wp.sh');
  writeFileSync(mock,`#!/bin/bash\nset -eu\ncase "$*" in\n 'option get home') echo https://fixture.invalid;;\n 'maintenance-mode activate') touch '${work}/maintenance';;\n 'maintenance-mode deactivate') rm -f '${work}/maintenance';;\n theme\\ install*) exit 42;;\n *) exit 0;;\nesac\n`);
  const cfg={target:{stackDir:work,wp:`bash ${mock}`,wpRoot:work,backupDir:join(work,'backups'),homeUrl:'https://fixture.invalid',filesTar:'false'},install:{themes:[{slug:'fixture',artifact:'fixture.zip'}],plugins:[]}};
  const source=readFileSync(join(skill,'release.mjs'),'utf8');
  const fn=source.slice(source.indexOf('function rollbackScript('),source.indexOf('function bundlePrefixFor('));
  const q=s=>"'"+s.replaceAll("'", "'\\''")+"'";
  const render=new Function('q','bundlePrefixFor','isAbsolute','join',fn+'; return rollbackScript;')(q,()=>join(work,'bundle/new'),isAbsolute,join);
  const rollback=render(cfg,{id:'new'},{id:'old'});
  writeFileSync(join(work,'rollback.sh'),rollback);
  const rb=spawnSync('bash',[join(work,'rollback.sh'),'code-only'],{encoding:'utf8'});
  // Fake runner exits 42 on an install; an array-expansion error fails earlier.
  if(rb.status!==42) failures.push({check:'compound WP runner reaches mock install',status:rb.status,stderr:rb.stderr.trim()});
  if(rollback.includes('files restore command is host-specific')) failures.push({check:'paired rollback restores files instead of printing a manual placeholder',status:'unimplemented'});
  mkdirSync(join(work,'bundle'),{recursive:true});
  const run=spawnSync('bash',[join(skill,'release-chain.sh'),'install'],{encoding:'utf8',env:{...process.env,
    WP_RELEASE_PHASE:'install',WP_RELEASE_ID:'fixture',STACK_DIR:work,WP_ROOT:work,HOME_URL:'https://fixture.invalid',BUNDLE_HOST_DIR:join(work,'bundle'),BUNDLE_PATH:join(work,'bundle'),BACKUP_DIR:join(work,'backups'),WP_RUN:`bash ${mock}`,FILES_TAR:'false',REHEARSAL_CHECK:'echo 1;',REHEARSAL_PROJECT:'unused',INSTALL_THEMES:'fixture=fixture.zip',INSTALL_PLUGINS:'',EXPECTED_VERSIONS:'',VERIFY_HOOK:'',FINGERPRINT_HOOK:'',WP_RELEASE_AUTHORIZED:'fixture-only'
  }});
  if(run.status!==42 || !existsSync(join(work,'maintenance'))) failures.push({check:'failed installation retains maintenance',status:run.status,maintenancePresent:existsSync(join(work,'maintenance')),stderr:run.stderr.trim()});
  console.log(JSON.stringify({scope:'local fake WP runner; no Docker/network/site',failures},null,2));
  process.exitCode=failures.length?1:0;
} finally {rmSync(work,{recursive:true,force:true});}
