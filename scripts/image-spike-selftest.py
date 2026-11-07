#!/usr/bin/env python3
"""CoreAI 0.6.37 (P20.5 A): selftest of the image test, offline (standard library only).

  python3 scripts/image-spike-selftest.py

Checked: models.json (both groups, Apache 2.0, the runtime commit and ggml commit the
Dockerfile builds), the timing lines of stable-diffusion.cpp, PNG sizes; loading against a
stand-in Hugging Face (redirect with commit, SHA-256 and size, a dropped connection that is
resumed, a wrong SHA-256 that deletes the file), the lock and its check (a changed file is
refused); a run with a stand-in sd-cli (exactly the arguments, PNG of the asked size, times,
peak RAM; a failing call, a wrong size, a missing lock); the report and its summary; and the
dry run of scripts/image-spike.sh (build, fetch with network, run without network).
"""
from __future__ import annotations

import hashlib
import http.server
import json
import os
import struct
import subprocess
import sys
import tempfile
import threading
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'tools' / 'image_spike'))
import run as spike  # noqa: E402


def check(ok: bool, why: str) -> None:
    if not ok:
        print('Bildtest-Selbsttest FAIL: ' + why, file=sys.stderr)
        sys.exit(1)


def png(width: int, height: int) -> bytes:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    raw = b''.join(b'\x00' + b'\x80\x40\x20' * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) \
        + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


# --- Registry and Dockerfile --------------------------------------------------------------
reg = spike.load_registry()
check(set(reg['groups']) == {'zimage-turbo', 'flux2-klein-4b'}, 'the two groups the operator chose: ' + ', '.join(reg['groups']))
check(all(g['license'] == 'Apache-2.0' and all(f['license'] == 'Apache-2.0' for f in g['files']) for g in reg['groups'].values()),
      'every model and file under Apache 2.0')
check(reg['groups']['zimage-turbo']['steps'] == 8 and reg['groups']['flux2-klein-4b']['steps'] == 4
      and all(g['cfg_scale'] == 1.0 for g in reg['groups'].values()), 'steps and guidance as stable-diffusion.cpp documents them')
docker = (ROOT / 'docker' / 'image-spike' / 'Dockerfile').read_text(encoding='utf-8')
check(f'ARG SD_COMMIT={reg["runtime"]["commit"]}' in docker and f'ARG GGML_COMMIT={reg["runtime"]["ggml_commit"]}' in docker
      and '-DGGML_NATIVE=ON' in docker and '--target sd-cli' in docker and 'git checkout --detach "$SD_COMMIT"' in docker,
      'the Dockerfile builds exactly the registered commit, natively for the server CPU')
check(reg['runtime']['tag'].endswith(reg['runtime']['commit'][:7]), 'the release tag names the commit')
check(spike.registry_errors({'format': 1}) and spike.registry_errors({'format': 2}) == ['format muss 1 sein'], 'a broken registry is refused')
bad = json.loads(json.dumps(reg))
bad['groups']['zimage-turbo']['files'][0]['path'] = '../etc/passwd'
bad['plan'][0]['size'] = '500x500'
errors = spike.registry_errors(bad)
check(any('Pfad' in e for e in errors) and any('64' in e for e in errors), 'paths outside and odd sizes are refused: ' + '; '.join(errors))
plan_sizes = [step['size'] for step in reg['plan']]
check(plan_sizes == ['512x512', '1024x1024'] and reg['plan'][1]['prompts'] == ['p1'], 'three prompts at 512, one at 1024')

# --- Parsing ------------------------------------------------------------------------------
LOG = ('[INFO ] model.cpp:1426 - loading tensors completed, taking 21.40s (read: 3.1s)\n'
       '[INFO ] stable-diffusion.cpp:1259 - get_learned_condition completed, taking 4.20s\n'
       '[INFO ] stable-diffusion.cpp:1735 - sampling(high noise) completed, taking 99.00s\n'
       '[INFO ] stable-diffusion.cpp:1776 - sampling completed, taking 61.75s\n'
       '[INFO ] stable-diffusion.cpp:1290 - decode_first_stage completed, taking 6.10s\n')
check(spike.timings(LOG) == {'load_s': 21.4, 'text_s': 4.2, 'sampling_s': 61.75, 'decode_s': 6.1}, 'the times: ' + str(spike.timings(LOG)))
check(spike.timings('nothing') == {'load_s': None, 'text_s': None, 'sampling_s': None, 'decode_s': None}, 'no times: None')

with tempfile.TemporaryDirectory(prefix='coreai-image-spike-') as tmp_name:
    tmp = Path(tmp_name)
    (tmp / 'a.png').write_bytes(png(64, 32))
    (tmp / 'b.png').write_bytes(b'not a png')
    check(spike.png_size(tmp / 'a.png') == (64, 32) and spike.png_size(tmp / 'b.png') is None and spike.png_size(tmp / 'c.png') is None,
          'PNG size from the header, nothing for no PNG')

    # --- Loading against a stand-in Hugging Face -------------------------------------------
    blobs = {'org/model/weights.gguf': os.urandom(300_000), 'org/text/encoder.gguf': os.urandom(120_000),
             'org/vae/split_files/vae/ae.safetensors': os.urandom(50_000)}
    state = {'drop_once': True, 'bad_sha': False, 'gets': []}

    class Hub(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_HEAD(self):
            parts = self.path.split('/resolve/')
            repo, rest = parts[0].strip('/'), parts[1]
            revision, path = rest.split('/', 1)
            key = repo + '/' + path
            if key not in blobs:
                self.send_response(404)
                self.end_headers()
                return
            digest = hashlib.sha256(blobs[key]).hexdigest()
            self.send_response(302)
            self.send_header('Location', '/cdn/' + key)
            self.send_header('X-Repo-Commit', 'c0ffee' + '0' * 34)
            self.send_header('X-Linked-Etag', '"' + ('0' * 64 if state['bad_sha'] else digest) + '"')
            self.send_header('X-Linked-Size', str(len(blobs[key])))
            self.end_headers()

        def do_GET(self):
            key = self.path[len('/cdn/'):]
            data = blobs[key]
            start = 0
            rng = self.headers.get('Range')
            state['gets'].append((key, rng))
            if rng:
                start = int(rng.split('=')[1].split('-')[0])
            body = data[start:]
            self.send_response(206 if rng else 200)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            if state['drop_once'] and key.endswith('weights.gguf') and not rng:
                state['drop_once'] = False
                self.wfile.write(body[:100_000])
                self.wfile.flush()
                self.close_connection = True
                return
            self.wfile.write(body)

    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Hub)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    spike.HF = f'http://127.0.0.1:{server.server_address[1]}'
    sleeps = []
    spike.time.sleep = lambda s: sleeps.append(s)  # no waiting between attempts here
    test_reg = {'format': 1, 'runtime': reg['runtime'], 'prompts': reg['prompts'], 'plan': reg['plan'], 'groups': {'mini': {
        'label': 'Mini', 'origin': 'Test', 'license': 'Apache-2.0', 'steps': 2, 'cfg_scale': 1.0, 'files': [
            {'role': 'diffusion_model', 'repo': 'org/model', 'path': 'weights.gguf', 'size': len(blobs['org/model/weights.gguf']), 'license': 'Apache-2.0'},
            {'role': 'llm', 'repo': 'org/text', 'path': 'encoder.gguf', 'size': len(blobs['org/text/encoder.gguf']), 'license': 'Apache-2.0'},
            {'role': 'vae', 'repo': 'org/vae', 'path': 'split_files/vae/ae.safetensors', 'size': 50_000, 'license': 'Apache-2.0'}]}}}
    check(spike.registry_errors(test_reg) == [], 'the test registry is valid')
    models = tmp / 'models'
    models.mkdir()
    spike.fetch(test_reg, 'mini', models)
    lock = json.loads((models / 'lock-mini.json').read_text(encoding='utf-8'))
    check([e['role'] for e in lock['files']] == ['diffusion_model', 'llm', 'vae']
          and all(e['sha256'] == hashlib.sha256(blobs[e['repo'] + '/' + e['path']]).hexdigest() == e['hf_sha256'] for e in lock['files'])
          and all(e['revision'] == 'c0ffee' + '0' * 34 for e in lock['files']),
          'every file registered with commit and SHA-256, equal to Hugging Face\'s')
    check(('org/model/weights.gguf', 'bytes=100000-') in state['gets'],
          'a dropped connection is resumed where it stopped: ' + str(state['gets']))
    check(not list(models.rglob('*.part')), 'no partial files left')
    paths, entries, error = spike.verify(models, 'mini')
    check(error == '' and set(paths) == {'diffusion_model', 'llm', 'vae'} and Path(paths['vae']).name == 'ae.safetensors',
          'the lock checks out: ' + error)
    # A file changed after loading is refused; a wrong SHA-256 at Hugging Face deletes the download.
    target = Path(paths['llm'])
    original = target.read_bytes()
    target.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
    check('veraendert' in spike.verify(models, 'mini')[2], 'a changed file is refused')
    target.write_bytes(original)
    state['bad_sha'] = True
    try:
        spike.fetch_file(models, test_reg['groups']['mini']['files'][1])
        check(False, 'a wrong SHA-256 must fail')
    except RuntimeError as failure:
        check('SHA-256' in str(failure) and not target.exists(), 'a wrong SHA-256 deletes the file: ' + str(failure))
    state['bad_sha'] = False
    spike.fetch_file(models, test_reg['groups']['mini']['files'][1])
    check(spike.verify(models, 'mini')[2] == '', 'loaded again, it checks out')
    check(spike.verify(models, 'other')[2].startswith('Gewichte nicht geladen'), 'no lock: said so')
    server.shutdown()

    # --- A run with a stand-in sd-cli ----------------------------------------------------------
    args_log = tmp / 'args.jsonl'
    stub = tmp / 'sd-cli'
    stub.write_text(f'''#!{sys.executable}
import json, struct, sys, zlib
args = sys.argv[1:]
open({str(args_log)!r}, 'a').write(json.dumps(args) + '\\n')
prompt = args[args.index('-p') + 1]
if 'FAIL' in prompt:
    print('[ERROR] something broke'); sys.exit(3)
w, h = int(args[args.index('-W') + 1]), int(args[args.index('-H') + 1])
if 'WRONG' in prompt:
    w = w // 2
def chunk(kind, data):
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
raw = b''.join(b'\\x00' + b'\\x10\\x20\\x30' * w for _ in range(h))
open(args[args.index('-o') + 1], 'wb').write(b'\\x89PNG\\r\\n\\x1a\\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
    + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))
block = bytearray(30 * 1024 * 1024)  # some RAM, so the peak shows
print({LOG!r})
''', encoding='utf-8')
    stub.chmod(0o755)
    out = tmp / 'out'
    out.mkdir()
    result = spike.run_group(test_reg, 'mini', models, out, 3, sd_cli=str(stub), timeout=60)
    calls = [json.loads(line) for line in args_log.read_text(encoding='utf-8').splitlines()]
    check(result['ok'] and len(result['images']) == 4 and all(i['ok'] for i in result['images']),
          'four images (three at 512, one at 1024): ' + json.dumps([i['error'] for i in result['images']]))
    first = calls[0]
    check(first[0] == '--diffusion-model' and first[first.index('--diffusion-model') + 1] == paths['diffusion_model']
          and first[first.index('--llm') + 1] == paths['llm'] and first[first.index('--vae') + 1] == paths['vae']
          and first[first.index('-p') + 1] == reg['prompts'][0]['text'] and first[first.index('-s') + 1] == '42'
          and first[first.index('--steps') + 1] == '2' and first[first.index('--cfg-scale') + 1] == '1.0'
          and first[first.index('-t') + 1] == '3' and first[first.index('-W') + 1] == '512' and '-v' in first,
          'exactly the registered files, prompt, seed, steps, guidance and threads: ' + json.dumps(first))
    check([c[c.index('-W') + 1] + 'x' + c[c.index('-H') + 1] for c in calls] == ['512x512'] * 3 + ['1024x1024'], 'sizes in plan order')
    image = result['images'][0]
    check(image['file'] == 'mini-512x512-p1.png' and spike.png_size(out / image['file']) == (512, 512)
          and image['sampling_s'] == 61.75 and image['load_s'] == 21.4 and image['wall_s'] > 0
          and (image['peak_rss_mb'] or 0) >= 30, 'image, times and peak RAM of exactly that call: ' + json.dumps(image))
    check(spike.FILE_RE.match(image['file']) and (out / 'logs' / 'mini-512x512-p1.log').is_file(), 'file name for Admin Core and the log kept')
    check(len(spike.run_group(test_reg, 'mini', models, out, 3, full=True, sd_cli=str(stub), timeout=60)['images']) == 6,
          '--full: every prompt at every size')
    broken = json.loads(json.dumps(test_reg))
    broken['prompts'][1]['text'] = 'FAIL please'
    broken['prompts'][2]['text'] = 'WRONG size'
    result = spike.run_group(broken, 'mini', models, out, 3, sd_cli=str(stub), timeout=60)
    errors = {i['prompt'] + '-' + i['size']: i['error'] for i in result['images']}
    check(result['ok'] and errors['p2-512x512'].startswith('sd-cli Exit 3') and errors['p3-512x512'] == 'PNG 256x512 statt 512x512'
          and not (out / 'mini-512x512-p3.png').exists() and not (out / 'mini-512x512-p2.png').exists(),
          'a failing call and a wrong size are errors, their files go: ' + json.dumps(errors))
    gone = spike.run_group(test_reg, 'mini', tmp / 'empty', out, 3, sd_cli=str(stub), timeout=60)
    check(not gone['ok'] and gone['error'].startswith('Gewichte nicht geladen') and gone['images'] == [], 'without weights: nothing runs')
    check(not spike.run_group(test_reg, 'mini', models, out, 3, sd_cli=str(tmp / 'nope'), timeout=60)['ok'], 'without sd-cli: an error')

    # --- Report ---------------------------------------------------------------------------------
    run_dir = tmp / 'runs' / '20261107-101500'
    run_dir.mkdir(parents=True)
    good = spike.run_group(test_reg, 'mini', models, run_dir, 3, sd_cli=str(stub), timeout=60)
    spike.write_json(run_dir / 'mini.json', good)
    (run_dir / 'host.txt').write_text('Model name: AMD EPYC\nAVX-512: avx512f\n', encoding='utf-8')
    data = spike.report(run_dir, test_reg)
    check(data['format'] == 1 and data['stamp'] == '20261107-101500' and data['groups'][0]['summary']['512x512']['images'] == 3
          and data['groups'][0]['summary']['1024x1024']['sampling_s'] == 61.75 and 'EPYC' in data['host'] and data['errors'] == [],
          'report with summary per size: ' + json.dumps(data['groups'][0]['summary']))
    md = (run_dir / 'report.md').read_text(encoding='utf-8')
    check('| p1 | 512x512 |' in md and 'sha256' in md and 'Seed 42' in md and reg['runtime']['commit'] in md, 'report.md with times, files, seeds')
    check(json.loads((run_dir / 'report.json').read_text(encoding='utf-8'))['groups'][0]['images'][0]['file'] == 'mini-512x512-p1.png',
          'report.json lists the images')

    # --- The script ---------------------------------------------------------------------------
    env = dict(os.environ, COREAI_IMAGE_SPIKE_BASE=str(tmp / 'base'))
    dry = subprocess.run(['bash', str(ROOT / 'scripts' / 'image-spike.sh'), '--dry-run', '--threads', '1'], capture_output=True, text=True,
                         env=env, timeout=60)
    text = dry.stdout + dry.stderr
    check(dry.returncode == 0, 'dry run: ' + text[-400:])
    check('docker build --pull -t coreai-image-spike:0.6.37' in text and text.count('run.py fetch --group') == 2
          and text.count('--network none') == 2 and 'run.py run --group zimage-turbo' in text and 'run.py report --run-dir' in text
          and '-v ' + str(tmp / 'base' / 'models') + ':/models:ro' in text and '--cap-drop ALL' in text,
          'build, fetch with network, run without network and read-only weights, report: ' + text[-600:])
    check(not (tmp / 'base').exists(), 'a dry run creates nothing')
    one = subprocess.run(['bash', str(ROOT / 'scripts' / 'image-spike.sh'), '--dry-run', '--threads', '1', '--groups', 'flux2-klein-4b', '--full'],
                         capture_output=True, text=True, env=env, timeout=60)
    check(one.returncode == 0 and 'zimage-turbo' not in one.stdout and '--full' in one.stdout, 'one group, all sizes')
    for bad_args in (['--groups', 'x;rm'], ['--threads', 'viele'], ['--groups', 'nichtda'], ['--unbekannt']):
        res = subprocess.run(['bash', str(ROOT / 'scripts' / 'image-spike.sh'), '--dry-run', *bad_args], capture_output=True, text=True,
                             env=env, timeout=60)
        check(res.returncode != 0, 'refused: ' + ' '.join(bad_args))

print('CoreAI Bildtest: Register (Apache 2.0, Commit wie im Dockerfile), Zeiten, PNG, Laden mit Wiederaufnahme und SHA-256,'
      ' Lock-Pruefung, Lauf mit festen Argumenten, Fehler, Bericht und Trockenlauf OK')
