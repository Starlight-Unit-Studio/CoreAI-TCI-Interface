/* CoreAI: TCI Interface 0.6.87: the chat menu (⋮ in the header), modeled on the ChatGPT reference.
   Title of the chat, then: Teilen (the chat as a Markdown file through the device's share sheet, or
   a download; CoreAI makes no public links), Anpinnen, Chat sperren (planned, needs the Konto-PIN),
   Zum Projekt hinzufügen (planned, CoreAI has no projects yet), Hochgeladene Dateien, Im Chat
   suchen (every message on the server, a hit that is not loaded yet is loaded), Zum Startbildschirm
   hinzufügen (the address leads straight to this chat), Archivieren and Löschen (each with its own
   confirmation). Data: api/console_sessions.php; the app hands over window.CoreAIChatApp. */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var btn = $('chatMenuBtn');
  var menu = $('chatMenu');
  if (!btn || !menu) return;

  var openDialogEl = null;
  var lastFocus = null;
  var toastTimer = null;
  var searchTimer = null;
  var searchSeq = 0;
  var shownFor = '';

  var ERRORS = {
    session_busy: 'Der Chat hat noch eine laufende Browser-Recherche und lässt sich erst danach löschen.',
    session_not_found: 'Diesen Chat gibt es nicht mehr.',
    missing_schema_032: 'Das Update ist noch nicht vollständig eingespielt (Datenbank-Migration 032).',
    session_migration_required: 'Das Update ist noch nicht vollständig eingespielt.'
  };

  function app() { return window.CoreAIChatApp || null; }
  function active() { var a = app(); return a && typeof a.active === 'function' ? a.active() : null; }
  function api() { var a = app(); return (a && a.apiBase) || './api'; }
  function errText(err, fallback) {
    var code = err && err.message;
    return ERRORS[code] || fallback || ('Fehler: ' + (code || 'unbekannt'));
  }

  function get(params) {
    return fetch(api() + '/console_sessions.php?' + new URLSearchParams(params).toString(), {credentials: 'include', cache: 'no-store'})
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP_' + r.status));
          return d;
        });
      });
  }
  function post(body) {
    return fetch(api() + '/console_sessions.php', {method: 'POST', credentials: 'include', cache: 'no-store',
      headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP_' + r.status));
        return d;
      });
    });
  }

  function toast(text) {
    var el = $('chatMenuToast');
    el.textContent = text;
    el.hidden = false;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3800);
  }

  // --- Menu ---------------------------------------------------------------------------------------
  function sync() {
    var chat = active();
    btn.hidden = !chat;
    if (!chat) closeMenu(false);
    var id = chat ? chat.id : '';
    if (id !== shownFor) {
      shownFor = id;
      closeSearch();
      // A link made for the home screen belongs to its chat only.
      try {
        var linked = new URLSearchParams(location.search).get('session');
        if (linked && linked !== id) history.replaceState(null, '', location.pathname + location.hash);
      } catch (e) { /* old browser */ }
    }
    if (chat) {
      $('chatMenuTitle').textContent = chat.title || 'Chat';
      $('chatMenuPinLabel').textContent = chat.pinned ? 'Nicht mehr anpinnen' : 'Anpinnen';
    }
  }
  function items() { return Array.prototype.slice.call(menu.querySelectorAll('.chat-menu-item')); }
  function openMenu() {
    sync();
    if (btn.hidden) return;
    menu.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    var first = items()[0];
    if (first) first.focus();
  }
  function closeMenu(focusButton) {
    if (menu.hidden) return;
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    if (focusButton) btn.focus();
  }

  // --- Dialogs ------------------------------------------------------------------------------------------
  function showDialog(id, focusEl) {
    lastFocus = document.activeElement;
    openDialogEl = $(id);
    openDialogEl.hidden = false;
    var target = focusEl || openDialogEl.querySelector('button');
    if (target) setTimeout(function () { target.focus(); }, 0);
  }
  function hideDialog() {
    if (!openDialogEl) return;
    openDialogEl.hidden = true;
    openDialogEl = null;
    if (lastFocus && lastFocus.focus && document.contains(lastFocus)) lastFocus.focus();
    else btn.focus();
  }

  // --- Actions --------------------------------------------------------------------------------------------
  function share(chat) {
    get({action: 'export', id: chat.id}).then(function (d) {
      var name = d.filename || 'chat.md';
      var file = null;
      try { file = new File([d.markdown], name, {type: 'text/markdown'}); } catch (e) { file = null; }
      if (file && navigator.canShare && navigator.share && navigator.canShare({files: [file]})) {
        return navigator.share({files: [file], title: d.title || chat.title}).then(function () { toast('Chat geteilt.'); })
          .catch(function (err) { if (!err || err.name !== 'AbortError') download(d.markdown, name); });
      }
      download(d.markdown, name);
    }).catch(function (err) { toast(errText(err, 'Der Chat konnte nicht geteilt werden.')); });
  }
  function download(text, name) {
    var url = URL.createObjectURL(new Blob([text], {type: 'text/markdown;charset=utf-8'}));
    var a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    toast('Chat als Datei gespeichert: ' + name);
  }

  function pin(chat) {
    post({action: 'pin', id: chat.id, pinned: !chat.pinned}).then(function (d) {
      toast(d.pinned ? 'Chat angepinnt. Er steht jetzt oben in der Liste.' : 'Chat nicht mehr angepinnt.');
      var a = app();
      return a && a.refresh ? a.refresh() : null;
    }).catch(function (err) { toast(errText(err, 'Der Chat konnte nicht angepinnt werden.')); });
  }

  function sizeText(bytes) {
    var n = Number(bytes) || 0;
    if (n >= 1048576) return (n / 1048576).toLocaleString('de-DE', {maximumFractionDigits: 1}) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' Byte';
  }
  function dateText(value) {
    var d = value ? new Date(value) : null;
    if (!d || isNaN(d.getTime())) return '';
    return d.toLocaleDateString('de-DE', {day: 'numeric', month: 'short', year: 'numeric'}) + ', '
      + d.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'});
  }
  function files(chat) {
    var list = $('chatFilesList');
    list.textContent = '';
    $('chatFilesEmpty').hidden = true;
    showDialog('chatFilesDialog');
    get({action: 'files', id: chat.id}).then(function (d) {
      (d.files || []).forEach(function (f) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.href = api() + '/console_media.php?uuid=' + encodeURIComponent(f.uuid);
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = f.name || 'Datei';
        var meta = document.createElement('small');
        meta.textContent = [sizeText(f.size), dateText(f.created_at)].filter(Boolean).join(' · ');
        li.appendChild(link);
        li.appendChild(meta);
        list.appendChild(li);
      });
      $('chatFilesEmpty').hidden = (d.files || []).length > 0;
    }).catch(function (err) {
      $('chatFilesEmpty').hidden = false;
      $('chatFilesEmpty').textContent = errText(err, 'Die Dateien konnten nicht geladen werden.');
    });
  }

  function home(chat) {
    var link = location.origin + location.pathname + '?session=' + encodeURIComponent(chat.id);
    try { history.replaceState(null, '', location.pathname + '?session=' + encodeURIComponent(chat.id) + location.hash); } catch (e) {}
    $('chatHomeLink').value = link;
    showDialog('chatHomeDialog', $('chatHomeCopy'));
  }
  function copyLink() {
    var value = $('chatHomeLink').value;
    var done = function () { toast('Link kopiert.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done).catch(function () { $('chatHomeLink').select(); toast('Bitte den markierten Link kopieren.'); });
    } else {
      $('chatHomeLink').select();
      toast('Bitte den markierten Link kopieren.');
    }
  }

  function archive(chat) {
    if (chat.busy) { toast('In diesem Chat arbeite ich noch. Bitte warte bis zum Abschluss.'); return; }
    $('chatArchiveError').textContent = '';
    showDialog('chatArchiveDialog', $('chatArchiveOk'));
  }
  function archiveNow() {
    var chat = active();
    if (!chat) { hideDialog(); return; }
    $('chatArchiveOk').disabled = true;
    post({action: 'archive', id: chat.id}).then(function () {
      hideDialog();
      toast('Chat archiviert. Du findest ihn unter Einstellungen > Archivierte Chats.');
      var a = app();
      return a && a.removed ? a.removed(chat.id) : null;
    }).catch(function (err) {
      $('chatArchiveError').textContent = errText(err, 'Der Chat konnte nicht archiviert werden.');
    }).then(function () { $('chatArchiveOk').disabled = false; });
  }

  function remove(chat) {
    if (chat.busy) { toast('In diesem Chat arbeite ich noch. Bitte warte bis zum Abschluss.'); return; }
    $('chatDeleteText').textContent = '„' + (chat.title || 'Chat') + '“ wird mit allen Nachrichten und hochgeladenen Dateien endgültig gelöscht. Das lässt sich nicht rückgängig machen.';
    $('chatDeleteError').textContent = '';
    showDialog('chatDeleteDialog', $('chatDeleteOk'));
  }
  function removeNow() {
    var chat = active();
    if (!chat) { hideDialog(); return; }
    $('chatDeleteOk').disabled = true;
    post({action: 'delete_permanently', id: chat.id, confirmation: 'DELETE:' + chat.id, archive_first: true}).then(function () {
      hideDialog();
      toast('Chat gelöscht.');
      var a = app();
      return a && a.removed ? a.removed(chat.id) : null;
    }).catch(function (err) {
      $('chatDeleteError').textContent = errText(err, 'Der Chat konnte nicht gelöscht werden.');
    }).then(function () { $('chatDeleteOk').disabled = false; });
  }

  // --- Search inside the chat -------------------------------------------------------------------------------
  function openSearch() {
    $('chatSearch').hidden = false;
    $('chatSearchInput').value = '';
    $('chatSearchCount').textContent = '';
    $('chatSearchResults').textContent = '';
    $('chatSearchInput').focus();
  }
  function closeSearch() {
    var panel = $('chatSearch');
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    searchSeq++;
  }
  function marked(text, query) {
    var frag = document.createDocumentFragment();
    var lower = text.toLocaleLowerCase('de-DE');
    var q = query.toLocaleLowerCase('de-DE');
    var at = 0;
    var hit = lower.indexOf(q);
    while (q && hit >= 0) {
      frag.appendChild(document.createTextNode(text.slice(at, hit)));
      var m = document.createElement('mark');
      m.textContent = text.slice(hit, hit + query.length);
      frag.appendChild(m);
      at = hit + query.length;
      hit = lower.indexOf(q, at);
    }
    frag.appendChild(document.createTextNode(text.slice(at)));
    return frag;
  }
  function runSearch() {
    var chat = active();
    var query = $('chatSearchInput').value.trim();
    var list = $('chatSearchResults');
    var seq = ++searchSeq;
    if (!chat || query.length < 2) {
      list.textContent = '';
      $('chatSearchCount').textContent = query.length === 1 ? 'Mindestens 2 Zeichen' : '';
      return;
    }
    $('chatSearchCount').textContent = 'Suche …';
    get({action: 'search', id: chat.id, q: query}).then(function (d) {
      if (seq !== searchSeq) return;
      var hits = d.results || [];
      list.textContent = '';
      $('chatSearchCount').textContent = hits.length === 0 ? 'Keine Treffer' : (hits.length === 1 ? '1 Treffer' : hits.length + ' Treffer');
      hits.forEach(function (hit) {
        var li = document.createElement('li');
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'chat-search-hit-btn';
        var who = document.createElement('strong');
        who.textContent = (hit.mara ? chat.assistant || 'Mara' : 'Du') + (hit.created_at ? ' · ' + dateText(hit.created_at) : '');
        var text = document.createElement('span');
        text.appendChild(marked(String(hit.snippet || ''), query));
        b.appendChild(who);
        b.appendChild(text);
        b.addEventListener('click', function () {
          var a = app();
          if (!a || !a.reveal) return;
          a.reveal(hit.id).then(function (found) { if (!found) toast('Diese Nachricht ließ sich nicht anzeigen.'); });
        });
        li.appendChild(b);
        list.appendChild(li);
      });
    }).catch(function (err) {
      if (seq !== searchSeq) return;
      $('chatSearchCount').textContent = errText(err, 'Suche fehlgeschlagen');
    });
  }

  // --- Wiring -------------------------------------------------------------------------------------------------
  var ACTIONS = {share: share, pin: pin, files: files, search: function () { openSearch(); }, home: home, archive: archive, delete: remove,
    lock: function () { toast('Chat sperren ist in Vorbereitung: dafür kommt zuerst die Konto-PIN.'); },
    project: function () { toast('Projekte sind in Vorbereitung.'); }};

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (menu.hidden) openMenu(); else closeMenu(true);
  });
  items().forEach(function (item) {
    item.addEventListener('click', function (e) {
      e.stopPropagation();
      var chat = active();
      closeMenu(false);
      if (!chat) return;
      var fn = ACTIONS[item.getAttribute('data-chat-action')];
      if (fn) fn(chat);
    });
  });
  menu.addEventListener('keydown', function (e) {
    var list = items();
    var i = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
    if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
    if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
  });
  document.addEventListener('click', function (e) {
    if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) closeMenu(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (openDialogEl) { hideDialog(); return; }
    if (!menu.hidden) { closeMenu(true); return; }
    if (!$('chatSearch').hidden) closeSearch();
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-chat-close]'), function (b) { b.addEventListener('click', hideDialog); });
  Array.prototype.forEach.call(document.querySelectorAll('.chat-dialog'), function (dialog) {
    dialog.addEventListener('click', function (e) { if (e.target === dialog) hideDialog(); });
  });
  $('chatArchiveOk').addEventListener('click', archiveNow);
  $('chatDeleteOk').addEventListener('click', removeNow);
  $('chatHomeCopy').addEventListener('click', copyLink);
  $('chatSearchInput').addEventListener('input', function () {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 280);
  });
  $('chatSearchInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var first = $('chatSearchResults').querySelector('button');
      if (first) first.click(); else runSearch();
    }
  });
  $('chatSearchClose').addEventListener('click', closeSearch);

  window.CoreAIChatMenu = {sync: sync, open: openMenu};
  sync();
})();
