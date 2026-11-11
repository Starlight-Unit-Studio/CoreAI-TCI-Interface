/* CoreAI: TCI Interface 0.6.17: voice messages (P20.1).
   A microphone button in the input field. One click records (up to 2 minutes,
   the time is shown), a second click or "Fertig" sends the recording at once
   as a voice message, together with whatever is already in the input field.
   Mara hears the WAV herself on the server (Gemma's audio input); no
   transcript has to be checked first. Pure silence is never sent (a small
   level check in the browser). After Mara's answer the server writes a
   transcript for the history; it appears small under the voice message.
   Signed-in accounts only; guests never see the button. A Core that cannot
   hear keeps the button visible but switched off, with the reason.
   0.7.14 (Settings > Stimme): a second button for dictation (on unless switched off there):
   the Core writes what was said and the text goes into the input field to be checked
   before sending. Every recording is also kept in this browser (the last 20, see
   js/coreui-recordings.js), so a Stimmsequenz that could not be sent is not lost. */
(function () {
  'use strict';

  var API = './api/voice_input.php';
  var DICTATE_API = './api/voice_dictate.php';
  var MIC = 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z M6 11a6 6 0 0 0 12 0 M12 17v4 M9 21h6';
  var STOP = 'M7 7h10v10H7z';
  // 0.7.14: lines of text with a small microphone: dictation into the input field.
  var DICTATE = 'M4 6h8 M4 11h6 M4 16h5 M17 4.5a2.5 2.5 0 0 0-2.5 2.5v4a2.5 2.5 0 0 0 5 0V7a2.5 2.5 0 0 0-2.5-2.5z M13 11a4 4 0 0 0 8 0 M17 15v4 M15 19h4';
  var WHERE = 'Sie liegt unter Einstellungen > Stimme > Zuletzt verwendete Aufnahmen.';
  var LEVEL_MIN = 0.012;     // RMS above this (and above the noise floor) counts as sound
  var VOICED_MIN_MS = 300;   // less sound than this in a whole recording: nothing is sent
  var state = { allowed: false, hears: null, ready: true, maxSeconds: 120, csrf: '', checkedAt: 0, dictation: false, owner: '' };
  var rec = null;            // the running recording
  var job = null;            // a finished recording that waits until Mara has answered
  var button = null;
  var dictateButton = null;  // 0.7.14
  var dictating = null;      // 0.7.14: a dictation the Core is writing ({controller})
  var transcripts = {};      // uuid -> answer of the transcript endpoint
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

  function icon(target, path) {
    var node = target && target.querySelector('path');
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
    var voiceRec = !!rec && rec.mode !== 'dictate';
    var dictRec = !!rec && rec.mode === 'dictate';
    var text = voiceRec ? 'Stimmsequenz beenden und senden' : (busy ? 'Stimmsequenz wartet auf deine CoreAI' : (off || 'Stimmsequenz aufnehmen'));
    button.classList.toggle('recording', voiceRec);
    button.classList.toggle('working', busy);
    button.setAttribute('aria-disabled', (off && !voiceRec) || dictRec ? 'true' : 'false');
    button.setAttribute('aria-pressed', voiceRec ? 'true' : 'false');
    button.title = text;
    button.setAttribute('aria-label', text);
    icon(button, voiceRec ? STOP : MIC);
    if (!dictateButton) return;
    var dtext = dictRec ? 'Diktat beenden' : (dictating ? 'Ich schreibe dein Diktat mit' : (off || 'Diktieren: Text ins Eingabefeld sprechen'));
    dictateButton.classList.toggle('recording', dictRec);
    dictateButton.classList.toggle('working', !!dictating);
    dictateButton.setAttribute('aria-disabled', (off && !dictRec) || voiceRec ? 'true' : 'false');
    dictateButton.setAttribute('aria-pressed', dictRec ? 'true' : 'false');
    dictateButton.title = dtext;
    dictateButton.setAttribute('aria-label', dtext);
    icon(dictateButton, dictRec ? STOP : DICTATE);
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

  function iconButton(id, className, path) {
    var node = document.createElement('button');
    node.type = 'button';
    node.id = id;
    node.className = className;
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    line.setAttribute('d', path);
    svg.appendChild(line);
    node.appendChild(svg);
    return node;
  }

  function makeButton() {
    var send = document.getElementById('btnSend');
    var inner = send && send.parentNode;
    var frame = document.querySelector('.composer-frame');
    if (!send || !inner || !frame || document.getElementById('btnMic')) return;
    button = iconButton('btnMic', 'composer-mic-btn', MIC);
    inner.insertBefore(button, send);
    inner.classList.add('has-mic');
    makeStrip(frame);
    button.addEventListener('click', function () { toggle('voice'); });
    render();
  }

  // 0.7.14: the dictation button, before the microphone; only while dictation is on (Settings > Stimme).
  function syncDictateButton() {
    var inner = button && button.parentNode;
    if (!inner) return;
    if (state.dictation && !dictateButton) {
      dictateButton = iconButton('btnDictate', 'composer-mic-btn composer-dictate-btn', DICTATE);
      inner.insertBefore(dictateButton, button);
      dictateButton.addEventListener('click', function () { toggle('dictate'); });
    } else if (!state.dictation && dictateButton && !(rec && rec.mode === 'dictate') && !dictating) {
      dictateButton.remove();
      dictateButton = null;
    }
    inner.classList.toggle('has-dictate', !!dictateButton);
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
        state.dictation = data.dictation !== false;
        state.owner = typeof data.recordings_key === 'string' ? data.recordings_key : '';
        makeButton();
        syncDictateButton();
        render();
        bindTranscripts(document);
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

  function toggle(mode) {
    if (rec) {
      if (rec.mode === mode) stop(true);
      else say(rec.mode === 'dictate' ? 'Beende zuerst dein Diktat.' : 'Beende zuerst deine Stimmsequenz.');
      return;
    }
    if (mode === 'dictate' && dictating) { say('Ich schreibe dein letztes Diktat noch mit.'); return; }
    if (mode !== 'dictate' && job) { say('Deine letzte Stimmsequenz wartet noch, bis deine CoreAI ihre Antwort beendet hat.'); return; }
    var off = reason();
    if (off) { say(off); return; }
    if (Date.now() - state.checkedAt > 60000) loadStatus();
    start(mode === 'dictate' ? 'dictate' : 'voice');
  }

  /* 0.6.22: the speech as it is, for Core's ear. The browser's noise suppression can
     smooth away soft consonants ("Gladbach" or "glatt"), and speech recognition
     generally does better on unfiltered audio. Echo cancellation stays (Eve may be
     reading aloud), the level control too (phone microphones are quiet). Opus at a
     fixed 64 kbit/s, plenty for speech, instead of leaving it to the browser. */
  var CAPTURE = { echoCancellation: true, noiseSuppression: false, autoGainControl: true, channelCount: 1 };
  var BITRATE = 64000;

  function start(mode) {
    navigator.mediaDevices.getUserMedia({ audio: CAPTURE })
      .then(function (stream) {
        var type = pickType();
        var recorder;
        try {
          var options = { audioBitsPerSecond: BITRATE };
          if (type) options.mimeType = type;
          recorder = new MediaRecorder(stream, options);
        } catch (e) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          say('Dieser Browser kann nicht aufnehmen.');
          return;
        }
        var current = { stream: stream, recorder: recorder, chunks: [], started: Date.now(), voicedMs: 0, floor: 1, send: false,
          type: recorder.mimeType || type || 'audio/webm', timer: 0, audio: null, mode: mode };
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
    if (rec.mode === 'dictate') {
      show('recording', 'DIKTAT ' + clock(seconds) + ' / ' + clock(state.maxSeconds),
        'Sprich jetzt. Mit „Fertig“ schreibe ich deine Worte ins Eingabefeld.');
    } else {
      show('recording', 'STIMMSEQUENZ ' + clock(seconds) + ' / ' + clock(state.maxSeconds),
        'Sprich jetzt. Mit „Fertig“ oder dem Mikrofonknopf beendest du die Stimmsequenz.');
    }
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
    if (!blob.size) { say(current.mode === 'dictate' ? 'Das Diktat ist leer.' : 'Die Stimmsequenz ist leer.'); return; }
    var seconds = Math.round((Date.now() - current.started) / 1000);
    if (current.mode === 'dictate') {
      keep({ blob: blob, kind: 'diktat', seconds: seconds }).then(function (id) { dictate(blob, id); });
      return;
    }
    var ext = current.type.indexOf('mp4') >= 0 ? 'm4a' : (current.type.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
    var stamp = new Date();
    var pad = function (n) { return ('0' + n).slice(-2); };
    var name = 'Sprachnachricht-' + stamp.getFullYear() + '-' + pad(stamp.getMonth() + 1) + '-' + pad(stamp.getDate())
      + '_' + pad(stamp.getHours()) + '-' + pad(stamp.getMinutes()) + '-' + pad(stamp.getSeconds()) + '.' + ext;
    var file;
    try { file = new File([blob], name, { type: current.type.split(';')[0] }); } catch (e) { file = blob; file.name = name; }
    keep({ blob: blob, kind: 'stimmsequenz', seconds: seconds }).then(function (id) { send(file, 0, id); });
  }

  // 0.7.14: every recording stays in this browser (the last 20); a failure never loses it.
  function keep(item) {
    var store = window.CoreAIRecordings;
    if (!store || !state.owner) return Promise.resolve('');
    return store.add(state.owner, item).then(function (record) { return record ? record.id : ''; }).catch(function () { return ''; });
  }

  function note(id, changes) {
    if (id && window.CoreAIRecordings && state.owner) window.CoreAIRecordings.update(state.owner, id, changes).catch(function () {});
  }

  // 0.7.14: dictation. The Core writes what was said; the text goes into the input field at the caret.
  function dictate(blob, id) {
    var controller = window.AbortController ? new AbortController() : null;
    dictating = { controller: controller };
    render();
    show('waiting', 'DIKTAT WIRD MITGESCHRIEBEN', 'Ich schreibe deine Worte mit. Das dauert einen Moment.');
    var form = new FormData();
    form.append('audio', blob, 'diktat.' + (blob.type.indexOf('mp4') >= 0 ? 'm4a' : (blob.type.indexOf('ogg') >= 0 ? 'ogg' : 'webm')));
    fetch(DICTATE_API, { method: 'POST', credentials: 'include', cache: 'no-store', body: form,
      headers: { 'Accept': 'application/json', 'X-CoreUI-CSRF': state.csrf }, signal: controller ? controller.signal : undefined })
      .then(function (response) { return response.json().catch(function () { return {}; }); })
      .then(function (data) {
        dictating = null;
        render();
        if (data && data.ok && String(data.text || '').trim()) {
          insert(String(data.text).trim());
          note(id, { text: String(data.text).trim(), state: 'mitgeschrieben' });
          hide();
          return;
        }
        var code = data && data.error;
        note(id, { state: 'nicht mitgeschrieben' });
        say(code === 'no_speech' || code === 'empty_audio' || code === 'missed' || (data && data.ok)
          ? 'Ich habe im Diktat keine Worte erkannt. ' + WHERE
          : (code === 'core_without_audio' ? 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.'
          : (code === 'dictation_off' ? 'Die Diktierfunktion ist unter Einstellungen > Stimme ausgeschaltet.'
          : (code === 'queue_timeout' ? 'Ich bin gerade zu beschäftigt, um dein Diktat mitzuschreiben. ' + WHERE
          : 'Ich konnte dein Diktat nicht mitschreiben. ' + WHERE))));
      })
      .catch(function (error) {
        dictating = null;
        render();
        note(id, { state: 'nicht mitgeschrieben' });
        if (error && error.name === 'AbortError') { hide(); return; }
        say('Ich konnte dein Diktat nicht mitschreiben. ' + WHERE);
      });
  }

  function insert(text) {
    var field = document.getElementById('inputMessage');
    if (!field) return;
    var value = String(field.value || '');
    // The field keeps its caret while the dictation button has the focus: the text goes where the caret was.
    var at = typeof field.selectionStart === 'number' ? Math.min(field.selectionStart, value.length) : value.length;
    var end = typeof field.selectionEnd === 'number' ? Math.max(at, Math.min(field.selectionEnd, value.length)) : at;
    var before = value.slice(0, at);
    var after = value.slice(end);
    var piece = (before && !/\s$/.test(before) ? ' ' : '') + text + (after && !/^\s/.test(after) ? ' ' : '');
    field.value = before + piece + after;
    var caret = (before + piece).length;
    field.focus();
    try { field.setSelectionRange(caret, caret); } catch (e) { /* not every input type */ }
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // Into the chat as a voice message. While Mara is still answering, the
  // recording waits (up to five minutes) instead of being thrown away.
  function send(file, tries, id) {
    var composer = window.CoreUIComposer;
    var result = composer && composer.sendVoice ? composer.sendVoice(file) : 'unavailable';
    if (result === 'ok') { job = null; render(); hide(); note(id, { state: 'gesendet' }); return; }
    if (result === 'busy' && tries < 300) {
      job = { file: file, id: id, timer: setTimeout(function () { send(file, tries + 1, id); }, 1000) };
      render();
      if (tries === 0) show('waiting', 'STIMMSEQUENZ WARTET', 'Ich sende sie, sobald ich meine vorherige Antwort abgeschlossen habe.');
      return;
    }
    job = null;
    render();
    note(id, { state: 'nicht gesendet' });
    var kept = id ? ' ' + WHERE : '';
    say(result === 'full' ? 'Es sind schon zehn Anhänge ausgewählt. Bitte erst einen entfernen.' + kept
      : (result === 'editing' ? 'Während du eine Nachricht bearbeitest, kannst du keine Stimmsequenz senden.' + kept
      : (result === 'busy' ? 'Ich bin noch mit einer Antwort beschäftigt. Deine Stimmsequenz wurde nicht gesendet.' + kept
      : 'Die Stimmsequenz ließ sich nicht senden.' + kept)));
  }

  // The transcript, written on the server after Mara's answer, small under the
  // voice message; while it is still being written the page asks again.
  function bindTranscripts(root) {
    if (!state.allowed) return;
    // 0.6.35: videos too: what was said in their sound track.
    var players = (root && root.querySelectorAll ? root : document).querySelectorAll('audio.msg-audio[data-uuid], video.msg-video[data-uuid]');
    Array.prototype.forEach.call(players, function (player) {
      if (player.getAttribute('data-transcript') === 'bound') return;
      player.setAttribute('data-transcript', 'bound');
      var line = document.createElement('div');
      line.className = 'msg-transcript';
      line.hidden = true;
      player.parentNode.insertBefore(line, player.nextSibling);
      loadTranscript(player.getAttribute('data-uuid'), line, 0);
    });
  }

  function showTranscript(line, data) {
    var text = data && data.state === 'done' ? String(data.text || '').trim() : '';
    if (text) {
      line.hidden = false;
      line.setAttribute('data-state', 'done');
      line.textContent = 'Mitschrift: ' + text;
    } else if (data && data.state === 'silent') {
      line.hidden = false;
      line.setAttribute('data-state', 'silent');
      line.textContent = 'Keine gesprochenen Worte.';
    } else if (data && data.state === 'pending') {
      line.hidden = false;
      line.setAttribute('data-state', 'pending');
      line.textContent = 'Mitschrift folgt …';
    } else {
      line.hidden = true;
    }
  }

  function loadTranscript(uuid, line, tries) {
    if (!uuid || !line.isConnected && tries > 0) return;
    var known = transcripts[uuid];
    if (known && known.state !== 'pending') { showTranscript(line, known); return; }
    fetch(API + '?action=transcript&uuid=' + encodeURIComponent(uuid), { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) { line.hidden = true; return; }
        transcripts[uuid] = data;
        showTranscript(line, data);
        if (data.state === 'pending' && tries < 150) setTimeout(function () { loadTranscript(uuid, line, tries + 1); }, 6000);
      })
      .catch(function () { line.hidden = true; });
  }

  function cancel() {
    if (rec) { stop(false); return; }
    if (dictating) {
      if (dictating.controller) dictating.controller.abort();
      return;
    }
    if (job && job.timer) clearTimeout(job.timer);
    if (job) note(job.id, { state: 'nicht gesendet' });
    job = null;
    render();
    hide();
  }

  window.addEventListener('coreui:core', function () { if (state.allowed) loadStatus(); });
  // Chat rows are drawn and redrawn by console-app.js; new voice messages get their line.
  if (window.MutationObserver) {
    new MutationObserver(function (records) {
      if (!state.allowed) return;
      for (var i = 0; i < records.length; i++) {
        for (var j = 0; j < records[i].addedNodes.length; j++) {
          var node = records[i].addedNodes[j];
          if (node.nodeType === 1) bindTranscripts(node.matches && node.matches('audio.msg-audio, video.msg-video') ? node.parentNode : node);
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && (rec || job || dictating)) cancel();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadStatus);
  else loadStatus();
})();
