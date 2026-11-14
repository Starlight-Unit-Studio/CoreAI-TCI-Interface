# Ollama Vision weights to CoreAI's CPU image renderer (prototype)

**Status: development only. Not connected to the admin interface or the live
image generator. Do not merge or advertise as a completed Vision Core adapter.**

## The model families are not interchangeable

The Ollama package `feadxus/flux2-klein-9b-uncensored` is listed with a
40K context window and text/GGUF-style Q4_K_M variants. It is **not verified**
as FLUX diffusion weights. A name containing "FLUX" is not a capability test.

Ollama's official `x/flux2-klein` image packages are different: the
experimental Ollama renderer was discontinued in August 2026. Many packages
contain thousands of separate, content-addressed safetensors tensor blobs for
the old MLX runner. The default FP4/FP8 MLX quantized layouts are not plain
GGUF and cannot be passed directly to stable-diffusion.cpp.

## What this prototype does

`tools/image/ollama_weight_bridge.py` parses read-only local Ollama OCI
manifests, rejects untrusted names, symlinks, invalid digests, incorrect tensor
sizes and packed quantized tensors, and reconstructs original **unquantized**
F16/BF16/F32 tensor layers into three standard safetensors files:

- `transformer.safetensors` (FLUX.2 diffusion transformer)
- `text_encoder.safetensors` (Qwen3 companion encoder)
- `vae.safetensors` (FLUX.2 decoder)

The exporter hashes each source blob and the assembled component, and
quarantines results outside Ollama's own store. The supported model identity
is deliberately restricted to `x/flux2-klein:4b-bf16` and
`x/flux2-klein:9b-bf16`. Common FP4 and FP8 variants are **rejected**;
dequantization and format compatibility require separate real-world tests.

The `cpu_render_probe` helper executes a genuine 256 x 256,
four-step image generation using the existing sd-cli and all three assembled
components. A valid PNG is required. Passing this helper has **not** been
demonstrated against real Ollama weights or the Netcup CPU server.

**No implicit activation:** `materialize()` always returns
`activatable=false` and `needs_cpu_render_test=true`. The code does
not modify `VisionCores`, PHP, the host configuration, Ollama, or the
running image worker. The protected standard FLUX generator stays selected.

## Open blockers before production integration

1. Safely identify the actual host Ollama models directory and expose a
   **read-only** path to the image worker; never chmod the entire model store,
   assume the directory is readable or accept a browser-provided path.
2. Run the importer against a genuine **BF16** Ollama image package, including
   real source SHA verification and disk/CPU capacity checks.
3. Verify tensor naming and companion components against the pinned
   stable-diffusion.cpp binary, and complete a real CPU image generation test.
4. Add an asynchronous owner-only import/verification operation with status,
   cancellation and strict model identity checks, reusing the existing global
   serial image queue. Activate only after the render probe passes.
5. Implement automatic rollback on generation errors and preserve provenance
   of actual weights. Keep public guests on the Apache-2.0 4B standard.
6. For **9B**, respect the FLUX Non-Commercial License v2.1, which is stricter
   than CoreAI's own noncommercial distribution policy and excludes direct
   production use with end users. Verify sufficient memory before any test.

The synthetic, isolated regression:
`python3 -I scripts/ollama-weight-bridge-selftest.py`
can run without network or model downloads; it does not certify real
hardware, licensing, compatible weights or successful image generation.

## Background sources

- [Ollama issue documenting discontinued image generation](https://github.com/ollama/ollama/issues/16876)
- [Ollama tensor layer and blob format](https://github.com/ollama/ollama/blob/main/manifest/layer.go)
- [stable-diffusion.cpp FLUX.2 model/encoder combinations](https://github.com/leejet/stable-diffusion.cpp/blob/master/docs/flux2.md)
- [FLUX.2 Klein 9B license](https://huggingface.co/black-forest-labs/FLUX.2-klein-9B/blob/main/LICENSE.md)
