#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const { version } = JSON.parse(readFileSync(new URL('../package.json', import.meta.url)));
const args = process.argv.slice(2);
if (args.length === 1 && ['--version', '-v', '-V'].includes(args[0])) {
  console.log(version);
} else {
  const json = args.includes('--json');
  const unknown = args.filter(arg => !['--json', '--help', '-h'].includes(arg));
  const output = unknown.length ? {
    error: 'Unsupported command or argument',
    help: 'Valid flags: --help, -h, --version, -v, -V, --json. Measurement is not available yet.',
  } : args.includes('--help') || args.includes('-h') ? {
    description: 'Lighthouse DEV toolchain scaffold; no network or browser actions.',
    usage: 'freeplast-lh [--help|-h|--version|-v|-V|--json]',
    examples: 'freeplast-lh; freeplast-lh --help; freeplast-lh --version',
  } : {
    bin: fileURLToPath(import.meta.url).replace(homedir(), '~'),
    description: 'Lighthouse DEV toolchain scaffold',
    status: 'not ready for DEV measurement',
    help: 'Run freeplast-lh --help. Fixture smoke instructions: tools/lighthouse-dev/README.md',
  };
  console.log(json ? JSON.stringify(output) : Object.entries(output).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n'));
  if (unknown.length) process.exitCode = 2;
}
