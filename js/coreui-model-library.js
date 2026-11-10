/* CoreAI 0.6.89: optional local Ollama registry browser, isolated from TCI tier management.
   Real downloads reuse the existing CoreAI install worker. No auto installs or API keys. */
(function () {
  'use strict';
  function create(doc, fetchFn, schedule) {
    var root = doc.getElementById('coreLibrary');
    var openButton = doc.getElementById('coreInstallLibraryOpen');
    if (!root || !openButton || typeof fetchFn !== 'function') return null;
    schedule = schedule || function (fn, delay) { return window.setTimeout(fn, delay); };
    var ids = ['Close', 'SearchForm', 'Query', 'Status', 'Results', 'Detail', 'Back',
      'ModelTitle', 'ModelDescription', 'ModelLink', 'Tag', 'Hint', 'Install', 'Progress',
      'ProgressText', 'ProgressBar', 'ProgressFill', 'Browser', 'Installed',
      'InstalledStatus', 'InstalledList', 'OllamaProvider', 'InstalledProvider'];
    var el = {};
    ids.forEach(function (id) { el[id] = doc.getElementById('coreLibrary' + id); });
    var state = {open: false, tab: 'ollama', loading: false, detail: null, csrf: '', request: 0,
      confirming: '', installing: '', polling: false, finished: false, installedRequest: 0,
      installedCores: [], pendingRemoval: '', removing: ''};
    function show(node, visible) { if (node) node.hidden = !visible; }
    function text(node, value) { if (node) node.textContent = value || ''; }
    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
    function item(parent, tag, cls, label) {
      var node = doc.createElement(tag);
      if (cls) node.className = cls;
      if (label !== undefined) node.textContent = String(label);
      parent.appendChild(node);
      return node;
    }
    function request(query, postBody) {
      var init = {method: postBody ? 'POST' : 'GET', credentials: 'include',
        cache: 'no-store', headers: {'Accept': 'application/json'}};
      if (postBody) {
        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CoreUI-CSRF'] = state.csrf;
        init.body = JSON.stringify(postBody);
      }
      return fetchFn('./api/user_settings.php' + (query ? '?' + query : ''), init).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data.ok) {
            var error = new Error(data.error || ('HTTP ' + response.status));
            error.code = data.error || '';
            throw error;
          }
          if (data.csrf_token) state.csrf = String(data.csrf_token);
          return data;
        });
      });
    }
    function fail(message) {
      text(el.Status, message);
      if (el.Status) el.Status.setAttribute('data-state', 'warn');
    }
    function sourceStatus(message) {
      text(el.Status, message);
      if (el.Status) el.Status.setAttribute('data-state', 'ok');
    }
    // The installed tab only reads Ollama's local inventory. No registry
    // connection, Hugging Face account or external search is required.
    function installedInfo(core) {
      var size = Number(core.size_bytes) || 0;
      var amount = size > 0 ? ' · ' + (size / 1073741824).toFixed(1).replace('.', ',') + ' GB' : '';
      var params = core.parameter_size ? ' · ' + core.parameter_size : '';
      var type = core.kind === 'tci' ? 'TCI Core'
        : core.kind === 'cloud' ? 'Cloud-Verknüpfung' : 'Lokales LLM';
      return type + params + amount;
    }
    function renderInstalled() {
      clear(el.InstalledList);
      if (!state.installedCores.length) {
        item(el.InstalledList, 'p', 'core-library-empty', 'Keine installierten Cores gefunden.');
        return;
      }
      state.installedCores.forEach(function (core) {
        var card = item(el.InstalledList, 'article', 'core-library-installed-item');
        var info = item(card, 'div', 'core-library-installed-copy');
        item(info, 'strong', '', core.label || core.name);
        if (core.label && core.label !== core.name) item(info, 'small', '', core.name);
        item(info, 'small', '', installedInfo(core));
        if (!core.removable) {
          item(card, 'span', 'core-library-protected', core.protected_reason || 'Geschützter Core');
          return;
        }
        var confirmation = state.pendingRemoval === core.name;
        var button = item(card, 'button', 'core-install-btn core-library-remove',
          state.removing === core.name ? 'Wird entfernt …'
            : confirmation ? 'Wirklich entfernen?' : 'Deinstallieren');
        button.type = 'button';
        button.disabled = !!state.removing || !!state.installing;
        button.addEventListener('click', function () { return removeInstalled(core.name); });
        if (confirmation) item(card, 'small', 'core-library-remove-note',
          'Entfernt nur diesen Core. Betroffene Konten wechseln zum Standard-Core.');
      });
    }
    function loadInstalled() {
      var serial = ++state.installedRequest;
      text(el.InstalledStatus, 'Liest die installierten Cores von Ollama …');
      return request('core_installed=1').then(function (data) {
        if (!state.open || state.tab !== 'installed' || serial !== state.installedRequest) return;
        state.installedCores = Array.isArray(data.cores) ? data.cores : [];
        renderInstalled();
        text(el.InstalledStatus, state.installedCores.length + ' installierte Cores im lokalen Ollama-Dienst.');
      }).catch(function () {
        if (!state.open || state.tab !== 'installed' || serial !== state.installedRequest) return;
        text(el.InstalledStatus, 'Installierte Cores sind gerade nicht erreichbar. Bitte erneut öffnen.');
      });
    }
    function updateCoreLists() {
      var chat = window.CoreUICoreMode && window.CoreUICoreMode.instance;
      if (chat && typeof chat.refresh === 'function') chat.refresh();
      var manager = window.CoreUICoreInstall && window.CoreUICoreInstall.instance;
      if (manager && typeof manager.refreshManage === 'function') manager.refreshManage();
    }
    function removeInstalled(name) {
      if (state.removing || state.installing || !state.csrf || !state.open || state.tab !== 'installed') return Promise.resolve();
      var row = state.installedCores.filter(function (core) { return core.name === name; })[0];
      if (!row || !row.removable) return Promise.resolve();
      if (state.pendingRemoval !== name) {
        state.pendingRemoval = name;
        renderInstalled();
        return Promise.resolve();
      }
      state.pendingRemoval = '';
      state.removing = name;
      renderInstalled();
      text(el.InstalledStatus, 'Entfernt ' + name + ' aus Ollama …');
      return request('', {action: 'installed_core_uninstall', model: name}).then(function (data) {
        var removed = Array.isArray(data.removed) ? data.removed : [];
        if (!removed.length) throw new Error('Kein Core wurde entfernt.');
        updateCoreLists();
        return loadInstalled().then(function () {
          text(el.InstalledStatus, removed.join(', ') + ' entfernt. Gespeicherte Auswahlen wurden zurückgesetzt.');
        });
      }).catch(function (error) {
        var notes = {core_protected: 'Dieser Core ist geschützt und kann nicht gelöscht werden.',
          install_running: 'Ein Installer oder Core-Update läuft noch. Bitte danach erneut versuchen.',
          model_not_installed: 'Der Core ist bereits nicht mehr installiert.',
          uninstall_partial: 'Ollama konnte den Core nicht vollständig entfernen.'};
        text(el.InstalledStatus, notes[error.code] || 'Das Deinstallieren ist fehlgeschlagen. Bitte erneut versuchen.');
      }).then(function () {
        state.removing = '';
        if (state.open && state.tab === 'installed') renderInstalled();
      });
    }
    function switchTab(tab) {
      if (!state.open || (tab !== 'ollama' && tab !== 'installed')) return Promise.resolve();
      state.tab = tab;
      state.request++;
      state.installedRequest++;
      state.pendingRemoval = '';
      show(el.Browser, tab === 'ollama');
      show(el.Installed, tab === 'installed');
      show(el.Detail, false);
      if (el.OllamaProvider) {
        el.OllamaProvider.className = 'core-library-provider' + (tab === 'ollama' ? ' core-library-provider--active' : '');
        el.OllamaProvider.setAttribute('aria-selected', tab === 'ollama' ? 'true' : 'false');
      }
      if (el.InstalledProvider) {
        el.InstalledProvider.className = 'core-library-provider' + (tab === 'installed' ? ' core-library-provider--active' : '');
        el.InstalledProvider.setAttribute('aria-selected', tab === 'installed' ? 'true' : 'false');
      }
      return tab === 'installed' ? loadInstalled() : search();
    }
    function installedVariant() {
      var tags = state.detail && state.detail.tags || [];
      return tags.filter(function (tag) { return tag.name === el.Tag.value; })[0] || null;
    }
    function renderVariant() {
      var tag = installedVariant();
      state.confirming = '';
      if (!tag) {
        text(el.Hint, 'Für dieses LLM sind derzeit keine lokalen Downloadvarianten erkennbar.');
        el.Install.disabled = true;
        text(el.Install, 'Nicht verfügbar');
        return;
      }
      text(el.Hint, tag.installed ? 'Bereits lokal installiert.' : tag.size
        + ' Downloadgröße laut Ollama. Stelle vor dem Herunterladen genügend freien Speicher bereit.');
      el.Install.disabled = !!tag.installed || !!state.installing;
      text(el.Install, tag.installed ? 'Installiert' : state.installing ? 'Installation läuft' : 'Installieren');
    }
    function renderDetail(detail) {
      state.detail = detail;
      state.confirming = '';
      show(el.Detail, true);
      text(el.ModelTitle, detail.name);
      text(el.ModelDescription, detail.description || 'Details aus der offiziellen Ollama-Bibliothek.');
      el.ModelLink.href = detail.url;
      clear(el.Tag);
      (detail.tags || []).forEach(function (tag) {
        var opt = item(el.Tag, 'option', '', tag.name + ' · ' + tag.size + (tag.installed ? ' · installiert' : ''));
        opt.value = tag.name;
      });
      el.Tag.disabled = !(detail.tags || []).length;
      renderVariant();
    }
    function loadDetail(name) {
      var sequence = ++state.request;
      fail('Lädt die Ollama-Details …');
      return request('ollama_detail=' + encodeURIComponent(name)).then(function (data) {
        if (!state.open || sequence !== state.request) return;
        renderDetail(data.detail || {name: name, tags: []});
        sourceStatus('Lokale Varianten und Downloadgrößen werden von Ollama angezeigt.');
      }).catch(function () {
        if (!state.open || sequence !== state.request) return;
        fail('Ollama-Details konnten nicht geladen werden. Bitte die offizielle Ollama-Seite verwenden.');
      });
    }
    function renderSearch(models) {
      clear(el.Results);
      if (!models.length) {
        item(el.Results, 'p', 'core-library-empty', 'Keine lokal installierbaren LLMs gefunden. Du kannst auch einen exakten Namen eingeben.');
        return;
      }
      models.forEach(function (model) {
        var card = item(el.Results, 'button', 'core-library-result');
        card.type = 'button';
        card.setAttribute('role', 'listitem');
        item(card, 'strong', '', model.name);
        item(card, 'small', '', model.summary || '');
        card.addEventListener('click', function () { loadDetail(model.name); });
      });
    }
    function search() {
      var query = (el.Query.value || '').trim();
      var sequence = ++state.request;
      state.loading = true;
      show(el.Detail, false);
      fail('Sucht in Ollamas offizieller Bibliothek …');
      return request('ollama_search=' + encodeURIComponent(query)).then(function (data) {
        if (!state.open || sequence !== state.request) return;
        var models = Array.isArray(data.models) ? data.models : [];
        renderSearch(models);
        sourceStatus(models.length + ' Einträge aus Ollamas offizieller Suche. Es werden nur lokale Varianten angeboten.');
      }).catch(function () {
        if (!state.open || sequence !== state.request) return;
        clear(el.Results);
        fail('Ollamas Suchseite konnte nicht eingelesen werden. Öffne die offizielle Suche oder gib den exakten LLM-Namen ein.');
        // If parsing the public search page fails, individual detail pages
        // can still be loaded safely from a typed official model ID.
        if (/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)?$/.test(query)) {
          var direct = item(el.Results, 'button', 'core-library-result', 'Details zu ' + query + ' direkt öffnen');
          direct.type = 'button';
          direct.addEventListener('click', function () { loadDetail(query); });
        }
      }).then(function () { state.loading = false; });
    }
    function renderProgress(job) {
      show(el.Progress, true);
      var value = Math.max(0, Math.min(100, Number(job.percent) || 0));
      var suffix = job.total ? ' · ' + (Number(job.completed || 0) / 1073741824).toFixed(1).replace('.', ',')
        + ' von ' + (Number(job.total) / 1073741824).toFixed(1).replace('.', ',') + ' GB' : '';
      text(el.ProgressText, (job.step || 'Lädt herunter …') + suffix
        + (job.phase === 'error' ? ' ' + (job.message || 'Download fehlgeschlagen.') : ''));
      el.ProgressBar.setAttribute('aria-valuenow', String(value));
      el.ProgressFill.style.width = value + '%';
      if (job.phase === 'error') fail(job.message || 'Der Download ist fehlgeschlagen.');
    }
    function poll() {
      if (!state.open || !state.polling) return Promise.resolve();
      return request('core_install_status=1').then(function (data) {
        var job = data.install || null;
        if (!job || job.kind !== 'library' || job.core !== state.installing) {
          state.polling = false;
          fail('Der Downloadstatus ist nicht mehr verfügbar. Bitte die lokale LLM-Liste prüfen.');
          return;
        }
        renderProgress(job);
        if (job.running && job.phase !== 'error') {
          schedule(poll, 1700);
          return;
        }
        state.polling = false;
        state.installing = '';
        if (job.phase === 'done') {
          state.finished = true;
          sourceStatus('Das LLM ' + job.core + ' ist jetzt lokal installiert.');
          if (window.CoreUICoreMode && window.CoreUICoreMode.instance
              && typeof window.CoreUICoreMode.instance.refresh === 'function') {
            window.CoreUICoreMode.instance.refresh();
          }
          if (state.detail) return loadDetail(state.detail.name);
        } else {
          fail(job.message || 'Installation abgebrochen.');
        }
        renderVariant();
      }).catch(function () {
        if (!state.open || !state.polling) return;
        schedule(poll, 2800);
      });
    }
    function install() {
      var tag = installedVariant();
      if (!tag || tag.installed || state.installing || !state.csrf) return;
      if (state.confirming !== tag.name) {
        state.confirming = tag.name;
        text(el.Install, 'Wirklich installieren?');
        text(el.Hint, tag.name + ' benötigt laut Ollama etwa ' + tag.size
          + ' für den Download. Der Server lädt das LLM lokal herunter.');
        return;
      }
      state.confirming = '';
      state.installing = tag.name;
      el.Install.disabled = true;
      text(el.Install, 'Startet …');
      fail('Startet die lokale Installation von ' + tag.name + ' …');
      request('', {action: 'ollama_library_install', model: tag.name}).then(function (data) {
        state.polling = true;
        show(el.Progress, true);
        renderProgress(data.install || {step: 'Installiert …', percent: 0});
        schedule(poll, 800);
      }).catch(function (error) {
        state.installing = '';
        el.Install.disabled = false;
        renderVariant();
        var messages = {already_installed: 'Dieses LLM ist bereits installiert.',
          install_running: 'Eine andere Installation oder ein Core-Update läuft bereits.',
          not_local_model: 'Ollama bestätigt keine lokal installierbare Variante.',
          registry_unavailable: 'Die offizielle Ollama-Seite ist derzeit nicht erreichbar.'};
        fail(messages[error.code] || 'Die Installation konnte nicht gestartet werden (' + error.message + ').');
      });
    }
    function open() {
      if (state.open) return;
      state.open = true;
      state.finished = false;
      root.hidden = false;
      el.Query.value = '';
      show(el.Detail, false);
      show(el.Progress, false);
      if (el.Close && typeof el.Close.focus === 'function') el.Close.focus();
      switchTab('ollama');
      request('core_install_status=1').then(function (data) {
        var job = data.install;
        if (state.open && job && job.kind === 'library' && job.running) {
          state.installing = job.core;
          state.polling = true;
          renderProgress(job);
          schedule(poll, 1200);
        }
      }).catch(function () {});
    }
    function close() {
      state.open = false;
      state.request++;
      state.installedRequest++;
      state.polling = false;
      state.pendingRemoval = '';
      state.confirming = '';
      root.hidden = true;
      if (typeof openButton.focus === 'function') openButton.focus();
    }
    openButton.addEventListener('click', open);
    el.OllamaProvider.addEventListener('click', function () { return switchTab('ollama'); });
    el.InstalledProvider.addEventListener('click', function () { return switchTab('installed'); });
    el.Close.addEventListener('click', close);
    el.Back.addEventListener('click', function () { show(el.Detail, false); state.request++; });
    el.Tag.addEventListener('change', renderVariant);
    el.Install.addEventListener('click', install);
    el.SearchForm.addEventListener('submit', function (event) {
      if (event && typeof event.preventDefault === 'function') event.preventDefault();
      search();
    });
    root.addEventListener('click', function (event) { if (event.target === root) close(); });
    doc.addEventListener('keydown', function (event) {
      if (!state.open || event.key !== 'Escape') return;
      if (el.Detail && !el.Detail.hidden) { show(el.Detail, false); state.request++; }
      else close();
    });
    return {open: open, close: close, search: search, loadDetail: loadDetail,
      switchTab: switchTab, loadInstalled: loadInstalled,
      state: function () { return {open: state.open, tab: state.tab, installing: state.installing,
        removing: state.removing, selected: state.detail && state.detail.name}; }};
  }
  window.CoreUIModelLibrary = {create: create};
  if (window.document && typeof window.fetch === 'function') {
    var start = function () {
      window.CoreUIModelLibrary.instance = create(window.document, window.fetch.bind(window));
    };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
