#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$PROJECT_ROOT/scripts/lib/host-swap.sh"

sandbox="$(mktemp -d)"
trap 'rm -rf -- "$sandbox"' EXIT
mkdir -p "$sandbox/bin" "$sandbox/etc"
export COREUI_TEST_LOG="$sandbox/commands.log"
export COREUI_TEST_SWAPS="$sandbox/proc-swaps"
export COREUI_TEST_FSTYPE=ext4
export COREUI_TEST_FREE_KIB=20000000
export COREUI_TEST_FAIL=''
export PATH="$sandbox/bin:$PATH"
swapfile="$sandbox/swapfile"
fstab="$sandbox/etc/fstab"
sysctl_conf="$sandbox/etc/99-stu-coreui-swap.conf"
swaps="$COREUI_TEST_SWAPS"
lockfile="$sandbox/swap.lock"

cat > "$sandbox/bin/findmnt" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$COREUI_TEST_FSTYPE"
MOCK
cat > "$sandbox/bin/df" <<'MOCK'
#!/usr/bin/env bash
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
printf 'mockfs 20000000 0 %s 0%% /\n' "$COREUI_TEST_FREE_KIB"
MOCK
cat > "$sandbox/bin/fallocate" <<'MOCK'
#!/usr/bin/env bash
printf 'fallocate\n' >> "$COREUI_TEST_LOG"
printf 'fake\n' > "$3"
[[ "$COREUI_TEST_FAIL" != 'fallocate' ]]
MOCK
cat > "$sandbox/bin/mkswap" <<'MOCK'
#!/usr/bin/env bash
printf 'mkswap\n' >> "$COREUI_TEST_LOG"
[[ "$COREUI_TEST_FAIL" != 'mkswap' ]]
MOCK
cat > "$sandbox/bin/swapon" <<'MOCK'
#!/usr/bin/env bash
printf 'swapon\n' >> "$COREUI_TEST_LOG"
[[ "$COREUI_TEST_FAIL" != 'swapon' ]] || exit 1
printf '%s file 8388608 0 -2\n' "$1" >> "$COREUI_TEST_SWAPS"
MOCK
cat > "$sandbox/bin/sysctl" <<'MOCK'
#!/usr/bin/env bash
printf 'sysctl %s\n' "$*" >> "$COREUI_TEST_LOG"
MOCK
chmod 0755 "$sandbox/bin/"*

reset_fixture() {
  printf 'Filename Type Size Used Priority\n' > "$swaps"
  : > "$fstab"
  : > "$COREUI_TEST_LOG"
  rm -f -- "$swapfile" "$sysctl_conf" "$sandbox/etc/90-operator.conf"
  export COREUI_TEST_FSTYPE=ext4 COREUI_TEST_FREE_KIB=20000000 COREUI_TEST_FAIL=''
  unset COREUI_SWAP_AUTOCREATE COREUI_SWAP_SIZE_GIB COREUI_SWAP_SWAPPINESS || true
}
run_install() {
  coreui_ensure_host_swap "$swapfile" "$fstab" "$swaps" "$sysctl_conf" "$lockfile"
}

printf '[TEST] Fresh swapfile, swappiness, persistent fstab entry\n'
reset_fixture
run_install
[[ -f "$swapfile" ]]
[[ "$(stat -c '%a' "$swapfile")" == '600' ]]
grep -Fxq "$swapfile none swap sw 0 0" "$fstab"
grep -Fxq 'vm.swappiness=10' "$sysctl_conf"
grep -Fq 'sysctl vm.swappiness=10' "$COREUI_TEST_LOG"
[[ "$(grep -c '^fallocate$' "$COREUI_TEST_LOG")" -eq 1 ]]

printf '[TEST] Repeat run makes no second swap\n'
run_install
[[ "$(grep -c '^fallocate$' "$COREUI_TEST_LOG")" -eq 1 ]]
[[ "$(grep -c 'swap sw 0 0' "$fstab")" -eq 1 ]]

printf '[TEST] Respect active other STU/Game swap\n'
reset_fixture
printf '/swapfile file 8388608 0 -2\n' >> "$swaps"
run_install
[[ ! -e "$swapfile" && ! -s "$COREUI_TEST_LOG" ]]

printf '[TEST] Respect configured but inactive swap\n'
reset_fixture
printf '/dev/vda2 none swap sw 0 0\n' > "$fstab"
run_install
[[ ! -e "$swapfile" && ! -s "$COREUI_TEST_LOG" ]]

printf '[TEST] Pre-existing swapfile not overwritten\n'
reset_fixture
printf 'preserve-this-content\n' > "$swapfile"
run_install
grep -Fxq 'preserve-this-content' "$swapfile"
[[ ! -s "$COREUI_TEST_LOG" ]]

printf '[TEST] Explicit opt-out\n'
reset_fixture
COREUI_SWAP_AUTOCREATE=0
run_install
[[ ! -e "$swapfile" && ! -s "$COREUI_TEST_LOG" ]]

printf '[TEST] Unsupported filesystem and insufficient space\n'
reset_fixture
COREUI_TEST_FSTYPE=btrfs
run_install
[[ ! -e "$swapfile" ]]
reset_fixture
COREUI_TEST_FREE_KIB=1024
run_install
[[ ! -e "$swapfile" ]]

printf '[TEST] Missing status files do not create swap\n'
reset_fixture
rm "$swaps"
run_install
[[ ! -e "$swapfile" ]]

printf '[TEST] Reject invalid settings\n'
reset_fixture
COREUI_SWAP_SIZE_GIB=99
run_install
[[ ! -e "$swapfile" ]]
reset_fixture
COREUI_SWAP_SWAPPINESS=999
run_install
[[ ! -e "$swapfile" ]]

printf '[TEST] Failed fallocate, mkswap or swapon remove only new file\n'
for stage in fallocate mkswap swapon; do
  reset_fixture
  COREUI_TEST_FAIL="$stage"
  run_install
  [[ ! -e "$swapfile" && ! -s "$fstab" && ! -e "$sysctl_conf" ]]
done

printf '[TEST] Preserve explicitly configured swappiness\n'
reset_fixture
printf 'vm.swappiness=1\n' > "$sandbox/etc/90-operator.conf"
run_install
[[ -f "$swapfile" && ! -e "$sysctl_conf" ]]
! grep -q '^sysctl ' "$COREUI_TEST_LOG"

printf '[TEST] Preserve existing own swappiness file\n'
reset_fixture
printf 'vm.swappiness=5\n' > "$sysctl_conf"
run_install
grep -Fxq 'vm.swappiness=5' "$sysctl_conf"
! grep -q '^sysctl ' "$COREUI_TEST_LOG"

printf '[TEST] Read-only status prints active without modifying files\n'
reset_fixture
printf '/dev/vda2 partition 8388608 0 -2\n' >> "$swaps"
coreui_swap_status "$swaps" "$fstab" >/dev/null
[[ ! -e "$swapfile" && ! -s "$COREUI_TEST_LOG" ]]

printf '[TEST] All CoreUI host swap tests passed.\n'
