/* CoreAI: TCI Interface 0.6.87: Settings > Archivierte Chats, modeled on the ChatGPT reference.
   Search over the titles, filters "Alle Chats" (or with uploaded files, or with Mara's images) and
   "Alle Projekte" (projects are planned: every chat stands under "Kein Projekt"), the chats grouped
   with their count, each with its date, "Dearchivieren" and "Löschen" (with a confirmation);
   "Keine archivierten Chats" when there are none. Data: api/console_sessions.php. */
(function () {
  'use strict';

  var view = document.getElementById('view-archivierte-chats');
  if (!view || !document.getElementById('arcGroups')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/console_sessions.php';
  var state = {sessions: [], loaded: false, seq: 0, timer: null, pending: null};

  var ERRORS = {
    session_busy: 'Der Chat hat noch eine laufende Browser-Recherche und lässt sich erst danach löschen.',
    session_not_found: 'Diesen Chat gibt es nicht mehr.',
    session_migration_required: 'Das Update ist noch nicht vollständig eingespielt.'
  };
  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || fallback || ('Fehler: ' + (code || 'unbekannt'));
  }
  function status(text, error) {
    var el = $('arcStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function request(url, opts) {
    return fetch(url, Object.assign({credentials: 'include', cache: 'no-store'}, opts || {})).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP_' + r.status));
        return d;
      });
    });
  }
  function post(body) {
    return request(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
  }

  function dateText(value) {
    var d = value ? new Date(value) : null;
    if (!d || isNaN(d.getTime())) return '';
    return d.toLocaleDateString('de-DE', {day: 'numeric', month: 'short', year: 'numeric'}) + ', '
      + d.toLocaleTimeString('de-DE', {hour: 'numeric', minute: '2-digit'});
  }

  function load() {
    var seq = ++state.seq;
    var params = new URLSearchParams({action: 'archived', q: $('arcSearch').value.trim(), filter: $('arcFilter').value});
    return request(API + '?' + params.toString()).then(function (d) {
      if (seq !== state.seq) return;
      state.sessions = d.sessions || [];
      state.loaded = true;
      render();
    }).catch(function (err) {
      if (seq !== state.seq) return;
      status('Archivierte Chats nicht erreichbar. ' + errText(err), true);
    });
  }

  function render() {
    var groups = $('arcGroups');
    groups.textContent = '';
    var searching = $('arcSearch').value.trim() !== '' || $('arcFilter').value !== 'all';
    var list = state.sessions.filter(function (s) { return !s.project; });
    $('arcEmpty').hidden = list.length > 0;
    $('arcEmptyText').textContent = searching ? 'Keine passenden archivierten Chats' : 'Keine archivierten Chats';
    if (!list.length) return;

    var group = document.createElement('section');
    group.className = 'arc-group';
    var head = document.createElement('div');
    head.className = 'arc-group-head';
    var name = document.createElement('h3');
    name.innerHTML = '<svg aria-hidden="true"><use href="#i-folder" /></svg>';
    name.appendChild(document.createTextNode('Kein Projekt'));
    var count = document.createElement('span');
    count.className = 'arc-count';
    count.textContent = list.length === 1 ? '1 Chat' : list.length + ' Chats';
    head.appendChild(name);
    head.appendChild(count);
    group.appendChild(head);

    var card = document.createElement('div');
    card.className = 'settings-panel cfg-card wide arc-card';
    list.forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'arc-row';
      row.setAttribute('data-session-id', s.id);
      var copy = document.createElement('div');
      copy.className = 'arc-copy';
      var title = document.createElement('strong');
      title.textContent = s.title || 'Chat';
      title.title = s.title || '';
      var when = document.createElement('small');
      when.textContent = dateText(s.updated_at || s.archived_at);
      copy.appendChild(title);
      copy.appendChild(when);
      var actions = document.createElement('div');
      actions.className = 'arc-actions';
      var restore = document.createElement('button');
      restore.type = 'button';
      restore.className = 'arc-btn';
      restore.textContent = 'Dearchivieren';
      restore.addEventListener('click', function () { unarchive(s, restore); });
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'arc-btn danger';
      del.textContent = 'Löschen';
      del.addEventListener('click', function () { confirmDelete(s); });
      actions.appendChild(restore);
      actions.appendChild(del);
      row.appendChild(copy);
      row.appendChild(actions);
      card.appendChild(row);
    });
    group.appendChild(card);
    groups.appendChild(group);
  }

  function drop(id) {
    state.sessions = state.sessions.filter(function (s) { return s.id !== id; });
    render();
  }

  function unarchive(s, button) {
    button.disabled = true;
    post({action: 'restore', id: s.id}).then(function () {
      drop(s.id);
      status('„' + (s.title || 'Chat') + '“ ist wieder in deiner Chatliste.');
    }).catch(function (err) {
      button.disabled = false;
      status(errText(err, 'Der Chat konnte nicht dearchiviert werden.'), true);
    });
  }

  function confirmDelete(s) {
    state.pending = s;
    $('arcConfirmText').textContent = '„' + (s.title || 'Chat') + '“ wird mit allen Nachrichten und hochgeladenen Dateien endgültig gelöscht. Das lässt sich nicht rückgängig machen.';
    $('arcConfirmError').textContent = '';
    $('arcConfirm').hidden = false;
    $('arcConfirmCancel').focus();
  }
  function closeConfirm() {
    $('arcConfirm').hidden = true;
    state.pending = null;
  }
  function deleteNow() {
    var s = state.pending;
    if (!s) return;
    $('arcConfirmOk').disabled = true;
    post({action: 'delete_permanently', id: s.id, confirmation: 'DELETE:' + s.id}).then(function () {
      closeConfirm();
      drop(s.id);
      status('„' + (s.title || 'Chat') + '“ wurde gelöscht.');
    }).catch(function (err) {
      $('arcConfirmError').textContent = errText(err, 'Der Chat konnte nicht gelöscht werden.');
    }).then(function () { $('arcConfirmOk').disabled = false; });
  }

  $('arcSearch').addEventListener('input', function () {
    if (state.timer) clearTimeout(state.timer);
    state.timer = setTimeout(load, 250);
  });
  $('arcFilter').addEventListener('change', load);
  $('arcProject').addEventListener('change', render);
  $('arcConfirmOk').addEventListener('click', deleteNow);
  $('arcConfirmCancel').addEventListener('click', closeConfirm);
  $('arcConfirm').addEventListener('click', function (e) { if (e.target === $('arcConfirm')) closeConfirm(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('arcConfirm').hidden) closeConfirm(); });

  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'archivierte-chats') { status(''); load(); }
  });
  if (!view.hidden) load();
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (!state.loaded && tabs && typeof tabs.current === 'function' && tabs.current() === 'archivierte-chats') load();

  window.CoreAIArchivedChats = {load: load};
})();
