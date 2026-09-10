"""Verify the approved vendored PDF distribution before checks/packaging (no network)."""
import hashlib
import json
from pathlib import Path


def verify_quotation_artifacts(adapter: Path) -> int:
    lock = json.loads((adapter / 'quotation-pdf.lock.json').read_text())
    expected = {
        'name': 'dompdf/dompdf', 'version': '3.1.6', 'license': 'LGPL-2.1',
        'url': 'https://github.com/dompdf/dompdf/releases/download/v3.1.6/dompdf-3.1.6.zip',
        'sha256': '05df8ee4907325ed2e09a139de9784325f761b43a049297155499270163ac94d',
    }
    if lock['library'] != expected:
        raise ValueError('PDF library differs from the owner-approved pin')
    if set(lock['assets']) != {'mark.svg', 'Manrope-Regular.ttf', 'Manrope-Bold.ttf', 'OFL.txt'}:
        raise ValueError('Approved PDF asset set incomplete')
    count = 0
    for folder, entries in [('vendor/dompdf', lock['vendor_files']), ('pdf-assets', lock['assets'])]:
        directory = adapter / folder
        files = {str(p.relative_to(directory)) for p in directory.rglob('*') if p.is_file()}
        # Documentation is not a runtime asset and has no effect on the approved bytes.
        if folder == 'pdf-assets':
            files.discard('README.md')
        if files != set(entries):
            raise ValueError(f'PDF distribution file set differs: {folder}')
        for name, digest in entries.items():
            path = directory / name
            if path.is_symlink() or not path.resolve().is_relative_to(directory.resolve()):
                raise ValueError('PDF distribution contains a link or escaping path')
            if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
                raise ValueError(f'PDF artifact checksum mismatch: {folder}/{name}')
            count += 1
    return count
