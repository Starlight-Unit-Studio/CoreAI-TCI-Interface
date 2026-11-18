#!/bin/sh
set -eu

project_root="${COREUI_PROJECT_ROOT:-/opt/ember-coreui}"
config_file="${STU_CONFIG:-$project_root/api/config.local.php}"
sandbox_image="${STU_EMBER_PY_IMAGE:-ember-py:2}"

test -S /var/run/docker.sock || {
  echo '[E.M.B.E.R. CoreUI Python] Docker socket is missing.' >&2
  exit 1
}
test -r "$config_file" || {
  echo '[E.M.B.E.R. CoreUI Python] Protected runtime configuration is missing.' >&2
  exit 1
}
test -r "$project_root/tools/ember_py_worker.py" || {
  echo '[E.M.B.E.R. CoreUI Python] Worker source is missing.' >&2
  exit 1
}

if ! docker image inspect "$sandbox_image" >/dev/null 2>&1; then
  echo "[E.M.B.E.R. CoreUI Python] Building the sandbox image $sandbox_image for the first time."
  docker build \
    --tag "$sandbox_image" \
    --file "$project_root/tools/ember_py.Dockerfile" \
    "$project_root/tools"
fi

exec python3 "$project_root/tools/ember_py_worker.py" \
  --config "$config_file" \
  --poll 2
