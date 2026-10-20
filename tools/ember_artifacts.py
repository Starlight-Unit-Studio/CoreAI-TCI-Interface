"""Bounded, no-follow collection of stopped sandbox output into the private store.

The v1 manifest is consumed by console_artifact_store.php. Never mount the durable
store into the code sandbox. PHP owns account/message binding and delivery.
"""
from __future__ import annotations

import hashlib
import mimetypes
import os
import re
import secrets
import stat
from pathlib import Path
from typing import Any

MAX_FILES = 10
MAX_FILE_BYTES = 25 * 1024 * 1024
MAX_TOTAL_BYTES = 100 * 1024 * 1024
MAX_ENTRIES = 256
MAX_DEPTH = 8
BLOCK_SIZE = 256 * 1024
DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
FILE_FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
KEY_RE = re.compile(r"^[1-9][0-9]{0,9}/[a-f0-9]{32}$")


def empty_manifest() -> dict[str, Any]:
    return {"version": 1, "files": [], "warnings": []}


def valid_name(name: str) -> bool:
    try:
        encoded = name.encode("utf-8", "strict")
    except UnicodeError:
        return False
    return (0 < len(encoded) <= 255 and name not in (".", "..")
            and not any(ord(c) < 32 or ord(c) == 127 or c in "/\\" for c in name))


def _directory(parent_fd: int, name: str, gid: int) -> int:
    try:
        os.mkdir(name, mode=0o770, dir_fd=parent_fd)
    except FileExistsError:
        pass
    fd = os.open(name, DIR_FLAGS, dir_fd=parent_fd)
    try:
        if os.geteuid() == 0:
            os.fchown(fd, -1, gid)
        # PHP needs directory write permission for session/account deletion.
        os.fchmod(fd, 0o770)
        return fd
    except BaseException:
        os.close(fd)
        raise


def collect_outputs(output: str | Path, base: str | Path, job_id: int,
                    *, partial: bool = False) -> dict[str, Any]:
    """Copy regular files only, after the caller has confirmed sandbox shutdown.

    Inherit the installed work directory's PHP group. Names never become storage
    paths. A rejected file does not hide other accepted files. Traversal and copy
    work are bounded. Use ZIP for exporting a complete directory structure.
    """
    if isinstance(job_id, bool) or not isinstance(job_id, int) or not 0 < job_id <= 4294967295:
        raise ValueError("invalid_job_id")
    result = empty_manifest()
    budget = {"entries": 0, "bytes": 0}
    stopped = False

    def warn(code: str) -> None:
        if code not in result["warnings"]:
            result["warnings"].append(code)

    def visit(source_fd: int, dest_fd: int, depth: int) -> None:
        nonlocal stopped
        # Do not sort or materialize a potentially unbounded directory.
        with os.scandir(source_fd) as entries:
            for entry in entries:
                if stopped:
                    return
                budget["entries"] += 1
                if budget["entries"] > MAX_ENTRIES:
                    warn("entry_limit")
                    stopped = True
                    return
                name = entry.name
                if not valid_name(name):
                    warn("invalid_name")
                    continue
                source_file = target_file = token = None
                accepted = False
                try:
                    before = os.stat(name, dir_fd=source_fd, follow_symlinks=False)
                    if stat.S_ISDIR(before.st_mode):
                        if depth >= MAX_DEPTH:
                            warn("depth_limit")
                            continue
                        child = os.open(name, DIR_FLAGS, dir_fd=source_fd)
                        try:
                            opened = os.fstat(child)
                            if (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino):
                                raise OSError("directory_changed")
                            visit(child, dest_fd, depth + 1)
                        finally:
                            os.close(child)
                        continue
                    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
                        warn("non_regular_or_link")
                        continue
                    if len(result["files"]) >= MAX_FILES:
                        warn("file_count_limit")
                        stopped = True
                        return
                    if before.st_size > MAX_FILE_BYTES:
                        warn("file_size_limit")
                        continue
                    if budget["bytes"] + before.st_size > MAX_TOTAL_BYTES:
                        warn("total_size_limit")
                        continue
                    source_file = os.open(name, FILE_FLAGS, dir_fd=source_fd)
                    opened = os.fstat(source_file)
                    if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                            or (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)):
                        raise OSError("source_changed")
                    token = secrets.token_hex(16)
                    target_file = os.open(token, os.O_WRONLY | os.O_CREAT | os.O_EXCL
                                          | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=dest_fd)
                    if os.geteuid() == 0:
                        os.fchown(target_file, -1, os.fstat(dest_fd).st_gid)
                    os.fchmod(target_file, 0o640)
                    digest = hashlib.sha256()
                    copied = 0
                    while True:
                        part = os.read(source_file, BLOCK_SIZE)
                        if not part:
                            break
                        copied += len(part)
                        if copied > MAX_FILE_BYTES or budget["bytes"] + copied > MAX_TOTAL_BYTES:
                            raise OSError("source_grew_past_limit")
                        digest.update(part)
                        view = memoryview(part)
                        while view:
                            count = os.write(target_file, view)
                            if count <= 0:
                                raise OSError("short_write")
                            view = view[count:]
                    after = os.fstat(source_file)
                    if (copied != opened.st_size or after.st_size != opened.st_size
                            or after.st_mtime_ns != opened.st_mtime_ns or after.st_nlink != 1):
                        raise OSError("source_changed")
                    os.fsync(target_file)
                    mime = mimetypes.guess_type(name, strict=False)[0] or "application/octet-stream"
                    if len(mime) > 128 or not re.fullmatch(r"[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+", mime):
                        mime = "application/octet-stream"
                    result["files"].append({"storage_key": f"{job_id}/{token}", "name": name,
                                            "size_bytes": copied, "sha256": digest.hexdigest(),
                                            "mime_type": mime, "partial": bool(partial)})
                    budget["bytes"] += copied
                    accepted = True
                except (OSError, ValueError):
                    warn("file_rejected")
                finally:
                    if source_file is not None:
                        os.close(source_file)
                    if target_file is not None:
                        os.close(target_file)
                    if token is not None and not accepted:
                        try:
                            os.unlink(token, dir_fd=dest_fd)
                        except OSError:
                            warn("cleanup_pending")

    base_fd = store_fd = dest_fd = output_fd = None
    try:
        base_fd = os.open(os.fspath(base), DIR_FLAGS)
        gid = os.fstat(base_fd).st_gid
        store_fd = _directory(base_fd, "artifacts", gid)
        dest_fd = _directory(store_fd, str(job_id), gid)
        output_fd = os.open(os.fspath(output), DIR_FLAGS)
        visit(output_fd, dest_fd, 0)
        os.fsync(dest_fd)
        os.fsync(store_fd)
        os.fsync(base_fd)
    except OSError:
        warn("storage_unavailable")
    finally:
        for fd in (output_fd, dest_fd, store_fd, base_fd):
            if fd is not None:
                os.close(fd)
    return result


def discard_outputs(base: str | Path, manifest: dict[str, Any]) -> None:
    """Delete this run's unpublished copies after a definitive DB rejection.

    Do not call on an ambiguous commit outcome. The DB may have committed and
    the PHP request may already be attaching the files to a response.
    """
    base_fd = os.open(os.fspath(base), DIR_FLAGS)
    store_fd = None
    try:
        store_fd = os.open("artifacts", DIR_FLAGS, dir_fd=base_fd)
        for file in manifest.get("files", []):
            key = file.get("storage_key", "")
            if not isinstance(key, str) or not KEY_RE.fullmatch(key):
                raise ValueError("invalid_storage_key")
            job, token = key.split("/", 1)
            job_fd = os.open(job, DIR_FLAGS, dir_fd=store_fd)
            try:
                os.unlink(token, dir_fd=job_fd)
            except FileNotFoundError:
                pass
            finally:
                os.close(job_fd)
            try:
                os.rmdir(job, dir_fd=store_fd)
            except OSError:
                pass
    finally:
        if store_fd is not None:
            os.close(store_fd)
        os.close(base_fd)
