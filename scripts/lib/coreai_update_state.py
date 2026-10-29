#!/usr/bin/env python3
"""CoreAI 0.5.83: state files of the self-update (host side, run by root).

scripts/coreai-updater.sh calls this helper for everything that is JSON:

  notes CHANGELOG LOCAL_VERSION         sections newer than LOCAL_VERSION
  available FILE COMMIT VERSION NOTES_FILE [ERROR]
                                        result of a check (an error keeps the
                                        last known result)
  status FILE key=value ...             merges fields into status.json
  get FILE KEY                          prints one field
  request FILE                          reads the request of the update window
                                        (no symlinks, small, strict fields)
  follow FILE                           passes the installer output through
                                        (stdin to stdout, the update log) and
                                        turns its milestones into progress

Every file is written to a temporary file in the same directory and renamed,
so a reader never sees half a file. Files are 0640 root:33 so the web app can
read them; it can never write here (var/update is root's).
"""

import json
import os
import re
import stat
import sys
import tempfile
from datetime import datetime, timezone

NOTES_MAX = 8000
REQUEST_MAX = 4096
VERSION_RE = re.compile(r'^[0-9]+\.[0-9]+\.[0-9]+$')
COMMIT_RE = re.compile(r'^[0-9a-f]{40}$')
STATUS_KEYS = {
    'state', 'phase', 'message', 'started_at', 'finished_at', 'from_version',
    'to_version', 'commit', 'restart_ollama', 'requested_by', 'backup',
    'percent', 'step',
}

# The installation (phase install) runs from 10 to 90 percent. Each milestone is
# a line that setup.sh, install.sh or the preflight prints; the update test
# checks that every one of them still exists in those scripts.
INSTALL_FROM, INSTALL_TO = 10, 90
MILESTONES = [
    ('Lade den neuesten CoreAI: TCI Interface-Entwicklungsstand aus main.', 12, 'Der neue Stand wird von GitHub geladen.'),
    ('Aktueller GitHub-Commit:', 14, 'Der neue Stand ist geladen.'),
    ('Bestehende Installation erkannt.', 15, 'Die Dateien werden ersetzt, alle Daten bleiben erhalten.'),
    ('Bereite den isolierten Parallelbetrieb', 18, 'Die Installation wird vorbereitet.'),
    ('Aktualisiere numerische Content-Cache-Schluessel', 20, 'Die Cache-Schlüssel werden erneuert.'),
    ('Pruefe die paketierten Python-Quellen.', 22, 'Die Python-Quellen werden geprüft.'),
    ('Baue die isolierten PHP-, Python-Worker- und optionalen Browse-Images.', 24, 'Die Images werden gebaut.'),
    ('Pruefe pdo_mysql im frisch gebauten PHP-Image.', 40, 'Das PHP-Image wird geprüft.'),
    ('Baue das wegwerfbare Python-Sandbox-Image', 42, 'Die Python-Sandbox wird gebaut.'),
    ('Ollama-Basismodell', 50, 'Die Cores werden geprüft.'),
    ('Erzeuge das getrennte Ollama-Modell', 53, 'Maras Core wird erzeugt.'),
    ('Starte die eigene CoreAI: TCI Interface-Datenbank', 57, 'Die Datenbank startet.'),
    ('Importiere alle CoreAI: TCI Interface-Migrationen', 60, 'Die Datenbank-Migrationen laufen.'),
    ('Starte die eigene SearXNG-Instanz', 64, 'Die Websuche startet.'),
    ('Starte CoreAI: TCI Interface-Web und PHP auf', 67, 'Web und PHP starten neu.'),
    ('Starte den isolierten CoreAI: TCI Interface-Python-Worker.', 73, 'Der Python-Worker startet.'),
    ('Update-Dienst aktiv:', 75, 'Der Update-Dienst ist eingerichtet.'),
    ('Installation erfolgreich.', 77, 'Die Installation ist abgeschlossen.'),
    ('Compose-Preflight, Version', 79, 'Der Preflight prüft alle Funktionen.'),
    ('Entwicklerstand erfolgreich installiert:', 90, 'Die Installation ist geprüft.'),
]
# Docker builds print "[php 3/7]" or "[ 2/4]": they fill their stretch of the bar.
BUILD_STRETCHES = {24: 40, 42: 50}
BUILD_RE = re.compile(r'\[(?:[\w.-]+ +)?(\d+)/(\d+)\]')
PREFLIGHT_FROM, PREFLIGHT_TO = 79, 89
PREFLIGHT_RE = re.compile(r'^\[(?:OK|WARN|FEHLER)\]')
PREFLIGHT_DEFAULT_LINES = 170
STATES = {'idle', 'running', 'done', 'failed'}


def now():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def read_json(path):
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def write_json(path, data):
    folder = os.path.dirname(os.path.abspath(path))
    fd, tmp = tempfile.mkstemp(prefix='.tmp-', dir=folder)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(data, handle, ensure_ascii=False, indent=1, sort_keys=True)
            handle.write('\n')
        os.chmod(tmp, 0o640)
        if os.geteuid() == 0:
            os.chown(tmp, 0, 33)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def version_key(text):
    return tuple(int(part) for part in text.split('.'))


def changelog_notes(changelog, local_version):
    """The changelog sections above LOCAL_VERSION (newest first), trimmed."""
    try:
        with open(changelog, 'r', encoding='utf-8', errors='replace') as handle:
            lines = handle.read().splitlines()
    except OSError:
        return ''
    local = version_key(local_version) if VERSION_RE.match(local_version or '') else None
    out = []
    taking = False
    for line in lines:
        head = re.match(r'^## ([0-9]+\.[0-9]+\.[0-9]+)\b', line)
        if head:
            version = head.group(1)
            if local is not None and version_key(version) <= local:
                break
            taking = True
            out.append(line)
            continue
        if taking:
            out.append(line)
    text = '\n'.join(out).strip()
    if len(text) > NOTES_MAX:
        cut = text.rfind('\n', 0, NOTES_MAX)
        text = text[:cut if cut > 0 else NOTES_MAX].rstrip() + '\n\n...'
    return text


def cmd_available(path, commit, version, notes_file, error=''):
    data = read_json(path)
    data['checked_at'] = now()
    if error:
        data['error'] = error[:200]
    else:
        if not COMMIT_RE.match(commit):
            raise SystemExit('invalid commit')
        data['error'] = ''
        data['latest_commit'] = commit
        data['latest_version'] = version if VERSION_RE.match(version or '') else ''
        notes = ''
        if notes_file:
            try:
                with open(notes_file, 'r', encoding='utf-8', errors='replace') as handle:
                    notes = handle.read(NOTES_MAX + 16).strip()
            except OSError:
                notes = ''
        data['notes'] = notes[:NOTES_MAX + 8]
    write_json(path, data)


def cmd_status(path, pairs):
    data = read_json(path)
    for pair in pairs:
        key, sep, value = pair.partition('=')
        if not sep or key not in STATUS_KEYS:
            raise SystemExit('invalid status field: ' + key)
        if key == 'state' and value not in STATES:
            raise SystemExit('invalid state: ' + value)
        if key in ('restart_ollama',):
            data[key] = value == '1'
        elif key in ('requested_by', 'percent'):
            data[key] = int(value) if value.isdigit() else 0
        else:
            data[key] = value[:600]
    data['updated_at'] = now()
    write_json(path, data)


def cmd_get(path, key):
    value = read_json(path).get(key, '')
    if isinstance(value, bool):
        value = '1' if value else '0'
    print(value)


def cmd_request(path):
    """Prints restart_ollama=0|1 and requested_by=N; exit 2 when unusable."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, 'O_NONBLOCK', 0))
    except OSError:
        return 2
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > REQUEST_MAX:
            return 2
        raw = os.read(fd, REQUEST_MAX + 1)
    finally:
        os.close(fd)
    try:
        data = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, ValueError):
        return 2
    if not isinstance(data, dict) or data.get('action') != 'update':
        return 2
    restart = data.get('restart_ollama')
    by = data.get('requested_by')
    if not isinstance(restart, bool) or not isinstance(by, int) or isinstance(by, bool) or by <= 0:
        return 2
    print('restart_ollama=' + ('1' if restart else '0'))
    print('requested_by=' + str(by))
    return 0


def cmd_follow(path):
    """Copies the installer output to stdout line by line and writes progress.

    Never stops early and never raises: the installer writes into this pipe,
    and a broken pipe would end the installation half way.
    """
    try:
        known = read_json(path)
        expected = int(known.get('preflight_lines') or 0) or PREFLIGHT_DEFAULT_LINES
    except Exception:  # noqa: BLE001 - progress is optional, the copy is not
        expected = PREFLIGHT_DEFAULT_LINES
    percent, step, stretch, preflight = INSTALL_FROM, '', None, 0
    out = sys.stdout.buffer
    while True:
        raw = sys.stdin.buffer.readline()
        if not raw:
            break
        try:
            out.write(raw)
            out.flush()
        except Exception:  # noqa: BLE001
            pass
        try:
            line = raw.decode('utf-8', 'replace').strip()
            text = re.sub(r'^\[[^\]]*\]\s*', '', line)
            new_percent, new_step = percent, step
            for marker, mark, label in MILESTONES:
                if marker in text:
                    new_percent, new_step = max(percent, mark), label
                    stretch = mark if mark in BUILD_STRETCHES else None
                    break
            else:
                build = BUILD_RE.search(line) if stretch is not None else None
                if build and int(build.group(2)) > 0:
                    share = min(1.0, int(build.group(1)) / int(build.group(2)))
                    new_percent = max(percent, stretch + int((BUILD_STRETCHES[stretch] - stretch) * share))
                elif PREFLIGHT_RE.match(line):
                    preflight += 1
                    share = min(1.0, preflight / expected)
                    new_percent = max(percent, PREFLIGHT_FROM + int((PREFLIGHT_TO - PREFLIGHT_FROM) * share))
            new_percent = min(INSTALL_TO, new_percent)
            if new_percent != percent or new_step != step:
                percent, step = new_percent, new_step
                data = read_json(path)
                data['percent'] = percent
                if step:
                    data['step'] = step
                data['updated_at'] = now()
                write_json(path, data)
        except Exception:  # noqa: BLE001
            continue
    if preflight:
        try:
            data = read_json(path)
            data['preflight_lines'] = preflight
            write_json(path, data)
        except Exception:  # noqa: BLE001
            pass
    return 0


def main(argv):
    if len(argv) < 2:
        raise SystemExit(__doc__)
    command = argv[1]
    if command == 'notes' and len(argv) == 4:
        print(changelog_notes(argv[2], argv[3]))
        return 0
    if command == 'available' and len(argv) in (6, 7):
        cmd_available(argv[2], argv[3], argv[4], argv[5], argv[6] if len(argv) == 7 else '')
        return 0
    if command == 'status' and len(argv) >= 3:
        cmd_status(argv[2], argv[3:])
        return 0
    if command == 'get' and len(argv) == 4:
        cmd_get(argv[2], argv[3])
        return 0
    if command == 'request' and len(argv) == 3:
        return cmd_request(argv[2])
    if command == 'follow' and len(argv) == 3:
        return cmd_follow(argv[2])
    raise SystemExit(__doc__)


if __name__ == '__main__':
    sys.exit(main(sys.argv))
