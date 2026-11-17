# -----------------------------------------------------------------------------
# STU - Sandbox-Image fuer Embers Python-Werkzeug (v1.1.1.87)
#
# Bauen (auf dem Server, als root):
#   cd /opt/ember-coreui
#   docker build -t ember-py:2 -f tools/ember_py.Dockerfile tools/
#
# 0.7.79: ember-py:2 bringt zusaetzlich Node.js 24 (LTS) mit npm und npx fuer
# JavaScript-Projekte (Ausfuehren und Prozesse im Tab Dateien). Installer und
# pyworker bauen es selbst; ember-py:1 wird danach entfernt, wenn es frei ist.
#
# Das Image enthaelt bewusst KEINE Zugangsdaten, keine Mounts und keinen Bezug
# zum Spiel. Es ist ein reiner Rechen-/Netz-Werkzeugkasten. Alle Grenzen (RAM,
# CPU, Prozesse, Laufzeit, Rootfs read-only) setzt der Worker beim docker run.
# -----------------------------------------------------------------------------
# Node.js kommt als fertiges offizielles Binary aus dem node-Image (gleiche
# Debian-Linie bookworm wie die glibc des Python-Images oder neuer).
FROM node:24-bookworm-slim AS node

FROM python:3.12-slim

# Nur was Ember realistisch braucht. Wer mehr will: hier ergaenzen und Tag hochzaehlen
# (ember-py:2) statt das laufende Image zu ueberschreiben.
RUN pip install --no-cache-dir \
      numpy \
      pandas \
      requests \
      beautifulsoup4 \
      lxml \
      python-dateutil \
      pytz \
      pillow \
      openpyxl \
      matplotlib \
 && rm -rf /root/.cache

# Kein Bytecode-Muell im read-only Rootfs, Diagramme ohne Display-Server.
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MPLBACKEND=Agg \
    HOME=/tmp

# matplotlib legt beim ersten Start einen Font-Cache an. Ohne Vorwaermen passiert
# das im read-only Container bei jedem Lauf neu in /tmp und kostet Sekunden.
RUN MPLCONFIGDIR=/opt/mplcache python -c "import matplotlib.pyplot" \
 && chmod -R a+rX /opt/mplcache
ENV MPLCONFIGDIR=/opt/mplcache

# Node.js 24 mit npm und npx. Der npm-Cache liegt im Arbeitsordner (/work), nicht
# im tmpfs /tmp: dort ist exec verboten, npx startet aber Programme aus dem Cache.
# Keine Update-, Spenden- oder Audit-Abfragen bei jedem Lauf.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
 && node --version && npm --version && npx --version
ENV NPM_CONFIG_CACHE=/work/.npm-cache \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    NPM_CONFIG_FUND=false \
    NPM_CONFIG_AUDIT=false

# The worker mounts the same isolated job output at /work/output and
# /mnt/data for compatibility with coding/cloud models.
RUN mkdir -p /work /mnt/data

WORKDIR /work
USER 65534:65534
CMD ["python3"]
