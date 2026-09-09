#!/usr/bin/env python3
"""Reproduce reviewed reference-photo crops; never retouch products or upscale.
Requires Pillow and Poppler's pdfimages. No network or WordPress writes.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
DATA = ROOT / 'wordpress/data'
OUT = DATA / 'catalog-photos'
PDF = ROOT / 'docs/Catálogo Freeplast 2026.pdf'
# Photo-only windows, measured on a 620px-wide rendering of each PDF page.
# Exclude titles, specifications and decorative page borders, NOT watermarks.
WINDOWS = {
    'tote': (6, (145, 200, 470, 495)),
    'caja-cosechera-3-4': (7, (105, 205, 520, 505)),
    'caja-universal-cerrada-negra': (8, (106, 220, 526, 493)),
    'caja-universal-cerrada-color': (9, (105, 235, 535, 510)),
    'caja-universal-ventilada-negra': (10, (110, 220, 525, 515)),
    'caja-universal-ventilada-color': (11, (105, 250, 535, 505)),
    'caja-tomatera': (12, (115, 205, 520, 515)),
    'caja-frutillera': (13, (105, 225, 530, 495)),
    'caja-frutera': (14, (120, 215, 525, 515)),
    'caja-paltera': (15, (105, 245, 520, 485)),
    'traversa-para-bins-tipo-g1': (16, (100, 260, 530, 485)),
    'traversa-para-bins-tipo-romano': (17, (165, 235, 490, 545)),
    'traversa-para-bins-tipo-upc': (18, (110, 265, 530, 520)),
    'caja-pollera': (19, (100, 195, 530, 520)),
    'caja-merlucera': (20, (160, 210, 485, 520)),
}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', '-v', '-V', action='version', version='1.0.0')
    parser.parse_args()
    from PIL import Image, ImageOps
    OUT.mkdir(parents=True, exist_ok=True)
    products = json.loads((DATA / 'products.json').read_text())['products']
    entries = []
    with tempfile.TemporaryDirectory(prefix='freeplast-photos-') as tmp:
        subprocess.run(['pdfimages', '-j', str(PDF), str(Path(tmp) / 'page')], check=True, capture_output=True)
        for product in products:
            slug = product['slug']
            old = product['image']
            if slug in WINDOWS:
                page, window = WINDOWS[slug]
                image = Image.open(Path(tmp) / f'page-{page-1:03d}.jpg').convert('RGB')
                crop = tuple(round(v * image.width / 620) for v in window)
                image = image.crop(crop)
                provenance = {'file': str(PDF.relative_to(ROOT)), 'sha256': digest(PDF), 'page': page, 'crop': crop}
            else:
                source = DATA / old['file']
                image = Image.open(source).convert('RGB')
                provenance = {'file': str(source.relative_to(ROOT)), 'sha256': digest(source)}
            # Only find framing bounds. This mask NEVER edits source pixels.
            mask = ImageOps.grayscale(image).point(lambda p: 255 if p < 210 else 0)
            bounds = mask.getbbox()
            if not bounds:
                raise ValueError(f'No product visible in {slug}')
            bounds = (max(0, bounds[0]-32), max(0, bounds[1]-32), min(image.width, bounds[2]+32), min(image.height, bounds[3]+32))
            image = image.crop(bounds)
            provenance['framing_crop'] = bounds
            size = min(960, round(max(image.size) * 1.2))
            edge = round(size / 1.2)
            image.thumbnail((edge, edge), Image.Resampling.LANCZOS)  # shrink only
            canvas = Image.new('RGB', (size, size), 'white')
            canvas.paste(image, ((size-image.width)//2, (size-image.height)//2))
            target = OUT / f'{slug}.webp'
            canvas.save(target, 'WEBP', quality=90, method=6)
            alt = product['title'] if 'title' in product else product.get('name', slug)
            if slug.endswith('-color'):
                alt += ' — fotografía referencial en rojo; no representa los demás colores'
            else:
                alt += ' — fotografía referencial'
            entries.append({'source_id': product['source_id'], 'slug': slug, 'file': target.name,
                            'sha256': digest(target), 'previous_sha256': old['checksum'].removeprefix('sha256:'),
                            'width': size, 'height': size, 'alt': alt,
                            'caption': ('Fotografía del catálogo 2026 en rojo. Los demás colores no se muestran en esta imagen.'
                                        if slug.endswith('-color') else
                                        ('Fotografía referencial del catálogo Freeplast 2026.' if slug in WINDOWS else
                                         'Fotografía referencial del sitio anterior. Original de mayor resolución pendiente.')),
                            'provisional': True, 'source': provenance})
    manifest = {'version': 1, 'revision': '2026-09-09', 'products': entries}
    (OUT / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    print(f'photos: {len(entries)}\noutput: wordpress/data/catalog-photos\nwordpress_writes: false')


if __name__ == '__main__':
    main()
