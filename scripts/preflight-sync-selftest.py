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
    # A selected local Ollama Core must not be silently unloaded after 15m.
    # The common sync, SSE, and preload paths must share the numeric -1 policy.
    cfg = (root / "api/config.php").read_text(encoding="utf-8")
    chat = (root / "api/chat.php").read_text(encoding="utf-8")
    stream = (root / "api/console_stream.php").read_text(encoding="utf-8")
    preload = (root / "api/core_preload.php").read_text(encoding="utf-8")
    if "coreui_env('COREUI_EMBER_KEEP_ALIVE', '-1')" not in cfg:
        failures.append("api/config.php: active Core keep_alive default must be -1")
    if "function ember_keep_alive_value(" not in chat or (
        "return ember_keep_alive_value(ember_cfg('STU_EMBER_KEEP_ALIVE', -1));" not in chat
    ):
        failures.append("api/chat.php: keep_alive must preserve numeric Ollama -1")
    if "'keep_alive' => $keepAlive" not in chat:
        failures.append("api/chat.php: sync chat does not send keep_alive")
    if "$payload['keep_alive'] = $keepAlive" not in stream:
        failures.append("api/console_stream.php: SSE does not send keep_alive")
    if "$body['keep_alive'] = $keepAlive" not in preload:
        failures.append("api/core_preload.php: model preload does not send keep_alive")
    if "'keep_alive'=>0" not in preload:
        failures.append("api/core_preload.php: explicit old-Core unload must remain")

    # The pinned section must remain a visible, independently foldable
    # navigation destination immediately beneath the single Projects link.
    # This contract is checked on CPU-only installations without Node.js.
    page = (root / "app.html").read_text(encoding="utf-8")
    projects_index = page.find('id="projectsNav"')
    pinned_index = page.find('id="pinnedZone"')
    recent_index = page.find('id="sessionZoneTitle"')
    if min(projects_index, pinned_index, recent_index) < 0 or not (
        projects_index < pinned_index < recent_index
    ):
        failures.append("app.html: required Projekte / Angeheftet / Letzte sidebar order missing")
    if re.search(r'<section class="pinned-zone"[^>]*\bhidden\b', page):
        failures.append("app.html: Angeheftet section must not be permanently hidden")
    for item in ('id="sidebarPinnedProjects"', 'id="pinnedSessionList"',
                 'id="sidebarPinnedEmpty"', 'id="sidebarPinnedToggle"'):
        if page.count(item) != 1:
            failures.append(f"app.html: missing or duplicated pinned sidebar element {item}")
    chat_js = (root / "js/console-app.js").read_text(encoding="utf-8")
    project_js = (root / "js/coreui-projects.js").read_text(encoding="utf-8")
    nav_css = (root / "css/coreui-sidebar-refresh.css").read_text(encoding="utf-8")
    if "function syncPinnedSection()" not in chat_js or (
        "var pinnedChats = state.sessions.filter(" not in chat_js
    ):
        failures.append("js/console-app.js: all pinned chats and shared count must be rendered")
    if "function renderPinnedProjects()" not in project_js or (
        "renderPinnedProjects();" not in project_js
    ):
        failures.append("js/coreui-projects.js: pinned projects must appear below Projekte")
    if ".sidebar-section-folded > .sidebar-pinned-project-list" not in nav_css:
        failures.append("css/coreui-sidebar-refresh.css: pinned projects must fold with pinned chats")
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

    # A recoverable malformed PROJECT_BUILD/PLAN_APPEND reply must retain its
    # intended tool during ONE corrective model call, not restart at PLAN_SAVE.
    # Tool execution/permissions remain guarded by the native parser.
    native_tools = (root / "api/console_native_tools.php").read_text(encoding="utf-8")
    stream_code = (root / "api/console_stream.php").read_text(encoding="utf-8")
    chat_code = (root / "api/chat.php").read_text(encoding="utf-8")
    if "function coreui_native_tool_repair_example(" not in native_tools:
        failures.append("api/console_native_tools.php: targeted same-tool repair missing")
    if "'PROJECT_BUILD' =>" not in native_tools or (
        "'PLAN_APPEND' =>" not in native_tools
    ):
        failures.append("api/console_native_tools.php: project build/append repair contracts missing")
    if "false,180);" in stream_code:
        failures.append("api/console_stream.php: tool correction still uses fixed 180-second CPU timeout")
    if "false,null,false,false);" not in stream_code:
        failures.append("api/console_stream.php: lean configured-timeout correction missing")
    if "['__skills'=>'none'],null,false,false);" not in chat_code:
        failures.append("api/chat.php: lean synchronous correction missing")
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
