/* CoreAI 0.7.69 (P09): the tab "Dateien" on the project page.
 * 0.7.75 (P10 A): the preview of an HTML page of the project in a sandboxed frame. 
 * Files of the project as a tree, search in names and content, a text editor
 * for single files, the history of a file with a comparison and restore, new
 * file, rename, delete and the current state as a ZIP. The server owns every
 * check (api/project_files.php); this module only shows and sends. */
(function () {
  'use strict';
  var panel = document.getElementById('projectFilesPanel');
  if (!panel) return;
  var API = './api/project_files.php';
  var root = document.createElement('div');
  root.className = 'project-files';
  panel.appendChild(root);

  var state = { project: '', files: [], bytes: 0, limits: null, csrf: '', loaded: false, error: '',
    file: null, draft: '', history: false, compare: null, query: '', results: null, folders: {},
    form: null, status: '', statusKind: '', conflict: false, busy: false, preview: null };
  var searchTimer = 0;

  var ERR = {
    invalid_path: 'Ungültiger Pfad. Erlaubt sind Ordner und Dateinamen aus Buchstaben, Ziffern, Leerzeichen und ._-()+.',
    unsupported_type: 'Dieser Dateityp wird hier nicht gespeichert. Erlaubt sind Textdateien wie .html, .css, .js, .json, .md, .txt oder .py.',
    file_too_large: 'Die Datei ist größer als 512 KB.',
    not_text: 'Das ist keine reine Textdatei.',
    file_exists: 'Unter diesem Pfad gibt es schon eine Datei.',
    file_not_found: 'Diese Datei gibt es nicht mehr.',
    revision_not_found: 'Diesen Stand gibt es nicht mehr.',
    file_changed: 'Die Datei wurde inzwischen geändert, zum Beispiel durch einen neuen Bau. Deine Änderungen sind noch im Editor.',
    project_full: 'Das Projekt ist voll: höchstens 200 Dateien und 10 MB.',
    storage_full: 'Dein Speicher ist voll. Unter Einstellungen > Speicher kannst du Platz schaffen.',
    project_files_migration_required: 'Die Datenbank-Migration 044 fehlt. Bitte das Update einspielen.',
    project_not_found: 'Das Projekt wurde nicht gefunden.',
    same_path: 'Der neue Pfad ist derselbe wie der alte.',
    invalid_query: 'Bitte mindestens zwei Zeichen eingeben.',
    csrf_failed: 'Die Sitzung wurde geändert. Bitte die Seite neu laden.'
  };
  var MAX_PREVIEW_ERRORS = 5;
  var SOURCE = { build: 'Von Mara gebaut', mara: 'Von Mara geändert', create: 'Neu angelegt', edit: 'Bearbeitet', rename: 'Umbenannt',
    restore: 'Wiederhergestellt' };

  function message(err) {
    var code = err && err.message || '';
    return ERR[code] || 'Das hat nicht geklappt (' + (code || 'unbekannter Fehler') + ').';
  }
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function button(label, cls, handler, attrs) {
    var b = el('button', cls, label);
    b.type = 'button';
    Object.keys(attrs || {}).forEach(function (k) { b.setAttribute(k, attrs[k]); });
    b.addEventListener('click', handler);
    return b;
  }
  function size(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(bytes < 10240 ? 1 : 0).replace('.', ',') + ' KB';
    return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }
  function when(stamp) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(stamp || ''));
    return m ? m[3] + '.' + m[2] + '.' + m[1] + ', ' + m[4] + ':' + m[5] : '';
  }
  function kind(path) {
    var ext = String(path).split('.').pop().toLowerCase();
    var map = { htm: 'HTML', markdown: 'MD', mjs: 'JS', cjs: 'JS', yaml: 'YML' };
    return (map[ext] || ext).slice(0, 4).toUpperCase();
  }
  function url(params) {
    var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return API + '?' + q;
  }
  function getJson(params) {
    return fetch(url(params), { credentials: 'include', cache: 'no-store' }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d || !d.ok) throw new Error((d && d.error) || ('HTTP ' + r.status));
        return d;
      });
    });
  }
  function post(body) {
    body.project_id = state.project;
    return fetch(API, { method: 'POST', credentials: 'include', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-COREUI-CSRF': state.csrf }, body: JSON.stringify(body) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok || !d || !d.ok) throw new Error((d && d.error) || ('HTTP ' + r.status));
          return d;
        });
      });
  }
  function setStatus(text, kind) { state.status = text || ''; state.statusKind = kind || ''; }
  function dirty() { return !!state.file && state.draft !== state.file.content; }

  // --- Line comparison (Myers) --------------------------------------------------------------
  function diffLines(a, b) {
    var n = a.length, m = b.length, max = n + m;
    if (max > 8000) return null;
    var v = { 1: 0 }, trace = [], d, k, x, y;
    outer:
    for (d = 0; d <= max; d++) {
      trace.push(Object.assign({}, v));
      for (k = -d; k <= d; k += 2) {
        x = (k === -d || (k !== d && v[k - 1] < v[k + 1])) ? v[k + 1] : v[k - 1] + 1;
        y = x - k;
        while (x < n && y < m && a[x] === b[y]) { x++; y++; }
        v[k] = x;
        if (x >= n && y >= m) break outer;
      }
    }
    var ops = [];
    x = n; y = m;
    for (d = trace.length - 1; d >= 0; d--) {
      var vd = trace[d];
      k = x - y;
      var prevK = (k === -d || (k !== d && vd[k - 1] < vd[k + 1])) ? k + 1 : k - 1;
      var prevX = vd[prevK], prevY = prevX - prevK;
      while (x > prevX && y > prevY) { ops.push({ t: ' ', text: a[x - 1], a: x, b: y }); x--; y--; }
      if (d > 0) {
        if (x === prevX) ops.push({ t: '+', text: b[y - 1], a: null, b: y });
        else ops.push({ t: '-', text: a[x - 1], a: x, b: null });
      }
      x = prevX; y = prevY;
    }
    return ops.reverse();
  }
  function lines(text) { return text === '' ? [] : String(text).replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n'); }

  // --- Loading ------------------------------------------------------------------------------
  function load() {
    return getJson({ project_id: state.project }).then(function (d) {
      state.files = d.files || []; state.bytes = d.bytes || 0; state.limits = d.limits || null;
      state.csrf = d.csrf_token || state.csrf; state.loaded = true; state.error = '';
    }).catch(function (e) { state.loaded = true; state.error = message(e); });
  }
  function openFile(path) {
    if (!leave()) return Promise.resolve();
    setStatus('');
    return getJson({ project_id: state.project, path: path }).then(function (d) {
      state.file = d.file; state.draft = d.file.content; state.history = false; state.compare = null;
      state.form = null; state.conflict = false;
      render();
      var editor = root.querySelector('.project-file-editor');
      if (editor) editor.focus();
    }).catch(function (e) { setStatus(message(e), 'error'); load().then(render); });
  }
  function backToList() {
    if (!leave()) return;
    state.file = null; state.form = null; state.compare = null; setStatus('');
    load().then(render);
  }
  function runSearch(query) {
    state.query = query;
    if (query.trim().length < 2) { state.results = null; render(); return; }
    getJson({ project_id: state.project, search: query.trim() }).then(function (d) {
      if (state.query !== query) return;
      state.results = d.results || []; render();
    }).catch(function (e) { state.results = []; setStatus(message(e), 'error'); render(); });
  }

  // --- Writing ------------------------------------------------------------------------------
  function busy(work) {
    if (state.busy) return;
    state.busy = true;
    render();
    work().then(function (changed) { if (changed !== false) refreshPreview(); })
      .catch(function (e) { setStatus(message(e), 'error'); })
      .then(function () { state.busy = false; render(); });
  }
  function save(force) {
    if (!state.file) return;
    busy(function () {
      var start = force ? getJson({ project_id: state.project, path: state.file.path }).then(function (d) { return d.file.sha256; })
        : Promise.resolve(state.file.sha256);
      return start.then(function (base) {
        return post({ action: 'save', path: state.file.path, content: state.draft, base_sha256: base });
      }).then(function (d) {
        state.conflict = false;
        return getJson({ project_id: state.project, path: d.file.path }).then(function (f) {
          state.file = f.file; state.draft = f.file.content;
          setStatus(d.changed ? 'Gespeichert als Stand ' + f.file.revision + '.' : 'Keine Änderungen.', 'ok');
        });
      }).catch(function (e) {
        if (e.message === 'file_changed') state.conflict = true;
        throw e;
      });
    });
  }
  function reloadFile() {
    if (!state.file) return;
    var path = state.file.path;
    state.draft = state.file.content; state.conflict = false;
    state.file = null;
    openFile(path);
  }
  function createFile(path) {
    busy(function () {
      return post({ action: 'create', path: path, content: '' }).then(function (d) {
        state.form = null;
        return load().then(function () { return openFile(d.file.path); }).then(function () { setStatus('Datei angelegt.', 'ok'); });
      });
    });
  }
  function renameFile(newPath) {
    busy(function () {
      var wasDirty = dirty(), draft = state.draft;
      var oldPath = state.file.path;
      return post({ action: 'rename', path: state.file.path, new_path: newPath }).then(function (d) {
        state.form = null;
        if (state.preview && state.preview.path === oldPath) {
          if (isPage(d.file.path)) state.preview.path = d.file.path; else closePreview();
        }
        return getJson({ project_id: state.project, path: d.file.path }).then(function (f) {
          // Unsaved edits survive a rename; they still need "Speichern".
          state.file = f.file; state.draft = wasDirty ? draft : f.file.content;
          setStatus('Umbenannt in ' + f.file.path + '.', 'ok');
        });
      });
    });
  }
  function deleteFile() {
    var path = state.file.path;
    if (!window.confirm('„' + path + '“ mit seinem ganzen Verlauf löschen? Das lässt sich nicht rückgängig machen.')) return;
    busy(function () {
      return post({ action: 'delete', path: path }).then(function () {
        state.file = null; state.draft = '';
        setStatus('„' + path + '“ gelöscht.', 'ok');
        return load();
      });
    });
  }
  function restore(revision) {
    if (dirty() && !window.confirm('Ungespeicherte Änderungen verwerfen und Stand ' + revision + ' wiederherstellen?')) return;
    busy(function () {
      return post({ action: 'restore', path: state.file.path, revision: revision }).then(function (d) {
        return getJson({ project_id: state.project, path: d.file.path }).then(function (f) {
          state.file = f.file; state.draft = f.file.content; state.compare = null;
          setStatus('Stand ' + revision + ' ist wiederhergestellt (jetzt Stand ' + f.file.revision + ').', 'ok');
        });
      });
    });
  }
  function compare(revision) {
    busy(function () {
      return getJson({ project_id: state.project, path: state.file.path, revision: revision }).then(function (d) {
        state.compare = { revision: d.revision.revision, source: d.revision.source, content: d.revision.content };
        return false;
      });
    });
  }

  // --- Preview (0.7.75, P10 A) ----------------------------------------------------------------
  // The page comes from api/project_files.php?preview= with "Content-Security-Policy: sandbox":
  // it runs in an origin of its own and never reaches the account. The frame lives outside the
  // re-rendered list, so typing or opening the history does not reload it.
  var previewBox = el('section', 'project-preview');
  previewBox.hidden = true;
  previewBox.setAttribute('aria-label', 'Vorschau');
  panel.appendChild(previewBox);
  var frame = null, previewErrors = null;
  function isPage(path) { return /\.html?$/i.test(String(path || '')); }
  function pages() {
    return state.files.map(function (f) { return f.path; }).filter(isPage).sort();
  }
  function startPage() {
    var list = pages();
    if (list.indexOf('index.html') >= 0) return 'index.html';
    var index = list.filter(function (p) { return /(^|\/)index\.html?$/i.test(p); });
    return index[0] || list[0] || '';
  }
  function previewUrl() {
    return url({ project_id: state.project, preview: state.preview.path, v: state.preview.nonce });
  }
  function openPreview(path) {
    if (!path) return;
    state.preview = { path: path, nonce: Date.now(), errors: [] };
    renderPreview();
  }
  function closePreview() {
    state.preview = null;
    renderPreview();
  }
  function refreshPreview() {
    if (!state.preview) return;
    if (state.loaded && pages().indexOf(state.preview.path) < 0 && !(state.file && state.file.path === state.preview.path)) {
      var next = startPage();
      if (!next) { closePreview(); return; }
      state.preview.path = next;
    }
    state.preview.nonce = Date.now();
    state.preview.errors = [];
    renderPreview();
  }
  function showPreviewErrors() {
    if (!previewErrors || !state.preview) return;
    var list = state.preview.errors;
    previewErrors.hidden = !list.length;
    previewErrors.textContent = '';
    list.forEach(function (e) {
      previewErrors.appendChild(el('li', '', e.message + (e.source ? ' · ' + e.source + (e.line ? ', Zeile ' + e.line : '') : '')));
    });
  }
  function renderPreview() {
    previewBox.textContent = '';
    frame = null; previewErrors = null;
    previewBox.hidden = !state.preview;
    if (!state.preview) return;
    var head = el('div', 'project-preview-head');
    var title = el('div', 'project-preview-title');
    title.appendChild(el('strong', '', 'Vorschau'));
    title.appendChild(el('small', '', state.preview.path + ' · gespeicherter Stand'));
    head.appendChild(title);
    var actions = el('div', 'project-preview-actions');
    actions.appendChild(button('Neu laden', 'project-files-secondary', refreshPreview, { id: 'projectPreviewReload' }));
    var tab = el('a', 'project-files-secondary', 'In neuem Tab');
    tab.href = previewUrl(); tab.target = '_blank'; tab.rel = 'noopener noreferrer';
    tab.id = 'projectPreviewTab';
    actions.appendChild(tab);
    actions.appendChild(button('Schließen', 'project-files-secondary', closePreview, { id: 'projectPreviewClose' }));
    head.appendChild(actions);
    previewBox.appendChild(head);
    previewErrors = el('ul', 'project-preview-errors');
    previewErrors.setAttribute('role', 'status');
    previewErrors.setAttribute('aria-label', 'Fehler in der Seite');
    previewBox.appendChild(previewErrors);
    showPreviewErrors();
    frame = document.createElement('iframe');
    frame.className = 'project-preview-frame';
    frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-modals allow-popups');
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.title = 'Vorschau von ' + state.preview.path;
    frame.src = previewUrl();
    previewBox.appendChild(frame);
  }
  window.addEventListener('message', function (event) {
    if (!frame || !state.preview || event.source !== frame.contentWindow) return;
    var data = event.data;
    if (!data || typeof data !== 'object') return;
    if (data.coreaiPreview === 'open' && typeof data.path === 'string' && pages().indexOf(data.path) >= 0) {
      openPreview(data.path);
    } else if (data.coreaiPreview === 'error' && state.preview.errors.length < MAX_PREVIEW_ERRORS) {
      state.preview.errors.push({ message: String(data.message || 'Fehler').slice(0, 300),
        source: String(data.source || '').slice(0, 200), line: Number(data.line) || 0 });
      showPreviewErrors();
    }
  });

  // --- Rendering ----------------------------------------------------------------------------
  function statusLine() {
    var p = el('p', 'project-files-status' + (state.statusKind ? ' is-' + state.statusKind : ''), state.status);
    p.setAttribute('role', 'status');
    p.setAttribute('aria-live', 'polite');
    return p;
  }
  function pathForm(label, value, submitLabel, onSubmit) {
    var form = el('form', 'project-files-form');
    var field = el('label', 'project-files-form-label');
    field.appendChild(el('span', '', label));
    var input = el('input');
    input.type = 'text'; input.value = value; input.maxLength = 200; input.required = true;
    input.setAttribute('autocomplete', 'off'); input.setAttribute('spellcheck', 'false');
    input.placeholder = 'ordner/datei.html';
    field.appendChild(input);
    form.appendChild(field);
    var actions = el('div', 'project-files-form-actions');
    var ok = el('button', 'project-files-primary', submitLabel); ok.type = 'submit'; ok.disabled = state.busy;
    actions.appendChild(ok);
    actions.appendChild(button('Abbrechen', 'project-files-secondary', function () { state.form = null; setStatus(''); render(); }));
    form.appendChild(actions);
    form.addEventListener('submit', function (e) { e.preventDefault(); if (input.value.trim()) onSubmit(input.value.trim()); });
    setTimeout(function () { input.focus(); input.select(); }, 0);
    return form;
  }
  function tree() {
    var rootNode = { folders: {}, files: [] };
    state.files.forEach(function (f) {
      var parts = f.path.split('/'), node = rootNode;
      for (var i = 0; i < parts.length - 1; i++) {
        var key = parts.slice(0, i + 1).join('/');
        node = node.folders[parts[i]] || (node.folders[parts[i]] = { key: key, folders: {}, files: [] });
      }
      node.files.push(f);
    });
    return rootNode;
  }
  function fileRow(f, depth) {
    var row = button('', 'project-file-row', function () { openFile(f.path); }, { 'data-path': f.path });
    row.style.setProperty('--depth', depth);
    row.appendChild(el('span', 'project-file-kind', kind(f.path)));
    var text = el('span', 'project-file-text');
    text.appendChild(el('strong', '', f.path.split('/').pop()));
    text.appendChild(el('small', '', size(f.size_bytes) + ' · Stand ' + f.revision + ' · ' + when(f.updated_at)));
    row.appendChild(text);
    return row;
  }
  function renderTree(node, depth, into) {
    Object.keys(node.folders).sort().forEach(function (name) {
      var folder = node.folders[name];
      var open = state.folders[folder.key] !== false;
      var head = button('', 'project-folder-row', function () { state.folders[folder.key] = !open; render(); },
        { 'aria-expanded': String(open), 'data-folder': folder.key });
      head.style.setProperty('--depth', depth);
      head.appendChild(el('span', 'project-folder-chevron', open ? '▾' : '▸'));
      head.appendChild(el('span', 'project-folder-name', name));
      into.appendChild(head);
      if (open) renderTree(folder, depth + 1, into);
    });
    node.files.sort(function (a, b) { return a.path.localeCompare(b.path); })
      .forEach(function (f) { into.appendChild(fileRow(f, depth)); });
  }
  function renderList() {
    var bar = el('div', 'project-files-toolbar');
    var search = el('input', 'project-files-search');
    search.type = 'search'; search.placeholder = 'In Dateien suchen'; search.value = state.query;
    search.setAttribute('aria-label', 'In Projektdateien suchen');
    search.addEventListener('input', function () {
      var value = search.value;
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { runSearch(value); }, 300);
    });
    bar.appendChild(search);
    bar.appendChild(button('Neue Datei', 'project-files-secondary', function () {
      state.form = { kind: 'create' }; setStatus(''); render();
    }, { id: 'projectFilesNew' }));
    if (startPage()) {
      bar.appendChild(button('Vorschau', 'project-files-secondary', function () { openPreview(startPage()); },
        { id: 'projectFilesPreview' }));
    }
    if (state.files.length) {
      var zip = el('a', 'project-files-secondary project-files-zip', 'Als ZIP herunterladen');
      zip.href = url({ project_id: state.project, zip: 1 });
      zip.setAttribute('download', '');
      bar.appendChild(zip);
    }
    root.appendChild(bar);
    if (state.form && state.form.kind === 'create') root.appendChild(pathForm('Pfad der neuen Datei', '', 'Anlegen', createFile));
    if (state.files.length) {
      var limits = state.limits || { files: 200, project_bytes: 10485760 };
      root.appendChild(el('p', 'project-files-summary', (state.files.length === 1 ? '1 Datei' : state.files.length + ' Dateien')
        + ' · ' + size(state.bytes) + ' von ' + size(limits.project_bytes)));
    }
    root.appendChild(statusLine());
    if (state.error) { root.appendChild(el('p', 'project-files-empty is-error', state.error)); return; }
    if (state.results) {
      var list = el('div', 'project-files-results');
      list.setAttribute('aria-label', 'Suchergebnisse');
      if (!state.results.length) list.appendChild(el('p', 'project-files-empty', 'Nichts gefunden.'));
      state.results.forEach(function (r) {
        var item = button('', 'project-search-result', function () { openFile(r.path); }, { 'data-path': r.path });
        item.appendChild(el('strong', '', r.path));
        r.lines.forEach(function (l) {
          var line = el('span', 'project-search-line');
          line.appendChild(el('span', 'project-search-number', 'Zeile ' + l.line));
          line.appendChild(el('code', '', l.text));
          item.appendChild(line);
        });
        list.appendChild(item);
      });
      root.appendChild(list);
      return;
    }
    if (!state.files.length) {
      root.appendChild(el('p', 'project-files-empty', state.loaded
        ? 'Noch keine Dateien. Was Mara in den Chats dieses Projekts baut, landet hier. Du kannst auch selbst eine Datei anlegen.'
        : 'Dateien werden geladen …'));
      return;
    }
    var treeBox = el('div', 'project-files-tree');
    treeBox.setAttribute('aria-label', 'Dateibaum');
    renderTree(tree(), 0, treeBox);
    root.appendChild(treeBox);
  }
  function gutterText(text) {
    var count = Math.max(1, text.split('\n').length), out = [];
    for (var i = 1; i <= count; i++) out.push(i);
    return out.join('\n');
  }
  function renderCompare() {
    var box = el('section', 'project-file-compare');
    box.setAttribute('aria-label', 'Vergleich');
    var head = el('div', 'project-file-compare-head');
    head.appendChild(el('strong', '', 'Stand ' + state.compare.revision + ' → aktueller Stand ' + state.file.revision));
    var ops = diffLines(lines(state.compare.content), lines(state.file.content));
    if (ops) {
      var plus = ops.filter(function (o) { return o.t === '+'; }).length, minus = ops.filter(function (o) { return o.t === '-'; }).length;
      head.appendChild(el('span', 'project-diff-count', '+' + plus + ' −' + minus));
    }
    var actions = el('div', 'project-file-compare-actions');
    if (state.compare.revision !== state.file.revision) {
      actions.appendChild(button('Diesen Stand wiederherstellen', 'project-files-primary', function () { restore(state.compare.revision); }));
    }
    actions.appendChild(button('Vergleich schließen', 'project-files-secondary', function () { state.compare = null; render(); }));
    head.appendChild(actions);
    box.appendChild(head);
    if (!ops) { box.appendChild(el('p', 'project-files-empty', 'Die Datei ist zu groß für einen Vergleich.')); return box; }
    if (!ops.some(function (o) { return o.t !== ' '; })) { box.appendChild(el('p', 'project-files-empty', 'Keine Unterschiede.')); return box; }
    var pre = el('div', 'project-diff');
    var i = 0;
    while (i < ops.length) {
      if (ops[i].t === ' ') {
        var j = i;
        while (j < ops.length && ops[j].t === ' ') j++;
        var run = j - i, keepStart = i === 0 ? 0 : 3, keepEnd = j === ops.length ? 0 : 3;
        if (run > keepStart + keepEnd + 2) {
          for (var s = i; s < i + keepStart; s++) pre.appendChild(diffRow(ops[s]));
          pre.appendChild(el('div', 'project-diff-gap', (run - keepStart - keepEnd) + ' unveränderte Zeilen'));
          for (var e = j - keepEnd; e < j; e++) pre.appendChild(diffRow(ops[e]));
        } else {
          for (var t = i; t < j; t++) pre.appendChild(diffRow(ops[t]));
        }
        i = j;
      } else {
        pre.appendChild(diffRow(ops[i])); i++;
      }
    }
    box.appendChild(pre);
    return box;
  }
  function diffRow(op) {
    var row = el('div', 'project-diff-row ' + (op.t === '+' ? 'is-add' : op.t === '-' ? 'is-del' : 'is-same'));
    row.appendChild(el('span', 'project-diff-num', op.a === null ? '' : String(op.a)));
    row.appendChild(el('span', 'project-diff-num', op.b === null ? '' : String(op.b)));
    row.appendChild(el('span', 'project-diff-sign', op.t === ' ' ? '' : op.t === '-' ? '−' : '+'));
    row.appendChild(el('code', 'project-diff-text', op.text));
    return row;
  }
  function renderFile() {
    var f = state.file;
    var head = el('div', 'project-file-head');
    head.appendChild(button('← Alle Dateien', 'project-files-back', backToList));
    var title = el('div', 'project-file-title');
    title.appendChild(el('strong', '', f.path));
    title.appendChild(el('small', '', size(f.size_bytes) + ' · Stand ' + f.revision + ' · ' + when(f.updated_at) + (dirty() ? ' · ungespeichert' : '')));
    head.appendChild(title);
    root.appendChild(head);

    var actions = el('div', 'project-file-actions');
    var saveBtn = button('Speichern', 'project-files-primary', function () { save(false); }, { id: 'projectFileSave' });
    saveBtn.disabled = !dirty() || state.busy;
    actions.appendChild(saveBtn);
    actions.appendChild(button('Verlauf', 'project-files-secondary', function () { state.history = !state.history; state.compare = null; render(); },
      { 'aria-expanded': String(state.history) }));
    if (isPage(f.path)) {
      actions.appendChild(button('Vorschau', 'project-files-secondary', function () { openPreview(f.path); },
        { id: 'projectFilePreview', title: 'Zeigt den gespeicherten Stand' }));
    }
    var dl = el('a', 'project-files-secondary', 'Herunterladen');
    dl.href = url({ project_id: state.project, path: f.path, download: 1 });
    dl.setAttribute('download', '');
    actions.appendChild(dl);
    actions.appendChild(button('Umbenennen', 'project-files-secondary', function () { state.form = { kind: 'rename' }; setStatus(''); render(); }));
    actions.appendChild(button('Löschen', 'project-files-danger', deleteFile));
    root.appendChild(actions);
    if (state.form && state.form.kind === 'rename') root.appendChild(pathForm('Neuer Pfad', f.path, 'Umbenennen', renameFile));
    root.appendChild(statusLine());
    if (state.conflict) {
      var conflict = el('div', 'project-files-conflict');
      conflict.appendChild(button('Meine Fassung trotzdem speichern', 'project-files-primary', function () { save(true); }));
      conflict.appendChild(button('Neu laden (meine Änderungen verwerfen)', 'project-files-secondary', reloadFile));
      root.appendChild(conflict);
    }
    if (state.history) {
      var hist = el('section', 'project-file-history');
      hist.setAttribute('aria-label', 'Verlauf');
      f.revisions.forEach(function (r) {
        var row = el('div', 'project-file-revision' + (r.revision === f.revision ? ' is-current' : ''));
        var text = el('span', 'project-file-text');
        text.appendChild(el('strong', '', 'Stand ' + r.revision + (r.revision === f.revision ? ' (aktuell)' : '')));
        text.appendChild(el('small', '', (SOURCE[r.source] || r.source) + ' · ' + when(r.created_at) + ' · ' + size(r.size_bytes)
          + (r.path !== f.path ? ' · damals ' + r.path : '')));
        row.appendChild(text);
        if (r.revision !== f.revision) {
          row.appendChild(button('Vergleichen', 'project-files-secondary', function () { compare(r.revision); }, { 'data-revision': String(r.revision) }));
        }
        hist.appendChild(row);
      });
      if (f.revisions.length >= (state.limits ? state.limits.revisions : 20)) {
        hist.appendChild(el('p', 'project-files-hint', 'Es bleiben die letzten ' + f.revisions.length + ' Stände.'));
      }
      root.appendChild(hist);
    }
    if (state.compare) root.appendChild(renderCompare());

    var wrap = el('div', 'project-file-editor-wrap');
    var gutter = el('pre', 'project-file-gutter', gutterText(state.draft));
    gutter.setAttribute('aria-hidden', 'true');
    var editor = el('textarea', 'project-file-editor');
    editor.value = state.draft;
    editor.spellcheck = false;
    editor.setAttribute('wrap', 'off');
    editor.setAttribute('aria-label', 'Inhalt von ' + f.path);
    editor.addEventListener('input', function () {
      var was = dirty();
      state.draft = editor.value;
      gutter.textContent = gutterText(state.draft);
      if (was !== dirty()) { saveBtn.disabled = !dirty() || state.busy; title.lastChild.textContent = size(f.size_bytes) + ' · Stand ' + f.revision + ' · ' + when(f.updated_at) + (dirty() ? ' · ungespeichert' : ''); }
    });
    editor.addEventListener('scroll', function () { gutter.scrollTop = editor.scrollTop; });
    editor.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && String(e.key).toLowerCase() === 's') { e.preventDefault(); if (dirty()) save(false); }
    });
    wrap.appendChild(gutter);
    wrap.appendChild(editor);
    root.appendChild(wrap);
  }
  function render() {
    var editor = root.querySelector('.project-file-editor');
    var keep = editor ? { start: editor.selectionStart, end: editor.selectionEnd, top: editor.scrollTop, focus: document.activeElement === editor } : null;
    root.textContent = '';
    root.classList.toggle('is-busy', state.busy);
    if (state.file) renderFile(); else renderList();
    var next = root.querySelector('.project-file-editor');
    if (next && keep) {
      next.scrollTop = keep.top;
      var gutter = root.querySelector('.project-file-gutter');
      if (gutter) gutter.scrollTop = keep.top;
      if (keep.focus) { next.focus(); try { next.setSelectionRange(keep.start, keep.end); } catch (e) {} }
    }
  }

  // --- Public -------------------------------------------------------------------------------
  /** Unsaved edits are only dropped after a confirmation; false keeps the user here. */
  function leave() {
    if (!dirty()) return true;
    return window.confirm('Ungespeicherte Änderungen an „' + state.file.path + '“ verwerfen?');
  }
  function reset() {
    state.project = ''; state.files = []; state.file = null; state.draft = ''; state.query = ''; state.results = null;
    state.form = null; state.compare = null; state.loaded = false; state.conflict = false; setStatus('');
    if (state.preview) closePreview();
  }
  function open(projectId) {
    if (projectId !== state.project) { reset(); state.project = projectId || ''; }
    if (!state.project) return Promise.resolve();
    render();
    if (state.file) return Promise.resolve();
    return load().then(render);
  }
  window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
  function dirtyProject() { return dirty() ? state.project : ''; }
  window.CoreAIProjectFiles = { open: open, leave: leave, reset: reset, dirtyProject: dirtyProject, diffLines: diffLines };
})();
