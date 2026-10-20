#!/usr/bin/env python3
"""Offline checks of the mandatory pre-merge CoreUI version gate."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "version-merge-check.py"
spec = importlib.util.spec_from_file_location("merge_version", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class MergeVersionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.write("VERSION", "0.5.6\n")
        for p in mod.PAGES + mod.REQUIRED_ACTIVE:
            self.write(p, "CoreUI version 0.5.6\n")
        self.write("CHANGELOG.md", "## 0.5.6 - 20.10.2026\n")
        self.write("docs/changelogs-txt/INDEX.txt", "* CHANGELOG_0_5_6.txt\n")
        self.write("docs/changelogs-txt/CHANGELOG_0_5_6.txt", "0.5.6\n")
        self.write("docs/UEBERGABEPROTOKOLL_0_5_6.txt", "0.5.6\n")

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, path, value):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(value, encoding="utf-8")

    def test_new_numeric_version_passes(self):
        self.assertEqual(mod.validate(self.root, "0.5.5"), "0.5.6")

    def test_unchanged_version_fails(self):
        with self.assertRaisesRegex(ValueError, "Every merge needs"):
            mod.validate(self.root, "0.5.6")

    def test_downgrade_fails(self):
        with self.assertRaisesRegex(ValueError, "Every merge needs"):
            mod.validate(self.root, "0.6.0")

    def test_suffixes_rejected(self):
        self.write("VERSION", "0.5.7-alpha")
        with self.assertRaisesRegex(ValueError, "no suffix"):
            mod.validate(self.root, "0.5.6")

    def test_stale_ui_version_fails(self):
        self.write("app.html", "CoreUI version 0.5.5")
        with self.assertRaisesRegex(ValueError, "app.html"):
            mod.validate(self.root, "0.5.5")

    def test_missing_per_version_handover_fails(self):
        (self.root / "docs/UEBERGABEPROTOKOLL_0_5_6.txt").unlink()
        with self.assertRaises(OSError):
            mod.validate(self.root, "0.5.5")

    def test_index_reference_required(self):
        self.write("docs/changelogs-txt/INDEX.txt", "wrong file v0.5.6")
        with self.assertRaisesRegex(ValueError, "changelog index"):
            mod.validate(self.root, "0.5.5")

    def test_changelog_heading_required(self):
        self.write("CHANGELOG.md", "Only an arbitrary 0.5.6 reference")
        with self.assertRaisesRegex(ValueError, "heading"):
            mod.validate(self.root, "0.5.5")


if __name__ == "__main__":
    unittest.main()
