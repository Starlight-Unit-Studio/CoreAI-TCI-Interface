#!/usr/bin/env python3
"""Guard user-facing installer and preflight terminal diagnostics against German regressions.

Only operator-visible output statements are checked. Comments, translated
application UI, model responses, license text and audit data are intentionally
not inspected. This test never installs, removes or changes system services.
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCRIPTS = (
    "setup.sh",
    "scripts/install.sh",
    "scripts/install-native.sh",
    "scripts/preflight.sh",
    "scripts/preflight-native.sh",
    "scripts/coreai-updater.sh",
    "scripts/update-from-main.sh",
    "scripts/stack.sh",
    "scripts/migrate.sh",
    "scripts/uninstall.sh",
    "scripts/swap.sh",
    "scripts/lib/host-swap.sh",
    "scripts/ollama-parallel-report.sh",
    "docker/pyworker/entrypoint.sh",
)
OUTPUT = re.compile(r"\b(?:echo|printf|log|warn|die|fail|ok|coreui_swap_warn)\b")
GERMAN = re.compile(
    r"[äöüÄÖÜß]"
    r"|\b(?:FEHLER|WARNUNG|Bitte|Konnte|konnte|Ungueltig\w*|fehlgeschlagen"
    r"|geprueft|Pruefung|pruefen|fuer|fehlt|ausfuehren|geloescht"
    r"|nicht|Keine|Kein|einen|wurde|wird|werden|bereits|vorhanden"
    r"|gesichert|aufbewahrt|geoeffnet|geschuetzt|Bestaetigung\w*"
    r"|Dateisystem|Schreibpfad|Abbruch|Deinstallation|Zusaetzliche)\b",
    re.IGNORECASE,
)
failures: list[str] = []
for source in SCRIPTS:
    path = ROOT / source
    if not path.is_file():
        failures.append(f"{source}: required script is missing")
        continue
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if line.lstrip().startswith("#"):
            continue
        if OUTPUT.search(line) and GERMAN.search(line):
            failures.append(f"{source}:{number}: {line.strip()[:170]}")

if failures:
    print("English terminal diagnostics regression:", file=sys.stderr)
    for failure in failures:
        print(" - " + failure, file=sys.stderr)
    sys.exit(1)
print("CoreAI installer and preflight terminal diagnostics: English output verified")
