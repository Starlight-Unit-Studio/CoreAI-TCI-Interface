/* CoreAI 0.6.71: one responsibility, evaluate the composer send shortcut.
   No storage or network logic here; CoreUIPreferences owns persistence. */
(function (root) {
  'use strict';
  function mode(preferences) {
    return preferences && preferences.sendKey === 'mod-enter' ? 'mod-enter' : 'enter';
  }
  function shouldSend(event, preferences) {
    if (!event || event.key !== 'Enter' || event.isComposing || event.keyCode === 229
        || event.altKey || event.shiftKey || event.repeat) return false;
    return mode(preferences) === 'mod-enter'
      ? !!(event.ctrlKey || event.metaKey)
      : !(event.ctrlKey || event.metaKey);
  }
  function hint(preferences) {
    return mode(preferences) === 'mod-enter'
      ? 'STRG/⌘+ENTER SENDEN · ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD'
      : 'ENTER SENDEN · SHIFT+ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD';
  }
  root.CoreUISendKeys = Object.freeze({mode: mode, shouldSend: shouldSend, hint: hint});
})(typeof window !== 'undefined' ? window : this);
