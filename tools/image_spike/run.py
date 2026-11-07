#!/usr/bin/env python3
"""CoreAI 0.6.37 (P20.5 A): the image test on the real server, standard library only.

scripts/image-spike.sh runs this inside its container (stable-diffusion.cpp built for the
server's CPU at the commit in models.json):

  fetch  --group G --models /models    loads the weights of one group (with network) and
                                       registers every file with repository, commit and SHA-256
                                       in lock-G.json; Hugging Face's own SHA-256 is compared.
  run    --group G --models /models --out /out --threads N [--full]
                                       checks every file against lock-G.json, then makes the test
                                       images with fixed prompts and seeds (without network) and
                                       measures loading, text encoding, sampling, decoding, the
                                       whole call and the peak RAM of each image.
  report --run-dir DIR                 report.json (Admin Core > Betrieb > Bildproben) and report.md.
  validate                             checks models.json.

Nothing here touches CoreAI's Cores, Modelfile or database.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import signal
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
REGISTRY = HERE / 'models.json'
HF = os.environ.get('COREAI_IMAGE_SPIKE_HF', 'https://huggingface.co').rstrip('/')
SD_CLI = os.environ.get('COREAI_SD_CLI', '/usr/local/bin/sd-cli')
GROUP_RE = re.compile(r'^[a-z0-9][a-z0-9_-]{0,60}$')
REPO_RE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]{0,95}/[A-Za-z0-9][A-Za-z0-9._-]{0,95}$')
SIZE_RE = re.compile(r'^([0-9]{3,4})x([0-9]{3,4})$')
PROMPT_RE = re.compile(r'^p[0-9]{1,2}$')
ROLES = ('diffusion_model', 'llm', 'vae')
FILE_RE = re.compile(r'^[a-z0-9][a-z0-9_-]{0,60}-[0-9]{3,4}x[0-9]{3,4}-p[0-9]{1,2}\.png$')
CHUNK = 8 * 1024 * 1024
IMAGE_TIMEOUT = int(os.environ.get('COREAI_IMAGE_SPIKE_TIMEOUT', '1800'))
TIMINGS = {
    'load_s': re.compile(r'loading tensors completed, taking ([0-9.]+)s'),
    'text_s': re.compile(r'get_learned_condition completed, taking ([0-9.]+)s'),
    # Not "sampling(high noise) completed" (video) or "hires sampling 1/1 completed".
    'sampling_s': re.compile(r'(?:^|[\s-])sampling completed, taking ([0-9.]+)s', re.M),
    'decode_s': re.compile(r'decode_first_stage completed, taking ([0-9.]+)s'),
}


def log(text: str) -> None:
    print('[CoreAI Bildtest] ' + text, flush=True)


# --- Registry -------------------------------------------------------------------------

def registry_errors(reg: Any) -> list[str]:
    """What is wrong with models.json (empty when it is fine)."""
    errors: list[str] = []
    if not isinstance(reg, dict) or reg.get('format') != 1:
        return ['format muss 1 sein']
    runtime = reg.get('runtime') or {}
    if not re.fullmatch(r'[0-9a-f]{40}', str(runtime.get('commit', ''))):
        errors.append('runtime.commit ist kein voller Git-Commit')
    if not str(runtime.get('license', '')).strip():
        errors.append('runtime.license fehlt')
    prompts = reg.get('prompts') or []
    ids = set()
    for prompt in prompts:
        if not isinstance(prompt, dict) or not PROMPT_RE.match(str(prompt.get('id', ''))):
            errors.append('Prompt ohne gueltige id')
            continue
        if not isinstance(prompt.get('seed'), int) or not str(prompt.get('text', '')).strip():
            errors.append('Prompt ' + prompt['id'] + ' ohne Seed oder Text')
        ids.add(prompt['id'])
    if not ids:
        errors.append('keine Prompts')
    for step in reg.get('plan') or []:
        if not SIZE_RE.match(str(step.get('size', ''))):
            errors.append('Plan mit ungueltiger Groesse ' + str(step.get('size')))
            continue
        width, height = (int(v) for v in SIZE_RE.match(step['size']).groups())
        if not (256 <= width <= 2048 and 256 <= height <= 2048 and width % 64 == 0 and height % 64 == 0):
            errors.append('Groesse ' + step['size'] + ' ausserhalb 256 bis 2048 oder nicht durch 64 teilbar')
        unknown = [p for p in step.get('prompts') or [] if p not in ids]
        if unknown or not step.get('prompts'):
            errors.append('Plan ' + step['size'] + ' nennt unbekannte oder keine Prompts')
    if not reg.get('plan'):
        errors.append('kein Plan')
    groups = reg.get('groups') or {}
    if not groups:
        errors.append('keine Gruppen')
    for gid, group in groups.items():
        if not GROUP_RE.match(gid):
            errors.append('Gruppen-id ' + gid + ' ungueltig')
        if not isinstance(group.get('steps'), int) or not 1 <= group['steps'] <= 50:
            errors.append(gid + ': steps zwischen 1 und 50')
        if not isinstance(group.get('cfg_scale'), (int, float)) or not 0 <= float(group['cfg_scale']) <= 20:
            errors.append(gid + ': cfg_scale zwischen 0 und 20')
        roles = []
        for spec in group.get('files') or []:
            roles.append(spec.get('role'))
            path = str(spec.get('path', ''))
            if not REPO_RE.match(str(spec.get('repo', ''))) or not path or path.startswith('/') or '..' in path.split('/'):
                errors.append(gid + ': Datei mit ungueltigem Repository oder Pfad')
            if not isinstance(spec.get('size'), int) or spec['size'] <= 0:
                errors.append(gid + ': ' + path + ' ohne Groesse')
            if not str(spec.get('license', '')).strip():
                errors.append(gid + ': ' + path + ' ohne Lizenz')
        if sorted(roles) != sorted(ROLES):
            errors.append(gid + ': braucht genau diffusion_model, llm und vae')
    return errors


def load_registry(path: Path = REGISTRY) -> dict:
    reg = json.loads(path.read_text(encoding='utf-8'))
    errors = registry_errors(reg)
    if errors:
        raise ValueError('models.json: ' + '; '.join(errors))
    return reg


# --- Files ----------------------------------------------------------------------------

def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for block in iter(lambda: handle.read(CHUNK), b''):
            digest.update(block)
    return digest.hexdigest()


def local_path(models: Path, spec: dict) -> Path:
    """Where a registered file lives: one folder per repository, the repository's own path inside."""
    return models / spec['repo'].replace('/', '__') / spec['path']


def write_json(path: Path, data: Any) -> None:
    tmp = path.with_name(path.name + '.tmp')
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    os.replace(tmp, path)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        return None


def hf_resolve(spec: dict, revision: str = 'main') -> dict:
    """Hugging Face's answer for one file: commit, SHA-256 (LFS), size and the download address.

    0.6.38: a redirect inside Hugging Face itself (a repository name in other letter case, a
    renamed repository) is followed; only the answer that names the file counts. 0.6.37 took
    the size of such a redirect (103 bytes) for the size of the VAE (operator's run, 07.11.2026).
    """
    url = HF + '/' + spec['repo'] + '/resolve/' + urllib.parse.quote(revision, safe='') + '/' \
        + '/'.join(urllib.parse.quote(part) for part in spec['path'].split('/'))
    home = urllib.parse.urlsplit(HF).netloc
    opener = urllib.request.build_opener(_NoRedirect)
    for _hop in range(6):
        request = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'CoreAI-image-spike/1'})
        try:
            response = opener.open(request, timeout=60)
            headers, location, redirected = response.headers, url, False
        except urllib.error.HTTPError as error:
            if error.code not in (301, 302, 303, 307, 308):
                raise RuntimeError(f'{spec["repo"]}/{spec["path"]}: HTTP {error.code}') from None
            headers, location, redirected = error.headers, urllib.parse.urljoin(url, error.headers.get('Location', '')), True
        if redirected and not headers.get('X-Linked-Etag') and urllib.parse.urlsplit(location).netloc == home:
            url = location  # still Hugging Face, not yet the file: ask there
            continue
        etag = (headers.get('X-Linked-Etag') or ('' if redirected else headers.get('ETag')) or '').strip().strip('"').lower()
        if etag.startswith('w/'):
            etag = etag[2:].strip('"')
        # The size of the file, never the size of a redirect's own short body.
        size = headers.get('X-Linked-Size') or ('' if redirected else headers.get('Content-Length'))
        return {'commit': (headers.get('X-Repo-Commit') or '').strip().lower(),
                'sha256': etag if re.fullmatch(r'[0-9a-f]{64}', etag) else '',
                'size': int(size) if size and size.isdigit() else None, 'url': location}
    raise RuntimeError(f'{spec["repo"]}/{spec["path"]}: zu viele Weiterleitungen')


def download(url: str, part: Path, expected: int, attempts: int = 4) -> None:
    """Into part, resuming after a dropped connection."""
    for attempt in range(1, attempts + 1):
        have = part.stat().st_size if part.exists() else 0
        if have == expected:
            return
        headers = {'User-Agent': 'CoreAI-image-spike/1'}
        if 0 < have < expected:
            headers['Range'] = f'bytes={have}-'
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=120) as response:
                mode = 'ab' if response.status == 206 else 'wb'
                with part.open(mode) as handle:
                    for block in iter(lambda: response.read(CHUNK), b''):
                        handle.write(block)
        except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as error:
            log(f'  Abbruch beim Laden ({error}), Versuch {attempt} von {attempts}')
            time.sleep(min(30, 2 ** attempt))
    have = part.stat().st_size if part.exists() else 0
    if have != expected:
        raise RuntimeError(f'{part.name}: {have} statt {expected} Bytes geladen')


def fetch_file(models: Path, spec: dict) -> dict:
    target = local_path(models, spec)
    target.parent.mkdir(parents=True, exist_ok=True)
    info = hf_resolve(spec, str(spec.get('revision') or 'main'))
    if info['size'] is not None and info['size'] != spec['size']:
        raise RuntimeError(f'{spec["path"]}: Hugging Face meldet {info["size"]} Bytes, eingetragen sind {spec["size"]}')
    if not (target.exists() and target.stat().st_size == spec['size']):
        log(f'  {spec["repo"]}/{spec["path"]} ({spec["size"] / 1e9:.1f} GB)')
        part = target.with_name(target.name + '.part')
        download(info['url'], part, spec['size'])
        os.replace(part, target)
    digest = sha256_file(target)
    if info['sha256'] and digest != info['sha256']:
        target.unlink(missing_ok=True)
        raise RuntimeError(f'{spec["path"]}: SHA-256 {digest} passt nicht zu Hugging Face ({info["sha256"]}); geloescht')
    return {'role': spec['role'], 'repo': spec['repo'], 'path': spec['path'], 'file': str(target.relative_to(models)),
            'revision': info['commit'] or str(spec.get('revision') or 'main'), 'sha256': digest,
            'hf_sha256': info['sha256'], 'size': spec['size'], 'license': spec['license']}


def fetch(reg: dict, group_id: str, models: Path) -> None:
    group = reg['groups'][group_id]
    entries = [fetch_file(models, spec) for spec in group['files']]
    write_json(models / f'lock-{group_id}.json', {'format': 1, 'group': group_id, 'fetched_at': time.strftime('%Y-%m-%dT%H:%M:%S%z'),
                                                  'files': entries})
    log(f'{group_id}: {len(entries)} Dateien registriert (Revision und SHA-256 in lock-{group_id}.json).')


def verify(models: Path, group_id: str) -> tuple[dict, list[dict], str]:
    """Paths by role and the registered files; an error text when a file is missing or changed."""
    lock_path = models / f'lock-{group_id}.json'
    if not lock_path.is_file():
        # 0.6.38: the reason the loading failed, so Admin Core shows it (not only "no lock").
        failed = models / f'fetch-error-{group_id}.json'
        if failed.is_file():
            try:
                reason = str(json.loads(failed.read_text(encoding='utf-8')).get('error', '')).strip()
            except (OSError, ValueError):
                reason = ''
            if reason:
                return {}, [], 'Laden fehlgeschlagen: ' + reason[:300]
        return {}, [], 'Gewichte nicht geladen (kein lock-' + group_id + '.json)'
    lock = json.loads(lock_path.read_text(encoding='utf-8'))
    paths: dict[str, str] = {}
    for entry in lock.get('files') or []:
        path = models / entry['file']
        if not path.is_file() or path.stat().st_size != entry['size']:
            return {}, lock.get('files') or [], entry['path'] + ' fehlt oder hat die falsche Groesse'
        if sha256_file(path) != entry['sha256']:
            return {}, lock.get('files') or [], entry['path'] + ' hat sich seit dem Laden veraendert (SHA-256)'
        paths[entry['role']] = str(path)
    missing = [role for role in ROLES if role not in paths]
    return paths, lock.get('files') or [], ('fehlende Rollen: ' + ', '.join(missing)) if missing else ''


# --- Generating -------------------------------------------------------------------------

def timings(output: str) -> dict:
    """The times stable-diffusion.cpp reports, in seconds (the last of each kind)."""
    found: dict[str, float | None] = {}
    for key, pattern in TIMINGS.items():
        hits = pattern.findall(output)
        found[key] = round(float(hits[-1]), 2) if hits else None
    return found


def png_size(path: Path) -> tuple[int, int] | None:
    """Width and height from the PNG header, None when it is no PNG."""
    try:
        with path.open('rb') as handle:
            head = handle.read(24)
    except OSError:
        return None
    if len(head) < 24 or head[:8] != b'\x89PNG\r\n\x1a\n' or head[12:16] != b'IHDR':
        return None
    return struct.unpack('>II', head[16:24])


def command(files: dict, prompt: dict, size: str, group: dict, threads: int, out_png: Path, sd_cli: str = SD_CLI) -> list[str]:
    width, height = SIZE_RE.match(size).groups()
    return [sd_cli, '--diffusion-model', files['diffusion_model'], '--llm', files['llm'], '--vae', files['vae'],
            '-p', prompt['text'], '--cfg-scale', str(float(group['cfg_scale'])), '--steps', str(group['steps']),
            '-W', width, '-H', height, '-s', str(prompt['seed']), '-t', str(threads), '-o', str(out_png), '-v']


def run_measured(cmd: list[str], log_path: Path, timeout: int) -> tuple[int, float, float | None, bool]:
    """Exit code, seconds, peak RAM in MB of exactly this process, and whether it ran out of time."""
    start = time.monotonic()
    with log_path.open('wb') as log_file:
        pid = os.posix_spawn(cmd[0], cmd, os.environ.copy(), file_actions=[
            (os.POSIX_SPAWN_DUP2, log_file.fileno(), 1), (os.POSIX_SPAWN_DUP2, log_file.fileno(), 2)])
    timed_out = False
    while True:
        done, status, usage = os.wait4(pid, os.WNOHANG)
        if done:
            break
        if time.monotonic() - start > timeout:
            timed_out = True
            os.kill(pid, signal.SIGKILL)
            done, status, usage = os.wait4(pid, 0)
            break
        time.sleep(0.25)
    seconds = round(time.monotonic() - start, 2)
    code = os.waitstatus_to_exitcode(status)
    return code, seconds, round(usage.ru_maxrss / 1024, 1) if usage.ru_maxrss else None, timed_out


def run_group(reg: dict, group_id: str, models: Path, out: Path, threads: int, full: bool = False,
              sd_cli: str = SD_CLI, timeout: int = IMAGE_TIMEOUT) -> dict:
    group = reg['groups'][group_id]
    prompts = {p['id']: p for p in reg['prompts']}
    result = {'id': group_id, 'label': group['label'], 'origin': group.get('origin', ''), 'license': group['license'],
              'steps': group['steps'], 'cfg_scale': group['cfg_scale'], 'threads': threads, 'ok': False, 'error': '',
              'files': [], 'images': []}
    files, entries, error = verify(models, group_id)
    result['files'] = [{k: e.get(k) for k in ('role', 'repo', 'path', 'revision', 'sha256', 'size', 'license')} for e in entries]
    if error:
        result['error'] = error
        return result
    if not (os.path.isfile(sd_cli) and os.access(sd_cli, os.X_OK)):
        result['error'] = 'sd-cli fehlt im Container'
        return result
    logs = out / 'logs'
    logs.mkdir(parents=True, exist_ok=True)
    for step in reg['plan']:
        chosen = [p['id'] for p in reg['prompts']] if full else step['prompts']
        for prompt_id in chosen:
            name = f'{group_id}-{step["size"]}-{prompt_id}.png'
            target = out / name
            target.unlink(missing_ok=True)
            log(f'{group_id}: {step["size"]}, {prompt_id} ({group["steps"]} Schritte, {threads} Threads) ...')
            log_path = logs / (name[:-4] + '.log')
            code, seconds, rss, timed_out = run_measured(command(files, prompts[prompt_id], step['size'], group, threads, target, sd_cli),
                                                         log_path, timeout)
            output = log_path.read_text(encoding='utf-8', errors='replace')
            image = {'prompt': prompt_id, 'size': step['size'], 'file': name, 'seed': prompts[prompt_id]['seed'],
                     'wall_s': seconds, 'peak_rss_mb': rss, **timings(output), 'ok': False, 'error': ''}
            dims = png_size(target)
            expected = tuple(int(v) for v in SIZE_RE.match(step['size']).groups())
            if timed_out:
                image['error'] = f'nach {timeout} s abgebrochen'
            elif code != 0:
                tail = [line for line in output.strip().splitlines() if line.strip()][-1:] or ['']
                image['error'] = f'sd-cli Exit {code}: {tail[0][:200]}'
            elif dims is None:
                image['error'] = 'kein PNG geschrieben'
            elif dims != expected:
                image['error'] = f'PNG {dims[0]}x{dims[1]} statt {step["size"]}'
            else:
                image['ok'] = True
            if not image['ok']:
                target.unlink(missing_ok=True)
                image['file'] = ''
            result['images'].append(image)
            log(f'  {"ok" if image["ok"] else "FEHLER " + image["error"]}: {seconds} s, RAM {rss} MB')
    ok_images = [i for i in result['images'] if i['ok']]
    result['ok'] = bool(ok_images)
    if not ok_images:
        result['error'] = next((i['error'] for i in result['images'] if i['error']), 'kein Bild')
    return result


# --- Report ---------------------------------------------------------------------------------

def median(values: list[float]) -> float | None:
    values = sorted(v for v in values if isinstance(v, (int, float)))
    if not values:
        return None
    middle = len(values) // 2
    return round(values[middle] if len(values) % 2 else (values[middle - 1] + values[middle]) / 2, 2)


def summary(group: dict) -> dict:
    """Per size: median time of the whole call, of sampling, and the highest RAM."""
    sizes: dict[str, dict] = {}
    for image in group.get('images') or []:
        if not image.get('ok'):
            continue
        sizes.setdefault(image['size'], []).append(image)
    return {size: {'images': len(items), 'wall_s': median([i['wall_s'] for i in items]),
                   'sampling_s': median([i.get('sampling_s') for i in items]),
                   'load_s': median([i.get('load_s') for i in items]),
                   'peak_rss_mb': max((i['peak_rss_mb'] for i in items if i.get('peak_rss_mb')), default=None)}
            for size, items in sizes.items()}


def report(run_dir: Path, reg: dict | None = None) -> dict:
    reg = reg or load_registry()
    groups = []
    for gid in reg['groups']:
        path = run_dir / f'{gid}.json'
        if path.is_file():
            group = json.loads(path.read_text(encoding='utf-8'))
            group['summary'] = summary(group)
            groups.append(group)
    host = (run_dir / 'host.txt').read_text(encoding='utf-8', errors='replace')[:4000] if (run_dir / 'host.txt').is_file() else ''
    data = {'format': 1, 'stamp': run_dir.name, 'created_at': time.strftime('%Y-%m-%dT%H:%M:%S%z'), 'host': host,
            'runtime': reg['runtime'], 'prompts': reg['prompts'], 'plan': reg['plan'], 'groups': groups,
            'errors': [f'{g["id"]}: {g["error"]}' for g in groups if g.get('error')]}
    write_json(run_dir / 'report.json', data)
    lines = [f'# CoreAI Bildtest {run_dir.name}', '', f'stable-diffusion.cpp {reg["runtime"]["tag"]} ({reg["runtime"]["commit"]}), '
             + reg['runtime']['license'], '', '```', host.strip(), '```', '']
    for group in groups:
        lines.append(f'## {group["label"]} ({group["license"]}), {group["steps"]} Schritte, {group["threads"]} Threads')
        lines.append('')
        if group.get('error'):
            lines.append('Fehler: ' + group['error'])
            lines.append('')
        lines.append('| Bild | Groesse | Gesamt s | Laden s | Text s | Schritte s | Dekodieren s | RAM MB | Status |')
        lines.append('|---|---|---|---|---|---|---|---|---|')
        for image in group.get('images') or []:
            lines.append('| {prompt} | {size} | {wall_s} | {load_s} | {text_s} | {sampling_s} | {decode_s} | {peak_rss_mb} | {status} |'.format(
                status='ok' if image['ok'] else image['error'], **image))
        lines.append('')
        for entry in group.get('files') or []:
            lines.append(f'- {entry["role"]}: {entry["repo"]}/{entry["path"]} @ {entry["revision"]}, sha256 {entry["sha256"]}, '
                         f'{entry["size"]} Bytes, {entry["license"]}')
        lines.append('')
    for prompt in reg['prompts']:
        lines.append(f'- {prompt["id"]} ({prompt["topic"]}, Seed {prompt["seed"]}): {prompt["text"]}')
    (run_dir / 'report.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
    return data


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='CoreAI Bildtest (P20.5 A)')
    sub = parser.add_subparsers(dest='cmd', required=True)
    fetch_p = sub.add_parser('fetch')
    fetch_p.add_argument('--group', required=True)
    fetch_p.add_argument('--models', required=True, type=Path)
    run_p = sub.add_parser('run')
    run_p.add_argument('--group', required=True)
    run_p.add_argument('--models', required=True, type=Path)
    run_p.add_argument('--out', required=True, type=Path)
    run_p.add_argument('--threads', required=True, type=int)
    run_p.add_argument('--full', action='store_true')
    report_p = sub.add_parser('report')
    report_p.add_argument('--run-dir', required=True, type=Path)
    sub.add_parser('validate')
    args = parser.parse_args(argv)
    reg = load_registry()
    if args.cmd == 'validate':
        print('models.json OK: ' + ', '.join(reg['groups']))
        return 0
    if args.cmd in ('fetch', 'run') and args.group not in reg['groups']:
        parser.error('unbekannte Gruppe ' + args.group)
    if args.cmd == 'fetch':
        failed = args.models / f'fetch-error-{args.group}.json'
        try:
            fetch(reg, args.group, args.models)
        except (RuntimeError, OSError, urllib.error.URLError) as error:
            log(f'{args.group}: Laden fehlgeschlagen: {error}')
            write_json(failed, {'group': args.group, 'error': str(error), 'at': time.strftime('%Y-%m-%dT%H:%M:%S%z')})
            return 1
        failed.unlink(missing_ok=True)
        return 0
    if args.cmd == 'run':
        if not 1 <= args.threads <= 512:
            parser.error('--threads zwischen 1 und 512')
        result = run_group(reg, args.group, args.models, args.out, args.threads, args.full)
        write_json(args.out / f'{args.group}.json', result)
        return 0 if result['ok'] else 1
    report(args.run_dir, reg)
    return 0


if __name__ == '__main__':
    sys.exit(main())
