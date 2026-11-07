/* CoreAI: TCI Interface 0.6.43: the + menu of the input field (operator, 07.11.2026).
   The paperclip becomes a + that opens a menu:
     ANHÄNGEN     Fotos, Kamera (devices with a camera), Dateien
     WERKZEUGE    Bild erstellen, Websuche, Recherche; Deep Research (in Vorbereitung)
     KONNEKTOREN  in Vorbereitung (roadmap P14); a later extension registers its entries
                  with CoreAIComposerMenu.registerConnector({id, label, detail, onSelect})
   A tool sets a chip in the input field ("Bild erstellen" with Normal / Groß). On sending,
   the chip becomes a short mark in front of the message ([[bild]], [[bild:gross]],
   [[websuche]], [[recherche]]); the server turns it into Mara's instruction
   (api/console_composer_intent.php), the bubble shows it as a chip again. Entries the
   account may not use (api/composer_tools.php) stay visible, greyed, with the reason. */
(function () {
  'use strict';

  var btn = document.getElementById('btnAttach');
  var input = document.getElementById('fileInput');
  var textarea = document.getElementById('inputMessage');
  var field = textarea ? textarea.closest('.composer-field') : null;
  var frame = btn ? btn.closest('.composer-frame') : null;
  if (!btn || !input || !field || !frame) return;

  var MARK_RE = /^\s*\[\[(bild(?::gross)?(?::[124])?|websuche|recherche)\]\]\s*/;
  var MARKS = { image: '[[bild]]', imageLarge: '[[bild:gross]]', web: '[[websuche]]', browse: '[[recherche]]' };
  var LABEL = { image: 'Bild erstellen', web: 'Websuche', browse: 'Recherche' };
  var DEFAULT_ACCEPT = input.getAttribute('accept') || '';
  var ICONS = {
    photo: '<rect x="3.5" y="4.5" width="17" height="15" rx="1"/><circle cx="9" cy="10" r="1.8"/><path d="M4 18l5.5-5.5 4 4 2.5-2.5 4.5 4.5"/>',
    camera: '<path d="M4 8h3.2l1.6-2.5h6.4L16.8 8H20v11H4z"/><circle cx="12" cy="13.3" r="3.3"/>',
    file: '<path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    image: '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.5L12 15l-1.8-4.5L5.5 9l4.7-1.4z"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
    web: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.4 3.6 5.2 3.6 8.5s-1 6.1-3.6 8.5c-2.6-2.4-3.6-5.2-3.6-8.5s1-6.1 3.6-8.5z"/>',
    browse: '<rect x="3.5" y="4.5" width="17" height="15"/><path d="M3.5 8.5h17"/><circle cx="11" cy="14" r="2.6"/><path d="M13 16l2.6 2.6"/>',
    deep: '<path d="M4 16l7.5-7.5 2.5 2.5L6.5 18.5z"/><path d="M11.5 8.5l2-2 3.5 3.5-2 2"/><path d="M17 6l2-2M9 20h7M12.5 15.5V20"/>',
    plug: '<path d="M9 3.5v4M15 3.5v4"/><path d="M6.5 7.5h11v3a5.5 5.5 0 0 1-11 0z"/><path d="M12 16v4.5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    close: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>'
  };

  var current = null;      // {tool, size}
  var tools = null;        // answer of api/composer_tools.php
  var toolsAt = 0;
  var filesAvailable = true;
  var connectors = [];
  var menu = null;

  function svg(name, cls) {
    return '<svg class="' + (cls || 'cm-icon') + '" viewBox="0 0 24 24" aria-hidden="true">' + ICONS[name] + '</svg>';
  }
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = String(text);
    return node;
  }

  /** The tool of a message and its text without the mark (pure). */
  function parse(text) {
    var value = String(text == null ? '' : text);
    var m = MARK_RE.exec(value);
    if (!m) return { intent: null, text: value, label: '' };
    var intent = m[1] === 'bild' ? { tool: 'image', size: 'normal' }
      : m[1] === 'bild:gross' ? { tool: 'image', size: 'large' }
      : m[1] === 'websuche' ? { tool: 'web', size: 'normal' } : { tool: 'browse', size: 'normal' };
    if (m[1].indexOf('bild') === 0) {
      intent = { tool: 'image', size: m[1].indexOf(':gross') !== -1 ? 'large' : 'normal' };
      var countMatch = /:([124])$/.exec(m[1]);
      if (countMatch) intent.count = Number(countMatch[1]);
    }
    return { intent: intent, text: value.slice(m[0].length), label: label(intent) };
  }
  function label(intent) {
    if (!intent) return '';
    if (intent.tool === 'image' && intent.count) return 'Bild erstellen · ' + intent.count + (intent.count === 1 ? ' Bild' : ' Bilder') + (intent.size === 'large' ? ' · Groß' : '');
    return intent.tool === 'image' && intent.size === 'large' ? 'Bild erstellen · Groß' : LABEL[intent.tool];
  }
  function mark(intent) {
    if (!intent) return '';
    if (intent.tool === 'image') return '[[bild' + (intent.size === 'large' ? ':gross' : '') + (intent.count ? ':' + intent.count : '') + ']]';
    return MARKS[intent.tool] || '';
  }

  // --- The chip in the input field ----------------------------------------------------------
  var chip = el('div', 'composer-intent');
  chip.hidden = true;
  chip.setAttribute('aria-live', 'polite');
  var chipLabel = el('span', 'composer-intent-label');
  var sizeBox = el('span', 'composer-intent-size');
  var sizeNormal = el('button', 'composer-intent-size-btn', 'Normal');
  var sizeLarge = el('button', 'composer-intent-size-btn', 'Groß');
  [sizeNormal, sizeLarge].forEach(function (b) { b.type = 'button'; });
  sizeNormal.title = '512 × 512, etwa eine Minute';
  sizeLarge.title = '1024 × 1024, drei bis vier Minuten';
  sizeBox.appendChild(sizeNormal);
  sizeBox.appendChild(sizeLarge);
  var countBox = el('span', 'composer-intent-size');
  countBox.setAttribute('role', 'group');
  countBox.setAttribute('aria-label', 'Anzahl der Bilder');
  var countButtons = [1, 2, 4].map(function (n) {
    var b = el('button', 'composer-intent-size-btn', String(n));
    b.type = 'button';
    b.setAttribute('aria-label', n + (n === 1 ? ' Bild' : ' Bilder'));
    b.addEventListener('click', function () { if (current) { current.count = n; renderChip(); } });
    countBox.appendChild(b);
    return b;
  });
  var chipClose = el('button', 'composer-intent-close');
  chipClose.type = 'button';
  chipClose.setAttribute('aria-label', 'Werkzeug entfernen');
  chipClose.innerHTML = svg('close');
  chip.appendChild(chipLabel);
  chip.appendChild(sizeBox);
  chip.appendChild(countBox);
  chip.appendChild(chipClose);
  field.insertBefore(chip, textarea);

  function renderChip() {
    chip.hidden = !current;
    if (!current) return;
    chip.setAttribute('data-tool', current.tool);
    chipLabel.innerHTML = svg(current.tool === 'image' ? 'image' : current.tool) + '<span>' + LABEL[current.tool] + '</span>';
    sizeBox.hidden = current.tool !== 'image';
    countBox.hidden = current.tool !== 'image';
    countButtons.forEach(function (b, i) { b.setAttribute('aria-pressed', (current.count || 1) === [1, 2, 4][i] ? 'true' : 'false'); });
    sizeNormal.setAttribute('aria-pressed', current.size === 'large' ? 'false' : 'true');
    sizeLarge.setAttribute('aria-pressed', current.size === 'large' ? 'true' : 'false');
  }
  function setIntent(tool, size, count) {
    current = tool ? { tool: tool, size: size === 'large' ? 'large' : 'normal' } : null;
    if (current && tool === 'image' && [1, 2, 4].indexOf(count) !== -1) current.count = count;
    renderChip();
  }
  sizeNormal.addEventListener('click', function () { if (current) { current.size = 'normal'; renderChip(); } });
  sizeLarge.addEventListener('click', function () { if (current) { current.size = 'large'; renderChip(); } });
  chipClose.addEventListener('click', function () { setIntent(null); textarea.focus(); });

  // --- The + button and the menu -------------------------------------------------------------
  btn.innerHTML = svg('plus');
  btn.classList.add('composer-plus-btn');
  btn.setAttribute('aria-label', 'Anhängen und Werkzeuge');
  btn.setAttribute('aria-haspopup', 'menu');
  btn.setAttribute('aria-expanded', 'false');

  function loadTools(force) {
    if (!force && tools && Date.now() - toolsAt < 60000) return Promise.resolve(tools);
    return fetch('./api/composer_tools.php', { credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.ok && data.tools) { tools = data; toolsAt = Date.now(); }
        return tools;
      })
      .catch(function () { return tools; });
  }
  function toolState(id) {
    // Unknown (older server, no answer): offered; the server checks anyway.
    var entry = tools && tools.tools ? tools.tools[id] : null;
    return entry ? { on: !!entry.on, why: String(entry.why || '') } : { on: true, why: '' };
  }
  function hasCamera() {
    try {
      return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || 'ontouchstart' in window;
    } catch (e) { return false; }
  }

  function pick(accept, capture) {
    input.setAttribute('accept', accept);
    if (capture) input.setAttribute('capture', capture); else input.removeAttribute('capture');
    close();
    input.click();
  }
  // After the picker, the input is the plain "Dateien" input again.
  input.addEventListener('change', function () {
    setTimeout(function () { input.setAttribute('accept', DEFAULT_ACCEPT); input.removeAttribute('capture'); }, 0);
  });

  function item(def) {
    var b = el('button', 'cm-item' + (def.disabled ? ' is-off' : ''));
    b.type = 'button';
    b.setAttribute('role', 'menuitem');
    if (def.id) b.setAttribute('data-id', def.id);
    if (def.disabled) b.setAttribute('aria-disabled', 'true');
    b.innerHTML = svg(def.icon);
    var text = el('span', 'cm-text');
    text.appendChild(el('span', 'cm-label', def.label));
    if (def.detail) text.appendChild(el('span', 'cm-detail', def.detail));
    b.appendChild(text);
    if (def.badge) b.appendChild(el('span', 'cm-badge', def.badge));
    if (current && def.tool && current.tool === def.tool) b.classList.add('is-active');
    b.addEventListener('click', function () {
      if (def.disabled) return;
      def.run();
    });
    return b;
  }
  function section(title, items) {
    var box = el('div', 'cm-section');
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', title);
    box.appendChild(el('div', 'cm-title', title));
    items.forEach(function (i) { box.appendChild(item(i)); });
    return box;
  }

  function build() {
    var image = toolState('image'), web = toolState('web'), browse = toolState('browse');
    var attach = [
      { id: 'photos', icon: 'photo', label: 'Fotos', detail: 'Bilder aus deiner Galerie', disabled: !filesAvailable,
        run: function () { pick('image/*', null); } }
    ];
    if (hasCamera()) {
      attach.push({ id: 'camera', icon: 'camera', label: 'Kamera', detail: 'Foto aufnehmen und Mara zeigen', disabled: !filesAvailable,
        run: function () { pick('image/*', 'environment'); } });
    }
    attach.push({ id: 'files', icon: 'file', label: 'Dateien', detail: filesAvailable ? 'PDF, Audio, Video, Dokumente' : 'Zehn Anhänge sind schon gewählt',
      disabled: !filesAvailable, run: function () { pick(DEFAULT_ACCEPT, null); } });
    var work = [
      { id: 'image', tool: 'image', icon: 'image', label: 'Bild erstellen', detail: image.on ? 'Mara malt ein Bild zu deiner Nachricht' : image.why,
        disabled: !image.on, run: function () { choose('image'); } },
      { id: 'web', tool: 'web', icon: 'web', label: 'Websuche', detail: web.on ? 'Aktuelles aus dem Netz' : web.why,
        disabled: !web.on, run: function () { choose('web'); } },
      { id: 'browse', tool: 'browse', icon: 'browse', label: 'Recherche', detail: browse.on ? 'Mara liest selbst Webseiten' : browse.why,
        disabled: !browse.on, run: function () { choose('browse'); } },
      { id: 'deep', icon: 'deep', label: 'Deep Research', detail: 'Bericht aus vielen Quellen', badge: 'In Vorbereitung',
        disabled: true, run: function () {} }
    ];
    var links = connectors.length ? connectors.map(function (c) {
      return { id: 'connector-' + c.id, icon: 'plug', label: c.label, detail: c.detail || '', disabled: !!c.disabled,
        run: function () { close(); try { c.onSelect(); } catch (e) {} } };
    }) : [{ id: 'connectors', icon: 'plug', label: 'Konnektoren', detail: 'Externe Dienste anbinden', badge: 'In Vorbereitung',
      disabled: true, run: function () {} }];
    var box = el('div', 'composer-menu');
    box.id = 'composerMenu';
    box.setAttribute('role', 'menu');
    box.setAttribute('aria-label', 'Anhängen und Werkzeuge');
    box.appendChild(section('Anhängen', attach));
    box.appendChild(section('Werkzeuge', work));
    box.appendChild(section('Konnektoren', links));
    box.addEventListener('keydown', onKey);
    return box;
  }

  function choose(tool) {
    setIntent(tool, current && current.tool === tool ? current.size : 'normal', current && current.count);
    close();
    textarea.focus();
  }

  function focusables() {
    return menu ? Array.prototype.slice.call(menu.querySelectorAll('.cm-item:not(.is-off)')) : [];
  }
  function onKey(event) {
    var list = focusables();
    var index = list.indexOf(document.activeElement);
    if (event.key === 'Escape') { event.preventDefault(); close(); btn.focus(); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!list.length) return;
      var next = event.key === 'ArrowDown' ? (index + 1) % list.length : (index - 1 + list.length) % list.length;
      list[next].focus();
    }
    if (event.key === 'Tab') close();
  }

  function open() {
    if (menu) return;
    menu = build();
    frame.appendChild(menu);
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('is-open');
    var first = focusables()[0];
    if (first) first.focus();
    // The answer may come a moment later: rebuild with what the account may use, without
    // playing the opening again and with the focus on the same entry.
    var shown = menu;
    loadTools(false).then(function () {
      if (!menu || menu !== shown) return;
      var focusedId = menu.contains(document.activeElement) ? document.activeElement.getAttribute('data-id') : null;
      var fresh = build();
      fresh.classList.add('is-settled');
      frame.replaceChild(fresh, menu);
      menu = fresh;
      var list = focusables();
      var again = list.filter(function (b) { return b.getAttribute('data-id') === focusedId; })[0] || list[0];
      if (again && (focusedId !== null || !frame.contains(document.activeElement))) again.focus();
    });
  }
  function close() {
    if (!menu) return;
    if (menu.parentNode) menu.parentNode.removeChild(menu);
    menu = null;
    btn.setAttribute('aria-expanded', 'false');
    btn.classList.remove('is-open');
  }
  btn.addEventListener('click', function (event) {
    event.preventDefault();
    if (menu) close(); else open();
  });
  document.addEventListener('pointerdown', function (event) {
    if (menu && !menu.contains(event.target) && !btn.contains(event.target)) close();
  });
  document.addEventListener('keydown', function (event) {
    if (menu && event.key === 'Escape') { close(); btn.focus(); }
  });

  window.CoreAIComposerMenu = {
    parse: parse,
    label: label,
    /** The text to send: the chosen tool's mark in front (once), then the chip is cleared.
        Without text only when files go along (a Stimmsequenz, a photo). */
    consume: function (text, withFiles) {
      var value = String(text == null ? '' : text);
      var out = window.CoreAIComposerMenu.marked(value, withFiles);
      if (out !== value) setIntent(null);
      return out;
    },
    /** Like consume, but the chip stays (an edit keeps it until it is saved). */
    marked: function (text, withFiles) {
      var value = String(text == null ? '' : text);
      if (!current || MARK_RE.test(value) || (!value.trim() && !withFiles)) return value;
      var rest = value.replace(/^\s+/, '');
      return mark(current) + (rest ? ' ' + rest : '');
    },
    /** The small symbol of a tool, for the chip in the person's bubble. */
    icon: function (tool) { return ICONS[tool] ? svg(tool, 'msg-intent-icon') : ''; },
    /** For an edit: the chip from the stored message, its text without the mark. */
    loadFrom: function (stored, text) {
      var parsed = parse(stored);
      setIntent(parsed.intent ? parsed.intent.tool : null, parsed.intent ? parsed.intent.size : null, parsed.intent && parsed.intent.count);
      return parse(text).text;
    },
    current: function () { return current ? Object.assign({}, current) : null; },
    set: setIntent,
    clear: function () { setIntent(null); },
    open: open,
    close: close,
    filesAvailable: function (yes) { filesAvailable = !!yes; },
    /** Roadmap P14: a connector appears in the menu once an extension registers it. */
    registerConnector: function (def) {
      if (!def || !/^[a-z0-9_-]{1,40}$/.test(String(def.id || '')) || typeof def.onSelect !== 'function') return false;
      connectors = connectors.filter(function (c) { return c.id !== def.id; });
      connectors.push({ id: String(def.id), label: String(def.label || def.id), detail: String(def.detail || ''),
        disabled: !!def.disabled, onSelect: def.onSelect });
      return true;
    },
    refresh: function () { return loadTools(true); }
  };
})();
