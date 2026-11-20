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
    "scripts/task-journal-selftest.php",
    "scripts/project-files-selftest.php",
    "scripts/chat-workspace-selftest.php",
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

    # An already populated project must never be regenerated from a stale
    # earlier plan when the operator asks for a new page or modification.
    build_intent = (root / "api/console_build_intent.php").read_text(encoding="utf-8")
    file_tools = (root / "api/project_file_tools.php").read_text(encoding="utf-8")
    if "return ['status'=>'incremental_project','tool'=>null];" not in build_intent:
        failures.append("api/console_build_intent.php: old plan rebuild still captures existing projects")
    if "Der alte, abgeschlossene Projektplan ist nur historischer Kontext" not in file_tools:
        failures.append("api/project_file_tools.php: existing project changes must honor current user request")

    # A failed exact-match edit is never an endless autonomous retry.
    # Protect the existing unrestricted successful project workflow, while
    # requiring a verified reread and a same-file failure circuit breaker.
    loop_code = (root / "api/console_agent_loop.php").read_text(encoding="utf-8")
    loop_selftest = (root / "scripts/agent-loop-selftest.php").read_text(encoding="utf-8")
    for token in ("function coreui_agent_edit_failure_reason(",
                  "same_file_edit_rejected_twice",
                  "'recovery_read'=>true",
                  "file_edit_not_applicable",
                  "file_reread_failed"):
        if token not in loop_code:
            failures.append("api/console_agent_loop.php: failed-edit recovery contract missing: " + token)
    if "COREUI_AGENT_MAX_STEPS" not in loop_code or "$maxSteps = $projectWorkflow ? 0" not in loop_code:
        failures.append("api/console_agent_loop.php: confirmed project work must remain unbounded")
    for token in ("changed patch body cannot bypass no-progress guard",
                  "a valid second patch completes normally",
                  "a storage failure stops immediately"):
        if token not in loop_selftest:
            failures.append("scripts/agent-loop-selftest.php: missing repeated-edit regression: " + token)

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
    # Every normal chat may open the real development environment without
    # becoming a visible Project. This guard runs even without Node.js.
    app_html = (root / "app.html").read_text(encoding="utf-8")
    modal_js = (root / "js/coreui-chat-dev.js").read_text(encoding="utf-8")
    insights_js = (root / "js/coreui-insights.js").read_text(encoding="utf-8")
    store_js = (root / "api/console_project_store.php").read_text(encoding="utf-8")
    tool_js = (root / "api/project_file_tools.php").read_text(encoding="utf-8")
    endpoint = (root / "api/console_sessions.php").read_text(encoding="utf-8")
    migration = (root / "database/migrations/056_chat_workspaces.sql").read_text(encoding="utf-8")
    for item in ('id="chatDevModal"', 'id="chatDevHost"', 'id="projectFilesPanel"'):
        if app_html.count(item) != 1:
            failures.append(f"app.html: missing or duplicated shared dev modal element {item}")
    if ("host.appendChild(panel)" not in modal_js
        or "marker.parentNode.insertBefore(panel,marker.nextSibling)" not in modal_js
        or "app.ensureDevWorkspace()" not in modal_js):
        failures.append("js/coreui-chat-dev.js: real editor/preview not reused for chat modal")
    if "root.CoreAIChatDev.open(dev)" not in insights_js:
        failures.append("js/coreui-insights.js: Entwicklung chat tab not connected")
    if ("workspace_session_id IS NULL" not in store_js
        or "coreui_chat_workspace_ensure(" not in store_js
        or "ON DELETE CASCADE" not in migration):
        failures.append("chat workspace: private backing store or deletion lifecycle missing")
    if ("coreui_chat_workspace_id(" not in tool_js
        or "In diesem Chat gibt es sie nicht" in tool_js):
        failures.append("api/project_file_tools.php: ordinary chat remains blocked from developer tools")
    if ("'workspace_ensure'" not in endpoint or "stu_require_csrf()" not in endpoint):
        failures.append("api/console_sessions.php: workspace creation lacks authenticated CSRF endpoint")
    if "workspace_ensure" not in (root / "js/console-app.js").read_text(encoding="utf-8"):
        failures.append("js/console-app.js: authenticated workspace request missing")
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
