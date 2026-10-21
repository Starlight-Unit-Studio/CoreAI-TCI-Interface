#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
printf 'Ember CoreUI Python-Werkzeugdiagnose (nur Status, keine Dateiinhalte)\n'

if [[ -f "$ROOT/var/compose.env" ]]; then
  if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
  else
    printf 'FEHLER: Docker Compose ist nicht verfuegbar.\n' >&2
    exit 1
  fi
  printf '\n[1] Compose-Python-Worker\n'
  "${COMPOSE[@]}" --project-name ember-coreui \
    --env-file "$ROOT/var/compose.env" --file "$ROOT/compose.yaml" \
    ps --all pyworker
  printf '\n[2] Python-Jobzustand (DB, keine privaten Daten)\n'
  "${COMPOSE[@]}" --project-name ember-coreui \
    --env-file "$ROOT/var/compose.env" --file "$ROOT/compose.yaml" \
    exec -T php php scripts/pyworker-diagnostics.php
  printf '\nWorker-Logs fuer lokale Pruefung: docker compose --project-name ember-coreui '
  printf -- '--env-file %s --file %s logs --tail=80 pyworker\n' \
    "$ROOT/var/compose.env" "$ROOT/compose.yaml"
else
  printf '\n[1] Nativer Python-Worker\n'
  systemctl is-active ember-coreui-python.service || true
  printf '\n[2] Python-Jobzustand (DB, keine privaten Daten)\n'
  php "$ROOT/scripts/pyworker-diagnostics.php"
  printf '\nWorker-Logs fuer lokale Pruefung: journalctl -u ember-coreui-python.service -n 80 --no-pager\n'
fi
printf '\nHinweis: Ein Modellstream ohne Python-Job ist zuerst ein Modell-/Providerpfadproblem.\n'
