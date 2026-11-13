#!/usr/bin/env python3
"""CoreAI 0.6.39 (P20.5 B): selftest of Mara's image service, offline (standard library only).

Checked: the registry (one model, three roles, sizes on the 64 grid, Dockerfile at the
registered commit; pins required before use); progress from sd-cli's output (the tensor
progress bar while loading is no sampling step); weights loaded from a stand-in Hugging Face
at their pinned revision, checked against the pinned SHA-256 and remembered, a changed file
deleted, a different SHA-256 at the Hub refused; the HTTP service with a stand-in sd-cli
(fixed arguments, the prompt one argument, real steps, PNG out, one job at a time, cancel,
time limit, failure, no PNG, bad requests, not ready, only 127.0.0.1); adopting the image
test's weights; and the wiring in compose, Dockerfile, installer, stack and update window.
"""
from __future__ import annotations

import hashlib
import http.server
import importlib.util
import json
import os
import struct
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def check(ok: bool, why: str) -> None:
    if not ok:
        print('CoreAI Bilddienst FAIL: ' + why, file=sys.stderr)
        sys.exit(1)


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


service_mod = load('coreai_image_service', ROOT / 'tools' / 'image' / 'image_service.py')
adopt_mod = load('coreai_image_adopt', ROOT / 'tools' / 'image' / 'adopt_spike.py')
SPIKE = service_mod.SPIKE

# --- Registry -------------------------------------------------------------------------
reg = json.loads((ROOT / 'config' / 'image' / 'mara-image.json').read_text(encoding='utf-8'))
check(reg['model']['id'] == 'flux2-klein-4b' and reg['model']['steps'] == 4 and reg['model']['license'] == 'Apache-2.0',
      'FLUX.2-klein 4B, 4 steps, Apache 2.0 (operator 07.11.2026)')
check(sorted(f['role'] for f in reg['files']) == ['diffusion_model', 'llm', 'vae']
      and all(f['license'] == 'Apache-2.0' for f in reg['files']), 'three Apache 2.0 files')
check({f['repo'] for f in reg['files']} == {'leejet/FLUX.2-klein-4B-GGUF', 'unsloth/Qwen3-4B-GGUF', 'Comfy-Org/Flux-2-Klein-4B'},
      'the repositories of the image test, in Hugging Face spelling')
spike_reg = json.loads((ROOT / 'tools' / 'image_spike' / 'models.json').read_text(encoding='utf-8'))
spike_files = {(f['repo'], f['path']): f['size'] for f in spike_reg['groups']['flux2-klein-4b']['files']}
check(all(spike_files.get((f['repo'], f['path'])) == f['size'] for f in reg['files']),
      'same files and sizes as in the image test (so its weights can be adopted)')
check(reg['sizes']['normal']['width'] == 512 and reg['sizes']['large']['width'] == 1024, '512 normal, 1024 only large')
pin_errors = service_mod.registry_errors(reg)
check(pin_errors == [], 'the registry is complete and pinned (revision and SHA-256 per file): ' + '; '.join(pin_errors))
# Pinned from the image test on the server (lock-flux2-klein-4b.json, 07.11.2026).
check({f['role']: (f['revision'][:12], f['sha256'][:12]) for f in reg['files']} == {
    'diffusion_model': ('3b1f5a9dc3ab', '0bba6951258e'), 'llm': ('22c9fc8a8c77', 'eed555233267'), 'vae': ('5b3abca5c0dc', '868fe7b343cc')},
    'the pins of the operator\'s image test')
unpinned_reg = json.loads(json.dumps(reg))
unpinned_reg['files'][2]['sha256'] = ''
check(any('SHA-256' in e for e in service_mod.registry_errors(unpinned_reg)), 'a file without SHA-256 is refused')
check(reg['runtime'] == {**spike_reg['runtime'], 'build': reg['runtime']['build']}, 'the runtime of the image test')
dockerfile = (ROOT / 'docker' / 'image' / 'Dockerfile').read_text(encoding='utf-8')
check(f'ARG SD_COMMIT={reg["runtime"]["commit"]}' in dockerfile and f'ARG GGML_COMMIT={reg["runtime"]["ggml_commit"]}' in dockerfile
      and '-DGGML_NATIVE=ON' in dockerfile and 'USER 33:33' in dockerfile
      and 'CMD ["python3", "-I", "/var/www/coreui/tools/image/image_service.py"]' in dockerfile,
      'Dockerfile: registered commits, native build, uid 33, the service')
bad = json.loads(json.dumps(reg))
bad['files'][0]['path'] = '../etc/passwd'
bad['sizes']['normal']['width'] = 500
errors = service_mod.registry_errors(bad)
check(any('Pfad' in e for e in errors) and any('normal' in e for e in errors), 'paths outside and odd sizes refused')

# Optional real asset integrity: before the user uploads the set, the
# default fallback is valid; already uploaded but malformed files must fail.
real_gallery = service_mod.MARA_REFS.load_manifest(ROOT)
check(real_gallery is not None, 'Mara reference manifest is part of the source')
real_names = [real_gallery['primary'], *real_gallery['identity'].values(),
              *real_gallery['style_only'].values()]
check(len(real_names) == 7 and len(set(real_names)) == 7,
      'exactly five identity and two hologram-style filenames, all distinct')
for name in real_names:
    image_path = ROOT / 'images' / 'mara_references' / name
    if image_path.exists() or image_path.is_symlink():
        check(service_mod.MARA_REFS._asset(ROOT, service_mod.MARA_REFS.DEFAULT_REL, name) is not None,
              'curated reference must contain real PNG/JPEG magic bytes: ' + name)

# --- Progress -------------------------------------------------------------------------
LOG = ('[INFO ] loading tensors\n  |==================================================| 1024/1024 - 900.00it/s\n'
       '[INFO ] model.cpp:1426 - loading tensors completed, taking 2.10s\n')
check(service_mod.progress(LOG, 4) == {'phase': 'text', 'step': 0, 'steps': 4}, 'loading the tensors is no sampling step')
LOG += '[INFO ] get_learned_condition completed, taking 4.20s\n  |=====>      | 1/4 - 13.10s/it\r  |==========> | 2/4 - 13.30s/it\r'
check(service_mod.progress(LOG, 4) == {'phase': 'sampling', 'step': 2, 'steps': 4}, 'two of four steps done')
LOG += '  |============| 4/4 - 13.40s/it\n[INFO ] sampling completed, taking 53.00s\n'
check(service_mod.progress(LOG, 4)['phase'] == 'decode' and service_mod.progress(LOG, 4)['step'] == 4, 'decoding after sampling')
check(service_mod.progress(LOG + '[INFO ] decode_first_stage completed, taking 6.1s\n', 4)['phase'] == 'save', 'saving at the end')
check(service_mod.progress('', 4)['phase'] == 'load', 'nothing yet: loading')


def png(width: int, height: int) -> bytes:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    raw = b''.join(b'\x00' + b'\x00\x00\x00' * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) \
        + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


with tempfile.TemporaryDirectory(prefix='coreai-image-service-') as tmp_name:
    tmp = Path(tmp_name)

    # The image service only resolves canonical and UUID-indexed account references.
    real_root = service_mod.ROOT
    reference_root = tmp / 'reference-root'
    (reference_root / 'images').mkdir(parents=True)
    (reference_root / 'var' / 'profile_media').mkdir(parents=True)
    (reference_root / 'assets' / 'chat_media').mkdir(parents=True)
    (reference_root / 'images' / 'mara_winter.jpg').write_bytes(b'canonical portrait')
    custom_uuid = 'a' * 32
    second_uuid = 'b' * 32
    (reference_root / 'var' / 'profile_media' / (custom_uuid + '.png')).write_bytes(png(64, 64))
    first_upload = reference_root / 'assets' / 'chat_media' / (custom_uuid + '.png')
    second_upload = reference_root / 'assets' / 'chat_media' / (second_uuid + '.jpg')
    first_upload.write_bytes(png(64, 64))
    second_upload.write_bytes(bytes.fromhex('ffd8ff') + b'fake-JPEG-bytes')
    service_mod.ROOT = reference_root
    try:
        check(service_mod.resolve_reference(None) is None, 'no reference for an unrelated image')
        check(service_mod.resolve_reference('default') == str(reference_root / 'images' / 'mara_winter.jpg'),
              'canonical portrait fallback')
        check(service_mod.resolve_reference('custom:' + custom_uuid) == str(reference_root / 'var' / 'profile_media' / (custom_uuid + '.png')),
              'custom account reference')
        # 0.6.58: the operator uploads the images separately. Until then the
        # original single-image default remains available without a crash.
        gallery_dir = reference_root / 'images' / 'mara_references'
        gallery_dir.mkdir()
        config_dir = reference_root / 'config' / 'image'
        config_dir.mkdir(parents=True)
        (config_dir / 'mara-references.json').write_bytes(
            (real_root / 'config' / 'image' / 'mara-references.json').read_bytes())
        manifest = service_mod.MARA_REFS.load_manifest(reference_root)
        check(manifest is not None, 'reference gallery manifest is loadable')
        gallery_names = [manifest['primary'], *manifest['identity'].values(),
                         *manifest['style_only'].values()]
        for name in gallery_names:
            content = png(64, 64) if name.endswith('.png') else (
                b'\xff\xd8\xff\xe0' + b'PhotoData' * 8 + b'\xff\xd9')
            (gallery_dir / name).write_bytes(content)
        select = service_mod.MARA_REFS.select
        base_gallery = select(reference_root, 'Mara in an office')
        check([item['name'] for item in base_gallery] == [
            'mara_identity_primary.png','mara_identity_cockpit.jpg','mara_identity_fullbody.jpg'
        ], 'standard portrait uses the frontal anchor with two other angles')
        check(service_mod.resolve_reference('default') == str(gallery_dir / manifest['primary']),
              'new Mara primary is preferred over the old fallback portrait')
        holo = select(reference_root, 'Ein gehendes Hologramm im Hafen')
        check([i['name'] for i in holo] == [
            'mara_identity_primary.png','mara_identity_fullbody.jpg',
            'mara_identity_city.jpg','mara_style_hologram_walking.jpg'
        ], 'walking hologram uses three photos of SAME Mara, one style-only shot')
        seated = select(reference_root, 'Mara sitzt als Hologramm auf einer Bank')
        check(seated[-1]['name'] == 'mara_style_hologram_seated.jpg'
              and len(seated) <= 4, 'sitting hologram selects seated style only')
        profile = select(reference_root, 'Ein Seitenprofil von Mara')
        check(profile[1]['name'] == 'mara_identity_profile.jpg'
              and all(i['role'] != 'style_only' for i in profile),
              'profile request uses real face angle without copying hologram look')
        check([i['name'] for i in select(reference_root, 'Mara mit einer weiteren Person',
                                         has_user_uploads=True)] == [manifest['primary']],
              'uploaded people never inherit optional Mara photos')
        (gallery_dir / 'mara_identity_cockpit.jpg').unlink()
        (gallery_dir / 'mara_identity_cockpit.jpg').symlink_to('/etc/passwd')
        check(all(i['name'] != 'mara_identity_cockpit.jpg' for i in select(reference_root, 'Portrait'))
              and all(str(i['path']).startswith(str(gallery_dir)) for i in base_gallery),
              'symlinks and paths outside the curated directory are ignored')
        (gallery_dir / 'mara_identity_cockpit.jpg').unlink()
        (gallery_dir / 'mara_identity_cockpit.jpg').write_bytes(b'\xff\xd8\xff' + b'Photo' * 12)
        fake_reg = json.loads(json.dumps(reg))
        fake_reg['measured'] = {}
        mock = service_mod.Service(fake_reg, tmp / 'test-models', tmp / 'curated-jobs', threads=1)
        mock._run = lambda _job, _prompt: None  # only inspect safe job construction
        canonical_job = mock.create('Mara in a science fiction port', 'normal', 1, 'default', [],
                                    'Ein gehendes Hologramm im Hafen')
        check(canonical_job['reference_count'] == 4
              and canonical_job['curated_mara_count'] == 4
              and len(canonical_job['reference_paths']) == 4
              and canonical_job['reference_paths'][0] == str(gallery_dir / manifest['primary'])
              and mock.public(canonical_job)['reference_count'] == 4,
              'only the canonical default gets the selected multi-reference set')
        custom_mock = service_mod.Service(fake_reg, tmp / 'test-models', tmp / 'custom-jobs', threads=1)
        custom_mock._run = lambda _job, _prompt: None
        custom_job = custom_mock.create('Mara in another style', 'normal', 1, 'custom:' + custom_uuid, [],
                                        'Ein gehendes Hologramm im Hafen')
        check(custom_job['reference_count'] == 1
              and custom_job['curated_mara_count'] == 0
              and custom_job['reference_paths'] == [
                  str(reference_root / 'var' / 'profile_media' / (custom_uuid + '.png'))],
              'custom assistant photos never mix in the default Mara gallery')
        joined_mock = service_mod.Service(fake_reg, tmp / 'test-models', tmp / 'join-jobs', threads=1)
        joined_mock._run = lambda _job, _prompt: None
        combined = joined_mock.create('Mara with a guest', 'normal', 1, 'default',
                                      ['upload:' + custom_uuid + '.png'], 'Mara with a guest')
        check(combined['reference_count'] == 2
              and combined['curated_mara_count'] == 1
              and combined['reference_paths'][1] == str(first_upload),
              'explicit user uploads remain separate and cannot be drowned by gallery photos')

        check(service_mod.resolve_uploaded_reference('upload:' + custom_uuid + '.png') == str(first_upload)
              and service_mod.resolve_uploaded_reference('upload:' + second_uuid + '.jpg') == str(second_upload),
              'two user reference files are resolved using validated UUID tokens')
        for token in ('/etc/passwd', 'upload:../../etc/passwd', 'upload:' + 'c' * 32 + '.png',
                      'upload:' + custom_uuid + '.exe'):
            try:
                service_mod.resolve_uploaded_reference(token)
                check(False, 'arbitrary or missing user reference accepted: ' + token)
            except ValueError:
                pass
        for bad_ref in ('../etc/passwd', '/etc/passwd', 'custom:../../etc/passwd', 'custom:' + 'b' * 32):
            try:
                service_mod.resolve_reference(bad_ref)
                check(False, 'reference must not allow arbitrary or missing file: ' + bad_ref)
            except ValueError:
                pass
    finally:
        service_mod.ROOT = real_root

    # --- Weights from a stand-in Hugging Face --------------------------------------------
    blobs = {'org/model/weights.gguf': os.urandom(200_000), 'org/text/encoder.gguf': os.urandom(90_000),
             'org/vae/split_files/vae/ae.safetensors': os.urandom(40_000)}
    commit = 'c0ffee' + '0' * 34
    hub = {'heads': [], 'gets': 0, 'bad_sha': False}

    class Hub(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_HEAD(self):
            repo, rest = self.path.split('/resolve/')
            revision, path = rest.split('/', 1)
            key = repo.strip('/') + '/' + path
            hub['heads'].append(revision)
            if key not in blobs or revision != commit:
                self.send_response(404)
                self.end_headers()
                return
            self.send_response(302)
            self.send_header('Location', '/cdn/' + key)
            self.send_header('X-Repo-Commit', commit)
            self.send_header('X-Linked-Etag', '"' + ('1' * 64 if hub['bad_sha'] else hashlib.sha256(blobs[key]).hexdigest()) + '"')
            self.send_header('X-Linked-Size', str(len(blobs[key])))
            self.end_headers()

        def do_GET(self):
            data = blobs[self.path[len('/cdn/'):]]
            hub['gets'] += 1
            self.send_response(200)
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)

    hub_server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Hub)
    threading.Thread(target=hub_server.serve_forever, daemon=True).start()
    SPIKE.HF = f'http://127.0.0.1:{hub_server.server_address[1]}'
    real_sleep = time.sleep
    SPIKE.time.sleep = lambda s: None  # the same module: no waiting between download attempts here

    test_reg = json.loads(json.dumps(reg))
    test_reg['files'] = [
        {'role': 'diffusion_model', 'repo': 'org/model', 'path': 'weights.gguf'},
        {'role': 'llm', 'repo': 'org/text', 'path': 'encoder.gguf'},
        {'role': 'vae', 'repo': 'org/vae', 'path': 'split_files/vae/ae.safetensors'}]
    for spec in test_reg['files']:
        data = blobs[spec['repo'] + '/' + spec['path']]
        spec.update(revision=commit, sha256=hashlib.sha256(data).hexdigest(), size=len(data), license='Apache-2.0')
    test_reg['sizes'] = {'normal': {'width': 64, 'height': 64, 'time_limit_s': 20},
                         'large': {'width': 128, 'height': 128, 'time_limit_s': 2}}
    test_reg['measured'] = {}
    check(service_mod.registry_errors(test_reg) == [], 'the test registry is complete and pinned')

    models = tmp / 'models'
    models.mkdir()
    svc = service_mod.Service(test_reg, models, tmp / 'work', threads=2)
    unpinned = service_mod.Service(unpinned_reg, models, tmp / 'work', threads=2)
    try:
        unpinned.ensure_weights()
        check(False, 'without pins the service must not load anything')
    except RuntimeError as failure:
        check('Register unvollstaendig' in str(failure) and hub['gets'] == 0, 'without pins nothing is loaded: ' + str(failure))
    paths = svc.ensure_weights()
    check(set(paths) == {'diffusion_model', 'llm', 'vae'} and hub['gets'] == 3 and set(hub['heads']) == {commit},
          'all three files loaded at the pinned revision: ' + str(hub['heads']))
    check(Path(paths['vae']) == models / 'org__vae' / 'split_files' / 'vae' / 'ae.safetensors', 'one folder per repository')
    verified = json.loads((models / 'verified.json').read_text(encoding='utf-8'))
    check(len(verified) == 3, 'every check remembered')
    hashed = []
    real_sha = SPIKE.sha256_file
    SPIKE.sha256_file = lambda p: hashed.append(p) or real_sha(p)
    svc.ensure_weights()
    check(hashed == [] and hub['gets'] == 3, 'a known, unchanged file is not hashed or loaded again')
    target = Path(paths['llm'])
    original = target.read_bytes()
    target.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
    try:
        svc.ensure_weights()
        check(False, 'a changed file must fail')
    except RuntimeError as failure:
        check('SHA-256' in str(failure) and not target.exists(), 'a changed file is deleted: ' + str(failure))
    svc.ensure_weights()
    check(target.read_bytes() == original and hub['gets'] == 4, 'and loaded again on the next start')
    SPIKE.sha256_file = real_sha
    target.unlink()
    hub['bad_sha'] = True
    try:
        svc.ensure_weights()
        check(False, 'another SHA-256 at the Hub must fail')
    except RuntimeError as failure:
        check('anderen SHA-256' in str(failure), 'a Hub with another SHA-256 is refused before loading: ' + str(failure))
    hub['bad_sha'] = False
    svc.ensure_weights()
    hub_server.shutdown()
    time.sleep = real_sleep

    # --- The service with a stand-in sd-cli -----------------------------------------------
    args_log = tmp / 'args.jsonl'
    stub = tmp / 'sd-cli'
    # This is an outer f-string that generates a second Python script.
    # Escape all byte-literal backslashes twice, or the generated script
    # will contain a literal NUL and fail before its --help can be read.
    stub.write_text(f'''#!{sys.executable}
import json, struct, sys, time, zlib
args = sys.argv[1:]
if args == ['--help']:
    print('  --preview-path <string>        path to write preview image to'); sys.exit(0)
open({str(args_log)!r}, 'a').write(json.dumps(args) + '\\n')
def chunk(k, d):
    return struct.pack('>I', len(d)) + k + d + struct.pack('>I', zlib.crc32(k + d) & 0xffffffff)
def png(w, h, rgb):
    raw = b''.join(b'\\x00' + bytes(rgb) * w for _ in range(h))
    return b'\\x89PNG\\r\\n\\x1a\\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')
prompt = args[args.index('-p') + 1]
steps = int(args[args.index('--steps') + 1])
w, h = int(args[args.index('-W') + 1]), int(args[args.index('-H') + 1])
out = args[args.index('-o') + 1]
print('  |=====| 300/300 - 900.00it/s', flush=True)
print('[INFO ] loading tensors completed, taking 0.10s', flush=True)
print('[INFO ] get_learned_condition completed, taking 0.05s', flush=True)
if 'FAIL' in prompt:
    sys.exit(3)
for i in range(1, steps + 1):
    sys.stdout.write('\\r  |===>   | %d/%d - 0.10s/it' % (i, steps)); sys.stdout.flush()
    if '--preview-path' in args:
        open(args[args.index('--preview-path') + 1], 'wb').write(png(w // 8, h // 8, (40 * i, 80, 200)))
    time.sleep(30 if 'SLOW' in prompt else 0.35)
print('\\n[INFO ] sampling completed, taking 0.40s', flush=True)
print('[INFO ] decode_first_stage completed, taking 0.05s', flush=True)
if 'NOPNG' in prompt:
    sys.exit(0)
open(out, 'wb').write(png(w, h, (16, 32, 48)))
''', encoding='utf-8')
    stub.chmod(0o755)
    # Check the simulator itself before interpreting a failed preview probe
    # as a problem with the real installed image service.
    stub_bytes = stub.read_bytes()
    check(b'\x00' not in stub_bytes, 'fixture sd-cli contains an invalid NUL byte')
    probe = subprocess.run([str(stub), '--help'], capture_output=True, text=True, timeout=10)
    check(probe.returncode == 0 and '--preview-path' in (probe.stdout + probe.stderr),
          'fixture sd-cli --help failed: ' + (probe.stderr or probe.stdout)[-500:])
    # This regression exercises *enabled* step previews even when the target
    # deployment intentionally sets COREAI_IMAGE_PREVIEW=0.
    os.environ['COREAI_IMAGE_PREVIEW'] = '1'

    svc = service_mod.Service(test_reg, models, tmp / 'work', threads=3, sd_cli=str(stub))
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), service_mod.make_handler(svc))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    base = f'http://127.0.0.1:{server.server_address[1]}'

    def call(method: str, path: str, body: dict | None = None) -> tuple[int, dict | bytes]:
        data = json.dumps(body).encode() if body is not None else None
        request = urllib.request.Request(base + path, data=data, method=method,
                                         headers={'Content-Type': 'application/json'} if data else {})
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                raw = response.read()
                return response.status, (raw if response.headers.get('Content-Type') == 'image/png' else json.loads(raw))
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read() or b'{}')

    status, body = call('POST', '/jobs', {'prompt': 'a fox', 'size': 'normal'})
    check(status == 503 and body['error'] in ('image_loading', 'image_unavailable'), 'not ready: no job')
    svc.start()
    for _ in range(100):
        if svc.ready or svc.error:
            break
        time.sleep(0.05)
    health = call('GET', '/health')[1]
    check(health['preview'] is True, 'selftest fixture sd-cli must advertise step previews: ' + json.dumps(health))
    check(health['ready'] and not health['busy'] and health['weights']['have'] == health['weights']['total'] > 0
          and health['sizes']['normal'] == {'width': 64, 'height': 64}, 'health: ready, weights complete: ' + json.dumps(health))

    # 0.7.46: exercise the Vision Core routes without network traffic. The import above
    # also ensures image_service.py loads with spec_from_file_location and python -I.
    svc.vision = service_mod.VisionCores(svc, test_reg)
    status, inventory = call('GET', '/vision')
    check(status == 200 and inventory['ok'] and inventory['active'] == 'default'
          and len(inventory['models']) == 1 and inventory['models'][0]['protected'],
          'Vision Core inventory: immutable built-in image generator')
    svc.vision.search = lambda query: [{'id': 'test', 'name': 'FLUX mock'}] if query == 'flux' else []
    status, found = call('GET', '/vision/search?q=flux')
    check(status == 200 and found['models'] == [{'id': 'test', 'name': 'FLUX mock'}],
          'Vision Core search route is reachable and forwards the search term')
    status, selected = call('POST', '/vision', {'action': 'select', 'id': 'default'})
    check(status == 200 and selected['active'] == 'default',
          'Vision Core default can be selected after weight integrity verification')
    status, protected = call('POST', '/vision', {'action': 'uninstall', 'id': 'default'})
    check(status == 400 and protected['error'] == 'model_protected',
          'Vision Core built-in generator cannot be deleted')
    status, invalid = call('POST', '/vision', {'action': 'select', 'id': '../etc/passwd'})
    check(status == 400 and invalid['error'] == 'invalid_model_id',
          'Vision Core endpoints reject untrusted model paths')

    for bad_body, code in (({'prompt': '', 'size': 'normal'}, 'bad_prompt'), ({'prompt': 'x' * 1300}, 'bad_prompt'),
                           ({'prompt': 'a fox', 'size': 'huge'}, 'bad_size'), ({'prompt': 'a fox', 'seed': -1}, 'bad_seed'),
                           ({'prompt': 'a fox', 'scene_hint': 42}, 'bad_request'),
                           ({'prompt': 'a fox', 'scene_hint': 'x' * 1300}, 'bad_request'),
                           ({'prompt': 'a\x00b'}, 'bad_prompt'),
                           ({'prompt': 'a fox', 'reference': '../etc/passwd'}, 'bad_reference'),
                           ({'prompt': 'a fox', 'references': '/etc/passwd'}, 'bad_reference'),
                           ({'prompt': 'a fox', 'references': ['upload:../../etc/passwd']}, 'bad_reference'),
                           ({'prompt': 'a fox', 'references': ['upload:' + 'a' * 32 + '.png'] * 2}, 'bad_reference'),
                           ({'prompt': 'a fox', 'references': ['upload:' + 'a' * 32 + '.png'] * 11}, 'bad_reference')):
        status, body = call('POST', '/jobs', bad_body)
        check(status == 400 and body['error'] == code, f'refused: {bad_body!r} -> {body}')

    prompt = 'A red fox; rm -rf / $(reboot) "quoted"'
    status, body = call('POST', '/jobs', {'prompt': prompt, 'size': 'normal', 'seed': 42})
    check(status == 202 and body['job']['state'] in ('queued', 'running') and body['job']['seed'] == 42, 'job accepted')
    job_id = body['job']['id']
    status, busy = call('POST', '/jobs', {'prompt': 'another', 'size': 'normal'})
    check(status == 503 and busy['error'] == 'image_busy', 'one job at a time')
    seen = []
    shots = []
    for _ in range(200):
        job = call('GET', '/jobs/' + job_id)[1]['job']
        if not seen or seen[-1] != (job['phase'], job['step']):
            seen.append((job['phase'], job['step']))
        if job['state'] == 'running' and job['preview'] > len(shots):
            status, shot = call('GET', f'/jobs/{job_id}/preview')
            if status == 200:
                shots.append(shot)
        if job['state'] not in ('queued', 'running'):
            break
        time.sleep(0.05)
    check(job['preview'] >= 3 and len(shots) >= 2 and all(isinstance(s, bytes) and s[:8] == b'\x89PNG\r\n\x1a\n' for s in shots)
          and len(set(shots)) == len(shots), '0.6.42: a new preview after every step, served while the job runs: ' + str(job['preview']))
    check(call('GET', f'/jobs/{job_id}/preview')[0] == 404 and not (tmp / 'work' / (job_id + '-preview.png')).exists(),
          'after the job no preview is left')
    check(job['state'] == 'done' and job['step'] == 4 and job['width'] == 64 and job['seconds'] > 0,
          'the job is done: ' + json.dumps(job))
    check(any(phase == 'sampling' and 0 < step < 4 for phase, step in seen), 'real steps were seen on the way: ' + str(seen))
    status, image = call('GET', f'/jobs/{job_id}/image')
    check(status == 200 and isinstance(image, bytes) and image[:8] == b'\x89PNG\r\n\x1a\n', 'the PNG is served')
    argv = json.loads(args_log.read_text(encoding='utf-8').splitlines()[-1])
    check(argv == ['--diffusion-model', paths['diffusion_model'], '--llm', paths['llm'], '--vae', paths['vae'], '-p', prompt,
                   '--cfg-scale', '1.0', '--steps', '4', '-W', '64', '-H', '64', '-s', '42', '-t', '3',
                   '-o', str(tmp / 'work' / (job_id + '.png')), '-v',
                   '--preview', 'proj', '--preview-path', str(tmp / 'work' / (job_id + '-preview.png')), '--preview-interval', '1'],
          'fixed arguments, the prompt as one argument: ' + json.dumps(argv))

    # The reference is passed as one trusted local -r argument, never as shell text.
    ref_path = str(ROOT / 'images' / 'mara_winter.jpg')
    custom_command = svc.command({'width': 64, 'height': 64, 'seed': 3, 'png': str(tmp / 'reference-test.png'),
                                  'preview_png': str(tmp / 'reference-preview.png'), 'reference_path': ref_path},
                                 'Mara in a spaceship')
    check(custom_command.count('-r') == 1 and custom_command[custom_command.index('-r') + 1] == ref_path,
          'self portraits use the reference file as an sd-cli argument')
    check('-r' not in argv, 'ordinary images do not get a portrait reference')
    # sd-cli supports multiple -r inputs: both uploaded subjects must reach the model.
    first_ref = '/var/www/coreui/assets/chat_media/' + 'a' * 32 + '.png'
    second_ref = '/var/www/coreui/assets/chat_media/' + 'b' * 32 + '.jpg'
    multi_command = svc.command({'width': 64, 'height': 64, 'seed': 3, 'png': str(tmp / 'multi-test.png'),
                                 'preview_png': str(tmp / 'multi-preview.png'),
                                 'reference_paths': [first_ref, second_ref]}, 'two warriors fighting')
    check(multi_command.count('-r') == 2 and
          [multi_command[i + 1] for i, arg in enumerate(multi_command) if arg == '-r'] == [first_ref, second_ref],
          'both attachment references are passed to the generator in stable order')
    with_identity = svc.command({'width': 64, 'height': 64, 'seed': 3, 'png': str(tmp / 'identity-test.png'),
                                 'preview_png': str(tmp / 'identity-preview.png'),
                                 'reference_paths': [ref_path, first_ref]}, 'Mara in uploaded scenery')
    check(with_identity.count('-r') == 2 and with_identity[with_identity.index('-r') + 1] == ref_path,
          'Mara identity and additional user reference are both preserved')

    status, body = call('POST', '/jobs', {'prompt': 'SLOW fox', 'size': 'normal'})
    slow_id = body['job']['id']
    time.sleep(0.6)
    check(call('DELETE', '/jobs/' + slow_id)[0] == 200, 'cancel accepted')
    for _ in range(100):
        job = call('GET', '/jobs/' + slow_id)[1]['job']
        if job['state'] not in ('queued', 'running'):
            break
        time.sleep(0.05)
    check(job['state'] == 'cancelled' and call('GET', f'/jobs/{slow_id}/image')[0] == 404, 'a cancelled job leaves no image')

    status, body = call('POST', '/jobs', {'prompt': 'SLOW fox', 'size': 'large'})
    late_id = body['job']['id']
    for _ in range(200):
        job = call('GET', '/jobs/' + late_id)[1]['job']
        if job['state'] not in ('queued', 'running'):
            break
        time.sleep(0.05)
    check(job['state'] == 'failed' and 'Zeitlimit' in job['error'], 'the time limit stops a job: ' + json.dumps(job))

    for word, expect in (('FAIL', 'sd-cli Exit 3'), ('NOPNG', 'kein PNG erzeugt')):
        status, body = call('POST', '/jobs', {'prompt': word + ' fox', 'size': 'normal'})
        jid = body['job']['id']
        for _ in range(200):
            job = call('GET', '/jobs/' + jid)[1]['job']
            if job['state'] not in ('queued', 'running'):
                break
            time.sleep(0.05)
        check(job['state'] == 'failed' and job['error'] == expect, f'{word}: {job}')
    check(call('GET', '/jobs/' + 'f' * 32)[0] == 404 and call('GET', '/jobs/../etc')[0] == 404, 'unknown jobs: 404')
    os.environ['COREAI_IMAGE_PREVIEW'] = '0'
    check(not service_mod.supports_preview(str(stub)), 'COREAI_IMAGE_PREVIEW=0 switches the previews off')
    del os.environ['COREAI_IMAGE_PREVIEW']
    check(not service_mod.supports_preview(str(tmp / 'missing-sd-cli')), 'no sd-cli: no previews')
    service_mod.mem_available_mb = lambda: 100.0
    svc.reg['measured'] = {'normal': {'peak_rss_mb': 9000}}
    status, body = call('POST', '/jobs', {'prompt': 'a fox', 'size': 'normal'})
    check(status == 503 and body['error'] == 'image_memory', 'too little free memory: no job')
    server.shutdown()

    # --- Adopting the image test's weights -------------------------------------------------
    spike_dir = tmp / 'spike'
    image_dir = tmp / 'image-models'
    image_dir.mkdir()
    for spec in test_reg['files']:
        src = spike_dir / spec['repo'].replace('/', '__') / spec['path']
        src.parent.mkdir(parents=True, exist_ok=True)
        src.write_bytes(blobs[spec['repo'] + '/' + spec['path']])
    wrong = json.loads(json.dumps(test_reg))
    wrong['files'][1]['size'] += 1
    wrong['files'].append({'role': 'x', 'repo': 'org/../../etc', 'path': 'passwd', 'size': 1})
    keep = image_dir / 'org__vae' / 'split_files' / 'vae' / 'ae.safetensors'
    keep.parent.mkdir(parents=True)
    keep.write_bytes(b'mine')
    lines = adopt_mod.adopt(wrong, spike_dir, image_dir)
    check(len(lines) == 1 and 'org/model/weights.gguf' in lines[0], 'only a file of the registered size is adopted: ' + str(lines))
    adopted = image_dir / 'org__model' / 'weights.gguf'
    check(adopted.read_bytes() == blobs['org/model/weights.gguf'] and oct(adopted.stat().st_mode & 0o777) == '0o640',
          'adopted with mode 0640')
    check(os.stat(adopted).st_ino == os.stat(spike_dir / 'org__model' / 'weights.gguf').st_ino, 'linked, not copied (no second 4 GB)')
    check(keep.read_bytes() == b'mine' and not (image_dir / 'org__text').exists(), 'an existing file and a wrong size stay untouched')
    check(adopt_mod.main(['x', str(ROOT / 'config' / 'image' / 'mara-image.json'), str(tmp / 'none'), str(image_dir)]) == 0,
          'no image test on this server: nothing to do')

# --- Wiring -------------------------------------------------------------------------------
compose = (ROOT / 'compose.yaml').read_text(encoding='utf-8')
block = compose[compose.index('\n  image:\n'):compose.index('\n  pyworker:\n')]
for needle in ('profiles: ["image"]', 'dockerfile: docker/image/Dockerfile', 'user: "33:33"', 'read_only: true', 'mem_limit: 20g',
               '- .:/var/www/coreui:ro', '- ./var/image-models:/models', 'COREAI_IMAGE_PORT: ${COREUI_IMAGE_PORT:-18791}'):
    check(needle in block, 'compose image service: ' + needle)
install = (ROOT / 'scripts' / 'install.sh').read_text(encoding='utf-8')
for needle in ('"$PROJECT_ROOT/var/image-models" \\', 'COREUI_INSTALL_IMAGE=$INSTALL_IMAGE', 'if compose build image; then',
               "warn 'Bilddienst konnte nicht gebaut werden; CoreAI laeuft ohne Bilder weiter.'",
               'python3 -I "$PROJECT_ROOT/tools/image/adopt_spike.py"', 'compose up -d image', 'compose stop image',
               '|| -n "$REQUEST_INSTALL_IMAGE" \\', 'if (( MEM_TOTAL_GB >= 31 )); then IMAGE_DEFAULT=1; else IMAGE_DEFAULT=0; fi'):
    check(needle in install, 'install.sh: ' + needle)
check(install.index('if compose build image; then') < install.index("log 'Baue das wegwerfbare Python-Sandbox-Image"),
      'the image service is built with the other images')
check('"$image_models_dir"' in (ROOT / 'scripts' / 'stack.sh').read_text(encoding='utf-8'), 'stack.sh creates var/image-models')
state = (ROOT / 'scripts' / 'lib' / 'coreai_update_state.py').read_text(encoding='utf-8')
check("('Baue den Bilddienst', 41," in state and "log 'Baue den Bilddienst (" in install, 'the update window names the long first build')

print('CoreAI Bilddienst: Register, Fortschritt aus sd-cli, Gewichte mit festem SHA-256, Auftraege (ein Bild zur Zeit, Abbruch, '
      'Zeitlimit, Fehler), Uebernahme aus dem Bildtest, Compose, Installer OK')
