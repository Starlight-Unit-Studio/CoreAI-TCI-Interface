#!/usr/bin/env python3
"""Regression guard: remove only Vision model management, preserve image generation."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

removed = (
    "tools/image/vision_cores.py",
    "api/vision_cores.php",
    "js/coreui-vision-library.js",
    "js/coreui-vision-ollama.js",
    "scripts/vision-nginx-route-selftest.py",
    "scripts/vision-ollama-ui-selftest.cjs",
    ".github/workflows/vision-core-image-service.yml",
)
for path in removed:
    assert not (ROOT / path).exists(), f"Vision management file still present: {path}"

app = source("app.html")
for marker in ("coreInstallVisionOpen", "visionLibrary", "coreui-vision-library.js",
               "coreui-vision-ollama.js", "VISION CORE-BIBLIOTHEK"):
    assert marker not in app, f"Unexpected Vision manager UI: {marker}"

assert 'id="coreInstallLibraryOpen"' in app, "Normal Ollama Core manager button removed"
assert 'id="coreInstall"' in app, "TCI Core installer removed"

css = source("css/console.css")
assert ".vision-library" not in css and ".vision-library-ollama-detail" not in css, "Vision CSS remains"

for path in ("config/nginx.conf.template", "config/runtime/nginx.conf.template"):
    assert "vision_cores" not in source(path), f"Deleted PHP API is still allowed in {path}"

for path in ("scripts/install.sh", "scripts/preflight.sh", "scripts/preflight-native.sh"):
    text = source(path)
    assert "vision_route_status" not in text and "vision-nginx-route-selftest" not in text, f"Outdated Vision check remains: {path}"
    assert "vision-ollama-ui-selftest" not in text and "tools/image/vision_cores.py" not in text, f"Outdated Vision test remains: {path}"

worker = source("tools/image/image_service.py")
assert "VisionCores" not in worker and "vision_busy" not in worker
assert "def create(" in worker and "def command(" in worker and "def do_POST(" in worker
assert "--diffusion-model" in worker and "--llm" in worker and "--vae" in worker
assert "stable-diffusion.cpp" in worker, "Original image service unexpectedly removed"

api = source("api/image_gen.php")
assert "function coreui_image_generate(" in api, "Image generation PHP entrypoint missing"
assert "function coreui_image_http(" in api, "Image worker HTTP bridge missing"
assert "model_registry" not in api and "model_label" not in api, "Alternate model selection still wired"

assert "function coreui_library_search(" in source("api/ollama_library.php"), "Normal Ollama Core library lost"
assert "ollama_library_install" in source("api/user_settings.php"), "Normal Ollama installer lost"

assert "flux2-klein-4b" in source("config/image/mara-image.json"), "Protected standard FLUX profile missing"
assert "dockerfile: docker/image/Dockerfile" in source("compose.yaml"), "Image service container missing"

print("PASS: Vision management removed; normal Ollama Core library and pinned image generator retained")
