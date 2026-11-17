/* CoreAI: TCI Interface 0.7.83 (P12 A), 0.7.84 (P12 B): Settings > Skills.
   The account's own skills (api/skill_packages.php): a list with search, and an editor with the
   name, the short name Mara writes in [SKILL: ...], a description, trigger words, the tools a skill
   needs, where it applies (every chat or one project), on or off, the instruction, and companion
   files in references/, templates/, scripts/ and assets/. Files the instruction names but the skill
   does not have are shown. Below, the built-in library to look up. The server checks everything;
   this module only shows and sends. */
(function () {
  'use strict';

  var view = document.getElementById('view-skills');
  if (!view || !document.getElementById('skMain')) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/skill_packages.php';
  var FOLDERS = [['references', 'Referenz'], ['templates', 'Vorlage'], ['scripts', 'Skript'], ['assets', 'Datei']];
  var KIND = {reference: 'Referenz', template: 'Vorlage', script: 'Skript', asset: 'Datei'};
  var ERRORS = {
    guests_not_allowed: 'Skills gibt es nur für angemeldete Konten.',
    skill_migration_required: 'Das Update ist noch nicht vollständig eingespielt (Migration 052).',
    invalid_skill_slug: 'Die Kennung besteht aus Kleinbuchstaben, Ziffern und Bindestrichen (2 bis 64 Zeichen).',
    skill_builtin_name: 'So heißt schon ein mitgelieferter Skill. Bitte eine andere Kennung wählen.',
    invalid_skill_title: 'Bitte einen Namen eingeben (höchstens 80 Zeichen).',
    invalid_skill_description: 'Bitte kurz beschreiben, wofür der Skill da ist (höchstens 300 Zeichen).',
    invalid_skill_body: 'Bitte die Anleitung eingeben.',
    skill_body_too_large: 'Die Anleitung ist länger als 4.000 Bytes. Lege Details als Referenz ab.',
    invalid_skill_triggers: 'Höchstens 12 Auslöser mit je höchstens 40 Zeichen.',
    invalid_skill_tool: 'Unbekannte Voraussetzung.',
    skill_exists: 'Einen Skill mit dieser Kennung hast du schon.',
    skill_limit: 'Du hast schon 50 Skills.',
    skill_not_found: 'Diesen Skill gibt es nicht mehr.',
    project_not_found: 'Dieses Projekt gibt es nicht mehr.',
    invalid_project: 'Dieses Projekt gibt es nicht mehr.',
    invalid_skill_path: 'Der Dateiname besteht aus Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich und hat eine Endung, zum Beispiel plan.md.',
    invalid_skill_file: 'Das ist kein reiner Text.',
    skill_file_too_large: 'Die Datei ist größer als 8.000 Bytes.',
    skill_file_exists: 'Diese Datei gibt es schon.',
    skill_file_not_found: 'Diese Datei gibt es nicht mehr.',
    skill_files_limit: 'Ein Skill hat höchstens 20 Begleitdateien.',
    csrf_failed: 'Die Sitzung wurde erneuert. Bitte die Seite neu laden.',
    skill_share_forbidden: 'Nur der Inhaber und Admins können Skills mit allen Konten teilen.',
    skill_share_project: 'Ein Projekt-Skill lässt sich nicht teilen.',
    invalid_skill_priority: 'Unbekannter Vorrang.'
  };
  // 0.7.84 (P12 B): precedence of a skill.
  var PRIORITY = {high: 'Hoch', normal: 'Normal', low: 'Niedrig'};
  var state = {csrf: '', ready: true, skills: [], builtin: [], projects: [], tools: {}, q: '', edit: null, file: null, busy: false,
    loaded: false, searchTimer: 0, sharing: false, canShare: false, shared: []};

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function errText(err) {
    var code = err && err.message || '';
    return ERRORS[code] || 'Das hat nicht geklappt (' + (code || 'unbekannter Fehler') + ').';
  }
  function status(text, error) {
    var s = $('skStatus');
    s.textContent = text || '';
    s.hidden = !text;
    s.classList.toggle('is-error', !!error);
  }
  function request(method, action, params, body) {
    var url = API + '?action=' + encodeURIComponent(action) + (params || '');
    var opts = {method: method, credentials: 'same-origin', cache: 'no-store', headers: {}};
    if (method === 'POST') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CoreUI-CSRF'] = state.csrf;
      opts.body = JSON.stringify(body || {});
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || !d || d.ok !== true) throw new Error((d && d.error) || ('http_' + r.status));
        if (d.csrf_token) state.csrf = d.csrf_token;
        return d;
      });
    });
  }
  function bytes(text) { return new TextEncoder().encode(text || '').length; }

  // --- The list -----------------------------------------------------------------------------------------
  function load(note) {
    return request('GET', 'list', state.q ? '&q=' + encodeURIComponent(state.q) : '').then(function (d) {
      state.ready = d.ready !== false;
      if (!state.ready) { status(ERRORS.skill_migration_required, true); $('skMain').hidden = true; $('skNew').disabled = true; return; }
      state.skills = d.skills || []; state.builtin = d.builtin || []; state.projects = d.projects || []; state.tools = d.tools || {};
      state.sharing = d.sharing === true; state.canShare = d.can_share === true; state.shared = d.shared || [];
      status(note || '');
      $('skMain').hidden = !!state.edit;
      renderList();
    }).catch(function (err) {
      status(errText(err), true);
      if (err && err.message === 'guests_not_allowed') { $('skMain').hidden = true; $('skNew').disabled = true; }
    });
  }
  function toolNames(list) {
    return (list || []).map(function (t) { return state.tools[t] || t; }).join(', ');
  }
  function renderList() {
    var own = $('skOwn');
    own.textContent = '';
    if (!state.skills.length) {
      own.appendChild(el('p', 'sk-empty', state.q ? 'Keiner deiner Skills passt zur Suche.'
        : 'Noch keine eigenen Skills. Mit „Neuer Skill“ legst du einen an; Mara findet ihn dann wie die mitgelieferten.'));
    }
    state.skills.forEach(function (sk) {
      var row = el('div', 'sec-row sk-row' + (sk.enabled ? '' : ' is-off'));
      row.setAttribute('data-skill', sk.slug);
      var copy = el('div', 'sec-copy');
      var title = el('strong', '', sk.title);
      copy.appendChild(title);
      var meta = [sk.slug, sk.project ? 'nur Projekt „' + sk.project.name + '“' : 'alle Chats',
        sk.files.length === 1 ? '1 Begleitdatei' : sk.files.length + ' Begleitdateien'];
      if (sk.requires.length) meta.push('braucht ' + toolNames(sk.requires));
      if (sk.priority && sk.priority !== 'normal') meta.push('Vorrang ' + PRIORITY[sk.priority].toLowerCase());
      if (sk.shared) meta.push('mit allen Konten geteilt');
      if (!sk.enabled) meta.push('aus');
      copy.appendChild(el('small', '', meta.join(' · ')));
      copy.appendChild(el('small', 'sk-desc', sk.description));
      if (sk.missing.length) copy.appendChild(el('small', 'sk-warn', 'Fehlt: ' + sk.missing.join(', ')));
      row.appendChild(copy);
      var open = el('button', 'sec-open', '');
      open.type = 'button';
      open.setAttribute('aria-label', sk.title + ' bearbeiten');
      open.innerHTML = '<svg aria-hidden="true"><use href="#i-chevron" /></svg>';
      row.appendChild(open);
      row.addEventListener('click', function () { openSkill(sk.id); });
      own.appendChild(row);
    });
    renderShared();
    var builtin = $('skBuiltin');
    builtin.textContent = '';
    var q = state.q.toLowerCase();
    var hits = state.builtin.filter(function (b) {
      return !q || (b.title + ' ' + b.id + ' ' + b.category + ' ' + b.description).toLowerCase().indexOf(q) >= 0;
    });
    var shown = q ? hits : hits.slice(0, 12);
    shown.forEach(function (b) {
      var row = el('div', 'sec-row sk-row sk-builtin');
      row.setAttribute('data-builtin', b.id);
      var copy = el('div', 'sec-copy');
      copy.appendChild(el('strong', '', b.title));
      copy.appendChild(el('small', '', [b.id, b.category, b.required ? 'immer aktiv' : '',
        b.references ? (b.references === 1 ? '1 Referenz' : b.references + ' Referenzen') : ''].filter(Boolean).join(' · ')));
      copy.appendChild(el('small', 'sk-desc', b.description));
      row.appendChild(copy);
      builtin.appendChild(row);
    });
    if (!hits.length) builtin.appendChild(el('p', 'sk-empty', 'Kein mitgelieferter Skill passt zur Suche.'));
    if (!q && hits.length > shown.length) {
      builtin.appendChild(el('p', 'sk-empty', 'Und ' + (hits.length - shown.length) + ' weitere. Suche nach einem Begriff, um sie zu finden.'));
    }
  }

  // --- Shared skills (0.7.84, P12 B) -------------------------------------------------------------------
  function sharedNote(sk) {
    if (!sk.shadowed_by) return '';
    return sk.shadowed_by.variant === 'own'
      ? 'Dein eigener Skill „' + sk.shadowed_by.title + '“ mit derselben Kennung geht vor.'
      : 'Der gemeinsame Skill „' + sk.shadowed_by.title + '“ von ' + sk.shadowed_by.owner + ' mit derselben Kennung geht vor.';
  }
  function useSwitch(sk) {
    var sw = el('label', 'settings-switch sk-use');
    var input = el('input'); input.type = 'checkbox'; input.checked = !!sk.used; input.setAttribute('data-use', sk.id);
    input.setAttribute('aria-label', 'Für mich nutzen: ' + sk.title);
    sw.appendChild(input); sw.appendChild(el('i'));
    sw.addEventListener('click', function (e) { e.stopPropagation(); });
    input.addEventListener('change', function () {
      var on = input.checked;
      request('POST', 'use', '', {id: sk.id, on: on}).then(function (d) {
        state.shared = d.shared || [];
        sk.used = on;
        if (state.edit && state.edit.view) { state.edit.used = on; renderView(state.edit); } else renderShared();
        status(on ? 'Mara nutzt „' + sk.title + '“ wieder.' : '„' + sk.title + '“ ist für dich ausgeschaltet.');
      }).catch(function (err) { input.checked = !on; status(errText(err), true); });
    });
    return sw;
  }
  function renderShared() {
    var box = $('skShared');
    $('skSharedHead').hidden = !state.sharing;
    box.hidden = !state.sharing;
    box.textContent = '';
    if (!state.sharing) return;
    if (!state.shared.length) {
      box.appendChild(el('p', 'sk-empty', state.q ? 'Kein gemeinsamer Skill passt zur Suche.'
        : 'Noch keine gemeinsamen Skills. Der Inhaber und Admins können eigene Skills mit allen Konten teilen.'));
    }
    state.shared.forEach(function (sk) {
      var row = el('div', 'sec-row sk-row sk-shared' + (sk.used ? '' : ' is-off'));
      row.setAttribute('data-shared', sk.slug);
      var copy = el('div', 'sec-copy');
      copy.appendChild(el('strong', '', sk.title));
      var meta = [sk.slug, 'von ' + sk.owner, sk.files.length === 1 ? '1 Begleitdatei' : sk.files.length + ' Begleitdateien'];
      if (sk.requires.length) meta.push('braucht ' + toolNames(sk.requires));
      if (sk.priority && sk.priority !== 'normal') meta.push('Vorrang ' + PRIORITY[sk.priority].toLowerCase());
      if (!sk.used) meta.push('für dich aus');
      copy.appendChild(el('small', '', meta.join(' · ')));
      copy.appendChild(el('small', 'sk-desc', sk.description));
      if (sk.used && sk.shadowed_by) copy.appendChild(el('small', 'sk-note', sharedNote(sk)));
      row.appendChild(copy);
      row.appendChild(useSwitch(sk));
      var open = el('button', 'sec-open', '');
      open.type = 'button';
      open.setAttribute('aria-label', sk.title + ' ansehen');
      open.innerHTML = '<svg aria-hidden="true"><use href="#i-chevron" /></svg>';
      row.appendChild(open);
      row.addEventListener('click', function () { viewShared(sk.id); });
      box.appendChild(row);
    });
  }
  function viewShared(id) {
    if (state.busy) return;
    state.busy = true;
    request('GET', 'view', '&id=' + encodeURIComponent(id)).then(function (d) {
      state.edit = Object.assign(d.skill, {view: true}); state.file = null;
      renderView(state.edit);
    }).catch(function (err) { status(errText(err), true); })
      .then(function () { state.busy = false; });
  }
  function renderView(sk) {
    var box = $('skEdit');
    box.textContent = '';
    box.hidden = false;
    $('skMain').hidden = true;
    var bar = el('div', 'pz-sub-bar');
    var back = el('button', 'sec-back', '');
    back.type = 'button'; back.id = 'skBack';
    back.innerHTML = '<svg aria-hidden="true"><use href="#i-back" /></svg>Skills';
    back.addEventListener('click', function () { closeEdit(); });
    bar.appendChild(back);
    box.appendChild(bar);
    box.appendChild(el('h3', 'sk-edit-title', sk.title));
    var panel = el('section', 'settings-panel cfg-card wide sk-form sk-view');
    panel.id = 'skView';
    var meta = [sk.slug, 'gemeinsamer Skill von ' + sk.owner, 'Vorrang ' + PRIORITY[sk.priority || 'normal'].toLowerCase()];
    if (sk.requires.length) meta.push('braucht ' + toolNames(sk.requires));
    panel.appendChild(el('p', 'pz-lead', meta.join(' · ')));
    panel.appendChild(el('p', 'sk-desc', sk.description));
    var on = el('div', 'setting-row sk-on');
    var onCopy = el('span', 'setting-copy');
    onCopy.appendChild(el('strong', '', 'FÜR MICH NUTZEN'));
    onCopy.appendChild(el('small', '', 'Ändern kann ihn nur, wer ihn geteilt hat. Ausschalten gilt nur für dein Konto.'));
    on.appendChild(onCopy); on.appendChild(useSwitch(sk));
    panel.appendChild(on);
    panel.appendChild(el('strong', 'sk-file-head', 'ANLEITUNG'));
    panel.appendChild(el('pre', 'sk-pre', sk.body || ''));
    if (sk.files.length) {
      panel.appendChild(el('strong', 'sk-file-head', 'BEGLEITDATEIEN'));
      sk.files.forEach(function (f) {
        var d = el('details', 'sk-view-file');
        d.setAttribute('data-file', f.path);
        d.appendChild(el('summary', '', f.path + ' · ' + (KIND[f.kind] || f.kind) + ' · ' + f.size_bytes.toLocaleString('de-DE') + ' Bytes'));
        d.appendChild(el('pre', 'sk-pre', f.content || ''));
        panel.appendChild(d);
      });
    }
    box.appendChild(panel);
  }

  // --- The editor ---------------------------------------------------------------------------------------
  function blank() {
    return {id: '', slug: '', title: '', description: '', triggers: [], requires: [], project: null, enabled: true, body: '', files: [], missing: [],
      priority: 'normal', shared: false};
  }
  function openSkill(id) {
    if (state.busy) return;
    state.busy = true;
    request('GET', 'get', '&id=' + encodeURIComponent(id)).then(function (d) {
      state.edit = d.skill; state.file = null;
      renderEdit();
    }).catch(function (err) { status(errText(err), true); })
      .then(function () { state.busy = false; });
  }
  function closeEdit(note) {
    state.edit = null; state.file = null;
    $('skEdit').hidden = true; $('skEdit').textContent = '';
    $('skMain').hidden = false;
    status(note || '');
    load(note);
  }
  function field(label, hint, input) {
    var box = el('label', 'settings-field sk-field');
    var head = el('span');
    head.appendChild(el('strong', '', label));
    if (hint) head.appendChild(el('small', '', hint));
    box.appendChild(head);
    box.appendChild(input);
    return box;
  }
  function renderEdit() {
    var sk = state.edit;
    var box = $('skEdit');
    box.textContent = '';
    box.hidden = false;
    $('skMain').hidden = true;
    var bar = el('div', 'pz-sub-bar');
    var back = el('button', 'sec-back', '');
    back.type = 'button'; back.id = 'skBack';
    back.innerHTML = '<svg aria-hidden="true"><use href="#i-back" /></svg>Skills';
    back.addEventListener('click', function () { closeEdit(); });
    bar.appendChild(back);
    box.appendChild(bar);
    box.appendChild(el('h3', 'sk-edit-title', sk.id ? sk.title : 'Neuer Skill'));

    var form = el('form', 'settings-panel cfg-card wide sk-form');
    form.id = 'skForm';
    var title = el('input'); title.id = 'skTitle'; title.type = 'text'; title.maxLength = 80; title.value = sk.title; title.required = true;
    var slug = el('input'); slug.id = 'skSlug'; slug.type = 'text'; slug.maxLength = 64; slug.value = sk.slug; slug.required = true;
    slug.autocomplete = 'off'; slug.spellcheck = false; slug.placeholder = 'zum-beispiel-wochenbericht';
    var desc = el('input'); desc.id = 'skDescription'; desc.type = 'text'; desc.maxLength = 300; desc.value = sk.description; desc.required = true;
    var triggers = el('input'); triggers.id = 'skTriggers'; triggers.type = 'text'; triggers.value = sk.triggers.join(', ');
    triggers.placeholder = 'wochenbericht, statusbericht';
    form.appendChild(field('NAME', 'So erscheint der Skill in der Liste.', title));
    form.appendChild(field('KENNUNG', 'Mara lädt den Skill mit [SKILL: kennung]; du kannst ihn mit /skill kennung vorgeben.', slug));
    form.appendChild(field('BESCHREIBUNG', 'Wofür der Skill da ist. Danach entscheidet Mara, ob sie ihn lädt.', desc));
    form.appendChild(field('AUSLÖSER', 'Wörter, bei denen der Skill gleich mitkommt, durch Kommas getrennt (höchstens 12).', triggers));

    var reqBox = el('fieldset', 'sk-requires');
    reqBox.appendChild(el('legend', '', 'VORAUSSETZUNGEN'));
    reqBox.appendChild(el('small', '', 'Werkzeuge, die der Skill braucht. Fehlt eines in einem Chat, sagt Mara das statt ein Ergebnis zu behaupten.'));
    Object.keys(state.tools).forEach(function (key) {
      var l = el('label', 'sk-check');
      var c = el('input'); c.type = 'checkbox'; c.value = key; c.checked = sk.requires.indexOf(key) >= 0; c.setAttribute('data-tool', key);
      l.appendChild(c); l.appendChild(el('span', '', state.tools[key]));
      reqBox.appendChild(l);
    });
    form.appendChild(reqBox);

    var scope = el('select'); scope.id = 'skScope';
    var all = el('option', '', 'Alle meine Chats'); all.value = ''; scope.appendChild(all);
    state.projects.forEach(function (p) {
      var o = el('option', '', 'Nur Projekt „' + p.name + '“'); o.value = p.id; scope.appendChild(o);
    });
    scope.value = sk.project ? sk.project.id : '';
    form.appendChild(field('GILT FÜR', 'Ein Projekt-Skill steht Mara nur in den Chats dieses Projekts zur Verfügung.', scope));

    var on = el('label', 'setting-row sk-on');
    var onCopy = el('span', 'setting-copy');
    onCopy.appendChild(el('strong', '', 'AKTIV'));
    onCopy.appendChild(el('small', '', 'Nur aktive Skills bietet CoreAI Mara an.'));
    var sw = el('span', 'settings-switch');
    var enabled = el('input'); enabled.type = 'checkbox'; enabled.id = 'skEnabled'; enabled.checked = sk.enabled;
    sw.appendChild(enabled); sw.appendChild(el('i'));
    on.appendChild(onCopy); on.appendChild(sw);
    form.appendChild(on);

    // 0.7.84 (P12 B): precedence, and sharing for the Inhaber and Admins.
    var prio = el('select'); prio.id = 'skPriority';
    Object.keys(PRIORITY).forEach(function (key) { var o = el('option', '', PRIORITY[key]); o.value = key; prio.appendChild(o); });
    prio.value = sk.priority || 'normal';
    if (state.sharing) {
      form.appendChild(field('VORRANG', 'Passen mehr Skills, als ins Kontextfenster passen, kommen die mit hohem Vorrang zuerst. '
        + 'Haben zwei gemeinsame Skills dieselbe Kennung, gilt der mit höherem Vorrang.', prio));
    }
    var share = null;
    if (state.canShare) {
      var shareRow = el('label', 'setting-row sk-on');
      var shareCopy = el('span', 'setting-copy');
      shareCopy.appendChild(el('strong', '', 'MIT ALLEN KONTEN TEILEN'));
      var shareHint = el('small', '', '');
      shareCopy.appendChild(shareHint);
      var shareSw = el('span', 'settings-switch');
      share = el('input'); share.type = 'checkbox'; share.id = 'skShare'; share.checked = !!sk.shared;
      shareSw.appendChild(share); shareSw.appendChild(el('i'));
      shareRow.appendChild(shareCopy); shareRow.appendChild(shareSw);
      form.appendChild(shareRow);
      var syncShare = function () {
        var project = scope.value !== '';
        share.disabled = project;
        if (project) share.checked = false;
        shareHint.textContent = project ? 'Ein Projekt-Skill bleibt in deinem Projekt und lässt sich nicht teilen.'
          : 'Andere Konten finden ihn unter „Gemeinsam“ und können ihn für sich ausschalten. Ändern kannst nur du.';
      };
      scope.addEventListener('change', syncShare);
      syncShare();
    }

    var bodyInput = el('textarea', 'sk-body'); bodyInput.id = 'skBody'; bodyInput.rows = 12; bodyInput.value = sk.body || ''; bodyInput.spellcheck = true;
    bodyInput.placeholder = 'Was Mara Schritt für Schritt tun soll. Begleitdateien nennst du mit ihrem Pfad, zum Beispiel templates/bericht.md.';
    var count = el('small', 'sk-count');
    var updateCount = function () {
      var n = bytes(bodyInput.value);
      count.textContent = n.toLocaleString('de-DE') + ' von 4.000 Bytes';
      count.classList.toggle('is-over', n > 4000);
    };
    bodyInput.addEventListener('input', updateCount);
    updateCount();
    form.appendChild(field('ANLEITUNG', 'Das folgt Mara, wenn sie den Skill lädt.', bodyInput));
    form.appendChild(count);
    if (sk.missing && sk.missing.length) {
      var warn = el('p', 'sk-warn', 'Die Anleitung nennt Dateien, die fehlen: ' + sk.missing.join(', ') + '. Lege sie unten an oder ändere die Anleitung.');
      warn.id = 'skMissing';
      form.appendChild(warn);
    }
    var actions = el('div', 'sk-actions');
    var save = el('button', 'settings-action', sk.id ? 'SPEICHERN' : 'ANLEGEN'); save.type = 'submit'; save.id = 'skSave';
    actions.appendChild(save);
    if (sk.id) {
      var del = el('button', 'settings-action sk-danger', 'LÖSCHEN'); del.type = 'button'; del.id = 'skDelete';
      del.addEventListener('click', function () {
        if (!window.confirm('„' + sk.title + '“ mit allen Begleitdateien löschen? Mara kann ihn danach nicht mehr laden.')) return;
        request('POST', 'delete', '', {id: sk.id}).then(function () { closeEdit('Der Skill wurde gelöscht.'); })
          .catch(function (err) { status(errText(err), true); });
      });
      actions.appendChild(del);
    }
    form.appendChild(actions);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (state.busy) return;
      var requires = Array.prototype.slice.call(reqBox.querySelectorAll('input[type=checkbox]:checked')).map(function (c) { return c.value; });
      state.busy = true; save.disabled = true;
      request('POST', 'save', '', {id: sk.id, title: title.value, slug: slug.value, description: desc.value, triggers: triggers.value,
        requires: requires, project_id: scope.value, enabled: enabled.checked, body: bodyInput.value,
        priority: prio.value, shared: share ? share.checked : false}).then(function (d) {
        var created = !sk.id;
        state.edit = d.skill;
        renderEdit();
        status(created ? 'Der Skill ist angelegt. Jetzt kannst du Begleitdateien hinzufügen.' : 'Gespeichert.');
      }).catch(function (err) { status(errText(err), true); })
        .then(function () { state.busy = false; save.disabled = false; });
    });
    box.appendChild(form);
    if (sk.id) box.appendChild(renderFiles(sk));
  }

  function renderFiles(sk) {
    var wrap = el('section', 'settings-panel cfg-card wide sk-files');
    wrap.setAttribute('aria-label', 'Begleitdateien');
    wrap.appendChild(el('h3', 'sec-heading', 'Begleitdateien'));
    wrap.appendChild(el('p', 'pz-lead', 'Mara lädt eine Datei mit [SKILL: ' + sk.slug + '/ordner/name]. Referenzen liest sie, Vorlagen füllt sie aus, '
      + 'Daten nutzt sie. Ein Skript bekommt sie nur als Text: Es läuft nie von selbst.'));
    var list = el('div', 'sec-group sk-file-list');
    if (!sk.files.length) list.appendChild(el('p', 'sk-empty', 'Noch keine Begleitdateien.'));
    sk.files.forEach(function (f) {
      var row = el('div', 'sec-row sk-file');
      row.setAttribute('data-file', f.path);
      var copy = el('div', 'sec-copy');
      copy.appendChild(el('strong', '', f.path));
      copy.appendChild(el('small', '', (KIND[f.kind] || f.kind) + ' · ' + f.size_bytes.toLocaleString('de-DE') + ' Bytes'));
      row.appendChild(copy);
      var edit = el('button', 'settings-action sk-small', 'BEARBEITEN'); edit.type = 'button';
      edit.addEventListener('click', function () { state.file = {path: f.path, content: f.content || ''}; renderEdit(); });
      var del = el('button', 'settings-action sk-small sk-danger', 'LÖSCHEN'); del.type = 'button';
      del.addEventListener('click', function () {
        if (!window.confirm(f.path + ' löschen?')) return;
        request('POST', 'file_delete', '', {id: sk.id, path: f.path}).then(function (d) { state.edit = d.skill; state.file = null; renderEdit(); status('Die Datei wurde gelöscht.'); })
          .catch(function (err) { status(errText(err), true); });
      });
      var tools = el('div', 'sk-file-actions');
      tools.appendChild(edit); tools.appendChild(del);
      row.appendChild(tools);
      list.appendChild(row);
    });
    wrap.appendChild(list);

    var editing = state.file;
    var form = el('form', 'sk-file-form');
    form.id = 'skFileForm';
    var folder = el('select'); folder.id = 'skFileFolder';
    FOLDERS.forEach(function (pair) { var o = el('option', '', pair[1] + ' (' + pair[0] + '/)'); o.value = pair[0]; folder.appendChild(o); });
    var name = el('input'); name.id = 'skFileName'; name.type = 'text'; name.maxLength = 81; name.placeholder = 'bericht.md'; name.spellcheck = false;
    if (editing) { folder.value = editing.path.split('/')[0]; name.value = editing.path.split('/').slice(1).join('/'); }
    var content = el('textarea', 'sk-body'); content.id = 'skFileContent'; content.rows = 10; content.spellcheck = false;
    content.value = editing ? editing.content : '';
    var row1 = el('div', 'sk-file-path');
    row1.appendChild(field('ORDNER', '', folder));
    row1.appendChild(field('DATEINAME', '', name));
    form.appendChild(el('strong', 'sk-file-head', editing ? editing.path + ' bearbeiten' : 'Datei hinzufügen'));
    form.appendChild(row1);
    form.appendChild(field('INHALT', 'Reiner Text, höchstens 8.000 Bytes.', content));
    var actions = el('div', 'sk-actions');
    var save = el('button', 'settings-action', editing ? 'DATEI SPEICHERN' : 'DATEI HINZUFÜGEN'); save.type = 'submit'; save.id = 'skFileSave';
    actions.appendChild(save);
    if (editing) {
      var cancel = el('button', 'settings-action sk-small', 'ABBRECHEN'); cancel.type = 'button';
      cancel.addEventListener('click', function () { state.file = null; renderEdit(); });
      actions.appendChild(cancel);
    }
    form.appendChild(actions);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (state.busy) return;
      state.busy = true; save.disabled = true;
      request('POST', 'file_save', '', {id: sk.id, path: folder.value + '/' + name.value.trim(), content: content.value,
        old_path: editing ? editing.path : ''}).then(function (d) {
        state.edit = d.skill; state.file = null;
        renderEdit();
        status('Die Datei ist gespeichert.');
      }).catch(function (err) { status(errText(err), true); })
        .then(function () { state.busy = false; save.disabled = false; });
    });
    wrap.appendChild(form);
    return wrap;
  }

  $('skNew').addEventListener('click', function () {
    if (!state.ready) return;
    state.edit = blank(); state.file = null;
    status('');
    renderEdit();
    $('skTitle').focus();
  });
  $('skSearch').addEventListener('input', function () {
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(function () { state.q = $('skSearch').value.trim(); load(); }, 250);
  });

  function ensure() {
    if (state.loaded || view.hidden) return;
    state.loaded = true;
    load();
  }
  new MutationObserver(ensure).observe(view, {attributes: true, attributeFilter: ['hidden']});
  ensure();
}());
