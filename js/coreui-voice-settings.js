/* CoreAI: TCI Interface 0.7.14: Settings > Stimme after the reference screenshots.
   Stimme: a dialog grouped by language (Deutsch, Englisch) with every voice CoreAI offers
   (config/voice/mara-voice.json "choices", free licenses only); a tap plays Mara's short
   sentence in that voice (api/voice.php?action=preview), "Fertig" saves, "Abbrechen" keeps the
   old choice. Sprache: the language of Stimmsequenzen and dictations ("Automatisch erkennen" or
   a fixed language; unset = the account's main language). Diktieren: the dictation button in
   the chat input field. Zuletzt verwendete Aufnahmen: the last 20 recordings in this browser
   (js/coreui-recordings.js), to play, save as a file or delete. Automatic reading aloud and the
   list of names and terms keep their own scripts (coreui-voice-read.js, coreui-voice-terms.js). */
(function () {
  'use strict';

  var view = document.getElementById('view-stimme');
  if (!view || !document.getElementById('vsMain')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/voice.php';
  var GROUPS = [['de', 'Deutsch'], ['en', 'Englisch']];
  var LANGUAGE_NAMES = {de: 'Deutsch', en: 'Englisch', fr: 'Französisch', it: 'Italienisch', pt: 'Portugiesisch', es: 'Spanisch'};
  var state = {csrf: '', loaded: false, voices: [], chosen: {de: 'eve', en: 'eve'}, draft: null, defaultVoice: 'eve',
    input: '', inputLanguages: ['de', 'en'], main: 'de', dictation: true, owner: '', menuOpen: false,
    audio: null, previewKey: '', previewSeq: 0, previews: {}, recUrls: [], clearArmed: false};
  var ERRORS = {
    not_authenticated: 'Bitte melde dich erneut an.',
    guests_not_allowed: 'Stimmeinstellungen gibt es nur für angemeldete Konten.',
    missing_schema_036: 'Das Update ist noch nicht vollständig eingespielt (Migration 036).',
    invalid_voice: 'Diese Stimme gibt es nicht.',
    voice_loading: 'Die Stimme wird gerade geladen. Einen Moment …',
    voice_busy: 'Ich lese gerade etwas anderes vor. Versuche es gleich noch einmal.',
    voice_timeout: 'Das Vorhören hat zu lange gedauert. Versuche es noch einmal.',
    voice_unavailable: 'Der Sprachdienst ist gerade nicht erreichbar. Vorhören geht erst, wenn er läuft.',
    voice_cache_failed: 'Die Hörprobe ließ sich nicht speichern.'
  };

  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || (fallback ? fallback + ' (' + (code || 'unbekannt') + ')' : 'Fehler: ' + (code || 'unbekannt'));
  }
  function status(text, error) {
    var el = $('vsStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function request(method, action, body) {
    var headers = {'Accept': 'application/json'};
    if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CoreUI-CSRF'] = state.csrf; }
    return fetch(API + '?action=' + action, {method: method, credentials: 'include', cache: 'no-store', headers: headers,
      body: method === 'POST' ? JSON.stringify(body) : undefined}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP_' + res.status));
        return data;
      });
    });
  }
  function voiceOf(name) {
    for (var i = 0; i < state.voices.length; i++) if (state.voices[i].name === name) return state.voices[i];
    return null;
  }
  function labelOf(name) { var v = voiceOf(name); return v ? v.label : name; }
  function languageLabel(code) {
    if (code === 'auto') return 'Automatisch erkennen';
    return LANGUAGE_NAMES[code] || code;
  }

  // --- The page ------------------------------------------------------------------------------------
  function show() {
    var btn = $('vsVoiceOpen');
    var de = labelOf(state.chosen.de);
    var en = labelOf(state.chosen.en);
    btn.textContent = de === en ? de : de + ' / ' + en;
    btn.setAttribute('aria-label', 'Stimme wählen: ' + (de === en ? de : 'Deutsch ' + de + ', Englisch ' + en));
    btn.disabled = !state.loaded || !state.voices.length;
    var lang = $('vsLanguage');
    lang.querySelector('span').textContent = languageLabel(state.input || state.main);
    lang.disabled = !state.loaded;
    var dictation = $('vsDictation');
    dictation.checked = !!state.dictation;
    dictation.disabled = !state.loaded;
  }

  function load() {
    return request('GET', 'prefs').then(function (data) {
      if (!Array.isArray(data.voices)) throw new Error('voice_unavailable');
      state.csrf = data.csrf_token || state.csrf;
      state.voices = data.voices.filter(function (v) { return v && typeof v.name === 'string'; });
      state.defaultVoice = data.default_voice || 'eve';
      state.chosen = {de: (data.chosen && data.chosen.de) || state.defaultVoice, en: (data.chosen && data.chosen.en) || state.defaultVoice};
      state.input = typeof data.input_language === 'string' ? data.input_language : '';
      state.inputLanguages = Array.isArray(data.input_languages) ? data.input_languages : ['de', 'en'];
      state.main = data.main_language === 'en' ? 'en' : 'de';
      state.dictation = data.dictation !== false;
      state.owner = typeof data.recordings_key === 'string' ? data.recordings_key : '';
      state.loaded = true;
      status(data.prefs_ready === false ? ERRORS.missing_schema_036 : '', data.prefs_ready === false);
      show();
    }).catch(function (err) {
      state.loaded = false;
      show();
      status(errText(err, 'Die Stimmeinstellungen ließen sich nicht laden'), true);
    });
  }

  function save(changes, label) {
    return request('POST', 'settings', changes).then(function (data) {
      if (data.chosen) state.chosen = {de: data.chosen.de || state.defaultVoice, en: data.chosen.en || state.defaultVoice};
      if (typeof data.input_language === 'string') state.input = data.input_language;
      if (typeof data.dictation === 'boolean') state.dictation = data.dictation;
      show();
      status(label + ' gespeichert.');
      return data;
    }).catch(function (err) {
      show();
      status(errText(err, label + ' ließ sich nicht speichern'), true);
      throw err;
    });
  }

  // --- Sprache (menu) ------------------------------------------------------------------------------
  var menu = $('vsLanguageMenu');
  var langBtn = $('vsLanguage');
  function closeMenu(focusButton) {
    if (!state.menuOpen) return;
    state.menuOpen = false;
    menu.hidden = true;
    langBtn.setAttribute('aria-expanded', 'false');
    if (focusButton) langBtn.focus();
  }
  function placeMenu() {
    var rect = langBtn.getBoundingClientRect();
    var width = menu.offsetWidth;
    var height = menu.offsetHeight;
    var left = Math.min(rect.right - width, window.innerWidth - width - 8);
    var top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8 && rect.top - 8 - height >= 8) top = rect.top - 8 - height;
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }
  function openMenu() {
    if (state.menuOpen) { closeMenu(false); return; }
    if (langBtn.disabled) return;
    var current = state.input || state.main;
    var codes = state.inputLanguages.slice().sort(function (a, b) { return languageLabel(a).localeCompare(languageLabel(b), 'de'); });
    menu.textContent = '';
    ['auto'].concat(codes).forEach(function (code) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'arc-option pz-option';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('data-value', code);
      item.setAttribute('aria-checked', code === current ? 'true' : 'false');
      var copy = document.createElement('span');
      var name = document.createElement('b');
      name.textContent = languageLabel(code);
      copy.appendChild(name);
      if (code === 'auto') {
        var hint = document.createElement('small');
        hint.textContent = 'Dein Core erkennt die Sprache selbst';
        copy.appendChild(hint);
      }
      item.appendChild(copy);
      item.insertAdjacentHTML('beforeend', '<svg aria-hidden="true"><use href="#i-check" /></svg>');
      item.addEventListener('click', function (e) {
        e.stopPropagation();
        closeMenu(true);
        if (code === current) return;
        var before = state.input;
        state.input = code;
        show();
        save({input_language: code}, 'Sprache').catch(function () { state.input = before; show(); });
      });
      menu.appendChild(item);
    });
    state.menuOpen = true;
    langBtn.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
    placeMenu();
    var checked = menu.querySelector('[aria-checked="true"]') || menu.querySelector('.pz-option');
    if (checked) checked.focus({preventScroll: true});
  }
  langBtn.addEventListener('click', function (e) { e.stopPropagation(); openMenu(); });
  menu.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    var items = Array.prototype.slice.call(menu.querySelectorAll('.pz-option'));
    var index = items.indexOf(document.activeElement);
    var next = e.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
    items[next].focus({preventScroll: true});
  });
  document.addEventListener('click', function (e) {
    if (state.menuOpen && !menu.contains(e.target)) closeMenu(false);
  });
  document.addEventListener('scroll', function (e) {
    if (!state.menuOpen || menu.contains(e.target)) return;
    var rect = langBtn.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) closeMenu(false);
    else placeMenu();
  }, true);
  window.addEventListener('resize', function () { closeMenu(false); });

  // --- Diktieren -------------------------------------------------------------------------------------
  $('vsDictation').addEventListener('change', function () {
    var input = this;
    var on = input.checked;
    input.disabled = true;
    save({dictation: on}, 'Diktierfunktion').catch(function () { state.dictation = !on; }).then(function () {
      input.disabled = !state.loaded;
      show();
    });
  });

  // --- Stimme (dialog with preview) -------------------------------------------------------------------
  var dialog = $('vsVoiceDialog');
  function voiceState(text, error) {
    var el = $('vsVoiceState');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function drawVoices() {
    var root = $('vsVoiceGroups');
    root.textContent = '';
    GROUPS.forEach(function (group) {
      var code = group[0];
      var list = state.voices.filter(function (v) { return (v.languages || []).indexOf(code) >= 0; });
      if (!list.length) return;
      var title = document.createElement('h4');
      title.className = 'vs-group-title';
      title.id = 'vsGroup-' + code;
      title.textContent = group[1];
      root.appendChild(title);
      var box = document.createElement('div');
      box.className = 'vs-list';
      box.setAttribute('role', 'radiogroup');
      box.setAttribute('aria-labelledby', title.id);
      list.forEach(function (voice) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'vs-voice';
        row.setAttribute('role', 'radio');
        row.setAttribute('data-voice', voice.name);
        row.setAttribute('data-language', code);
        row.setAttribute('aria-checked', state.draft[code] === voice.name ? 'true' : 'false');
        row.insertAdjacentHTML('beforeend', '<svg class="vs-check" aria-hidden="true"><use href="#i-check" /></svg>');
        var copy = document.createElement('span');
        copy.className = 'vs-voice-copy';
        var name = document.createElement('b');
        name.textContent = voice.label;
        var meta = document.createElement('small');
        meta.textContent = [voice.gender, voice.license, voice.name === state.defaultVoice ? 'Standard' : ''].filter(Boolean).join(' · ');
        copy.appendChild(name);
        copy.appendChild(meta);
        row.appendChild(copy);
        row.insertAdjacentHTML('beforeend', '<span class="vs-play" aria-hidden="true"><svg><use href="#i-play" /></svg></span>');
        row.title = voice.origin || voice.label;
        row.addEventListener('click', function () { pick(code, voice.name); });
        row.addEventListener('keydown', function (e) {
          if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
          e.preventDefault();
          var rows = Array.prototype.slice.call(box.querySelectorAll('.vs-voice'));
          var index = rows.indexOf(row);
          rows[e.key === 'ArrowDown' ? (index + 1) % rows.length : (index - 1 + rows.length) % rows.length].focus();
        });
        box.appendChild(row);
      });
      root.appendChild(box);
    });
  }
  function markRows() {
    Array.prototype.forEach.call(dialog.querySelectorAll('.vs-voice'), function (row) {
      var code = row.getAttribute('data-language');
      var key = code + ':' + row.getAttribute('data-voice');
      row.setAttribute('aria-checked', state.draft && state.draft[code] === row.getAttribute('data-voice') ? 'true' : 'false');
      row.classList.toggle('is-loading', state.previewKey === key && !state.audio);
      row.classList.toggle('is-playing', state.previewKey === key && !!state.audio);
    });
  }
  function stopPreview() {
    state.previewSeq++;
    if (state.audio) { try { state.audio.pause(); } catch (e) { /* already gone */ } }
    state.audio = null;
    state.previewKey = '';
    markRows();
  }
  function previewBlob(code, name) {
    var key = code + ':' + name;
    if (state.previews[key]) return Promise.resolve(state.previews[key]);
    return fetch(API + '?action=preview&voice=' + encodeURIComponent(name) + '&language=' + encodeURIComponent(code),
      {credentials: 'include', cache: 'no-store', headers: {'Accept': 'audio/wav, application/json'}}).then(function (res) {
      var type = res.headers.get('Content-Type') || '';
      if (res.ok && type.indexOf('audio/') === 0) {
        return res.blob().then(function (blob) { state.previews[key] = URL.createObjectURL(blob); return state.previews[key]; });
      }
      return res.json().catch(function () { return {}; }).then(function (data) { throw new Error(data.error || ('HTTP_' + res.status)); });
    });
  }
  function preview(code, name, tries) {
    var seq = ++state.previewSeq;
    if (state.audio) { try { state.audio.pause(); } catch (e) { /* already gone */ } }
    state.audio = null;
    state.previewKey = code + ':' + name;
    markRows();
    voiceState(tries ? ERRORS.voice_loading : '');
    previewBlob(code, name).then(function (url) {
      if (seq !== state.previewSeq) return;
      var audio = new Audio(url);
      state.audio = audio;
      markRows();
      audio.addEventListener('ended', function () { if (state.audio === audio) { state.audio = null; state.previewKey = ''; markRows(); } });
      voiceState('');
      return audio.play().catch(function () {
        if (state.audio === audio) { state.audio = null; state.previewKey = ''; markRows(); }
        voiceState('Der Browser hat die Wiedergabe blockiert. Tippe noch einmal auf die Stimme.', true);
      });
    }).catch(function (err) {
      if (seq !== state.previewSeq) return;
      // The voice service loads a language or a voice on first use: ask again for a while.
      if (err && err.message === 'voice_loading' && (tries || 0) < 15) {
        window.setTimeout(function () { if (seq === state.previewSeq) preview(code, name, (tries || 0) + 1); }, 2000);
        return;
      }
      state.previewKey = '';
      markRows();
      voiceState(errText(err, 'Vorhören nicht möglich'), true);
    });
  }
  function pick(code, name) {
    if (!state.draft) return;
    state.draft[code] = name;
    markRows();
    preview(code, name, 0);
  }
  function openVoices() {
    if (!state.loaded) return;
    state.draft = {de: state.chosen.de, en: state.chosen.en};
    drawVoices();
    voiceState('');
    dialog.hidden = false;
    var checked = dialog.querySelector('.vs-voice[aria-checked="true"]') || dialog.querySelector('.vs-voice');
    if (checked) checked.focus({preventScroll: true});
  }
  function closeVoices() {
    stopPreview();
    state.draft = null;
    dialog.hidden = true;
    $('vsVoiceOpen').focus();
  }
  $('vsVoiceOpen').addEventListener('click', openVoices);
  $('vsVoiceClose').addEventListener('click', closeVoices);
  $('vsVoiceCancel').addEventListener('click', closeVoices);
  $('vsVoiceDone').addEventListener('click', function () {
    var draft = state.draft || state.chosen;
    var changes = {};
    if (draft.de !== state.chosen.de) changes.voice_de = draft.de;
    if (draft.en !== state.chosen.en) changes.voice_en = draft.en;
    if (!Object.keys(changes).length) { closeVoices(); return; }
    var done = $('vsVoiceDone');
    done.disabled = true;
    save(changes, 'Stimme').then(function () { closeVoices(); }).catch(function (err) {
      voiceState(errText(err, 'Die Stimme ließ sich nicht speichern'), true);
    }).then(function () { done.disabled = false; });
  });

  // --- Zuletzt verwendete Aufnahmen ------------------------------------------------------------------
  var recDialog = $('vsRecDialog');
  function recState(text) { $('vsRecState').textContent = text || ''; }
  function releaseUrls() {
    state.recUrls.forEach(function (url) { try { URL.revokeObjectURL(url); } catch (e) { /* gone */ } });
    state.recUrls = [];
  }
  function two(n) { return ('0' + n).slice(-2); }
  function fileName(item) {
    var d = new Date(item.created || Date.now());
    var ext = /mp4/.test(item.type) ? 'm4a' : (/ogg/.test(item.type) ? 'ogg' : 'webm');
    return (item.kind === 'diktat' ? 'Diktat-' : 'Stimmsequenz-') + d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate())
      + '_' + two(d.getHours()) + '-' + two(d.getMinutes()) + '-' + two(d.getSeconds()) + '.' + ext;
  }
  function drawRecordings(items) {
    var list = $('vsRecList');
    releaseUrls();
    list.textContent = '';
    $('vsRecClear').disabled = !items.length;
    recState(items.length ? '' : 'Noch keine Aufnahmen auf diesem Gerät.');
    items.forEach(function (item) {
      var url = URL.createObjectURL(item.blob);
      state.recUrls.push(url);
      var li = document.createElement('li');
      li.className = 'vs-rec';
      var head = document.createElement('div');
      head.className = 'vs-rec-head';
      var kind = document.createElement('b');
      kind.textContent = item.kind === 'diktat' ? 'Diktat' : 'Stimmsequenz';
      var meta = document.createElement('small');
      var d = new Date(item.created || 0);
      meta.textContent = [d.toLocaleString('de-DE', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'}),
        Math.floor((item.seconds || 0) / 60) + ':' + two((item.seconds || 0) % 60), item.state || ''].filter(Boolean).join(' · ');
      head.appendChild(kind);
      head.appendChild(meta);
      li.appendChild(head);
      if (item.text) {
        var text = document.createElement('p');
        text.className = 'vs-rec-text';
        text.textContent = item.text;
        li.appendChild(text);
      }
      var audio = document.createElement('audio');
      audio.controls = true;
      audio.preload = 'none';
      audio.src = url;
      li.appendChild(audio);
      var actions = document.createElement('div');
      actions.className = 'vs-rec-actions';
      var keep = document.createElement('a');
      keep.className = 'arc-btn';
      keep.href = url;
      keep.download = fileName(item);
      keep.textContent = 'Speichern';
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'arc-btn vs-danger';
      del.textContent = 'Löschen';
      del.setAttribute('aria-label', (item.kind === 'diktat' ? 'Diktat' : 'Stimmsequenz') + ' vom ' + meta.textContent.split(' · ')[0] + ' löschen');
      del.addEventListener('click', function () {
        window.CoreAIRecordings.remove(state.owner, item.id).then(refreshRecordings).catch(function () { recState('Die Aufnahme ließ sich nicht löschen.'); });
      });
      actions.appendChild(keep);
      actions.appendChild(del);
      li.appendChild(actions);
      list.appendChild(li);
    });
  }
  function refreshRecordings() {
    var store = window.CoreAIRecordings;
    if (!store || !store.available()) { drawRecordings([]); recState('Dieser Browser kann keine Aufnahmen speichern.'); return Promise.resolve(); }
    if (!state.owner) { drawRecordings([]); recState(state.loaded ? 'Noch keine Aufnahmen auf diesem Gerät.' : 'Die Aufnahmen lassen sich erst zeigen, wenn die Einstellungen geladen sind.'); return Promise.resolve(); }
    return store.list(state.owner).then(drawRecordings).catch(function () { drawRecordings([]); recState('Die Aufnahmen ließen sich nicht lesen.'); });
  }
  function openRecordings() {
    state.clearArmed = false;
    $('vsRecClear').textContent = 'Alle löschen';
    recDialog.hidden = false;
    refreshRecordings();
    $('vsRecDone').focus({preventScroll: true});
  }
  function closeRecordings() {
    Array.prototype.forEach.call(recDialog.querySelectorAll('audio'), function (a) { try { a.pause(); } catch (e) { /* gone */ } });
    releaseUrls();
    recDialog.hidden = true;
    $('vsRecordingsOpen').focus();
  }
  $('vsRecordingsRow').addEventListener('click', function (e) { if (!e.target.closest('#vsRecordingsOpen')) openRecordings(); });
  $('vsRecordingsOpen').addEventListener('click', function (e) { e.stopPropagation(); openRecordings(); });
  $('vsRecClose').addEventListener('click', closeRecordings);
  $('vsRecDone').addEventListener('click', closeRecordings);
  $('vsRecClear').addEventListener('click', function () {
    var btn = this;
    if (!state.clearArmed) { state.clearArmed = true; btn.textContent = 'Wirklich alle löschen'; return; }
    state.clearArmed = false;
    btn.textContent = 'Alle löschen';
    window.CoreAIRecordings.clear(state.owner).then(refreshRecordings).catch(function () { recState('Die Aufnahmen ließen sich nicht löschen.'); });
  });

  // --- Dialogs: backdrop and Escape ------------------------------------------------------------------
  dialog.addEventListener('click', function (e) { if (e.target === dialog) closeVoices(); });
  recDialog.addEventListener('click', function (e) { if (e.target === recDialog) closeRecordings(); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (state.menuOpen) { closeMenu(true); return; }
    if (!dialog.hidden) { closeVoices(); return; }
    if (!recDialog.hidden) closeRecordings();
  });

  show();
  load();
})();
