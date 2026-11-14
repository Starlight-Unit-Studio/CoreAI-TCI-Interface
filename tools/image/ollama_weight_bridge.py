#!/usr/bin/env python3
"""Fail-closed, read-only importer for genuine local Ollama FLUX.2 BF16 tensor layers.

Ollama image models use one-tensor safetensors blobs in a local OCI manifest.
This module reconstructs stable-diffusion.cpp-compatible *candidate* component
files without trusting paths from the manifest or accepting packed MLX FP4/FP8.
An actual sd-cli CPU render MUST pass before the candidate can be activated.
The default installed FLUX image generator remains untouched by this code.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import struct
import subprocess
from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Any

NAME_RE = re.compile(r"^x/flux2-klein:(4b-bf16|9b-bf16)$", re.I)
DIGEST_RE = re.compile(r"^sha256:([0-9a-f]{64})$")
LAYER_RE = re.compile(r"^(transformer|text_encoder|vae)/([A-Za-z0-9][A-Za-z0-9_.-]{0,250})$")
TENSOR_MEDIA = "application/vnd.ollama.image.tensor"
ALLOWED_DTYPE = {"F16": 2, "BF16": 2, "F32": 4}
MAX_TENSORS = 8000
MAX_COMPONENT_BYTES = 65_000_000_000
MAX_METADATA_BYTES = 1_000_000


class ImportErrorCode(ValueError):
    """Safe error code for UI; never return untrusted manifest text."""


def _doc(path: Path, max_size: int = 2_000_000) -> dict:
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > max_size:
            raise ImportErrorCode("invalid_ollama_manifest")
        obj = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(obj, dict):
            raise ValueError()
        return obj
    except (OSError, ValueError) as error:
        if isinstance(error, ImportErrorCode):
            raise
        raise ImportErrorCode("invalid_ollama_manifest") from error


def _blob(root: Path, digest: str) -> Path:
    match = DIGEST_RE.fullmatch(str(digest))
    if not match:
        raise ImportErrorCode("invalid_blob_digest")
    path = root / "blobs" / ("sha256-" + match.group(1))
    if path.is_symlink() or not path.is_file():
        raise ImportErrorCode("missing_ollama_blob")
    return path


def _manifest(root: Path, model: str) -> tuple[dict, str]:
    match = NAME_RE.fullmatch(model)
    if not match:
        raise ImportErrorCode("unsupported_ollama_image_family")
    namespace, name_tag = model.split("/", 1)
    name, tag = name_tag.split(":", 1)
    path = root / "manifests" / "registry.ollama.ai" / namespace / name / tag
    manifest = _doc(path)
    if manifest.get("mediaType") == "application/vnd.ollama.manifest.list.v2+json":
        candidates = [row for row in manifest.get("manifests", []) if isinstance(row, dict)
                      and row.get("runner") == "mlx" and row.get("format") == "safetensors"]
        if len(candidates) != 1:
            raise ImportErrorCode("unsupported_ollama_manifest_runner")
        manifest = _doc(_blob(root, candidates[0].get("digest", "")))
    if manifest.get("mediaType") != "application/vnd.docker.distribution.manifest.v2+json":
        raise ImportErrorCode("unsupported_ollama_manifest_format")
    if manifest.get("runner") not in (None, "", "mlx") or manifest.get("format") not in (None, "", "safetensors"):
        raise ImportErrorCode("unsupported_ollama_manifest_runner")
    layers = manifest.get("layers")
    if not isinstance(layers, list) or len(layers) > MAX_TENSORS + 100:
        raise ImportErrorCode("invalid_ollama_tensor_count")
    return manifest, "flux2-klein-" + ("9b" if tag.lower().startswith("9b") else "4b")


def _read_tensor(path: Path, layer: dict) -> tuple[dict, int, int]:
    expected = layer.get("size")
    if not isinstance(expected, int) or expected <= 16 or expected > MAX_COMPONENT_BYTES:
        raise ImportErrorCode("invalid_tensor_size")
    if path.stat().st_size != expected:
        raise ImportErrorCode("incomplete_ollama_blob")
    try:
        with path.open("rb") as stream:
            first = stream.read(8)
            if len(first) != 8:
                raise ImportErrorCode("bad_safetensors_header")
            hsize = struct.unpack("<Q", first)[0]
            if hsize < 8 or hsize > MAX_METADATA_BYTES or 8 + hsize >= expected:
                raise ImportErrorCode("bad_safetensors_header")
            meta = json.loads(stream.read(hsize).decode("utf-8"))
    except (OSError, ValueError, UnicodeDecodeError) as exc:
        if isinstance(exc, ImportErrorCode):
            raise
        raise ImportErrorCode("bad_safetensors_header") from exc
    if not isinstance(meta, dict):
        raise ImportErrorCode("bad_safetensors_header")
    entries = [(key, value) for key, value in meta.items() if key != "__metadata__"]
    if len(entries) != 1 or not isinstance(entries[0][1], dict):
        raise ImportErrorCode("unsupported_ollama_tensor_layout")
    payload = entries[0][1]
    dtype = payload.get("dtype")
    if dtype not in ALLOWED_DTYPE:
        raise ImportErrorCode("ollama_tensor_quantization_incompatible")
    shape = payload.get("shape")
    if not isinstance(shape, list) or not 1 <= len(shape) <= 8 or not all(
            isinstance(v, int) and not isinstance(v, bool) and v > 0 for v in shape):
        raise ImportErrorCode("invalid_tensor_shape")
    start, end = payload.get("data_offsets", [None, None])
    if not isinstance(start, int) or not isinstance(end, int) or start != 0 or end <= 0:
        raise ImportErrorCode("invalid_tensor_offsets")
    count = 1
    for dim in shape:
        count *= dim
        if count > MAX_COMPONENT_BYTES:
            raise ImportErrorCode("invalid_tensor_shape")
    if count * ALLOWED_DTYPE[dtype] != end or end + 8 + hsize != expected:
        raise ImportErrorCode("invalid_tensor_payload")
    return {"dtype": dtype, "shape": shape}, 8 + hsize, end


def inspect(root: Path, model: str) -> dict[str, Any]:
    """Inspect installed OCI tensors without exposing or changing Ollama's blobs."""
    root = Path(root)
    manifest, family = _manifest(root, model)
    components: dict[str, list[tuple[str, Path, str, dict, int, int]]] = {
        "transformer": [], "text_encoder": [], "vae": []}
    seen = set()
    for layer in manifest["layers"]:
        if not isinstance(layer, dict):
            raise ImportErrorCode("invalid_ollama_tensor_layer")
        if layer.get("mediaType") != TENSOR_MEDIA:
            continue
        name = str(layer.get("name") or "")
        match = LAYER_RE.fullmatch(name)
        if not match or name in seen:
            raise ImportErrorCode("unsupported_ollama_tensor_name")
        seen.add(name)
        path = _blob(root, layer.get("digest", ""))
        info, offset, size = _read_tensor(path, layer)
        components[match.group(1)].append((match.group(2), path, layer["digest"], info, offset, size))
        if len(seen) > MAX_TENSORS:
            raise ImportErrorCode("invalid_ollama_tensor_count")
    if any(not rows for rows in components.values()):
        raise ImportErrorCode("missing_ollama_image_component")
    for rows in components.values():
        if sum(row[-1] for row in rows) > MAX_COMPONENT_BYTES:
            raise ImportErrorCode("ollama_component_too_large")
    provenance = hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    return {"family": family, "model": model, "components": components, "manifest_sha256": provenance}


def _assemble(rows: list[tuple], destination: Path) -> tuple[int, str]:
    rows = sorted(rows, key=lambda row: row[0])
    header: dict[str, Any] = {}
    offset = 0
    for name, _, _, info, _, size in rows:
        header[name] = {**info, "data_offsets": [offset, offset + size]}
        offset += size
    raw = json.dumps(header, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
    raw += b" " * ((8 - len(raw) % 8) % 8)
    if len(raw) > 20_000_000:
        raise ImportErrorCode("ollama_header_too_large")
    part = destination.with_name(destination.name + ".part")
    output_hash = hashlib.sha256()
    try:
        with part.open("wb") as target:
            prefix = struct.pack("<Q", len(raw)) + raw
            target.write(prefix)
            output_hash.update(prefix)
            for _, path, expected, _, offset, size in rows:
                source_hash = hashlib.sha256()
                with path.open("rb") as source:
                    while True:
                        chunk = source.read(1024 * 1024)
                        if not chunk:
                            break
                        source_hash.update(chunk)
                    if "sha256:" + source_hash.hexdigest() != expected:
                        raise ImportErrorCode("ollama_blob_hash_mismatch")
                    source.seek(offset)
                    remaining = size
                    while remaining:
                        chunk = source.read(min(1024 * 1024, remaining))
                        if not chunk:
                            raise ImportErrorCode("incomplete_ollama_blob")
                        target.write(chunk)
                        output_hash.update(chunk)
                        remaining -= len(chunk)
            target.flush()
            os.fsync(target.fileno())
        os.replace(part, destination)
    finally:
        part.unlink(missing_ok=True)
    return destination.stat().st_size, output_hash.hexdigest()


def materialize(root: Path, models: Path, model: str) -> dict:
    """Quarantine real local Ollama tensor weights; do NOT select or activate."""
    meta = inspect(root, model)
    profile_root = Path(models) / "ollama-imports" / meta["manifest_sha256"][:24]
    profile_root.mkdir(parents=True, exist_ok=True)
    exported = {}
    for role, rows in meta["components"].items():
        target = profile_root / (role + ".safetensors")
        size, sha = _assemble(rows, target)
        exported[role] = {"path": str(target), "size": size, "sha256": sha}
    return {"ok": True, "model": model, "family": meta["family"],
            "source_manifest_sha256": meta["manifest_sha256"], "files": exported,
            "activatable": False, "needs_cpu_render_test": True}


def cpu_render_probe(sd_cli: str, exported: dict, output_dir: Path, timeout_s: int = 360) -> dict:
    """Execute real 256x256 sd-cli rendering, not a fake capability check."""
    paths = exported["files"]
    with TemporaryDirectory(prefix="coreai-ollama-test-", dir=output_dir) as temp:
        output = Path(temp) / "probe.png"
        command = [sd_cli, "--diffusion-model", paths["transformer"]["path"],
                   "--llm", paths["text_encoder"]["path"], "--vae", paths["vae"]["path"],
                   "-p", "a single red geometric cube on a white background",
                   "--cfg-scale", "1.0", "--steps", "4", "-W", "256", "-H", "256",
                   "-s", "42", "-t", "2", "-o", str(output)]
        try:
            done = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                  timeout=max(30, min(timeout_s, 900)), check=False)
        except (OSError, subprocess.TimeoutExpired):
            return {"ok": False, "error": "ollama_cpu_probe_failed"}
        good = (done.returncode == 0 and output.is_file() and output.stat().st_size > 100
                and output.open("rb").read(8) == b"\x89PNG\r\n\x1a\n")
        return {"ok": bool(good), "error": "" if good else "ollama_cpu_probe_failed"}
