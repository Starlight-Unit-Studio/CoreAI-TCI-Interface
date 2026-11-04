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


README = (
    "# CoreAI: TCI Interface\n\n"
    "**Aktueller Quellstand: `0.5.6`** | [CHANGELOG.md](CHANGELOG.md)\n\n"
    "## Quick start: install CoreAI\n\n"
    "## Updating CoreAI\n\n"
    "Use Settings > System > CoreAI Update after initial installation.\n\n"
    "curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/"
    "CoreAI-TCI-Interface/main/setup.sh\n"
)


class MergeVersionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.write("VERSION", "0.5.6\n")
        for p in mod.PAGES + mod.REQUIRED_ACTIVE:
            self.write(p, "CoreUI version 0.5.6\n")
        # Since 0.6.2 the README is checked as a guide: official one-liner,
        # CHANGELOG link, English Quick start, integrated updater, no release diary.
        self.write("README.md", README)
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

    def test_readme_needs_official_one_liner(self):
        self.write("README.md", README.replace("CoreAI-TCI-Interface/main", "coreui/main"))
        with self.assertRaisesRegex(ValueError, "one-liner"):
            mod.validate(self.root, "0.5.5")

    def test_readme_must_be_english_quick_start(self):
        self.write("README.md", README.replace("## Quick start: install CoreAI",
                                               "## Schnellstart: Installation"))
        with self.assertRaisesRegex(ValueError, "English Quick start"):
            mod.validate(self.root, "0.5.5")

    def test_readme_must_document_integrated_updater(self):
        self.write("README.md", README.replace("## Updating CoreAI", "## Updater missing"))
        with self.assertRaisesRegex(ValueError, "integrated update"):
            mod.validate(self.root, "0.5.5")

    def test_readme_must_identify_settings_updater(self):
        self.write("README.md", README.replace("CoreAI Update", "Update panel"))
        with self.assertRaisesRegex(ValueError, "Settings > System updater"):
            mod.validate(self.root, "0.5.5")

    def test_readme_is_no_changelog(self):
        self.write("README.md", README + "\n## Mara liest vor (0.5.6)\n\nNeu.\n")
        with self.assertRaisesRegex(ValueError, "per-version changelog"):
            mod.validate(self.root, "0.5.5")


if __name__ == "__main__":
    unittest.main()
