/* CoreAI 0.5.76: install window for a TCI tier that Ollama does not have.
   Opened from a greyed entry in the Core menu. It explains what is missing,
   shows the server's short hardware check with a recommendation and lets an
   admin install the tier: Ollama downloads the Gemma base, then CoreAI
   builds Mara's Core from her Modelfile (api/core_install.php). The job
   runs on the server; the window only follows its progress and may be
   closed meanwhile. */
(function () {
  'use strict';

  var API = './api/user_settings.php';
  var POLL_MS = 1500;
  var VERDICT = {
    gut: ['Empfehlung: installieren', 'Der Server hat genug Reserven für diese Stufe.'],
    knapp: ['Möglich, aber knapp', 'Lies die gelben Punkte, bevor du installierst.'],
    abraten: ['Lieber nicht installieren', 'Die roten Punkte sprechen dagegen. Installieren geht nur mit Bestätigung.']
  };
  var ERRORS = {
    install_not_allowed: 'Installieren dürfen nur Admins.',
    already_installed: 'Diese Stufe ist inzwischen installiert.',
    install_blocked: 'Ollama ist nicht erreichbar, so kann nichts installiert werden.',
    install_running: 'Gerade läuft schon eine Installation.',
    install_confirm_required: 'Der Check rät ab. Bitte bestätige die Installation noch einmal.',
    unknown_tier: 'Diese Stufe kennt der Installer nicht.'
  };

  function gb(bytes) {
    var n = Number(bytes) / 1073741824;
    if (!isFinite(n) || n <= 0) return '0 GB';
    return (n < 10 ? n.toFixed(1).replace('.', ',') : String(Math.round(n))) + ' GB';
  }

  function create(doc, fetchFn, schedule, hooks) {
    var root = doc.getElementById('coreInstall');
    if (!root || typeof fetchFn !== 'function') return null;
    schedule = schedule || function (fn, ms) { return window.setTimeout(fn, ms); };
    hooks = hooks || {};
    var el = {};
    ['Title', 'Lead', 'Tech', 'Check', 'Rows', 'Verdict', 'VerdictTitle', 'VerdictText', 'Progress', 'BarWrap', 'Bar',
      'Step', 'Note', 'Go', 'Cancel', 'Close'].forEach(function (key) { el[key] = doc.getElementById('coreInstall' + key); });
    var state = {tier: '', check: null, canInstall: false, install: null, confirm: false, busy: false,
      csrf: '', polling: false, error: ''};

    function isOpen() { return !root.hidden; }
    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
    function show(node, on) { if (node) node.hidden = !on; }
    function text(node, value) { if (node) node.textContent = value || ''; }

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
            error.data = data || {};
            throw error;
          }
          return data;
        });
      });
    }

    // The job that belongs to this window: same tier, or any running one.
    function job() {
      var j = state.install;
      return j && (j.tier === state.tier || j.running) ? j : null;
    }

    function renderRows() {
      clear(el.Rows);
      ((state.check && state.check.rows) || []).forEach(function (row) {
        var dt = doc.createElement('dt');
        dt.setAttribute('data-state', row.state);
        dt.textContent = row.label;
        var dd = doc.createElement('dd');
        dd.setAttribute('data-state', row.state);
        dd.textContent = row.value;
        el.Rows.appendChild(dt);
        el.Rows.appendChild(dd);
      });
    }

    function render() {
      var check = state.check;
      var j = job();
      var mine = !!(j && j.tier === state.tier);
      var running = !!(j && j.running);
      var done = mine && j.phase === 'done';
      var failed = mine && !running && j.phase === 'error';
      text(el.Title, check ? check.label : 'Core installieren');
      if (!check) {
        text(el.Lead, state.error || 'Der Hardware-Check läuft …');
        text(el.Tech, '');
        clear(el.Rows);
        show(el.Check, !state.error);
        show(el.Verdict, false);
      } else {
        text(el.Lead, done ? 'Maras Core für diese Stufe ist gebaut und steht jetzt im Core-Menü.'
          : 'Diese Stufe ist auf dem Server noch nicht installiert. Ollama lädt dafür ca. '
            + String(check.download_gb).replace('.', ',') + ' GB herunter, danach baut CoreAI daraus Maras Core mit ihrem Modelfile.');
        text(el.Tech, 'Technisch: ' + check.base + ', angelegt als ' + check.core);
        renderRows();
        // Only admins get the hardware check (the server sends no rows otherwise).
        var verdict = VERDICT[check.verdict] || ['', ''];
        show(el.Check, !!(check.rows && check.rows.length));
        show(el.Verdict, !done && !!VERDICT[check.verdict]);
        if (el.Verdict) el.Verdict.setAttribute('data-verdict', check.verdict || '');
        text(el.VerdictTitle, verdict[0]);
        text(el.VerdictText, verdict[1]);
      }

      show(el.Progress, mine && (running || done));
      if (mine) {
        var pct = Math.max(0, Math.min(100, Number(j.percent) || 0));
        if (el.Bar && el.Bar.style) el.Bar.style.width = pct + '%';
        if (el.BarWrap) el.BarWrap.setAttribute('aria-valuenow', String(pct));
        var sizes = j.phase === 'pull' && j.total > 0 ? ' ' + gb(j.completed) + ' von ' + gb(j.total) + ' (' + pct + ' %)' : '';
        text(el.Step, (j.step || '') + sizes);
      }

      var note = '';
      var go = '';
      if (running && !mine) note = 'Gerade wird ' + (j.label || 'eine andere Stufe') + ' installiert. Bitte warte, bis das fertig ist.';
      else if (running) note = 'Du kannst das Fenster schließen, die Installation läuft auf dem Server weiter.';
      else if (done) { note = ''; go = 'Jetzt nutzen'; }
      else if (failed) note = j.message || 'Die Installation ist fehlgeschlagen.';
      if (state.error && check) note = state.error;
      if (!running && !done && check) {
        if (!state.canInstall) note = note || 'Installieren dürfen nur Admins. Bitte deinen Admin, diese Stufe hier im Chat zu installieren.';
        else if (check.installed) note = note || 'Diese Stufe ist bereits installiert.';
        else go = state.confirm ? 'Wirklich installieren?' : failed ? 'Erneut versuchen'
          : check.verdict === 'abraten' ? 'Trotzdem installieren' : 'Installieren';
      }
      text(el.Note, note);
      if (el.Note) el.Note.setAttribute('data-tone', failed || state.error ? 'bad' : state.confirm ? 'warn' : '');
      if (el.Go) {
        show(el.Go, !!go);
        el.Go.textContent = go || 'Installieren';
        el.Go.disabled = state.busy || !!(check && check.blocked && !done);
        el.Go.setAttribute('data-tone', state.confirm || (check && check.verdict === 'abraten') ? 'warn' : '');
      }
      text(el.Cancel, running || done ? 'Schließen' : 'Abbrechen');
    }

    function poll() {
      if (!state.polling) return Promise.resolve();
      return request(API + '?core_install_status=1', 'GET').then(function (data) {
        var before = job();
        state.install = data.install || null;
        var now = job();
        if (before && before.running && now && !now.running && now.phase === 'done' && typeof hooks.installed === 'function') {
          hooks.installed(now.core);
        }
      }).catch(function () {}).then(function () {
        var j = job();
        state.polling = isOpen() && !!(j && j.running);
        if (state.polling) schedule(poll, POLL_MS);
        render();
      });
    }

    function follow() {
      var j = job();
      if (state.polling || !isOpen() || !(j && j.running)) return;
      state.polling = true;
      schedule(poll, POLL_MS);
    }

    function open(tier) {
      state.tier = String(tier || '');
      state.check = null;
      state.error = '';
      state.confirm = false;
      state.busy = false;
      root.hidden = false;
      render();
      if (el.Close && typeof el.Close.focus === 'function') el.Close.focus();
      return request(API + '?core_install=' + encodeURIComponent(state.tier), 'GET').then(function (data) {
        state.check = data.check || null;
        state.canInstall = data.can_install === true;
        state.install = data.install || null;
        if (data.csrf_token) state.csrf = String(data.csrf_token);
      }).catch(function (error) {
        state.error = ERRORS[error.code] || 'Der Hardware-Check ist gerade nicht erreichbar.';
      }).then(function () {
        render();
        follow();
      });
    }

    function close() {
      if (!isOpen()) return;
      root.hidden = true;
      state.polling = false;
      state.confirm = false;
      if (typeof hooks.closed === 'function') hooks.closed();
    }

    function install() {
      var check = state.check;
      var j = job();
      if (j && j.tier === state.tier && j.phase === 'done' && !j.running) {
        close();
        return Promise.resolve(typeof hooks.choose === 'function' ? hooks.choose(j.core) : null);
      }
      if (!check || state.busy || !state.canInstall || check.installed || check.blocked || (j && j.running)) return Promise.resolve();
      if (check.verdict === 'abraten' && !state.confirm) {
        state.confirm = true;
        render();
        return Promise.resolve();
      }
      state.busy = true;
      state.error = '';
      render();
      var body = {action: 'core_install', tier: state.tier};
      if (state.confirm) body.confirm = true;
      return request(API, 'POST', body).then(function (data) {
        state.install = data.install || null;
      }).catch(function (error) {
        state.error = ERRORS[error.code] || 'Die Installation ließ sich nicht starten. Bitte noch einmal versuchen.';
        if (error.data && error.data.install) state.install = error.data.install;
        if (error.data && error.data.check) state.check = error.data.check;
      }).then(function () {
        state.busy = false;
        state.confirm = false;
        render();
        follow();
      });
    }

    if (el.Go) el.Go.addEventListener('click', function () { return install(); });
    if (el.Cancel) el.Cancel.addEventListener('click', function () { close(); });
    if (el.Close) el.Close.addEventListener('click', function () { close(); });
    root.addEventListener('click', function (event) { if (event && event.target === root) close(); });
    doc.addEventListener('keydown', function (event) { if (event.key === 'Escape' && isOpen()) close(); });

    return {open: open, close: close, install: install, poll: poll, isOpen: isOpen,
      state: function () { return {tier: state.tier, check: state.check, install: state.install, confirm: state.confirm, polling: state.polling}; }};
  }

  window.CoreUICoreInstall = {create: create, gb: gb};
  if (window.document && typeof window.fetch === 'function') {
    var start = function () {
      var doc = window.document;
      window.CoreUICoreInstall.instance = create(doc, window.fetch.bind(window), null, {
        installed: function () {
          var mode = window.CoreUICoreMode && window.CoreUICoreMode.instance;
          if (mode && typeof mode.refresh === 'function') mode.refresh();
        },
        choose: function (core) {
          var mode = window.CoreUICoreMode && window.CoreUICoreMode.instance;
          return mode && typeof mode.choose === 'function' ? mode.choose(core) : null;
        },
        closed: function () {
          var chip = doc.getElementById('coreModeChip');
          if (chip && typeof chip.focus === 'function') chip.focus();
        }
      });
    };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
