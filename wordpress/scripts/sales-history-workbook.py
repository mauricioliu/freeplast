#!/usr/bin/env python3
"""Sales-history enrichment: stored workbook cells -> importer-supported CSV + audit.

Offline and read-only: the XLSX is opened as a bounded ZIP, only the STORED cell
values are read (formulas are never evaluated), the workbook is never written
and its SHA-256 is checked before and after. Sale identity is NEVER guessed:
rows are located only through the id_venta -> source_row map of the prior
import (--applied-csv = the CSV actually imported, --identity-map = its
candidates.json), and a row is emitted only when date, normalized RUT and total
re-read from the workbook equal what was imported. Output (CSV + audit) goes to
a new mode-0700 directory OUTSIDE the repo; stdout carries counts only.

  sales-history-workbook.py WORKBOOK --expected-workbook-sha256 SHA --applied-csv CSV --identity-map JSON --out DIR [--key-rut 969304406]
The workbook must hash to --expected-workbook-sha256 (the source the prior import was prepared from) or nothing is written.
Exit: 0 ok, 1 usage/safety error, 2 audit found rows that cannot be proposed.
"""
import argparse, csv, hashlib, io, json, os, re, sys, zipfile
from datetime import date, timedelta
from decimal import Decimal, InvalidOperation
from pathlib import Path
import xml.etree.ElementTree as ET

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
RNS = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
MAX_PART = 64 * 1024 * 1024   # uncompressed bytes per ZIP member
MAX_CELLS = 2_000_000
FIELDS = ('producto', 'total_cajas', 'precio', 'neto')
COLS = {'rut': 'A', 'fecha': 'B', 'producto': 'E', 'total_cajas': 'K', 'precio': 'L', 'neto': 'M', 'total': 'O', 'factura': 'W', 'cliente': 'D'}
REPO = Path(__file__).resolve().parents[2]


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def norm_rut(raw):
    s = re.sub(r'[\s.\-]', '', (raw or '').strip()[:20]).upper()
    return s if re.fullmatch(r'\d{7,8}[0-9K]', s) else ''


def part(z, name):
    info = z.getinfo(name)
    if info.file_size > MAX_PART:
        raise SystemExit(f'error: ZIP member {name} exceeds {MAX_PART} bytes')
    return z.read(name)


def read_cells(path, wanted_rows):
    """{row: {col: stored value (str)|None}} for wanted rows; also the date epoch flag. Values only, never formulas."""
    with zipfile.ZipFile(path) as z:
        names = set(z.namelist())
        if any(n.startswith('xl/externalLinks/') for n in names):
            raise SystemExit('error: workbook has external links; refusing')
        wb = ET.fromstring(part(z, 'xl/workbook.xml'))
        epoch1904 = (wb.find(f'{NS}workbookPr') is not None and wb.find(f'{NS}workbookPr').get('date1904') in ('1', 'true'))
        rid = wb.find(f'{NS}sheets')[0].get(f'{RNS}id')
        rels = ET.fromstring(part(z, 'xl/_rels/workbook.xml.rels'))
        target = next(r.get('Target') for r in rels if r.get('Id') == rid)
        sheet = target.lstrip('/') if target.startswith('/') else 'xl/' + target
        shared = []
        if 'xl/sharedStrings.xml' in names:
            for si in ET.fromstring(part(z, 'xl/sharedStrings.xml')):
                shared.append(''.join(t.text or '' for t in si.iter(f'{NS}t')))
        out, seen = {}, 0
        for _, el in ET.iterparse(io.BytesIO(part(z, sheet)), events=('end',)):
            if el.tag != f'{NS}row':
                continue
            r = int(el.get('r'))
            if r in wanted_rows:
                cells = {}
                for c in el.findall(f'{NS}c'):
                    seen += 1
                    col = re.match(r'[A-Z]+', c.get('r')).group(0)
                    t, v = c.get('t'), c.find(f'{NS}v')
                    if t == 'inlineStr':
                        val = ''.join(x.text or '' for x in c.iter(f'{NS}t'))
                    elif v is None or v.text is None:
                        val = None
                    elif t == 's':
                        val = shared[int(v.text)]
                    elif t == 'e':
                        val = None  # error cell: no stored value
                    else:
                        val = v.text
                    cells[col] = (val, t)
                out[r] = cells
                if seen > MAX_CELLS:
                    raise SystemExit('error: too many cells')
            el.clear()
    return out, epoch1904


def serial_to_iso(text, epoch1904=False):
    try:
        d = Decimal(text)
    except (InvalidOperation, TypeError):
        return ''
    if d < 1 or d != d.to_integral_value():
        return ''
    base = date(1904, 1, 1) if epoch1904 else date(1899, 12, 30)
    return (base + timedelta(days=int(d))).isoformat()


def to_int(text):
    """Non-negative integer <= 9 digits, else None. No rounding, ever."""
    if text is None or str(text).strip() == '':
        return None
    try:
        d = Decimal(str(text).strip())
    except InvalidOperation:
        return None
    if d < 0 or d != d.to_integral_value() or len(str(int(d))) > 9:
        return None
    return int(d)


def unique_by_id(items, label):
    ids = [i['id_venta'] for i in items]
    if len(set(ids)) != len(ids):
        raise SystemExit(f'error: duplicate id_venta in {label}; refusing, nothing written')
    return {i['id_venta']: i for i in items}


def build(workbook, applied_csv, identity_map, out_dir, expected_sha256, key_rut='969304406'):
    out = Path(out_dir).resolve()
    if REPO == out or REPO in out.parents:
        raise SystemExit('error: --out must be outside the repository')
    if out.exists():
        raise SystemExit('error: --out already exists; choose a new private directory')
    wb_hash = sha256(workbook)
    if wb_hash != (expected_sha256 or '').strip().lower():
        raise SystemExit('error: workbook sha256 differs from --expected-workbook-sha256 (the preparation source); refusing, nothing written')
    with open(applied_csv, newline='', encoding='utf-8-sig') as f:
        applied = unique_by_id(list(csv.DictReader(f)), 'applied CSV')
    with open(identity_map, encoding='utf-8') as f:
        mapping = unique_by_id(json.load(f), 'identity map')
    cells, epoch1904 = read_cells(workbook, {r for c in mapping.values() for r in c['source_rows']})

    rows, issues, filled = [], [], {k: 0 for k in FIELDS}
    key = {'rows': 0, 'with_detail': 0}
    status = {'proposed': 0, 'identity_unproven': 0, 'core_mismatch': 0}
    for id_ in sorted(set(applied) | set(mapping), key=lambda x: (len(x), x)):
        a, m = applied.get(id_), mapping.get(id_)
        if not a or not m or len(m['source_rows']) != 1 or any(a[k] != m[k] for k in ('fecha', 'rut', 'total')):
            status['identity_unproven'] += 1
            issues.append({'id_venta': id_, 'reason': 'identity_unproven: id absent from applied CSV or map, not a single source row, or the two disagree'})
            continue
        r = m['source_rows'][0]
        row = {k: (cells.get(r, {}).get(c) or (None, None))[0] for k, c in COLS.items()}
        total = to_int(row['total'])
        if serial_to_iso(row['fecha'], epoch1904) != a['fecha'] or norm_rut(row['rut']) != a['rut'] or total is None or str(total) != a['total']:
            status['core_mismatch'] += 1
            issues.append({'id_venta': id_, 'source_row': r, 'reason': 'core_mismatch: workbook date/RUT/total differ from the imported row'})
            continue
        prod = (row['producto'] or '').strip()
        if len(prod) > 200 or re.search(r'[\x00-\x1f]', prod):
            issues.append({'id_venta': id_, 'source_row': r, 'reason': 'producto unusable, left empty'})
            prod = ''
        rec = {'id_venta': id_, 'fecha': a['fecha'], 'rut': a['rut'], 'producto': prod}
        for k in FIELDS[1:]:
            rec[k] = to_int(row[k])
            if rec[k] is None and row[k] not in (None, ''):
                issues.append({'id_venta': id_, 'source_row': r, 'reason': f'{k} not a non-negative integer, left empty (never rounded)'})
        rec['total'] = total
        if str(row['factura'] or '').strip() != id_:
            issues.append({'id_venta': id_, 'source_row': r, 'reason': 'note: N° Factura cell differs from id_venta'})
        status['proposed'] += 1
        for k in FIELDS:
            filled[k] += rec[k] not in (None, '')
        if a['rut'] == key_rut:
            key['rows'] += 1
            key['with_detail'] += any(rec[k] not in (None, '') for k in FIELDS)
        rows.append(rec)

    out.mkdir(mode=0o700, parents=True)
    os.chmod(out, 0o700)
    csv_path = out / 'enrichment.csv'
    with open(os.open(csv_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(['id_venta', 'fecha', 'rut', 'producto', 'total_cajas', 'precio', 'neto', 'total'])
        for rec in rows:
            w.writerow([rec['id_venta'], rec['fecha'], rec['rut'], rec['producto']] + ['' if rec[k] is None else rec[k] for k in ('total_cajas', 'precio', 'neto', 'total')])
    if sha256(workbook) != wb_hash:
        raise SystemExit('error: workbook changed while reading')
    audit = {
        'workbook_sha256': wb_hash, 'workbook_unchanged': True, 'applied_csv_sha256': sha256(applied_csv), 'identity_map_sha256': sha256(identity_map),
        'enrichment_csv_sha256': sha256(csv_path), 'applied_rows': len(applied), 'map_rows': len(mapping), 'status': status,
        'rows_proposed': len(rows), 'rows_with_any_detail': sum(any(r[k] not in (None, '') for k in FIELDS) for r in rows), 'fields_present': filled,
        'key_logistics_rut_rows': key, 'issues_total': len(issues), 'issues': issues,
    }
    with open(os.open(out / 'audit.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w', encoding='utf-8') as f:
        json.dump(audit, f, ensure_ascii=False, indent=1)
    return audit, rows


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('workbook'); p.add_argument('--applied-csv', required=True); p.add_argument('--identity-map', required=True)
    p.add_argument('--out', required=True); p.add_argument('--expected-workbook-sha256', required=True, help='SHA-256 of the workbook the prior import was prepared from'); p.add_argument('--key-rut', default='969304406')
    a = p.parse_args(argv)
    audit, _ = build(a.workbook, a.applied_csv, a.identity_map, a.out, a.expected_workbook_sha256, a.key_rut)
    print(json.dumps({k: audit[k] for k in ('status', 'rows_proposed', 'rows_with_any_detail', 'fields_present', 'key_logistics_rut_rows', 'issues_total')}, ensure_ascii=False))
    print(f'next: audit {a.out}/audit.json; preview {a.out}/enrichment.csv in the owner importer (nothing is imported by this tool)')
    return 0 if audit['status']['proposed'] == audit['applied_rows'] else 2


if __name__ == '__main__':
    sys.exit(main())
