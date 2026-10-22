import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createHash } from 'node:crypto';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import lighthouse from 'lighthouse';
import { lighthouseConfig, timezone, smokeBrowserArgs } from '../src/config.mjs';

// ponytail: synthetic compatibility probe only; DEV guard and durable runner belong to ticket 2.
process.umask(0o077);
assert.equal(process.argv.length, 2, 'Smoke accepts no URL or arguments');
const pins = JSON.parse(await readFile(new URL('../toolchain.json', import.meta.url)));
assert.equal(process.versions.node, pins.node);
assert.equal(createHash('sha256').update(await readFile(pins.browser.path)).digest('hex'), pins.browser.sha256);
for (const name of ['lighthouse', 'puppeteer-core']) {
  const pkg = JSON.parse(await readFile(new URL(`../node_modules/${name}/package.json`, import.meta.url)));
  assert.equal(pkg.version, pins[name]);
}
const root = join(homedir(), '.local/state/freeplast-lighthouse-dev/ticket-1-evidence');
await mkdir(root, { recursive: true, mode: 0o700 });
const evidence = await mkdtemp(join(root, 'smoke-'));
const profile = await mkdtemp(join(tmpdir(), 'freeplast-lh-smoke-'));
const requests = [];
const html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="description" content="Fixture local de compatibilidad"><title>Fixture Lighthouse</title></head><body><main><h1>Fixture local</h1><p>Sin servicios externos ni datos comerciales.</p></main></body></html>';
const server = createServer((req, res) => {
  requests.push({ method: req.method, path: req.url });
  res.writeHead(200, { 'Content-Type': req.url === '/robots.txt' ? 'text/plain' : 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(req.url === '/robots.txt' ? 'User-agent: *\nDisallow: /\n' : html);
});
let browser;
let timer;
const started = Date.now();
let status = 'incomplete';
try {
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const url = `http://127.0.0.1:${server.address().port}/`;
  const run = async () => {
    assert.notEqual(process.env.PUPPETEER_DANGEROUS_NO_SANDBOX, 'true', 'Sandbox must remain enabled');
    const launchOptions = {
      executablePath: pins.browser.path, userDataDir: profile, headless: true,
      args: [...smokeBrowserArgs], dumpio: true,
    };
    await writeFile(join(evidence, 'launch.json'), JSON.stringify({
      executablePath: pins.browser.path, args: await puppeteer.defaultArgs(launchOptions),
      environment: Object.fromEntries(['LANG', 'LC_ALL', 'DISPLAY', 'WAYLAND_DISPLAY', 'XDG_RUNTIME_DIR', 'CHROME_LOG_FILE', 'PUPPETEER_DANGEROUS_NO_SANDBOX'].map(key => [key, process.env[key] ?? null])),
    }, null, 2), { flag: 'wx', mode: 0o600 });
    browser = await puppeteer.launch(launchOptions);
    assert.match(await browser.version(), new RegExp(pins.browser.version.replaceAll('.', '\\.')));
    const page = await browser.newPage();
    await page.emulateTimezone(timezone);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'es' });
    const cdp = await page.createCDPSession();
    const browserVersion = await cdp.send('Browser.getVersion');
    await cdp.detach();
    const result = await lighthouse(url, { logLevel: 'error' }, lighthouseConfig('mobile'), page);
    assert.ok(result && !result.lhr.runtimeError, 'Lighthouse must complete');
    assert.equal(result.lhr.finalDisplayedUrl, url);
    for (const id of ['first-contentful-paint', 'largest-contentful-paint', 'speed-index', 'total-blocking-time', 'cumulative-layout-shift']) {
      assert.ok(Number.isFinite(result.lhr.audits[id].numericValue), `Missing ${id}`);
    }
    for (const id of ['performance', 'accessibility', 'best-practices', 'seo']) assert.ok(Number.isFinite(result.lhr.categories[id].score), `Missing ${id}`);
    assert.equal(result.report.length, 2);
    await writeFile(join(evidence, 'lhr.json'), result.report[0], { flag: 'wx', mode: 0o600 });
    await writeFile(join(evidence, 'lhr.html'), result.report[1], { flag: 'wx', mode: 0o600 });
    await writeFile(join(evidence, 'toolchain.json'), JSON.stringify({ pins, browserVersion, settings: result.lhr.configSettings }, null, 2), { flag: 'wx', mode: 0o600 });
    status = 'passed';
  };
  await Promise.race([run(), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Smoke deadline exceeded')), 110000); })]);
} finally {
  clearTimeout(timer);
  if (browser) await browser.close();
  await new Promise(resolve => server.close(resolve));
  await rm(profile, { recursive: true, force: true });
  await writeFile(join(evidence, 'result.json'), JSON.stringify({ status, elapsedMs: Date.now() - started, requests }, null, 2), { flag: 'wx', mode: 0o600 });
  console.log(JSON.stringify({ status, evidence }));
}
