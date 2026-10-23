#!/usr/bin/env python3
"""Offline regression for the *real worker's* sandbox syntax preflight.

Runs trusted preflight code on temporary files without Docker, network, or DB.
The production runtime adds the existing Docker isolation around the same code.
"""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from ember_py_worker import (  # noqa: E402
    python_syntax_preflight_bootstrap,
    is_syntax_preflight_failure,
    SYNTAX_PREFLIGHT_EXIT,
)


def run(code: str, work: Path):
    script = work / "main.py"
    script.write_text(code, encoding="utf-8")
    return subprocess.run(
        [sys.executable, "-I", "-c", python_syntax_preflight_bootstrap(str(script))],
        capture_output=True, text=True, timeout=10, check=False,
    )


def check(ok: bool, why: str):
    if not ok:
        raise AssertionError(why)


def main():
    with tempfile.TemporaryDirectory(prefix="coreui-py-preflight-") as folder:
        work = Path(folder)
        good = run("print('durchgelaufen')\n", work)
        check(good.returncode == 0 and good.stdout.strip() == "durchgelaufen"
              and good.stderr == "", "valid Python must execute as normal")
        check(not is_syntax_preflight_failure(good.returncode, good.stderr),
              "valid code misclassified")

        side_effect = work / "should-not-exist.txt"
        bad = run(
            "from pathlib import Path\n"
            + "Path(" + repr(str(side_effect)) + ").write_text('bad')\n"
            + "def broken(:\n  pass\n", work)
        check(is_syntax_preflight_failure(bad.returncode, bad.stderr),
              "invalid Python must be rejected before execution")
        check(bad.returncode == SYNTAX_PREFLIGHT_EXIT
              and bad.stderr.startswith("COREUI_PY_SYNTAX_ERROR:SyntaxError:3:"),
              "safe parser location should be reported")
        check(not side_effect.exists(), "compile stage must have no side effects")
        check("Path(" not in bad.stderr and "should-not-exist" not in bad.stderr,
              "stderr must not leak source or private filesystem names")

        indent = run("def test():\nprint(1)\n", work)
        check(is_syntax_preflight_failure(indent.returncode, indent.stderr)
              and ":IndentationError:" in indent.stderr,
              "indentation errors must be rejected before execution")

        runtime = run("raise RuntimeError('ordinary runtime failure')\n", work)
        check(runtime.returncode != 0 and not is_syntax_preflight_failure(
                  runtime.returncode, runtime.stderr),
              "runtime failure must not be confused with syntax preflight")
        check("RuntimeError" in runtime.stderr, "runtime trace must remain available")

        unicode = run("print('Schröder π')\n", work)
        check(unicode.returncode == 0 and "π" in unicode.stdout,
              "valid UTF-8 code must be preserved")

    print("CoreUI general Python syntax preflight selftest: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
