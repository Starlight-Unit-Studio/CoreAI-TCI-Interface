/* CoreAI 0.7.69 (P09): the tab "Dateien" on the project page.
 * 0.7.75 (P10 A): the preview of an HTML page of the project in a sandboxed frame.
 * 0.7.77 (P10 B): commands on the saved files in the sandbox container, with their output. 
 * 0.7.78 (P10 C): processes that keep running there (a program, a shell, a development server)
 *   with live output, input and Beenden, and the preview of a development server.
 * 0.7.80 (P11 A): versions and working states (Sichern, Verlauf, Änderungen, Wiederherstellen,
 *   Arbeitsstände with their own files) and the ZIP with a Git repository.
 * 0.7.81 (P11 B): merging another working state into the active one, with a preview and a
 *   choice for every conflict (my version, the other one, or a text of one's own).
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
    form: null, status: '', statusKind: '', conflict: false, busy: false, preview: null,
    run: { open: false, list: [], current: null, command: '', suggestion: '', allowed: true, ready: true, error: '', sending: false },
    proc: freshProc(), vcs: freshVcs() };
  function freshVcs() {
    return { open: false, ready: true, data: null, error: '', busy: false, message: '', view: null, form: null, merge: null };
  }
  function freshProc() {
    return { open: false, list: [], current: null, since: 0, command: '', port: '', suggestion: { command: '', port: null },
      allowed: true, ready: true, error: '', sending: false };
  }
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
    csrf_failed: 'Die Sitzung wurde geändert. Bitte die Seite neu laden.',
    invalid_command: 'Bitte einen Befehl in einer Zeile eingeben (höchstens 400 Zeichen).',
    run_busy: 'In diesem Projekt läuft schon ein Befehl.',
    run_limit: 'Für diese Stunde sind es genug Läufe. Bitte später noch einmal.',
    no_files: 'Das Projekt hat noch keine Dateien.',
    python_disabled: 'Ausführen gehört zum Python-Werkzeug, und das ist für dieses Konto ausgeschaltet.',
    run_not_found: 'Diesen Lauf gibt es nicht mehr.',
    project_runs_migration_required: 'Die Datenbank-Migration 047 fehlt. Bitte das Update einspielen.',
    invalid_port: 'Der Port muss eine Zahl von 1024 bis 65535 sein.',
    proc_busy: 'In diesem Projekt läuft schon ein Prozess.',
    proc_active_limit: 'In deinen Projekten laufen schon zwei Prozesse. Bitte zuerst einen beenden.',
    proc_limit: 'Für diese Stunde sind es genug Starts. Bitte später noch einmal.',
    proc_not_running: 'Der Prozess läuft nicht mehr.',
    proc_not_found: 'Diesen Prozess gibt es nicht mehr.',
    proc_no_port: 'Für diesen Prozess ist kein Port angegeben.',
    invalid_input: 'Die Eingabe ist zu lang (höchstens 2000 Zeichen).',
    input_busy: 'Es warten schon viele Eingaben. Bitte einen Moment.',
    project_procs_migration_required: 'Die Datenbank-Migration 048 fehlt. Bitte das Update einspielen.',
    nothing_to_commit: 'Es gibt keine ungesicherten Änderungen.',
    invalid_message: 'Bitte kurz beschreiben, was sich geändert hat (höchstens 200 Zeichen).',
    invalid_branch_name: 'Der Name darf Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich und / enthalten und beginnt mit einem Buchstaben oder einer Ziffer.',
    branch_exists: 'Diesen Arbeitsstand gibt es schon.',
    branch_limit: 'Ein Projekt hat höchstens 10 Arbeitsstände.',
    branch_active: 'Der aktive Arbeitsstand kann nicht gelöscht werden.',
    branch_not_found: 'Diesen Arbeitsstand gibt es nicht mehr.',
    commit_not_found: 'Diese Version gibt es nicht mehr.',
    history_full: 'Der Verlauf ist voll (200 Versionen). Lösche einen Arbeitsstand, den du nicht mehr brauchst.',
    already_current: 'Genau dieser Stand ist schon da.',
    project_vcs_migration_required: 'Die Datenbank-Migration 049 fehlt. Bitte das Update einspielen.',
    already_merged: 'Dieser Arbeitsstand ist schon ganz enthalten. Es gibt nichts zusammenzuführen.',
    merge_same: 'Ein Arbeitsstand kann nicht mit sich selbst zusammengeführt werden.',
    merge_changed: 'Inzwischen hat sich etwas geändert. Bitte die Zusammenführung noch einmal prüfen.',
    merge_unresolved: 'Bitte für jeden Konflikt eine Lösung wählen.',
    conflict_markers: 'Im Text stehen noch Konfliktmarker (<<<<<<< oder >>>>>>>). Bitte die Stelle fertig zusammenführen.',
    invalid_resolution: 'Diese Lösung passt nicht zu den Konflikten. Bitte die Zusammenführung neu öffnen.',
    project_merge_migration_required: 'Die Datenbank-Migration 050 fehlt. Bitte das Update einspielen.'
  };
  var MAX_PREVIEW_ERRORS = 5;
  var SOURCE = { build: 'Von Mara gebaut', mara: 'Von Mara geändert', create: 'Neu angelegt', edit: 'Bearbeitet', rename: 'Umbenannt',
    restore: 'Wiederhergestellt', branch: 'Arbeitsstand gewechselt', merge: 'Zusammengeführt' };

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
    if (state.preview.proc) {
      return url({ project_id: state.project, proc_preview: state.preview.proc, k: state.preview.key, path: state.preview.path, v: state.preview.nonce });
    }
    return url({ project_id: state.project, preview: state.preview.path, v: state.preview.nonce });
  }
  function openPreview(path) {
    if (!path) return;
    state.preview = { path: path, nonce: Date.now(), errors: [] };
    renderPreview();
  }
  // 0.7.78 (P10 C): a page of the development server that runs in a process.
  // Messages count only with the random key of this preview: a foreign page that a link opened
  // in the same frame does not know it and cannot reach the server through CoreAI.
  function previewKey() {
    var bytes = new Uint8Array(18);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function openServerPreview(procId, path, port) {
    var same = state.preview && state.preview.proc === procId ? state.preview.key : '';
    state.preview = { proc: procId, port: port, key: same || previewKey(), path: path || '/', nonce: Date.now(), errors: [] };
    renderPreview();
  }
  function closePreview() {
    state.preview = null;
    renderPreview();
  }
  function refreshPreview() {
    if (!state.preview) return;
    if (state.preview.proc) {
      // The worker copies a saved file into the running server within about two seconds.
      var shown = state.preview;
      setTimeout(function () {
        if (state.preview !== shown) return;
        shown.nonce = Date.now(); shown.errors = [];
        renderPreview();
      }, 2500);
      return;
    }
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
    title.appendChild(el('small', '', state.preview.proc ? state.preview.path + ' · Entwicklungsserver auf Port ' + state.preview.port
      : state.preview.path + ' · gespeicherter Stand'));
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
  // --- Runs (0.7.77, P10 B) -------------------------------------------------------------------
  // A command runs in the sandbox container on a copy of the saved files; its own changes to
  // files stay there. The output is the real stdout and stderr with the exit code.
  var runBox = el('section', 'project-run');
  runBox.hidden = true;
  runBox.setAttribute('aria-label', 'Ausführen');
  panel.insertBefore(runBox, previewBox);
  var runTimer = 0, runInput = null;
  var RUN_STATE = { queued: 'Wartet auf den Sandbox-Dienst …', running: 'Läuft …', cancelled: 'Abgebrochen' };
  var RUN_ERROR = {
    timeout: 'Nach 120 s abgebrochen (Zeitlimit).',
    no_worker: 'Der Sandbox-Dienst hat den Lauf nicht übernommen. Läuft der Python-Dienst auf dem Server?',
    worker_lost: 'Der Sandbox-Dienst hat sich während des Laufs nicht mehr gemeldet.',
    docker_missing: 'Docker ist auf dem Server nicht erreichbar.',
    sandbox_stop_unconfirmed: 'Der Sandbox-Container ließ sich nicht sicher beenden.',
    cancelled: 'Abgebrochen.'
  };
  function seconds(ms) { return (Math.round((Number(ms) || 0) / 100) / 10).toFixed(1).replace('.', ',') + ' s'; }
  function runLine(r) {
    if (!r) return '';
    if (r.status === 'done') return 'Exitcode ' + r.exit_code + ' · ' + seconds(r.duration_ms) + (r.files_count ? ' · ' + r.files_count + ' Dateien' : '');
    if (r.status === 'error') return RUN_ERROR[r.error] || ('Fehler: ' + r.error);
    if (r.status === 'cancelled') return 'Abgebrochen' + (r.duration_ms ? ' nach ' + seconds(r.duration_ms) : '') + '.';
    return (r.cancel_requested ? 'Wird abgebrochen …' : RUN_STATE[r.status]) || r.status;
  }
  function runBadge(r) {
    if (r.status === 'done') return r.exit_code === 0 ? 'is-ok' : 'is-fail';
    if (r.status === 'queued' || r.status === 'running') return 'is-active';
    return 'is-fail';
  }
  function stopRunPoll() { if (runTimer) { clearTimeout(runTimer); runTimer = 0; } }
  // The 1 s polls of a command and a process run only while the project page
  // is open and the browser tab is visible; they continue when it is shown again.
  var pollsPaused = false;
  function pollsAllowed() { return !pollsPaused && !document.hidden; }
  function pausePolls() { pollsPaused = true; stopRunPoll(); stopProcPoll(); }
  function resumePolls() {
    if (!pollsAllowed() || !state.project) return;
    var r = state.run.current, p = state.proc.current;
    if (state.run.open && r && !runTimer && (r.status === 'queued' || r.status === 'running')) showRun(r.id);
    if (state.proc.open && procActive(p) && !procTimer) showProc(p.id);
  }
  if (typeof document.addEventListener === 'function') {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stopRunPoll(); stopProcPoll(); } else resumePolls();
    });
  }
  function loadRuns() {
    return getJson({ project_id: state.project, runs: 1 }).then(function (d) {
      state.run.list = d.runs || []; state.run.ready = d.ready !== false; state.run.allowed = d.allowed !== false;
      state.run.suggestion = d.suggestion || ''; state.csrf = d.csrf_token || state.csrf;
      if (!state.run.command) state.run.command = state.run.suggestion;
      var active = state.run.list.filter(function (r) { return r.status === 'queued' || r.status === 'running'; })[0];
      if (active && (!state.run.current || state.run.current.id !== active.id)) return showRun(active.id);
      if (!state.run.current && state.run.list.length) return showRun(state.run.list[0].id);
    }).catch(function (e) { state.run.error = message(e); });
  }
  function showRun(id) {
    stopRunPoll();
    return getJson({ project_id: state.project, run: id }).then(function (d) {
      state.run.current = d.run; state.run.error = '';
      renderRun();
      if (d.run.status === 'queued' || d.run.status === 'running') {
        if (pollsAllowed()) { stopRunPoll(); runTimer = setTimeout(function () { showRun(id); }, 1000); }
      } else {
        var known = state.run.list.some(function (r) { return r.id === d.run.id && r.status === d.run.status; });
        if (!known) getJson({ project_id: state.project, runs: 1 }).then(function (x) { state.run.list = x.runs || []; renderRun(); }).catch(function () {});
      }
    }).catch(function (e) { state.run.error = message(e); renderRun(); });
  }
  function openRuns() {
    state.run.open = true; state.run.error = '';
    renderRun();
    loadRuns().then(renderRun);
  }
  function closeRuns() { state.run.open = false; stopRunPoll(); renderRun(); }
  function startRun(command) {
    if (state.run.sending) return;
    state.run.sending = true; state.run.error = ''; state.run.command = command;
    renderRun();
    post({ action: 'run', command: command }).then(function (d) {
      state.run.list = [d.run].concat(state.run.list.filter(function (r) { return r.id !== d.run.id; }));
      state.run.current = d.run;
      return showRun(d.run.id);
    }).catch(function (e) { state.run.error = message(e); })
      .then(function () { state.run.sending = false; renderRun(); });
  }
  function cancelRun() {
    var r = state.run.current;
    if (!r) return;
    post({ action: 'run_cancel', run_id: r.id }).then(function (d) { state.run.current = d.run; renderRun(); })
      .catch(function (e) { state.run.error = message(e); renderRun(); });
  }
  function outputBlock(label, text, cls) {
    var box = el('div', 'project-run-block ' + cls);
    box.appendChild(el('strong', '', label));
    box.appendChild(el('pre', 'project-run-output', text));
    return box;
  }
  function renderRun() {
    var keep = runInput && document.activeElement === runInput ? { value: runInput.value, start: runInput.selectionStart } : null;
    runBox.textContent = ''; runInput = null;
    runBox.hidden = !state.run.open;
    if (!state.run.open) return;
    var head = el('div', 'project-run-head');
    var title = el('div', 'project-preview-title');
    title.appendChild(el('strong', '', 'Ausführen'));
    title.appendChild(el('small', '', 'Im Sandbox-Container mit den gespeicherten Dateien. Was der Befehl an Dateien ändert, bleibt dort.'));
    head.appendChild(title);
    head.appendChild(button('Schließen', 'project-files-secondary', closeRuns, { id: 'projectRunClose' }));
    runBox.appendChild(head);
    var form = el('form', 'project-run-form');
    runInput = el('input', 'project-run-command');
    runInput.type = 'text'; runInput.value = keep ? keep.value : state.run.command; runInput.maxLength = 400;
    runInput.placeholder = state.run.suggestion || 'z. B. python -m unittest -v';
    runInput.setAttribute('aria-label', 'Befehl'); runInput.spellcheck = false; runInput.autocomplete = 'off';
    runInput.addEventListener('input', function () { state.run.command = runInput.value; });
    form.appendChild(runInput);
    var r = state.run.current, active = !!r && (r.status === 'queued' || r.status === 'running');
    var go = el('button', 'project-files-primary', 'Ausführen');
    go.type = 'submit'; go.id = 'projectRunStart';
    go.disabled = active || state.run.sending || !state.run.allowed || !state.run.ready;
    form.appendChild(go);
    if (active) form.appendChild(button('Abbrechen', 'project-files-danger', cancelRun, { id: 'projectRunCancel' }));
    form.addEventListener('submit', function (e) { e.preventDefault(); var c = runInput.value.trim(); if (c) startRun(c); });
    runBox.appendChild(form);
    if (!state.run.ready) runBox.appendChild(el('p', 'project-files-status is-error', ERR.project_runs_migration_required));
    else if (!state.run.allowed) runBox.appendChild(el('p', 'project-files-status is-error', ERR.python_disabled));
    if (state.run.error) runBox.appendChild(el('p', 'project-files-status is-error', state.run.error));
    if (r) {
      var status = el('p', 'project-run-status ' + runBadge(r));
      status.setAttribute('role', 'status');
      status.appendChild(el('code', '', r.command));
      status.appendChild(el('span', '', ' · ' + runLine(r)));
      runBox.appendChild(status);
      if (r.stdout) runBox.appendChild(outputBlock('Ausgabe', r.stdout, 'is-stdout'));
      if (r.stderr) runBox.appendChild(outputBlock('Fehlerausgabe', r.stderr, 'is-stderr'));
      if (!active && !r.stdout && !r.stderr && r.status === 'done') runBox.appendChild(el('p', 'project-files-hint', 'Keine Ausgabe.'));
    }
    if (state.run.list.length > 1 || (state.run.list.length === 1 && (!r || state.run.list[0].id !== r.id))) {
      var list = el('div', 'project-run-list');
      list.setAttribute('aria-label', 'Letzte Läufe');
      list.appendChild(el('strong', '', 'Letzte Läufe'));
      state.run.list.forEach(function (item) {
        var b = button('', 'project-run-item ' + runBadge(item) + (r && r.id === item.id ? ' is-current' : ''), function () { showRun(item.id); },
          { 'data-run': String(item.id) });
        b.appendChild(el('code', '', item.command));
        b.appendChild(el('small', '', runLine(item) + (item.from_chat ? ' · von Mara' : '')));
        list.appendChild(b);
      });
      runBox.appendChild(list);
    }
    if (keep) { runInput.focus(); try { runInput.setSelectionRange(keep.start, keep.start); } catch (e) {} }
  }

  // --- Processes (0.7.78, P10 C) ----------------------------------------------------------------
  // A command keeps running in the sandbox container: a program, a shell or a development server.
  // Its output arrives every second (only what is new), lines typed here go to its stdin, and
  // "Beenden" ends it with its real exit code. With a port, the server's pages show in the preview.
  var procBox = el('section', 'project-proc');
  procBox.hidden = true;
  procBox.setAttribute('aria-label', 'Prozesse');
  panel.insertBefore(procBox, previewBox);
  var procTimer = 0, procOutput = null, procInput = null, procShown = '', fetchesOpen = 0;
  var PROC_ERROR = {
    no_worker: 'Der Sandbox-Dienst hat den Prozess nicht übernommen. Läuft der Python-Dienst auf dem Server?',
    worker_lost: 'Der Sandbox-Dienst hat sich nicht mehr gemeldet.',
    worker_restart: 'Der Sandbox-Dienst wurde neu gestartet; der Prozess lief dabei nicht weiter.',
    docker_missing: 'Docker ist auf dem Server nicht erreichbar.',
    docker_runtime_error: 'Der Sandbox-Container ließ sich nicht starten.',
    no_files: 'Das Projekt hat keine Dateien.',
    unsafe_path: 'Ein Dateipfad im Projekt wurde abgelehnt.',
    sandbox_stop_unconfirmed: 'Der Sandbox-Container ließ sich nicht sicher beenden.'
  };
  var PROC_STOPPED = { idle: ' · niemand hat mehr hingesehen', time_limit: ' · längste Laufzeit erreicht', worker_stopped: ' · Sandbox-Dienst neu gestartet' };
  function procActive(p) { return !!p && (p.status === 'queued' || p.status === 'running'); }
  function procLine(p) {
    if (!p) return '';
    if (p.status === 'queued') return 'Wartet auf den Sandbox-Dienst …';
    if (p.status === 'running') return p.stop_requested ? 'Wird beendet …' : 'Läuft' + (p.port ? ' · Port ' + p.port : '');
    if (p.status === 'exited') return 'Beendet mit Exitcode ' + p.exit_code;
    if (p.status === 'stopped') return 'Beendet' + (p.exit_code !== null && p.exit_code !== undefined ? ' · Exitcode ' + p.exit_code : '') + (PROC_STOPPED[p.error] || '');
    return PROC_ERROR[p.error] || ('Fehler: ' + p.error);
  }
  function procBadge(p) {
    if (procActive(p)) return 'is-active';
    if (p.status === 'exited') return p.exit_code === 0 ? 'is-ok' : 'is-fail';
    return p.status === 'stopped' ? 'is-stopped' : 'is-fail';
  }
  function stopProcPoll() { if (procTimer) { clearTimeout(procTimer); procTimer = 0; } }
  function loadProcs() {
    return getJson({ project_id: state.project, procs: 1 }).then(function (d) {
      var s = state.proc;
      s.list = d.procs || []; s.ready = d.ready !== false; s.allowed = d.allowed !== false;
      s.suggestion = d.suggestion || { command: '', port: null }; state.csrf = d.csrf_token || state.csrf;
      if (!s.command) { s.command = s.suggestion.command || ''; s.port = s.suggestion.port ? String(s.suggestion.port) : ''; }
      var active = s.list.filter(procActive)[0];
      if (active && (!s.current || s.current.id !== active.id)) return showProc(active.id);
      if (!s.current && s.list.length) return showProc(s.list[0].id);
    }).catch(function (e) { state.proc.error = message(e); });
  }
  function showProc(id) {
    stopProcPoll();
    var s = state.proc;
    if (!s.current || s.current.id !== id) { s.current = null; s.since = 0; procShown = ''; }
    return getJson({ project_id: state.project, proc: id, since: s.since }).then(function (d) {
      var p = d.proc, before = s.current;
      var text = (p.output_cut && s.since > 0 ? '\n[… ältere Ausgabe ist nicht mehr gespeichert …]\n' : '') + (p.output || '');
      procShown = (p.output_from === 0 || s.since === 0 ? '' : procShown) + text;
      if (procShown.length > 200000) procShown = procShown.slice(-150000);
      s.since = p.output_end; s.current = p; s.error = '';
      if (!before || before.id !== p.id || before.status !== p.status || before.stop_requested !== p.stop_requested || !procOutput) renderProc();
      else appendOutput();
      if (procActive(p)) {
        if (pollsAllowed()) { stopProcPoll(); procTimer = setTimeout(function () { showProc(id); }, 1000); }
      } else {
        var known = s.list.some(function (x) { return x.id === p.id && x.status === p.status; });
        if (!known) getJson({ project_id: state.project, procs: 1 }).then(function (x) { s.list = x.procs || []; renderProc(); }).catch(function () {});
      }
    }).catch(function (e) { s.error = message(e); renderProc(); });
  }
  function appendOutput() {
    if (!procOutput) return;
    var atEnd = procOutput.scrollHeight - procOutput.scrollTop - procOutput.clientHeight < 24;
    if (procOutput.textContent !== procShown) procOutput.textContent = procShown;
    if (atEnd) procOutput.scrollTop = procOutput.scrollHeight;
  }
  function openProcs() {
    state.proc.open = true; state.proc.error = '';
    renderProc();
    loadProcs().then(renderProc);
  }
  function closeProcs() { state.proc.open = false; stopProcPoll(); renderProc(); }
  function startProc(command, port) {
    var s = state.proc;
    if (s.sending) return;
    s.sending = true; s.error = ''; s.command = command; s.port = port;
    renderProc();
    post({ action: 'proc_start', command: command, port: port === '' ? null : port }).then(function (d) {
      s.list = [d.proc].concat(s.list.filter(function (x) { return x.id !== d.proc.id; }));
      s.current = null;
      return showProc(d.proc.id);
    }).catch(function (e) { s.error = message(e); })
      .then(function () { s.sending = false; renderProc(); });
  }
  function stopProc() {
    var p = state.proc.current;
    if (!p) return;
    post({ action: 'proc_stop', proc_id: p.id }).then(function (d) {
      state.proc.current = Object.assign({}, state.proc.current, { status: d.proc.status, stop_requested: d.proc.stop_requested,
        exit_code: d.proc.exit_code, error: d.proc.error });
      renderProc();
      if (procActive(d.proc)) showProc(d.proc.id);
    }).catch(function (e) { state.proc.error = message(e); renderProc(); });
  }
  function sendInput(text) {
    var p = state.proc.current;
    if (!p || p.status !== 'running') return;
    post({ action: 'proc_input', proc_id: p.id, text: text, newline: true }).then(function () {
      if (procInput) procInput.value = '';
    }).catch(function (e) { state.proc.error = message(e); renderProc(); });
  }
  // A request of the preview page to its development server, answered through the API.
  function serverFetch(target, data) {
    var reply = function (answer) { try { target.postMessage(Object.assign({ coreaiPreviewReply: data.id }, answer), '*'); } catch (e) {} };
    var method = String(data.method || 'GET').toUpperCase();
    if (typeof data.id !== 'string' || typeof data.path !== 'string' || !/^\/[\x21-\x7e]{0,999}$/.test(data.path)
        || ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'].indexOf(method) < 0
        || (data.body !== null && data.body !== undefined && (typeof data.body !== 'string' || data.body.length > 1048576))) {
      reply({ error: 'Diese Anfrage geht in der Vorschau nicht.' });
      return;
    }
    if (fetchesOpen >= 6) { reply({ error: 'Zu viele Anfragen gleichzeitig.' }); return; }
    fetchesOpen++;
    post({ action: 'proc_fetch', proc_id: state.preview.proc, method: method, path: data.path,
      body: typeof data.body === 'string' ? data.body : null, content_type: String(data.type || '').slice(0, 160) })
      .then(function (d) { reply(d.response); })
      .catch(function (e) { reply({ error: message(e) }); })
      .then(function () { fetchesOpen--; });
  }
  function renderProc() {
    var s = state.proc;
    var keep = procInput && document.activeElement === procInput ? procInput.value : null;
    var keepCommand = procBox.querySelector('.project-proc-command');
    keepCommand = keepCommand && document.activeElement === keepCommand;
    procBox.textContent = ''; procOutput = null; procInput = null;
    procBox.hidden = !s.open;
    if (!s.open) return;
    var head = el('div', 'project-run-head');
    var title = el('div', 'project-preview-title');
    title.appendChild(el('strong', '', 'Prozesse'));
    title.appendChild(el('small', '', 'Läuft weiter im Sandbox-Container: ein Programm, eine Shell oder ein Entwicklungsserver. Gespeicherte Änderungen kommen nach wenigen Sekunden an.'));
    head.appendChild(title);
    head.appendChild(button('Schließen', 'project-files-secondary', closeProcs, { id: 'projectProcClose' }));
    procBox.appendChild(head);
    var p = s.current, active = procActive(p);
    if (!active) {
      var form = el('form', 'project-run-form');
      var cmd = el('input', 'project-run-command project-proc-command');
      cmd.type = 'text'; cmd.value = s.command; cmd.maxLength = 400;
      cmd.placeholder = s.suggestion.command || 'z. B. python -m http.server 8000';
      cmd.setAttribute('aria-label', 'Befehl'); cmd.spellcheck = false; cmd.autocomplete = 'off';
      cmd.addEventListener('input', function () { s.command = cmd.value; });
      form.appendChild(cmd);
      var port = el('input', 'project-proc-port');
      port.type = 'text'; port.inputMode = 'numeric'; port.value = s.port; port.maxLength = 5; port.placeholder = 'Port';
      port.setAttribute('aria-label', 'Port des Servers für die Vorschau (leer lassen ohne Server)');
      port.addEventListener('input', function () { s.port = port.value.trim(); });
      form.appendChild(port);
      var go = el('button', 'project-files-primary', 'Starten');
      go.type = 'submit'; go.id = 'projectProcStart';
      go.disabled = s.sending || !s.allowed || !s.ready;
      form.appendChild(go);
      form.addEventListener('submit', function (e) { e.preventDefault(); var c = cmd.value.trim(); if (c) startProc(c, port.value.trim()); });
      procBox.appendChild(form);
      if (keepCommand) cmd.focus();
    }
    if (!s.ready) procBox.appendChild(el('p', 'project-files-status is-error', ERR.project_procs_migration_required));
    else if (!s.allowed) procBox.appendChild(el('p', 'project-files-status is-error', ERR.python_disabled));
    if (s.error) procBox.appendChild(el('p', 'project-files-status is-error', s.error));
    if (p) {
      var status = el('div', 'project-run-status project-proc-status ' + procBadge(p));
      status.setAttribute('role', 'status');
      var line = el('p', '');
      line.appendChild(el('code', '', p.command));
      line.appendChild(el('span', '', ' · ' + procLine(p)));
      status.appendChild(line);
      if (active) {
        var actions = el('div', 'project-proc-actions');
        if (p.status === 'running' && p.port && !p.stop_requested) {
          actions.appendChild(button('Vorschau', 'project-files-secondary', function () { openServerPreview(p.id, '/', p.port); },
            { id: 'projectProcPreview' }));
        }
        if (!p.stop_requested) actions.appendChild(button('Beenden', 'project-files-danger', stopProc, { id: 'projectProcStop' }));
        status.appendChild(actions);
      }
      procBox.appendChild(status);
      procOutput = el('pre', 'project-run-output project-proc-output', '');
      procOutput.setAttribute('aria-label', 'Ausgabe des Prozesses');
      procOutput.setAttribute('aria-live', 'off');
      procBox.appendChild(procOutput);
      procOutput.textContent = procShown;
      procOutput.scrollTop = procOutput.scrollHeight;
      if (p.status === 'running' && !p.stop_requested) {
        var send = el('form', 'project-run-form project-proc-send');
        procInput = el('input', 'project-run-command project-proc-input');
        procInput.type = 'text'; procInput.maxLength = 2000; procInput.autocomplete = 'off'; procInput.spellcheck = false;
        procInput.placeholder = 'Eingabe an das Programm, Enter sendet';
        procInput.setAttribute('aria-label', 'Eingabe an das Programm');
        if (keep !== null) procInput.value = keep;
        send.appendChild(procInput);
        var sendButton = el('button', 'project-files-secondary', 'Senden');
        sendButton.type = 'submit'; sendButton.id = 'projectProcSend';
        send.appendChild(sendButton);
        send.addEventListener('submit', function (e) { e.preventDefault(); sendInput(procInput.value); });
        procBox.appendChild(send);
        if (keep !== null) procInput.focus();
      }
    }
    if (s.list.length > 1 || (s.list.length === 1 && (!p || s.list[0].id !== p.id))) {
      var list = el('div', 'project-run-list');
      list.setAttribute('aria-label', 'Letzte Prozesse');
      list.appendChild(el('strong', '', 'Letzte Prozesse'));
      s.list.forEach(function (item) {
        var b = button('', 'project-run-item project-proc-item ' + procBadge(item) + (p && p.id === item.id ? ' is-current' : ''),
          function () { showProc(item.id); }, { 'data-proc': String(item.id) });
        b.appendChild(el('code', '', item.command));
        b.appendChild(el('small', '', procLine(item)));
        list.appendChild(b);
      });
      procBox.appendChild(list);
    }
  }

  // --- Versions and working states (0.7.80, P11 A) -------------------------------------------------
  // "Sichern" keeps the state of every file as a version. Each working state has its own files:
  // unsaved changes stay with the working state they were made in. Restoring saves unsaved
  // changes first and adds the old state as a new version, so no version is lost.
  var vcsBox = el('section', 'project-vcs');
  vcsBox.hidden = true;
  vcsBox.setAttribute('aria-label', 'Versionen');
  panel.insertBefore(vcsBox, previewBox);
  var vcsMessage = null;
  var AUTHOR = { person: 'von dir', mara: 'von Mara', auto: 'automatisch' };
  var CHANGE = { added: 'neu', modified: 'geändert', deleted: 'gelöscht' };
  function vcsLoad() {
    return getJson({ project_id: state.project, vcs: 1 }).then(function (d) {
      state.csrf = d.csrf_token || state.csrf;
      state.vcs.ready = d.ready !== false;
      state.vcs.data = d.ready === false ? null : d;
      state.vcs.error = '';
    }).catch(function (e) { state.vcs.error = message(e); });
  }
  function openVcs() {
    // Never show an old state: files may have changed meanwhile (another tab, Mara, a build).
    state.vcs.open = true; state.vcs.error = ''; state.vcs.view = null; state.vcs.data = null; state.vcs.merge = null;
    renderVcs();
    vcsLoad().then(renderVcs);
  }
  function closeVcs() { state.vcs.open = false; renderVcs(); }
  // After a switch or a restore the project files are different: the list and the preview follow.
  function vcsFilesChanged() {
    state.file = null; state.draft = ''; state.compare = null; state.history = false;
    return load().then(function () { render(); refreshPreview(); });
  }
  function vcsAction(body, changesFiles, done) {
    if (state.vcs.busy) return;
    if (changesFiles && !leave()) return;
    state.vcs.busy = true; state.vcs.error = '';
    renderVcs();
    post(body).then(function (d) {
      state.vcs.data = Object.assign({ ready: true }, d.state || state.vcs.data);
      state.vcs.view = null;
      if (done) done(d);
      return changesFiles ? vcsFilesChanged() : null;
    }).catch(function (e) { state.vcs.error = message(e); })
      .then(function () { state.vcs.busy = false; renderVcs(); });
  }
  function vcsCommit() {
    var text = vcsMessage ? vcsMessage.value.trim() : '';
    if (!text) { state.vcs.error = ERR.invalid_message; renderVcs(); return; }
    // Saving reads the files as they are on the server: a draft in the editor is not part of it.
    if (dirty() && !window.confirm('Im Editor ist eine Änderung an „' + state.file.path + '“ noch nicht gespeichert. Ohne sie sichern?')) return;
    vcsAction({ action: 'vcs_commit', message: text }, false, function () { state.vcs.message = ''; if (vcsMessage) vcsMessage.value = ''; });
  }
  function vcsSwitch(branch) { vcsAction({ action: 'vcs_switch', branch_id: branch.id }, true); }
  function vcsDelete(branch) {
    if (!window.confirm('Arbeitsstand „' + branch.name + '“ löschen? Versionen, die nur dort liegen, gehen mit.')) return;
    vcsAction({ action: 'vcs_branch_delete', branch_id: branch.id }, false);
  }
  function vcsRestore(commit) {
    var note = state.vcs.data && !state.vcs.data.status.clean ? ' Ungesicherte Änderungen werden vorher als eigene Version gesichert.' : '';
    if (!window.confirm('„' + commit.message + '“ wiederherstellen?' + note)) return;
    vcsAction({ action: 'vcs_restore', commit_id: commit.id }, true);
  }
  // 0.7.81 (P11 B): merging another working state into the active one. The preview shows what
  // comes over and every conflict; nothing changes before "Zusammenführen", and every conflict
  // needs a choice: my version, the other one, or a text of one's own.
  function vcsMergeOpen(branch, note) {
    if (state.vcs.busy) return;
    state.vcs.busy = true; state.vcs.error = '';
    renderVcs();
    getJson({ project_id: state.project, vcs_merge: branch.id }).then(function (d) {
      state.vcs.merge = { branch: branch, data: d.merge, choices: {}, texts: {}, message: d.merge.message, note: note || '' };
      state.vcs.view = null;
    }).catch(function (e) { state.vcs.merge = null; state.vcs.error = message(e); })
      .then(function () { state.vcs.busy = false; renderVcs(); });
  }
  function vcsMergeGo() {
    var mg = state.vcs.merge;
    if (!mg || state.vcs.busy) return;
    var resolutions = {};
    var open = mg.data.conflicts.filter(function (c) {
      var choice = mg.choices[c.path];
      if (!choice) return true;
      resolutions[c.path] = choice === 'text' ? { choice: 'text', text: mg.texts[c.path] } : { choice: choice };
      return false;
    });
    if (open.length) { state.vcs.error = ERR.merge_unresolved; renderVcs(); return; }
    if (!leave()) return;
    state.vcs.busy = true; state.vcs.error = '';
    renderVcs();
    post({ action: 'vcs_merge', branch_id: mg.branch.id, ours_tree: mg.data.ours_tree, theirs_head: mg.data.theirs_head,
      resolutions: resolutions, message: (mg.message || '').trim() }).then(function (d) {
      state.vcs.data = Object.assign({ ready: true }, d.state);
      state.vcs.merge = null; state.vcs.view = null;
      return vcsFilesChanged().then(function () { state.vcs.busy = false; renderVcs(); });
    }).catch(function (e) {
      state.vcs.busy = false;
      // Something changed since the preview: show the merge again as it is now, choices start anew.
      if (e && e.message === 'merge_changed') { vcsMergeOpen(mg.branch, ERR.merge_changed); return; }
      state.vcs.error = message(e);
      renderVcs();
    });
  }
  function vcsCreate(name, carry) { vcsAction({ action: 'vcs_branch', name: name, carry: carry }, true, function () { state.vcs.form = null; }); }
  function vcsShowCommit(commit) {
    getJson({ project_id: state.project, vcs_commit: commit.id }).then(function (d) {
      state.vcs.view = { kind: 'commit', commit: d.commit, changes: d.changes };
      renderVcs();
    }).catch(function (e) { state.vcs.error = message(e); renderVcs(); });
  }
  // One file compared between two states: [from, to] are a version id, 'work' or null (not there).
  function vcsShowFile(path, from, to, title) {
    var get = function (which) {
      if (which === null) return Promise.resolve({ exists: false, content: '' });
      return getJson({ project_id: state.project, vcs_file: which, path: path }).then(function (d) { return d.file; });
    };
    Promise.all([get(from), get(to)]).then(function (both) {
      state.vcs.view = { kind: 'file', path: path, title: title, before: both[0], after: both[1], back: state.vcs.view };
      renderVcs();
    }).catch(function (e) { state.vcs.error = message(e); renderVcs(); });
  }
  var KIND = { content: 'beide haben dieselben Stellen geändert', added: 'auf beiden Seiten neu angelegt',
    deleted_ours: 'hier gelöscht, dort geändert', deleted_theirs: 'hier geändert, dort gelöscht' };
  function vcsFileView(v) {
    var fbox = el('div', 'project-vcs-view project-file-compare');
    var fhead = el('div', 'project-file-compare-head');
    fhead.appendChild(el('strong', '', v.path + ' · ' + v.title));
    var ops = diffLines(lines(v.before.content), lines(v.after.content));
    if (ops) {
      fhead.appendChild(el('span', 'project-diff-count', '+' + ops.filter(function (o) { return o.t === '+'; }).length
        + ' −' + ops.filter(function (o) { return o.t === '-'; }).length));
    }
    fhead.appendChild(button(v.back ? 'Zurück' : 'Schließen', 'project-files-secondary', function () { state.vcs.view = v.back || null; renderVcs(); }));
    fbox.appendChild(fhead);
    fbox.appendChild(diffBody(ops));
    return fbox;
  }
  function renderMerge(s) {
    var mg = s.merge;
    var md = mg.data;
    var box = el('div', 'project-vcs-merge');
    box.id = 'projectVcsMerge';
    var head = el('div', 'project-file-compare-head');
    head.appendChild(el('strong', '', '„' + md.from.name + '“ in „' + md.into.name + '“ zusammenführen'));
    head.appendChild(button('Abbrechen', 'project-files-secondary', function () { s.merge = null; s.view = null; s.error = ''; renderVcs(); },
      { id: 'projectVcsMergeCancel' }));
    box.appendChild(head);
    if (mg.note) box.appendChild(el('p', 'project-files-status', mg.note));
    if (md.unsaved) box.appendChild(el('p', 'project-files-hint', 'Deine ungesicherten Änderungen werden vorher als eigene Version gesichert.'));
    if (md.from.unsaved) {
      box.appendChild(el('p', 'project-files-hint', 'Ungesicherte Änderungen von „' + md.from.name + '“ kommen nicht mit, nur seine gesicherten Versionen.'));
    }
    if (md.changes.clean && !md.conflicts.length) {
      box.appendChild(el('p', 'project-files-hint', 'An den Dateien ändert sich nichts; die Versionen von „' + md.from.name + '“ kommen trotzdem dazu.'));
    }
    if (!md.changes.clean) {
      box.appendChild(el('strong', '', 'Wird übernommen'));
      box.appendChild(changeList(md.changes, function (path, kind) {
        vcsShowFile(path, md.base_commit_id, kind === 'deleted' ? null : md.theirs_head, 'Änderung in „' + md.from.name + '“');
      }));
      if (md.merged.length) box.appendChild(el('p', 'project-files-hint', 'Zeilenweise zusammengeführt: ' + md.merged.join(', ')));
    }
    var unresolved = 0;
    if (md.conflicts.length) {
      box.appendChild(el('strong', '', md.conflicts.length === 1 ? '1 Konflikt' : md.conflicts.length + ' Konflikte'));
      md.conflicts.forEach(function (c, i) {
        var choice = mg.choices[c.path] || '';
        if (!choice) unresolved++;
        var item = el('div', 'project-vcs-conflict' + (choice ? ' is-solved' : ''));
        item.setAttribute('data-conflict', c.path);
        var top = el('div', 'project-vcs-conflict-head');
        top.appendChild(el('code', '', c.path));
        top.appendChild(el('small', '', KIND[c.kind] + (c.count > 1 ? ' (' + c.count + ' Stellen)' : '')));
        if (c.ours !== null && c.theirs !== null) {
          top.appendChild(button('Vergleichen', 'project-files-secondary', function () {
            s.view = { kind: 'file', path: c.path, title: '„' + md.into.name + '“ gegenüber „' + md.from.name + '“',
              before: { exists: true, content: c.ours }, after: { exists: true, content: c.theirs }, back: null };
            renderVcs();
          }, { 'data-compare': c.path }));
        }
        item.appendChild(top);
        var options = [['ours', c.ours === null ? 'Gelöscht lassen' : 'Meine Fassung („' + md.into.name + '“)'],
          ['theirs', c.theirs === null ? 'Löschen wie in „' + md.from.name + '“' : 'Fassung von „' + md.from.name + '“']];
        if (c.merged !== null) options.push(['text', 'Selbst zusammenführen']);
        var group = el('div', 'project-vcs-choices');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Lösung für ' + c.path);
        options.forEach(function (o) {
          var label = el('label', 'project-vcs-choice');
          var radio = el('input');
          radio.type = 'radio'; radio.name = 'projectVcsConflict' + i; radio.value = o[0]; radio.checked = choice === o[0];
          radio.addEventListener('change', function () {
            mg.choices[c.path] = o[0];
            if (o[0] === 'text' && mg.texts[c.path] === undefined) mg.texts[c.path] = c.merged;
            s.error = '';
            renderVcs();
          });
          label.appendChild(radio);
          label.appendChild(el('span', '', o[1]));
          group.appendChild(label);
        });
        item.appendChild(group);
        if (choice === 'text') {
          var area = el('textarea', 'project-vcs-conflict-text');
          area.value = mg.texts[c.path];
          area.spellcheck = false;
          area.rows = Math.min(18, Math.max(6, area.value.split('\n').length + 1));
          area.setAttribute('aria-label', 'Zusammengeführter Inhalt von ' + c.path);
          area.addEventListener('input', function () { mg.texts[c.path] = area.value; });
          item.appendChild(area);
          item.appendChild(el('small', 'project-files-hint', 'Zwischen <<<<<<< und ======= steht deine Fassung, darunter bis >>>>>>> die andere. '
            + 'Lass stehen, was bleiben soll, und lösche die Markerzeilen.'));
        }
        box.appendChild(item);
      });
    }
    var form = el('form', 'project-run-form project-vcs-merge-go');
    var msg = el('input', 'project-run-command');
    msg.type = 'text'; msg.maxLength = 200; msg.value = mg.message; msg.id = 'projectVcsMergeMessage'; msg.autocomplete = 'off';
    msg.setAttribute('aria-label', 'Beschreibung der Zusammenführung');
    msg.addEventListener('input', function () { mg.message = msg.value; });
    form.appendChild(msg);
    var go = el('button', 'project-files-primary', 'Zusammenführen');
    go.type = 'submit'; go.id = 'projectVcsMergeGo'; go.disabled = s.busy || unresolved > 0;
    form.appendChild(go);
    form.addEventListener('submit', function (e) { e.preventDefault(); vcsMergeGo(); });
    box.appendChild(form);
    if (unresolved) box.appendChild(el('p', 'project-files-hint', unresolved === 1 ? 'Noch 1 Konflikt ohne Lösung.' : 'Noch ' + unresolved + ' Konflikte ohne Lösung.'));
    return box;
  }
  function changeList(changes, onPick) {
    var list = el('ul', 'project-vcs-changes');
    ['added', 'modified', 'deleted'].forEach(function (kind) {
      (changes[kind] || []).forEach(function (path) {
        var item = el('li', 'is-' + kind);
        var b = button('', 'project-vcs-change', function () { onPick(path, kind); }, { 'data-path': path });
        b.appendChild(el('code', '', path));
        b.appendChild(el('small', '', CHANGE[kind]));
        item.appendChild(b);
        list.appendChild(item);
      });
    });
    return list;
  }
  function renderVcs() {
    var s = state.vcs;
    var keep = vcsMessage && document.activeElement === vcsMessage;
    if (vcsMessage) s.message = vcsMessage.value;
    vcsBox.textContent = ''; vcsMessage = null;
    vcsBox.hidden = !s.open;
    if (!s.open) return;
    var head = el('div', 'project-run-head');
    var title = el('div', 'project-preview-title');
    title.appendChild(el('strong', '', 'Versionen'));
    title.appendChild(el('small', '', 'Sichern hält den Stand aller Dateien fest. Jeder Arbeitsstand hat eigene Dateien; ungesicherte Änderungen bleiben bei ihrem Arbeitsstand.'));
    head.appendChild(title);
    var actions = el('div', 'project-preview-actions');
    if (s.data) {
      var zip = el('a', 'project-files-secondary', 'Als ZIP mit Git');
      zip.href = url({ project_id: state.project, vcs_zip: 1 });
      zip.setAttribute('download', ''); zip.id = 'projectVcsZip';
      actions.appendChild(zip);
    }
    actions.appendChild(button('Schließen', 'project-files-secondary', closeVcs, { id: 'projectVcsClose' }));
    head.appendChild(actions);
    vcsBox.appendChild(head);
    if (!s.ready) { vcsBox.appendChild(el('p', 'project-files-status is-error', ERR.project_vcs_migration_required)); return; }
    if (s.error) vcsBox.appendChild(el('p', 'project-files-status is-error', s.error));
    var d = s.data;
    if (!d) { vcsBox.appendChild(el('p', 'project-files-hint', 'Lädt …')); return; }
    // 0.7.81: while a merge is open it is the only thing here (and a file it compares).
    if (s.merge) {
      vcsBox.appendChild(renderMerge(s));
      if (s.view && s.view.kind === 'file') vcsBox.appendChild(vcsFileView(s.view));
      return;
    }

    // Working states.
    var branches = el('div', 'project-vcs-branches');
    branches.setAttribute('aria-label', 'Arbeitsstände');
    branches.appendChild(el('strong', '', 'Arbeitsstände'));
    d.branches.forEach(function (b) {
      var row = el('div', 'project-vcs-branch' + (b.active ? ' is-active' : ''));
      row.setAttribute('data-branch', b.name);
      row.appendChild(el('code', '', b.name));
      row.appendChild(el('small', '', (b.active ? 'aktiv' : '') + (b.unsaved ? (b.active ? ' · ' : '') + 'ungesicherte Änderungen' : '')));
      if (!b.active) {
        var sw = button('Wechseln', 'project-files-secondary', function () { vcsSwitch(b); }, { 'data-switch': b.name });
        sw.disabled = s.busy;
        row.appendChild(sw);
        if (d.can_merge && !b.merged) {
          var mergeButton = button('Zusammenführen', 'project-files-secondary', function () { vcsMergeOpen(b); },
            { 'data-merge': b.name, title: 'In „' + d.active.name + '“ zusammenführen' });
          mergeButton.disabled = s.busy;
          row.appendChild(mergeButton);
        }
        row.appendChild(button('Löschen', 'project-files-danger', function () { vcsDelete(b); }, { 'data-delete': b.name }));
      }
      branches.appendChild(row);
    });
    if (s.form) {
      var form = el('form', 'project-run-form project-vcs-new');
      var name = el('input', 'project-run-command');
      name.type = 'text'; name.maxLength = 60; name.value = s.form.name || ''; name.placeholder = 'Name, z. B. neuer-titel'; name.id = 'projectVcsBranchName';
      name.setAttribute('aria-label', 'Name des Arbeitsstands'); name.autocomplete = 'off'; name.spellcheck = false;
      form.appendChild(name);
      var carry = el('label', 'project-vcs-carry');
      var box = el('input'); box.type = 'checkbox'; box.id = 'projectVcsCarry'; box.checked = s.form.carry;
      carry.appendChild(box);
      carry.appendChild(el('span', '', 'Ungesicherte Änderungen mitnehmen'));
      form.appendChild(carry);
      var create = el('button', 'project-files-primary', 'Anlegen'); create.type = 'submit'; create.id = 'projectVcsCreate'; create.disabled = s.busy;
      form.appendChild(create);
      form.appendChild(button('Abbrechen', 'project-files-secondary', function () { s.form = null; renderVcs(); }));
      form.addEventListener('submit', function (e) { e.preventDefault(); s.form = { name: name.value, carry: box.checked }; vcsCreate(name.value.trim(), box.checked); });
      branches.appendChild(form);
      setTimeout(function () { name.focus(); }, 0);
    } else {
      branches.appendChild(button('Neuer Arbeitsstand', 'project-files-secondary', function () {
        s.form = { name: '', carry: !d.status.clean }; renderVcs();
      }, { id: 'projectVcsNewBranch' }));
    }
    vcsBox.appendChild(branches);

    // Unsaved changes and saving.
    var status = el('div', 'project-vcs-status');
    status.setAttribute('role', 'status');
    var changed = d.status.added.length + d.status.modified.length + d.status.deleted.length;
    status.appendChild(el('strong', '', d.status.clean ? 'Alles gesichert' : 'Ungesicherte Änderungen (' + changed + ')'));
    if (!d.status.clean) {
      var head0 = d.active.head_commit_id;
      status.appendChild(changeList(d.status, function (path, kind) {
        vcsShowFile(path, kind === 'added' ? null : head0, kind === 'deleted' ? null : 'work', 'Ungesichert gegenüber der letzten Version');
      }));
    }
    var save = el('form', 'project-run-form project-vcs-save');
    vcsMessage = el('input', 'project-run-command');
    vcsMessage.type = 'text'; vcsMessage.maxLength = 200; vcsMessage.value = s.message; vcsMessage.id = 'projectVcsMessage';
    vcsMessage.placeholder = 'Was hat sich geändert?'; vcsMessage.setAttribute('aria-label', 'Beschreibung der Version'); vcsMessage.autocomplete = 'off';
    save.appendChild(vcsMessage);
    var go = el('button', 'project-files-primary', 'Sichern'); go.type = 'submit'; go.id = 'projectVcsCommit';
    go.disabled = s.busy || d.status.clean;
    save.appendChild(go);
    save.addEventListener('submit', function (e) { e.preventDefault(); vcsCommit(); });
    status.appendChild(save);
    vcsBox.appendChild(status);
    if (keep) { vcsMessage.focus(); try { vcsMessage.setSelectionRange(vcsMessage.value.length, vcsMessage.value.length); } catch (e) {} }

    // A version's changes, or one file compared.
    var v = s.view;
    if (v && v.kind === 'commit') {
      var cbox = el('div', 'project-vcs-view');
      var chead = el('div', 'project-file-compare-head');
      chead.appendChild(el('strong', '', '„' + v.commit.message + '“'));
      chead.appendChild(button('Schließen', 'project-files-secondary', function () { s.view = null; renderVcs(); }));
      cbox.appendChild(chead);
      if (v.changes.clean) cbox.appendChild(el('p', 'project-files-hint', 'Diese Version hat nichts geändert.'));
      else cbox.appendChild(changeList(v.changes, function (path, kind) {
        vcsShowFile(path, kind === 'added' ? null : v.commit.parent_id, kind === 'deleted' ? null : v.commit.id, '„' + v.commit.message + '“');
      }));
      vcsBox.appendChild(cbox);
    } else if (v && v.kind === 'file') {
      vcsBox.appendChild(vcsFileView(v));
    }

    // The versions of the active working state.
    var log = el('div', 'project-vcs-log');
    log.setAttribute('aria-label', 'Versionen von ' + d.active.name);
    log.appendChild(el('strong', '', 'Versionen von ' + d.active.name));
    if (!d.log.length) log.appendChild(el('p', 'project-files-hint', 'Noch keine Version. Sichere den ersten Stand.'));
    d.log.forEach(function (c, i) {
      var row = el('div', 'project-vcs-commit' + (i === 0 ? ' is-head' : ''));
      row.setAttribute('data-commit', String(c.id));
      var text = el('div', 'project-vcs-commit-text');
      text.appendChild(el('span', '', c.message));
      text.appendChild(el('small', '', AUTHOR[c.author] + ' · ' + when(c.created_at) + ' · ' + (c.files_count === 1 ? '1 Datei' : c.files_count + ' Dateien')
        + (c.merge_parent_id ? ' · Zusammenführung' : '') + (i === 0 ? ' · neueste' : '')));
      row.appendChild(text);
      var acts = el('div', 'project-vcs-commit-actions');
      acts.appendChild(button('Änderungen', 'project-files-secondary', function () { vcsShowCommit(c); }, { 'data-show': String(c.id) }));
      if (i > 0 || !d.status.clean) acts.appendChild(button('Wiederherstellen', 'project-files-secondary', function () { vcsRestore(c); }, { 'data-restore': String(c.id) }));
      row.appendChild(acts);
      log.appendChild(row);
    });
    vcsBox.appendChild(log);
  }

  window.addEventListener('message', function (event) {
    if (!frame || !state.preview || event.source !== frame.contentWindow) return;
    var data = event.data;
    if (!data || typeof data !== 'object') return;
    if (state.preview.proc && data.key !== state.preview.key) return;
    if (state.preview.proc && data.coreaiPreview === 'open' && typeof data.path === 'string' && /^\/[\x21-\x7e]{0,999}$/.test(data.path)) {
      openServerPreview(state.preview.proc, data.path, state.preview.port);
    } else if (state.preview.proc && data.coreaiPreview === 'fetch') {
      serverFetch(event.source, data);
    } else if (!state.preview.proc && data.coreaiPreview === 'open' && typeof data.path === 'string' && pages().indexOf(data.path) >= 0) {
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
      bar.appendChild(button('Ausführen', 'project-files-secondary', openRuns, { id: 'projectFilesRun', 'aria-expanded': String(state.run.open) }));
      bar.appendChild(button('Prozesse', 'project-files-secondary', openProcs, { id: 'projectFilesProcs', 'aria-expanded': String(state.proc.open) }));
      bar.appendChild(button('Versionen', 'project-files-secondary', openVcs, { id: 'projectFilesVcs', 'aria-expanded': String(state.vcs.open) }));
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
    box.appendChild(diffBody(ops));
    return box;
  }
  // The lines of a comparison, unchanged runs folded (also used by the versions, 0.7.80).
  function diffBody(ops) {
    if (!ops) return el('p', 'project-files-empty', 'Die Datei ist zu groß für einen Vergleich.');
    if (!ops.some(function (o) { return o.t !== ' '; })) return el('p', 'project-files-empty', 'Keine Unterschiede.');
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
    return pre;
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
    stopRunPoll();
    state.run = { open: false, list: [], current: null, command: '', suggestion: '', allowed: true, ready: true, error: '', sending: false };
    renderRun();
    stopProcPoll();
    state.proc = freshProc(); procShown = '';
    renderProc();
    state.vcs = freshVcs();
    renderVcs();
  }
  function open(projectId) {
    if (projectId !== state.project) { reset(); state.project = projectId || ''; }
    pollsPaused = false;
    if (!state.project) return Promise.resolve();
    render();
    resumePolls();
    if (state.file) return Promise.resolve();
    return load().then(render);
  }
  window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
  function dirtyProject() { return dirty() ? state.project : ''; }
  window.CoreAIProjectFiles = { open: open, leave: leave, reset: reset, pause: pausePolls, dirtyProject: dirtyProject, diffLines: diffLines };
})();
