#!/usr/bin/env bash
# CoreUI host swap provisioning. Source this file; do not execute it directly.
# Existing swap is always operator-owned. Only an absent configuration is created.

coreui_swap_info() {
  printf '[Ember CoreUI][Swap] [OK] %s\n' "$*"
}

coreui_swap_warn() {
  printf '[Ember CoreUI][Swap] [WARN] %s\n' "$*" >&2
}

coreui_swap_has_active() {
  local proc_swaps="$1"
  awk 'NR > 1 && NF >= 2 { found = 1 } END { exit !found }' "$proc_swaps"
}

coreui_swap_has_fstab_entry() {
  local fstab="$1"
  awk '$0 !~ /^[[:space:]]*#/ && NF >= 3 && $3 == "swap" { found = 1 } END { exit !found }' "$fstab"
}

coreui_swap_has_custom_swappiness() {
  local sysctl_dir="$1"
  local file

  if [[ "$sysctl_dir" == '/etc/sysctl.d' ]] \
      && grep -Eq '^[[:space:]]*vm[.]swappiness[[:space:]]*=' /etc/sysctl.conf 2>/dev/null; then
    return 0
  fi
  for file in "$sysctl_dir"/*.conf; do
    [[ -f "$file" ]] || continue
    if grep -Eq '^[[:space:]]*vm[.]swappiness[[:space:]]*=' "$file"; then
      return 0
    fi
  done
  return 1
}

coreui_swap_status() {
  local proc_swaps="${1:-/proc/swaps}"
  local fstab="${2:-/etc/fstab}"
  if [[ ! -r "$proc_swaps" || ! -r "$fstab" ]]; then
    coreui_swap_warn 'Swap-Status nicht vollstaendig lesbar.'
    return 0
  fi
  if coreui_swap_has_active "$proc_swaps"; then
    coreui_swap_info 'Aktiver Swap vorhanden. Installer veraendert ihn nicht.'
  elif coreui_swap_has_fstab_entry "$fstab"; then
    coreui_swap_warn 'Swap ist in /etc/fstab eingetragen, aber inaktiv. Keine automatische Aenderung.'
  else
    coreui_swap_warn 'Kein aktiver oder in /etc/fstab eingetragener Swap vorhanden.'
  fi
}

# The optional path arguments are intended for isolated unit tests.
# This subshell releases the advisory host lock and restores shell state.
coreui_ensure_host_swap() (
  local swapfile="${1:-/swapfile}"
  local fstab="${2:-/etc/fstab}"
  local proc_swaps="${3:-/proc/swaps}"
  local sysctl_file="${4:-/etc/sysctl.d/99-stu-coreui-swap.conf}"
  local lockfile="${5:-/run/stu-host-swap.lock}"
  local size_gib="${COREUI_SWAP_SIZE_GIB:-8}"
  local swappiness="${COREUI_SWAP_SWAPPINESS:-10}"
  local directory fstype free_kib required_kib lockfd

  case "${COREUI_SWAP_AUTOCREATE:-1}" in
    1|true|TRUE|yes|YES|on|ON) ;;
    *) coreui_swap_info 'Automatische Swap-Einrichtung deaktiviert.'; return 0 ;;
  esac

  if [[ ! -r "$proc_swaps" || ! -r "$fstab" || ! -w "$fstab" ]]; then
    coreui_swap_warn 'Swap-Status oder /etc/fstab nicht sicher zugaenglich. Host bleibt unveraendert.'
    return 0
  fi
  if [[ "$swapfile" != /* || "$lockfile" != /* ]]; then
    coreui_swap_warn 'Unsicherer Swap- oder Lock-Dateipfad. Einrichtung uebersprungen.'
    return 0
  fi
  if [[ ! "$size_gib" =~ ^[1-9][0-9]?$ ]] || (( size_gib > 64 )); then
    coreui_swap_warn 'COREUI_SWAP_SIZE_GIB muss zwischen 1 und 64 liegen.'
    return 0
  fi
  if [[ ! "$swappiness" =~ ^(0|[1-9][0-9]{0,2})$ ]] || (( swappiness > 200 )); then
    coreui_swap_warn 'COREUI_SWAP_SWAPPINESS muss zwischen 0 und 200 liegen.'
    return 0
  fi

  local cmd
  for cmd in flock findmnt df fallocate chmod mkswap swapon awk; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
      coreui_swap_warn "Benoetigter Befehl fehlt: $cmd. Host bleibt unveraendert."
      return 0
    fi
  done

  # Protect repeated CoreUI runs and coordinate with other STU installers
  # that use the same lockfile.
  if [[ -L "$lockfile" || ( -e "$lockfile" && ! -f "$lockfile" ) ]]; then
    coreui_swap_warn 'Unsicherer Swap-Lockpfad. Einrichtung uebersprungen.'
    return 0
  fi
  if ! exec {lockfd}>"$lockfile"; then
    coreui_swap_warn 'Host-Swap-Lock konnte nicht geoeffnet werden.'
    return 0
  fi
  if ! flock -x -w 20 "$lockfd"; then
    coreui_swap_warn 'Host-Swap-Lock ist belegt. Erneute Pruefung beim naechsten Lauf.'
    return 0
  fi

  if coreui_swap_has_active "$proc_swaps"; then
    coreui_swap_info 'Vorhandenen aktiven Swap erkannt. Keine Aenderung.'
    return 0
  fi
  if coreui_swap_has_fstab_entry "$fstab"; then
    coreui_swap_warn 'Konfigurierte Swap-Partition/Datei ist inaktiv. Manuelle Pruefung statt Ueberschreiben.'
    return 0
  fi
  if [[ -e "$swapfile" || -L "$swapfile" ]]; then
    coreui_swap_warn "Vorhandenes $swapfile wird niemals ueberschrieben."
    return 0
  fi

  directory="$(dirname -- "$swapfile")"
  fstype="$(findmnt -n -o FSTYPE -T "$directory" 2>/dev/null || true)"
  case "$fstype" in
    ext4|xfs) ;;
    *) coreui_swap_warn "Dateisystem ${fstype:-unbekannt} nicht freigegeben (nur ext4/XFS)."; return 0 ;;
  esac

  free_kib="$(df -Pk "$directory" 2>/dev/null | awk 'NR == 2 { print $4 }' || true)"
  required_kib=$(( (size_gib + 1) * 1024 * 1024 ))
  if [[ ! "$free_kib" =~ ^[0-9]+$ ]] || (( free_kib < required_kib )); then
    coreui_swap_warn "Mindestens ${size_gib} GiB plus 1 GiB freie Reserve erforderlich."
    return 0
  fi

  coreui_swap_info "Kein Swap gefunden. Lege ${size_gib} GiB unter $swapfile an."
  if ! (umask 077; fallocate -l "${size_gib}G" "$swapfile"); then
    coreui_swap_warn 'Swapdatei konnte nicht erstellt werden.'
    [[ ! -f "$swapfile" ]] || rm -f -- "$swapfile" || true
    return 0
  fi
  if ! chmod 0600 "$swapfile" || ! mkswap "$swapfile" >/dev/null 2>&1; then
    coreui_swap_warn 'Swapdatei konnte nicht initialisiert werden.'
    rm -f -- "$swapfile" || true
    return 0
  fi
  if ! swapon "$swapfile"; then
    # On an ambiguous partial activation, never delete a possibly active file.
    if awk -v path="$swapfile" 'NR > 1 && $1 == path { found = 1 } END { exit !found }' "$proc_swaps"; then
      coreui_swap_warn 'Swap ist trotz Fehlerrueckgabe aktiv. Bitte Zustand manuell pruefen.'
    else
      coreui_swap_warn 'swapon fehlgeschlagen. Neue inaktive Datei wird entfernt.'
      rm -f -- "$swapfile" || true
    fi
    return 0
  fi

  if ! printf '\n%s none swap sw 0 0\n' "$swapfile" >> "$fstab"; then
    coreui_swap_warn 'Swap ist aktiv, /etc/fstab konnte aber nicht gesichert werden. Bitte manuell korrigieren.'
    return 0
  fi

  if [[ -e "$sysctl_file" || -L "$sysctl_file" ]] \
      || coreui_swap_has_custom_swappiness "$(dirname -- "$sysctl_file")"; then
    coreui_swap_info 'Vorhandene Betreiber-Swappiness bleibt unangetastet.'
  elif (set -C; printf '# CoreUI-managed new swap only\nvm.swappiness=%s\n' "$swappiness" > "$sysctl_file"); then
    chmod 0644 "$sysctl_file" || coreui_swap_warn 'Berechtigungen der sysctl-Datei konnten nicht gesetzt werden.'
    if command -v sysctl >/dev/null 2>&1; then
      sysctl -w "vm.swappiness=$swappiness" >/dev/null \
        || coreui_swap_warn 'Swappiness konnte nicht sofort aktiviert werden.'
    fi
  else
    coreui_swap_warn 'Swappiness-Konfiguration konnte nicht geschrieben werden; Swap bleibt aktiv.'
  fi

  coreui_swap_info "${size_gib} GiB Swap aktiv und fuer Neustarts in /etc/fstab hinterlegt."
)
