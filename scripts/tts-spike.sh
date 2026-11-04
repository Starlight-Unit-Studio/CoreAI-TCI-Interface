#!/usr/bin/env bash
# CoreAI: TCI Interface 0.5.94: P20.0 Stimmtest auf dem echten Server.
# Vergleicht die Kandidaten fuer Maras Stimme (Piper, Pocket TTS, Chatterbox):
# Echtzeitfaktor bei 2 und 4 Kernen, Ladezeit, Spitzen-RAM, Hoerproben derselben
# Saetze und die Lizenz jeder Stimme. Jede Engine laeuft in ihrem eigenen
# Container; gemessen wird ohne Netz. Aendert keine Cores, kein Modelfile und
# keine Datenbank; die Ergebnisse stehen in Admin Core > Betrieb > Stimmproben.
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
TOOLS="$PROJECT_ROOT/tools/tts_spike"
REGISTRY="$TOOLS/voices.json"
DOCKER_DIR="$PROJECT_ROOT/docker/tts-spike"
BASE="$PROJECT_ROOT/var/tts-spike"
MODELS="$BASE/models"
RUNS="$BASE/runs"
STAMP="$(date +%Y%m%d-%H%M%S)"
IMAGE_TAG='0.5.94'  # follows docker/tts-spike, not the app version: 0.5.95 reuses the images
VERSION_TAG="coreai-tts-spike"

ENGINES='piper,pocket,chatterbox'
GROUP_FILTER=''
CORES='2,4'
SKIP_BUILD=''
REFETCH=''
DRY_RUN=''
KEEP='5'
RUN_STAMP=''
TIME_LIMIT='3600'
MEMORY='24g'

usage() {
  cat <<'EOF'
CoreAI P20.0 Stimmtest (Kandidaten fuer Maras Stimme)

  sudo bash scripts/tts-spike.sh [Optionen]

Optionen:
  --engines LISTE   Engines, Standard: piper,pocket,chatterbox
  --groups LISTE    Nur diese Gruppen aus tools/tts_spike/voices.json (z. B. pocket-de,chatterbox)
  --cores LISTE     Kernzahlen, Standard: 2,4. Alle Stimmen sprechen mit 4 Kernen (Hoerproben),
                    bei den anderen Kernzahlen misst je Gruppe die erste Stimme.
  --skip-build      Container nicht neu bauen (nur wenn sie schon existieren)
  --refetch         Gewichte neu laden und neu registrieren, auch wenn sie schon da sind
  --keep N          So viele Testlaeufe behalten (Standard: 5)
  --run ZEITSTEMPEL In einen vorhandenen Lauf schreiben, z. B. um Gruppen nachzuholen:
                    --groups pocket-de,pocket-de24 --run 20261104-141500
                    Der Bericht wird danach aus allen Ergebnissen des Laufs neu erstellt.
  --dry-run         Nur zeigen, was passieren wuerde
  -h, --help        Diese Hilfe

Ablauf:
  1. Container bauen (je Engine einer, Versionen aus docker/tts-spike/constraints-*.txt).
  2. Gewichte laden (mit Netz) nach var/tts-spike/models; jede Datei wird mit Herkunft,
     Revision und SHA-256 in lock-GRUPPE.json registriert.
  3. Messen ohne Netz, mit festem Kernlimit; vorher wird jede Datei gegen ihren SHA-256 geprueft.
  4. Bericht: var/tts-spike/runs/ZEITSTEMPEL/report.json und report.md.
     Anhoeren: Admin Core > Betrieb > Stimmproben (nur Inhaber).

Dauer: beim ersten Mal 20 bis 40 Minuten (etwa 4 GB Gewichte und 5 GB Container),
danach etwa 15 Minuten. Bitte starten, wenn Mara gerade nichts Grosses rechnet.
EOF
}

while (($#)); do
  case "$1" in
    --engines) ENGINES="${2:-}"; shift 2 ;;
    --groups) GROUP_FILTER="${2:-}"; shift 2 ;;
    --cores) CORES="${2:-}"; shift 2 ;;
    --skip-build) SKIP_BUILD='1'; shift ;;
    --refetch) REFETCH='1'; shift ;;
    --keep) KEEP="${2:-}"; shift 2 ;;
    --run) RUN_STAMP="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN='1'; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unbekannte Option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

log() { printf '[CoreAI Stimmtest] %s\n' "$*"; }
die() { printf '[CoreAI Stimmtest] FEHLER: %s\n' "$*" >&2; exit 1; }

[[ "$ENGINES" =~ ^[a-z,]+$ ]] || die 'Ungueltige --engines'
[[ -z "$GROUP_FILTER" || "$GROUP_FILTER" =~ ^[a-z0-9_,-]+$ ]] || die 'Ungueltige --groups'
[[ "$CORES" =~ ^[0-9]+(,[0-9]+)*$ ]] || die 'Ungueltige --cores'
[[ "$KEEP" =~ ^[0-9]+$ ]] && (( KEEP >= 1 )) || die 'Ungueltiges --keep'
[[ -z "$RUN_STAMP" || "$RUN_STAMP" =~ ^[0-9]{8}-[0-9]{6}$ ]] || die 'Ungueltiges --run (Form JJJJMMTT-HHMMSS)'
command -v python3 >/dev/null 2>&1 || die 'python3 fehlt auf dem Server'
[[ -f "$REGISTRY" ]] || die 'tools/tts_spike/voices.json fehlt'
if [[ -z "$DRY_RUN" ]]; then
  command -v docker >/dev/null 2>&1 || die 'docker fehlt'
  (( EUID == 0 )) || die 'Bitte als root starten (sudo), die Hoerproben gehoeren root:33.'
fi

run() {
  if [[ -n "$DRY_RUN" ]]; then printf '+'; printf ' %q' "$@"; printf '\n'; return 0; fi
  "$@"
}

# Groups to test: "group engine" lines, in registry order.
mapfile -t SELECTED < <(python3 -I - "$REGISTRY" "$ENGINES" "$GROUP_FILTER" <<'PY'
import json, sys
reg = json.load(open(sys.argv[1], encoding='utf-8'))
engines = set(filter(None, sys.argv[2].split(',')))
groups = set(filter(None, sys.argv[3].split(',')))
for gid, group in reg['groups'].items():
    if group['engine'] in engines and (not groups or gid in groups):
        print(gid + ' ' + group['engine'])
PY
)
((${#SELECTED[@]})) || die 'Keine Gruppe ausgewaehlt (--engines oder --groups pruefen).'
SAMPLE_CORES="$(python3 -I -c 'import json,sys; print(int(json.load(open(sys.argv[1]))["sample_cores"]))' "$REGISTRY")"
IFS=',' read -r -a CORE_LIST <<< "$CORES"
NPROC="$(nproc 2>/dev/null || echo 4)"
for cores in "${CORE_LIST[@]}"; do
  (( cores >= 1 && cores <= NPROC )) || die "Kernzahl $cores passt nicht zu $NPROC Kernen"
done
# All voices speak at the sample core count; when it is not in the list, at the largest one.
if [[ ",$CORES," != *",$SAMPLE_CORES,"* ]]; then
  SAMPLE_CORES="$(printf '%s\n' "${CORE_LIST[@]}" | sort -n | tail -n 1)"
fi
ENGINES_NEEDED=()
declare -A ENGINE_SEEN=()
for line in "${SELECTED[@]}"; do
  engine="${line#* }"
  [[ -n "${ENGINE_SEEN[$engine]:-}" ]] && continue
  ENGINE_SEEN[$engine]=1
  ENGINES_NEEDED+=("$engine")
done

OUT="$RUNS/$STAMP"
if [[ -n "$RUN_STAMP" ]]; then
  OUT="$RUNS/$RUN_STAMP"
  [[ -n "$DRY_RUN" || -d "$OUT" ]] || die "Lauf $RUN_STAMP nicht gefunden unter var/tts-spike/runs"
  STAMP="$RUN_STAMP"
fi
if [[ -z "$DRY_RUN" ]]; then
  install -d -m 0750 -o root -g 33 "$BASE" "$RUNS"
  install -d -m 0700 -o root -g root "$MODELS"
  [[ -d "$OUT" ]] || install -d -m 0750 -o root -g 33 "$OUT"
  free_gb="$(df -Pk "$BASE" | awk 'NR==2 {printf "%d", $4/1048576}')"
  (( free_gb >= 15 )) || log "Hinweis: nur ${free_gb} GB frei; Gewichte und Container brauchen beim ersten Mal etwa 10 GB."
  exec > >(tee -a "$OUT/run.log") 2>&1
fi
log "Lauf $STAMP: ${#SELECTED[@]} Gruppen, Kerne $CORES, Hoerproben mit $SAMPLE_CORES Kernen"

# --- Server ----------------------------------------------------------------
if [[ -z "$DRY_RUN" && ! -f "$OUT/host.txt" ]]; then
  {
    printf 'Zeit: %s\n' "$(date --iso-8601=seconds 2>/dev/null || date)"
    if command -v lscpu >/dev/null 2>&1; then
      lscpu | grep -E '^(Model name|CPU\(s\)|Thread\(s\) per core|Core\(s\) per socket|Socket\(s\)):' || true
      printf 'AVX-512: %s\n' "$(grep -o -w -E 'avx512[a-z_0-9]*' /proc/cpuinfo 2>/dev/null | sort -u | tr '\n' ' ' || true)"
    fi
    command -v free >/dev/null 2>&1 && free -h | head -2
    printf 'Docker: %s\n' "$(docker --version 2>/dev/null || true)"
  } > "$OUT/host.txt" 2>&1 || true
fi

# --- 1. Container ----------------------------------------------------------
image_of() { printf '%s-%s:%s' "$VERSION_TAG" "$1" "$IMAGE_TAG"; }
for engine in "${ENGINES_NEEDED[@]}"; do
  image="$(image_of "$engine")"
  if [[ -n "$SKIP_BUILD" ]]; then
    [[ -n "$DRY_RUN" ]] || docker image inspect "$image" >/dev/null 2>&1 || die "Container $image fehlt; ohne --skip-build starten."
    continue
  fi
  log "Baue Container $image ..."
  run docker build --pull --target "$engine" -t "$image" -f "$DOCKER_DIR/Dockerfile" "$DOCKER_DIR"
done

SAFE=(--cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp:rw,exec,size=4g
      -e HOME=/tmp -e HF_HOME=/models/hf -v "$TOOLS:/app:ro")

# --- 2. Gewichte laden und registrieren (mit Netz) -----------------------------
for line in "${SELECTED[@]}"; do
  group="${line%% *}"; engine="${line#* }"
  if [[ -z "$REFETCH" && -f "$MODELS/lock-$group.json" ]]; then
    log "Gewichte fuer $group schon registriert (--refetch laedt neu)."
    continue
  fi
  log "Lade Gewichte fuer $group ..."
  if ! run timeout --foreground "$TIME_LIMIT" docker run --rm --name "coreai-tts-fetch-$group" "${SAFE[@]}" \
      -v "$MODELS:/models" "$(image_of "$engine")" python3 -I /app/run.py fetch --group "$group" --models /models; then
    docker rm -f "coreai-tts-fetch-$group" >/dev/null 2>&1 || true
    log "  Laden fehlgeschlagen; $group wird ohne Gewichte gemessen und meldet den Fehler im Bericht."
  fi
done

# --- 3. Messen ohne Netz -------------------------------------------------------
for line in "${SELECTED[@]}"; do
  group="${line%% *}"; engine="${line#* }"
  for cores in $(printf '%s\n' "${CORE_LIST[@]}" | sort -rn); do
    first=()
    [[ "$cores" == "$SAMPLE_CORES" ]] || first=(--first-only)
    cpus="$((NPROC - cores))-$((NPROC - 1))"
    log "Messe $group mit $cores Kernen (CPUs $cpus) ..."
    if ! run timeout --foreground "$TIME_LIMIT" docker run --rm --name "coreai-tts-run-$group-$cores" "${SAFE[@]}" \
        --network none --cpuset-cpus "$cpus" --memory "$MEMORY" --pids-limit 1024 \
        -e HF_HUB_OFFLINE=1 -e OMP_NUM_THREADS="$cores" -e MKL_NUM_THREADS="$cores" \
        -v "$MODELS:/models:ro" -v "$OUT:/out" \
        "$(image_of "$engine")" python3 -I /app/run.py run --group "$group" --models /models --out /out \
        --cores "$cores" "${first[@]}"; then
      docker rm -f "coreai-tts-run-$group-$cores" >/dev/null 2>&1 || true
      log "  $group mit $cores Kernen meldet einen Fehler (siehe Bericht)."
    fi
  done
done

# --- 4. Bericht ----------------------------------------------------------------
run python3 -I "$TOOLS/run.py" report --run-dir "$OUT"
if [[ -z "$DRY_RUN" ]]; then
  chown -R root:33 "$OUT"
  find "$OUT" -type d -exec chmod 0750 {} +
  find "$OUT" -type f -exec chmod 0640 {} +
  # Keep the newest runs; only folders named like a time stamp are touched.
  mapfile -t old < <(find "$RUNS" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended \
    -regex '.*/[0-9]{8}-[0-9]{6}' -printf '%f\n' | sort -r | tail -n +"$((KEEP + 1))")
  for stamp in "${old[@]}"; do
    [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}$ ]] && rm -rf -- "${RUNS:?}/$stamp"
  done
fi
log "Bericht: $OUT/report.md"
log "Anhoeren: Admin Core > Betrieb > Stimmproben (nur Inhaber)."
