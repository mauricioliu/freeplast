import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runWorker } from '../src/lifecycle.mjs';
import { lock } from '../src/store.mjs';
import { spawn } from 'node:child_process';
import { once } from 'node:events';

const worker = new URL('./worker-fixture.mjs', import.meta.url);
async function stopped(pid) {
  for (let i = 0; i < 50; i++) {
    try { const status = await readFile(`/proc/${pid}/stat`, 'utf8'); if (status.split(') ')[1].startsWith('Z')) return true; }
    catch (e) { if (e.code === 'ENOENT') return true; throw e; }
    await new Promise(resolve => setTimeout(resolve, 20));
  }
  return false;
}
test('subprocess completion, missing result, timeout, interruption and crash clean owned process group', async () => {
  const root = await mkdtemp(join(tmpdir(), 'lh-lifecycle-'));
  const fd = await lock(root);
  try {
    assert.deepEqual(await runWorker({ mode: 'complete' }, fd.fd, { worker }), { synthetic: true });
    await assert.rejects(runWorker({ mode: 'empty' }, fd.fd, { worker }), /worker-crash/);
    for (const mode of ['crash', 'timeout', 'interrupt']) {
      const pidFile = join(root, mode + '.pid');
      const controller = new AbortController();
      let timer;
      if (mode === 'interrupt') timer = setTimeout(() => controller.abort(), 250);
      await assert.rejects(runWorker({ mode, pidFile }, fd.fd, { worker, timeout: mode === 'timeout' ? 250 : 5000, signal: controller.signal }), mode === 'crash' ? /worker-crash/ : mode === 'timeout' ? /audit-timeout/ : /interrupted/);
      clearTimeout(timer);
      const pid = Number(await readFile(pidFile, 'utf8'));
      assert.equal(await stopped(pid), true, `${mode}: owned child survived`);
    }
  } finally { await fd.close(); await rm(root, { recursive: true, force: true }); }
});

test('inherited kernel descriptor retains exclusion after supervisor SIGKILL until worker cleanup', async () => {
  const root = await mkdtemp(join(tmpdir(), 'lh-parent-crash-'));
  const pidFile = join(root, 'child.pid');
  const store = new URL('../src/store.mjs', import.meta.url).href;
  const lifecycle = new URL('../src/lifecycle.mjs', import.meta.url).href;
  const code = `import {lock} from ${JSON.stringify(store)}; import {runWorker} from ${JSON.stringify(lifecycle)}; import {access} from 'node:fs/promises'; const fd=await lock(${JSON.stringify(root)}); const run=runWorker({mode:'disconnect',pidFile:${JSON.stringify(pidFile)}},fd.fd,{worker:new URL(${JSON.stringify(worker.href)})}); for(;;){try{await access(${JSON.stringify(pidFile)});break}catch{await new Promise(r=>setTimeout(r,10))}} console.log('ready'); await run; await fd.close();`;
  const parent = spawn(process.execPath, ['--input-type=module', '-e', code], { stdio: ['ignore', 'pipe', 'ignore'] });
  try {
    await once(parent.stdout, 'data', { signal: AbortSignal.timeout(5000) });
    parent.kill('SIGKILL'); await once(parent, 'exit');
    await assert.rejects(lock(root), /busy/);
    await new Promise(resolve => setTimeout(resolve, 700));
    const fd = await lock(root); await fd.close();
    assert.equal(await stopped(Number(await readFile(pidFile, 'utf8'))), true);
  } finally { parent.kill('SIGKILL'); await rm(root, { recursive: true, force: true }); }
});
