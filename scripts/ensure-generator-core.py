#!/usr/bin/env python3
"""Idempotent host-side Ollama provisioning of the optional FLUX.2 TCI generator.

Runs from both native and Compose installers, including source-based updates.
Only an explicit non-commercial license acknowledgement permits downloading a
missing 9B base. An already installed base can be adapted automatically.
Preflight remains read-only; this script is invoked by installers, not by checks.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
GENERATOR = "ember-coreui:gen"
BASE = "fegdxus/flux2-klein-9b-uncensored:Q8_0"
LICENSE = "https://github.com/black-forest-labs/flux2/blob/main/model_licenses/LICENSE-FLUX-NON-COMMERICAL"
STATE = ROOT / "var" / "generator-core.json"
LICENSE_PATH = ROOT / "licenses" / "FLUX-NON-COMMERCIAL-v2.1.txt"
LICENSE_RECEIPT = ROOT / "var" / "cache" / "generator-license.json"


def enabled(name: str) -> bool:
    return os.environ.get(name, "").lower() in {"1", "true", "yes", "on"}


def ollama(*args: str, quiet: bool = False) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["ollama", *args], text=True, check=False,
                          stdout=subprocess.DEVNULL if quiet else None,
                          stderr=subprocess.DEVNULL if quiet else None)


def present(name: str) -> bool:
    return ollama("show", name, quiet=True).returncode == 0


def persona_from_template(template: str) -> str:
    match = re.search(r'^SYSTEM[ \t]+"""(.*?)^"""[ \t]*$', template,
                      flags=re.MULTILINE | re.DOTALL)
    if not match or not match.group(1).strip():
        raise ValueError("SYSTEM fehlt im kanonischen Modelfile")
    system = match.group(1).strip()
    if '"""' in system:
        raise ValueError("SYSTEM enthaelt ungueltige Anfuehrungszeichen")
    return system


def modelfile(system: str) -> str:
    return f'FROM {BASE}\nSYSTEM """{system}"""\n'


def state_hash(system: str) -> str:
    return hashlib.sha256((GENERATOR + "\n" + BASE + "\n" + system).encode("utf-8")).hexdigest()


def read_state() -> dict:
    if STATE.is_symlink():
        raise ValueError("Generator-Zustandsdatei ist ein Symlink")
    try:
        value = json.loads(STATE.read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except FileNotFoundError:
        return {}
    except (OSError, json.JSONDecodeError):
        return {}


def save_state(digest: str) -> None:
    STATE.parent.mkdir(parents=True, exist_ok=True)
    if STATE.is_symlink():
        raise ValueError("Generator-Zustandsdatei ist ein Symlink")
    fd, temp = tempfile.mkstemp(prefix=".generator-core-", dir=str(STATE.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as out:
            json.dump({"model": GENERATOR, "source": BASE, "system_sha256": digest}, out)
            out.write("\n")
        os.chmod(temp, 0o640)
        os.replace(temp, STATE)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def license_accepted() -> bool:
    if LICENSE_PATH.is_symlink() or not LICENSE_PATH.is_file():
        return False
    if LICENSE_RECEIPT.is_symlink() or not LICENSE_RECEIPT.is_file():
        return False
    try:
        accepted = json.loads(LICENSE_RECEIPT.read_text(encoding="utf-8"))
        expected = hashlib.sha256(LICENSE_PATH.read_bytes()).hexdigest()
    except (OSError, ValueError, json.JSONDecodeError):
        return False
    return (isinstance(accepted, dict) and accepted.get("accepted") is True
            and accepted.get("license_sha256") == expected)


def ensure() -> int:
    if not license_accepted():
        print("[CoreAI Generator] Lizenz nicht akzeptiert: erst den Lizenzdialog im Admin-Core öffnen.")
        return 0
    template = (ROOT / "config" / "Modelfile.template").read_text(encoding="utf-8")
    system = persona_from_template(template)
    digest = state_hash(system)
    current = read_state()
    exists = present(GENERATOR)
    source_preexisting = present(BASE)

    if exists and current.get("model") == GENERATOR and current.get("system_sha256") == digest:
        if source_preexisting and enabled("COREUI_GENERATOR_REMOVE_EXISTING_BASE"):
            if ollama("rm", BASE).returncode != 0:
                print("[CoreAI Generator] Vorhandenen Basistag konnte ich nicht entfernen.", file=sys.stderr)
                return 1
            print("[CoreAI Generator] Betreiberfreigabe: bereits vorhandenen Basistag entfernt.")
        print("[CoreAI Generator] ember-coreui:gen bereits aktuell.")
        return 0

    # Do not overwrite a preexisting Core that our installer never created.
    if exists and current.get("model") != GENERATOR:
        print("[CoreAI Generator] Vorhandener ember-coreui:gen gehoert nicht zum Installer; unveraendert gelassen.")
        return 0

    if not source_preexisting:
        print("[CoreAI Generator] Lade temporaere FLUX.2 9B-Basis.")
        if ollama("pull", BASE).returncode != 0:
            print("[CoreAI Generator] Download fehlgeschlagen. Kein fremdes Modell entfernt.", file=sys.stderr)
            return 1

    fd, file = tempfile.mkstemp(prefix="coreai-gen-", suffix=".Modelfile")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(modelfile(system))
        os.chmod(file, 0o600)
        print("[CoreAI Generator] Erzeuge " + GENERATOR + " mit Maras bestehender Persona.")
        if ollama("create", GENERATOR, "-f", file).returncode != 0 or not present(GENERATOR):
            print("[CoreAI Generator] Erstellung/Pruefung fehlgeschlagen, Basistag bleibt erhalten.", file=sys.stderr)
            return 1
    finally:
        os.unlink(file)

    save_state(digest)
    if not source_preexisting or enabled("COREUI_GENERATOR_REMOVE_EXISTING_BASE"):
        if ollama("rm", BASE).returncode != 0:
            print("[CoreAI Generator] Warnung: temporaeren Basistag konnte ich nicht entfernen.", file=sys.stderr)
        else:
            print("[CoreAI Generator] Basistag nach erfolgreicher Verifikation entfernt; Gewichte bleiben referenziert.")
    else:
        print("[CoreAI Generator] Vorher vorhandenen FLUX.2-Basistag zum Schutz anderer Ollama-Clients behalten.")
    return 0


def check() -> int:
    if not license_accepted():
        print("FLUX.2-Lizenz noch nicht akzeptiert. Generator erst nach Freigabe installierbar.")
        return 3
    if present(GENERATOR):
        print("TCI Core Generator in Ollama vorhanden: " + GENERATOR)
        return 0
    if read_state() or present(BASE) or license_accepted():
        print("TCI Core Generator fehlt; Installer/Update kann den fehlenden Core anlegen.")
        return 2
    print("TCI Core Generator wird beim naechsten Installer-/Update-Lauf angelegt.")
    return 3


def main() -> int:
    parser = argparse.ArgumentParser(description="Install/verify CoreAI FLUX generator alias")
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument("--ensure", action="store_true", help="Idempotent install or update")
    actions.add_argument("--check", action="store_true", help="Read-only preflight verification")
    args = parser.parse_args()
    try:
        return check() if args.check else ensure()
    except (OSError, ValueError) as error:
        print("[CoreAI Generator] " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
