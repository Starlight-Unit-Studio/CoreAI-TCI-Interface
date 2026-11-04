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

de-2 (0.6.5), after the first live read-aloud:
  E.M.B.E.R.        Ember            (other dotted letters are spelled: U.S.A. -> U Es A)
  12:00-20:00 CET   zwölf Uhr bis zwanzig Uhr mitteleuropäischer Zeit
  2026-11-04        vierte November zweitausendsechsundzwanzig
  3-5, 16:9, 2:1    drei bis fünf, sechzehn zu neun, zwei zu eins
  v0.6.3, 3.11      Version null Punkt sechs Punkt drei, drei Punkt elf
  Update, Browser   Apdeit, Brauser  (English words respelled so a German voice says them right)
  dash, arrow      a comma between words (a short pause instead of a read sign)

Standard library only, so the host (report) and every engine image can use it.
"""
from __future__ import annotations

import re

VERSION = 'de-2'

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
    (r'\bi\.\s?d\.\s?R\.', 'in der Regel'), (r'\bo\.\s?[äÄ]\.', 'oder Ähnliches'), (r'\bs\.\s?o\.', 'siehe oben'),
    (r'\bs\.\s?u\.', 'siehe unten'), (r'\bu\.\s?U\.', 'unter Umständen'), (r'\bz\.\s?T\.', 'zum Teil'),
    (r'\bv\.\s?a\.', 'vor allem'), (r'\be\.\s?V\.', 'eingetragener Verein'), (r'\betc\.', 'et cetera'),
    (r'\bDr\.', 'Doktor'), (r'\bProf\.', 'Professor'), (r'\bzzgl\.', 'zuzüglich'), (r'\bggü\.', 'gegenüber'),
    (r'(?<!\d\s)(?<!\d)\bmax\.', 'maximal'), (r'(?<!\d\s)(?<!\d)\bmin\.', 'minimal'), (r'\bmind\.', 'mindestens'),
    (r'\bTel\.', 'Telefon'), (r'\bStr\.', 'Straße'), (r'\bkm/h\b', 'Kilometer pro Stunde'), (r'\bund/oder\b', 'und oder'),
]
# Dotted letters with their own spoken name; all others are spelled letter by letter.
DOTTED = {'EMBER': 'Ember'}
# A dotted name at the end of a sentence keeps its full stop when one of these starts the next.
SENTENCE_STARTERS = {'Das', 'Die', 'Der', 'Dies', 'Diese', 'Dieser', 'Es', 'Ich', 'Du', 'Wir', 'Sie', 'Er', 'Ihr', 'Man',
                     'Wenn', 'Dann', 'Damit', 'Dabei', 'Danach', 'Hier', 'Jetzt', 'Bitte', 'Auch', 'So', 'Mara', 'Eve'}
TIME_ZONES = {'CET': 'mitteleuropäischer Zeit', 'MEZ': 'mitteleuropäischer Zeit', 'CEST': 'mitteleuropäischer Sommerzeit',
              'MESZ': 'mitteleuropäischer Sommerzeit', 'UTC': 'U Te Ze', 'GMT': 'Ge Em Te'}
DOMAINS = {'de': 'De', 'com': 'Komm', 'org': 'Org', 'net': 'Nett', 'io': 'I O', 'ai': 'A I', 'eu': 'E U', 'info': 'Info',
           'app': 'Äpp', 'dev': 'Dev'}

# English words a German voice misreads, respelled the German way. Kinds:
#   n  noun: endings (Updates, Browsern) and compounds (Updatefenster -> Apdeit-fenster)
#   w  word: endings only (online, cool, Jobs)
#   x  exact: the word, or with a plural s (names, brands, fixed forms)
PRONUNCIATION = {
    'update': ('Apdeit', 'n'), 'upgrade': ('Apgreid', 'n'), 'download': ('Daunloud', 'n'), 'upload': ('Aploud', 'n'),
    'browser': ('Brauser', 'n'), 'chat': ('Tschätt', 'n'), 'chatbot': ('Tschättbott', 'n'), 'cloud': ('Klaud', 'n'),
    'interface': ('Interfehs', 'n'), 'core': ('Kohr', 'n'), 'team': ('Tiem', 'n'), 'feature': ('Fietscher', 'n'),
    'software': ('Softwär', 'n'), 'hardware': ('Hardwär', 'n'), 'website': ('Websait', 'n'), 'framework': ('Freimwörk', 'n'),
    'container': ('Kontehner', 'n'), 'account': ('Äkaunt', 'n'), 'feedback': ('Fiedbäck', 'n'), 'meeting': ('Mieting', 'n'),
    'design': ('Disain', 'n'), 'backup': ('Bäckapp', 'n'), 'cache': ('Käsch', 'n'), 'setup': ('Settapp', 'n'),
    'laptop': ('Läptop', 'n'), 'smartphone': ('Smartfohn', 'n'), 'display': ('Displeh', 'n'), 'game': ('Gehm', 'n'),
    'gaming': ('Gehming', 'n'), 'player': ('Pleijer', 'n'), 'level': ('Lewel', 'n'), 'workflow': ('Wörkflou', 'n'),
    'highlight': ('Hailait', 'n'), 'home': ('Houm', 'n'), 'host': ('Houst', 'n'), 'hosting': ('Housting', 'n'),
    'shell': ('Schell', 'n'), 'preflight': ('Priflait', 'n'), 'memory': ('Memmori', 'n'), 'thread': ('Tredd', 'n'),
    'release': ('Rilies', 'n'), 'dashboard': ('Däschbord', 'n'), 'pipeline': ('Paiplain', 'n'), 'queue': ('Kju', 'n'),
    'voice': ('Weus', 'n'), 'email': ('Imeil', 'n'), 'weekend': ('Wiekend', 'n'), 'headset': ('Hedset', 'n'),
    'keyboard': ('Kiebord', 'n'), 'password': ('Passwörd', 'n'), 'logout': ('Logaut', 'n'), 'user': ('Juser', 'n'),
    'check': ('Tscheck', 'n'), 'debugging': ('Dibagging', 'n'),
    'online': ('onlein', 'w'), 'offline': ('offlein', 'w'), 'cool': ('kuhl', 'w'), 'nice': ('neis', 'w'), 'fan': ('Fän', 'w'),
    'job': ('Dschobb', 'w'), 'show': ('Schou', 'w'), 'bug': ('Bagg', 'w'), 'debug': ('Dibagg', 'w'), 'mail': ('Meil', 'w'),
    'unit': ('Junit', 'w'),
    'okay': ('Okeh', 'x'), 'ok': ('Okeh', 'x'), 'hi': ('Hai', 'x'), 'sorry': ('Sorri', 'x'), 'live': ('leif', 'x'),
    'tablet': ('Täblet', 'x'), 'gecheckt': ('getscheckt', 'x'), 'geupdatet': ('geapdeitet', 'x'), 'gechattet': ('getschättet', 'x'),
    'coreai': ('Kohr Äi Ei', 'x'), 'eve': ('Iev', 'x'), 'thorne': ('Thorn', 'x'), 'starlight': ('Starlait', 'x'),
    'python': ('Paiten', 'x'), 'javascript': ('Dschawaskript', 'x'), 'java': ('Dschawa', 'x'), 'github': ('Gitt Habb', 'x'),
    'google': ('Guhgel', 'x'), 'youtube': ('Jutjub', 'x'), 'whatsapp': ('Wotsäpp', 'x'), 'iphone': ('Eifohn', 'x'),
    'windows': ('Windous', 'x'), 'chrome': ('Krohm', 'x'), 'firefox': ('Feierfox', 'x'), 'chatgpt': ('Tschätt Ge Pe Te', 'x'),
    'claude': ('Klohd', 'x'), 'openai': ('Oupen Äi Ei', 'x'), 'nvidia': ('Enwidia', 'x'), 'microsoft': ('Maikrosoft', 'x'),
    'apple': ('Äppel', 'x'), 'amazon': ('Ämason', 'x'), 'searxng': ('Sörks En Ge', 'x'), 'playwright': ('Pleirait', 'x'),
}
ENDINGS = ('s', 'e', 'es', 'en', 'er', 'em', 'ern', 'ers', 'n', 'r', 't', 'te', 'ten', 'tet', 'st', 'in', 'innen', 'erin', 'erinnen')


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


def _match_case(original: str, spoken: str) -> str:
    return (spoken[:1].upper() if original[:1].isupper() else spoken[:1].lower()) + spoken[1:]


def _attach(spoken: str, key: str, ending: str) -> str:
    """Respelling plus a German ending: updaten -> apdeiten, Caches -> Käsches, chatten -> tschätten."""
    if key.endswith('e') and ending in ('n', 'r', 't', 'te', 'ten', 'tet', 'st'):
        ending = 'e' + ending
    if ending == 's' and re.search(r'(?:s|ß|sch)$', spoken):
        ending = 'es'
    if len(spoken) >= 2 and spoken[-1] == spoken[-2] and ending[:1] == spoken[-1]:
        ending = ending[1:]
    return spoken + ending


def respell(word: str) -> str:
    """An English word the German way (PRONUNCIATION); any other word unchanged."""
    lower = word.lower()
    for size in range(len(lower), 1, -1):
        entry = PRONUNCIATION.get(lower[:size])
        if not entry:
            continue
        spoken, kind = entry
        key, rest = lower[:size], lower[size:]
        if rest[:1] == key[-1:] and rest[1:] in ENDINGS:  # debuggen, chatten: English doubles the last letter
            rest = rest[1:]
        if not rest:
            return _match_case(word, spoken)
        if kind == 'x':
            return _match_case(word, _attach(spoken, key, 's')) if rest == 's' else word
        if rest in ENDINGS:
            return _match_case(word, _attach(spoken, key, rest))
        if kind == 'n' and len(rest) >= 3 and word[size:].islower():
            return _match_case(word, spoken) + '-' + word[size:]
        return word
    return word


def _sentence_ends(m: re.Match) -> bool:
    """After a dotted abbreviation: does a new sentence start (end of line, or a typical first word)?"""
    rest = m.string[m.end():]
    following = re.match(r'[ \t]*(\w+)', rest)
    return not rest.strip() or rest.lstrip(' \t').startswith('\n') or bool(following and following.group(1) in SENTENCE_STARTERS)


def _amount(value: int, one: str, many: str) -> str:
    return f'eine {one}' if value == 1 else f'{cardinal(value)} {many}'


def _clock_words(hours: int, minutes: int) -> str:
    return ('ein' if hours == 1 else cardinal(hours)) + ' Uhr' + (' ' + cardinal(minutes) if minutes else '')


_MONTH_RE = '|'.join(MONTHS)
_PREFIX_RE = r'(?P<pre>\b(?i:am|vom|zum|den|dem|im|ab|seit|bis|nach|vor)\s+)?'
_EXT_RE = '|'.join(sorted(EXTENSIONS, key=len, reverse=True))


def prepare(text: str) -> str:
    """The text as a German speaker says it."""
    out = text
    for pattern, words in ABBREVIATIONS:
        out = re.sub(pattern, lambda m, words=words: words + ('.' if m.group(0).endswith('.') and _sentence_ends(m) else ''), out)
    out = re.sub(r'\s&\s', ' und ', out)
    out = re.sub(r'\b[Ee]-[Mm]ail', 'Imeil', out)
    out = re.sub(r'\b[Kk]now-[Hh]ow\b', 'Nouhau', out)

    def dotted(m: re.Match) -> str:
        raw = m.group(0)
        letters = raw.replace('.', '')
        if len(letters) == 2 and not letters.isupper():
            return raw
        spoken = DOTTED.get(letters.upper()) or ' '.join(LETTERS.get(c.lower(), c) for c in letters)
        return spoken + ('.' if raw.endswith('.') and _sentence_ends(m) else '')
    out = re.sub(r'(?<![\w.])(?:[A-Za-zÄÖÜäöü]\.){2,}(?:[A-Za-zÄÖÜäöü](?![\w.]))?', dotted, out)

    def file_name(m: re.Match) -> str:
        name = re.sub(r'[_-]+', ' ', m.group(1))
        return f'{name} Punkt {_spell_extension(m.group(2))}'
    out = re.sub(rf'\b([A-Za-z0-9][A-Za-z0-9_-]*)\.({_EXT_RE})\b', file_name, out, flags=re.IGNORECASE)
    out = re.sub(rf"\b([A-Za-z0-9][A-Za-z0-9-]*)\.({'|'.join(DOMAINS)})\b(?!\.\w)",
                 lambda m: f'{m.group(1)} Punkt {DOMAINS[m.group(2).lower()]}', out, flags=re.IGNORECASE)

    def zone(m: re.Match) -> str:
        spoken = TIME_ZONES[m.group('z')]
        if m.group('sign'):
            spoken += (' plus ' if m.group('sign') == '+' else ' minus ') + cardinal(int(m.group('oh')))
            if m.group('om') and int(m.group('om')):
                spoken += ' ' + cardinal(int(m.group('om')))
        return spoken
    out = re.sub(rf"\b(?P<z>{'|'.join(TIME_ZONES)})\b(?:\s*(?P<sign>[+-])\s*(?P<oh>\d{{1,2}})(?::?(?P<om>\d{{2}}))?\b)?", zone, out)

    def iso_date(m: re.Match) -> str:
        yr, month, day = int(m.group('y')), int(m.group('m')), int(m.group('d'))
        if not (1 <= day <= 31 and 1 <= month <= 12):
            return m.group(0)
        return f"{m.group('pre') or ''}{ordinal(day, _ending(m.group('pre')))} {MONTHS[month - 1]} {year(yr)}"
    out = re.sub(_PREFIX_RE + r'\b(?P<y>\d{4})-(?P<m>\d{2})-(?P<d>\d{2})\b', iso_date, out)

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

    def short_date(m: re.Match) -> str:
        day, month = int(m.group('d')), int(m.group('m'))
        if not (1 <= day <= 31 and 1 <= month <= 12):
            return m.group(0)
        return f"{m.group('pre')}{ordinal(day, _ending(m.group('pre')))} {MONTHS[month - 1]}"
    out = re.sub(r'(?P<pre>\b(?i:am|vom|zum|den|dem|ab|seit|bis|der|heute)\s+)(?P<d>\d{1,2})\.(?P<m>\d{1,2})\.(?!\d)', short_date, out)

    def time_range(m: re.Match) -> str:
        h1, m1, h2, m2 = int(m.group('h1')), int(m.group('m1') or 0), int(m.group('h2')), int(m.group('m2') or 0)
        with_uhr = bool(m.group('uhr'))
        if max(h1, h2) > 24 or max(m1, m2) > 59 or not (with_uhr or m.group('m1') or m.group('m2')):
            return m.group(0)
        if '.' in m.group(0) and not with_uhr:
            return m.group(0)
        if with_uhr and not m.group('m1') and not m.group('m2'):
            return f'{cardinal(h1)} bis {_clock_words(h2, 0)}'
        return f'{_clock_words(h1, m1)} bis {_clock_words(h2, m2)}'
    out = re.sub(r'(?<![\d.:])(?P<h1>\d{1,2})(?:[:.](?P<m1>\d{2}))?\s*[-\u2013\u2014]\s*(?P<h2>\d{1,2})(?:[:.](?P<m2>\d{2}))?(?![\d:])(?P<uhr>\s*Uhr\b)?',
                 time_range, out)

    def clock(m: re.Match) -> str:
        hours, minutes = int(m.group('h')), int(m.group('min') or 0)
        if hours > 24 or minutes > 59:
            return m.group(0)
        return _clock_words(hours, minutes)
    out = re.sub(r'\b(?P<h>\d{1,2})(?:[:.](?P<min>\d{2}))?\s*Uhr\b', clock, out)
    out = re.sub(r'\b(?P<um>[Uu]m)\s+(?P<h>\d{1,2}):(?P<min>\d{2})\b', lambda m: m.group('um') + ' ' + clock(m), out)

    # 1:02:30 is a duration (Laufzeit), 2:30 min too.
    def duration(m: re.Match) -> str:
        hours, minutes, secs = int(m.group('h') or 0), int(m.group('min')), int(m.group('s') or 0)
        if minutes > 59 or secs > 59:
            return m.group(0)
        spoken = [_amount(v, one, many) for v, one, many in ((hours, 'Stunde', 'Stunden'), (minutes, 'Minute', 'Minuten'),
                                                              (secs, 'Sekunde', 'Sekunden')) if v]
        said = (spoken[0] if len(spoken) == 1 else ', '.join(spoken[:-1]) + ' und ' + spoken[-1]) if spoken else 'null Sekunden'
        return said + ('.' if m.groupdict().get('dot') and _sentence_ends(m) else '')
    out = re.sub(r'(?<![\d:])(?P<h>\d{1,2}):(?P<min>\d{2}):(?P<s>\d{2})(?![\d:])', duration, out)
    out = re.sub(r'(?<![\d:])(?P<min>\d{1,2}):(?P<s>\d{2})\s*(?:min\b(?P<dot>\.)?|Min(?P<dot2>\.)|Minuten\b)(?P<h>)', duration, out)
    out = re.sub(r'(?<=\d)\s*(?:min\b|Min\b)(?P<dot>\.)?', lambda m: ' Minuten' + ('.' if m.group('dot') and _sentence_ends(m) else ''), out)
    out = re.sub(r'(?<=\d)\s*(?:Std\b|h\b)(?P<dot>\.)?', lambda m: ' Stunden' + ('.' if m.group('dot') and _sentence_ends(m) else ''), out)
    out = re.sub(r'(?<![\d:])(?P<h>\d{1,2}):(?P<min>\d{2})(?![\d:])', clock, out)
    # What is left with a colon between numbers is a ratio or a score: 16:9, 2:1.
    out = re.sub(r'(?<![\d:])(\d{1,4}):(\d{1,4})(?![\d:])', lambda m: f'{_number(m.group(1))} zu {_number(m.group(2))}', out)

    def decimal(m: re.Match) -> str:
        whole = int(m.group(1).replace('.', ''))
        return f'{cardinal(whole)} Komma {_digits(m.group(2))}'
    out = re.sub(r'\b(\d{1,3}(?:\.\d{3})+|\d+),(\d+)\b', decimal, out)
    out = re.sub(r'\b(\d{1,3}(?:\.\d{3})+)\b', lambda m: cardinal(int(m.group(1).replace('.', ''))), out)
    out = re.sub(r'(?<![\d.,:])([1-9]\d{0,3}|0)\s*[-\u2013\u2014]\s*([1-9]\d{0,3}|0)(?!\d|[.,:]\d)',
                 lambda m: f'{_number(m.group(1))} bis {_number(m.group(2))}', out)

    def version(m: re.Match) -> str:
        spoken = ' Punkt '.join(_number(group) for group in m.group('num').split('.'))
        said = m.group('v') and not re.search(r'Version\s*$', m.string[:m.start()], re.IGNORECASE)
        return ('Version ' if said else '') + spoken
    out = re.sub(r'(?<![\w.])(?P<v>[vV])?(?P<num>\d+(?:\.\d+){1,3})(?!\.?\d)', version, out)
    out = re.sub(r'\b\d+\b', lambda m: _number(m.group(0)), out)
    out = re.sub(r'\s?%', ' Prozent', out)
    out = re.sub(r'\s?€', ' Euro', out)
    out = re.sub(r'\s?°C\b', ' Grad Celsius', out)
    out = re.sub(r'\s?°', ' Grad', out)
    out = re.sub(r'[~≈]\s*(?=\w)', 'circa ', out)
    out = re.sub(r'(?<=\w)@(?=\w)', ' ät ', out)
    # A dot left between lower-case letters or digits is said: game.starlight-unit -> game Punkt starlight-unit.
    out = re.sub(r'(?<=[a-zäöüß0-9])\.(?=[a-zäöüß0-9])', ' Punkt ', out)
    out = re.sub(r'[A-Za-zÄÖÜäöüß]+', lambda m: respell(m.group(0)), out)
    # Dashes and arrows between words are a short pause, not a read sign.
    out = re.sub(r'\s*(?:->|=>|→|⇒)\s*', ', ', out)
    out = re.sub(r'\s+[-\u2013\u2014]\s+|(?<=\w)[\u2013\u2014](?=\w)', ', ', out)
    return re.sub(r'[ \t]{2,}', ' ', out)
