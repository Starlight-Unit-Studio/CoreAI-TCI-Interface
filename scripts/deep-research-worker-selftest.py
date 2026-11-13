#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""CoreAI 0.7.40: Deep Research in the browse worker, without database, network or Core.

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
check(q[0] == ["site:example.test angebote", '"example test" produkte'], "queries preserve quoted names: %r" % q[0])
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
check(b["pages_per_step"] == 10 and b["max_minutes"] == 30 and b["queries_per_step"] == 4 and b["results_per_query"] == 10 and b["browser_pages_per_step"] == 2, "budget bounds: %r" % b)



# Real-world failure classes reproduced with synthetic organizations and names.
quality = dr.quality
case = "Recherchiere Mira Solberg und Aurora Unit Studios"
good = [
    {"url": "https://www.aurora-unit.example/", "title": "Aurora Unit Studios | Mira Solberg", "content": "Bücher und Apps"},
    {"url": "https://books.aurora-unit.example/author", "title": "Mira Solberg | Autorin", "content": "Aurora Unit"},
]
noise = [
    {"url": "https://news.example/music", "title": "Aurora Express Musical", "content": "Musiktheater"},
    {"url": "https://science.example/prof", "title": "Johannes Solberg, Professor", "content": "Datenbanken"},
    {"url": "https://store.example/audio", "title": "Aurora 8 Audiokabel", "content": "Audio"},
]
check(quality.identity_phrases(case) == ["Mira Solberg", "Aurora Unit Studios"], "two named entities")
check(quality.rank_results(noise + good, case)[:2] == good, "exact-name hits outrank weak discovery candidates")
check(len(quality.rank_results(noise + good, case)) == 5, "discovery does not reject candidates before reading pages")
check(quality.relevance(noise[0], case) is None, "a near-match is not an identity match")
forced = quality.enforce_queries(["Johannes Solberg Professor", "Aurora Express Musical"],
                                 case, "Suche Autoren und Studios", 3)
check(forced[0].startswith('"Mira Solberg"') and all(
    any(quality.contains_phrase(q, name) for name in quality.identity_phrases(case))
    for q in forced), "all search queries retain a subject anchor")
check(not any("Johannes" in q or "Express" in q for q in forced),
      "planner drift to a different person or product must not survive query repair")
first_step = quality.enforce_queries([], "Finde Details zu Mira Solberg", "Suche nach Romanen", 3)[0]
other_step = quality.enforce_queries([], "Finde Details zu Mira Solberg", "Analysiere Software", 3)[0]
check(first_step != other_step and "romanen" in first_step and "software" in other_step,
      "exact-name discovery queries must vary across research steps")
check(not quality.fetched_page_is_relevant(case, noise[0]["url"], noise[0]["title"],
                                           "Andere Shows und Veranstaltungen sind hier aufgelistet. " * 3),
      "an unrelated readable page is still not evidence")
check(quality.fetched_page_is_relevant(case, good[0]["url"], good[0]["title"],
                                      "Mira Solberg gruendete Aurora Unit Studios und publiziert Romane. " * 3),
      "relevant full text accepted")
check(quality.supported_notes("- Buch [1, 2]\n- Produkt [7]\n- ohne Beleg", {1, 2}) == "- Buch [1, 2]",
      "only factual notes with valid source numbers survive")
# Regression: a real answer page may hide the exact subject below a generic search snippet.
summary_only = {"url": "https://profiles.example/about", "title": "About the creative studio",
                "content": "Read the complete biography on this page."}
check(summary_only in quality.rank_results([summary_only], "Mira Solberg"),
      "a generic snippet must not prevent inspecting the full page")
long_page = ("Overview of the creative workshop and project updates. " * 500
             + "Mira Solberg founded the studio and wrote the novel series. " * 3)
check(quality.fetched_page_is_relevant("Mira Solberg", summary_only["url"], "About the creative studio", long_page),
      "a verified subject mention after the introduction makes a real source")
excerpt = quality.focused_excerpt("Mira Solberg", long_page, 900)
check("Mira Solberg" in excerpt and len(excerpt) < 950,
      "the local Core reads the relevant part rather than the long page's introduction")
check(not quality.fetched_page_is_relevant("Mira Solberg", summary_only["url"],
                                          "", "Unrelated news about a different person and local events. " * 8),
      "a search snippet cannot verify an unrelated fetched page")

prompt_sys, prompt_user = dr.report_prompts("Thema", "Titel", ["Eins", "Zwei"],
                                          {0: "- A [1]" * 120, 1: "- B [2]" * 120},
                                          [{"n": 1, "title": "A", "domain": "a.example", "url": "https://a.example"},
                                           {"n": 2, "title": "B", "domain": "b.example", "url": "https://b.example"}], 1400)
check("QUELLENREGISTER" in prompt_user and "[2] B" in prompt_user and "### Zwei" in prompt_user,
      "source register and later steps survive context allocation")


# Visual browser checks: CAPTCHA detection, bounded action and screenshot response.
import coreai_research_browser as browser_research

check(browser_research.is_challenge("Please verify you are human before continuing"),
      "anti-bot challenge detected")
check(browser_research.is_challenge("More news", ("https://www.google.com/recaptcha/api2/anchor",)),
      "CAPTCHA frame detected")
check(not browser_research.is_challenge("A regular biography and publication page."),
      "ordinary public pages remain readable")
action, observation = browser_research.visual_action(
    '{"observation":"Visible studio details", "action":"type","ref":2,'
    '"text":"Mira Solberg","submit":true}')
check(action == {"action": "type", "ref": 2, "text": "Mira Solberg", "submit": True}
      and observation == "Visible studio details", "vision JSON is bounded")
check(browser_research.visual_action('{"action":"goto","url":"http://127.0.0.1/admin"}')[0] == {},
      "vision cannot navigate to arbitrary URLs")
check(browser_research.visual_action('{"action":"click","ref":999}')[0] == {},
      "vision cannot choose arbitrary missing elements")

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
check(kinds[0] == "step" and kinds.count("step") == 2 and kinds.count("search") >= 3 and "note" in kinds, "events: %r" % kinds)
check(kinds.count("search") == io.progresses[-1][3], "reported count includes unattended source-recovery searches")
check(io.log[1] == ("search", "site:example.test angebote", ["example.test", "blocked.test"]), "a search names its domains: %r" % (io.log[1],))
check([s["n"] for s in io.sources] == [1] and len(io.sources) == 1, "only genuinely read, relevant pages become sources: %r" % io.sources)
check(not any(s["domain"] == "blocked.test" for s in io.sources), "failed fetches and snippets are not evidence")
check(io.writing and io.finished[0] == "done" and "[42]" not in io.finished[1] and io.finished[2] == [1], "report: %r" % (io.finished[:1] + io.finished[2:],))
percents = [p for _, _, p, _ in io.progresses]
check(percents == sorted(percents) and percents[-1] == 100, "progress only grows and ends at 100: %r" % percents)
check(io.progresses[-1][0] == ["done", "done", "done"] and io.progresses[-1][3] >= 3, "all steps done, additional evidence searches allowed")
active = [s for s, phase, _, _ in io.progresses if phase == "write"][0]
check(active == ["done", "done", "active"], "the report step is active while writing: %r" % active)
check([c[0] for c in io.core_calls] == [True, False, False], "one query call, only sourced notes, one report call")

io = FakeIO(cancel_after=3)
check(dr.run_deep_research(run, io, dr.budget_from({})) == "cancelled" and io.finished[0] == "cancelled", "Stopp ends the run")
io = FakeIO(core_error=True)
check(dr.run_deep_research(run, io, dr.budget_from({})) == "error" and io.finished[3] == "core down", "a Core error ends the run honestly")

ticks = iter([0, 0, 10_000, 10_000, 10_000, 10_000, 10_000, 10_000])
io = FakeIO()
dr.run_deep_research(run, io, dr.budget_from({"STU_DEEP_RESEARCH_MAX_MINUTES": 3}), clock=lambda: next(ticks, 10_000))
check(any(k == "info" and "Zeitrahmen" in t for k, t, _ in io.log) and io.finished[0] == "done", "time budget: the report still comes")



# A complete run with a generic snippet but an exact match inside the read page.
class RelevantBodyIO(FakeIO):
    def search(self, query):
        return [{"url": "https://studio.example/biography",
                 "title": "Studio biography and projects",
                 "content": "Creative work, publications and software projects."}]

    def fetch(self, url):
        return "Studio biography", (
            "Overview of the workshop and current developments. " * 300
            + "Mira Solberg writes speculative fiction and builds the studio software. " * 5
        )

realistic = RelevantBodyIO()
research_case = {"query": "Mira Solberg", "title": "Profil Mira Solberg", "num_ctx": 4096,
                 "steps": ["Finde offizielle Informationen.", "Fasse die Ergebnisse in einem Bericht zusammen."]}
check(dr.run_deep_research(research_case, realistic, dr.budget_from({})) == "done",
      "subject run finishes even with a generic search result")
check(len(realistic.sources) == 1 and realistic.sources[0]["domain"] == "studio.example",
      "source is created only after verifying the fetched page")
check(any("Mira Solberg" in event[1] for event in realistic.log if event[0] == "note") or
      realistic.finished[2] == [1], "the run can cite the genuinely matching source")


# Deep Research must fall back to a Playwright-verified page when HTTP sees a JS shell.
class BrowserFallbackIO(RelevantBodyIO):
    def __init__(self):
        super().__init__()
        self.visual_calls = []

    def fetch(self, url):
        return "Loading...", "JavaScript is needed to show the actual profile. " * 3

    def fetch_visual(self, url, query, step):
        self.visual_calls.append((url, query, step))
        return ("Mira Solberg | Profile", "Mira Solberg writes fiction and builds software. " * 7,
                "https://studio.example/biography")

visual_io = BrowserFallbackIO()
check(dr.run_deep_research(research_case, visual_io, dr.budget_from({})) == "done",
      "Playwright fallback finishes")
check(len(visual_io.visual_calls) == 1 and len(visual_io.sources) == 1,
      "one rendered page verified and cited, not a generic JavaScript snippet")
check(visual_io.sources[0]["url"] == "https://studio.example/biography",
      "final resolved URL is cited")
check(any(k == "info" and "Playwright" in t for k, t, _ in visual_io.log),
      "the browser step is visible in research activity")



# Blocked pages do not pause a background run or require an opened chat.
# Mara must search for independent accessible sources and finish the report.
class ChallengeRecoveryIO(FakeIO):
    def __init__(self):
        super().__init__()
        self.queries = []
        self.browser_attempts = 0

    def search(self, query):
        self.queries.append(query)
        if "-site:guarded.example" in query:
            return [{"url": "https://public.example/profile",
                     "title": "Mira Solberg author profile",
                     "content": "Books, studio and software projects."}]
        return [{"url": "https://guarded.example/profile",
                 "title": "Mira Solberg author profile",
                 "content": "An article behind a CAPTCHA."}]

    def fetch(self, url):
        if "guarded.example" in url:
            raise RuntimeError("HTTP 403: access denied")
        return "Mira Solberg publications", (
            "Mira Solberg wrote books and maintains a software studio. " * 5)

    def fetch_visual(self, url, query, step):
        self.browser_attempts += 1
        raise RuntimeError("CAPTCHA: verification required")

recovery = ChallengeRecoveryIO()
result = dr.run_deep_research(research_case, recovery, dr.budget_from({}))
check(result == "done" and recovery.finished[0] == "done",
      "Deep Research finishes autonomously despite an inaccessible site")
check(recovery.browser_attempts == 1 and
      any("-site:guarded.example" in query for query in recovery.queries),
      "challenge triggers an alternative search avoiding the protected website")
check(len(recovery.sources) == 1 and recovery.sources[0]["url"] ==
      "https://public.example/profile", "only accessible verified evidence is cited")
check(not any("warte auf" in msg.lower() for _, msg, _ in recovery.log),
      "no interactive handoff or foreground waiting")
check(any("Zugangssperre" in msg for _, msg, _ in recovery.log),
      "the blocked source is visible in the activity log")

alternative = quality.alternative_queries(
    "Mira Solberg", "Suche Biografie und Veröffentlichungen",
    ["guarded.example"], limit=2)
check(alternative and all("-site:guarded.example" in q for q in alternative),
      "autonomous recovery excludes the access-gated domain")

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

payload = w.ollama_payload({"STU_EMBER_MODEL": "mara-vision", "STU_EMBER_KEEP_ALIVE": ""},
                            "Vision", "Read this page.", False, images=["jpeg_b64"])
check(payload["messages"][-1]["images"] == ["jpeg_b64"] and payload["model"] == "mara-vision",
      "browser screenshot reaches the same native multimodal Core")
plain = w.ollama_payload({"STU_EMBER_MODEL": "mara-vision", "STU_EMBER_KEEP_ALIVE": ""},
                          "Prompt", "Ordinary call", False)
check("images" not in plain["messages"][-1], "normal text calls remain unchanged")
check(w.DEFAULTS.get("STU_DEEP_RESEARCH_VISUAL_ENABLED") == "1",
      "visual browser fallback defaults on but remains configurable")


# Deterministic visual browser session, without Chromium or network.
class DemoPage:
    def __init__(self, captcha=False):
        self.url = "https://studio.example/"
        self.text = ("Verify you are human" if captcha else "Search this studio and its public projects.")
        self.frames, self.closed = [], False

    def goto(self, url, **kwargs):
        self.url = url

    def wait_for_timeout(self, milliseconds):
        pass

    def locator(self, selector):
        return types.SimpleNamespace(count=lambda: 0)

    def screenshot(self, **kwargs):
        return b"JPEG_VISUAL_TEST_FRAME"

    def title(self):
        return "Studio profile"

    def close(self):
        self.closed = True


class DemoContext:
    def __init__(self, captcha=False):
        self.page = DemoPage(captcha)
        self.closed = False

    def new_page(self):
        return self.page

    def close(self):
        self.closed = True


class DemoBrowser:
    def __init__(self, captcha=False):
        self.context = DemoContext(captcha)
        self.closed = False

    def new_context(self, **kwargs):
        return self.context

    def close(self):
        self.closed = True


class DemoPlaywright:
    def __init__(self, captcha=False):
        self.browser = DemoBrowser(captcha)
        self.chromium = types.SimpleNamespace(launch=lambda **kwargs: self.browser)

    def stop(self):
        pass


class DemoProvider:
    def __init__(self, captcha=False):
        self.pw = DemoPlaywright(captcha)

    def start(self):
        return self.pw


def demo_api(provider):
    def demo_action(page, act, env):
        check(act["action"] == "type" and act["text"] == "Mira Solberg", "safe search action")
        page.text = "Mira Solberg writes speculative fiction and develops software. " * 5
        page.url = "https://studio.example/search?q=Mira"
        return "type", False, None

    return types.SimpleNamespace(
        sync_playwright=lambda: provider,
        install_network_guard=lambda *args: None,
        navigation_block_reason=lambda *args: None,
        BrowseBlocked=w.BrowseBlocked, deep_research=dr,
        dismiss_consent=lambda *args: False,
        page_full_text=lambda page: page.text,
        collect_elements=lambda page: [{"ref": 1, "name": "Search", "role": "searchbox"}],
        render_elements=lambda els: "[1] Suchfeld Search",
        element_for=lambda page, act, env: (object(), {"name": "Search"}),
        inspect_element=lambda loc: {"protected": False, "form": "search"},
        blocked_type_reason=w.blocked_type_reason,
        blocked_click_reason=w.blocked_click_reason,
        cfg_int=w.cfg_int,
        do_action=demo_action,
    )


vision_calls = []
def demo_vision(system, user, screenshot):
    vision_calls.append((user, screenshot))
    if len(vision_calls) == 1:
        return json.dumps({"observation": "Studio search field", "action": "type", "ref": 1,
                           "text": "Mira Solberg", "submit": True})
    return json.dumps({"observation": "Mira Solberg writes books and develops software.",
                       "action": "none"})

provider = DemoProvider()
session = browser_research.ResearchBrowserSession(
    {"STU_DEEP_RESEARCH_BROWSER_ACTIONS": "2"}, demo_api(provider), vision=demo_vision)
try:
    title, body, final_url = session.read("https://studio.example/", "Mira Solberg", "Recherchiere Bücher")
finally:
    session.close()
check(final_url.startswith("https://studio.example/search?") and "Mira Solberg" in body,
      "visual search changes the real page and captures verified content")
check(len(vision_calls) == 2 and all(image for _, image in vision_calls),
      "the active Core sees a fresh screenshot per allowed UI step")
check(provider.pw.browser.closed and provider.pw.browser.context.closed,
      "browser session closes resources after research")

captcha_provider = DemoProvider(captcha=True)
captcha_calls = []
captcha_session = browser_research.ResearchBrowserSession(
    {}, demo_api(captcha_provider), vision=lambda *args: captcha_calls.append(args))
try:
    try:
        captcha_session.read("https://studio.example/", "Mira Solberg", "Read biography")
        check(False, "CAPTCHA must stop browsing")
    except browser_research.ResearchChallenge:
        pass
finally:
    captcha_session.close()
check(captcha_calls == [], "CAPTCHA is detected before image-guided automation")

check(callable(w.claim_next_research) and callable(w.process_research), "the worker claims and runs research")

print("CoreAI deep research worker: page text, queries, notes, report citations, page choice, budget, a whole run, "
      "Stopp, Core error, time budget, page fetch with the local-address guard OK")
