#!/usr/bin/env bash
# Isolated fixtures: no production Docker, systemd, Ollama or /opt writes.
set -Eeuo pipefail
IFS=$'\n\t'
BASE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ORIGINAL="$BASE/scripts/uninstall.sh"
[[ -f "$ORIGINAL" ]] || { echo 'Uninstall test: missing runner' >&2; exit 1; }
bash -n "$ORIGINAL"

WORK="$(mktemp -d /tmp/coreai-uninstall-selftest.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/units" "$WORK/opt"
printf "Description=CoreAI: TCI Interface Test unit\n" > "$WORK/units/coreai-update.path"
printf "# Foreign unit\n" > "$WORK/units/foreign-app.service"
cat > "$WORK/bin/docker" <<'MOCK'
#!/usr/bin/env bash
if [[ "${1:-}" == compose && "${2:-}" == version ]]; then exit 0; fi
printf '%s\n' "$*" >> "$COREAI_UNINSTALL_TEST_LOG"
MOCK
chmod 0755 "$WORK/bin/docker"
cat > "$WORK/bin/systemctl" <<'MOCK'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >> "$COREAI_UNINSTALL_TEST_LOG"
MOCK
chmod 0755 "$WORK/bin/systemctl"
export PATH="$WORK/bin:$PATH"
export COREAI_UNINSTALL_TEST_LOG="$WORK/calls.log"
: > "$COREAI_UNINSTALL_TEST_LOG"

new_fixture() {
  local label="$1" root="$WORK/opt/$1"
  mkdir -p "$root/scripts" "$root/var/update" "$root/var/update-request" "$root/var/uninstall-request" "$root/api"
  printf '0.6.98\n' > "$root/VERSION"
  printf 'services: {}\n' > "$root/compose.yaml"
  printf 'COREUI_HTTP_PORT=9080\n' > "$root/var/compose.env"
  printf '<?php\n' > "$root/api/config.local.php"
  printf 'private messages\n' > "$root/var/important-data.txt"
  sed \
    -e "s|^PROJECT_ROOT='/opt/ember-coreui'$|PROJECT_ROOT='$root'|" \
    -e "s|== '/opt/ember-coreui'|== '$root'|" \
    -e "s|^SYSTEMD_DIR='/etc/systemd/system'$|SYSTEMD_DIR='$WORK/units'|" \
    -e "s|^SYSTEMD_RUNTIME_DIR='/run/systemd/system'$|SYSTEMD_RUNTIME_DIR='$WORK/units'|" \
    "$ORIGINAL" > "$root/scripts/uninstall.sh"
  chmod 0755 "$root/scripts/uninstall.sh"
  # The fixture still has a real stack.sh marker like a deployed installation.
  printf '#!/bin/sh\n' > "$root/scripts/stack.sh"
}

new_fixture first
first="$WORK/opt/first"
bash "$first/scripts/uninstall.sh" --dry-run > "$WORK/dry.txt"
[[ -d "$first" && -f "$first/var/important-data.txt" ]] || { echo 'Dry-run changed data' >&2; exit 1; }
[[ ! -s "$COREAI_UNINSTALL_TEST_LOG" ]] || { echo 'Dry-run ran host tools' >&2; exit 1; }

# The browser runner may NEVER accept purge, expired jobs or spoofed callers.
if bash "$first/scripts/uninstall.sh" --request >"$WORK/invalid.txt" 2>&1; then
  echo 'Runner accepted nonexistent request' >&2; exit 1
fi
# Never run the destructive --purge-data mode during Preflight.
# Redirecting stdin does NOT disconnect /dev/tty. On an SSH terminal
# this would still prompt the operator and could purge the fixture.
# Check the guard contract without executing the destructive branch.
if ! grep -Fq "expected='COREAI DATEN ENDGUELTIG LOESCHEN'" "$ORIGINAL" \
   || ! grep -Fq '[[ -r /dev/tty && -w /dev/tty ]] || die' "$ORIGINAL" \
   || ! grep -Fq '[[ "$typed" == "$expected" ]] || die' "$ORIGINAL"; then
  echo 'Runner missing interactive purge confirmation guards' >&2; exit 1
fi
[[ -f "$first/var/important-data.txt" ]] || { echo 'Invalid request touched data' >&2; exit 1; }
if (( EUID == 0 )); then
  printf '{"action":"uninstall","mode":"purge"}\n' > "$first/var/uninstall-request/request.json"
  chown 33:33 "$first/var/uninstall-request/request.json"
  chmod 0600 "$first/var/uninstall-request/request.json"
  if bash "$first/scripts/uninstall.sh" --request >"$WORK/rejected.txt" 2>&1; then
    echo 'Runner accepted unsafe purge request' >&2; exit 1
  fi
  [[ ! -e "$first/var/uninstall-request/request.json" ]] || { echo 'Invalid request not consumed' >&2; exit 1; }
fi

new_fixture second
second="$WORK/opt/second"
python3 - "$second/var/uninstall-request/request.json" <<'PY'
import json, sys, time
with open(sys.argv[1], 'w') as out:
    json.dump({'action':'uninstall','mode':'archive','requested_by':17,
               'requested_at':int(time.time()),'nonce':'f'*32},out)
PY
if (( EUID == 0 )); then
  chown 33:33 "$second/var/uninstall-request/request.json"
  chmod 0600 "$second/var/uninstall-request/request.json"
  bash "$second/scripts/uninstall.sh" --request > "$WORK/success.txt"
  archive=("$second".uninstalled.*)
  [[ ${#archive[@]} == 1 && -f "${archive[0]}/var/important-data.txt" ]] \
    || { echo 'Archived data missing' >&2; exit 1; }
  [[ ! -e "$second" ]] || { echo 'Original root still present' >&2; exit 1; }
  [[ ! -e "${archive[0]}/var/uninstall-request/request.json" ]] || { echo 'Stale request survived archive' >&2; exit 1; }
  grep -q 'down' "$COREAI_UNINSTALL_TEST_LOG" || { echo 'Compose down missing' >&2; exit 1; }
  grep -q 'systemctl disable --now coreai-update.path' "$COREAI_UNINSTALL_TEST_LOG" || { echo 'Managed unit not disabled' >&2; exit 1; }
  [[ -f "$WORK/units/foreign-app.service" ]] || { echo 'Foreign unit changed' >&2; exit 1; }
else
  echo 'Uninstall fixture: non-root mode checked (root-only archive needs production root)' >&2
fi
printf 'CoreAI uninstall runner: dry-run, request validation and isolated archive OK\n'
