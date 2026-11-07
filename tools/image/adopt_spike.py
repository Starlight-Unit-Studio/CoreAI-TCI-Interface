#!/usr/bin/env python3
"""CoreAI 0.6.39 (P20.5 B): adopt the weights the image test already loaded.

Run by the installer as root on the host. For every file of config/image/mara-image.json
that is missing in var/image-models, a file of the same repository, path and registered
size in var/image-spike/models (the image test, scripts/image-spike.sh) is hard-linked
there (copied when linking is not possible) and handed to the image service (uid 33).
Nothing is downloaded and nothing is deleted. The image service checks every file against
its registered SHA-256 before the first image anyway; a file that does not match is
deleted there and loaded again.

  python3 -I tools/image/adopt_spike.py REGISTRY SPIKE_MODELS IMAGE_MODELS
"""
from __future__ import annotations

import json
import os
import shutil
import sys
from pathlib import Path


def target_of(models: Path, spec: dict) -> Path:
    return models / spec['repo'].replace('/', '__') / spec['path']


def safe_spec(spec: dict) -> bool:
    repo, path = spec.get('repo'), spec.get('path')
    return (isinstance(repo, str) and repo.count('/') == 1 and '..' not in repo
            and isinstance(path, str) and not path.startswith('/') and '..' not in path.split('/')
            and isinstance(spec.get('size'), int) and spec['size'] > 0)


def adopt(registry: dict, spike: Path, models: Path, uid: int = 33, gid: int = 33) -> list[str]:
    """What was adopted, as readable lines."""
    done = []
    for spec in registry.get('files') or []:
        if not safe_spec(spec):
            continue
        target = target_of(models, spec)
        source = target_of(spike, spec)
        if target.exists() or not source.is_file() or source.is_symlink() or source.stat().st_size != spec['size']:
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        part = target.with_name(target.name + '.adopt')
        part.unlink(missing_ok=True)
        try:
            os.link(source, part)
            how = 'verlinkt'
        except OSError:
            shutil.copyfile(source, part)
            how = 'kopiert'
        if os.geteuid() == 0:
            os.chown(part, uid, gid)
            folder = models
            for name in target.parent.relative_to(models).parts:
                folder = folder / name
                os.chown(folder, uid, gid)
        os.chmod(part, 0o640)
        os.replace(part, target)
        done.append(f'{spec["repo"]}/{spec["path"]} aus dem Bildtest {how}')
    return done


def main(argv: list[str]) -> int:
    if len(argv) != 4:
        print(__doc__.strip().splitlines()[-1].strip(), file=sys.stderr)
        return 2
    registry = json.loads(Path(argv[1]).read_text(encoding='utf-8'))
    spike, models = Path(argv[2]), Path(argv[3])
    if not spike.is_dir() or not models.is_dir():
        return 0
    for line in adopt(registry, spike, models):
        print('[CoreAI Bilder] ' + line)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
