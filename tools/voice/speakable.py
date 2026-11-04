"""CoreAI 0.6.1 (parts 0.6.5, English 0.6.8): what of a Mara answer is read aloud (P20.3), standard library only.

A chat answer is Markdown. Before Eve speaks it:
  - code blocks and tables are named, not read ("Codeblock ausgelassen.")
  - links keep their text, bare addresses become "Link", images their caption
  - headings, lists, quotes and emphasis lose their marks
  - tool markers such as [WEB: ...] and emojis are dropped
Then the German text preparation runs (numbers, dates, file names as words)
and the text is cut into parts: the first one short, so the voice starts
quickly, each next one at most GROWTH times as long as the one before (up to
MAX_PART). Eve computes about twice as fast as she speaks, so the next part
is ready before the current one ends and there is no gap (0.6.5: a short
first sentence followed by a 320 character part left several seconds of
silence).
"""
from __future__ import annotations

import re

MAX_INPUT = 40000
FIRST_PART = 120
MIN_PART = 40
GROWTH = 1.8
MAX_PART = 320
MAX_PARTS = 240

_FENCE = re.compile(r'```.*?(?:```|\Z)', re.S)
_TOOL_LINE = re.compile(r'\[[A-Z][A-Z_ ]{1,30}:[^\]\n]*\]')
_EMOJI = re.compile('[\U0001F000-\U0001FAFF\u2600-\u27BF\uFE0F\u200D\u2B00-\u2BFF]')
_SENTENCE_END = re.compile(r'(?<=[.!?\u2026])\s+')
_CLAUSE_END = re.compile(r'(?<=[,;:])\s+')


# 0.6.8: what is said instead of code, tables, images and addresses, per language.
LABELS = {
    'de': {'code': 'Codeblock ausgelassen.', 'table': 'Tabelle ausgelassen.', 'image': 'Bild', 'link': 'Link'},
    'en': {'code': 'Code block skipped.', 'table': 'Table skipped.', 'image': 'Image', 'link': 'link'},
}
# Words that mark a German or an English answer; words both languages use (in, so, also, was) stay out.
_DE_WORDS = set('der die das und ist nicht ich du sie wir ihr ein eine einen einem mit für auf zu den dem des von sich auch '
                'wie aber oder wenn dann noch nur schon hier kann können bitte danke ja nein sind wird werden habe haben '
                'mein dein dass weil nach bei über unter durch zum zur gibt mir dir uns euch sehr mehr jetzt heute gerne '
                'kein keine diese dieser dieses'.split())
_EN_WORDS = set("the and is are not you we they it this that with for on of to be have has had will can would should "
                "could your my our their there what which who please thanks yes been does do did from at by about if "
                "then just only here now today very more these those i i'm it's don't can't you're let's".split())


def detect_language(markdown: str, fallback: str = 'de') -> str:
    """'de' or 'en' for an answer, from the words it uses (code left out); fallback when unclear."""
    text = _FENCE.sub(' ', str(markdown or '')[:MAX_INPUT]).lower()
    words = re.findall(r"[a-zäöüß']+", text)
    german = sum(word in _DE_WORDS for word in words) + len(re.findall('[äöüß]', text))
    english = sum(word in _EN_WORDS for word in words)
    if english >= 3 and english > 1.5 * german:
        return 'en'
    if german >= 3 and german > 1.5 * english:
        return 'de'
    return fallback if fallback in LABELS else 'de'


def speakable(markdown: str, language: str = 'de') -> str:
    """Markdown answer -> plain text a voice can read; paragraphs on separate lines."""
    label = LABELS.get(language, LABELS['de'])
    text = str(markdown or '')[:MAX_INPUT].replace('\r\n', '\n').replace('\r', '\n')
    text = _FENCE.sub('\n' + label['code'] + '\n', text)
    lines_out: list[str] = []
    in_table = False
    for raw in text.split('\n'):
        line = raw.strip()
        if line.startswith('|') and line.count('|') >= 2:
            if not in_table:
                lines_out.append(label['table'])
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
    text = re.sub(r'!\[([^\]]*)\]\([^)]*\)', lambda m: f"{label['image']}: {m.group(1)}" if m.group(1).strip() else label['image'], text)
    text = re.sub(r'\[([^\]]+)\]\([^)]*\)', r'\1', text)
    text = re.sub(r'<?\bhttps?://[^\s<>()]*[^\s<>().,;:!?]>?', label['link'], text)
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


def _end(part: str, continues: bool = False) -> str:
    """A part ends with punctuation: a comma when its sentence goes on in the next part."""
    part = part.strip()
    if re.search(r'[.!?\u2026:,;]$', part):
        return part
    return part + (',' if continues else '.')


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


def _units(text: str) -> list[tuple[str, bool]]:
    """Sentences cut into clauses (after , ; :); a clause longer than MAX_PART into word runs.
    Each unit says whether its sentence goes on without punctuation (a cut inside a clause)."""
    units: list[tuple[str, bool]] = []
    for paragraph in str(text or '').split('\n'):
        for sentence in _SENTENCE_END.split(paragraph.strip()):
            for clause in _CLAUSE_END.split(sentence.strip()):
                pieces = _cut_long(clause, MAX_PART) if clause.strip() else []
                units.extend((piece, index < len(pieces) - 1) for index, piece in enumerate(pieces))
    return units


def part_limit(parts: list[str]) -> int:
    """How long the next part may be: FIRST_PART at first, then GROWTH times the last part."""
    if not parts:
        return FIRST_PART
    return min(MAX_PART, max(MIN_PART, int(GROWTH * len(parts[-1]))))


def split_parts(text: str) -> list[str]:
    """Plain text -> parts: the first short (quick start), then growing up to MAX_PART characters.
    Parts are filled with whole clauses; only a clause too long for its part is cut at a word."""
    units = _units(text)
    parts: list[str] = []
    current, continues = '', False

    def push_back(pieces: list[str], cut: bool) -> None:
        units[0:0] = [(piece, True) for piece in pieces[:-1]] + ([(pieces[-1], cut)] if pieces else [])

    while units and len(parts) < MAX_PARTS:
        unit, cut = units.pop(0)
        limit = part_limit(parts)
        if current and len(current) + 1 + len(unit) > limit:
            room = limit - len(current) - 1
            if len(current) < limit // 2 and room >= MIN_PART // 2:
                # A short start ("Ja.") is filled up from the next clause, so the part after it
                # stays within GROWTH instead of being much longer than a tiny first part.
                head, *tail = _cut_long(unit, room)
                push_back(tail, cut)
                parts.append(_end(f'{current} {head}', bool(tail) or cut))
                current = ''
                continue
            parts.append(_end(current, continues))
            current = ''
            limit = part_limit(parts)
        if not current and len(unit) > limit:
            head, *tail = _cut_long(unit, limit)
            push_back(tail, cut)
            unit, cut = head, bool(tail) or cut
        current = f'{current} {unit}'.strip()
        continues = cut
        if not parts and len(current) >= MIN_PART:
            parts.append(_end(current, continues))
            current = ''
    if current and len(parts) < MAX_PARTS:
        parts.append(_end(current, continues))
    return parts


def plan(markdown: str, prepare, language: str = 'de') -> list[str]:
    """Answer -> spoken parts, with the text preparation of that language in between."""
    return split_parts(prepare(speakable(markdown, language)))
