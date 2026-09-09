#!/usr/bin/env node
// A local capability/config guard, not an authorization or safety-test bypass.
// The driver independently requires its full source-matched safety receipt.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {homedir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../..',import.meta.url));
const cfg=JSON.parse(readFileSync(join(root,'wp-release.json')));
assert.deepEqual(cfg.migration,{source:'wordpress/.build/woo-release/photo-migration',hook:'run.php'});
assert.equal(cfg.hooks.recordFingerprint,'wordpress/scripts/record-state.php');
const driver=join(homedir(),'.agents/skills/wp-release/scripts/release.mjs');
const probe=spawnSync(process.execPath,[driver,'--config',join(root,'wp-release.json'),'plan'],{encoding:'utf8'});
assert.equal(probe.status,0,probe.stderr);
assert.match(probe.stdout,/sealed migration configured: DATA required/);
assert.match(probe.stdout,/proposed tier: DATA/);
console.log('photo_release_ready: sealed DATA migration configured; driver capability confirmed; full ceremony and authorization still required');
