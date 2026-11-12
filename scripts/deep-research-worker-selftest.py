#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""CoreAI 0.7.38: Deep Research in the browse worker, without database, network or Core.

    python3 scripts/deep-research-worker-selftest.py

Checked: the pure parts of tools/coreai_deep_research.py (page text, queries, notes, report with
only known citations, page choice, budget), a whole run with a fake IO (events in order, numbered
sources, states, progress up to 100, cited sources), Stopp, a Core error, the time budget, and the
worker's page fetch (local addresses and redirects to them blocked, only HTML or text).
"""
import importlib.util
import json
import sys
import types
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / "tools"))
import coreai_deep_research as dr  # noqa: E402


def check(ok, why):
    if not ok:
        print(f"Deep research worker FAIL: {why}", file=sys.stderr)
        sys.exit(1)


# --- Pure parts ---------------------------------------------------------------------------------
page = ("<html><head><title>Starlight &amp; Co | Start</title><script>var x='<p>nope</p>'</script>"
        "<style>p{}</style></head><body><nav>Menü Home Kontakt</nav><h1>Willkommen</h1><p>Erster Absatz mit Inhalt.</p>"
        "<p>Zweiter&nbsp;Absatz.</p><footer>Impressum</footer><!-- c --></body></html>")
check(dr.html_title(page) == "Starlight & Co | Start", "title")
text = dr.html_to_text(page)
check("nope" not in text and "Menü" not in text and "Impressum" not in text, "scripts, menus and footers are dropped: " + text)
check(text.split("\n") == ["Willkommen", "Erster Absatz mit Inhalt.", "Zweiter Absatz."], "paragraphs kept: " + repr(text))
check(dr.domain_of("https://www.Example.test/a?b") == "example.test", "domain without www")
steps, writing = dr.research_steps(["Durchsuche die Website.", "Suche externe Quellen.",
                                    "Fasse die Erkenntnisse in einem strukturierten Bericht zusammen."])
check(writing and steps == ["Durchsuche die Website.", "Suche externe Quellen."], "the last report step writes")
steps2, writing2 = dr.research_steps(["Vergleiche A und B.", "Suche Preise."])
check(not writing2 and len(steps2) == 2, "no report step: all steps search")

q = dr.parse_queries('{"steps": [{"queries": ["site:example.test angebote", "\\"example test\\" produkte", "x"]}, '
                     '{"queries": "example test bewertung"}]}', "https://www.example.test", ["A", "B", "C"], 3)
check(q[0] == ["site:example.test angebote", "example test produkte"], "queries of step 1: %r" % q[0])
check(q[1] == ["example test bewertung"], "a single string counts")
check(q[2] and q[2][0].startswith("example.test"), "a step without queries gets its own words: %r" % q[2])
check(dr.parse_queries("kein json", "Solarstrom Balkon", ["Suche nach Förderprogrammen."], 2)[0][0].startswith("Solarstrom Balkon"),
      "fallback queries keep the topic")

short, notes = dr.split_notes("KURZ: Ich habe die Angebote gefunden.\n- Bücher [1]\n- Spiel [2]")
check(short == "Ich habe die Angebote gefunden." and notes.startswith("- Bücher [1]"), "notes split")
check(dr.split_notes("- nur Stichpunkte [1]")[0] == "", "notes without KURZ")

report, cited = dr.clean_report("```markdown\n## Zusammenfassung\nA [1, 9]. B [2][2] C [7].\n\n## Quellen\n1. x\n```",
                                "Titel", {1, 2, 3})
check(report.startswith("# Titel\n\n## Zusammenfassung"), "a title line is added: " + report[:40])
check("[9]" not in report and "[7]" not in report and "[1]" in report and "Quellen" not in report, "unknown citations and the source list go: " + report)
check(cited == [1, 2], "cited sources: %r" % cited)
check("C." in report, "no space before the full stop after a removed citation: " + report)

results = [{"url": "https://a.test/1"}, {"url": "https://a.test/2"}, {"url": "https://b.test/1"},
           {"url": "ftp://c.test"}, {"url": "https://c.test/1"}]
picked = [r["url"] for r in dr.pick_pages(results, {"https://c.test/1"}, 3)]
check(picked == ["https://a.test/1", "https://b.test/1", "https://a.test/2"], "different domains first, seen and non-http skipped: %r" % picked)
check(dr.page_chars(4096, 3) == 2252 and dr.page_chars(None, 3) == 2252 and dr.page_chars(100, 3) == 900 and dr.page_chars(65536, 3) == 5000,
      "page size follows the window within bounds")
b = dr.budget_from({"STU_DEEP_RESEARCH_PAGES_PER_STEP": "99", "STU_DEEP_RESEARCH_MAX_MINUTES": "x"})
check(b["pages_per_step"] == 6 and b["max_minutes"] == 30 and b["queries_per_step"] == 3, "budget bounds: %r" % b)


# --- A whole run with a fake IO -------------------------------------------------------------------
class FakeIO:
    def __init__(self, cancel_after=None, core_error=False):
        self.log, self.sources, self.progresses, self.finished = [], [], [], None
        self.cancel_after, self.checks, self.core_error, self.core_calls = cancel_after, 0, core_error, []
        self.writing = False

    def cancelled(self):
        self.checks += 1
        return self.cancel_after is not None and self.checks > self.cancel_after

    def event(self, kind, text, domains):
        self.log.append((kind, text, list(domains)))

    def progress(self, states, phase, percent, searches):
        self.progresses.append((list(states), phase, percent, searches))

    def source(self, entry):
        self.sources.append(dict(entry))

    def write_phase(self):
        self.writing = True

    def search(self, query):
        if "fehlt" in query:
            return []
        host = "example.test" if "site:" in query else "other.test"
        return [{"url": f"https://www.{host}/{abs(hash(query)) % 1000}", "title": "Seite zu " + query, "content": "Ausschnitt " * 8},
                {"url": "https://blocked.test/x", "title": "Gesperrt", "content": "Ein Ausschnitt, der für eine Quelle reicht, wenn die Seite nicht lädt."}]

    def fetch(self, url):
        if "blocked" in url:
            raise RuntimeError("Adressen im lokalen Netz werden nicht geöffnet")
        return "Titel " + url.rsplit("/", 1)[-1], "Inhalt der Seite " * 20

    def core(self, system, user, want_json, timeout):
        self.core_calls.append((want_json, user[:40]))
        if self.core_error:
            raise RuntimeError("core down")
        if want_json:
            return json.dumps({"steps": [{"queries": ["site:example.test angebote"]}, {"queries": ["example test markt", "fehlt hier"]}]})
        if "SEITEN" in user:
            return "KURZ: Ich habe die Angebote gefunden.\n- Fakt eins [1]\n- Fakt zwei [2]"
        return "# Bericht\n\n## Zusammenfassung\n" + "Viel Inhalt mit Beleg [1] und [2] und erfundenem [42]. " * 8

    def finish(self, status, report, cited, error):
        self.finished = (status, report, cited, error)


run = {"query": "https://www.example.test", "title": "Analyse example.test", "num_ctx": 4096,
       "steps": ["Durchsuche die Website.", "Ergänze externe Quellen.", "Fasse alles in einem Bericht zusammen."]}
io = FakeIO()
check(dr.run_deep_research(run, io, dr.budget_from({})) == "done", "the run finishes")
kinds = [k for k, _, _ in io.log]
check(kinds[0] == "step" and kinds.count("step") == 2 and kinds.count("search") == 3 and "note" in kinds, "events: %r" % kinds)
check(io.log[1] == ("search", "site:example.test angebote", ["example.test", "blocked.test"]), "a search names its domains: %r" % (io.log[1],))
check([s["n"] for s in io.sources] == list(range(1, len(io.sources) + 1)) and len(io.sources) == 3, "sources numbered, a page seen in step 1 is not read again: %r" % io.sources)
check(any(s["domain"] == "blocked.test" for s in io.sources), "a page that does not load still counts with its search snippet")
check(io.writing and io.finished[0] == "done" and "[42]" not in io.finished[1] and io.finished[2] == [1, 2], "report: %r" % (io.finished[:1] + io.finished[2:],))
percents = [p for _, _, p, _ in io.progresses]
check(percents == sorted(percents) and percents[-1] == 100, "progress only grows and ends at 100: %r" % percents)
check(io.progresses[-1][0] == ["done", "done", "done"] and io.progresses[-1][3] == 3, "all steps done, three searches")
active = [s for s, phase, _, _ in io.progresses if phase == "write"][0]
check(active == ["done", "done", "active"], "the report step is active while writing: %r" % active)
check([c[0] for c in io.core_calls] == [True, False, False, False], "one query call, a notes call per step, one report call")

io = FakeIO(cancel_after=3)
check(dr.run_deep_research(run, io, dr.budget_from({})) == "cancelled" and io.finished[0] == "cancelled", "Stopp ends the run")
io = FakeIO(core_error=True)
check(dr.run_deep_research(run, io, dr.budget_from({})) == "error" and io.finished[3] == "core down", "a Core error ends the run honestly")

ticks = iter([0, 0, 10_000, 10_000, 10_000, 10_000, 10_000, 10_000])
io = FakeIO()
dr.run_deep_research(run, io, dr.budget_from({"STU_DEEP_RESEARCH_MAX_MINUTES": 3}), clock=lambda: next(ticks, 10_000))
check(any(k == "info" and "Zeitrahmen" in t for k, t, _ in io.log) and io.finished[0] == "done", "time budget: the report still comes")


# --- The worker's page fetch ----------------------------------------------------------------------
def have(module):
    try:
        return importlib.util.find_spec(module) is not None
    except (ImportError, ValueError):
        return False


if not have("pymysql"):
    sys.modules["pymysql"] = types.ModuleType("pymysql")
if not have("requests"):
    sys.modules["requests"] = types.ModuleType("requests")
    sys.modules["requests"].exceptions = types.SimpleNamespace(
        ReadTimeout=type("ReadTimeout", (Exception,), {}), ConnectTimeout=type("ConnectTimeout", (Exception,), {}),
        ConnectionError=type("ConnectionError", (Exception,), {}))
if not have("playwright"):
    sync = types.ModuleType("playwright.sync_api")
    sync.sync_playwright = None
    sync.TimeoutError = type("TimeoutError", (Exception,), {})
    sys.modules["playwright"] = types.ModuleType("playwright")
    sys.modules["playwright.sync_api"] = sync
spec = importlib.util.spec_from_file_location("ember_browse_worker", ROOT / "tools" / "ember_browse_worker.py")
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)


class Resp:
    def __init__(self, status=200, headers=None, body=b"", encoding="utf-8"):
        self.status_code, self.headers, self.body, self.encoding = status, headers or {}, body, encoding

    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError("http %d" % self.status_code)

    def iter_content(self, size):
        yield self.body

    def close(self):
        pass


def guard(url):
    return "Adressen im lokalen Netz werden nicht geöffnet" if "127.0.0.1" in url or "intern" in url else None


pages = {
    "https://ok.test/": Resp(200, {"Content-Type": "text/html; charset=utf-8"}, page.encode()),
    "https://jump.test/": Resp(302, {"Location": "http://127.0.0.1/admin"}),
    "https://pdf.test/": Resp(200, {"Content-Type": "application/pdf"}, b"%PDF"),
    "https://move.test/": Resp(301, {"Location": "/neu"}),
    "https://move.test/neu": Resp(200, {"Content-Type": "text/plain"}, b"Nur   Text"),
}
get = lambda url, **kw: pages[url]  # noqa: E731
title, body = w.fetch_page({}, "https://ok.test/", guard=guard, get=get)
check(title == "Starlight & Co | Start" and "Erster Absatz" in body, "a page is read")
check(w.fetch_page({}, "https://move.test/", guard=guard, get=get) == ("", "Nur Text"), "a redirect inside the web is followed")
for url, why in (("http://intern.test/", "local address"), ("https://jump.test/", "redirect to a local address"), ("https://pdf.test/", "no text")):
    try:
        w.fetch_page({}, url, guard=guard, get=get)
        check(False, why + " must not be read")
    except Exception as exc:  # noqa: PERF203
        check(isinstance(exc, (w.BrowseBlocked, ValueError)), why + ": %r" % exc)
check(callable(w.claim_next_research) and callable(w.process_research), "the worker claims and runs research")

print("CoreAI deep research worker: page text, queries, notes, report citations, page choice, budget, a whole run, "
      "Stopp, Core error, time budget, page fetch with the local-address guard OK")
