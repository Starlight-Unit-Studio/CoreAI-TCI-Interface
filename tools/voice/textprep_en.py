"""CoreAI 0.6.8: English text preparation before Eve speaks English (P20.3b), standard library only.

The operator's listening test (04.11.2026) found the English model reads numbers,
dates, times and file names perfectly on its own, so this stays small. It only
handles what broke the German voice and is likely to break this one, too:

  E.M.B.E.R.            Ember            (other dotted letters: U.S.A. -> U S A)
  e.g., i.e., etc.      for example, that is, et cetera
  12:00-20:00 CET       12:00 to 20:00 Central European Time
  3-5                   3 to 5
  " - ", arrows         a comma (a short pause instead of a read sign)
"""
from __future__ import annotations

import re

VERSION = 'en-1'

DOTTED = {'EMBER': 'Ember'}
ABBREVIATIONS = [
    (r'\be\.\s?g\.', 'for example'), (r'\bi\.\s?e\.', 'that is'), (r'\betc\.', 'et cetera'), (r'\bvs\.', 'versus'),
    (r'\bapprox\.', 'approximately'), (r'\bDr\.', 'Doctor'), (r'\bMr\.', 'Mister'), (r'\bMrs\.', 'Missus'),
]
TIME_ZONES = {'CET': 'Central European Time', 'CEST': 'Central European Summer Time', 'MEZ': 'Central European Time',
              'MESZ': 'Central European Summer Time', 'UTC': 'U T C', 'GMT': 'G M T'}


def prepare(text: str) -> str:
    """The text as an English speaker says it, where the voice needs help."""
    out = text
    for pattern, words in ABBREVIATIONS:
        out = re.sub(pattern, words, out)

    def dotted(m: re.Match) -> str:
        raw = m.group(0)
        letters = raw.replace('.', '')
        if len(letters) == 2 and not letters.isupper():
            return raw
        spoken = DOTTED.get(letters.upper()) or ' '.join(letters.upper())
        rest = m.string[m.end():]
        ends = raw.endswith('.') and (not rest.strip() or re.match(r'\s+[A-Z]', rest) is not None and len(letters) > 2)
        return spoken + ('.' if ends else '')
    out = re.sub(r'(?<![\w.])(?:[A-Za-z]\.){2,}(?:[A-Za-z](?![\w.]))?', dotted, out)
    out = re.sub(rf"\b({'|'.join(TIME_ZONES)})\b", lambda m: TIME_ZONES[m.group(1)], out)
    # Ranges of times or numbers: 12:00-20:00, 9-17, 3 - 5.
    out = re.sub(r'(?<![\d.:])(\d{1,2}(?::\d{2})?)\s*[-\u2013\u2014]\s*(\d{1,2}(?::\d{2})?)(?![\d:])', r'\1 to \2', out)
    out = re.sub(r'(?<![\d.,:])([1-9]\d{0,3})\s*[-\u2013\u2014]\s*([1-9]\d{0,3})(?!\d|[.,:]\d)', r'\1 to \2', out)
    out = re.sub(r'\s*(?:->|=>|\u2192|\u21d2)\s*', ', ', out)
    out = re.sub(r'\s+[-\u2013\u2014]\s+|(?<=\w)[\u2013\u2014](?=\w)', ', ', out)
    return re.sub(r'[ \t]{2,}', ' ', out)
