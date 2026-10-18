#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$PROJECT_ROOT/scripts/lib/host-swap.sh"

case "${1:-status}" in
  status)
    coreui_swap_status
    command -v swapon >/dev/null 2>&1 && swapon --show || true
    command -v sysctl >/dev/null 2>&1 && sysctl vm.swappiness || true
    ;;
  ensure)
    [[ "${EUID:-$(id -u)}" -eq 0 ]] || { coreui_swap_warn 'Bitte als root ausfuehren.'; exit 1; }
    coreui_ensure_host_swap
    ;;
  *)
    printf 'Usage: bash scripts/swap.sh [status|ensure]\n' >&2
    exit 2
    ;;
esac
