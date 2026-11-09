/* CoreAI 0.6.75: real, per-account Denkaufwand below the composer.
 * Uses the same General API and the existing Core Thinking switch.
 * No synthetic levels: named efforts are offered ONLY when /api/show lists
 * them for the active, authorized Core. Boolean-only models use An/Aus.
 * Does not touch Modelfiles, num_ctx, model queues, or other accounts.
 */
(function () {
  'use strict';
  var API = './api/user_settings.php';
  var ORDER = ['low', 'medium', 'high', 'max'];
  var LABEL = {low: 'Gering', medium: 'Mittel', high: 'Hoch', max: 'Max'};
  function create(doc, fetchFn, mode) {
    var trigger = doc.getElementById('reasonEffortChip');
    var menu = doc.getElementById('reasonEffortMenu');
    var list = doc.getElementById('reasonEffortOptions');
    var label = doc.getElementById('reasonEffortLabel');
    var note = doc.getElementById('reasonEffortNote');
    var status = doc.getElementById('reasonEffortStatus');
    if (!trigger || !menu || !list || !label || typeof fetchFn !== 'function') return null;
    var state = {busy: false, loading: true, ready: false, available: false, levels: [],
      level: '', csrf: '', core: '', sequence: 0, error: ''};
    function thinking() {
      return mode && typeof mode.state === 'function' ? mode.state().thinking !== false : true;
    }
    function selected() {
      if (!thinking()) return 'off';
      if (!state.available || !state.levels.length) return 'on';
      return state.levels.indexOf(state.level) !== -1 ? state.level : '';
    }
    function title(key) {
      return key === 'off' ? 'Aus' : key === 'on' ? 'An'
        : key === '' ? 'Core-Standard' : (LABEL[key] || 'Core-Standard');
    }
    function render() {
      var active = selected();
      label.textContent = state.loading ? 'Lädt …' : title(active);
      trigger.disabled = !state.ready || state.busy;
      trigger.setAttribute('aria-label', 'Denkaufwand: ' + label.textContent + '. Stufe auswählen');
      while (list.firstChild) list.removeChild(list.firstChild);
      var choices = state.available && state.levels.length
        ? ['off', ''].concat(ORDER.filter(function (x) { return state.levels.indexOf(x) !== -1; }))
        : ['off', 'on'];
      choices.forEach(function (choice) {
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'reason-effort-option';
        btn.setAttribute('role', 'radio');
        btn.setAttribute('aria-checked', active === choice ? 'true' : 'false');
        btn.disabled = state.loading || state.busy;
        btn.setAttribute('data-level', choice);
        var dot = doc.createElement('span');
        dot.className = 'reason-effort-dot';
        dot.setAttribute('aria-hidden', 'true');
        var text = doc.createElement('span');
        text.textContent = title(choice);
        btn.appendChild(dot);
        btn.appendChild(text);
        btn.addEventListener('click', function () { return choose(choice); });
        list.appendChild(btn);
      });
      if (note) note.textContent = !state.ready ? 'Core-Einstellungen sind derzeit nicht erreichbar.'
        : state.levels.length && state.available
          ? 'Nur die vom aktuellen Core unterstützten Stufen. Gilt für deine nächste Nachricht.'
          : 'Dieser Core bietet keine benannten Denkstufen. Denken an/aus funktioniert weiterhin.';
      if (status) status.textContent = state.error || '';
    }
    function request(url, body) {
      var init = {credentials: 'include', cache: 'no-store', method: body ? 'POST' : 'GET',
        headers: {'Accept': 'application/json'}};
      if (body) {
        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CoreUI-CSRF'] = state.csrf;
        init.body = JSON.stringify(body);
      }
      return fetchFn(url, init).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data || data.ok !== true) {
            throw new Error((data && data.error) || ('HTTP_' + response.status));
          }
          return data;
        });
      });
    }
    function reload() {
      var seq = ++state.sequence;
      state.loading = true;
      state.error = '';
      render();
      return request(API + '?general=1').then(function (data) {
        if (seq !== state.sequence) return;
        state.ready = true;
        state.available = data.available === true;
        state.core = String(data.model || '');
        state.csrf = String(data.csrf_token || '');
        var named = data.reasoning && Array.isArray(data.reasoning.levels) ? data.reasoning.levels : [];
        state.levels = ORDER.filter(function (x) { return named.indexOf(x) !== -1; });
        state.level = data.general && typeof data.general.thinking_level === 'string'
          ? data.general.thinking_level : '';
        state.loading = false;
        render();
      }).catch(function () {
        if (seq !== state.sequence) return;
        // Keep the existing binary Thinking control usable even when
        // the model-capabilities endpoint is temporarily unreachable.
        state.ready = !!(mode && mode.state && mode.state().loaded);
        state.available = false;
        state.levels = [];
        state.loading = false;
        state.error = 'Zusätzliche Denkstufen momentan nicht erreichbar.';
        render();
      });
    }
    function choose(key) {
      if (!state.ready || state.busy) return Promise.resolve(false);
      var instance = mode || window.CoreUICoreMode && window.CoreUICoreMode.instance;
      if (!instance || typeof instance.setThinking !== 'function') return Promise.resolve(false);
      if (key !== 'off' && key !== 'on' && key !== '' && state.levels.indexOf(key) === -1)
        return Promise.resolve(false);
      state.busy = true;
      state.error = '';
      render();
      var action;
      if (key === 'off' || key === 'on') {
        // Reuse the existing account Thinking API and its established rollback.
        action = instance.setThinking(key === 'on').then(function (actual) {
          if (actual !== (key === 'on')) throw new Error('thinking_not_saved');
          return true;
        });
      } else if (!state.available) {
        action = Promise.reject(new Error('missing_schema_027'));
      } else {
        action = request(API, {action: 'general', settings: {thinking_level: key}})
          .then(function () {
            // A named effort is an explicit request to think.
            // Keep the original Core switch as source of truth.
            return instance.setThinking(true);
          }).then(function (actual) {
            if (actual !== true) throw new Error('thinking_not_saved');
            return true;
          });
      }
      return action.then(function () {
        close(false);
        return reload().then(function () { return true; });
      }).catch(function (error) {
        state.error = error && error.message === 'thinking_level_not_supported'
          ? 'Diese Stufe unterstützt dein aktueller Core nicht.'
          : 'Denkaufwand konnte nicht gespeichert werden.';
        return reload().then(function () { state.error = 'Bitte erneut versuchen.'; return false; });
      }).then(function (ok) {
        state.busy = false;
        render();
        return ok;
      });
    }
    function isOpen() { return !menu.hidden; }
    function close(focus) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (focus && typeof trigger.focus === 'function') trigger.focus();
    }
    function open() {
      if (mode && typeof mode.close === 'function') mode.close(false);
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      render();
    }
    trigger.addEventListener('click', function (ev) {
      if (ev && ev.stopPropagation) ev.stopPropagation();
      if (isOpen()) close(false); else open();
    });
    doc.addEventListener('click', function (ev) {
      if (isOpen() && ev.target && !menu.contains(ev.target) && !trigger.contains(ev.target)) close(false);
    });
    doc.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && isOpen()) { close(true); ev.preventDefault(); }
    });
    window.addEventListener('coreui:core', function () { close(false); reload(); });
    window.addEventListener('coreui:thinking', render);
    state.loading = true;
    render();
    var start = mode && mode.ready && typeof mode.ready.then === 'function' ? mode.ready : Promise.resolve();
    start.then(reload);
    return {reload: reload, choose: choose, open: open, close: close,
      state: function () { return {level: state.level, levels: state.levels.slice(),
        available: state.available, ready: state.ready, thinking: thinking()}; }};
  }
  window.CoreUIReasoningEffort = {create: create};
  if (window.document && typeof window.fetch === 'function') {
    var init = function () {
      window.CoreUIReasoningEffort.instance = create(window.document,
        window.fetch.bind(window), window.CoreUICoreMode && window.CoreUICoreMode.instance);
    };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', init);
    else init();
  }
})();
