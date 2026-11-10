#!/usr/bin/env bash
# CoreAI: TCI Interface uninstall runner. Never touches global Docker, Ollama,
# Apache, Nginx or unrelated stacks. Browser requests can only archive data.
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

PROJECT_ROOT='/opt/ember-coreui'
COMPOSE_PROJECT='ember-coreui'
SYSTEMD_DIR='/etc/systemd/system'
SYSTEMD_RUNTIME_DIR='/run/systemd/system'
REQUEST="$PROJECT_ROOT/var/uninstall-request/request.json"
ACTION='dry-run'

log() { printf '[CoreAI Uninstall] %s\n' "$*"; }
die() { printf '[CoreAI Uninstall] FEHLER: %s\n' "$*" >&2; exit 1; }

case "${1:---dry-run}" in
  --dry-run) ACTION='dry-run' ;;
  --execute) ACTION='archive' ;;
  --purge-data) ACTION='purge' ;;
  --request) ACTION='request' ;;
  *) die 'Aufruf: uninstall.sh --dry-run | --execute | --purge-data | --request' ;;
esac
(( $# <= 1 )) || die 'Zusaetzliche Argumente werden nicht akzeptiert.'

# Exactly one official installation path. No user-controlled paths or symlinks.
validate_root() {
  [[ "$PROJECT_ROOT" == '/opt/ember-coreui' ]] || die 'Unerwartetes Installationsziel.'
  [[ -d "$PROJECT_ROOT" && ! -L "$PROJECT_ROOT" ]] || die 'CoreAI ist nicht am Standardpfad installiert.'
  [[ -d "$PROJECT_ROOT/var" && ! -L "$PROJECT_ROOT/var" ]] \
    || die 'Persistentes var/-Verzeichnis ist ein Symlink oder fehlt.'
  local required
  for required in VERSION compose.yaml scripts/stack.sh var/compose.env api/config.local.php; do
    [[ -f "$PROJECT_ROOT/$required" && ! -L "$PROJECT_ROOT/$required" ]] \
      || die "Unvollstaendige oder unsichere CoreAI-Installation: $required"
  done
  if command -v mountpoint >/dev/null 2>&1; then
    if mountpoint -q "$PROJECT_ROOT"; then
      die 'Projektverzeichnis ist ein Mountpoint. Deinstallation nicht automatisch moeglich.'
    fi
  fi
}

validate_web_request() {
  [[ -f "$REQUEST" && ! -L "$REQUEST" ]] || die 'Keine sichere Web-Deinstallationsanfrage.'
  command -v python3 >/dev/null 2>&1 || die 'Python3 wird zur sicheren Anfragepruefung benoetigt.'
  python3 - "$REQUEST" <<'PY'
import json, os, re, stat, sys, time
path = sys.argv[1]
try:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, 'O_NONBLOCK', 0))
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > 2048 or info.st_uid != 33 or (info.st_mode & 0o007):
            raise ValueError('invalid request file')
        raw = os.read(fd, 2049)
    finally:
        os.close(fd)
    row = json.loads(raw)
    if not isinstance(row, dict) or set(row) != {'action', 'mode', 'requested_by', 'requested_at', 'nonce'}:
        raise ValueError('invalid request shape')
    if row['action'] != 'uninstall' or row['mode'] != 'archive':
        raise ValueError('invalid request action')
    if type(row['requested_by']) is not int or row['requested_by'] <= 0:
        raise ValueError('invalid requester')
    if type(row['requested_at']) is not int or not 0 <= time.time() - row['requested_at'] <= 300:
        raise ValueError('expired request')
    if not isinstance(row['nonce'], str) or not re.fullmatch('[a-f0-9]{32}', row['nonce']):
        raise ValueError('invalid nonce')
except (OSError, ValueError, TypeError, UnicodeError) as error:
    print('Invalid uninstall request: ' + str(error), file=sys.stderr)
    sys.exit(1)
PY
}

# The updater and uninstaller share a lock; never tear down an active upgrade.
lock_host() {
  command -v flock >/dev/null 2>&1 || die 'flock fehlt.'
  local lockfile="$PROJECT_ROOT/var/update/.lock"
  [[ -d "$PROJECT_ROOT/var/update" && ! -L "$PROJECT_ROOT/var/update" ]] || die 'Update-Lock-Verzeichnis fehlt oder ist unsicher.'
  [[ ! -L "$lockfile" ]] || die 'Unsicherer Update-Lock.'
  exec 9>"$lockfile"
  flock -n 9 || die 'Ein Update oder eine andere Deinstallation laeuft bereits.'
  [[ ! -e "$PROJECT_ROOT/var/update-request/request.json" ]] \
    || die 'Ein CoreAI-Update wurde bereits angefordert. Erst abschliessen oder abbrechen.'
}

# Reject foreign units rather than removing a service another operator owns.
managed_units=(coreai-update.service coreai-update.path coreai-update-check.service \
  coreai-update-check.path coreai-update-check.timer coreai-uninstall.service coreai-uninstall.path)
check_units() {
  local unit path
  for unit in "${managed_units[@]}"; do
    path="$SYSTEMD_DIR/$unit"
    [[ -e "$path" || -L "$path" ]] || continue
    [[ -f "$path" && ! -L "$path" ]] || die "Systemd-Unit ist nicht regulaer: $path"
    grep -q 'CoreAI' "$path" || die "Systemd-Unit ist nicht CoreAI zugeordnet: $path"
  done
}

stop_units() {
  command -v systemctl >/dev/null 2>&1 || return 0
  [[ -d "$SYSTEMD_RUNTIME_DIR" ]] || return 0
  local unit path
  for unit in "${managed_units[@]}"; do
    path="$SYSTEMD_DIR/$unit"
    [[ -f "$path" ]] || continue
    # Do not stop the currently executing oneshot unit. It goes away after exit.
    if [[ "$unit" == 'coreai-uninstall.service' ]]; then
      : # The current oneshot must finish itself.
    elif [[ "$unit" == *.service ]]; then
      systemctl stop "$unit" >/dev/null 2>&1 || die "Dienst konnte nicht gestoppt werden: $unit"
    else
      systemctl disable --now "$unit" >/dev/null 2>&1 || die "Unit konnte nicht deaktiviert werden: $unit"
    fi
    rm -f -- "$path"
  done
  systemctl daemon-reload || die 'systemd konnte nicht neu eingelesen werden.'
  systemctl reset-failed coreai-update.service coreai-uninstall.service >/dev/null 2>&1 || true
}

stop_containers() {
  local args=(--project-name "$COMPOSE_PROJECT" --env-file "$PROJECT_ROOT/var/compose.env" --file "$PROJECT_ROOT/compose.yaml")
  if docker compose version >/dev/null 2>&1; then
    docker compose "${args[@]}" down
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "${args[@]}" down
  else
    die 'Docker Compose nicht gefunden. Keine Dateien werden geloescht.'
  fi
}

check_data_mounts() {
  if command -v findmnt >/dev/null 2>&1; then
    local point
    while IFS= read -r point; do
      [[ "$point" != "$PROJECT_ROOT" && "$point" != "$PROJECT_ROOT"/* ]] \
        || die "Datenbereinigung verweigert: eingebundenes Dateisystem unter $point"
    done < <(findmnt -rn -o TARGET || true)
  fi
}

validate_root
log 'Geplante Eingriffe: Nur das Compose-Projekt ember-coreui und die expliziten CoreAI-systemd-Units.'
log 'Andere Docker-Projekte, Ollama, fremde Modelle, globales Nginx/Apache und Host-Swap bleiben unangetastet.'
case "$ACTION" in
  archive|request) log 'Daten, Uploads, Datenbank, Konten und Dateien werden als privates Archiv unter /opt/ aufbewahrt.' ;;
  purge) log 'ACHTUNG: Alle lokalen CoreAI-Dateien inklusive Datenbank und Backups werden unwiderruflich geloescht.' ;;
  dry-run) log 'Nur Vorschau. Aufruf mit --execute deinstalliert nach Bestaetigung und bewahrt Daten auf.'; exit 0 ;;
esac
(( EUID == 0 )) || die 'Deinstallation erfordert root (sudo).'
check_units
if [[ "$ACTION" == 'request' ]]; then
  validate_web_request
  ACTION='archive'
else
  [[ -r /dev/tty && -w /dev/tty ]] || die 'Eine interaktive Terminalbestaetigung wird benoetigt.'
  if [[ "$ACTION" == 'purge' ]]; then
    check_data_mounts
    expected='COREAI DATEN ENDGUELTIG LOESCHEN'
  else
    expected='COREAI ENTFERNEN'
  fi
  printf '[CoreAI Uninstall] Zur Bestaetigung exakt "%s" eingeben: ' "$expected" >/dev/tty
  IFS= read -r typed </dev/tty || die 'Keine Bestaetigung erhalten.'
  [[ "$typed" == "$expected" ]] || die 'Deinstallation abgebrochen. Keine Aenderungen.'
fi

lock_host
check_data_mounts
# Perform all preflight checks before stopping the compose stack.
if [[ "$ACTION" == 'archive' ]]; then
  ARCHIVE="$PROJECT_ROOT.uninstalled.$(date -u +%Y%m%dT%H%M%SZ)"
  [[ ! -e "$ARCHIVE" && ! -L "$ARCHIVE" ]] || die 'Archivziel existiert bereits.'
fi
# Consume the browser request before archiving, so a later restore cannot
# accidentally trigger another uninstall via systemd PathExists.
if [[ "${1:-}" == '--request' ]]; then rm -f -- "$REQUEST"; fi
log 'Stoppe nur die CoreAI-Compose-Dienste.'
stop_containers
log 'Deaktiviere und entferne nur die CoreAI-systemd-Units.'
stop_units
if [[ "$ACTION" == 'purge' ]]; then
  check_data_mounts
  rm -rf --one-file-system -- "$PROJECT_ROOT"
  log 'CoreAI inklusive lokaler Daten entfernt. Globale Dienste und Ollama sind unberuehrt.'
else
  mv -- "$PROJECT_ROOT" "$ARCHIVE"
  chmod 0700 "$ARCHIVE"
  log "CoreAI deinstalliert. Datenarchiv: $ARCHIVE"
  log 'Bei Bedarf kann das Archiv spaeter ueber SSH wiederhergestellt werden.'
fi
