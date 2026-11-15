/* Owner-only image A/B diagnosis. Entirely inside CoreAI Admin Core:
 * a fixed 512px prompt, serial Qwen3 vs Ponpoke runs; never edits global pGen.
 */
(function () {
  'use strict';
  var card = document.getElementById('imageAbCard');
  if (!card) return;
  var apiRoot = window.EMBER_COREUI && window.EMBER_COREUI.apiUrl
    ? window.EMBER_COREUI.apiUrl : new URL('../api/', document.baseURI).href;
  var endpoint = new URL('admin.php', apiRoot).href;
  var csrf = '';
  var busy = false;
  var timer = 0;
  var loaded = false;
  var last = null;
  var start = document.getElementById('imageAbStart');
  var cancel = document.getElementById('imageAbCancel');
  var reload = document.getElementById('imageAbReload');
  var status = document.getElementById('imageAbStatus');
  var verdict = document.getElementById('imageAbVerdict');
  var labels = {
    not_started: 'Noch nicht gestartet',
    running: 'Bildberechnung läuft',
    success: 'Bild erfolgreich erzeugt und PNG geprüft',
    failed: 'Bildberechnung fehlgeschlagen',
    unavailable: 'Test nicht ausführbar',
    cancelled: 'Vom Inhaber abgebrochen'
  };
  var phases = {
    load: 'Gewichte laden', text: 'Prompt kodieren',
    sampling: 'Bild berechnen', decode: 'Pixel dekodieren',
    save: 'Bild speichern', done: 'Bild abgeschlossen'
  };
  var failures = {
    image_busy: 'Der Bilddienst ist bereits beschäftigt.',
    image_unavailable: 'Bilddienst nicht bereit.',
    image_loading: 'Modellgewichte werden noch geladen.',
    image_memory: 'Nicht genügend freier RAM.',
    image_timeout: 'Bildauftrag überschritt das Zeitlimit.',
    image_worker_timeout: 'Der Bildprozess überschritt sein Zeitlimit.',
    image_worker_exit: 'Der sd-cli-Bildprozess wurde mit Fehler beendet.',
    image_worker_exception: 'Der Bildprozess meldete eine technische Ausnahme.',
    image_png_missing: 'Kein PNG erzeugt.',
    image_png_invalid: 'PNG-Datei ist ungültig.',
    diagnostic_png_invalid: 'Bild konnte nicht als 512 × 512 PNG bestätigt werden.',
    diagnostic_worker_unreachable: 'Bilddienst während des Tests nicht erreichbar.',
    diagnostic_job_lost: 'Der Bildauftrag ist nicht mehr vorhanden.',
    diagnostic_timeout: 'Testzeit überschritten, Auftrag beendet.',
    diagnostic_encoder_mismatch: 'Der Worker verwendet nicht den angeforderten Encoder.',
    diagnostic_model_mismatch: 'Der Worker verwendet nicht das festgelegte 4B-Modell.',
    ponpoke_not_ready: 'Ponpoke 4B Q8_0 ist nicht installiert beziehungsweise nicht bereit.',
    license_accept_required: 'Ponpoke erfordert die bestätigte FLUX-Lizenz dieses Kontos.',
    image_failed: 'Bildgenerierung fehlgeschlagen.',
    cancelled: 'Auftrag abgebrochen.'
  };
  var verdicts = {
    pending: '',
    both_passed: 'Beide Encoder funktionieren mit dem identischen FLUX.2-4B-Generator. Bitte anschließend die normale Chat-Ansteuerung prüfen.',
    ponpoke_path_suspected: 'Qwen3 funktioniert, Ponpoke scheitert. Der Fehler liegt wahrscheinlich im Ponpoke-Pfad oder in dessen Kompatibilität.',
    qwen_path_failed: 'Ponpoke funktioniert, der ursprüngliche Qwen-Pfad scheitert. Der Qwen-Pfad muss geprüft werden.',
    both_failed: 'Beide Bildberechnungen sind fehlgeschlagen. Wahrscheinlich betrifft das Problem gemeinsame Komponenten oder die Serverressourcen.',
    incomplete: 'Der Vergleich ist unvollständig. Details zu den einzelnen Tests stehen darüber.'
  };
  function message(error) {
    return failures[error] || ({
      diagnostic_already_running: 'Ein A/B-Test läuft bereits.',
      diagnostic_request_busy: 'Eine andere Diagnoseabfrage ist gerade aktiv.',
      diagnostic_storage_unavailable: 'Der Diagnose-Status kann nicht gespeichert werden.'
    }[error] || error || 'Unbekannter Fehler');
  }
  function call(action, method) {
    var options = {method: method || 'GET', credentials:'include', cache:'no-store',
      headers: {'Accept':'application/json'}};
    if (method === 'POST') {
      options.headers['X-CoreUI-CSRF'] = csrf;
      options.headers['Content-Type'] = 'application/json';
      options.body = '{}';
    }
    return fetch(endpoint + '?action=' + encodeURIComponent(action), options)
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data.ok) {
            var error = new Error(data.error || 'HTTP_' + response.status);
            error.status = response.status;
            throw error;
          }
          return data;
        });
      });
  }
  function caseText(entry) {
    entry = entry || {};
    var parts = [labels[entry.state] || labels.not_started];
    if (entry.phase && phases[entry.phase] && entry.state === 'running') {
      var detail = 'Phase: ' + phases[entry.phase];
      if (entry.phase === 'sampling' && Number(entry.steps) > 0) {
        detail += ', Schritt ' + Number(entry.step || 0) + '/' + Number(entry.steps);
      }
      parts.push(detail);
    }
    if (entry.encoder) parts.push('Encoder: ' + (entry.encoder === 'original' ? 'Qwen3' : 'Ponpoke Q8_0'));
    if (entry.seconds != null) parts.push('Laufzeit: ' + entry.seconds + ' s');
    if (entry.peak_rss_mb != null) parts.push('RAM-Spitze: ' +
      (Number(entry.peak_rss_mb) / 1024).toFixed(1).replace('.', ',') + ' GiB');
    if (entry.error_code) parts.push(message(entry.error_code)
      + (entry.exit_code != null ? ' Exitcode ' + entry.exit_code + '.' : '')
      + (entry.error_type ? ' Typ: ' + entry.error_type + '.' : ''));
    return parts.join(' · ');
  }
  function clearTimer() {
    if (timer) clearTimeout(timer);
    timer = 0;
  }
  function render(data) {
    last = data;
    card.hidden = false;
    loaded = true;
    var running = data.state === 'running';
    start.disabled = busy || running;
    cancel.hidden = !running;
    cancel.disabled = busy;
    reload.disabled = busy;
    document.getElementById('imageAbQwenState').textContent = caseText(data.cases && data.cases.qwen);
    document.getElementById('imageAbPonpokeState').textContent = caseText(data.cases && data.cases.ponpoke);
    document.getElementById('imageAbQwen').classList.toggle('failed',
      !!(data.cases && data.cases.qwen && data.cases.qwen.state === 'failed'));
    document.getElementById('imageAbPonpoke').classList.toggle('failed',
      !!(data.cases && data.cases.ponpoke && data.cases.ponpoke.state === 'failed'));
    var mode = data.state === 'idle' ? 'Noch kein A/B-Test durchgeführt.' :
      running ? (data.active === 'qwen' ? 'Test A läuft: Qwen3-4B.' : 'Test B läuft: Ponpoke Uncensored.') :
      data.state === 'cancelled' ? 'Test abgebrochen.' : 'Vergleich abgeschlossen.';
    status.textContent = mode + (running ? ' Das Smartphone kann die Seite verlassen; beim Zurückkehren wird der Lauf fortgesetzt.' : '');
    verdict.textContent = verdicts[data.comparison] || '';
    clearTimer();
    if (running && !busy) timer = setTimeout(function () { tick(); }, 3000);
  }
  function fail(error) {
    if (error.status === 401 || error.status === 403) {
      card.hidden = true;
      clearTimer();
    } else {
      card.hidden = false;
      status.textContent = 'A/B-Diagnose: ' + message(error.message);
      if (last && last.state === 'running') {
        clearTimer();
        timer = setTimeout(function () { tick(); }, 7000);
      }
    }
  }
  function request(action, method) {
    if (busy) return Promise.resolve();
    busy = true;
    start.disabled = true;
    cancel.disabled = true;
    reload.disabled = true;
    clearTimer();
    return call(action, method).then(function (data) {
      if (data.csrf_token) csrf = data.csrf_token;
      busy = false;
      render(data);
    }).catch(function (error) {
      busy = false;
      start.disabled = last ? last.state === 'running' : true;
      reload.disabled = false;
      cancel.disabled = false;
      fail(error);
    });
  }
  function tick() { return request('image_ab_poll','POST'); }
  function load() {
    return request('image_ab_status','GET').then(function () {
      if (last && last.state === 'running') {
        // The next scheduled POST poll advances the persistent state.
      }
    });
  }
  start.addEventListener('click', function () {
    if (!csrf || busy) return;
    status.textContent = 'Test A wird gestartet.';
    request('image_ab_start','POST');
  });
  cancel.addEventListener('click', function () {
    if (!csrf || busy) return;
    request('image_ab_cancel','POST');
  });
  reload.addEventListener('click', function () { load(); });
  document.addEventListener('coreai:view', function (event) {
    var view = event && event.detail ? event.detail.view : '';
    if (view === 'admin-betrieb') load();
    else clearTimer();
  });
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (tabs && typeof tabs.current === 'function' && tabs.current() === 'admin-betrieb') load();
})();
