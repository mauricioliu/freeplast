#!/usr/bin/env python3
"""Public contact and confirmed commercial source boundaries; no HTTP or writes."""
from pathlib import Path
import hashlib
import json
root = Path(__file__).resolve().parents[1]
home = (root / 'wp-content/themes/freeplast/templates/front-page.html').read_text()
# The redesigned home renders contact details through its shared footer.
# Keep the approved address and retired-map prohibition, not the old markup location.
footer = (root / 'wp-content/themes/freeplast/parts/footer.html').read_text()
assert '"slug":"footer"' in home, 'home must render the shared contact footer'
assert 'P Picarquín sitio 52, San Fco de Mostazal, VI Región' in footer
assert 'Camino El Arrayán' not in home + footer and 'maps.app.goo.gl/QtGSdagB55W7rnRj7' not in home + footer
assert 'href="https://api.whatsapp.com/send?phone=56968444265"' in footer
assert 'href="mailto:ventas@freeplast.cl"' in footer
# Public wording is not an authorization to change routing coordinates/origin.
assert "define( 'FPW_DISTANCE_ORIGIN', 'Camino El Arrayán 52, San Francisco de Mostazal' );" in (root / 'wp-content/plugins/freeplast-woo/dispatch-distance.php').read_text()
# The ficha's fine print must state the packaging/minimum distinction accurately — the obsolete denial of any minimum is rejected.
single = (root / 'wp-content/themes/freeplast/woocommerce/content-single-product.php').read_text()
assert 'no una cantidad mínima de solicitud' not in single, 'obsolete fine print: packaging data denied being a request minimum'
assert 'mínimo por solicitud es propio de cada producto' in single, 'the fine print names the per-product minimum (or its pending state)'
sealed = root / 'scripts/catalog-pdf-release/products.json'
assert hashlib.sha256(sealed.read_bytes()).hexdigest() == 'a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a'
catalog = json.loads((root / 'data/products.json').read_text())['products']
assert len(catalog) == 17
for p in catalog:
    s = p['specs']
    assert s['quantity_step'] == 1
    assert s['minimum_quantity'] == (50 if p['slug'].startswith('traversa-') else s['units_per_pallet'])
assert {p['slug'] for p in catalog if p['specs']['minimum_quantity'] is None} == {'bases-plasticas-para-pediluvios', 'ladrillo-plastico'}
assert all(p['lifecycle'] == 'active' for p in catalog)
# Whole public-runtime surface: the only remaining Arrayán reference is the separately reviewed route origin.
for base in ['wp-content/themes/freeplast', 'wp-content/plugins/freeplast-woo']:
    for path in (root / base).rglob('*'):
        if path.is_file() and path.suffix in ('.php', '.html', '.js', '.css') and 'vendor' not in path.parts:
            text = path.read_text(errors='replace')
            assert 'Arrayán' not in text or path.name == 'dispatch-distance.php', path
            assert 'maps.app.goo.gl' not in text, path
# The guarded /contacto/ migration quotes the approved address itself, never the retired plugin as authority.
migration_lib = (root / 'scripts/lib/contacto-address.php').read_text()
assert 'P Picarquín sitio 52, San Fco de Mostazal, VI Región' in migration_lib
for path in [root / 'scripts/update-contacto-address.php', root / 'scripts/lib/contacto-address.php']:
    assert 'freeplast-catalog-quotes' not in path.read_text(), path
print('commercial sources: 17 minima/steps, two requestable unknowns, public contacts, separate route origin and sealed technical release passed')
