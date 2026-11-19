#!/usr/bin/env python3
"""Offline guard: keep both shipping Preflights wired to actual regression tests.

This check can run in CI without Docker, Ollama, MariaDB or production data.
Passing it is NOT a substitute for running the real preflight on each host.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import re

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
        if 'scripts/terminal-language-selftest.py' not in body or 'terminal_language_report=' not in body:
            failures.append(f"{rel}: English-language terminal diagnostic guard not called")
        for test in REQUIRED_TESTS:
            if not (root / test).is_file():
                failures.append(f"{test}: missing")
            # One reference in the UI manifest and one real execution site.
            if body.count(test) < 2:
                failures.append(f"{rel}: {test} not registered AND executed")
    # A project schema change must also reach the SQLite fixtures run by both
    # host preflights. The missing description column in 0.8.1 made eight
    # independent checks fail before they could exercise their target.
    project_store = (root / "api/console_project_store.php").read_text(encoding="utf-8")
    declared = re.search(
        r"SELECT\s+([a-z_,\s]+)\s+FROM stu_console_projects LIMIT 0",
        project_store, re.IGNORECASE,
    )
    if declared is None:
        failures.append("api/console_project_store.php: project readiness query missing")
    else:
        project_columns = set(re.findall(r"[a-z_]+", declared.group(1)))
        fixture_names = (
            "project-file-tools", "chat-branch", "project-runs", "project-procs",
            "project-merge", "project-vcs", "skill-packages", "skill-sharing", "skill-revisions",
        )
        for name in fixture_names:
            rel = f"scripts/{name}-selftest.php"
            body = (root / rel).read_text(encoding="utf-8")
            definitions = re.findall(
                r"CREATE TABLE stu_console_projects\s*\((.*?)\)",
                body, re.IGNORECASE | re.DOTALL,
            )
            if not definitions:
                failures.append(f"{rel}: project fixture not found")
                continue
            for index, definition in enumerate(definitions, 1):
                fixture_columns = set(re.findall(
                    r"(?:^|,)\s*([a-z_]+)\s+[a-z_]+", definition, re.IGNORECASE,
                ))
                missing = sorted(project_columns - fixture_columns)
                if missing:
                    failures.append(f"{rel}: SQLite project fixture {index} missing {', '.join(missing)}")

    # Autonomous projects must not finish at Markdown just because a later
    # turn contains only "Continue". Validate BOTH dispatch routes and the
    # account-owned original request lookup on every source change.
    native = (root / "api/console_native_tools.php").read_text(encoding="utf-8")
    origin = (root / "api/console_plan_store.php").read_text(encoding="utf-8")
    intent = (root / "api/console_build_intent.php").read_text(encoding="utf-8")
    if "coreui_build_intent_select_for_plan(" not in native:
        failures.append("api/console_native_tools.php: plan-to-build handoff missing")
    if "in_array($kind,['PLAN_SAVE','PLAN_APPEND'],true)\n          && $planGenerate" in native:
        failures.append("api/console_native_tools.php: PLAN_CONTINUE is excluded from approved build")
    if "function coreui_plan_origin_request(" not in origin:
        failures.append("api/console_plan_store.php: saved original intent recovery missing")
    if "function coreui_build_intent_select_for_plan(" not in intent:
        failures.append("api/console_build_intent.php: owner-bound plan intent routing missing")
    for path in ("api/chat.php", "api/console_stream.php"):
        body = (root / path).read_text(encoding="utf-8")
        if "coreui_build_intent_select_for_plan(" not in body:
            failures.append(f"{path}: returning completed plan must retain original authorization")

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
