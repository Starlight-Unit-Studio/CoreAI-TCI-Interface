"""CoreAI 0.5.98: German text preparation before a voice speaks (P20.0, later P20.3).

The voice models misread digits, dates, times and file names. This turns them
into the words a German speaker would say, before the text reaches any engine:

  32.768            zweiunddreißigtausendsiebenhundertachtundsechzig
  der 4. November   der vierte November        am 4. November  am vierten November
  2026              zweitausendsechsundzwanzig 1999            neunzehnhundertneunundneunzig
  14:30 Uhr         vierzehn Uhr dreißig       1 Uhr           ein Uhr
  15,2 %            fünfzehn Komma zwei Prozent
  index.html        index Punkt Ha Te Em El
  z. B.             zum Beispiel

Standard library only, so the host (report) and every engine image can use it.
"""
from __future__ import annotations

import re

VERSION = 'de-1'

ONES = ['null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun', 'zehn', 'elf', 'zwölf',
        'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn']
TENS = {2: 'zwanzig', 3: 'dreißig', 4: 'vierzig', 5: 'fünfzig', 6: 'sechzig', 7: 'siebzig', 8: 'achtzig', 9: 'neunzig'}
MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
ORDINAL_STEMS = {1: 'ers', 3: 'drit', 7: 'sieb', 8: 'ach'}
# Words before a date that take the "-ten" form (am vierten, den vierten, ...).
DATIVE = {'am', 'vom', 'zum', 'den', 'dem', 'im', 'ab', 'seit', 'bis', 'nach', 'vor'}

LETTERS = {'a': 'A', 'b': 'Be', 'c': 'Ze', 'd': 'De', 'e': 'E', 'f': 'Ef', 'g': 'Ge', 'h': 'Ha', 'i': 'I', 'j': 'Jot',
           'k': 'Ka', 'l': 'El', 'm': 'Em', 'n': 'En', 'o': 'O', 'p': 'Pe', 'q': 'Ku', 'r': 'Er', 's': 'Es', 't': 'Te',
           'u': 'U', 'v': 'Fau', 'w': 'We', 'x': 'Ix', 'y': 'Üpsilon', 'z': 'Zett'}
# File endings people say as a word; all others are spelled.
EXT_WORDS = {'json': 'Jason', 'zip': 'Zipp', 'yaml': 'Jamel', 'yml': 'Jamel', 'gif': 'Gif', 'wav': 'Wave', 'jpeg': 'Jay Peg'}
EXTENSIONS = ('html', 'htm', 'css', 'js', 'ts', 'json', 'py', 'php', 'md', 'txt', 'csv', 'xml', 'yaml', 'yml', 'zip',
              'pdf', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'sh', 'wav', 'mp3', 'mp4', 'docx', 'doc', 'xlsx', 'xls', 'sql', 'log')
ABBREVIATIONS = [
    (r'\bz\.\s?B\.', 'zum Beispiel'), (r'\bd\.\s?h\.', 'das heißt'), (r'\bu\.\s?a\.', 'unter anderem'),
    (r'\busw\.', 'und so weiter'), (r'\bbzw\.', 'beziehungsweise'), (r'\bca\.', 'circa'), (r'\bevtl\.', 'eventuell'),
    (r'\bggf\.', 'gegebenenfalls'), (r'\bNr\.', 'Nummer'), (r'\binkl\.', 'inklusive'), (r'\bvgl\.', 'vergleiche'),
]


def _under_100(n: int, final: bool = True) -> str:
    if n < 20:
        return ('eins' if final else 'ein') if n == 1 else ONES[n]
    tens, unit = divmod(n, 10)
    if unit == 0:
        return TENS[tens]
    return ('ein' if unit == 1 else ONES[unit]) + 'und' + TENS[tens]


def _under_1000(n: int, final: bool = True) -> str:
    hundreds, rest = divmod(n, 100)
    out = ''
    if hundreds:
        out = ('' if hundreds == 1 else ONES[hundreds]) + 'hundert'
    if rest:
        out += _under_100(rest, final)
    return out


def cardinal(n: int) -> str:
    """German number word, e.g. 32768 -> zweiunddreißigtausendsiebenhundertachtundsechzig."""
    if n < 0:
        return 'minus ' + cardinal(-n)
    if n == 0:
        return 'null'
    if n >= 10 ** 12:
        return ' '.join(ONES[int(d)] for d in str(n))
    billions, rest = divmod(n, 10 ** 9)
    millions, rest = divmod(rest, 10 ** 6)
    thousands, rest = divmod(rest, 1000)
    parts = []
    if billions:
        parts.append('eine Milliarde' if billions == 1 else _under_1000(billions, False) + ' Milliarden')
    if millions:
        parts.append('eine Million' if millions == 1 else _under_1000(millions, False) + ' Millionen')
    tail = ''
    if thousands:
        tail = ('' if thousands == 1 else _under_1000(thousands, False)) + 'tausend'
    if rest:
        tail += _under_1000(rest, True)
    if tail:
        parts.append(tail)
    return ' '.join(parts)


def ordinal(n: int, ending: str = 'te') -> str:
    """vierte / vierten; ending is 'te' or 'ten'."""
    if n < 20:
        return ORDINAL_STEMS.get(n, ONES[n]) + ending
    return cardinal(n) + 's' + ending


def year(n: int) -> str:
    """1999 -> neunzehnhundertneunundneunzig, 2026 -> zweitausendsechsundzwanzig."""
    if 1100 <= n <= 1999:
        high, low = divmod(n, 100)
        return _under_100(high) + 'hundert' + (_under_100(low) if low else '')
    return cardinal(n)


def _digits(text: str) -> str:
    return ' '.join(ONES[int(d)] for d in text)


def _ending(prefix: str | None) -> str:
    words = (prefix or '').split()
    return 'ten' if words and words[-1].lower() in DATIVE else 'te'


def _spell_extension(ext: str) -> str:
    ext = ext.lower()
    if ext in EXT_WORDS:
        return EXT_WORDS[ext]
    return ' '.join(LETTERS[c] if c in LETTERS else ONES[int(c)] for c in ext)


def _number(token: str) -> str:
    value = int(token)
    if len(token) == 4 and 1100 <= value <= 1999:
        return year(value)
    if len(token) > 1 and token.startswith('0'):
        return _digits(token)
    return cardinal(value)


_MONTH_RE = '|'.join(MONTHS)
_PREFIX_RE = r'(?P<pre>\b(?i:am|vom|zum|den|dem|im|ab|seit|bis|nach|vor)\s+)?'
_EXT_RE = '|'.join(sorted(EXTENSIONS, key=len, reverse=True))


def prepare(text: str) -> str:
    """The text as a German speaker says it."""
    out = text
    for pattern, words in ABBREVIATIONS:
        out = re.sub(pattern, words, out)
    out = re.sub(r'\s&\s', ' und ', out)

    def file_name(m: re.Match) -> str:
        name = re.sub(r'[_-]+', ' ', m.group(1))
        return f'{name} Punkt {_spell_extension(m.group(2))}'
    out = re.sub(rf'\b([A-Za-z0-9][A-Za-z0-9_-]*)\.({_EXT_RE})\b', file_name, out, flags=re.IGNORECASE)

    def numeric_date(m: re.Match) -> str:
        day, month, yr = int(m.group('d')), int(m.group('m')), int(m.group('y'))
        if not (1 <= day <= 31 and 1 <= month <= 12):
            return m.group(0)
        return f"{m.group('pre') or ''}{ordinal(day, _ending(m.group('pre')))} {MONTHS[month - 1]} {year(yr)}"
    out = re.sub(_PREFIX_RE + r'\b(?P<d>\d{1,2})\.(?P<m>\d{1,2})\.(?P<y>\d{4})\b', numeric_date, out)

    def word_date(m: re.Match) -> str:
        day = int(m.group('d'))
        if not 1 <= day <= 31:
            return m.group(0)
        spoken = f"{m.group('pre') or ''}{ordinal(day, _ending(m.group('pre')))} {m.group('mon')}"
        return spoken + (f" {year(int(m.group('y')))}" if m.group('y') else '')
    out = re.sub(_PREFIX_RE + rf'\b(?P<d>\d{{1,2}})\.\s?(?P<mon>{_MONTH_RE})(?:\s+(?P<y>\d{{4}}))?\b', word_date, out)
    # "vom 3. bis 5. Juni": the first day of a range is an ordinal too.
    out = re.sub(_PREFIX_RE + r'\b(?P<d>\d{1,2})\.(?=\s+bis\s)',
                 lambda m: f"{m.group('pre') or ''}{ordinal(int(m.group('d')), _ending(m.group('pre')))}", out)

    def clock(m: re.Match) -> str:
        hours, minutes = int(m.group('h')), int(m.group('min') or 0)
        if hours > 24 or minutes > 59:
            return m.group(0)
        spoken = ('ein' if hours == 1 else cardinal(hours)) + ' Uhr'
        return spoken + (' ' + cardinal(minutes) if minutes else '')
    out = re.sub(r'\b(?P<h>\d{1,2})(?::(?P<min>\d{2}))?\s*Uhr\b', clock, out)
    out = re.sub(r'\b(?P<um>[Uu]m)\s+(?P<h>\d{1,2}):(?P<min>\d{2})\b', lambda m: m.group('um') + ' ' + clock(m), out)

    def decimal(m: re.Match) -> str:
        whole = int(m.group(1).replace('.', ''))
        return f'{cardinal(whole)} Komma {_digits(m.group(2))}'
    out = re.sub(r'\b(\d{1,3}(?:\.\d{3})+|\d+),(\d+)\b', decimal, out)
    out = re.sub(r'\b(\d{1,3}(?:\.\d{3})+)\b', lambda m: cardinal(int(m.group(1).replace('.', ''))), out)
    out = re.sub(r'\b\d+\b', lambda m: _number(m.group(0)), out)
    out = re.sub(r'\s?%', ' Prozent', out)
    out = re.sub(r'\s?€', ' Euro', out)
    return re.sub(r'[ \t]{2,}', ' ', out)
