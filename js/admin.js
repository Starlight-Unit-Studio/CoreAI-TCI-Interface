/* CoreAI: TCI Interface isolated administration controller.
   0.5.79: Admin Core lives in the settings page as its own tab group. The
   tabs appear only for Inhaber and Admin (the server checks every call
   anyway); a category loads its data when its tab opens. */
(function () {
  'use strict';

  var csrfToken = '';
  var actorLevel = 4;
  var actorId = 0;
  var flashTimer = 0;
  var runtimeState = {};
  var ready = false;
  var flash = document.getElementById('adminFlash');
  var identity = document.getElementById('adminIdentity');
  var navGroup = document.getElementById('adminNavGroup');
  var startAdmin = document.getElementById('startAdmin');
  var VIEWS = {
    'admin-uebersicht': function () { return loadOverview(); },
    'admin-benutzer': function () { return loadUsers(); },
    'admin-zugang': function () { return loadOverview(); },
    'admin-betrieb': function () { return loadOverview(); },
    'admin-memory': function () { return loadMemories(); },
    'admin-lore': function () { return loadLore(false); },
    'admin-protokoll': function () { return loadLogs(); },
    'admin-audit': function () { return loadAudit(); }
  };

  function show(message, error) {
    if (!flash) return;
    flash.textContent = message;
    flash.classList.toggle('error', error === true);
    flash.classList.add('visible');
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () { flash.classList.remove('visible'); }, 3200);
  }

  function api(action, options, query) {
    options = options || {};
    var url = './api/admin.php?action=' + encodeURIComponent(action);
    if (query) url += '&' + query;
    options.credentials = 'include';
    options.cache = 'no-store';
    options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CoreUI-CSRF'] = csrfToken;
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data.ok) {
          var err = new Error(data.error || ('HTTP_' + response.status));
          err.data = data;
          if (response.status === 401) location.replace('login.html');
          if (response.status === 403 && data.error === 'insufficient_permissions') hideAdmin();
          throw err;
        }
        return data;
      });
    });
  }

  function post(action, body) {
    return api(action, { method: 'POST', body: JSON.stringify(body || {}) });
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function tabs() {
    return window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  }

  function showAdmin() {
    if (navGroup) navGroup.hidden = false;
    if (startAdmin) startAdmin.hidden = false;
    var instance = tabs();
    if (instance) instance.refresh();
  }

  function hideAdmin() {
    ready = false;
    if (navGroup) navGroup.hidden = true;
    if (startAdmin) startAdmin.hidden = true;
    var instance = tabs();
    if (instance && /^admin-/.test(instance.current())) instance.show('start');
  }

  // A category loads its data when its tab opens.
  function onView(event) {
    var name = event && event.detail ? event.detail.view : '';
    if (ready && VIEWS[name]) VIEWS[name]();
  }

  function loadOverview() {
    return api('overview').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      runtimeState = data.settings || {};
      // The figures also show on the start view tiles.
      Object.keys(data.stats || {}).forEach(function (key) {
        document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (target) {
          target.textContent = String(data.stats[key]);
        });
      });
      fillRuntime(runtimeState);
      showGuestGuard(data.client_address || null);
    }).catch(function (error) { show('Übersicht: ' + error.message, true); });
  }

  function localDateTime(value) {
    if (!value) return '';
    var normalized = String(value).replace(' ', 'T');
    return normalized.slice(0, 16);
  }

  var ROLE_OPTIONS = [[0, 'INHABER'], [1, 'ADMIN'], [4, 'NUTZER']];
  // 0.5.83: models outside CoreAI and their releases per account (Inhaber only).
  var modelAccess = null;

  function loadModelAccess() {
    if (actorLevel !== 0) return Promise.resolve(null);
    return api('model_access').then(function (data) {
      modelAccess = data;
      return data;
    }).catch(function (error) {
      modelAccess = null;
      show('Cores: ' + error.message, true);
      return null;
    });
  }

  function grantsOf(user) {
    var grants = modelAccess && modelAccess.grants ? modelAccess.grants[String(user.id)] : null;
    return Array.isArray(grants) ? grants : [];
  }

  // Cell of the CORES column: the release button, or why there is none.
  function modelCell(user, row) {
    var cell = el('td', 'model-access-cell');
    if (Number(user.is_guest) === 1) { cell.appendChild(el('small', '', 'Standard-Core')); return cell; }
    if (Number(user.permission_level) === 0) { cell.appendChild(el('small', '', 'alle (Inhaber)')); return cell; }
    if (actorLevel !== 0 || !modelAccess) { cell.appendChild(el('small', '', 'nur Inhaber')); return cell; }
    var toggle = el('button', 'model-access-toggle', 'FREIGABEN (' + grantsOf(user).length + ')');
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', function () {
      var open = row.nextSibling && row.nextSibling.classList && row.nextSibling.classList.contains('model-access-row');
      closePanel(row);
      if (open) return;
      row.parentNode.insertBefore(modelPanel(user, toggle), row.nextSibling);
      toggle.setAttribute('aria-expanded', 'true');
    });
    cell.appendChild(toggle);
    return cell;
  }

  // One panel row under an account at a time (Core releases or deleting).
  function closePanel(row) {
    var next = row.nextSibling;
    if (next && next.classList && next.classList.contains('user-panel-row')) next.parentNode.removeChild(next);
    row.querySelectorAll('[aria-expanded="true"]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
  }

  // 0.5.84: who may be deleted by whom (the server checks the same):
  // never oneself or an Inhaber, an Admin only Nutzer and Gaeste.
  function canDelete(user) {
    var level = Number(user.permission_level);
    if (Number(user.id) === actorId || level === 0) return false;
    return actorLevel === 0 || (actorLevel === 1 && level > 1);
  }

  function accountName(user) {
    return String(user.username || user.id);
  }

  var DELETE_ERRORS = {
    account_busy: 'Das Konto arbeitet gerade (laufende Antwort, Browser oder Python). Kurz warten und erneut löschen.',
    protected_account: 'Dieses Konto ist geschützt und kann nicht gelöscht werden.',
    self_delete_blocked: 'Das eigene Konto kann hier nicht gelöscht werden.',
    delete_confirmation_required: 'Die eingetippte Bestätigung stimmt nicht.',
    user_not_found: 'Das Konto gibt es nicht mehr.'
  };

  // Row under an account: what goes, the typed confirmation, then delete.
  function deletePanel(user, toggle) {
    var tr = el('tr', 'user-panel-row user-delete-row');
    var td = el('td');
    td.colSpan = 7;
    var who = (user.display_name || accountName(user)) + (user.username ? ' (' + user.username + ')' : '');
    td.appendChild(el('p', 'user-delete-title', 'Konto ' + who + ' endgültig löschen?'));
    td.appendChild(el('p', 'model-access-note', 'Gelöscht werden alle Chats mit Nachrichten, Anhängen und Dateien, hochgeladenes Wissen, '
      + 'Maras Erinnerungen an dieses Konto, Profil und Avatare, Einstellungen, Core-Freigaben und alle Anmeldungen. '
      + 'Das lässt sich nicht rückgängig machen. Im Audit bleibt nur ein Eintrag mit der E-Mail.'));
    var label = el('label', 'user-delete-confirm');
    label.appendChild(el('span', '', 'Zum Bestätigen eintippen: '));
    label.appendChild(el('code', '', accountName(user)));
    var input = document.createElement('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('aria-label', 'Bestätigung: ' + accountName(user));
    label.appendChild(input);
    td.appendChild(label);
    var actions = el('div', 'user-delete-actions');
    var cancel = el('button', 'settings-action compact', 'ABBRECHEN');
    cancel.type = 'button';
    var confirm = el('button', 'settings-action compact danger', 'ENDGÜLTIG LÖSCHEN');
    confirm.type = 'button';
    confirm.disabled = true;
    actions.appendChild(cancel);
    actions.appendChild(confirm);
    td.appendChild(actions);
    tr.appendChild(td);

    input.addEventListener('input', function () {
      confirm.disabled = input.value.trim().toLowerCase() !== accountName(user).toLowerCase();
    });
    cancel.addEventListener('click', function () {
      tr.parentNode.removeChild(tr);
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    });
    confirm.addEventListener('click', function () {
      confirm.disabled = true;
      cancel.disabled = true;
      post('user_delete', { user_id: Number(user.id), confirm: input.value.trim() }).then(function (data) {
        var d = data.deleted || {};
        show('Konto ' + accountName(user) + ' gelöscht: ' + (d.sessions_deleted || 0) + ' Chats, '
          + (d.messages_deleted || 0) + ' Nachrichten, ' + (d.files_deleted || 0) + ' Dateien.');
        return Promise.all([loadUsers(), loadOverview()]);
      }).catch(function (error) {
        show('Löschen: ' + (DELETE_ERRORS[error.message] || error.message), true);
        confirm.disabled = input.value.trim().toLowerCase() !== accountName(user).toLowerCase();
        cancel.disabled = false;
      });
    });
    setTimeout(function () { input.focus(); }, 0);
    return tr;
  }

  // Row under an account: one switch per model outside CoreAI, then save.
  function modelPanel(user, toggle) {
    var tr = el('tr', 'user-panel-row model-access-row');
    var td = el('td');
    td.colSpan = 7;
    var models = (modelAccess && modelAccess.models) || [];
    var granted = grantsOf(user);
    td.appendChild(el('p', 'model-access-note', models.length
      ? 'Fremde Cores außerhalb von CoreAI für ' + (user.display_name || user.username || ('Konto ' + user.id))
        + '. Maras Cores brauchen keine Freigabe.'
      : 'Keine fremden Cores außerhalb von CoreAI in Ollama installiert.'));
    if (modelAccess && modelAccess.ollama === false) {
      td.appendChild(el('p', 'model-access-note warn', 'Ollama-Liste gerade nicht erreichbar; gezeigt werden nur bestehende Freigaben.'));
    }
    var list = el('div', 'model-access-list');
    models.forEach(function (name) {
      var label = el('label', 'model-access-choice');
      var box = document.createElement('input');
      box.type = 'checkbox';
      box.value = name;
      box.checked = granted.indexOf(name) >= 0;
      label.appendChild(box);
      label.appendChild(el('span', '', name));
      list.appendChild(label);
    });
    td.appendChild(list);
    var save = el('button', 'settings-action compact', 'FREIGABEN SPEICHERN');
    save.type = 'button';
    save.disabled = models.length === 0;
    save.addEventListener('click', function () {
      var chosen = Array.prototype.slice.call(list.querySelectorAll('input:checked')).map(function (box) { return box.value; });
      save.disabled = true;
      post('model_access_save', { user_id: Number(user.id), models: chosen }).then(function (data) {
        if (modelAccess) {
          if (!modelAccess.grants || Array.isArray(modelAccess.grants)) modelAccess.grants = {};
          modelAccess.grants[String(user.id)] = data.models || [];
        }
        toggle.textContent = 'FREIGABEN (' + (data.models || []).length + ')';
        show('Freigaben für Benutzer ' + user.id + ' gespeichert.');
      }).catch(function (error) {
        show('Freigaben: ' + error.message, true);
      }).finally(function () { save.disabled = false; });
    });
    td.appendChild(save);
    tr.appendChild(td);
    return tr;
  }

  function roleName(level, guest) {
    if (guest) return 'GAST';
    var hit = ROLE_OPTIONS.filter(function (role) { return role[0] === Number(level); })[0];
    return hit ? hit[1] : 'NUTZER';
  }

  function loadUsers() {
    var q = document.getElementById('userSearch').value.trim();
    return Promise.all([api('users', null, 'limit=150&q=' + encodeURIComponent(q)), loadModelAccess()]).then(function (both) {
      var data = both[0];
      var tbody = document.getElementById('userRows');
      tbody.innerHTML = '';
      (data.items || []).forEach(function (user) {
        var row = document.createElement('tr');
        row.appendChild(el('td', '', user.id));
        var account = el('td');
        account.appendChild(el('strong', '', user.display_name || user.username || 'ohne E-Mail'));
        account.appendChild(el('small', '', (user.username || '') + (user.character_name ? (' // ' + user.character_name) : '')));
        row.appendChild(account);

        var levelCell = el('td');
        var level = document.createElement('select');
        // 0.5.79: CoreAI roles instead of the old game ranks; a guest stays a guest.
        var roles = Number(user.is_guest) === 1 ? [[4, 'GAST']] : ROLE_OPTIONS;
        roles.forEach(function (role) {
          var option = el('option', '', role[1]);
          option.value = String(role[0]);
          if (Number(user.permission_level) === role[0] || roles.length === 1) option.selected = true;
          level.appendChild(option);
        });
        level.disabled = roles.length === 1;
        levelCell.appendChild(level);
        row.appendChild(levelCell);

        var banCell = el('td');
        var until = document.createElement('input');
        until.type = 'datetime-local';
        until.value = localDateTime(user.banned_until);
        until.title = user.banned_reason || '';
        banCell.appendChild(until);
        row.appendChild(banCell);
        row.appendChild(el('td', '', user.session_count || 0));
        row.appendChild(modelCell(user, row));
        var actionCell = el('td');
        var actions = actionCell.appendChild(el('div', 'user-actions'));
        var save = el('button', '', 'SPEICHERN');
        save.type = 'button';
        save.addEventListener('click', function () {
          var reason = '';
          if (until.value) reason = window.prompt('Interner Sperrgrund:', user.banned_reason || '') || '';
          post('user_update', {
            user_id: Number(user.id),
            permission_level: Number(level.value),
            banned_until: until.value,
            banned_reason: reason
          }).then(function () {
            show('Benutzer ' + user.id + ' gespeichert.');
            loadUsers();
          }).catch(function (error) { show('Benutzer: ' + error.message, true); });
        });
        actions.appendChild(save);
        if (canDelete(user)) {
          var remove = el('button', 'danger user-delete-toggle', 'LÖSCHEN');
          remove.type = 'button';
          remove.setAttribute('aria-expanded', 'false');
          remove.addEventListener('click', function () {
            var open = row.nextSibling && row.nextSibling.classList && row.nextSibling.classList.contains('user-delete-row');
            closePanel(row);
            if (open) return;
            row.parentNode.insertBefore(deletePanel(user, remove), row.nextSibling);
            remove.setAttribute('aria-expanded', 'true');
          });
          actions.appendChild(remove);
        }
        row.appendChild(actionCell);
        tbody.appendChild(row);
      });
    }).catch(function (error) { show('Benutzer: ' + error.message, true); });
  }

  function createUser(event) {
    event.preventDefault();
    var button = document.querySelector('#adminUserForm button[type="submit"]');
    if (button) button.disabled = true;
    post('user_create', {
      display_name: document.getElementById('newUserDisplayName').value,
      username: document.getElementById('newUserEmail').value,
      password: document.getElementById('newUserPassword').value,
      permission_level: Number(document.getElementById('newUserLevel').value) || 4
    }).then(function (data) {
      document.getElementById('adminUserForm').reset();
      show('Benutzer #' + data.user.id + ' wurde mit eigenem CoreAI: TCI Interface-Profil angelegt.');
      return Promise.all([loadUsers(), loadOverview()]);
    }).catch(function (error) {
      var labels = {
        username_taken: 'Diese Login-E-Mail ist bereits vergeben.',
        invalid_password: 'Das Startpasswort muss mindestens 12 Zeichen lang sein.',
        protected_account: 'Diese Rolle darf dein Administrationskonto nicht vergeben.'
      };
      show(labels[error.message] || ('Benutzeranlage: ' + error.message), true);
    }).finally(function () { if (button) button.disabled = false; });
  }

  function resetMemoryForm() {
    document.getElementById('adminMemoryId').value = '0';
    document.getElementById('adminMemoryFact').value = '';
    document.getElementById('adminMemoryScope').value = 'global';
    document.getElementById('adminMemoryRelevance').value = '5';
    document.getElementById('adminMemoryUser').value = '0';
    document.getElementById('adminMemoryCharacter').value = '';
  }

  function editMemory(item) {
    document.getElementById('adminMemoryId').value = String(item.id || 0);
    document.getElementById('adminMemoryFact').value = item.fact || '';
    document.getElementById('adminMemoryScope').value = item.scope || 'global';
    document.getElementById('adminMemoryRelevance').value = String(item.relevance || 5);
    document.getElementById('adminMemoryUser').value = String(item.user_id || 0);
    document.getElementById('adminMemoryCharacter').value = item.character_id || '';
    document.getElementById('adminMemoryFact').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function loadMemories() {
    var q = document.getElementById('memorySearch').value.trim();
    var scope = document.getElementById('memoryScopeFilter').value;
    return api('memories', null, 'limit=250&q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(scope)).then(function (data) {
      var list = document.getElementById('adminMemoryRows');
      list.innerHTML = '';
      (data.items || []).forEach(function (item) {
        var row = el('article', 'memory-admin-item');
        var body = el('div');
        body.appendChild(el('p', '', item.fact || ''));
        body.appendChild(el('small', '', '#' + item.id + ' // ' + String(item.scope || '').toUpperCase() + ' // REL.' + item.relevance + (item.username ? (' // ' + item.username) : '')));
        var actions = el('div', 'item-actions');
        var edit = el('button', '', 'EDIT');
        edit.addEventListener('click', function () { editMemory(item); });
        var remove = el('button', 'danger', 'LÖSCHEN');
        remove.addEventListener('click', function () {
          if (!window.confirm('Memory #' + item.id + ' wirklich löschen?')) return;
          post('memory_delete', { id: Number(item.id) }).then(function () {
            show('Memory gelöscht.');
            loadMemories();
          }).catch(function (error) { show('Memory: ' + error.message, true); });
        });
        actions.appendChild(edit);
        actions.appendChild(remove);
        row.appendChild(body);
        row.appendChild(actions);
        list.appendChild(row);
      });
      if (!(data.items || []).length) list.appendChild(el('div', 'admin-callout', 'KEINE MEMORY-EINTRÄGE GEFUNDEN'));
    }).catch(function (error) { show('Memory: ' + error.message, true); });
  }

  function saveMemory(event) {
    event.preventDefault();
    var body = {
      id: Number(document.getElementById('adminMemoryId').value) || 0,
      fact: document.getElementById('adminMemoryFact').value,
      scope: document.getElementById('adminMemoryScope').value,
      relevance: Number(document.getElementById('adminMemoryRelevance').value) || 5,
      user_id: Number(document.getElementById('adminMemoryUser').value) || 0,
      character_id: document.getElementById('adminMemoryCharacter').value.trim()
    };
    post('memory_upsert', body).then(function () {
      show('Memory gespeichert.');
      resetMemoryForm();
      loadMemories();
    }).catch(function (error) { show('Memory: ' + error.message, true); });
  }

  function renderLore(data) {
    var sourceBox = document.getElementById('loreSources');
    var select = document.getElementById('loreSourceFilter');
    sourceBox.innerHTML = '';
    var previous = select.value;
    select.innerHTML = '<option value="">ALLE QUELLEN</option>';
    (data.sources || []).forEach(function (source) {
      sourceBox.appendChild(el('span', 'lore-source', source.source + ' // ' + source.chunks + ' CHUNKS'));
      var option = el('option', '', source.source);
      option.value = source.source;
      select.appendChild(option);
    });
    select.value = previous;
    var results = document.getElementById('loreResults');
    results.innerHTML = '';
    (data.items || []).forEach(function (item) {
      var row = el('article', 'lore-item');
      var body = el('div');
      body.appendChild(el('p', '', item.chunk_text || ''));
      body.appendChild(el('small', '', item.source + ' // CHUNK ' + item.chunk_no + (item.title ? (' // ' + item.title) : '')));
      row.appendChild(body);
      results.appendChild(row);
    });
  }

  function loadLore(search) {
    var q = search ? document.getElementById('loreSearch').value.trim() : '';
    var source = search ? document.getElementById('loreSourceFilter').value : '';
    return api('lore', null, 'q=' + encodeURIComponent(q) + '&source=' + encodeURIComponent(source))
      .then(renderLore)
      .catch(function (error) { show('Lore: ' + error.message, true); });
  }

  function boolSetting(value) {
    return String(value) === '1';
  }

  // 0.5.78: can the server tell visitors apart? Behind a reverse proxy only if it
  // sends X-Forwarded-For; otherwise the per-network guest budget stays off.
  function showGuestGuard(client) {
    var note = document.getElementById('guestGuardNote');
    if (!note || !client) return;
    var title = document.getElementById('guestGuardTitle');
    var text = document.getElementById('guestGuardText');
    var known = client.source === 'direct' || client.source === 'forwarded';
    note.hidden = false;
    note.classList.toggle('amber', !known);
    title.textContent = known ? 'SCHUTZ VOR MEHRFACH-GÄSTEN AKTIV' : 'BESUCHER-ADRESSE NICHT ERKENNBAR';
    text.textContent = known
      ? 'Gäste aus demselben Netzwerk teilen sich ihr Kontingent für 24 Stunden; Browserdaten löschen bringt keine neuen Nachrichten. Ein Gerät kommt per Cookie in sein altes Gastkonto zurück.'
      : 'Der Reverse-Proxy meldet die Adresse der Besucher nicht, alle kommen scheinbar vom selben Rechner. Bis das behoben ist, wirken nur Gerätemerkmal, Tagesgrenze und Stundenlimit. Im Proxy ergänzen: proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
  }

  function fillRuntime(settings) {
    if (!settings) return;
    document.getElementById('registrationEnabled').checked = boolSetting(settings.registration_enabled);
    document.getElementById('guestEnabled').checked = boolSetting(settings.guest_enabled);
    document.getElementById('guestMessageLimit').value = settings.guest_message_limit || '5';
    document.getElementById('guestNetworkGuests').value = settings.guest_network_guests || '1';
    document.getElementById('guestDailyMessages').value = settings.guest_daily_messages != null ? settings.guest_daily_messages : '200';
    document.getElementById('captchaEnabled').checked = settings.captcha_enabled == null ? true : boolSetting(settings.captcha_enabled);
    document.getElementById('maintenanceEnabled').checked = boolSetting(settings.maintenance_enabled);
    document.getElementById('memoryDefaultEnabled').checked = boolSetting(settings.memory_default_enabled);
    document.getElementById('promptLimit').value = settings.user_system_prompt_max_chars || '6000';
    document.getElementById('maintenanceMessage').value = settings.maintenance_message || '';
    document.getElementById('toolWebEnabled').checked = boolSetting(settings.tool_web_enabled);
    document.getElementById('toolBrowseEnabled').checked = boolSetting(settings.tool_browse_enabled);
    document.getElementById('toolPythonEnabled').checked = boolSetting(settings.tool_python_enabled);
    document.getElementById('externalEnabled').checked = boolSetting(settings.external_api_enabled);
    document.getElementById('externalLabel').value = settings.external_api_label || 'OpenAI-kompatibel';
    document.getElementById('externalBaseUrl').value = settings.external_api_base_url || '';
    document.getElementById('externalModel').value = settings.external_api_model || '';
  }

  function saveRuntime() {
    var settings = {
      registration_enabled: document.getElementById('registrationEnabled').checked ? '1' : '0',
      guest_enabled: document.getElementById('guestEnabled').checked ? '1' : '0',
      guest_message_limit: document.getElementById('guestMessageLimit').value,
      guest_network_guests: document.getElementById('guestNetworkGuests').value,
      guest_daily_messages: document.getElementById('guestDailyMessages').value,
      captcha_enabled: document.getElementById('captchaEnabled').checked ? '1' : '0',
      maintenance_enabled: document.getElementById('maintenanceEnabled').checked ? '1' : '0',
      memory_default_enabled: document.getElementById('memoryDefaultEnabled').checked ? '1' : '0',
      user_system_prompt_max_chars: document.getElementById('promptLimit').value,
      maintenance_message: document.getElementById('maintenanceMessage').value,
      tool_web_enabled: document.getElementById('toolWebEnabled').checked ? '1' : '0',
      tool_browse_enabled: document.getElementById('toolBrowseEnabled').checked ? '1' : '0',
      tool_python_enabled: document.getElementById('toolPythonEnabled').checked ? '1' : '0',
      external_api_enabled: document.getElementById('externalEnabled').checked ? '1' : '0',
      external_api_label: document.getElementById('externalLabel').value,
      external_api_base_url: document.getElementById('externalBaseUrl').value,
      external_api_model: document.getElementById('externalModel').value
    };
    post('settings_save', { settings: settings }).then(function (data) {
      runtimeState = data.settings || settings;
      fillRuntime(runtimeState);
      show('Runtime-Einstellungen gespeichert.');
    }).catch(function (error) { show('Runtime: ' + error.message, true); });
  }

  function loadLogs() {
    return api('logs', null, 'limit=1000').then(function (data) {
      var out = document.getElementById('logOutput');
      out.textContent = (data.lines || []).join('\n') || 'KEINE PROTOKOLLZEILEN';
      out.scrollTop = out.scrollHeight;
    }).catch(function (error) { show('Protokoll: ' + error.message, true); });
  }

  function clearLogs() {
    if (!window.confirm('Das CoreAI: TCI Interface-Fehlerprotokoll wirklich leeren?')) return;
    post('logs_clear', {}).then(function (data) {
      show('Protokoll geleert: ' + data.removed + ' sichtbare Zeilen.');
      loadLogs();
    }).catch(function (error) { show('Protokoll: ' + error.message, true); });
  }

  function loadAudit() {
    return api('audit', null, 'limit=200').then(function (data) {
      var list = document.getElementById('auditRows');
      list.innerHTML = '';
      (data.items || []).forEach(function (item) {
        var row = el('article', 'audit-item');
        var body = el('div');
        body.appendChild(el('p', '', item.action_name + (item.target_type ? (' // ' + item.target_type + ':' + item.target_id) : '')));
        body.appendChild(el('small', '', (item.actor || ('UID ' + item.actor_user_id)) + ' // ' + item.created_at));
        row.appendChild(body);
        list.appendChild(row);
      });
    }).catch(function (error) { show('Audit: ' + error.message, true); });
  }

  function bind() {
    document.getElementById('refreshOverview').addEventListener('click', loadOverview);
    document.getElementById('reloadUsers').addEventListener('click', loadUsers);
    document.getElementById('searchUsers').addEventListener('click', loadUsers);
    document.getElementById('adminUserForm').addEventListener('submit', createUser);
    document.getElementById('reloadAdminMemories').addEventListener('click', loadMemories);
    document.getElementById('searchAdminMemories').addEventListener('click', loadMemories);
    document.getElementById('adminMemoryForm').addEventListener('submit', saveMemory);
    document.getElementById('resetAdminMemory').addEventListener('click', resetMemoryForm);
    document.getElementById('reloadLore').addEventListener('click', function () { loadLore(false); });
    document.getElementById('searchLore').addEventListener('click', function () { loadLore(true); });
    document.getElementById('saveRuntime').addEventListener('click', saveRuntime);
    document.getElementById('saveRuntimeAccess').addEventListener('click', saveRuntime);
    document.getElementById('reloadLogs').addEventListener('click', loadLogs);
    document.getElementById('clearLogs').addEventListener('click', clearLogs);
    document.getElementById('reloadAudit').addEventListener('click', loadAudit);
  }

  if (!navGroup) return;
  document.addEventListener('coreai:view', onView);
  fetch('./api/auth.php?action=me', { credentials: 'include', cache: 'no-store' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      // Users and guests never see Admin Core; settings.js handles the login.
      if (!data.ok || data.is_guest || Number(data.permission_level) > 1) return null;
      csrfToken = data.csrf_token || '';
      actorLevel = Number(data.permission_level);
      actorId = Number(data.user_id) || 0;
      var adminOption = document.querySelector('#newUserLevel option[value="1"]');
      if (adminOption && actorLevel > 0) adminOption.disabled = true;
      if (identity) identity.textContent = (data.username || 'ADMIN') + ' // ' + roleName(data.permission_level, false);
      bind();
      ready = true;
      // Showing the group opens an admin tab the address asked for; that tab loads its data itself.
      return loadOverview().then(showAdmin);
    })
    .catch(function (error) { show('Admin-Initialisierung: ' + error.message, true); });
})();
