#!/usr/bin/env bash
set -u
IFS=$'\n\t'

# Rein lesender Bericht fuer den gemeinsamen Ollama-Dienst. Das Skript aendert
# weder systemd noch Modelle oder den laufenden CoreAI: TCI Interface-Stack.

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ENV_FILE="$PROJECT_ROOT/var/compose.env"

printf 'CoreAI: TCI Interface Ollama-Parallelbericht\n'
printf 'Zeit: %s\n' "$(date --iso-8601=seconds 2>/dev/null || date)"

printf '\nArbeitsspeicher:\n'
if command -v free >/dev/null 2>&1; then
  free -h
else
  printf 'free is not available.\n'
fi

printf '\nGPU und VRAM:\n'
if command -v nvidia-smi >/dev/null 2>&1; then
  nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free \
    --format=csv,noheader 2>/dev/null || nvidia-smi 2>/dev/null || true
else
  printf 'No NVIDIA-SMI interface found.\n'
fi

printf '\nOllama-Dienstvariablen:\n'
if command -v systemctl >/dev/null 2>&1; then
  service_env=''
  if service_env="$(systemctl show ollama.service --property=Environment --no-pager 2>/dev/null)" \
      && [[ "$service_env" == Environment=* ]]; then
    printf '%s\n' "${service_env:-Environment ist leer.}"
  else
    printf 'systemd could not find ollama.service.\n'
  fi
else
  printf 'systemctl is not available.\n'
fi

printf '\nGeladene Ollama-Modelle:\n'
if command -v ollama >/dev/null 2>&1; then
  ollama ps 2>/dev/null || printf 'Unable to run ollama ps.\n'
else
  printf 'ollama is not in PATH.\n'
fi

printf '\nInstallierte Ollama-Modelle:\n'
if command -v ollama >/dev/null 2>&1; then
  ollama list 2>/dev/null || printf 'Unable to run ollama list.\n'
fi

printf '\nCoreUI-Modell:\n'
if [[ -r "$ENV_FILE" ]]; then
  coreui_model="$(sed -n 's/^COREUI_MODEL_NAME=//p' "$ENV_FILE" | tail -n 1)"
  printf '%s\n' "${coreui_model:-not configured in var/compose.env}"
else
  printf 'var/compose.env is not readable.\n'
fi

printf '\nHinweis:\n'
printf '%s\n' 'For two distinct model names, OLLAMA_MAX_LOADED_MODELS=2 is the relevant upper limit.'
printf '%s\n' 'OLLAMA_NUM_PARALLEL=1 vermeidet zusaetzliche Kontextkopien je Modell.'
printf '%s\n' 'Bei zu wenig RAM oder VRAM reiht Ollama Anforderungen in die Warteschlange ein.'
