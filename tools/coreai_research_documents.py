# -*- coding: utf-8 -*-
"""Bounded, public-only document reader for hybrid Deep Research.

Never follows a redirect into private networks. Data returned by a document
is evidence only, never a tool instruction, code to execute or authentication.
"""
import csv
import io
import json
from urllib.parse import urljoin, urlsplit

import requests
import coreai_deep_research as dr

MAX_BYTES = 6_000_000
MAX_REDIRECTS = 5
MAX_PDF_PAGES = 36


def fetch_document(cfg, url, navigation_guard, get=None):
    get = get or requests.get
    target = str(url or "")
    data = b""
    kind = ""
    for _ in range(MAX_REDIRECTS):
        reason = navigation_guard(target, cfg)
        if reason:
            raise ValueError("research_public_url_blocked: " + str(reason))
        resp = get(target, timeout=(6, 24), allow_redirects=False, stream=True,
                   headers={"User-Agent": "CoreAI-Research/0.7", "Accept": "application/pdf,text/csv,application/json,text/plain,*/*;q=0.5"})
        try:
            if resp.status_code in (301, 302, 303, 307, 308) and resp.headers.get("Location"):
                target = urljoin(target, resp.headers["Location"])
                continue
            resp.raise_for_status()
            kind = (resp.headers.get("Content-Type") or "").split(";", 1)[0].strip().lower()
            for chunk in resp.iter_content(65536):
                data += chunk
                if len(data) > MAX_BYTES:
                    raise ValueError("research_document_too_large")
            break
        finally:
            resp.close()
    else:
        raise ValueError("research_document_redirects_exceeded")
    title = urlsplit(target).path.rsplit("/", 1)[-1] or target
    if kind == "application/pdf" or data.startswith(b"%PDF-"):
        try:
            from pypdf import PdfReader
        except ImportError as exc:
            raise ValueError("pypdf_missing_in_browse_image") from exc
        reader = PdfReader(io.BytesIO(data), strict=False)
        pages = []
        for index, page in enumerate(reader.pages):
            if index >= MAX_PDF_PAGES:
                break
            text = page.extract_text() or ""
            if text.strip():
                pages.append(f"[PDF Seite {index + 1}]\n{text[:6000]}")
        if not pages:
            raise ValueError("research_pdf_unreadable_text")
        return title, "\n\n".join(pages)[:50000], target
    if kind in ("text/csv", "application/csv") or target.lower().split("?", 1)[0].endswith(".csv"):
        rows = csv.reader(io.StringIO(data.decode("utf-8-sig", errors="replace")))
        sample = [" | ".join(str(cell)[:200] for cell in row[:30])
                  for _, row in zip(range(300), rows)]
        return title, "\n".join(sample)[:50000], target
    if kind in ("application/json", "application/ld+json") or target.lower().split("?", 1)[0].endswith(".json"):
        obj = json.loads(data.decode("utf-8-sig", errors="replace"))
        return title, json.dumps(obj, ensure_ascii=False, indent=2)[:50000], target
    if kind in ("text/plain", "text/markdown"):
        return title, data.decode("utf-8", errors="replace")[:50000], target
    if kind in ("text/html", "application/xhtml+xml"):
        raw = data.decode("utf-8", errors="replace")
        return dr.html_title(raw) or title, dr.html_to_text(raw), target
    raise ValueError("research_unsupported_document_type: " + kind[:60])
