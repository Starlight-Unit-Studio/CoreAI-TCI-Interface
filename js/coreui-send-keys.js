/* CoreAI 0.6.71: one responsibility, evaluate the composer send shortcut (0.7.29: through CoreAIShortcuts).
   No storage or network logic here; CoreUIPreferences owns persistence. */
(function (root) {
  'use strict';
  function mode(preferences) {
    return preferences && preferences.sendKey === 'mod-enter' ? 'mod-enter' : 'enter';
  }
  // 0.7.29: with Settings > Tastatur (js/coreui-shortcuts.js) the send key is a shortcut like any other;
  // the two old modes stay the fallback when that module is not loaded.
  function shortcuts() { return root.CoreAIShortcuts || null; }
  function shouldSend(event, preferences) {
    if (shortcuts()) return shortcuts().isSend(event);
    if (!event || event.key !== 'Enter' || event.isComposing || event.keyCode === 229
        || event.altKey || event.shiftKey || event.repeat) return false;
    return mode(preferences) === 'mod-enter'
      ? !!(event.ctrlKey || event.metaKey)
      : !(event.ctrlKey || event.metaKey);
  }
  function hint(preferences) {
    if (shortcuts()) return shortcuts().sendHint();
    return mode(preferences) === 'mod-enter'
      ? 'STRG/⌘+ENTER SENDEN · ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD'
      : 'ENTER SENDEN · SHIFT+ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD';
  }
  root.CoreUISendKeys = Object.freeze({mode: mode, shouldSend: shouldSend, hint: hint});
})(typeof window !== 'undefined' ? window : this);
