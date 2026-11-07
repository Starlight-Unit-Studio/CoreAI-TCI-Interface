#!/usr/bin/env python3
"""Offline check for opt-in percent markers of the actual CoreAI updater."""
from pathlib import Path
import json
import os
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "scripts" / "lib" / "coreai_update_state.py"
LINE = "Lade den neuesten CoreAI: TCI Interface-Entwicklungsstand aus main.\n"


def run(operator: bool):
    with tempfile.TemporaryDirectory(prefix="coreai-progress-check-") as folder:
        status = Path(folder) / "status.json"
        status.write_text(json.dumps({"state": "running", "percent": 10}), encoding="utf-8")
        env = os.environ.copy()
        env.pop("COREAI_OPERATOR_PROGRESS", None)
        if operator:
            env["COREAI_OPERATOR_PROGRESS"] = "1"
        process = subprocess.run(
            [sys.executable, "-I", str(HELPER), "follow", str(status)],
            input=LINE, text=True, capture_output=True, env=env, timeout=8, check=False
        )
        assert process.returncode == 0, process.stderr
        parsed = json.loads(status.read_text(encoding="utf-8"))
        assert parsed["percent"] == 12, parsed
        assert LINE.strip() in process.stdout
        if operator:
            assert "__COREAI_PROGRESS__:12\n" in process.stdout, process.stdout
        else:
            assert "__COREAI_PROGRESS__" not in process.stdout


if __name__ == "__main__":
    run(True)
    run(False)
    print("Native CoreAI updater progress: operator only, offline OK")
