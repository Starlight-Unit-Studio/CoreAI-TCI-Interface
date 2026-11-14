#!/usr/bin/env python3
"""Offline tiny OCI fixtures for safe Ollama image model weight materialization."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import struct
from pathlib import Path
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("coreai_ollama_weight_bridge", ROOT / "tools" / "image" / "ollama_weight_bridge.py")
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


def blob(root: Path, dtype: str = "F16") -> dict:
    payload = b"\x11" * 8
    head = json.dumps({"data": {"dtype": dtype, "shape": [4], "data_offsets": [0, 8]}},
                      separators=(",", ":")).encode()
    raw = struct.pack("<Q", len(head)) + head + payload
    sha = hashlib.sha256(raw).hexdigest()
    path = root / "blobs" / ("sha256-" + sha)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(raw)
    return {"digest": "sha256:" + sha, "size": len(raw),
            "mediaType": "application/vnd.ollama.image.tensor"}


def fixture(root: Path, dtype: str = "F16") -> dict:
    manifest = {"schemaVersion": 2,
                "mediaType": "application/vnd.docker.distribution.manifest.v2+json",
                "runner": "mlx", "format": "safetensors", "layers": []}
    for role in ("transformer", "text_encoder", "vae"):
        manifest["layers"].append(dict(blob(root, dtype), name=role + "/test.weight"))
    target = root / "manifests" / "registry.ollama.ai" / "x" / "flux2-klein" / "4b-bf16"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(manifest), encoding="utf-8")
    return manifest


def rejects(fn, code: str) -> None:
    try:
        fn()
    except bridge.ImportErrorCode as error:
        assert str(error) == code, (str(error), code)
    else:
        raise AssertionError("Expected rejection: " + code)


with TemporaryDirectory(prefix="coreai-ollama-bridge-") as temp:
    store = Path(temp) / "ollama"
    out = Path(temp) / "models"
    manifest = fixture(store)
    meta = bridge.inspect(store, "x/flux2-klein:4b-bf16")
    assert meta["family"] == "flux2-klein-4b"
    assert all(len(rows) == 1 for rows in meta["components"].values())
    result = bridge.materialize(store, out, "x/flux2-klein:4b-bf16")
    assert result["ok"] and not result["activatable"] and result["needs_cpu_render_test"]
    assert set(result["files"]) == {"transformer", "text_encoder", "vae"}
    for item in result["files"].values():
        data = Path(item["path"]).read_bytes()
        assert hashlib.sha256(data).hexdigest() == item["sha256"]
        size = struct.unpack("<Q", data[:8])[0]
        header = json.loads(data[8:8 + size])
        assert header["test.weight"]["dtype"] == "F16"
        assert data[8 + size:] == b"\x11" * 8

    rejects(lambda: bridge.inspect(store, "feadxus/flux2-klein-9b-uncensored:Q4_K_M"),
            "unsupported_ollama_image_family")
    rejects(lambda: bridge.inspect(store, "x/flux2-klein:4b-qlora"),
            "unsupported_ollama_image_family")
    manifest["layers"][0]["name"] = "transformer/../escape"
    path = store / "manifests" / "registry.ollama.ai" / "x" / "flux2-klein" / "4b-bf16"
    path.write_text(json.dumps(manifest))
    rejects(lambda: bridge.inspect(store, "x/flux2-klein:4b-bf16"),
            "unsupported_ollama_tensor_name")
    manifest["layers"][0]["name"] = "transformer/test.weight"
    path.write_text(json.dumps(manifest))
    blob_path = store / "blobs" / ("sha256-" + manifest["layers"][0]["digest"].split(":")[1])
    blob_path.write_bytes(blob_path.read_bytes()[:-2] + b"AA")
    rejects(lambda: bridge.materialize(store, out, "x/flux2-klein:4b-bf16"),
            "ollama_blob_hash_mismatch")

    fixture(store, "U32")
    rejects(lambda: bridge.inspect(store, "x/flux2-klein:4b-bf16"),
            "ollama_tensor_quantization_incompatible")

print("PASS: Ollama real tensor weight export, exact hashes, protected default, incompatible formats and paths")
