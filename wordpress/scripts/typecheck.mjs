#!/usr/bin/env node
/**
 * Static checks for the Freeplast WordPress shell:
 *   - php -l on every theme/plugin PHP file (uses the disposable PHP toolchain)
 *   - node --check on the repository scripts, theme JS and plugin JS
 *   - JSON validation for theme.json and the catalog source stub
 */
import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WORDPRESS_DIR = dirname(HERE);
const PHP = join(WORDPRESS_DIR, '.tools', 'php', 'php');

let failures = 0;

function walk(dir, ext) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...walk(full, ext));
    else if (entry.endsWith(ext)) out.push(full);
  }
  return out;
}

function run(label, cmd, args) {
  const res = spawnSync(cmd, args, { encoding: 'utf8' });
  if (res.status !== 0) {
    failures++;
    console.error(`FAIL ${label}\n${res.stdout || ''}${res.stderr || ''}`);
  } else {
    console.log(`ok   ${label}`);
  }
}

/* PHP syntax on theme + plugin */
const phpFiles = [
  ...walk(join(WORDPRESS_DIR, 'wp-content', 'themes'), '.php'),
  ...walk(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes'), '.php'),
];
if (phpFiles.length === 0) {
  failures++;
  console.error('FAIL no PHP files found to lint');
}
for (const file of phpFiles) {
  run(`php -l ${file.replaceAll(WORDPRESS_DIR + '/', '')}`, PHP, ['-l', file]);
}

/* Node syntax */
const jsFiles = [
  ...walk(join(WORDPRESS_DIR, 'scripts'), '.mjs'),
  ...walk(join(WORDPRESS_DIR, 'wp-content', 'themes'), '.js'),
  ...walk(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes'), '.js'),
];
for (const file of jsFiles) {
  run(`node --check ${file.replaceAll(WORDPRESS_DIR + '/', '')}`, process.execPath, ['--check', file]);
}

/* JSON validity */
for (const file of [join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast', 'theme.json'), join(WORDPRESS_DIR, 'data', 'products.json')]) {
  try {
    JSON.parse(readFileSync(file, 'utf8'));
    console.log(`ok   json ${file.replaceAll(WORDPRESS_DIR + '/', '')}`);
  } catch (err) {
    failures++;
    console.error(`FAIL json ${file}: ${err.message}`);
  }
}

process.exit(failures === 0 ? 0 : 1);
