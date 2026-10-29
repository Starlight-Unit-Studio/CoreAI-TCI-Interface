#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

REPOSITORY='Starlight-Unit-Studio/CoreAI-TCI-Interface'
# Ein und derselbe One-Liner installiert und aktualisiert den jeweils gemergten
# Entwicklungsstand. Das letzte veroeffentlichte und gepruefte ZIP bleibt ueber
# COREUI_SETUP_SOURCE=release explizit verfuegbar.
SOURCE_MODE="${COREUI_SETUP_SOURCE:-main}"
ARCHIVED_RELEASE_VERSION='0.5.3-alpha'
TAG="v${ARCHIVED_RELEASE_VERSION}"
ARCHIVE='EMBER_COREUI_0_5_3_ALPHA.zip'
CHECKSUM="${ARCHIVE}.sha256"
PACKAGE_ROOT='EMBER_COREUI_0_5_3_ALPHA'
TARGET_DIR='/opt/ember-coreui'
DOWNLOAD_BASE="https://raw.githubusercontent.com/${REPOSITORY}/main/releases/${TAG}"
TEMP_DIR=''
SOURCE_DIR=''
SOURCE_COMMIT=''

log() {
  printf '[CoreAI: TCI Interface Setup] %s\n' "$*"
}

die() {
  printf '[CoreAI: TCI Interface Setup] FEHLER: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" && "$TEMP_DIR" == /tmp/ember-coreui-setup.* ]]; then
    rm -rf -- "$TEMP_DIR"
  fi
}
trap cleanup EXIT

cleanup_legacy_release_dirs() {
  local legacy_dir
  local legacy_name
  local removed=0

  while IFS= read -r -d '' legacy_dir; do
    legacy_name="${legacy_dir##*/}"
    [[ "$legacy_name" =~ ^EMBER_COREUI_[0-9]+_[0-9]+_[0-9]+_ALPHA$ ]] || continue
    [[ ! -L "$legacy_dir" ]] || die "Unsicherer symbolischer Altversionspfad erkannt: $legacy_dir"
    [[ "$legacy_dir" == "$TARGET_DIR"/EMBER_COREUI_*_ALPHA ]] \
      || die "Unsicherer Altversionspfad erkannt: $legacy_dir"
    if [[ ! -f "$legacy_dir/VERSION" || ! -f "$legacy_dir/scripts/install.sh" ]]; then
      log "WARN: $legacy_name sieht nur dem Namen nach wie eine Altversion aus und bleibt deshalb erhalten."
      continue
    fi
    if command -v mountpoint >/dev/null 2>&1 && mountpoint -q "$legacy_dir"; then
      die "Altversionsordner ist ein Einhaengepunkt und wird nicht automatisch entfernt: $legacy_dir"
    fi

    log "Entferne vollstaendig entpackte Altversion: $legacy_name"
    rm -rf -- "$legacy_dir"
    [[ ! -e "$legacy_dir" ]] || die "Altversionsordner konnte nicht entfernt werden: $legacy_dir"
    removed=$((removed + 1))
  done < <(find -P "$TARGET_DIR" -mindepth 1 -maxdepth 1 -type d -name 'EMBER_COREUI_*_ALPHA' -print0)

  if (( removed > 0 )); then
    log "$removed entpackte Altversionsordner wurden entfernt. Persistente Daten blieben unangetastet."
  fi
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte das heruntergeladene Setup mit sudo bash ausfuehren.'
case "$SOURCE_MODE" in
  main|release) ;;
  *) die 'COREUI_SETUP_SOURCE muss main oder release sein.' ;;
esac

packages=()
command -v curl >/dev/null 2>&1 || packages+=(ca-certificates curl)
command -v sha256sum >/dev/null 2>&1 || packages+=(coreutils)
command -v unzip >/dev/null 2>&1 || packages+=(unzip)
command -v rsync >/dev/null 2>&1 || packages+=(rsync)
if [[ "$SOURCE_MODE" == 'main' ]]; then
  command -v git >/dev/null 2>&1 || packages+=(git)
fi
if (( ${#packages[@]} > 0 )); then
  command -v apt-get >/dev/null 2>&1 || die 'Benötigte Installationswerkzeuge fehlen und apt-get ist nicht verfuegbar.'
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y "${packages[@]}"
fi

TEMP_DIR="$(mktemp -d /tmp/ember-coreui-setup.XXXXXX)"
if [[ "$SOURCE_MODE" == 'main' ]]; then
  log 'Lade den neuesten CoreAI: TCI Interface-Entwicklungsstand aus main.'
  # Das globale umask 027 schuetzt lokale Zugangsdaten, ist fuer den
  # oeffentlichen Git-Checkout aber zu strikt: rsync -a uebernimmt 750/640
  # root:root und der Webserver (UID 33) kann api/health.php nicht lesen.
  # Nur beim Checkout 022 benutzen, niemals beim Schreiben lokaler Secrets.
  ( umask 022
    git clone --quiet --depth 1 --single-branch --branch main -- \
      "https://github.com/${REPOSITORY}.git" "$TEMP_DIR/source"
  ) || die 'GitHub-Quellstand konnte nicht geladen werden; die Installation bleibt unveraendert.'
  SOURCE_DIR="$TEMP_DIR/source"
  SOURCE_COMMIT="$(git -C "$SOURCE_DIR" rev-parse --verify HEAD)" \
    || die 'Der Quellstand enthaelt keinen gueltigen Commit.'
  [[ "$SOURCE_COMMIT" =~ ^[a-f0-9]{40}$ ]] || die 'Ungueltiger Git-Commit.'
  for required in api/console_artifact.php api/console_artifacts.php \
      database/migrations/011_console_artifacts.sql; do
    [[ -s "$SOURCE_DIR/$required" ]] || die "Unvollstaendiger main-Quellstand: $required fehlt."
  done
  log "Aktueller GitHub-Commit: $SOURCE_COMMIT"
else
  log "Lade das unveraenderte historische E.M.B.E.R. CoreUI-Release ${ARCHIVED_RELEASE_VERSION}."
  curl --fail --location --show-error --silent --retry 3 --retry-all-errors \
    --output "$TEMP_DIR/$ARCHIVE" "$DOWNLOAD_BASE/$ARCHIVE"
  curl --fail --location --show-error --silent --retry 3 --retry-all-errors \
    --output "$TEMP_DIR/$CHECKSUM" "$DOWNLOAD_BASE/$CHECKSUM"
  (
    cd "$TEMP_DIR"
    sha256sum -c "$CHECKSUM"
  )
  unzip -q "$TEMP_DIR/$ARCHIVE" -d "$TEMP_DIR/unpacked"
  SOURCE_DIR="$TEMP_DIR/unpacked/$PACKAGE_ROOT"
  [[ "$(tr -d '[:space:]' < "$SOURCE_DIR/VERSION")" == "$ARCHIVED_RELEASE_VERSION" ]] \
    || die 'Paketversion und Setup-Version stimmen nicht ueberein.'
fi

for required in VERSION README.md compose.yaml scripts/install.sh scripts/stack.sh scripts/preflight.sh; do
  [[ -s "$SOURCE_DIR/$required" ]] || die "Der Quellstand ist unvollstaendig: $required fehlt."
done
SOURCE_VERSION="$(tr -d '[:space:]' < "$SOURCE_DIR/VERSION")"
if [[ "$SOURCE_MODE" == 'main' ]]; then
  [[ "$SOURCE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die 'VERSION muss rein numerisch sein: Major.Minor.Patch.'
  [[ -s "$SOURCE_DIR/scripts/cache-bust.py" ]] || die 'Cache-Buster-Installer fehlt.'
fi

if find "$SOURCE_DIR" -type f \
    \( -iname '*master*bibel*' -o -iname '*kompendium*v6*' \) -print -quit | grep -q .; then
  die 'Das Installationspaket enthaelt gesperrtes privates Studio-Material.'
fi

existing=0
if [[ -f "$TARGET_DIR/api/config.local.php" && -f "$TARGET_DIR/var/compose.env" ]]; then
  existing=1
  log 'Bestehende Installation erkannt. Konten, Datenbank, Uploads und lokale Konfiguration bleiben erhalten.'
fi

install -d -m 0750 "$TARGET_DIR"
cleanup_legacy_release_dirs
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='releases/' \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  "$SOURCE_DIR/" "$TARGET_DIR/"

# rsync deliberately preserves local files. Remove only these retired source
# files, and only when absent from the selected checkout (also on repeated runs).
# Never use --delete here: operator files and persistent data must survive.
for obsolete in api/user_skills.php js/skills-settings.js \
    scripts/skills-ui-selftest.cjs css/admin.css; do
  if [[ ! -e "$SOURCE_DIR/$obsolete" && ! -L "$SOURCE_DIR/$obsolete" ]]; then
    rm -f -- "$TARGET_DIR/$obsolete"
  fi
done

# Exakte Altlasten aus versehentlichen Alpha-Paketen entfernen. Andere Dateien
# im docs-Verzeichnis bleiben unangetastet.
rm -f -- \
  "$TARGET_DIR/docs/S.U. MASTER BIBEL v10.4.docx" \
  "$TARGET_DIR/docs/STU_KOMPENDIUM_V6.docx"

chmod 0750 \
  "$TARGET_DIR/scripts/install.sh" \
  "$TARGET_DIR/scripts/stack.sh" \
  "$TARGET_DIR/scripts/preflight.sh" \
  "$TARGET_DIR/scripts/migrate.sh"

cd "$TARGET_DIR"
if (( existing == 1 )); then
  COREUI_SKIP_BOOTSTRAP=1 ./scripts/install.sh
elif [[ -t 0 && -r /dev/tty && -w /dev/tty ]]; then
  ./scripts/install.sh </dev/tty
else
  [[ -n "${COREUI_ADMIN_EMAIL:-}" && -n "${COREUI_ADMIN_PASSWORD:-}" ]] \
    || die 'Fuer eine nicht-interaktive Erstinstallation muessen COREUI_ADMIN_EMAIL und COREUI_ADMIN_PASSWORD gesetzt sein.'
  ./scripts/install.sh
fi

./scripts/preflight.sh
if [[ "$SOURCE_MODE" == 'main' ]]; then
  [[ ! -L "$TARGET_DIR/var/dev_source_commit" ]] || die 'Unsicherer Entwicklerstand-Markierungspfad.'
  printf '%s\n' "$SOURCE_COMMIT" > "$TARGET_DIR/var/dev_source_commit"
  # 0.5.83: the update window (web server, group 33) compares it with main.
  chgrp 33 "$TARGET_DIR/var/dev_source_commit" 2>/dev/null || true
  chmod 0640 "$TARGET_DIR/var/dev_source_commit"
  log "Entwicklerstand erfolgreich installiert: $SOURCE_COMMIT"
  log "CoreAI: TCI Interface $SOURCE_VERSION, GitHub-Commit $SOURCE_COMMIT."
else
  rm -f -- "$TARGET_DIR/var/dev_source_commit"
  log "Archiviertes historisches E.M.B.E.R. CoreUI Release ${ARCHIVED_RELEASE_VERSION} wurde geprueft installiert."
fi

