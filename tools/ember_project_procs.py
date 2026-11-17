# -*- coding: utf-8 -*-
"""
CoreAI 0.7.78 (P10 C): processes of a project that keep running in the sandbox.

A process is a command on the project's saved files (a program, a shell or a development
server) that keeps running after it started. The Python worker (tools/ember_py_worker.py) runs
a ProcManager in a thread of its own, with its own database connection, next to Mara's Python
jobs and the project runs of P10 B. For every process it:

- starts the command with "sh -c" behind the same walls as the runs (tools/ember_project_runs.py)
  plus a small init, with stdin open: input lines from the tab Dateien reach the program in order;
- writes the output (stdout and stderr in the order they come) into the row: the latest
  OUTPUT_CHARS characters and the end position, so the browser only asks for what is new;
- copies files saved in the meantime into the running work directory (changed, new, removed), so
  a development server shows the current state;
- answers requests of the preview to a development server inside the container: "docker exec"
  runs a small Python client against 127.0.0.1 in the container's own network namespace. No port
  is published, nothing outside the container reaches the server;
- ends the process on request, after the maximum lifetime, when nobody has looked at it for a
  while, or when the worker stops. The exit code is the real one of the program (128 + signal
  when a signal ended it).

The project in the database is never written from here.
"""

import codecs
import hashlib
import json
import os
import re
import secrets
import shutil
import subprocess
import tempfile
import threading
import time

from ember_project_runs import safe_relpath, stage_files

PROC_TABLE = "stu_console_project_procs"
INPUT_TABLE = "stu_console_project_proc_input"
FETCH_TABLE = "stu_console_project_proc_fetch"
OUTPUT_CHARS = 65536
FETCH_BYTES = 2 * 1024 * 1024
FETCH_SECONDS = 10
SYNC_SECONDS = 2.0
BEAT_SECONDS = 2.0
TICK_ACTIVE = 0.25
TICK_IDLE = 1.0
STOP_GRACE = 3
FLUSH_SECONDS = 0.5
INPUT_BUFFER = 262144
_PATH = re.compile(r"^/[\x21-\x7e]{0,999}$")
_METHODS = ("GET", "POST", "PUT", "PATCH", "DELETE", "HEAD")

# Runs inside the container with "python -I -c": one request to the development server on
# 127.0.0.1 (or ::1 when only IPv6 answers), redirects on the same server followed up to three times. The first output line is
# JSON (status, type, final path), the body follows as it came, cut at the limit.
FETCH_SCRIPT = r"""
import http.client, json, sys, urllib.parse as up
method, port, path, ctype, limit = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], int(sys.argv[5])
body = sys.stdin.buffer.read() if method not in ("GET", "HEAD") else None
out = sys.stdout.buffer
def say(head, data=b""):
    out.write((json.dumps(head) + "\n").encode("utf-8")); out.write(data); out.flush(); sys.exit(0)
def ask(host):
    conn = http.client.HTTPConnection(host, port, timeout=8)
    headers = {"Host": "localhost:%d" % port, "Accept-Encoding": "identity", "User-Agent": "CoreAI-Vorschau"}
    if body is not None and ctype:
        headers["Content-Type"] = ctype
    conn.request(method, path, body=body, headers=headers)
    return conn, conn.getresponse()
for hop in range(4):
    # A server on "localhost" may listen on IPv6 only (Node.js, Vite): then ::1 answers.
    try:
        try:
            conn, r = ask("127.0.0.1")
        except ConnectionRefusedError:
            conn, r = ask("::1")
    except Exception as exc:
        say({"error": "connect", "detail": type(exc).__name__})
    loc = r.getheader("Location") or ""
    if r.status in (301, 302, 303, 307, 308) and loc and hop < 3:
        u = up.urlsplit(up.urljoin("http://localhost:%d%s" % (port, path), loc))
        if u.hostname in ("localhost", "127.0.0.1") and (u.port or 80) == port:
            path = (u.path or "/") + ("?" + u.query if u.query else "")
            if r.status == 303 or (r.status in (301, 302) and method == "POST"):
                method, body = "GET", None
            r.read()
            conn.close()
            continue
    data = r.read(limit + 1)
    say({"status": r.status, "type": r.getheader("Content-Type") or "", "path": path,
         "truncated": len(data) > limit}, data[:limit])
say({"error": "redirects"})
"""


# ----------------------------------------------------------------------------- pure helpers

def docker_command(cfg, container, workdir, command):
    """The docker run line (pure): the walls of the runs, stdin open, an init that passes
    signals on, so "Beenden" ends the program with its own exit code."""
    return [
        "docker", "run", "--rm", "-i", "--init", "--name", container,
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
        str(cfg.get("STU_EMBER_PROJECT_IMAGE") or cfg.get("STU_EMBER_PY_IMAGE", "ember-py:2")),
        "sh", "-c", command,
    ]


def fetch_command(container, method, port, path, content_type=""):
    """The docker exec line for one request to the development server (pure), or ValueError."""
    if method not in _METHODS:
        raise ValueError("invalid_method")
    if not isinstance(port, int) or not 1 <= port <= 65535:
        raise ValueError("invalid_port")
    if not isinstance(path, str) or not _PATH.match(path):
        raise ValueError("invalid_path")
    content_type = (content_type or "")[:160]
    if re.search(r"[\x00-\x1f\x7f]", content_type):
        raise ValueError("invalid_type")
    return ["docker", "exec", "-i", "-u", "65534:65534", container, "python", "-I", "-c", FETCH_SCRIPT,
            method, str(port), path, content_type, str(FETCH_BYTES)]


def parse_fetch(raw):
    """The answer of FETCH_SCRIPT (pure): a dict with status, type, path, truncated and body,
    or with error."""
    raw = raw or b""
    cut = raw.find(b"\n")
    if cut < 0:
        return {"error": "no_answer"}
    try:
        head = json.loads(raw[:cut].decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return {"error": "no_answer"}
    if not isinstance(head, dict):
        return {"error": "no_answer"}
    if head.get("error"):
        return {"error": str(head["error"])[:60]}
    try:
        status = int(head.get("status"))
    except (TypeError, ValueError):
        return {"error": "no_answer"}
    return {"status": status, "type": str(head.get("type") or "")[:160], "path": str(head.get("path") or "")[:1000],
            "truncated": bool(head.get("truncated")), "body": raw[cut + 1:][:FETCH_BYTES]}


def digest_meta(meta):
    """The digest of ember_project_runs.files_digest, from path => sha256 of the content (pure)."""
    h = hashlib.sha256()
    for path in sorted(meta):
        h.update(path.encode("utf-8") + b"\x00")
        h.update(str(meta[path]).encode("ascii") + b"\n")
    return h.hexdigest()


def sync_plan(staged, current):
    """What to copy and what to remove (pure): two sorted lists of paths."""
    changed = sorted(p for p, sha in current.items() if staged.get(p) != sha)
    removed = sorted(p for p in staged if p not in current)
    return changed, removed


def write_file(workdir, path, content):
    """One file into the running work directory, replaced in one step."""
    rel = safe_relpath(path)
    if rel is None:
        raise ValueError("unsafe_path")
    target = os.path.join(workdir, *rel.split("/"))
    folder = os.path.dirname(target)
    os.makedirs(folder, mode=0o777, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".coreai-", dir=folder)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as fh:
            fh.write(content)
        os.chmod(tmp, 0o666)
        os.replace(tmp, target)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def remove_file(workdir, path):
    rel = safe_relpath(path)
    if rel is None:
        return
    target = os.path.join(workdir, *rel.split("/"))
    try:
        if os.path.isfile(target) and not os.path.islink(target):
            os.unlink(target)
    except OSError:
        pass


# ----------------------------------------------------------------------------- one process

class Proc(object):
    """One running command with its container, output buffer and staged files."""

    def __init__(self, proc_id, command, port=None):
        self.id = int(proc_id)
        self.command = command
        self.port = int(port) if port else None
        self.container = "ember-proc-%d-%s" % (self.id, secrets.token_hex(6))
        self.root = None
        self.workdir = None
        self.popen = None
        self.reader = None
        self.lock = threading.Lock()
        self.text = ""
        self.end = 0
        self.flushed_end = 0
        self.started = None
        self.staged = {}
        self.synced = 0.0
        self.owner = None
        self.flushed_at = 0.0
        self.pending = b""

    def start(self, cfg, base, files):
        """files: path => (content, sha256). Raises ValueError for an unsafe path and
        FileNotFoundError without docker."""
        os.makedirs(base, exist_ok=True)
        self.root = tempfile.mkdtemp(prefix="proc%d_" % self.id, dir=base)
        self.workdir = os.path.join(self.root, "work")
        os.mkdir(self.workdir, 0o777)
        os.chmod(self.workdir, 0o777)
        stage_files(dict((p, c) for p, (c, _) in files.items()), self.workdir)
        self.staged = dict((p, s) for p, (_, s) in files.items())
        self.synced = time.monotonic()
        self.popen = subprocess.Popen(docker_command(cfg, self.container, self.workdir, self.command),
                                      stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        self.started = time.monotonic()
        # Input never blocks the manager: what the program does not read yet waits in self.pending.
        os.set_blocking(self.popen.stdin.fileno(), False)
        self.reader = threading.Thread(target=self._read, name="proc-%d-output" % self.id, daemon=True)
        self.reader.start()

    def _read(self):
        decoder = codecs.getincrementaldecoder("utf-8")("replace")
        stream = self.popen.stdout
        while True:
            try:
                chunk = os.read(stream.fileno(), 4096)
            except OSError:
                break
            if not chunk:
                break
            self.append(decoder.decode(chunk))
        self.append(decoder.decode(b"", final=True))

    def append(self, text):
        if not text:
            return
        with self.lock:
            self.text = (self.text + text)[-OUTPUT_CHARS:]
            self.end += len(text)

    def snapshot(self):
        with self.lock:
            return self.text, self.end

    def write(self, data):
        """Input for the program, in order; False when its stdin is closed."""
        self.pending += data.encode("utf-8")
        return self.push_input()

    def push_input(self):
        """As much waiting input as the program takes now, without blocking."""
        while self.pending:
            try:
                n = os.write(self.popen.stdin.fileno(), self.pending)
            except BlockingIOError:
                return True
            except (BrokenPipeError, OSError, ValueError, AttributeError):
                self.pending = b""
                return False
            self.pending = self.pending[n:]
        return True

    def input_full(self):
        return len(self.pending) >= INPUT_BUFFER

    def poll(self):
        return self.popen.poll() if self.popen is not None else None

    def wait_output(self, seconds=2.0):
        if self.reader is not None:
            self.reader.join(seconds)

    def stop(self, stop_container, grace=STOP_GRACE):
        """SIGTERM through the init, SIGKILL after `grace` seconds. Returns (exit code, confirmed)."""
        try:
            subprocess.run(["docker", "stop", "-t", str(int(grace)), self.container],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=grace + 20)
        except (OSError, subprocess.TimeoutExpired):
            pass
        rc = None
        if self.popen is not None:
            try:
                rc = self.popen.wait(timeout=10)
            except subprocess.TimeoutExpired:
                stop_container(self.container)
                self.popen.kill()
                try:
                    rc = self.popen.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    rc = None
        confirmed = stop_container(self.container)
        self.wait_output()
        return rc, confirmed

    def sync(self, current, load):
        """Bring the work directory to the saved state: current is path => sha256, load(paths)
        returns path => content. Returns True when something changed."""
        changed, removed = sync_plan(self.staged, current)
        if changed:
            contents = load(changed)
            for path in changed:
                if path in contents:
                    write_file(self.workdir, path, contents[path])
                    self.staged[path] = current[path]
        for path in removed:
            remove_file(self.workdir, path)
            self.staged.pop(path, None)
        self.synced = time.monotonic()
        return bool(changed or removed)

    def fetch(self, method, path, content_type="", body=None):
        """One request to the development server inside the container (parse_fetch result)."""
        if not self.port:
            return {"error": "no_port"}
        try:
            line = fetch_command(self.container, method, self.port, path, content_type)
        except ValueError as exc:
            return {"error": str(exc)}
        try:
            done = subprocess.run(line, input=body or b"", stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  timeout=FETCH_SECONDS + 5)
        except subprocess.TimeoutExpired:
            return {"error": "timeout"}
        except OSError:
            return {"error": "docker_missing"}
        if done.returncode != 0 and not done.stdout:
            return {"error": "exec_failed"}
        return parse_fetch(done.stdout)

    def cleanup(self):
        if self.root:
            shutil.rmtree(self.root, ignore_errors=True)


def end_state(reason, rc, confirmed):
    """status, exit code and error of an ended process (pure). reason is None when the program
    ended by itself, else stopped (asked for), time_limit, idle, gone or worker_stopped."""
    if not confirmed:
        return "error", rc, "sandbox_stop_unconfirmed"
    if reason is None:
        if rc is None or rc == 125:
            return "error", None, "docker_runtime_error"
        return "exited", rc, None
    return "stopped", rc, (None if reason == "stopped" else reason)


# ----------------------------------------------------------------------------- database

def table_ready(conn):
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id, output_end, stop_requested_at FROM %s LIMIT 0" % PROC_TABLE)
            cur.execute("SELECT id, sent_at FROM %s LIMIT 0" % INPUT_TABLE)
            cur.execute("SELECT id, response_path, response_body FROM %s LIMIT 0" % FETCH_TABLE)
        return True
    except Exception:
        return False


def list_orphans():
    try:
        done = subprocess.run(["docker", "ps", "-aq", "--filter", "name=ember-proc-"],
                              stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=20)
        return [x for x in done.stdout.decode("ascii", "ignore").split() if x]
    except (OSError, subprocess.TimeoutExpired):
        return []


class ProcManager(object):
    """Starts, tends and ends the processes. One instance per worker, in its own thread."""

    def __init__(self, connect, cfg, base, stop_container, pid, log=None):
        self.connect = connect
        self.cfg = cfg
        self.base = base
        self.stop_container = stop_container
        self.pid = int(pid)
        self.log = log or (lambda text: print(text, flush=True))
        self.procs = {}
        self.max_procs = max(1, int(float(cfg.get("STU_EMBER_PROJECT_PROCS", "3") or 3)))
        self.lifetime = max(60, int(float(cfg.get("STU_EMBER_PROJECT_PROC_SECONDS", "1800") or 1800)))
        self.idle = max(30, int(float(cfg.get("STU_EMBER_PROJECT_PROC_IDLE", "300") or 300)))
        self.beat = 0.0
        self.swept = 0.0

    # --- loop
    def run(self, should_stop):
        conn = None
        first = True
        while not should_stop():
            try:
                if conn is None:
                    conn = self.connect()
                if first:
                    self.recover(conn)
                    first = False
                self.step(conn)
            except Exception as exc:
                self.log("[procs] Problem: %s" % exc)
                try:
                    if conn is not None:
                        conn.close()
                except Exception:
                    pass
                conn = None
                time.sleep(3)
                continue
            time.sleep(TICK_ACTIVE if self.procs else TICK_IDLE)
        self.shutdown(conn)

    def recover(self, conn):
        """After a worker start: containers of an earlier worker are ended, its rows too."""
        for container in list_orphans():
            self.stop_container(container)
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET status='error', error='worker_restart', finished_at=NOW() "
                        "WHERE status='running' AND (worker_pid IS NULL OR worker_pid<>%%s)" % PROC_TABLE, (self.pid,))

    def step(self, conn):
        while len(self.procs) < self.max_procs:
            row = self.claim(conn)
            if not row:
                break
            self.launch(conn, row)
        for proc in list(self.procs.values()):
            self.tend(conn, proc)
        if self.procs:
            self.serve_fetches(conn)
            if time.monotonic() - self.beat >= BEAT_SECONDS:
                ids = list(self.procs)
                with conn.cursor() as cur:
                    cur.execute("UPDATE %s SET beat_at=NOW() WHERE id IN (%s) AND status='running'"
                                % (PROC_TABLE, ",".join(["%s"] * len(ids))), ids)
                self.beat = time.monotonic()
        elif time.monotonic() - self.swept >= 30:
            self.drop_stale_fetches(conn)
            self.swept = time.monotonic()

    # --- start
    def claim(self, conn):
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM %s WHERE status='queued' ORDER BY id ASC LIMIT 1" % PROC_TABLE)
            row = cur.fetchone()
            if not row:
                return None
            cur.execute("UPDATE %s SET status='running', started_at=NOW(), beat_at=NOW(), worker_pid=%%s "
                        "WHERE id=%%s AND status='queued'" % PROC_TABLE, (self.pid, row["id"]))
            if cur.rowcount != 1:
                return None
            cur.execute("SELECT * FROM %s WHERE id=%%s" % PROC_TABLE, (row["id"],))
            return cur.fetchone()

    def files_meta(self, conn, row):
        with conn.cursor() as cur:
            cur.execute("SELECT path, sha256 FROM stu_console_project_files WHERE project_id=%s AND user_id=%s",
                        (row["project_id"], row["user_id"]))
            return dict((r["path"], r["sha256"]) for r in cur.fetchall())

    def files_content(self, conn, row, paths):
        out = {}
        with conn.cursor() as cur:
            for i in range(0, len(paths), 50):
                part = paths[i:i + 50]
                cur.execute("SELECT path, content FROM stu_console_project_files WHERE project_id=%%s AND user_id=%%s "
                            "AND path IN (%s)" % ",".join(["%s"] * len(part)), [row["project_id"], row["user_id"]] + part)
                for r in cur.fetchall():
                    out[r["path"]] = r["content"]
        return out

    def launch(self, conn, row):
        proc = Proc(row["id"], str(row["command"]), row.get("port"))
        proc.owner = (row["project_id"], row["user_id"])
        meta = self.files_meta(conn, row)
        if not meta:
            self.finish(conn, proc, "error", None, "no_files")
            return
        contents = self.files_content(conn, row, sorted(meta))
        files = dict((p, (contents[p], meta[p])) for p in meta if p in contents)
        try:
            proc.start(self.cfg, self.base, files)
        except FileNotFoundError:
            proc.cleanup()
            self.finish(conn, proc, "error", None, "docker_missing")
            return
        except ValueError as exc:
            proc.cleanup()
            self.finish(conn, proc, "error", None, str(exc)[:60])
            return
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET files_count=%%s, files_sha256=%%s, synced_at=NOW() WHERE id=%%s" % PROC_TABLE,
                        (len(files), digest_meta(proc.staged), proc.id))
        self.procs[proc.id] = proc
        self.log("[proc %d] gestartet: %s (%d Dateien)" % (proc.id, proc.command[:80], len(files)))

    # --- while it runs
    def flush(self, conn, proc, final=False):
        text, end = proc.snapshot()
        if end == proc.flushed_end or (not final and time.monotonic() - proc.flushed_at < FLUSH_SECONDS):
            return
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET output=%%s, output_end=%%s WHERE id=%%s" % PROC_TABLE, (text, end, proc.id))
        proc.flushed_end = end
        proc.flushed_at = time.monotonic()

    def tend(self, conn, proc):
        with conn.cursor() as cur:
            cur.execute("SELECT stop_requested_at, TIMESTAMPDIFF(SECOND, COALESCE(seen_at, started_at), NOW()) AS idle "
                        "FROM %s WHERE id=%%s" % PROC_TABLE, (proc.id,))
            row = cur.fetchone()
        self.flush(conn, proc)
        proc.push_input()
        if row is not None and not proc.input_full():
            with conn.cursor() as cur:
                cur.execute("SELECT id, data FROM %s WHERE proc_id=%%s AND sent_at IS NULL ORDER BY id ASC LIMIT 20"
                            % INPUT_TABLE, (proc.id,))
                pending = cur.fetchall()
                for item in pending:
                    proc.write(item["data"])
                    cur.execute("UPDATE %s SET sent_at=NOW() WHERE id=%%s" % INPUT_TABLE, (item["id"],))
        rc = proc.poll()
        if rc is not None:
            proc.wait_output()
            self.flush(conn, proc, True)
            status, code, error = end_state(None, rc, self.stop_container(proc.container))
            self.end(conn, proc, status, code, error)
            return
        reason = None
        if row is None:
            reason = "gone"
        elif row.get("stop_requested_at"):
            reason = "stopped"
        elif time.monotonic() - proc.started > self.lifetime:
            reason = "time_limit"
        elif row.get("idle") is not None and int(row["idle"]) >= self.idle:
            reason = "idle"
        if reason:
            rc, confirmed = proc.stop(self.stop_container)
            if reason != "gone":
                self.flush(conn, proc, True)
            status, code, error = end_state(reason, rc, confirmed)
            self.end(conn, proc, status, code, error)
            return
        if time.monotonic() - proc.synced >= SYNC_SECONDS:
            owner = {"project_id": proc.owner[0], "user_id": proc.owner[1]}
            current = self.files_meta(conn, owner)
            try:
                changed = proc.sync(current, lambda paths: self.files_content(conn, owner, paths))
            except ValueError:
                changed = False
            if changed:
                with conn.cursor() as cur:
                    cur.execute("UPDATE %s SET files_count=%%s, files_sha256=%%s, synced_at=NOW() WHERE id=%%s" % PROC_TABLE,
                                (len(proc.staged), digest_meta(proc.staged), proc.id))

    def end(self, conn, proc, status, code, error):
        self.finish(conn, proc, status, code, error)
        if error != "sandbox_stop_unconfirmed":
            proc.cleanup()
        self.procs.pop(proc.id, None)
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET status='error', error='not_running', finished_at=NOW() "
                        "WHERE proc_id=%%s AND status='queued'" % FETCH_TABLE, (proc.id,))
        self.log("[proc %d] %s exit=%s %s" % (proc.id, status, code, error or ""))

    def finish(self, conn, proc, status, code, error):
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET status=%%s, exit_code=%%s, error=%%s, finished_at=NOW() "
                        "WHERE id=%%s AND status='running'" % PROC_TABLE, (status, code, error, proc.id))

    # --- the preview's requests
    def serve_fetches(self, conn):
        ids = list(self.procs)
        with conn.cursor() as cur:
            cur.execute("SELECT id, proc_id, method, path, content_type, body FROM %s WHERE status='queued' "
                        "AND proc_id IN (%s) ORDER BY id ASC LIMIT 8" % (FETCH_TABLE, ",".join(["%s"] * len(ids))), ids)
            rows = cur.fetchall()
        for item in rows:
            proc = self.procs.get(int(item["proc_id"]))
            result = proc.fetch(str(item["method"]), str(item["path"]), item.get("content_type") or "",
                                item.get("body")) if proc else {"error": "not_running"}
            with conn.cursor() as cur:
                if result.get("error"):
                    cur.execute("UPDATE %s SET status='error', error=%%s, finished_at=NOW() WHERE id=%%s AND status='queued'"
                                % FETCH_TABLE, (result["error"], item["id"]))
                else:
                    cur.execute("UPDATE %s SET status='done', response_status=%%s, response_type=%%s, response_path=%%s, "
                                "response_body=%%s, error=%%s, finished_at=NOW() WHERE id=%%s AND status='queued'" % FETCH_TABLE,
                                (result["status"], result["type"], result["path"] or str(item["path"]), result["body"],
                                 "truncated" if result.get("truncated") else None, item["id"]))

    def drop_stale_fetches(self, conn):
        with conn.cursor() as cur:
            cur.execute("UPDATE %s SET status='error', error='not_running', finished_at=NOW() WHERE status='queued' "
                        "AND created_at < NOW() - INTERVAL 30 SECOND" % FETCH_TABLE)

    # --- end of the worker
    def shutdown(self, conn):
        for proc in list(self.procs.values()):
            rc, confirmed = proc.stop(self.stop_container, grace=1)
            status, code, error = end_state("worker_stopped", rc, confirmed)
            try:
                if conn is not None:
                    self.flush(conn, proc, True)
                    self.end(conn, proc, status, code, error)
                else:
                    proc.cleanup()
            except Exception:
                proc.cleanup()
        self.procs = {}
