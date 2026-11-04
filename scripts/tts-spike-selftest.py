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
check(len(ids) == len(set(ids)) == 24, '24 voices, each once')
# 0.5.99: the operator chose Eve (24 layers); she is measured alone with and without int8, at 4, 6 and 8 cores.
check(reg['groups']['pocket-de24-eve'] == {'engine': 'pocket', 'label': reg['groups']['pocket-de24-eve']['label'], 'language': 'german_24l'}
      and reg['groups']['pocket-de24-eve-int8'].get('quantize') is True and reg['groups']['pocket-de24-eve-int8']['language'] == 'german_24l',
      'Eve groups')
for vid, gid in (('pocket-de24-eve-solo', 'pocket-de24-eve'), ('pocket-de24-eve-int8', 'pocket-de24-eve-int8')):
    voice = next(v for v in reg['voices'] if v['id'] == vid)
    check(voice['group'] == gid and voice['voice'] == 'eve' and voice['status'] == 'frei' and 'p361' in voice['voice_license'], 'Eve voice ' + vid)
    check([v['id'] for v in reg['voices'] if v['group'] == gid] == [vid], gid + ' has Eve alone')
check("TTSModel.load_model(language=group['language'], quantize=bool(group.get('quantize')))"
      in (ROOT / 'tools' / 'tts_spike' / 'run.py').read_text(encoding='utf-8'), 'int8 only where the group asks for it')
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
ref = reg['references']['kerstin']
check(ref['license'] == 'CC0 1.0' and 'dataset-voice-kerstin' in ref['dataset'] and ref['seconds'] == 10, 'clone reference: Kerstin, CC0, 10 s')
check(reg['groups']['chatterbox']['reference'] == 'kerstin', 'Chatterbox clones Kerstin')
# 0.5.98: Kerstin also as a Pocket clone, in a cache of its own (the gated Kyutai weights never reach the other groups).
for gid, language in (('pocket-de-kerstin', 'german'), ('pocket-de24-kerstin', 'german_24l')):
    group = reg['groups'][gid]
    check(group == {'engine': 'pocket', 'label': group['label'], 'language': language, 'clone': True, 'hf_home': 'hf-clone',
                    'reference': 'kerstin'}, 'clone group ' + gid)
    voice = next(v for v in reg['voices'] if v['id'] == gid)
    check(voice['voice'] == 'reference' and voice['gender'] == 'weiblich' and voice['status'] == 'frei' and 'CC0' in voice['voice_license'],
          'clone voice ' + gid)
check(all(g.get('hf_home', 'hf') == 'hf' for g in reg['groups'].values() if not g.get('clone')), 'every other group in the shared cache')
# 0.5.98: the token-free way for every installation: the clone's exported voice file on the free weights.
for gid, source, language in (('pocket-de-stimmdatei', 'pocket-de-kerstin', 'german'), ('pocket-de24-stimmdatei', 'pocket-de24-kerstin', 'german_24l')):
    group = reg['groups'][gid]
    check(group == {'engine': 'pocket', 'label': group['label'], 'language': language, 'voice_file_from': source}, 'voice file group ' + gid)
    check(list(reg['groups']).index(gid) > list(reg['groups']).index(source), gid + ' after its clone')
    voice = next(v for v in reg['voices'] if v['id'] == gid)
    check(voice['voice'] == 'file' and voice['status'] == 'frei' and 'kein Token' in voice['weights_license'], 'voice file voice ' + gid)
run_source = (ROOT / 'tools' / 'tts_spike' / 'run.py').read_text(encoding='utf-8')
check("export_model_state(states[0], str(target))" in run_source and "key='voice-file'" in run_source, 'the clone exports its voice file')
check(next(v for v in reg['voices'] if v['id'] == 'chatterbox-standard')['gender'] == 'männlich', 'the Chatterbox standard voice is male')
check(reg['textprep'] == 'de-2', 'German text preparation on')
check(all(re.match(r'^de_rhasspy-[0-9]{4}\.flac$', c) for c in ref['clips']), 'reference clips by name')
check(reg['groups']['chatterbox']['allow'] == ['ve.pt', 't3_mtl23ls_v2.safetensors', 's3gen.pt', 'grapheme_mtl_merged_expanded_v1.json', 'conds.pt'],
      'Chatterbox loads only the multilingual files')
for gid in ('pocket-de', 'pocket-de24'):
    check(all(v['group'] != gid or v['voice'] in ('alba', 'anna', 'vera', 'eve', 'caro_davy', 'estelle') for v in reg['voices']), 'Pocket voices of ' + gid)

# --- 0.6.0: Mara's chosen voice, registered with origin, SHA-256 and license ------------------
mara = json.loads((ROOT / 'config' / 'voice' / 'mara-voice.json').read_text(encoding='utf-8'))
check(mara['format'] == 1 and mara['voice']['name'] == 'eve' and mara['model'] == {
    'label': mara['model']['label'], 'language': 'german_24l', 'quantize': True, 'weights_license': 'CC BY 4.0 (Kyutai)',
    'voice_cloning_weights': False}, 'Mara speaks as Eve, 24 layers, int8, free weights')
check(mara['engine']['package'] == reg['engines']['pocket']['package']
      and mara['engine']['package'] in (ROOT / 'docker' / 'tts-spike' / 'constraints-pocket.txt').read_text(encoding='utf-8').splitlines(),
      'the same pinned engine as in the test')
check(mara['textprep'] == reg['textprep'] == 'de-2', 'the same text preparation')
check([f['key'] for f in mara['files']] == ['model', 'tokenizer', 'voice'], 'model, tokenizer and voice state registered')
for entry in mara['files']:
    check(re.fullmatch(r'[0-9a-f]{64}', entry['sha256']) is not None and re.fullmatch(r'[0-9a-f]{40}', entry['revision']) is not None
          and entry['size'] > 0 and entry['repo'] == 'kyutai/pocket-tts-without-voice-cloning' and 'CC BY 4.0' in entry['license'],
          'registered file ' + entry['key'])
check(mara['files'][0]['path'] == 'languages/german_24l/model.safetensors' and mara['files'][2]['path'] == 'languages/german_24l/embeddings/eve.safetensors',
      'the 24 layer model and Eve')
check('Kyutai' in mara['attribution'] and 'VCTK' in mara['attribution'] and 'p361' in mara['attribution'], 'attribution names Kyutai and VCTK')
check(mara['limits']['cores'] == 4 and mara['measured']['rtf'] < 0.8, 'core limit with room below real time')
eve_int8 = next(v for v in reg['voices'] if v['id'] == 'pocket-de24-eve-int8')
check(reg['groups'][eve_int8['group']].get('quantize') is True and eve_int8['voice'] == mara['voice']['name'], 'the measured voice is the registered one')

# --- 0.5.98: German text preparation ----------------------------------------------------
import importlib.util
spec = importlib.util.spec_from_file_location('textprep_de', ROOT / 'tools' / 'tts_spike' / 'textprep_de.py')
prep = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prep)
spoken = {s['id']: prep.prepare(s['text']) for s in reg['sentences']}
check(spoken['s2'] == 'Dein Kontextfenster ist auf zweiunddreißigtausendsiebenhundertachtundsechzig Tokens eingestellt, und drei von vier Werkzeugen sind aktiv.', 's2: ' + spoken['s2'])
check(spoken['s3'] == 'Heute ist Mittwoch, der vierte November zweitausendsechsundzwanzig, und es ist vierzehn Uhr dreißig.', 's3: ' + spoken['s3'])
check(spoken['s5'] == 'Im Projekt liegen drei Dateien: index Punkt Ha Te Em El, style Punkt Ze Es Es und script Punkt Jot Es.', 's5: ' + spoken['s5'])
check('aus dem Jahr zweitausendvierundzwanzig,' in spoken['s7'], 's7: ' + spoken['s7'])
for sid in ('s1', 's4', 's6'):
    check(spoken[sid] == next(s['text'] for s in reg['sentences'] if s['id'] == sid), sid + ' stays as written')
for value, words in ((0, 'null'), (1, 'eins'), (16, 'sechzehn'), (17, 'siebzehn'), (21, 'einundzwanzig'), (30, 'dreißig'),
                     (100, 'hundert'), (101, 'hunderteins'), (1000, 'tausend'), (1001, 'tausendeins'), (21000, 'einundzwanzigtausend'),
                     (101000, 'hunderteintausend'), (1000000, 'eine Million'), (2500000, 'zwei Millionen fünfhunderttausend')):
    check(prep.cardinal(value) == words, f'{value} -> {prep.cardinal(value)}')
for value, te, ten in ((1, 'erste', 'ersten'), (3, 'dritte', 'dritten'), (7, 'siebte', 'siebten'), (8, 'achte', 'achten'),
                       (20, 'zwanzigste', 'zwanzigsten'), (31, 'einunddreißigste', 'einunddreißigsten')):
    check(prep.ordinal(value) == te and prep.ordinal(value, 'ten') == ten, f'ordinal {value}')
check(prep.year(1999) == 'neunzehnhundertneunundneunzig' and prep.year(1901) == 'neunzehnhunderteins' and prep.year(2000) == 'zweitausend', 'years')
for text, words in (
    ('Am 1. Mai 1999 um 7:05 war es 1 Uhr.', 'Am ersten Mai neunzehnhundertneunundneunzig um sieben Uhr fünf war es ein Uhr.'),
    ('Um 14:30 treffen wir uns.', 'Um vierzehn Uhr dreißig treffen wir uns.'),
    ('Vom 3. bis 5. Juni', 'Vom dritten bis fünften Juni'),
    ('Termin: 04.11.2026, seit dem 24. Dezember.', 'Termin: vierte November zweitausendsechsundzwanzig, seit dem vierundzwanzigsten Dezember.'),
    ('Das kostet 15 € und ist 15,2 % teurer, z. B. bei Nr. 5.',
     'Das kostet fünfzehn Euro und ist fünfzehn Komma zwei Prozent teurer, zum Beispiel bei Nummer fünf.'),
    ('data_v2.json, logo-neu.PNG, README.md, archiv.zip', 'data v2 Punkt Jason, logo neu Punkt Pe En Ge, README Punkt Em De, archiv Punkt Zipp'),
    ('007 Punkte, 3,14 und 1.234,5 & mehr', 'null null sieben Punkte, drei Komma eins vier und tausendzweihundertvierunddreißig Komma fünf und mehr'),
):
    check(prep.prepare(text) == words, f'{text!r} -> {prep.prepare(text)!r}')
check(not re.search(r'\d', ' '.join(spoken.values())), 'no digit reaches a voice in the test sentences')

# --- 0.6.5: de-2, after the first live read-aloud (dotted names, times, English words) ------------
check(prep.VERSION == 'de-2', 'text preparation de-2')
for text, words in (
    # E.M.B.E.R. broke the reading; dotted letters are a name or spelled.
    ('Ich bin die TCI von E.M.B.E.R. und helfe dir.', 'Ich bin die TCI von Ember und helfe dir.'),
    ('Das E.M.B.E.R.-Framework läuft auf E.M.B.E.R. Das ist neu.', 'Das Ember-Freimwörk läuft auf Ember. Das ist neu.'),
    ('Teil von e.m.b.e.r.', 'Teil von Ember.'),
    ('U.S.A. und J.R.R. Tolkien', 'U Es A und Jot Er Er Tolkien'),
    ('Das ist z.B. so, d.h. es geht, i.d.R. gut, etc. Die Sache.',
     'Das ist zum Beispiel so, das heißt es geht, in der Regel gut, et cetera. Die Sache.'),
    # 12:00-20:00 CET came out as gibberish.
    ('Geöffnet von 12:00-20:00 CET.', 'Geöffnet von zwölf Uhr bis zwanzig Uhr mitteleuropäischer Zeit.'),
    ('Am Wochenende 10:00 \u2013 14:30 Uhr, sonst 9-17 Uhr.', 'Am Wochenende zehn Uhr bis vierzehn Uhr dreißig, sonst neun bis siebzehn Uhr.'),
    ('Server-Zeit UTC+2, Sommer CEST, um 8.30 Uhr.', 'Server-Zeit U Te Ze plus zwei, Sommer mitteleuropäischer Sommerzeit, um acht Uhr dreißig.'),
    ('Start 18:45, Laufzeit 1:02:30, Pause 2:30 min.',
     'Start achtzehn Uhr fünfundvierzig, Laufzeit eine Stunde, zwei Minuten und dreißig Sekunden, Pause zwei Minuten und dreißig Sekunden.'),
    ('Am 2026-11-04 und am 4.11. geht es.', 'Am vierten November zweitausendsechsundzwanzig und am vierten November geht es.'),
    ('Format 16:9, Ergebnis 2:1, Seiten 3-5, 2024\u20132026.',
     'Format sechzehn zu neun, Ergebnis zwei zu eins, Seiten drei bis fünf, zweitausendvierundzwanzig bis zweitausendsechsundzwanzig.'),
    ('Version v0.6.3 und Python 3.11', 'Version null Punkt sechs Punkt drei und Paiten drei Punkt elf'),
    ('Es ist ~20 °C -> perfekt - wirklich.', 'Es ist circa zwanzig Grad Celsius, perfekt, wirklich.'),
    ('Offen 8:00\u201412:00, Pause \u2014 dann weiter.', 'Offen acht Uhr bis zwölf Uhr, Pause, dann weiter.'),
    ('Schreib an info@starlight-unit.de', 'Schreib an info ät starlait-junit Punkt De'),
    # English words were read the German way.
    ('Das Update für den Browser und die Cloud ist online, check mal das Interface und den Chat.',
     'Das Apdeit für den Brauser und die Klaud ist onlein, tscheck mal das Interfehs und den Tschätt.'),
    ('Updates, updaten, geupdatet, Chats, chatten, Teams, Caches, Interfaces, debuggen, Designerin, Updatefenster, Checkliste.',
     'Apdeits, apdeiten, geapdeitet, Tschätts, tschätten, Tiems, Käsches, Interfehses, dibaggen, Disainerin, Apdeit-fenster, Tscheck-liste.'),
    ('Mara Thorne von CoreAI: TCI Interface, OK, sorry, die E-Mail-Adresse.',
     'Mara Thorn von Kohr Ei Ei: TCI Interfehs, Okeh, sorri, die Imeil-Adresse.'),
    # German words that only look like English ones stay.
    ('Tablette, Mailand, Hostie, hier, Bugatti, Eventuell, Homeoffice.', 'Tablette, Mailand, Hostie, hier, Bugatti, Eventuell, Houm-office.'),
):
    check(prep.prepare(text) == words, f'de-2: {text!r} -> {prep.prepare(text)!r}')
for key, (spoken_word, kind) in prep.PRONUNCIATION.items():
    check(key == key.lower() and kind in ('n', 'w', 'x') and spoken_word.strip() == spoken_word and spoken_word, 'pronunciation entry ' + key)

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
check(len(fetches) == 9 and len(runs) == 26, 'nine groups fetched (clones wait for the token, voice files for the clones), thirteen measured')
check(dry.stdout.count('wartet auf die Stimmdatei') == 2, 'voice file groups wait for their clone')
check(dry.stdout.count('braucht die Klon-Gewichte von Kyutai') == 2 and 'tts-spike.sh --hf-token' in dry.stdout, 'how to unlock the clone')
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
check(len(kept) == 8 and not any('--group pocket-de ' in l for l in kept), 'registered weights are kept')
check(len([l for l in dry_run('--refetch').stdout.splitlines() if 'run.py fetch ' in l]) == 9, '--refetch loads them again')
# 0.5.98: the token, typed in hidden and stored for root only, reaches only the clone fetches, by name.
token = 'hf_' + 'Ab1' * 10
(sandbox / 'hf_token').write_text(token + '\n', encoding='utf-8')
unlocked = dry_run('--refetch')
clone_fetches = [l for l in unlocked.stdout.splitlines() if 'run.py fetch ' in l]
check(len(clone_fetches) == 11, 'with the token the clones are fetched too')
for clone_group in ('pocket-de-kerstin', 'pocket-de24-kerstin'):
    (sandbox / 'models' / f'lock-{clone_group}.json').write_text('{}', encoding='utf-8')
unlocked = dry_run('--refetch')
clone_fetches = [l for l in unlocked.stdout.splitlines() if 'run.py fetch ' in l]
check(len(clone_fetches) == 13, 'with the clones loaded, the voice file groups are fetched too')
for line in clone_fetches:
    check(('-e HF_TOKEN ' in line) == bool(re.search(r'--group pocket-de(24)?-kerstin ', line)), 'token only for the clone fetches: ' + line[-60:])
check(any('--group pocket-de-stimmdatei ' in l and '-e HF_TOKEN' not in l for l in clone_fetches), 'the voice file path never sees the token')
check(not any('HF_TOKEN' in l for l in unlocked.stdout.splitlines() if 'run.py run ' in l), 'never while measuring')
check(token not in unlocked.stdout + unlocked.stderr, 'the token itself is never printed')
(sandbox / 'hf_token').unlink()
stored = subprocess.run(['bash', str(script), '--dry-run', '--hf-token'], input=token + '\n', capture_output=True, text=True, timeout=30,
                        env=dict(os.environ, COREAI_TTS_SPIKE_BASE=str(sandbox)))
check(stored.returncode == 0 and 'nur fuer root lesbar' in stored.stdout and token not in stored.stdout + stored.stderr, 'token accepted')
for wrong in ('', 'abc', 'hf_short', 'hf_' + 'x' * 20 + ';rm'):
    refused = subprocess.run(['bash', str(script), '--dry-run', '--hf-token'], input=wrong + '\n', capture_output=True, text=True, timeout=30)
    check(refused.returncode != 0 and 'kein Hugging-Face-Token' in refused.stderr, 'bad token refused: ' + repr(wrong))
# 0.5.99: a token typed into the command is refused and never printed back.
for args in (['--hf-' + token], ['--hf-token', token], [token], ['--skip-build', '--hf_token=' + token]):
    leaked = subprocess.run(['bash', str(script), '--dry-run', *args], capture_output=True, text=True, timeout=30)
    check(leaked.returncode == 2 and 'Den Token nicht in den Befehl schreiben' in leaked.stderr, 'token on the command line refused: ' + args[0][:8])
    check(token not in leaked.stdout + leaked.stderr, 'the token is not printed back')
shell_text = script.read_text(encoding='utf-8')
check("IFS= read -rs token" in shell_text and 'chmod 0600 "$TOKEN_FILE.tmp"' in shell_text and 'chown root:root "$TOKEN_FILE.tmp"' in shell_text,
      'hidden input, root only')
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
        'format': 1, 'sample_cores': 4, 'textprep': 'de-2', 'sentences': reg['sentences'], 'references': reg['references'],
        'engines': {'probe': {'label': 'Testton', 'package': 'probe', 'code_license': 'MIT'}},
        'groups': {'probe-a': {'engine': 'probe', 'label': 'Testton A'}, 'probe-b': {'engine': 'probe', 'label': 'Testton B'},
                   'probe-klon': {'engine': 'probe', 'label': 'Testton Klon', 'clone': True, 'hf_home': 'hf-clone', 'reference': 'kerstin'},
                   'probe-datei': {'engine': 'probe', 'label': 'Testton Stimmdatei', 'voice_file_from': 'probe-klon'}},
        'voices': [
            {'id': 'probe-a-eins', 'group': 'probe-a', 'voice': 'eins', 'label': 'Eins', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-a-kaputt', 'group': 'probe-a', 'voice': 'broken', 'label': 'Kaputt', 'gender': 'weiblich', 'status': 'pruefen',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-b-zwei', 'group': 'probe-b', 'voice': 'zwei', 'label': 'Zwei', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC BY 4.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-klon', 'group': 'probe-klon', 'voice': 'reference', 'label': 'Klon', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
            {'id': 'probe-datei', 'group': 'probe-datei', 'voice': 'file', 'label': 'Stimmdatei', 'gender': 'weiblich', 'status': 'frei',
             'voice_license': 'CC0 1.0', 'weights_license': 'MIT', 'origin': 'Test'},
        ],
    }
    registry = tmp_path / 'voices.json'
    registry.write_text(json.dumps(test_reg, ensure_ascii=False), encoding='utf-8')
    models, out = tmp_path / 'models', tmp_path / 'runs' / '20261104-120000'
    r = call('--registry', str(registry), 'run', '--group', 'probe-a', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 1, 'no run without registered weights')
    check('lock-probe-a.json fehlt' in json.loads((out / 'result-probe-a-4c.json').read_text())['error'], 'missing weights reported')
    r = call('--registry', str(registry), 'run', '--group', 'probe-klon', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 1 and 'Klon-Gewichte von Kyutai nicht freigegeben' in json.loads((out / 'result-probe-klon-4c.json').read_text())['error'],
          'a clone without weights says how to unlock it')
    r = call('--registry', str(registry), 'run', '--group', 'probe-datei', '--models', str(models), '--out', str(out), '--cores', '4')
    check(r.returncode == 1 and 'Stimmdatei fehlt: zuerst den Klon probe-klon laden' in json.loads((out / 'result-probe-datei-4c.json').read_text())['error'],
          'a voice file group without its clone says what to do')
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
    check(result['textprep'] == 'de-2', 'text preparation recorded')
    s2 = next(x for x in eins['samples'] if x['sentence'] == 's2')
    check(abs(s2['audio_s'] - 0.03 * len(spoken['s2'])) < 0.01, 'the engine got the prepared text')
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
    check(report['textprep'] == 'de-2' and {x['id']: x['spoken'] for x in report['sentences']} == spoken, 'the report names the spoken text')
    voices = {v['id']: v for v in report['voices']}
    check(set(voices) == {'probe-a-eins', 'probe-a-kaputt', 'probe-b-zwei', 'probe-klon', 'probe-datei'}, 'every voice in the report')
    check(not voices['probe-klon']['ok'] and 'Klon-Gewichte von Kyutai nicht freigegeben' in voices['probe-klon']['error'], 'the locked clone shows why')
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
