"""CoreAI 0.6.1: what of a Mara answer is read aloud (P20.3), standard library only.

A chat answer is Markdown. Before Eve speaks it:
  - code blocks and tables are named, not read ("Codeblock ausgelassen.")
  - links keep their text, bare addresses become "Link", images their caption
  - headings, lists, quotes and emphasis lose their marks
  - tool markers such as [WEB: ...] and emojis are dropped
Then the German text preparation runs (numbers, dates, file names as words)
and the text is cut into parts: the first one short, so the voice starts
quickly, the following ones up to a few sentences.
"""
from __future__ import annotations

import re

MAX_INPUT = 40000
FIRST_PART = 160
MAX_PART = 320
MAX_PARTS = 240

_FENCE = re.compile(r'```.*?(?:```|\Z)', re.S)
_TOOL_LINE = re.compile(r'\[[A-Z][A-Z_ ]{1,30}:[^\]\n]*\]')
_EMOJI = re.compile('[\U0001F000-\U0001FAFF\u2600-\u27BF\uFE0F\u200D\u2B00-\u2BFF]')
_SENTENCE_END = re.compile(r'(?<=[.!?\u2026])\s+')


def speakable(markdown: str) -> str:
    """Markdown answer -> plain text a voice can read; paragraphs on separate lines."""
    text = str(markdown or '')[:MAX_INPUT].replace('\r\n', '\n').replace('\r', '\n')
    text = _FENCE.sub('\nCodeblock ausgelassen.\n', text)
    lines_out: list[str] = []
    in_table = False
    for raw in text.split('\n'):
        line = raw.strip()
        if line.startswith('|') and line.count('|') >= 2:
            if not in_table:
                lines_out.append('Tabelle ausgelassen.')
                in_table = True
            continue
        in_table = False
        if not line or re.fullmatch(r'[-*_=]{3,}', line) or _TOOL_LINE.fullmatch(line):
            lines_out.append('')
            continue
        # Headings and list items are their own sentence (they rarely end with a full stop).
        marked = bool(re.match(r'^(?:#{1,6}\s+|[-*+\u2022]\s+|\d{1,3}[.)]\s+)', line))
        line = re.sub(r'^#{1,6}\s+', '', line)
        line = re.sub(r'^(?:>\s?)+', '', line)
        line = re.sub(r'^(?:[-*+\u2022]|\d{1,3}[.)])\s+', '', line)
        if marked:
            lines_out.extend(['', _end(line), ''])
        else:
            lines_out.append(line)
    text = '\n'.join(lines_out)
    text = _TOOL_LINE.sub('', text)
    text = re.sub(r'!\[([^\]]*)\]\([^)]*\)', lambda m: f'Bild: {m.group(1)}' if m.group(1).strip() else 'Bild', text)
    text = re.sub(r'\[([^\]]+)\]\([^)]*\)', r'\1', text)
    text = re.sub(r'<?\bhttps?://[^\s<>()]*[^\s<>().,;:!?]>?', 'Link', text)
    text = re.sub(r'`([^`\n]*)`', r'\1', text)
    for mark in ('**', '__', '~~', '*', '_'):
        quoted = re.escape(mark)
        text = re.sub(rf'(?<![\w*_~]){quoted}(?=\S)(.+?)(?<=\S){quoted}(?![\w*_~])', r'\1', text)
    text = _EMOJI.sub('', text)
    paragraphs = []
    for block in re.split(r'\n\s*\n', text):
        joined = ' '.join(part.strip() for part in block.split('\n') if part.strip())
        joined = re.sub(r'[ \t]{2,}', ' ', joined).strip()
        joined = re.sub(r'\s+([.,;:!?])', r'\1', joined)
        if joined:
            paragraphs.append(joined)
    return '\n'.join(paragraphs)


def _end(part: str) -> str:
    part = part.strip()
    return part if re.search(r'[.!?\u2026:]$', part) else part + '.'


def _cut_long(sentence: str, limit: int) -> list[str]:
    """A sentence longer than the limit is cut at a comma or a word, never inside one."""
    out = []
    rest = sentence.strip()
    while len(rest) > limit:
        window = rest[:limit]
        cut = max(window.rfind(', '), window.rfind('; '), window.rfind(': '))
        if cut < limit // 3:
            cut = window.rfind(' ')
        if cut <= 0:
            cut = limit
        out.append(rest[:cut + 1].strip().rstrip(',;'))
        rest = rest[cut + 1:].strip()
    if rest:
        out.append(rest)
    return out


def split_parts(text: str) -> list[str]:
    """Plain text -> parts: the first short (quick start), then up to MAX_PART characters."""
    sentences: list[str] = []
    for paragraph in str(text or '').split('\n'):
        for sentence in _SENTENCE_END.split(paragraph.strip()):
            if sentence.strip():
                sentences.extend(_cut_long(sentence, MAX_PART))
    parts: list[str] = []
    current = ''
    for sentence in sentences:
        limit = FIRST_PART if not parts else MAX_PART
        if current and len(current) + 1 + len(sentence) > limit:
            parts.append(_end(current))
            current = sentence
        else:
            current = f'{current} {sentence}'.strip()
        if not parts and len(current) >= 40:
            parts.append(_end(current))
            current = ''
    if current:
        parts.append(_end(current))
    return parts[:MAX_PARTS]


def plan(markdown: str, prepare) -> list[str]:
    """Answer -> spoken parts, with the German text preparation in between."""
    return split_parts(prepare(speakable(markdown)))
