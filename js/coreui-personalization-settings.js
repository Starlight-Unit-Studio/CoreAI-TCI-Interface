/* CoreAI: TCI Interface 0.7.0: Settings > Personalisierung after the reference screenshots.
   Every switch and every menu saves on its own (api/personalization.php, action "save" with only
   the changed keys; no save button on the page). Mara-Erinnerung: the memory switch and the
   Erinnerungszusammenfassung that Mara writes with the account's Core (api/memory_summary.php),
   with "Info zur Erinnerung", "Erinnerung löschen und ausschalten", "Tiefer eintauchen" and a
   question row that opens a new chat (app.html?draft=). Schreibstil. Referenzfotos: uploaded like a
   chat image (api/console_upload.php) and used when the person asks for an image of themselves.
   The style menus, the sub page "Benutzerdefinierte Anweisungen für Mara" (instructions, nickname,
   job, more about the person, role) and Weitere Mara-Einstellungen. Konnektorsuche and Codex Cloud
   are planned. Never changes the operator Modelfile. */
(function () {
  'use strict';

  var view = document.getElementById('view-personalisierung');
  if (!view || !document.getElementById('pzMain')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/personalization.php';
  var state = {csrf: '', canSave: false, settings: {}, available: {}, references: {}, memory: false,
    loaded: false, saveSeq: 0, menuBtn: null, slot: null};

  // The answer style the server writes for Mara (api/console_answer_style.php), in plain words.
  var OPTIONS = {
    tone: [['standard', 'Standard', 'Voreingestellter Stil und Tonfall'], ['professionell', 'Professionell', 'Ausgereift und präzise'],
      ['freundlich', 'Freundlich', 'Herzlich und gesprächig'], ['aufrichtig', 'Aufrichtig', 'Direkt und motivierend'],
      ['skurril', 'Skurril', 'Verspielt und fantasievoll'], ['effizient', 'Effizient', 'Prägnant und klar'],
      ['zynisch', 'Zynisch', 'Kritisch und sarkastisch']],
    warmth: [['mehr', 'Mehr', 'Wärmer und persönlicher'], ['standard', 'Standard', 'Je nach Situation'],
      ['weniger', 'Weniger', 'Sachlicher']],
    enthusiasm: [['mehr', 'Mehr', 'Mit mehr Schwung'], ['standard', 'Standard', 'Je nach Situation'],
      ['weniger', 'Weniger', 'Ruhiger']],
    emoji: [['mehr', 'Mehr', 'Gern passende Emojis'], ['standard', 'Standard', 'Ab und zu ein passendes'],
      ['weniger', 'Weniger', 'Nur wenige'], ['keine', 'Keine', 'Gar keine Emojis']],
    visual_answers: [['mehr', 'Mehr', 'Fettschrift, Tabellen und Beispiele, wenn sie helfen'],
      ['standard', 'Standard', 'Visuelle Struktur nur, wenn sie hilft'], ['weniger', 'Weniger', 'Lieber Fließtext']],
    headings_lists: [['mehr', 'Mehr', 'Längere Antworten gut gegliedert'], ['standard', 'Standard', 'Je nach Situation'],
      ['weniger', 'Weniger', 'Nur bei echtem Bedarf']]
  };
  var LABELS = {adapt_style: 'Schreibstil', quick_answers: 'Schnelle Antworten', suggested_prompts: 'Vorgeschlagene Prompts',
    voice_history: 'Frühere Stimmsequenzen', web_search: 'Websuche', browse_search: 'Browser-Recherche',
    live_voice: 'Live-Gespräch', knowledge_search: 'Suche in meinem Wissen', tone: 'Basisstil und -tonfall',
    warmth: 'Herzlichkeit', enthusiasm: 'Begeisterung', emoji: 'Emoji', visual_answers: 'Visuelle Antworten',
    headings_lists: 'Überschriften und Listen', custom_instructions: 'Benutzerdefinierte Anweisungen'};
  // The sub page "Benutzerdefinierte Anweisungen für Mara": setting => field.
  var FIELDS = {custom_instructions: 'personaInstructions', nickname: 'personaNickname', occupation: 'personaOccupation',
    about: 'personaAbout', role: 'personaRole'};
  var ERRORS = {
    missing_schema_022: 'Das Update ist noch nicht vollständig eingespielt (Migration 022).',
    image_unavailable: 'Die Bilderstellung ist für dein Konto nicht verfügbar.',
    invalid_reference: 'Dieses Foto lässt sich nicht als Referenz verwenden.',
    file_too_large: 'Das Foto ist größer als 8 MB.',
    invalid_file_type: 'Bitte wähle ein Foto als PNG, JPEG oder WebP.',
    not_authenticated: 'Bitte melde dich erneut an.',
    core_busy: 'Mara ist gerade mit anderen Anfragen beschäftigt. Versuche es gleich noch einmal.',
    summary_failed: 'Mara konnte die Zusammenfassung gerade nicht schreiben. Ist der Core erreichbar?',
    missing_schema_033: 'Das Update ist noch nicht vollständig eingespielt (Migration 033).'
  };
  var REF_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
  var REF_MAX = 8 * 1024 * 1024;

  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || (fallback ? fallback + ' (' + (code || 'unbekannt') + ')' : 'Fehler: ' + (code || 'unbekannt'));
  }
  function status(text, error) {
    var el = $('personalizationStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function request(url, method, body) {
    var headers = {'Accept': 'application/json'};
    if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CoreUI-CSRF'] = state.csrf; }
    return fetch(url, {method: method, credentials: 'include', cache: 'no-store', headers: headers,
      body: method === 'POST' ? JSON.stringify(body) : undefined}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP_' + res.status));
        return data;
      });
    });
  }
  function optionLabel(key, value) {
    var list = OPTIONS[key] || [];
    for (var i = 0; i < list.length; i++) if (list[i][0] === value) return list[i][1];
    return value;
  }

  // --- Show the stored state ------------------------------------------------------------------------
  function show() {
    var s = state.settings;
    Array.prototype.forEach.call(view.querySelectorAll('input[data-personalization]'), function (input) {
      input.checked = s[input.getAttribute('data-personalization')] === true;
      input.disabled = !state.canSave;
    });
    Array.prototype.forEach.call(view.querySelectorAll('.pz-select'), function (btn) {
      var key = btn.getAttribute('data-personalization');
      btn.setAttribute('data-value', s[key] || 'standard');
      btn.querySelector('span').textContent = optionLabel(key, s[key] || 'standard');
      btn.disabled = !state.canSave;
    });
    var memory = $('personaMemoryEnabled');
    memory.checked = state.memory === true;
    var preview = $('pzInstructionsPreview');
    var text = String(s.custom_instructions || '').replace(/\s+/g, ' ').trim();
    preview.textContent = text.length > 90 ? text.slice(0, 90) + '…' : text;
    preview.hidden = text === '';
    showTools();
    showReferences();
  }

  // Websuche and Browser-Recherche: a personal switch only opts out; the operator, the account's
  // tool rules and the Lockdown-Modus decide whether they exist at all.
  function showTools() {
    var a = state.available || {};
    Array.prototype.forEach.call(view.querySelectorAll('[data-pz-tool]'), function (row) {
      var tool = row.getAttribute('data-pz-tool');
      var input = row.querySelector('input');
      var note = row.querySelector('.pz-blocked');
      var blocked = a.lockdown ? 'IM LOCKDOWN-MODUS AUS' : (a[tool] === false ? 'FÜR DEIN KONTO NICHT FREIGEGEBEN' : '');
      note.textContent = blocked;
      note.hidden = blocked === '';
      if (blocked) input.disabled = true;
    });
  }

  function showReferences() {
    var image = !!(state.available && state.available.image);
    Array.prototype.forEach.call(view.querySelectorAll('[data-pz-ref]'), function (row) {
      var slot = row.getAttribute('data-pz-ref');
      var ref = state.references ? state.references[slot] : null;
      var thumb = row.querySelector('.pz-ref-thumb');
      var add = row.querySelector('.pz-ref-add');
      var remove = row.querySelector('.pz-ref-remove');
      if (ref && ref.url) { thumb.src = ref.url; thumb.hidden = false; } else { thumb.removeAttribute('src'); thumb.hidden = true; }
      remove.hidden = !ref;
      remove.disabled = !state.canSave;
      add.querySelector('span').textContent = ref ? 'Ändern' : 'Hinzufügen';
      add.disabled = !image || !state.canSave;
      row.classList.toggle('is-unavailable', !image);
    });
    $('pzRefNote').textContent = image
      ? 'Die Fotos bleiben in deinem Konto und gehen nur in Bilder, um die du für dich selbst bittest. PNG, JPEG oder WebP bis 8 MB.'
      : 'Die Bilderstellung ist auf diesem Server nicht eingerichtet oder für dein Konto nicht freigegeben. Gespeicherte Fotos kannst du trotzdem entfernen.';
  }

  function apply(data) {
    if (data.settings) state.settings = data.settings;
    if (data.references) state.references = data.references;
    show();
  }

  function load() {
    return request(API, 'GET').then(function (data) {
      state.csrf = data.csrf_token || state.csrf;
      state.canSave = data.schema_available === true;
      state.available = data.available || {};
      state.memory = data.memory_enabled === true;
      state.loaded = true;
      apply(data);
      status(state.canSave ? '' : 'Migration 022 fehlt. Die Einstellungen können noch nicht gespeichert werden.', !state.canSave);
    }).catch(function (err) {
      status('Personalisierung konnte nicht geladen werden. ' + errText(err), true);
    });
  }

  // --- Saving ------------------------------------------------------------------------------------
  // Only the keys in changes are sent; an older answer must not undo a newer switch.
  function saveChanges(changes, label) {
    if (!state.canSave) return Promise.reject(new Error('missing_schema_022'));
    var keys = Object.keys(changes);
    var previous = {};
    var seq = ++state.saveSeq;
    keys.forEach(function (key) { previous[key] = state.settings[key]; state.settings[key] = changes[key]; });
    show();
    return request(API, 'POST', {action: 'save', settings: changes}).then(function (data) {
      if (seq === state.saveSeq) apply(data);
      status(label + ' gespeichert.');
      return true;
    }).catch(function (err) {
      keys.forEach(function (key) { if (state.settings[key] === changes[key]) state.settings[key] = previous[key]; });
      show();
      status(errText(err, label + ' konnte nicht gespeichert werden.'), true);
      throw err;
    });
  }
  function save(key, value) {
    var change = {};
    change[key] = value;
    return saveChanges(change, LABELS[key] || 'Die Einstellung');
  }

  Array.prototype.forEach.call(view.querySelectorAll('input[data-personalization]'), function (input) {
    input.addEventListener('change', function () {
      save(input.getAttribute('data-personalization'), input.checked).catch(function () {});
    });
  });

  $('personaMemoryEnabled').addEventListener('change', function () {
    var input = $('personaMemoryEnabled');
    var enabled = input.checked;
    input.disabled = true;
    request(API, 'POST', {action: 'memory', enabled: enabled}).then(function (data) {
      state.memory = data.memory_enabled === true;
      input.checked = state.memory;
      var legacy = $('aiMemoryEnabled');
      if (legacy) legacy.checked = state.memory;
      status(state.memory ? 'Erinnerung aktiviert.' : 'Erinnerung deaktiviert. Gespeicherte Erinnerungen bleiben erhalten.');
    }).catch(function (err) {
      input.checked = !enabled;
      status(errText(err, 'Die Erinnerung konnte nicht geändert werden.'), true);
    }).then(function () { input.disabled = false; });
  });

  // --- The style menus (Standard ⌄) ---------------------------------------------------------------------
  var menu = $('pzMenu');
  function closeMenu(focusButton) {
    if (!state.menuBtn) return;
    var btn = state.menuBtn;
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    state.menuBtn = null;
    if (focusButton) btn.focus();
  }
  function placeMenu(btn) {
    var rect = btn.getBoundingClientRect();
    var width = menu.offsetWidth;
    var height = menu.offsetHeight;
    var left = Math.min(rect.right - width, window.innerWidth - width - 8);
    var top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8 && rect.top - 8 - height >= 8) top = rect.top - 8 - height;
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }
  function openMenu(btn) {
    var again = state.menuBtn === btn;
    closeMenu(false);
    if (again || btn.disabled) return;
    var key = btn.getAttribute('data-personalization');
    var current = btn.getAttribute('data-value');
    menu.textContent = '';
    menu.setAttribute('aria-label', LABELS[key] || '');
    (OPTIONS[key] || []).forEach(function (opt) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'arc-option pz-option';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('data-value', opt[0]);
      item.setAttribute('aria-checked', opt[0] === current ? 'true' : 'false');
      var copy = document.createElement('span');
      var name = document.createElement('b');
      name.textContent = opt[1];
      var hint = document.createElement('small');
      hint.textContent = opt[2];
      copy.appendChild(name);
      copy.appendChild(hint);
      item.appendChild(copy);
      item.insertAdjacentHTML('beforeend', '<svg aria-hidden="true"><use href="#i-check" /></svg>');
      item.addEventListener('click', function (e) {
        e.stopPropagation();
        closeMenu(true);
        if (opt[0] !== current) save(key, opt[0]).catch(function () {});
      });
      menu.appendChild(item);
    });
    state.menuBtn = btn;
    btn.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
    placeMenu(btn);
    var checked = menu.querySelector('[aria-checked="true"]') || menu.querySelector('.pz-option');
    if (checked) checked.focus({preventScroll: true});
  }
  Array.prototype.forEach.call(view.querySelectorAll('.pz-select'), function (btn) {
    btn.addEventListener('click', function (e) { e.stopPropagation(); openMenu(btn); });
  });
  menu.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    var items = Array.prototype.slice.call(menu.querySelectorAll('.pz-option'));
    var index = items.indexOf(document.activeElement);
    var next = e.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
    items[next].focus({preventScroll: true});
  });
  document.addEventListener('click', function (e) {
    if (state.menuBtn && !menu.contains(e.target)) closeMenu(false);
  });
  // The menu follows its button while the page scrolls; it closes once the button leaves the window.
  document.addEventListener('scroll', function (e) {
    if (!state.menuBtn || menu.contains(e.target)) return;
    var rect = state.menuBtn.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) closeMenu(false);
    else placeMenu(state.menuBtn);
  }, true);
  window.addEventListener('resize', function () { closeMenu(false); });

  // --- Sub page "Benutzerdefinierte Anweisungen für Mara" (Zurück, Abbrechen, Speichern) -------------------
  var instructions = $('personaInstructions');
  function count() { $('personaInstructionsCount').textContent = instructions.value.length + ' / 2400'; }
  function fieldValues() {
    var out = {};
    Object.keys(FIELDS).forEach(function (key) { out[key] = $(FIELDS[key]).value; });
    return out;
  }
  function dirty() {
    return Object.keys(FIELDS).some(function (key) { return String($(FIELDS[key]).value) !== String(state.settings[key] || ''); });
  }
  function updateSaveButton() { $('pzInstructionsSave').disabled = !state.canSave || !dirty(); }
  function subOpen() { return !$('pzSub-instructions').hidden; }
  function openInstructions() {
    if (!state.loaded) return;
    closeMenu(false);
    Object.keys(FIELDS).forEach(function (key) { $(FIELDS[key]).value = String(state.settings[key] || ''); });
    count();
    $('pzInstructionsError').textContent = '';
    updateSaveButton();
    $('pzMain').hidden = true;
    $('pzSub-instructions').hidden = false;
    status('');
    if (view.scrollIntoView) view.scrollIntoView({block: 'start'});
    instructions.focus();
  }
  function closeInstructions() {
    $('pzSub-instructions').hidden = true;
    $('pzMain').hidden = false;
    var row = view.querySelector('.pz-nav .sec-open');
    if (row) row.focus();
  }
  Object.keys(FIELDS).forEach(function (key) {
    $(FIELDS[key]).addEventListener('input', function () { if (key === 'custom_instructions') count(); updateSaveButton(); });
    $(FIELDS[key]).addEventListener('change', updateSaveButton);
  });
  $('pzInstructionsSave').addEventListener('click', function () {
    var button = $('pzInstructionsSave');
    button.disabled = true;
    $('pzInstructionsError').textContent = '';
    saveChanges(fieldValues(), 'Benutzerdefinierte Anweisungen').then(function () {
      closeInstructions();
    }).catch(function (err) {
      $('pzInstructionsError').textContent = errText(err, 'Die Anweisungen konnten nicht gespeichert werden.');
      updateSaveButton();
    });
  });
  $('pzInstructionsCancel').addEventListener('click', closeInstructions);
  $('pzInstructionsBack').addEventListener('click', closeInstructions);
  Array.prototype.forEach.call(view.querySelectorAll('[data-pz-open="instructions"]'), function (el) {
    el.addEventListener('click', function (e) { e.stopPropagation(); openInstructions(); });
  });

  // --- Erinnerungszusammenfassung ---------------------------------------------------------------------------
  var SUMMARY_API = './api/memory_summary.php';
  var summary = {seq: 0};
  var MEMORY_OFF = 'Die Erinnerung ist ausgeschaltet; Mara nutzt diese Erinnerungen gerade nicht.';
  function summaryState(text) { $('pzSummaryState').textContent = text || ''; }
  // A suggestion or a question goes to a new chat with Mara, into the input row (nothing is sent yet).
  function openDraft(text) {
    text = String(text || '').trim();
    if (text) location.href = './app.html?draft=' + encodeURIComponent(text);
  }
  function renderSummary(markdown) {
    var box = $('pzSummaryText');
    box.textContent = '';
    var section = '';
    var paragraph = [];
    var suggestions = null;
    function flush() {
      if (!paragraph.length) return;
      var p = document.createElement('p');
      p.textContent = paragraph.join(' ');
      box.appendChild(p);
      paragraph = [];
    }
    String(markdown || '').split('\n').forEach(function (raw) {
      var line = raw.trim();
      if (/^##\s+/.test(line)) {
        flush();
        section = line.replace(/^##\s+/, '').replace(/\*\*/g, '');
        var h = document.createElement('h4');
        h.textContent = section;
        box.appendChild(h);
        suggestions = null;
        return;
      }
      if (line === '') { flush(); return; }
      var item = /^-\s+(.+)$/.exec(line);
      if (item && /tiefer eintauchen/i.test(section)) {
        flush();
        if (!suggestions) { suggestions = document.createElement('ul'); suggestions.className = 'pz-suggestions'; box.appendChild(suggestions); }
        var text = item[1].replace(/\*\*/g, '').trim();
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pz-suggestion';
        btn.innerHTML = '<svg aria-hidden="true"><use href="#i-reply" /></svg>';
        var span = document.createElement('span');
        span.textContent = text;
        btn.appendChild(span);
        btn.addEventListener('click', function () { openDraft(text); });
        li.appendChild(btn);
        suggestions.appendChild(li);
        return;
      }
      paragraph.push(line.replace(/^-\s+/, '• ').replace(/\*\*/g, ''));
    });
    flush();
  }
  function showFactList() {
    var list = $('pzSummaryList');
    list.textContent = '';
    request('./api/memories.php?limit=100', 'GET').then(function (data) {
      // Only what Mara learned about this person; global facts are maintained by the operator.
      (data.items || []).filter(function (m) { return m.scope !== 'global'; }).forEach(function (m) {
        var li = document.createElement('li');
        li.textContent = String(m.fact || '');
        list.appendChild(li);
      });
      list.hidden = !list.children.length;
    }).catch(function () { list.hidden = true; });
  }
  function generate(seq) {
    $('pzSummaryRetryBox').hidden = true;
    $('pzSummaryList').hidden = true;
    return request(SUMMARY_API, 'POST', {action: 'generate'}).then(function (data) {
      if (seq !== summary.seq) return;
      if (data.summary) { renderSummary(data.summary.markdown); summaryState(state.memory ? '' : MEMORY_OFF); }
      else summaryState('Mara hat sich noch nichts über dich gemerkt.');
    }).catch(function (err) {
      if (seq !== summary.seq) return;
      summaryState(errText(err, 'Die Zusammenfassung konnte nicht geladen werden.'));
      $('pzSummaryRetryBox').hidden = false;
      if (!$('pzSummaryText').children.length) showFactList();
    });
  }
  function openSummary() {
    var seq = ++summary.seq;
    $('pzSummaryText').textContent = '';
    $('pzSummaryList').hidden = true;
    $('pzSummaryRetryBox').hidden = true;
    $('pzInfo').hidden = true;
    $('pzAsk').value = '';
    $('pzAskSend').disabled = true;
    summaryState('Wird geladen …');
    $('pzSummaryDialog').hidden = false;
    $('pzSummaryClose').focus();
    request(SUMMARY_API, 'GET').then(function (data) {
      if (seq !== summary.seq) return;
      if (data.csrf_token) state.csrf = data.csrf_token;
      if (!data.fact_count) { summaryState('Mara hat sich noch nichts über dich gemerkt.'); return; }
      if (data.summary) renderSummary(data.summary.markdown);
      if (data.summary && data.fresh) { summaryState(state.memory ? '' : MEMORY_OFF); return; }
      summaryState(data.summary ? 'Mara bringt die Zusammenfassung auf den neuesten Stand …' : 'Mara schreibt deine Zusammenfassung …');
      return generate(seq);
    }).catch(function (err) {
      if (seq !== summary.seq) return;
      summaryState('Die Zusammenfassung konnte nicht geladen werden. ' + errText(err));
      $('pzSummaryRetryBox').hidden = false;
    });
  }
  function closeSummaryMenu() {
    $('pzSummaryMenu').hidden = true;
    $('pzSummaryMore').setAttribute('aria-expanded', 'false');
  }
  function closeSummary() {
    summary.seq++;
    closeSummaryMenu();
    $('pzInfo').hidden = true;
    $('pzSummaryDialog').hidden = true;
  }
  $('pzSummaryOpen').addEventListener('click', openSummary);
  $('pzSummaryClose').addEventListener('click', closeSummary);
  $('pzSummaryRetry').addEventListener('click', function () {
    summaryState('Mara schreibt deine Zusammenfassung …');
    generate(summary.seq);
  });
  $('pzSummaryMore').addEventListener('click', function (e) {
    e.stopPropagation();
    var open = $('pzSummaryMenu').hidden;
    $('pzSummaryMenu').hidden = !open;
    $('pzSummaryMore').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) $('pzSummaryInfo').focus();
  });
  $('pzSummaryInfo').addEventListener('click', function () {
    closeSummaryMenu();
    $('pzInfo').hidden = false;
    $('pzInfoOk').focus();
  });
  function closeInfo() { $('pzInfo').hidden = true; $('pzSummaryMore').focus(); }
  $('pzInfoOk').addEventListener('click', closeInfo);
  $('pzInfoClose').addEventListener('click', closeInfo);
  Array.prototype.forEach.call(view.querySelectorAll('[data-pz-close-summary]'), function (el) {
    el.addEventListener('click', closeSummary);
  });
  $('pzAsk').addEventListener('input', function () { $('pzAskSend').disabled = !$('pzAsk').value.trim(); });
  $('pzAskForm').addEventListener('submit', function (e) {
    e.preventDefault();
    openDraft($('pzAsk').value);
  });

  // "Erinnerung löschen und ausschalten"
  $('pzSummaryWipe').addEventListener('click', function () {
    closeSummaryMenu();
    $('pzWipeError').textContent = '';
    $('pzWipeConfirm').hidden = false;
    $('pzWipeCancel').focus();
  });
  function closeWipe() { $('pzWipeConfirm').hidden = true; }
  $('pzWipeCancel').addEventListener('click', closeWipe);
  $('pzWipeOk').addEventListener('click', function () {
    var button = $('pzWipeOk');
    button.disabled = true;
    request(SUMMARY_API, 'POST', {action: 'wipe', confirm: true}).then(function (data) {
      closeWipe();
      closeSummary();
      state.memory = data.memory_enabled === true;
      var legacy = $('aiMemoryEnabled');
      if (legacy) legacy.checked = state.memory;
      show();
      status(data.deleted === 1 ? '1 Erinnerung gelöscht. Die Erinnerung ist ausgeschaltet.'
        : (data.deleted || 0) + ' Erinnerungen gelöscht. Die Erinnerung ist ausgeschaltet.');
    }).catch(function (err) {
      $('pzWipeError').textContent = errText(err, 'Die Erinnerungen konnten nicht gelöscht werden.');
    }).then(function () { button.disabled = false; });
  });

  Array.prototype.forEach.call(view.querySelectorAll('.pz-dialog, #pzWipeConfirm'), function (dialog) {
    dialog.addEventListener('click', function (e) {
      if (e.target !== dialog) return;
      if (dialog.id === 'pzSummaryDialog') closeSummary(); else dialog.hidden = true;
    });
  });
  document.addEventListener('click', function (e) {
    if (!$('pzSummaryMenu').hidden && !$('pzSummaryMenu').contains(e.target)) closeSummaryMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (state.menuBtn) { closeMenu(true); return; }
    if (!$('pzWipeConfirm').hidden) { closeWipe(); return; }
    if (!$('pzSummaryMenu').hidden) { closeSummaryMenu(); $('pzSummaryMore').focus(); return; }
    if (!$('pzInfo').hidden) { closeInfo(); return; }
    if (!$('pzSummaryDialog').hidden) { closeSummary(); return; }
    if (subOpen()) closeInstructions();
  });

  // --- Referenzfotos -------------------------------------------------------------------------------------
  var fileInput = $('pzRefFile');
  function setReference(slot, uuid) {
    return request(API, 'POST', {action: 'reference', slot: slot, uuid: uuid}).then(function (data) {
      apply(data);
      return data;
    });
  }
  function dropUpload(uuid) {
    request('./api/console_upload.php', 'POST', {action: 'delete_unreferenced', uuid: uuid}).catch(function () {});
  }
  function upload(file) {
    var fd = new FormData();
    fd.append('file', file);
    return fetch('./api/console_upload.php', {method: 'POST', credentials: 'include', cache: 'no-store',
      headers: {'X-CoreUI-CSRF': state.csrf}, body: fd}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok || !data.uuid) throw new Error(data.error || ('HTTP_' + res.status));
        return String(data.uuid);
      });
    });
  }
  Array.prototype.forEach.call(view.querySelectorAll('[data-pz-ref]'), function (row) {
    var slot = row.getAttribute('data-pz-ref');
    var name = slot === 'body' ? 'Ganzkörper-Referenzfoto' : 'Referenzfoto';
    row.querySelector('.pz-ref-add').addEventListener('click', function () {
      if (!state.available.image || !state.canSave) return;
      state.slot = slot;
      fileInput.value = '';
      fileInput.click();
    });
    row.querySelector('.pz-ref-remove').addEventListener('click', function () {
      var button = row.querySelector('.pz-ref-remove');
      button.disabled = true;
      setReference(slot, null).then(function () {
        status(name + ' entfernt.');
      }).catch(function (err) {
        button.disabled = false;
        status(errText(err, 'Das Foto konnte nicht entfernt werden.'), true);
      });
    });
  });
  fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0];
    var slot = state.slot;
    if (!file || !slot) return;
    var name = slot === 'body' ? 'Ganzkörper-Referenzfoto' : 'Referenzfoto';
    if (REF_TYPES.indexOf(file.type) < 0) { status(ERRORS.invalid_file_type, true); return; }
    if (file.size > REF_MAX) { status(ERRORS.file_too_large, true); return; }
    var add = view.querySelector('[data-pz-ref="' + slot + '"] .pz-ref-add');
    add.disabled = true;
    status(name + ' wird hochgeladen …');
    upload(file).then(function (uuid) {
      return setReference(slot, uuid).catch(function (err) { dropUpload(uuid); throw err; });
    }).then(function () {
      status(name + ' gespeichert.');
    }).catch(function (err) {
      status(errText(err, 'Das Foto konnte nicht gespeichert werden.'), true);
      showReferences();
    });
  });

  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'personalisierung') load();
  });
  load();

  window.CoreAIPersonalization = {load: load};
})();
