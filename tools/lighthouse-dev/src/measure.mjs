import { readFile, mkdir, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { setTimeout as sleep } from 'node:timers/promises';
import { origin, routes, policy, checkRequest, resolveLocal, resourceHosts, deny, SafetyError } from './policy.mjs';
import { preflight, request, sha256 } from './preflight.mjs';
import { stateRoot, privateDirectory, capacity, save, manifest, readPrivate, toolchain, lock } from './store.mjs';
import { fingerprint, hardware, load, checkout } from './fingerprint.mjs';
import { profiles } from './config.mjs';
import { runWorker } from './lifecycle.mjs';

export const schedule = () => [1, 2, 3].flatMap(round => ['mobile', 'desktop'].flatMap(profile => routes.map(path => ({ round, profile, path }))));
export async function instrumentHash() {
  const base = fileURLToPath(new URL('../', import.meta.url));
  const paths = ['package.json', 'package-lock.json', 'toolchain.json', 'bin/freeplast-lh.mjs', ...(await readdir(join(base, 'src'))).sort().filter(x => x.endsWith('.mjs')).map(x => 'src/' + x)];
  return sha256(JSON.stringify(await Promise.all(paths.map(async path => [path, sha256(await readFile(join(base, path)))]))));
}

async function identity(root, source) {
  let receipt;
  try { receipt = await readPrivate(join(root, 'identity.json')); } catch { deny('identity-receipt-required'); }
  if (receipt.schema !== 1 || receipt.origin !== origin || receipt.checkout !== checkout || receipt.sourceHash !== source.sourceHash || !Number.isFinite(Date.parse(receipt.verifiedAt)) || Date.now() - Date.parse(receipt.verifiedAt) > 86400000 || Date.parse(receipt.verifiedAt) > Date.now()) deny('identity-receipt-stale');
  for (const key of ['operator', 'runtimeId', 'wordpress']) if (typeof receipt[key] !== 'string' || !/^[\w.@-]{1,100}$/.test(receipt[key])) deny('identity-receipt-invalid');
  if (!receipt.plugins || typeof receipt.plugins !== 'object' || Array.isArray(receipt.plugins) || Object.entries(receipt.plugins).some(([k, v]) => !/^[\w-]{1,100}$/.test(k) || typeof v !== 'string' || !/^[\w.-]{1,50}$/.test(v))) deny('identity-receipt-invalid');
  return { schema: 1, origin, checkout, sourceHash: source.sourceHash, verifiedAt: receipt.verifiedAt, operator: receipt.operator, runtimeId: receipt.runtimeId, wordpress: receipt.wordpress, plugins: receipt.plugins };
}

export async function inspect({ root = stateRoot, signal } = {}) {
  await privateDirectory(root);
  const pins = await toolchain(), instrument = await instrumentHash();
  let gate;
  try { gate = await readPrivate(join(root, 'security-gate.json')); } catch { deny('security-gate-required'); }
  if (gate.instrument !== instrument || gate.status !== 'passed' || gate.deniedRequests !== 0) deny('security-gate-stale');
  const source = await fingerprint();
  const receipt = await identity(root, source);
  const addresses = await resolveLocal();
  const address = addresses[0];
  const p = policy();
  const pages = [];
  for (const path of routes) {
    const result = await preflight(p, path, address, signal);
    const hosts = await resourceHosts(result.html, p);
    pages.push({ path, ...result, html: undefined, hosts });
  }
  const assetUrl = origin + '/wp-content/themes/freeplast/style.css';
  checkRequest(p, assetUrl, 'GET', 'Stylesheet');
  const asset = await request(assetUrl, address, signal);
  if (asset.status !== 200 || sha256(asset.body) !== sha256(await readFile(join(checkout, 'wordpress/wp-content/themes/freeplast/style.css')))) deny('served-source-mismatch');
  const after = await fingerprint();
  if (JSON.stringify(after) !== JSON.stringify(source)) deny('source-drift');
  return { pins, instrument, configHash: sha256(JSON.stringify(profiles)), policyVersion: 1, sanitizerVersion: 2, origin, routes, addresses, identity: receipt, source, pages, styleHash: sha256(asset.body), hardware: hardware(), storage: await capacity(root, 32 * 1024 ** 2), load: load() };
}

export async function doctor() {
  const fd = await lock();
  try {
    const evidence = await inspect({ signal: AbortSignal.timeout(60000) });
    const dir = join(stateRoot, 'doctor-' + randomUUID());
    await mkdir(dir, { mode: 0o700 });
    await save(stateRoot, join(dir, 'doctor.json'), evidence);
    return { status: 'passed', evidence: dir, identity: 'operator receipt plus public LOCAL/noindex and matching theme asset; not DB attestation' };
  } finally { await fd.close(); }
}

// Injectable operations are internal test seams; no CLI URL/config/worker override exists.
export async function collect({ root, fd, label, inspectRun, auditRun, currentSource, currentAddresses, preflightRun, pause = sleep, signal, now = Date.now }) {
  const started = now(), deadline = started + 45 * 60 * 1000;
  const id = new Date(started).toISOString().replaceAll(/[:.]/g, '-') + '-' + randomUUID();
  const dir = join(root, id);
  await mkdir(dir, { mode: 0o700 });
  const run = { schema: 1, id, label, status: 'incomplete', startedAt: new Date(started).toISOString(), deadline: new Date(deadline).toISOString(), schedule: schedule(), audits: [], artifacts: [], cache: 'browser-cold-new-profile; server-unchanged', retries: 0, loadBefore: load() };
  await manifest(root, dir, run);
  try {
    run.environment = await inspectRun();
    await manifest(root, dir, run);
    for (const item of run.schedule) {
      if (signal?.aborted) deny('interrupted');
      if (now() + 120000 > deadline) deny('series-deadline');
      await capacity(root, 32 * 1024 ** 2);
      if (JSON.stringify(await currentSource()) !== JSON.stringify(run.environment.source)) deny('source-drift');
      if (JSON.stringify(await currentAddresses()) !== JSON.stringify(run.environment.addresses)) deny('dns-drift');
      const auditStarted = now();
      const auditSignal = signal ? AbortSignal.any([signal, AbortSignal.timeout(117000)]) : AbortSignal.timeout(117000);
      const before = await preflightRun(item, run.environment, auditSignal);
      const original = run.environment.pages.find(x => x.path === item.path);
      if (before.contentHash !== original.contentHash || JSON.stringify(before.headers) !== JSON.stringify(original.headers)) deny('public-content-drift');
      const row = { ...item, status: 'incomplete', startedAt: new Date(now()).toISOString(), requestedUrl: origin + item.path, preflight: { ...before, html: undefined }, loadBefore: load() };
      run.audits.push(row);
      await manifest(root, dir, run);
      const remaining = 117000 - (now() - auditStarted);
      if (remaining <= 0) deny('audit-timeout');
      const result = await auditRun(item, run.environment, fd, { signal: auditSignal, timeout: remaining });
      if (!result?.lhr || typeof result.html !== 'string' || !result.html.includes('<html') || !result.summary || !result.security?.active) deny('missing-report');
      const stem = `${String(run.audits.length).padStart(2, '0')}-${item.profile}`;
      run.artifacts.push(await save(root, join(dir, `${stem}.json`), result.lhr));
      run.artifacts.push(await save(root, join(dir, `${stem}.html`), result.html));
      row.finalUrl = result.lhr.finalDisplayedUrl;
      row.summary = result.summary;
      row.settings = result.settings;
      row.security = result.security;
      row.launch = result.launch;
      row.loadAfter = load();
      row.finishedAt = new Date(now()).toISOString();
      row.status = 'complete';
      await manifest(root, dir, run);
      if (run.audits.length < 18) await pause(5000, undefined, { signal });
    }
    run.sourceAfter = await currentSource();
    if (JSON.stringify(run.sourceAfter) !== JSON.stringify(run.environment.source)) deny('source-drift');
    if (JSON.stringify(await currentAddresses()) !== JSON.stringify(run.environment.addresses)) deny('dns-drift');
    run.status = 'complete';
  } catch (error) {
    run.failure = error instanceof SafetyError ? error.code : signal?.aborted ? 'interrupted' : 'collection-failed';
  } finally {
    run.finishedAt = new Date(now()).toISOString();
    run.loadAfter = load();
    run.artifacts.push(await save(root, join(dir, 'summary.json'), { id, status: run.status, failure: run.failure, audits: run.audits.map(({ path, profile, round, status, summary }) => ({ path, profile, round, status, summary })) }));
    run.artifacts.push(await save(root, join(dir, 'summary.md'), `# ${id}\n\nStatus: ${run.status}\nCompleted: ${run.audits.filter(x => x.status === 'complete').length}/18\nFailure: ${run.failure || 'none'}\n\nLaboratory only. TBT is not INP. noindex preserved. No improvement claim.\n`));
    run.artifacts.push(await save(root, join(dir, 'events.json'), { startedAt: run.startedAt, finishedAt: run.finishedAt, status: run.status, reason: run.failure || null }));
    await manifest(root, dir, run);
  }
  return { status: run.status, id, evidence: dir, ...(run.failure ? { error: run.failure } : {}) };
}

export async function measure(label) {
  const fd = await lock();
  const controller = new AbortController();
  const stop = () => controller.abort();
  process.once('SIGINT', stop); process.once('SIGTERM', stop);
  try {
    return await collect({
      root: stateRoot, fd, label, signal: controller.signal,
      inspectRun: () => inspect({ signal: controller.signal }), currentSource: fingerprint, currentAddresses: resolveLocal,
      preflightRun: (item, env, signal) => preflight(policy(), item.path, env.addresses[0], signal),
      auditRun: (item, env, _fd, budget) => runWorker({ policy: policy({ hosts: env.pages.find(x => x.path === item.path).hosts }), address: env.addresses[0], pins: env.pins, url: origin + item.path, profile: item.profile }, fd.fd, budget),
    });
  } finally {
    process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop);
    await fd.close();
  }
}
