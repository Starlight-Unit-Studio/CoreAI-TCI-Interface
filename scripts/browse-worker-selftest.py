#!/usr/bin/env python3
"""CoreAI 0.5.77: Maras Browser haerten, Formulare, Live-Ansicht ohne Einfrieren.

Teil 1 (immer, ohne Server): Aktions-Parser, Elementliste, Textfenster, Sicherheitsregeln
(geschuetzte Felder, Kaeufe, Logins, Nachrichten, persoenliche Daten, lokales Netz),
parameterfreier Ollama-Request mit dem Core des Nutzers, Report als Mara, Herzschlag
waehrend der Core rechnet.

Teil 2 (wenn Playwright installiert ist): ein echter Lauf in Chromium mit nachgebautem
Core gegen lokale Testseiten. Mara fuellt ein Suchformular aus (tippen, auswaehlen,
anhaken, abschicken), der Cursor zeigt vor jeder Aktion aufs Ziel, waehrend der Core
rechnet kommen Status und frische Bilder, Login- und Kontaktformulare werden blockiert,
lokale Adressen sind gesperrt.
"""

import http.server
import importlib.util
import json
import os
import sys
import threading
import time
import types
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / "tools" / "ember_browse_worker.py"


def fail(why):
    print(f"Browse worker FAIL: {why}", file=sys.stderr)
    sys.exit(1)


def check(ok, why):
    if not ok:
        fail(why)


def have(module):
    try:
        return importlib.util.find_spec(module) is not None
    except (ImportError, ValueError):
        return False


# COREAI_BROWSE_LIVE=1: echter Lauf Pflicht (Browse-Container, Worker-venv); =0: nur Regeln.
LIVE_MODE = os.environ.get("COREAI_BROWSE_LIVE", "").strip()
LIVE = have("playwright") and have("requests") and have("pymysql")
if LIVE_MODE == "1" and not LIVE:
    fail("COREAI_BROWSE_LIVE=1 but Playwright, requests or PyMySQL is missing")
if LIVE_MODE == "0":
    LIVE = False
sys.dont_write_bytecode = True

if not LIVE:
    # Der Worker braucht seine Abhaengigkeiten erst zur Laufzeit; fuer die reinen Teile genuegen Platzhalter.
    if not have("pymysql"):
        sys.modules["pymysql"] = types.ModuleType("pymysql")
    if not have("requests"):
        req = types.ModuleType("requests")
        exc = types.SimpleNamespace(
            ReadTimeout=type("ReadTimeout", (Exception,), {}),
            ConnectTimeout=type("ConnectTimeout", (Exception,), {}),
            ConnectionError=type("ConnectionError", (Exception,), {}),
        )
        req.exceptions = exc
        sys.modules["requests"] = req
    if not have("playwright"):
        pw = types.ModuleType("playwright")
        sync = types.ModuleType("playwright.sync_api")
        sync.sync_playwright = None
        sync.TimeoutError = type("TimeoutError", (Exception,), {})
        sys.modules["playwright"] = pw
        sys.modules["playwright.sync_api"] = sync

spec = importlib.util.spec_from_file_location("ember_browse_worker", WORKER)
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)

# ----------------------------------------------------------------------------- Teil 1

# Aktionen des Cores lesen.
check(w.parse_action('{"action":"click","ref":"12"}') == {"action": "click", "ref": 12}, "ref as text")
check(w.parse_action('Klar: {"aktion":"TYPE","nummer":"[5]","text":"Bonn","submit":true} fertig')
      == {"action": "type", "ref": 5, "text": "Bonn", "submit": True}, "free text around JSON, German keys")
check(w.parse_action('[{"action":"done","summary":"x"}]')["action"] == "done", "list answer")
check(w.parse_action("kein json") is None and w.parse_action('"text"') is None, "garbage")

# Elementliste fuer den Core.
rows = w.render_elements([
    {"ref": 1, "role": "searchbox", "name": "Suche", "type": "search", "value": "abc", "in_view": True},
    {"ref": 2, "role": "textbox", "name": "Passwort", "type": "password", "protected": True, "in_view": True},
    {"ref": 3, "role": "combobox", "name": "Land", "options": ["Deutschland", "Österreich"], "value": "Deutschland", "in_view": True},
    {"ref": 4, "role": "checkbox", "name": "Nur verfügbar", "checked": False, "in_view": False},
    {"ref": 5, "role": "textbox", "name": "Datum", "type": "date", "required": True, "in_view": True},
])
check('[1] Suchfeld "Suche" (Wert "abc")' in rows, "search field row: " + rows)
check('[2] Textfeld "Passwort" (geschuetzt, Typ password)' in rows, "protected field")
check('Optionen: Deutschland | Österreich, Wert "Deutschland"' in rows, "select options")
check('[4] Haken "Nur verfügbar" (nicht angehakt, weiter unten)' in rows, "checkbox below")
check('(Typ date, Pflicht)' in rows, "date, required")
check(w.render_elements([]) == "(keine)", "empty list")

# Textfenster: Scrollen liest weiter.
text = "".join(f"Zeile {i}. " for i in range(600))
chunk, off, total = w.text_window(text, 0)
check(off == 0 and total == len(text) and len(chunk) == w.TEXT_WINDOW + 2 and chunk.endswith(" …"), "first window")
chunk2, off2, _ = w.text_window(text, w.TEXT_STEP)
check(off2 == w.TEXT_STEP and chunk2.startswith(text[w.TEXT_STEP:w.TEXT_STEP + 20]), "scrolled window")
check(w.text_window(text, 10 ** 9)[1] == len(text) - 1 and w.text_window("", 50) == ("", 0, 0), "window bounds")
prompt = w.action_user_prompt("Ziel X", "(keine Suchtreffer)", "https://a.example/", "T", [], chunk2, off2, total, ["step1: goto"], 12, 12)
check(f"Zeichen {w.TEXT_STEP + 1} bis {w.TEXT_STEP + w.TEXT_WINDOW} von {total}; mit scroll weiterlesen" in prompt, "window position in prompt")
check("LETZTER Schritt" in prompt and "SCHRITT 12 von 12" in prompt, "last step tells the Core")
check(w.split_chunks("Nebelfalke", 3) == ["Nebe", "lfal", "ke"] and w.split_chunks("abc") == ["abc"] and w.split_chunks("") == [],
      "typing in visible pieces")

# Sicherheitsregeln.
check(w.blocked_click_reason("Jetzt kaufen") and w.blocked_click_reason("In den Warenkorb")
      and w.blocked_click_reason("Zahlungspflichtig bestellen") and w.blocked_click_reason("Anmelden")
      and w.blocked_click_reason("Login / Registrieren") and w.blocked_click_reason("Nachricht senden")
      and w.blocked_click_reason("Konto löschen"), "purchase, login, send and delete buttons are blocked")
check(w.blocked_click_reason("Suchen") is None and w.blocked_click_reason("Sendung verfolgen") is None
      and w.blocked_click_reason("Krankenkasse vergleichen") is None and w.blocked_click_reason("PayPal Hilfe") is None
      and w.blocked_click_reason("Weiter") is None and w.blocked_click_reason("Alle akzeptieren") is None,
      "ordinary buttons stay clickable")
check(w.blocked_click_reason("Abschicken", {"submit": True, "form": "message"})
      and w.blocked_click_reason("Los", {"submit": True, "form": "message"})
      and w.blocked_click_reason("Los", {"submit": True, "form": "search"}) is None, "submit buttons follow the form kind")
check(w.blocked_type_reason({"protected": True}, "x", "") and w.blocked_type_reason({"form": "login"}, "max", "")
      and w.blocked_type_reason({"form": "payment"}, "1", "") and w.blocked_type_reason({"form": "upload"}, "1", ""),
      "passwords, logins, payments, uploads")
check(w.blocked_type_reason({"form": "search"}, "Fahrplan Köln Bonn", "Zug von Köln nach Bonn") is None, "search typing allowed")
check(w.blocked_type_reason({"form": "message"}, "Hallo", "") is None
      and w.blocked_type_reason({"form": "message"}, "Hallo", "", submit=True), "message forms: typing yes, sending no")
check(w.blocked_type_reason({"form": "free"}, "mara@example.org", "Suche nach etwas")
      and w.blocked_type_reason({"form": "free"}, "mara@example.org", "Trag mara@example.org ein") is None,
      "e-mail only when it is in the task")
check(w.blocked_type_reason({"form": "free"}, "+49 170 1234567", "x") and w.blocked_type_reason({"form": "free"}, "DE89 3704 0044 0532 0130 00", "x")
      and w.blocked_type_reason({"form": "free"}, "PLZ 41061", "x") is None, "phone and IBAN need the task, short numbers do not")
check(w.blocked_type_reason({"form": "free"}, "x" * 501, "") is not None, "length cap")

# Lokales Netz.
check(all(w.host_is_private(h) for h in ("127.0.0.1", "localhost", "10.1.2.3", "192.168.0.10", "169.254.1.1", "::1",
                                          "::ffff:127.0.0.1", "nas.local", "db.internal", "")), "private hosts")
check(not w.host_is_private("93.184.216.34") and not w.host_is_private("example.org"), "public hosts")
fake_dns = {"rebind.example": "127.0.0.1", "ok.example": "93.184.216.34"}


def resolver(host, port):
    if host not in fake_dns:
        raise OSError("unknown")
    return [(2, 1, 6, "", (fake_dns[host], 0))]


cfg = dict(w.DEFAULTS)
check(w.navigation_block_reason("https://rebind.example/x", cfg, resolver=resolver), "name pointing home is blocked")
check(w.navigation_block_reason("https://ok.example/x", cfg, resolver=resolver) is None, "public name is fine")
check(w.navigation_block_reason("http://127.0.0.1:11434/api/tags", cfg) and w.navigation_block_reason("file:///etc/passwd", cfg)
      and w.navigation_block_reason("javascript:alert(1)", cfg), "Ollama, files, scripts")
check(w.navigation_block_reason("http://127.0.0.1:8080/", dict(cfg, STU_EMBER_BROWSE_ALLOW_PRIVATE="1")) is None, "operator may allow")

# Ollama-Request: derselbe Core wie im Chat, parameterfrei, keep_alive wie chat.php.
job = {"model": "gemma4:12b", "num_ctx": 16384}
jcfg = w.config_for_job(dict(cfg, STU_EMBER_KEEP_ALIVE="15m"), job)
payload = w.ollama_payload(jcfg, "SYS", "USER", want_json=False, think=False)
check(payload["model"] == "gemma4:12b" and payload["options"] == {"num_ctx": 16384}, "user's Core with its window only")
check(payload["keep_alive"] == "15m" and payload["think"] is False and "temperature" not in json.dumps(payload)
      and "num_predict" not in json.dumps(payload), "no Gemma tuning")
check(w.ollama_payload(w.config_for_job(dict(cfg, STU_EMBER_KEEP_ALIVE="-1"), {}), "", "U", False)["keep_alive"] == -1, "-1 as number")
plain = w.ollama_payload(w.config_for_job(dict(cfg, STU_EMBER_KEEP_ALIVE=""), {"model": "bad model; rm"}), "", "U", False)
check("keep_alive" not in plain and "options" not in plain and plain["model"] == "ember-coreui:latest"
      and plain["messages"] == [{"role": "user", "content": "U"}], "empty keep_alive, invalid model name, no system")
check(w.keep_alive_value({"STU_EMBER_KEEP_ALIVE": "1h30m"}) == "1h30m" and w.keep_alive_value({"STU_EMBER_KEEP_ALIVE": "bald"}) is None,
      "durations")
check(w.job_num_ctx({"num_ctx": 100}) is None and w.job_num_ctx({"num_ctx": "8192"}) == 8192, "num_ctx bounds")
check(w.character_name({"STU_EMBER_CHARACTER_NAME": "Ember"}) == "Mara" and w.character_name({}) == "Mara"
      and w.character_name({"STU_EMBER_CHARACTER_NAME": "Nova"}) == "Nova", "public name")
check(w.DEFAULTS["STU_EMBER_CHARACTER_NAME"] == "Mara", "default name")

# Prompts.
with_forms, without = w.action_system(True), w.action_system(False)
check('"action":"type"' in with_forms and '"action":"select"' in with_forms and '"action":"check"' in with_forms
      and '"action":"type"' not in without and "Formulare fuellst du nicht aus" in without, "form actions only when enabled")
check("UNGEPRUEFTE DATEN" in with_forms and "geschuetzt" in with_forms and "Passwoerter" in with_forms, "rules in the prompt")
report = w.report_prompt("Preis Nebelfalke", "42 Euro", ["https://shop.example/x"], "Mara", True)
check("als Mara" in report and "- https://shop.example/x" in report and "Markdown" in report and "Ember" not in report, "report as Mara")
check("Kein Markdown" in w.report_prompt("x", "y", [], "Mara", False), "global channel without Markdown")
check(w.clean_report("I am Mara and I have to report") == "", "thinking protocol dropped")
# 0.5.79: Maras Modelfile erlaubt Werkzeugmarker; der Bericht sagt ausdruecklich, dass sie aus sind,
# und eine Antwort, die nur aus Markern besteht, wird nie zur leeren Chat-Blase.
check("KEINE Werkzeuge" in report and "mindestens zwei" in report, "report: tools off, several sources")
check(w.clean_report("[BROWSE: Preise nachsehen]", console=True) == "" and w.clean_report("[WEB: x] [PY]1[/PY]") == "",
      "a marker-only report counts as empty")
check(w.clean_report("- **42 Euro** laut [Spieleladen](https://a.example)", console=True).startswith("- **42 Euro**"),
      "Markdown stays in the private chat")
fb = w.fallback_report("(kein klarer Befund)", [{"text": "42 Euro", "host": "a.example", "url": "https://a.example/x"}],
                       ["https://a.example/x", "https://b.example/y"], True)
check("42 Euro (a.example)" in fb and "Quellen: https://a.example/x, https://b.example/y" in fb, "fallback with notes and sources")
check(w.fallback_report("", [], [], True).startswith("Ich habe nachgeschaut"), "fallback is never empty")
notes_prompt = w.action_user_prompt("Z", "(keine)", "https://a.example/", "T", [], "x", 0, 1, [], 2, 12,
                                    notes=[{"text": "42 Euro", "host": "a.example", "title": "Laden", "url": "https://a.example/"}],
                                    visited_hosts=["a.example"], min_sources=2)
check("- [Laden] 42 Euro (https://a.example/)" in notes_prompt and "GEPRUEFTE WEBSEITEN: 1 (a.example); pruefe mindestens 2" in notes_prompt,
      "notes and checked websites in every prompt")
check('"action":"search"' in w.ACTION_SYS and '"notiz"' in w.ACTION_SYS and "nie auf eine einzige Quelle" in w.ACTION_SYS,
      "prompt: search, notes, several sources")
check(w.reading_path([{"x": 10, "y": 50, "w": 400}]) == [(10.0, 50.0), (210.0, 50.0), (378.0, 50.0)], "reading path along a line")
check(w.same_url("https://a.example/x/", "https://a.example/x#top") and not w.same_url("", ""), "same address")

# Suche wie in chat.php: Stichworte als zweiter Versuch, keine Doppelten, sauberer Text.
check(w.web_query_variants("Wie ist das Wetter morgen in Mönchengladbach?")
      == ["Wie ist das Wetter morgen in Mönchengladbach?", "Wetter morgen Mönchengladbach"], "key words")
check(w.web_query_variants("gemma4 12b") == ["gemma4 12b"] and w.web_query_variants(" ") == [], "plain query")
check(w.web_url_key("https://www.Example.org/a/?utm_source=x&b=2&a=1#top") == "example.org/a?a=1&b=2", "url key like PHP")
cleaned = w.clean_results([
    {"title": "Gemma &amp; Co", "url": "https://ollama.com/library/gemma4?utm_source=searx", "content": "<b>12b</b>  braucht&nbsp;8 GB"},
    {"title": "Doppelt", "url": "https://www.ollama.com/library/gemma4/", "content": "gleiches Ziel"},
    {"title": "Skript", "url": "javascript:alert(1)", "content": "x"},
    "kaputt",
], 5)
check(cleaned == [{"title": "Gemma & Co", "url": "https://ollama.com/library/gemma4?utm_source=searx", "content": "12b braucht 8 GB"}],
      f"clean results: {cleaned}")

# Herzschlag: der Core rechnet in einem Hilfsthread, der Hauptthread meldet sich weiter.
ticks = []
value = w.call_with_heartbeat(lambda: (time.sleep(0.35), "fertig")[1], ticks.append, interval=0.1)
check(value == "fertig" and len(ticks) >= 2 and ticks == sorted(ticks), "ticks while the Core works")
try:
    w.call_with_heartbeat(lambda: (_ for _ in ()).throw(ValueError("kaputt")), lambda e: None, interval=0.05)
    fail("errors must surface")
except ValueError as exc:
    check(str(exc) == "kaputt", "error from the worker thread")
check(w.call_with_heartbeat(lambda: 7, lambda e: (_ for _ in ()).throw(RuntimeError("tick")), interval=0.01) == 7,
      "a broken status never stops the job")

# Verdrahtung.
src = WORKER.read_text(encoding="utf-8")
check("page.accessibility" not in src, "no removed Playwright API")
check('"options": {"temperature"' not in src and "num_predict=" not in src, "no tuning left in the worker")
check("run_browse(job_cfg, job, log, push_frame=jframe, say=say)" in src and "kind VARCHAR(16)" in src, "job Core and step kinds")
chat = (ROOT / "api" / "chat.php").read_text(encoding="utf-8")
check("ADD COLUMN IF NOT EXISTS model VARCHAR(160) NULL" in chat and "$numCtx = ember_profile_num_ctx($model);" in chat
      and "max_steps, model, num_ctx)" in chat, "chat stores the Core with the job")
stream = (ROOT / "api" / "console_browse_stream.php").read_text(encoding="utf-8")
check("bsse_resume_id($_GET['after_frame'] ?? '')" in stream and "'kind' => bsse_step_kind(" in stream
      and "bsse_send('status', ['status' => $status]);" in stream and "'after_frame' => $lastFrameId" in stream,
      "stream resumes and reports kinds and queue status")
app = (ROOT / "js" / "console-app.js").read_text(encoding="utf-8")
check("es.addEventListener('timeout', function (ev) {" in app and "reconnect(200, true);" in app
      and "if (kind === 'think') {" in app and "browse-live-think" in app and "showQueued();" in app,
      "client reconnects, shows thinking and the queue")
css = (ROOT / "css" / "console.css").read_text(encoding="utf-8")
check(".browse-live.thinking:not(.reading) .browse-ghost-cursor {" in css and "@keyframes browse-cursor-read" in css
      and "left 520ms" in css, "cursor glides and reads")
console_stream = (ROOT / "api" / "console_stream.php").read_text(encoding="utf-8")
# 0.5.79: kein AFK mehr (Reste des Game-Chats): keine Systemmeldung unter dem Browser, kein
# AFK-Status fuer Mara, keine /afk-Befehle.
check("nicht an der Tastatur" not in src and "ember_browse_afk" not in src and "set_browse_afk" not in src,
      "the worker never posts an AFK message")
check(not any(x in chat + console_stream for x in ("ember_console_afk", "ember_browse_afk_active", "ember_gate_self_afk",
                                                   "chat_apply_afk_state", "nicht an der Tastatur"))
      and "|afk|back)" not in chat and "'error'=>'afk_removed'" in chat, "no AFK anywhere in the chat")
check("$emberMsgId > 0 && $browseJobId <= 0 && !ember_agent_cancel_requested()" in console_stream,
      "a browse job goes before the compaction")

print("CoreAI browse worker (rules): parser, elements, text window, safety, Core, prompts, heartbeat OK")

# ----------------------------------------------------------------------------- Teil 2

if not LIVE:
    print("CoreAI browse worker (live): skipped here (no Playwright or COREAI_BROWSE_LIVE=0)")
    sys.exit(0)

PAGES = {
    "/shop": """<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Spieleladen</title></head>
<body><header><a href="/login">Anmelden</a> <a href="/kontakt">Kontakt</a></header>
<main><h1>Spieleladen Suche</h1>
<form action="/treffer" method="get" role="search">
 <label for="q">Suchbegriff</label> <input id="q" name="q" type="search">
 <label for="kat">Kategorie</label> <select id="kat" name="kat"><option value="alle">Alle</option>
   <option value="spiele">Brettspiele</option><option value="buch">Bücher</option></select>
 <label><input type="checkbox" name="lager" value="1"> Nur auf Lager</label>
 <button type="submit">Suchen</button>
</form>
<p id="late">Angebote werden geladen …</p>
<script>setTimeout(function(){document.getElementById('late').textContent='Heute 10 Prozent auf alles.';document.body.style.background='#eef';},1200);</script>
</main></body></html>""",
    "/login": """<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Login</title></head><body><main>
<form action="/eingeloggt" method="post"><label for="u">E-Mail</label><input id="u" name="u" type="email">
<label for="p">Passwort</label><input id="p" name="p" type="password"><button type="submit">Einloggen</button></form>
</main></body></html>""",
    "/kontakt": """<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Kontakt</title></head><body><main>
<form action="/gesendet" method="post"><label for="m">Nachricht</label><textarea id="m" name="m"></textarea>
<label for="e">E-Mail</label><input id="e" name="e" type="email"><button type="submit">Weiter</button></form>
</main></body></html>""",
}
HITS = {"posts": 0}


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        path = self.path.split("?", 1)[0]
        if path == "/treffer":
            query = self.path.split("?", 1)[1] if "?" in self.path else ""
            body = (f"<!doctype html><html lang='de'><head><meta charset='utf-8'><title>Treffer</title></head><body><main>"
                    f"<h1>Treffer</h1><p id='query'>{query}</p><p>Nebelfalke, Brettspiel, auf Lager: 42 Euro.</p></main></body></html>")
        else:
            body = PAGES.get(path, "<!doctype html><title>404</title><p>nicht da</p>")
        data = body.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        HITS["posts"] += 1
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"gesendet")


server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
BASE = f"http://127.0.0.1:{server.server_address[1]}"


def ref_of(prompt, label):
    for line in prompt.split("ELEMENTE:\n", 1)[1].split("\nSEITENTEXT", 1)[0].splitlines():
        if f'"{label}"' in line:
            return int(line.split("]", 1)[0].lstrip("["))
    fail(f"element {label!r} missing in prompt:\n{prompt[:1500]}")


def run(script, cfg_over=None, start=None, model_delay=0.0, search=None):
    """Ein Lauf mit nachgebautem Core: script ist eine Liste von Funktionen prompt -> Aktion."""
    prompts, said, frames = [], [], []

    def fake_action(cfg_, user_msg, log=None, system=None):
        prompts.append(user_msg)
        if model_delay:
            time.sleep(model_delay)
        step = script[min(len(prompts), len(script)) - 1]
        return json.dumps(step(user_msg))

    w.browse_action_call = fake_action
    w.searxng_search = search or (lambda cfg_, q, log=None: [{"title": "Spieleladen", "url": BASE + "/shop", "content": "Brettspiele"}])
    run_cfg = dict(w.DEFAULTS, STU_EMBER_BROWSE_ALLOW_PRIVATE="1", STU_EMBER_BROWSE_HEARTBEAT_SEC="2",
                   STU_EMBER_BROWSE_MIN_SOURCES="1",
                   STU_EMBER_BROWSE_SCREENSHOT_DIR=str(Path(os.environ.get("TMPDIR", "/tmp")) / "coreai-browse-selftest"))
    run_cfg.update(cfg_over or {})
    job = {"id": 1, "goal": "Was kostet der Nebelfalke (Brettspiel, auf Lager)?", "max_steps": len(script), "start_url": start}
    result = w.run_browse(run_cfg, job, lambda m: None,
                          push_frame=lambda *a: frames.append({"step": a[0], "image": bool(a[1]), "cx": a[2], "cy": a[3],
                                                               "url": a[6], "label": a[7], "click": a[8]}),
                          say=lambda text, kind="step": said.append((kind, text)))
    return result, prompts, said, frames


# Suchformular: tippen, auswaehlen, anhaken, abschicken, lesen.
script = [
    lambda p: {"action": "goto", "url": BASE + "/shop"},
    lambda p: {"action": "type", "ref": ref_of(p, "Suchbegriff"), "text": "Nebelfalke"},
    lambda p: {"action": "select", "ref": ref_of(p, "Kategorie"), "option": "brettspiele"},
    lambda p: {"action": "check", "ref": ref_of(p, "Nur auf Lager"), "checked": True},
    lambda p: {"action": "click", "ref": ref_of(p, "Suchen")},
    lambda p: {"action": "done", "summary": "Nebelfalke kostet 42 Euro (Spieleladen)."},
]
(summary, steps, shot, sources, notes), prompts, said, frames = run(script, model_delay=2.6)
check(summary == "Nebelfalke kostet 42 Euro (Spieleladen).", f"summary: {summary!r} steps={steps}")
check(any("q=Nebelfalke&kat=spiele&lager=1" in s for s in sources), f"form sent with all fields: {sources}")
check("Nebelfalke, Brettspiel, auf Lager: 42 Euro" in prompts[-1], "the Core reads the result page")
check(prompts[0].count("[1] ") == 1 and "Spieleladen" in prompts[0], "search page as first page with numbered links")
kinds = [k for k, _ in said]
check(kinds.count("think") >= 6 and "warn" not in kinds, f"a thinking status per step, nothing blocked: {said}")
check(("step", "Tippe „Nebelfalke“ in „Suchbegriff“") in said and ("step", "Wähle „Brettspiele“ in „Kategorie“") in said
      and ("step", "Setze den Haken bei „Nur auf Lager“") in said and ("step", "Klicke auf „Suchen“") in said,
      f"human status for every action: {said}")
check(len([f for f in frames if f["click"]]) >= 5, "the cursor points at every field and button before acting")
check(any(f["click"] and f["url"] == "searxng://local/search" for f in frames) and "(Link)" in steps[0],
      f"goto to a search hit: the cursor clicks the hit: {steps[0]}")
reading = [f for f in frames if not f["image"]]
check(len(reading) >= 4 and len({(round(f["cx"]), round(f["cy"])) for f in reading}) >= 3,
      "while the Core thinks, the cursor moves along real text blocks")
check(len([f for f in frames if f["label"] == "Mara tippt"]) == 3, "typing shows up in three pictures")
check(any(f["label"] == "Mara liest die Seite" for f in frames), "fresh picture while the Core thinks (late content)")
check(all(f["url"].startswith(("http", "searxng://")) for f in frames), "frames carry a readable address")

# Login und Kontakt: nichts eingeben, nichts senden.
script = [
    lambda p: {"action": "type", "ref": ref_of(p, "Passwort"), "text": "geheim"},
    lambda p: {"action": "click", "ref": ref_of(p, "Einloggen")},
    lambda p: {"action": "type", "ref": ref_of(p, "E-Mail"), "text": "mara@example.org"},
    lambda p: {"action": "done", "summary": "Login nicht moeglich."},
]
(summary, steps, _, _, _), _, said, _ = run(script, start=BASE + "/login")
check(summary == "Login nicht moeglich." and len([k for k, _ in said if k == "warn"]) == 3
      and sum("BLOCKIERT" in s for s in steps) == 3, f"login form blocked three times: {steps}")
script = [
    lambda p: {"action": "type", "ref": ref_of(p, "Nachricht"), "text": "Hallo", "submit": True},
    lambda p: {"action": "type", "ref": ref_of(p, "Nachricht"), "text": "Hallo"},
    lambda p: {"action": "click", "ref": ref_of(p, "Weiter")},
    lambda p: {"action": "done", "summary": "Kontaktformular nicht gesendet."},
]
(summary, steps, _, _, _), _, said, _ = run(script, start=BASE + "/kontakt")
check(HITS["posts"] == 0, "nothing was ever posted")
check("BLOCKIERT type" in steps[0] and "step2: type" in steps[1] and "BLOCKIERT click" in steps[2],
      f"typing yes, sending no: {steps}")

# Formulare aus: nur lesen.
script = [lambda p: {"action": "type", "ref": ref_of(p, "Suchbegriff"), "text": "x"},
          lambda p: {"action": "done", "summary": "ok"}]
(_, steps, _, _, _), prompts, said, _ = run(script, cfg_over={"STU_EMBER_BROWSE_FORMS": "0"}, start=BASE + "/shop")
check("ausgeschaltet" in steps[0], f"forms switched off: {steps}")

# Lokales Netz gesperrt (Standard), auch als Startseite und per goto.
script = [lambda p: {"action": "goto", "url": BASE + "/shop"}, lambda p: {"action": "done", "summary": "nichts"}]
(_, steps, _, _, _), _, said, _ = run(script, cfg_over={"STU_EMBER_BROWSE_ALLOW_PRIVATE": "0"}, start=BASE + "/login")
check(steps[0].startswith("start-goto blockiert") and "BLOCKIERT goto" in steps[1], f"local addresses blocked: {steps}")

# Mehrere Quellen: ein zu fruehes done wird einmal zurueckgestellt, Mara sucht neu, oeffnet eine
# zweite Webseite (anderer Hostname) und haelt Notizen je Quelle fest.
BASE2 = BASE.replace("127.0.0.1", "localhost")
PAGES["/preise"] = """<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Preisvergleich</title></head>
<body><main><h1>Preisvergleich Nebelfalke</h1><p>Der Nebelfalke kostet bei drei Haendlern zwischen 41 und 43 Euro.</p></main></body></html>"""


def two_searches(cfg_, query, log=None):
    if "Preisvergleich" in query:
        return [{"title": "Preisvergleich", "url": BASE2 + "/preise", "content": "41 bis 43 Euro"}]
    return [{"title": "Spieleladen", "url": BASE + "/shop", "content": "Brettspiele"}]


script = [
    lambda p: {"action": "goto", "url": BASE + "/shop"},
    lambda p: {"action": "done", "summary": "42 Euro laut Spieleladen"},
    lambda p: {"action": "search", "query": "Nebelfalke Preisvergleich"},
    lambda p: {"action": "goto", "url": BASE2 + "/preise"},
    lambda p: {"action": "done", "summary": "Etwa 42 Euro, bei drei Haendlern 41 bis 43 Euro.", "notiz": "Preisvergleich: 41 bis 43 Euro"},
]
(summary, steps, _, sources, notes), prompts, said, frames = run(
    script, cfg_over={"STU_EMBER_BROWSE_MIN_SOURCES": "2"}, search=two_searches)
check("done zurueckgestellt, erst 1 von 2 Webseiten" in steps[1], f"too early done is held back once: {steps}")
check(steps[2].startswith('step3: search "Nebelfalke Preisvergleich" (1 Treffer)') and "(Link)" in steps[3], f"new search, hit clicked: {steps}")
check(summary == "Etwa 42 Euro, bei drei Haendlern 41 bis 43 Euro.", f"second done accepted: {summary!r}")
check([n["host"] for n in notes] == ["127.0.0.1", "localhost"] and notes[0]["text"] == "42 Euro laut Spieleladen",
      f"notes per source: {notes}")
check(any(u.startswith(BASE + "/shop") for u in sources) and any(u.startswith(BASE2 + "/preise") for u in sources), f"both sources: {sources}")
check("- [Spieleladen] 42 Euro laut Spieleladen" in prompts[2] and "GEPRUEFTE WEBSEITEN: 1 (127.0.0.1)" in prompts[2],
      "after the nudge the Core sees its note and the count")
check(("step", "Mara prüft noch eine weitere Quelle") in said and ("step", "Mara notiert: Preisvergleich: 41 bis 43 Euro") in said,
      f"the live view says so: {said}")

# Netzwerkschutz fuer alles, was eine Seite nachlaedt (Bilder, Skripte, Weiterleitungen),
# und Cookie-Banner: Mara lehnt ab, wenn sie kann.
HITS["pixel"] = 0
_orig_get = Handler.do_GET


def counting_get(self):
    if self.path.startswith("/pixel"):
        HITS["pixel"] += 1
    return _orig_get(self)


Handler.do_GET = counting_get
public = """<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Oeffentlich</title></head><body>
<div id="cookie-banner" role="dialog"><p>Wir nutzen Cookies.</p>
<button onclick="this.parentNode.remove();document.title='akzeptiert'">Alle akzeptieren</button>
<button onclick="this.parentNode.remove();document.title='abgelehnt'">Ablehnen</button></div>
<main><p>Inhalt</p><a id="weg" href="BASE/pixel.html">weiter</a></main></body></html>""".replace("BASE", BASE)
with w.sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True, args=["--no-sandbox"])
    ctx = browser.new_context()
    w.install_network_guard(ctx, dict(w.DEFAULTS))
    ctx.route("http://public.example/**", lambda route: route.fulfill(status=200, content_type="text/html", body=public))
    page = ctx.new_page()
    page.goto("http://public.example/", wait_until="load")
    check(w.dismiss_consent(page) and page.title() == "abgelehnt", "cookie banner declined")
    check(w.dismiss_consent(page) is False, "no banner, no click")
    # Ein Link (oder eine Weiterleitung) einer fremden Seite ins lokale Netz wird abgebrochen.
    try:
        page.click("#weg", timeout=3000)
        page.wait_for_timeout(600)
    except Exception:
        pass
    check(HITS["pixel"] == 0 and "pixel" not in page.url, "a public page cannot lead the browser into the local net")
    browser.close()
server.shutdown()

print("CoreAI browse worker (live): form filled, cursor and thinking frames, login and contact blocked, "
      "local net closed, cookie banner declined OK")
