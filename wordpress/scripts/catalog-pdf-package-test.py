#!/usr/bin/env python3
"""Offline packager regression; writes only the dedicated ignored build output."""
import hashlib
import json
import pathlib
import subprocess
import sys
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
script = root / 'scripts/package-catalog-pdf.py'
for flag in ('--version', '-v', '-V'):
    assert subprocess.check_output([sys.executable, script, flag], text=True).strip() == '1.0.0'
assert subprocess.run([sys.executable, script, '--unknown'], capture_output=True).returncode == 2

def package():
    output = pathlib.Path(subprocess.check_output([sys.executable, script], text=True).strip())
    return output, {str(p.relative_to(output)): hashlib.sha256(p.read_bytes()).hexdigest()
                    for p in sorted(output.rglob('*')) if p.is_file()}

out, first = package()
_, second = package()
assert first == second, 'Packaging must be byte deterministic'
expected = {'run.php', 'policy.php', 'review.json', 'catalog-pdf.php', 'products.json'}
assert {p.name for p in (out / 'migration').iterdir()} == expected
assert (out / 'migration/products.json').read_bytes() == (root / 'data/products.json').read_bytes()
assert (out / 'migration/catalog-pdf.php').read_bytes() == (root / 'scripts/lib/catalog-pdf.php').read_bytes()
review = json.loads((out / 'migration/review.json').read_text())
assert len(review) == len({p['id'] for p in review}) == len({p['source_id'] for p in review}) == 15
for name, prefix in [('freeplast-woo.zip', 'freeplast-woo/'), ('freeplast-woo-theme.zip', 'freeplast/')]:
    with zipfile.ZipFile(out / name) as archive:
        assert all(i.filename.startswith(prefix) and '..' not in pathlib.PurePosixPath(i.filename).parts for i in archive.infolist())
        assert all(i.date_time == (1980, 1, 1, 0, 0, 0) for i in archive.infolist())
print('catalog PDF package: deterministic ZIPs, exact closure, 15 identities and CLI validation passed')
