#!/usr/bin/env python3
"""Low-rate anonymous probe. Fail: median TTFB > 1.8s, any > 5s, or non-200."""
import json, statistics, subprocess, sys, time
from datetime import datetime, timezone
from pathlib import Path

paths = sys.argv[1:] or ['/', '/categoria-producto/agricola/', '/producto/totem/']
rows = []
for run in range(3):
    for path in paths:
        url = 'https://freeplast.cl' + path
        result = subprocess.run(['curl', '-sS', '--compressed', '--max-time', '30', '-o', '/dev/null', '-w', '%{json}', url], capture_output=True, text=True)
        raw = json.loads(result.stdout) if result.stdout else {}
        row = {'at': datetime.now(timezone.utc).isoformat(), 'run': run + 1, 'path': path, 'exit': result.returncode}
        row.update({k: raw.get(k) for k in ['http_code', 'time_namelookup', 'time_connect', 'time_appconnect', 'time_starttransfer', 'time_total', 'size_download', 'remote_ip']})
        rows.append(row)
        print(json.dumps(row), flush=True)
        time.sleep(1)
failed = False
for path in paths:
    timings = [r['time_starttransfer'] for r in rows if r['path'] == path and r['exit'] == 0 and r['http_code'] == 200]
    median = statistics.median(timings) if timings else None
    ok = len(timings) == 3 and median <= 1.8 and max(timings) <= 5
    failed |= not ok
    print(json.dumps({'path': path, 'median_ttfb_s': median, 'max_ttfb_s': max(timings) if timings else None, 'verdict': 'PASS' if ok else 'FAIL'}))
sys.exit(1 if failed else 0)
