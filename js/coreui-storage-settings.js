/* CoreAI: TCI Interface 0.7.28: Settings > Speicher after the reference screenshots.
   The overview shows what the account keeps ("X von Y verwendet", the limit set in Admin Core;
   without a limit the free space of the server) and "Speicherplatz verwalten" with Dateien and
   Bilder. Dateien ("Alle"): search, upload ("+"), Vorgeschlagen / Favoriten / Deine Elemente, a type
   filter, list or grid, project folders, and per item Öffnen, Herunterladen, Im Chat öffnen, Favorit,
   Löschen. Bilder: Deine Kreationen (Mara's images) and Uploads, the Referenzbilder of
   Personalisierung, a grid. Deleting frees the space for good (api/files.php). Uploads go through
   api/console_upload.php like in the chat, which also checks the limit. */
(function () {
  'use strict';

  var view = document.getElementById('view-speicher');
  if (!view || !document.getElementById('stMain')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/files.php';
  var UPLOAD = './api/console_upload.php';
  var TABS = {
    files: [['suggested', 'Vorgeschlagen'], ['favorites', 'Favoriten'], ['all', 'Deine Elemente']],
    images: [['creations', 'Deine Kreationen'], ['uploads', 'Uploads']]
  };
  var TYPES = [['', 'Alle Typen'], ['document', 'Dokumente'], ['audio', 'Audio'], ['video', 'Video'], ['mara', 'Von Mara erstellt']];
  var KIND_ICON = {document: 'i-file', audio: 'i-mic', video: 'i-image', image: 'i-image', mara: 'i-spark'};
  var ERRORS = {
    not_authenticated: 'Bitte melde dich erneut an.',
    guests_not_allowed: 'Speicher gibt es nur für angemeldete Konten.',
    missing_schema_039: 'Das Update ist noch nicht vollständig eingespielt (Migration 039).',
    not_found: 'Diese Datei gibt es nicht mehr.',
    empty_file: 'Die Datei ist leer.',
    move_failed: 'Die Datei konnte nicht gespeichert werden.'
  };
  var state = {csrf: '', summary: null, view: 'files', tab: 'all', q: '', type: '', project: null, layout: 'list',
    offset: 0, items: [], seq: 0, menuItem: null, menuFor: null, deleting: null, searchTimer: 0, favoritesReady: true};
  try { if (window.localStorage.getItem('coreai-storage-layout') === 'grid') state.layout = 'grid'; } catch (e) { /* private mode */ }

  function size(bytes) {
    var n = Number(bytes) || 0;
    var de = function (v, d) { return v.toLocaleString('de-DE', {maximumFractionDigits: d, minimumFractionDigits: 0}); };
    if (n >= 1073741824) return de(n / 1073741824, 2) + ' GB';
    if (n >= 1048576) return de(n / 1048576, n >= 104857600 ? 0 : 1) + ' MB';
    return de(Math.max(n > 0 ? 1 : 0, Math.ceil(n / 1024)), 0) + ' KB';
  }
  function count(n, one, many) { return Number(n || 0).toLocaleString('de-DE') + ' ' + (Number(n) === 1 ? one : many); }
  function date(text) {
    var d = new Date(String(text || '').replace(' ', 'T'));
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit', year: 'numeric'});
  }
  function status(text, error) {
    var el = $('stStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || (fallback ? fallback + ' (' + (code || 'unbekannt') + ')' : 'Fehler: ' + (code || 'unbekannt'));
  }
  function request(method, action, params, body) {
    var headers = {'Accept': 'application/json'};
    if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CoreUI-CSRF'] = state.csrf; }
    var query = '?action=' + action + (params ? '&' + params : '');
    return fetch(API + query, {method: method, credentials: 'include', cache: 'no-store', headers: headers,
      body: method === 'POST' ? JSON.stringify(body || {}) : undefined}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP_' + res.status));
        return data;
      });
    });
  }
  function icon(name) { return '<svg aria-hidden="true"><use href="#' + name + '" /></svg>'; }

  // --- Overview -------------------------------------------------------------------------------------
  function showSummary(data) {
    state.summary = data;
    var used = Number(data.used) || 0;
    var limit = data.limit == null ? null : Number(data.limit);
    var bar = $('stBar');
    var fill = bar.querySelector('i');
    var note = '';
    var pct;
    if (limit) {
      $('stUsage').textContent = size(used) + ' von ' + size(limit) + ' verwendet';
      pct = Math.min(100, used / limit * 100);
      if (used >= limit) note = 'Dein Speicher ist voll. Uploads und neue Bilder von Mara gehen erst wieder, wenn du Platz schaffst.';
      else if (pct >= 90) note = 'Dein Speicher ist fast voll.';
    } else {
      $('stUsage').textContent = size(used) + ' verwendet';
      var free = data.disk_free == null ? null : Number(data.disk_free);
      pct = free ? Math.min(100, used / (used + free) * 100) : 0;
      note = 'Für dein Konto gibt es keine Speichergrenze.' + (free != null ? ' Auf dem Server sind noch ' + size(free) + ' frei.' : '');
    }
    bar.hidden = !limit && data.disk_free == null;
    fill.style.width = Math.max(used > 0 ? 1.5 : 0, pct) + '%';
    bar.setAttribute('aria-valuenow', String(Math.round(pct)));
    bar.classList.toggle('is-full', !!limit && used >= limit);
    $('stUsageNote').textContent = note;
    $('stFilesMeta').textContent = size(data.files.bytes) + ' · ' + count(data.files.count, 'Datei', 'Dateien');
    $('stImagesMeta').textContent = size(data.images.bytes) + ' · ' + count(data.images.count, 'Bild', 'Bilder');
    state.favoritesReady = data.favorites_ready !== false;
    drawReferences(data.references || {});
  }
  function loadSummary() {
    return request('GET', 'summary').then(function (data) {
      state.csrf = data.csrf_token || state.csrf;
      showSummary(data);
      status('');
      $('stMain').hidden = false;
    }).catch(function (err) {
      status(errText(err, 'Der Speicher ließ sich nicht laden'), true);
      if (err && err.message === 'guests_not_allowed') $('stMain').hidden = true;
    });
  }
  function drawReferences(refs) {
    var box = $('stRefChips');
    box.textContent = '';
    [['portrait', 'Referenzfoto'], ['body', 'Ganzkörper']].forEach(function (pair) {
      var ref = refs[pair[0]];
      if (!ref || !ref.url) return;
      var chip = document.createElement('a');
      chip.className = 'st-chip st-ref';
      chip.href = ref.url;
      chip.target = '_blank';
      chip.rel = 'noopener';
      var img = document.createElement('img');
      img.src = ref.url;
      img.alt = '';
      chip.appendChild(img);
      chip.appendChild(document.createTextNode(pair[1]));
      box.appendChild(chip);
    });
  }

  // --- Dateien / Bilder ----------------------------------------------------------------------------
  function open(which) {
    state.view = which;
    state.tab = which === 'images' ? 'creations' : 'all';
    state.q = '';
    state.type = '';
    state.project = null;
    $('stSearch').value = '';
    $('stSubTitle').textContent = which === 'images' ? 'Bilder' : 'Alle';
    $('stFile').setAttribute('accept', which === 'images' ? 'image/png,image/jpeg,image/webp,image/gif' : '');
    $('stRefs').hidden = which !== 'images';
    $('stToolbar').hidden = which === 'images';
    drawTabs();
    $('stMain').hidden = true;
    $('stSub').hidden = false;
    status('');
    load(true);
    $('stBack').focus({preventScroll: true});
    view.scrollIntoView({block: 'start'});
  }
  function close() {
    closeMenus();
    $('stSub').hidden = true;
    $('stMain').hidden = false;
    loadSummary();
  }
  function drawTabs() {
    var box = $('stTabs');
    box.textContent = '';
    TABS[state.view].forEach(function (pair) {
      if (pair[0] === 'favorites' && !state.favoritesReady) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'st-tab';
      b.setAttribute('role', 'tab');
      b.setAttribute('data-tab', pair[0]);
      b.setAttribute('aria-selected', pair[0] === state.tab ? 'true' : 'false');
      b.textContent = pair[1];
      b.addEventListener('click', function () {
        if (state.tab === pair[0]) return;
        state.tab = pair[0];
        state.project = null;
        drawTabs();
        load(true);
      });
      box.appendChild(b);
    });
  }
  function layout() {
    var grid = state.view === 'images' || state.layout === 'grid';
    $('stList').setAttribute('data-layout', grid ? 'grid' : 'list');
    $('stListHead').hidden = grid;
    $('stViewGrid').setAttribute('aria-pressed', grid ? 'true' : 'false');
    $('stViewList').setAttribute('aria-pressed', grid ? 'false' : 'true');
    $('stFilter').classList.toggle('is-active', !!state.type);
    var crumb = $('stCrumb');
    crumb.hidden = !state.project;
    crumb.querySelector('span').textContent = state.project ? 'Alle / ' + state.project.name : '';
  }
  function load(reset) {
    var seq = ++state.seq;
    if (reset) { state.offset = 0; state.items = []; }
    layout();
    var params = 'view=' + state.view + '&tab=' + state.tab + '&offset=' + state.offset
      + (state.q ? '&q=' + encodeURIComponent(state.q) : '') + (state.type ? '&type=' + state.type : '')
      + (state.project ? '&project=' + state.project.id : '');
    $('stEmpty').hidden = true;
    if (reset) $('stList').setAttribute('aria-busy', 'true');
    return request('GET', 'list', params).then(function (data) {
      if (seq !== state.seq) return;
      state.items = state.items.concat(data.items || []);
      state.offset = state.items.length;
      draw(reset ? (data.folders || []) : null);
      $('stMore').hidden = !data.has_more;
    }).catch(function (err) {
      if (seq !== state.seq) return;
      status(errText(err, 'Die Liste ließ sich nicht laden'), true);
    }).then(function () { $('stList').removeAttribute('aria-busy'); });
  }
  function emptyText() {
    if (state.q) return 'Nichts gefunden für „' + state.q + '“.';
    if (state.view === 'images') return state.tab === 'uploads' ? 'Noch keine hochgeladenen Bilder.' : 'Mara hat für dich noch keine Bilder erstellt.';
    if (state.tab === 'favorites') return 'Noch keine Favoriten. Über „…“ markierst du eine Datei als Favorit.';
    if (state.type) return 'Keine Dateien dieses Typs.';
    return 'Noch keine Dateien.';
  }
  function draw(folders) {
    var list = $('stList');
    if (folders) list.textContent = '';
    else Array.prototype.forEach.call(list.querySelectorAll('.st-item:not(.st-folder)'), function (n) { n.remove(); });
    (folders || []).forEach(function (folder) { list.appendChild(folderEl(folder)); });
    state.items.forEach(function (item) { list.appendChild(itemEl(item)); });
    var none = !state.items.length && !(folders && folders.length) && !list.querySelector('.st-folder');
    $('stEmpty').hidden = !none;
    $('stEmpty').textContent = none ? emptyText() : '';
  }
  function folderEl(folder) {
    var row = document.createElement('div');
    row.className = 'st-item st-folder';
    var main = document.createElement('button');
    main.type = 'button';
    main.className = 'st-item-main';
    main.innerHTML = '<span class="st-thumb">' + icon('i-folder') + '</span>';
    var copy = document.createElement('span');
    copy.className = 'st-copy';
    var name = document.createElement('b');
    name.textContent = folder.name || 'Projekt';
    var meta = document.createElement('small');
    meta.textContent = 'Projekt · ' + count(folder.count, 'Datei', 'Dateien') + ' · ' + size(folder.size);
    copy.appendChild(name);
    copy.appendChild(meta);
    main.appendChild(copy);
    main.addEventListener('click', function () {
      state.project = {id: folder.id, name: folder.name || 'Projekt'};
      load(true);
    });
    row.appendChild(main);
    return row;
  }
  function itemEl(item) {
    var row = document.createElement('div');
    row.className = 'st-item';
    row.setAttribute('data-id', item.id);
    var main = document.createElement('a');
    main.className = 'st-item-main';
    main.href = item.url;
    main.target = '_blank';
    main.rel = 'noopener';
    var thumb = document.createElement('span');
    thumb.className = 'st-thumb st-kind-' + item.kind;
    if (item.thumb) {
      var img = document.createElement('img');
      img.src = item.thumb;
      img.alt = '';
      img.loading = 'lazy';
      thumb.appendChild(img);
    } else {
      thumb.innerHTML = icon(KIND_ICON[item.kind] || 'i-file');
    }
    main.appendChild(thumb);
    var copy = document.createElement('span');
    copy.className = 'st-copy';
    var name = document.createElement('b');
    name.textContent = item.name;
    if (item.favorite) name.insertAdjacentHTML('beforeend', '<svg class="st-fav" aria-label="Favorit" role="img"><use href="#i-star" /></svg>');
    var meta = document.createElement('small');
    var where = item.chat ? (item.chat.project ? item.chat.project + ' · ' : '') + (item.chat.title || 'Chat') : '';
    meta.textContent = [size(item.size), date(item.created), item.kind === 'mara' ? 'von Mara' : '', where].filter(Boolean).join(' · ');
    copy.appendChild(name);
    copy.appendChild(meta);
    main.appendChild(copy);
    row.appendChild(main);
    var more = document.createElement('button');
    more.type = 'button';
    more.className = 'st-more-btn';
    more.setAttribute('aria-haspopup', 'menu');
    more.setAttribute('aria-expanded', 'false');
    more.setAttribute('aria-label', 'Aktionen für ' + item.name);
    more.innerHTML = icon('i-more');
    more.addEventListener('click', function (e) { e.stopPropagation(); openItemMenu(item, more); });
    row.appendChild(more);
    return row;
  }

  // --- Menus ----------------------------------------------------------------------------------------
  var itemMenu = $('stItemMenu');
  var typeMenu = $('stTypeMenu');
  function place(menu, btn) {
    var rect = btn.getBoundingClientRect();
    var width = menu.offsetWidth;
    var height = menu.offsetHeight;
    var left = Math.min(rect.right - width, window.innerWidth - width - 8);
    var top = rect.bottom + 6;
    if (top + height > window.innerHeight - 8 && rect.top - 6 - height >= 8) top = rect.top - 6 - height;
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }
  function closeMenus(focus) {
    var btn = state.menuFor;
    [itemMenu, typeMenu].forEach(function (m) { m.hidden = true; });
    if (btn) btn.setAttribute('aria-expanded', 'false');
    state.menuFor = null;
    state.menuItem = null;
    if (focus && btn) btn.focus();
  }
  function option(menu, label, iconName, onClick, danger) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'arc-option' + (danger ? ' pz-danger' : '');
    b.setAttribute('role', 'menuitem');
    b.innerHTML = icon(iconName).replace('<svg', '<svg class="arc-lead"') + '<span></span>';
    b.querySelector('span').textContent = label;
    b.addEventListener('click', function (e) { e.stopPropagation(); closeMenus(false); onClick(); });
    menu.appendChild(b);
  }
  function openItemMenu(item, btn) {
    var again = state.menuFor === btn;
    closeMenus(false);
    if (again) return;
    itemMenu.textContent = '';
    option(itemMenu, 'Öffnen', 'i-eye', function () { window.open(item.url, '_blank', 'noopener'); });
    option(itemMenu, 'Herunterladen', 'i-download', function () {
      var a = document.createElement('a');
      a.href = item.url;
      a.download = item.name;
      document.body.appendChild(a);
      a.click();
      a.remove();
    });
    if (item.chat && item.chat.id) {
      option(itemMenu, 'Im Chat öffnen', 'i-bubble', function () { window.location.href = './app.html?session=' + encodeURIComponent(item.chat.id); });
    }
    if (state.view === 'files' && state.favoritesReady) {
      option(itemMenu, item.favorite ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen', 'i-star', function () { favorite(item, !item.favorite); });
    }
    itemMenu.insertAdjacentHTML('beforeend', '<hr class="arc-menu-line" />');
    option(itemMenu, 'Löschen', 'i-trash', function () { askDelete(item); }, true);
    state.menuFor = btn;
    state.menuItem = item;
    btn.setAttribute('aria-expanded', 'true');
    itemMenu.hidden = false;
    place(itemMenu, btn);
    var first = itemMenu.querySelector('.arc-option');
    if (first) first.focus({preventScroll: true});
  }
  function openTypeMenu() {
    var btn = $('stFilter');
    var again = state.menuFor === btn;
    closeMenus(false);
    if (again) return;
    typeMenu.textContent = '';
    TYPES.forEach(function (pair) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'arc-option pz-option';
      b.setAttribute('role', 'menuitemradio');
      b.setAttribute('data-value', pair[0]);
      b.setAttribute('aria-checked', pair[0] === state.type ? 'true' : 'false');
      b.innerHTML = '<span><b></b></span><svg aria-hidden="true"><use href="#i-check" /></svg>';
      b.querySelector('b').textContent = pair[1];
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        closeMenus(true);
        if (state.type === pair[0]) return;
        state.type = pair[0];
        load(true);
      });
      typeMenu.appendChild(b);
    });
    state.menuFor = btn;
    btn.setAttribute('aria-expanded', 'true');
    typeMenu.hidden = false;
    place(typeMenu, btn);
    var checked = typeMenu.querySelector('[aria-checked="true"]');
    if (checked) checked.focus({preventScroll: true});
  }
  document.addEventListener('click', function (e) {
    if (state.menuFor && !itemMenu.contains(e.target) && !typeMenu.contains(e.target)) closeMenus(false);
  });
  document.addEventListener('scroll', function (e) {
    if (!state.menuFor || itemMenu.contains(e.target) || typeMenu.contains(e.target)) return;
    var rect = state.menuFor.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) closeMenus(false);
    else place(itemMenu.hidden ? typeMenu : itemMenu, state.menuFor);
  }, true);
  window.addEventListener('resize', function () { closeMenus(false); });

  function favorite(item, on) {
    request('POST', 'favorite', '', {id: item.id, on: on}).then(function () {
      item.favorite = on;
      if (state.tab === 'favorites' && !on) state.items = state.items.filter(function (x) { return x.id !== item.id; });
      draw(null);
      status(on ? '„' + item.name + '“ ist jetzt ein Favorit.' : '„' + item.name + '“ ist kein Favorit mehr.');
    }).catch(function (err) { status(errText(err, 'Der Favorit ließ sich nicht speichern'), true); });
  }

  // --- Löschen --------------------------------------------------------------------------------------
  var confirmBox = $('stDeleteConfirm');
  function askDelete(item) {
    state.deleting = item;
    $('stDeleteTitle').textContent = item.kind === 'image' ? 'Bild löschen?' : 'Datei löschen?';
    $('stDeleteText').textContent = '„' + item.name + '“ (' + size(item.size) + ') wird endgültig gelöscht.'
      + (item.chat ? ' Im Chat „' + (item.chat.title || 'Chat') + '“ ist ' + (item.kind === 'image' ? 'das Bild' : 'die Datei') + ' danach nicht mehr zu sehen.' : '');
    $('stDeleteError').textContent = '';
    confirmBox.hidden = false;
    $('stDeleteCancel').focus();
  }
  function closeDelete() {
    confirmBox.hidden = true;
    state.deleting = null;
  }
  $('stDeleteCancel').addEventListener('click', closeDelete);
  confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) closeDelete(); });
  $('stDeleteOk').addEventListener('click', function () {
    var item = state.deleting;
    if (!item) return;
    var ok = $('stDeleteOk');
    ok.disabled = true;
    request('POST', 'delete', '', {id: item.id}).then(function (data) {
      state.items = state.items.filter(function (x) { return x.id !== item.id; });
      state.offset = state.items.length;
      draw(null);
      closeDelete();
      status('„' + item.name + '“ gelöscht, ' + size(data.freed) + ' frei.');
      if (state.summary) showSummary(Object.assign({}, state.summary, {used: data.used, files: data.files, images: data.images}));
    }).catch(function (err) {
      $('stDeleteError').textContent = errText(err, 'Das Löschen hat nicht geklappt');
    }).then(function () { ok.disabled = false; });
  });

  // --- Hochladen ------------------------------------------------------------------------------------
  function uploadOne(file) {
    return new Promise(function (resolve) {
      var form = new FormData();
      form.append('file', file, file.name);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', UPLOAD);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      if (state.csrf) xhr.setRequestHeader('X-CoreUI-CSRF', state.csrf);
      xhr.onload = function () {
        var d = null;
        try { d = JSON.parse(xhr.responseText); } catch (e) { d = null; }
        if (xhr.status >= 200 && xhr.status < 300 && d && d.ok) { resolve({ok: true, kind: d.kind}); return; }
        var code = (d && d.error) || ('HTTP_' + xhr.status);
        var msg = ERRORS[code] || ('Upload fehlgeschlagen (' + code + ')');
        if (code === 'storage_full') msg = 'Dein Speicher ist voll' + (d && d.used_label ? ' (' + d.used_label + ' von ' + d.limit_label + ')' : '') + '.';
        if (code === 'file_too_large' && d && d.max_mb) msg = 'Zu groß (höchstens ' + d.max_mb + ' MB).';
        resolve({ok: false, message: '„' + file.name + '“: ' + msg, full: code === 'storage_full'});
      };
      xhr.onerror = function () { resolve({ok: false, message: '„' + file.name + '“: keine Verbindung zum Server.'}); };
      xhr.send(form);
    });
  }
  $('stAdd').addEventListener('click', function () { $('stFile').click(); });
  $('stFile').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files || []);
    this.value = '';
    if (!files.length) return;
    var done = 0;
    var failures = [];
    var chain = Promise.resolve();
    files.forEach(function (file, i) {
      chain = chain.then(function () {
        if (failures.some(function (f) { return f.full; })) return null;
        status('Lade ' + (i + 1) + ' von ' + files.length + ' hoch …');
        return uploadOne(file).then(function (r) { if (r.ok) done++; else failures.push(r); });
      });
    });
    chain.then(function () {
      status(failures.length ? failures.map(function (f) { return f.message; }).join(' ')
        : (done === 1 ? 'Hochgeladen.' : count(done, 'Datei', 'Dateien') + ' hochgeladen.'), failures.length > 0);
      if (state.view === 'images' && done) { state.tab = 'uploads'; drawTabs(); }
      if (state.view === 'files' && done && state.tab !== 'all' && state.tab !== 'suggested') { state.tab = 'all'; drawTabs(); }
      load(true);
      request('GET', 'summary').then(showSummary).catch(function () {});
    });
  });

  // --- Wiring ---------------------------------------------------------------------------------------
  Array.prototype.forEach.call(view.querySelectorAll('[data-st-open]'), function (node) {
    node.addEventListener('click', function (e) { e.stopPropagation(); open(node.getAttribute('data-st-open')); });
  });
  $('stBack').addEventListener('click', close);
  $('stCrumb').addEventListener('click', function () { state.project = null; load(true); });
  $('stFilter').addEventListener('click', function (e) { e.stopPropagation(); openTypeMenu(); });
  $('stViewGrid').addEventListener('click', function () { setLayout('grid'); });
  $('stViewList').addEventListener('click', function () { setLayout('list'); });
  function setLayout(which) {
    state.layout = which;
    try { window.localStorage.setItem('coreai-storage-layout', which); } catch (e) { /* private mode */ }
    layout();
  }
  $('stSearch').addEventListener('input', function () {
    var value = this.value.trim();
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(function () {
      if (value === state.q) return;
      state.q = value;
      load(true);
    }, 250);
  });
  $('stMore').addEventListener('click', function () { load(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (state.menuFor) { closeMenus(true); return; }
    if (!confirmBox.hidden) closeDelete();
  });
  [itemMenu, typeMenu].forEach(function (menu) {
    menu.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      var items = Array.prototype.slice.call(menu.querySelectorAll('.arc-option'));
      var index = items.indexOf(document.activeElement);
      items[e.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length].focus({preventScroll: true});
    });
  });

  // The page loads its numbers when it is opened (and once at start when it is the first page).
  var loaded = false;
  function ensure() {
    if (loaded || view.hidden) return;
    loaded = true;
    loadSummary();
  }
  new MutationObserver(ensure).observe(view, {attributes: true, attributeFilter: ['hidden']});
  ensure();
})();
