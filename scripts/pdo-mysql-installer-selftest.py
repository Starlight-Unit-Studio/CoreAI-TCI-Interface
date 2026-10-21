#!/usr/bin/env python3
"""Offline contract check: PHP database driver build, install and preflights."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(condition: bool, label: str) -> None:
    if not condition:
        raise AssertionError(label)


image = read("docker/php/Dockerfile")
installer = read("scripts/install.sh")
compose_preflight = read("scripts/preflight.sh")
native_preflight = read("scripts/preflight-native.sh")

require('docker-php-ext-install -j"$(nproc)"' in image
        and "pdo_mysql zip" in image, "pdo_mysql must be built into own PHP image")
require('extension_loaded("pdo_mysql")' in image,
        "built PHP image must validate that PDO MySQL actually loaded")
require('compose build php pyworker' in installer,
        "installer must rebuild its PHP service")
require("compose run -T --rm --no-deps php php -r 'exit(extension_loaded(\"pdo_mysql\") ? 0 : 1)'" in installer,
        "installer must probe the newly built image before starting PHP service")
require('compose up -d --force-recreate php web' in installer,
        "updater must actually replace stale running PHP service")
require("compose exec -T php php -r 'exit(extension_loaded(\"pdo_mysql\") ? 0 : 1)'" in installer,
        "installer must validate the running service")
for name, contents in (
    ("Compose", compose_preflight),
    ("Native", native_preflight),
):
    require('extension_loaded($argv[1])' in contents,
            name + " preflight must query actual PHP runtime")
    require('php -m | grep' not in contents
            and 'php -m 2>/dev/null | grep' not in contents,
            name + " must not use fragile module-list pipeline")
    require('for extension_name in curl dom gd mbstring pdo_mysql zip' in contents,
            name + " must still require pdo_mysql")
print("PDO-MySQL installer and preflight contract: OK")
