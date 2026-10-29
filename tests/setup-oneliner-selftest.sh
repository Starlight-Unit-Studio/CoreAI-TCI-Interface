#!/usr/bin/env bash
# Offline contract test for the unchanged public one-line launcher.
# No Docker, GitHub, apt, model or production path is touched.
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
WORK="$(mktemp -d /tmp/coreui-oneliner-test.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/fixture/scripts" "$WORK/fixture/api" \
  "$WORK/fixture/var" "$WORK/fixture/database/migrations" \
  "$WORK/fixture/.github" "$WORK/fixture/releases"

# Always redirect /opt/ember-coreui to a unique disposable directory.
TARGET="$WORK/target"
sed "s|^TARGET_DIR='/opt/ember-coreui'$|TARGET_DIR='$TARGET'|" \
  "$ROOT/setup.sh" > "$WORK/setup.sh"
bash -n "$WORK/setup.sh"
chmod 0755 "$WORK/setup.sh"

printf '%s\n' '0.5.5' > "$WORK/fixture/VERSION"
printf '%s\n' 'standalone cache tool' > "$WORK/fixture/scripts/cache-bust.py"
printf '%s\n' 'test app' > "$WORK/fixture/app.html"
printf '%s\n' 'test compose' > "$WORK/fixture/compose.yaml"
printf '%s\n' 'test README' > "$WORK/fixture/README.md"
printf '%s\n' 'test stack' > "$WORK/fixture/scripts/stack.sh"
printf '#!/usr/bin/env bash\nexit 0\n' > "$WORK/fixture/scripts/migrate.sh"
printf '%s\n' 'test route' > "$WORK/fixture/api/console_artifact.php"
printf '%s\n' 'test route' > "$WORK/fixture/api/console_artifacts.php"
printf '%s\n' 'test schema' > "$WORK/fixture/database/migrations/011_console_artifacts.sql"
printf '%s\n' 'not installed' > "$WORK/fixture/.github/hidden.yml"
printf '%s\n' 'not installed' > "$WORK/fixture/releases/huge.zip"
printf '%s\n' 'original source' > "$WORK/fixture/api/chat.php"
printf '%s\n' 'source config should not overwrite live config' > "$WORK/fixture/api/config.local.php"
cat > "$WORK/fixture/scripts/install.sh" <<'INSTALL'
#!/usr/bin/env bash
set -Eeuo pipefail
mkdir -p var
if [[ -f var/compose.env ]]; then
  [[ "${COREUI_SKIP_BOOTSTRAP:-}" == 1 ]] || exit 31
  printf 'update\n' >> var/test_installs
else
  [[ -n "${COREUI_ADMIN_EMAIL:-}" && -n "${COREUI_ADMIN_PASSWORD:-}" ]] || exit 32
  printf 'generated config\n' > api/config.local.php
  printf 'persisted env\n' > var/compose.env
  printf 'initial\n' >> var/test_installs
fi
INSTALL
cat > "$WORK/fixture/scripts/preflight.sh" <<'PREFLIGHT'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ -s database/migrations/011_console_artifacts.sql ]]
[[ -s var/compose.env ]]
PREFLIGHT
chmod 0755 "$WORK/fixture/scripts/"*.sh
# Simulate a source checkout with non-executable migration script.
chmod 0644 "$WORK/fixture/scripts/migrate.sh"

cat > "$WORK/bin/git" <<'GIT'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "$1" == clone ]]; then
  for last in "$@"; do :; done
  if [[ "${FAKE_FAIL_GIT:-}" == 1 ]]; then exit 17; fi
  # Detect the real umask inherited by Git, independent of the mock checkout.
  umask > "$FAKE_CLONE_UMASK_LOG"
  mkdir -p "$last"
  cp -a "$FAKE_REPO/." "$last/"
  mkdir -p "$last/.git"
elif [[ "$1" == -C && "$3" == rev-parse && "$4" == --verify && "$5" == HEAD ]]; then
  printf '%040d\n' 7
else
  printf 'Unexpected git invocation\n' >&2
  exit 18
fi
GIT
cat > "$WORK/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -Eeuo pipefail
out=''
last=''
while (($#)); do
  if [[ "$1" == --output ]]; then
    shift
    out="$1"
  fi
  last="$1"
  shift
done
case "$last" in
  *.zip) cp "$FAKE_ARCHIVE" "$out" ;;
  *.sha256) cp "$FAKE_SUM" "$out" ;;
  *) exit 45 ;;
esac
CURL
chmod 0755 "$WORK/bin/"*
export FAKE_REPO="$WORK/fixture"
export FAKE_CLONE_UMASK_LOG="$WORK/clone-umask"
export COREUI_ADMIN_EMAIL='test@example.test'
export COREUI_ADMIN_PASSWORD='non-production-password'
export FAKE_ARCHIVE="$WORK/EMBER_COREUI_0_5_3_ALPHA.zip"
export FAKE_SUM="$WORK/EMBER_COREUI_0_5_3_ALPHA.zip.sha256"
export PATH="$WORK/bin:$PATH"

# New installation: the original oneliner now chooses main and tracks its commit.
"$WORK/setup.sh" > "$WORK/install.log"
# Git must be able to emit public source files readable by the isolated
# nginx/php UID 33; the installer's own restrictive umask stays at 027.
[[ "$(cat "$FAKE_CLONE_UMASK_LOG")" == '0022' ]]
[[ "$(cat "$TARGET/var/dev_source_commit")" == '0000000000000000000000000000000000000007' ]]
# 0.5.83: readable for the web server (group 33), so the update window can compare.
[[ "$(stat -c '%a %g' "$TARGET/var/dev_source_commit")" == '640 33' ]]
[[ "$(cat "$TARGET/var/test_installs")" == 'initial' ]]
[[ -x "$TARGET/scripts/migrate.sh" ]]
[[ ! -e "$TARGET/.git" && ! -e "$TARGET/.github" && ! -e "$TARGET/releases/huge.zip" ]]
[[ "$(cat "$TARGET/api/chat.php")" == 'original source' ]]

# Update must preserve all persistent data while refreshing merged main code.
mkdir -p "$TARGET/logs" "$TARGET/uploads" "$TARGET/assets/chat_media" "$TARGET/assets/profile_photos"
printf '%s\n' 'account memory' > "$TARGET/var/sessions.txt"
printf '%s\n' 'custom config' > "$TARGET/api/config.local.php"
printf '%s\n' 'operator data' > "$TARGET/uploads/user.txt"
printf '%s\n' 'older code' > "$TARGET/api/chat.php"
printf '%s\n' 'file creation fixed' > "$WORK/fixture/api/chat.php"
mkdir -p "$TARGET/js" "$TARGET/css" "$TARGET/skills/operator-skill"
for obsolete in api/user_skills.php js/skills-settings.js scripts/skills-ui-selftest.cjs css/admin.css; do
  printf '%s\n' 'obsolete source from previous version' > "$TARGET/$obsolete"
done
printf '%s\n' 'operator extension' > "$TARGET/api/operator.php"
printf '%s\n' 'operator skill' > "$TARGET/skills/operator-skill/SKILL.md"
"$WORK/setup.sh" > "$WORK/update.log"
for obsolete in api/user_skills.php js/skills-settings.js scripts/skills-ui-selftest.cjs css/admin.css; do
  [[ ! -e "$TARGET/$obsolete" ]]
done
[[ "$(cat "$TARGET/api/operator.php")" == 'operator extension' ]]
[[ "$(cat "$TARGET/skills/operator-skill/SKILL.md")" == 'operator skill' ]]
[[ "$(cat "$TARGET/api/chat.php")" == 'file creation fixed' ]]
[[ "$(cat "$TARGET/api/config.local.php")" == 'custom config' ]]
[[ "$(cat "$TARGET/var/sessions.txt")" == 'account memory' ]]
[[ "$(cat "$TARGET/uploads/user.txt")" == 'operator data' ]]
[[ "$(tail -n 1 "$TARGET/var/test_installs")" == 'update' ]]
[[ -x "$TARGET/scripts/migrate.sh" ]]
[[ "$(cat "$TARGET/var/dev_source_commit")" == '0000000000000000000000000000000000000007' ]]

# Broken git input must abort before modifying an installation.
printf '%s\n' 'must survive failed fetch' > "$TARGET/api/user_skills.php"
if FAKE_FAIL_GIT=1 "$WORK/setup.sh" > "$WORK/failure.log" 2>&1; then
  echo 'FAIL: failing GitHub fetch was not rejected' >&2
  exit 51
fi
[[ "$(cat "$TARGET/api/chat.php")" == 'file creation fixed' ]]
[[ "$(cat "$TARGET/api/user_skills.php")" == 'must survive failed fetch' ]]

# Retrying the update cleans stale files and remains safe when already clean.
"$WORK/setup.sh" > "$WORK/retry.log"
"$WORK/setup.sh" > "$WORK/repeat.log"
[[ ! -e "$TARGET/api/user_skills.php" ]]
[[ "$(cat "$TARGET/api/config.local.php")" == 'custom config' ]]

# Explicit pinned-release fallback is still verified by its original ZIP/SHA pair.
mkdir -p "$WORK/package/EMBER_COREUI_0_5_3_ALPHA"
cp -a "$WORK/fixture/." "$WORK/package/EMBER_COREUI_0_5_3_ALPHA/"
printf '%s\n' '0.5.3-alpha' > "$WORK/package/EMBER_COREUI_0_5_3_ALPHA/VERSION"
# Cleanup must not remove a file shipped by the selected source itself.
printf '%s\n' 'selected source owns this file' > "$WORK/package/EMBER_COREUI_0_5_3_ALPHA/api/user_skills.php"
(cd "$WORK/package" && zip -qr "$FAKE_ARCHIVE" EMBER_COREUI_0_5_3_ALPHA)
(cd "$WORK" && sha256sum EMBER_COREUI_0_5_3_ALPHA.zip > EMBER_COREUI_0_5_3_ALPHA.zip.sha256)
COREUI_SETUP_SOURCE=release "$WORK/setup.sh" > "$WORK/release.log"
[[ ! -e "$TARGET/var/dev_source_commit" ]]
[[ "$(cat "$TARGET/var/sessions.txt")" == 'account memory' ]]
[[ "$(cat "$TARGET/api/config.local.php")" == 'custom config' ]]
grep -q 'EMBER_COREUI_0_5_3_ALPHA.zip: OK' "$WORK/release.log"
[[ "$(cat "$TARGET/api/user_skills.php")" == 'selected source owns this file' ]]

# A failed checksum must never modify an existing installation.
printf '%s\n' 'broken checksum' > "$FAKE_SUM"
if COREUI_SETUP_SOURCE=release "$WORK/setup.sh" > "$WORK/bad-checksum.log" 2>&1; then
  echo 'FAIL: corrupted pinned release checksum was accepted' >&2
  exit 53
fi
[[ "$(cat "$TARGET/api/chat.php")" == 'file creation fixed' ]]

# Explicit invalid modes are rejected before any write.
if COREUI_SETUP_SOURCE='untrusted' "$WORK/setup.sh" > "$WORK/invalid.log" 2>&1; then
  echo 'FAIL: invalid source mode was not rejected' >&2
  exit 52
fi

# Both preflights must use the current skills test, never the removed DB helper.
for preflight in scripts/preflight.sh scripts/preflight-native.sh; do
  ! grep -Fq 'coreui_skills_schema_ready' "$ROOT/$preflight"
  grep -Fq 'scripts/skills-selftest.php' "$ROOT/$preflight"
done

echo 'PASS: one-liner, safe update, retired files, retries, local data, failed fetch, pinned fallback, current skills checks'

