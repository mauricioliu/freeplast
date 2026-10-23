import http from 'node:http';
import { createHash } from 'node:crypto';
import { checkRequest, deny, measureMode } from './policy.mjs';

export const sha256 = value => createHash('sha256').update(value).digest('hex');
export function request(url, address, signal) {
  return new Promise((resolve, reject) => {
    const req = http.get(url, {
      agent: false, signal, timeout: 10000,
      lookup: (_host, options, cb) => cb(null, options.all ? [{ address, family: address.includes(':') ? 6 : 4 }] : address, address.includes(':') ? 6 : 4),
      headers: { 'Accept-Language': 'es', 'User-Agent': 'Freeplast-DEV-preflight/1' },
    }, res => {
      let size = 0;
      const chunks = [];
      res.on('data', chunk => {
        size += chunk.length;
        if (size > 4 * 1024 * 1024) req.destroy(new Error('response-size'));
        else chunks.push(chunk);
      });
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }));
      res.on('error', reject);
    });
    req.on('timeout', () => req.destroy(new Error('preflight-timeout')));
    req.on('error', reject);
  });
}

export function publicIdentity(html, headers) {
  const robots = [...html.matchAll(/<meta\b[^>]*>/gi)].filter(x => /name\s*=\s*["']robots["']/i.test(x[0])).map(x => x[0]).join(' ');
  if (!/noindex/i.test(robots) || !/nofollow/i.test(robots) || !/\bLOCAL\b/.test(html)) deny('local-identity');
  if (!/no-store/i.test(headers['cache-control'] || '')) deny('cache-policy');
}

// `mode` requests the gated DEV measurement input (exact query) and requires the
// source-proven marker in the served HTML: server-side mode activity is detected,
// never inferred from the CLI flag alone.
export async function preflight(p, path, address, signal, { mode = false } = {}) {
  let url = p.origin + path + (mode ? measureMode.query : '');
  const hops = [];
  for (let hop = 0; hop <= 3; hop++) {
    checkRequest(p, url);
    if (hops.some(x => x.url === url)) deny('redirect-loop');
    const response = await request(url, address, signal);
    hops.push({ url, status: response.status });
    if ([301, 302, 303, 307, 308].includes(response.status)) {
      if (!response.headers.location || hop === 3) deny('redirect-limit');
      url = new URL(response.headers.location, url).href;
      checkRequest(p, url);
      continue;
    }
    if (response.status !== 200) deny('preflight-status');
    const html = response.body.toString('utf8');
    publicIdentity(html, response.headers);
    const markers = mode ? html.split(measureMode.marker).length - 1 : 0;
    if (mode && (!markers || !html.includes(measureMode.bootstrapMarker))) deny('measure-mode-inactive');
    return {
      html, url, hops,
      mode: { active: mode, id: measureMode.id, query: measureMode.query, version: measureMode.version, markers },
      // No normalizer: conservative byte drift is invalid, including dynamic nonces.
      contentHash: sha256(response.body),
      headers: Object.fromEntries(['cache-control', 'content-type', 'content-security-policy', 'x-robots-tag'].map(k => [k, response.headers[k] ? (k === 'content-security-policy' ? sha256(response.headers[k]) : response.headers[k]) : null])),
    };
  }
}
