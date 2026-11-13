# -*- coding: utf-8 -*-
"""Pure, model-independent relevance checks for CoreAI Deep Research.

These checks rank evidence, not people. No user identity, site or provider is hardcoded.
"""
import html
import re
import unicodedata
from urllib.parse import urlsplit

CAP_WORD = r"(?:[A-ZÄÖÜ][A-Za-zÀ-ÿ0-9-]{2,}|[A-Z]{2,})"
CAP_RUN = re.compile(r"\b" + CAP_WORD + r"(?:\s+" + CAP_WORD + r")+", re.UNICODE)
URL = re.compile(r"https?://[^\s<>\"']+", re.I)
SKIP_ENTITIES = {"deep research", "deep recherche", "deep search", "bitte suche", "bitte recherchiere"}
ACTION_WORDS = {"recherche", "recherchiere", "analysiere", "durchsuche", "suche", "bitte", "untersuche", "finde"}
ENTITY_ENDINGS = {"studios", "studio", "labs", "laboratories", "systems", "solutions", "software", "institute", "institut", "foundation", "gmbh", "ag", "inc", "ltd", "universität", "university"}
STOP_WORDS = {"bitte", "recherche", "recherchiere", "recherchieren", "suche", "nach", "über", "ueber", "der", "die", "das", "den", "dem", "des", "eine", "einer", "einem", "einen", "mit", "und", "oder", "von", "vom", "aus", "bei", "zum", "zur", "dazu", "alle", "alles", "aktuelle", "aktuell", "wer", "was", "wie", "ist", "sind", "ein", "für", "fur", "mich", "ihn", "sie", "seine", "ihre", "an", "im", "am", "in", "zu", "deep", "research", "bericht", "informationen", "finde", "darüber", "offiziell"}


def fold(value):
    """Text comparison independent of punctuation, Unicode accents and URL dashes."""
    value = unicodedata.normalize("NFKD", html.unescape(str(value or "")).casefold())
    value = "".join(c for c in value if not unicodedata.combining(c))
    return " ".join(re.findall(r"[a-z0-9]+", value))


def host(url):
    try:
        value = (urlsplit(str(url or "")).hostname or "").lower()
        return value[4:] if value.startswith("www.") else value
    except ValueError:
        return ""


def explicit_hosts(query):
    return tuple(dict.fromkeys(h for h in (host(x.rstrip(".,;:!?")) for x in URL.findall(query or "")) if h))


def identity_phrases(query):
    """Extract probable multiword names without assuming who the user is.

    For adjacent names/places, keep their beginning and possible organization tail.
    The phrases are anchors for verifying search results, not biographies.
    """
    raw = URL.sub(" ", str(query or ""))[:500]
    phrases = []
    for quoted in re.findall(r'["“]([^"”]{4,80})["”]', raw):
        quoted = " ".join(quoted.split())
        if 2 <= len(quoted.split()) <= 7:
            phrases.append(quoted)
    for match in CAP_RUN.finditer(raw):
        words = match.group(0).split()
        while words and fold(words[0]) in ACTION_WORDS:
            words.pop(0)
        if len(words) < 2:
            continue
        if len(words) <= 3:
            phrases.append(" ".join(words))
        else:
            phrases.append(" ".join(words[:2]))
            if fold(words[-1]) in ENTITY_ENDINGS:
                phrases.append(" ".join(words[-3:]))
            else:
                phrases.append(" ".join(words[-2:]))
    output = []
    for phrase in phrases:
        key = fold(phrase)
        if len(key) < 5 or key in SKIP_ENTITIES or key in {fold(x) for x in output}:
            continue
        output.append(phrase)
    unique = [p for p in output if not any(p != q and fold(p) in fold(q) for q in output)]
    return unique[:4]


def topic_terms(query):
    raw = URL.sub(" ", str(query or ""))
    # A URL-only research task still has meaningful subject words in its host.
    for domain in explicit_hosts(query):
        raw += " " + domain.replace("-", " ").replace(".", " ")
    return tuple(dict.fromkeys(w for w in fold(raw).split()
                               if len(w) >= 4 and w not in STOP_WORDS
                               and w not in ("https", "http", "html", "www", "test", "com", "net", "org")))[:15]


def contains_phrase(text, phrase):
    return (" " + fold(phrase) + " ") in (" " + fold(text) + " ")


def on_host(url, known_host):
    current = host(url)
    return bool(known_host and (current == known_host or current.endswith("." + known_host)))


def relevance(result, query, hosts=()):
    """None means off-topic; the rank is higher for exact named entities and readable titles."""
    url = str(result.get("url") or "")
    if not url.lower().startswith(("http://", "https://")):
        return None
    title = str(result.get("title") or "")
    excerpt = str(result.get("content") or result.get("snippet") or "")
    address = fold(url)
    anchors = identity_phrases(query)
    anchor_hits = sum(1 for p in anchors if contains_phrase(title, p) or contains_phrase(excerpt, p)
                      or contains_phrase(address, p))
    site_matches = any(on_host(url, x) for x in (*explicit_hosts(query), *hosts))
    if anchors and not anchor_hits and not site_matches:
        return None
    corpus = fold(title + " " + excerpt + " " + url)
    overlap = sum(1 for term in topic_terms(query) if (" " + term + " ") in (" " + corpus + " "))
    if not anchors and not site_matches and overlap == 0:
        return None
    score = overlap * 2
    for p in anchors:
        if contains_phrase(title, p):
            score += 18
        if contains_phrase(excerpt, p):
            score += 12
        if contains_phrase(address, p):
            score += 9
    if site_matches:
        score += 22
    score += min(len(anchors), anchor_hits) * 3
    return score


def rank_results(results, query, seen=(), hosts=()):
    """Deduplicate and rank by evidence, *not* by artificial domain variety."""
    seen = set(seen)
    ranked, urls = [], set()
    for index, item in enumerate(results):
        url = str(item.get("url") or "")
        key = url.split("#", 1)[0].rstrip("/").casefold()
        if url in seen or key in urls:
            continue
        score = relevance(item, query, hosts)
        if score is None:
            continue
        urls.add(key)
        ranked.append((-score, index, item))
    ranked.sort(key=lambda x: (x[0], x[1]))
    return [item for _, _, item in ranked]


def fetched_page_is_relevant(query, url, title, body, hosts=()):
    """A source must be actually readable and must pass the identity check."""
    if len(" ".join(str(body or "").split())) < 80:
        return False
    anchors = identity_phrases(query)
    if anchors and not any(contains_phrase(str(title or "") + " " + str(body or ""), p) for p in anchors):
        # The search URL alone is not proof that the fetched (possibly redirected)
        # page really discusses the named subject.
        if not any(on_host(url, h) for h in explicit_hosts(query)):
            return False
    return relevance({"url": url, "title": title, "content": body[:12000]}, query, hosts) is not None


def enforce_queries(proposed, query, step, count, hosts=()):
    """Keep operator's search budget, but prevent the Core from losing proper names."""
    anchors = identity_phrases(query)
    if not anchors:
        return list(dict.fromkeys(str(q).strip()[:140] for q in proposed if str(q).strip()))[:count]
    marker = '"' + anchors[0] + '"'
    chosen = []
    # The discovery query changes with the research step; avoid repeating the
    # same bare name query at every step and throwing away already seen URLs.
    subject_words = set(fold(" ".join(anchors)).split())
    generic = {"quelle", "quellen", "webseite", "webseiten", "seiten", "fakten", "vergleiche", "suche", "lies", "berichte"}
    focus = next((w for w in topic_terms(step) if w not in subject_words and w not in generic), "")
    if hosts:
        chosen.append((marker + " site:" + hosts[0] + (" " + focus if focus else ""))[:140])
    else:
        chosen.append((marker + (' "' + anchors[1] + '"' if len(anchors) > 1 else "")
                       + (" " + focus if focus else ""))[:140])
    for i, raw in enumerate(proposed):
        text = " ".join(str(raw or "").replace("“", '"').replace("”", '"').split())
        if not text:
            continue
        if not any(contains_phrase(text, a) for a in anchors):
            text = '"' + anchors[i % len(anchors)] + '" ' + text
        if len(text) > 140:
            text = text[:140].rsplit(" ", 1)[0]
        if text.casefold() not in {x.casefold() for x in chosen}:
            chosen.append(text)
        if len(chosen) >= count:
            break
    if len(chosen) < count and len(anchors) > 1:
        alternative = '"' + anchors[-1] + '" ' + " ".join(str(step or "").split()[:4])
        if alternative.casefold() not in {x.casefold() for x in chosen}:
            chosen.append(alternative[:140])
    return chosen[:count]


def supported_notes(text, source_numbers):
    """Discard uncited notes and bogus source numbers before the final report prompt."""
    allowed = {int(n) for n in source_numbers}
    cleaned = []
    for line in str(text or "").splitlines():
        groups = re.findall(r"\[(\d{1,3}(?:\s*[,;]\s*\d{1,3})*)\]", line)
        nums = [int(n) for group in groups for n in re.split(r"\s*[,;]\s*", group)]
        if nums and all(n in allowed for n in nums):
            cleaned.append(line.strip())
    return "\n".join(cleaned)[:3000]
