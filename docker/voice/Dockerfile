# CoreAI 0.6.1: Mara's voice service (P20.3). Pocket TTS on the CPU at the
# exact versions of the voice test; the weights are not in the image, the
# service fetches them at their registered revisions and checks each SHA-256
# (config/voice/mara-voice.json) into var/voice-models.
FROM python:3.11-slim-bookworm
ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/tmp
COPY docker/tts-spike/constraints-pocket.txt /tmp/constraints.txt
RUN pip install --only-binary=:all: --index-url https://download.pytorch.org/whl/cpu torch==2.6.0 \
 && pip install --only-binary=:all: -c /tmp/constraints.txt pocket-tts pyyaml \
 && rm -f /tmp/constraints.txt
USER 33:33
CMD ["python3", "-I", "/var/www/coreui/tools/voice/voice_service.py"]
