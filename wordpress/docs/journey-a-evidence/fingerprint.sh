#!/usr/bin/env bash
# Read-only checksum manifest, not a bootstrap/deployment command.
set -euo pipefail
if (( $# > 1 )); then printf '%s\n' 'error: unexpected arguments; use --help' >&2; exit 2; fi
case "${1:-}" in
  --help) printf '%s\n' 'Usage: bash wordpress/docs/journey-a-evidence/fingerprint.sh > manifest.txt' 'Offline content hashes: frozen A, complete runtime/fixture/test inputs, pinned probe sources. Missing inputs fail. No network/server/database.'; exit 0 ;;
  --version|-v|-V) printf '2\n'; exit 0 ;;
esac
if (( $# )); then printf '%s\n' 'error: unexpected argument; only --help or --version is supported' >&2; exit 2; fi
cd "$(dirname "$0")/../../.."
python3 - <<'PY'
from pathlib import Path
import hashlib, re, subprocess, sys
source = '785e65b502078b67e29bffbd2a7beab8ae408aac'
base = 'wordpress/design/prototype-quote-journey/'
def git(*args):
    return subprocess.check_output(['git', *args], stderr=subprocess.PIPE)
def digest(data):
    return hashlib.sha256(data).hexdigest()
def rows(paths):
    return [(digest(Path(p).read_bytes()), p) for p in sorted(set(paths))]
try:
    source_paths = git('ls-tree', '-r', '--name-only', '-z', source, '--', base).decode().strip('\0').split('\0')
    if not source_paths or not source_paths[0]: raise ValueError('frozen A tree missing')
    frozen = [(digest(git('cat-file', 'blob', source + ':' + p)), p) for p in sorted(source_paths)]
    families = ('wordpress/wp-content/themes/freeplast/', 'wordpress/wp-content/plugins/freeplast-woo/', 'wordpress/scripts/', 'wordpress/data/', 'wordpress/assets/')
    explicit = {'package.json', 'package-lock.json', 'wordpress/woo-dependencies.json', 'wordpress/docs/journey-a-evidence/fingerprint.sh'}
    files = git('ls-files', '-c', '-o', '--exclude-standard', '-z').decode().strip('\0').split('\0')
    integrated = rows([p for p in files if p.startswith(families) or p in explicit])
    # Identify the actual cached source used by native probes; never read DB,
    # uploads, credentials or arbitrary .build contents, and never fetch them.
    wp = 'wordpress/.build/wp/'
    native = rows([wp + p for p in [
        'wp-includes/block-template.php', 'wp-includes/class-wp-hook.php',
        'wp-includes/plugin.php', 'wp-includes/utf8.php', 'wp-includes/kses.php',
        'wp-content/plugins/woocommerce/src/Blocks/BlockTypes/ClassicTemplate.php',
        'wp-content/plugins/woocommerce/includes/wc-template-functions.php',
        'wp-content/plugins/woocommerce/includes/class-wc-checkout.php',
        'wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.js',
        'wp-content/plugins/woocommerce/assets/js/frontend/checkout.js',
        'wp-content/plugins/woocommerce/assets/js/frontend/utils/custom-place-order-button.js',
    ]] + [str(p) for p in Path(wp + 'wp-includes/html-api').glob('*.php')])
    theme = re.search(r'^Version:\s*(\S+)', Path('wordpress/wp-content/themes/freeplast/style.css').read_text(), re.M).group(1)
    plugin = Path('wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php').read_text()
    adapter = re.search(r'^ \* Version:\s*(\S+)', plugin, re.M).group(1)
    fields = re.search(r"wp_enqueue_script\(\s*'fpw-fields'.*?array\([^)]*\),\s*'([^']+)'", plugin, re.S).group(1)
    manifest = '\n'.join(sha + '  ' + p for sha, p in frozen + integrated + native) + '\n'
    head = git('rev-parse', 'HEAD').decode().strip()
except (OSError, ValueError, AttributeError, subprocess.CalledProcessError) as error:
    print('error: cannot fingerprint all inputs; check frozen A, working files and pinned offline cache. No inputs were fetched.', file=sys.stderr)
    sys.exit(1)
print('# journey-a evidence fingerprint v2')
print('# base_commit (NOT implementation version): ' + head)
print('# source-A commit: ' + source)
print('# content_digest: ' + digest(manifest.encode()))
for title, records in [('source-A', frozen), ('integrated', integrated), ('native-probes', native)]:
    print('\n[' + title + '] count=' + str(len(records)))
    for sha, path in records: print(sha + '  ' + path)
print('\n[versions]\ntheme: ' + theme + '\nadapter: ' + adapter + '\nfields.js: ' + fields)
PY
