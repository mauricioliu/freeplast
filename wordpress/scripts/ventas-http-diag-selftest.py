#!/usr/bin/env python3
"""Offline self-check for the lead-run ventas HTTP diagnostic (no server, no WP).
Compiles the runner, php -l's both fixture files and the embedded mu-plugin
template, and asserts the logged evidence fields carry no secrets/PII."""
import os
import py_compile
import re
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
DRIVER = os.path.join(HERE, 'ventas-http-diag.py')
FIXTURE = os.path.join(HERE, 'ventas-http-diag-fixture.php')
PHP = os.path.join(os.path.dirname(HERE), '.tools', 'php', 'php')
failures = []


def check(name, ok):
    print('%s: %s' % (name, 'pass' if ok else 'FAIL'))
    if not ok:
        failures.append(name)


py_compile.compile(DRIVER, doraise=True)
check('driver_compiles', True)
src = open(DRIVER, encoding='utf-8').read()
mu = re.search(r"MU_PLUGIN = r'''(.*?)'''", src, re.S).group(1)

# Regression: has_action()/has_filter() return the hook PRIORITY; 0 is present.
import importlib.util
spec = importlib.util.spec_from_file_location('ventas_http_diag', DRIVER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
check('priority0_hook_counts_as_present', module.hooks_missing_entry(
    {'front': 0, 'note_meta': 0, 'save': 0, 'bulk': 0}) is False)
check('absent_hook_counts_as_missing', module.hooks_missing_entry(
    {'front': 0, 'note_meta': 0, 'save': False, 'bulk': 0}) is True)
check('missing_key_counts_as_missing', module.hooks_missing_entry({'front': 0}) is True)

if os.path.exists(PHP):
    with tempfile.TemporaryDirectory() as tmp:
        for name, code in (('mu_plugin', mu), ('fixture', open(FIXTURE, encoding='utf-8').read())):
            path = os.path.join(tmp, name + '.php')
            open(path, 'w', encoding='utf-8').write(code)
            r = subprocess.run([PHP, '-l', path], capture_output=True, text=True)
            check(name + '_php_lint', r.returncode == 0)
else:
    print('php tool missing: php lint skipped')

# logged JSONL fields = keys of the arrays passed to fpw_diag_write only (the
# mint ajax response legitimately carries a nonce string; it is never logged)
logged = set()
for block in re.findall(r'fpw_diag_write\(\s*array\((.*?)\)\s*\)', mu, re.S):
    logged |= set(re.findall(r"'(\w+)'\s*=>", block))
logged |= set(re.findall(r"\$entry\['(\w+)'\]", mu))
banned = {'nonce', 'cookie', 'pass', 'pwd', 'log', 'email', 'user_login'}
check('no_secret_fields_logged', not (logged & banned))
check('actor_and_hooks_present', {'phase', 'transport', 'actor', 'hooks'} <= logged)

sys.exit(1 if failures else 0)
