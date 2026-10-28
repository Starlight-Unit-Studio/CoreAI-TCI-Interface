#!/usr/bin/env bash
# CoreAI: TCI Interface 0.5.73: P20.0 Medien-Test auf dem echten Server.
# Misst Hoeren ueber Gemma 4 (Ollama), optional Sprachausgabe-Kandidaten und
# Bildgenerierung. Aendert keine Modelle, kein Modelfile und keine Datenbank;
# jeder Core-Aufruf reiht sich in Maras serielle Warteschlange ein.
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
STAMP="$(date +%Y%m%d-%H%M%S)"
REL_OUT="var/cache/media-spike/$STAMP"
OUT="$PROJECT_ROOT/$REL_OUT"

AUDIO=''
MODEL=''
DURATIONS='5,15,30,45,60'
SKIP_FORMATS=''
TTS_CMDS=()
TTS_THREADS='2,4'
IMAGE_CMD=''
IMAGE_THREADS='20'

usage() {
  cat <<'EOF'
CoreAI P20.0 Medien-Test

  bash scripts/media-spike.sh [Optionen]

Optionen:
  --audio DATEI          Eigene Sprachaufnahme (beliebiges Format, z. B. vom Handy).
                         Ohne Aufnahme und ohne Stimme gibt es nur einen Formattest mit Sinuston.
  --model NAME           Core fuer den Test (Standard: Mara-Core aus der CoreAI-Konfiguration).
  --durations LISTE      Laengen in Sekunden (Standard: 5,15,30,45,60).
  --skip-formats         Falsche Formate (24 Bit, Float, 44,1 kHz Stereo, rohes PCM) nicht testen.
  --tts NAME=BEFEHL      Sprachausgabe-Kandidat, mehrfach erlaubt. {text} und {out} werden ersetzt.
                         Beispiel Piper (Version und Stimme selbst pruefen):
                         --tts "piper-thorsten=python3 -m piper -m de_DE-thorsten-high -f {out} -- {text}"
  --tts-threads LISTE    Kernzahlen fuer die Sprachausgabe (Standard: 2,4).
  --image BEFEHL         Bildbefehl mit {out} und {threads}, z. B. stable-diffusion.cpp.
  --image-threads LISTE  Kernzahlen fuer die Bildgenerierung (Standard: 20).
  -h, --help             Diese Hilfe.

Ergebnis: var/cache/media-spike/ZEITSTEMPEL/report.md
Bitte starten, wenn Mara gerade nichts Grosses baut; die Core-Aufrufe warten sonst in der Warteschlange.
EOF
}

while (($#)); do
  case "$1" in
    --audio) AUDIO="${2:-}"; shift 2 ;;
    --model) MODEL="${2:-}"; shift 2 ;;
    --durations) DURATIONS="${2:-}"; shift 2 ;;
    --skip-formats) SKIP_FORMATS='1'; shift ;;
    --tts) TTS_CMDS+=("${2:-}"); shift 2 ;;
    --tts-threads) TTS_THREADS="${2:-}"; shift 2 ;;
    --image) IMAGE_CMD="${2:-}"; shift 2 ;;
    --image-threads) IMAGE_THREADS="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unbekannte Option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

log() { printf '[CoreAI P20.0] %s\n' "$*"; }
mkdir -p "$OUT/input" "$OUT/tts" "$OUT/image"
if [[ ! -x /usr/bin/time ]] && { ((${#TTS_CMDS[@]})) || [[ -n "$IMAGE_CMD" ]]; }; then
  log 'Hinweis: /usr/bin/time fehlt, der RAM-Bedarf wird nicht gemessen (Paket "time" installiert ihn).'
fi

# --- Server --------------------------------------------------------------
{
  printf 'Zeit: %s\n' "$(date --iso-8601=seconds 2>/dev/null || date)"
  if command -v lscpu >/dev/null 2>&1; then
    lscpu | grep -E '^(Model name|CPU\(s\)|Thread\(s\) per core|Core\(s\) per socket|Socket\(s\)|NUMA node\(s\)):' || true
    printf 'AVX-512: %s\n' "$(grep -o -w -E 'avx512[a-z_0-9]*' /proc/cpuinfo 2>/dev/null | sort -u | tr '\n' ' ' || true)"
  fi
  command -v free >/dev/null 2>&1 && free -h | head -2
  command -v ollama >/dev/null 2>&1 && ollama --version 2>/dev/null | head -1
} > "$OUT/host.txt" 2>&1 || true
log "Serverdaten: $OUT/host.txt"

if [[ -n "$AUDIO" ]]; then
  [[ -f "$AUDIO" ]] || { printf 'Aufnahme nicht gefunden: %s\n' "$AUDIO" >&2; exit 1; }
  ext="${AUDIO##*.}"
  cp -- "$AUDIO" "$OUT/input/user-audio.${ext,,}"
  log "Eigene Aufnahme uebernommen: $(basename -- "$AUDIO")"
fi

# --- Timing helper: wall seconds and peak RSS in kB ----------------------
run_timed() { # $1 cores, $2 command; prints "wall|rss|exit" (empty fields stay in place)
  local cores="$1" cmd="$2" tfile rc=0 cpus=''
  tfile="$(mktemp)"
  if command -v taskset >/dev/null 2>&1 && (( cores > 0 )); then cpus="0-$((cores - 1))"; fi
  if [[ -x /usr/bin/time ]]; then
    if [[ -n "$cpus" ]]; then
      OMP_NUM_THREADS="$cores" /usr/bin/time -f '%e %M' -o "$tfile" taskset -c "$cpus" bash -c "$cmd" >/dev/null 2>&1 || rc=$?
    else
      OMP_NUM_THREADS="$cores" /usr/bin/time -f '%e %M' -o "$tfile" bash -c "$cmd" >/dev/null 2>&1 || rc=$?
    fi
    IFS=' ' read -r wall rss < <(tail -n 1 "$tfile")
  else
    local start end
    start="$(date +%s.%N)"
    OMP_NUM_THREADS="$cores" bash -c "$cmd" >/dev/null 2>&1 || rc=$?
    end="$(date +%s.%N)"
    wall="$(awk -v a="$start" -v b="$end" 'BEGIN{printf "%.2f", b-a}')"; rss=''
  fi
  rm -f "$tfile"
  printf '%s|%s|%s\n' "${wall:-}" "${rss:-}" "$rc"
}

quote() { printf "'%s'" "${1//\'/\'\\\'\'}"; }

# --- Sprachausgabe (TTS) -------------------------------------------------
SENTENCES=(
  'Hallo, ich bin Mara.'
  'Die Ravager Thorn meldet volle Einsatzbereitschaft, alle Systeme laufen stabil und der Kurs ist gesetzt.'
  'Am 28. Oktober 2026 um 14:30 Uhr habe ich drei Dateien erzeugt: index.html, style.css und script.js. Das Projekt ist 15,2 Kilobyte gross.'
)
if ((${#TTS_CMDS[@]})); then
  IFS=',' read -r -a tts_threads <<< "$TTS_THREADS"
  for spec in "${TTS_CMDS[@]}"; do
    name="${spec%%=*}"; template="${spec#*=}"
    [[ -n "$name" && "$template" != "$spec" ]] || { printf 'Ungueltiges --tts: %s (Form NAME=BEFEHL)\n' "$spec" >&2; exit 2; }
    for cores in "${tts_threads[@]}"; do
      for i in "${!SENTENCES[@]}"; do
        file="$OUT/tts/${name}-${cores}c-s$((i + 1)).wav"
        cmd="${template//\{text\}/$(quote "${SENTENCES[$i]}")}"
        cmd="${cmd//\{out\}/$(quote "$file")}"
        log "Stimme $name, $cores Kerne, Satz $((i + 1)) ..."
        IFS='|' read -r wall rss rc < <(run_timed "$cores" "$cmd")
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$name" "$cores" "s$((i + 1))" "$wall" "$rss" \
          "/var/www/coreui/$REL_OUT/tts/$(basename -- "$file")" >> "$OUT/tts/timings.tsv"
        [[ "$rc" == '0' ]] || log "  Exitcode $rc (Befehl pruefen)"
      done
    done
  done
  # Without an own recording, Core hears the first voice: a full round trip.
  first="$(ls "$OUT"/tts/*-s2.wav 2>/dev/null | head -n 1 || true)"
  if [[ -z "$AUDIO" && -n "$first" ]]; then cp -- "$first" "$OUT/input/tts-sample.wav"; fi
fi

# --- Bildgenerierung -----------------------------------------------------
if [[ -n "$IMAGE_CMD" ]]; then
  IFS=',' read -r -a image_threads <<< "$IMAGE_THREADS"
  for cores in "${image_threads[@]}"; do
    file="$OUT/image/bild-${cores}c.png"
    cmd="${IMAGE_CMD//\{out\}/$(quote "$file")}"
    cmd="${cmd//\{threads\}/$cores}"
    log "Bild mit $cores Kernen ..."
    IFS='|' read -r wall rss rc < <(run_timed "$cores" "$cmd")
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "bild-${cores}c" "$cores" "$wall" "$rss" \
      "/var/www/coreui/$REL_OUT/image/$(basename -- "$file")" "$rc" >> "$OUT/image/timings.tsv"
  done
fi

# --- Hoeren ueber Core (im PHP-Container oder nativ) ---------------------
php_args=(--out "$REL_OUT" --durations "$DURATIONS")
[[ -n "$MODEL" ]] && php_args+=(--model "$MODEL")
[[ -n "$SKIP_FORMATS" ]] && php_args+=(--skip-formats)
if [[ -n "$AUDIO" ]]; then
  php_args+=(--audio "$REL_OUT/input/$(ls "$OUT/input" | grep '^user-audio' | head -n 1)")
fi

if [[ -f "$ENV_FILE" ]] && command -v docker >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then COMPOSE=(docker compose); else COMPOSE=(docker-compose); fi
  # Container paths: the timing files name /var/www/coreui/...; native runs map them back.
  "${COMPOSE[@]}" --project-name ember-coreui --env-file "$ENV_FILE" --file "$COMPOSE_FILE" \
    exec -T php php scripts/media-spike.php "${php_args[@]}"
else
  for tsv in "$OUT/tts/timings.tsv" "$OUT/image/timings.tsv"; do
    [[ -f "$tsv" ]] && sed -i "s#/var/www/coreui/#$PROJECT_ROOT/#g" "$tsv"
  done
  (cd "$PROJECT_ROOT" && php scripts/media-spike.php "${php_args[@]}")
fi

log "Bericht: $OUT/report.md"
log "Bitte report.md schicken. Die WAV-Dateien liegen daneben, falls du sie anhoeren willst."
