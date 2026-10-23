#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const { version } = JSON.parse(readFileSync(new URL('../package.json', import.meta.url)));
const args = process.argv.slice(2);
if (args.length === 1 && ['--version', '-v', '-V'].includes(args[0])) console.log(version);
else {
  const { formatOutput, homeView } = await import('../src/output.mjs');
  const print = value => console.log(args.includes('--json') ? JSON.stringify(value) : formatOutput(value));
  const description = 'Isolated DEV collection and deterministic laboratory comparison; no automatic baseline promotion.';
  const usage = {
    cycle: 'cycle --action <begin|post|decide|restore|show> [--input <private-json-path>] [--json]',
    doctor: 'doctor [--json]', measure: 'measure --label <label> --input <private-series-request.json> [--json]', list: 'list [--json]', show: 'show --run <id> [--json]',
    calibrate: 'calibrate --a <id> --b <id> [--focal <profile:/path/>] [--json]',
    'baseline-set': 'baseline-set --run <id> --confirm [--json] (alias: baseline set)',
    'budget-init': 'budget-init --confirm --plan <plan-id> --hash <plan-sha256> --operator <name> --deadline <iso> [--json] (explicit lead-gated 240-audit/13-series/96-capture allowance)',
    'budget-reconcile': 'budget-reconcile --confirm --input <private-recovery.json> [--json]',
    compare: 'compare --baseline <id> --candidate <id> [--calibration <cal-id>] [--json]',
  };
  const specs = { cycle: ['action', 'input'], doctor: [], measure: ['label', 'input'], list: [], show: ['run'], calibrate: ['a', 'b', 'focal'], 'baseline-set': ['run', 'confirm'], 'budget-init': ['confirm', 'plan', 'hash', 'operator', 'deadline'], 'budget-reconcile': ['confirm', 'input'], compare: ['baseline', 'candidate', 'calibration'] };
  const examples = {
    cycle: 'cycle --action show; cycle --action begin --input /private/begin.json. begin: repo,hypothesis,paths,baseline,calibration,acceptance; optional mode=read-only (no restoration). post: tests. decide: candidate,acceptance. Full schemas: README.md.',
    doctor: 'doctor --help; doctor --json (network; requires authorization)',
    measure: 'measure --help; measure --label aa-1 --input /private/series.json --json (purpose,generation,captures,hypothesis; network; requires budget)',
    list: 'list; list --json', show: 'show --run <id>; show --run <id> --json',
    calibrate: 'calibrate --a <id-a1> --b <id-a2>; calibrate --a <id-a1> --b <id-a2> --focal mobile:/ --json (default: no focal)',
    'baseline-set': 'baseline-set --run <id> --confirm; baseline set --run <id> --confirm --json',
    'budget-init': 'budget-init --help; budget-init --confirm --plan plan-mvl2p6uk-wka1 --hash <approved-plan-sha256> --operator <agent> --deadline 2026-10-24T05:27:07Z --json (lead-gated activation only; no renewal)',
    'budget-reconcile': 'budget-reconcile --help; budget-reconcile --confirm --input /private/recovery.json --json (custodied technical repair only; no refunds)',
    compare: 'compare --baseline <id-a> --candidate <id-b>; compare --baseline <id-a> --candidate <id-b> --calibration <cal-id> --json (default: no calibration)',
  };
  let command = 'list';
  const helpFor = () => Object.hasOwn(specs, command) ? `${usage[command]}; valid flags: ${specs[command].map(x => '--' + x).concat(['--json', '--help', '-h']).join(', ')}; examples: ${examples[command]}` : `Commands: ${Object.keys(specs).join(', ')}; run --help.`;
  const fail = message => { const error = new Error(message); error.code = 'usage'; throw error; };
  try {
    const tokens = args.filter(x => !['--json', '--help', '-h'].includes(x));
    if (tokens[0] === 'baseline' && tokens[1] === 'set') tokens.splice(0, 2, 'baseline-set');
    command = tokens.shift() || 'list';
    const help = args.includes('--help') || args.includes('-h');
    if (!Object.hasOwn(specs, command)) fail(`Unknown command or argument ${/^[\w-]{1,80}$/.test(command) ? command : '[unsupported argument]'}`);
    const options = {};
    for (let i = 0; i < tokens.length; i++) {
      const token = tokens[i], name = token.slice(2);
      if (!token.startsWith('--') || !specs[command].includes(name)) fail(`Unknown flag or argument ${/^--[\w-]{1,80}$/.test(token) ? token : '[unsupported argument]'} for ${command}`);
      if (Object.hasOwn(options, name)) fail(`Duplicate flag ${token} for ${command}`);
      if (name === 'confirm') options[name] = true;
      else { const value = tokens[++i]; if (!value || value.startsWith('-')) fail(`Missing value for ${token}`); options[name] = value; }
    }
    if (help) print({ description, usage: args.some(x => !x.startsWith('-')) ? helpFor() : Object.values(usage).join('; '), help: '--help/-h per command; --version/-v/-V; default output: compact TOON, --json: full details; no URL/config overrides.' });
    else {
      const required = { cycle: ['action'], measure: ['label', 'input'], show: ['run'], calibrate: ['a', 'b'], 'baseline-set': ['run', 'confirm'], 'budget-init': ['confirm', 'plan', 'hash', 'operator', 'deadline'], 'budget-reconcile': ['confirm', 'input'], compare: ['baseline', 'candidate'] };
      for (const name of required[command] || []) if (!options[name]) fail(`Missing required flag --${name} for ${command}`);
      for (const [k, v] of Object.entries(options)) {
        if (['confirm', 'focal', 'action', 'input'].includes(k)) continue;
        if (k === 'hash' && !/^[a-f0-9]{64}$/.test(v)) fail(`Invalid value for --${k}`);
        else if (k === 'operator' && !/^[\w.@-]{1,100}$/.test(v)) fail(`Invalid value for --${k}`);
        else if (k === 'deadline' && !Number.isFinite(Date.parse(v))) fail(`Invalid value for --${k}`);
        else if (!['hash', 'operator', 'deadline'].includes(k) && !/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/.test(v)) fail(`Invalid value for --${k}`);
      }
      if (command === 'budget-init' && Date.parse(options.deadline) <= Date.now()) fail('--deadline must be in the future');
      if (command === 'cycle' && !['begin', 'post', 'decide', 'restore', 'show'].includes(options.action)) fail('Invalid --action; use begin, post, decide, restore or show');
      if (command === 'cycle' && (['begin', 'post', 'decide'].includes(options.action) !== Boolean(options.input))) fail(`--input is required only for cycle begin/post/decide, not ${options.action}`);
      if (options.label && options.label.length > 40) fail('--label must be at most 40 characters');
      if (options.focal && !['mobile', 'desktop'].some(p => ['/', '/tienda/', '/producto/caja-cosechera-3-4/'].some(r => options.focal === `${p}:${r}`))) fail('Invalid --focal; use mobile:/, desktop:/ or another fixed route');
      let result;
      if (command === 'budget-init') {
        const { initializeBudget } = await import('../src/budget.mjs'); result = await initializeBudget({ planId: options.plan, planHash: options.hash, operator: options.operator, deadline: options.deadline });
      } else if (command === 'budget-reconcile') {
        const { reconcileMeasurement } = await import('../src/budget.mjs');
        const { readPrivate } = await import('../src/store.mjs');
        result = await reconcileMeasurement(await readPrivate(options.input));
      } else if (command === 'cycle') {
        const { cycle } = await import('../src/cycle.mjs');
        const { readPrivate } = await import('../src/store.mjs');
        result = await cycle(options.action, options.input ? await readPrivate(options.input) : null);
      } else if (['doctor', 'measure'].includes(command)) {
        const m = await import('../src/measure.mjs');
        const { readPrivate } = await import('../src/store.mjs');
        result = command === 'doctor' ? await m.doctor() : await m.measure(options.label, await readPrivate(options.input));
      } else {
        const m = await import('../src/compare.mjs');
        if (command === 'list') result = await m.list();
        if (command === 'show') result = await m.show(options.run);
        if (command === 'calibrate') result = await m.calibrate(options.a, options.b, options.focal);
        if (command === 'baseline-set') result = await m.baselineSet(options.run);
        if (command === 'compare') result = await m.compare(options.baseline, options.candidate, options.calibration);
        process.exitCode = m.exitCode(result.status);
      }
      if (command === 'list' && !args.some(x => !x.startsWith('-'))) result = homeView(result, fileURLToPath(import.meta.url), homedir(), description);
      print(result);
      if (result.status === 'incomplete') process.exitCode = 1;
    }
  } catch (error) {
    const code = error.code || 'operation-failed';
    print({ error: code, ...(code === 'usage' ? { message: error.message } : {}), help: code === 'usage' ? helpFor() : 'Check private evidence and tools/lighthouse-dev/README.md. Invalid records never imply improvement; do not retry collection automatically.' });
    process.exitCode = code === 'usage' ? 2 : code === 'busy' ? 6 : 1;
  }
}
