# Approved quotation assets

Owner approval: https://github.com/mauricioliu/freeplast/issues/56#issuecomment-5623666677

- `mark.svg`: byte-identical copy of the current theme's `assets/img/mark.svg`; no invented logo or new artwork.
- `Manrope-Regular.ttf` / `Manrope-Bold.ttf`: static 400/700 instances of the existing theme's `assets/fonts/manrope.woff2`, not downloaded substitute fonts. Original WOFF2 SHA-256: `ce340d48531930f3f2c8b7c47d149f82c9f4413548dd216e0f4d9af94a87c374`. Converted locally for Dompdf's TrueType embedding. Source copyright and SIL OFL 1.1 are preserved in `OFL.txt`; no Reserved Font Name is declared in that notice.
- Output hashes and the complete unmodified Dompdf release inventory live in `../quotation-pdf.lock.json`. Both checker and packaging fail if required files are missing, altered or unlisted. Runtime checks required assets and dependency availability; it does not download anything.

## Reproduce the fonts

From the repository root, using `uv` with pinned conversion tools (build-only, not runtime dependencies):

```sh
uv run --with fonttools==4.59.0 --with brotli==1.1.0 python - <<'PY'
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from pathlib import Path
source = Path('wordpress/wp-content/themes/freeplast/assets/fonts/manrope.woff2')
target = Path('wordpress/wp-content/plugins/freeplast-woo/pdf-assets')
for weight, name in [(400, 'Regular'), (700, 'Bold')]:
    font = TTFont(source, recalcTimestamp=False)
    font = instantiateVariableFont(font, {'wght': weight}, inplace=True)
    font.flavor = None
    for key, value in [(1, 'Manrope'), (2, name), (4, 'Manrope ' + name), (6, 'Manrope-' + name)]:
        font['name'].setName(value, key, 3, 1, 0x409)
    font.save(target / ('Manrope-' + name + '.ttf'))
PY
```

## Vendored distribution

`../vendor/dompdf/` is the **complete, unmodified** `dompdf-3.1.6.zip` release, checked against GitHub's release asset SHA-256 before extraction. Do not patch vendor code. Inventory:

| Package | Version | Declared license |
|---|---|---|
| dompdf/dompdf | 3.1.6 | LGPL-2.1 |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1-or-later |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0-or-later |
| masterminds/html5 | 2.10.1 | MIT |
| sabberworm/php-css-parser | 8.9.0 | MIT |

All distributed source, copyright notices, licenses and Composer metadata remain included, including the LGPL-3.0-or-later SVG dependency. No paid license, closed-source binary replacement or provider fork. The local adapter remains editable PHP and loads the distribution dynamically. Approval of library/assets is not human acceptance of the PDF layout.
