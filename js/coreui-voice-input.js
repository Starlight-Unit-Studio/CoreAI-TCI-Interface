/* CoreAI: TCI Interface 0.6.14: voice input (P20.1).
   A microphone button in the input field. One click records (up to 2 minutes,
   the time is shown), a second click or "Fertig" ends the recording, Core
   writes the transcript and it lands in the input field, where it can be
   checked and sent. Pure silence is never sent (a small level check in the
   browser). The server deletes the recording right after the transcript.
   Signed-in accounts only; guests never see the button. A Core that cannot
   hear keeps the button visible but switched off, with the reason. */
(function () {
  'use strict';

  var API = './api/voice_input.php';
  var MIC = 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z M6 11a6 6 0 0 0 12 0 M12 17v4 M9 21h6';
  var STOP = 'M7 7h10v10H7z';
  var LEVEL_MIN = 0.012;     // RMS above this (and above the noise floor) counts as sound
  var VOICED_MIN_MS = 300;   // less sound than this in a whole recording: nothing is sent
  var state = { allowed: false, hears: null, ready: true, maxSeconds: 120, csrf: '', checkedAt: 0 };
  var rec = null;            // the running recording
  var job = null;            // the running transcript request
  var button = null;
  var strip = null;
  var messageTimer = 0;
  var statusRetries = 0;

  function supported() {
    return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  }

  function reason() {
    if (!supported()) return 'Spracheingabe braucht eine sichere Verbindung (HTTPS) und einen aktuellen Browser.';
    if (!state.ready) return 'Spracheingabe ist auf dem Server nicht eingerichtet (ffmpeg fehlt).';
    if (state.hears === false) return 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.';
    return '';
  }

  function icon(path) {
    var node = button && button.querySelector('path');
    if (node) node.setAttribute('d', path);
  }

  function clock(seconds) {
    seconds = Math.max(0, Math.floor(seconds));
    return Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
  }

  function render() {
    if (!button) return;
    var off = reason();
    var busy = !!job;
    var text = rec ? 'Aufnahme beenden und mitschreiben' : (busy ? 'Core schreibt mit' : (off || 'Spracheingabe: Aufnahme starten'));
    button.classList.toggle('recording', !!rec);
    button.classList.toggle('working', busy);
    button.setAttribute('aria-disabled', off && !rec ? 'true' : 'false');
    button.setAttribute('aria-pressed', rec ? 'true' : 'false');
    button.title = text;
    button.setAttribute('aria-label', text);
    icon(rec ? STOP : MIC);
  }

  // The strip above the input field: recording time, transcript state or a short message.
  function show(mode, title, sub) {
    if (!strip) return;
    clearTimeout(messageTimer);
    strip.hidden = false;
    strip.setAttribute('data-mode', mode);
    strip.querySelector('.voice-input-title').textContent = title;
    strip.querySelector('.voice-input-sub').textContent = sub || '';
    strip.querySelector('.voice-input-done').hidden = mode !== 'recording';
    strip.querySelector('.voice-input-cancel').hidden = mode === 'message';
    strip.querySelector('.voice-input-close').hidden = mode !== 'message';
    if (mode === 'message') messageTimer = setTimeout(hide, 9000);
  }

  function hide() {
    clearTimeout(messageTimer);
    if (strip) strip.hidden = true;
  }

  function say(text) { show('message', text, ''); }

  function makeStrip(frame) {
    strip = document.createElement('div');
    strip.className = 'composer-voice-state';
    strip.id = 'voiceInputState';
    strip.hidden = true;
    strip.setAttribute('role', 'status');
    strip.setAttribute('aria-live', 'polite');
    strip.innerHTML = '<span class="voice-input-dot" aria-hidden="true"></span>'
      + '<span class="voice-input-copy"><strong class="voice-input-title"></strong><small class="voice-input-sub"></small></span>'
      + '<span class="voice-input-level" aria-hidden="true"><i></i></span>'
      + '<span class="voice-input-actions">'
      + '<button type="button" class="voice-input-cancel">ABBRECHEN</button>'
      + '<button type="button" class="voice-input-done">FERTIG</button>'
      + '<button type="button" class="voice-input-close" aria-label="Hinweis schließen">×</button></span>';
    frame.parentNode.insertBefore(strip, frame);
    strip.querySelector('.voice-input-cancel').addEventListener('click', cancel);
    strip.querySelector('.voice-input-done').addEventListener('click', function () { stop(true); });
    strip.querySelector('.voice-input-close').addEventListener('click', hide);
  }

  function makeButton() {
    var send = document.getElementById('btnSend');
    var inner = send && send.parentNode;
    var frame = document.querySelector('.composer-frame');
    if (!send || !inner || !frame || document.getElementById('btnMic')) return;
    button = document.createElement('button');
    button.type = 'button';
    button.id = 'btnMic';
    button.className = 'composer-mic-btn';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', MIC);
    svg.appendChild(path);
    button.appendChild(svg);
    inner.insertBefore(button, send);
    inner.classList.add('has-mic');
    makeStrip(frame);
    button.addEventListener('click', toggle);
    render();
  }

  function loadStatus() {
    return fetch(API + '?action=status', { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        statusRetries = 0;
        state.checkedAt = Date.now();
        state.allowed = !!(data && data.ok && data.allowed);
        if (!state.allowed) return;
        state.hears = data.hears === true ? true : (data.hears === false ? false : null);
        state.ready = data.ready !== false;
        state.maxSeconds = Math.max(10, Math.min(600, parseInt(data.max_seconds, 10) || 120));
        state.csrf = data.csrf_token || state.csrf;
        makeButton();
        render();
      })
      .catch(function (error) {
        // 0.6.15: without a status there is no button (guests must never see it), but the
        // reason goes to the console and the page asks again, at most three times.
        if (window.console && console.warn) {
          console.warn('CoreAI Spracheingabe: Status nicht lesbar (' + ((error && error.message) || error) + ').');
        }
        if (statusRetries < 3) {
          statusRetries++;
          setTimeout(loadStatus, 60000);
        }
      });
  }

  function pickType() {
    var types = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function toggle() {
    if (rec) { stop(true); return; }
    if (job) { say('Core schreibt noch mit.'); return; }
    var off = reason();
    if (off) { say(off); return; }
    if (Date.now() - state.checkedAt > 60000) loadStatus();
    start();
  }

  function start() {
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } })
      .then(function (stream) {
        var type = pickType();
        var recorder;
        try {
          recorder = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
        } catch (e) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          say('Dieser Browser kann nicht aufnehmen.');
          return;
        }
        var current = { stream: stream, recorder: recorder, chunks: [], started: Date.now(), voicedMs: 0, floor: 1, send: false,
          type: recorder.mimeType || type || 'audio/webm', timer: 0, audio: null };
        rec = current;
        recorder.addEventListener('dataavailable', function (event) { if (event.data && event.data.size) current.chunks.push(event.data); });
        recorder.addEventListener('stop', finish);
        recorder.start(1000);
        listen(rec);
        rec.timer = setInterval(tick, 100);
        tick();
        render();
      })
      .catch(function (error) {
        var name = error && error.name;
        say(name === 'NotAllowedError' || name === 'SecurityError'
          ? 'Mikrofon nicht erlaubt. Erlaube den Zugriff über das Schloss-Symbol in der Adresszeile.'
          : (name === 'NotFoundError' ? 'Kein Mikrofon gefunden.' : 'Das Mikrofon ließ sich nicht öffnen.'));
      });
  }

  // Level check in the browser: how long was there sound above the noise floor?
  function listen(current) {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) { current.voicedMs = VOICED_MIN_MS; return; }
    try {
      var ctx = new Ctx();
      var source = ctx.createMediaStreamSource(current.stream);
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 1024;
      source.connect(analyser);
      current.audio = { ctx: ctx, analyser: analyser, data: new Float32Array(analyser.fftSize), last: Date.now() };
    } catch (e) {
      current.voicedMs = VOICED_MIN_MS;
    }
  }

  function level(current) {
    var a = current.audio;
    if (!a) return 0;
    a.analyser.getFloatTimeDomainData(a.data);
    var sum = 0;
    for (var i = 0; i < a.data.length; i++) sum += a.data[i] * a.data[i];
    var rms = Math.sqrt(sum / a.data.length);
    var now = Date.now();
    var step = Math.min(500, now - a.last);
    a.last = now;
    current.floor = Math.min(current.floor * 1.002 + 0.00005, Math.max(rms, 0.0005));
    if (rms > LEVEL_MIN && rms > current.floor * 3) current.voicedMs += step;
    return rms;
  }

  function tick() {
    if (!rec) return;
    var seconds = (Date.now() - rec.started) / 1000;
    var rms = level(rec);
    show('recording', 'AUFNAHME ' + clock(seconds) + ' / ' + clock(state.maxSeconds),
      'Sprich jetzt. Fertig oder Mikrofon-Knopf beendet die Aufnahme.');
    var bar = strip && strip.querySelector('.voice-input-level i');
    if (bar) bar.style.width = Math.min(100, Math.round(rms * 600)) + '%';
    if (seconds >= state.maxSeconds) stop(true);
  }

  function release(current) {
    clearInterval(current.timer);
    current.stream.getTracks().forEach(function (t) { t.stop(); });
    if (current.audio && current.audio.ctx && current.audio.ctx.close) current.audio.ctx.close().catch(function () {});
  }

  function stop(send) {
    if (!rec) return;
    rec.send = !!send;
    level(rec);
    if (rec.recorder.state !== 'inactive') rec.recorder.stop(); else finish();
  }

  function finish() {
    var current = rec;
    if (!current) return;
    rec = null;
    release(current);
    render();
    if (!current.send) { hide(); return; }
    if (current.voicedMs < VOICED_MIN_MS) {
      say('Ich habe nichts gehört. Bitte noch einmal und etwas näher am Mikrofon sprechen.');
      return;
    }
    var blob = new Blob(current.chunks, { type: current.type });
    if (!blob.size) { say('Die Aufnahme ist leer.'); return; }
    upload(blob, current.type, (Date.now() - current.started) / 1000);
  }

  function upload(blob, type, seconds) {
    var ext = type.indexOf('mp4') >= 0 ? 'm4a' : (type.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
    var form = new FormData();
    form.append('audio', blob, 'aufnahme.' + ext);
    var controller = window.AbortController ? new AbortController() : null;
    job = { controller: controller };
    render();
    show('working', 'CORE SCHREIBT MIT …', 'Aufnahme ' + clock(seconds) + '. Etwa 20 Sekunden je Minute.');
    fetch(API + '?action=transcribe', {
      method: 'POST', credentials: 'include', cache: 'no-store', body: form,
      headers: { 'Accept': 'application/json', 'X-CoreUI-CSRF': state.csrf },
      signal: controller ? controller.signal : undefined
    })
      .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        job = null;
        render();
        if (data && data.ok && data.text) {
          insert(String(data.text));
          if (data.truncated) say('Nur die ersten ' + clock(state.maxSeconds) + ' Minuten wurden mitgeschrieben.');
          else hide();
          return;
        }
        if (data && data.error === 'core_without_audio') { state.hears = false; render(); }
        say((data && data.message) || 'Core konnte die Aufnahme nicht mitschreiben.');
      })
      .catch(function (error) {
        job = null;
        render();
        if (error && error.name === 'AbortError') { hide(); return; }
        say('Keine Verbindung zum Server. Bitte noch einmal versuchen.');
      });
  }

  // The transcript goes where the cursor is; the person checks it and sends.
  function insert(text) {
    var input = document.getElementById('inputMessage');
    if (!input) return;
    var value = input.value;
    var at = typeof input.selectionStart === 'number' && document.activeElement === input ? input.selectionStart : value.length;
    var end = typeof input.selectionEnd === 'number' && document.activeElement === input ? input.selectionEnd : value.length;
    var before = value.slice(0, at);
    var after = value.slice(end);
    var lead = before && !/\s$/.test(before) ? ' ' : '';
    var tail = after && !/^\s/.test(after) ? ' ' : '';
    input.value = before + lead + text + tail + after;
    var caret = (before + lead + text).length;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
    try { input.setSelectionRange(caret, caret); } catch (e) { /* not every input type allows it */ }
  }

  function cancel() {
    if (rec) { stop(false); return; }
    if (job && job.controller) job.controller.abort();
    job = null;
    render();
    hide();
  }

  window.addEventListener('coreui:core', function () { if (state.allowed) loadStatus(); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && (rec || job)) cancel();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadStatus);
  else loadStatus();
})();
