/* CoreAI 0.5.75: Core chip under the composer. The menu lists the Cores
   installed in Ollama (like the KI-Profil) and holds the thinking switch.
   Both are stored per account and apply from the next message on; a running
   turn keeps what it started with. A chosen local Core is loaded right away
   by the server, the chip shows LÄDT until Ollama holds it in memory.
   0.5.76: Gemma tiers carry TCI names (Schnell, Normal, Pro, Premium; the
   server decides, see coreui_core_label). The technical names live in the
   legend next to the chip, together with formatting and commands. */
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
  function sameCore(a, b) { return !!modelKey(a) && modelKey(a) === modelKey(b); }
  function shortLabel(label) { return String(label || '').replace(/^TCI:\s*/, '') || 'Core'; }

  // Mirror of coreui_core_tier() for names the server has not labelled yet.
  var TIERS = [['e2b', 'TCI: Mara Schnell', 'Gemma 4 E2B'], ['e4b', 'TCI: Mara Normal', 'Gemma 4 E4B'],
    ['26b', 'TCI: Mara Pro', 'Gemma 4 26B'], ['31b', 'TCI: Mara Premium', 'Gemma 4 31B']];
  function tierLabel(name) {
    var m = String(name || '').trim().toLowerCase();
    if (!/gemma-?4/.test(m)) return '';
    for (var i = 0; i < TIERS.length; i++) {
      if (new RegExp('(^|[^a-z0-9])' + TIERS[i][0] + '(?![a-z0-9])').test(m)) return TIERS[i][1];
    }
    return '';
  }

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
    var legendBtn = doc.getElementById('coreLegendBtn');
    var legend = doc.getElementById('coreLegend');
    var legendRows = doc.getElementById('coreLegendRows');
    var state = {thinking: true, override: '', defaultModel: '', defaultLabel: '', labels: {}, csrf: '',
      loaded: false, saving: false, models: null, inMemory: [], loading: '', polls: 0, listError: false};

    function effective() { return state.override || state.defaultModel; }
    function labelOf(name) {
      if (sameCore(name, state.defaultModel) && state.defaultLabel) return state.defaultLabel;
      return state.labels[modelKey(name)] || tierLabel(name) || shortCore(name);
    }
    function sizeOf(name) {
      var hit = (state.models || []).filter(function (m) { return m && sameCore(m.name, name); })[0];
      return hit && hit.parameter_size ? String(hit.parameter_size) : '';
    }
    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
    function say(text) { if (status) status.textContent = text || ''; }

    function render() {
      var core = shortLabel(labelOf(effective()));
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

    function metaFor(name, size, prefix) {
      var parts = prefix ? [prefix] : [];
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
      // One entry per TCI name: Mara's own Core is the standard; a raw Core of
      // the same tier (her base) and further variants are listed in the legend.
      var seen = {};
      var standard = labelOf(state.defaultModel);
      seen[standard + '|' + isCloud(state.defaultModel)] = true;
      list.appendChild(option('', standard, metaFor(state.defaultModel, sizeOf(state.defaultModel), 'Standard'), false));
      var found = state.override === '';
      // Tier order (Schnell to Premium, local before cloud), own names last.
      var rank = function (name) {
        var label = labelOf(name);
        for (var i = 0; i < TIERS.length; i++) if (TIERS[i][1] === label) return i * 2 + (isCloud(name) ? 1 : 0);
        return 99;
      };
      (state.models || []).slice().sort(function (a, b) {
        return rank(String(a && a.name || '')) - rank(String(b && b.name || ''));
      }).forEach(function (model) {
        var name = String(model && model.name || '');
        if (!name) return;
        var current = name === state.override;
        if (current) found = true;
        var label = labelOf(name);
        var key = label + '|' + isCloud(name);
        if (!current && (sameCore(name, state.defaultModel) || seen[key])) return;
        seen[key] = true;
        list.appendChild(option(name, label, metaFor(name, model.parameter_size || ''), false));
      });
      if (!found && state.models !== null) list.appendChild(option(state.override, labelOf(state.override), 'nicht mehr installiert', true));
      else if (!found) list.appendChild(option(state.override, labelOf(state.override), metaFor(state.override, ''), false));
      renderLegend();
    }

    function legendRow(label, core, note) {
      var tr = doc.createElement('tr');
      [label, core, note].forEach(function (text, i) {
        var cell = doc.createElement(i === 0 ? 'th' : 'td');
        if (i === 0) cell.setAttribute('scope', 'row');
        cell.textContent = text;
        tr.appendChild(cell);
      });
      legendRows.appendChild(tr);
    }

    // Legend: every TCI tier with the technical Cores behind it, then Cores
    // that keep their own name.
    function renderLegend() {
      if (!legendRows) return;
      clear(legendRows);
      var models = (state.models || []).map(function (m) { return String(m && m.name || ''); }).filter(Boolean);
      if (state.defaultModel && !models.some(function (n) { return sameCore(n, state.defaultModel); })) {
        models.unshift(state.defaultModel);
      }
      TIERS.forEach(function (tier) {
        var names = models.filter(function (n) { return labelOf(n) === tier[1]; });
        var core = names.length ? names.map(function (n) {
          return n + (sameCore(n, state.defaultModel) ? ' (Maras Core)' : '');
        }).join(', ') : tier[2];
        var note = !names.length ? (state.models === null ? '' : 'nicht installiert')
          : names.some(function (n) { return sameCore(n, state.defaultModel); }) ? 'Standard'
          : names.every(isCloud) ? 'Cloud' : 'installiert';
        legendRow(tier[1], core, note);
      });
      models.filter(function (n) { return labelOf(n) === shortCore(n); }).forEach(function (n) {
        legendRow(shortCore(n), n, isCloud(n) ? 'Cloud, eigener Name' : 'eigener Name');
      });
      if (state.models === null && state.listError) legendRow('', 'Ollama-Liste gerade nicht erreichbar', '');
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
        state.defaultLabel = String(data.default_label || '');
        if (data.core_label) state.labels[modelKey(effective())] = String(data.core_label);
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
          if (both[0].default_label) state.defaultLabel = String(both[0].default_label);
          state.models.forEach(function (m) {
            if (m && m.name && m.label) state.labels[modelKey(m.name)] = String(m.label);
          });
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
        if (data.label) state.labels[modelKey(data.model)] = String(data.label);
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
    function legendOpen() { return !!legend && !legend.hidden; }
    function closeLegend(focusButton) {
      if (!legendOpen()) return;
      legend.hidden = true;
      if (legendBtn) legendBtn.setAttribute('aria-expanded', 'false');
      if (focusButton && legendBtn && typeof legendBtn.focus === 'function') legendBtn.focus();
    }
    function openLegend() {
      if (!legend) return Promise.resolve();
      close(false);
      legend.hidden = false;
      if (legendBtn) legendBtn.setAttribute('aria-expanded', 'true');
      renderLegend();
      return loadList();
    }
    function open() {
      closeLegend(false);
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
    if (legendBtn) legendBtn.addEventListener('click', function (event) {
      if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
      if (legendOpen()) { closeLegend(false); return Promise.resolve(); }
      return openLegend();
    });
    doc.addEventListener('click', function (event) {
      var path = event && typeof event.composedPath === 'function' ? event.composedPath() : [];
      if (isOpen() && path.indexOf(menu) < 0 && path.indexOf(chip) < 0
          && !inside(menu, event.target) && !inside(chip, event.target)) close(false);
      if (legendOpen() && path.indexOf(legend) < 0 && path.indexOf(legendBtn) < 0
          && !inside(legend, event.target) && !(legendBtn && inside(legendBtn, event.target))) closeLegend(false);
    });
    doc.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      if (isOpen()) close(true);
      if (legendOpen()) closeLegend(true);
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
      openLegend: openLegend,
      closeLegend: closeLegend,
      label: labelOf,
      state: function () {
        return {thinking: state.thinking, override: state.override, core: effective(), loaded: state.loaded, loading: state.loading};
      }
    };
  }

  window.CoreUICoreMode = {create: create, shortCore: shortCore, modelKey: modelKey, tierLabel: tierLabel,
    label: function (name) {
      var mode = window.CoreUICoreMode.instance;
      return mode ? mode.label(name) : (tierLabel(name) || shortCore(name));
    }};
  if (window.document && typeof window.fetch === 'function') {
    var start = function () { window.CoreUICoreMode.instance = create(window.document, window.fetch.bind(window)); };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
