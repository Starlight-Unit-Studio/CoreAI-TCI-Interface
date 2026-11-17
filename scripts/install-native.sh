#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 027

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$PROJECT_ROOT/scripts/lib/host-swap.sh"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
COREUI_VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
CONFIG_TMP=''
DB_CLIENT_TMP=''
OLLAMA_INSTALL_TMP=''

cleanup() {
  [[ -z "$CONFIG_TMP" || ! -f "$CONFIG_TMP" ]] || rm -f -- "$CONFIG_TMP"
  [[ -z "$DB_CLIENT_TMP" || ! -f "$DB_CLIENT_TMP" ]] || rm -f -- "$DB_CLIENT_TMP"
  [[ -z "$OLLAMA_INSTALL_TMP" || ! -f "$OLLAMA_INSTALL_TMP" ]] || rm -f -- "$OLLAMA_INSTALL_TMP"
}
trap cleanup EXIT

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

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

php_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

read_constant() {
  local key="$1"
  COREUI_READ_CONFIG="$CONFIG_FILE" COREUI_READ_KEY="$key" php -r '
    $file = getenv("COREUI_READ_CONFIG");
    $key = getenv("COREUI_READ_KEY");
    require $file;
    if (!defined($key)) exit(3);
    $value = constant($key);
    if (is_bool($value)) echo $value ? "1" : "0";
    elseif (is_scalar($value)) echo (string)$value;
  '
}

wait_http() {
  local url="$1"
  local attempts="${2:-30}"
  local count=0
  while (( count < attempts )); do
    if curl -fsS --max-time 4 "$url" >/dev/null 2>&1; then
      return 0
    fi
    count=$((count + 1))
    sleep 2
  done
  return 1
}

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Run this installer with sudo or as root.'
[[ "${COREUI_NATIVE_CONFIRM:-}" == 'DEDICATED_SERVER' ]] \
  || die 'The native installer is intended only for a dedicated, empty server. Use scripts/install.sh by default, or explicitly confirm with COREUI_NATIVE_CONFIRM=DEDICATED_SERVER.'
[[ -f "$PROJECT_ROOT/app.html" ]] || die 'app.html is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/settings.html" ]] || die 'settings.html is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/admin/index.html" ]] || die 'admin/index.html is missing. The installation package is incomplete.'
[[ -f "$PROJECT_ROOT/config/nginx.conf.template" ]] || die 'Nginx-Template is missing.'
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
[[ -f "$PROJECT_ROOT/database/migrations/013_console_agent_runs.sql" ]] || die 'SQL agent runs schema is missing.'
[[ -f "$PROJECT_ROOT/api/console_model_queue.php" ]] || die 'Model queue implementation is missing.'
[[ -f "$PROJECT_ROOT/scripts/model-queue-selftest.php" ]] || die 'Model queue self-test is missing.'
[[ "$PROJECT_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] || die 'The project path may contain only letters, digits, dots, underscores, hyphens and forward slashes.'
[[ "$PROJECT_ROOT" != /root/* ]] || die 'Move the package to /opt/ember-coreui first. Nginx must not serve files from /root.'
[[ "$PROJECT_ROOT" != /home/* ]] || die 'Move the package to /opt/ember-coreui first. The hardened worker isolates home directories.'

rm -f -- \
  "$PROJECT_ROOT/docs/S.U. MASTER BIBEL v10.4.docx" \
  "$PROJECT_ROOT/docs/STU_KOMPENDIUM_V6.docx"

if [[ -r /etc/os-release ]]; then
  # shellcheck disable=SC1091
  source /etc/os-release
else
  die '/etc/os-release is missing. Unterstuetzt werden Debian und Ubuntu.'
fi
case "${ID:-}:${ID_LIKE:-}" in
  debian:*|ubuntu:*|*:debian*) ;;
  *) die "Unsupported operating system: ${PRETTY_NAME:-unknown}. Use Debian 12+ or Ubuntu 24.04+." ;;
esac

SERVER_NAME="${COREUI_SERVER_NAME:-_}"
PUBLIC_URL="${COREUI_PUBLIC_URL:-}"
INSTALL_OLLAMA="${COREUI_INSTALL_OLLAMA:-1}"
INSTALL_SEARXNG="${COREUI_INSTALL_SEARXNG:-1}"
INSTALL_BROWSE="${COREUI_INSTALL_BROWSE:-1}"
FORCE_CONFIG="${COREUI_FORCE_CONFIG:-0}"
BASE_MODEL="${COREUI_BASE_MODEL:-}"
MODEL_NAME="${COREUI_MODEL_NAME:-ember-core:e4b}"
LOCK_NAMESPACE="${COREUI_LOCK_NAMESPACE:-ember_coreui_native}"
SEARXNG_URL="${COREUI_SEARXNG_URL:-http://127.0.0.1:8888/search}"

[[ "$SERVER_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || die 'COREUI_SERVER_NAME contains invalid characters.'
[[ "$MODEL_NAME" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_MODEL_NAME contains invalid characters.'
[[ -z "$BASE_MODEL" || "$BASE_MODEL" =~ ^[A-Za-z0-9._:/-]+$ ]] || die 'COREUI_BASE_MODEL contains invalid characters.'
[[ "$LOCK_NAMESPACE" =~ ^[A-Za-z0-9_.:-]{1,40}$ ]] || die 'COREUI_LOCK_NAMESPACE contains invalid characters.'

if [[ -z "$PUBLIC_URL" ]]; then
  if [[ "$SERVER_NAME" == '_' ]]; then
    PUBLIC_URL='http://localhost'
  else
    PUBLIC_URL="http://$SERVER_NAME"
  fi
fi

ADMIN_EMAIL="${COREUI_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${COREUI_ADMIN_PASSWORD:-}"
ADMIN_NAME="${COREUI_ADMIN_NAME:-Operator}"
ADMIN_GENDER="${COREUI_ADMIN_GENDER:-}"

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
[[ "$ADMIN_GENDER" == '' || "$ADMIN_GENDER" == 'm' || "$ADMIN_GENDER" == 'f' ]] || die 'COREUI_ADMIN_GENDER must be empty, m or f.'

log "Installing system packages for CoreAI: TCI Interface $COREUI_VERSION."
export DEBIAN_FRONTEND=noninteractive
apt-get update

PACKAGES=(
  ca-certificates
  curl
  docker.io
  ffmpeg
  mariadb-client
  mariadb-server
  nginx
  openssl
  poppler-utils
  php-cli
  php-curl
  php-fpm
  php-gd
  php-mbstring
  php-mysql
  php-xml
  php-zip
  python3-pip
  python3-pymysql
  python3-venv
  unzip
)
apt-get install -y "${PACKAGES[@]}"

command -v php >/dev/null || die 'PHP was not found.'
command -v mariadb >/dev/null || die 'MariaDB client was not found.'
command -v nginx >/dev/null || die 'Nginx was not found.'

log 'Updating numeric content cache fingerprints for CSS and JavaScript.'
python3 "$PROJECT_ROOT/scripts/cache-bust.py" --root "$PROJECT_ROOT" || die 'Asset cache fingerprint update failed.'

log 'Checking packaged PHP and Python sources.'
while IFS= read -r -d '' php_file; do
  php -l "$php_file" >/dev/null || die "PHP syntax error in $php_file"
done < <(find "$PROJECT_ROOT/api" "$PROJECT_ROOT/scripts" "$PROJECT_ROOT/tools" -type f -name '*.php' -print0)
while IFS= read -r -d '' py_file; do
  python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); compile(p.read_text(encoding="utf-8"), str(p), "exec")' "$py_file" \
    || die "Python syntax error in $py_file"
done < <(find "$PROJECT_ROOT/tools" -type f -name '*.py' -print0)

systemctl enable --now mariadb

FPM_UNIT="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | sort -V | tail -n 1)"
[[ -n "$FPM_UNIT" ]] || die 'No PHP-FPM service found.'
systemctl enable --now "$FPM_UNIT"

PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -n 1)"
[[ -n "$PHP_SOCKET" ]] || die 'No PHP-FPM socket found under /run/php.'

PHP_VERSION_MM="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_INI_DIR="/etc/php/$PHP_VERSION_MM/fpm/conf.d"
[[ -d "$PHP_FPM_INI_DIR" ]] || die "PHP-FPM configuration directory missing: $PHP_FPM_INI_DIR"
cat > "$PHP_FPM_INI_DIR/99-ember-coreui.ini" <<'INI'
upload_max_filesize = 256M
post_max_size = 260M
max_execution_time = 900
max_input_time = 900
memory_limit = 768M
max_file_uploads = 10
session.cookie_httponly = 1
session.use_strict_mode = 1
expose_php = Off
INI
systemctl restart "$FPM_UNIT"

install -d -m 0770 -o www-data -g www-data \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos/pending" \
  "$PROJECT_ROOT/assets/profile_photos/approved"

REUSE_CONFIG=0
if [[ -f "$CONFIG_FILE" ]] && ! is_enabled "$FORCE_CONFIG"; then
  REUSE_CONFIG=1
  log 'Existing api/config.local.php will remain unchanged.'
  DB_HOST="$(read_constant STU_DB_HOST || true)"
  DB_PORT="$(read_constant STU_DB_PORT || true)"
  DB_NAME="$(read_constant STU_DB_NAME || true)"
  DB_USER="$(read_constant STU_DB_USER || true)"
  DB_PASS="$(read_constant STU_DB_PASS || true)"
  MODEL_NAME="$(read_constant STU_EMBER_MODEL || printf '%s' "$MODEL_NAME")"
  SEARXNG_URL="$(read_constant STU_EMBER_WEB_URL || printf '%s' "$SEARXNG_URL")"
  INSTALL_BROWSE="$(read_constant STU_EMBER_BROWSE_ENABLED || printf '%s' "$INSTALL_BROWSE")"
else
  DB_HOST="${COREUI_DB_HOST:-127.0.0.1}"
  DB_PORT="${COREUI_DB_PORT:-3306}"
  DB_NAME="${COREUI_DB_NAME:-ember_coreui}"
  DB_USER="${COREUI_DB_USER:-ember_coreui}"
  DB_PASS="${COREUI_DB_PASS:-$(openssl rand -hex 24)}"
fi

[[ "$DB_HOST" == '127.0.0.1' || "$DB_HOST" == 'localhost' ]] || die 'The automated installer supports only a local MariaDB at 127.0.0.1 or localhost.'
[[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || die 'Invalid database port.'
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die 'Invalid database name.'
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die 'Invalid database username.'
[[ -n "$DB_PASS" ]] || die 'The database password is empty.'
[[ "$DB_PASS" =~ ^[A-Za-z0-9._~!@%+=:-]+$ ]] || die 'The automated installer allows only safe ASCII characters in database passwords, without spaces or quotation marks.'

if (( REUSE_CONFIG == 0 )); then
  DB_HOST_PHP="$(php_escape "$DB_HOST")"
  DB_NAME_PHP="$(php_escape "$DB_NAME")"
  DB_USER_PHP="$(php_escape "$DB_USER")"
  DB_PASS_PHP="$(php_escape "$DB_PASS")"
  PUBLIC_URL_PHP="$(php_escape "$PUBLIC_URL")"
  MODEL_NAME_PHP="$(php_escape "$MODEL_NAME")"
  SEARXNG_URL_PHP="$(php_escape "$SEARXNG_URL")"
  SCREENSHOT_DIR_PHP="$(php_escape "$PROJECT_ROOT/uploads/ember_browse")"
  if is_enabled "$INSTALL_BROWSE"; then BROWSE_BOOL=true; else BROWSE_BOOL=false; fi
  if [[ -n "$SEARXNG_URL" ]]; then WEB_BOOL=true; else WEB_BOOL=false; fi
  THREADS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '4')"
  [[ "$THREADS" =~ ^[0-9]+$ ]] || THREADS=4

  CONFIG_TMP="$(mktemp)"
  cat > "$CONFIG_TMP" <<PHP
<?php
declare(strict_types=1);

/* Generated by CoreAI: TCI Interface scripts/install-native.sh. */
define('STU_DB_HOST', '$DB_HOST_PHP');
define('STU_DB_PORT', $DB_PORT);
define('STU_DB_NAME', '$DB_NAME_PHP');
define('STU_DB_USER', '$DB_USER_PHP');
define('STU_DB_PASS', '$DB_PASS_PHP');

define('STU_PUBLIC_BASE_URL', '$PUBLIC_URL_PHP');
define('STU_SESSION_NAME', 'EMBERCOREUISESSID');
define('STU_ALLOW_REGISTRATION', false);
define('STU_DEBUG', false);

define('STU_EMBER_ENABLED', true);
define('STU_EMBER_USER_ID', 1);
define('STU_EMBER_CHARACTER_ID', 'ember');
define('STU_EMBER_CHARACTER_NAME', 'Mara');
define('STU_EMBER_MODEL', '$MODEL_NAME_PHP');
define('STU_EMBER_OLLAMA_URL', 'http://127.0.0.1:11434/api/chat');
define('STU_EMBER_LOCK_NAMESPACE', '$LOCK_NAMESPACE');
define('STU_EMBER_NUM_THREAD', $THREADS);
define('STU_EMBER_NUM_PREDICT', 6500);
define('STU_EMBER_NUM_CTX', 32768);
define('STU_EMBER_TEMPERATURE', 1.0);
define('STU_EMBER_TOP_P', 0.95);
define('STU_EMBER_TOP_K', 64);
define('STU_EMBER_TIMEOUT', 600);
define('STU_EMBER_TIMEOUT_RETRY', 600);
define('STU_EMBER_MAX_REPLY_CHARS', 60000);

define('STU_EMBER_MEMORY_ENABLED', true);
define('STU_EMBER_REFLECT_ENABLED', true);
define('STU_EMBER_LORE_ENABLED', true);
define('STU_EMBER_WEB_ENABLED', $WEB_BOOL);
define('STU_EMBER_WEB_URL', '$SEARXNG_URL_PHP');
define('STU_EMBER_BROWSE_ENABLED', $BROWSE_BOOL);
define('STU_EMBER_BROWSE_MAX_STEPS', 12);
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 420);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
define('STU_EMBER_BROWSE_SCREENSHOT_DIR', '$SCREENSHOT_DIR_PHP');
define('STU_EMBER_PY_ENABLED', true);
PHP
  install -m 0640 -o root -g www-data "$CONFIG_TMP" "$CONFIG_FILE"
fi

log "Creating MariaDB database $DB_NAME and importing its schema."
DB_PASS_SQL="$(printf '%s' "$DB_PASS" | sed "s/'/''/g")"
mariadb --protocol=socket --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS_SQL';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

DB_CLIENT_TMP="$(mktemp)"
chmod 0600 "$DB_CLIENT_TMP"
cat > "$DB_CLIENT_TMP" <<CNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
default-character-set=utf8mb4
CNF
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/001_core.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/002_coreui_management.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/003_console_sessions.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/004_profiles_knowledge.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/006_account_security.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/007_remove_private_studio_lore.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/008_message_actions.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/009_message_editing.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/011_console_artifacts.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/012_console_work_events.sql"
mariadb --defaults-extra-file="$DB_CLIENT_TMP" "$DB_NAME" < "$PROJECT_ROOT/database/migrations/013_console_agent_runs.sql"

log 'Initializing Ember and the administrator account.'
COREUI_ADMIN_EMAIL="$ADMIN_EMAIL" \
COREUI_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
COREUI_ADMIN_NAME="$ADMIN_NAME" \
COREUI_ADMIN_GENDER="$ADMIN_GENDER" \
php "$PROJECT_ROOT/scripts/bootstrap.php"

if ! command -v ollama >/dev/null 2>&1; then
  if ! is_enabled "$INSTALL_OLLAMA"; then
    die 'Ollama is missing. Install it or set COREUI_INSTALL_OLLAMA=1.'
  fi
  log 'Installing Ollama using the official Linux installer.'
  OLLAMA_INSTALL_TMP="$(mktemp)"
  curl -fsSL https://ollama.com/install.sh -o "$OLLAMA_INSTALL_TMP"
  sh "$OLLAMA_INSTALL_TMP"
fi

if systemctl list-unit-files ollama.service >/dev/null 2>&1; then
  systemctl enable --now ollama
fi
wait_http 'http://127.0.0.1:11434/api/tags' 30 || die 'Ollama is not responding on 127.0.0.1:11434.'

if [[ -z "$BASE_MODEL" ]]; then
  BASE_MODEL="gemma4:e4b"
fi
[[ "$BASE_MODEL" =~ ^gemma4: ]] || warn "Basismodell $BASE_MODEL is not a gemma4 tag. Installation will continue anyway."

BASE_MODEL_NEW=0
if ! ollama show "$BASE_MODEL" >/dev/null 2>&1; then
  log "Downloading temporary Ollama base model $BASE_MODEL. This may take longer depending on the model."
  ollama pull "$BASE_MODEL"
  BASE_MODEL_NEW=1
else
  log "Ollama-Basismodell $BASE_MODEL already exists and is preserved."
fi

if [[ "$MODEL_NAME" != "$BASE_MODEL" ]]; then
  MODELFILE_GENERATED="$PROJECT_ROOT/var/Modelfile.generated"
  sed "s|^FROM gemma4:e4b$|FROM $BASE_MODEL|" "$PROJECT_ROOT/config/Modelfile.template" > "$MODELFILE_GENERATED"
  log "Creating local CoreAI: TCI Interface model $MODEL_NAME aus $BASE_MODEL."
  ollama create "$MODEL_NAME" -f "$MODELFILE_GENERATED"
  ollama show "$MODEL_NAME" >/dev/null 2>&1 || die "TCI Core $MODEL_NAME could not be verified. The base model will be preserved."
  if (( BASE_MODEL_NEW == 1 )) && [[ "$BASE_MODEL" == gemma4:* ]]; then
    if ollama rm "$BASE_MODEL"; then
      log "Removed temporary Ollama base tag $BASE_MODEL after the Core was successfully verified."
    else
      warn "Core $MODEL_NAME is ready, but temporary base tag $BASE_MODEL could not be removed."
    fi
  fi
else
  warn 'COREUI_MODEL_NAME matches the base model; no custom Modelfile will be created.'
fi

# Same managed generator as the Compose installer. Safe to rerun during a source update.
if ! python3 -I "$PROJECT_ROOT/scripts/ensure-generator-core.py" --ensure; then
  warn 'TCI Core Generator could not be created or updated. Preflight will report its status.'
fi

if is_enabled "$INSTALL_SEARXNG"; then
  command -v docker >/dev/null || die 'Docker is required for SearXNG.'
  systemctl enable --now docker
  install -d -m 0770 -o www-data -g www-data \
    "$PROJECT_ROOT/var/searxng/config" \
    "$PROJECT_ROOT/var/searxng/cache"
  SEARXNG_SECRET="$(openssl rand -hex 32)"
  sed "s|{{SEARXNG_SECRET}}|$SEARXNG_SECRET|g" \
    "$PROJECT_ROOT/config/searxng-settings.yml.template" \
    > "$PROJECT_ROOT/var/searxng/config/settings.yml"
  chmod 0640 "$PROJECT_ROOT/var/searxng/config/settings.yml"

  if docker container inspect ember-coreui-searxng >/dev/null 2>&1; then
    EXISTING_MOUNT="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/etc/searxng"}}{{.Source}}{{end}}{{end}}' ember-coreui-searxng)"
    [[ "$EXISTING_MOUNT" == "$PROJECT_ROOT/var/searxng/config" ]] || die 'A foreign container named ember-coreui-searxng exists. Remove or rename it manually.'
    docker restart ember-coreui-searxng >/dev/null
  else
    log 'Starte die private SearXNG-Instanz auf 127.0.0.1:8888.'
    docker pull docker.io/searxng/searxng:latest
    docker run -d \
      --name ember-coreui-searxng \
      --restart unless-stopped \
      -p 127.0.0.1:8888:8080 \
      -e SEARXNG_BASE_URL=http://127.0.0.1:8888/ \
      -e FORCE_OWNERSHIP=true \
      -v "$PROJECT_ROOT/var/searxng/config:/etc/searxng:rw" \
      -v "$PROJECT_ROOT/var/searxng/cache:/var/cache/searxng:rw" \
      docker.io/searxng/searxng:latest >/dev/null
  fi
  wait_http 'http://127.0.0.1:8888/search?q=ember&format=json' 45 || die 'SearXNG is not responding with JSON search enabled.'
fi

if is_enabled "$INSTALL_BROWSE"; then
  if ! is_enabled "$INSTALL_SEARXNG" && [[ "$SEARXNG_URL" == 'http://127.0.0.1:8888/search' ]]; then
    die 'Browser research is enabled, but local SearXNG installation is disabled and no external COREUI_SEARXNG_URL is set.'
  fi
  log 'Installing the asynchronous Playwright browser worker.'
  python3 -m venv "$PROJECT_ROOT/var/venv"
  "$PROJECT_ROOT/var/venv/bin/python" -m pip install --upgrade pip wheel
  "$PROJECT_ROOT/var/venv/bin/python" -m pip install -r "$PROJECT_ROOT/tools/requirements-browse.txt"
  PLAYWRIGHT_BROWSERS_PATH="$PROJECT_ROOT/var/playwright" \
    "$PROJECT_ROOT/var/venv/bin/python" -m playwright install-deps chromium
  PLAYWRIGHT_BROWSERS_PATH="$PROJECT_ROOT/var/playwright" \
    "$PROJECT_ROOT/var/venv/bin/python" -m playwright install chromium

  sed \
    -e "s|@@COREUI_ROOT@@|$PROJECT_ROOT|g" \
    "$PROJECT_ROOT/config/ember-browse-worker.service.template" \
    > /etc/systemd/system/ember-coreui-browse.service
fi

log 'Building the isolated Python sandbox image (with Node.js).'
docker build -t ember-py:2 -f "$PROJECT_ROOT/tools/ember_py.Dockerfile" "$PROJECT_ROOT/tools"
sed \
  -e "s|@@COREUI_ROOT@@|$PROJECT_ROOT|g" \
  "$PROJECT_ROOT/config/ember-py-worker.service.template" \
  > /etc/systemd/system/ember-coreui-python.service

chgrp -R www-data "$PROJECT_ROOT"
chmod -R g+rX,o-rwx "$PROJECT_ROOT"
chown -R www-data:www-data \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos"
chmod 0770 \
  "$PROJECT_ROOT/logs" \
  "$PROJECT_ROOT/var/cache" \
  "$PROJECT_ROOT/var/console_media" \
  "$PROJECT_ROOT/var/profile_media" \
  "$PROJECT_ROOT/var/knowledge_uploads" \
  "$PROJECT_ROOT/var/ember_py" \
  "$PROJECT_ROOT/var/ember_frames" \
  "$PROJECT_ROOT/var/pdf_pages" \
  "$PROJECT_ROOT/var/worker-home" \
  "$PROJECT_ROOT/uploads/ember_browse" \
  "$PROJECT_ROOT/assets/chat_media" \
  "$PROJECT_ROOT/assets/profile_photos" \
  "$PROJECT_ROOT/assets/profile_photos/pending" \
  "$PROJECT_ROOT/assets/profile_photos/approved"
chmod 0750 "$PROJECT_ROOT/var"
chmod 0640 "$CONFIG_FILE"
chmod 0750 "$PROJECT_ROOT/scripts/install-native.sh" "$PROJECT_ROOT/scripts/preflight-native.sh" 2>/dev/null || true

systemctl daemon-reload
systemctl enable --now ember-coreui-python.service
# 0.7.79: an update brings new worker code (runs, processes, the image ember-py:2); a running worker
# keeps the old code until it restarts, so it is restarted here like the browse worker.
systemctl restart ember-coreui-python.service
# ember-py:2 replaces ember-py:1 (Node.js added). Only now, with the worker on the new tag, the old
# image goes; a container that still uses it keeps it (no error).
docker image rm ember-py:1 >/dev/null 2>&1 || true
if is_enabled "$INSTALL_BROWSE"; then
  systemctl enable --now ember-coreui-browse.service
  # 0.7.38: an update brings new worker code (Deep Research); a running worker keeps the old code
  # until it restarts, so it is restarted here (enable --now alone leaves it as it is).
  systemctl restart ember-coreui-browse.service
fi

log 'Configuring Nginx.'
sed \
  -e "s|@@COREUI_ROOT@@|$PROJECT_ROOT|g" \
  -e "s|@@SERVER_NAME@@|$SERVER_NAME|g" \
  -e "s|@@PHP_SOCKET@@|$PHP_SOCKET|g" \
  "$PROJECT_ROOT/config/nginx.conf.template" \
  > /etc/nginx/sites-available/ember-coreui.conf
ln -sfn /etc/nginx/sites-available/ember-coreui.conf /etc/nginx/sites-enabled/ember-coreui.conf
if is_enabled "${COREUI_DISABLE_NGINX_DEFAULT:-0}" && [[ -L /etc/nginx/sites-enabled/default ]]; then
  unlink /etc/nginx/sites-enabled/default
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

HEALTH_HOST="$SERVER_NAME"
[[ "$HEALTH_HOST" == '_' ]] && HEALTH_HOST='localhost'
if curl -fsS --max-time 10 -H "Host: $HEALTH_HOST" 'http://127.0.0.1/api/health.php' >/dev/null; then
  log "Installation successful. Open $PUBLIC_URL/"
else
  warn 'The Nginx health check is not green yet. Run scripts/preflight.sh and inspect journalctl and logs/stu_error.log.'
fi

coreui_ensure_host_swap || warn 'Optional host swap configuration failed.'

log 'Security note: MariaDB, Ollama, and SearXNG remain bound to loopback. Only HTTP and optionally HTTPS are exposed.'
