#!/usr/bin/env python3
"""Offline lifecycle regression tests. No real Ollama server or downloads."""
from __future__ import annotations

from importlib import util
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

module_path = Path(__file__).resolve().parent / "ensure-generator-core.py"
spec = util.spec_from_file_location("coreai_generator", module_path)
assert spec is not None and spec.loader is not None
generator = util.module_from_spec(spec)
spec.loader.exec_module(generator)


class MockOllama:
    def __init__(self, models=(), fail_create=False):
        self.models = set(models)
        self.calls = []
        self.fail_create = fail_create

    def __call__(self, *args, quiet=False):
        self.calls.append(args)
        cmd = args[0]
        if cmd == "show":
            success = args[1] in self.models
        elif cmd == "pull":
            self.models.add(args[1])
            success = True
        elif cmd == "create":
            contents = Path(args[-1]).read_text(encoding="utf-8")
            assert "FROM " + generator.BASE in contents
            assert "SYSTEM \"\"\"Du bist Mara Thorne" in contents
            if self.fail_create:
                success = False
            else:
                self.models.add(args[1])
                success = True
        elif cmd == "rm":
            assert generator.GENERATOR in self.models
            self.models.discard(args[1])
            success = True
        else:
            raise AssertionError("unexpected Ollama action " + str(args))
        return SimpleNamespace(returncode=0 if success else 1)


with tempfile.TemporaryDirectory(prefix="coreai-generator-test-") as path:
    root = Path(path)
    (root / "config").mkdir()
    (root / "licenses").mkdir()
    (root / "var" / "cache").mkdir(parents=True)
    template = root / "config" / "Modelfile.template"
    template.write_text('FROM gemma4:e4b\nSYSTEM """Du bist Mara Thorne.\nHilf bei Bildern.\n"""\n'
                        'RENDERER gemma4\nPARSER gemma4\nPARAMETER num_ctx 131072\n', encoding="utf-8")
    generator.ROOT = root
    generator.STATE = root / "var" / "generator-core.json"
    generator.LICENSE_PATH = root / "licenses" / "FLUX-NON-COMMERCIAL-v2.1.txt"
    generator.LICENSE_RECEIPT = root / "var" / "cache" / "generator-license.json"
    generator.LICENSE_PATH.write_text("FLUX Non-Commercial License v2.1: offline test", encoding="utf-8")
    original = template.read_text(encoding="utf-8")

    empty = MockOllama()
    generator.ollama = empty
    with patch.dict(os.environ, {}, clear=True):
        assert generator.ensure() == 0
        assert empty.calls.count(("pull", generator.BASE)) == 0
        assert generator.check() == 3
    assert not generator.STATE.exists()
    assert not generator.license_accepted()
    generator.LICENSE_RECEIPT.write_text(__import__("json").dumps({
        "accepted": True,
        "license_sha256": __import__("hashlib").sha256(generator.LICENSE_PATH.read_bytes()).hexdigest(),
        "accepted_by": 1}) + "\n", encoding="utf-8")
    assert generator.license_accepted()

    inherited = MockOllama([generator.BASE])
    generator.ollama = inherited
    with patch.dict(os.environ, {}, clear=True):
        assert generator.check() == 2
        assert generator.ensure() == 0
        assert generator.GENERATOR in inherited.models
        assert generator.BASE in inherited.models, "preexisting base must survive by default"
        assert generator.STATE.exists()
        assert generator.check() == 0
        calls = len(inherited.calls)
        assert generator.ensure() == 0
        assert len(inherited.calls) == calls + 2, "only availability checks on unchanged run"
    assert template.read_text(encoding="utf-8") == original

    # Explicit operator choice permits removing a previously installed source tag.
    generator.ollama = inherited
    with patch.dict(os.environ, {"COREUI_GENERATOR_REMOVE_EXISTING_BASE": "1"}, clear=True):
        assert generator.ensure() == 0
    assert generator.BASE not in inherited.models

    generator.STATE.unlink()
    fresh = MockOllama()
    generator.ollama = fresh
    with patch.dict(os.environ, {}, clear=True):
        assert generator.ensure() == 0
    assert generator.GENERATOR in fresh.models and generator.BASE not in fresh.models
    assert ("pull", generator.BASE) in fresh.calls and ("rm", generator.BASE) in fresh.calls
    assert template.read_text(encoding="utf-8") == original

    # A changed persona causes a rebuild; previous cleanup did not lose model weights.
    template.write_text(original.replace("Hilf bei Bildern.", "Hilf immer bei Bildern."), encoding="utf-8")
    with patch.dict(os.environ, {}, clear=True):
        assert generator.ensure() == 0
    assert fresh.calls.count(("pull", generator.BASE)) == 2
    assert fresh.calls.count(("rm", generator.BASE)) == 2

    generator.STATE.unlink()
    failing = MockOllama([], fail_create=True)
    generator.ollama = failing
    with patch.dict(os.environ, {}, clear=True):
        assert generator.ensure() == 1
    assert generator.BASE in failing.models
    assert generator.GENERATOR not in failing.models
    assert ("rm", generator.BASE) not in failing.calls
    assert not generator.STATE.exists()

    # An unrelated tag of the same name is never silently overwritten or deleted.
    unmanaged = MockOllama([generator.GENERATOR, generator.BASE])
    generator.ollama = unmanaged
    with patch.dict(os.environ, {}, clear=True):
        assert generator.ensure() == 0
    assert not any(c[0] in ("rm", "create", "pull") for c in unmanaged.calls)
    assert template.read_text(encoding="utf-8").startswith("FROM gemma4:e4b")

print("CoreAI Generator: automatic setup, updates, read-only preflight, licensing, safe cleanup, failure recovery OK")
