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
die() { printf '[CoreAI Uninstall] ERROR: %s\n' "$*" >&2; exit 1; }

case "${1:---dry-run}" in
  --dry-run) ACTION='dry-run' ;;
  --execute) ACTION='archive' ;;
  --purge-data) ACTION='purge' ;;
  --request) ACTION='request' ;;
  *) die 'Usage: uninstall.sh --dry-run | --execute | --purge-data | --request' ;;
esac
(( $# <= 1 )) || die 'Additional arguments are not accepted.'

# Exactly one official installation path. No user-controlled paths or symlinks.
validate_root() {
  [[ "$PROJECT_ROOT" == '/opt/ember-coreui' ]] || die 'Unexpected installation target.'
  [[ -d "$PROJECT_ROOT" && ! -L "$PROJECT_ROOT" ]] || die 'CoreAI is not installed in the standard directory.'
  [[ -d "$PROJECT_ROOT/var" && ! -L "$PROJECT_ROOT/var" ]] \
    || die 'Persistent var/ directory is a symbolic link or is missing.'
  local required
  for required in VERSION compose.yaml scripts/stack.sh var/compose.env api/config.local.php; do
    [[ -f "$PROJECT_ROOT/$required" && ! -L "$PROJECT_ROOT/$required" ]] \
      || die "Incomplete or unsafe CoreAI installation: $required"
  done
  if command -v mountpoint >/dev/null 2>&1; then
    if mountpoint -q "$PROJECT_ROOT"; then
      die 'Project directory is a mount point. Automatic uninstall is not allowed.'
    fi
  fi
}

validate_web_request() {
  [[ -f "$REQUEST" && ! -L "$REQUEST" ]] || die 'No valid web uninstall request found.'
  command -v python3 >/dev/null 2>&1 || die 'Python 3 is required to validate the uninstall request safely.'
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
  command -v flock >/dev/null 2>&1 || die 'flock is missing.'
  local lockfile="$PROJECT_ROOT/var/update/.lock"
  [[ -d "$PROJECT_ROOT/var/update" && ! -L "$PROJECT_ROOT/var/update" ]] || die 'Update lock directory is missing or unsafe.'
  [[ ! -L "$lockfile" ]] || die 'Unsafe update lock.'
  exec 9>"$lockfile"
  flock -n 9 || die 'An update or another uninstall is already running.'
  [[ ! -e "$PROJECT_ROOT/var/update-request/request.json" ]] \
    || die 'A CoreAI update has already been requested. Complete or cancel it first.'
}

# Reject foreign units rather than removing a service another operator owns.
managed_units=(coreai-update.service coreai-update.path coreai-update-check.service \
  coreai-update-check.path coreai-update-check.timer coreai-uninstall.service coreai-uninstall.path)
check_units() {
  local unit path
  for unit in "${managed_units[@]}"; do
    path="$SYSTEMD_DIR/$unit"
    [[ -e "$path" || -L "$path" ]] || continue
    [[ -f "$path" && ! -L "$path" ]] || die "Systemd unit is not a regular file: $path"
    grep -q '^Description=CoreAI: TCI Interface ' "$path" || die "Systemd unit does not belong to CoreAI: $path"
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
      systemctl stop "$unit" >/dev/null 2>&1 || die "Service could not be stopped: $unit"
    else
      systemctl disable --now "$unit" >/dev/null 2>&1 || die "Unit could not be disabled: $unit"
    fi
    rm -f -- "$path"
  done
  systemctl daemon-reload || die 'systemd configuration could not be reloaded.'
  systemctl reset-failed coreai-update.service coreai-uninstall.service >/dev/null 2>&1 || true
}

stop_containers() {
  local args=(--project-name "$COMPOSE_PROJECT" --env-file "$PROJECT_ROOT/var/compose.env" --file "$PROJECT_ROOT/compose.yaml")
  if docker compose version >/dev/null 2>&1; then
    docker compose "${args[@]}" down
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "${args[@]}" down
  else
    die 'Docker Compose was not found. No files will be deleted.'
  fi
}

check_data_mounts() {
  command -v findmnt >/dev/null 2>&1 || die 'findmnt is missing. Mount points cannot be verified safely.'
  local point
  while IFS= read -r point; do
    [[ "$point" != "$PROJECT_ROOT" && "$point" != "$PROJECT_ROOT"/* ]] \
      || die "Data removal refused: mounted filesystem at $point"
  done < <(findmnt -rn -o TARGET || true)
}

validate_root
log 'Planned changes: only the ember-coreui Compose project and explicitly managed CoreAI systemd units.'
log 'Other Docker projects, Ollama, third-party models, global Nginx/Apache, and host swap remain untouched.'
case "$ACTION" in
  archive|request) log 'Data, uploads, database, accounts, and files are preserved in a private archive under /opt/.' ;;
  purge) log 'WARNING: All local CoreAI files, including the database and backups, will be permanently deleted.' ;;
  dry-run) log 'Preview only. Run --execute to uninstall after confirmation while preserving data.'; exit 0 ;;
esac
(( EUID == 0 )) || die 'Uninstallation requires root (sudo).'
check_units
if [[ "$ACTION" == 'request' ]]; then
  [[ -d "$PROJECT_ROOT/var/uninstall-request" && ! -L "$PROJECT_ROOT/var/uninstall-request" ]] \
    || die 'Unsafe uninstall request directory.'
  if ! validate_web_request; then
    rm -f -- "$REQUEST"
    die 'Invalid uninstall request safely discarded.'
  fi
  ACTION='archive'
else
  [[ -r /dev/tty && -w /dev/tty ]] || die 'Interactive terminal confirmation is required.'
  if [[ "$ACTION" == 'purge' ]]; then
    check_data_mounts
    expected='COREAI DELETE ALL DATA PERMANENTLY'
  else
    expected='COREAI UNINSTALL'
  fi
  printf '[CoreAI Uninstall] To confirm, type exactly "%s": ' "$expected" >/dev/tty
  IFS= read -r typed </dev/tty || die 'No confirmation received.'
  [[ "$typed" == "$expected" ]] || die 'Uninstall cancelled. No changes made.'
fi

lock_host
check_data_mounts
# Perform all preflight checks before stopping the compose stack.
if [[ "$ACTION" == 'archive' ]]; then
  ARCHIVE="$PROJECT_ROOT.uninstalled.$(date -u +%Y%m%dT%H%M%SZ)"
  [[ ! -e "$ARCHIVE" && ! -L "$ARCHIVE" ]] || die 'Archive destination already exists.'
fi
# Consume the browser request before archiving, so a later restore cannot
# accidentally trigger another uninstall via systemd PathExists.
if [[ "${1:-}" == '--request' ]]; then rm -f -- "$REQUEST"; fi
log 'Stopping only CoreAI Compose services.'
stop_containers
log 'Disabling and removing only CoreAI systemd units.'
stop_units
if [[ "$ACTION" == 'purge' ]]; then
  check_data_mounts
  rm -rf --one-file-system -- "$PROJECT_ROOT"
  log 'CoreAI and its local data have been removed. Global services and Ollama remain untouched.'
else
  mv -- "$PROJECT_ROOT" "$ARCHIVE"
  chmod 0700 "$ARCHIVE"
  log "CoreAI uninstalled. Data archive: $ARCHIVE"
  log 'The archive can be restored later over SSH if needed.'
fi
