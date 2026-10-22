import { fork } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { SafetyError } from './policy.mjs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export async function runWorker(config, lockFd, { signal, timeout = 120000, worker = new URL('./audit-worker.mjs', import.meta.url) } = {}) {
  const profileDir = await mkdtemp(join(tmpdir(), 'freeplast-lh-audit-'));
  try { return await new Promise((resolve, reject) => {
    const child = fork(fileURLToPath(worker), [], {
      detached: true, execArgv: [], stdio: ['ignore', 'ignore', 'ignore', lockFd, 'ipc'],
      env: { PATH: '/usr/bin:/bin', HOME: process.env.HOME, LANG: 'es_CL.UTF-8' },
    });
    let result, failure, killer;
    const stop = code => {
      failure ||= new SafetyError(code);
      child.kill('SIGTERM');
      killer ||= setTimeout(() => killGroup(), 3000);
    };
    const killGroup = () => { try { process.kill(-child.pid, 'SIGKILL'); } catch (e) { if (e.code !== 'ESRCH') failure ||= new SafetyError('cleanup-failed'); } };
    const abort = () => stop('interrupted');
    const timer = setTimeout(() => stop('audit-timeout'), timeout);
    signal?.addEventListener('abort', abort, { once: true });
    child.on('message', message => { result = message; });
    child.once('error', () => { failure = new SafetyError('worker-start'); });
    child.once('exit', () => {
      // Group belongs to this freshly spawned worker, including its non-detached browser.
      killGroup();
      clearTimeout(timer); clearTimeout(killer); signal?.removeEventListener('abort', abort);
      if (failure) reject(failure);
      else if (!result?.ok) { const error = new SafetyError(result?.error || 'worker-crash'); error.diagnostic = result?.diagnostic; reject(error); }
      else resolve(result.result);
    });
    if (signal?.aborted) stop('interrupted');
    else child.send({ ...config, profileDir });
  }); } finally { await rm(profileDir, { recursive: true, force: true }); }
}
