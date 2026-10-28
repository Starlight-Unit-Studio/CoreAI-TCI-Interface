#!/usr/bin/env python3
"""Deterministic release checks for CoreAI: TCI Interface branding and licensing."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
EXPECTED_VERSION = "0.5.75"
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
    "docs/PRODUCT_NAMING.md",
    "docs/changelogs-txt/CHANGELOG_0_5_57.txt",
    "docs/UEBERGABEPROTOKOLL_0_5_57.txt",
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
    require("CoreAI: TCI Interface" in trademarks and "Quantum" in trademarks,
            "current product and family naming rule missing")

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
                r'<meta\s+name="application-name"\s+content="[^"]*CoreAI: TCI Interface[^"]*"',
                page,
                re.IGNORECASE,
            )
            is not None,
            f"CoreAI: TCI Interface application-name missing: {relative_path}",
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
    require(structured_data.get("name") == "CoreAI: TCI Interface", "structured product name mismatch")
    require(structured_data.get("isPartOf", {}).get("name") == "Quantum Software",
            "Quantum software family missing from structured data")
    require(structured_data.get("codeRepository") ==
            "https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface",
            "renamed GitHub repository is missing from structured data")
    require("CoreAI: TCI Interface" in read("docs/PRODUCT_NAMING.md"), "naming policy missing")
    require("Starlight-Unit-Studio/CoreAI-TCI-Interface" in read("setup.sh"),
            "setup still downloads the old repository")
    for installer in ("setup.sh", "scripts/install.sh", "scripts/install-native.sh"):
        source = read(installer)
        require("CoreAI: TCI Interface" in source,
                f"current product name missing from installer text: {installer}")
        require("Ember CoreUI" not in source,
                f"obsolete character-style product name in installer: {installer}")

    for current in PUBLIC_PAGES:
        require("Ember CoreUI" not in read(current) and "E.M.B.E.R. CoreUI" not in read(current),
                f"obsolete product branding in active page: {current}")
    # Public credit: verify the visible owner and full requested year range.
    for page_name in PUBLIC_PAGES:
        page = read(page_name)
        require("2023-2026" in page and "Patrick Schildgen" in page,
                f"owner copyright or year range missing: {page_name}")
    for credit_file in ("COPYRIGHT.md", "NOTICE.md", "LICENSE.de.md", "LICENSE.md"):
        require("2023-2026 Patrick Schildgen" in read(credit_file),
                f"copyright attribution not consistent: {credit_file}")
    settings_page = read("settings.html")
    require('<span class="about-family">QUANTUM SOFTWARE</span>' in settings_page,
            "Quantum family line missing in settings About")
    require('<span class="about-product">COREAI: TCI INTERFACE</span>' in settings_page,
            "separate CoreAI product line missing in settings About")
    require("PROJECT ST" not in settings_page and "Project ST" not in read("app.html"),
            "obsolete Project Starlight product label still visible")
    style = read("css/console.css")
    require(".about-copy .copyright-line" in style and "font-size: clamp(14px" in style,
            "readable copyright style missing")

    model = read("config/Modelfile.template")
    # 0.5.66: the owner renamed the runtime line in the Modelfile to the
    # current product; the Mara identity stays mandatory.
    require("Mara" in model and "CoreAI: TCI Interface" in model,
            "Modelfile must retain Mara identity and the CoreAI: TCI Interface runtime")
    require("FROM gemma4:e4b" in model and "RENDERER gemma4" in model
            and "PARSER gemma4" in model,
            "Modelfile Gemma base and parser/renderer contract missing")
    # Sampling parameters belong to the operator's active template.
    # Numeric validation and installed-file matching live in the PHP selftest.
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
    require("COREAI: TCI INTERFACE-DATENBANK" in settings, "database label is not branded with the current product name")
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

    print("CoreAI: TCI Interface branding and license selftest: OK")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        print(f"CoreAI: TCI Interface branding and license selftest: FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
