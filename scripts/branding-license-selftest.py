#!/usr/bin/env python3
"""Deterministic release checks for E.M.B.E.R. CoreUI branding and licensing."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
EXPECTED_VERSION = "0.5.39"
PUBLIC_PAGES = (
    "index.html",
    "login.html",
    "app.html",
    "settings.html",
    "protocols.html",
    "admin/index.html",
)
REQUIRED_FILES = (
    "LICENSE.de.md",
    "LICENSE.md",
    "LICENSE_HISTORY.md",
    "TRADEMARKS.md",
    "COMMUNITY_POLICY.md",
    "NOTICE.md",
    "COPYRIGHT.md",
    "docs/changelogs-txt/CHANGELOG_0_5_18.txt",
    "docs/UEBERGABEPROTOKOLL_0_5_18.txt",
)


def read(relative_path: str) -> str:
    path = ROOT / relative_path
    if not path.is_file() or path.stat().st_size == 0:
        raise AssertionError(f"required file missing or empty: {relative_path}")
    return path.read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> int:
    version = read("VERSION").strip()
    require(re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version) is not None,
            "VERSION must be numeric-only")
    require(version == EXPECTED_VERSION, "VERSION mismatch")

    for relative_path in REQUIRED_FILES:
        read(relative_path)

    german_license = read("LICENSE.de.md")
    policy = read("COMMUNITY_POLICY.md")
    trademarks = read("TRADEMARKS.md")
    require(
        "Starlight Unit Studios E.M.B.E.R. CoreUI Community Source Lizenz 1.0"
        in german_license,
        "controlling license name missing",
    )
    require("keine Nutzergrenze" in german_license, "no-user-limit rule missing")
    require("no paid license tier" in policy, "free community policy incomplete")
    require("no license phone-home" in policy, "no-telemetry policy incomplete")
    require("E.M.B.E.R. CoreUI" in trademarks, "trademark naming rule missing")

    for relative_path in PUBLIC_PAGES:
        page = read(relative_path)
        require("0.5.3-alpha" not in page, f"Outdated active version: {relative_path}")
        asset_keys = re.findall(
            r'(?:href|src)="(?:\./|\.\./)(?:css|js)/[^"]+\?v=([^"]+)"',
            page
        )
        require(bool(asset_keys), f"Asset cache keys missing: {relative_path}")
        require(all(re.fullmatch(r"(?:[0-9]+\.[0-9]+\.[0-9]+|[0-9]{25})", key)
                    for key in asset_keys), f"Cache key is not numeric: {relative_path}")
        require(
            re.search(
                r'<meta\s+name="application-name"\s+content="[^"]*E\.M\.B\.E\.R\. CoreUI[^"]*"',
                page,
                re.IGNORECASE,
            )
            is not None,
            f"E.M.B.E.R. CoreUI application-name missing: {relative_path}",
        )

    landing_page = read("index.html")
    structured_match = re.search(
        r'<script\s+type="application/ld\+json">\s*(\{.*?\})\s*</script>',
        landing_page,
        re.DOTALL,
    )
    require(structured_match is not None, "structured software metadata missing")
    structured_data = json.loads(structured_match.group(1))
    require(structured_data.get("@type") == "SoftwareApplication", "structured software type mismatch")
    require(structured_data.get("name") == "E.M.B.E.R. CoreUI", "structured product name mismatch")
    for installer in ("setup.sh", "scripts/install.sh", "scripts/install-native.sh"):
        source = read(installer)
        require("E.M.B.E.R. CoreUI" in source,
                f"official acronym missing from installer text: {installer}")
        require("Ember CoreUI" not in source,
                f"obsolete character-style product name in installer: {installer}")

    for current in PUBLIC_PAGES:
        require("Ember CoreUI" not in read(current),
                f"obsolete product branding in active page: {current}")
    model = read("config/Modelfile.template")
    require("E.M.B.E.R. CoreUI" in model and "CoreAI namens Ember" in model,
            "Modelfile conflates framework, terminal and Ember character")
    require("PARAMETER num_ctx" not in model and "PARAMETER num_predict" not in model,
            "Gemma Modelfile parameter restrictions regressed")
    require("PARAMETER draft_num_predict 0" in model,
            "approved Gemma draft parameter must remain untouched")
    require("VALUES (?, ?, 'Mara', NOW(), NOW())" in read("scripts/bootstrap.php"),
            "administrator bootstrap would incorrectly name CoreAI Ember")
    require("VALUES (?, ?, 'Mara', NOW(), NOW())" in read("api/account_store.php"),
            "new accounts would incorrectly name CoreAI Ember")
    for installer in ("scripts/install.sh", "scripts/install-native.sh"):
        require("STU_EMBER_CHARACTER_NAME', 'Mara'" in read(installer),
                f"installer would still initialize the wrong CoreAI name: {installer}")


    require(
        structured_data.get("publisher", {}).get("name") == "Starlight Unit Studios",
        "structured publisher mismatch",
    )
    require(structured_data.get("isAccessibleForFree") is True, "free-access metadata mismatch")

    settings = read("js/settings.js")
    profile_store = read("api/profile_store.php")
    require("E.M.B.E.R. COREUI-DATENBANK" in settings, "database label is not fully branded")
    require("--- MARA COREAI-PROFIL ---" in profile_store, "CoreAI profile marker must name Mara")
    require("--- COREUI-PROFIL ---" not in profile_store, "legacy profile marker remains")

    text_extensions = {
        ".css", ".html", ".js", ".json", ".md", ".php", ".py",
        ".sh", ".sql", ".txt", ".yaml", ".yml",
    }
    for path in ROOT.rglob("*"):
        if path.is_file() and path.suffix.lower() in text_extensions:
            content = path.read_text(encoding="utf-8")
            require("\u2014" not in content, f"Unicode em dash found: {path.relative_to(ROOT)}")

    print("E.M.B.E.R. CoreUI branding and license selftest: OK")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        print(f"E.M.B.E.R. CoreUI branding and license selftest: FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
