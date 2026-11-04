#!/usr/bin/env python3
"""CoreAI 0.5.94: voice candidates for Mara (P20.0), measured on the server.

Three steps, each in its own process:

  fetch   inside the engine container, with network: downloads the weights of
          one group into /models (Hugging Face cache, pinned revisions where the
          engine pins them) and registers every file with origin, revision and
          SHA-256 in lock-GROUP.json.
  run     inside the engine container, without network and with a fixed core
          count: checks every registered file against its SHA-256, loads the
          engine once, then speaks every test sentence with every voice of the
          group. Writes the WAV samples and result-GROUP-Nc.json.
  report  on the host (standard library only): merges the results into
          report.json (read by Admin Core > Betrieb > Stimmproben) and report.md.

Nothing here touches a Core, the Modelfile, the database or the serial queue.
"""
from __future__ import annotations

import argparse
import functools
import hashlib
import json
import math
import os
import re
import statistics
import sys
import time
import wave
from array import array
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Iterator

FORMAT = 1
ID_RE = re.compile(r'^[a-z0-9][a-z0-9_-]{0,60}$')
SENTENCE_RE = re.compile(r'^s[0-9]{1,2}$')
WARMUP_TEXT = 'Test.'
PACKAGES = ('piper-tts', 'onnxruntime', 'pocket-tts', 'chatterbox-tts', 'torch', 'torchaudio',
            'transformers', 'huggingface-hub', 'numpy', 'soundfile', 'librosa')


# --- Registry --------------------------------------------------------------

@functools.lru_cache(maxsize=1)
def load_textprep():
    """tools/tts_spike/textprep_de.py, loaded by path (python3 -I leaves the script folder out)."""
    import importlib.util
    spec = importlib.util.spec_from_file_location('coreai_textprep_de', Path(__file__).resolve().with_name('textprep_de.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def spoken_text(reg: dict, text: str) -> str:
    """0.5.98: what the voice really gets (numbers, dates, file names as words)."""
    if reg.get('textprep') != 'de-1':
        return text
    return load_textprep().prepare(text)


def use_hf_home(models: Path, group: dict) -> None:
    """0.5.98: the Kerstin clone keeps the gated Kyutai weights in their own cache, so
    the other groups never load a file they did not register."""
    os.environ['HF_HOME'] = str(models / group.get('hf_home', 'hf'))


def group_reference(reg: dict, group: dict) -> dict | None:
    name = group.get('reference')
    return reg.get('references', {}).get(name) if isinstance(name, str) else None


def load_registry(path: Path) -> dict:
    reg = json.loads(path.read_text(encoding='utf-8'))
    if reg.get('format') != FORMAT:
        raise SystemExit('voices.json: unknown format')
    seen = set()
    for sentence in reg.get('sentences', []):
        if not SENTENCE_RE.match(str(sentence.get('id', ''))) or not str(sentence.get('text', '')).strip():
            raise SystemExit('voices.json: bad sentence ' + repr(sentence.get('id')))
    for group_id, group in reg.get('groups', {}).items():
        if not ID_RE.match(group_id):
            raise SystemExit('voices.json: bad group id ' + repr(group_id))
        if group.get('engine') not in ENGINES:
            raise SystemExit('voices.json: unknown engine for ' + group_id)
        if 'reference' in group and group['reference'] not in reg.get('references', {}):
            raise SystemExit('voices.json: unknown reference for ' + group_id)
        if group.get('hf_home', 'hf') not in ('hf', 'hf-clone'):
            raise SystemExit('voices.json: unknown hf_home for ' + group_id)
    for voice in reg.get('voices', []):
        vid = str(voice.get('id', ''))
        if not ID_RE.match(vid) or vid in seen:
            raise SystemExit('voices.json: bad or repeated voice id ' + repr(vid))
        if voice.get('group') not in reg.get('groups', {}):
            raise SystemExit('voices.json: voice without group ' + vid)
        if voice.get('status') not in ('frei', 'pruefen'):
            raise SystemExit('voices.json: voice status must be frei or pruefen: ' + vid)
        seen.add(vid)
    return reg


def group_voices(reg: dict, group_id: str) -> list[dict]:
    return [v for v in reg.get('voices', []) if v.get('group') == group_id]


def sample_name(voice_id: str, cores: int, sentence_id: str) -> str:
    return f'{voice_id}-{cores}c-{sentence_id}.wav'


# --- Files -----------------------------------------------------------------

def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, 'rb') as handle:
        for block in iter(lambda: handle.read(1 << 20), b''):
            digest.update(block)
    return digest.hexdigest()


def file_entry(models: Path, path: Path, **extra: Any) -> dict:
    entry = {'path': str(path.relative_to(models)), 'sha256': sha256_file(path), 'size': path.stat().st_size}
    entry.update({k: v for k, v in extra.items() if v is not None})
    return entry


def write_json(path: Path, data: dict) -> None:
    tmp = path.with_name(path.name + '.tmp')
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    os.replace(tmp, path)


def lock_path(models: Path, group_id: str) -> Path:
    return models / f'lock-{group_id}.json'


def verify_lock(models: Path, group_id: str) -> list[dict]:
    """Every registered file must still carry its SHA-256; nothing else is used."""
    path = lock_path(models, group_id)
    if not path.is_file():
        raise RuntimeError(f'Gewichte fuer {group_id} nicht geladen (lock-{group_id}.json fehlt)')
    lock = json.loads(path.read_text(encoding='utf-8'))
    files = lock.get('files') or []
    if not files:
        raise RuntimeError(f'lock-{group_id}.json ohne Dateien')
    for entry in files:
        target = (models / entry['path']).resolve()
        if not str(target).startswith(str(models.resolve()) + os.sep) or not target.is_file():
            raise RuntimeError('registrierte Datei fehlt: ' + entry['path'])
        if sha256_file(target) != entry['sha256']:
            raise RuntimeError('SHA-256 weicht ab: ' + entry['path'])
        entry['verified'] = True
    return files


def find_entry(files: list[dict], key: str) -> Path:
    for entry in files:
        if entry.get('key') == key:
            return Path(entry['path'])
    raise RuntimeError('Datei nicht registriert: ' + key)


def hf_revision(path: Path) -> str | None:
    parts = Path(path).parts
    if 'snapshots' in parts:
        index = parts.index('snapshots')
        if index + 1 < len(parts):
            return parts[index + 1]
    return None


def hf_scan(models: Path, repo: str, prefix: str, hf_dir: str = 'hf') -> list[dict]:
    """Registers every cached file of a Hugging Face repo below a prefix."""
    base = models / hf_dir / 'hub' / ('models--' + repo.replace('/', '--')) / 'snapshots'
    out = []
    if not base.is_dir():
        return out
    for snapshot in sorted(base.iterdir()):
        for path in sorted(snapshot.rglob('*')):
            rel = path.relative_to(snapshot).as_posix()
            if path.is_file() and rel.startswith(prefix):
                out.append(file_entry(models, path, key=rel, repo=repo, revision=snapshot.name,
                                      source=f'https://huggingface.co/{repo}/resolve/{snapshot.name}/{rel}'))
    return out


# --- Audio -----------------------------------------------------------------

def to_floats(chunk: Any) -> list[float] | Any:
    if hasattr(chunk, 'detach'):
        chunk = chunk.detach().cpu().numpy()
    if hasattr(chunk, 'reshape'):
        return chunk.reshape(-1)
    return list(chunk)


def write_wav(path: Path, chunks: list[Any], sample_rate: int) -> int:
    samples = array('h')
    for chunk in chunks:
        if hasattr(chunk, 'astype'):
            import numpy as np  # present in every engine image
            clipped = np.clip(chunk, -1.0, 1.0)
            samples.frombytes((clipped * 32767.0).astype('<i2').tobytes())
        else:
            samples.extend(int(max(-1.0, min(1.0, float(x))) * 32767.0) for x in chunk)
    with wave.open(str(path), 'wb') as handle:
        handle.setnchannels(1)
        handle.setsampwidth(2)
        handle.setframerate(int(sample_rate))
        if sys.byteorder != 'little':
            samples.byteswap()
        handle.writeframes(samples.tobytes())
    return len(samples)


def peak_rss_mb() -> float:
    try:
        import resource
        return round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024.0, 1)
    except Exception:
        return 0.0


def set_threads(cores: int) -> None:
    for name in ('OMP_NUM_THREADS', 'MKL_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'NUMEXPR_NUM_THREADS'):
        os.environ[name] = str(cores)


def torch_threads(cores: int) -> None:
    import torch
    torch.set_num_threads(cores)
    try:
        torch.set_num_interop_threads(1)
    except RuntimeError:
        pass


# --- Engines ---------------------------------------------------------------

class Probe:
    """Selftest only: a tone instead of speech, with the same measuring path."""
    params: dict = {'note': 'Testton statt Sprache'}

    def fetch(self, models: Path, group_id: str, group: dict, voices: list[dict], reg: dict) -> list[dict]:
        target = models / 'probe' / f'{group_id}.bin'
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(b'probe-weights-' + group_id.encode())
        return [file_entry(models, target, key='weights', source='selftest')]

    def load(self, models: Path, group: dict, files: list[dict], cores: int) -> None:
        self.sample_rate = 16000
        time.sleep(0.01)

    def prepare(self, voice: dict) -> float:
        if voice.get('voice') == 'broken':
            raise RuntimeError('Teststimme absichtlich defekt')
        return 220.0 + 20.0 * (sum(map(ord, voice['id'])) % 10)

    def stream(self, state: float, text: str) -> Iterator[list[float]]:
        total = int(self.sample_rate * max(0.2, 0.03 * len(text)))
        step = max(1, total // 4)
        for start in range(0, total, step):
            time.sleep(0.002)
            yield [0.3 * math.sin(2 * math.pi * state * (i / self.sample_rate)) for i in range(start, min(total, start + step))]


class Piper:
    params: dict = {'synthesis': 'Standard der Stimme (length_scale, noise_scale aus der .onnx.json)'}

    def fetch(self, models: Path, group_id: str, group: dict, voices: list[dict], reg: dict) -> list[dict]:
        from huggingface_hub import hf_hub_download
        out = []
        for spec in group['files']:
            path = Path(hf_hub_download(repo_id=spec['repo'], filename=spec['path'], revision=spec.get('revision', 'main')))
            key = 'config' if spec['path'].endswith('.json') else 'model'
            rev = hf_revision(path)
            out.append(file_entry(models, path, key=key, repo=spec['repo'], revision=rev,
                                  source=f"https://huggingface.co/{spec['repo']}/resolve/{rev or 'main'}/{spec['path']}"))
        return out

    def load(self, models: Path, group: dict, files: list[dict], cores: int) -> None:
        import onnxruntime
        from piper import PiperVoice
        from piper.config import PiperConfig
        from piper.phonemize_espeak import ESPEAK_DATA_DIR
        options = onnxruntime.SessionOptions()
        options.intra_op_num_threads = cores
        options.inter_op_num_threads = 1
        config = json.loads((models / find_entry(files, 'config')).read_text(encoding='utf-8'))
        session = onnxruntime.InferenceSession(str(models / find_entry(files, 'model')), sess_options=options,
                                               providers=['CPUExecutionProvider'])
        self.voice = PiperVoice(config=PiperConfig.from_dict(config), session=session,
                                espeak_data_dir=Path(ESPEAK_DATA_DIR), download_dir=Path('/tmp'))
        self.sample_rate = int(self.voice.config.sample_rate)

    def prepare(self, voice: dict) -> None:
        return None

    def stream(self, state: None, text: str) -> Iterator[Any]:
        for chunk in self.voice.synthesize(text):
            yield chunk.audio_float_array


class Pocket:
    params: dict = {'temperature': 'Standard des Modells (0,3)', 'quantize': False,
                    'voices': 'vordefinierte Stimmzustaende; Klon-Gruppen: Referenzaufnahme'}
    REPO = 'kyutai/pocket-tts-without-voice-cloning'
    CLONE_REPO = 'kyutai/pocket-tts'
    NO_TOKEN = ('Klon-Gewichte von Kyutai nicht freigegeben: auf huggingface.co/kyutai/pocket-tts den Bedingungen '
                'zustimmen und den Lese-Token mit "tts-spike.sh --hf-token" hinterlegen')

    def fetch(self, models: Path, group_id: str, group: dict, voices: list[dict], reg: dict) -> list[dict]:
        from pocket_tts import TTSModel
        clone = bool(group.get('clone'))
        if clone and not os.environ.get('HF_TOKEN'):
            raise RuntimeError(self.NO_TOKEN)
        model = TTSModel.load_model(language=group['language'])
        if bool(getattr(model, 'has_voice_cloning', False)) != clone:
            raise RuntimeError(self.NO_TOKEN if clone else 'Pocket TTS hat unerwartet die Klon-Gewichte geladen')
        files: list[dict] = []
        if clone:
            files.extend(fetch_reference(models, group_reference(reg, group), f'reference-{group_id}'))
            reference = models / find_entry(files, 'reference')
            states = [model.get_state_for_audio_prompt(reference)]
        else:
            states = [model.get_state_for_audio_prompt(voice['voice']) for voice in voices]
        if states:
            model.generate_audio(states[0], WARMUP_TEXT)
        hf_dir = group.get('hf_home', 'hf')
        cached = hf_scan(models, self.REPO, f"languages/{group['language']}/", hf_dir)
        if clone:
            cached += hf_scan(models, self.CLONE_REPO, f"languages/{group['language']}/", hf_dir)
        if not cached:
            raise RuntimeError('Pocket TTS: keine Dateien im Cache gefunden')
        return cached + files

    def load(self, models: Path, group: dict, files: list[dict], cores: int) -> None:
        torch_threads(cores)
        from pocket_tts import TTSModel
        self.model = TTSModel.load_model(language=group['language'])
        clone = bool(group.get('clone'))
        if bool(getattr(self.model, 'has_voice_cloning', False)) != clone:
            raise RuntimeError('Pocket TTS: Klon-Gewichte fehlen' if clone else 'Pocket TTS hat unerwartet die Klon-Gewichte geladen')
        self.reference = models / find_entry(files, 'reference') if clone else None
        self.sample_rate = int(self.model.sample_rate)

    def prepare(self, voice: dict) -> Any:
        if voice.get('voice') == 'reference':
            return self.model.get_state_for_audio_prompt(self.reference)
        return self.model.get_state_for_audio_prompt(voice['voice'])

    def stream(self, state: Any, text: str) -> Iterator[Any]:
        # 0.5.95: no torch.inference_mode() here. Pocket generates in its own
        # thread (inference mode does not reach it) and updates the state in
        # place; a state copied under inference mode is then refused ("Inplace
        # update to inference tensor outside InferenceMode"). Pocket already
        # runs under torch.no_grad itself.
        for chunk in self.model.generate_audio_stream(state, text):
            yield to_floats(chunk)


def fetch_reference(models: Path, ref: dict | None, folder_name: str) -> list[dict]:
    """Clips of a free dataset voice, joined to one reference WAV (per group folder)."""
    import urllib.request
    import numpy as np
    import soundfile
    if not ref:
        raise RuntimeError('Referenz fehlt in voices.json')
    folder = models / folder_name
    folder.mkdir(parents=True, exist_ok=True)
    out, parts, rate, total = [], [], None, 0.0
    for clip in ref['clips']:
        if not re.match(r'^[A-Za-z0-9_.-]+\.flac$', clip):
            raise RuntimeError('ungueltiger Clipname: ' + clip)
        target = folder / clip
        source = ''
        for base in ref['base_urls']:
            with urllib.request.urlopen(base + clip, timeout=60) as response:
                data = response.read()
            if data[:4] == b'fLaC':
                target.write_bytes(data)
                source = base + clip
                break
        if not source:
            raise RuntimeError('Referenzclip nicht als FLAC erhalten: ' + clip)
        out.append(file_entry(models, target, key='clip:' + clip, source=source, license=ref['license']))
        audio, clip_rate = soundfile.read(str(target), dtype='float32', always_2d=True)
        audio = audio.mean(axis=1)
        if rate is None:
            rate = clip_rate
        if clip_rate != rate:
            continue
        parts.extend([audio, np.zeros(int(rate * 0.25), dtype='float32')])
        total += len(audio) / rate
        if total >= float(ref['seconds']):
            break
    if rate is None or total < 3.0:
        raise RuntimeError('Referenz zu kurz')
    joined = np.concatenate(parts)[: int(rate * float(ref['seconds']))]
    reference = folder / 'reference.wav'
    soundfile.write(str(reference), joined, rate, subtype='PCM_16')
    out.append(file_entry(models, reference, key='reference', source='zusammengesetzt aus den Clips oben',
                          license=ref['license']))
    return out


class Chatterbox:
    params: dict = {'language_id': 'de', 'exaggeration': 0.5, 'cfg_weight': 0.5, 'temperature': 0.8,
                    'note': 'Standardwerte der Engine; chinesische Hilfsdaten abgeschaltet (nur Deutsch)'}

    @staticmethod
    def _german_only() -> None:
        # The multilingual tokenizer loads Chinese helpers at start (one of
        # them from the network). German needs neither.
        from chatterbox.models.tokenizers import tokenizer as cb_tokenizer
        cb_tokenizer.ChineseCangjieConverter._load_cangjie_mapping = lambda self, model_dir=None: None
        cb_tokenizer.ChineseCangjieConverter._init_segmenter = lambda self: setattr(self, 'segmenter', None)

    def fetch(self, models: Path, group_id: str, group: dict, voices: list[dict], reg: dict) -> list[dict]:
        from huggingface_hub import snapshot_download
        ckpt = Path(snapshot_download(repo_id=group['repo'], repo_type='model', revision=group.get('revision', 'main'),
                                      allow_patterns=group['allow']))
        rev = hf_revision(ckpt)
        out = []
        for name in group['allow']:
            path = ckpt / name
            if not path.is_file():
                raise RuntimeError('Chatterbox: Datei fehlt nach dem Laden: ' + name)
            out.append(file_entry(models, path, key=name, repo=group['repo'], revision=rev,
                                  source=f"https://huggingface.co/{group['repo']}/resolve/{rev}/{name}"))
        out.extend(fetch_reference(models, group_reference(reg, group), 'reference'))
        return out

    def load(self, models: Path, group: dict, files: list[dict], cores: int) -> None:
        torch_threads(cores)
        self._german_only()
        from chatterbox.mtl_tts import ChatterboxMultilingualTTS
        self.ckpt = models / find_entry(files, 't3_mtl23ls_v2.safetensors').parent
        self.reference = models / find_entry(files, 'reference')
        self.model = ChatterboxMultilingualTTS.from_local(self.ckpt, 'cpu')
        self.sample_rate = int(self.model.sr)

    def prepare(self, voice: dict) -> Any:
        if voice.get('voice') == 'reference':
            self.model.prepare_conditionals(str(self.reference), exaggeration=self.params['exaggeration'])
            return self.model.conds
        from chatterbox.mtl_tts import Conditionals
        return Conditionals.load(self.ckpt / 'conds.pt', map_location='cpu').to('cpu')

    def stream(self, state: Any, text: str) -> Iterator[Any]:
        import torch
        self.model.conds = state
        with torch.inference_mode():
            wav = self.model.generate(text, language_id=self.params['language_id'],
                                      exaggeration=self.params['exaggeration'],
                                      cfg_weight=self.params['cfg_weight'], temperature=self.params['temperature'])
        yield to_floats(wav)


ENGINES = {'probe': Probe, 'piper': Piper, 'pocket': Pocket, 'chatterbox': Chatterbox}


# --- Steps -----------------------------------------------------------------

def package_versions() -> dict:
    from importlib import metadata
    out = {'python': sys.version.split()[0]}
    for name in PACKAGES:
        try:
            out[name] = metadata.version(name)
        except metadata.PackageNotFoundError:
            pass
    return out


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def cmd_fetch(args: argparse.Namespace) -> int:
    reg = load_registry(Path(args.registry))
    group = reg['groups'].get(args.group)
    if group is None:
        raise SystemExit('unknown group ' + args.group)
    models = Path(args.models)
    models.mkdir(parents=True, exist_ok=True)
    engine = ENGINES[group['engine']]()
    started = time.perf_counter()
    use_hf_home(models, group)
    files = engine.fetch(models, args.group, group, group_voices(reg, args.group), reg)
    write_json(lock_path(models, args.group), {
        'format': FORMAT, 'group': args.group, 'engine': group['engine'], 'fetched_at': now_iso(),
        'seconds': round(time.perf_counter() - started, 1), 'versions': package_versions(), 'files': files,
    })
    size = sum(int(f['size']) for f in files)
    print(f'[CoreAI TTS] {args.group}: {len(files)} Dateien, {size / 1e6:.1f} MB registriert')
    return 0


def measure(engine: Any, state: Any, text: str) -> tuple[list[Any], float, float]:
    started = time.perf_counter()
    first = None
    chunks = []
    for chunk in engine.stream(state, text):
        if first is None:
            first = time.perf_counter() - started
        chunks.append(chunk)
    return chunks, time.perf_counter() - started, first if first is not None else 0.0


def cmd_run(args: argparse.Namespace) -> int:
    set_threads(args.cores)
    reg = load_registry(Path(args.registry))
    group = reg['groups'].get(args.group)
    if group is None:
        raise SystemExit('unknown group ' + args.group)
    models, out = Path(args.models), Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    voices = group_voices(reg, args.group)
    if args.first_only:
        voices = voices[:1]
    result = {
        'format': FORMAT, 'group': args.group, 'engine': group['engine'], 'cores': args.cores,
        'started_at': now_iso(), 'ok': False, 'error': '', 'load_s': None, 'warmup_s': None,
        'rss_after_load_mb': None, 'peak_rss_mb': None, 'sample_rate': None,
        'params': getattr(ENGINES[group['engine']], 'params', {}), 'versions': package_versions(),
        'files': [], 'voices': [],
    }
    result['textprep'] = reg.get('textprep')
    result_file = out / f'result-{args.group}-{args.cores}c.json'
    use_hf_home(models, group)
    try:
        if group.get('clone') and not lock_path(models, args.group).is_file():
            raise RuntimeError(Pocket.NO_TOKEN)
        result['files'] = verify_lock(models, args.group)
        engine = ENGINES[group['engine']]()
        started = time.perf_counter()
        engine.load(models, group, result['files'], args.cores)
        result['load_s'] = round(time.perf_counter() - started, 3)
        result['rss_after_load_mb'] = peak_rss_mb()
        result['sample_rate'] = engine.sample_rate
        warm_state = None
        for voice in voices:
            entry = {'id': voice['id'], 'ok': False, 'error': '', 'prepare_s': None, 'samples': []}
            result['voices'].append(entry)
            try:
                started = time.perf_counter()
                state = engine.prepare(voice)
                entry['prepare_s'] = round(time.perf_counter() - started, 3)
                if warm_state is None:
                    # The first synthesis pays one-off costs; it is measured apart.
                    _, warm, _ = measure(engine, state, WARMUP_TEXT)
                    result['warmup_s'] = round(warm, 3)
                    warm_state = state
                for sentence in reg['sentences']:
                    chunks, synth, first = measure(engine, state, spoken_text(reg, sentence['text']))
                    name = sample_name(voice['id'], args.cores, sentence['id'])
                    count = write_wav(out / name, chunks, engine.sample_rate)
                    audio = count / float(engine.sample_rate)
                    entry['samples'].append({
                        'sentence': sentence['id'], 'file': name, 'synth_s': round(synth, 3),
                        'audio_s': round(audio, 3), 'rtf': round(synth / audio, 3) if audio > 0 else None,
                        'first_audio_s': round(first, 3), 'chunks': len(chunks),
                    })
                entry['ok'] = True
            except Exception as error:  # one broken voice does not stop the group
                entry['error'] = f'{type(error).__name__}: {error}'[:400]
        result['ok'] = any(v['ok'] for v in result['voices'])
        if not result['ok'] and not result['error']:
            result['error'] = 'keine Stimme dieser Gruppe hat gesprochen'
    except Exception as error:
        result['error'] = f'{type(error).__name__}: {error}'[:400]
    result['peak_rss_mb'] = peak_rss_mb()
    result['finished_at'] = now_iso()
    write_json(result_file, result)
    state = 'OK' if result['ok'] else 'FEHLER ' + result['error']
    print(f"[CoreAI TTS] {args.group}, {args.cores} Kerne: {state}")
    return 0 if result['ok'] else 1


def median(values: Iterable[Any]) -> float | None:
    clean = [float(v) for v in values if isinstance(v, (int, float))]
    return round(statistics.median(clean), 3) if clean else None


def build_report(reg: dict, run_dir: Path, stamp: str) -> dict:
    results = []
    for path in sorted(run_dir.glob('result-*.json')):
        try:
            data = json.loads(path.read_text(encoding='utf-8'))
        except (OSError, ValueError):
            continue
        if data.get('format') == FORMAT and data.get('group') in reg['groups']:
            results.append(data)
    sample_cores = int(reg.get('sample_cores', 4))
    host_file = run_dir / 'host.txt'
    host = host_file.read_text(encoding='utf-8', errors='replace').splitlines()[:20] if host_file.is_file() else []
    groups, voices, errors = [], [], []
    for group_id, group in reg['groups'].items():
        if group['engine'] == 'probe' and not any(r['group'] == group_id for r in results):
            continue
        runs = sorted((r for r in results if r['group'] == group_id), key=lambda r: r['cores'])
        if not runs:
            continue
        engine = reg.get('engines', {}).get(group['engine'], {})
        group_out = {
            'id': group_id, 'engine': group['engine'], 'label': group.get('label', group_id),
            'engine_label': engine.get('label', group['engine']), 'package': engine.get('package', ''),
            'code_license': engine.get('code_license', ''), 'code_note': engine.get('code_note', ''),
            'runs': [], 'files': [], 'params': runs[-1].get('params', {}), 'versions': runs[-1].get('versions', {}),
        }
        for run in runs:
            measured = [s for v in run.get('voices', []) if v.get('ok') for s in v.get('samples', [])]
            group_out['runs'].append({
                'cores': run['cores'], 'ok': bool(run.get('ok')), 'error': run.get('error', ''),
                'load_s': run.get('load_s'), 'warmup_s': run.get('warmup_s'),
                'rss_after_load_mb': run.get('rss_after_load_mb'), 'peak_rss_mb': run.get('peak_rss_mb'),
                'rtf_median': median(s['rtf'] for s in measured),
                'first_audio_median_s': median(s['first_audio_s'] for s in measured),
                'voices': len([v for v in run.get('voices', []) if v.get('ok')]),
            })
            if run.get('error'):
                errors.append({'group': group_id, 'cores': run['cores'], 'voice': '', 'error': run['error']})
            for v in run.get('voices', []):
                if v.get('error'):
                    errors.append({'group': group_id, 'cores': run['cores'], 'voice': v['id'], 'error': v['error']})
            if not group_out['files'] and run.get('files'):
                group_out['files'] = [{k: f.get(k) for k in ('key', 'path', 'repo', 'revision', 'source', 'sha256', 'size', 'license')}
                                      for f in run['files']]
        groups.append(group_out)
        preferred = [r for r in runs if r['cores'] == sample_cores] or [runs[-1]]
        sample_run = preferred[0]
        for voice in group_voices(reg, group_id):
            produced = next((v for v in sample_run.get('voices', []) if v.get('id') == voice['id']), None)
            samples = produced.get('samples', []) if produced else []
            voices.append({
                'id': voice['id'], 'group': group_id, 'engine': group['engine'], 'label': voice.get('label', voice['id']),
                'model': group.get('label', group_id), 'gender': voice.get('gender', ''), 'status': voice['status'],
                'voice_license': voice.get('voice_license', ''), 'weights_license': voice.get('weights_license', ''),
                'code_license': engine.get('code_license', ''), 'origin': voice.get('origin', ''), 'note': voice.get('note', ''),
                'cores': sample_run['cores'], 'ok': bool(produced and produced.get('ok')),
                'error': (produced or {}).get('error', '') or ('' if produced else (sample_run.get('error') or 'nicht gemessen')),
                'load_s': sample_run.get('load_s'), 'peak_rss_mb': sample_run.get('peak_rss_mb'),
                'rtf_median': median(s['rtf'] for s in samples),
                'first_audio_median_s': median(s['first_audio_s'] for s in samples),
                'samples': samples,
            })
    return {
        'format': FORMAT, 'stamp': stamp, 'created_at': now_iso(), 'sample_cores': sample_cores, 'host': host,
        'sentences': [dict(s, spoken=spoken_text(reg, s['text'])) for s in reg['sentences']],
        'textprep': reg.get('textprep'), 'groups': groups, 'voices': voices, 'errors': errors,
    }


def fmt(value: Any, digits: int = 2) -> str:
    if value is None:
        return '-'
    if isinstance(value, float):
        return f'{value:.{digits}f}'.replace('.', ',')
    return str(value)


def report_markdown(report: dict) -> str:
    lines = [f"# CoreAI Stimmtest {report['stamp']}", '',
             'Echtzeitfaktor (RTF) = Rechenzeit / Audiodauer; unter 1 ist schneller als Echtzeit.', '']
    if report['host']:
        lines += ['## Server', '', '```', *report['host'], '```', '']
    lines += ['## Engines', '', '| Gruppe | Kerne | Laden s | Aufwärmen s | RAM MB | RTF (Median) | Erster Ton s | Stimmen | Fehler |',
              '|---|---|---|---|---|---|---|---|---|']
    for group in report['groups']:
        for run in group['runs']:
            lines.append(f"| {group['label']} | {run['cores']} | {fmt(run['load_s'])} | {fmt(run['warmup_s'])} | "
                         f"{fmt(run['peak_rss_mb'], 0)} | {fmt(run['rtf_median'])} | {fmt(run['first_audio_median_s'])} | "
                         f"{run['voices']} | {run['error'] or '-'} |")
    lines += ['', f"## Stimmen ({report['sample_cores']} Kerne)", '',
              '| Stimme | Modell | Lizenz | RTF | Erster Ton s | Status |', '|---|---|---|---|---|---|']
    for voice in report['voices']:
        status = 'ok' if voice['ok'] else ('FEHLER: ' + voice['error'])
        lines.append(f"| {voice['label']} ({voice['gender']}) | {voice['model']} | {voice['status']}: {voice['voice_license']} | "
                     f"{fmt(voice['rtf_median'])} | {fmt(voice['first_audio_median_s'])} | {status} |")
    lines += ['', '## Sätze', '']
    for s in report['sentences']:
        lines.append(f"- {s['id']} ({s['topic']}): {s['text']}")
        if s.get('spoken') and s['spoken'] != s['text']:
            lines.append(f"  gesprochen als: {s['spoken']}")
    lines += ['', '## Registrierte Dateien', '']
    for group in report['groups']:
        for f in group['files']:
            lines.append(f"- {group['id']}: `{f.get('path')}` {f.get('revision') or ''} sha256 {f.get('sha256')} ({f.get('size')} Bytes)")
    if report['errors']:
        lines += ['', '## Fehler', '']
        lines += [f"- {e['group']} {e['cores']} Kerne {e['voice']}: {e['error']}" for e in report['errors']]
    return '\n'.join(lines) + '\n'


def cmd_report(args: argparse.Namespace) -> int:
    reg = load_registry(Path(args.registry))
    run_dir = Path(args.run_dir)
    stamp = run_dir.name
    report = build_report(reg, run_dir, stamp)
    write_json(run_dir / 'report.json', report)
    (run_dir / 'report.md').write_text(report_markdown(report), encoding='utf-8')
    ok = sum(1 for v in report['voices'] if v['ok'])
    print(f"[CoreAI TTS] Bericht: {ok} von {len(report['voices'])} Stimmen mit Hörproben, {len(report['errors'])} Fehler")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='CoreAI Stimmtest (P20.0)')
    parser.add_argument('--registry', default=str(Path(__file__).with_name('voices.json')))
    sub = parser.add_subparsers(dest='cmd', required=True)
    fetch = sub.add_parser('fetch')
    fetch.add_argument('--group', required=True)
    fetch.add_argument('--models', required=True)
    run = sub.add_parser('run')
    run.add_argument('--group', required=True)
    run.add_argument('--models', required=True)
    run.add_argument('--out', required=True)
    run.add_argument('--cores', type=int, required=True)
    run.add_argument('--first-only', action='store_true')
    report = sub.add_parser('report')
    report.add_argument('--run-dir', required=True)
    args = parser.parse_args(argv)
    if getattr(args, 'cores', 1) < 1:
        raise SystemExit('--cores must be at least 1')
    return {'fetch': cmd_fetch, 'run': cmd_run, 'report': cmd_report}[args.cmd](args)


if __name__ == '__main__':
    sys.exit(main())
