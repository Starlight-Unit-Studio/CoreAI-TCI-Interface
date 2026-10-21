#!/usr/bin/env python3
"""Mandatory offline CoreUI version increment gate before merging into main.

Usage: python3 scripts/version-merge-check.py --base-version 0.5.5
Provide the freshly fetched numeric VERSION from the target main branch.
This guard requires no Actions, GitHub API, git history or credentials.
"""
from __future__ import annotations

import argparse
import re
from pathlib import Path

TRIPLE = re.compile(r"[0-9]+\.[0-9]+\.[0-9]+\Z")
PAGES = ("index.html", "login.html", "app.html", "settings.html",
         "protocols.html", "admin/index.html")
REQUIRED_ACTIVE = ("README.md", "CHANGELOG.md",
                   "docs/changelogs-txt/INDEX.txt",
                   "js/console-gate.js",
                   "scripts/branding-license-selftest.py",
                   "scripts/preflight.sh", "scripts/preflight-native.sh")


def numeric(value: str) -> tuple[int, int, int]:
    if not TRIPLE.fullmatch(value):
        raise ValueError("Version must have three numbers and no suffix: " + repr(value))
    parts = tuple(map(int, value.split(".")))
    if not (0 <= parts[0] <= 999 and 0 <= parts[1] <= 9 and 0 <= parts[2] <= 99):
        raise ValueError("Version counters must stay within 0..999 / 0..9 / 0..99")
    return parts


def validate(root: Path, base: str) -> str:
    root = root.resolve()
    previous = numeric(base)
    version = (root / "VERSION").read_text(encoding="utf-8").strip()
    current = numeric(version)
    if current <= previous:
        raise ValueError(f"Every merge needs a new numeric version: main={base}, PR={version}")
    slug = version.replace(".", "_")
    current_log = f"docs/changelogs-txt/CHANGELOG_{slug}.txt"
    current_handover = f"docs/UEBERGABEPROTOKOLL_{slug}.txt"

    for path in PAGES + REQUIRED_ACTIVE + (current_log, current_handover):
        source = (root / path).read_text(encoding="utf-8")
        if version not in source and slug not in source:
            raise ValueError(f"Current version {version} missing in {path}")
    # The changelog must have a dedicated heading, not just a stray reference.
    changelog = (root / "CHANGELOG.md").read_text(encoding="utf-8")
    if f"## {version} " not in changelog:
        raise ValueError("Missing current version heading in CHANGELOG.md")
    if f"CHANGELOG_{slug}.txt" not in (root / "docs/changelogs-txt/INDEX.txt").read_text(encoding="utf-8"):
        raise ValueError("Missing current version from changelog index")
    return version


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-version", required=True, help="Numeric VERSION from current main")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    try:
        new_version = validate(args.root, args.base_version)
    except (ValueError, OSError) as exc:
        parser.exit(1, f"FAIL: merge version check: {exc}\n")
    print(f"PASS: merge version changed from {args.base_version} to {new_version}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
