#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$PROJECT_ROOT/scripts/lib/host-swap.sh"
COREUI_VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
MODEL_OWNER_FILE="$PROJECT_ROOT/var/model.owner"
COMPOSE_PROJECT='ember-coreui'
COMPOSE_CMD=()
TEMP_FILES=()

log() {
  printf '[CoreAI: TCI Interface] %s\n' "$*"
}

warn() {
  printf '[CoreAI: TCI Interface] WARNING: %s\n' "$*" >&2
}

die() {
  printf '[CoreAI: TCI Interface] ERROR: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  local file
  for file in "${TEMP_FILES[@]:-}"; do
    [[ -z "$file" || ! -f "$file" ]] || rm -f -- "$file"
  done
}
trap cleanup EXIT

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

php_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

valid_port() {
  [[ "$1" =~ ^[0-9]{4,5}$ ]] && (( 10#$1 >= 1024 && 10#$1 <= 65535 ))
}

read_saved_env() {
  local key="$1"
  [[ -f "$ENV_FILE" ]] || return 1
  awk -F= -v wanted="$key" '
    $1 == wanted {
      sub(/^[^=]*=/, "")
      print
      exit
    }
  ' "$ENV_FILE"
}

ensure_apt_updated() {
  if [[ "${APT_UPDATED:-0}" != '1' ]]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    APT_UPDATED=1
  fi
}

ensure_host_tools() {
  local packages=()
  command -v curl >/dev/null 2>&1 || packages+=(ca-certificates curl)
  command -v openssl >/dev/null 2>&1 || packages+=(openssl)
  command -v python3 >/dev/null 2>&1 || packages+=(python3)
  command -v ss >/dev/null 2>&1 || packages+=(iproute2)
  if (( ${#packages[@]} > 0 )); then
    ensure_apt_updated
    apt-get install -y "${packages[@]}"
  fi
}

ensure_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    is_enabled "$INSTALL_DOCKER" || die 'Docker is missing. Install Docker or set COREUI_INSTALL_DOCKER=1.'
    log 'Installing Docker for the isolated CoreAI: TCI Interface stack.'
    ensure_apt_updated
    apt-get install -y docker.io
  fi

  if command -v systemctl >/dev/null 2>&1 && ! systemctl is-active --quiet docker; then
    systemctl enable --now docker
  fi
  docker info >/dev/null 2>&1 || die 'Docker is installed, but the daemon is unreachable.'

  if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(docker compose)
    return
  fi
  if command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=(docker-compose)
    return
  fi

  is_enabled "$INSTALL_DOCKER" || die 'Docker Compose is missing. Install Compose or set COREUI_INSTALL_DOCKER=1.'
  ensure_apt_updated
  if apt-cache show docker-compose-plugin >/dev/null 2>&1; then
    apt-get install -y docker-compose-plugin
  elif apt-cache show docker-compose-v2 >/dev/null 2>&1; then
    apt-get install -y docker-compose-v2
  else
    apt-get install -y docker-compose
  fi

  if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=(docker-compose)
  else
    die 'Docker Compose could not be configured.'
  fi
}

compose() {
  "${COMPOSE_CMD[@]}" \
    --project-name "$COMPOSE_PROJECT" \
    --env-file "$ENV_FILE" \
    --file "$COMPOSE_FILE" \
    "$@"
}

wait_http() {
  local url="$1"
  local attempts="${2:-60}"
  local count=0
  while (( count < attempts )); do
    if curl -fsS --max-time 5 "$url" >/dev/null 2>&1; then
      return 0
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

wait_healthy() {
  local service="$1"
  local attempts="${2:-60}"
  local count=0
  local container_id=''
  local status=''
  while (( count < attempts )); do
    container_id="$(compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
      status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id" 2>/dev/null || true)"
      [[ "$status" == 'healthy' || "$status" == 'running' ]] && return 0
      [[ "$status" == 'unhealthy' || "$status" == 'exited' || "$status" == 'dead' ]] && return 1
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

port_is_listening() {
  local port="$1"
  ss -H -ltn 2>/dev/null | awk -v suffix=":$port" '
    $4 ~ (suffix "$") { found = 1 }
    END { exit(found ? 0 : 1) }
  '
}

stack_service_running() {
  local service="$1"
  local container_id=''
  container_id="$(compose ps -q "$service" 2>/dev/null || true)"
  [[ -n "$container_id" ]] || return 1
  [[ "$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)" == 'true' ]]
}

assert_stack_identity() {
  local ids=()
  local id
  local working_dir
  mapfile -t ids < <(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT")
  if (( ${#ids[@]} == 0 )); then
    EXISTING_STACK=0
    return
  fi

  for id in "${ids[@]}"; do
    working_dir="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$id" 2>/dev/null || true)"
    [[ "$working_dir" == "$PROJECT_ROOT" ]] \
      || die "Ein Compose-Projekt namens $COMPOSE_PROJECT belongs to a different directory: ${working_dir:-unknown}"
  done
  EXISTING_STACK=1
}

assert_ports_available() {
  local ports=("$HTTP_PORT" "$PHP_PORT" "$DB_PORT")
  local labels=('HTTP' 'PHP-FPM' 'MariaDB')
  local services=('web' 'php' 'database')
  local saved_ports=("${SAVED_HTTP_PORT:-}" "${SAVED_PHP_PORT:-}" "${SAVED_DB_PORT:-}")
  local seen=' '
  local index
  if is_enabled "$INSTALL_SEARXNG"; then
    ports+=("$SEARXNG_PORT")
    labels+=('SearXNG')
    services+=('searxng')
    saved_ports+=("${SAVED_SEARXNG_PORT:-}")
  fi

  for index in "${!ports[@]}"; do
    [[ "$seen" != *" ${ports[$index]} "* ]] || die "Port ${ports[$index]} is assigned twice within the CoreAI: TCI Interface stack."
    seen+="${ports[$index]} "
    if port_is_listening "${ports[$index]}"; then
      if (( EXISTING_STACK == 0 )) \
          || [[ "${ports[$index]}" != "${saved_ports[$index]}" ]] \
          || ! stack_service_running "${services[$index]}"; then
        die "${labels[$index]}-Port ${ports[$index]} is already in use. Choose a different COREUI_*-PORT."
      fi
    fi
  done
}

prepare_permissions() {
  chgrp 33 "$PROJECT_ROOT"
  chmod 0750 "$PROJECT_ROOT"

  install -d -m 0750 -o root -g 33 \
    "$PROJECT_ROOT/var" \
    "$PROJECT_ROOT/var/nginx" \
    "$PROJECT_ROOT/var/php-fpm"

  install -d -m 0770 -o 33 -g 33 \
    "$PROJECT_ROOT/logs" \
    "$PROJECT_ROOT/var/cache" \
    "$PROJECT_ROOT/var/console_media" \
    "$PROJECT_ROOT/var/profile_media" \
    "$PROJECT_ROOT/var/knowledge_uploads" \
    "$PROJECT_ROOT/var/ember_py" \
    "$PROJECT_ROOT/var/ember_frames" \
    "$PROJECT_ROOT/var/pdf_pages" \
    "$PROJECT_ROOT/var/browser-home" \
    "$PROJECT_ROOT/var/php_sessions" \
    "$PROJECT_ROOT/var/update-request" \
    "$PROJECT_ROOT/var/voice_cache" \
    "$PROJECT_ROOT/var/voice-models" \
    "$PROJECT_ROOT/var/image-models" \
    "$PROJECT_ROOT/uploads/ember_browse" \
    "$PROJECT_ROOT/assets/chat_media" \
    "$PROJECT_ROOT/assets/profile_photos" \
    "$PROJECT_ROOT/assets/profile_photos/pending" \
    "$PROJECT_ROOT/assets/profile_photos/approved"

  # 0.5.83: self-update. var/update is root's (the web app only reads it),
  # var/backups holds the database backups taken before an update.
  install -d -m 0750 -o root -g 33 "$PROJECT_ROOT/var/update"
  install -d -m 0700 -o root -g root "$PROJECT_ROOT/var/backups"

  if [[ ! -d "$PROJECT_ROOT/var/mariadb" ]]; then
    install -d -m 0700 -o root -g root "$PROJECT_ROOT/var/mariadb"
  fi
  if [[ ! -d "$PROJECT_ROOT/var/searxng/config" ]]; then
    install -d -m 0770 -o root -g root \
      "$PROJECT_ROOT/var/searxng" \
      "$PROJECT_ROOT/var/searxng/config"
  fi
  if [[ ! -d "$PROJECT_ROOT/var/searxng/cache" ]]; then
    install -d -m 0770 -o root -g root "$PROJECT_ROOT/var/searxng/cache"
  fi
}

write_runtime_files() {
  local env_tmp config_tmp nginx_tmp fpm_tmp searxng_tmp
  local db_pass_php public_url_php model_php searxng_url_php lock_namespace_php
  local web_bool browse_bool

  env_tmp="$(mktemp)"
  config_tmp="$(mktemp)"
  nginx_tmp="$(mktemp)"
  fpm_tmp="$(mktemp)"
  searxng_tmp="$(mktemp)"
  TEMP_FILES+=("$env_tmp" "$config_tmp" "$nginx_tmp" "$fpm_tmp" "$searxng_tmp")

  cat > "$env_tmp" <<ENV
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT
COREUI_HTTP_PORT=$HTTP_PORT
COREUI_PHP_PORT=$PHP_PORT
COREUI_DB_PORT=$DB_PORT
COREUI_SEARXNG_PORT=$SEARXNG_PORT
COREUI_DB_NAME=$DB_NAME
COREUI_DB_USER=$DB_USER
COREUI_DB_PASS=$DB_PASS
COREUI_DB_ROOT_PASS=$DB_ROOT_PASS
COREUI_MODEL_NAME=$MODEL_NAME
COREUI_LOCK_NAMESPACE=$LOCK_NAMESPACE
COREUI_BIND_ADDRESS=$BIND_ADDRESS
COREUI_PROJECT_ROOT=$PROJECT_ROOT
COREUI_INSTALL_SEARXNG=$INSTALL_SEARXNG
COREUI_INSTALL_BROWSE=$INSTALL_BROWSE
COREUI_INSTALL_VOICE=$INSTALL_VOICE
COREUI_INSTALL_IMAGE=$INSTALL_IMAGE
COREUI_SEARXNG_URL=$SEARXNG_URL
COREUI_SEARXNG_SECRET=$SEARXNG_SECRET
HF_TOKEN=$IMAGE_HF_TOKEN
ENV
  install -m 0600 -o root -g root "$env_tmp" "$ENV_FILE"

  if [[ ! -f "$CONFIG_FILE" || "$FORCE_CONFIG" == '1' ]]; then
    db_pass_php="$(php_escape "$DB_PASS")"
    public_url_php="$(php_escape "$PUBLIC_URL")"
    model_php="$(php_escape "$MODEL_NAME")"
    searxng_url_php="$(php_escape "$SEARXNG_URL")"
    lock_namespace_php="$(php_escape "$LOCK_NAMESPACE")"
    if [[ -n "$SEARXNG_URL" ]]; then web_bool=true; else web_bool=false; fi
    if is_enabled "$INSTALL_BROWSE"; then browse_bool=true; else browse_bool=false; fi

    cat > "$config_tmp" <<PHP
<?php
declare(strict_types=1);

/* Generated by CoreAI: TCI Interface scripts/install.sh for the isolated Compose stack. */
define('STU_DB_HOST', '127.0.0.1');
define('STU_DB_PORT', $DB_PORT);
define('STU_DB_NAME', '$DB_NAME');
define('STU_DB_USER', '$DB_USER');
define('STU_DB_PASS', '$db_pass_php');

define('STU_PUBLIC_BASE_URL', '$public_url_php');
define('STU_SESSION_NAME', 'EMBERCOREUISESSID');
define('STU_ALLOW_REGISTRATION', false);
define('STU_DEBUG', false);

define('STU_EMBER_ENABLED', true);
define('STU_EMBER_USER_ID', 1);
define('STU_EMBER_CHARACTER_ID', 'ember');
define('STU_EMBER_CHARACTER_NAME', 'Mara');
define('STU_EMBER_MODEL', '$model_php');
define('STU_EMBER_OLLAMA_URL', 'http://127.0.0.1:11434/api/chat');
define('STU_EMBER_LOCK_NAMESPACE', '$lock_namespace_php');
define('STU_EMBER_NUM_THREAD', $THREADS);
define('STU_EMBER_NUM_PREDICT', 6500);
define('STU_EMBER_TIMEOUT', 600);
define('STU_EMBER_TIMEOUT_RETRY', 600);
define('STU_EMBER_MAX_REPLY_CHARS', 60000);
define('STU_EMBER_TEMPERATURE', 1.0);
define('STU_EMBER_TOP_P', 0.95);
define('STU_EMBER_TOP_K', 64);

define('STU_EMBER_MEMORY_ENABLED', true);
define('STU_EMBER_REFLECT_ENABLED', true);
define('STU_EMBER_LORE_ENABLED', true);
define('STU_EMBER_WEB_ENABLED', $web_bool);
define('STU_EMBER_WEB_URL', '$searxng_url_php');
define('STU_EMBER_BROWSE_ENABLED', $browse_bool);
define('STU_EMBER_BROWSE_MAX_STEPS', 12);
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
define('STU_EMBER_BROWSE_SCREENSHOT_DIR', '/var/www/coreui/uploads/ember_browse');
define('STU_EMBER_PY_ENABLED', true);
PHP
    install -m 0640 -o root -g 33 "$config_tmp" "$CONFIG_FILE"
  else
    log 'Existing api/config.local.php will remain unchanged.'
  fi

  sed \
    -e "s|@@BIND_ADDRESS@@|$BIND_ADDRESS|g" \
    -e "s|@@HTTP_PORT@@|$HTTP_PORT|g" \
    -e "s|@@PHP_PORT@@|$PHP_PORT|g" \
    "$PROJECT_ROOT/config/runtime/nginx.conf.template" > "$nginx_tmp"
  install -m 0640 -o root -g 33 "$nginx_tmp" "$PROJECT_ROOT/var/nginx/coreui.conf"

  sed -e "s|@@PHP_PORT@@|$PHP_PORT|g" \
    "$PROJECT_ROOT/config/runtime/php-fpm.conf.template" > "$fpm_tmp"
  install -m 0640 -o root -g 33 "$fpm_tmp" "$PROJECT_ROOT/var/php-fpm/zz-coreui.conf"

  sed \
    -e "s|{{SEARXNG_SECRET}}|$SEARXNG_SECRET|g" \
    -e "s|{{SEARXNG_PORT}}|$SEARXNG_PORT|g" \
    "$PROJECT_ROOT/config/runtime/searxng-settings.yml.template" > "$searxng_tmp"
  install -m 0640 -o root -g root "$searxng_tmp" "$PROJECT_ROOT/var/searxng/config/settings.yml"
}

# 0.5.83: the update window of the Inhaber. Systemd units on the host check
# main every 10 minutes and run scripts/coreai-updater.sh when the window
# drops its request file; the web app itself never runs a command.
ensure_update_service() {
  local marker="$PROJECT_ROOT/var/update/updater.json"
  local units=(coreai-update.service coreai-update.path coreai-update-check.service \
    coreai-update-check.timer coreai-update-check.path)
  local unit unit_tmp
  if ! command -v systemctl >/dev/null 2>&1 || [[ ! -d /run/systemd/system ]]; then
    warn 'systemd is unavailable: the Update window will not have a host service. Continue updating with the setup one-liner.'
    printf '{"installed": false, "reason": "no_systemd", "version": "%s"}\n' "$COREUI_VERSION" > "$marker"
    chown root:33 "$marker"
    chmod 0640 "$marker"
    return 0
  fi
  chmod 0750 "$PROJECT_ROOT/scripts/coreai-updater.sh"
  for unit in "${units[@]}"; do
    unit_tmp="$(mktemp)"
    TEMP_FILES+=("$unit_tmp")
    sed "s|@@PROJECT_ROOT@@|$PROJECT_ROOT|g" "$PROJECT_ROOT/config/systemd/$unit.template" > "$unit_tmp"
    install -m 0644 -o root -g root "$unit_tmp" "/etc/systemd/system/$unit"
  done
  systemctl daemon-reload
  systemctl enable --now coreai-update.path coreai-update-check.path coreai-update-check.timer >/dev/null 2>&1 \
    || { warn 'Update services could not be enabled.'; return 0; }
  printf '{"installed": true, "units": "systemd", "version": "%s"}\n' "$COREUI_VERSION" > "$marker"
  chown root:33 "$marker"
  chmod 0640 "$marker"
  log 'Update service active: checks for new merges every 10 minutes and starts from the Update window.'
}

# 0.6.96: independent owner-only uninstall runner; no PHP shell execution.
ensure_uninstall_service() {
  local dir="$PROJECT_ROOT/var/uninstall-request"
  local marker="$dir/runner.json"
  local unit tmp
  [[ "$PROJECT_ROOT" == '/opt/ember-coreui' ]] || {
    warn 'The uninstall host service supports only the official default directory.'; return 0;
  }
  [[ ! -L "$dir" && ! -L "$marker" ]] || die 'Unsafe uninstall request path.'
  install -d -m 0770 -o 33 -g 33 "$dir"
  # An old positive marker must never falsely advertise a disabled host service.
  printf '{"installed":false,"version":"%s"}\n' "$COREUI_VERSION" > "$marker"
  chown root:33 "$marker"
  chmod 0640 "$marker"
  if ! command -v systemctl >/dev/null 2>&1 || [[ ! -d /run/systemd/system ]]; then
    warn 'systemd is unavailable: CoreAI can be uninstalled using the SSH uninstall one-liner.'
    return 0
  fi
  if [[ -e "$dir/request.json" || -L "$dir/request.json" ]]; then
    warn 'An old uninstall request was found; the host service was not enabled for security reasons.'
    return 0
  fi
  chmod 0750 "$PROJECT_ROOT/scripts/uninstall.sh"
  for unit in coreai-uninstall.service coreai-uninstall.path; do
    tmp="$(mktemp)"
    TEMP_FILES+=("$tmp")
    sed "s|@@PROJECT_ROOT@@|$PROJECT_ROOT|g" "$PROJECT_ROOT/config/systemd/$unit.template" > "$tmp"
    install -m 0644 -o root -g root "$tmp" "/etc/systemd/system/$unit"
  done
  systemctl daemon-reload
  if ! systemctl enable --now coreai-uninstall.path >/dev/null 2>&1; then
    warn 'The uninstall host service could not be enabled; the SSH fallback remains available.'
    return 0
  fi
  printf '{"installed":true,"version":"%s"}\n' "$COREUI_VERSION" > "$marker"
  chown root:33 "$marker"
  chmod 0640 "$marker"
  log 'Uninstall host service active; owner-only with CSRF token, password and confirmation.'
}

ensure_ollama_model() {
  local install_tmp
  local owned_model=''
  local base_new=0

  if ! command -v ollama >/dev/null 2>&1; then
    is_enabled "$INSTALL_OLLAMA" || die 'Ollama is missing. Install it or set COREUI_INSTALL_OLLAMA=1.'
    log 'Installing Ollama using the official Linux installer.'
    install_tmp="$(mktemp)"
    TEMP_FILES+=("$install_tmp")
    curl -fsSL https://ollama.com/install.sh -o "$install_tmp"
    sh "$install_tmp"
    if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files ollama.service >/dev/null 2>&1; then
      systemctl enable --now ollama
    fi
  fi

  wait_http 'http://127.0.0.1:11434/api/tags' 30 \
    || die 'Ollama is not responding on 127.0.0.1:11434. The parallel installer does not reconfigure an existing Ollama service.'

  if [[ -z "$BASE_MODEL" ]]; then
    BASE_MODEL="gemma4:e4b"
  fi
  [[ "$MODEL_NAME" != "$BASE_MODEL" ]] \
    || die 'COREUI_MODEL_NAME must differ from the base model in isolated mode.'

  [[ -f "$MODEL_OWNER_FILE" ]] && owned_model="$(tr -d '\r\n' < "$MODEL_OWNER_FILE")"
  if ollama show "$MODEL_NAME" >/dev/null 2>&1 \
      && [[ "$owned_model" != "$MODEL_NAME" ]] \
      && ! is_enabled "$ADOPT_MODEL"; then
    die "Model $MODEL_NAME already exists but is not marked as owned by CoreAI: TCI Interface. Choose another name or explicitly set COREUI_ADOPT_MODEL=1."
  fi

  if ! ollama show "$BASE_MODEL" >/dev/null 2>&1; then
    log "Downloading temporary Ollama base model $BASE_MODEL."
    ollama pull "$BASE_MODEL"
    base_new=1
  else
    log "Ollama base model $BASE_MODEL already exists and is kept for safety."
  fi

  sed "s|^FROM gemma4:e4b$|FROM $BASE_MODEL|" \
    "$PROJECT_ROOT/config/Modelfile.template" > "$PROJECT_ROOT/var/Modelfile.generated"
  chown root:33 "$PROJECT_ROOT/var/Modelfile.generated"
  chmod 0640 "$PROJECT_ROOT/var/Modelfile.generated"

  log "Creating separate Ollama model $MODEL_NAME aus $BASE_MODEL."
  ollama create "$MODEL_NAME" -f "$PROJECT_ROOT/var/Modelfile.generated"
  ollama show "$MODEL_NAME" >/dev/null 2>&1 || die "The new TCI Core $MODEL_NAME could not be verified. The base model will be preserved."
  log "Custom Ollama Modelfile for $MODEL_NAME contains no PARAMETER overrides."
  if (( base_new == 1 )) && [[ "$BASE_MODEL" == gemma4:* ]]; then
    if ollama rm "$BASE_MODEL"; then
      log "Removed temporary Ollama base tag $BASE_MODEL after the Core was successfully verified."
    else
      warn "Core $MODEL_NAME is ready, but temporary base tag $BASE_MODEL could not be removed."
    fi
  fi
  # The upstream FROM model may itself carry defaults, which Ollama can
  # display in 'show --modelfile'. Those are not injected by CoreUI.
  printf '%s\n' "$MODEL_NAME" > "$MODEL_OWNER_FILE"
  chown root:33 "$MODEL_OWNER_FILE"
  chmod 0640 "$MODEL_OWNER_FILE"
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Run this installer with sudo or as root.'
[[ -f "$PROJECT_ROOT/app.html" ]] || die 'app.html is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/settings.html" ]] || die 'settings.html is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/config/runtime/nginx.conf.template" ]] || die 'Runtime Nginx template is missing.'
[[ -f "$PROJECT_ROOT/config/systemd/coreai-update.service.template" ]] || die 'Update service templates are missing.'
[[ -f "$PROJECT_ROOT/scripts/coreai-updater.sh" ]] || die 'scripts/coreai-updater.sh is missing.'
[[ -f "$COMPOSE_FILE" ]] || die 'compose.yaml is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/scripts/migrate.sh" ]] || die 'scripts/migrate.sh is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/database/migrations/001_core.sql" ]] || die 'SQL base schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/002_coreui_management.sql" ]] || die 'SQL management schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/003_console_sessions.sql" ]] || die 'SQL sessions schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/004_profiles_knowledge.sql" ]] || die 'SQL profile and knowledge schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql" ]] || die 'SQL thinking and attachments schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/006_account_security.sql" ]] || die 'SQL account security schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/007_remove_private_studio_lore.sql" ]] || die 'SQL privacy migration is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/008_message_actions.sql" ]] || die 'SQL message actions schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/009_message_editing.sql" ]] || die 'SQL message editing schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/011_console_artifacts.sql" ]] || die 'SQL artifact output schema is missing.'
[[ -f "$PROJECT_ROOT/database/migrations/012_console_work_events.sql" ]] || die 'SQL work events are missing.'
[[ "$PROJECT_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'The project path contains unsupported characters.'
[[ "$PROJECT_ROOT" != /root/* ]] || die 'Move the package permanently to /opt/ember-coreui.'
[[ "$PROJECT_ROOT" != /home/* ]] || die 'Move the package permanently to /opt/ember-coreui.'

# Remove only the two known legacy files from accidental alpha packages.
# Andere Betreiber-Dokumente im docs-Verzeichnis bleiben erhalten.
rm -f -- \
  "$PROJECT_ROOT/docs/S.U. MASTER BIBEL v10.4.docx" \
  "$PROJECT_ROOT/docs/STU_KOMPENDIUM_V6.docx"

if [[ -r /etc/os-release ]]; then
  # shellcheck disable=SC1091
  source /etc/os-release
else
  die '/etc/os-release is missing. Supported distributions: Debian and Ubuntu.'
fi
case "${ID:-}:${ID_LIKE:-}" in
  debian:*|ubuntu:*|*:debian*) ;;
  *) die "Unsupported operating system: ${PRETTY_NAME:-unknown}. Use Debian 12+ or Ubuntu 24.04+." ;;
esac

# Muss vor der Erzeugung neuer Zugangsdaten laufen, da minimale Server-Images
# openssl und selbst curl nicht zwingend vorinstalliert haben.
ensure_host_tools

REQUEST_HTTP_PORT="${COREUI_HTTP_PORT:-}"
REQUEST_PHP_PORT="${COREUI_PHP_PORT:-}"
REQUEST_DB_PORT="${COREUI_DB_PORT:-}"
REQUEST_SEARXNG_PORT="${COREUI_SEARXNG_PORT:-}"
REQUEST_DB_NAME="${COREUI_DB_NAME:-}"
REQUEST_DB_USER="${COREUI_DB_USER:-}"
REQUEST_DB_PASS="${COREUI_DB_PASS:-}"
REQUEST_DB_ROOT_PASS="${COREUI_DB_ROOT_PASS:-}"
REQUEST_MODEL_NAME="${COREUI_MODEL_NAME:-}"
REQUEST_LOCK_NAMESPACE="${COREUI_LOCK_NAMESPACE:-}"
REQUEST_BIND_ADDRESS="${COREUI_BIND_ADDRESS:-}"
REQUEST_INSTALL_SEARXNG="${COREUI_INSTALL_SEARXNG:-}"
REQUEST_INSTALL_BROWSE="${COREUI_INSTALL_BROWSE:-}"
REQUEST_INSTALL_VOICE="${COREUI_INSTALL_VOICE:-}"
REQUEST_INSTALL_IMAGE="${COREUI_INSTALL_IMAGE:-}"
REQUEST_SEARXNG_URL="${COREUI_SEARXNG_URL:-}"

SAVED_HTTP_PORT="$(read_saved_env COREUI_HTTP_PORT || true)"
SAVED_PHP_PORT="$(read_saved_env COREUI_PHP_PORT || true)"
SAVED_DB_PORT="$(read_saved_env COREUI_DB_PORT || true)"
SAVED_SEARXNG_PORT="$(read_saved_env COREUI_SEARXNG_PORT || true)"
SAVED_DB_NAME="$(read_saved_env COREUI_DB_NAME || true)"
SAVED_DB_USER="$(read_saved_env COREUI_DB_USER || true)"
SAVED_DB_PASS="$(read_saved_env COREUI_DB_PASS || true)"
SAVED_DB_ROOT_PASS="$(read_saved_env COREUI_DB_ROOT_PASS || true)"
SAVED_MODEL_NAME="$(read_saved_env COREUI_MODEL_NAME || true)"
SAVED_LOCK_NAMESPACE="$(read_saved_env COREUI_LOCK_NAMESPACE || true)"
SAVED_BIND_ADDRESS="$(read_saved_env COREUI_BIND_ADDRESS || true)"
SAVED_INSTALL_SEARXNG="$(read_saved_env COREUI_INSTALL_SEARXNG || true)"
SAVED_INSTALL_BROWSE="$(read_saved_env COREUI_INSTALL_BROWSE || true)"
SAVED_INSTALL_VOICE="$(read_saved_env COREUI_INSTALL_VOICE || true)"
SAVED_INSTALL_IMAGE="$(read_saved_env COREUI_INSTALL_IMAGE || true)"
SAVED_SEARXNG_URL="$(read_saved_env COREUI_SEARXNG_URL || true)"
SAVED_SEARXNG_SECRET="$(read_saved_env COREUI_SEARXNG_SECRET || true)"
SAVED_HF_TOKEN="$(read_saved_env HF_TOKEN || true)"

if [[ -e "$PROJECT_ROOT/var/mariadb/ibdata1" || -d "$PROJECT_ROOT/var/mariadb/mysql" ]]; then
  if [[ -n "$REQUEST_DB_NAME" && "$REQUEST_DB_NAME" != "$SAVED_DB_NAME" \
      || -n "$REQUEST_DB_USER" && "$REQUEST_DB_USER" != "$SAVED_DB_USER" \
      || -n "$REQUEST_DB_PASS" && "$REQUEST_DB_PASS" != "$SAVED_DB_PASS" \
      || -n "$REQUEST_DB_ROOT_PASS" && "$REQUEST_DB_ROOT_PASS" != "$SAVED_DB_ROOT_PASS" ]]; then
    die 'Database names and credentials of an initialized container database cannot be rotated by the installer. Use a controlled MariaDB migration instead.'
  fi
fi

HTTP_PORT="${REQUEST_HTTP_PORT:-${SAVED_HTTP_PORT:-9080}}"
PHP_PORT="${REQUEST_PHP_PORT:-${SAVED_PHP_PORT:-19073}}"
DB_PORT="${REQUEST_DB_PORT:-${SAVED_DB_PORT:-13306}}"
SEARXNG_PORT="${REQUEST_SEARXNG_PORT:-${SAVED_SEARXNG_PORT:-18889}}"
DB_NAME="${REQUEST_DB_NAME:-${SAVED_DB_NAME:-ember_coreui}}"
DB_USER="${REQUEST_DB_USER:-${SAVED_DB_USER:-ember_coreui}}"
DB_PASS="${REQUEST_DB_PASS:-${SAVED_DB_PASS:-$(openssl rand -hex 24)}}"
DB_ROOT_PASS="${REQUEST_DB_ROOT_PASS:-${SAVED_DB_ROOT_PASS:-$(openssl rand -hex 24)}}"
MODEL_NAME="${REQUEST_MODEL_NAME:-${SAVED_MODEL_NAME:-ember-core:e4b}}"
LOCK_NAMESPACE="${REQUEST_LOCK_NAMESPACE:-${SAVED_LOCK_NAMESPACE:-ember_coreui}}"
BIND_ADDRESS="${REQUEST_BIND_ADDRESS:-${SAVED_BIND_ADDRESS:-127.0.0.1}}"
INSTALL_SEARXNG="${REQUEST_INSTALL_SEARXNG:-${SAVED_INSTALL_SEARXNG:-1}}"
INSTALL_BROWSE="${REQUEST_INSTALL_BROWSE:-${SAVED_INSTALL_BROWSE:-1}}"
# 0.6.1: Maras Stimme (Vorlesen). Standard an; COREUI_INSTALL_VOICE=0 laesst sie weg.
INSTALL_VOICE="${REQUEST_INSTALL_VOICE:-${SAVED_INSTALL_VOICE:-1}}"
# 0.6.39: Maras Bilder (FLUX.2-klein 4B, bis etwa 14 GB RAM je Bild). Standard an ab
# 32 GB Arbeitsspeicher; COREUI_INSTALL_IMAGE=0 laesst den Bilddienst weg, =1 erzwingt ihn.
MEM_TOTAL_GB="$(awk '/^MemTotal:/ {printf "%d", $2/1048576}' /proc/meminfo 2>/dev/null || printf '0')"
[[ "$MEM_TOTAL_GB" =~ ^[0-9]+$ ]] || MEM_TOTAL_GB=0
if (( MEM_TOTAL_GB >= 31 )); then IMAGE_DEFAULT=1; else IMAGE_DEFAULT=0; fi
INSTALL_IMAGE="${REQUEST_INSTALL_IMAGE:-${SAVED_INSTALL_IMAGE:-$IMAGE_DEFAULT}}"
INSTALL_DOCKER="${COREUI_INSTALL_DOCKER:-1}"
INSTALL_OLLAMA="${COREUI_INSTALL_OLLAMA:-1}"
FORCE_CONFIG="${COREUI_FORCE_CONFIG:-0}"
SKIP_BOOTSTRAP="${COREUI_SKIP_BOOTSTRAP:-0}"
ADOPT_MODEL="${COREUI_ADOPT_MODEL:-0}"
BASE_MODEL="${COREUI_BASE_MODEL:-}"
PUBLIC_URL="${COREUI_PUBLIC_URL:-http://127.0.0.1:$HTTP_PORT}"
THREADS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '4')"
[[ "$THREADS" =~ ^[0-9]+$ ]] || THREADS=4

if [[ -f "$CONFIG_FILE" && -f "$ENV_FILE" && "$FORCE_CONFIG" != '1' ]]; then
  if [[ -n "$REQUEST_HTTP_PORT" \
      || -n "$REQUEST_PHP_PORT" \
      || -n "$REQUEST_DB_PORT" \
      || -n "$REQUEST_SEARXNG_PORT" \
      || -n "$REQUEST_DB_NAME" \
      || -n "$REQUEST_DB_USER" \
      || -n "$REQUEST_DB_PASS" \
      || -n "$REQUEST_DB_ROOT_PASS" \
      || -n "$REQUEST_MODEL_NAME" \
      || -n "$REQUEST_LOCK_NAMESPACE" \
      || -n "$REQUEST_BIND_ADDRESS" \
      || -n "$REQUEST_INSTALL_SEARXNG" \
      || -n "$REQUEST_INSTALL_BROWSE" \
      || -n "$REQUEST_INSTALL_VOICE" \
      || -n "$REQUEST_INSTALL_IMAGE" \
      || -n "$REQUEST_SEARXNG_URL" \
      || -n "${COREUI_PUBLIC_URL:-}" ]]; then
    die 'Configuration values have changed for an existing installation. Back up the current state and explicitly retry with COREUI_FORCE_CONFIG=1.'
  fi
fi

if is_enabled "$INSTALL_SEARXNG"; then
  SEARXNG_URL="${REQUEST_SEARXNG_URL:-${SAVED_SEARXNG_URL:-http://127.0.0.1:$SEARXNG_PORT/search}}"
else
  SEARXNG_URL="${REQUEST_SEARXNG_URL:-${SAVED_SEARXNG_URL:-}}"
fi
SEARXNG_SECRET="${SAVED_SEARXNG_SECRET:-$(openssl rand -hex 32)}"
# Preserve an existing instance's HF access across installer/update runs.
# A newly provided HF_TOKEN overrides the saved secret; never log either.
IMAGE_HF_TOKEN="${HF_TOKEN:-$SAVED_HF_TOKEN}"
# Reuse an already authenticated operator's local Hugging Face CLI token.
# No extra login is needed if the original image-spike setup used hf auth login.
if [[ -z "$IMAGE_HF_TOKEN" ]]; then
  for hf_file in "${HF_HOME:-/root/.cache/huggingface}/token" /root/.cache/huggingface/token /root/.huggingface/token; do
    if [[ -f "$hf_file" && ! -L "$hf_file" ]]; then
      candidate="$(head -n 1 "$hf_file" 2>/dev/null || true)"
      if [[ "$candidate" =~ ^[A-Za-z0-9_-]{20,256}$ ]]; then
        IMAGE_HF_TOKEN="$candidate"
        break
      fi
    fi
  done
  unset candidate hf_file
fi
if [[ -n "$IMAGE_HF_TOKEN" && ! "$IMAGE_HF_TOKEN" =~ ^[A-Za-z0-9_-]{20,256}$ ]]; then
  die 'HF_TOKEN is invalid (character format check only).'
fi

for port in "$HTTP_PORT" "$PHP_PORT" "$DB_PORT" "$SEARXNG_PORT"; do
  valid_port "$port" || die "Invalid or privileged port: $port"
done
[[ "$BIND_ADDRESS" == '127.0.0.1' || "$BIND_ADDRESS" == '0.0.0.0' ]] \
  || die 'COREUI_BIND_ADDRESS must be 127.0.0.1 or 0.0.0.0.'
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die 'Invalid database name.'
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die 'Invalid database username.'
[[ "$DB_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'Invalid database password for the automated installer.'
[[ "$DB_ROOT_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'Invalid database root password for the automated installer.'
[[ "$MODEL_NAME" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_MODEL_NAME contains invalid characters.'
[[ -z "$BASE_MODEL" || "$BASE_MODEL" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_BASE_MODEL contains invalid characters.'
[[ "$LOCK_NAMESPACE" =~ ^[A-Za-z0-9_.:-]{1,40}$ ]] || die 'COREUI_LOCK_NAMESPACE contains invalid characters.'
[[ "$SEARXNG_URL" != *$'\n'* && "$SEARXNG_URL" != *$'\r'* ]] || die 'Invalid SearXNG URL.'

if is_enabled "$INSTALL_BROWSE" && [[ -z "$SEARXNG_URL" ]]; then
  die 'The browser worker requires a local or external SearXNG URL.'
fi
if [[ -f "$CONFIG_FILE" && ! -f "$ENV_FILE" && "$FORCE_CONFIG" != '1' ]]; then
  die 'An unowned api/config.local.php exists. Use COREUI_FORCE_CONFIG=1 only after backup and explicit review.'
fi

ADMIN_EMAIL="${COREUI_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${COREUI_ADMIN_PASSWORD:-}"
ADMIN_NAME="${COREUI_ADMIN_NAME:-Operator}"
ADMIN_GENDER="${COREUI_ADMIN_GENDER:-}"

if ! is_enabled "$SKIP_BOOTSTRAP"; then
  if [[ -z "$ADMIN_EMAIL" ]]; then
    [[ -t 0 ]] || die 'COREUI_ADMIN_EMAIL is required for non-interactive installation.'
    read -r -p 'Admin email: ' ADMIN_EMAIL
  fi
  if [[ -z "$ADMIN_PASSWORD" ]]; then
    [[ -t 0 ]] || die 'COREUI_ADMIN_PASSWORD is required for non-interactive installation.'
    read -r -s -p 'Admin password, at least 12 characters: ' ADMIN_PASSWORD
    printf '\n'
    read -r -s -p 'Repeat admin password: ' ADMIN_PASSWORD_CONFIRM
    printf '\n'
    [[ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]] || die 'Passwords do not match.'
  fi
  [[ ${#ADMIN_PASSWORD} -ge 12 ]] || die 'The admin password must be at least 12 characters long.'
  [[ "$ADMIN_GENDER" == '' || "$ADMIN_GENDER" == 'm' || "$ADMIN_GENDER" == 'f' ]] \
    || die 'COREUI_ADMIN_GENDER must be empty, m or f.'
fi

log "Preparing isolated parallel operation for CoreAI: TCI Interface $COREUI_VERSION vor."
ensure_docker
prepare_permissions
log 'Updating numeric content cache fingerprints for CSS and JavaScript.'
python3 "$PROJECT_ROOT/scripts/cache-bust.py" --root "$PROJECT_ROOT" || die 'Asset cache fingerprint update failed.'
assert_stack_identity
assert_ports_available
write_runtime_files

compose config >/dev/null || die 'The Compose configuration is invalid.'

log 'Checking packaged Python sources.'
while IFS= read -r -d '' py_file; do
  python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); compile(p.read_text(encoding="utf-8"), str(p), "exec")' "$py_file" \
    || die "Python syntax error in $py_file"
done < <(find "$PROJECT_ROOT/tools" -type f -name '*.py' -print0)

log 'Building isolated PHP, Python worker, and optional browser images.'
compose build php pyworker
# Verify the freshly BUILT image, not only the existing php service.
# A stale container may still run an older image with missing PDO support.
log 'Checking pdo_mysql in the newly built PHP image.'
if ! compose run -T --rm --no-deps php php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'; then
  die 'pdo_mysql is missing from the new PHP image. PHP build failed; existing data is unchanged.'
fi
if is_enabled "$INSTALL_BROWSE"; then
  compose build browse
fi
# 0.6.1: Maras Stimme. Optional: scheitert der Bau (etwa ohne Netz zum
# PyTorch-Index), laeuft CoreAI ohne Vorlesen weiter.
VOICE_READY=0
if is_enabled "$INSTALL_VOICE"; then
  log 'Building the voice service (Mara’s voice Eve, Pocket TTS on CPU).'
  if compose build voice; then
    VOICE_READY=1
  else
    warn 'Voice service could not be built; CoreAI will continue without text-to-speech.'
  fi
fi
# 0.6.39: Maras Bilder. Optional: stable-diffusion.cpp wird fuer genau diese CPU gebaut
# (beim ersten Mal 10 bis 20 Minuten, danach aus dem Docker-Cache). Scheitert der Bau,
# laeuft CoreAI ohne Bilder weiter.
IMAGE_READY=0
if is_enabled "$INSTALL_IMAGE"; then
  log 'Building the image service (stable-diffusion.cpp for this CPU, FLUX.2-klein 4B).'
  if compose build image; then
    IMAGE_READY=1
  else
    warn 'Image service could not be built; CoreAI will continue without image generation.'
  fi
fi
log 'Building disposable Python sandbox image (with Node.js), without project or secret mounts.'
docker build -t ember-py:2 -f "$PROJECT_ROOT/tools/ember_py.Dockerfile" "$PROJECT_ROOT/tools"

# Same PHP lint stage and order; report exact file and PHP parser failure.
compose run -T --rm --no-deps php sh -ec '
  find api scripts tools -type f -name "*.php" -exec sh -c '\'' 
    for file do
      if ! php -l "$file" >/dev/null 2>&1; then
        printf "[PHP-SYNTAX-ERROR] %s\\n" "$file" >&2
        php -l "$file" >&2 || true
        exit 1
      fi
    done
  '\'' sh {} +
' || die 'PHP syntax check failed; see filename and error above.'

ensure_ollama_model
# The FLUX.2 generator shares the normal installer/update lifecycle (not an admin-only step).
# An already installed base is adapted automatically. A first download needs license acceptance.
if ! python3 -I "$PROJECT_ROOT/scripts/ensure-generator-core.py" --ensure; then
  warn 'TCI Core Generator could not be created or updated. Preflight will report its status.'
fi

log 'Starting the dedicated CoreAI: TCI Interface database on its protected loopback port.'
compose up -d database
if ! wait_healthy database 75; then
  compose logs --tail=100 database >&2 || true
  die 'The isolated CoreAI: TCI Interface database did not become healthy.'
fi

log 'Applying all CoreAI: TCI Interface migrations to the dedicated container database.'
# This must work even when older source checkouts have no executable bit.
bash "$PROJECT_ROOT/scripts/migrate.sh"

if ! is_enabled "$SKIP_BOOTSTRAP"; then
  log 'Initializing Mara CoreAI and the CoreAI: TCI Interface administrator account.'
  compose run -T --rm --no-deps \
    -e "COREUI_ADMIN_EMAIL=$ADMIN_EMAIL" \
    -e "COREUI_ADMIN_PASSWORD=$ADMIN_PASSWORD" \
    -e "COREUI_ADMIN_NAME=$ADMIN_NAME" \
    -e "COREUI_ADMIN_GENDER=$ADMIN_GENDER" \
    php php scripts/bootstrap.php
else
  warn 'Account bootstrap was skipped because COREUI_SKIP_BOOTSTRAP=1.'
fi

if is_enabled "$INSTALL_SEARXNG"; then
  log "Starting the dedicated SearXNG instance on 127.0.0.1:$SEARXNG_PORT."
  compose up -d searxng
  wait_http "http://127.0.0.1:$SEARXNG_PORT/search?q=ember&format=json" 60 \
    || die 'The isolated SearXNG instance is not returning JSON.'
else
  compose stop searxng >/dev/null 2>&1 || true
fi

log "Starting CoreAI: TCI Interface web and PHP on $BIND_ADDRESS:$HTTP_PORT."
# Force the running PHP service to use the just validated image. Volumes,
# MariaDB, uploads and all other Compose stacks are preserved.
compose up -d --force-recreate php web
if ! compose exec -T php php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'; then
  compose logs --tail=100 php >&2 || true
  die 'pdo_mysql is still missing from the running PHP container after update.'
fi
if ! wait_healthy php 45; then
  compose logs --tail=100 php >&2 || true
  die 'The isolated CoreAI: TCI Interface PHP service did not become healthy.'
fi
if ! wait_http "http://127.0.0.1:$HTTP_PORT/api/health.php" 45; then
  compose logs --tail=100 web php >&2 || true
  die 'The CoreAI: TCI Interface health check failed.'
fi

if is_enabled "$INSTALL_BROWSE"; then
  # 0.7.38: recreated like php and web, so an update's worker code (Deep Research) runs at once.
  compose up -d --force-recreate browse
else
  compose stop browse >/dev/null 2>&1 || true
fi

log 'Starting the isolated CoreAI: TCI Interface Python worker.'
compose up -d pyworker
# 0.7.79: ember-py:2 replaces ember-py:1 (Node.js added). Only now, with the worker on the new tag,
# the old image goes; a container that still uses it keeps it (no error).
docker image rm ember-py:1 >/dev/null 2>&1 || true

if [[ "$VOICE_READY" == '1' ]]; then
  # Beim ersten Start laedt der Dienst Eves Gewichte (etwa 0,7 GB) und prueft jede Datei gegen ihren SHA-256.
  log 'Starting the voice service (Mara’s voice).'
  compose up -d voice || warn 'Voice service did not start; CoreAI will continue without text-to-speech.'
else
  compose stop voice >/dev/null 2>&1 || true
fi

if [[ "$IMAGE_READY" == '1' ]]; then
  # Reuse image weights already loaded by the image test rather than reloading them
  # (etwa 9 GB). Fehlende laedt der Dienst selbst; er prueft jede Datei gegen ihren SHA-256.
  python3 -I "$PROJECT_ROOT/tools/image/adopt_spike.py" "$PROJECT_ROOT/config/image/mara-image.json" \
    "$PROJECT_ROOT/var/image-spike/models" "$PROJECT_ROOT/var/image-models" \
    || warn 'Weights from the image test could not be reused; the image service will load them itself.'
  # Reuse the successful 9B picture-generator benchmark when available.
  # The optional 9B profile is license-bound, SHA-256 checked and hardlinked.
  python3 -I "$PROJECT_ROOT/tools/image/adopt_pgen.py" \
    || warn 'pGen 9B could not be reused from the image test; 4B remains the default.'
  log 'Starting the image service (Mara’s images).'
  compose up -d image || warn 'Image service did not start; CoreAI will continue without image generation.'
else
  compose stop image >/dev/null 2>&1 || true
fi

chmod 0750 "$PROJECT_ROOT/scripts/install.sh" "$PROJECT_ROOT/scripts/preflight.sh" \
  "$PROJECT_ROOT/scripts/install-native.sh" "$PROJECT_ROOT/scripts/preflight-native.sh" \
  "$PROJECT_ROOT/scripts/stack.sh" "$PROJECT_ROOT/scripts/migrate.sh" \
  "$PROJECT_ROOT/scripts/coreai-updater.sh" 2>/dev/null || true

ensure_update_service
ensure_uninstall_service

log "Installation successful. Local endpoint: http://127.0.0.1:$HTTP_PORT/"
# Optional host safeguard after successful stack start; no existing swap is changed.
coreui_ensure_host_swap || warn "Optional host swap configuration failed."
if [[ "$BIND_ADDRESS" == '127.0.0.1' ]]; then
  log "To test securely from your own computer: ssh -L $HTTP_PORT:127.0.0.1:$HTTP_PORT USER@SERVER"
fi
log 'Existing Nginx, Apache, PHP-FPM, MariaDB, Game, Ember, and homepage configurations were not modified.'
log 'Only the existing Ollama service is shared as a local model interface.'
