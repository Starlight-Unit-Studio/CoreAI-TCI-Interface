#!/usr/bin/env python3
"""Offline guard: keep both shipping Preflights wired to actual regression tests.

This check can run in CI without Docker, Ollama, MariaDB or production data.
Passing it is NOT a substitute for running the real preflight on each host.
"""
from __future__ import annotations

import argparse
from pathlib import Path

PREFLIGHTS = ("scripts/preflight.sh", "scripts/preflight-native.sh")
# Safety-critical cross-mode contracts. The two plan tests caught the 0.7.94
# release regression: obsolete 12k rejection assertions after lifting that cap.
REQUIRED_TESTS = (
    "scripts/model-project-intent-selftest.php",
    "scripts/contextual-project-selftest.php",
    "scripts/native-tools-selftest.php",
    "scripts/plan-autocontinue-selftest.php",
    "scripts/agent-loop-selftest.php",
    "scripts/project-files-selftest.php",
    "scripts/project-env-selftest.php",
)
RULE_FILES = ("AGENTS.md", "CLAUDE.md", "docs/VERSIONING_POLICY.md")


def verify(root: Path) -> list[str]:
    failures: list[str] = []
    for rel in RULE_FILES:
        path = root / rel
        if not path.is_file() or "PREFLIGHT_SYNC_GATE" not in path.read_text(encoding="utf-8"):
            failures.append(f"{rel}: mandatory preflight rule missing")
    for rel in PREFLIGHTS:
        path = root / rel
        if not path.is_file():
            failures.append(f"{rel}: missing")
            continue
        body = path.read_text(encoding="utf-8")
        if "PREFLIGHT_SYNC_GATE" not in body:
            failures.append(f"{rel}: release preflight sync invocation missing")
        if 'scripts/preflight-sync-selftest.py' not in body:
            failures.append(f"{rel}: preflight contract runner not called")
        for test in REQUIRED_TESTS:
            if not (root / test).is_file():
                failures.append(f"{test}: missing")
            # One reference in the UI manifest and one real execution site.
            if body.count(test) < 2:
                failures.append(f"{rel}: {test} not registered AND executed")
    for rel in ("scripts/model-project-intent-selftest.php",
                "scripts/contextual-project-selftest.php"):
        text = (root / rel).read_text(encoding="utf-8")
        if "END_OF_APPROVED_PLAN_" not in text or "str_contains(" not in text:
            failures.append(f"{rel}: full-length approved plan regression missing")
        if "Oversized plans must not be silently truncated" in text or (
                "Oversized saved plan was silently truncated" in text):
            failures.append(f"{rel}: obsolete 12000-byte rejection expectation")
    return failures


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    errors = verify(args.root.resolve())
    if errors:
        for item in errors:
            print("[FAIL] " + item)
        return 1
    print("Preflight sync gate: both variants, required tests and full-plan contracts OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
