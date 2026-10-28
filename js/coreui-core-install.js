/* CoreAI 0.5.76: install window for a TCI tier that Ollama does not have.
   Opened from a greyed entry in the Core menu. It explains what is missing,
   shows the server's short hardware check with a recommendation and lets an
   admin install the tier: Ollama downloads the Gemma base, then CoreAI
   builds Mara's Core from her Modelfile (api/core_install.php). The job
   runs on the server; the window only follows its progress and may be
   closed meanwhile.
   The same window opens as "Cores verwalten" for admins: every tier with its
   Cores, "Jetzt aktualisieren" and the weekly auto-update switch (both also
   sit in the install view), and the Ollama Cloud connection: like
   `ollama signin`, the server's Ollama hands out a connect link on
   ollama.com; after confirming it there, cloud Cores can be added. */
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
    insufficient_permissions: 'Das dürfen nur Admins.',
    already_installed: 'Diese Stufe ist inzwischen installiert.',
    install_blocked: 'Ollama ist nicht erreichbar, so kann nichts installiert werden.',
    install_running: 'Gerade läuft schon eine Installation oder ein Update.',
    install_confirm_required: 'Der Check rät ab. Bitte bestätige die Installation noch einmal.',
    nothing_to_update: 'Es ist noch keine lokale Stufe installiert, es gibt nichts zu aktualisieren.',
    cloud_signin_required: 'Erst den Server mit einem Ollama-Konto verbinden (Ollama Cloud, unten).',
    unknown_tier: 'Diese Stufe kennt der Installer nicht.'
  };
  var RESULT = {updated: 'neue Fassung, neu gebaut', rebuilt: 'Modelfile-Text erneuert', current: 'aktuell'};
  var STATUS = {installed: 'installiert', cloud: 'nur Cloud', missing: 'nicht installiert'};

  function gb(bytes) {
    var n = Number(bytes) / 1073741824;
    if (!isFinite(n) || n <= 0) return '0 GB';
    return (n < 10 ? n.toFixed(1).replace('.', ',') : String(Math.round(n))) + ' GB';
  }
  function two(n) { return (n < 10 ? '0' : '') + n; }
  function when(seconds) {
    var d = new Date(Number(seconds) * 1000);
    return two(d.getDate()) + '.' + two(d.getMonth() + 1) + '.' + d.getFullYear() + ', ' + two(d.getHours()) + ':' + two(d.getMinutes());
  }

  function create(doc, fetchFn, schedule, hooks) {
    var root = doc.getElementById('coreInstall');
    if (!root || typeof fetchFn !== 'function') return null;
    schedule = schedule || function (fn, ms) { return window.setTimeout(fn, ms); };
    hooks = hooks || {};
    var el = {};
    ['Kicker', 'Title', 'Lead', 'Tech', 'Tiers', 'TierList', 'Check', 'Rows', 'Verdict', 'VerdictTitle', 'VerdictText',
      'Progress', 'BarWrap', 'Bar', 'Step', 'Maint', 'Auto', 'UpdateInfo', 'UpdateNow', 'ToManage', 'Results',
      'Cloud', 'CloudState', 'CloudSteps', 'CloudLink', 'CloudCheck', 'Note', 'Go', 'Cancel', 'Close'].forEach(function (key) { el[key] = doc.getElementById('coreInstall' + key); });
    var state = {mode: 'tier', tier: '', check: null, canInstall: false, install: null, confirm: false, busy: false,
      csrf: '', polling: false, error: '', auto: null, tiers: null, plan: null, cloud: null, cloudBusy: false};

    function isOpen() { return !root.hidden; }
    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
    function show(node, on) { if (node) node.hidden = !on; }
    function text(node, value) { if (node) node.textContent = value || ''; }
    function add(parent, tag, cls, value) {
      var node = doc.createElement(tag);
      if (cls) node.className = cls;
      if (value) node.textContent = value;
      parent.appendChild(node);
      return node;
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
            error.data = data || {};
            throw error;
          }
          return data;
        });
      });
    }

    // The job this view follows: an install of this tier, an update in the
    // overview, or any job that is still running.
    function job() {
      var j = state.install;
      if (!j) return null;
      return j.running || ours(j) ? j : null;
    }
    // The overview follows updates and cloud Cores it added; the install view its tier.
    function ours(j) {
      if (!j) return false;
      if (state.mode === 'manage') return j.kind === 'update' || j.cloud === true;
      return j.kind !== 'update' && !j.cloud && j.tier === state.tier;
    }
    function connected() { return !!(state.cloud && state.cloud.account && state.cloud.account.state === 'connected'); }
    function admin() { return state.mode === 'manage' || state.canInstall; }

    function renderRows() {
      clear(el.Rows);
      ((state.check && state.check.rows) || []).forEach(function (row) {
        add(el.Rows, 'dt', '', row.label).setAttribute('data-state', row.state);
        add(el.Rows, 'dd', '', row.value).setAttribute('data-state', row.state);
      });
    }

    function renderTiers() {
      clear(el.TierList);
      (state.tiers || []).forEach(function (tier) {
        var li = add(el.TierList, 'li', 'core-install-tier');
        li.setAttribute('data-status', tier.status);
        var head = add(li, 'div', 'core-install-tier-head');
        add(head, 'strong', '', tier.label);
        add(head, 'span', 'core-install-tier-status', STATUS[tier.status] || tier.status);
        var cores = (tier.local || []).concat(tier.cloud || []);
        add(li, 'small', 'core-install-tier-cores', cores.length ? cores.join(', ') : tier.base);
        if (tier.alias_of) {
          add(li, 'small', 'core-install-tier-alias', 'Achtung: ' + tier.base + ' ist nur ein zweiter Name für ' + tier.alias_of
            + '. Ein Update lädt das echte ' + tier.base.replace(/^.*:/, '') + ' (ca. ' + String(tier.download_gb).replace('.', ',') + ' GB).');
        }
        var actions = add(li, 'div', 'core-install-tier-actions');
        if (tier.status !== 'installed') {
          var button = add(actions, 'button', 'core-install-tier-btn', tier.status === 'cloud' ? 'Lokal installieren' : 'Installieren');
          button.type = 'button';
          button.addEventListener('click', function () { return open(tier.tier); });
        }
        if (tier.cloud_missing) {
          var cloud = add(actions, 'button', 'core-install-tier-btn', 'Cloud hinzufügen');
          cloud.type = 'button';
          cloud.disabled = !connected() || state.busy || !!(job() && job().running);
          if (!connected()) cloud.setAttribute('title', 'Erst den Server mit einem Ollama-Konto verbinden');
          cloud.addEventListener('click', function () { return start({action: 'core_cloud_add', tier: tier.tier}); });
        }
        if (!actions.firstChild) li.removeChild(actions);
      });
    }

    function renderCloud() {
      show(el.Cloud, state.mode === 'manage' && !!state.tiers);
      var c = state.cloud;
      var account = c && c.account ? c.account.state : '';
      var line = !c ? 'Prüft die Verbindung zu Ollama …'
        : account === 'connected' ? 'Verbunden als ' + c.account.name + ' (Plan: ' + c.account.plan + ').'
        : account === 'signed_out' ? 'Noch nicht mit einem Ollama-Konto verbunden.'
          + (c.account.signin_url ? '' : ' Ollama hat keinen Anmeldelink geliefert, bitte gleich noch einmal prüfen.')
        : account === 'unreachable' ? 'Ollama ist gerade nicht erreichbar.'
        : !c.version_ok ? 'Ollama ' + (c.version || '') + ' ist zu alt für Cloud-Cores (ab ' + c.min_version + '). Bitte Ollama auf dem Server aktualisieren.'
        : account === 'unsupported' ? 'Diese Ollama-Version kennt die Anmeldung nicht. Bitte Ollama auf dem Server aktualisieren.'
        : 'Ollama erreicht ollama.com gerade nicht. Später noch einmal prüfen.';
      text(el.CloudState, line);
      if (el.CloudState) el.CloudState.setAttribute('data-state', account === 'connected' ? 'ok' : c ? 'warn' : '');
      var link = account === 'signed_out' && c.account.signin_url ? c.account.signin_url : '';
      show(el.CloudSteps, account === 'signed_out');
      show(el.CloudLink, !!link);
      if (el.CloudLink && link) el.CloudLink.setAttribute('href', link);
      if (el.CloudCheck) {
        el.CloudCheck.disabled = state.cloudBusy;
        el.CloudCheck.textContent = account === 'connected' ? 'Erneut prüfen' : 'Verbindung prüfen';
      }
    }

    function renderMaint() {
      var j = job();
      var running = !!(j && j.running);
      show(el.Maint, admin() && !!state.auto);
      show(el.ToManage, state.mode === 'tier' && state.canInstall);
      if (!state.auto) return;
      if (el.Auto) {
        el.Auto.checked = state.auto.enabled === true;
        el.Auto.disabled = state.busy;
      }
      var parts = [state.auto.last ? 'Zuletzt geprüft: ' + when(state.auto.last) + '.' : 'Noch kein Update gelaufen.'];
      if (state.auto.enabled && state.auto.next) parts.push('Nächstes Auto-Update ab ' + when(state.auto.next) + '.');
      if (state.plan) {
        parts.push(state.plan.length ? 'Ein Update prüft ' + state.plan.map(function (p) { return p.label.replace(/^TCI:\s*/, ''); }).join(', ') + '.'
          : 'Noch keine lokale Stufe installiert.');
      }
      text(el.UpdateInfo, parts.join(' '));
      if (el.UpdateNow) el.UpdateNow.disabled = state.busy || running || (!!state.plan && !state.plan.length);
      clear(el.Results);
      var last = state.install && state.install.kind === 'update' && !state.install.running ? state.install.results || [] : [];
      show(el.Results, state.mode === 'manage' && last.length > 0);
      last.forEach(function (r) {
        add(el.Results, 'li', '', r.label + ': ' + (r.result === 'error' ? (r.message || 'Fehler') : (RESULT[r.result] || r.result)))
          .setAttribute('data-state', r.result === 'error' ? 'bad' : 'ok');
      });
    }

    function renderProgress(j) {
      var pct = Math.max(0, Math.min(100, Number(j.percent) || 0));
      if (el.Bar && el.Bar.style) el.Bar.style.width = pct + '%';
      if (el.BarWrap) el.BarWrap.setAttribute('aria-valuenow', String(pct));
      var sizes = j.phase === 'pull' && j.total > 0 ? ' ' + gb(j.completed) + ' von ' + gb(j.total) + ' (' + pct + ' %)' : '';
      var where = j.kind === 'update' && j.running && j.items ? j.label + ' (' + j.item + ' von ' + j.items + '): ' : '';
      text(el.Step, where + (j.step || '') + sizes);
    }

    function render() {
      var manage = state.mode === 'manage';
      var check = state.check;
      var j = job();
      var mine = ours(j);
      var running = !!(j && j.running);
      var done = mine && !running && j.phase === 'done';
      var failed = mine && !running && j.phase === 'error';
      text(el.Kicker, manage ? 'CORES VERWALTEN' : 'CORE INSTALLIEREN');
      show(el.Tiers, manage && !!state.tiers);
      show(el.Tech, !manage);
      if (manage) {
        text(el.Title, 'Cores verwalten');
        text(el.Lead, state.tiers ? 'Ollama bringt die Gemma-Stufen in unregelmäßigen Abständen in neuen Fassungen. '
          + 'Ein Update lädt nur, was sich geändert hat, und baut Maras Cores danach mit ihrem Modelfile neu.'
          : state.error || 'Lädt die Übersicht …');
        renderTiers();
        show(el.Check, false);
      } else {
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
              + String(check.download_gb).replace('.', ',') + ' GB herunter, danach baut CoreAI daraus Maras Core mit ihrem Modelfile.'
              + (check.cloud_base && state.canInstall ? ' Diese Stufe gibt es auch als Cloud-Core ohne Download (Ollama-Konto nötig): unter Cores verwalten.' : ''));
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
      }
      if (manage) show(el.Verdict, false);

      show(el.Progress, mine && (running || done));
      if (mine) renderProgress(j);
      renderMaint();
      renderCloud();

      var note = '';
      var go = '';
      if (running && !mine) {
        note = j.kind === 'update' ? 'Gerade läuft ein Update der Cores. Bitte warte, bis es fertig ist.'
          : 'Gerade wird ' + (j.label || 'eine andere Stufe') + ' installiert. Bitte warte, bis das fertig ist.';
      } else if (running) {
        note = 'Du kannst das Fenster schließen, ' + (j.kind === 'update' ? 'das Update' : 'die Installation') + ' läuft auf dem Server weiter.';
      } else if (done) {
        note = manage ? (j.kind === 'update' ? j.message || 'Update fertig.' : j.step || '') : '';
        go = manage ? '' : 'Jetzt nutzen';
      } else if (failed) {
        note = j.message || (j.kind === 'update' ? 'Das Update ist fehlgeschlagen.' : 'Die Installation ist fehlgeschlagen.');
      }
      if (state.error && (check || manage)) note = state.error;
      if (!manage && !running && !done && check) {
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
      text(el.Cancel, running || done || manage ? 'Schließen' : 'Abbrechen');
    }

    function poll() {
      if (!state.polling) return Promise.resolve();
      return request(API + '?core_install_status=1', 'GET').then(function (data) {
        var before = state.install;
        state.install = data.install || null;
        var now = state.install;
        if (before && before.running && now && !now.running && now.phase !== 'error' && typeof hooks.installed === 'function') {
          hooks.installed(now.core);
        }
        if (before && before.running && now && !now.running && state.mode === 'manage') return refreshManage().catch(function () {});
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

    function reset(mode) {
      state.mode = mode;
      state.check = null;
      state.error = '';
      state.confirm = false;
      state.busy = false;
      root.hidden = false;
      render();
      if (el.Close && typeof el.Close.focus === 'function') el.Close.focus();
    }

    function open(tier) {
      state.tier = String(tier || '');
      reset('tier');
      return request(API + '?core_install=' + encodeURIComponent(state.tier), 'GET').then(function (data) {
        state.check = data.check || null;
        state.canInstall = data.can_install === true;
        state.install = data.install || null;
        state.auto = data.auto || null;
        if (data.csrf_token) state.csrf = String(data.csrf_token);
      }).catch(function (error) {
        state.error = ERRORS[error.code] || 'Der Hardware-Check ist gerade nicht erreichbar.';
      }).then(function () {
        render();
        follow();
      });
    }

    function refreshManage() {
      return request(API + '?core_manage=1', 'GET').then(function (data) {
        state.tiers = Array.isArray(data.tiers) ? data.tiers : [];
        state.plan = Array.isArray(data.plan) ? data.plan : [];
        state.auto = data.auto || null;
        state.install = data.install || null;
        if (data.csrf_token) state.csrf = String(data.csrf_token);
      });
    }

    // Asking ollama.com may take a moment: the overview does not wait for it.
    function loadCloud() {
      state.cloudBusy = true;
      render();
      return request(API + '?core_cloud=1', 'GET').then(function (data) {
        state.cloud = data.cloud || null;
      }).catch(function () {
        state.cloud = {version: null, version_ok: false, account: {state: 'unavailable'}};
      }).then(function () {
        state.cloudBusy = false;
        render();
      });
    }

    function manage() {
      state.tier = '';
      state.tiers = null;
      state.plan = null;
      reset('manage');
      var overview = refreshManage().catch(function (error) {
        state.error = ERRORS[error.code] || 'Die Übersicht ist gerade nicht erreichbar.';
      }).then(function () {
        render();
        follow();
      });
      return Promise.all([overview, loadCloud()]);
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
      if (state.mode !== 'tier') return Promise.resolve();
      if (j && ours(j) && j.phase === 'done' && !j.running) {
        close();
        return Promise.resolve(typeof hooks.choose === 'function' ? hooks.choose(j.core) : null);
      }
      if (!check || state.busy || !state.canInstall || check.installed || check.blocked || (j && j.running)) return Promise.resolve();
      if (check.verdict === 'abraten' && !state.confirm) {
        state.confirm = true;
        render();
        return Promise.resolve();
      }
      var body = {action: 'core_install', tier: state.tier};
      if (state.confirm) body.confirm = true;
      return start(body);
    }

    function start(body) {
      state.busy = true;
      state.error = '';
      render();
      return request(API, 'POST', body).then(function (data) {
        state.install = data.install || null;
      }).catch(function (error) {
        state.error = ERRORS[error.code] || (body.action === 'core_update' ? 'Das Update ließ sich nicht starten.'
          : 'Die Installation ließ sich nicht starten.') + (ERRORS[error.code] ? '' : ' Bitte noch einmal versuchen.');
        if (error.data && error.data.install) state.install = error.data.install;
        if (error.data && error.data.check) state.check = error.data.check;
      }).then(function () {
        state.busy = false;
        state.confirm = false;
        render();
        follow();
      });
    }

    function updateNow() {
      var j = job();
      if (state.busy || (j && j.running)) return Promise.resolve();
      if (state.mode === 'tier') state.mode = 'manage';
      return start({action: 'core_update'}).then(function () {
        if (!state.tiers) return refreshManage().catch(function () {}).then(render);
      });
    }

    function setAuto(on) {
      if (!state.auto || state.busy) return Promise.resolve();
      var before = state.auto.enabled === true;
      state.busy = true;
      state.error = '';
      state.auto.enabled = on === true;
      render();
      return request(API, 'POST', {action: 'core_auto_update', enabled: on === true}).then(function (data) {
        state.auto = data.auto || state.auto;
      }).catch(function () {
        state.auto.enabled = before;
        state.error = 'Der Auto-Update-Schalter wurde nicht gespeichert. Bitte noch einmal versuchen.';
      }).then(function () {
        state.busy = false;
        render();
      });
    }

    if (el.Go) el.Go.addEventListener('click', function () { return install(); });
    if (el.UpdateNow) el.UpdateNow.addEventListener('click', function () { return updateNow(); });
    if (el.ToManage) el.ToManage.addEventListener('click', function () { return manage(); });
    if (el.CloudCheck) el.CloudCheck.addEventListener('click', function () { return loadCloud(); });
    if (el.Auto) el.Auto.addEventListener('change', function () { return setAuto(el.Auto.checked); });
    if (el.Cancel) el.Cancel.addEventListener('click', function () { close(); });
    if (el.Close) el.Close.addEventListener('click', function () { close(); });
    root.addEventListener('click', function (event) { if (event && event.target === root) close(); });
    doc.addEventListener('keydown', function (event) { if (event.key === 'Escape' && isOpen()) close(); });

    return {open: open, manage: manage, close: close, install: install, updateNow: updateNow, setAuto: setAuto,
      poll: poll, isOpen: isOpen,
      state: function () {
        return {mode: state.mode, tier: state.tier, check: state.check, install: state.install, confirm: state.confirm,
          polling: state.polling, auto: state.auto, cloud: state.cloud};
      }};
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
