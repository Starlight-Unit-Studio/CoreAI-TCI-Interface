#!/usr/bin/env python3
"""Adopt the operator's completed 9B image benchmark without duplicating 19 GB.

Safe to rerun on install and update. A missing or unverified benchmark is left
untouched; the in-app pGen installer may fetch the 9B model separately.
"""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import sys


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run(root: Path) -> int:
    source = root / 'var' / 'image-spike' / 'models'
    target = root / 'var' / 'image-models'
    if not source.is_dir() or not target.is_dir():
        return 0
    image = root / 'tools' / 'image'
    spike = load('pgen_spike', root / 'tools' / 'image_spike' / 'run.py')
    profiles = load('pgen_profiles_adopt', image / 'pgen_profiles.py')
    adoption = load('pgen_adopt', image / 'adopt_spike.py')
    base = json.loads((root / 'config' / 'image' / 'mara-image.json').read_text(encoding='utf-8'))
    groups = json.loads((root / 'tools' / 'image_spike' / 'models.json').read_text(encoding='utf-8'))
    lock_file = source / 'lock-flux2-klein-9b.json'
    if not lock_file.is_file() or lock_file.is_symlink():
        print('[pGen] 9B-Bildtest noch nicht vorhanden; bleibt optional.')
        return 0
    receipt = root / 'var' / 'cache' / 'generator-license.json'
    license_file = root / 'licenses' / 'FLUX-NON-COMMERCIAL-v2.1.txt'
    if not receipt.is_file() or receipt.is_symlink():
        print('[pGen] 9B: Lizenz nicht akzeptiert, keine automatische Uebernahme.')
        return 0
    import hashlib
    license_hash = hashlib.sha256(license_file.read_bytes()).hexdigest()
    try:
        accepted = json.loads(receipt.read_text(encoding='utf-8'))
    except (ValueError, OSError):
        accepted = {}
    if accepted.get('accepted') is not True or accepted.get('license_sha256') != license_hash:
        print('[pGen] 9B: Lizenzfreigabe fehlt oder ist veraltet.')
        return 0

    lock = json.loads(lock_file.read_text(encoding='utf-8'))
    registry = profiles.registry_from_lock(base, groups, lock)
    paths, _, error = spike.verify(source, profiles.QUALITY)
    if error or len(paths) != 3:
        print('[pGen] 9B-Bildtest nicht verifiziert: ' + (error or 'Gewichte fehlen'))
        return 1
    for message in adoption.adopt(registry, source, target):
        print('[pGen] ' + message)
    # Confirm every destination is readable with the exact tested SHA-256.
    for spec in registry['files']:
        location = adoption.target_of(target, spec)
        if not location.is_file() or spike.sha256_file(location) != spec['sha256']:
            print('[pGen] 9B-Zielgewichte konnten nicht verifiziert werden.', file=sys.stderr)
            return 1
    profiles.save_lock(target / 'pgen-9b.json', lock)
    print('[pGen] FLUX.2-klein 9B aus dem Bildtest ohne doppelte Gewichte uebernommen.')
    return 0


if __name__ == '__main__':
    sys.exit(run(Path(__file__).resolve().parents[2]))
