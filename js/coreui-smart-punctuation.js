/* CoreAI 0.6.79: optional safe punctuation while typing natural-language
 * prompts. Never alters pasted text, files, code fences, inline code or
 * existing message history. Off by default for developer workflows.
 */
(function (root) {
  'use strict';
  function transformed(text, at, insert, lang) {
    if (!insert || at < 1 || at > text.length) return null;
    var before = text.slice(0, at);
    var line = before.slice(before.lastIndexOf('\n') + 1);
    var prefix = before.slice(0, before.lastIndexOf('\n') + 1);
    // Never silently mutate code, JSON, URLs, Markdown links or backtick spans.
    if (/^\s*(?:```|~~~| {4,}|\t)/m.test(line) ||
        /(?:https?:\/\/|www\.|\{[^\n]*:|\[[^\n]*\]\(|\b(?:const|let|var|function|class|def|import|SELECT)\b)/i.test(line) ||
        (before.match(/`/g) || []).length % 2 === 1 ||
        ((prefix.match(/^`{3}/gm) || []).length % 2 === 1)) return null;
    var replace = [
      [/\.\.\.$/, '…'],
      [/->$/, '→'],
      [/<-$/, '←'],
      [/!=$/, '≠'],
      [/>=$/, '≥'],
      [/<=$/, '≤'],
      [/\+\-$/, '±']
    ];
    for (var i=0; i<replace.length; i++) {
      var match = before.match(replace[i][0]);
      if (match) return {start:at-match[0].length,end:at,value:replace[i][1]};
    }
    // Convert a complete quoted natural-language phrase only when the
    // user types the closing quote; do not auto-format unpaired quotes.
    if (insert === '"' && !/[\[\]{};=]/.test(line)) {
      var quote = before.match(/(^|[\s(])"([^"\n]{1,120})"$/);
      if (quote) {
        var start = at - quote[0].length + quote[1].length;
        return {start:start,end:at,value:(lang === 'en' ? '“' : '„') + quote[2] + (lang === 'en' ? '”' : '“')};
      }
    }
    return null;
  }
  function attach(doc) {
    var el = doc && doc.getElementById('inputMessage');
    if (!el || !root.CoreUIPreferences) return false;
    el.addEventListener('input', function (event) {
      if (!event || event.inputType !== 'insertText' || event.isComposing || !event.data) return;
      if (!root.CoreUIPreferences.load().appearance.smartPunctuation) return;
      if (el.selectionStart !== el.selectionEnd) return;
      var pos = el.selectionStart;
      var change = transformed(el.value, pos, event.data,
        doc.documentElement.getAttribute('data-coreui-ui-lang'));
      if (!change) return;
      el.setRangeText(change.value, change.start, change.end, 'end');
      // Refresh existing composer counters without starting any new messages.
      if (typeof root.Event === 'function') {
        el.dispatchEvent(new root.Event('input', {bubbles:true}));
      }
    });
    return true;
  }
  root.CoreUISmartPunctuation = {attach:attach, transform:transformed};
  if (root.document) {
    if (root.document.readyState === 'loading') {
      root.document.addEventListener('DOMContentLoaded', function () { attach(root.document); });
    } else attach(root.document);
  }
})(window);
