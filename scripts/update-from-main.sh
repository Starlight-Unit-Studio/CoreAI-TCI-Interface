#!/usr/bin/env bash
# CoreAI: TCI Interface developer update: intentionally installs source from main, NOT a release ZIP.
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

REPOSITORY='https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface.git'
TARGET_DIR='/opt/ember-coreui'
TMP_DIR=''

log() { printf '[CoreAI: TCI Interface DEV] %s\n' "$*"; }
die() { printf '[CoreAI: TCI Interface DEV] ERROR: %s\n' "$*" >&2; exit 1; }
cleanup() {
  if [[ -n "$TMP_DIR" && -d "$TMP_DIR" && "$TMP_DIR" == /tmp/ember-coreui-dev.* ]]; then
    rm -rf -- "$TMP_DIR"
  fi
}
trap cleanup EXIT

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Run with sudo bash.'
[[ -f "$TARGET_DIR/api/config.local.php" && -f "$TARGET_DIR/var/compose.env" ]] \
  || die 'No existing isolated installation detected. Install the official release first.'
command -v rsync >/dev/null || die 'rsync is missing. Install rsync.'
command -v git >/dev/null || die 'git is missing. Install Git (apt-get install git).'

TMP_DIR="$(mktemp -d /tmp/ember-coreui-dev.XXXXXX)"
log 'Downloading the current main source. This is not a release or an Actions artifact.'
git clone --quiet --depth 1 --single-branch --branch main -- "$REPOSITORY" "$TMP_DIR/source" \
  || die 'Unable to download from GitHub. The existing installation remains unchanged.'
SOURCE="$TMP_DIR/source"
COMMIT="$(git -C "$SOURCE" rev-parse --verify HEAD)"
[[ "$COMMIT" =~ ^[a-f0-9]{40}$ ]] || die 'Invalid Git commit.'
for file in VERSION app.html compose.yaml scripts/install.sh scripts/preflight.sh \
    api/console_artifacts.php api/console_artifact.php \
    api/console_data_link_bridge.php database/migrations/011_console_artifacts.sql; do
  [[ -s "$SOURCE/$file" ]] || die "File is missing from the main source: $file"
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
# rsync keeps local files; remove only retired source files that main no longer has
# (the same list as setup.sh; 0.7.16: the dictation endpoint of 0.7.14).
for obsolete in api/user_skills.php js/skills-settings.js scripts/skills-ui-selftest.cjs css/admin.css \
    api/voice_dictate.php scripts/voice-dictation-ui-selftest.cjs; do
  if [[ ! -e "$SOURCE/$obsolete" && ! -L "$SOURCE/$obsolete" ]]; then
    rm -f -- "$TARGET_DIR/$obsolete"
  fi
done
# Source updates also need executable installer/preflight/migration scripts.
chmod 0750 "$TARGET_DIR/scripts/install.sh" "$TARGET_DIR/scripts/preflight.sh" \
  "$TARGET_DIR/scripts/migrate.sh"
install -m 0640 -o root -g 33 /dev/null "$TARGET_DIR/var/dev_source_commit"
printf '%s\n' "$COMMIT" > "$TARGET_DIR/var/dev_source_commit"
cd "$TARGET_DIR"
COREUI_SKIP_BOOTSTRAP=1 ./scripts/install.sh
./scripts/preflight.sh
log "Development source successfully installed: $COMMIT"
log 'The product VERSION file remains at the last approved release. This is not a release version bump.'
