#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Offline hybrid research regression. No DB, cloud account, server or browser.

Run: python3 scripts/deep-research-hybrid-selftest.py
"""
import copy
import json
import re
import sys
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / "tools"))
import coreai_deep_research as dr
import coreai_research_hybrid as hybrid
import coreai_research_quality as quality
import coreai_research_documents as documents


def check(value, reason):
    if not value:
        raise AssertionError("Hybrid Deep Research FAIL: " + str(reason))


class IO:
    def __init__(self):
        self.saved = None
        self.events = []
        self.progresses = []
        self.sources = []
        self.evidence = {}
        self.finished = None
        self.search_threads = set()
        self.fetch_threads = set()
        self.model_calls = 0

    def load_checkpoint(self):
        return copy.deepcopy(self.saved)

    def checkpoint(self, state):
        self.saved = copy.deepcopy(state)

    def cancelled(self):
        return False

    def event(self, kind, message, domains):
        self.events.append((kind, str(message), domains))

    def progress(self, states, phase, percent, searches):
        self.progresses.append((states[:], phase, percent, searches))

    def source(self, entry):
        if not any(s["n"] == entry["n"] for s in self.sources):
            self.sources.append(entry.copy())

    def store_evidence(self, number, excerpt, checksum):
        self.evidence[number] = (excerpt, checksum)

    def search(self, query):
        self.search_threads.add(threading.current_thread().name)
        if "werke" in quality.fold(query) or "romane" in quality.fold(query):
            return [{"url": "https://books.example/author",
                     "title": "Mira Solberg Romane",
                     "content": "Mira Solberg ist Autorin der Romanreihe."}]
        return [{"url": "https://studio.example/about",
                 "title": "Mira Solberg Studio",
                 "content": "Mira Solberg entwickelt Software."}]

    def fetch(self, url):
        self.fetch_threads.add(threading.current_thread().name)
        if "books.example" in url:
            return "Mira Solberg Romane", (
                "Mira Solberg hat mehrere Romane veröffentlicht. "
                "Mira Solberg arbeitet an fantastischen Geschichten. " * 8)
        return "Mira Solberg Studio", (
            "Mira Solberg entwickelt Software für kreative Projekte. "
            "Mira Solberg arbeitet an einer lokalen Softwareplattform. " * 8)

    def core(self, system, prompt, json_out, timeout):
        check(threading.current_thread().name == "MainThread",
              "model calls must never run on parallel I/O threads")
        self.model_calls += 1
        if "bereitest eine gruendliche Webrecherche" in system:
            return json.dumps({"steps": [
                {"queries": ['"Mira Solberg" Romane Werke']},
                {"queries": ['"Mira Solberg" Studio Software']}
            ]})
        if "Lead Researcher" in system:
            return '{"stop":true,"followups":[],"new_tasks":[]}'
        if "haeltst Fakten fest" in system:
            match = re.search(r"\[(\d+)\]", prompt)
            number = int(match.group(1)) if match else 0
            if "Romane" in prompt:
                return f"KURZ: Ich habe Romane gefunden.\n- Mira Solberg hat Romane veröffentlicht [{number}]"
            return f"KURZ: Ich habe das Studio gefunden.\n- Mira Solberg entwickelt Software für kreative Projekte [{number}]"
        if "Citation Agent" in system:
            return prompt.split("BERICHT:\n", 1)[1].split("\n\nORIGINALAUSZÜGE:", 1)[0]
        if "Recherchebericht" in system:
            return ("# Mira Solberg\n\n## Zusammenfassung\n\n"
                    "Mira Solberg hat Romane veröffentlicht und entwickelt Software für "
                    "kreative Projekte [1][2].\n\n"
                    "## Tätigkeiten\n\n"
                    "Mira Solberg entwickelt Software für kreative Projekte [2]. "
                    "Mira Solberg hat Romane veröffentlicht [1].")
        return ""

    def write_phase(self):
        self.events.append(("write", "report", []))

    def finish(self, status, report, cited, error):
        self.finished = (status, report, cited, error)


run = {
    "query": "Recherchiere Mira Solberg", "title": "Mira Solberg",
    "steps": ["Finde Romane und Werke.", "Untersuche Studio und Software.",
              "Schreibe einen strukturierten Bericht mit Quellen."],
    "num_ctx": 4096,
}
budget = dr.budget_from({})
check(budget["hybrid_enabled"] == 1 and budget["io_workers"] >= 2, "hybrid default on")
io = IO()
status = hybrid.run_hybrid_research(run, io, budget)
check(status == "done" and io.finished[0] == "done", "hybrid run completes")
check(len(io.sources) == 2, f"both independent workers find primary sources: {io.sources}")
check(set(io.evidence) == {1, 2}, "source excerpts are independently cached")
check(all(io.saved["workers"][i]["notes"] for i in (0, 1)), "isolated worker Map summaries")
check(io.saved["phase"] == "done" and io.saved["depth"] >= 1, "persistent stage checkpoint")
check(io.progresses[-1][2] == 100 and all(
    earlier[2] <= later[2] for earlier, later in zip(io.progresses, io.progresses[1:])),
    "monotonic progress, even on alternate searches")
check(len(io.search_threads) > 0 and "MainThread" not in io.search_threads,
      "searches are performed in a network I/O pool")
check(len(io.fetch_threads) > 0 and "MainThread" not in io.fetch_threads,
      "page reads are performed in a network I/O pool")
check(io.model_calls >= 4, "Lead, isolated Map workers, Reduce and citation review")
check(io.finished[2] == [1, 2], f"only verified sources cited: {io.finished[2]}")
check("Mira Solberg" in io.finished[1], "source-grounded report")
check(not any("system prompt" in str(x).lower() for x in io.saved.values()
              if isinstance(x, str)), "no private model thinking in checkpoint")

# A claimed citation does not pass merely because its number exists.
state = hybrid._new_state(run)
state["sources"] = [{"n": 1, "url": "https://studio.example",
                      "title": "Studio", "domain": "studio.example"}]
state["evidence"] = {"1": "Mira Solberg develops creative software and books. " * 5}
report, nums = hybrid._audit_report(
    "# Title\n\nUnrelated claims about alien planets and extra-terrestrial volcanos [1].\n"
    "Mira Solberg develops creative software [1].\n"
    "Invented unsupported sentence about an unrelated scientist.", state)
check("alien" not in report and "Invented" not in report and nums == [1],
      "citation audit filters unsupported claims and invented references")

# Checkpoint recovery keeps the same evidence IDs and does not re-fetch known
# URLs when the server resumes a previously running, incomplete task.
resume = IO()
resume.saved = copy.deepcopy(io.saved)
resume.saved["phase"] = "reduce"
resume.saved["depth"] = 1
resume.saved["report"] = ""
resume.saved["workers"][0]["status"] = "todo"
resume.saved["workers"][1]["status"] = "todo"
resume_result = hybrid.run_hybrid_research(run, resume, budget)
check(resume_result == "done" and resume.saved["depth"] >= 1,
      "a research job resumes from structured external memory")
check(len(resume.sources) == 0 and resume.saved["sources"] == io.saved["sources"],
      "resuming does not insert duplicate source references")

# Parse CSV/JSON with a fake public HTTP response, and reject redirect-to-local.
class Resp:
    def __init__(self, body, kind, status=200, redirect=""):
        self.body, self.kind, self.status_code = body, kind, status
        self.headers = {"Content-Type": kind}
        if redirect:
            self.headers["Location"] = redirect
    def raise_for_status(self):
        if self.status_code >= 400:
            raise ValueError("status failure")
    def iter_content(self, chunksize):
        yield self.body
    def close(self):
        pass


def fake_get(url, **kw):
    if url.endswith(".csv"):
        return Resp(b"name,value\nJan,10\nFeb,20\n", "text/csv")
    if url.endswith(".json"):
        return Resp(b'{"research": "Mira Solberg"}', "application/json")
    return Resp(b"", "text/plain")


def public_guard(url, cfg):
    return "local target" if "127.0.0.1" in url else None


for url in ("https://safe.example/stats.csv", "https://safe.example/data.json"):
    title, body, end = documents.fetch_document({}, url, public_guard, get=fake_get)
    check(title and body and end == url, "supported structured public document")
try:
    documents.fetch_document({}, "http://127.0.0.1/data.csv", public_guard, get=fake_get)
    check(False, "document reader must reject private network")
except ValueError:
    pass

print("CoreAI hybrid Deep Research: Lead, Map/Reduce, IO concurrency, memory, citation audit and documents OK")
