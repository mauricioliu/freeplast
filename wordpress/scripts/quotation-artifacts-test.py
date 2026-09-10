"""The PDF package contract rejects missing, altered and unlisted distribution bytes."""
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from lib.quotation_artifacts import verify_quotation_artifacts

SOURCE = Path(__file__).resolve().parents[1] / 'wp-content/plugins/freeplast-woo'


class QuotationArtifacts(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='fpw-artifact-test-')
        self.addCleanup(self.temp.cleanup)
        self.adapter = Path(self.temp.name) / 'adapter'
        shutil.copytree(SOURCE, self.adapter)

    def test_approved_distribution(self):
        self.assertGreater(verify_quotation_artifacts(self.adapter), 100)

    def test_damaged_asset_or_vendor_file(self):
        for name in ['pdf-assets/Manrope-Regular.ttf', 'pdf-assets/mark.svg', 'vendor/dompdf/VERSION']:
            with self.subTest(name=name):
                file = self.adapter / name
                original = file.read_bytes()
                file.write_bytes(b'altered')
                with self.assertRaises(ValueError):
                    verify_quotation_artifacts(self.adapter)
                file.write_bytes(original)

    def test_unlisted_or_missing_file(self):
        extra = self.adapter / 'vendor/dompdf/unlisted.php'
        extra.write_text('<?php // not in the approved release')
        with self.assertRaises(ValueError):
            verify_quotation_artifacts(self.adapter)
        extra.unlink()
        (self.adapter / 'vendor/dompdf/autoload.inc.php').unlink()
        with self.assertRaises(ValueError):
            verify_quotation_artifacts(self.adapter)

    def test_incomplete_asset_manifest(self):
        manifest = self.adapter / 'quotation-pdf.lock.json'
        lock = json.loads(manifest.read_text())
        lock['assets'] = {}
        manifest.write_text(json.dumps(lock))
        with self.assertRaises(ValueError):
            verify_quotation_artifacts(self.adapter)


if __name__ == '__main__':
    unittest.main(testRunner=unittest.TextTestRunner(stream=sys.stdout))
