#!/usr/bin/env python3
"""Offline regression for Vision Core's authenticated Nginx-to-PHP route.

The image worker's /vision unit tests cannot detect an omitted external
/api/vision_cores.php Nginx allowlist entry (0.7.45 / 0.7.46 incident).
Run this check in both preflights and on the PR before deployment.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ("config/runtime/nginx.conf.template", "config/nginx.conf.template")


def require(condition: bool, description: str) -> None:
    if not condition:
        raise AssertionError("Vision Core Nginx FAIL: " + description)


for relative in TEMPLATES:
    config = (ROOT / relative).read_text(encoding="utf-8")
    routes = [
        line.strip() for line in config.splitlines()
        if line.strip().startswith("location ~ ^/api/(?:")
    ]
    require(len(routes) == 1, relative + ": exactly one authenticated PHP allowlist")
    match = re.fullmatch(r"location ~ (.+) \{", routes[0])
    require(match is not None, relative + ": malformed PHP location")
    route = re.compile(match.group(1))
    for url in (
        "/api/vision_cores.php",
        "/api/health.php",
        "/api/models.php",
        "/api/chat.php",
    ):
        require(route.fullmatch(url) is not None, relative + ": rejected " + url)
    for url in (
        "/api/image_gen.php",
        "/api/vision_cores.php/extra",
        "/api/vision_cores_admin.php",
        "/api/config.local.php",
        "/api/vision_cores.php.bak",
    ):
        require(route.fullmatch(url) is None, relative + ": exposed " + url)
    deny = config.find("location ~ \\.php$ {")
    require(deny > config.find(routes[0]), relative + ": general PHP deny must follow allowlist")
    require("return 404;" in config[deny:deny + 100],
            relative + ": unknown PHP endpoints must still be denied")
    require("try_files $uri =404;" in config, relative + ": missing file guard")

bridge = (ROOT / "api/vision_cores.php").read_text(encoding="utf-8")
for expected in (
    "stu_require_user_id()",
    "stu_require_permission_at_most(1, $pdo)",
    "stu_require_csrf()",
    "coreui_image_http('GET', $path",
    "coreui_image_http('POST', '/vision'",
):
    require(expected in bridge, "bridge missing authorization or local image routing: " + expected)

browser = (ROOT / "js/coreui-vision-library.js").read_text(encoding="utf-8")
require("fetchFn('./api/vision_cores.php'" in browser, "UI must call the approved PHP bridge")
installer = (ROOT / "scripts/install.sh").read_text(encoding="utf-8")
require('"$PROJECT_ROOT/config/runtime/nginx.conf.template" > "$nginx_tmp"' in installer,
        "installer must render the updated Compose Nginx template")
require("compose up -d --force-recreate php web" in installer,
        "installer must apply updated Nginx route to the web container")
stack = (ROOT / "scripts/stack.sh").read_text(encoding="utf-8")
require('RUNTIME_NGINX_TEMPLATE="$PROJECT_ROOT/config/runtime/nginx.conf.template"' in stack
        and "render_runtime_files" in stack, "stack must support Nginx template refresh")

print("PASS: Vision Core PHP route allowed in both Nginx modes; all other PHP remains deny-by-default")
