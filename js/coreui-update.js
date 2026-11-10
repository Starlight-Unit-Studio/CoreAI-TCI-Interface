/* CoreAI 0.5.83: the update window.
 *
 * When a new merge lands on main, the host notices it (every 10 minutes) and
 * the Inhaber gets a matte golden window over a third of the screen, at every
 * page load until he starts it ("Später" snoozes popup and opt-in auto-updates for one hour).
 * "Update starten" asks the host to install it; then everyone, the Inhaber and
 * every other account with CoreAI open, sees "Bitte auf Neustart warten" with
 * the real progress: the host reads the installer's output and reports the step
 * it is on and how far it is. The page reloads as soon as CoreAI is back on
 * the new version, not after a fixed wait.
 */
(function () {
  'use strict';

  var API = './api/update.php';
  var POLL_IDLE_MS = 60000;
  var POLL_WAIT_MS = 3000;
  var GIVE_UP_S = 20 * 60;
  // Only if the host sends no percent (an older updater): a rough value per phase.
  var PHASE_PERCENT = { queued: 0, backup: 4, download: 9, install: 50, restart: 92, done: 100 };
  var STEPS = [
    ['backup', 'Sichern'],
    ['download', 'Laden'],
    ['install', 'Installieren'],
    ['restart', 'Neustart']
  ];
  var PHASES = {
    queued: 'Das Update wird vorbereitet.',
    backup: 'Die Datenbank wird gesichert.',
    download: 'Der neue Stand wird geladen.',
    install: 'CoreAI wird installiert und neu gestartet.',
    restart: 'Die Dienste werden neu gestartet.',
    done: 'Neustart abgeschlossen.'
  };

  var csrf = '';
  var last = null;
  var dismissedUntil = 0;
  var SNOOZE_MS = 60 * 60 * 1000;
  var root = null;
  var mode = '';
  var idleTimer = 0;
  var waitTimer = 0;
  var tickTimer = 0;
  var wait = null;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function request(method, action, body) {
    var options = { method: method, credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' } };
    if (method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CoreUI-CSRF'] = csrf;
      options.body = JSON.stringify(body || {});
    }
    return fetch(API + '?action=' + encodeURIComponent(action), options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data || data.ok !== true) {
          var error = new Error((data && data.error) || ('HTTP_' + response.status));
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  function errorText(code) {
    var texts = {
      updater_missing: 'Der Update-Dienst ist auf dem Server noch nicht eingerichtet. Einmal mit dem Setup-Einzeiler aktualisieren.',
      update_running: 'Es läuft bereits ein Update.',
      no_update: 'Es gibt gerade kein neues Update.',
      request_dir_missing: 'Der Anfrage-Ordner fehlt. Einmal mit dem Setup-Einzeiler aktualisieren.',
      owner_only: 'Nur der Inhaber kann CoreAI aktualisieren.',
      csrf_failed: 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'
    };
    return texts[code] || ('Das Update konnte nicht gestartet werden (' + code + ').');
  }

  // --- Changelog: a small, safe Markdown subset (headings, lists, bold, code) ---
  function inline(target, text) {
    String(text).split(/(\*\*[^*]+\*\*|`[^`]+`)/).forEach(function (part) {
      if (!part) return;
      if (/^\*\*[^*]+\*\*$/.test(part)) target.appendChild(el('strong', '', part.slice(2, -2)));
      else if (/^`[^`]+`$/.test(part)) target.appendChild(el('code', '', part.slice(1, -1)));
      else target.appendChild(document.createTextNode(part));
    });
  }

  function renderNotes(target, notes) {
    target.textContent = '';
    var list = null;
    String(notes || '').split('\n').forEach(function (line) {
      var item = /^\s*[-*]\s+(.*)$/.exec(line);
      if (item) {
        if (!list) list = target.appendChild(el('ul'));
        inline(list.appendChild(el('li')), item[1]);
        return;
      }
      list = null;
      var head = /^#{2,3}\s+(.*)$/.exec(line);
      if (head) target.appendChild(el('h3', '', head[1]));
      else if (line.trim() === '...') target.appendChild(el('p', 'cu-more', 'Weitere Änderungen stehen im CHANGELOG.'));
      else if (line.trim()) inline(target.appendChild(el('p')), line);
    });
    if (!target.firstChild) target.appendChild(el('p', 'cu-more', 'Für diesen Stand liegen keine Änderungsnotizen vor.'));
  }

  function close() {
    if (root) root.remove();
    root = null;
    mode = '';
    document.documentElement.classList.remove('coreai-update-open');
  }

  function frame(kind) {
    close();
    root = el('div', 'cu-backdrop cu-mode-' + kind);
    root.id = 'coreaiUpdate';
    document.body.appendChild(root);
    document.documentElement.classList.add('coreai-update-open');
    mode = kind;
    return root;
  }

  function snoozed(info) {
    // The host decides the time window. Use its remaining seconds so a
    // phone with an incorrect wall clock cannot defeat or extend the pause.
    if (info && Number.isFinite(Number(info.snooze_seconds))) {
      return Number(info.snooze_seconds) > 0 || dismissedUntil > Date.now();
    }
    var serverUntil = Number(info && info.snooze_until) * 1000 || 0;
    return Math.max(serverUntil, dismissedUntil) > Date.now();
  }

  function retryIcon(button) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('cu-retry-icon');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M3 12a9 9 0 1 0 3-6.7L3 8 M3 3v5h5');
    svg.appendChild(path);
    button.appendChild(svg);
    button.appendChild(el('span', '', 'Wiederholen'));
  }

  // --- The golden window (Inhaber) ---
  function showWindow(info, failure) {
    var backdrop = frame('window');
    var box = backdrop.appendChild(el('section', 'cu-window'));
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'cuTitle');

    var head = box.appendChild(el('header', 'cu-head'));
    head.appendChild(el('span', 'cu-kicker', 'COREAI UPDATE'));
    var title = head.appendChild(el('h2', '', failure ? 'Update fehlgeschlagen' : 'Neue Version verfügbar'));
    title.id = 'cuTitle';
    var versions = head.appendChild(el('p', 'cu-versions'));
    versions.appendChild(el('span', 'cu-from', info.current_version || 'installiert'));
    versions.appendChild(el('span', 'cu-arrow', '→'));
    versions.appendChild(el('strong', 'cu-to', info.latest_version || ('Commit ' + (info.latest_commit || ''))));

    if (failure) {
      var problem = box.appendChild(el('p', 'cu-error', info.message || 'Das Update ist fehlgeschlagen.'));
      problem.setAttribute('role', 'alert');
      if (info.failure_kind === 'preflight') {
        var hint = box.appendChild(el('p', 'cu-retry-hint',
          'Der Preflight hat Fehler gemeldet. Bitte versuche es in ein paar Minuten erneut. '
          + 'Die Details stehen im Update-Protokoll. Nach einem korrigierten Merge lässt sich das Update wiederholen.'));
        hint.setAttribute('role', 'status');
      }
      if (last && last.log && last.log.length) {
        var details = box.appendChild(el('details', 'cu-log'));
        details.appendChild(el('summary', '', 'Update-Protokoll (letzte Zeilen)'));
        details.appendChild(el('pre', '', last.log.join('\n')));
      }
    }

    var notes = box.appendChild(el('div', 'cu-notes'));
    notes.tabIndex = 0;
    notes.setAttribute('aria-label', 'Änderungen');
    renderNotes(notes, info.notes);

    var error = box.appendChild(el('p', 'cu-error cu-start-error'));
    error.hidden = true;
    error.setAttribute('role', 'alert');

    var option = box.appendChild(el('label', 'cu-option'));
    var ollama = option.appendChild(el('input'));
    ollama.type = 'checkbox';
    ollama.checked = true;
    ollama.id = 'cuOllama';
    option.appendChild(el('span', '', 'Ollama danach neu starten'));
    box.appendChild(el('p', 'cu-hint', 'Vorher wird die Datenbank gesichert. Alle offenen Seiten zeigen währenddessen den Installationsfortschritt; nur beim Neustart von PHP und Web ist CoreAI kurz nicht erreichbar. Danach lädt die Seite von selbst neu.'));

    var actions = box.appendChild(el('footer', 'cu-actions'));
    var later = actions.appendChild(el('button', 'cu-later', 'Später'));
    later.type = 'button';
    var start = actions.appendChild(el('button', 'cu-start', failure ? null : 'Update starten'));
    start.type = 'button';
    if (failure) retryIcon(start);
    if (!info.installed) {
      start.disabled = true;
      error.hidden = false;
      error.textContent = errorText('updater_missing');
    }

    later.addEventListener('click', function () {
      later.disabled = true;
      start.disabled = true;
      error.hidden = true;
      request('POST', 'later', {}).then(function (data) {
        var remaining = Number(data.snooze_seconds);
        dismissedUntil = Date.now() + (Number.isFinite(remaining) && remaining > 0
          ? remaining * 1000 : SNOOZE_MS);
        if (last && last.update) {
          last.update.snooze_until = Number(data.snooze_until) || 0;
          last.update.snooze_seconds = Math.max(0, Number(data.snooze_seconds) || 3600);
        }
        close();
        renderCard();
      }).catch(function () {
        later.disabled = false;
        start.disabled = !info.installed;
        error.hidden = false;
        error.textContent = 'Die Pause konnte nicht gespeichert werden. Bitte erneut versuchen.';
      });
    });
    start.addEventListener('click', function () {
      start.disabled = true;
      later.disabled = true;
      error.hidden = true;
      request('POST', 'start', { restart_ollama: ollama.checked }).then(function (data) {
        last = Object.assign({}, last || {}, { running: true, state: data.state, phase: data.phase, version: data.version, update: data.update });
        startWaiting(data);
      }).catch(function (problem) {
        start.disabled = !info.installed;
        later.disabled = false;
        error.hidden = false;
        error.textContent = errorText(problem.message);
      });
    });
    backdrop.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !later.disabled) later.click();
    });
    setTimeout(function () { if (!start.disabled) start.focus(); else later.focus(); }, 0);
  }

  // --- "Bitte auf Neustart warten" (everyone) ---
  function showWait() {
    var backdrop = frame('wait');
    var box = backdrop.appendChild(el('section', 'cu-wait'));
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'cuWaitTitle');
    box.appendChild(el('div', 'cu-spinner')).setAttribute('aria-hidden', 'true');
    var title = box.appendChild(el('h2', '', 'Bitte auf Neustart warten'));
    title.id = 'cuWaitTitle';
    var phase = box.appendChild(el('p', 'cu-phase'));
    phase.setAttribute('aria-live', 'polite');
    var steps = box.appendChild(el('ol', 'cu-steps'));
    STEPS.forEach(function (step) {
      var item = steps.appendChild(el('li', '', step[1]));
      item.setAttribute('data-step', step[0]);
    });
    var progress = box.appendChild(el('div', 'cu-progress'));
    var head = progress.appendChild(el('div', 'cu-progress-head'));
    head.appendChild(el('span', 'cu-progress-label', 'Installationsfortschritt'));
    head.appendChild(el('strong', 'cu-percent', '0 %'));
    var bar = progress.appendChild(el('div', 'cu-bar'));
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-label', 'Installationsfortschritt');
    bar.appendChild(el('span', 'cu-fill'));
    box.appendChild(el('p', 'cu-eta'));
    var reload = box.appendChild(el('button', 'cu-reload', 'Seite neu laden'));
    reload.type = 'button';
    reload.hidden = true;
    reload.addEventListener('click', function () { location.reload(); });
    // Inhaber only: the host service did not pick the request up.
    var cancel = box.appendChild(el('button', 'cu-cancel', 'Anfrage zurückziehen'));
    cancel.type = 'button';
    cancel.hidden = true;
    cancel.addEventListener('click', function () {
      cancel.disabled = true;
      request('POST', 'cancel').then(function () {
        clearTimeout(waitTimer);
        clearInterval(tickTimer);
        wait = null;
        close();
        refresh().then(scheduleIdle, scheduleIdle);
      }).catch(function () { cancel.disabled = false; });
    });
  }

  function clock(seconds) {
    var minutes = Math.floor(seconds / 60);
    var rest = seconds % 60;
    return minutes + ':' + (rest < 10 ? '0' : '') + rest;
  }

  // Takes the host's report: phase, step and percent (never going back).
  function track(data) {
    if (!data) return;
    if (data.phase) wait.phase = data.phase;
    if (data.step) wait.step = data.step;
    var percent = typeof data.percent === 'number' ? data.percent : PHASE_PERCENT[wait.phase];
    if (typeof percent === 'number') wait.percent = Math.max(wait.percent, Math.min(100, percent));
    wait.offline = false;
  }

  function paintWait() {
    if (!root || mode !== 'wait') showWait();
    var elapsed = Math.round((Date.now() - wait.since) / 1000);
    var finished = wait.phase === 'done';
    var percent = finished ? 100 : wait.percent;
    root.querySelector('.cu-phase').textContent = finished ? PHASES.done : (wait.step || PHASES[wait.phase] || PHASES.install);
    var reached = STEPS.findIndex(function (step) { return step[0] === wait.phase; });
    if (finished) reached = STEPS.length;
    root.querySelectorAll('.cu-steps li').forEach(function (item, index) {
      item.classList.toggle('is-done', index < reached);
      item.classList.toggle('is-active', index === reached);
    });
    root.querySelector('.cu-fill').style.width = percent + '%';
    root.querySelector('.cu-percent').textContent = percent + ' %';
    var bar = root.querySelector('.cu-bar');
    bar.setAttribute('aria-valuenow', String(percent));
    bar.classList.toggle('is-moving', !finished && !wait.offline);
    var eta = root.querySelector('.cu-eta');
    if (finished) eta.textContent = 'Die Seite wird neu geladen.';
    else if (wait.offline) eta.textContent = 'CoreAI startet gerade neu, die Verbindung ist kurz weg. Der Fortschritt kommt gleich zurück.';
    else eta.textContent = 'Läuft seit ' + clock(elapsed) + ' Min. Die Seite lädt danach von selbst neu.';
    root.querySelector('.cu-reload').hidden = elapsed < GIVE_UP_S;
    var stalled = !finished && wait.phase === 'queued' && last && last.update && last.update.stalled;
    if (stalled) eta.textContent = 'Der Update-Dienst auf dem Server reagiert nicht (systemctl status coreai-update.path).';
    root.querySelector('.cu-cancel').hidden = !stalled;
  }

  function finish() {
    clearTimeout(waitTimer);
    clearInterval(tickTimer);
    wait.phase = 'done';
    wait.offline = false;
    paintWait();
    setTimeout(function () { location.reload(); }, 1200);
  }

  function startWaiting(data) {
    clearTimeout(idleTimer);
    if (!wait) wait = { since: Date.now(), version: (data && data.version) || '', phase: 'queued', step: '', percent: 0, offline: false, down: false };
    track(data);
    paintWait();
    clearInterval(tickTimer);
    tickTimer = setInterval(function () { if (wait) paintWait(); }, 1000);
    clearTimeout(waitTimer);
    waitTimer = setTimeout(pollWait, POLL_WAIT_MS);
  }

  function pollWait() {
    request('GET', 'status').then(function (data) {
      last = data;
      if (data.csrf_token) csrf = data.csrf_token;
      if (data.running) {
        track(data);
        paintWait();
        waitTimer = setTimeout(pollWait, POLL_WAIT_MS);
        return;
      }
      if (data.state === 'failed' && data.owner && data.update) {
        clearInterval(tickTimer);
        wait = null;
        showWindow(data.update, true);
        renderCard();
        return;
      }
      // Done, or back on another version, or down and up again: reload.
      if (data.state === 'done' || wait.down || (wait.version && data.version !== wait.version)) {
        finish();
        return;
      }
      clearInterval(tickTimer);
      wait = null;
      close();
      scheduleIdle();
    }).catch(function () {
      // CoreAI is restarting (no answer, 502): keep the last progress and wait.
      wait.down = true;
      wait.offline = true;
      paintWait();
      waitTimer = setTimeout(pollWait, POLL_WAIT_MS);
    });
  }

  // --- Settings card (Inhaber) ---
  function renderCard() {
    var card = document.getElementById('updateCard');
    if (!card) return;
    var info = last && last.owner ? last.update : null;
    card.hidden = !info;
    if (!info) return;
    var stateText = 'CoreAI ist aktuell.';
    if (!info.installed) stateText = 'Der Update-Dienst ist auf dem Server noch nicht eingerichtet. Einmal mit dem Setup-Einzeiler aktualisieren, danach kommen Updates hierüber.';
    else if (info.running) stateText = (info.step || PHASES[info.phase] || 'Ein Update läuft.') + ' (' + (info.percent || 0) + ' %)';
    else if (info.state === 'failed') stateText = 'Letztes Update fehlgeschlagen: ' + (info.message || 'siehe Update-Protokoll.');
    else if (info.available) stateText = 'Neue Version ' + (info.latest_version || '') + ' verfügbar.';
    else if (info.check_error) stateText = 'GitHub war bei der letzten Prüfung nicht erreichbar.';
    if (info.checking) stateText += ' Prüfung läuft ...';
    else if (info.checked_at && info.installed) stateText += ' Zuletzt geprüft: ' + new Date(info.checked_at).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) + '.';
    document.getElementById('updateCardState').textContent = stateText;
    document.getElementById('updateCardCurrent').textContent = info.current_version || 'unbekannt';
    document.getElementById('updateCardLatest').textContent = info.latest_version || (info.latest_commit ? 'Commit ' + info.latest_commit : 'noch nicht geprüft');
    document.getElementById('updateCardOpen').hidden = !(info.available || info.state === 'failed') || info.running;
    document.getElementById('updateCardCheck').disabled = !info.installed || info.checking || info.running;
    var auto = document.getElementById('updateAutoEnable');
    if (auto) {
      auto.checked = info.auto_enabled === true;
      auto.disabled = !info.installed;
    }
    var hold = document.getElementById('updateAutoHint');
    if (hold) {
      var seconds = Math.max(0, Number(info.snooze_seconds) || 0);
      hold.textContent = seconds > 0
        ? '„Später“ aktiv: automatischer Start und Erinnerungsfenster pausieren noch etwa '
          + Math.ceil(seconds / 60) + ' Minuten. Manuell aktualisieren ist möglich.'
        : 'Standardmäßig aus. Wenn aktiviert, installiert CoreAI neue GitHub-Merges nach der regelmäßigen Prüfung. '
          + 'Dazu werden die Datenbank gesichert und Dienste neu gestartet.';
    }
  }

  function bindCard() {
    var check = document.getElementById('updateCardCheck');
    var open = document.getElementById('updateCardOpen');
    if (check) check.addEventListener('click', function () {
      check.disabled = true;
      request('POST', 'check').then(function () {
        document.getElementById('updateCardState').textContent = 'Prüfung läuft ...';
        setTimeout(refresh, 6000);
      }).catch(function (problem) {
        document.getElementById('updateCardState').textContent = errorText(problem.message);
        check.disabled = false;
      });
    });
    if (open) open.addEventListener('click', function () {
      if (last && last.update) showWindow(last.update, last.update.state === 'failed');
    });
    var auto = document.getElementById('updateAutoEnable');
    if (auto) auto.addEventListener('change', function () {
      var enabled = auto.checked;
      auto.disabled = true;
      request('POST', 'auto', { enabled: enabled }).then(function (data) {
        if (last && last.update) last.update.auto_enabled = data.auto_enabled === true;
        renderCard();
      }).catch(function (problem) {
        auto.checked = !enabled;
        auto.disabled = false;
        document.getElementById('updateCardState').textContent =
          'Automatische Updates konnten nicht geändert werden: ' + errorText(problem.message);
      });
    });
  }

  // --- Idle: look for a new update or a started one ---
  function apply(data) {
    last = data;
    if (data.csrf_token) csrf = data.csrf_token;
    renderCard();
    if (data.running) {
      if (mode !== 'wait') startWaiting(data);
      return;
    }
    var info = data.owner ? data.update : null;
    if (info && (info.available || info.state === 'failed')
        && !snoozed(info) && mode !== 'window') {
      showWindow(info, info.state === 'failed');
    }
  }

  function scheduleIdle() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
      if (document.hidden || mode === 'wait') return scheduleIdle();
      refresh().then(scheduleIdle, scheduleIdle);
    }, POLL_IDLE_MS);
  }

  function refresh() {
    if (mode === 'wait') return Promise.resolve(last);
    return request('GET', 'status').then(function (data) {
      apply(data);
      return data;
    }).catch(function () { return null; });
  }

  function boot() {
    bindCard();
    refresh().then(function () { if (mode !== 'wait') scheduleIdle(); });
  }

  window.CoreAIUpdate = { refresh: refresh, state: function () { return last; }, mode: function () { return mode; } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
