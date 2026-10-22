#!/usr/bin/env node
import { readFileSync } from 'node:fs';

const { version } = JSON.parse(readFileSync(new URL('../package.json', import.meta.url)));
const args = process.argv.slice(2);
const print = value => console.log(args.includes('--json') ? JSON.stringify(value) : Object.entries(value).map(([k, v]) => `${k}: ${JSON.stringify(v)}`).join('\n'));
const usage = {
  doctor: 'doctor [--json]', measure: 'measure --label <label> [--json]', list: 'list [--json]', show: 'show --run <id> [--json]',
  calibrate: 'calibrate --a <id> --b <id> [--focal <profile:/path/>] [--json]',
  'baseline-set': 'baseline-set --run <id> --confirm [--json] (alias: baseline set)',
  compare: 'compare --baseline <id> --candidate <id> [--calibration <cal-id>] [--json]',
};
if (args.length === 1 && ['--version', '-v', '-V'].includes(args[0])) console.log(version);
else {
  try {
    const tokens = args.filter(x => !['--json', '--help', '-h'].includes(x));
    if (tokens[0] === 'baseline' && tokens[1] === 'set') tokens.splice(0, 2, 'baseline-set');
    const command = tokens.shift() || 'list', help = args.includes('--help') || args.includes('-h');
    const specs = { doctor: [], measure: ['label'], list: [], show: ['run'], calibrate: ['a', 'b', 'focal'], 'baseline-set': ['run', 'confirm'], compare: ['baseline', 'candidate', 'calibration'] };
    const required = { measure: ['label'], show: ['run'], calibrate: ['a', 'b'], 'baseline-set': ['run', 'confirm'], compare: ['baseline', 'candidate'] };
    const fail = () => { const e = new Error(); e.code = 'usage'; throw e; };
    if (!Object.hasOwn(specs, command)) fail();
    const options = {};
    for (let i = 0; i < tokens.length; i++) {
      const name = tokens[i].slice(2);
      if (!tokens[i].startsWith('--') || !specs[command].includes(name) || Object.hasOwn(options, name)) fail();
      if (name === 'confirm') options[name] = true;
      else { const value = tokens[++i]; if (!value || value.startsWith('-')) fail(); options[name] = value; }
    }
    if (help) print({ description: 'Isolated DEV collection and deterministic laboratory comparison; no automatic baseline promotion.', usage: args.some(x => !x.startsWith('-')) ? usage[command] : Object.values(usage).join('; '), help: '--help/-h, --version/-v/-V; no URL/config overrides. See tools/lighthouse-dev/README.md.' });
    else {
      if ((required[command] || []).some(k => !options[k])) fail();
      for (const [k, v] of Object.entries(options)) if (!['confirm', 'focal'].includes(k) && !/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/.test(v)) fail();
      if (options.label && options.label.length > 40) fail();
      if (options.focal && !['mobile', 'desktop'].some(p => ['/', '/tienda/', '/producto/caja-cosechera-3-4/'].some(r => options.focal === `${p}:${r}`))) fail();
      let result;
      if (['doctor', 'measure'].includes(command)) {
        const m = await import('../src/measure.mjs');
        result = command === 'doctor' ? await m.doctor() : await m.measure(options.label);
      } else {
        const m = await import('../src/compare.mjs');
        if (command === 'list') result = await m.list();
        if (command === 'show') result = await m.show(options.run);
        if (command === 'calibrate') result = await m.calibrate(options.a, options.b, options.focal);
        if (command === 'baseline-set') result = await m.baselineSet(options.run);
        if (command === 'compare') result = await m.compare(options.baseline, options.candidate, options.calibration);
        process.exitCode = m.exitCode(result.status);
      }
      print(result);
      if (result.status === 'incomplete') process.exitCode = 1;
    }
  } catch (error) {
    const code = error.code || 'operation-failed';
    print({ error: code, help: code === 'usage' ? `Valid: ${Object.values(usage).join('; ')}; --help/-h, --version/-v/-V. No URL/config overrides.` : 'Check private evidence and tools/lighthouse-dev/README.md. Invalid records never imply improvement; do not retry collection automatically.' });
    process.exitCode = code === 'usage' ? 2 : code === 'busy' ? 6 : 1;
  }
}
