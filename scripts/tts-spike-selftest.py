#!/usr/bin/env python3
"""CoreAI 0.5.94: the voice test (P20.0) without engines and without network.

A probe engine speaks a tone instead of German through the same path as the
real engines: register the weights with SHA-256, refuse changed weights,
measure every sentence, write WAV samples, merge the report that Admin Core
reads. Also checks the registry (licenses, ids, sentences), the pinned images
and that tts-spike.sh measures without network and with a core limit.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import wave
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RUN = ROOT / 'tools' / 'tts_spike' / 'run.py'
REGISTRY = ROOT / 'tools' / 'tts_spike' / 'voices.json'
SAMPLE_RE = re.compile(r'^[a-z0-9][a-z0-9_-]{0,60}-[0-9]{1,2}c-s[0-9]{1,2}\.wav$')


def check(ok: bool, why: str) -> None:
    if not ok:
        print('TTS spike FAIL: ' + why, file=sys.stderr)
        sys.exit(1)


def call(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, '-I', str(RUN), *args], capture_output=True, text=True, timeout=120)


# --- The real registry ---------------------------------------------------------
reg = json.loads(REGISTRY.read_text(encoding='utf-8'))
check(reg['format'] == 1 and reg['sample_cores'] == 4, 'registry format, samples with 4 cores')
check([s['id'] for s in reg['sentences']] == [f's{i}' for i in range(1, 8)], 'seven test sentences')
for sentence in reg['sentences']:
    check(chr(0x2013) not in sentence['text'] and chr(0x2014) not in sentence['text'], 'no long dashes in ' + sentence['id'])
topics = ' '.join(s['topic'] for s in reg['sentences'])
for topic in ('Begrüßung', 'Zahlen', 'Datum', 'Frage', 'Umlaute', 'Absatz'):
    check(topic in topics, 'sentence topic ' + topic)
check(set(reg['engines']) == {'piper', 'pocket', 'chatterbox'}, 'three engines')
check(reg['engines']['piper']['package'] == 'piper-tts==1.8.0' and reg['engines']['piper']['code_license'] == 'GPL-3.0-or-later', 'Piper pinned, GPL named')
check(reg['engines']['pocket']['package'] == 'pocket-tts==4.0.1' and reg['engines']['chatterbox']['package'] == 'chatterbox-tts==0.1.7', 'engines pinned')
ids = [v['id'] for v in reg['voices']]
check(len(ids) == len(set(ids)) == 18, '18 voices, each once')
for voice in reg['voices']:
    check(re.match(r'^[a-z0-9][a-z0-9_-]{0,60}$', voice['id']) is not None, 'voice id ' + voice['id'])
    check(voice['group'] in reg['groups'], 'voice group ' + voice['id'])
    check(voice['status'] in ('frei', 'pruefen'), 'voice status ' + voice['id'])
    check(bool(voice['voice_license']) and bool(voice['weights_license']) and bool(voice['origin']), 'license and origin of ' + voice['id'])
    check(voice['gender'] in ('weiblich', 'männlich'), 'gender of ' + voice['id'])
    for word in ('expresso', 'ears/', 'cosette', 'jean', 'juergen'):
        check(word not in voice['origin'] and voice.get('voice') != word, 'no non-commercial or unclear voice: ' + voice['id'])
    if voice['status'] == 'frei':
        check(any(free in voice['voice_license'] for free in ('CC0', 'CC BY 4.0')), 'free voice has a free license: ' + voice['id'])
check(next(v for v in reg['voices'] if v['id'] == 'piper-ramona')['status'] == 'pruefen', 'M-AILABS voices are marked for review')
check(next(v for v in reg['voices'] if v['id'] == 'chatterbox-standard')['status'] == 'pruefen', 'undocumented built-in voice marked for review')
ref = reg['groups']['chatterbox']['reference']
check(ref['license'] == 'CC0 1.0' and 'dataset-voice-kerstin' in ref['dataset'] and ref['seconds'] == 10, 'clone reference: Kerstin, CC0, 10 s')
check(all(re.match(r'^de_rhasspy-[0-9]{4}\.flac$', c) for c in ref['clips']), 'reference clips by name')
check(reg['groups']['chatterbox']['allow'] == ['ve.pt', 't3_mtl23ls_v2.safetensors', 's3gen.pt', 'grapheme_mtl_merged_expanded_v1.json', 'conds.pt'],
      'Chatterbox loads only the multilingual files')
for gid in ('pocket-de', 'pocket-de24'):
    check(all(v['group'] != gid or v['voice'] in ('alba', 'anna', 'vera', 'eve', 'caro_davy', 'estelle') for v in reg['voices']), 'Pocket voices of ' + gid)

# --- Pinned images ------------------------------------------------------------------
docker = ROOT / 'docker' / 'tts-spike'
dockerfile = (docker / 'Dockerfile').read_text(encoding='utf-8')
for target, package in (('piper', 'piper-tts==1.8.0'), ('pocket', 'pocket-tts==4.0.1'), ('chatterbox', 'chatterbox-tts==0.1.7')):
    check(f'AS {target}' in dockerfile and f'COPY constraints-{target}.txt /tmp/constraints.txt' in dockerfile, 'image target ' + target)
    pins = (docker / f'constraints-{target}.txt').read_text(encoding='utf-8')
    check(package in pins.splitlines(), 'constraints pin ' + package)
    check(not re.search(r'^nvidia-', pins, re.M), 'no CUDA packages for ' + target)
check(dockerfile.count('--index-url https://download.pytorch.org/whl/cpu torch==2.6.0') == 2, 'torch from the CPU index')
check(dockerfile.count('--only-binary=:all:') == 5, 'only prebuilt packages')
# 0.5.95: Pocket generates in its own thread and updates the state in place; under
# torch.inference_mode() that state is refused, so its stream must not use it.
import ast
tree = ast.parse((ROOT / 'tools' / 'tts_spike' / 'run.py').read_text(encoding='utf-8'))
pocket = next(n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == 'Pocket')
stream = next(n for n in pocket.body if isinstance(n, ast.FunctionDef) and n.name == 'stream')
check('inference_mode' not in ast.unparse(stream), 'Pocket stream runs without inference_mode')
check('generate_audio_stream' in ast.unparse(stream), 'Pocket streams its chunks')

# --- tts-spike.sh: measures without network and with a core limit --------------------
# 0.5.96: the dry runs look at an empty folder of their own. On the server a real
# run leaves registered weights behind (lock-*.json), and the script then rightly
# skips fetching them; the test must not depend on that.
script = ROOT / 'scripts' / 'tts-spike.sh'
check(subprocess.run(['bash', '-n', str(script)]).returncode == 0, 'tts-spike.sh syntax')
sandbox = Path(tempfile.mkdtemp(prefix='coreai-tts-dry-'))


def dry_run(*args: str) -> subprocess.CompletedProcess:
    env = dict(os.environ, COREAI_TTS_SPIKE_BASE=str(sandbox))
    return subprocess.run(['bash', str(script), '--dry-run', *args], capture_output=True, text=True, timeout=60, env=env)


dry = dry_run()
check(dry.returncode == 0, 'dry run: ' + dry.stderr)
lines = dry.stdout.splitlines()
builds = [l for l in lines if l.startswith('+ docker build')]
check(len(builds) == 3, 'one image per engine')
runs = [l for l in lines if 'run.py run ' in l]
fetches = [l for l in lines if 'run.py fetch ' in l]
check(len(fetches) == 7 and len(runs) == 14, 'seven groups fetched, each measured with 2 and 4 cores')
for line in runs:
    check('--network none' in line and '--cpuset-cpus' in line and 'HF_HUB_OFFLINE=1' in line, 'measuring without network, core limit')
    check('/models:ro' in line and '--cap-drop ALL' in line and '--read-only' in line, 'weights read-only, container locked down')
    check(('--cores 2 --first-only' in line) or line.endswith('--cores 4'), 'all voices at 4 cores, the first one at 2')
    check(f'{sandbox}/models:/models:ro' in line, 'the dry run uses its own folder')
for line in fetches:
    check('--network none' not in line and '/models:ro' not in line, 'fetching needs network and write access')
check(any('run.py report' in l for l in lines), 'report at the end')
# Registered weights are not fetched again, unless --refetch asks for it.
(sandbox / 'models').mkdir(parents=True)
(sandbox / 'models' / 'lock-pocket-de.json').write_text('{}', encoding='utf-8')
kept = [l for l in dry_run().stdout.splitlines() if 'run.py fetch ' in l]
check(len(kept) == 6 and not any('--group pocket-de ' in l for l in kept), 'registered weights are kept')
check(len([l for l in dry_run('--refetch').stdout.splitlines() if 'run.py fetch ' in l]) == 7, '--refetch loads them again')
check('if [[ -n "$DRY_RUN" && -n "${COREAI_TTS_SPIKE_BASE:-}" ]]; then' in script.read_text(encoding='utf-8'),
      'another folder only for a dry run')
bad = dry_run('--cores', '2;rm')
check(bad.returncode != 0, 'bad --cores refused')
# 0.5.95: --run adds groups to an existing run (e.g. after a fix), the report covers all of it.
# 0.5.97: "letzter" takes the newest run; an unknown name lists the runs there are.
none_yet = dry_run('--run', 'letzter')
check(none_yet.returncode != 0 and 'Noch kein Testlauf vorhanden' in none_yet.stderr, 'letzter without any run')
for stamp in ('20261104-120000', '20261104-141500'):
    (sandbox / 'runs' / stamp).mkdir(parents=True)
(sandbox / 'runs' / 'notizen').mkdir()
missing = dry_run('--run', '20261104-155136')
check(missing.returncode != 0 and 'Lauf 20261104-155136 nicht gefunden. Vorhanden: 20261104-141500 20261104-120000. Einfacher: --run letzter'
      in missing.stderr, 'unknown run: the existing ones are named, newest first: ' + missing.stderr)
latest = dry_run('--skip-build', '--groups', 'pocket-de', '--run', 'letzter')
check(latest.returncode == 0 and 'Schreibe in den vorhandenen Lauf 20261104-141500.' in latest.stdout
      and all('/runs/20261104-141500:/out' in l for l in latest.stdout.splitlines() if 'run.py run ' in l), 'letzter is the newest run')
again = dry_run('--skip-build', '--groups', 'pocket-de,pocket-de24', '--run', '20261104-141500')
check(again.returncode == 0, '--run dry run: ' + again.stderr)
again_runs = [l for l in again.stdout.splitlines() if 'run.py run ' in l]
check(len(again_runs) == 4 and all('/runs/20261104-141500:/out' in l for l in again_runs), 'measured into the given run')
check(not any('docker build' in l or 'run.py fetch' in l and 'chatterbox' in l for l in again.stdout.splitlines()), 'only the chosen groups')
check(any('run.py report --run-dir ' in l and l.endswith('/runs/20261104-141500') for l in again.stdout.splitlines()), 'report rebuilt for that run')
for wrong in ('2026', '../20261104-141500', '20261104-141500;rm', 'ZEITSTEMPEL'):
    check(dry_run('--run', wrong).returncode != 0, 'bad --run refused: ' + wrong)
shutil.rmtree(sandbox)
shell = script.read_text(encoding='utf-8')
check('chown -R root:33 "$OUT"' in shell and "-exec chmod 0640 {} +" in shell, 'samples readable for PHP only (root:33, 0640)')
check("[[ \"$stamp\" =~ ^[0-9]{8}-[0-9]{6}$ ]] && rm -rf -- \"${RUNS:?}/$stamp\"" in shell, 'cleanup touches only time stamp folders')

# --- The measuring path with a probe engine --------------------------------------------
with tempfile.TemporaryDirectory() as tmp:
    tmp_path = Path(tmp)
    test_reg = {
        'format': 1, 'sample_cores': 4, 'sentences': reg['sentences'],
        'engines': {'probe': {'label': 'Testton', 'package': 'probe', 'code_license': 'MIT'}},
        'groups': {'probe-a': {'engine': 'probe', 'label': 'Testton A'}, 'probe-b': {'engine': 'probe', 'label': 'Testton B'}},
        'voices': [
            {'id': 'probe-a-eins', 'group': 'probe-a', 'voice': 'eins', 'label': 'Eins', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-a-kaputt', 'group': 'probe-a', 'voice': 'broken', 'label': 'Kaputt', 'gender': 'weiblich', 'status': 'pruefen',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-b-zwei', 'group': 'probe-b', 'voice': 'zwei', 'label': 'Zwei', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC BY 4.0', 'weights_license': 'MIT', 'origin': 'Test'},
        ],
    }
    registry = tmp_path / 'voices.json'
    registry.write_text(json.dumps(test_reg, ensure_ascii=False), encoding='utf-8')
    models, out = tmp_path / 'models', tmp_path / 'runs' / '20261104-120000'
    r = call('--registry', str(registry), 'run', '--group', 'probe-a', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 1, 'no run without registered weights')
    check('lock-probe-a.json fehlt' in json.loads((out / 'result-probe-a-4c.json').read_text())['error'], 'missing weights reported')
    for group in ('probe-a', 'probe-b'):
        r = call('--registry', str(registry), 'fetch', '--group', group, '--models', str(models))
        check(r.returncode == 0, 'fetch ' + group + ': ' + r.stderr)
    lock = json.loads((models / 'lock-probe-a.json').read_text())
    check(len(lock['files']) == 1 and re.match(r'^[0-9a-f]{64}$', lock['files'][0]['sha256']) and lock['files'][0]['path'] == 'probe/probe-a.bin',
          'weights registered with SHA-256')
    r = call('--registry', str(registry), 'run', '--group', 'probe-a', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 0, 'run probe-a: ' + r.stderr + r.stdout)
    r = call('--registry', str(registry), 'run', '--group', 'probe-a', '--models', str(models), '--out', str(out), '--cores', '2', '--first-only')
    check(r.returncode == 0, 'run probe-a with 2 cores')
    r = call('--registry', str(registry), 'run', '--group', 'probe-b', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 0, 'run probe-b')
    # Changed weights are refused.
    (models / 'probe' / 'probe-b.bin').write_bytes(b'changed')
    r = call('--registry', str(registry), 'run', '--group', 'probe-b', '--models', str(models), '--out', str(out), '--cores', '2', '--first-only')
    check(r.returncode == 1, 'changed weights refused')
    check('SHA-256 weicht ab' in json.loads((out / 'result-probe-b-2c.json').read_text())['error'], 'the reason is named')

    result = json.loads((out / 'result-probe-a-4c.json').read_text())
    check(result['ok'] and result['files'][0]['verified'] is True and result['sample_rate'] == 16000, 'group ok, weights verified')
    check(result['load_s'] is not None and result['warmup_s'] is not None and result['peak_rss_mb'] > 0, 'load, warm-up and RAM measured')
    eins = next(v for v in result['voices'] if v['id'] == 'probe-a-eins')
    kaputt = next(v for v in result['voices'] if v['id'] == 'probe-a-kaputt')
    check(eins['ok'] and len(eins['samples']) == 7, 'every sentence spoken')
    check(not kaputt['ok'] and 'absichtlich defekt' in kaputt['error'], 'a broken voice is reported, the group goes on')
    for sample in eins['samples']:
        check(SAMPLE_RE.match(sample['file']) is not None, 'sample name ' + sample['file'])
        check(sample['audio_s'] > 0 and sample['synth_s'] > 0 and abs(sample['rtf'] - sample['synth_s'] / sample['audio_s']) < 0.01, 'RTF of ' + sample['sentence'])
        check(0 < sample['first_audio_s'] <= sample['synth_s'] and sample['chunks'] >= 1, 'first audio before the end')
        with wave.open(str(out / sample['file'])) as handle:
            check(handle.getnchannels() == 1 and handle.getsampwidth() == 2 and handle.getframerate() == 16000, 'WAV mono 16 bit')
            check(abs(handle.getnframes() / 16000 - sample['audio_s']) < 0.01, 'audio length matches')
    two = json.loads((out / 'result-probe-a-2c.json').read_text())
    check([v['id'] for v in two['voices']] == ['probe-a-eins'], 'with 2 cores only the first voice')

    (out / 'host.txt').write_text('Zeit: test\nCPU(s): 20\n', encoding='utf-8')
    r = call('--registry', str(registry), 'report', '--run-dir', str(out))
    check(r.returncode == 0, 'report: ' + r.stderr)
    report = json.loads((out / 'report.json').read_text())
    check(report['format'] == 1 and report['stamp'] == '20261104-120000' and report['sample_cores'] == 4, 'report head')
    check(report['host'] == ['Zeit: test', 'CPU(s): 20'] and len(report['sentences']) == 7, 'host and sentences')
    voices = {v['id']: v for v in report['voices']}
    check(set(voices) == {'probe-a-eins', 'probe-a-kaputt', 'probe-b-zwei'}, 'every voice in the report')
    check(voices['probe-a-eins']['ok'] and voices['probe-a-eins']['cores'] == 4 and len(voices['probe-a-eins']['samples']) == 7, 'samples from the 4 core run')
    check(voices['probe-a-eins']['rtf_median'] is not None and voices['probe-a-eins']['first_audio_median_s'] is not None, 'medians per voice')
    check(voices['probe-a-eins']['status'] == 'frei' and voices['probe-a-eins']['voice_license'] == 'CC0 1.0', 'license per voice')
    check(not voices['probe-a-kaputt']['ok'] and voices['probe-a-kaputt']['samples'] == [], 'broken voice without samples')
    check(voices['probe-b-zwei']['ok'], 'probe-b samples from its good 4 core run')
    groups = {g['id']: g for g in report['groups']}
    check([r['cores'] for r in groups['probe-a']['runs']] == [2, 4] and all(r['ok'] for r in groups['probe-a']['runs']), 'runs per core count')
    check(groups['probe-a']['runs'][0]['rtf_median'] is not None and groups['probe-a']['runs'][0]['peak_rss_mb'] > 0, 'group figures')
    check(not groups['probe-b']['runs'][0]['ok'] and 'SHA-256' in groups['probe-b']['runs'][0]['error'], 'the refused run stays visible')
    check(groups['probe-a']['files'][0]['sha256'] == lock['files'][0]['sha256'], 'registered files in the report')
    check(any(e['voice'] == 'probe-a-kaputt' for e in report['errors']) and any(e['group'] == 'probe-b' and e['cores'] == 2 for e in report['errors']),
          'errors listed')
    md = (out / 'report.md').read_text(encoding='utf-8')
    check('| Testton A | 4 |' in md and 'Kaputt (weiblich)' in md and 'sha256 ' in md and '## Fehler' in md, 'report.md')
    for name in [s['file'] for v in report['voices'] for s in v['samples']]:
        check((out / name).is_file(), 'listed sample exists: ' + name)

print('CoreAI TTS spike: registry, licenses, pinned images, offline measuring with a core limit, SHA-256 check, samples and report OK')
