#!/usr/bin/env bash
# Offline regression for migration script permissions; never contacts Docker.
set -Eeuo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
for script in "$ROOT/scripts/migrate.sh" "$ROOT/scripts/install.sh" \
  "$ROOT/setup.sh" "$ROOT/scripts/update-from-main.sh"; do
  bash -n "$script"
done
[[ -x "$ROOT/scripts/migrate.sh" ]] || {
  printf 'FAIL: Git checkout lacks executable migration script\n' >&2
  exit 1
}
grep -Fq 'bash "$PROJECT_ROOT/scripts/migrate.sh"' "$ROOT/scripts/install.sh"
grep -Fq '"$TARGET_DIR/scripts/migrate.sh"' "$ROOT/setup.sh"
grep -Fq '"$TARGET_DIR/scripts/migrate.sh"' "$ROOT/scripts/update-from-main.sh"
WORK="$(mktemp -d)"
trap 'rm -rf -- "$WORK"' EXIT
printf '#!/usr/bin/env bash\nprintf "migration-mock-ok\\n"\n' > "$WORK/migration.sh"
chmod 0644 "$WORK/migration.sh"
[[ ! -x "$WORK/migration.sh" ]] || exit 2
[[ "$(bash "$WORK/migration.sh")" == 'migration-mock-ok' ]] || exit 3
printf 'PASS: migration checkout, setup, updater and non-executable bash fallback\n'
