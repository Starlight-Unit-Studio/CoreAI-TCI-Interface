#!/usr/bin/env python3
"""Use content-based, numeric-only browser cache keys for static CoreUI assets.

The numeric product version in VERSION does not double as an asset cache key.
Run once during an install/update and use --verify in preflight checks.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import re
import stat
import tempfile
from pathlib import Path

PAGES = (
    'index.html', 'login.html', 'app.html', 'settings.html',
    'protocols.html', 'admin/index.html',
)
ASSET = re.compile(
    r'(?P<start>\b(?:href|src)\s*=\s*[\"\'])'
    r'(?P<path>(?:\./|\.\./)(?:js|css)/[a-zA-Z0-9_./-]+\.(?:js|css))'
    r'(?:\?v=[^\"\']*)?'
    r'(?P<end>[\"\'])'
)
NUMERIC_VERSION = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+\Z')


def _fingerprint(file: Path) -> str:
    # SHA-256-derived 80-bit decimal cache key. It changes with asset bytes,
    # never appends words to the canonical product version.
    return str(int.from_bytes(hashlib.sha256(file.read_bytes()).digest()[:10], 'big')).zfill(25)


def _atomic_write(path: Path, contents: str) -> None:
    info = path.stat()
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '.', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='') as stream:
            stream.write(contents)
        os.chmod(name, stat.S_IMODE(info.st_mode))
        if hasattr(os, 'chown'):
            os.chown(name, info.st_uid, info.st_gid)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def run(root: Path, *, verify: bool) -> tuple[int, int]:
    root = root.resolve()
    version = (root / 'VERSION').read_text(encoding='utf-8').strip()
    if not NUMERIC_VERSION.fullmatch(version):
        raise ValueError('VERSION must be numeric-only (for example 0.5.5)')
    result = []
    for rel in PAGES:
        page = root / rel
        if page.is_symlink() or not page.is_file():
            raise ValueError('missing or unsafe HTML page: ' + rel)
        old = page.read_text(encoding='utf-8')
        replacements = [0]

        def replace(match: re.Match[str]) -> str:
            asset = (page.parent / match.group('path')).resolve()
            if not asset.is_relative_to(root) or asset.is_symlink() or not asset.is_file():
                raise ValueError('missing or unsafe asset: ' + match.group('path'))
            replacements[0] += 1
            return f"{match.group('start')}{match.group('path')}?v={_fingerprint(asset)}{match.group('end')}"

        new = ASSET.sub(replace, old)
        if not replacements[0]:
            raise ValueError('no static assets found in ' + rel)
        if verify and new != old:
            raise ValueError('stale cache keys in ' + rel)
        result.append((page, old, new, replacements[0]))
    if not verify:
        for page, old, new, _ in result:
            if new != old:
                _atomic_write(page, new)
    return (sum(old != new for _, old, new, _ in result),
            sum(n for _, _, _, n in result))


def main() -> int:
    parser = argparse.ArgumentParser(description='Ember CoreUI static asset cache keys')
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--verify', action='store_true')
    args = parser.parse_args()
    try:
        changed, assets = run(args.root, verify=args.verify)
    except (OSError, ValueError) as error:
        parser.exit(1, f'CoreUI cache-buster error: {error}\n')
    print(f'CoreUI cache-buster OK: {assets} asset references; {changed} HTML files ' +
          ('verified' if args.verify else 'updated'))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
