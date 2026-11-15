#!/usr/bin/env bash
# CoreAI: TCI Interface 0.6.37: P20.5 A, Bildtest auf dem echten Server.
# Baut stable-diffusion.cpp fuer genau diese CPU (Commit aus tools/image_spike/models.json),
# laedt die Gewichte von Z-Image-Turbo und FLUX.2-klein 4B (Apache 2.0) mit Revision und
# SHA-256 und erzeugt Testbilder mit festen Prompts und Seeds. Gemessen werden Laden,
# Textverstaendnis, Rechenschritte, Dekodieren, die ganze Zeit je Bild und der hoechste
# Arbeitsspeicher. Gemessen wird ohne Netz. Aendert keine Cores, kein Modelfile und keine
# Datenbank; die Bilder stehen in Admin Core > Betrieb > Bildproben (nur Inhaber).
# 0.7.51: FLUX.2-klein 9B als zuschaltbare Gruppe (FLUX Non-Commercial License v2.1): laeuft nur mit
# --groups flux2-klein-9b und --accept-license flux-non-commercial. Prompt p4 prueft Gesichter nach
# Maras Referenzbild (images/mara_references, nur lesend eingebunden).
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
TOOLS="$PROJECT_ROOT/tools/image_spike"
REGISTRY="$TOOLS/models.json"
DOCKER_DIR="$PROJECT_ROOT/docker/image-spike"
BASE="$PROJECT_ROOT/var/image-spike"
MODELS="$BASE/models"
RUNS="$BASE/runs"
STAMP="$(date +%Y%m%d-%H%M%S)"
IMAGE='coreai-image-spike:0.6.37'  # follows docker/image-spike, not the app version

GROUP_FILTER=''
THREADS=''
FULL=''
SKIP_BUILD=''
REFETCH=''
DRY_RUN=''
KEEP='5'
TIME_LIMIT='14400'
MEMORY='48g'
ACCEPTED=''
REFS="$PROJECT_ROOT/images/mara_references"

usage() {
  cat <<'EOF'
CoreAI P20.5 A Bildtest (Bildgenerierung auf der CPU)

  sudo bash scripts/image-spike.sh [Optionen]

Optionen:
  --groups LISTE    Nur diese Gruppen aus tools/image_spike/models.json
                    (zimage-turbo, flux2-klein-4b; Standard: beide).
                    Zuschaltbar, nie von allein: flux2-klein-9b (etwa 19 GB Gewichte).
  --accept-license K  Noetig fuer flux2-klein-9b: --accept-license flux-non-commercial.
                    Damit bestaetigst du, dass du die FLUX Non-Commercial License v2.1 gelesen
                    hast und einhaeltst: nur private, nicht-kommerzielle Nutzung, nicht fuer
                    andere Nutzer des Servers.
  --threads N       Rechenthreads (Standard: alle Kerne des Servers)
  --full            Alle Prompts auch in 1024x1024 (Standard: alle in 512x512, einer in 1024x1024)
  --skip-build      Container nicht neu bauen (nur wenn er schon existiert)
  --refetch         Gewichte neu laden und neu registrieren
  --keep N          So viele Testlaeufe behalten (Standard: 5)
  --dry-run         Nur zeigen, was passieren wuerde
  -h, --help        Diese Hilfe

Ablauf:
  1. Container bauen: stable-diffusion.cpp fuer diese CPU (beim ersten Mal 10 bis 20 Minuten).
  2. Gewichte laden (mit Netz, etwa 20 GB) nach var/image-spike/models; jede Datei wird mit
     Repository, Revision und SHA-256 in lock-GRUPPE.json registriert und mit dem SHA-256 von
     Hugging Face verglichen.
  3. Bilder erzeugen ohne Netz; vorher wird jede Datei gegen ihren SHA-256 geprueft.
  4. Bericht: var/image-spike/runs/ZEITSTEMPEL/report.json und report.md.
     Ansehen: Admin Core > Betrieb > Bildproben (nur Inhaber).

Bitte starten, wenn Mara gerade nichts Grosses rechnet: der Test nutzt alle Kerne und bis zu
etwa 20 GB Arbeitsspeicher, solange er laeuft.
EOF
}

while (($#)); do
  case "$1" in
    --groups) GROUP_FILTER="${2:-}"; shift 2 ;;
    --accept-license) ACCEPTED="${2:-}"; shift 2 ;;
    --threads) THREADS="${2:-}"; shift 2 ;;
    --full) FULL='1'; shift ;;
    --skip-build) SKIP_BUILD='1'; shift ;;
    --refetch) REFETCH='1'; shift ;;
    --keep) KEEP="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN='1'; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unbekannte Option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

log() { printf '[CoreAI Bildtest] %s\n' "$*"; }
die() { printf '[CoreAI Bildtest] FEHLER: %s\n' "$*" >&2; exit 1; }

[[ -z "$GROUP_FILTER" || "$GROUP_FILTER" =~ ^[a-z0-9_,-]+$ ]] || die 'Ungueltige --groups'
[[ -z "$ACCEPTED" || "$ACCEPTED" =~ ^[a-z0-9,-]+$ ]] || die 'Ungueltiges --accept-license'
[[ -z "$THREADS" || "$THREADS" =~ ^[0-9]+$ ]] || die 'Ungueltige --threads'
[[ "$KEEP" =~ ^[0-9]+$ ]] && (( KEEP >= 1 )) || die 'Ungueltiges --keep'
command -v python3 >/dev/null 2>&1 || die 'python3 fehlt auf dem Server'
[[ -f "$REGISTRY" ]] || die 'tools/image_spike/models.json fehlt'
python3 -I "$TOOLS/run.py" validate >/dev/null || die 'tools/image_spike/models.json ist ungueltig'
# Only a dry run may use another folder (the selftest gives it an empty one).
if [[ -n "$DRY_RUN" && -n "${COREAI_IMAGE_SPIKE_BASE:-}" ]]; then
  BASE="$COREAI_IMAGE_SPIKE_BASE"
  MODELS="$BASE/models"
  RUNS="$BASE/runs"
fi
if [[ -z "$DRY_RUN" ]]; then
  command -v docker >/dev/null 2>&1 || die 'docker fehlt'
  (( EUID == 0 )) || die 'Bitte als root starten (sudo), die Bilder gehoeren root:33.'
fi

run() {
  if [[ -n "$DRY_RUN" ]]; then printf '+'; printf ' %q' "$@"; printf '\n'; return 0; fi
  "$@"
}

# 0.7.51: without --groups only the groups that are not optional; a group whose licence has to be
# accepted (FLUX.2-klein 9B) stops here, before anything is built or loaded, unless --accept-license names it.
SELECTED=()
WEIGHT_BYTES=0
while IFS=$'\t' read -r gid kind accept bytes license license_url license_note; do
  [[ -n "$gid" ]] || continue
  if [[ -n "$GROUP_FILTER" ]]; then
    [[ ",$GROUP_FILTER," == *",$gid,"* ]] || continue
  else
    [[ "$kind" == 'default' ]] || continue
  fi
  if [[ "$accept" != '-' && ",$ACCEPTED," != *",$accept,"* ]]; then
    printf '[CoreAI Bildtest] %s steht unter der %s.\n' "$gid" "$license" >&2
    printf '[CoreAI Bildtest] %s\n' "$license_note" >&2
    printf '[CoreAI Bildtest] Volltext: %s\n' "$license_url" >&2
    die "Bitte erst lesen und dann mit --accept-license $accept bestaetigen."
  fi
  SELECTED+=("$gid")
  WEIGHT_BYTES=$((WEIGHT_BYTES + bytes))
done < <(python3 -I "$TOOLS/run.py" groups)
((${#SELECTED[@]})) || die 'Keine Gruppe ausgewaehlt (--groups pruefen).'
NPROC="$(nproc 2>/dev/null || echo 4)"
[[ -n "$THREADS" ]] || THREADS="$NPROC"
(( THREADS >= 1 && THREADS <= NPROC )) || die "--threads $THREADS passt nicht zu $NPROC Kernen"

OUT="$RUNS/$STAMP"
if [[ -z "$DRY_RUN" ]]; then
  install -d -m 0750 -o root -g 33 "$BASE" "$RUNS"
  install -d -m 0700 -o root -g root "$MODELS"
  install -d -m 0750 -o root -g 33 "$OUT"
  free_gb="$(df -Pk "$BASE" | awk 'NR==2 {printf "%d", $4/1048576}')"
  # 0.7.51: from the weights of exactly this selection (FLUX.2-klein 9B alone is about 19 GB).
  weights_gb=$(( (WEIGHT_BYTES + 1073741823) / 1073741824 ))
  need_disk_gb=$(( weights_gb + 6 ))
  need_ram_gb=$(( WEIGHT_BYTES / 1073741824 > 12 ? 32 : 24 ))
  (( free_gb >= need_disk_gb )) || log "Hinweis: nur ${free_gb} GB frei; Gewichte (${weights_gb} GB) und Container brauchen beim ersten Mal etwa ${need_disk_gb} GB."
  avail_gb="$(awk '/^MemAvailable:/ {printf "%d", $2/1048576}' /proc/meminfo 2>/dev/null || echo 0)"
  (( avail_gb >= need_ram_gb )) || log "Hinweis: nur ${avail_gb} GB Arbeitsspeicher frei; der Test braucht bis zu etwa $(( need_ram_gb - 4 )) GB."
  exec > >(tee -a "$OUT/run.log") 2>&1
fi
log "Lauf $STAMP: ${SELECTED[*]}, $THREADS Threads${FULL:+, alle Prompts in allen Groessen}"

# --- Server ----------------------------------------------------------------
if [[ -z "$DRY_RUN" ]]; then
  {
    printf 'Zeit: %s\n' "$(date --iso-8601=seconds 2>/dev/null || date)"
    if command -v lscpu >/dev/null 2>&1; then
      lscpu | grep -E '^(Model name|CPU\(s\)|Thread\(s\) per core|Core\(s\) per socket|Socket\(s\)|NUMA node\(s\)):' || true
      printf 'AVX-512: %s\n' "$(grep -o -w -E 'avx512[a-z_0-9]*' /proc/cpuinfo 2>/dev/null | sort -u | tr '\n' ' ' || true)"
    fi
    command -v free >/dev/null 2>&1 && free -h | head -2
    printf 'Threads im Test: %s\n' "$THREADS"
    printf 'Docker: %s\n' "$(docker --version 2>/dev/null || true)"
  } > "$OUT/host.txt" 2>&1 || true
fi

# --- 1. Container ----------------------------------------------------------
if [[ -n "$SKIP_BUILD" ]]; then
  [[ -n "$DRY_RUN" ]] || docker image inspect "$IMAGE" >/dev/null 2>&1 || die "Container $IMAGE fehlt; ohne --skip-build starten."
else
  log "Baue Container $IMAGE (stable-diffusion.cpp fuer diese CPU) ..."
  run docker build --pull -t "$IMAGE" -f "$DOCKER_DIR/Dockerfile" "$DOCKER_DIR"
fi

# The installer can adopt 9B as a hardlink and chown the shared inode to
# 33:33 (0640). Container root has NO DAC_OVERRIDE after --cap-drop ALL;
# add only the existing service's read group, never privileged/DAC bypass.
SAFE=(--cap-drop ALL --group-add 33 --security-opt no-new-privileges --read-only --tmpfs /tmp:rw,size=1g
      -e HOME=/tmp -v "$TOOLS:/app:ro")

# --- 2. Gewichte laden und registrieren (mit Netz) -----------------------------
for group in "${SELECTED[@]}"; do
  if [[ -z "$REFETCH" && -f "$MODELS/lock-$group.json" ]]; then
    log "Gewichte fuer $group schon registriert (--refetch laedt neu)."
    continue
  fi
  log "Lade Gewichte fuer $group ..."
  if ! run timeout --foreground "$TIME_LIMIT" docker run --rm --name "coreai-image-fetch-$group" "${SAFE[@]}" \
      -v "$MODELS:/models" "$IMAGE" python3 -I /app/run.py fetch --group "$group" --models /models; then
    docker rm -f "coreai-image-fetch-$group" >/dev/null 2>&1 || true
    log "  Laden fehlgeschlagen; $group meldet den Fehler im Bericht."
  fi
done

# --- 3. Bilder erzeugen ohne Netz -------------------------------------------------
full_args=()
[[ -z "$FULL" ]] || full_args=(--full)
# 0.7.51: Mara's reference portraits, read-only. A missing folder is never created by Docker:
# the face prompt then says honestly that its reference image is missing.
ref_mount=()
ref_args=()
if [[ -d "$REFS" ]]; then
  ref_mount=(-v "$REFS:/refs:ro")
  ref_args=(--refs /refs)
else
  log "Hinweis: $REFS fehlt; der Gesichtsprompt laeuft ohne Referenzbild nicht."
fi
for group in "${SELECTED[@]}"; do
  log "Erzeuge Bilder mit $group ($THREADS Threads) ..."
  if ! run timeout --foreground "$TIME_LIMIT" docker run --rm --name "coreai-image-run-$group" "${SAFE[@]}" \
      --network none --memory "$MEMORY" --pids-limit 1024 \
      -v "$MODELS:/models:ro" -v "$OUT:/out" "${ref_mount[@]}" \
      "$IMAGE" python3 -I /app/run.py run --group "$group" --models /models --out /out --threads "$THREADS" "${ref_args[@]}" "${full_args[@]}"; then
    docker rm -f "coreai-image-run-$group" >/dev/null 2>&1 || true
    log "  $group meldet einen Fehler (siehe Bericht)."
  fi
done

# --- 4. Bericht ----------------------------------------------------------------
run python3 -I "$TOOLS/run.py" report --run-dir "$OUT"
if [[ -z "$DRY_RUN" ]]; then
  chown -R root:33 "$OUT"
  find "$OUT" -type d -exec chmod 0750 {} +
  find "$OUT" -type f -exec chmod 0640 {} +
  mapfile -t old < <(find "$RUNS" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended \
    -regex '.*/[0-9]{8}-[0-9]{6}' -printf '%f\n' | sort -r | tail -n +"$((KEEP + 1))")
  for stamp in "${old[@]}"; do
    [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}$ ]] && rm -rf -- "${RUNS:?}/$stamp"
  done
fi
log "Bericht: $OUT/report.md"
log "Ansehen: Admin Core > Betrieb > Bildproben (nur Inhaber)."
