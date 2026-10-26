#!/usr/bin/env bash
# CoreAI: TCI Interface developer update: intentionally installs source from main, NOT a release ZIP.
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

REPOSITORY='https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface.git'
TARGET_DIR='/opt/ember-coreui'
TMP_DIR=''

log() { printf '[CoreAI: TCI Interface DEV] %s\n' "$*"; }
die() { printf '[CoreAI: TCI Interface DEV] FEHLER: %s\n' "$*" >&2; exit 1; }
cleanup() {
  if [[ -n "$TMP_DIR" && -d "$TMP_DIR" && "$TMP_DIR" == /tmp/ember-coreui-dev.* ]]; then
    rm -rf -- "$TMP_DIR"
  fi
}
trap cleanup EXIT

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte mit sudo bash ausfuehren.'
[[ -f "$TARGET_DIR/api/config.local.php" && -f "$TARGET_DIR/var/compose.env" ]] \
  || die 'Keine bestehende isolierte Installation erkannt. Zuerst das regulare Release installieren.'
command -v rsync >/dev/null || die 'rsync fehlt. Bitte rsync installieren.'
command -v git >/dev/null || die 'git fehlt. Bitte git installieren (apt-get install git).'

TMP_DIR="$(mktemp -d /tmp/ember-coreui-dev.XXXXXX)"
log 'Lade den aktuellen Quellstand aus main. Kein Release, keine Actions.'
git clone --quiet --depth 1 --single-branch --branch main -- "$REPOSITORY" "$TMP_DIR/source" \
  || die 'GitHub konnte nicht geladen werden. Die Installation bleibt unveraendert.'
SOURCE="$TMP_DIR/source"
COMMIT="$(git -C "$SOURCE" rev-parse --verify HEAD)"
[[ "$COMMIT" =~ ^[a-f0-9]{40}$ ]] || die 'Ungueltiger Git-Commit.'
for file in VERSION app.html compose.yaml scripts/install.sh scripts/preflight.sh \
    api/console_artifacts.php api/console_artifact.php \
    api/console_data_link_bridge.php database/migrations/011_console_artifacts.sql; do
  [[ -s "$SOURCE/$file" ]] || die "Datei im main-Quellstand fehlt: $file"
done
log "Uebernehme Quellstand $COMMIT unter Erhalt aller lokalen Daten."
rsync -a \
  --exclude='.git/' \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  "$SOURCE/" "$TARGET_DIR/"
# Source updates also need executable installer/preflight/migration scripts.
chmod 0750 "$TARGET_DIR/scripts/install.sh" "$TARGET_DIR/scripts/preflight.sh" \
  "$TARGET_DIR/scripts/migrate.sh"
install -m 0640 -o root -g 33 /dev/null "$TARGET_DIR/var/dev_source_commit"
printf '%s\n' "$COMMIT" > "$TARGET_DIR/var/dev_source_commit"
cd "$TARGET_DIR"
COREUI_SKIP_BOOTSTRAP=1 ./scripts/install.sh
./scripts/preflight.sh
log "Entwicklerstand erfolgreich installiert: $COMMIT"
log 'Das Produkt-VERSION-Feld bleibt beim letzten freigegebenen Release. Es ist kein Release-Bump.'
