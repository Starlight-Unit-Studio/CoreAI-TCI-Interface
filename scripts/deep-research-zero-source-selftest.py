#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Regression: search outage must never turn into a fake completed report."""
import copy
import json
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / "tools"))
import coreai_research_search as secondary
import coreai_research_hybrid as hybrid
import coreai_deep_research as dr


def check(condition, message):
    if not condition:
        raise AssertionError("Deep Research zero-source FAIL: " + message)


# A realistic public search HTML page with nested tags and redirect-wrapped URL.
html_doc = """<html><body><div class="result">
 <h2><a rel="nofollow" class="result__a"
 href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fpublisher.example%2Fauthor%2Fmira">
 <span>Mira <b>Solberg</b></span><img src="/icon.png">, Author</a></h2>
 <a class="result__snippet">Published fiction</a></div>
 <a class="result__a" href="http://127.0.0.1/secrets">Private site</a>
</body></html>"""


def public_guard(url, cfg):
    return "private" if "127.0.0.1" in url else None


parsed = secondary.parse_html(html_doc, guard=public_guard)
check(len(parsed) == 1, "public search result can be parsed while rejecting private URLs")
check(parsed[0]["url"] == "https://publisher.example/author/mira", "DDG redirect unwraps the actual source")
check(parsed[0]["title"] == "Mira Solberg, Author", "nested result title is readable")


class HTTP:
    status_code = 200
    headers = {}
    encoding = "utf-8"
    content = html_doc.encode("utf-8")
    def raise_for_status(self):
        pass
    def close(self):
        pass


requests = []
def get(url, **kw):
    requests.append((url, kw))
    return HTTP()


real = secondary.public_search("Mira Solberg", {}, public_guard, get=get)
check(len(real) == 1 and requests[0][1].get("allow_redirects") is False,
      "fallback fetches public discovery without following arbitrary redirects")
check("Mira Solberg" in requests[0][1]["params"]["q"], "subject preserved")


class IO:
    def __init__(self, backend_down=False):
        self.backend_down = backend_down
        self.checkpoints = []
        self.events = []
        self.searches = []
        self.complete = None
        self.write_calls = 0

    def cancelled(self):
        return False

    def load_checkpoint(self):
        return None

    def checkpoint(self, state):
        self.checkpoints.append(copy.deepcopy(state))

    def event(self, kind, message, domains):
        self.events.append((kind, str(message)))

    def progress(self, *args):
        pass

    def search(self, query):
        self.searches.append(query)
        if self.backend_down:
            raise OSError("SearXNG connection refused")
        return []

    def core(self, system, prompt, want_json, timeout):
        if "Lead Researcher" in system:
            return '{"stop":false,"followups":[],"new_tasks":[]}'
        return json.dumps({"steps": [
            {"queries": ['"Mira Solberg" books']},
            {"queries": ['"Mira Solberg" author']}
        ]})

    def finish(self, status, report, cited, error):
        self.complete = (status, report, cited, error)

    def write_phase(self):
        self.write_calls += 1


run = {"query": "Mira Solberg", "title": "Mira Solberg",
       "steps": ["Suche Bücher von Mira Solberg.", "Prüfe öffentliche Biografie.",
                 "Verfasse den Recherchebericht."], "num_ctx": 4096}
budget = dr.budget_from({})
for down, reason in ((True, "research_search_backend_unavailable"),
                     (False, "research_no_search_results")):
    io = IO(backend_down=down)
    status = hybrid.run_hybrid_research(run, io, budget)
    check(status == "error" and io.complete[0] == "error" and io.complete[3] == reason,
          "search failure must be a background error, not a successful zero-source report")
    check(io.complete[1] is None and io.complete[2] == [] and io.write_calls == 0,
          "do not synthesize, invent or announce a report without evidence")
    check(io.checkpoints[-1]["phase"] == "error", "failure is persisted in structured checkpoint")
    check(any("Suchanfragen:" in msg for _, msg in io.events),
          "worker activity includes search/hit/error diagnostics")
    check(len(io.searches) > 0, "research really attempted discovery")

print("CoreAI zero-source regression: public search parse, guard, outage and honest result OK")
