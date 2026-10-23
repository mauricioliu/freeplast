#!/usr/bin/env python3
"""Synthetic-data tests for sales-history-workbook.py (no real workbook, no network)."""
import csv, hashlib, importlib.util, json, tempfile, unittest, zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location('swb', Path(__file__).with_name('sales-history-workbook.py'))
swb = importlib.util.module_from_spec(spec); spec.loader.exec_module(swb)
M = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'


def cell(ref, v=None, t=None, f=None):
    return f'<c r="{ref}"' + (f' t="{t}"' if t else '') + '>' + (f'<f>{f}</f>' if f else '') + ('' if v is None else f'<v>{v}</v>') + '</c>'


def make_xlsx(path, rows, extra=None):
    sheet = f'<worksheet {M}><sheetData>' + ''.join(f'<row r="{n}">{"".join(cs)}</row>' for n, cs in rows.items()) + '</sheetData></worksheet>'
    with zipfile.ZipFile(path, 'w') as z:
        z.writestr('xl/workbook.xml', f'<workbook {M} xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr('xl/sharedStrings.xml', f'<sst {M}><si><t>CO</t></si><si><t>ME</t></si></sst>')
        z.writestr('xl/worksheets/sheet1.xml', sheet)
        for n, body in (extra or {}).items(): z.writestr(n, body)


def row(rut, serial, prod, boxes, price, net, total, inv, formula=False):
    f = 'K1*2' if formula else None  # formula present: only the cached <v> may be read
    return [f'<c r="A" t="inlineStr"><is><t>{rut}</t></is></c>', cell('B', serial), cell('E', prod, 's'),
            cell('K', boxes, f=f), cell('L', price), cell('M', net), cell('O', total), cell('W', inv)]


class Converter(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.d = Path(temp.name); self.out = self.d / 'out' / 'run'
        # 45699 = 2025-02-11 ; 45671 = 2025-01-14
        make_xlsx(self.d / 'w.xlsx', {
            2: row('96.930.440-6', 45699, 0, 70, 2100, 147000, 174930, 11, formula=True),
            3: row('96930440-6', 45671, 0, 2000, 1880, 3760000, 4474400, 12),
            4: row('76.123.456-7', 45699, 1, 0, 0, '', 500, 13),                      # zero populated, net empty
            5: row('76123456-7', 45699, 1, 12.5, 10, 20, 700, 14),                    # fractional boxes: never rounded
            6: row('76123456-7', 45699, 1, 1, 1, 1, 999, 15),                         # total differs from import
        })
        self.sha = hashlib.sha256((self.d / 'w.xlsx').read_bytes()).hexdigest()
        self.applied = self.d / 'a.csv'
        self.applied.write_text('id_venta,fecha,rut,total\n11,2025-02-11,969304406,174930\n12,2025-01-14,969304406,4474400\n13,2025-02-11,761234567,500\n14,2025-02-11,761234567,700\n15,2025-02-11,761234567,700\n99,2025-02-11,761234567,1\n')
        mp = [{'id_venta': i, 'fecha': '2025-02-11', 'rut': '761234567', 'total': t, 'source_rows': [r]} for i, t, r in (('13', '500', 4), ('14', '700', 5), ('15', '700', 6))]
        mp = [dict(id_venta='11', fecha='2025-02-11', rut='969304406', total='174930', source_rows=[2]), dict(id_venta='12', fecha='2025-01-14', rut='969304406', total='4474400', source_rows=[3])] + mp
        self.map = self.d / 'm.json'; self.map.write_text(json.dumps(mp))

    def run_build(self):
        return swb.build(self.d / 'w.xlsx', self.applied, self.map, self.out, self.sha)

    def test_conversion_audit_and_safety(self):
        before = hashlib.sha256((self.d / 'w.xlsx').read_bytes()).hexdigest()
        audit, rows = self.run_build()
        self.assertEqual(before, audit['workbook_sha256']); self.assertEqual(before, hashlib.sha256((self.d / 'w.xlsx').read_bytes()).hexdigest())
        r = {x['id_venta']: x for x in rows}
        self.assertEqual(('2025-02-11', 'CO', 70, 2100, 147000), (r['11']['fecha'], r['11']['producto'], r['11']['total_cajas'], r['11']['precio'], r['11']['neto']))
        self.assertEqual(('2025-01-14', 2000, 3760000), (r['12']['fecha'], r['12']['total_cajas'], r['12']['neto']))
        self.assertEqual((0, 0, None), (r['13']['total_cajas'], r['13']['precio'], r['13']['neto']), 'zero kept, empty stays empty')
        self.assertIsNone(r['14']['total_cajas'], 'fractional never rounded'); self.assertEqual(10, r['14']['precio'])
        self.assertNotIn('15', r, 'core total mismatch -> not proposed'); self.assertNotIn('99', r, 'id absent from the map -> identity unproven')
        self.assertEqual({'proposed': 4, 'identity_unproven': 1, 'core_mismatch': 1}, audit['status'])
        self.assertEqual({'rows': 2, 'with_detail': 2}, audit['key_logistics_rut_rows'])
        self.assertEqual(0o700, self.out.stat().st_mode & 0o777); self.assertEqual(0o600, (self.out / 'enrichment.csv').stat().st_mode & 0o777)
        with open(self.out / 'enrichment.csv', newline='') as f: lines = list(csv.reader(f))
        self.assertEqual(['id_venta', 'fecha', 'rut', 'producto', 'total_cajas', 'precio', 'neto', 'total'], lines[0])
        self.assertEqual(['13', '2025-02-11', '761234567', 'ME', '0', '0', '', '500'], lines[3])

    def test_refuses_repo_output_existing_output_and_external_links(self):
        with self.assertRaises(SystemExit): swb.build(self.d / 'w.xlsx', self.applied, self.map, swb.REPO / 'tmp-out', self.sha)
        self.run_build()
        with self.assertRaises(SystemExit): self.run_build()
        make_xlsx(self.d / 'x.xlsx', {2: []}, {'xl/externalLinks/externalLink1.xml': '<x/>'})
        with self.assertRaises(SystemExit): swb.read_cells(self.d / 'x.xlsx', {2})

    def test_detail_only_workbook_change_is_rejected_before_any_output(self):
        # same date/RUT/total, different boxes: identity checks alone would accept it
        make_xlsx(self.d / 'w.xlsx', {
            2: row('96.930.440-6', 45699, 0, 71, 2100, 147000, 174930, 11), 3: row('96930440-6', 45671, 0, 2000, 1880, 3760000, 4474400, 12),
            4: row('76.123.456-7', 45699, 1, 0, 0, '', 500, 13), 5: row('76123456-7', 45699, 1, 12.5, 10, 20, 700, 14), 6: row('76123456-7', 45699, 1, 1, 1, 1, 999, 15)})
        with self.assertRaises(SystemExit): self.run_build()
        self.assertFalse(self.out.exists())
        with self.assertRaises(SystemExit): swb.build(self.d / 'w.xlsx', self.applied, self.map, self.out, '')

    def test_duplicate_ids_are_rejected(self):
        self.applied.write_text(self.applied.read_text() + '11,2025-02-11,969304406,174930\n')
        with self.assertRaises(SystemExit): self.run_build()
        self.assertFalse(self.out.exists())
        self.setUp()
        mp = json.loads(self.map.read_text()); self.map.write_text(json.dumps(mp + [mp[0]]))
        with self.assertRaises(SystemExit): self.run_build()
        self.assertFalse(self.out.exists())

    def test_namesakes_do_not_establish_identity_or_invent_missing_detail(self):
        name = '<c r="D" t="inlineStr"><is><t>Empresa Gemela</t></is></c>'
        make_xlsx(self.d / 'w.xlsx', {
            2: row('96.930.440-6', 45699, 0, 70, 2100, 147000, 174930, 11) + [name],
            3: row('76.123.456-7', 45671, 0, '', '', '', 4474400, 12) + [name],
        })
        self.sha = hashlib.sha256((self.d / 'w.xlsx').read_bytes()).hexdigest()
        self.applied.write_text('id_venta,fecha,rut,total\n11,2025-02-11,969304406,174930\n12,2025-01-14,761234567,4474400\n')
        mapping = [
            dict(id_venta='11', fecha='2025-02-11', rut='969304406', total='174930', source_rows=[2]),
            dict(id_venta='12', fecha='2025-01-14', rut='761234567', total='4474400', source_rows=[3]),
        ]
        self.map.write_text(json.dumps(mapping))
        audit, rows = self.run_build()
        self.assertEqual(2, audit['status']['proposed'])
        self.assertEqual(['969304406', '761234567'], [r['rut'] for r in rows])
        self.assertEqual((None, None, None), tuple(rows[1][k] for k in ('total_cajas', 'precio', 'neto')))
        self.assertNotIn('cliente', rows[1], 'legacy enrichment CSV never retained CLIENTE; viewer cannot reconstruct that source field')
        # An identical name cannot repair a different RUT in the immutable import identity.
        mapping[1]['rut'] = '969304406'
        self.map.write_text(json.dumps(mapping)); self.out = self.d / 'out' / 'mismatch'
        audit, rows = self.run_build()
        self.assertEqual(['11'], [r['id_venta'] for r in rows])
        self.assertEqual(1, audit['status']['identity_unproven'])

    def test_helpers(self):
        self.assertEqual('2025-02-11', swb.serial_to_iso('45699')); self.assertEqual('', swb.serial_to_iso('45699.5')); self.assertEqual('', swb.serial_to_iso('x'))
        self.assertEqual((0, None, None, None), (swb.to_int('0'), swb.to_int('-1'), swb.to_int('1.5'), swb.to_int('1234567890')))
        self.assertEqual(7, swb.to_int('7.0')); self.assertEqual('76543210K', swb.norm_rut('76.543.210-k')); self.assertEqual('', swb.norm_rut('abc'))


if __name__ == '__main__':
    unittest.main()
