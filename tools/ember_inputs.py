"""Turn-bound upload snapshots. Never expose the private media store to Docker."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import stat
import zipfile
import zlib

MAX_FILE_BYTES = 256 * 1024 * 1024  # same as console_upload.php
MAX_FILES = 10
MAX_ZIP_BYTES = 1024 * 1024 * 1024
MAX_ZIP_ENTRIES = 10000


class InputError(ValueError):
    """Safe diagnostic code; no private host paths or file contents."""


def resolve_inputs(conn, job):
    """Resolve only this live message's uploads, including legacy file_uuid."""
    uid = int(job.get('recipient_uid') or 0)
    message = int(job.get('trigger_message_id') or 0)
    session = job.get('session_id')
    if job.get('channel') != 'console' or uid <= 0 or message <= 0 or not session:
        return []
    with conn.cursor() as cur:
        cur.execute(
            "SELECT m.file_uuid, (SELECT COALESCE(MAX(r.revision_no),0) "
            "FROM stu_console_message_revisions r WHERE r.message_id=m.id) AS revision "
            "FROM stu_chat_messages m JOIN stu_console_sessions s ON s.id=m.session_id "
            "WHERE m.id=%s AND m.user_id=%s AND s.user_id=%s AND m.session_id=%s "
            "AND m.channel='console' AND m.deleted_at IS NULL "
            "AND EXISTS (SELECT 1 FROM stu_ember_py_jobs j WHERE j.id=%s "
            "AND j.status='running' AND j.worker_pid=%s)",
            (message, uid, uid, session, int(job['id']), os.getpid()))
        turn = cur.fetchone()
        if not turn or int(turn['revision']) != int(job.get('trigger_revision') or 0):
            raise InputError('input_context_changed')
        cur.execute("SELECT media_uuid FROM stu_console_message_attachments "
                    "WHERE message_id=%s AND user_id=%s ORDER BY position", (message, uid))
        ids = [r['media_uuid'] for r in cur.fetchall()]
        if not ids and turn.get('file_uuid'):
            ids = [turn['file_uuid']]
        if len(ids) > MAX_FILES or len(set(ids)) != len(ids):
            raise InputError('input_attachment_limit')
        rows = []
        for uuid in ids:
            if not isinstance(uuid, str) or not re.fullmatch('[a-f0-9]{32}', uuid):
                raise InputError('input_invalid_id')
            cur.execute("SELECT uuid,orig_name,mime_type,file_size,rel_path FROM stu_console_media "
                        "WHERE uuid=%s AND user_id=%s", (uuid, uid))
            row = cur.fetchone()
            if not row:
                raise InputError('input_missing:' + uuid)
            rows.append(row)
        return rows


def validate_zip(path):
    """Validate all members before model code can extract the copied archive."""
    try:
        with zipfile.ZipFile(path) as archive:
            entries = archive.infolist()
            if len(entries) > MAX_ZIP_ENTRIES:
                raise InputError('input_zip_entry_limit')
            total, seen, files = 0, set(), set()
            for entry in entries:
                name = entry.filename.rstrip('/')
                parts = name.split('/')
                mode = entry.external_attr >> 16
                if (not name or entry.orig_filename != entry.filename or '\\' in name
                        or ':' in name or any(p in ('', '.', '..') for p in parts)
                        or any(ord(c) < 32 or ord(c) == 127 for c in name)
                        or stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR)
                        or name in seen or entry.flag_bits & 1):
                    raise InputError('input_zip_unsafe')
                seen.add(name)
                if not entry.is_dir():
                    files.add(name)
                total += entry.file_size
                if (total > MAX_ZIP_BYTES or entry.file_size > MAX_FILE_BYTES
                        or entry.file_size > max(1024 * 1024, entry.compress_size * 1000)):
                    raise InputError('input_zip_expansion_limit')
            for name in seen:
                parts = name.split('/')
                if any('/'.join(parts[:i]) in files for i in range(1, len(parts))):
                    raise InputError('input_zip_path_collision')
            # Stream every entry for CRC verification within the declared limits.
            for entry in entries:
                actual = 0
                with archive.open(entry) as source:
                    while block := source.read(262144):
                        actual += len(block)
                        if actual > entry.file_size:
                            raise InputError('input_zip_size_mismatch')
                if actual != entry.file_size:
                    raise InputError('input_zip_size_mismatch')
    except (zipfile.BadZipFile, NotImplementedError, RuntimeError, EOFError, zlib.error) as exc:
        raise InputError('input_zip_invalid') from exc


def copy_input(root, row, destination):
    """Open each path component without following links, then copy a stable inode."""
    uuid = row.get('uuid', '')
    if not re.fullmatch('[a-f0-9]{32}', uuid):
        raise InputError('input_invalid_id')
    rel = row.get('rel_path', '')
    parts = rel.split('/')
    if (len(parts) != 3 or '/'.join(parts[:2]) not in ('var/console_media', 'assets/chat_media')
            or not re.fullmatch(re.escape(uuid) + r'\.[a-z0-9]+', parts[-1])):
        raise InputError('input_invalid_path:' + uuid)
    expected = int(row.get('file_size') or 0)
    if not 0 < expected <= MAX_FILE_BYTES:
        raise InputError('input_size_limit:' + uuid)
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        with os.fdopen(fd, 'rb') as source:
            before = os.fstat(source.fileno())
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size != expected:
                raise InputError('input_integrity_failed:' + uuid)
            digest, size = hashlib.sha256(), 0
            with open(destination, 'xb') as target:
                while block := source.read(262144):
                    size += len(block)
                    if size > expected:
                        raise InputError('input_integrity_failed:' + uuid)
                    digest.update(block)
                    target.write(block)
            after = os.fstat(source.fileno())
            if (size != expected or before.st_mtime_ns != after.st_mtime_ns
                    or before.st_ctime_ns != after.st_ctime_ns or after.st_size != expected):
                raise InputError('input_integrity_failed:' + uuid)
            return digest.hexdigest()
    finally:
        os.close(directory)


def stage_inputs(root, rows, destination):
    """Snapshot original bytes; names remain metadata, never filesystem paths."""
    if len(rows) > MAX_FILES:
        raise InputError('input_attachment_limit')
    destination = Path(destination)
    destination.mkdir(mode=0o755)
    manifest = {'version': 1, 'files': []}
    for row in rows:
        uuid = row.get('uuid', '')
        if not re.fullmatch('[a-f0-9]{32}', uuid):
            raise InputError('input_invalid_id')
        target = destination / uuid
        try:
            sha = copy_input(root, row, target)
            name = str(row.get('orig_name') or 'datei')
            if name.lower().endswith('.zip') or zipfile.is_zipfile(target):
                validate_zip(target)
            target.chmod(0o444)
        except OSError as exc:
            raise InputError('input_unavailable:' + uuid) from exc
        manifest['files'].append({'upload_id': uuid, 'name': name,
                                  'path': '/work/input/' + uuid,
                                  'size_bytes': int(row['file_size']), 'sha256': sha,
                                  'mime_type': str(row.get('mime_type') or 'application/octet-stream')})
    index = destination / 'manifest.json'
    index.write_text(json.dumps(manifest, ensure_ascii=True), encoding='utf-8')
    index.chmod(0o444)
    return manifest
