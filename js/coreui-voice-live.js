/* CoreAI: TCI Interface 0.6.31 (P20.4): the live conversation with Mara.
   A live button next to the microphone in the input row opens a full-screen view:
   greeting with the account's name, a living element that shows whether I listen, think
   or speak, and buttons to mute the microphone and to end. Hands-free: a pause of about
   0.9 s ends the person's round; speaking while I talk or think interrupts me.
   Each round: the recording (WAV, with 0.4 s before the first word) is uploaded like a
   Stimmsequenz, api/voice_live.php writes the transcript, keeps both sides as normal chat
   messages and streams my answer sentence by sentence; Eve reads each sentence as soon as
   it is there. Signed-in accounts only. Mara Normal answers live (operator, 06.11.2026).
   0.6.32 (operator after the first test): the parts Eve reads are fetched ahead, one after
   the other, while the part before is playing (before, each part was only computed when the
   one before had ended: pauses after commas and between sentences); Mara Schnell writes the
   transcript when both Cores fit into memory; a pause of 0.7 s ends the round; the live
   button sits in the place of the send button while the input row is empty. */
(function () {
  'use strict';

  var API = './api/voice_live.php';
  var UPLOAD = './api/console_upload.php';
  var AUDIO = './api/voice.php?action=audio';
  var WAVE = 'M5 10v4 M9 6v12 M13 8v8 M17 10v4';
  var MIC = 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z M5 11a7 7 0 0 0 14 0 M12 18v3';
  var MIC_OFF = 'M3 3l18 18 M9 9v3a3 3 0 0 0 5.1 2.1 M15 9.3V6a3 3 0 0 0-5.9-.8 M5 11a7 7 0 0 0 11.7 5.2 M19 11a7 7 0 0 1-.6 2.8 M12 18v3';
  var CLOSE = 'M6 6l12 12 M18 6L6 18';
  var VAD = { preRollMs: 400, startMs: 130, bargeMs: 320, endSilenceMs: 700, minVoicedMs: 300, maxMs: 58000 };
  var TEXT = {
    preparing: 'Ich mache mich bereit …',
    listening: 'Ich höre zu',
    hearing: 'Ich höre dich',
    thinking: 'Ich denke nach',
    speaking: 'Ich spreche',
    muted: 'Mikrofon stumm. Tippe auf das Mikrofon, um weiterzusprechen.',
    silence: 'Ich habe gerade nichts verstanden. Sprich einfach noch einmal.',
    denied: 'Ich bekomme kein Mikrofon. Erlaube es im Browser und starte das Live-Gespräch neu.',
    failed: 'Das hat gerade nicht geklappt. Sprich einfach noch einmal.',
    noChat: 'Öffne zuerst einen Chat, dann starte das Live-Gespräch.',
    busy: 'Ich antworte gerade noch im Chat. Starte das Live-Gespräch danach.',
    noVoice: 'Meine Stimme ist gerade nicht erreichbar; ich antworte hier als Text.'
  };
  var status = { checked: false, allowed: false, hears: null, ready: true, voice: false, name: '', csrf: '', core: '' };
  var live = null;
  var button = null;

  function supported() {
    return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      && (window.AudioContext || window.webkitAudioContext) && window.fetch && window.TextDecoder);
  }

  function svgIcon(path, className) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    if (className) svg.setAttribute('class', className);
    var node = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    node.setAttribute('d', path);
    svg.appendChild(node);
    return svg;
  }

  function reason() {
    if (!supported()) return 'Das Live-Gespräch braucht eine sichere Verbindung (HTTPS) und einen aktuellen Browser.';
    if (!status.ready) return 'Das Live-Gespräch ist auf dem Server nicht eingerichtet (ffmpeg fehlt).';
    if (status.hears === false) return 'Der Core für das Live-Gespräch unterstützt keine Audioeingabe.';
    return '';
  }

  // --- The button in the input row: in the place of the send button while the row is empty ---
  function placeButton() {
    var send = document.getElementById('btnSend');
    var inner = send && send.parentNode;
    if (!inner) return;
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.id = 'btnLive';
      button.className = 'composer-send composer-live-btn';
      button.appendChild(svgIcon(WAVE));
      button.addEventListener('click', function () {
        var why = reason();
        if (why) { button.title = why; return; }
        open();
      });
    }
    if (button.parentNode !== inner || button.previousSibling !== send) inner.insertBefore(button, send.nextSibling);
    // 0.6.32: only a usable live button takes the send button's place; otherwise sending stays.
    var why = reason();
    inner.classList.toggle('has-live', !why);
    button.hidden = !!why;
    button.setAttribute('aria-disabled', why ? 'true' : 'false');
    button.title = why || 'Live-Gespräch mit Mara';
    button.setAttribute('aria-label', why ? 'Live-Gespräch nicht verfügbar' : 'Live-Gespräch mit Mara');
  }

  function loadStatus() {
    return fetch(API + '?action=status', { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        status.checked = true;
        status.allowed = !!(d && d.ok && d.allowed);
        if (!status.allowed) return;
        status.hears = d.hears === true ? true : (d.hears === false ? false : null);
        status.ready = d.ready !== false;
        status.voice = !!(d.voice && (d.voice.available || d.voice.loading));
        status.name = String(d.name || '');
        status.csrf = String(d.csrf_token || status.csrf);
        status.core = String(d.core_label || d.core || '');
        placeButton();
        // The microphone button may come a moment later: the live button stays right after send.
        var inner = button && button.parentNode;
        if (inner && window.MutationObserver) new MutationObserver(placeButton).observe(inner, { childList: true });
      })
      .catch(function () { status.checked = true; });
  }

  // --- The full-screen view ------------------------------------------------------
  function buildView() {
    var root = document.createElement('div');
    root.className = 'live-overlay';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Live-Gespräch mit Mara');
    root.innerHTML = '<div class="live-top"><span class="live-badge">LIVE</span><span class="live-core"></span></div>'
      + '<div class="live-center"><div class="live-orb" data-state="preparing" aria-hidden="true"><i></i><i></i><i></i><b></b></div>'
      + '<h2 class="live-greeting"></h2><p class="live-status" aria-live="polite"></p>'
      + '<p class="live-heard"></p><p class="live-said"></p></div>'
      + '<div class="live-controls"><button type="button" class="live-mute" aria-pressed="false"></button>'
      + '<button type="button" class="live-end" aria-label="Live-Gespräch beenden"></button></div>';
    root.querySelector('.live-mute').appendChild(svgIcon(MIC, 'live-icon-mic'));
    root.querySelector('.live-mute').appendChild(svgIcon(MIC_OFF, 'live-icon-off'));
    root.querySelector('.live-end').appendChild(svgIcon(CLOSE));
    root.querySelector('.live-mute').addEventListener('click', toggleMute);
    root.querySelector('.live-end').addEventListener('click', close);
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    return root;
  }

  function setState(name, note) {
    if (!live) return;
    live.state = name;
    live.view.querySelector('.live-orb').setAttribute('data-state', live.muted && name === 'listening' ? 'muted' : name);
    var text = note || TEXT[live.muted && name === 'listening' ? 'muted' : name] || '';
    live.view.querySelector('.live-status').textContent = text;
    live.view.setAttribute('data-state', name);
  }

  function setLine(cls, text) {
    if (live) live.view.querySelector(cls).textContent = text || '';
  }

  function toggleMute() {
    if (!live) return;
    live.muted = !live.muted;
    var mute = live.view.querySelector('.live-mute');
    mute.setAttribute('aria-pressed', live.muted ? 'true' : 'false');
    mute.setAttribute('aria-label', live.muted ? 'Mikrofon einschalten' : 'Mikrofon stummschalten');
    if (live.muted && live.utterance) live.utterance = null;
    if (live.state === 'listening' || live.state === 'hearing') setState('listening');
  }

  function open() {
    if (live) return;
    var composer = window.CoreUIComposer;
    var ctx = composer && composer.liveContext ? composer.liveContext() : null;
    live = { view: buildView(), state: 'preparing', muted: false, ctx: ctx, pairs: [], queue: [], playing: null,
      controller: null, utterance: null, preRoll: [], floor: 0.006, speechMs: 0, closed: false, audio: new Audio(), wake: null,
      hear: '', epoch: 0, fetching: Promise.resolve(), urls: [] };
    document.body.appendChild(live.view);
    document.documentElement.classList.add('live-open');
    live.view.querySelector('.live-mute').setAttribute('aria-label', 'Mikrofon stummschalten');
    live.view.querySelector('.live-core').textContent = status.core;
    live.view.querySelector('.live-greeting').textContent = 'Du kannst jetzt sprechen' + (status.name ? ', ' + status.name : '');
    live.view.querySelector('.live-end').focus();
    if (!ctx) { setState('failed', TEXT.noChat); return; }
    if (ctx.busy) { setState('failed', TEXT.busy); return; }
    setState('preparing');
    keepAwake();
    // Created inside the tap, so the browser lets it run (and lets my voice play later).
    var Ctx = window.AudioContext || window.webkitAudioContext;
    live.audioCtx = new Ctx();
    if (live.audioCtx.resume) live.audioCtx.resume().catch(function () {});
    var opened = live;
    var warmed = post('warm', { session_id: ctx.sessionId, character_id: ctx.characterId, since_id: ctx.lastMessageId })
      .then(function (d) { if (d && d.hear_core) opened.hear = String(d.hear_core); return d; })
      .catch(function () { return null; });
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: false, autoGainControl: true, channelCount: 1 } })
      .then(function (stream) {
        if (!live || live.closed) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        live.stream = stream;
        var source = live.audioCtx.createMediaStreamSource(stream);
        var proc = live.audioCtx.createScriptProcessor(2048, 1, 1);
        proc.onaudioprocess = function (e) { onFrame(e.inputBuffer.getChannelData(0)); };
        source.connect(proc);
        proc.connect(live.audioCtx.destination);
        live.proc = proc;
        return warmed.then(function () { if (live && !live.closed && live.state === 'preparing') setState('listening'); });
      })
      .catch(function () { setState('failed', TEXT.denied); });
  }

  function close() {
    if (!live) return;
    var current = live;
    current.closed = true;
    interrupt();
    try { if (current.proc) current.proc.disconnect(); } catch (e) {}
    try { if (current.audioCtx) current.audioCtx.close(); } catch (e) {}
    if (current.stream) current.stream.getTracks().forEach(function (t) { t.stop(); });
    if (current.wake && current.wake.release) current.wake.release().catch(function () {});
    if (current.view.parentNode) current.view.parentNode.removeChild(current.view);
    document.documentElement.classList.remove('live-open');
    live = null;
    // After the conversation: my memory reflection over the rounds, then the chat shows both sides.
    if (current.ctx && current.pairs.length) {
      post('end', { session_id: current.ctx.sessionId, pairs: current.pairs }, true).catch(function () {});
    }
    var composer = window.CoreUIComposer;
    if (current.ctx && composer && composer.reload) composer.reload();
    if (button) button.focus();
  }

  function keepAwake() {
    if (!navigator.wakeLock || !navigator.wakeLock.request) return;
    navigator.wakeLock.request('screen').then(function (lock) { if (live) live.wake = lock; else lock.release(); }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () { if (live && document.visibilityState === 'visible') keepAwake(); });

  // --- Hearing: a simple voice detector with a moving noise floor ----------------
  function rms(frame) {
    var sum = 0;
    for (var i = 0; i < frame.length; i++) sum += frame[i] * frame[i];
    return Math.sqrt(sum / Math.max(1, frame.length));
  }

  function onFrame(input) {
    if (!live || live.closed || !live.audioCtx) return;
    var frame = new Float32Array(input);
    var ms = frame.length / live.audioCtx.sampleRate * 1000;
    var level = rms(frame);
    if (live.muted || live.state === 'preparing' || live.state === 'failed') { live.preRoll = []; return; }
    var talking = live.state === 'speaking' || live.state === 'thinking';
    if (!live.utterance) {
      live.preRoll.push(frame);
      while (live.preRoll.length * ms > VAD.preRollMs) live.preRoll.shift();
      var start = Math.max(live.floor * (talking ? 5 : 3), talking ? 0.04 : 0.015);
      if (level > start) live.speechMs += ms; else { live.speechMs = 0; live.floor = live.floor * 0.95 + level * 0.05; }
      if (live.speechMs >= (talking ? VAD.bargeMs : VAD.startMs)) {
        if (talking) interrupt();
        live.utterance = { frames: live.preRoll.slice(), voicedMs: live.speechMs, silentMs: 0, totalMs: live.preRoll.length * ms };
        live.preRoll = [];
        live.speechMs = 0;
        setState('hearing');
      }
      return;
    }
    var u = live.utterance;
    u.frames.push(frame);
    u.totalMs += ms;
    if (level < Math.max(live.floor * 2, 0.01)) u.silentMs += ms; else { u.silentMs = 0; u.voicedMs += ms; }
    if (u.silentMs >= VAD.endSilenceMs || u.totalMs >= VAD.maxMs) {
      live.utterance = null;
      if (u.voicedMs >= VAD.minVoicedMs) sendTurn(wav(u.frames, live.audioCtx.sampleRate));
      else setState('listening');
    }
  }

  // A WAV file (16-bit PCM, mono, 16 kHz when the device rate allows it) from the frames.
  function wav(frames, rate) {
    var factor = rate % 16000 === 0 ? rate / 16000 : 1;
    var outRate = rate / factor;
    var total = 0;
    frames.forEach(function (f) { total += f.length; });
    var count = Math.floor(total / factor);
    var buffer = new ArrayBuffer(44 + count * 2);
    var view = new DataView(buffer);
    function text(offset, s) { for (var i = 0; i < s.length; i++) view.setUint8(offset + i, s.charCodeAt(i)); }
    text(0, 'RIFF'); view.setUint32(4, 36 + count * 2, true); text(8, 'WAVE'); text(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, outRate, true); view.setUint32(28, outRate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    text(36, 'data'); view.setUint32(40, count * 2, true);
    var pos = 44, acc = 0, n = 0;
    frames.forEach(function (f) {
      for (var i = 0; i < f.length; i++) {
        acc += f[i]; n++;
        if (n === factor) {
          var v = Math.max(-1, Math.min(1, acc / factor));
          if (pos < 44 + count * 2) view.setInt16(pos, v < 0 ? v * 0x8000 : v * 0x7FFF, true);
          pos += 2; acc = 0; n = 0;
        }
      }
    });
    return new Blob([buffer], { type: 'audio/wav' });
  }

  function stamp() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + '_' + p(d.getHours()) + '-' + p(d.getMinutes()) + '-' + p(d.getSeconds());
  }

  function post(action, body, keepalive) {
    return fetch(API + '?action=' + action, { method: 'POST', credentials: 'include', cache: 'no-store', keepalive: !!keepalive,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CoreUI-CSRF': status.csrf },
      body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }

  // --- One round -------------------------------------------------------------------
  function sendTurn(blob) {
    if (!live) return;
    var round = live;
    setState('thinking');
    setLine('.live-heard', '');
    setLine('.live-said', '');
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    round.controller = controller;
    var fd = new FormData();
    fd.append('file', new File([blob], 'Sprachnachricht-' + stamp() + '.wav', { type: 'audio/wav' }));
    fd.append('character_id', round.ctx.characterId);
    fetch(UPLOAD, { method: 'POST', credentials: 'include', body: fd, signal: controller ? controller.signal : undefined,
      headers: { 'X-CoreUI-CSRF': status.csrf, 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (up) {
        if (!up || !up.ok || !up.uuid) throw new Error('upload');
        return fetch(API + '?action=turn', { method: 'POST', credentials: 'include', cache: 'no-store',
          signal: controller ? controller.signal : undefined,
          headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CoreUI-CSRF': status.csrf },
          body: JSON.stringify({ session_id: round.ctx.sessionId, character_id: round.ctx.characterId,
            since_id: round.ctx.lastMessageId, uuid: up.uuid, hear: round.hear }) });
      })
      .then(function (r) {
        if (!r.ok || !r.body) throw new Error('turn');
        return readEvents(r.body, function (event, data) { onEvent(round, event, data); });
      })
      .then(function () { if (live === round && round.controller === controller) { round.controller = null; settle(); } })
      .catch(function (e) {
        if (live !== round || (e && e.name === 'AbortError')) return;
        round.controller = null;
        setState('listening', TEXT.failed);
      });
  }

  function readEvents(stream, handle) {
    var reader = stream.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    function pump() {
      return reader.read().then(function (chunk) {
        if (chunk.done) return;
        buffer += decoder.decode(chunk.value, { stream: true });
        var split;
        while ((split = buffer.indexOf('\n\n')) >= 0) {
          var block = buffer.slice(0, split);
          buffer = buffer.slice(split + 2);
          var event = 'message', data = '';
          block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) event = line.slice(6).trim();
            else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
          });
          var parsed = null;
          try { parsed = data ? JSON.parse(data) : {}; } catch (e) { parsed = {}; }
          handle(event, parsed);
        }
        return pump();
      });
    }
    return pump();
  }

  function onEvent(round, event, data) {
    if (live !== round) return;
    if (event === 'heard') {
      setLine('.live-heard', '„' + String(data.text || '') + '“');
    } else if (event === 'hear') {
      round.hear = String(data.core || '');
    } else if (event === 'silence') {
      setState('listening', TEXT.silence);
    } else if (event === 'sentence') {
      enqueue(String(data.text || ''));
    } else if (event === 'done') {
      if (Number(data.user_message_id) > 0 && Number(data.reply_id) > 0) round.pairs.push([Number(data.user_message_id), Number(data.reply_id)]);
    } else if (event === 'error') {
      setState('listening', TEXT.failed);
    }
  }

  // --- My voice: one sentence after the other, fetched ahead ---------------------------
  // Eve computes one part at a time (about twice as fast as she speaks). Every part is
  // requested as soon as its sentence is planned, in order, one download after the other,
  // so the next part is ready when the current one ends: no pause after a comma or between
  // sentences (0.6.31 only asked for a part when the one before had ended).
  function enqueue(sentence) {
    if (!live || !sentence) return;
    setLine('.live-said', sentence);
    if (!status.voice) { setState('speaking', TEXT.noVoice); return; }
    var round = live;
    var epoch = round.epoch;
    var item = { text: sentence };
    item.parts = post('say', { text: sentence })
      .catch(function () { return null; })
      .then(function (plan) {
        var count = plan && plan.ok ? Math.min(Number(plan.parts) || 0, 64) : 0;
        var list = [];
        for (var i = 0; i < count; i++) list.push(fetchPart(round, epoch, plan.id, i));
        return list;
      });
    round.queue.push(item);
    if (!round.playing) playNext();
  }

  // One part as a blob URL, after the downloads before it; null when it cannot be had.
  function fetchPart(round, epoch, id, part) {
    var got = round.fetching.then(function () {
      if (live !== round || round.epoch !== epoch) return null;
      return fetch(AUDIO + '&id=' + encodeURIComponent(id) + '&part=' + part, { credentials: 'include' })
        .then(function (r) { return r.ok ? r.blob() : null; })
        .then(function (blob) {
          if (!blob || !blob.size || live !== round || round.epoch !== epoch) return null;
          var url = URL.createObjectURL(blob);
          round.urls.push(url);
          return url;
        })
        .catch(function () { return null; });
    });
    round.fetching = got.then(function () {}, function () {});
    return got;
  }

  function forget(round, url) {
    var at = round.urls.indexOf(url);
    if (at >= 0) round.urls.splice(at, 1);
    try { URL.revokeObjectURL(url); } catch (e) {}
  }

  function playNext() {
    if (!live) return;
    var round = live;
    var item = round.queue.shift();
    if (!item) { round.playing = null; settle(); return; }
    round.playing = item;
    setState('speaking');
    setLine('.live-said', item.text);
    item.parts.then(function (list) {
      var part = 0;
      function next() {
        if (live !== round || round.playing !== item) return;
        if (part >= list.length) { playNext(); return; }
        list[part++].then(function (url) {
          if (live !== round || round.playing !== item) { if (url) forget(round, url); return; }
          if (!url) { next(); return; }
          var done = false;
          function after() { if (done) return; done = true; forget(round, url); next(); }
          round.audio.src = url;
          round.audio.onended = after;
          round.audio.onerror = after;
          var played = round.audio.play();
          if (played && played.catch) played.catch(after);
        });
      }
      next();
    });
  }

  // Back to listening once my answer is complete and spoken.
  function settle() {
    if (!live || live.playing || live.queue.length || live.controller) return;
    if (live.state === 'thinking' || live.state === 'speaking') setState('listening');
  }

  // Interrupt me: stop my voice and the running round.
  function interrupt() {
    if (!live) return;
    if (live.controller) { try { live.controller.abort(); } catch (e) {} live.controller = null; }
    live.epoch++;
    live.queue = [];
    live.playing = null;
    try { live.audio.pause(); live.audio.removeAttribute('src'); live.audio.load(); } catch (e) {}
    live.urls.splice(0).forEach(function (url) { try { URL.revokeObjectURL(url); } catch (e) {} });
  }

  window.CoreAIVoiceLive = { open: open, close: close, status: function () { return status; }, wav: wav };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadStatus);
  else loadStatus();
})();
