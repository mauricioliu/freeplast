import { fork } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { SafetyError } from './policy.mjs';
import { failureEvidence } from './failure.mjs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export async function runWorker(config, lockFd, { signal, timeout = 120000, worker = new URL('./audit-worker.mjs', import.meta.url) } = {}) {
  const profileDir = await mkdtemp(join(tmpdir(), 'freeplast-lh-audit-'));
  let rejected;
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
    const killGroup = () => { try { process.kill(-child.pid, 'SIGKILL'); return true; } catch (e) { if (e.code !== 'ESRCH') failure ||= new SafetyError('cleanup-failed'); return false; } };
    const abort = () => stop('interrupted');
    const timer = setTimeout(() => stop('audit-timeout'), timeout);
    signal?.addEventListener('abort', abort, { once: true });
    child.on('message', message => { result = message; });
    child.once('error', () => { failure = new SafetyError('worker-start'); });
    child.once('exit', () => {
      // Group belongs to this freshly spawned worker, including its non-detached browser.
      const groupKillSent = killGroup();
      let processGroupAbsent = false;
      try { process.kill(-child.pid, 0); } catch (e) { processGroupAbsent = e.code === 'ESRCH'; }
      clearTimeout(timer); clearTimeout(killer); signal?.removeEventListener('abort', abort);
      if (failure || !result?.ok) {
        rejected = failure || new SafetyError(result?.error || 'worker-crash');
        rejected.diagnostic = result?.diagnostic;
        rejected.evidence = failureEvidence({ ...result?.evidence, worker: { exitObserved: true, processGroupAbsent, groupKillSent } });
        reject(rejected);
      } else resolve(result.result);
    });
    if (signal?.aborted) stop('interrupted');
    else child.send({ ...config, profileDir });
  }); } finally {
    try { await rm(profileDir, { recursive: true, force: true }); if (rejected) rejected.evidence.worker.profileRemoved = true; }
    catch (error) { if (!rejected) throw error; rejected.evidence.worker.profileRemoved = false; }
  }
}
