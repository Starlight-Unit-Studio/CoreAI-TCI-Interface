#!/usr/bin/env python3
"""Verified, locally pinned Hugging Face replacements for pGen text encoders.

The upstream ponpoke repositories are gated. Obtain a Hugging Face token only
after accepting the publisher's access terms. No token is persisted here, and
no unverifiable weight can become active. Existing canonical encoder weights
are kept until replacements are verified, so installations stay recoverable.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import urllib.error
import urllib.parse
import urllib.request

SOURCES = {
    'flux2-klein-4b': (
        'ponpoke/flux2-klein-4b-uncensored-text-encoder',
        'flux2-klein-4b-uncensored-q8_0.gguf',
    ),
    'flux2-klein-9b': (
        'ponpoke/flux2-klein-9b-uncensored-text-encoder',
        'flux2-klein-9b-uncensored-q8_0.gguf',
    ),
}
LICENSE = 'FLUX Non-Commercial License v2.1'
SHA = re.compile(r'^[0-9a-f]{64}$')
COMMIT = re.compile(r'^[0-9a-f]{40}$')
CHUNK = 2 * 1024 * 1024


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def _token() -> str:
    value = os.environ.get('HF_TOKEN', '').strip()
    return value if re.fullmatch(r'[A-Za-z0-9_-]{20,256}', value) else ''


def _headers(token: str) -> dict[str, str]:
    return {'User-Agent': 'CoreAI-pGen-encoder/1',
            **({'Authorization': 'Bearer ' + token} if token else {})}


def _sha_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(CHUNK), b''):
            digest.update(block)
    return digest.hexdigest()


def _trusted_url(value: str) -> bool:
    url = urllib.parse.urlsplit(value)
    host = (url.hostname or '').lower()
    return url.scheme == 'https' and (host == 'huggingface.co'
        or host.endswith('.huggingface.co') or host.endswith('.hf.co'))


def _head(repo: str, filename: str, token: str, revision: str = 'main') -> dict:
    url = ('https://huggingface.co/' + repo + '/resolve/' + revision + '/'
           + urllib.parse.quote(filename))
    opener = urllib.request.build_opener(_NoRedirect)
    for _ in range(6):
        request = urllib.request.Request(url, headers=_headers(token), method='HEAD')
        try:
            response = opener.open(request, timeout=40)
            hdr, location, code = response.headers, url, response.status
            response.close()
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise RuntimeError('Hugging Face: Zugriff nicht freigegeben (HF_TOKEN/Modellbedingungen)') from None
            if error.code not in (301, 302, 303, 307, 308):
                raise RuntimeError('Hugging Face HTTP ' + str(error.code)) from None
            hdr, location, code = error.headers, urllib.parse.urljoin(url, error.headers.get('Location', '')), error.code
        digest = str(hdr.get('X-Linked-Etag') or (hdr.get('ETag') if code == 200 else '') or '').strip('"').lower()
        size = hdr.get('X-Linked-Size') or (hdr.get('Content-Length') if code == 200 else '')
        commit = str(hdr.get('X-Repo-Commit') or '').lower()
        if COMMIT.fullmatch(commit) and SHA.fullmatch(digest) and str(size).isdigit():
            if not _trusted_url(location):
                raise RuntimeError('HF-Downloadziel nicht freigegeben')
            return {'sha256': digest, 'size': int(size), 'revision': commit,
                    'url': location, 'requires_token': code == 200}
        if code in (301, 302, 303, 307, 308) and _trusted_url(location) and urllib.parse.urlsplit(location).hostname == 'huggingface.co':
            url = location
            continue
        raise RuntimeError('Hugging Face hat keinen verifizierbaren SHA-256/Commit geliefert')
    raise RuntimeError('Zu viele HF-Weiterleitungen')


def _paths(models: Path, profile: str) -> tuple[Path, Path]:
    repo, name = SOURCES[profile]
    target = models / repo.replace('/', '__') / name
    lock = models / ('ponpoke-' + profile + '.json')
    return target, lock


def verified(models: Path, profile: str) -> Path | None:
    if profile not in SOURCES:
        raise ValueError('invalid_pgen_profile')
    target, lock = _paths(models, profile)
    if target.is_symlink() or lock.is_symlink():
        return None
    try:
        record = json.loads(lock.read_text(encoding='utf-8'))
        repo, name = SOURCES[profile]
        if (record.get('repo'), record.get('filename')) != (repo, name):
            return None
        if record.get('license') != LICENSE or not COMMIT.fullmatch(record.get('revision', '')):
            return None
        if not SHA.fullmatch(record.get('sha256', '')) or not isinstance(record.get('size'), int):
            return None
        if target.stat().st_size != record['size']:
            return None
        return target if _sha_file(target) == record['sha256'] else None
    except (OSError, ValueError, TypeError, KeyError):
        return None


def install(models: Path, profile: str, token: str | None = None) -> Path:
    if profile not in SOURCES:
        raise ValueError('invalid_pgen_profile')
    existing = verified(models, profile)
    if existing is not None:
        return existing
    repo, filename = SOURCES[profile]
    token = _token() if token is None else token
    if not token:
        raise RuntimeError('Hugging Face: HF_TOKEN fehlt oder Modellzugriff nicht freigeschaltet')
    info = _head(repo, filename, token)
    if info['size'] < 1024 * 1024 or info['size'] > 20 * 1024 ** 3:
        raise RuntimeError('Unplausible Groesse des Text-Encoders')
    target, lock = _paths(models, profile)
    if target.is_symlink() or lock.is_symlink():
        raise RuntimeError('Symlink statt Encoder-Datei oder Lock')
    target.parent.mkdir(parents=True, exist_ok=True)
    if shutil.disk_usage(models).free < info['size'] + 1024 ** 3 and not target.is_file():
        raise RuntimeError('Zu wenig freier Speicher fuer Ponpoke-Encoder')
    part = target.with_name(target.name + '.part')
    if part.is_symlink():
        raise RuntimeError('Unzulassiger Encoder-Downloadpfad')
    have = part.stat().st_size if part.is_file() else 0
    if have > info['size']:
        part.unlink()
        have = 0
    headers = _headers(token) if info['requires_token'] else _headers('')
    if 0 < have < info['size']:
        headers['Range'] = 'bytes=' + str(have) + '-'
    request = urllib.request.Request(info['url'], headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            mode = 'ab' if have and response.status == 206 else 'wb'
            with part.open(mode) as stream:
                for block in iter(lambda: response.read(CHUNK), b''):
                    stream.write(block)
    except urllib.error.HTTPError as error:
        if error.code in (401, 403):
            raise RuntimeError('Hugging Face: Zugriff nicht freigegeben') from None
        raise
    if part.stat().st_size != info['size'] or _sha_file(part) != info['sha256']:
        raise RuntimeError('Ponpoke-Datei unvollstaendig oder SHA-256 ungueltig')
    os.replace(part, target)
    record = {'repo': repo, 'filename': filename, 'license': LICENSE,
              'revision': info['revision'], 'sha256': info['sha256'],
              'size': info['size']}
    draft = lock.with_name(lock.name + '.part')
    if draft.is_symlink():
        raise RuntimeError('Unzulaessiger Encoder-Lock')
    draft.write_text(json.dumps(record, sort_keys=True) + '\n', encoding='utf-8')
    os.replace(draft, lock)
    return target
