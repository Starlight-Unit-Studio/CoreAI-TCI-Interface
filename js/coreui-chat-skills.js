/* CoreAI: TCI Interface 0.7.84 (P12 B): the skills of one chat.
   The + menu gets "Skills für diesen Chat". It opens a window with every skill this chat can use:
   project skills, the account's own, shared ones and the built-in ones, each variant on its own line
   (two skills can share a short name). Up to three can be fixed for the chat; Mara then always has
   them here and uses exactly that variant. The fixed skills stay visible above the input field and
   can be taken out there. The server (api/skill_packages.php, action chat and chat_set) checks
   everything; this module only shows and sends. */
(function () {
  'use strict';

  var menu = window.CoreAIComposerMenu;
  var textarea = document.getElementById('inputMessage');
  var field = textarea ? textarea.closest('.composer-field') : null;
  if (!menu || !menu.registerSkills || !field) return;

  var API = './api/skill_packages.php';
  var VARIANT = {project: 'Projekt-Skill', own: 'eigener Skill', shared: 'gemeinsam', builtin: 'mitgeliefert'};
  var GROUPS = [['project', 'Projekt'], ['own', 'Deine Skills'], ['shared', 'Gemeinsam'], ['builtin', 'Mitgeliefert']];
  var ERRORS = {
    guests_not_allowed: 'Skills gibt es nur für angemeldete Konten.',
    skill_migration_required: 'Das Update ist noch nicht vollständig eingespielt (Migration 053).',
    skill_chat_limit: 'Höchstens drei Skills je Chat.',
    skill_variant_twice: 'Von einer Kennung gilt nur eine Fassung.',
    skill_not_found: 'Einen der Skills gibt es nicht mehr. Bitte neu wählen.',
    session_not_found: 'Diesen Chat gibt es nicht mehr.',
    csrf_failed: 'Die Sitzung wurde erneuert. Bitte die Seite neu laden.'
  };
  var state = {session: '', data: null, error: '', csrf: '', loading: null};

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = String(text);
    return node;
  }
  function errText(code) {
    return ERRORS[code] || 'Das hat nicht geklappt (' + (code || 'unbekannter Fehler') + ').';
  }
  function request(method, action, params, body) {
    var opts = {method: method, credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
    if (method === 'POST') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CoreUI-CSRF'] = state.csrf;
      opts.body = JSON.stringify(body || {});
    }
    return fetch(API + '?action=' + action + (params || ''), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d || d.ok !== true) throw new Error((d && d.error) || ('http_' + r.status));
        if (d.csrf_token) state.csrf = d.csrf_token;
        return d;
      });
    });
  }
  function activeId() {
    try {
      var a = window.CoreAIChatApp && window.CoreAIChatApp.active ? window.CoreAIChatApp.active() : null;
      return a && a.id ? String(a.id) : '';
    } catch (e) { return ''; }
  }
  function byRef(ref) {
    var list = state.data ? state.data.options : [];
    for (var i = 0; i < list.length; i++) if (list[i].ref === ref) return list[i];
    return null;
  }
  function chosen() {
    return state.data ? state.data.chosen.map(byRef).filter(Boolean) : [];
  }

  // --- Loading per chat -------------------------------------------------------------------------
  function load(sessionId) {
    state.session = sessionId;
    state.data = null;
    state.error = '';
    render();
    if (!sessionId) return Promise.resolve(null);
    var p = request('GET', 'chat', '&session=' + encodeURIComponent(sessionId)).then(function (d) {
      if (state.session !== sessionId) return null;
      state.data = d;
      render();
      return d;
    }).catch(function (err) {
      if (state.session !== sessionId) return null;
      state.error = err && err.message || 'failed';
      render();
      return null;
    });
    state.loading = p;
    return p;
  }
  function follow() {
    var id = activeId();
    if (id !== state.session) load(id);
  }
  window.addEventListener('coreai:active-session', function (e) {
    var id = e && e.detail ? String(e.detail.id || '') : '';
    if (id !== state.session) load(id);
  });

  // --- The fixed skills above the input field ---------------------------------------------------
  var bar = el('div', 'composer-skills');
  bar.hidden = true;
  bar.setAttribute('aria-label', 'Skills für diesen Chat');
  field.insertBefore(bar, textarea);
  function render() {
    var list = chosen();
    bar.textContent = '';
    bar.hidden = !list.length;
    if (!list.length) return;
    bar.appendChild(el('span', 'composer-skills-label', 'Skills:'));
    list.forEach(function (o) {
      var chip = el('span', 'composer-skill');
      chip.setAttribute('data-ref', o.ref);
      chip.appendChild(el('span', 'composer-skill-name', o.title));
      if (o.variant !== 'builtin') chip.title = o.slug + ' · ' + VARIANT[o.variant] + (o.owner ? ' von ' + o.owner : '');
      var x = el('button', 'composer-skill-remove', '×');
      x.type = 'button';
      x.setAttribute('aria-label', o.title + ' aus diesem Chat nehmen');
      x.addEventListener('click', function () {
        save(state.data.chosen.filter(function (r) { return r !== o.ref; }), null);
      });
      chip.appendChild(x);
      bar.appendChild(chip);
    });
  }
  function save(refs, done) {
    var sessionId = state.session;
    return request('POST', 'chat_set', '', {session: sessionId, refs: refs}).then(function (d) {
      if (state.session === sessionId) { state.data = d; render(); }
      if (done) done(null);
    }).catch(function (err) {
      if (done) done(err && err.message || 'failed');
    });
  }

  // --- The window ----------------------------------------------------------------------------------
  var dialog = null;
  var lastFocus = null;
  function closeDialog() {
    if (!dialog) return;
    dialog.remove();
    dialog = null;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function openDialog() {
    if (!state.data) return;
    closeDialog();
    // The menu entry that opened it is gone with the menu: the focus comes back to the + button.
    lastFocus = document.getElementById('btnAttach') || textarea;
    var picked = state.data.chosen.filter(function (r) { return !!byRef(r); });
    var root = el('div', 'chat-dialog cs-dialog');
    root.id = 'chatSkillsDialog';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-labelledby', 'chatSkillsTitle');
    var box = el('div', 'chat-dialog-box cs-box');
    var head = el('div', 'chat-dialog-head');
    var h = el('h2', '', 'Skills für diesen Chat');
    h.id = 'chatSkillsTitle';
    var x = el('button', 'chat-dialog-close', '×');
    x.type = 'button';
    x.setAttribute('aria-label', 'Schließen');
    x.addEventListener('click', closeDialog);
    head.appendChild(h); head.appendChild(x);
    box.appendChild(head);
    box.appendChild(el('p', 'cs-lead', 'Bis zu drei Skills, die Mara in diesem Chat immer nutzt. Gibt es eine Kennung mehrfach, '
      + 'nimmt sie genau die gewählte Fassung. Ohne Auswahl wählt Mara selbst.'));
    if (state.data.gone && state.data.gone.length) {
      box.appendChild(el('p', 'cs-gone', 'Nicht mehr verfügbar und beim Speichern entfernt: ' + state.data.gone.length
        + (state.data.gone.length === 1 ? ' Skill.' : ' Skills.')));
    }
    var search = el('input', 'cs-search');
    search.type = 'search';
    search.placeholder = 'Skills durchsuchen';
    search.setAttribute('aria-label', 'Skills durchsuchen');
    box.appendChild(search);
    var count = el('p', 'cs-count');
    count.setAttribute('aria-live', 'polite');
    box.appendChild(count);
    var list = el('div', 'cs-list');
    box.appendChild(list);
    var error = el('p', 'chat-dialog-error');
    error.setAttribute('role', 'alert');
    box.appendChild(error);
    var actions = el('div', 'chat-dialog-actions');
    var reset = el('button', 'chat-dialog-secondary', 'Mara wählt selbst');
    reset.type = 'button';
    reset.id = 'chatSkillsReset';
    var ok = el('button', 'chat-dialog-primary', 'Speichern');
    ok.type = 'button';
    ok.id = 'chatSkillsSave';
    actions.appendChild(reset); actions.appendChild(ok);
    box.appendChild(actions);
    root.appendChild(box);

    function draw() {
      var q = search.value.trim().toLowerCase();
      list.textContent = '';
      count.textContent = picked.length + ' von ' + state.data.max + ' gewählt';
      GROUPS.forEach(function (g) {
        var items = state.data.options.filter(function (o) {
          return o.variant === g[0] && (!q || (o.title + ' ' + o.slug + ' ' + o.description + ' ' + (o.owner || '')).toLowerCase().indexOf(q) >= 0);
        });
        if (!items.length) return;
        var group = el('div', 'cs-group');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', g[1]);
        group.appendChild(el('h3', 'cs-group-title', g[1]));
        items.forEach(function (o) {
          var row = el('label', 'cs-option');
          row.setAttribute('data-ref', o.ref);
          var c = el('input');
          c.type = 'checkbox';
          c.checked = picked.indexOf(o.ref) >= 0;
          c.disabled = !c.checked && picked.length >= state.data.max
            && !picked.some(function (r) { var p = byRef(r); return p && p.slug === o.slug; });
          c.addEventListener('change', function () {
            if (c.checked) {
              // Another variant of the same short name gives way.
              picked = picked.filter(function (r) { var p = byRef(r); return !p || p.slug !== o.slug; });
              if (picked.length < state.data.max) picked.push(o.ref);
            } else {
              picked = picked.filter(function (r) { return r !== o.ref; });
            }
            error.textContent = '';
            draw();
          });
          var copy = el('span', 'cs-copy');
          copy.appendChild(el('strong', '', o.title));
          var meta = [o.slug, VARIANT[o.variant] + (o.owner ? ' von ' + o.owner : '')];
          if (o.priority && o.priority !== 'normal') meta.push(o.priority === 'high' ? 'Vorrang hoch' : 'Vorrang niedrig');
          var twins = state.data.options.filter(function (t) { return t.slug === o.slug; }).length > 1;
          if (twins) meta.push(o.used ? 'gilt hier' : 'verdeckt');
          copy.appendChild(el('small', '', meta.join(' · ')));
          copy.appendChild(el('small', 'cs-desc', o.description));
          row.appendChild(c); row.appendChild(copy);
          group.appendChild(row);
        });
        list.appendChild(group);
      });
      if (!list.firstChild) list.appendChild(el('p', 'cs-empty', 'Kein Skill passt zur Suche.'));
    }
    function finish(refs) {
      ok.disabled = true; reset.disabled = true;
      save(refs, function (err) {
        ok.disabled = false; reset.disabled = false;
        // A disabled button drops the focus: it comes back into the window with the reason.
        if (err) { error.textContent = errText(err); ok.focus(); return; }
        closeDialog();
      });
    }
    search.addEventListener('input', draw);
    ok.addEventListener('click', function () { finish(picked.slice()); });
    reset.addEventListener('click', function () { finish([]); });
    root.addEventListener('click', function (e) { if (e.target === root) closeDialog(); });

    draw();
    document.body.appendChild(root);
    dialog = root;
    search.focus();
  }

  // Escape closes the window wherever the focus is.
  document.addEventListener('keydown', function (e) {
    if (dialog && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeDialog(); }
  }, true);

  menu.registerSkills({
    detail: function () {
      follow(); // the menu may open before the chat app said which chat is open
      if (!state.session) return 'Nach der ersten Nachricht in diesem Chat';
      if (state.error) return errText(state.error);
      if (!state.data) return 'Wird geladen …';
      var list = chosen();
      return list.length ? list.map(function (o) { return o.title; }).join(', ') : 'Mara wählt selbst';
    },
    disabled: function () { return !state.session || !!state.error || !state.data; },
    onSelect: function () {
      if (state.data) openDialog();
    }
  });

  window.CoreAIChatSkills = {
    reload: function () { return load(activeId()); },
    open: function () { if (state.data) openDialog(); },
    current: function () { return state.data ? state.data.chosen.slice() : []; }
  };
  follow();
}());
