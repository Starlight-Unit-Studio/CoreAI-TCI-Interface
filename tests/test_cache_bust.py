#!/usr/bin/env python3
"""Offline regressions for numeric-only versions and content-based cache keys."""
import importlib.util
import re
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts' / 'cache-bust.py'
spec = importlib.util.spec_from_file_location('cache_bust', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class VersionCacheTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / 'VERSION').write_text('0.5.5\n')
        for folder in ('js', 'css', 'admin'):
            (self.root / folder).mkdir()
        (self.root / 'js' / 'x.js').write_text('const value = 1;')
        (self.root / 'css' / 'style.css').write_text('body { color: red; }')
        for name in mod.PAGES:
            html = ('<link href="./css/style.css?v=0.5.3-alpha">'
                    '<script src="./js/x.js?v=artifacts-1"></script>')
            if name.startswith('admin/'):
                html = html.replace('./css/', '../css/').replace('./js/', '../js/')
            (self.root / name).write_text(html, encoding='utf-8')

    def tearDown(self):
        self.temp.cleanup()

    def test_updates_all_pages_and_verifies(self):
        changed, refs = mod.run(self.root, verify=False)
        self.assertEqual((changed, refs), (6, 12))
        self.assertEqual(mod.run(self.root, verify=True), (0, 12))
        html = (self.root / 'app.html').read_text()
        self.assertNotIn('alpha', html)
        self.assertNotIn('artifacts-1', html)
        self.assertEqual(len(re.findall(r'\?v=\d{25}', html)), 2)
        mode = (self.root / 'app.html').stat().st_mode & 0o777
        self.assertEqual(mode, 0o644)

    def test_cache_key_only_changes_when_asset_bytes_change(self):
        mod.run(self.root, verify=False)
        before = (self.root / 'app.html').read_text()
        mod.run(self.root, verify=False)
        self.assertEqual(before, (self.root / 'app.html').read_text())
        (self.root / 'js' / 'x.js').write_text('const value = 2;')
        with self.assertRaisesRegex(ValueError, 'stale cache keys'):
            mod.run(self.root, verify=True)
        mod.run(self.root, verify=False)
        after = (self.root / 'app.html').read_text()
        self.assertNotEqual(before, after)
        self.assertEqual(re.findall(r'css/style.css\?v=(\d+)', before), re.findall(r'css/style.css\?v=(\d+)', after))

    def test_non_numeric_product_version_is_rejected(self):
        (self.root / 'VERSION').write_text('0.5.5-alpha\n')
        with self.assertRaisesRegex(ValueError, 'numeric-only'):
            mod.run(self.root, verify=False)

    def test_missing_asset_does_not_partially_modify_pages(self):
        original = (self.root / 'app.html').read_text()
        (self.root / 'js' / 'x.js').unlink()
        with self.assertRaisesRegex(ValueError, 'missing or unsafe asset'):
            mod.run(self.root, verify=False)
        self.assertEqual(original, (self.root / 'app.html').read_text())

    def test_escape_from_project_is_rejected(self):
        # A reference may not escape the expected repo directories.
        (self.root / 'app.html').write_text('<script src="./js/../../outside.js"></script>')
        with self.assertRaisesRegex(ValueError, 'missing or unsafe asset'):
            mod.run(self.root, verify=False)


if __name__ == '__main__':
    unittest.main()
