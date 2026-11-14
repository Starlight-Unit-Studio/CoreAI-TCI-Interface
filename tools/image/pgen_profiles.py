#!/usr/bin/env python3
"""Two deliberately fixed pGen profiles for the Quantum Gen Engine.

4B stays in the canonical pinned registry. The 9B profile can only be
created from a completed image-spike lock with identical files, revisions and
SHA-256. No caller-provided model IDs or arbitrary download paths are allowed.
"""
from __future__ import annotations

from copy import deepcopy
import json
import os
from pathlib import Path
import re
import tempfile

STANDARD = 'flux2-klein-4b'
QUALITY = 'flux2-klein-9b'
ROLES = ('diffusion_model', 'llm', 'vae')
SHA40 = re.compile(r'^[0-9a-f]{40}$')
SHA64 = re.compile(r'^[0-9a-f]{64}$')


def registry_from_lock(base: dict, spike: dict, lock: dict) -> dict:
    group = spike['groups'][QUALITY]
    if lock.get('format') != 1 or lock.get('group') != QUALITY:
        raise ValueError('pgen_lock_invalid')
    rows = lock.get('files')
    if not isinstance(rows, list) or len(rows) != 3:
        raise ValueError('pgen_lock_incomplete')
    expected = {f['role']: f for f in group['files']}
    if set(expected) != set(ROLES) or set(r.get('role') for r in rows if isinstance(r, dict)) != set(ROLES):
        raise ValueError('pgen_lock_roles')
    files = []
    for entry in rows:
        if not isinstance(entry, dict):
            raise ValueError('pgen_lock_invalid')
        spec = expected[entry['role']]
        for key in ('role', 'repo', 'path', 'size', 'license'):
            if entry.get(key) != spec[key]:
                raise ValueError('pgen_lock_mismatch')
        if entry.get('file') != spec['repo'].replace('/', '__') + '/' + spec['path']:
            raise ValueError('pgen_lock_path')
        sha, revision = str(entry.get('sha256', '')), str(entry.get('revision', ''))
        if not SHA64.fullmatch(sha) or not SHA40.fullmatch(revision):
            raise ValueError('pgen_lock_missing_hash')
        hf = str(entry.get('hf_sha256') or '')
        if hf and hf != sha:
            raise ValueError('pgen_lock_hash_mismatch')
        files.append({key: entry[key] for key in ('role','repo','path','revision','sha256','size','license')})
    result = deepcopy(base)
    result['model'] = {'id': QUALITY, 'label': group['label'],
                       'license': group['license'], 'steps': group['steps'],
                       'cfg_scale': group['cfg_scale']}
    result['files'] = files
    # Actual operator measurements, 4 steps, 20 CPU threads, 14 November 2026.
    result['measured'] = {'threads': 20,
        'normal': {'peak_rss_mb': 17782, 'wall_s': 137},
        'large': {'peak_rss_mb': 22605, 'wall_s': 426}}
    result['limits']['memory_note'] = '9B: ca. 22.6 GB Peak RAM bei 1024 x 1024 (CPU).'
    return result


def load_registry(path: Path, base: dict, spike: dict) -> dict | None:
    if path.is_symlink() or not path.is_file():
        return None
    lock = json.loads(path.read_text(encoding='utf-8'))
    return registry_from_lock(base, spike, lock)


def save_lock(path: Path, lock: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.pgen-lock-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as output:
            json.dump(lock, output, ensure_ascii=False, indent=2)
            output.write('\n')
        os.chmod(tmp, 0o640)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
