#!/usr/bin/env python3
"""Read-only canonical Mara reference selection for the optional FLUX image service.

The operator provides seven original images in images/mara_references/. We use
the frontal portrait first and at most two complementary identity images.
The two blue hologram samples are *style-only* and are never used unless the
user explicitly requests a hologram/wireframe appearance.

No images are fetched at runtime, no user-defined filesystem paths are
accepted, and the set is NEVER appended to a custom account avatar.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

CONFIG_REL = Path('config/image/mara-references.json')
DEFAULT_REL = Path('images/mara_references')
MAX_REFERENCE_FILE_BYTES = 8 * 1024 * 1024
SAFE_FILENAME = re.compile(r'^mara_(?:identity|style)_[a-z0-9_]+\.(?:png|jpg)$')

# Match only the specific visual intent from the original user scene, never
# the identity policy text (which itself mentions faces and portraits).
HOLOGRAM_WORDS = ('hologram', 'holograf', 'holograph', 'wireframe',
                  'drahtgitter', 'lichtprojektion', 'light projection')
WALK_WORDS = ('gehen', 'gehende', 'gehend', 'lauf', 'spazier', 'walk',
              'running', 'runway', 'walking', 'street', 'running', 'steht')
FULLBODY_WORDS = ('ganzkörper', 'ganzkoerper', 'ganzer körper', 'full body',
                  'full-body', 'whole body', 'kopf bis fuß', 'head to toe', 'standing')
PROFILE_WORDS = ('seitenprofil', 'profilansicht', 'side profile', 'profil', 'profile', 'seitlich')
PORTRAIT_WORDS = ('nahaufnahme', 'portrait', 'porträt', 'portraet',
                  'closeup', 'close-up', 'headshot', 'gesicht', 'face')
SITTING_WORDS = ('sitz', 'sitting', 'seated', 'seat', 'sessel')


def _asset(root: Path, directory: Path, filename: object) -> Path | None:
    """Resolve only a known static file, without traversal/symlinks or huge uploads."""
    if not isinstance(filename, str) or not SAFE_FILENAME.fullmatch(filename):
        return None
    base = root / directory
    path = base / filename
    try:
        if path.is_symlink() or not path.is_file():
            return None
        if path.resolve().parent != base.resolve():
            return None
        size = path.stat().st_size
        if not 32 <= size <= MAX_REFERENCE_FILE_BYTES:
            return None
        with path.open('rb') as handle:
            start = handle.read(12)
        if filename.endswith('.png'):
            valid = start.startswith(b'\x89PNG\r\n\x1a\n')
        else:
            valid = start.startswith(b'\xff\xd8\xff')
        return path if valid else None
    except OSError:
        return None


def load_manifest(root: Path) -> dict | None:
    try:
        config = json.loads((root / CONFIG_REL).read_text(encoding='utf-8'))
    except (OSError, UnicodeError, ValueError):
        return None
    if not isinstance(config, dict) or config.get('format') != 1:
        return None
    if config.get('directory') != DEFAULT_REL.as_posix():
        return None
    if not isinstance(config.get('identity'), dict) or not isinstance(config.get('style_only'), dict):
        return None
    return config


def select(root: Path, scene: str, *, has_user_uploads: bool = False) -> list[dict]:
    """Return validated {path,name,role} records, with the primary first.

    All returned identity images depict exactly TCI Mara Thorne. User-selected
    account photos do NOT call this function (the service enforces that).
    """
    config = load_manifest(root)
    if config is None:
        return []
    primary = _asset(root, DEFAULT_REL, config.get('primary'))
    if primary is None:
        return []
    result = [{'path': str(primary), 'name': primary.name, 'role': 'identity_primary'}]
    if has_user_uploads:
        return result  # preserve every explicitly uploaded person's own identity

    selection = config.get('selection') or {}
    max_identity = min(2, max(0, int(selection.get('max_extra_identity', 2))))
    max_style = min(1, max(0, int(selection.get('max_extra_style', 1))))
    scene = (scene or '').casefold()
    walking = any(word in scene for word in WALK_WORDS)
    hologram = any(word in scene for word in HOLOGRAM_WORDS)
    if any(word in scene for word in PROFILE_WORDS):
        priority = ('profile', 'cockpit', 'fullbody', 'city')
    elif walking or any(word in scene for word in FULLBODY_WORDS):
        priority = ('fullbody', 'city', 'cockpit', 'profile')
    elif any(word in scene for word in PORTRAIT_WORDS):
        priority = ('cockpit', 'profile', 'fullbody', 'city')
    else:
        priority = ('cockpit', 'fullbody', 'profile', 'city')
    for role in priority:
        if len(result) - 1 >= max_identity:
            break
        file = _asset(root, DEFAULT_REL, config['identity'].get(role))
        if file is not None and all(item['name'] != file.name for item in result):
            result.append({'path': str(file), 'name': file.name, 'role': 'identity'})
    if hologram and max_style:
        style_role = 'hologram_walking' if walking else 'hologram_seated'
        file = _asset(root, DEFAULT_REL, config['style_only'].get(style_role))
        if file is not None and all(item['name'] != file.name for item in result):
            result.append({'path': str(file), 'name': file.name, 'role': 'style_only'})
    return result
