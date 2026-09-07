#!/usr/bin/env python3
"""Read-only production probe; two anonymous GETs, no purge or settings changes.

Exit 1 on wrong product/canonical. Intermittent: a green run is not proof of repair.
Requires Python 3 and curl. Never run concurrently as a stress/load test.
"""
import hashlib
import html
import json
import re
import subprocess
import tempfile
import time
from pathlib import Path

BASE = 'https://freeplast.cl/producto/'
CASES = [
    ('traversas-para-bines/', 'Traversas para Bins Tipo UPC'),
    ('traversas-para-bins-tipo-w/', 'Traversas para Bins Tipo G1'),
]
failed = False
hashes = []
with tempfile.TemporaryDirectory(prefix='freeplast-wpsc-repro-') as directory:
    root = Path(directory)
    for i, (slug, expected) in enumerate(CASES):
        time.sleep(5 if i == 0 else 1.6)
        url = BASE + slug
        status = subprocess.check_output([
            'curl', '-sS', '--max-time', '35', '-w', '%{http_code}',
            '-D', str(root / 'headers'), '-o', str(root / 'body'), url,
        ], text=True)
        body = (root / 'body').read_bytes()
        source = body.decode('utf-8', errors='replace')
        h1 = [html.unescape(re.sub('<[^>]+>', '', value)).strip()
              for value in re.findall(r'<h1\b[^>]*>(.*?)</h1>', source, re.S | re.I)]
        canonical = re.findall(
            r'<link\b[^>]*rel=[\"\']canonical[\"\'][^>]*href=[\"\']([^\"\']+)',
            source, re.I)
        ok = status == '200' and h1 == [expected] and canonical == [url]
        failed |= not ok
        hashes.append(hashlib.sha256(body).hexdigest())
        print(json.dumps({
            'pass': ok, 'url': url, 'status': status, 'h1': h1,
            'canonical': canonical, 'sha256': hashes[-1],
            'headers': [line for line in (root / 'headers').read_text().splitlines()
                        if line.lower().startswith(('http/', 'cache-control:', 'age:', 'server:'))],
        }, ensure_ascii=False), flush=True)
print('Different URLs returned identical bodies:', hashes[0] == hashes[1])
raise SystemExit(1 if failed else 0)
