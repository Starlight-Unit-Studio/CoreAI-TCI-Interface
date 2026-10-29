#!/usr/bin/env bash
# CoreAI 0.5.83: self-update, started from the Inhaber's update window.
#
#   coreai-updater.sh check   asks GitHub for the newest commit on main
#                             (systemd timer every 10 minutes, or "Jetzt pruefen")
#   coreai-updater.sh run     installs it: database backup, the official setup.sh
#                             of that commit, restart of the CoreAI services
#
# Runs on the host as root through systemd (coreai-update*.service, set up by
# scripts/install.sh). The web app never runs a command: it only drops a
# request file into var/update-request. What happens is decided here, and the
# only source is the main branch of the official repository.
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPOSITORY='Starlight-Unit-Studio/CoreAI-TCI-Interface'
REPOSITORY_URL="https://github.com/${REPOSITORY}.git"
RAW_BASE="https://raw.githubusercontent.com/${REPOSITORY}"
UPDATE_DIR="$PROJECT_ROOT/var/update"
REQUEST_DIR="$PROJECT_ROOT/var/update-request"
REQUEST_FILE="$REQUEST_DIR/request.json"
CHECK_FILE="$REQUEST_DIR/check"
BACKUP_DIR="$PROJECT_ROOT/var/backups"
STATE_TOOL="$PROJECT_ROOT/scripts/lib/coreai_update_state.py"
AVAILABLE_FILE="$UPDATE_DIR/available.json"
STATUS_FILE="$UPDATE_DIR/status.json"
LOG_FILE="$UPDATE_DIR/update.log"
LOCK_FILE="$UPDATE_DIR/.lock"
BACKUPS_KEPT=5
OLLAMA_URL='http://127.0.0.1:11434/api/tags'
TMP_DIR=''

log() {
  printf '[CoreAI Update] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

die() {
  log "FEHLER: $*" >&2
  exit 1
}

cleanup() {
  if [[ -n "$TMP_DIR" && "$TMP_DIR" == /tmp/coreai-update.* && -d "$TMP_DIR" ]]; then
    rm -rf -- "$TMP_DIR"
  fi
}
trap cleanup EXIT

state() {
  python3 -I "$STATE_TOOL" "$@"
}

utc_now() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

read_env() {
  local key="$1"
  [[ -f "$PROJECT_ROOT/var/compose.env" ]] || return 0
  awk -F= -v wanted="$key" '$1 == wanted { sub(/^[^=]*=/, ""); print; exit }' "$PROJECT_ROOT/var/compose.env"
}

local_version() {
  tr -d '[:space:]' < "$PROJECT_ROOT/VERSION" 2>/dev/null || true
}

local_commit() {
  local marker="$PROJECT_ROOT/var/dev_source_commit" commit=''
  if [[ -f "$marker" && ! -L "$marker" ]]; then
    commit="$(tr -d '[:space:]' < "$marker")"
  fi
  [[ "$commit" =~ ^[0-9a-f]{40}$ ]] && printf '%s\n' "$commit"
  return 0
}

ensure_dirs() {
  local dir
  for dir in "$PROJECT_ROOT/var" "$UPDATE_DIR" "$REQUEST_DIR" "$BACKUP_DIR"; do
    [[ ! -L "$dir" ]] || die "Unsicherer symbolischer Pfad: $dir"
  done
  install -d -m 0750 -o root -g 33 "$UPDATE_DIR"
  install -d -m 0770 -o 33 -g 33 "$REQUEST_DIR"
  install -d -m 0700 -o root -g root "$BACKUP_DIR"
}

take_lock() {
  exec 9>>"$LOCK_FILE"
  flock -n 9
}

remote_main() {
  local listing
  listing="$(timeout 40 git ls-remote --heads -- "$REPOSITORY_URL" main 2>/dev/null)" || return 1
  awk '$2 == "refs/heads/main" { print $1; exit }' <<<"$listing"
}

# One check: newest commit on main, its VERSION and the changelog sections
# above the installed version. An error keeps the last known result.
check_now() {
  local commit version='' notes
  if ! commit="$(remote_main)" || [[ ! "$commit" =~ ^[0-9a-f]{40}$ ]]; then
    state available "$AVAILABLE_FILE" '' '' '' 'github_unreachable'
    log 'GitHub nicht erreichbar; letztes Pruefergebnis bleibt.'
    return 0
  fi
  [[ -n "$TMP_DIR" ]] || TMP_DIR="$(mktemp -d /tmp/coreai-update.XXXXXX)"
  notes="$TMP_DIR/notes.md"
  : > "$notes"
  if curl -fsS --max-time 20 --max-filesize 64 -o "$TMP_DIR/VERSION" "$RAW_BASE/$commit/VERSION" 2>/dev/null; then
    version="$(tr -d '[:space:]' < "$TMP_DIR/VERSION")"
  fi
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || version=''
  if [[ "$commit" != "$(local_commit)" ]] \
      && curl -fsS --max-time 30 --max-filesize 4194304 -o "$TMP_DIR/CHANGELOG.md" "$RAW_BASE/$commit/CHANGELOG.md" 2>/dev/null; then
    state notes "$TMP_DIR/CHANGELOG.md" "$(local_version)" > "$notes" || : > "$notes"
  fi
  state available "$AVAILABLE_FILE" "$commit" "$version" "$notes"
  if [[ "$commit" == "$(local_commit)" ]]; then
    log "Aktuell: $(local_version), Commit $commit."
  else
    log "Neuer Stand auf main: ${version:-unbekannt}, Commit $commit (installiert: $(local_version))."
  fi
}

do_check() {
  ensure_dirs
  # Remove the request first: the path unit would start the check again and again.
  rm -f -- "$CHECK_FILE"
  if ! take_lock; then
    log 'Ein Update laeuft; die Pruefung folgt spaeter.'
    return 0
  fi
  if [[ "$(state get "$STATUS_FILE" state)" == 'running' ]]; then
    # Nothing holds the lock, so the last update did not finish (server restart?).
    state status "$STATUS_FILE" state=failed message='Das Update wurde unterbrochen, etwa durch einen Neustart des Servers. Bitte erneut starten.' \
      finished_at="$(utc_now)"
  fi
  check_now
}

fail_run() {
  local phase="$1" message="$2"
  log "FEHLER in Phase $phase: $message"
  state status "$STATUS_FILE" state=failed phase="$phase" message="$message" finished_at="$(utc_now)"
  exit 1
}

backup_database() {
  local stamp file
  stamp="$(date -u +%Y%m%d-%H%M%S)"
  file="$BACKUP_DIR/coreai-$stamp-v$(local_version).sql.gz"
  # Credentials stay inside the database container (its own environment).
  "$PROJECT_ROOT/scripts/stack.sh" exec -T database sh -c \
    'exec mariadb-dump -h127.0.0.1 -P"$COREUI_DB_PORT" -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick --routines --events --databases "$MARIADB_DATABASE"' \
    | gzip -c > "$file.part" || { rm -f -- "$file.part"; return 1; }
  [[ "$(gzip -dc "$file.part" | head -c 4096 | wc -c)" -gt 0 ]] || { rm -f -- "$file.part"; return 1; }
  chmod 0600 "$file.part"
  mv -f -- "$file.part" "$file"
  ls -1t -- "$BACKUP_DIR"/coreai-*.sql.gz 2>/dev/null | tail -n +"$((BACKUPS_KEPT + 1))" | while IFS= read -r old; do
    rm -f -- "$old"
  done
  printf '%s\n' "${file#"$PROJECT_ROOT/"}"
}

download_setup() {
  local commit
  commit="$(remote_main)" && [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || return 1
  TMP_DIR="$(mktemp -d /tmp/coreai-update.XXXXXX)"
  curl -fsS --max-time 60 --max-filesize 1048576 -o "$TMP_DIR/setup.sh" "$RAW_BASE/$commit/setup.sh" || return 1
  # The setup of main must install exactly this installation.
  grep -qxF "TARGET_DIR='$PROJECT_ROOT'" "$TMP_DIR/setup.sh" || return 2
  bash -n "$TMP_DIR/setup.sh" || return 1
  log "Setup aus main geladen (Commit $commit)."
}

restart_services() {
  local restart_ollama="$1" services=(pyworker) attempt
  if is_enabled "$(read_env COREUI_INSTALL_BROWSE)"; then services+=(browse); fi
  # The workers read their code from the project folder: restart them so the
  # new code runs. PHP and web were recreated by the installer already.
  "$PROJECT_ROOT/scripts/stack.sh" restart "${services[@]}" || return 1
  if [[ "$restart_ollama" == '1' ]]; then
    if systemctl cat ollama.service >/dev/null 2>&1; then
      log 'Starte Ollama neu.'
      systemctl restart ollama || return 1
      for attempt in $(seq 1 30); do
        curl -fsS --max-time 4 "$OLLAMA_URL" >/dev/null 2>&1 && return 0
        sleep 2
      done
      return 1
    fi
    log 'Kein systemd-Dienst ollama gefunden; Ollama bleibt unveraendert.'
  fi
  return 0
}

health_ok() {
  local port attempt
  port="$(read_env COREUI_HTTP_PORT)"
  [[ "$port" =~ ^[0-9]{2,5}$ ]] || return 1
  for attempt in $(seq 1 30); do
    curl -fsS --max-time 5 "http://127.0.0.1:$port/api/health.php" >/dev/null 2>&1 && return 0
    sleep 2
  done
  return 1
}

do_run() {
  local request restart_ollama=0 requested_by=0 line backup='' result=0 message
  ensure_dirs
  if [[ ! -e "$REQUEST_FILE" && ! -L "$REQUEST_FILE" ]]; then
    log 'Keine Update-Anfrage vorhanden.'
    return 0
  fi
  if ! request="$(state request "$REQUEST_FILE")"; then
    rm -f -- "$REQUEST_FILE"
    log 'Ungueltige Update-Anfrage verworfen.'
    return 0
  fi
  while IFS= read -r line; do
    case "$line" in
      restart_ollama=1) restart_ollama=1 ;;
      requested_by=*) requested_by="${line#requested_by=}" ;;
    esac
  done <<<"$request"
  if ! take_lock; then
    rm -f -- "$REQUEST_FILE"
    log 'Ein Update laeuft bereits.'
    return 0
  fi

  [[ ! -f "$LOG_FILE" ]] || mv -f -- "$LOG_FILE" "$LOG_FILE.1"
  : > "$LOG_FILE"
  chgrp 33 "$LOG_FILE" 2>/dev/null || true
  chmod 0640 "$LOG_FILE"
  exec >>"$LOG_FILE" 2>&1

  log "Update angefordert von Konto $requested_by, Ollama-Neustart: $restart_ollama."
  state status "$STATUS_FILE" state=running phase=backup message='Datenbank wird gesichert.' \
    started_at="$(utc_now)" finished_at='' from_version="$(local_version)" to_version='' commit='' \
    restart_ollama="$restart_ollama" requested_by="$requested_by" backup=''
  # Only now: the window never sees "no request" before "running" (no early reload).
  rm -f -- "$REQUEST_FILE"

  backup="$(backup_database)" || fail_run backup 'Die Datenbanksicherung ist fehlgeschlagen. CoreAI bleibt unveraendert.'
  log "Datenbank gesichert: $backup"
  state status "$STATUS_FILE" phase=download message='Neuer Stand wird geladen.' backup="$backup"

  download_setup || result=$?
  if (( result == 2 )); then
    fail_run download "Das Setup aus main installiert nicht nach $PROJECT_ROOT. CoreAI bleibt unveraendert."
  elif (( result != 0 )); then
    fail_run download 'GitHub ist nicht erreichbar. CoreAI bleibt unveraendert.'
  fi

  state status "$STATUS_FILE" phase=install message='CoreAI wird installiert und neu gestartet.'
  if ! (cd "$TMP_DIR" && COREUI_SETUP_SOURCE=main bash ./setup.sh </dev/null); then
    # Bring the stack up with what is there, so the window can show the error.
    "$PROJECT_ROOT/scripts/stack.sh" up -d >/dev/null 2>&1 || true
    fail_run install 'Die Installation meldet Fehler. Details im Update-Protokoll.'
  fi

  state status "$STATUS_FILE" phase=restart message='Dienste werden neu gestartet.'
  restart_services "$restart_ollama" || fail_run restart 'Ein Dienst liess sich nicht neu starten. Details im Update-Protokoll.'

  message='Update abgeschlossen.'
  health_ok || message='Update abgeschlossen; der Health-Check meldet noch Fehler.'
  state status "$STATUS_FILE" state=done phase=done message="$message" finished_at="$(utc_now)" \
    to_version="$(local_version)" commit="$(local_commit)"
  log "$message Version $(local_version), Commit $(local_commit)."
  check_now || true
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Bitte als root ausfuehren (systemd-Dienst coreai-update).'
[[ -f "$STATE_TOOL" ]] || die 'scripts/lib/coreai_update_state.py fehlt.'
case "${1:-}" in
  check) do_check ;;
  run) do_run ;;
  *) die 'Aufruf: coreai-updater.sh check|run' ;;
esac
