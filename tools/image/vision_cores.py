#!/usr/bin/env python3
"""Hugging Face Vision Core catalog for CoreAI's pinned local diffusion runtime.

Only known-compatible FLUX.2-klein 4B GGUF packages are offered by this
initial adapter. No generic vision-language model is ever passed to sd-cli.
The built-in FLUX profile is immutable; all additional weights are SHA-256
pinned to their Hub commit, verified before activation, and stored in /models.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
import re
import threading
import urllib.request
from pathlib import Path


SOURCES = {
    "leejet/FLUX.2-klein-4B-GGUF": {
        "prefix": "flux-2-klein-4b-", "label": "FLUX.2 Klein 4B",
        "steps": 4, "cfg_scale": 1.0,
    },
    "leejet/FLUX.2-klein-base-4B-GGUF": {
        "prefix": "flux-2-klein-base-4b-", "label": "FLUX.2 Klein Base 4B",
        "steps": 20, "cfg_scale": 4.0,
    },
}
HEX40 = re.compile(r"[0-9a-f]{40}")
HEX64 = re.compile(r"[0-9a-f]{64}")
QUANT = re.compile(r"Q[248][0-9A-Za-z_]*\.gguf$", re.I)


def model_id(repo: str, path: str) -> str:
    return hashlib.sha256((repo + "/" + path).encode("utf-8")).hexdigest()[:24]


def hub_catalog(query: str = "") -> list[dict]:
    """Show only verified, locally supported image model files from the Hub."""
    query = query.lower().strip()[:80]
    results = []
    for repo, family in SOURCES.items():
        url = "https://huggingface.co/api/models/" + repo + "/revision/main?blobs=true"
        request = urllib.request.Request(url, headers={"User-Agent": "CoreAI-VisionLibrary/1.0"})
        with urllib.request.urlopen(request, timeout=9) as response:
            if response.status != 200:
                raise RuntimeError("huggingface_unavailable")
            doc = json.loads(response.read(2_000_001).decode("utf-8"))
        revision = str(doc.get("sha") or "")
        if not HEX40.fullmatch(revision):
            raise RuntimeError("huggingface_revision_missing")
        for entry in doc.get("siblings") or []:
            path = str(entry.get("rfilename") or "")
            name = path.rsplit("/", 1)[-1]
            if ("/" in path or not name.lower().startswith(family["prefix"].lower())
                    or not QUANT.search(name)):
                continue
            lfs = entry.get("lfs") or {}
            sha = str(lfs.get("sha256") or "")
            size = lfs.get("size") or entry.get("size")
            if not HEX64.fullmatch(sha) or not isinstance(size, int) or size < 1_000_000:
                continue
            label = family["label"] + " (" + name.split("-")[-1].replace(".gguf", "") + ")"
            if query and not any(query in part.lower() for part in (repo, name, label)):
                continue
            results.append({
                "id": model_id(repo, path), "name": label, "repo": repo, "path": path,
                "revision": revision, "sha256": sha, "size": size,
                "steps": family["steps"], "cfg_scale": family["cfg_scale"],
                "license": "Apache-2.0", "source": "Hugging Face",
            })
    return sorted(results, key=lambda row: (row["repo"], row["name"]))


def registry_for(candidate: dict, default: dict) -> dict:
    """Reuse the pinned compatible FLUX.2 Klein 4B encoder and VAE."""
    reg = copy.deepcopy(default)
    reg["model"].update({
        "id": candidate["id"], "label": candidate["name"],
        "steps": candidate["steps"], "cfg_scale": candidate["cfg_scale"],
        "license": candidate["license"],
    })
    reg["files"] = [
        dict(file, **{
            "repo": candidate["repo"], "path": candidate["path"],
            "revision": candidate["revision"], "sha256": candidate["sha256"],
            "size": candidate["size"],
        }) if file["role"] == "diffusion_model" else file
        for file in reg["files"]
    ]
    if candidate["steps"] > 4:
        reg["sizes"]["normal"]["time_limit_s"] = 1200
        reg["sizes"]["large"]["time_limit_s"] = 2400
    reg["origin"] = {"source": "huggingface", "model_id": candidate["id"]}
    return reg


class VisionCores:
    """State and model management; only the authenticated PHP bridge calls writes."""

    def __init__(self, service, default: dict):
        self.service = service
        self.default = copy.deepcopy(default)
        self.store = service.models / "vision-cores.json"
        self.guard = threading.RLock()
        self.installing = ""
        self.install_error = ""
        self.install_registry = None
        self.records = {}
        self.active = "default"
        self._load()

    def _load(self):
        try:
            raw = json.loads(self.store.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return
        if not isinstance(raw, dict):
            return
        for key, row in (raw.get("models") or {}).items():
            if not isinstance(key, str) or not re.fullmatch(r"[0-9a-f]{24}", key):
                continue
            if not isinstance(row, dict) or not isinstance(row.get("registry"), dict):
                continue
            reg = row["registry"]
            if self.service.registry_errors(reg):
                continue
            if reg.get("origin", {}).get("model_id") != key:
                continue
            self.records[key] = row
        chosen = raw.get("active")
        if chosen in self.records:
            self.active = chosen

    def _save(self):
        data = {"format": 1, "active": self.active, "models": self.records}
        self.store.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.store.with_name("vision-cores.tmp")
        with open(tmp, "w", encoding="utf-8") as handle:
            json.dump(data, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
        os.replace(tmp, self.store)

    def selected_registry(self) -> dict:
        with self.guard:
            return copy.deepcopy(self.default if self.active == "default" else self.records[self.active]["registry"])

    def list(self) -> dict:
        with self.guard:
            rows = [{
                "id": "default", "name": str(self.default["model"]["label"]),
                "source": "Hugging Face", "license": str(self.default["model"].get("license") or ""),
                "installed": True, "protected": True, "active": self.active == "default",
            }]
            for key, row in sorted(self.records.items()):
                reg = row["registry"]
                rows.append({
                    "id": key, "name": reg["model"]["label"], "source": "Hugging Face",
                    "license": reg["model"].get("license", ""),
                    "installed": True, "protected": False, "active": self.active == key,
                })
            progress = self.service.weights_progress(self.install_registry) if self.install_registry else {"have": 0, "total": 0}
            return {"ok": True, "active": self.active, "models": rows,
                    "installing": self.installing, "install_error": self.install_error,
                    "progress": progress}

    def search(self, query: str) -> list[dict]:
        rows = hub_catalog(query)
        with self.guard:
            for row in rows:
                row["installed"] = row["id"] in self.records or (
                    row["repo"] == self.default["files"][0]["repo"]
                    and row["path"] == self.default["files"][0]["path"])
                row["active"] = (row["id"] in self.records and self.active == row["id"])
        return rows

    def install(self, ident: str):
        with self.service.state_lock:
            if self.service.vision_busy or self.service.busy_unlocked():
                raise BlockingIOError("image_busy")
            self.service.vision_busy = True
        with self.guard:
            if self.installing:
                self.service.vision_busy = False
                raise BlockingIOError("install_running")
            self.installing = ident
            self.install_error = ""
        thread = threading.Thread(target=self._install_worker, args=(ident,), daemon=True)
        thread.start()

    def _install_worker(self, ident: str):
        try:
            candidates = hub_catalog()
            choice = next((row for row in candidates if row["id"] == ident), None)
            if choice is None:
                raise ValueError("model_unavailable")
            reg = registry_for(choice, self.default)
            if self.service.registry_errors(reg):
                raise ValueError("invalid_model_registry")
            with self.guard:
                self.install_registry = reg
            with self.service.lock:
                self.service.ensure_weights(reg)
            with self.guard:
                self.records[ident] = {"registry": reg}
                self._save()
        except Exception as exc:
            with self.guard:
                self.install_error = str(exc)[:260]
        finally:
            with self.guard:
                self.installing = ""
                self.install_registry = None
            with self.service.state_lock:
                self.service.vision_busy = False

    def select(self, ident: str):
        with self.guard:
            if ident != "default" and ident not in self.records:
                raise ValueError("model_not_installed")
            if self.installing or self.service.loading or not self.service.ready:
                raise BlockingIOError("image_busy")
            with self.service.state_lock:
                if self.service.vision_busy or self.service.busy_unlocked():
                    raise BlockingIOError("image_busy")
                self.service.vision_busy = True
            try:
                reg = copy.deepcopy(self.default if ident == "default" else self.records[ident]["registry"])
                with self.service.lock:
                    paths = self.service.ensure_weights(reg)
                    self.service.reg = reg
                    self.service.paths = paths
                self.active = ident
                self._save()
            finally:
                with self.service.state_lock:
                    self.service.vision_busy = False

    def uninstall(self, ident: str):
        with self.guard:
            if ident == "default":
                raise ValueError("model_protected")
            if ident == self.active:
                raise ValueError("model_active")
            if ident not in self.records:
                raise ValueError("model_not_installed")
            with self.service.state_lock:
                if self.service.vision_busy or self.service.busy_unlocked():
                    raise BlockingIOError("image_busy")
                self.service.vision_busy = True
            try:
                reg = self.records[ident]["registry"]
                file = next(item for item in reg["files"] if item["role"] == "diffusion_model")
                target = self.service.file_path(self.service.models, file).resolve()
                root = self.service.models.resolve()
                # Never remove the built-in or files shared by another profile.
                preserved = [
                    next(item for item in r["registry"]["files"] if item["role"] == "diffusion_model")
                    for key, r in self.records.items() if key != ident
                ] + [next(item for item in self.default["files"] if item["role"] == "diffusion_model")]
                self.records.pop(ident)
                self._save()
                if target.is_relative_to(root) and not any(
                    self.service.file_path(self.service.models, item).resolve() == target
                    for item in preserved
                ):
                    target.unlink(missing_ok=True)
            finally:
                with self.service.state_lock:
                    self.service.vision_busy = False
