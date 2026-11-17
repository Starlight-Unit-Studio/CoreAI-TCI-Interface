#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""CoreAI 0.7.77 (P10 B): commands on project files in the sandbox (tools/ember_project_runs.py).

    python3 scripts/project-runs-worker-selftest.py

Offline: safe paths, the file digest, clipping, the docker line (the same walls as Mara's
Python), staging into a work directory, and the hook in tools/ember_py_worker.py.
With Docker and the image ember-py:1 present, real runs: passing and failing unittest with the
real output and exit code, a timeout, a cancel, no writes outside the work directory, and the
work directory removed afterwards. Without Docker those runs are skipped and named.
"""
import os
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.join(ROOT, "tools"))
import ember_project_runs as runs  # noqa: E402


def check(ok, why):
    if not ok:
        print("Project runs FAIL: " + why, file=sys.stderr)
        sys.exit(1)


# --- Paths, digest, clip (pure) ---------------------------------------------------------------
for good in ["main.py", "tests/test_game.py", "src/a b (1).py", ".env", "ü/datei.txt"]:
    check(runs.safe_relpath(good) == good, "safe path " + good)
for bad in ["", "/etc/passwd", "../x", "a/../b", "a//b", "a/./b", "a\\b", " a", "a/ b", "x\x00y", "a/" * 9 + "b", "a" * 201]:
    check(runs.safe_relpath(bad) is None, "unsafe path " + repr(bad))
d1 = runs.files_digest({"b.py": "2", "a.py": "1"})
check(d1 == runs.files_digest({"a.py": "1", "b.py": "2"}) and d1 != runs.files_digest({"a.py": "1", "b.py": "3"})
      and len(d1) == 64, "digest is stable and content-bound")
check(runs.clip("abc", 10) == "abc" and "[... gekuerzt ...]" in runs.clip("x" * 100, 20)
      and runs.clip("A" * 50 + "B" * 50, 20).endswith("B" * 12), "clip keeps start and end")

cfg = {"STU_EMBER_PY_IMAGE": "ember-py:1", "STU_EMBER_PY_MEMORY": "512m"}
line = runs.docker_command(cfg, "ember-run-1-x", "/var/w", "python -m unittest -v")
for flag in ["--read-only", "--cap-drop", "ALL", "no-new-privileges", "65534:65534", "--pids-limit", "--memory"]:
    check(flag in line, "docker line has " + flag)
check(line[-4:] == ["ember-py:1", "sh", "-c", "python -m unittest -v"] and "/var/w:/work:rw" in line,
      "docker line: image, sh -c and the work directory only: " + repr(line[-6:]))
check(not any(":/opt" in x or "docker.sock" in x or "config.local" in x for x in line), "no CoreAI mount, no socket")
check(runs.docker_command(dict(cfg, STU_EMBER_PROJECT_IMAGE="ember-dev:1"), "c", "/w", "true")[-4] == "ember-dev:1",
      "an own image when configured")

tmp = tempfile.mkdtemp()
try:
    n = runs.stage_files({"main.py": "print(1)\n", "pkg/mod.py": "x = 1\n"}, tmp)
    check(n == 2 and open(os.path.join(tmp, "pkg", "mod.py")).read() == "x = 1\n", "staging writes the tree")
    try:
        runs.stage_files({"../evil.py": "x"}, tmp)
        check(False, "staging refuses an unsafe path")
    except ValueError as exc:
        check(str(exc) == "unsafe_path", "unsafe path error")
finally:
    shutil.rmtree(tmp, ignore_errors=True)

worker = open(os.path.join(ROOT, "tools", "ember_py_worker.py"), encoding="utf-8").read()
check("import ember_project_runs" in worker and "ember_project_runs.claim_next_run(conn, os.getpid())" in worker
      and "ember_project_runs.process_run(conn, cfg, run, work_base(cfg), stop_container)" in worker
      and worker.index("job = claim_next_job(conn)") < worker.index("ember_project_runs.claim_next_run"),
      "the worker takes project runs after Python jobs")


# --- Real runs in the sandbox (Docker and ember-py:1) ------------------------------------------
def docker_ready():
    try:
        return subprocess.run(["docker", "image", "inspect", "ember-py:1"], stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, timeout=20).returncode == 0
    except Exception:
        return False


def stop(container):
    r = subprocess.run(["docker", "rm", "--force", container], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
    return r.returncode == 0 or b"No such container" in r.stderr


sandbox = "Sandbox-Laeufe uebersprungen (Docker oder ember-py:1 fehlt)"
if docker_ready():
    base = tempfile.mkdtemp(prefix="coreai-runs-")
    os.chmod(base, 0o777)
    project = {
        "game.py": "def add(a, b):\n    return a + b\n",
        "tests/__init__.py": "",
        "tests/test_game.py": "import unittest\nfrom game import add\n\nclass T(unittest.TestCase):\n"
                              "    def test_add(self):\n        self.assertEqual(add(2, 3), 5)\n",
    }
    cfg = {"STU_EMBER_PY_IMAGE": "ember-py:1", "STU_EMBER_PROJECT_RUN_TIMEOUT": "60"}
    ok = runs.run_command(cfg, 9001, project, "python -m unittest -v", base, stop=stop)
    check(ok["status"] == "done" and ok["exit_code"] == 0 and "test_add" in ok["stderr"] and "OK" in ok["stderr"]
          and ok["files_count"] == 3 and ok["files_sha256"] == runs.files_digest(project), "a passing test run: %r" % ok)
    broken = dict(project, **{"game.py": "def add(a, b):\n    return a - b\n"})
    bad = runs.run_command(cfg, 9002, broken, "python -m unittest -v", base, stop=stop)
    check(bad["status"] == "done" and bad["exit_code"] == 1 and "FAILED (failures=1)" in bad["stderr"]
          and "AssertionError: -1 != 5" in bad["stderr"], "a failing test with the real output: %r" % bad)
    walls = runs.run_command(cfg, 9003, project, "id -u; touch /etc/x 2>&1; echo hi > out.txt && cat out.txt; ls /work", base, stop=stop)
    check(walls["exit_code"] == 0 and walls["stdout"].splitlines()[0] == "65534" and "Read-only file system" in walls["stdout"]
          and "hi" in walls["stdout"], "unprivileged, read-only outside /work: %r" % walls)
    slow = runs.run_command(dict(cfg, STU_EMBER_PROJECT_RUN_TIMEOUT="2"), 9004, project, "echo start; sleep 30", base, stop=stop)
    check(slow["status"] == "error" and slow["error"] == "timeout" and slow["exit_code"] is None and slow["duration_ms"] < 15000,
          "a run that is too long ends: %r" % slow)
    t0 = time.monotonic()
    stopped = runs.run_command(cfg, 9005, project, "sleep 30", base, cancelled=lambda: time.monotonic() - t0 > 1.5, stop=stop)
    check(stopped["status"] == "cancelled" and stopped["duration_ms"] < 15000, "a cancelled run stops: %r" % stopped)
    check(os.listdir(base) == [], "work directories are removed: %r" % os.listdir(base))
    shutil.rmtree(base, ignore_errors=True)
    sandbox = "echte Laeufe in ember-py:1 (bestanden, fehlgeschlagen, Grenzen, Zeitlimit, Abbruch)"

print("CoreAI Projektlaeufe (Worker): Pfade, Pruefsumme, Kuerzen, Docker-Zeile, Bereitstellung, Anbindung OK; " + sandbox)
