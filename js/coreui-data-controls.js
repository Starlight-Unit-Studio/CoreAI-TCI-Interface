/* CoreAI 0.7.32: Settings > Datenkontrollen after the reference screenshot.
   Archivierte Chats (count and link), Alle Chats archivieren and Alle Chats löschen through
   api/console_sessions.php (data_summary, archive_all, delete_all). Both ask first; deleting needs the
   word LÖSCHEN. Locked chats (PIN) are never touched, a chat Mara is still working in stays.
   Daten exportieren keeps its handler in js/settings.js. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var view = $('view-datenkontrollen');
  if (!view || !$('dcArchiveAll')) return;
  var state = {loaded: false, loading: false, csrf: '', summary: null, busy: false};

  function num(n) { return Number(n || 0).toLocaleString('de-DE'); }
  function chats(n) { return num(n) + ' ' + (Number(n) === 1 ? 'Chat' : 'Chats'); }
  function status(text, error) {
    var node = $('dcStatus');
    node.textContent = text || '';
    if (error) node.setAttribute('data-state', 'error'); else node.removeAttribute('data-state');
  }
  var ERRORS = {
    archive_all_confirmation_required: 'Das Archivieren wurde nicht bestätigt.',
    delete_all_confirmation_required: 'Das Löschen wurde nicht bestätigt.',
    csrf_invalid: 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.',
    session_migration_required: 'Die Chats sind auf diesem Server noch nicht eingerichtet.'
  };
  function errText(err, fallback) { return ERRORS[err && err.code] || fallback; }
  function request(method, action, body) {
    var opts = {method: method, credentials: 'same-origin', headers: {Accept: 'application/json'}};
    if (method === 'POST') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CoreUI-CSRF'] = state.csrf;
      opts.body = JSON.stringify(Object.assign({action: action}, body || {}));
    }
    return fetch('api/console_sessions.php' + (method === 'GET' ? '?action=' + action : ''), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d.ok) { var e = new Error(d.error || ('http_' + r.status)); e.code = d.error || ''; e.status = r.status; throw e; }
        if (d.csrf_token) state.csrf = d.csrf_token;
        return d;
      });
    });
  }

  function render() {
    var s = state.summary;
    var locked = !s.locked ? '' : s.locked === 1 ? ' Ein gesperrter Chat bleibt unberührt.' : ' ' + num(s.locked) + ' gesperrte Chats bleiben unberührt.';
    $('dcArchivedNote').textContent = s.archived ? chats(s.archived) + ' im Archiv.' : 'Keine archivierten Chats.';
    $('dcActiveNote').textContent = s.active ? chats(s.active) + ' sind aktiv. Du kannst sie im Archiv wiederherstellen.' : 'Keine aktiven Chats.';
    $('dcDeleteNote').textContent = (s.active + s.archived ? chats(s.active + s.archived) + ' samt Nachrichten und Dateien.' : 'Keine Chats.') + locked;
    $('dcArchiveAll').disabled = state.busy || s.active === 0;
    $('dcDeleteAll').disabled = state.busy || s.active + s.archived === 0;
  }

  function load() {
    if (state.loading) return Promise.resolve();
    state.loading = true;
    return request('GET', 'data_summary').then(function (d) {
      state.summary = {active: d.active, archived: d.archived, locked: d.locked};
      state.loaded = true;
      render();
    }).catch(function (err) {
      $('dcArchivedNote').textContent = '';
      status(errText(err, 'Deine Chats ließen sich nicht zählen. Bitte versuche es gleich noch einmal.'), true);
    }).then(function () { state.loading = false; });
  }

  // --- Dialogs ----------------------------------------------------------------------------------------------
  var lastFocus = null;
  function openDialog(id, focus) {
    lastFocus = document.activeElement;
    $(id).hidden = false;
    if (focus) focus.focus();
  }
  function closeDialog(id) {
    $(id).hidden = true;
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  }
  ['dcArchiveConfirm', 'dcDeleteConfirm'].forEach(function (id) {
    $(id).addEventListener('keydown', function (e) { if (e.key === 'Escape' && !state.busy) { e.preventDefault(); closeDialog(id); } });
    $(id).addEventListener('click', function (e) { if (e.target === $(id) && !state.busy) closeDialog(id); });
  });

  $('dcArchiveAll').addEventListener('click', function () {
    var s = state.summary;
    $('dcArchiveText').textContent = chats(s.active) + (s.active === 1 ? ' wird' : ' werden') + ' archiviert. Du findest '
      + (s.active === 1 ? 'ihn' : 'sie') + ' unter Archivierte Chats und kannst ' + (s.active === 1 ? 'ihn' : 'sie') + ' dort wiederherstellen.'
      + (s.locked ? ' Gesperrte Chats bleiben, wo sie sind.' : '');
    $('dcArchiveError').textContent = '';
    openDialog('dcArchiveConfirm', $('dcArchiveCancel'));
  });
  $('dcArchiveCancel').addEventListener('click', function () { closeDialog('dcArchiveConfirm'); });
  $('dcArchiveOk').addEventListener('click', function () {
    var ok = $('dcArchiveOk');
    state.busy = true;
    ok.disabled = true;
    request('POST', 'archive_all', {confirm: 'ARCHIVE_ALL_CHATS'}).then(function (d) {
      state.summary = {active: d.active, archived: d.archived, locked: d.locked};
      closeDialog('dcArchiveConfirm');
      status(d.archived_now ? chats(d.archived_now) + ' archiviert.' : 'Es gab keine aktiven Chats.');
    }).catch(function (err) {
      $('dcArchiveError').textContent = errText(err, 'Die Chats ließen sich nicht archivieren.');
    }).then(function () { state.busy = false; ok.disabled = false; render(); });
  });

  var word = $('dcDeleteWord');
  function wordOk() { return word.value.trim().toLocaleUpperCase('de-DE') === 'LÖSCHEN'; }
  word.addEventListener('input', function () { $('dcDeleteOk').disabled = state.busy || !wordOk(); });
  word.addEventListener('keydown', function (e) { if (e.key === 'Enter' && wordOk() && !state.busy) { e.preventDefault(); $('dcDeleteOk').click(); } });
  $('dcDeleteAll').addEventListener('click', function () {
    var s = state.summary;
    var total = s.active + s.archived;
    $('dcDeleteText').textContent = chats(total) + (s.archived ? ' (davon ' + num(s.archived) + ' im Archiv)' : '') + (total === 1 ? ' wird' : ' werden')
      + ' mit allen Nachrichten und hochgeladenen Dateien endgültig gelöscht. Das lässt sich nicht rückgängig machen.'
      + (s.locked ? ' Gesperrte Chats bleiben erhalten.' : '');
    word.value = '';
    $('dcDeleteOk').disabled = true;
    $('dcDeleteError').textContent = '';
    openDialog('dcDeleteConfirm', word);
  });
  $('dcDeleteCancel').addEventListener('click', function () { closeDialog('dcDeleteConfirm'); });
  $('dcDeleteOk').addEventListener('click', function () {
    if (!wordOk()) return;
    var ok = $('dcDeleteOk');
    state.busy = true;
    ok.disabled = true;
    $('dcDeleteCancel').disabled = true;
    $('dcDeleteError').textContent = '';
    ok.textContent = 'WIRD GELÖSCHT …';
    request('POST', 'delete_all', {confirm: 'DELETE_ALL_CHATS'}).then(function (d) {
      state.summary = {active: d.summary.active, archived: d.summary.archived, locked: d.summary.locked};
      closeDialog('dcDeleteConfirm');
      var text = d.deleted ? chats(d.deleted) + ' gelöscht.' : 'Es wurde kein Chat gelöscht.';
      if (d.busy) text += ' ' + chats(d.busy) + (d.busy === 1 ? ', in dem ich noch arbeite, bleibt.' : ', in denen ich noch arbeite, bleiben.');
      if (d.failed) text += ' ' + chats(d.failed) + (d.failed === 1 ? ' ließ' : ' ließen') + ' sich nicht löschen.';
      status(text, !!d.failed);
    }).catch(function (err) {
      $('dcDeleteError').textContent = errText(err, 'Die Chats ließen sich nicht löschen.');
    }).then(function () {
      state.busy = false;
      ok.textContent = 'ALLE LÖSCHEN';
      ok.disabled = !wordOk();
      $('dcDeleteCancel').disabled = false;
      render();
    });
  });

  function maybeLoad() { if (!view.hidden && !state.loaded) load(); }
  new MutationObserver(maybeLoad).observe(view, {attributes: true, attributeFilter: ['hidden']});
  maybeLoad();
})();
