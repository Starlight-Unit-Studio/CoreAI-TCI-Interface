#!/usr/bin/env python3
"""Offline worker liveness safeguards. No database or Docker required."""
from __future__ import annotations

import ast
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = (root / "tools/ember_py_worker.py").read_text(encoding="utf-8")
tree = ast.parse(source)
functions = {
    item.name: item for item in tree.body if isinstance(item, ast.FunctionDef)
}


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def attr_calls(function: ast.FunctionDef, attribute: str) -> list[ast.Call]:
    return [
        node for node in ast.walk(function)
        if isinstance(node, ast.Call)
        and isinstance(node.func, ast.Attribute)
        and node.func.attr == attribute
    ]


for name in ("db_connect", "main", "process_job"):
    check(name in functions, f"Python worker function missing: {name}")

connect_calls = attr_calls(functions["db_connect"], "connect")
check(len(connect_calls) == 1, "Expected one PyMySQL connection entrypoint")
options = {
    keyword.arg: keyword.value for keyword in connect_calls[0].keywords
}
for key in ("connect_timeout", "read_timeout", "write_timeout"):
    check(
        key in options and isinstance(options[key], ast.Constant)
        and isinstance(options[key].value, int)
        and 1 <= options[key].value <= 30,
        f"Unbounded PyMySQL {key} can block the worker",
    )

for name in ("main", "process_job"):
    pings = attr_calls(functions[name], "ping")
    check(any(
        isinstance(keyword.value, ast.Constant) and keyword.value.value is True
        for call in pings for keyword in call.keywords
        if keyword.arg == "reconnect"
    ), f"Missing safe DB reconnect in {name}")

check("status='running'" in source and "worker_pid=%s" in source,
      "Do not overwrite a timed-out/foreign worker result")
print("Ember Python worker DB liveness selftest: OK")
