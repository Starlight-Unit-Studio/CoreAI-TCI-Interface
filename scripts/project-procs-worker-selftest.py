#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""CoreAI 0.7.78 (P10 C): processes of a project in the sandbox (tools/ember_project_procs.py).

    python3 scripts/project-procs-worker-selftest.py

Offline: the docker line (the walls of the runs, stdin open, an init), the request line for a
development server, reading its answer, the digest, the sync plan, files written in one step,
the end states and the hook in tools/ember_py_worker.py.
With Docker and the image ember-py:2 present, real processes: input reaches the program and
its output comes back in order, the exit code of a program that ends by itself, "Beenden" with
the real exit code of SIGTERM, a development server answers from inside the container (page,
redirect, JSON, POST, a missing page), a saved change is served after the sync, a port without
a server, and the work directory is removed. Without Docker those are skipped and named.
"""
import atexit
import os
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.join(ROOT, "tools"))
import ember_project_procs as procs  # noqa: E402
import ember_project_runs as runs  # noqa: E402


def check(ok, why):
    if not ok:
        print("Project procs FAIL: " + why, file=sys.stderr)
        sys.exit(1)


# --- Pure parts ---------------------------------------------------------------------------------
cfg = {"STU_EMBER_PY_IMAGE": "ember-py:2", "STU_EMBER_PY_MEMORY": "512m"}
line = procs.docker_command(cfg, "ember-proc-1-x", "/var/w", "python -m http.server 8000")
for flag in ["--read-only", "--cap-drop", "ALL", "no-new-privileges", "65534:65534", "--pids-limit", "--memory", "-i", "--init"]:
    check(flag in line, "docker line has " + flag)
check(line[-4:] == ["ember-py:2", "sh", "-c", "python -m http.server 8000"] and "/var/w:/work:rw" in line
      and "-p" not in line and "--publish" not in line, "docker line: image, sh -c, the work directory, no published port")
check(not any(":/opt" in x or "docker.sock" in x or "config.local" in x for x in line), "no CoreAI mount, no socket")
check(procs.docker_command(dict(cfg, STU_EMBER_PROJECT_IMAGE="ember-dev:1"), "c", "/w", "true")[-4] == "ember-dev:1", "own image")

fetch = procs.fetch_command("ember-proc-1-x", "GET", 8000, "/a/b?x=1")
check(fetch[:7] == ["docker", "exec", "-i", "-u", "65534:65534", "ember-proc-1-x", "python"]
      and fetch[-5:] == ["GET", "8000", "/a/b?x=1", "", str(procs.FETCH_BYTES)], "request line: " + repr(fetch[-5:]))
for bad in [("TRACE", 8000, "/"), ("GET", 0, "/"), ("GET", 70000, "/"), ("GET", 8000, "a"), ("GET", 8000, "/a b"),
            ("GET", 8000, "/\r\nX: y"), ("GET", 8000, "/" + "a" * 1000)]:
    try:
        procs.fetch_command("c", *bad)
        check(False, "a bad request is refused: " + repr(bad))
    except ValueError:
        pass
try:
    procs.fetch_command("c", "POST", 8000, "/", "text/plain\r\nX: 1")
    check(False, "a bad content type is refused")
except ValueError:
    pass

check(procs.parse_fetch(b'{"status": 200, "type": "text/html", "path": "/", "truncated": false}\n<p>hi</p>')
      == {"status": 200, "type": "text/html", "path": "/", "truncated": False, "body": b"<p>hi</p>"}, "answer read")
check(procs.parse_fetch(b'{"error": "connect", "detail": "ConnectionRefusedError"}\n') == {"error": "connect"}, "connect error")
check(procs.parse_fetch(b"") == {"error": "no_answer"} and procs.parse_fetch(b"garbage\n") == {"error": "no_answer"}
      and procs.parse_fetch(b'{"status": "x"}\n') == {"error": "no_answer"}, "broken answers")

files = {"a.py": "1", "b/c.html": "<p>"}
meta = dict((p, __import__("hashlib").sha256(c.encode("utf-8")).hexdigest()) for p, c in files.items())
check(procs.digest_meta(meta) == runs.files_digest(files), "the digest matches the runs' digest")
check(procs.sync_plan({"a": "1", "b": "2", "c": "3"}, {"a": "1", "b": "9", "d": "4"}) == (["b", "d"], ["c"]), "sync plan")

check(procs.end_state(None, 0, True) == ("exited", 0, None) and procs.end_state(None, 3, True) == ("exited", 3, None)
      and procs.end_state(None, 125, True) == ("error", None, "docker_runtime_error")
      and procs.end_state("stopped", 143, True) == ("stopped", 143, None)
      and procs.end_state("idle", 143, True) == ("stopped", 143, "idle")
      and procs.end_state("stopped", 143, False) == ("error", 143, "sandbox_stop_unconfirmed"), "end states")

tmp = tempfile.mkdtemp()
try:
    procs.write_file(tmp, "x/y.txt", "eins")
    procs.write_file(tmp, "x/y.txt", "zwei")
    check(open(os.path.join(tmp, "x", "y.txt")).read() == "zwei" and os.listdir(os.path.join(tmp, "x")) == ["y.txt"],
          "a file is replaced in one step, no temporary file stays")
    try:
        procs.write_file(tmp, "../evil", "x")
        check(False, "an unsafe path is refused")
    except ValueError:
        pass
    procs.remove_file(tmp, "x/y.txt")
    procs.remove_file(tmp, "../etc/passwd")
    check(not os.path.exists(os.path.join(tmp, "x", "y.txt")), "a removed file goes")
finally:
    shutil.rmtree(tmp, ignore_errors=True)

p = procs.Proc(5, "true")
for i in range(70):
    p.append("x" * 1000)
text, end = p.snapshot()
check(len(text) == procs.OUTPUT_CHARS and end == 70000, "the buffer keeps the latest output and counts all of it")

# Input to a program that does not read: the pipe fills, the rest waits, nothing blocks.
read_end, write_end = os.pipe()
os.set_blocking(write_end, False)
holder = type("Popen", (), {})()
holder.stdin = os.fdopen(write_end, "wb", buffering=0)
p.popen = holder
t0 = time.monotonic()
for _ in range(200):
    p.write("x" * 2000 + "\n")
check(time.monotonic() - t0 < 2 and p.input_full() and 0 < len(p.pending) < 400200,
      "input never blocks the worker: %.2fs, %d bytes waiting" % (time.monotonic() - t0, len(p.pending)))
os.read(read_end, 65536)
waiting = len(p.pending)
p.push_input()
check(len(p.pending) < waiting, "waiting input moves on when the program reads")
os.close(read_end)
check(p.write("mehr\n") is False and p.pending == b"", "a closed stdin is reported, nothing waits for ever")
holder.stdin.close()

worker = open(os.path.join(ROOT, "tools", "ember_py_worker.py"), encoding="utf-8").read()
check("import ember_project_procs" in worker and "ember_project_procs.ProcManager(" in worker
      and "threading.Thread(target=manager.run" in worker and "procs.join(60)" in worker
      and '"STU_EMBER_PROJECT_PROC_IDLE": "300"' in worker, "the worker runs the process manager in a thread")


# --- Real processes in the sandbox (Docker and ember-py:2) -----------------------------------------
def docker_ready():
    try:
        return subprocess.run(["docker", "image", "inspect", "ember-py:2"], stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, timeout=20).returncode == 0
    except Exception:
        return False


def stop(container):
    r = subprocess.run(["docker", "rm", "--force", container], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
    return r.returncode == 0 or b"No such container" in r.stderr


def wait_for(cond, seconds=20.0):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        if cond():
            return True
        time.sleep(0.1)
    return False


def staged(files):
    sha = lambda c: __import__("hashlib").sha256(c.encode("utf-8")).hexdigest()  # noqa: E731
    return dict((path, (content, sha(content))) for path, content in files.items())


sandbox = "Sandbox-Prozesse uebersprungen (Docker oder ember-py:2 fehlt)"
started = []


@atexit.register
def _cleanup():
    # A failed check must not leave a test container running.
    for proc in started:
        if proc.poll() is None:
            stop(proc.container)


if docker_ready():
    base = tempfile.mkdtemp(prefix="coreai-procs-")
    os.chmod(base, 0o777)
    cfg = {"STU_EMBER_PY_IMAGE": "ember-py:2"}

    # Input and output, then an exit code of its own.
    echo = procs.Proc(9101, "python ask.py")
    started.append(echo)
    echo.start(cfg, base, staged({"ask.py": "import sys\nprint('Name?', flush=True)\nname = sys.stdin.readline().strip()\n"
                                             "print('Hallo ' + name)\nprint('kaputt', file=sys.stderr)\nsys.exit(3)\n"}))
    check(wait_for(lambda: "Name?" in echo.snapshot()[0]), "the program's first line arrives: %r" % (echo.snapshot(),))
    check(echo.write("Mara\n"), "input is written")
    check(wait_for(lambda: echo.poll() is not None), "the program ends by itself")
    echo.wait_output()
    out = echo.snapshot()[0]
    check(out.index("Name?") < out.index("Hallo Mara") and "kaputt" in out and echo.poll() == 3,
          "input reached the program, output in order with stderr, exit code 3: %r rc=%r" % (out, echo.poll()))
    check(stop(echo.container), "container gone")
    echo.cleanup()

    # A development server: page, redirect, JSON, POST, missing page, sync, Beenden.
    app = ("import http.server, json\n"
           "class H(http.server.SimpleHTTPRequestHandler):\n"
           "    def do_GET(self):\n"
           "        if self.path.startswith('/api/zahl'):\n"
           "            body = json.dumps({'zahl': 42, 'pfad': self.path}).encode()\n"
           "            self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(body); return\n"
           "        return super().do_GET()\n"
           "    def do_POST(self):\n"
           "        n = int(self.headers.get('Content-Length') or 0)\n"
           "        body = ('erhalten: ' + self.rfile.read(n).decode() + ' als ' + self.headers.get('Content-Type', '')).encode()\n"
           "        self.send_response(201); self.send_header('Content-Type', 'text/plain'); self.end_headers(); self.wfile.write(body)\n"
           "print('Server bereit auf 8000', flush=True)\n"
           "http.server.ThreadingHTTPServer(('0.0.0.0', 8000), H).serve_forever()\n")
    server = procs.Proc(9102, "python app.py", 8000)
    started.append(server)
    server.start(cfg, base, staged({"app.py": app, "index.html": "<h1>Start</h1>", "docs/index.html": "<p>Doku</p>"}))
    check(wait_for(lambda: "Server bereit" in server.snapshot()[0]), "the server starts: %r" % (server.snapshot(),))
    page = {}
    check(wait_for(lambda: page.update(server.fetch("GET", "/")) or page.get("status") == 200, 15),
          "the page comes from inside the container: %r" % page)
    check(page["body"] == b"<h1>Start</h1>" and "text/html" in page["type"], "page body and type: %r" % page)
    docs = server.fetch("GET", "/docs")
    check(docs.get("status") == 200 and docs["path"] == "/docs/" and docs["body"] == b"<p>Doku</p>",
          "a redirect on the same server is followed: %r" % docs)
    data = server.fetch("GET", "/api/zahl?x=1")
    check(data.get("status") == 200 and b'"zahl": 42' in data["body"] and b"/api/zahl?x=1" in data["body"]
          and data["type"] == "application/json", "JSON with the query: %r" % data)
    posted = server.fetch("POST", "/senden", "text/plain", b"hallo")
    check(posted.get("status") == 201 and posted["body"] == b"erhalten: hallo als text/plain", "POST with body: %r" % posted)
    missing = server.fetch("GET", "/fehlt.html")
    check(missing.get("status") == 404, "a missing page: %r" % missing)
    check(server.fetch("GET", "/../../etc/passwd").get("status") in (400, 404), "no way out of the work directory")

    current = {"index.html": __import__("hashlib").sha256(b"<h1>Neu</h1>").hexdigest(),
               "app.py": server.staged["app.py"]}
    changed = server.sync(current, lambda paths: {"index.html": "<h1>Neu</h1>"})
    check(changed and server.fetch("GET", "/")["body"] == b"<h1>Neu</h1>", "a saved change is served after the sync")
    check(server.fetch("GET", "/docs/index.html").get("status") == 404, "a removed file is gone from the server")

    other = procs.Proc(9103, "sleep 60", 8000)
    started.append(other)
    other.start(cfg, base, staged({"a.txt": "x"}))
    check(os.get_blocking(other.popen.stdin.fileno()) is False, "a started process takes input without blocking the worker")
    check(wait_for(lambda: other.fetch("GET", "/").get("error") == "connect", 10), "a port without a server says so")
    t0 = time.monotonic()
    for _ in range(200):
        check(other.write("x" * 2000 + "\n"), "input is taken while the program does not read")
    check(time.monotonic() - t0 < 3 and len(other.pending) <= 400200,
          "input to a program that does not read never blocks the worker: %.1fs, %d waiting" % (time.monotonic() - t0, len(other.pending)))
    rc, confirmed = other.stop(stop, grace=1)
    check(confirmed and rc in (137, 143), "sleep ends on Beenden: %r" % rc)
    other.cleanup()

    # 0.7.79: a Node.js server (ember-py:2) answers the preview the same way, and ends with its own code.
    node_app = ("const http = require('node:http');\n"
                "http.createServer((req, res) => { res.setHeader('Content-Type', 'application/json');"
                " res.end(JSON.stringify({ node: process.version, pfad: req.url })); })\n"
                "  .listen(3000, 'localhost', () => console.log('Node-Server bereit'));\n")
    nodeproc = procs.Proc(9104, "node server.js", 3000)
    started.append(nodeproc)
    nodeproc.start(cfg, base, staged({"server.js": node_app}))
    check(wait_for(lambda: "Node-Server bereit" in nodeproc.snapshot()[0]), "the Node.js server starts: %r" % (nodeproc.snapshot(),))
    answer = nodeproc.fetch("GET", "/api?x=1")
    check(answer.get("status") == 200 and b'"node":"v24.' in answer["body"] and b'"pfad":"/api?x=1"' in answer["body"],
          "the Node.js server on localhost answers from inside the container: %r" % answer)
    rc, confirmed = nodeproc.stop(stop)
    check(confirmed and rc == 143, "Beenden ends the Node.js server with its real exit code: %r" % rc)
    nodeproc.cleanup()

    t0 = time.monotonic()
    rc, confirmed = server.stop(stop)
    check(confirmed and rc == 143 and time.monotonic() - t0 < 10,
          "Beenden ends the server with SIGTERM and its real exit code: rc=%r" % rc)
    check(server.fetch("GET", "/").get("error"), "no answer after the end")
    server.cleanup()
    check(os.listdir(base) == [], "work directories are removed: %r" % os.listdir(base))
    shutil.rmtree(base, ignore_errors=True)
    sandbox = ("echte Prozesse in ember-py:2 (Eingabe, Ausgabe in Reihenfolge, Exitcode, Server mit Seite, Weiterleitung, "
               "JSON, POST, 404, Abgleich, Node.js-Server, Beenden mit SIGTERM)")

print("CoreAI Projektprozesse (Worker): Docker-Zeile, Server-Anfrage, Antwort, Pruefsumme, Abgleich, Endzustaende, "
      "Puffer, Anbindung OK; " + sandbox)
