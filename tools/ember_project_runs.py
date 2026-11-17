# -*- coding: utf-8 -*-
"""
CoreAI 0.7.77 (P10 B): a command on the saved files of a project, in the sandbox.

The Python worker (tools/ember_py_worker.py) claims a queued row of stu_console_project_runs
when it has no Python job. The project's files are written into a fresh work directory, and
the command runs there with "sh -c" in the same throw-away container as Mara's Python: no
mount of CoreAI, no host services, read-only root file system, no capabilities, an unprivileged
user and hard limits for memory, CPU, processes and time. Files the command changes stay in the
container: the project in the database is never written from here.

stdout, stderr, the exit code and the duration go back into the row. A cancel request ends the
container within about a second.
"""

import hashlib
import os
import posixpath
import re
import secrets
import shutil
import subprocess
import tempfile
import time

RUN_TABLE = "stu_console_project_runs"
_PART = re.compile(r"^[\w.][\w. ()+-]{0,79}$", re.UNICODE)


def safe_relpath(path):
    """A project path as the PHP side stores it, or None (no absolute paths, no "..", no
    empty or hidden-trick parts). The database is trusted to hold checked paths; this is a
    second wall before anything touches the disk."""
    if not isinstance(path, str) or not path or len(path) > 200 or "\x00" in path or "\\" in path:
        return None
    if path.startswith("/") or path != posixpath.normpath(path):
        return None
    parts = path.split("/")
    if len(parts) > 8:
        return None
    for part in parts:
        if part in ("", ".", "..") or part != part.strip() or not _PART.match(part):
            return None
    return path


def files_digest(files):
    """One sha256 over path and content of every file, sorted by path (pure)."""
    h = hashlib.sha256()
    for path in sorted(files):
        h.update(path.encode("utf-8") + b"\x00")
        h.update(hashlib.sha256(files[path].encode("utf-8")).hexdigest().encode("ascii") + b"\n")
    return h.hexdigest()


def stage_files(files, workdir):
    """Write the project into workdir (pure apart from the disk). Returns the count."""
    count = 0
    for path, content in files.items():
        rel = safe_relpath(path)
        if rel is None:
            raise ValueError("unsafe_path")
        target = os.path.join(workdir, *rel.split("/"))
        os.makedirs(os.path.dirname(target), mode=0o777, exist_ok=True)
        with open(target, "w", encoding="utf-8", newline="") as fh:
            fh.write(content)
        os.chmod(target, 0o666)
        count += 1
    for root, dirs, _ in os.walk(workdir):
        for name in dirs:
            os.chmod(os.path.join(root, name), 0o777)
    return count


def clip(text, limit):
    """Keep the start and the end of a long output (pure)."""
    text = text or ""
    if len(text) <= limit:
        return text
    head = int(limit * 0.4)
    return text[:head] + "\n[... gekuerzt ...]\n" + text[-(limit - head):]


def docker_command(cfg, container, workdir, command):
    """The docker run line (pure): the same walls as Mara's Python."""
    return [
        "docker", "run", "--rm", "--name", container,
        "--network", "bridge",
        "--memory", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
        "--memory-swap", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
        "--cpus", str(cfg.get("STU_EMBER_PY_CPUS", "1.0")),
        "--pids-limit", str(cfg.get("STU_EMBER_PY_PIDS", "128")),
        "--cap-drop", "ALL", "--security-opt", "no-new-privileges", "--read-only",
        "--tmpfs", "/tmp:rw,noexec,nosuid,size=%s" % cfg.get("STU_EMBER_PY_TMPFS_SIZE", "64m"),
        "--user", "65534:65534",
        "-v", "%s:/work:rw" % workdir,
        "-w", "/work", "-e", "HOME=/tmp", "-e", "PYTHONUNBUFFERED=1", "-e", "PYTHONDONTWRITEBYTECODE=1",
        "-e", "MPLBACKEND=Agg",
        str(cfg.get("STU_EMBER_PROJECT_IMAGE") or cfg.get("STU_EMBER_PY_IMAGE", "ember-py:1")),
        "sh", "-c", command,
    ]


def run_command(cfg, run_id, files, command, base, cancelled=lambda: False, stop=None):
    """Stage, run, collect. Returns a dict for finish_run. `cancelled` is asked about once a
    second; `stop(container)` ends a container (tools/ember_py_worker.py stop_container)."""
    timeout = int(float(cfg.get("STU_EMBER_PROJECT_RUN_TIMEOUT", "120")))
    limit = int(cfg.get("STU_EMBER_PY_STORE_CHARS", "16000"))
    os.makedirs(base, exist_ok=True)
    root = tempfile.mkdtemp(prefix="run%d_" % int(run_id), dir=base)
    workdir = os.path.join(root, "work")
    os.mkdir(workdir, 0o777)
    os.chmod(workdir, 0o777)
    container = "ember-run-%d-%s" % (int(run_id), secrets.token_hex(6))
    result = {"status": "error", "stdout": "", "stderr": "", "exit_code": None, "duration_ms": 0,
              "error": None, "files_count": 0, "files_sha256": files_digest(files)}
    stopped = True
    try:
        result["files_count"] = stage_files(files, workdir)
        started = time.monotonic()
        stopped = False
        try:
            proc = subprocess.Popen(docker_command(cfg, container, workdir, command),
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        except FileNotFoundError:
            stopped = True
            result["error"] = "docker_missing"
            return result
        reason = None
        while True:
            try:
                out, err = proc.communicate(timeout=1.0)
                break
            except subprocess.TimeoutExpired:
                if time.monotonic() - started > timeout:
                    reason = "timeout"
                elif cancelled():
                    reason = "cancelled"
                if reason:
                    if stop is not None:
                        stop(container)
                    try:
                        out, err = proc.communicate(timeout=20)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                        out, err = proc.communicate()
                    break
        result["duration_ms"] = int((time.monotonic() - started) * 1000)
        result["stdout"] = clip((out or b"").decode("utf-8", "replace"), limit)
        result["stderr"] = clip((err or b"").decode("utf-8", "replace"), limit)
        stopped = stop(container) if stop is not None else True
        if reason == "cancelled":
            result["status"] = "cancelled"
            result["error"] = "cancelled"
        elif reason == "timeout":
            result["error"] = "timeout"
        else:
            rc = proc.returncode
            if rc is not None and 0 <= rc < 125:
                result["exit_code"] = rc
                result["status"] = "done"
            else:
                result["error"] = "docker_runtime_error"
        if not stopped:
            result["status"] = "error"
            result["error"] = "sandbox_stop_unconfirmed"
        return result
    except ValueError as exc:
        result["error"] = str(exc)
        return result
    finally:
        if stopped:
            shutil.rmtree(root, ignore_errors=True)


# ----------------------------------------------------------------------------- database

def table_ready(conn):
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id, cancel_requested_at FROM %s LIMIT 0" % RUN_TABLE)
        return True
    except Exception:
        return False


def claim_next_run(conn, pid):
    """Atomically one queued run to running, or None."""
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM %s WHERE status='queued' ORDER BY id ASC LIMIT 1" % RUN_TABLE)
        row = cur.fetchone()
        if not row:
            return None
        cur.execute("UPDATE %s SET status='running', started_at=NOW(), worker_pid=%%s "
                    "WHERE id=%%s AND status='queued'" % RUN_TABLE, (pid, row["id"]))
        if cur.rowcount != 1:
            return None
        cur.execute("SELECT * FROM %s WHERE id=%%s" % RUN_TABLE, (row["id"],))
        return cur.fetchone()


def load_files(conn, run):
    with conn.cursor() as cur:
        cur.execute("SELECT path, content FROM stu_console_project_files WHERE project_id=%s AND user_id=%s",
                    (run["project_id"], run["user_id"]))
        return {r["path"]: r["content"] for r in cur.fetchall()}


def cancel_requested(conn, run_id):
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT cancel_requested_at FROM %s WHERE id=%%s" % RUN_TABLE, (run_id,))
            row = cur.fetchone()
        return bool(row and row.get("cancel_requested_at"))
    except Exception:
        return False


def finish_run(conn, run_id, pid, result):
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE %s SET status=%%s, exit_code=%%s, stdout=%%s, stderr=%%s, error=%%s, duration_ms=%%s, "
            "files_count=%%s, files_sha256=%%s, finished_at=NOW() WHERE id=%%s AND status='running' AND worker_pid=%%s"
            % RUN_TABLE,
            (result["status"], result["exit_code"], result["stdout"], result["stderr"],
             (result["error"] or None) and str(result["error"])[:255], result["duration_ms"],
             result["files_count"], result["files_sha256"], run_id, pid))
        return cur.rowcount == 1


def process_run(conn, cfg, run, base, stop):
    run_id = int(run["id"])
    pid = os.getpid()
    files = load_files(conn, run)
    if not files:
        result = {"status": "error", "stdout": "", "stderr": "", "exit_code": None, "duration_ms": 0,
                  "error": "no_files", "files_count": 0, "files_sha256": files_digest({})}
    else:
        result = run_command(cfg, run_id, files, str(run["command"]), base,
                             cancelled=lambda: cancel_requested(conn, run_id), stop=stop)
    finish_run(conn, run_id, pid, result)
    print("[run %d] %s exit=%s in %dms (%d Dateien)" % (run_id, result["status"], result["exit_code"],
                                                       result["duration_ms"], result["files_count"]), flush=True)
    return result
