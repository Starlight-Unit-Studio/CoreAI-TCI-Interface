#!/usr/bin/env python3
"""CoreAI 0.6.1 (0.6.5: no long silence): Mara's voice service (P20.3, read aloud).

Runs in its own container (compose service "voice"), listens on 127.0.0.1
only and keeps Eve loaded:

  GET  /health  {"ok", "ready", "loading", "error", "voice", "cores"}
  POST /plan    {"text": answer, "language"?: hint}  -> {"ok", "parts": [...], "language", "textprep"}
  POST /speak   {"text": part, "language"?: "de"|"en"} -> audio/wav (16 bit mono)

0.6.8: German and English. The language of an answer is detected from its words
(the hint decides when unclear); English loads on first use.

The voice is exactly the one in config/voice/mara-voice.json: every file is
fetched at its pinned revision and checked against its SHA-256 before the
model loads, and Pocket's own pins must name the same files. At most one
synthesis runs at a time (the others wait, then get "busy"), on a fixed
number of cores, with a time limit per part. COREAI_VOICE_ENGINE=probe
swaps the voice for a tone (selftest only).
"""
from __future__ import annotations

import gc
import hashlib
import importlib.util
import json
import math
import os
import struct
import sys
import threading
import time
from array import array
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
REGISTRY = Path(os.environ.get('COREAI_VOICE_REGISTRY', str(ROOT / 'config' / 'voice' / 'mara-voice.json')))
MAX_BODY = 200_000
MAX_PART_CHARS = 1200
QUEUE_WAIT_S = float(os.environ.get('COREAI_VOICE_QUEUE_WAIT', '90'))
WATCH_S = float(os.environ.get('COREAI_VOICE_WATCH_S', '20'))


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


SPEAKABLE = _load('coreai_voice_speakable', HERE / 'speakable.py')
TEXTPREP = _load('coreai_textprep_de', ROOT / 'tools' / 'tts_spike' / 'textprep_de.py')
TEXTPREP_EN = _load('coreai_textprep_en', HERE / 'textprep_en.py')
# 0.6.8: one text preparation and one warm-up sentence per language Eve speaks.
PREPARE = {'de': TEXTPREP.prepare, 'en': TEXTPREP_EN.prepare}
TEXTPREP_VERSION = {'de': TEXTPREP.VERSION, 'en': TEXTPREP_EN.VERSION}
WARMUP = {'de': 'Hallo.', 'en': 'Hello.'}
# RAM the voices may use together (the container has 6 GB); measured values from the registry.
MEM_MB = float(os.environ.get('COREAI_VOICE_MEM_MB', '5600'))


def voice_entries(registry: dict) -> dict:
    """0.6.8: the main language (German) stands at the top of the registry, more under "languages"."""
    entries = {'de': {key: registry[key] for key in ('model', 'voice', 'files', 'textprep', 'measured') if key in registry}}
    for language, entry in (registry.get('languages') or {}).items():
        if language in PREPARE and language != 'de':
            entries[language] = entry
    return entries


def entry_label(entry: dict) -> str:
    return entry['model']['label'] + ', Stimme ' + entry['voice']['label']


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, 'rb') as handle:
        for block in iter(lambda: handle.read(1 << 20), b''):
            digest.update(block)
    return digest.hexdigest()


def pcm16(samples: Any) -> bytes:
    """Float samples in [-1, 1] -> 16 bit little-endian PCM."""
    if hasattr(samples, 'detach'):
        samples = samples.detach().cpu().numpy()
    if hasattr(samples, 'reshape'):
        import numpy as np
        return (np.clip(samples.reshape(-1), -1.0, 1.0) * 32767.0).astype('<i2').tobytes()
    return b''.join(struct.pack('<h', int(max(-1.0, min(1.0, float(x))) * 32767.0)) for x in samples)


def wav_from_pcm(pcm: bytes, rate: int) -> bytes:
    header = b'RIFF' + struct.pack('<I', 36 + len(pcm)) + b'WAVEfmt ' + struct.pack('<IHHIIHH', 16, 1, 1, rate, rate * 2, 2, 16)
    return header + b'data' + struct.pack('<I', len(pcm)) + pcm


def wav_bytes(samples: Any, rate: int) -> bytes:
    """Float samples in [-1, 1] -> 16 bit mono WAV."""
    return wav_from_pcm(pcm16(samples), rate)


EDGE_S = 0.15        # silence kept before the first and after the last sound of a part
MAX_PAUSE_S = 0.7    # a pause inside a part is never longer than this
QUIET = 0.02         # below this peak (of full scale) a 10 ms frame counts as silence


def tighten_pcm(pcm: bytes, rate: int, edge_s: float = EDGE_S, max_pause_s: float = MAX_PAUSE_S, quiet: float = QUIET) -> bytes:
    """0.6.5: no long silence. Leading and trailing silence is cut to edge_s, any pause inside
    the part to max_pause_s (half kept at each side, so sounds fade out and in as spoken).
    Parts are played one after another, so this is the gap between parts, too."""
    samples = array('h')
    samples.frombytes(pcm[:len(pcm) - len(pcm) % 2])
    if sys.byteorder == 'big':
        samples.byteswap()
    frame = max(1, rate // 100)
    limit = int(quiet * 32767)
    frames = [samples[i:i + frame] for i in range(0, len(samples), frame)]
    loud = [max(chunk) > limit or -min(chunk) > limit for chunk in frames]
    if True not in loud:
        return pcm
    first = loud.index(True)
    last = len(loud) - 1 - loud[::-1].index(True)
    edge = max(1, round(edge_s * 100))
    keep_pause = max(2, round(max_pause_s * 100))
    kept: list[int] = list(range(max(0, first - edge), first))
    index = first
    while index <= last:
        if loud[index]:
            kept.append(index)
            index += 1
            continue
        end = index
        while end <= last and not loud[end]:
            end += 1
        run = list(range(index, end))
        kept.extend(run if len(run) <= keep_pause else run[:keep_pause // 2] + run[-(keep_pause - keep_pause // 2):])
        index = end
    kept.extend(range(last + 1, min(len(frames), last + 1 + edge)))
    out = array('h')
    for index in kept:
        out.extend(frames[index])
    if sys.byteorder == 'big':
        out.byteswap()
    return out.tobytes()


def time_limit(text: str) -> float:
    """Five times the expected audio length (about 14 characters a second), at least 10 seconds."""
    return max(10.0, 5.0 * len(text) / 14.0)


class ProbeEngine:
    """Selftest only: a short tone per part, through the same queue and limits (330 Hz German, 440 Hz English)."""

    def __init__(self, entry: dict, cores: int, language: str = 'de'):
        self.rate = 16000
        self.language = language
        self.label = 'Testton' if language == 'de' else 'Testton ' + language
        self.delay = float(os.environ.get('COREAI_VOICE_PROBE_DELAY', '0.05'))

    def load(self) -> None:
        time.sleep(float(os.environ.get('COREAI_VOICE_PROBE_LOAD_' + self.language.upper(), os.environ.get('COREAI_VOICE_PROBE_LOAD', '0'))))

    def synth(self, text: str, limit: float) -> tuple[list[float], int]:
        if 'TIMEOUT' in text:
            raise TimeoutError('time limit')
        time.sleep(self.delay)
        count = int(self.rate * max(0.2, 0.02 * len(text)))
        tone = 330 if self.language == 'de' else 440
        return [0.2 * math.sin(2 * math.pi * tone * i / self.rate) for i in range(count)], self.rate


class PocketEngine:
    """Eve with Pocket TTS, exactly as registered."""

    def __init__(self, entry: dict, cores: int, language: str = 'de'):
        self.registry = entry
        self.cores = cores
        self.language = language
        self.label = entry_label(entry)

    def _fetch_and_verify(self) -> None:
        from huggingface_hub import hf_hub_download
        for entry in self.registry['files']:
            path = Path(hf_hub_download(repo_id=entry['repo'], filename=entry['path'], revision=entry['revision']))
            if sha256_file(path) != entry['sha256']:
                raise RuntimeError('SHA-256 weicht ab: ' + entry['path'])

    def _check_pins(self) -> None:
        """Pocket loads its files from its own pins; they must be the registered ones."""
        from pocket_tts.utils.config import CONFIGS_DIR, load_config
        from pocket_tts.utils.utils import get_predefined_voice
        model, voice = self.registry['model'], self.registry['voice']
        config = load_config(CONFIGS_DIR / f"{model['language']}.yaml")
        pins = {
            'model': config.weights_path_without_voice_cloning,
            'tokenizer': config.flow_lm.lookup_table.tokenizer_path,
            'voice': get_predefined_voice(model['language'], voice['name']),
        }
        for entry in self.registry['files']:
            expected = f"hf://{entry['repo']}/{entry['path']}@{entry['revision']}"
            if pins.get(entry['key']) != expected:
                raise RuntimeError(f"Pocket laedt fuer {entry['key']} eine andere Datei als registriert")

    def load(self) -> None:
        import torch
        torch.set_num_threads(self.cores)
        try:
            torch.set_num_interop_threads(1)
        except RuntimeError:
            pass
        self._check_pins()
        self._fetch_and_verify()
        from pocket_tts import TTSModel
        model = self.registry['model']
        self.model = TTSModel.load_model(language=model['language'], quantize=bool(model.get('quantize')))
        if getattr(self.model, 'has_voice_cloning', False):
            raise RuntimeError('Klon-Gewichte geladen, erwartet waren die freien Gewichte')
        self.state = self.model.get_state_for_audio_prompt(self.registry['voice']['name'])
        self.rate = int(self.model.sample_rate)
        self.synth(PREPARE[self.language](WARMUP[self.language]), 30.0)

    def synth(self, text: str, limit: float) -> tuple[Any, int]:
        import numpy as np
        stop = threading.Event()
        timer = threading.Timer(limit, stop.set)
        timer.start()
        chunks = []
        try:
            for chunk in self.model.generate_audio_stream(self.state, text, stop=stop):
                chunks.append(chunk.detach().cpu().numpy().reshape(-1))
        finally:
            timer.cancel()
        if stop.is_set():
            raise TimeoutError('time limit')
        return (np.concatenate(chunks) if chunks else np.zeros(0, dtype='float32')), self.rate


def watched_files() -> list[Path]:
    """The code and registry this process runs with (the repository is mounted read-only)."""
    extra = [Path(x) for x in os.environ.get('COREAI_VOICE_WATCH_FILES', '').split(':') if x]
    return [HERE / 'voice_service.py', HERE / 'speakable.py', HERE / 'textprep_en.py', ROOT / 'tools' / 'tts_spike' / 'textprep_de.py',
            REGISTRY] + extra


def code_stamp(paths: list[Path]) -> dict:
    stamp = {}
    for path in paths:
        try:
            stamp[str(path)] = path.stat().st_mtime_ns
        except OSError:
            stamp[str(path)] = 0
    return stamp


class Service:
    """0.6.8: one engine per language. The main language (German) loads at start; another one when
    an answer in it is read for the first time. Voices stay loaded while their measured RAM fits
    MEM_MB together; otherwise the one used longest ago is unloaded (never during a synthesis)."""

    def __init__(self, registry: dict, engine_name: str, cores: int):
        self.registry = registry
        self.entries = voice_entries(registry)
        self.engine_class = ProbeEngine if engine_name == 'probe' else PocketEngine
        self.cores = cores
        self.default = 'de'
        self.engines: dict = {}
        self.status = {language: {'ready': False, 'loading': False, 'error': '', 'used': 0.0} for language in self.entries}
        self.status[self.default]['loading'] = True
        self.lock = threading.Lock()        # one synthesis at a time
        self.load_lock = threading.Lock()   # one model load at a time
        self.state_lock = threading.Lock()

    # The main language decides the overall state, as before 0.6.8.
    @property
    def ready(self) -> bool:
        return self.status[self.default]['ready']

    @property
    def loading(self) -> bool:
        return self.status[self.default]['loading']

    @property
    def error(self) -> str:
        return self.status[self.default]['error']

    def start(self) -> None:
        self.status[self.default]['loading'] = False
        self.ensure(self.default)

    def ensure(self, language: str) -> str:
        """'ready'; or the language starts loading in the background ('loading'); 'unavailable' after a failed load."""
        with self.state_lock:
            state = self.status[language]
            if state['ready']:
                return 'ready'
            if state['loading']:
                return 'loading'
            if state['error']:
                return 'unavailable'
            state['loading'] = True
        threading.Thread(target=self._load, args=(language,), daemon=True).start()
        return 'loading'

    def _load(self, language: str) -> None:
        with self.load_lock:
            try:
                self._make_room(language)
                engine = self.engine_class(self.entries[language], self.cores, language)
                engine.load()
                with self.state_lock:
                    self.engines[language] = engine
                    self.status[language].update(ready=True, used=time.monotonic())
                print(f'[CoreAI Stimme] Sprache {language} geladen', flush=True)
            except Exception as error:  # reported by /health, the service stays up
                self.status[language]['error'] = f'{type(error).__name__}: {error}'[:300]
            finally:
                self.status[language]['loading'] = False

    def _need_mb(self, language: str) -> float:
        return float((self.entries[language].get('measured') or {}).get('peak_rss_mb') or 0)

    def _make_room(self, language: str) -> None:
        while True:
            with self.state_lock:
                others = [lang for lang in self.engines if lang != language]
                if not others or sum(self._need_mb(lang) for lang in others) + self._need_mb(language) <= MEM_MB:
                    return
                oldest = min(others, key=lambda lang: self.status[lang]['used'])
            with self.lock:
                with self.state_lock:
                    self.engines.pop(oldest, None)
                    self.status[oldest]['ready'] = False
            gc.collect()
            print(f'[CoreAI Stimme] Sprache {oldest} entladen, Platz fuer {language}', flush=True)

    def watch(self, interval: float) -> None:
        """0.6.5: an update brings new code into the mounted folder, but a running container
        keeps the old one. When a watched file changes, the running part is finished, then
        the process ends and Docker starts it again (restart: unless-stopped) with the new code."""
        if interval <= 0:
            return
        paths = watched_files()
        started = code_stamp(paths)

        def run() -> None:
            while True:
                time.sleep(interval)
                if code_stamp(paths) != started:
                    self.lock.acquire()
                    print('[CoreAI Stimme] Code oder Register geaendert, Sprachdienst startet neu.', flush=True)
                    os._exit(0)
        threading.Thread(target=run, daemon=True).start()

    def health(self) -> dict:
        languages = {lang: {'ready': st['ready'], 'loading': st['loading'], 'error': st['error'], 'voice': self.label(lang)}
                     for lang, st in self.status.items()}
        return {'ok': True, 'ready': self.ready, 'loading': self.loading, 'error': self.error,
                'voice': self.label(self.default), 'cores': self.cores, 'textprep': TEXTPREP.VERSION, 'languages': languages}

    def label(self, language: str) -> str:
        engine = self.engines.get(language)
        if engine is not None:
            return engine.label
        if self.engine_class is ProbeEngine:
            return 'Testton' if language == 'de' else 'Testton ' + language
        return entry_label(self.entries[language])

    def plan(self, text: str, hint: str = 'de') -> tuple[list[str], str]:
        """The parts of an answer and the language they are spoken in (detected; hint when unclear)."""
        language = SPEAKABLE.detect_language(text, hint if hint in self.entries else self.default)
        if language not in self.entries:
            language = self.default
        return SPEAKABLE.plan(text, PREPARE[language], language), language

    def speak(self, text: str, language: str = 'de') -> bytes:
        if not self.lock.acquire(timeout=QUEUE_WAIT_S):
            raise BlockingIOError('busy')
        try:
            engine = self.engines.get(language)
            if engine is None:
                raise LookupError('not loaded')
            self.status[language]['used'] = time.monotonic()
            samples, rate = engine.synth(text, time_limit(text))
        finally:
            self.lock.release()
        return wav_from_pcm(tighten_pcm(pcm16(samples), rate), rate)


def make_handler(service: Service):
    class Handler(BaseHTTPRequestHandler):
        server_version = 'CoreAIVoice/1'

        def log_message(self, fmt: str, *args: Any) -> None:  # no request log with text in it
            return

        def _json(self, status: int, data: dict) -> None:
            body = json.dumps(data, ensure_ascii=False).encode('utf-8')
            self.send_response(status)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _body(self) -> dict | None:
            length = int(self.headers.get('Content-Length') or 0)
            if length <= 0 or length > MAX_BODY:
                return None
            try:
                data = json.loads(self.rfile.read(length).decode('utf-8'))
            except (ValueError, UnicodeDecodeError):
                return None
            return data if isinstance(data, dict) and isinstance(data.get('text'), str) else None

        def do_GET(self) -> None:
            if self.path == '/health':
                return self._json(200, service.health())
            return self._json(404, {'ok': False, 'error': 'not_found'})

        def do_POST(self) -> None:
            if self.path not in ('/plan', '/speak'):
                return self._json(404, {'ok': False, 'error': 'not_found'})
            data = self._body()
            if data is None:
                return self._json(400, {'ok': False, 'error': 'bad_request'})
            language = data.get('language') or 'de'
            if not isinstance(language, str) or language not in service.entries:
                return self._json(400, {'ok': False, 'error': 'bad_language'})
            if self.path == '/plan':
                parts, spoken_in = service.plan(data['text'], language)
                return self._json(200, {'ok': True, 'parts': parts, 'language': spoken_in, 'textprep': TEXTPREP_VERSION[spoken_in]})
            text = data['text'].strip()
            if not text or len(text) > MAX_PART_CHARS:
                return self._json(400, {'ok': False, 'error': 'bad_part'})
            state = service.ensure(language)
            if state != 'ready':
                return self._json(503, {'ok': False, 'error': 'voice_loading' if state == 'loading' else 'voice_unavailable'})
            try:
                audio = service.speak(text, language)
            except LookupError:
                # Unloaded for another language in the meantime: load again.
                service.ensure(language)
                return self._json(503, {'ok': False, 'error': 'voice_loading'})
            except BlockingIOError:
                return self._json(503, {'ok': False, 'error': 'voice_busy'})
            except TimeoutError:
                return self._json(504, {'ok': False, 'error': 'voice_timeout'})
            except Exception as error:
                return self._json(500, {'ok': False, 'error': 'voice_failed', 'detail': type(error).__name__})
            self.send_response(200)
            self.send_header('Content-Type', 'audio/wav')
            self.send_header('X-CoreAI-Voice-Language', language)
            self.send_header('Content-Length', str(len(audio)))
            self.end_headers()
            self.wfile.write(audio)

    return Handler


def main() -> int:
    registry = json.loads(REGISTRY.read_text(encoding='utf-8'))
    port = int(os.environ.get('COREAI_VOICE_PORT', '18790'))
    cores = max(1, int(os.environ.get('COREAI_VOICE_CORES', str(registry['limits']['cores']))))
    engine = os.environ.get('COREAI_VOICE_ENGINE', 'pocket')
    service = Service(registry, engine, cores)
    service.start()
    service.watch(WATCH_S)
    server = ThreadingHTTPServer(('127.0.0.1', port), make_handler(service))
    server.daemon_threads = True
    print(f'[CoreAI Stimme] bereit auf 127.0.0.1:{port} ({engine}, {cores} Kerne)', flush=True)
    server.serve_forever()
    return 0


if __name__ == '__main__':
    sys.exit(main())
