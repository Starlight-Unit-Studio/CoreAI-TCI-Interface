# -*- coding: utf-8 -*-
"""Optional, operator-approved read-only MCP search discovery.

Only public URL results are returned. MCP snippets are never cited directly:
CoreAI's existing guarded HTTP/PDF/Browser readers must verify the document.
User-account or enterprise credentials are deliberately NOT inferred here.
"""
import json
import re


PROTOCOL = "2025-06-18"
READ_NAMES = re.compile(r"^(?:search|find|list|get|read)_[a-z0-9_]{1,70}$", re.I)


def _json_result(response):
    raw = response.text[:200_000]
    if raw.lstrip().startswith("{"):
        return json.loads(raw)
    # MCP Streamable HTTP may reply with SSE content rather than JSON.
    for line in reversed(raw.splitlines()):
        if line.startswith("data:"):
            try:
                value = json.loads(line[5:].strip())
                if isinstance(value, dict) and ("result" in value or "error" in value):
                    return value
            except (ValueError, TypeError):
                continue
    raise ValueError("mcp_response_unreadable")


def _call(endpoint, message, session="", post=None):
    if post is None:
        import requests  # optional public MCP provider only
        post = requests.post
    headers = {"Content-Type": "application/json",
               "Accept": "application/json, text/event-stream",
               "MCP-Protocol-Version": PROTOCOL}
    if session:
        headers["Mcp-Session-Id"] = session
    result = post(endpoint, json=message, headers=headers, timeout=(4, 16),
                  allow_redirects=False)
    if result.status_code >= 300:
        raise ValueError("mcp_server_unavailable")
    return _json_result(result), result.headers.get("Mcp-Session-Id", session)


def search_public_mcp(cfg, query, guard, post=None):
    """Return URL candidates from a configured public read-only MCP tool."""
    endpoint = str(cfg.get("STU_DEEP_RESEARCH_MCP_URL") or "").strip()
    tool = str(cfg.get("STU_DEEP_RESEARCH_MCP_READ_TOOL") or "").strip()
    if not endpoint or not tool:
        return []
    if not endpoint.startswith("https://") or not READ_NAMES.fullmatch(tool):
        return []
    if guard(endpoint, cfg):
        return []
    # No auth headers, browser cookies, personal files, secret keys or
    # unrestricted tool names are accepted by this public adapter.
    init = {"jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"protocolVersion": PROTOCOL, "capabilities": {},
                       "clientInfo": {"name": "CoreAI Research", "version": "0.7.42"}}}
    data, session = _call(endpoint, init, post=post)
    if data.get("error"):
        return []
    # Required MCP initialized notification; a 202/204 empty response is
    # normal, so do not try to decode it as a tool result.
    notify = {"jsonrpc": "2.0", "method": "notifications/initialized"}
    sender = post
    if sender is None:
        import requests
        sender = requests.post
    headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream",
               "MCP-Protocol-Version": PROTOCOL}
    if session:
        headers["Mcp-Session-Id"] = session
    ack = sender(endpoint, json=notify, headers=headers, timeout=(4, 12), allow_redirects=False)
    if ack.status_code >= 300:
        return []
    method = str(cfg.get("STU_DEEP_RESEARCH_MCP_QUERY_FIELD") or "query")
    if method not in ("query", "q", "text", "search_query"):
        return []
    request = {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
               "params": {"name": tool, "arguments": {method: str(query)[:200]}}}
    data, _ = _call(endpoint, request, session, post=post)
    if data.get("error"):
        return []
    result = data.get("result") or {}
    items = result.get("structuredContent")
    if isinstance(items, dict):
        items = items.get("results") or items.get("items") or [items]
    if not isinstance(items, list):
        items = []
    for part in result.get("content") or []:
        if not isinstance(part, dict) or part.get("type") != "text":
            continue
        try:
            parsed = json.loads(str(part.get("text") or ""))
            if isinstance(parsed, list):
                items.extend(parsed)
            elif isinstance(parsed, dict):
                sub = parsed.get("results") or parsed.get("items")
                items.extend(sub if isinstance(sub, list) else [parsed])
        except ValueError:
            continue
    output, known = [], set()
    for entry in items[:50]:
        if not isinstance(entry, dict):
            continue
        url = str(entry.get("url") or entry.get("uri") or "").strip()
        if not url.startswith("https://") or guard(url, cfg) or url in known:
            continue
        known.add(url)
        output.append({"url": url,
                       "title": str(entry.get("title") or "")[:280],
                       "content": str(entry.get("snippet") or entry.get("summary") or "")[:700]})
        if len(output) >= 10:
            break
    return output
