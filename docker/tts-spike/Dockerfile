# CoreAI 0.5.94: one image per voice engine for the voice test (P20.0).
# Built by scripts/tts-spike.sh with --target piper|pocket|chatterbox and this
# folder as the build context. The images carry only the engine, at the exact
# versions of constraints-*.txt; the weights come from var/tts-spike/models and
# are registered there with origin, revision and SHA-256 (tools/tts_spike).
FROM python:3.11-slim-bookworm AS base
ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/tmp \
    NUMBA_CACHE_DIR=/tmp/numba \
    MPLCONFIGDIR=/tmp/matplotlib
LABEL org.coreai.purpose="tts-spike"

# Piper: ONNX on the CPU, espeak-ng is part of the wheel.
FROM base AS piper
COPY constraints-piper.txt /tmp/constraints.txt
RUN pip install --only-binary=:all: -c /tmp/constraints.txt piper-tts huggingface-hub

# Pocket TTS: torch on the CPU only (no CUDA libraries).
FROM base AS pocket
COPY constraints-pocket.txt /tmp/constraints.txt
RUN pip install --only-binary=:all: --index-url https://download.pytorch.org/whl/cpu torch==2.6.0 \
 && pip install --only-binary=:all: -c /tmp/constraints.txt pocket-tts pyyaml soundfile

# Chatterbox Multilingual: pins torch 2.6.0 itself; torch comes from the CPU index first.
FROM base AS chatterbox
COPY constraints-chatterbox.txt /tmp/constraints.txt
RUN pip install --only-binary=:all: --index-url https://download.pytorch.org/whl/cpu torch==2.6.0 torchaudio==2.6.0 \
 && pip install --only-binary=:all: -c /tmp/constraints.txt chatterbox-tts soundfile
