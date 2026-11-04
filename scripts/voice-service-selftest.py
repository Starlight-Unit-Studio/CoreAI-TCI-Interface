#!/usr/bin/env python3
"""CoreAI 0.6.1 (0.6.5): Mara's voice service (P20.3) without Pocket and without network.

What of an answer is read (code, tables, links, lists, emojis), the German
text preparation in between, the parts (first one short), and the service
itself with its probe engine: health, plan, WAV, one job at a time (busy),
time limit, bad input, loopback only. Plus the wiring: image pinned like
the voice test, compose service optional and locked down, installer and
stack keep CoreAI running when the voice is missing.
"""
from __future__ import annotations

import importlib.util
import json
import os
import re
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def check(ok: bool, why: str) -> None:
    if not ok:
        print('Voice service FAIL: ' + why, file=sys.stderr)
        sys.exit(1)


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


speakable = load('speakable', ROOT / 'tools' / 'voice' / 'speakable.py')
textprep = load('textprep', ROOT / 'tools' / 'tts_spike' / 'textprep_de.py')

# --- What is read -------------------------------------------------------------------------
answer = """## Ergebnis

Hier ist **deine** Übersicht vom 4. November 2026 😀:

- Datei `index.html` ist fertig, siehe [Doku](https://example.com/doku).
- Größe: 15,2 KB

```python
print("geheim")
```

| A | B |
|---|---|
| 1 | 2 |

[WEB: suche nach etwas]
Mehr unter https://coreai.test/x. Fertig! Ein Absatz,
der umgebrochen ist."""
plain = speakable.speakable(answer)
check('print' not in plain and 'geheim' not in plain and 'Codeblock ausgelassen.' in plain, 'code is named, not read')
check('Tabelle ausgelassen.' in plain and '| 1 |' not in plain, 'table is named, not read')
check('example.com' not in plain and 'siehe Doku.' in plain and 'Mehr unter Link.' in plain, 'links: text kept, addresses become "Link"')
check('[WEB:' not in plain and '**' not in plain and '`' not in plain and '#' not in plain, 'markers dropped')
check('\U0001F600' not in plain, 'emoji dropped')
check(plain.splitlines()[:4] == ['Ergebnis.', 'Hier ist deine Übersicht vom 4. November 2026:', 'Datei index.html ist fertig, siehe Doku.', 'Größe: 15,2 KB.'],
      'headings and list items are sentences: ' + repr(plain.splitlines()[:4]))
check('Ein Absatz, der umgebrochen ist.' in plain, 'wrapped lines joined')
parts = speakable.plan(answer, textprep.prepare)
check(parts[0].startswith('Ergebnis. Hier ist deine Übersicht vom vierten November zweitausendsechsundzwanzig'), 'first part: ' + parts[0])
check(len(parts[0]) <= speakable.FIRST_PART and all(len(p) <= speakable.MAX_PART for p in parts), 'first part short, others bounded')
check(all(len(parts[i]) <= speakable.part_limit(parts[:i]) for i in range(1, len(parts))), 'each part at most GROWTH times the one before')
check(not re.search(r'\d', ' '.join(parts)) and 'index Punkt Ha Te Em El' in ' '.join(parts), 'no digits reach the voice')
check(all(re.search(r'[.!?…:,;]$', p) for p in parts), 'every part ends with punctuation')
# 0.6.5: a short first sentence and then a long one left seconds of silence (Eve computes
# the long part while the short one plays). Parts now grow step by step, cut at commas.
grown = speakable.split_parts('Hallo, ich bin Mara und helfe dir gern. ' + ('Das ist ein langer Satz, der immer weiter geht, '
                              'weil er viele Nebensätze hat, und noch mehr Inhalt bringt. ') * 6 + 'Ende.')
check(len(grown[0]) <= speakable.FIRST_PART and len(grown[1]) <= int(speakable.GROWTH * len(grown[0])), 'no long part right after a short one')
check(all(len(grown[i]) <= speakable.part_limit(grown[:i]) for i in range(1, len(grown))) and max(map(len, grown)) > 2 * len(grown[0]),
      'parts grow step by step: ' + repr([len(g) for g in grown]))
check(' '.join(grown).count('langer Satz') == 6 and grown[-1].endswith('Ende.'), 'nothing lost')
tiny = speakable.split_parts('Ja. ' + 'Wort ' * 30)
check(len(tiny[0]) > 40 and all(len(tiny[i]) <= speakable.part_limit(tiny[:i]) for i in range(1, len(tiny)))
      and ' '.join(tiny).count('Wort') == 30, 'a tiny first sentence is filled up, the next part stays within GROWTH: ' + repr([len(t) for t in tiny]))
check(not any(re.search(r'[a-zäöü]\.$', g) and not g.endswith(('Inhalt bringt.', 'gern.', 'Ende.')) for g in grown),
      'a sentence cut between parts ends with a comma, not a full stop')
long_sentence = 'Wort ' * 200
cut = speakable.split_parts(long_sentence)
check(len(cut) > 2 and all(len(p) <= speakable.MAX_PART + 1 for p in cut) and ''.join(cut).count('Wort') == 200, 'long sentences cut at words, nothing lost')
check(all(p.endswith(',') for p in cut[:-1]) and cut[-1].endswith('.'), 'a cut sentence goes on with a comma')
check(speakable.plan('Ich bin die TCI von E.M.B.E.R. und helfe dir gern bei allem.', textprep.prepare)
      == ['Ich bin die Ti Si Ei von Ember und helfe dir gern bei allem.'], 'E.M.B.E.R. is read as a name, TCI with its I')
check(speakable.plan('```\nnur code\n```', textprep.prepare) == ['Codeblock ausgelassen.'], 'only code: says so')
check(speakable.plan('   ', textprep.prepare) == [], 'nothing to read')

# --- 0.6.5: no long silence -------------------------------------------------------------------
service_module = load('voice_service', ROOT / 'tools' / 'voice' / 'voice_service.py')
RATE = 24000


def tone(seconds: float) -> list[float]:
    return [0.3 * __import__('math').sin(2 * 3.14159 * 220 * i / RATE) for i in range(int(RATE * seconds))]


def quiet(seconds: float) -> list[float]:
    return [0.0] * int(RATE * seconds)


pcm = service_module.pcm16(quiet(1.0) + tone(0.5) + quiet(0.2) + tone(0.5) + quiet(2.5) + tone(0.5) + quiet(1.5))
tight = service_module.tighten_pcm(pcm, RATE)
seconds = len(tight) / 2 / RATE
expected = service_module.EDGE_S * 2 + 1.5 + 0.2 + service_module.MAX_PAUSE_S
check(abs(seconds - expected) < 0.03, f'silence: edges cut to {service_module.EDGE_S} s, long pause to {service_module.MAX_PAUSE_S} s, '
      f'short pause kept ({seconds:.2f} s, expected {expected:.2f} s)')
plain = service_module.pcm16(tone(1.0))
check(service_module.tighten_pcm(plain, RATE) == plain, 'speech without silence stays as it is')
check(service_module.tighten_pcm(service_module.pcm16(quiet(1.0)), RATE) == service_module.pcm16(quiet(1.0)), 'only silence: unchanged')
source_service = (ROOT / 'tools' / 'voice' / 'voice_service.py').read_text(encoding='utf-8')
check('return wav_from_pcm(tighten_pcm(pcm16(samples), rate), rate)' in source_service, 'every spoken part is tightened')

# --- The service with the probe engine -------------------------------------------------------
with socket.socket() as probe_socket:
    probe_socket.bind(('127.0.0.1', 0))
    port = probe_socket.getsockname()[1]
env = dict(os.environ, COREAI_VOICE_ENGINE='probe', COREAI_VOICE_PORT=str(port), COREAI_VOICE_PROBE_DELAY='0.6',
           COREAI_VOICE_QUEUE_WAIT='0.2')
service = subprocess.Popen([sys.executable, '-I', str(ROOT / 'tools' / 'voice' / 'voice_service.py')], env=env,
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)


def call(method: str, path: str, body: dict | None = None, raw: bytes | None = None):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    request = urllib.request.Request(f'http://127.0.0.1:{port}{path}', data=data, method=method)
    request.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            return response.status, response.headers.get('Content-Type', ''), response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get('Content-Type', ''), error.read()


try:
    for _ in range(50):
        try:
            status, _, body = call('GET', '/health')
            if json.loads(body).get('ready'):
                break
        except (urllib.error.URLError, ConnectionError):
            pass
        time.sleep(0.1)
    health = json.loads(call('GET', '/health')[2])
    check(health['ok'] and health['ready'] and not health['loading'] and health['textprep'] == 'de-2' and health['cores'] == 4, 'health')
    status, _, body = call('POST', '/plan', {'text': answer})
    check(status == 200 and json.loads(body)['parts'] == parts, 'plan from the service is the same')
    status, ctype, wav = call('POST', '/speak', {'text': 'Hallo, ich bin Mara.'})
    check(status == 200 and ctype == 'audio/wav' and wav[:4] == b'RIFF' and wav[8:12] == b'WAVE', 'WAV for a part')
    channels, rate, bits = int.from_bytes(wav[22:24], 'little'), int.from_bytes(wav[24:28], 'little'), int.from_bytes(wav[34:36], 'little')
    check((channels, rate, bits) == (1, 16000, 16) and int.from_bytes(wav[40:44], 'little') == len(wav) - 44, 'mono 16 bit, sizes right')
    # One job at a time: a second request waits briefly, then hears "busy".
    results = []
    first = threading.Thread(target=lambda: results.append(call('POST', '/speak', {'text': 'Erster Satz.'})[0]))
    first.start()
    time.sleep(0.15)
    busy = call('POST', '/speak', {'text': 'Zweiter Satz.'})
    first.join()
    check(results == [200] and busy[0] == 503 and json.loads(busy[2])['error'] == 'voice_busy', 'one synthesis at a time')
    check(call('POST', '/speak', {'text': 'Bitte TIMEOUT.'})[0] == 504, 'time limit reported')
    check(call('POST', '/speak', {'text': ''})[0] == 400 and call('POST', '/speak', {'text': 'x' * 1300})[0] == 400, 'empty or long part refused')
    check(call('POST', '/plan', raw=b'not json')[0] == 400 and call('POST', '/speak', {'nope': 1})[0] == 400, 'bad bodies refused')
    check(call('GET', '/nothing')[0] == 404 and call('POST', '/nothing', {'text': 'x'})[0] == 404, 'unknown paths')
finally:
    service.terminate()
    output = service.communicate(timeout=10)[0]
check(f'bereit auf 127.0.0.1:{port}' in output, 'listens on loopback only')
check('Hallo, ich bin Mara.' not in output and 'Erster Satz' not in output, 'no spoken text in the log')

# --- 0.6.5: new code after an update restarts the service ---------------------------------------
import tempfile
with tempfile.TemporaryDirectory() as tmp:
    marker = Path(tmp) / 'code.txt'
    marker.write_text('alt', encoding='utf-8')
    with socket.socket() as probe_socket:
        probe_socket.bind(('127.0.0.1', 0))
        watch_port = probe_socket.getsockname()[1]
    watcher = subprocess.Popen([sys.executable, '-I', str(ROOT / 'tools' / 'voice' / 'voice_service.py')],
                               env=dict(os.environ, COREAI_VOICE_ENGINE='probe', COREAI_VOICE_PORT=str(watch_port),
                                        COREAI_VOICE_WATCH_S='0.2', COREAI_VOICE_WATCH_FILES=str(marker)),
                               stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    time.sleep(0.8)
    check(watcher.poll() is None, 'the service keeps running while nothing changes')
    marker.write_text('neu', encoding='utf-8')
    os.utime(marker, ns=(time.time_ns() + 10**9, time.time_ns() + 10**9))
    try:
        code = watcher.wait(timeout=10)
    except subprocess.TimeoutExpired:
        watcher.kill()
        code = None
    log = watcher.communicate(timeout=10)[0]
    check(code == 0 and 'Sprachdienst startet neu' in log, 'a changed file ends the service cleanly, Docker starts it again')
check('COREAI_VOICE_WATCH_S: "20"' in (ROOT / 'compose.yaml').read_text(encoding='utf-8'), 'compose turns the watcher on')

# --- Wiring ------------------------------------------------------------------------------------
source = (ROOT / 'tools' / 'voice' / 'voice_service.py').read_text(encoding='utf-8')
check("ThreadingHTTPServer(('127.0.0.1', port)" in source, 'bound to 127.0.0.1')
check('self._check_pins()\n        self._fetch_and_verify()' in source and "raise RuntimeError('SHA-256 weicht ab: ' + entry['path'])" in source,
      'pins and SHA-256 checked before the model loads')
check("quantize=bool(model.get('quantize'))" in source and "get_state_for_audio_prompt(self.registry['voice']['name'])" in source,
      'Eve with int8 as registered')
dockerfile = (ROOT / 'docker' / 'voice' / 'Dockerfile').read_text(encoding='utf-8')
check('COPY docker/tts-spike/constraints-pocket.txt /tmp/constraints.txt' in dockerfile and 'torch==2.6.0' in dockerfile
      and 'download.pytorch.org/whl/cpu' in dockerfile and 'USER 33:33' in dockerfile, 'image: same pins as the voice test, CPU torch, not root')
compose = (ROOT / 'compose.yaml').read_text(encoding='utf-8')
block = compose[compose.index('\n  voice:\n'):compose.index('\n  pyworker:\n')]
for needle in ('profiles: ["voice"]', 'restart: unless-stopped', 'user: "33:33"', 'read_only: true', 'cpus: ${COREUI_VOICE_CORES:-4}', 'mem_limit: 6g',
               '- .:/var/www/coreui:ro', '- ./var/voice-models:/models', 'HF_HOME: /models/hf'):
    check(needle in block, 'compose voice: ' + needle)
check('- ./var/voice_cache:/var/www/coreui/var/voice_cache' in compose, 'PHP may write the read-aloud cache')
install = (ROOT / 'scripts' / 'install.sh').read_text(encoding='utf-8')
check('if compose build voice; then' in install and "warn 'Sprachdienst konnte nicht gebaut werden; CoreAI laeuft ohne Vorlesen weiter.'" in install
      and "compose up -d voice || warn" in install, 'installer: optional, CoreAI runs on without the voice')
check('INSTALL_VOICE="${REQUEST_INSTALL_VOICE:-${SAVED_INSTALL_VOICE:-1}}"' in install and 'COREUI_INSTALL_VOICE=$INSTALL_VOICE' in install, 'on by default, can be switched off')
stack = (ROOT / 'scripts' / 'stack.sh').read_text(encoding='utf-8')
check('voice_cache_dir="$PROJECT_ROOT/var/voice_cache"' in stack and 'voice_models_dir="$PROJECT_ROOT/var/voice-models"' in stack, 'folders for cache and weights')

print('CoreAI voice service: what is read, parts, text preparation, WAV, one job at a time, limits, loopback, image and wiring OK')
