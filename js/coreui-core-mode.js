/* CoreAI 0.5.75: Core chip under the composer. The menu lists the Cores
   installed in Ollama (like the KI-Profil) and holds the thinking switch.
   Both are stored per account and apply from the next message on; a running
   turn keeps what it started with. A chosen local Core is loaded right away
   by the server, the chip shows LÄDT until Ollama holds it in memory. */
(function () {
  'use strict';

  var API = './api/user_settings.php';
  var MODELS = './api/models.php';
  var POLL_MS = 2000;
  var POLL_LIMIT = 45; // about 90 s

  function shortCore(name) {
    var s = String(name || '').trim().replace(/:latest$/, '');
    return s || 'Core';
  }
  function modelKey(name) {
    var s = String(name || '').trim().toLowerCase();
    return s && s.indexOf(':') < 0 ? s + ':latest' : s;
  }
  function isCloud(name) { return /(?:^|[:._-])cloud(?:$|[:._-])/i.test(String(name || '')); }

  function create(doc, fetchFn, schedule) {
    var chip = doc.getElementById('coreModeChip');
    var menu = doc.getElementById('coreModeMenu');
    if (!chip || !menu || typeof fetchFn !== 'function') return null;
    schedule = schedule || function (fn, ms) { return window.setTimeout(fn, ms); };
    var nameEl = doc.getElementById('coreModeName');
    var thinkEl = doc.getElementById('coreModeThink');
    var list = doc.getElementById('coreModeList');
    var toggle = doc.getElementById('coreThinkToggle');
    var status = doc.getElementById('coreModeStatus');
    var state = {thinking: true, override: '', defaultModel: '', csrf: '', loaded: false, saving: false,
      models: null, inMemory: [], loading: '', polls: 0, listError: false};

    function effective() { return state.override || state.defaultModel; }
    function say(text) { if (status) status.textContent = text || ''; }

    function render() {
      var core = shortCore(effective());
      chip.setAttribute('data-thinking', state.thinking ? 'on' : 'off');
      chip.setAttribute('data-state', state.loading ? 'loading' : 'ready');
      chip.setAttribute('aria-label', 'Core ' + core + (state.loading ? ', wird geladen' : '')
        + ', Denken ' + (state.thinking ? 'an' : 'aus') + '. Core und Denken einstellen');
      if (nameEl) nameEl.textContent = core;
      if (thinkEl) thinkEl.textContent = state.loading ? 'LÄDT' : (state.thinking ? 'DENKEN' : 'SCHNELL');
      if (toggle) {
        toggle.checked = state.thinking;
        toggle.disabled = !state.loaded || state.saving;
      }
    }

    function option(value, label, meta, disabled) {
      var button = doc.createElement('button');
      var checked = value === state.override;
      button.type = 'button';
      button.className = 'core-mode-option';
      button.setAttribute('role', 'radio');
      button.setAttribute('aria-checked', checked ? 'true' : 'false');
      button.setAttribute('data-value', value);
      button.disabled = !!disabled || state.saving;
      var name = doc.createElement('span');
      name.className = 'core-mode-option-name';
      name.textContent = label;
      var small = doc.createElement('small');
      small.textContent = meta;
      var mark = doc.createElement('span');
      mark.className = 'core-mode-check';
      mark.setAttribute('aria-hidden', 'true');
      mark.textContent = checked ? '✓' : '';
      button.appendChild(name);
      button.appendChild(small);
      button.appendChild(mark);
      button.addEventListener('click', function (event) {
        // The list is rebuilt on choice; the click must not count as outside the menu.
        if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
        return choose(value);
      });
      return button;
    }

    function metaFor(name, size) {
      var parts = [];
      if (size) parts.push(size);
      if (isCloud(name)) parts.push('Cloud');
      else if (state.inMemory.indexOf(modelKey(name)) >= 0) parts.push('geladen');
      if (state.loading && state.loading === modelKey(name)) parts.push('lädt …');
      return parts.join(' · ');
    }

    function renderList() {
      if (!list) return;
      while (list.firstChild) list.removeChild(list.firstChild);
      if (state.models === null) {
        var wait = doc.createElement('p');
        wait.className = 'core-mode-empty';
        wait.textContent = state.listError ? 'Ollama-Liste gerade nicht erreichbar.' : 'Ollama-Liste wird geladen …';
        list.appendChild(wait);
        if (!state.listError) return;
      }
      list.appendChild(option('', 'Serverstandard', metaFor(state.defaultModel, shortCore(state.defaultModel)), false));
      var found = state.override === '';
      (state.models || []).forEach(function (model) {
        var name = String(model && model.name || '');
        if (!name) return;
        if (name === state.override) found = true;
        list.appendChild(option(name, name, metaFor(name, model.parameter_size || ''), false));
      });
      if (!found && state.models !== null) list.appendChild(option(state.override, state.override, 'nicht mehr installiert', true));
      else if (!found) list.appendChild(option(state.override, state.override, metaFor(state.override, ''), false));
    }

    function request(url, method, body) {
      var init = {method: method, credentials: 'include', cache: 'no-store', headers: {'Accept': 'application/json'}};
      if (body) {
        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CoreUI-CSRF'] = state.csrf;
        init.body = JSON.stringify(body);
      }
      return fetchFn(url, init).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data || !data.ok) {
            var error = new Error((data && data.error) || ('HTTP_' + response.status));
            error.code = (data && data.error) || '';
            throw error;
          }
          return data;
        });
      });
    }

    function load() {
      return request(API, 'GET').then(function (data) {
        var s = data.settings || {};
        state.thinking = s.thinking_enabled !== false;
        state.override = String(s.model_override || '');
        state.defaultModel = String(data.default_model || '');
        state.csrf = String(data.csrf_token || '');
        state.loaded = true;
        say('');
        render();
      }).catch(function () {
        say('Einstellungen gerade nicht erreichbar.');
        render();
      });
    }

    function loadList() {
      state.listError = false;
      return Promise.all([request(MODELS, 'GET'), request(API + '?core_status=1', 'GET').catch(function () { return {loaded: []}; })])
        .then(function (both) {
          state.models = Array.isArray(both[0].models) ? both[0].models : [];
          if (both[0].default_model) state.defaultModel = String(both[0].default_model);
          state.inMemory = Array.isArray(both[1].loaded) ? both[1].loaded.map(modelKey) : [];
        })
        .catch(function () { state.models = null; state.listError = true; })
        .then(function () { renderList(); render(); });
    }

    function poll() {
      if (!state.loading) return Promise.resolve();
      return request(API + '?core_status=1', 'GET').then(function (data) {
        state.inMemory = Array.isArray(data.loaded) ? data.loaded.map(modelKey) : [];
      }).catch(function () {}).then(function () {
        if (state.inMemory.indexOf(state.loading) >= 0) {
          state.loading = '';
          say('Core geladen und bereit.');
        } else if (++state.polls >= POLL_LIMIT) {
          state.loading = '';
          say('Das Laden dauert länger. Spätestens mit der nächsten Nachricht ist der Core da.');
        } else {
          schedule(poll, POLL_MS);
        }
        renderList();
        render();
      });
    }

    function choose(value) {
      value = String(value || '');
      if (!state.loaded || state.saving || value === state.override) return Promise.resolve(state.override);
      state.saving = true;
      say('Core wird gewählt …');
      renderList();
      render();
      return request(API, 'POST', {action: 'core', model_override: value}).then(function (data) {
        state.override = String(data.model_override || '');
        if (data.preload === 'started') {
          state.loading = modelKey(data.model);
          state.polls = 0;
          say('Core wird geladen …');
          schedule(poll, POLL_MS);
        } else {
          state.loading = '';
          say(data.preload === 'cloud' ? 'Cloud-Core gewählt, er muss nicht geladen werden.' : 'Core ist bereits geladen.');
        }
      }).catch(function (error) {
        say(error.code === 'model_not_available' ? 'Dieser Core ist nicht mehr installiert.'
          : 'Nicht gespeichert. Bitte noch einmal versuchen.');
      }).then(function () {
        state.saving = false;
        renderList();
        render();
        return state.override;
      });
    }

    function setThinking(on) {
      on = on === true;
      if (!state.loaded || state.saving || on === state.thinking) {
        render();
        return Promise.resolve(state.thinking);
      }
      var before = state.thinking;
      state.thinking = on;
      state.saving = true;
      say('');
      render();
      return request(API, 'POST', {action: 'thinking', thinking_enabled: on}).then(function (data) {
        state.thinking = data.thinking_enabled !== false;
        say(state.thinking ? 'Denken an: gründlicher, aber langsamer.' : 'Denken aus: Mara antwortet direkt.');
      }).catch(function () {
        state.thinking = before;
        say('Nicht gespeichert. Bitte noch einmal versuchen.');
      }).then(function () {
        state.saving = false;
        render();
        return state.thinking;
      });
    }

    function isOpen() { return !menu.hidden; }
    function open() {
      menu.hidden = false;
      chip.setAttribute('aria-expanded', 'true');
      renderList();
      return loadList();
    }
    function close(focusChip) {
      if (menu.hidden) return;
      menu.hidden = true;
      chip.setAttribute('aria-expanded', 'false');
      if (focusChip && typeof chip.focus === 'function') chip.focus();
    }
    function inside(node, target) {
      return !!target && (node === target || (typeof node.contains === 'function' && node.contains(target)));
    }

    chip.addEventListener('click', function (event) {
      if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
      if (isOpen()) { close(false); return Promise.resolve(); }
      return open();
    });
    if (toggle) toggle.addEventListener('change', function () { return setThinking(toggle.checked); });
    doc.addEventListener('click', function (event) {
      var path = event && typeof event.composedPath === 'function' ? event.composedPath() : [];
      if (path.indexOf(menu) >= 0 || path.indexOf(chip) >= 0) return;
      if (isOpen() && !inside(menu, event.target) && !inside(chip, event.target)) close(false);
    });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) close(true);
    });

    render();
    return {
      ready: load(),
      setThinking: setThinking,
      choose: choose,
      poll: poll,
      open: open,
      close: close,
      isOpen: isOpen,
      state: function () {
        return {thinking: state.thinking, override: state.override, core: effective(), loaded: state.loaded, loading: state.loading};
      }
    };
  }

  window.CoreUICoreMode = {create: create, shortCore: shortCore, modelKey: modelKey};
  if (window.document && typeof window.fetch === 'function') {
    var start = function () { window.CoreUICoreMode.instance = create(window.document, window.fetch.bind(window)); };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
