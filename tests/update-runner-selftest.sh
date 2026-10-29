#!/usr/bin/env bash
# CoreAI 0.5.83: offline test of the host updater (scripts/coreai-updater.sh).
# git, curl, systemctl and the Compose stack are stand-ins; no GitHub, Docker,
# Ollama or /opt path is touched. Needs root (the updater hands files to UID 33).
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
WORK="$(mktemp -d /tmp/coreai-updater-test.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
APP="$WORK/opt/ember-coreui"
BIN="$WORK/bin"
LOG="$WORK/calls.log"
A=$(printf 'a%.0s' {1..40})
B=$(printf 'b%.0s' {1..40})
mkdir -p "$APP/scripts/lib" "$APP/var" "$BIN" "$WORK/remote"
cp "$ROOT/scripts/coreai-updater.sh" "$APP/scripts/"
cp "$ROOT/scripts/lib/coreai_update_state.py" "$APP/scripts/lib/"
printf '0.5.83\n' > "$APP/VERSION"
printf '%s\n' "$A" > "$APP/var/dev_source_commit"
printf 'COREUI_HTTP_PORT=9080\nCOREUI_INSTALL_BROWSE=1\n' > "$APP/var/compose.env"
: > "$LOG"

# What main offers.
printf '0.5.84\n' > "$WORK/remote/VERSION"
cat > "$WORK/remote/CHANGELOG.md" <<'MD'
# CoreAI: TCI Interface

## 0.5.85 - 31.10.2026

- Noch neuer.

## 0.5.84 - 30.10.2026

- **Neue Stimme:** Mara spricht.

## 0.5.83 - 29.10.2026

- Schon installiert.
MD
cat > "$WORK/remote/setup.sh" <<SETUP
#!/usr/bin/env bash
set -Eeuo pipefail
TARGET_DIR='$APP'
printf 'setup %s\n' "\${COREUI_SETUP_SOURCE:-}" >> '$LOG'
[[ "\${FAKE_SETUP_FAIL:-}" != 1 ]] || exit 9
printf '0.5.85\n' > "\$TARGET_DIR/VERSION"
printf '%s\n' '$B' > "\$TARGET_DIR/var/dev_source_commit"
SETUP

cat > "$APP/scripts/stack.sh" <<STACK
#!/usr/bin/env bash
printf 'stack %s\n' "\$*" >> '$LOG'
if [[ "\$1" == exec ]]; then
  [[ "\${FAKE_DUMP_FAIL:-}" != 1 ]] || exit 3
  printf -- '-- MariaDB dump\nCREATE DATABASE ember_coreui;\n'
fi
STACK
cat > "$BIN/git" <<GIT
#!/usr/bin/env bash
printf 'git %s\n' "\$*" >> '$LOG'
[[ "\${FAKE_GIT_FAIL:-}" != 1 ]] || exit 128
[[ "\$1" == ls-remote ]] && printf '%s\trefs/heads/main\n' '$B'
GIT
cat > "$BIN/curl" <<CURL
#!/usr/bin/env bash
out=''; url=''
while ((\$#)); do
  case "\$1" in -o) shift; out="\$1" ;; http*) url="\$1" ;; esac
  shift
done
printf 'curl %s\n' "\$url" >> '$LOG'
case "\$url" in
  */$B/VERSION) cp '$WORK/remote/VERSION' "\$out" ;;
  */$B/CHANGELOG.md) cp '$WORK/remote/CHANGELOG.md' "\$out" ;;
  */$B/setup.sh) cp "\${FAKE_SETUP_FILE:-$WORK/remote/setup.sh}" "\$out" ;;
  http://127.0.0.1:*) exit 0 ;;
  *) exit 22 ;;
esac
CURL
cat > "$BIN/systemctl" <<SYSTEMCTL
#!/usr/bin/env bash
printf 'systemctl %s\n' "\$*" >> '$LOG'
exit 0
SYSTEMCTL
chmod 0755 "$BIN"/* "$APP/scripts/"*.sh "$WORK/remote/setup.sh"
export PATH="$BIN:$PATH"
UPD="$APP/scripts/coreai-updater.sh"
json() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get(sys.argv[2], ""))' "$1" "$2"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

# Check: newest main, its version and the changelog above the installed version.
"$UPD" check > /dev/null
AV="$APP/var/update/available.json"
[[ "$(json "$AV" latest_commit)" == "$B" && "$(json "$AV" latest_version)" == '0.5.84' ]] || fail 'check result'
notes="$(json "$AV" notes)"
[[ "$notes" == *'## 0.5.85'* && "$notes" == *'**Neue Stimme:**'* && "$notes" != *'Schon installiert'* ]] || fail "notes: $notes"
[[ "$(stat -c '%a %g' "$AV")" == '640 33' ]] || fail 'available.json 0640 root:33'
[[ "$(stat -c '%a %u' "$APP/var/update-request")" == '770 33' && "$(stat -c '%a' "$APP/var/backups")" == '700' ]] || fail 'folders'
# GitHub down: the last result stays, the error is noted; "Jetzt pruefen" file is consumed.
touch "$APP/var/update-request/check"
FAKE_GIT_FAIL=1 "$UPD" check > /dev/null
[[ "$(json "$AV" error)" == 'github_unreachable' && "$(json "$AV" latest_commit)" == "$B" ]] || fail 'error keeps last result'
[[ ! -e "$APP/var/update-request/check" ]] || fail 'check request not consumed'

# Run without a request: nothing happens.
"$UPD" run > /dev/null
[[ ! -e "$APP/var/update/status.json" ]] || fail 'run without request'
# A planted symlink is removed, never followed.
ln -s /etc/hostname "$APP/var/update-request/request.json"
"$UPD" run > /dev/null
[[ ! -e "$APP/var/update-request/request.json" && ! -L "$APP/var/update-request/request.json" && ! -e "$APP/var/update/status.json" ]] || fail 'symlink request'
# Malformed requests too.
printf '{"action":"update","requested_by":"1; rm -rf /","restart_ollama":true}' > "$APP/var/update-request/request.json"
"$UPD" run > /dev/null
[[ ! -e "$APP/var/update-request/request.json" && ! -e "$APP/var/update/status.json" ]] || fail 'malformed request'
! grep -q '^setup' "$LOG" || fail 'setup ran for an invalid request'

# A failing backup stops before anything changes.
printf '{"action":"update","requested_by":1,"restart_ollama":true}' > "$APP/var/update-request/request.json"
FAKE_DUMP_FAIL=1 "$UPD" run > /dev/null 2>&1 && fail 'failed backup reported success'
ST="$APP/var/update/status.json"
[[ "$(json "$ST" state)" == 'failed' && "$(json "$ST" phase)" == 'backup' ]] || fail 'backup failure state'
! grep -q '^setup' "$LOG" || fail 'setup ran without a backup'
[[ "$(cat "$APP/VERSION")" == '0.5.83' ]] || fail 'version changed after failed backup'

# A setup.sh that would install somewhere else is refused.
sed "s|^TARGET_DIR=.*|TARGET_DIR='/opt/elsewhere'|" "$WORK/remote/setup.sh" > "$WORK/remote/other-setup.sh"
printf '{"action":"update","requested_by":1,"restart_ollama":false}' > "$APP/var/update-request/request.json"
FAKE_SETUP_FILE="$WORK/remote/other-setup.sh" "$UPD" run > /dev/null 2>&1 && fail 'foreign target accepted'
[[ "$(json "$ST" phase)" == 'download' && "$(json "$ST" message)" == *'installiert nicht nach'* ]] || fail 'foreign target state'
! grep -q '^setup' "$LOG" || fail 'foreign setup ran'

# A failing installation: state failed, the stack is brought up so the window can say so.
printf '{"action":"update","requested_by":1,"restart_ollama":false}' > "$APP/var/update-request/request.json"
: > "$LOG"
FAKE_SETUP_FAIL=1 "$UPD" run > /dev/null 2>&1 && fail 'failed setup reported success'
[[ "$(json "$ST" state)" == 'failed' && "$(json "$ST" phase)" == 'install' ]] || fail 'install failure state'
grep -q '^stack up -d$' "$LOG" || fail 'stack not brought up after failure'

# The real thing: backup, official setup of main, workers and Ollama restarted.
printf '{"action":"update","requested_by":5,"restart_ollama":true}' > "$APP/var/update-request/request.json"
: > "$LOG"
"$UPD" run > /dev/null
[[ "$(json "$ST" state)" == 'done' && "$(json "$ST" phase)" == 'done' ]] || fail "done state: $(cat "$ST")"
[[ "$(json "$ST" from_version)" == '0.5.83' && "$(json "$ST" to_version)" == '0.5.85' && "$(json "$ST" commit)" == "$B" ]] || fail 'versions in status'
[[ "$(json "$ST" requested_by)" == '5' && "$(json "$ST" restart_ollama)" == 'True' ]] || fail 'request fields in status'
[[ ! -e "$APP/var/update-request/request.json" ]] || fail 'request not consumed'
grep -q '^setup main$' "$LOG" || fail 'setup.sh from main not run'
grep -q '^stack restart pyworker browse$' "$LOG" || fail 'workers not restarted'
grep -q '^systemctl restart ollama$' "$LOG" || fail 'Ollama not restarted'
backup="$(json "$ST" backup)"
[[ "$backup" == var/backups/coreai-*-v0.5.83.sql.gz && -f "$APP/$backup" ]] || fail "backup file: $backup"
gzip -dc "$APP/$backup" | grep -q 'CREATE DATABASE ember_coreui' || fail 'backup content'
[[ "$(stat -c '%a' "$APP/$backup")" == '600' ]] || fail 'backup is root only'
[[ "$(sed -n '1p' <(grep -n 'stack exec' "$LOG" | cut -d: -f1))" -lt "$(grep -n '^setup' "$LOG" | cut -d: -f1)" ]] || fail 'backup before setup'
grep -q 'Update angefordert von Konto 5' "$APP/var/update/update.log" || fail 'update log'
[[ -f "$APP/var/update/update.log.1" ]] || fail 'previous log kept'
# The window has nothing to offer afterwards (installed commit = main).
[[ "$(json "$AV" latest_commit)" == "$B" && "$(cat "$APP/var/dev_source_commit")" == "$B" ]] || fail 'check after update'

# Without Ollama restart, Ollama stays untouched; browse off: only the Python worker.
printf '{"action":"update","requested_by":5,"restart_ollama":false}' > "$APP/var/update-request/request.json"
printf 'COREUI_HTTP_PORT=9080\nCOREUI_INSTALL_BROWSE=0\n' > "$APP/var/compose.env"
: > "$LOG"
"$UPD" run > /dev/null
! grep -q 'systemctl restart ollama' "$LOG" || fail 'Ollama restarted without being asked'
grep -q '^stack restart pyworker$' "$LOG" || fail 'browse restarted although off'
# Five backups are kept.
for i in 1 2 3 4 5; do
  touch -d "-$i hour" "$APP/var/backups/coreai-2026010$i-000000-v0.5.0.sql.gz"
done
printf '{"action":"update","requested_by":5,"restart_ollama":false}' > "$APP/var/update-request/request.json"
"$UPD" run > /dev/null
[[ "$(find "$APP/var/backups" -name 'coreai-*.sql.gz' | wc -l)" == 5 ]] || fail 'backups not pruned to 5'

# An update cut off (server restart): the next check marks it as interrupted.
python3 -I "$APP/scripts/lib/coreai_update_state.py" status "$ST" state=running phase=install
"$UPD" check > /dev/null
[[ "$(json "$ST" state)" == 'failed' && "$(json "$ST" message)" == *'unterbrochen'* ]] || fail 'interrupted update'
# While an update holds the lock, a check waits and still consumes its request.
exec 8>>"$APP/var/update/.lock"
flock -n 8
touch "$APP/var/update-request/check"
printf '{"action":"update","requested_by":1,"restart_ollama":false}' > "$APP/var/update-request/request.json"
: > "$LOG"
"$UPD" check > /dev/null
"$UPD" run > /dev/null
[[ ! -e "$APP/var/update-request/check" && ! -e "$APP/var/update-request/request.json" ]] || fail 'requests left behind under lock'
! grep -q '^git\|^setup' "$LOG" || fail 'worked while another update held the lock'
exec 8>&-

echo 'PASS: update check, notes, refused requests, backup first, official setup, worker and Ollama restart, failures, pruning, lock'
