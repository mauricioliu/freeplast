#!/usr/bin/env python3
"""Offline PDF transcription and immutable catalog boundaries; no WordPress/network."""
import hashlib
import json
from pathlib import Path

products = json.loads((Path(__file__).resolve().parents[1] / 'data/products.json').read_text())['products']
def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
def protected(p):
    return {k: v for k, v in p.items() if k not in ('specs', 'excerpt', 'description', 'review')} | {
        'minimum_quantity': p['specs']['minimum_quantity'], 'quantity_step': p['specs']['quantity_step']}
assert digest([protected(p) for p in products]) == 'db705f60ee66fde26c0e8968b6f040725f3120bbe065a77bf7119b57ad42db67'
assert digest(products[-2:]) == '21d282f79cb79321ecf30fd87facde1c138fe7599d9e322fdc5de5d0b5549ec1'
# Independent page ledger: slug, detailed page, weight, capacity, units/pallet, pallet height.
rows = [
 ('tote', 6, '800 g', '21 l', 120, '2.30'),
 ('caja-cosechera-3-4', 7, '1250 g', None, 70, '2.60'),
 ('caja-universal-cerrada-negra', 8, '1480 g', '46 l', 100, '2.10'),
 ('caja-universal-cerrada-color', 9, '1520 g', '46 l', 100, '2.10'),
 ('caja-universal-ventilada-negra', 10, '1800 g', '46 l', 100, '2.10'),
 ('caja-universal-ventilada-color', 11, '1870 g', '46 l', 100, '2.10'),
 ('caja-tomatera', 12, '840 g', '38 l', 128, '2.80'),
 ('caja-frutillera', 13, '300 g aprox.', None, 200, '2.50'),
 ('caja-frutera', 14, '1450 g', '44 l', 65, '2.80'),
 ('caja-paltera', 15, '330 g aprox.', '30 l', 540, '2.10'),
 ('traversa-para-bins-tipo-g1', 16, '2520 g', None, 132, '1.90'),
 ('traversa-para-bins-tipo-romano', 17, 'Consultar', None, 200, None),
 ('traversa-para-bins-tipo-upc', 18, '2050 g', None, 132, '1.90'),
 ('caja-pollera', 19, '1350 g aprox.', None, 90, '2.80'),
 ('caja-merlucera', 20, '2260 g', '40 l', 75, '2.30'),
]
dimensions = ['500 x 280 x 190 mm (exteriores)', '600 x 400 x 180 mm'] + ['625 x 444 x 226 mm'] * 4 + ['470 x 340 x 270 mm', '500 x 300 x 110 mm', '600 x 400 x 210 mm', '500 x 300 x 150 mm', 'Largo 1215 mm; alto 136 mm', 'Largo 1220 mm; alto 107 mm', 'Largo 1220 mm; alto 129 mm', '600 x 400 x 180 mm', '660 x 445 x 145 mm']
interiors = {6: '475 x 260 x 175 (unidad no indicada en PDF)', 7: '570 x 370 x 170 mm', 12: '450 x 320 x 260 mm', 13: '480 x 280 x 85 mm', 19: '570 x 370 x 170 mm'}
materials = ['PEAD reciclado', 'PEAD reciclado', 'PEAD reciclado', 'PEAD virgen', 'PEAD reciclado', 'PEAD virgen', 'PP', 'PP reciclado', 'PEAD reciclado', 'PEAD virgen', 'PP reciclado', 'PP reciclado', 'PP reciclado', 'HDPE recuperado', 'PEAD reciclado']
by_slug = {p['slug']: p for p in products}
assert len(by_slug) == len(products) == 17
for slug, page, weight, capacity, count, height in rows:
    p = by_slug[slug]
    s = p['specs']
    assert s['dimensions'] == dimensions[page - 6], slug
    assert s['interior_dimensions'] == interiors.get(page), slug
    assert s['material_short'] == materials[page - 6], slug
    assert s['features'] == ('Anidable y apilable' if page in (8, 9, 10, 11) else 'Apilable' if page in (7, 12, 19, 20) else 'Plegable' if page == 15 else None), slug
    assert (s['weight'], s['capacity'], s['units_per_pallet']) == (weight, capacity, count), slug
    assert s['pallet_dimensions'] == (f'1.00 x 1.20 x {height} m' if height else None), slug
    assert p['review']['spec_sources']['units_per_pallet'] == page
    for key, value in s.items():
        if value not in (None, 'Consultar'):
            assert key in p['review']['spec_sources'], (slug, key)
    assert 'not transcribable' not in str(p)
    if p['options']:
        assert [o['label'] for o in p['options']] == ['Blanco', 'Rojo', 'Amarillo', 'Azul', 'Verde']
        assert s['color'] == 'Blanco, Rojo, Amarillo, Azul y Verde'
    else:
        assert s['color'] == ('Transparente' if page == 15 else 'Negro')
    if 'universal' in slug:
        assert s['dimensions'] == '625 x 444 x 226 mm'
        assert s['features'] == 'Anidable y apilable'
assert by_slug['caja-frutera']['review']['spec_sources']['capacity'] == 3
assert by_slug['caja-cosechera-3-4']['review']['spec_sources']['dimensions'] == 2
assert 'unidad no indicada' in by_slug['tote']['specs']['interior_dimensions']
assert by_slug['caja-pollera']['specs']['dimensions'] == '600 x 400 x 180 mm'
assert 'G2' in str(by_slug['traversa-para-bins-tipo-romano']['review'])
print('catalog PDF: 15 page ledgers, provenance, colors, unknowns and 17 protected identities passed')
