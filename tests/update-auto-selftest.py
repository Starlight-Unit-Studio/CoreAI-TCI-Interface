#!/usr/bin/env python3
"""Offline auto-update scheduling and Preflight classification regression.
No network, systemd, Docker, account, Ollama or real CoreAI update.
"""
import importlib.util
import io
import json
import tempfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("coreai_update_state", ROOT / "scripts/lib/coreai_update_state.py")
state = importlib.util.module_from_spec(spec)
spec.loader.exec_module(state)
A, B, C = "a" * 40, "b" * 40, "c" * 40


def put(path, payload):
    path.write_text(json.dumps(payload), encoding="utf-8")


def check(ok, name):
    if not ok:
        raise AssertionError(name)


with tempfile.TemporaryDirectory(prefix="coreai-update-auto-test-") as tmp:
    root = Path(tmp)
    policy = root / "auto.json"
    available = root / "available.json"
    status = root / "status.json"
    request = root / "request.json"
    attempts = root / "auto-attempt.json"
    put(available, {"latest_commit": B, "error": ""})
    paths = [str(x) for x in (policy, available, status, request, attempts)]
    t = 1_900_000_000

    def queue(now, local=A):
        with patch.object(state.time, "time", return_value=now):
            return state.cmd_auto_queue(*paths, local)

    check(queue(t) == 0 and not request.exists(), "automatic updates disabled by default")
    put(policy, {"enabled": True, "requested_by": 7, "restart_ollama": False, "snooze_until": t + 3600})
    check(queue(t + 20) == 0 and not request.exists(), "one-hour Later pause is server-enforced")
    check(queue(t + 3600) == 0 and not request.exists(), "first eligible check records observation, no auto-install yet")
    check(queue(t + 3600 + 599) == 0 and not request.exists(), "grace period prevents premature auto-install")
    check(queue(t + 3600 + 600) == 0 and request.exists(), "post-grace auto-update queued")
    data = json.loads(request.read_text())
    check(data["automatic"] is True and data["requested_by"] == 7
          and data["restart_ollama"] is False and data["action"] == "update",
          "official updater request with strict auto provenance")
    request.unlink()
    check(queue(t + 7200) == 0 and not request.exists(), "one attempt maximum per commit")
    put(available, {"latest_commit": C, "error": ""})
    put(status, {"state": "failed", "attempted_commit": C})
    check(queue(t + 9000) == 0 and not request.exists(), "failed commit cannot retry itself")
    put(status, {"state": "failed", "attempted_commit": B})
    check(queue(t + 9000) == 0 and not request.exists(), "new commit receives its own observation grace")
    put(request, {"action": "update", "requested_by": 9, "restart_ollama": True})
    check(queue(t + 9700) == 0 and json.loads(request.read_text())["requested_by"] == 9,
          "manual queued request has priority, never overwritten")
    request.unlink()
    check(queue(t + 9701) == 0 and request.exists()
          and json.loads(request.read_text())["automatic"] is True,
          "new fixed commit may automatically update after grace")
    request.unlink()

    put(policy, {"enabled": False, "requested_by": 7, "snooze_until": 0})
    check(queue(t + 11000) == 0 and not request.exists(), "owner disable stops auto-update")
    policy.unlink()
    policy.symlink_to(available)
    check(queue(t + 12000) == 0 and not request.exists(), "symlink policy rejected")
    policy.unlink()

    # The progress follower retains the installer output and tags a real
    # preflight failure independently of a generic install/build error.
    put(status, {"state": "running", "phase": "install", "percent": 79})
    lines = b"Compose-Preflight, Version 0.6.93\n[OK] Core-Status\nPreflight fehlgeschlagen: 1 Problem(e).\n"
    stdin = SimpleNamespace(buffer=io.BytesIO(lines))
    stdout = SimpleNamespace(buffer=io.BytesIO())
    with patch.object(state.sys, "stdin", stdin), patch.object(state.sys, "stdout", stdout):
        check(state.cmd_follow(str(status)) == 0, "follower accepts installer stream")
    check(stdout.buffer.getvalue() == lines, "follower passes update log unchanged")
    check(json.loads(status.read_text()).get("failure_kind") == "preflight",
          "Preflight failure has its own machine-readable kind")
    put(status, {"state": "running", "phase": "install", "failure_kind": ""})
    stdin = SimpleNamespace(buffer=io.BytesIO(b"Die Installation fehlgeschlagen: Docker-Image\n"))
    with patch.object(state.sys, "stdin", stdin), patch.object(state.sys, "stdout", SimpleNamespace(buffer=io.BytesIO())):
        state.cmd_follow(str(status))
    check(json.loads(status.read_text()).get("failure_kind") != "preflight",
          "generic install failure cannot masquerade as a Preflight error")
print("PASS: owner opt-in, 60-min snooze, 10-min grace, once/commit, manual priority, symlink guard, Preflight log flag")
