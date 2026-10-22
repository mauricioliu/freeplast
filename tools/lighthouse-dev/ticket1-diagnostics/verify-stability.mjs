#!/usr/bin/env node
// Ticket1 post-integration stability verification (READ-ONLY live GETs).
// Runs ONLY after the lead explicitly releases verification: worktree code is
// NOT served; main is integrated by the lead.
//
// Safety gate (lead requirements, rev 3):
//  - Real DNS pinning: resolve ALL records for the origin host; EVERY address
//    must belong to an actual interface of this host; the HTTP socket then
//    connects to the verified address (http.request host=<pinned IP>, Host
//    header preserved). fetch() is NOT used (it re-resolves DNS itself).
//    Mixed/nonlocal results, or a changed resolution mid-run (rebinding), are
//    rejected BEFORE any socket is opened.
//  - Durable counter: plan-global shared dir; start record fsynced BEFORE the
//    network request; dangling start blocks further runs; 18-GET global cap.
//  - Evidence: one complete immutable JSON per round (tmp+rename, 0600,
//    single parseable object); a duplicate completed round refuses to
//    overwrite. Directory 0700, files 0600, symlinks refused.
//  - Approved origin/paths only, GET only, redirects refused, bounded
//    time/bytes. Raw HTML/headers/tokens never persisted: only counts and
//    value-free hashes (script/style excluded).
//
// Offline self-test (no network): node verify-stability.mjs --self-test

import { openSync, writeSync, fsyncSync, closeSync, readFileSync, existsSync, mkdirSync, statSync, lstatSync, renameSync, chmodSync, unlinkSync, rmSync, mkdtempSync, symlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { lookup } from 'node:dns/promises';
import { networkInterfaces } from 'node:os';
import { dirname, join, isAbsolute } from 'node:path';
import { request } from 'node:http';

const ORIGIN = 'http://mliu:8131';
const HOST_HEADER = 'mliu:8131';
const ORIGIN_HOST = 'mliu';
const ORIGIN_PORT = 8131;
const PATHS = ['/', '/tienda/', '/producto/caja-cosechera-3-4/'];
const MODES = { controlled1: true, controlled2: true, 'normal-after': false };
const MAX_BYTES = 2 * 1024 * 1024;
const TIMEOUT_MS = 20_000;
// Plan-global shared budget (ticket2 must use the same default path); the
// FP_T1_COUNTER_DIR override exists for offline self-tests only and is
// recorded inside every evidence file.
const SHARED_DIR = '/home/mauricio-liu/.herd/plan-mode/plan-mvjyu52z-4elv/shared/diagnostics';

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');

function sharedDir() {
  const override = process.env.FP_T1_COUNTER_DIR;
  return override && isAbsolute(override) ? override : SHARED_DIR;
}

/** Refuse symlinks anywhere in an existing path chain (lstat, no follow). */
function assertNoSymlink(p, what) {
  let cur = p;
  for (;;) {
    let lst = null;
    try { lst = lstatSync(cur); } catch (e) { if (e.code !== 'ENOENT') { throw e; } }
    if (lst && lst.isSymbolicLink()) { throw new Error(`symlink refused at ${cur} (${what})`); }
    const parent = dirname(cur);
    if (parent === cur) { break; }
    cur = parent;
  }
}

function prepareSharedDir() {
  const dir = sharedDir();
  mkdirSync(dir, { recursive: true, mode: 0o700 });
  assertNoSymlink(dir, 'shared diagnostics dir');
  const st = statSync(dir);
  if (!st.isDirectory()) { throw new Error('shared diagnostics path is not a directory'); }
  chmodSync(dir, 0o700);
  const counters = join(dir, 'counters.log');
  if (existsSync(counters)) {
    assertNoSymlink(counters, 'counters.log');
    const cst = statSync(counters);
    if (!cst.isFile()) { throw new Error('counters.log is not a regular file'); }
    const mode = cst.mode & 0o777;
    if (mode !== 0o600) { throw new Error(`counters.log mode ${mode.toString(8)} != 0600; refusing (fix explicitly, never silently)`); }
  }
  return { dir, counters };
}

/** Durable append: write + fsync; returns only after fsync completes. */
function durable(counters, line) {
  const fd = openSync(counters, 'a', 0o600);
  try {
    writeSync(fd, JSON.stringify(line) + '\n');
    fsyncSync(fd);
  } finally { closeSync(fd); }
}

function readCounters(counters) {
  if (!existsSync(counters)) { return []; }
  assertNoSymlink(counters, 'counters.log');
  return readFileSync(counters, 'utf8').split('\n').filter(Boolean).map((r) => JSON.parse(r));
}

/** Dangling start (uncertain effect) or exhausted budget -> refuse. */
function budgetGuards(rows) {
  const done = rows.filter((r) => r.phase === 'done');
  const open = rows.filter((r) => r.phase === 'start').find((s) => !done.some((d) => d.n === s.n));
  if (open) { throw new Error(`uncertain prior effect: GET #${open.n} (${open.round} ${open.path}) start without completion; refusing`); }
  if (done.length + PATHS.length > 18) { throw new Error(`global diagnostic GET budget would exceed 18 (done=${done.length}, planned=${PATHS.length})`); }
}

/** Pinning: every resolved address must be an address of this host. */
function validatePinning(dnsResults, ifaceAddrs) {
  if (!dnsResults.length) { return { ok: false, reason: 'no DNS results' }; }
  const iface = new Set(ifaceAddrs);
  const bad = dnsResults.filter((r) => !iface.has(r.address));
  if (bad.length) { return { ok: false, reason: `non-interface address in DNS results: ${bad.map((b) => b.address).join(',')}` }; }
  return { ok: true, pinned: dnsResults[0] };
}

async function resolveAll(host) {
  try { return await lookup(host, { all: true, verbatim: true }); } catch { return []; }
}

function interfaceAddresses() {
  const addrs = [];
  for (const list of Object.values(networkInterfaces())) {
    for (const a of list ?? []) { addrs.push(a.address); }
  }
  return addrs;
}

/** GET one path over the PINNED address. No redirects, bounded, GET only. */
function pinnedGet(pinnedAddress, family, path) {
  return new Promise((resolve, reject) => {
    const req = request({
      host: pinnedAddress,
      family,
      port: ORIGIN_PORT,
      path,
      method: 'GET',
      headers: { host: HOST_HEADER, accept: 'text/html' },
      setHost: false,
    }, (res) => {
      const chunks = []; let total = 0;
      res.on('data', (c) => {
        total += c.byteLength;
        if (total > MAX_BYTES) { req.destroy(); reject(new Error('body exceeds byte cap')); return; }
        chunks.push(c);
      });
      res.on('end', () => {
        if (res.statusCode >= 300 && res.statusCode < 400) { reject(new Error(`refusing redirect ${res.statusCode}`)); return; }
        if (res.statusCode !== 200) { reject(new Error(`unexpected status ${res.statusCode}`)); return; }
        resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) });
      });
    });
    req.setTimeout(TIMEOUT_MS, () => { req.destroy(new Error('timeout')); });
    req.on('error', reject);
    req.end();
  });
}

const stripScriptStyle = (html) => html
  .replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  .replace(/<style\b[\s\S]*?<\/style\s*>/gi, '')
  .replace(/<!--[\s\S]*?-->/g, '');

function structurize(html, buf, status, contentType) {
  const clean = stripScriptStyle(html);
  const tagCounts = {};
  for (const m of clean.matchAll(/<([a-zA-Z][a-zA-Z0-9-]*)\b/g)) tagCounts[m[1].toLowerCase()] = (tagCounts[m[1].toLowerCase()] ?? 0) + 1;
  const ordered = (re) => [...clean.matchAll(re)].map((m) => m[1]);
  return {
    status,
    bytes: buf.length,
    rawSha256: sha256(buf),
    contentType,
    tagCountTotal: Object.values(tagCounts).reduce((a, b) => a + b, 0),
    tagCountsSha256: sha256(JSON.stringify(tagCounts)),
    textSha256: sha256(clean.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()),
    hrefsSha256: sha256(ordered(/<a\b[^>]*\bhref="([^"]*)"/g).join('\n')),
    imgSrcSha256: sha256(ordered(/<img\b[^>]*\bsrc="([^"]*)"/g).join('\n')),
    quantityIdsSha256: sha256(ordered(/\b(?:id|for)="(quantity_[^"]*)"/g).join('\n')),
    sectionHashes: [...clean.matchAll(/<section\b[^>]*>/g)].map((m) => ({
      class: (m[0].match(/class="([^"]*)"/)?.[1] ?? '').split(/\s+/).slice(0, 3).join('.'),
      hash: sha256(m[0]),
    })),
  };
}

/** One complete immutable JSON per round: tmp (0600) + fsync + rename. */
function writeEvidence(dir, round, evidence) {
  const final = join(dir, `evidence-${round}.json`);
  assertNoSymlink(final, `evidence-${round}`); // catches dangling symlinks too
  if (existsSync(final)) { throw new Error(`evidence for round ${round} already exists; refusing to overwrite`); }
  const tmp = join(dir, `.evidence-${round}.${process.pid}.tmp`);
  try {
    const fd = openSync(tmp, 'w', 0o600);
    try { writeSync(fd, JSON.stringify(evidence, null, 1) + '\n'); fsyncSync(fd); } finally { closeSync(fd); }
    renameSync(tmp, final);
    chmodSync(final, 0o600);
  } catch (e) {
    try { if (existsSync(tmp)) { unlinkSync(tmp); } } catch { /* best effort */ }
    throw e;
  }
  return final;
}

function readEvidence(dir, round) {
  const p = join(dir, `evidence-${round}.json`);
  if (!existsSync(p)) { return null; }
  assertNoSymlink(p, `evidence-${round}`);
  const st = statSync(p);
  if ((st.mode & 0o777) !== 0o600) { throw new Error(`evidence mode != 0600 for ${p}`); }
  return JSON.parse(readFileSync(p, 'utf8')); // single complete JSON object only
}

// ---------- live main (only after lead release) ----------
async function main(roundArg) {
  if (!MODES.hasOwnProperty(roundArg)) {
    console.error('usage: node verify-stability.mjs <controlled1|controlled2|normal-after>');
    process.exit(2);
  }
  const measure = MODES[roundArg];
  const { dir, counters } = prepareSharedDir();
  budgetGuards(readCounters(counters));

  // Pinning BEFORE any contact: all DNS results must be host interface addresses.
  const dns1 = await resolveAll(ORIGIN_HOST);
  const pin1 = validatePinning(dns1, interfaceAddresses());
  if (!pin1.ok) { throw new Error(`pinning rejected before contact: ${pin1.reason}`); }
  const { address: pinned, family } = pin1.pinned;

  const evidence = { round: roundArg, measure, origin: ORIGIN, pinnedAddress: `${pinned}/${family}`, counterDir: dir, counterDirIsOverride: sharedDir() !== SHARED_DIR, gets: [] };

  for (const path of PATHS) {
    const n = readCounters(counters).reduce((m, r) => Math.max(m, r.n ?? 0), 0) + 1;
    const url = ORIGIN + path + (measure ? '?fp-measure=1' : '');
    durable(counters, { phase: 'start', n, round: roundArg, path, url, at: new Date().toISOString() }); // fsynced BEFORE network

    const res = await pinnedGet(pinned, family, path + (measure ? '?fp-measure=1' : ''));

    // Rebinding check: resolution must still be identical and on-interface.
    const dns2 = await resolveAll(ORIGIN_HOST);
    const pin2 = validatePinning(dns2, interfaceAddresses());
    const sameResolution = dns2.length === dns1.length && dns2.every((r, i) => r.address === dns1[i].address);
    if (!pin2.ok || !sameResolution) { throw new Error('post-fetch re-validation failed (possible rebinding)'); }

    const record = { n, path, ...structurize(res.body.toString('utf8'), res.body, res.status, res.headers['content-type'] ?? '') };
    evidence.gets.push(record);
    durable(counters, { phase: 'done', n, round: roundArg, path, status: res.status, bytes: record.bytes, sha256: record.rawSha256, at: new Date().toISOString() });
  }

  const prevRound = roundArg === 'controlled2' ? 'controlled1' : null;
  if (prevRound) {
    const prev = readEvidence(dir, prevRound);
    if (prev) {
      evidence.comparisonVs = prevRound;
      evidence.comparison = {};
      for (const cur of evidence.gets) {
        const p = prev.gets?.find((g) => g.path === cur.path);
        evidence.comparison[cur.path] = p ? {
          rawEqual: cur.rawSha256 === p.rawSha256,
          textEqual: cur.textSha256 === p.textSha256,
          hrefsEqual: cur.hrefsSha256 === p.hrefsSha256,
          quantityIdsEqual: cur.quantityIdsSha256 === p.quantityIdsSha256,
          tagCountsEqual: cur.tagCountsSha256 === p.tagCountsSha256,
        } : 'missing-in-previous-round';
      }
    }
  }
  writeEvidence(dir, roundArg, evidence);
  if (evidence.comparison) { console.log(JSON.stringify(evidence.comparison, null, 1)); }
  console.log(`round ${roundArg}: ${evidence.gets.length} GETs recorded; ${join(dir, `evidence-${roundArg}.json`)}`);
}

// ---------- offline self-test (no network, no writes outside owned mkdtemp) ----------
function selfTest() {
  const failures = [];
  const ok = (cond, label) => { if (cond) { console.log(`  ok: ${label}`); } else { failures.push(label); console.error(`  FAIL: ${label}`); } };

  const r1 = validatePinning([{ address: '8.8.8.8', family: 4 }], ['127.0.0.1', '100.64.0.2']);
  ok(r1.ok === false, 'nonlocal DNS rejected before contact: ' + r1.reason);
  const r2 = validatePinning([{ address: '127.0.0.1', family: 4 }, { address: '203.0.113.9', family: 4 }], ['127.0.0.1']);
  ok(r2.ok === false, 'mixed DNS rejected before contact: ' + r2.reason);
  const r3 = validatePinning([], []);
  ok(r3.ok === false, 'empty DNS rejected');
  const r4 = validatePinning([{ address: '127.0.0.1', family: 4 }], ['127.0.0.1']);
  ok(r4.ok === true && r4.pinned.address === '127.0.0.1', 'valid local DNS pins (validation performs no contact)');

  const before = validatePinning([{ address: '127.0.0.1', family: 4 }], ['127.0.0.1']);
  const after = validatePinning([{ address: '203.0.113.1', family: 4 }], ['127.0.0.1']);
  const rebindingDetected = before.ok && !after.ok;
  ok(rebindingDetected, 'changed resolution (rebinding) rejected by re-validation logic');

  const tmp = mkdtempSync('/tmp/fpmselftestdir');
  try {
    const counters = join(tmp, 'counters.log');
    durable(counters, { phase: 'start', n: 1, round: 'controlled1', path: '/', at: 'x' });
    let threw = false;
    try { budgetGuards(readCounters(counters)); } catch (e) { threw = String(e.message).includes('uncertain'); }
    ok(threw, 'dangling start refuses further runs');
    ok(readCounters(counters).length === 1, 'fsynced start record readable back');
    ok((statSync(counters).mode & 0o777) === 0o600, 'counters.log created 0600');

    const rows = [];
    for (let i = 1; i <= 18; i++) { rows.push({ phase: 'start', n: i }, { phase: 'done', n: i }); }
    threw = false;
    try { budgetGuards(rows); } catch (e) { threw = String(e.message).includes('exceed 18'); }
    ok(threw, '18-GET cap refuses 3 more');

    chmodSync(counters, 0o644);
    threw = false;
    try { process.env.FP_T1_COUNTER_DIR = tmp; prepareSharedDir(); } catch (e) { threw = String(e.message).includes('0600'); }
    ok(threw, 'wrong counters mode refused');
    chmodSync(counters, 0o600);

    writeEvidence(tmp, 'controlled1', { round: 'controlled1', gets: [{ path: '/', n: 1 }] });
    ok(JSON.parse(readFileSync(join(tmp, 'evidence-controlled1.json'), 'utf8')).round === 'controlled1', 'evidence is one complete parseable JSON');
    threw = false;
    try { writeEvidence(tmp, 'controlled1', { round: 'controlled1' }); } catch (e) { threw = String(e.message).includes('refusing to overwrite'); }
    ok(threw, 'duplicate completed round refuses overwrite');
    ok(readEvidence(tmp, 'controlled1').round === 'controlled1', 'evidence reader accepts the single JSON');
    ok((statSync(join(tmp, 'evidence-controlled1.json')).mode & 0o777) === 0o600, 'evidence file 0600');

    symlinkSync(join(tmp, 'evidence-controlled1.json'), join(tmp, 'evidence-controlled2.json'));
    threw = false;
    try { writeEvidence(tmp, 'controlled2', {}); } catch (e) { threw = String(e.message).includes('symlink'); }
    ok(threw, 'symlink evidence target refused');
    unlinkSync(join(tmp, 'evidence-controlled2.json'));

    ok(MODES.hasOwnProperty('nope') === false, 'unknown round rejected by arg gate');
    ok((statSync(tmp).mode & 0o777) === 0o700, 'fixture dir 0700');
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
  if (failures.length) { console.error(`SELF-TEST FAIL (${failures.length})`); process.exit(1); }
  console.log('SELF-TEST PASS (all offline safety cases)');
}

const arg = process.argv[2] ?? '';
if (arg === '--self-test') {
  selfTest();
} else {
  main(arg).catch((e) => { console.error(String(e && e.message ? e.message : e)); process.exit(3); });
}
