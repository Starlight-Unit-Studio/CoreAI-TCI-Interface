#!/usr/bin/env python3
"""Offline pGen regressions: strict 9B lock, 4B/9B worker routing and isolation."""
from __future__ import annotations
import copy
import importlib.util
import json
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load(name: str, file: Path):
    spec = importlib.util.spec_from_file_location(name, file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


profiles = load('pgen_profiles_test', ROOT / 'tools' / 'image' / 'pgen_profiles.py')
worker = load('pgen_worker_test', ROOT / 'tools' / 'image' / 'image_service.py')
base = json.loads((ROOT / 'config' / 'image' / 'mara-image.json').read_text(encoding='utf-8'))
spike = json.loads((ROOT / 'tools' / 'image_spike' / 'models.json').read_text(encoding='utf-8'))
specs = spike['groups'][profiles.QUALITY]['files']
rows = [
    dict(role=f['role'], repo=f['repo'], path=f['path'], size=f['size'],
         license=f['license'], revision='a'*40, sha256='b'*64, hf_sha256='b'*64,
         file=f['repo'].replace('/', '__') + '/' + f['path'])
    for f in specs
]
lock = {'format': 1, 'group': profiles.QUALITY, 'files': rows}
reg9 = profiles.registry_from_lock(base, spike, lock)
assert reg9['model']['id'] == profiles.QUALITY
assert reg9['model']['license'] == 'FLUX Non-Commercial License v2.1'
assert reg9['measured']['large']['peak_rss_mb'] == 22605
assert reg9['files'][0]['revision'] == 'a'*40
assert base['model']['id'] == profiles.STANDARD, '4B registry must not be mutated'
assert len(reg9['files']) == 3
for field, value in [('sha256','0'), ('revision','bad'), ('repo','evil/model'),
                     ('path','../evil'), ('size',1), ('file','../etc/passwd')]:
    wrong = copy.deepcopy(lock)
    wrong['files'][0][field] = value
    try:
        profiles.registry_from_lock(base, spike, wrong)
    except ValueError:
        pass
    else:
        raise AssertionError('unsafe 9B lock was accepted: ' + field)

with tempfile.TemporaryDirectory(prefix='pgen-selftest-') as name:
    tmp = Path(name)
    file = tmp / 'pgen-9b.json'
    assert profiles.load_registry(file, base, spike) is None
    profiles.save_lock(file, lock)
    assert profiles.load_registry(file, base, spike)['model']['id'] == profiles.QUALITY
    # Both gated Ponpoke encoder sources are immutable and separately pinned.
    import hashlib
    ponpoke = worker.PONPOKE
    assert set(ponpoke.SOURCES) == {profiles.STANDARD, profiles.QUALITY}
    assert not ponpoke._trusted_url('http://huggingface.co/unsafe')
    assert not ponpoke._trusted_url('https://evil.example/redirect')
    assert ponpoke._trusted_url('https://cas-bridge.xethub.hf.co/file')
    for profile in (profiles.STANDARD, profiles.QUALITY):
        source, filename = ponpoke.SOURCES[profile]
        assert source.startswith('ponpoke/flux2-klein-')
        assert filename.endswith('-q8_0.gguf')
        target, record_path = ponpoke._paths(tmp / 'models', profile)
        target.parent.mkdir(parents=True, exist_ok=True)
        raw = ('test-encoder-' + profile).encode('ascii')
        target.write_bytes(raw)
        checksum = hashlib.sha256(raw).hexdigest()
        record = {'repo': source, 'filename': filename, 'license': ponpoke.LICENSE,
                  'sha256': checksum, 'revision': 'a' * 40, 'size': len(raw)}
        record_path.write_text(json.dumps(record), encoding='utf-8')
        assert ponpoke.verified(tmp / 'models', profile) == target
        assert ponpoke.install(tmp / 'models', profile, token='') == target, (
            'verified local encoder is reused without a new HF download')
        record_path.write_text(json.dumps({**record, 'sha256': '0' * 64}), encoding='utf-8')
        assert ponpoke.verified(tmp / 'models', profile) is None
        record_path.write_text(json.dumps(record), encoding='utf-8')
        target.unlink()
        assert ponpoke.verified(tmp / 'models', profile) is None
    light_base = copy.deepcopy(base)
    light_base['measured']['normal']['peak_rss_mb'] = 0  # CI has much less RAM than the production server
    svc = worker.Service(light_base, tmp / 'models', tmp / 'work', threads=3,
                         sd_cli='/bin/false')
    svc.ready = True
    svc.work.mkdir()
    svc.profiles[profiles.QUALITY] = reg9
    svc.profile_paths[profiles.STANDARD] = {'diffusion_model':'4b.gguf','llm':'qwen4.gguf','vae':'vae.safetensors'}
    svc.profile_paths[profiles.QUALITY] = {'diffusion_model':'9b.gguf','llm':'qwen8.gguf','vae':'vae.safetensors'}
    svc.ponpoke_paths[profiles.STANDARD] = 'ponpoke-4b-q8_0.gguf'
    svc.ponpoke_paths[profiles.QUALITY] = 'ponpoke-9b-q8_0.gguf'
    reg9['measured']['normal']['peak_rss_mb'] = 0
    # Do not launch sd-cli: inspect the exact job the running image service would execute.
    svc._run = lambda job, prompt: None
    job9 = svc.create('a photograph', 'normal', 42, model_id=profiles.QUALITY,
                      user_license_accepted=True)
    job9['state'] = 'done'
    job4 = svc.create('a drawing', 'normal', 42, model_id=profiles.STANDARD,
                      user_license_accepted=True)
    nine_args = svc.command(job9, 'a photograph')
    four_args = svc.command(job4, 'a drawing')
    assert nine_args[nine_args.index('--diffusion-model')+1] == '9b.gguf'
    assert nine_args[nine_args.index('--llm')+1] == 'ponpoke-9b-q8_0.gguf'
    assert four_args[four_args.index('--diffusion-model')+1] == '4b.gguf'
    assert four_args[four_args.index('--llm')+1] == 'ponpoke-4b-q8_0.gguf'
    assert job4['text_encoder'] == 'ponpoke-uncensored-q8_0'
    assert job9['text_encoder'] == 'ponpoke-uncensored-q8_0'
    job4['state'] = 'done'
    unlicensed = svc.create('baseline', 'normal', 41, model_id=profiles.STANDARD,
                           user_license_accepted=False)
    assert svc.command(unlicensed, 'baseline')[svc.command(unlicensed, 'baseline').index('--llm') + 1] == 'qwen4.gguf', (
        'other user must never inherit non-commercial Ponpoke encoder approval')
    unlicensed['state'] = 'done'
    assert svc.health()['ponpoke'][profiles.STANDARD]['ready']
    assert svc.health()['ponpoke'][profiles.QUALITY]['ready']
    assert job9['model_label'] == 'FLUX.2-klein 9B (Q8_0)'
    assert svc.public(job9)['model'] == profiles.QUALITY
    assert svc.public(job4)['model'] == profiles.STANDARD
    assert svc.health()['pgen'][profiles.QUALITY]['ready']
    # Regression: installing weights requires suitable hardware but does not
    # require an explicit CoreAI license-checkbox receipt. 9B inference does.
    assert not svc.quality_license_accepted()
    from unittest.mock import patch
    existing_paths = svc.profile_paths.pop(profiles.QUALITY)
    svc.quality_hardware_ready = lambda: True
    with patch.object(worker.threading, 'Thread') as thread:
        assert svc.install_quality(), '9B installation must start before UI consent'
        thread.assert_called_once()
        thread.return_value.start.assert_called_once()
    assert not svc.quality_license_accepted(), 'download must not silently record consent'
    svc.pgen_installing = False
    svc.profile_paths[profiles.QUALITY] = existing_paths
    import inspect
    handler_source = inspect.getsource(worker.make_handler)
    assert "model_id == PGEN.QUALITY and data.get('user_license_accepted') is not True" in handler_source, (
        '9B jobs must reject requests lacking the requesting user account consent flag')
    try:
        svc.create('bad model', 'normal', 1, model_id='arbitrary')
    except ValueError as error:
        assert str(error) == 'pgen_unavailable'
    else:
        raise AssertionError('arbitrary model accepted')

print('CoreAI pGen: two profiles, immutable 4B, strict 9B pins, real worker path and metadata OK')
