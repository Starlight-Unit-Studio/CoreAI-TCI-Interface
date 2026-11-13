# -*- coding: utf-8 -*-
"""Bounded, optional public search discovery when the configured SearXNG fails.

A search hit is NEVER a citation. The Research worker must still fetch the
actual document and pass the normal subject/source checks before citing it.
No CAPTCHA bypass, proxies, account credentials or private-network access.
"""
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit, parse_qs, unquote
import html

MAX_BODY = 850_000
DUCK_HOSTS = {"html.duckduckgo.com", "duckduckgo.com"}


def _target(href):
    href = html.unescape(str(href or "").strip())
    if href.startswith("//"):
        href = "https:" + href
    if href.startswith("/"):
        href = "https://duckduckgo.com" + href
    parsed = urlsplit(href)
    if parsed.hostname in DUCK_HOSTS and parsed.path.rstrip("/") in ("/l", "/l/"):
        href = (parse_qs(parsed.query).get("uddg") or [""])[0]
    parsed = urlsplit(href)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        return ""
    if parsed.username or parsed.password:
        return ""
    return href


class ResultParser(HTMLParser):
    """Parse public DuckDuckGo HTML result links without executing scripts."""
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.results = []
        self.active = None
        self.depth = 0

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if self.active is not None:
            # Void elements (img/br/etc.) do not have closing tags.
            if tag not in ("area", "base", "br", "col", "embed", "hr",
                           "img", "input", "link", "meta", "param", "source", "wbr"):
                self.depth += 1
            return
        if tag != "a":
            return
        classes = str(attrs.get("class") or "").split()
        if "result__a" not in classes:
            return
        target = _target(attrs.get("href"))
        if target:
            self.active = {"url": target, "title": "", "content": ""}
            self.depth = 1

    def handle_data(self, data):
        if self.active is not None:
            self.active["title"] += data

    def handle_endtag(self, tag):
        if self.active is None:
            return
        self.depth -= 1
        if self.depth <= 0:
            self.active["title"] = " ".join(self.active["title"].split())[:300]
            if self.active["title"]:
                self.results.append(self.active)
            self.active = None
            self.depth = 0


def parse_html(text, limit=10, guard=None, cfg=None):
    parser = ResultParser()
    parser.feed(str(text or "")[:MAX_BODY])
    out, seen = [], set()
    for item in parser.results:
        url = item["url"]
        key = url.split("#", 1)[0].rstrip("/").casefold()
        if key in seen or (guard and guard(url, cfg)):
            continue
        seen.add(key)
        out.append(item)
        if len(out) >= max(1, min(10, int(limit))):
            break
    return out


def public_search(query, cfg, guard, get=None, timeout=12, limit=10):
    """Best-effort independent index. Never hide an unavailable provider."""
    if get is None:
        import requests
        get = requests.get
    url = "https://html.duckduckgo.com/html/"
    if guard(url, cfg):
        raise ValueError("public_search_endpoint_blocked")
    response = get(url, params={"q": str(query or "")[:180]}, timeout=(5, min(20, int(timeout))),
                   allow_redirects=False,
                   headers={"User-Agent": "Mozilla/5.0 (compatible; CoreAI-Research/0.7)",
                            "Accept": "text/html,application/xhtml+xml"})
    try:
        # Redirects are not blindly followed; another domain cannot move
        # discovery to an internal service or an unapproved provider.
        if response.status_code in (301, 302, 303, 307, 308):
            raise ValueError("public_search_redirected")
        response.raise_for_status()
        body = getattr(response, "content", b"")
        if not isinstance(body, bytes):
            body = str(body).encode("utf-8", errors="replace")
        if len(body) > MAX_BODY:
            raise ValueError("public_search_response_too_large")
        text = body.decode(getattr(response, "encoding", None) or "utf-8", errors="replace")
        if any(marker in text.lower() for marker in ("cf-turnstile", "g-recaptcha", "h-captcha")):
            raise ValueError("public_search_challenge")
        return parse_html(text, limit=limit, guard=guard, cfg=cfg)
    finally:
        response.close()
