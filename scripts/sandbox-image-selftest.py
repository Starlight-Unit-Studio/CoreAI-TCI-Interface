#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""CoreAI 0.7.79: the sandbox image ember-py:2 with Python 3.12 and Node.js 24.

    python3 scripts/sandbox-image-selftest.py

Offline: the Dockerfile brings the official Node.js binary with npm and npx, keeps the npm cache
in the work folder (exec is not allowed in /tmp) and still runs as user 65534; every place that
builds or starts the image uses the same tag ember-py:2, and the installers remove ember-py:1.
With Docker and ember-py:2 present, behind the walls of the runs: Python, Node.js 24 and npm
start, node --test passes and fails with its real output and exit code, npm test runs a
package.json script, a program in the npm cache in /work may run while /tmp stays noexec, and
the root file system is read-only. Without Docker those checks are skipped and named.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
TAG = "ember-py:2"


def check(ok, why):
    if not ok:
        print("Sandbox image FAIL: " + why, file=sys.stderr)
        sys.exit(1)


def read(rel):
    with open(os.path.join(ROOT, rel), encoding="utf-8") as fh:
        return fh.read()


# --- Offline: Dockerfile and one tag everywhere -----------------------------------------------------
docker = read("tools/ember_py.Dockerfile")
check("FROM node:24-bookworm-slim AS node" in docker and "FROM python:3.12-slim" in docker, "Node.js 24 next to Python 3.12")
check("COPY --from=node /usr/local/bin/node /usr/local/bin/node" in docker
      and "COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules" in docker
      and "ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm" in docker
      and "ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx" in docker, "node, npm and npx in the image")
check("NPM_CONFIG_CACHE=/work/.npm-cache" in docker and "NPM_CONFIG_UPDATE_NOTIFIER=false" in docker
      and "NODE_ENV" not in docker, "npm cache in /work, no update checks, no NODE_ENV forced on projects")
check(docker.rstrip().endswith('CMD ["python3"]') and "USER 65534:65534" in docker, "still an unprivileged user")

places = {
    "compose.yaml": "      STU_EMBER_PY_IMAGE: %s\n" % TAG,
    "docker/pyworker/entrypoint.sh": 'sandbox_image="${STU_EMBER_PY_IMAGE:-%s}"' % TAG,
    "tools/ember_py_worker.py": '"STU_EMBER_PY_IMAGE": "%s",' % TAG,
    "tools/ember_project_runs.py": 'cfg.get("STU_EMBER_PY_IMAGE", "%s")' % TAG,
    "tools/ember_project_procs.py": 'cfg.get("STU_EMBER_PY_IMAGE", "%s")' % TAG,
    "scripts/install.sh": 'docker build -t %s -f "$PROJECT_ROOT/tools/ember_py.Dockerfile" "$PROJECT_ROOT/tools"' % TAG,
    "scripts/install-native.sh": 'docker build -t %s -f "$PROJECT_ROOT/tools/ember_py.Dockerfile" "$PROJECT_ROOT/tools"' % TAG,
}
for rel, needle in places.items():
    check(needle in read(rel), "%s uses %s" % (rel, TAG))
for rel, restart in [("scripts/install.sh", "compose up -d pyworker\n"),
                     ("scripts/install-native.sh", "systemctl restart ember-coreui-python.service\n")]:
    text = read(rel)
    check(restart in text and "docker image rm ember-py:1 >/dev/null 2>&1 || true" in text
          and text.index("docker build -t " + TAG) < text.index(restart) < text.index("docker image rm ember-py:1"),
          "%s builds %s, restarts the worker on the new code, and only then removes ember-py:1" % (rel, TAG))
for rel in list(places) + ["tools/ember_py.Dockerfile", "scripts/preflight.sh", "scripts/preflight-native.sh"]:
    for line in read(rel).splitlines():
        if "ember-py:1" in line:
            check("image rm ember-py:1" in line or line.lstrip().startswith("#"), "no code uses ember-py:1 any more: %s: %s" % (rel, line.strip()))
for rel in ["scripts/preflight.sh", "scripts/preflight-native.sh"]:
    check("ember-py:2 sh -c 'python --version && node --version && npm --version'" in read(rel), rel + " checks Python and Node.js in the image")
hint = read("api/project_file_tools.php")
check("(Python 3.12 und \"\n      . \"Node.js 24 mit npm)" in hint and "[AUSFUEHREN: npm test]" in hint and "kein Node.js" not in hint,
      "Mara knows Node.js is there")


# --- Behind the walls (Docker and ember-py:2) --------------------------------------------------------
def image_ready():
    try:
        return subprocess.run(["docker", "image", "inspect", TAG], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              timeout=20).returncode == 0
    except Exception:
        return False


def walls(workdir, command):
    # The same walls as tools/ember_project_runs.py docker_command, without network.
    return subprocess.run([
        "docker", "run", "--rm", "--network", "none", "--memory", "512m", "--memory-swap", "512m", "--cpus", "1.0",
        "--pids-limit", "128", "--cap-drop", "ALL", "--security-opt", "no-new-privileges", "--read-only",
        "--tmpfs", "/tmp:rw,noexec,nosuid,size=64m", "--user", "65534:65534", "-v", "%s:/work:rw" % workdir,
        "-w", "/work", "-e", "HOME=/tmp", TAG, "sh", "-c", command,
    ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)


def project(files):
    work = tempfile.mkdtemp(prefix="coreai-image-")
    os.chmod(work, 0o777)
    for rel, content in files.items():
        target = os.path.join(work, *rel.split("/"))
        os.makedirs(os.path.dirname(target), exist_ok=True)
        with open(target, "w", encoding="utf-8") as fh:
            fh.write(content)
    for base, dirs, names in os.walk(work):
        for name in dirs:
            os.chmod(os.path.join(base, name), 0o777)
        for name in names:
            os.chmod(os.path.join(base, name), 0o666)
    return work


live = "Laeufe im Image uebersprungen (Docker oder %s fehlt)" % TAG
if image_ready():
    work = project({
        "add.js": "module.exports = (a, b) => a + b;\n",
        "test/add.test.js": "const test = require('node:test');\nconst assert = require('node:assert');\n"
                            "const add = require('../add');\ntest('add', () => { assert.strictEqual(add(2, 3), 5); });\n",
        "package.json": '{"name": "demo", "version": "1.0.0", "scripts": {"test": "node --test"}}\n',
    })
    try:
        tools = walls(work, "python --version && node --version && npm --version && npx --version")
        out = tools.stdout.decode()
        check(tools.returncode == 0 and re.search(r"^Python 3\.12\.", out, re.M) and re.search(r"^v24\.", out, re.M),
              "Python 3.12, Node.js 24, npm and npx start: %r %r" % (out, tools.stderr[-300:]))
        passing = walls(work, "node --test")
        check(passing.returncode == 0 and "pass 1" in passing.stdout.decode() and "fail 0" in passing.stdout.decode(),
              "node --test passes: %r" % passing.stdout[-400:])
        npm = walls(work, "npm test")
        check(npm.returncode == 0 and "pass 1" in npm.stdout.decode(), "npm test runs the package script: %r" % npm.stdout[-400:])
        with open(os.path.join(work, "add.js"), "w", encoding="utf-8") as fh:
            fh.write("module.exports = (a, b) => a - b;\n")
        failing = walls(work, "node --test")
        check(failing.returncode == 1 and "fail 1" in failing.stdout.decode() and "-1 !== 5" in failing.stdout.decode(),
              "node --test fails with the real output and exit code: %r" % failing.stdout[-600:])
        execs = walls(work, "mkdir -p .npm-cache/bin && printf '#!/bin/sh\\necho im-cache\\n' > .npm-cache/bin/x && chmod +x .npm-cache/bin/x"
                            " && .npm-cache/bin/x && cp .npm-cache/bin/x /tmp/x && (/tmp/x || echo tmp-noexec)"
                            " && (touch /usr/local/x 2>/dev/null || echo root-ro) && id -u")
        lines = execs.stdout.decode().split()
        check(execs.returncode == 0 and lines == ["im-cache", "tmp-noexec", "root-ro", "65534"],
              "programs from the npm cache in /work run, /tmp stays noexec, root read-only, user 65534: %r %r" % (lines, execs.stderr[-200:]))
    finally:
        shutil.rmtree(work, ignore_errors=True)
    live = "%s hinter den Mauern: Python 3.12, Node.js 24, npm, node --test gruen und rot, npm test, Cache ausfuehrbar, /tmp ohne exec" % TAG

print("CoreAI Sandbox-Image: Node.js im Dockerfile, ein Tag ueberall, alter Tag wird entfernt, Hinweis fuer Mara OK; " + live)
