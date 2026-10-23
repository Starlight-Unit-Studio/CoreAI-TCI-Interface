#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
E.M.B.E.R. CoreUI - Python worker with durable generated outputs
=====================================
Fuehrt den Code aus, den Ember per [PY]...[/PY] im Chat erzeugt.

Was es tut:
  1. Pollt stu_ember_py_jobs nach 'queued' Jobs (atomar geclaimt, wie der Browse-Worker).
  2. Schreibt den Code in ein temporaeres Arbeitsverzeichnis ausserhalb von www.
  3. Startet einen wegwerfbaren Docker-Container und laesst den Code dort laufen.
  4. Uebernimmt Ausgabedateien vor der Bereinigung in den dauerhaften Store.
  5. Speichert stdout/stderr/Exitcode/Dauer und das Dateimanifest atomar.

Warum Docker und nicht einfach subprocess:
  Der Worker laeuft als root (er braucht den Docker-Socket). Der Code, den Ember
  schreibt, laeuft NIEMALS in diesem Prozess, sondern nur im Container:
    - kein Mount des E.M.B.E.R.-CoreUI-Projekts, daher keine Konfigurationsdatei im Container
    - --network bridge: volles Internet, aber die Loopback-Dienste des Hosts
      (MariaDB, Ollama, SearXNG auf 127.0.0.1) sind aus dem Container nicht erreichbar
    - --read-only Rootfs, Schreiben nur im Job-Verzeichnis und in /tmp (tmpfs)
    - --cap-drop ALL, --security-opt no-new-privileges, unprivilegierter Nutzer
    - harte Grenzen fuer RAM, CPU, Prozesse und Laufzeit

WICHTIG: Dieser Worker haelt bewusst NICHT den globalen Ollama-Lock. Der aufrufende
PHP-Request haelt ihn bereits fuer den kompletten Turn (Call #1 -> Python -> Call #2).
Wuerde der Worker ihn ebenfalls anfordern, gaebe es einen garantierten Deadlock.

Abhaengigkeiten:
    pip install pymysql          (die vorhandene /opt/ember-venv reicht)
    docker + gebautes Image      (siehe tools/ember_py.Dockerfile)

Start:
    python3 tools/ember_py_worker.py --once     # genau einen Job, dann Ende
    python3 tools/ember_py_worker.py --poll 2   # Dauerloop (systemd)
"""

import os
import json
import secrets
import re
import sys
import time
import shutil
import signal
import argparse
import subprocess
import tempfile
import traceback

from ember_artifacts import (
    collect_outputs, discard_outputs, empty_manifest,
    recover_workspace_outputs, recover_stdout_html,
)

try:
    import pymysql
except ImportError:
    pymysql = None

# ----------------------------------------------------------------------------- config

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

DEFAULTS = {
    "STU_DB_HOST": "127.0.0.1",
    "STU_DB_PORT": "13306",
    "STU_DB_NAME": "ember_coreui",
    "STU_DB_USER": "ember_coreui",
    "STU_DB_PASS": "",
    "STU_EMBER_PY_IMAGE": "ember-py:1",
    "STU_EMBER_PY_EXEC_TIMEOUT": "60",
    "STU_EMBER_PY_MEMORY": "512m",
    "STU_EMBER_PY_CPUS": "1.0",
    "STU_EMBER_PY_PIDS": "128",
    "STU_EMBER_PY_TMPFS_SIZE": "64m",
    "STU_EMBER_PY_STORE_CHARS": "16000",
    "STU_EMBER_PY_WORKDIR": os.path.join(PROJECT_ROOT, "var", "ember_py"),
}

DEFAULT_CONFIG = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "api", "config.local.php"))


def parse_php_config(path):
    """Liest define('KEY', <expr>) aus config.local.php - identische Logik wie im
    Browse-Worker, damit es genau eine Quelle der Wahrheit gibt."""
    cfg = dict(DEFAULTS)
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            src = fh.read()
    except OSError as exc:
        print("[config] WARN konnte %s nicht lesen: %s" % (path, exc), flush=True)
        return cfg

    for key in list(cfg.keys()) + ["STU_DB_PORT"]:
        m = re.search(r"define\(\s*['\"]" + re.escape(key) + r"['\"]\s*,\s*(.+?)\)\s*;",
                      src, re.DOTALL)
        if not m:
            continue
        expr = m.group(1).strip()
        if "getenv(" in expr:
            env_m = re.search(r"getenv\(\s*['\"]([^'\"]+)['\"]\s*\)", expr)
            if env_m and os.environ.get(env_m.group(1)):
                cfg[key] = os.environ[env_m.group(1)]
                continue
        lit = re.findall(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"", expr)
        if lit:
            val = lit[-1][0] or lit[-1][1]
            cfg[key] = val.replace("\\'", "'").replace('\\"', '"')
        else:
            num = re.search(r"(-?\d+(?:\.\d+)?)", expr)
            if num:
                cfg[key] = num.group(1)
    return cfg


# ----------------------------------------------------------------------------- db


def db_connect(cfg):
    if pymysql is None:
        raise RuntimeError("Fehlt: pip install pymysql")
    return pymysql.connect(
        host=cfg["STU_DB_HOST"],
        user=cfg["STU_DB_USER"],
        password=cfg["STU_DB_PASS"],
        database=cfg["STU_DB_NAME"],
        port=int(cfg.get("STU_DB_PORT", "13306") or "13306"),
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


def ensure_jobs_table(conn):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS stu_ember_py_jobs (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
              code MEDIUMTEXT NOT NULL,
              stdout MEDIUMTEXT NULL,
              stderr MEDIUMTEXT NULL,
              exit_code INT NULL,
              duration_ms INT NULL,
              channel VARCHAR(32) NOT NULL DEFAULT 'global',
              trigger_user_id INT NULL,
              recipient_uid INT NULL,
              error TEXT NULL,
              worker_pid INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)


def claim_next_job(conn):
    """Atomar genau einen queued-Job auf running setzen und zurueckgeben."""
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM stu_ember_py_jobs WHERE status='queued' ORDER BY id ASC LIMIT 1")
        row = cur.fetchone()
        if not row:
            return None
        job_id = row["id"]
        cur.execute(
            "UPDATE stu_ember_py_jobs SET status='running', started_at=NOW(), worker_pid=%s "
            "WHERE id=%s AND status='queued'",
            (os.getpid(), job_id),
        )
        if cur.rowcount != 1:
            return None  # ein anderer Worker war schneller
        cur.execute("SELECT * FROM stu_ember_py_jobs WHERE id=%s", (job_id,))
        return cur.fetchone()


def finish_job(conn, job_id, status, stdout="", stderr="", exit_code=None,
               duration_ms=0, error=None, limit=16000, manifest=None):
    def clip(s):
        s = s or ""
        if len(s) <= limit:
            return s
        head = int(limit * 0.4)
        return s[:head] + "\n[... gekuerzt ...]\n" + s[-(limit - head):]

    with conn.cursor() as cur:
        cur.execute(
            "UPDATE stu_ember_py_jobs SET status=%s, stdout=%s, stderr=%s, exit_code=%s, "
            "duration_ms=%s, error=%s, artifacts_json=%s, finished_at=NOW() "
            "WHERE id=%s AND status='running' AND worker_pid=%s",
            (status, clip(stdout), clip(stderr), exit_code, int(duration_ms), error,
             json.dumps(manifest or empty_manifest(), ensure_ascii=True, separators=(",", ":")),
             job_id, os.getpid()),
        )
        return cur.rowcount == 1


# ----------------------------------------------------------------------------- ausfuehrung


def work_base(cfg):
    base = cfg.get("STU_EMBER_PY_WORKDIR") or os.path.join(PROJECT_ROOT, "var", "ember_py")
    return base if os.path.isabs(base) else os.path.join(PROJECT_ROOT, base)


def stop_container(container):
    """Confirm quiescence before collecting or removing the sandbox directory."""
    try:
        result = subprocess.run(["docker", "rm", "--force", container],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        missing = b"No such container" in result.stderr or b"No such object" in result.stderr
        return result.returncode == 0 or missing
    except (OSError, subprocess.TimeoutExpired):
        return False


def run_in_container(cfg, job_id, code, *, publish_outputs=False):
    """Return stdout, stderr, exit code, duration, error and a v1 manifest.

    The separate output bind mount prevents replacing its host-side root with a
    link. The durable store is never exposed to model code. A container whose
    shutdown cannot be confirmed yields no outputs and retains its temporary work.
    """
    base = work_base(cfg)
    os.makedirs(base, exist_ok=True)
    workdir = tempfile.mkdtemp(prefix="job%d_" % job_id, dir=base)
    sandbox = os.path.join(workdir, "work")
    output = os.path.join(workdir, "output")
    container = "ember-py-%d-%s" % (job_id, secrets.token_hex(6))
    timeout = int(float(cfg.get("STU_EMBER_PY_EXEC_TIMEOUT", "60")))
    stdout, stderr, rc, dur, err = "", "", None, 0, None
    manifest = empty_manifest()
    stopped = True
    try:
        os.mkdir(sandbox, 0o777)
        os.mkdir(output, 0o777)
        os.chmod(sandbox, 0o777)
        os.chmod(output, 0o777)
        os.mkdir(os.path.join(sandbox, "output"), 0o755)
        script = os.path.join(sandbox, "main.py")
        with open(script, "w", encoding="utf-8") as fh:
            fh.write(code)
        os.chmod(script, 0o644)
        cmd = [
            "docker", "run", "--rm", "--name", container,
            "--network", "bridge",
            "--memory", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
            "--memory-swap", str(cfg.get("STU_EMBER_PY_MEMORY", "512m")),
            "--cpus", str(cfg.get("STU_EMBER_PY_CPUS", "1.0")),
            "--pids-limit", str(cfg.get("STU_EMBER_PY_PIDS", "128")),
            "--cap-drop", "ALL", "--security-opt", "no-new-privileges", "--read-only",
            "--tmpfs", "/tmp:rw,noexec,nosuid,size=%s" % cfg.get("STU_EMBER_PY_TMPFS_SIZE", "64m"),
            "--user", "65534:65534",
            "-v", "%s:/work:rw" % sandbox,
            "-v", "%s:/work/output:rw" % output,
            # Cloud/coding models often use /mnt/data for generated files.
            # This is the SAME bounded per-job output dir, not a host /mnt/data
            # mount and never the persistent artifact store.
            "-v", "%s:/mnt/data:rw" % output,
            "-w", "/work", "-e", "HOME=/tmp", "-e", "MPLBACKEND=Agg",
            "-e", "PYTHONUNBUFFERED=1", "-e", "EMBER_OUTPUT_DIR=/work/output",
            str(cfg.get("STU_EMBER_PY_IMAGE", "ember-py:1")),
            "python", "-I", "/work/main.py",
        ]
        started = time.monotonic()
        stopped = False
        try:
            proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout + 10)
            stdout = proc.stdout.decode("utf-8", "replace")
            stderr = proc.stderr.decode("utf-8", "replace")
            rc = proc.returncode
            stopped = stop_container(container)
            if not 0 <= rc < 125:
                err = "docker_runtime_error"
        except subprocess.TimeoutExpired as exc:
            stdout = (exc.stdout or b"").decode("utf-8", "replace")
            stderr = (exc.stderr or b"").decode("utf-8", "replace")
            stopped = stop_container(container)
            err = "timeout nach %ds - der Code lief zu lange" % timeout
        except FileNotFoundError:
            stopped = True
            err = "docker ist auf diesem Host nicht installiert/erreichbar"
        finally:
            dur = int((time.monotonic() - started) * 1000)
        if not stopped:
            err = "sandbox_stop_unconfirmed; temporary work retained for operator cleanup"
            manifest["warnings"].append("sandbox_stop_unconfirmed")
        elif publish_outputs:
            recovered = 0
            stdout_recovered = False
            try:
                # Many cloud coding models write ./mini_game.html or
                # ./project/index.html despite the designated output path.
                # Inspect only the stopped, isolated sandbox work directory.
                recovered = recover_workspace_outputs(sandbox, output)
                if recovered == 0 and rc == 0:
                    stdout_recovered = recover_stdout_html(stdout, output)
            except OSError:
                manifest["warnings"].append("workspace_recovery_unavailable")
            saved_warnings = list(manifest["warnings"])
            manifest = collect_outputs(output, base, int(job_id), partial=bool(err or rc != 0))
            manifest["warnings"].extend(saved_warnings)
            if recovered:
                manifest["warnings"].append("workspace_output_recovered")
            if stdout_recovered:
                manifest["warnings"].append("stdout_html_recovered")
    finally:
        if stopped:
            shutil.rmtree(workdir, ignore_errors=True)
    return stdout, stderr, rc, dur, err, manifest


def process_job(conn, cfg, job):
    job_id = int(job["id"])
    code = job.get("code") or ""
    limit = int(cfg.get("STU_EMBER_PY_STORE_CHARS", "16000"))
    print("[job %d] starte (%d Zeichen Code)" % (job_id, len(code)), flush=True)
    bound = (job.get("channel") == "console" and int(job.get("recipient_uid") or 0) > 0
             and bool(job.get("session_id")) and int(job.get("trigger_message_id") or 0) > 0)
    stdout, stderr, rc, dur, err, manifest = run_in_container(cfg, job_id, code, publish_outputs=bound)
    # A PHP timeout/cancellation wins. Do not overwrite it. If the DB throws,
    # commit outcome is unknown: retain copies rather than deleting live files.
    accepted = finish_job(conn, job_id, "error" if err else "done", stdout, stderr, rc, dur,
                          err, limit, manifest)
    if not accepted and manifest["files"]:
        discard_outputs(work_base(cfg), manifest)
    print("[job %d] %s rc=%s in %dms (%d Dateien)" %
          (job_id, "fertig" if accepted else "verworfen", rc, dur, len(manifest["files"])), flush=True)


# ----------------------------------------------------------------------------- main

_STOP = False


def _on_signal(signum, frame):
    global _STOP
    _STOP = True
    print("[worker] Signal %s - beende nach dem aktuellen Job" % signum, flush=True)


def main():
    ap = argparse.ArgumentParser(description="Ember Python-Worker")
    ap.add_argument("--config", default=DEFAULT_CONFIG, help="Pfad zu config.local.php")
    ap.add_argument("--once", action="store_true", help="genau einen Job abarbeiten")
    ap.add_argument("--poll", type=float, default=2.0, help="Pollintervall in Sekunden")
    args = ap.parse_args()

    cfg = parse_php_config(args.config)
    signal.signal(signal.SIGTERM, _on_signal)
    signal.signal(signal.SIGINT, _on_signal)

    conn = db_connect(cfg)
    ensure_jobs_table(conn)
    with conn.cursor() as cur:
        # Installer migration 011 must precede worker startup.
        cur.execute("SELECT artifacts_json,session_id,trigger_message_id,trigger_revision FROM stu_ember_py_jobs LIMIT 0")
    print("[worker] startet. Image=%s DB=%s Timeout=%ss"
          % (cfg.get("STU_EMBER_PY_IMAGE"), cfg.get("STU_DB_NAME"),
             cfg.get("STU_EMBER_PY_EXEC_TIMEOUT")), flush=True)

    while not _STOP:
        try:
            job = claim_next_job(conn)
        except Exception as exc:
            print("[worker] DB-Problem: %s - verbinde neu" % exc, flush=True)
            time.sleep(3)
            try:
                conn = db_connect(cfg)
            except Exception:
                time.sleep(5)
            continue

        if not job:
            if args.once:
                print("[worker] kein Job offen.", flush=True)
                return 0
            time.sleep(max(0.5, args.poll))
            continue

        try:
            process_job(conn, cfg, job)
        except Exception:
            tb = traceback.format_exc()
            print("[worker] unerwarteter Fehler:\n%s" % tb, flush=True)
            try:
                finish_job(conn, int(job["id"]), "error", "", "", None, 0, tb[:800])
            except Exception:
                pass

        if args.once:
            return 0

    return 0


if __name__ == "__main__":
    sys.exit(main())
