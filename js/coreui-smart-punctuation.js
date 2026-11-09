/* CoreAI: TCI Interface 0.6.80: intelligente Zeichensetzung im Chat-Eingabefeld.
   Settings > Darstellung > Erweitert switches it on (preference smartPunctuation, off by default).
   Only while typing, character by character: a paste, an IME composition, inline `code`,
   a ```code block``` and a URL are never touched. Backspace right after a replacement
   brings back exactly what was typed.

     "  ->  „ … “ (German) or “ … ” (English)      '  ->  ‚ … ‘ / ’ (German), ‘ … ’ (English)
     ... ->  …        -- -> –  (not at the start of a line, so a Markdown --- stays)
     -> ->  →   <- -> ←   <-> -> ↔   => -> ⇒
     <= ->  ≤   >= -> ≥   != -> ≠   ~= -> ≈   +- -> ±                                        */
(function () {
  'use strict';

  var QUOTES = {
    de: {open2: '„', close2: '“', open1: '‚', close1: '‘'},
    en: {open2: '“', close2: '”', open1: '‘', close1: '’'}
  };
  var APOSTROPHE = '’';
  // After these (or at the start) a quote opens.
  var OPENERS = /[\s([{<„“‚‘«‹–\u2014\/-]/;

  function lineOf(before) {
    var at = before.lastIndexOf('\n');
    return at < 0 ? before : before.slice(at + 1);
  }

  /** Inside a ```code block```, inline `code` or a URL (pure). */
  function inCode(before) {
    var fences = before.match(/^[ \t]*```/gm);
    if (fences && fences.length % 2 === 1) return true;
    var line = lineOf(before).replace(/```/g, '');
    if ((line.match(/`/g) || []).length % 2 === 1) return true;
    var word = before.slice(Math.max(before.lastIndexOf(' '), before.lastIndexOf('\n'), before.lastIndexOf('\t')) + 1);
    return /:\/\//.test(word) || /^www\./i.test(word);
  }

  /**
   * The replacement for the character just typed (pure). `before` is the text up to and
   * including that character. Returns {remove, insert} (characters to take away at the end of
   * `before` and what to put there) or null.
   */
  function replacement(before, lang) {
    if (!before || inCode(before.slice(0, -1))) return null;
    var q = QUOTES[lang === 'en' ? 'en' : 'de'];
    var typed = before.slice(-1);
    var prev = before.slice(-2, -1);
    var prev2 = before.slice(-3, -2);
    var opening = prev === '' || OPENERS.test(prev);
    switch (typed) {
      case '"':
        return {remove: 1, insert: opening ? q.open2 : q.close2};
      case "'": {
        if (opening) return {remove: 1, insert: q.open1};
        if (lang !== 'en') {
          // German: a closing ‘ only when this line has an open ‚ before; else an apostrophe.
          var line = lineOf(before.slice(0, -1));
          var lastOpen = line.lastIndexOf(q.open1);
          if (lastOpen >= 0 && line.indexOf(q.close1, lastOpen) < 0) return {remove: 1, insert: q.close1};
        }
        return {remove: 1, insert: APOSTROPHE};
      }
      case '.':
        return before.slice(-3) === '...' && before.slice(-4, -3) !== '.' ? {remove: 3, insert: '…'} : null;
      case '-':
        if (prev === '<') return {remove: 2, insert: '←'};
        if (prev === '+') return {remove: 2, insert: '±'};
        if (prev === '-' && prev2 !== '-' && /\S/.test(lineOf(before.slice(0, -2)))) {
          return {remove: 2, insert: '–'};
        }
        return null;
      case '>':
        if (prev === '-' || prev === '–') return {remove: 2, insert: '→'};
        if (prev === '=') return {remove: 2, insert: '⇒'};
        if (prev === '←') return {remove: 2, insert: '↔'};
        return null;
      case '=':
        if (prev === '<') return {remove: 2, insert: '≤'};
        if (prev === '>') return {remove: 2, insert: '≥'};
        if (prev === '!') return {remove: 2, insert: '≠'};
        if (prev === '~') return {remove: 2, insert: '≈'};
        return null;
      default:
        return null;
    }
  }

  function interfaceLang() {
    var root = document.documentElement;
    var lang = root.getAttribute('data-coreui-ui-lang') || root.getAttribute('lang') || 'de';
    return /^en/i.test(lang) ? 'en' : 'de';
  }

  function enabled() {
    try {
      return !!(window.CoreUIPreferences && window.CoreUIPreferences.load().smartPunctuation);
    } catch (error) {
      return false;
    }
  }

  /** Watches one text field. Returns a function that stops watching. */
  function attach(field, options) {
    options = options || {};
    var isOn = typeof options.enabled === 'function' ? options.enabled : enabled;
    var langOf = typeof options.lang === 'function' ? options.lang : interfaceLang;
    var last = null; // the last replacement, for Backspace

    function onInput(event) {
      last = null;
      if (!event || event.inputType !== 'insertText' || event.isComposing) return;
      if (typeof event.data !== 'string' || event.data.length !== 1 || !isOn()) return;
      var caret = field.selectionStart;
      if (caret !== field.selectionEnd || caret < 1) return;
      var before = field.value.slice(0, caret);
      var hit = replacement(before, langOf());
      if (!hit) return;
      var start = caret - hit.remove;
      var original = before.slice(start);
      field.setRangeText(hit.insert, start, caret, 'end');
      last = {start: start, insert: hit.insert, original: original, value: field.value};
    }

    function onBeforeInput(event) {
      if (!last || !event || event.inputType !== 'deleteContentBackward') return;
      var end = last.start + last.insert.length;
      if (field.value !== last.value || field.selectionStart !== end || field.selectionEnd !== end) {
        last = null;
        return;
      }
      event.preventDefault();
      var restore = last;
      last = null;
      field.setRangeText(restore.original, restore.start, end, 'end');
      // Let the chat resize and update its send button like after any edit.
      field.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function forget() { last = null; }

    field.addEventListener('input', onInput);
    field.addEventListener('beforeinput', onBeforeInput);
    field.addEventListener('blur', forget);
    field.addEventListener('pointerdown', forget);
    return function detach() {
      field.removeEventListener('input', onInput);
      field.removeEventListener('beforeinput', onBeforeInput);
      field.removeEventListener('blur', forget);
      field.removeEventListener('pointerdown', forget);
    };
  }

  window.CoreAISmartPunctuation = Object.freeze({replacement: replacement, inCode: inCode, attach: attach});

  var input = document.getElementById('inputMessage');
  if (input) attach(input);
})();
