#!/usr/bin/env python3
"""CoreAI 0.6.39 (P20.5 B): Mara's image service.

Runs in its own container (compose service "image"), listens on 127.0.0.1 only and
makes one image at a time with stable-diffusion.cpp (sd-cli, built for this CPU) and
the model in config/image/mara-image.json (FLUX.2-klein 4B, decided 07.11.2026):

  GET    /health            {"ok", "ready", "loading", "error", "model", "sizes", "busy", "weights"}
  POST   /jobs              {"prompt": text, "size": "normal"|"large", "seed"?: int} -> 202 {"ok", "job": {...}}
  GET    /jobs/ID           {"ok", "job": {"state", "phase", "step", "steps", "seconds", ...}}
  GET    /jobs/ID/image     image/png, once the job is done
  GET    /jobs/ID/preview   0.6.42: the latest preview of the job (image/png, small)
  DELETE /jobs/ID           stops the job (sd-cli is killed)

0.6.42: when sd-cli can (its --help names --preview-path), every sampling step writes a
preview: the latent projected to colours ("proj", no extra decoding; for FLUX.2-klein a
64 x 64 image at 512 x 512). The job counts them ("preview"), so the chat can show the
image coming into being step by step. COREAI_IMAGE_PREVIEW=0 switches this off.

The PHP side holds Mara's serial model queue while a job runs, so the image never
competes with a Core for the CPU. Every weight file is used exactly at its registered
revision and checked against its SHA-256 before the first job; without pinned values
the service stays unavailable. Weights the image test already loaded are adopted by the
installer (tools/image/adopt_spike.py). sd-cli gets fixed arguments only: the prompt is
one argument, never shell text. COREAI_IMAGE_SD_CLI points to a stand-in in the selftest.
"""
from __future__ import annotations

import importlib.util
import json
import os
import re
import secrets
import signal
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
REGISTRY = Path(os.environ.get('COREAI_IMAGE_REGISTRY', str(ROOT / 'config' / 'image' / 'mara-image.json')))
MODELS = Path(os.environ.get('COREAI_IMAGE_MODELS', '/models'))
WORK = Path(os.environ.get('COREAI_IMAGE_WORK', '/tmp/coreai-image'))
SD_CLI = os.environ.get('COREAI_IMAGE_SD_CLI', '/usr/local/bin/sd-cli')
WATCH_S = float(os.environ.get('COREAI_IMAGE_WATCH_S', '20'))
KEEP_S = 600          # a finished job (and its image) is kept this long
MAX_BODY = 20_000
ROLES = ('diffusion_model', 'llm', 'vae')
HEX40 = re.compile(r'[0-9a-f]{40}')
HEX64 = re.compile(r'[0-9a-f]{64}')
JOB_ID = re.compile(r'[0-9a-f]{32}')
CUSTOM_REFERENCE = re.compile(r'custom:([0-9a-f]{32})')
UPLOAD_REFERENCE = re.compile(r'upload:([0-9a-f]{32})\.(png|jpg|webp|gif)')
MAX_UPLOAD_REFERENCES = 10


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


# Loading from Hugging Face is the code of the image test (redirects, resume, SHA-256).
SPIKE = _load('coreai_image_spike_run', ROOT / 'tools' / 'image_spike' / 'run.py')
MARA_REFS = _load('coreai_mara_references', HERE / 'mara_references.py')
from vision_cores import VisionCores
if os.environ.get('COREAI_IMAGE_HF'):
    SPIKE.HF = os.environ['COREAI_IMAGE_HF'].rstrip('/')


def log(text: str) -> None:
    print('[CoreAI Bilder] ' + text, flush=True)


# --- Registry ------------------------------------------------------------------------

def registry_errors(reg: dict) -> list[str]:
    """What keeps the registry from being used (empty when it is complete and pinned)."""
    errors = []
    files = reg.get('files') or []
    if sorted(f.get('role') for f in files) != sorted(ROLES):
        errors.append('Rollen muessen genau diffusion_model, llm und vae sein')
    for f in files:
        name = str(f.get('path', '?'))
        if not isinstance(f.get('repo'), str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*', f['repo']):
            errors.append(name + ': Repository ungueltig')
        if not isinstance(f.get('path'), str) or f['path'].startswith('/') or '..' in f['path'].split('/'):
            errors.append(name + ': Pfad ungueltig')
        if not HEX40.fullmatch(str(f.get('revision') or '')):
            errors.append(name + ': keine festgelegte Revision')
        if not HEX64.fullmatch(str(f.get('sha256') or '')):
            errors.append(name + ': kein festgelegter SHA-256')
        if not isinstance(f.get('size'), int) or f['size'] <= 0:
            errors.append(name + ': Groesse fehlt')
    sizes = reg.get('sizes') or {}
    for key in ('normal', 'large'):
        size = sizes.get(key) or {}
        if not all(isinstance(size.get(k), int) and size[k] > 0 for k in ('width', 'height', 'time_limit_s')) \
                or size.get('width', 1) % 64 or size.get('height', 1) % 64:
            errors.append('Groesse ' + key + ' ungueltig')
    model = reg.get('model') or {}
    if not isinstance(model.get('steps'), int) or not 1 <= model['steps'] <= 50:
        errors.append('Schritte ungueltig')
    return errors


def file_path(models: Path, spec: dict) -> Path:
    """One folder per repository, the repository's own path inside (as in the image test)."""
    return models / spec['repo'].replace('/', '__') / spec['path']


# --- Progress from the output of sd-cli -----------------------------------------------

PHASE_MARKS = (('text', 'loading tensors completed'), ('sampling', 'get_learned_condition completed'),
               ('decode', 'sampling completed'), ('save', 'decode_first_stage completed'))
STEP_RE = re.compile(r'\|\s*(\d+)/(\d+)\s*-')


def progress(output: str, steps: int) -> dict:
    """Phase and sampling step from what sd-cli wrote so far (pure). Steps count only after the
    text encoding, so the progress bar of loading the tensors is not taken for a step."""
    phase, at = 'load', 0
    for name, mark in PHASE_MARKS:
        index = output.find(mark)
        if index >= 0:
            phase, at = name, index
    step = 0
    if phase in ('sampling', 'decode', 'save'):
        hits = [m for m in STEP_RE.finditer(output, output.find('get_learned_condition completed'))
                if int(m.group(2)) == steps]
        step = min(steps, int(hits[-1].group(1))) if hits else 0
        if phase != 'sampling':
            step = steps
    return {'phase': phase, 'step': step, 'steps': steps}


def supports_preview(sd_cli: str) -> bool:
    """Whether this sd-cli writes step previews (pure apart from one --help call)."""
    if os.environ.get('COREAI_IMAGE_PREVIEW', '1') == '0':
        return False
    try:
        done = subprocess.run([sd_cli, '--help'], capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.SubprocessError):
        return False
    return '--preview-path' in (done.stdout or '') + (done.stderr or '')


def mem_available_mb() -> float | None:
    try:
        for line in Path('/proc/meminfo').read_text().splitlines():
            if line.startswith('MemAvailable:'):
                return int(line.split()[1]) / 1024
    except (OSError, ValueError, IndexError):
        return None
    return None


def png_ok(path: Path) -> bool:
    try:
        with path.open('rb') as handle:
            return handle.read(8) == b'\x89PNG\r\n\x1a\n'
    except OSError:
        return False


# Resolve an internal reference token, never a browser-supplied filesystem path.
# Each custom token was read from the current account's database record by PHP.
def resolve_reference(token: str | None) -> str | None:
    if token is None:
        return None
    if token == 'default':
        curated = MARA_REFS.select(ROOT, '', has_user_uploads=True)
        if curated:
            return curated[0]['path']  # prefer new Mara master, if deployed
        path = ROOT / 'images' / 'mara_winter.jpg'
        if not path.is_file() or path.stat().st_size > 8 * 1024 * 1024:
            raise ValueError('reference_unavailable')
        return str(path)
    match = CUSTOM_REFERENCE.fullmatch(token)
    if not match:
        raise ValueError('bad_reference')
    path = ROOT / 'var' / 'profile_media' / (match.group(1) + '.png')
    if not path.is_file() or not png_ok(path) or path.stat().st_size > 4 * 1024 * 1024:
        raise ValueError('reference_unavailable')
    return str(path)


def resolve_uploaded_reference(token: str) -> str:
    """Only immutable UUID filenames in the read-only upload mount may be referenced."""
    match = UPLOAD_REFERENCE.fullmatch(token)
    if not match:
        raise ValueError('bad_reference')
    name = match.group(1) + '.' + match.group(2)
    path = ROOT / 'assets' / 'chat_media' / name
    allowed_dir = ROOT / 'assets' / 'chat_media'
    if (path.is_symlink() or path.resolve().parent != allowed_dir.resolve() or
            not path.is_file() or not 0 < path.stat().st_size <= 8 * 1024 * 1024):
        raise ValueError('reference_unavailable')
    with path.open('rb') as stream:
        header = stream.read(16)
    ext = match.group(2)
    valid = (
        (ext == 'png' and header.startswith(b'\x89PNG\r\n\x1a\n')) or
        (ext == 'jpg' and header.startswith(b'\xff\xd8\xff')) or
        (ext == 'webp' and header[:4] == b'RIFF' and header[8:12] == b'WEBP') or
        (ext == 'gif' and header[:6] in (b'GIF87a', b'GIF89a'))
    )
    if not valid:
        raise ValueError('reference_unavailable')
    return str(path)


# --- Service ---------------------------------------------------------------------------

class Service:
    def __init__(self, registry: dict, models: Path, work: Path, threads: int, sd_cli: str = SD_CLI):
        self.reg = registry
        self.registry_errors = registry_errors
        self.file_path = file_path
        self.models = models
        self.work = work
        self.threads = threads
        self.sd_cli = sd_cli
        self.ready = False
        self.loading = False
        self.error = ''
        self.paths: dict[str, str] = {}
        self.preview = False
        self.jobs: dict[str, dict] = {}
        self.lock = threading.Lock()        # one job at a time
        self.state_lock = threading.Lock()
        self.vision_busy = False
        self.vision = None

    # Weights ---------------------------------------------------------------
    def _verified(self) -> dict:
        try:
            data = json.loads((self.models / 'verified.json').read_text(encoding='utf-8'))
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError):
            return {}

    def _remember(self, verified: dict) -> None:
        try:
            SPIKE.write_json(self.models / 'verified.json', verified)
        except OSError:
            pass  # only saves a check at the next start

    def ensure_weights(self, registry: dict | None = None) -> dict:
        """Every registered file at its place and checked; returns the paths by role."""
        reg = self.reg if registry is None else registry
        errors = registry_errors(reg)
        if errors:
            raise RuntimeError('Register unvollstaendig: ' + '; '.join(errors))
        verified = self._verified()
        paths = {}
        for spec in reg['files']:
            target = file_path(self.models, spec)
            key = str(target.relative_to(self.models))
            stat = target.stat() if target.is_file() else None
            known = verified.get(key) or {}
            if stat and stat.st_size == spec['size'] and known.get('sha256') == spec['sha256'] \
                    and known.get('size') == stat.st_size and known.get('mtime_ns') == stat.st_mtime_ns:
                paths[spec['role']] = str(target)
                continue
            if not (stat and stat.st_size == spec['size']):
                self._download(spec, target)
            digest = SPIKE.sha256_file(target)
            if digest != spec['sha256']:
                target.unlink(missing_ok=True)
                raise RuntimeError(f'{spec["path"]}: SHA-256 passt nicht zum Register; Datei geloescht, der naechste Start laedt sie neu')
            stat = target.stat()
            verified[key] = {'size': stat.st_size, 'mtime_ns': stat.st_mtime_ns, 'sha256': digest}
            self._remember(verified)
            paths[spec['role']] = str(target)
        return paths

    def _download(self, spec: dict, target: Path) -> None:
        target.parent.mkdir(parents=True, exist_ok=True)
        info = SPIKE.hf_resolve(spec, spec['revision'])
        if info['size'] is not None and info['size'] != spec['size']:
            raise RuntimeError(f'{spec["path"]}: Hugging Face meldet {info["size"]} Bytes, eingetragen sind {spec["size"]}')
        if info['sha256'] and info['sha256'] != spec['sha256']:
            raise RuntimeError(f'{spec["path"]}: Hugging Face nennt einen anderen SHA-256 als das Register')
        log(f'Lade {spec["repo"]}/{spec["path"]} ({spec["size"] / 1e9:.1f} GB)')
        part = target.with_name(target.name + '.part')
        SPIKE.download(info['url'], part, spec['size'])
        os.replace(part, target)

    def weights_progress(self, registry: dict | None = None) -> dict:
        have = 0
        total = 0
        for spec in (self.reg if registry is None else registry).get('files') or []:
            total += int(spec.get('size') or 0)
            try:
                target = file_path(self.models, spec)
            except (KeyError, TypeError):
                continue
            for candidate in (target, target.with_name(target.name + '.part')):
                try:
                    have += candidate.stat().st_size
                    break
                except OSError:
                    continue
        return {'have': min(have, total), 'total': total}

    def start(self) -> None:
        self.loading = True
        threading.Thread(target=self._prepare, daemon=True).start()

    def _prepare(self) -> None:
        try:
            if not (os.path.isfile(self.sd_cli) and os.access(self.sd_cli, os.X_OK)):
                raise RuntimeError('sd-cli fehlt im Container')
            self.work.mkdir(parents=True, exist_ok=True)
            self.preview = supports_preview(self.sd_cli)
            self.paths = self.ensure_weights()
            self.ready = True
            log('bereit: ' + self.reg['model']['label'])
        except Exception as error:  # reported by /health; the service stays up
            self.error = f'{type(error).__name__}: {error}'[:400]
            log('nicht bereit: ' + self.error)
        finally:
            self.loading = False

    # Jobs -------------------------------------------------------------------
    def busy_unlocked(self) -> bool:
        return any(job['state'] in ('queued', 'running') for job in self.jobs.values())

    def busy(self) -> bool:
        with self.state_lock:
            return self.vision_busy or self.busy_unlocked()

    def _clean(self) -> None:
        now = time.monotonic()
        with self.state_lock:
            for job_id in [j for j, job in self.jobs.items() if job.get('finished') and now - job['finished'] > KEEP_S]:
                job = self.jobs.pop(job_id)
                for key in ('png', 'log', 'preview_png'):
                    Path(job[key]).unlink(missing_ok=True)

    def create(self, prompt: str, size_key: str, seed: int | None, reference: str | None = None,
               references: list[str] | None = None, scene_hint: str | None = None) -> dict:
        # Tokens are already account-checked by PHP. No arbitrary paths are accepted here.
        reference_path = resolve_reference(reference)
        if references is None:
            references = []
        if len(references) > MAX_UPLOAD_REFERENCES or len(references) != len(set(references)):
            raise ValueError('bad_reference')
        uploaded_paths = [resolve_uploaded_reference(token) for token in references]
        curated = []
        if reference == 'default':
            # ONLY the built-in canonical Mara image may be expanded into the
            # official portrait set. Custom assistant pictures always override
            # the set; unrelated uploads are never reclassified as Mara.
            curated = MARA_REFS.select(ROOT, scene_hint or '',
                                       has_user_uploads=bool(references))
            if curated:
                reference_path = curated[0]['path']
        extra_paths = [item['path'] for item in curated[1:]]
        reference_paths = ([reference_path] if reference_path else []) + extra_paths + uploaded_paths
        self._clean()
        size = self.reg['sizes'][size_key]
        need = float(((self.reg.get('measured') or {}).get(size_key) or {}).get('peak_rss_mb') or 0)
        available = mem_available_mb()
        if need and available is not None and available < need + 1024:
            raise MemoryError(f'{int(available)} MB frei, gebraucht etwa {int(need)} MB')
        with self.state_lock:
            if self.vision_busy or self.busy_unlocked():
                raise BlockingIOError('busy')
            job_id = secrets.token_hex(16)
            job = {'id': job_id, 'state': 'queued', 'phase': 'load', 'step': 0, 'steps': int(self.reg['model']['steps']),
                   'error': '', 'size': size_key, 'width': size['width'], 'height': size['height'],
                   'seed': seed if seed is not None else secrets.randbelow(2 ** 31 - 1),
                   'seconds': None, 'peak_rss_mb': None, 'started': time.monotonic(), 'finished': None,
                   'png': str(self.work / (job_id + '.png')), 'log': str(self.work / (job_id + '.log')),
                   'preview_png': str(self.work / (job_id + '-preview.png')), 'preview': 0, 'preview_stamp': None,
                   'pid': 0, 'cancel': False, 'limit': int(size['time_limit_s']),
                   'reference_path': reference_path, 'reference_paths': reference_paths,
                   'reference_count': len(reference_paths), 'curated_mara_count': len(curated),
                   'model_label': self.reg['model']['label'], 'model_registry': copy.deepcopy(self.reg)}
            self.jobs[job_id] = job
        threading.Thread(target=self._run, args=(job, prompt), daemon=True).start()
        return job

    def command(self, job: dict, prompt: str) -> list[str]:
        model = self.reg['model']
        ref_paths = job.get('reference_paths')
        if ref_paths is None:
            ref_paths = [job['reference_path']] if job.get('reference_path') else []
        reference_args: list[str] = []
        for path in ref_paths:
            reference_args.extend(['-r', path])
        return [self.sd_cli, '--diffusion-model', self.paths['diffusion_model'], '--llm', self.paths['llm'],
                '--vae', self.paths['vae'], '-p', prompt, '--cfg-scale', str(float(model['cfg_scale'])), '--steps', str(model['steps']),
                '-W', str(job['width']), '-H', str(job['height']), '-s', str(job['seed']), '-t', str(self.threads),
                '-o', job['png'], '-v'] + reference_args + (['--preview', 'proj', '--preview-path', job['preview_png'], '--preview-interval', '1']
                                           if self.preview else [])

    def _run(self, job: dict, prompt: str) -> None:
        with self.lock:
            job['state'] = 'running'
            start = time.monotonic()
            status = 0
            usage = None
            try:
                cmd = self.command(job, prompt)
                with open(job['log'], 'wb') as log_file:
                    pid = os.posix_spawn(cmd[0], cmd, os.environ.copy(), setsid=True, file_actions=[
                        (os.POSIX_SPAWN_DUP2, log_file.fileno(), 1), (os.POSIX_SPAWN_DUP2, log_file.fileno(), 2)])
                job['pid'] = pid
                timed_out = False
                while True:
                    done, status, usage = os.wait4(pid, os.WNOHANG)
                    if done:
                        break
                    self._update_progress(job)
                    if job['cancel'] or time.monotonic() - start > job['limit']:
                        timed_out = not job['cancel']
                        self._kill(pid)
                        done, status, usage = os.wait4(pid, 0)
                        break
                    time.sleep(0.25)
                self._update_progress(job)
                job['seconds'] = round(time.monotonic() - start, 2)
                job['peak_rss_mb'] = round(usage.ru_maxrss / 1024, 1) if usage and usage.ru_maxrss else None
                code = os.waitstatus_to_exitcode(status)
                if job['cancel']:
                    job.update(state='cancelled', error='abgebrochen')
                elif timed_out:
                    job.update(state='failed', error=f'Zeitlimit von {job["limit"]} s erreicht')
                elif code != 0:
                    job.update(state='failed', error=f'sd-cli Exit {code}')
                elif not png_ok(Path(job['png'])):
                    job.update(state='failed', error='kein PNG erzeugt')
                else:
                    job.update(state='done', phase='done', step=job['steps'])
            except Exception as error:
                job.update(state='failed', error=f'{type(error).__name__}: {error}'[:300])
            finally:
                job['finished'] = time.monotonic()
                Path(job['preview_png']).unlink(missing_ok=True)
                if job['state'] != 'done':
                    Path(job['png']).unlink(missing_ok=True)
                log(f'Auftrag {job["id"][:8]}: {job["state"]}, {job["width"]}x{job["height"]}, {job["seconds"]} s'
                    + (f' ({job["error"]})' if job['error'] else ''))

    @staticmethod
    def _kill(pid: int) -> None:
        try:
            os.killpg(pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass

    @staticmethod
    def _update_progress(job: dict) -> None:
        # 0.6.42: a new preview (sd-cli rewrites the same file after every step).
        try:
            stat = os.stat(job['preview_png'])
            stamp = (stat.st_mtime_ns, stat.st_size)
            if stat.st_size > 0 and stamp != job['preview_stamp']:
                job['preview_stamp'] = stamp
                job['preview'] += 1
        except OSError:
            pass
        try:
            with open(job['log'], 'rb') as handle:
                handle.seek(0, 2)
                size = handle.tell()
                handle.seek(max(0, size - 200_000))
                text = handle.read().decode('utf-8', 'replace')
        except OSError:
            return
        job.update(progress(text, job['steps']))

    def cancel(self, job_id: str) -> dict | None:
        with self.state_lock:
            job = self.jobs.get(job_id)
            if job and job['state'] in ('queued', 'running'):
                job['cancel'] = True
                if job['pid']:
                    self._kill(job['pid'])
            return job

    def public(self, job: dict) -> dict:
        keys = ('id', 'state', 'phase', 'step', 'steps', 'error', 'size', 'width', 'height', 'seed', 'seconds', 'peak_rss_mb', 'preview', 'reference_count', 'curated_mara_count', 'model_label', 'model_registry')
        return {key: job[key] for key in keys}

    def health(self) -> dict:
        model = self.reg.get('model') or {}
        return {'ok': True, 'ready': self.ready, 'loading': self.loading, 'error': self.error, 'busy': self.busy(),
                'model': model.get('label', ''), 'model_id': self.vision.active if self.vision else 'default',
                'license': model.get('license', ''), 'threads': self.threads,
                'preview': self.preview,
                'sizes': {key: {'width': s['width'], 'height': s['height']} for key, s in (self.reg.get('sizes') or {}).items()},
                'weights': self.weights_progress()}

    def watch(self, interval: float) -> None:
        """After an update the mounted code changes; the running job ends first, then the
        process ends and Docker starts it again with the new code (as Eve's service)."""
        if interval <= 0:
            return
        paths = [HERE / 'image_service.py', HERE / 'mara_references.py', ROOT / 'tools' / 'image_spike' / 'run.py',
                 ROOT / 'config' / 'image' / 'mara-references.json', REGISTRY]

        def stamp() -> list[int]:
            out = []
            for path in paths:
                try:
                    out.append(path.stat().st_mtime_ns)
                except OSError:
                    out.append(0)
            return out

        started = stamp()

        def run() -> None:
            while True:
                time.sleep(interval)
                if stamp() != started:
                    self.lock.acquire()
                    log('Code oder Register geaendert, Bilddienst startet neu.')
                    os._exit(0)
        threading.Thread(target=run, daemon=True).start()


def make_handler(service: Service):
    class Handler(BaseHTTPRequestHandler):
        server_version = 'CoreAIImage/1'

        def log_message(self, fmt: str, *args: Any) -> None:  # no request log with prompts in it
            return

        def _json(self, status: int, data: dict) -> None:
            body = json.dumps(data, ensure_ascii=False).encode('utf-8')
            self.send_response(status)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _job(self) -> tuple[dict | None, str]:
            parts = self.path.strip('/').split('/')
            if len(parts) < 2 or parts[0] != 'jobs' or not JOB_ID.fullmatch(parts[1]):
                return None, ''
            with service.state_lock:
                job = service.jobs.get(parts[1])
            return job, parts[2] if len(parts) == 3 else ''

        def do_GET(self) -> None:
            if self.path == '/health':
                return self._json(200, service.health())
            if self.path.startswith('/vision') and service.vision is not None:
                try:
                    if self.path == '/vision':
                        return self._json(200, service.vision.list())
                    if self.path.startswith('/vision/search?q='):
                        from urllib.parse import parse_qs, urlsplit
                        term = parse_qs(urlsplit(self.path).query).get('q', [''])[0]
                        return self._json(200, {'ok': True, 'models': service.vision.search(term)})
                except Exception as error:
                    return self._json(503, {'ok': False, 'error': str(error)[:100]})
            job, rest = self._job()
            if job is None:
                return self._json(404, {'ok': False, 'error': 'not_found'})
            if rest == '':
                return self._json(200, {'ok': True, 'job': service.public(job)})
            if rest == 'preview':
                # 0.6.42: the latest step preview; a file sd-cli is still writing is no PNG yet.
                try:
                    data = Path(job['preview_png']).read_bytes()
                except OSError:
                    data = b''
                if job['state'] not in ('queued', 'running') or not data.startswith(b'\x89PNG\r\n\x1a\n') or not data.endswith(b'IEND\xaeB`\x82'):
                    return self._json(404, {'ok': False, 'error': 'no_preview'})
                self.send_response(200)
                self.send_header('Content-Type', 'image/png')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)
                return None
            if rest != 'image' or job['state'] != 'done':
                return self._json(404, {'ok': False, 'error': 'no_image'})
            try:
                data = Path(job['png']).read_bytes()
            except OSError:
                return self._json(404, {'ok': False, 'error': 'no_image'})
            self.send_response(200)
            self.send_header('Content-Type', 'image/png')
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_DELETE(self) -> None:
            job, rest = self._job()
            if job is None or rest:
                return self._json(404, {'ok': False, 'error': 'not_found'})
            service.cancel(job['id'])
            return self._json(200, {'ok': True, 'job': service.public(job)})

        def do_POST(self) -> None:
            if self.path == '/vision' and service.vision is not None:
                length = int(self.headers.get('Content-Length') or 0)
                if not 0 < length <= 2048:
                    return self._json(400, {'ok': False, 'error': 'bad_request'})
                try:
                    data = json.loads(self.rfile.read(length))
                    if not isinstance(data, dict):
                        raise ValueError('bad_request')
                    action = data.get('action')
                    ident = data.get('id')
                    if not isinstance(ident, str) or not re.fullmatch(r'default|[0-9a-f]{24}', ident):
                        raise ValueError('invalid_model_id')
                    if action == 'install' and ident != 'default':
                        service.vision.install(ident)
                    elif action == 'select':
                        service.vision.select(ident)
                    elif action == 'uninstall':
                        service.vision.uninstall(ident)
                    else:
                        raise ValueError('bad_action')
                    return self._json(200, service.vision.list())
                except BlockingIOError as error:
                    return self._json(409, {'ok': False, 'error': str(error)[:100]})
                except ValueError as error:
                    return self._json(400, {'ok': False, 'error': str(error)[:100]})
                except Exception:
                    return self._json(503, {'ok': False, 'error': 'image_unavailable'})
            if self.path != '/jobs':
                return self._json(404, {'ok': False, 'error': 'not_found'})
            length = int(self.headers.get('Content-Length') or 0)
            if length <= 0 or length > MAX_BODY:
                return self._json(400, {'ok': False, 'error': 'bad_request'})
            try:
                data = json.loads(self.rfile.read(length).decode('utf-8'))
            except (ValueError, UnicodeDecodeError):
                return self._json(400, {'ok': False, 'error': 'bad_request'})
            limit = int((service.reg.get('limits') or {}).get('prompt_chars') or 1200)
            prompt = data.get('prompt') if isinstance(data, dict) else None
            size = data.get('size', 'normal') if isinstance(data, dict) else None
            seed = data.get('seed') if isinstance(data, dict) else None
            reference = data.get('reference') if isinstance(data, dict) else None
            references = data.get('references', []) if isinstance(data, dict) else []
            scene_hint = data.get('scene_hint') if isinstance(data, dict) else None
            if (scene_hint is not None and
                (not isinstance(scene_hint, str) or len(scene_hint) > limit or '\x00' in scene_hint)):
                return self._json(400, {'ok': False, 'error': 'bad_request'})
            if not isinstance(prompt, str) or not prompt.strip() or len(prompt) > limit or '\x00' in prompt:
                return self._json(400, {'ok': False, 'error': 'bad_prompt'})
            if size not in (service.reg.get('sizes') or {}):
                return self._json(400, {'ok': False, 'error': 'bad_size'})
            if seed is not None and (not isinstance(seed, int) or isinstance(seed, bool) or not 0 <= seed < 2 ** 31):
                return self._json(400, {'ok': False, 'error': 'bad_seed'})
            if reference is not None and (not isinstance(reference, str) or
                                          (reference != 'default' and CUSTOM_REFERENCE.fullmatch(reference) is None)):
                return self._json(400, {'ok': False, 'error': 'bad_reference'})
            if (not isinstance(references, list) or len(references) > MAX_UPLOAD_REFERENCES or
                    any(not isinstance(token, str) or not UPLOAD_REFERENCE.fullmatch(token) for token in references) or
                    len(references) != len(set(references))):
                return self._json(400, {'ok': False, 'error': 'bad_reference'})
            if not service.ready:
                return self._json(503, {'ok': False, 'error': 'image_loading' if service.loading else 'image_unavailable',
                                        'detail': service.error})
            try:
                job = service.create(prompt.strip(), size, seed, reference, references, scene_hint)
            except BlockingIOError:
                return self._json(503, {'ok': False, 'error': 'image_busy'})
            except MemoryError as error:
                return self._json(503, {'ok': False, 'error': 'image_memory', 'detail': str(error)})
            except ValueError as error:
                return self._json(400, {'ok': False, 'error': str(error)})
            return self._json(202, {'ok': True, 'job': service.public(job)})

    return Handler


def default_threads() -> int:
    try:
        return max(1, len(os.sched_getaffinity(0)))
    except (AttributeError, OSError):
        return max(1, os.cpu_count() or 1)


def main() -> int:
    registry = json.loads(REGISTRY.read_text(encoding='utf-8'))
    port = int(os.environ.get('COREAI_IMAGE_PORT', '18791'))
    threads = int(os.environ.get('COREAI_IMAGE_THREADS') or 0) or default_threads()
    service = Service(registry, MODELS, WORK, threads)
    service.vision = VisionCores(service, registry)
    service.reg = service.vision.selected_registry()
    service.start()
    service.watch(WATCH_S)
    server = ThreadingHTTPServer(('127.0.0.1', port), make_handler(service))
    server.daemon_threads = True
    log(f'hoert auf 127.0.0.1:{port} ({threads} Threads)')
    server.serve_forever()
    return 0


if __name__ == '__main__':
    sys.exit(main())
