# -*- coding: utf-8 -*-
"""CoreAI hybrid Deep Research: one Lead, independent Map workers, audited Reduce.

The Lead alone calls the model for planning, worker summaries, reflection and
citation review. Independent source discovery and document reading overlap on
I/O threads; model calls stay in the existing *global* serial Ollama lock.
No private model chain-of-thought is stored. Durable state is structured JSON.
"""
import concurrent.futures
import hashlib
import json
import re
import time
from urllib.parse import urlsplit

import coreai_deep_research as dr
import coreai_research_quality as quality

STATE_VERSION = 1
CITE = re.compile(r"\[(\d{1,3})\]")
WORD = re.compile(r"[\wÀ-ÿ]{4,}", re.UNICODE)



def _core(io, role, system, prompt, want_json, timeout):
    """Role-specific context, but always through the same locked Core queue.

    An operator may choose a faster Worker or a specialist Citation model.
    With no override all roles use Mara's existing selected local Core.
    """
    specialized = getattr(io, "research_core", None)
    if callable(specialized):
        return specialized(role, system, prompt, want_json, timeout)
    return io.core(system, prompt, want_json, timeout)


def _budget(budget, key, default, low, high):
    try:
        value = int(budget.get(key, default))
    except (ValueError, TypeError):
        value = default
    return max(low, min(high, value))


def _record(io, kind, message, domains=()):
    io.event(kind, message, list(domains))


def _check(io, started, clock, budget):
    if io.cancelled():
        raise dr.Cancelled()
    return clock() - started < _budget(budget, "max_minutes", 30, 3, 120) * 60


def _new_state(run):
    steps, _ = dr.research_steps(run.get("steps") or [])
    if not steps:
        steps = ["Suche einschlägige Primärquellen und unabhängige Belege."]
    return {
        "version": STATE_VERSION, "query": str(run.get("query") or ""),
        "phase": "plan", "steps": steps,
        "workers": [{"goal": step, "status": "todo", "notes": "", "brief": "",
                     "source_ids": [], "queries": [], "attempts": 0} for step in steps],
        "sources": [], "evidence": {}, "seen": [], "searches": 0,
        "depth": 0, "report": "", "citations": [], "progress": 0,
        "planner_queries": [], "coverage": {}, "analysis": [],
    }


def _checkpoint(io, state):
    store = getattr(io, "checkpoint", None)
    if callable(store):
        # Snapshots preserve decisions, worker notes, source excerpts and exact
        # URLs, not unbounded HTML, model prompts or private reasoning tokens.
        store(state)


def _remember(io, run):
    getter = getattr(io, "load_checkpoint", None)
    if callable(getter):
        try:
            state = getter()
            if (isinstance(state, dict) and state.get("version") == STATE_VERSION
                    and state.get("query") == str(run.get("query") or "")
                    and isinstance(state.get("workers"), list)):
                return state
        except Exception:
            pass
    return _new_state(run)


def _progress(io, state, phase, active=None, done=False):
    states = ["todo"] * len(run_steps := (state.get("steps") or []))
    for idx, worker in enumerate(state["workers"][:len(states)]):
        states[idx] = ("done" if worker["status"] == "done"
                       else "active" if worker["status"] == "active" else "todo")
    if active is not None and active < len(states) and states[active] == "todo":
        states[active] = "active"
    finished = sum(v == "done" for v in states)
    percentage = 100 if done else min(99, int(finished * 85 / max(1, len(states))) +
                                       (5 if phase == "verify" else 0))
    state["progress"] = max(int(state.get("progress") or 0), percentage)
    io.progress(states, phase, state["progress"], state["searches"])


def _normalize_ref(url):
    return str(url or "").split("#", 1)[0].rstrip("/").casefold()


def _fetch(io, url):
    """Network-only operation safe to run on an I/O worker thread."""
    reader = getattr(io, "fetch_document", None)
    if callable(reader):
        result = reader(url)
        if isinstance(result, tuple) and len(result) == 3:
            return result
    title, text = io.fetch(url)
    return title, text, url


def _parallel_jobs(tasks, fn, parallel):
    """Preserve task order while overlapping independent search/fetch I/O."""
    if not tasks:
        return []
    outputs = [None] * len(tasks)
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, parallel)) as pool:
        futures = {pool.submit(fn, task): idx for idx, task in enumerate(tasks)}
        for future in concurrent.futures.as_completed(futures):
            idx = futures[future]
            try:
                outputs[idx] = (future.result(), None)
            except Exception as exc:
                outputs[idx] = (None, str(exc)[:150])
    return outputs


def _source_excerpt(query, text, window):
    return quality.focused_excerpt(query, text, window)


def _brief_notes(query, pages):
    """Extractive fallback: exact page sentences, never a model invention."""
    anchors = quality.identity_phrases(query)
    out = []
    for n, _, _, body in pages:
        for sentence in re.split(r"(?<=[.!?])\s+", " ".join(body.split())):
            if len(sentence) < 25:
                continue
            if anchors and not any(quality.contains_phrase(sentence, x) for x in anchors):
                continue
            out.append("- " + sentence[:230] + f" [{n}]")
            break
    return "\n".join(out[:8])


def _supported_notes(notes, allowed, evidence):
    # Citation numbers alone are insufficient. Require that each numbered
    # claim shares meaningful content with its actual cached document.
    allowed = {int(x) for x in allowed}
    candidates = quality.supported_notes(notes, allowed)
    kept = []
    for line in candidates.splitlines():
        cited = [int(n) for n in CITE.findall(line)]
        claim = set(quality.fold(CITE.sub("", line)).split())
        claim = {t for t in claim if len(t) >= 4 and t not in quality.STOP_WORDS}
        combined = set()
        for n in cited:
            combined.update(quality.fold(evidence.get(str(n), "")).split())
        if not claim or len(claim.intersection(combined)) >= min(2, len(claim)):
            kept.append(line)
    return "\n".join(kept)[:3500]


def _search_batch(io, state, query_map, parallel, check):
    requests = [(wi, q) for wi, values in sorted(query_map.items()) for q in values if q]
    if not requests:
        return {}
    check()
    matches = _parallel_jobs(requests, lambda v: io.search(v[1]) or [], parallel)
    found = {}
    for (idx, query), (response, error) in zip(requests, matches):
        check()
        state["searches"] += 1
        found.setdefault(idx, []).extend(response or [])
        domains = [dr.domain_of(item.get("url")) for item in (response or [])
                   if item.get("url")]
        _record(io, "search", query, list(dict.fromkeys(domains))[:8])
        if error:
            _record(io, "info", "Suche nicht verfügbar: " + error)
    return found


def _candidate_tasks(state, hits, budget):
    # Separate worker contexts choose their own ranked candidates. A shared
    # seen ledger prevents duplicated work across workers and resumed runs.
    tasks, assigned = [], set(state["seen"])
    maximum = _budget(budget, "pages_per_step", 4, 1, 10) * 3
    for wi, results in sorted(hits.items()):
        w = state["workers"][wi]
        if w["status"] == "done":
            continue
        ranked = quality.rank_results(results, state["query"], assigned)
        for result in ranked[:maximum]:
            key = _normalize_ref(result.get("url"))
            if key in assigned:
                continue
            assigned.add(key)
            tasks.append((wi, result))
    return tasks


def _read_batch(io, state, hits, budget, parallel, check, log):
    targets = _candidate_tasks(state, hits, budget)
    if not targets:
        return {}
    outcomes = _parallel_jobs(targets, lambda v: _fetch(io, v[1]["url"]), parallel)
    pages = {}
    max_pages = _budget(budget, "pages_per_step", 4, 1, 10)
    by_worker = {}
    for (wi, result), (response, error) in zip(targets, outcomes):
        by_worker.setdefault(wi, []).append((result, response, error))
    for wi, records in sorted(by_worker.items()):
        check()
        count = 0
        browser_uses = 0
        for result, response, error in records:
            if count >= max_pages:
                break
            original_url = result["url"]
            key = _normalize_ref(original_url)
            if key not in state["seen"]:
                state["seen"].append(key)
            title, body, url = response if response and len(response) == 3 else ("", "", original_url)
            if error:
                log("research: HTTP read failed (" + error + ")")
            valid = quality.fetched_page_is_relevant(state["query"], url, title, body)
            if not valid and browser_uses < _budget(budget, "browser_pages_per_step", 2, 0, 6):
                browser = getattr(io, "fetch_visual", None)
                if callable(browser):
                    browser_uses += 1
                    try:
                        title, body, url = browser(original_url, state["query"],
                                                   state["workers"][wi]["goal"])
                        valid = quality.fetched_page_is_relevant(state["query"], url, title, body)
                    except dr.Cancelled:
                        raise
                    except Exception as exc:
                        log("research: browser unavailable (" + str(exc)[:120] + ")")
            if not valid:
                continue
            resolved_key = _normalize_ref(url)
            if resolved_key not in state["seen"]:
                state["seen"].append(resolved_key)
            if any(_normalize_ref(s["url"]) == resolved_key for s in state["sources"]):
                continue
            number = len(state["sources"]) + 1
            entry = {"n": number, "url": url, "title": str(title or result.get("title") or url)[:300],
                     "domain": dr.domain_of(url)}
            excerpt = _source_excerpt(state["query"], body, dr.page_chars(
                budget.get("num_ctx") or 4096, max_pages))
            state["sources"].append(entry)
            state["evidence"][str(number)] = excerpt[:5500]
            state["workers"][wi]["source_ids"].append(number)
            io.source(entry)
            evidence_store = getattr(io, "store_evidence", None)
            if callable(evidence_store):
                evidence_store(number, excerpt[:5500], hashlib.sha256(
                    body.encode("utf-8", errors="replace")).hexdigest())
            _record(io, "read", entry["title"], [entry["domain"]])
            pages.setdefault(wi, []).append((number, entry["title"], url, excerpt))
            _checkpoint(io, state)
            count += 1
    return pages


def _worker_notes(io, state, wi, pages, budget, check):
    w = state["workers"][wi]
    w["status"] = "active"
    _record(io, "step", "Teilrecherche: " + w["goal"][:260])
    if not pages:
        w["attempts"] += 1
        _record(io, "info", "Teilrecherche ohne zugänglichen Volltext: " + w["goal"][:200])
        return False
    check()
    # Independent prompt per worker: no other worker's HTML, thoughts or
    # full history enters this context. Only its own task and its own pages.
    raw = _core(io, "worker", *dr.notes_prompts(state["query"], w["goal"], pages), False,
                budget["core_timeout"])
    short, summary = dr.split_notes(raw)
    allowed = {p[0] for p in pages}
    filtered = _supported_notes(summary, allowed, state["evidence"])
    if not filtered:
        filtered = _brief_notes(state["query"], pages)
    if filtered:
        w["notes"] = (w["notes"] + "\n" + filtered).strip()[-5000:]
        w["brief"] = (short or w["brief"] or filtered.splitlines()[0])[:300]
        _record(io, "note", w["brief"])
    w["attempts"] += 1
    return bool(filtered)


def _lead_decide(io, state, budget):
    """One shared reasoning trajectory decides what needs depth, not raw CoT."""
    coverage = []
    summary = _coverage_score(state)
    for idx, w in enumerate(state["workers"]):
        coverage.append(f"{idx}: {w['goal']}\n"
                        f"Belege: {len(w['source_ids'])}, unabhängige Domains: "
                        f"{summary[str(idx)]['independent_domains']}, "
                        f"Notizen: {w['notes'][:800]}")
    system = (
        "Du bist Lead Researcher. Prüfe nach der parallelen Recherche "
        "Unklarheiten, Widersprüche und fehlende Belege. Vertraue nur den "
        "angegebenen belegten Notizen. Gib NUR JSON aus: "
        '{"stop":true,"followups":[{"worker":0,"query":"konkrete Suchanfrage"}],'
        '"new_tasks":["zusätzliches unabhängiges Teilthema"],'
        '"analysis":{"operation":"sum|mean|median|min|max|stdev",'
        '"values":[2,4],"sources":[1,2]}}. '
        "analysis nur wenn die Zahlen EXAKT aus den Belegen stammen und "
        "die Berechnung wirklich zur Forschungsfrage gehört. "
        "Wenn alle wichtigen Aspekte abgedeckt sind, stop=true. "
        "Kein internes Thinking oder Behauptungen ohne Quellen.")
    user = "Auftrag: " + state["query"] + "\n" + "\n\n".join(coverage)
    raw = _core(io, "lead", system, user[:9500], True, budget["core_timeout"])
    result = dr.parse_json_object(raw)
    return result if isinstance(result, dict) else {}


def _coverage_score(state):
    """Conservative coverage metric, not model confidence or invented facts."""
    registry = {s["n"]: s["domain"] for s in state["sources"]}
    coverage = {}
    for idx, worker in enumerate(state["workers"]):
        domains = sorted({registry[n] for n in worker["source_ids"] if n in registry})
        coverage[str(idx)] = {
            "sources": len(worker["source_ids"]), "independent_domains": len(domains),
            "has_cited_notes": bool(worker["notes"]), "domains": domains[:10],
        }
    state["coverage"] = coverage
    return coverage


def _followup_queries(decision, state, budget):
    focus = {}
    workers = state["workers"]
    for item in (decision.get("followups") or [])[:len(workers) * 2]:
        if not isinstance(item, dict):
            continue
        idx = item.get("worker")
        if type(idx) is not int or not 0 <= idx < len(workers):
            continue
        query = str(item.get("query") or "").strip()[:140]
        if not query:
            continue
        focus.setdefault(idx, []).append(query)
    # Backtracking when evidence is missing OR all hits come from one
    # provenance. The model may recommend stopping; independent source
    # coverage still gets a bounded chance for verification.
    coverage = _coverage_score(state)
    for idx, w in enumerate(workers):
        if not w["notes"] or coverage[str(idx)]["independent_domains"] < 2:
            excluded = coverage[str(idx)]["domains"]
            focus.setdefault(idx, []).extend(
                quality.alternative_queries(state["query"], w["goal"],
                                            blocked_hosts=excluded, limit=2))
    for idx, value in list(focus.items()):
        focus[idx] = quality.enforce_queries(value, state["query"],
                                             workers[idx]["goal"], 2)
    return focus



def _analysis_values(decision, state):
    """Only cached, source-backed numeric inputs are eligible for the sandbox."""
    spec = decision.get("analysis")
    if not isinstance(spec, dict):
        return None
    allowed = {"sum", "mean", "median", "min", "max", "stdev"}
    operation = str(spec.get("operation") or "").lower()
    if operation not in allowed:
        return None
    sources = spec.get("sources")
    numbers = spec.get("values")
    if not isinstance(sources, list) or not isinstance(numbers, list):
        return None
    sources = [x for x in sources if type(x) is int and 0 < x <= len(state["sources"])]
    if not sources or not 2 <= len(numbers) <= 100:
        return None
    validated = []
    evidence = "\n".join(state["evidence"].get(str(n), "") for n in sources)
    for number in numbers:
        if isinstance(number, bool) or not isinstance(number, (float, int)):
            return None
        value = float(number)
        if not -1e12 < value < 1e12:
            return None
        plain = str(number)
        if not re.search(r"(?<!\d)" + re.escape(plain).replace(r"\.", r"[.,]") + r"(?!\d)", evidence):
            return None
        validated.append(value)
    return {"operation": operation, "values": validated, "sources": sorted(set(sources))}


def _analyze_with_sandbox(io, decision, state):
    spec = _analysis_values(decision, state)
    if not spec or not callable(getattr(io, "python_analysis", None)):
        return
    try:
        outcome = io.python_analysis(spec)
        if not isinstance(outcome, dict) or not isinstance(outcome.get("value"), (int, float)):
            return
        source_markers = "".join(f"[{n}]" for n in spec["sources"])
        chart = str(outcome.get("sparkline") or "")[:100]
        result = (f"Isolierte Python-Berechnung ({spec['operation']}) aus "
                  f"{len(spec['values'])} belegten Zahlen: {outcome['value']} "
                  f"{source_markers}" + (f"\nDatenverlauf (Sparkline): {chart}" if chart else ""))
        state.setdefault("analysis", []).append(result[:500])
        _record(io, "info", "Datenanalyse im isolierten Python-Worker abgeschlossen.")
    except dr.Cancelled:
        raise
    except Exception:
        _record(io, "info", "Python-Auswertung nicht verfügbar; Recherche läuft ohne Unterbrechung weiter.")


def _append_tasks(decision, state, budget):
    # Dynamic spawning is bounded by the existing run plan's scale; a new
    # worker has a fresh isolated context but shares the evidence ledger.
    limit = len(state["steps"]) + _budget(budget, "extra_workers", 2, 0, 8)
    existing = {quality.fold(w["goal"]) for w in state["workers"]}
    for task in decision.get("new_tasks") or []:
        task = " ".join(str(task or "").split())[:220]
        if len(state["workers"]) >= limit:
            break
        if len(task) < 12 or quality.fold(task) in existing:
            continue
        state["workers"].append({"goal": task, "status": "todo", "notes": "",
                                 "brief": "", "source_ids": [], "queries": [], "attempts": 0})
        existing.add(quality.fold(task))


def _report_review(io, state, candidate, budget):
    if not state["sources"]:
        return candidate
    source_lines = []
    cited = {int(x) for x in CITE.findall(candidate)}
    for entry in state["sources"]:
        if entry["n"] in cited:
            body = state["evidence"].get(str(entry["n"]), "")
            source_lines.append(f"[{entry['n']}] {entry['title']} ({entry['url']})\n{body[:1100]}")
    if not source_lines:
        return candidate
    system = (
        "Du bist ein unabhängiger Citation Agent. Prüfe den Bericht NUR "
        "gegen die nummerierten Originalauszüge. Entferne nicht belegbare "
        "Behauptungen, erfundene Quellen und falsche Zuschreibungen. "
        "Zitiere in Markdown nur die tatsächlich passenden [Nummern]. "
        "Behalte belegte Erkenntnisse und Recherchegrenzen. "
        "Keine zusätzlichen Fakten, kein privates Thinking, nur den Bericht.")
    user = ("BERICHT:\n" + candidate[:8500] + "\n\nORIGINALAUSZÜGE:\n" +
            "\n\n".join(source_lines))[:15000]
    try:
        result = _core(io, "citation", system, user, False, budget["core_timeout"])
        return result if len(str(result or "").strip()) >= 180 else candidate
    except dr.Cancelled:
        raise
    except Exception:
        return candidate


def _audit_report(report, state):
    """Deterministic post-pass; exact source references must exist and overlap.

    Semantic truth is NOT mathematically provable by token overlap. The
    independent citation-model review above provides an additional check.
    Uncited specific factual sentences are omitted rather than fabricated.
    """
    valid = {s["n"] for s in state["sources"]}
    out, citations = [], set()
    in_limits = False
    for line in report.splitlines():
        stripped = line.strip()
        if not stripped:
            out.append("")
            continue
        if stripped.startswith("#"):
            in_limits = any(word in quality.fold(stripped)
                            for word in ("recherchegrenzen", "methodik", "einschrankungen"))
            out.append(line)
            continue
        nums = [int(x) for x in CITE.findall(line)]
        if not nums:
            # Labels, method and research limits are not asserted sourced facts.
            if in_limits or stripped.endswith(":") or len(stripped) < 35:
                out.append(line)
            continue
        if any(n not in valid for n in nums):
            continue
        claim = {t for t in quality.fold(CITE.sub("", line)).split()
                 if len(t) >= 5 and t not in quality.STOP_WORDS}
        corpus = set()
        for n in nums:
            corpus.update(quality.fold(state["evidence"].get(str(n), "")).split())
        if claim and len(claim.intersection(corpus)) < min(2, len(claim)):
            continue
        citations.update(nums)
        out.append(line)
    return "\n".join(out).strip(), sorted(citations)


def _synthesize(io, state, budget, check):
    check()
    notes, shorts = {}, {}
    for i, w in enumerate(state["workers"]):
        if w["notes"]:
            notes[i] = w["notes"]
            shorts[i] = w.get("brief", "")
    steps = [w["goal"] for w in state["workers"]]
    if state.get("analysis"):
        steps.append("Quellengebundene Datenanalyse")
        notes[len(steps) - 1] = "\n".join(state["analysis"])[:2000]
    title = str(budget.get("title") or "Deep Research")
    if not notes:
        return (
            f"# {title}\n\n## Zusammenfassung\n\n"
            "Keine ausreichend passenden, lesbaren und belegbaren Quellen gefunden.\n\n"
            "## Recherchegrenzen\n\n"
            "Suchtreffer und unzugängliche Websites wurden nicht als Belege verwendet."
        ), []
    system, prompt = dr.report_prompts(state["query"], title, steps, notes,
                                       state["sources"], _budget(budget, "report_chars", 14000, 5000, 30000))
    generated = _core(io, "lead", system, prompt, False, budget["core_timeout"])
    candidate, _ = dr.clean_report(generated, title, {s["n"] for s in state["sources"]})
    candidate = _report_review(io, state, candidate, budget)
    candidate, _ = dr.clean_report(candidate, title, {s["n"] for s in state["sources"]})
    report, numbers = _audit_report(candidate, state)
    if len(report) < 180 or not numbers:
        # Preserve genuine sourced findings even if the audit model misbehaved.
        basic = dr.fallback_report(title, state["query"], steps, notes, shorts)
        report, numbers = _audit_report(basic, state)
        if len(report) < 100:
            report = f"# {title}\n\n## Recherchegrenzen\n\nKeine ausreichend belegbaren Aussagen ermittelt."
            numbers = []
    return report, numbers


def run_hybrid_research(run, io, budget, log=lambda *_: None, clock=time.monotonic):
    """Async-in-the-server, parallel-on-I/O, serial-on-model, resumable research."""
    started = clock()
    state = _remember(io, run)
    budget = dict(budget)
    budget["num_ctx"] = run.get("num_ctx")
    budget["title"] = run.get("title") or "Deep Research"
    parallel = _budget(budget, "io_workers", 3, 1, 8)
    max_depth = _budget(budget, "depth_rounds", 2, 1, 5)

    def check():
        return _check(io, started, clock, budget)

    try:
        if not check():
            raise dr.Cancelled()
        if state.get("phase") == "done" and state.get("report"):
            # Recovered after saving the final checkpoint but before the DB
            # completion transaction; never repeat expensive model synthesis.
            _progress(io, state, "done", done=True)
            io.finish("done", state["report"], state.get("citations") or [], None)
            return "done"
        _progress(io, state, "plan")
        if not state["planner_queries"]:
            system, prompt = dr.queries_prompts(
                state["query"], state["steps"], _budget(budget, "queries_per_step", 4, 1, 8))
            raw = _core(io, "lead", system, prompt, True, budget["core_timeout"])
            state["planner_queries"] = dr.parse_queries(
                raw, state["query"], state["steps"], budget["queries_per_step"])
            state["phase"] = "map"
            _checkpoint(io, state)
        while state["depth"] < max_depth and check():
            round_number = state["depth"]
            queries = {}
            for i, w in enumerate(state["workers"]):
                if w["status"] == "done":
                    continue
                if round_number == 0 and i < len(state["planner_queries"]):
                    suggested = state["planner_queries"][i]
                else:
                    suggested = w.get("queries") or quality.alternative_queries(
                        state["query"], w["goal"], limit=2)
                queries[i] = quality.enforce_queries(
                    suggested, state["query"], w["goal"],
                    budget["queries_per_step"] if round_number == 0 else 2)
                w["queries"] = queries[i]
                if w["status"] != "done":
                    w["status"] = "active"
            state["phase"] = "search"
            _checkpoint(io, state)
            results = _search_batch(io, state, queries, parallel, check)
            state["phase"] = "read"
            _checkpoint(io, state)
            pages = _read_batch(io, state, results, budget, parallel, check, log)
            for wi, w in enumerate(state["workers"]):
                if wi not in queries:
                    continue
                _worker_notes(io, state, wi, pages.get(wi, []), budget, check)
                w["status"] = "done"
                _coverage_score(state)
                _progress(io, state, "map", wi)
                _checkpoint(io, state)
            state["depth"] += 1
            state["phase"] = "reduce"
            _checkpoint(io, state)
            if state["depth"] >= max_depth or not check():
                break
            decision = _lead_decide(io, state, budget)
            _record(io, "info", "Lead Researcher prüft Widersprüche und offene Belege.")
            _analyze_with_sandbox(io, decision, state)
            _append_tasks(decision, state, budget)
            next_queries = _followup_queries(decision, state, budget)
            source_domains = {source["domain"] for source in state["sources"]}
            if (decision.get("stop") is True and len(source_domains) >= 2 and
                    all(w["notes"] for w in state["workers"])):
                break
            if not next_queries and all(w["notes"] for w in state["workers"]):
                break
            for wi, followups in next_queries.items():
                state["workers"][wi]["queries"] = followups
                state["workers"][wi]["status"] = "todo"
            _checkpoint(io, state)
        if not check() and not io.cancelled():
            _record(io, "info", "Das Zeitbudget ist erreicht; Mara führt die belegten Funde zusammen.")
        if io.cancelled():
            raise dr.Cancelled()
        state["phase"] = "verify"
        _progress(io, state, "verify")
        io.write_phase()
        _checkpoint(io, state)
        report, cited = _synthesize(io, state, budget, lambda: io.cancelled() and (_ for _ in ()).throw(dr.Cancelled()))
        state["report"], state["citations"] = report, cited
        state["phase"] = "done"
        _checkpoint(io, state)
        for w in state["workers"]:
            w["status"] = "done"
        _progress(io, state, "done", done=True)
        io.finish("done", report, cited, None)
        return "done"
    except dr.Cancelled:
        io.finish("cancelled", None, [], None)
        return "cancelled"
    except Exception as exc:
        log("hybrid Deep Research fehlgeschlagen: " + str(exc)[:300])
        io.finish("error", None, [], str(exc)[:300])
        return "error"
