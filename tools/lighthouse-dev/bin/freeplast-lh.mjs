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
  const command = args.find(arg => !arg.startsWith('-'));
  const options = args.filter(arg => !['--json', '--help', '-h'].includes(arg));
  const measuring = command === 'measure';
  const operating = measuring || command === 'doctor';
  const help = args.includes('--help') || args.includes('-h');
  const validOperation = operating && (help ? options.length === 1 : measuring ? options.length === 3 && options[0] === 'measure' && options[1] === '--label' && /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,39}$/.test(options[2]) : options.length === 1);
  const unknown = validOperation ? [] : options;
  if (validOperation && !help) {
    try {
      const { doctor, measure } = await import('../src/measure.mjs');
      const result = measuring ? await measure(options[2]) : await doctor();
      console.log(json ? JSON.stringify(result) : Object.entries(result).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n'));
      if (result.status === 'incomplete') process.exitCode = 1;
    } catch (error) {
      const result = { error: error.code || 'operation-failed', help: 'Check tools/lighthouse-dev/README.md safety and identity prerequisites. Do not retry a failed series automatically.' };
      console.log(json ? JSON.stringify(result) : Object.entries(result).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n'));
      process.exitCode = error.code === 'busy' ? 6 : 1;
    }
  } else {
  const output = unknown.length ? {
    error: 'Unsupported command or argument',
    help: 'Valid: doctor [--json]; measure --label <1-40 letters/digits/_/-> [--json]; --help, -h, --version, -v, -V. No URL/config overrides.',
  } : args.includes('--help') || args.includes('-h') ? {
    description: 'Isolated Lighthouse DEV collection; doctor/measure require verified safety gate and operator identity receipt.',
    usage: 'freeplast-lh doctor [--json]; freeplast-lh measure --label <label> [--json]; --help/-h and --version/-v/-V',
    examples: 'freeplast-lh doctor --help; freeplast-lh measure --help; freeplast-lh --version',
  } : {
    bin: fileURLToPath(import.meta.url).replace(homedir(), '~'),
    description: 'Lighthouse DEV toolchain scaffold',
    status: 'not ready for DEV measurement',
    help: 'Run freeplast-lh --help. Fixture smoke instructions: tools/lighthouse-dev/README.md',
  };
  console.log(json ? JSON.stringify(output) : Object.entries(output).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n'));
  if (unknown.length) process.exitCode = 2;
  }
}
