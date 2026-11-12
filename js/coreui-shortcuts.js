/* CoreAI 0.7.29: Settings > Tastatur after the reference screenshots.
   One responsibility: the shortcuts themselves (which actions exist, their default keys, recording a
   key combination, checking it, matching a key press, keeping the choice in this browser).
   The chat (js/coreui-shortcuts-app.js) runs the actions, the settings page edits them.

   A combination is a string like "Mod+Shift+O": modifiers in a fixed order (Mod, Meta, Alt, Shift),
   then the key. Mod is the main modifier of the device (Strg on Windows and Linux, ⌘ on a Mac),
   Meta the other one (Super/Win, ⌃ on a Mac). "" means no key ("Keine"). */
(function (root) {
  'use strict';

  var STORAGE_KEY = 'coreai.shortcuts.v1';
  var nav = root.navigator || {};
  var platform = String((nav.userAgentData && nav.userAgentData.platform) || nav.platform || '');
  var IS_MAC = /mac|iphone|ipad|ipod/i.test(platform);
  var IS_WINDOWS = /win/i.test(platform);

  var SECTIONS = Object.freeze([
    Object.freeze({id: 'editor', title: 'Nachrichteneditor'}),
    Object.freeze({id: 'chat', title: 'Chat'}),
    Object.freeze({id: 'app', title: 'App'})
  ]);
  // planned: shown as "In Vorbereitung", without a key and without a switch (CoreAI cannot do it yet).
  var ACTIONS = Object.freeze([
    {id: 'focus', section: 'editor', label: 'Eingabefeld fokussieren', keys: 'Shift+Escape'},
    {id: 'send', section: 'editor', label: 'Nachricht senden', keys: 'Enter'},
    {id: 'background', section: 'editor', label: 'Nachricht im Hintergrund senden', planned: true},
    {id: 'effort', section: 'editor', label: 'Denken auswählen', keys: 'Mod+Shift+M'},
    {id: 'dictation', section: 'editor', label: 'Diktierfunktion ein-/ausschalten', keys: ''},
    {id: 'attach', section: 'editor', label: 'Dateien anhängen', keys: 'Mod+U'},
    {id: 'chatDelete', section: 'chat', label: 'Chat löschen', keys: 'Mod+Shift+Backspace'},
    {id: 'chatSearch', section: 'chat', label: 'Im Chat suchen', keys: 'Mod+Shift+F'},
    {id: 'newChat', section: 'app', label: 'Neuer Chat', keys: 'Mod+Shift+O'},
    {id: 'help', section: 'app', label: 'Tastenkürzel anzeigen', keys: 'Mod+/'},
    {id: 'notes', section: 'app', label: 'Anmerkungen senden', planned: true},
    {id: 'switchChat', section: 'app', label: 'Chat wechseln …', keys: 'Mod+K'},
    {id: 'sidebar', section: 'app', label: 'Seitenleiste ein/aus', keys: 'Mod+Shift+S'}
  ].map(function (a) { return Object.freeze(a); }));
  var BY_ID = {};
  ACTIONS.forEach(function (a) { BY_ID[a.id] = a; });

  var MODIFIERS = ['Mod', 'Meta', 'Alt', 'Shift'];
  var NAMED = ['Enter', 'Escape', 'Backspace', 'Delete', 'Tab', 'Space', 'Plus', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
    'Home', 'End', 'PageUp', 'PageDown', 'Insert'];
  var IGNORED = ['Shift', 'Control', 'Alt', 'Meta', 'AltGraph', 'CapsLock', 'OS', 'Fn', 'FnLock', 'Hyper', 'Super', 'NumLock',
    'ScrollLock', 'Dead', 'Unidentified', 'Process', 'Compose'];
  // The browser keeps these for itself (a page never sees them) or they are needed for editing text.
  var BROWSER = ['Mod+W', 'Mod+T', 'Mod+N', 'Mod+Q', 'Mod+Shift+N', 'Mod+Shift+T', 'Mod+Shift+W', 'Mod+Tab', 'Mod+Shift+Tab', 'Alt+F4',
    'Mod+L', 'Mod+R', 'Mod+Shift+R', 'F5', 'F11', 'F12'];
  var EDITING = ['Mod+C', 'Mod+V', 'Mod+X', 'Mod+A', 'Mod+Z', 'Mod+Y', 'Mod+Shift+Z', 'Mod+Shift+V', 'Mod+B', 'Mod+I',
    'Mod+Backspace', 'Mod+Delete', 'Mod+ArrowLeft', 'Mod+ArrowRight', 'Mod+Home', 'Mod+End'];

  function isFunctionKey(key) { return /^F([1-9]|1[0-9]|2[0-4])$/.test(key); }
  function isSymbol(key) {
    // One printed character that is neither a letter nor a digit: its Shift is part of the character.
    return key.length === 1 && !/[0-9]/.test(key) && key.toUpperCase() === key.toLowerCase();
  }

  /** The key of a key press in our notation, or null (a modifier alone, a dead key, IME). */
  function keyOf(event) {
    var k = event && typeof event.key === 'string' ? event.key : '';
    if (!k || IGNORED.indexOf(k) >= 0) return null;
    if (k === ' ' || k === 'Spacebar') return 'Space';
    if (k === '+') return 'Plus';
    if (k === 'Esc') return 'Escape';
    if (k === 'Del') return 'Delete';
    var code = String(event.code || '');
    if (k.length === 1) {
      // Option/AltGr turn letters into other characters (⌥U on a Mac): the printed key counts.
      if (event.altKey && !/[0-9A-Za-zÄÖÜäöü]/.test(k)) {
        var letter = /^Key([A-Z])$/.exec(code);
        if (letter) return letter[1];
        var digit = /^Digit([0-9])$/.exec(code);
        if (digit) return digit[1];
      }
      var up = k.toUpperCase();
      return up.length === 1 ? up : k; // ß stays ß
    }
    if (NAMED.indexOf(k) >= 0 || isFunctionKey(k)) return k;
    return null;
  }

  /** "Mod+Shift+O" for a key press (null while only modifiers are held). */
  function fromEvent(event) {
    var key = keyOf(event);
    if (!key) return null;
    var parts = [];
    if (IS_MAC ? event.metaKey : event.ctrlKey) parts.push('Mod');
    if (IS_MAC ? event.ctrlKey : event.metaKey) parts.push('Meta');
    if (event.altKey) parts.push('Alt');
    if (event.shiftKey && !isSymbol(key)) parts.push('Shift');
    parts.push(key);
    return parts.join('+');
  }

  /** A well-formed combination ("" included), or null. */
  function parse(combo) {
    if (combo === '') return '';
    if (typeof combo !== 'string' || combo.length > 40) return null;
    var parts = combo.split('+');
    var key = parts.pop();
    if (!key || (key.length !== 1 && NAMED.indexOf(key) < 0 && !isFunctionKey(key))) return null;
    if (key.length === 1 && key.toUpperCase().length === 1 && key !== key.toUpperCase()) return null;
    var last = -1;
    for (var i = 0; i < parts.length; i++) {
      var at = MODIFIERS.indexOf(parts[i]);
      if (at <= last) return null; // unknown, repeated or out of order
      last = at;
    }
    if (isSymbol(key) && parts.indexOf('Shift') >= 0) return null;
    return combo;
  }

  var NAMES_PC = {Mod: 'Strg', Meta: IS_WINDOWS ? 'Win' : 'Super', Alt: 'Alt', Shift: 'Shift'};
  var NAMES_MAC = {Mod: '⌘', Meta: '⌃', Alt: '⌥', Shift: '⇧'};
  var KEY_NAMES = {Escape: 'Esc', Backspace: 'Rücktaste', Delete: 'Entf', Space: 'Leertaste', Plus: '+', Enter: 'Enter',
    ArrowUp: '↑', ArrowDown: '↓', ArrowLeft: '←', ArrowRight: '→', PageUp: 'Bild ↑', PageDown: 'Bild ↓', Home: 'Pos1', End: 'Ende',
    Insert: 'Einfg', Tab: 'Tab'};
  /** "Strg+Shift+O" (⌘⇧O on a Mac), "Keine" for none. */
  function label(combo) {
    if (!combo) return 'Keine';
    var parts = String(combo).split('+');
    var key = parts.pop();
    var names = IS_MAC ? NAMES_MAC : NAMES_PC;
    var out = parts.map(function (p) { return names[p] || p; });
    out.push(KEY_NAMES[key] || key);
    return out.join(IS_MAC ? '' : '+');
  }

  // --- Storage ----------------------------------------------------------------------------------------------
  function storage() { try { return root.localStorage || null; } catch (e) { return null; } }
  function legacySend() {
    // Until 0.7.28 the page only knew "Enter sendet" or "Strg/⌘ + Enter sendet" (CoreUIPreferences.sendKey).
    try {
      var prefs = root.CoreUIPreferences && root.CoreUIPreferences.load();
      return prefs && prefs.sendKey === 'mod-enter' ? 'Mod+Enter' : 'Enter';
    } catch (e) { return 'Enter'; }
  }
  function defaults() {
    var out = {};
    ACTIONS.forEach(function (a) {
      if (a.planned) return;
      out[a.id] = {on: true, keys: a.id === 'send' ? legacySend() : a.keys};
    });
    return out;
  }
  function readStored() {
    var s = storage();
    if (!s) return {};
    try {
      var data = JSON.parse(s.getItem(STORAGE_KEY) || 'null');
      return data && data.v === 1 && data.items && typeof data.items === 'object' ? data.items : {};
    } catch (e) { return {}; }
  }
  /** Every action that CoreAI can run: {id: {on, keys}}. Broken stored values fall back to the default. */
  function load() {
    var base = defaults();
    var stored = readStored();
    Object.keys(base).forEach(function (id) {
      var item = stored[id];
      if (!item || typeof item !== 'object') return;
      if (typeof item.on === 'boolean') base[id].on = item.on;
      if (typeof item.keys === 'string' && parse(item.keys) !== null) base[id].keys = item.keys;
    });
    return base;
  }
  function emit(map) {
    try { root.dispatchEvent(new root.CustomEvent('coreai:shortcuts', {detail: map})); } catch (e) {}
  }
  function syncLegacy(map) {
    // The composer hint and older code read CoreUIPreferences.sendKey; keep it in step.
    var prefs = root.CoreUIPreferences;
    if (!prefs) return;
    try {
      var current = prefs.load();
      var wanted = map.send && map.send.on && map.send.keys === 'Mod+Enter' ? 'mod-enter' : 'enter';
      if (current.sendKey !== wanted) prefs.save(Object.assign({}, current, {sendKey: wanted}));
    } catch (e) {}
  }
  function write(map) {
    // Only what differs from the defaults is kept; the send key always (it replaces the old sendKey).
    var items = {};
    var base = defaults();
    Object.keys(base).forEach(function (id) {
      var v = map[id];
      if (!v) return;
      if (id === 'send' || v.on !== base[id].on || v.keys !== base[id].keys) items[id] = {on: v.on, keys: v.keys};
    });
    var s = storage();
    if (s) {
      try { s.setItem(STORAGE_KEY, JSON.stringify({v: 1, items: items})); } catch (e) {}
    }
    syncLegacy(map);
    var fresh = load();
    emit(fresh);
    return fresh;
  }

  // --- Checks -----------------------------------------------------------------------------------------------
  /** '' when the combination may be used for the action, otherwise an error code (taken:<id> for a clash). */
  function check(id, combo, map) {
    var action = BY_ID[id];
    if (!action || action.planned) return 'unknown';
    if (combo === '') return '';
    if (parse(combo) === null) return 'invalid';
    var parts = combo.split('+');
    var key = parts[parts.length - 1];
    var mods = parts.slice(0, -1);
    var strong = mods.indexOf('Mod') >= 0 || mods.indexOf('Meta') >= 0 || mods.indexOf('Alt') >= 0;
    if (BROWSER.indexOf(combo) >= 0) return 'browser';
    if (EDITING.indexOf(combo) >= 0) return 'editing';
    if (!(id === 'send' && key === 'Enter')) {
      if (key === 'Escape' && !mods.length) return 'escape';
      if (!strong && key !== 'Escape' && !isFunctionKey(key)) return 'modifier';
    }
    var current = map || load();
    for (var other in current) {
      if (other !== id && current[other].on && current[other].keys === combo) return 'taken:' + other;
    }
    return '';
  }
  function message(code) {
    var mod = IS_MAC ? '⌘' : 'Strg';
    if (code.indexOf('taken:') === 0) {
      var other = BY_ID[code.slice(6)];
      return 'Schon belegt: „' + (other ? other.label : code.slice(6)) + '“. Ändere zuerst dort die Tasten.';
    }
    return {
      modifier: 'Kombiniere die Taste mit ' + mod + ', Alt oder ' + (IS_MAC ? '⌃' : NAMES_PC.Meta) + ', sonst stört das Kürzel beim Schreiben.',
      escape: 'Esc allein schließt Fenster und Menüs. Kombiniere Esc zum Beispiel mit Shift.',
      browser: 'Diese Kombination gehört dem Browser.',
      editing: 'Diese Kombination brauchst du zum Bearbeiten von Text.',
      invalid: 'Diese Tasten lassen sich nicht als Kürzel verwenden.',
      unknown: 'Dieses Kürzel gibt es nicht.'
    }[code] || 'Diese Tasten lassen sich nicht als Kürzel verwenden.';
  }

  /** Changes one action ({on} and/or {keys}); returns {ok, error, message, map}. */
  function set(id, patch) {
    var map = load();
    if (!map[id]) return {ok: false, error: 'unknown', message: message('unknown'), map: map};
    var next = {on: map[id].on, keys: map[id].keys};
    if (patch && typeof patch.on === 'boolean') next.on = patch.on;
    if (patch && typeof patch.keys === 'string') next.keys = patch.keys;
    var probe = Object.assign({}, map);
    probe[id] = next;
    // Switching an action back on, or giving it new keys, must not clash with another one.
    if (next.on && next.keys) {
      var error = check(id, next.keys, probe);
      if (error) return {ok: false, error: error, message: message(error), map: map};
    }
    map[id] = next;
    return {ok: true, error: '', message: '', map: write(map)};
  }
  function reset() {
    var s = storage();
    if (s) { try { s.removeItem(STORAGE_KEY); } catch (e) {} }
    // Back to the defaults means Enter sends again.
    var map = defaults();
    map.send.keys = 'Enter';
    return write(map);
  }

  // --- Matching ---------------------------------------------------------------------------------------------
  /** The action of a key press ('' for none). The send key belongs to the chat input and is not reported here. */
  function match(event, map) {
    if (!event || event.isComposing || event.keyCode === 229) return '';
    var combo = fromEvent(event);
    if (!combo) return '';
    var current = map || load();
    for (var id in current) {
      if (id !== 'send' && current[id].on && current[id].keys === combo) return id;
    }
    return '';
  }
  /** Whether a key press in the chat input sends the message. */
  function isSend(event, map) {
    if (!event || event.isComposing || event.keyCode === 229 || event.repeat) return false;
    var send = (map || load()).send;
    return !!(send && send.on && send.keys && fromEvent(event) === send.keys);
  }
  /** The line under the chat input. */
  function sendHint(map) {
    var send = (map || load()).send;
    var tail = ' · LANGER TEXT ALS .MD';
    if (!send || !send.on || !send.keys) return 'SENDEN ÜBER DEN KNOPF · ENTER ZEILENUMBRUCH' + tail;
    if (send.keys === 'Enter') return 'ENTER SENDEN · SHIFT+ENTER ZEILENUMBRUCH' + tail;
    // Any other send key leaves Enter to the text field: it starts a new line.
    return label(send.keys).toUpperCase() + ' SENDEN · ENTER ZEILENUMBRUCH' + tail;
  }

  if (root.addEventListener) {
    root.addEventListener('storage', function (event) {
      if (event.key === STORAGE_KEY || event.key === null) emit(load());
    });
  }

  root.CoreAIShortcuts = Object.freeze({
    key: STORAGE_KEY,
    isMac: IS_MAC,
    sections: SECTIONS,
    actions: ACTIONS,
    action: function (id) { return BY_ID[id] || null; },
    defaults: defaults,
    load: load,
    set: set,
    reset: reset,
    fromEvent: fromEvent,
    parse: parse,
    check: check,
    message: message,
    label: label,
    match: match,
    isSend: isSend,
    sendHint: sendHint
  });
})(typeof window !== 'undefined' ? window : this);
