/* CoreAI: TCI Interface 0.6.1: Mara reads her answers aloud with Eve (P20.3).
   A speaker button under every Mara answer. The server cuts the answer into
   parts (the first one short); playback starts as soon as the first part is
   ready and the next part loads while the current one plays. A second click
   stops. Optional: read new answers aloud automatically (KI-Profil).
   Signed-in accounts only; guests never see the button. */
(function () {
  'use strict';

  var API = './api/voice.php';
  var SPEAKER = 'M4 9v6h4l5 4V5L8 9H4z M15 8.6v6.8a4 4 0 0 0 0-6.8z M15 5v2a5 5 0 0 1 0 10v2a7 7 0 0 0 0-14z';
  var STOP = 'M7 7h10v10H7z';
  var state = { allowed: false, available: false, loading: false, autoRead: false, csrf: '', checked: false };
  var current = null;
  var waiting = [];
  var statusTimer = 0;

  function icon(button, path) {
    var svg = button.querySelector('svg');
    if (!svg) return;
    var node = svg.querySelector('path');
    if (node) node.setAttribute('d', path);
  }

  function makeButton() {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'msg-action msg-speak';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', SPEAKER);
    svg.appendChild(path);
    button.appendChild(svg);
    return button;
  }

  function label(button) {
    var text = state.available ? 'Antwort vorlesen' : (state.loading ? 'Maras Stimme wird geladen' : 'Vorlesen gerade nicht verfuegbar');
    if (button.classList.contains('speaking')) text = 'Vorlesen stoppen';
    button.title = text;
    button.setAttribute('aria-label', text);
    button.disabled = !state.available && !button.classList.contains('speaking');
  }

  // Shown to signed-in accounts while the voice is ready or loading; a server
  // without the voice service (for example a native install) shows nothing.
  function visible() {
    return state.allowed && (state.available || state.loading);
  }

  function refreshButtons() {
    document.querySelectorAll('.msg-speak').forEach(function (button) {
      button.hidden = !visible() && !button.classList.contains('speaking');
      label(button);
    });
  }

  function loadStatus() {
    clearTimeout(statusTimer);
    return fetch(API + '?action=status', { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        state.checked = true;
        state.allowed = !!(data && data.ok && data.allowed);
        state.available = state.allowed && !!data.available;
        state.loading = state.allowed && !!data.loading;
        state.autoRead = state.allowed && !!data.auto_read;
        state.csrf = (data && data.csrf_token) || state.csrf;
        refreshButtons();
        bindAutoReadSwitch();
        // While Eve loads (first start fetches her weights), look again.
        if (state.allowed && !state.available) statusTimer = setTimeout(loadStatus, state.loading ? 15000 : 60000);
        waiting.splice(0).forEach(function (fn) { fn(); });
      })
      .catch(function () {
        state.checked = true;
        waiting.splice(0).forEach(function (fn) { fn(); });
      });
  }

  function whenChecked(fn) {
    if (state.checked) fn();
    else waiting.push(fn);
  }

  function stop() {
    if (!current) return;
    var playing = current;
    current = null;
    playing.cancelled = true;
    playing.audios.forEach(function (audio) {
      try { audio.pause(); } catch (e) {}
      audio.removeAttribute('src');
    });
    playing.button.classList.remove('speaking', 'loading');
    icon(playing.button, SPEAKER);
    label(playing.button);
  }

  function partUrl(id, index) {
    return API + '?action=audio&id=' + encodeURIComponent(id) + '&part=' + index;
  }

  function fail(run, message) {
    if (current !== run) return;
    stop();
    run.button.classList.add('error');
    run.button.title = message;
    run.button.setAttribute('aria-label', message);
    setTimeout(function () { run.button.classList.remove('error'); label(run.button); }, 4000);
  }

  function playPart(run, index) {
    if (run.cancelled) return;
    if (index >= run.parts) { stop(); return; }
    var audio = run.audios[index] || prepare(run, index);
    // The next part loads while this one plays.
    if (index + 1 < run.parts && !run.audios[index + 1]) prepare(run, index + 1);
    audio.onended = function () { playPart(run, index + 1); };
    audio.onerror = function () {
      if (run.retried[index]) { fail(run, 'Vorlesen fehlgeschlagen'); return; }
      run.retried[index] = true;
      setTimeout(function () {
        if (run.cancelled) return;
        audio.src = partUrl(run.id, index) + '&retry=1';
        playPart(run, index);
      }, 2000);
    };
    var started = audio.play();
    if (started && typeof started.then === 'function') {
      started.then(function () { run.button.classList.remove('loading'); })
        .catch(function (error) { if (error && error.name === 'NotAllowedError') fail(run, 'Der Browser hat das Abspielen blockiert'); });
    } else {
      run.button.classList.remove('loading');
    }
  }

  function prepare(run, index) {
    var audio = new Audio();
    audio.preload = 'auto';
    audio.src = partUrl(run.id, index);
    run.audios[index] = audio;
    return audio;
  }

  function start(record, button) {
    stop();
    var run = { button: button, messageId: Number(record.id), cancelled: false, audios: [], retried: {}, parts: 0, id: '' };
    current = run;
    button.classList.add('speaking', 'loading');
    icon(button, STOP);
    label(button);
    fetch(API + '?action=plan', {
      method: 'POST', credentials: 'include', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CoreUI-CSRF': state.csrf },
      body: JSON.stringify({ session_id: String(record.session_id || ''), message_id: Number(record.id) || 0 })
    }).then(function (response) { return response.json(); })
      .then(function (data) {
        if (run.cancelled) return;
        if (!data || !data.ok || !(data.parts > 0)) {
          fail(run, data && data.error === 'nothing_to_read' ? 'Nichts zum Vorlesen' : 'Vorlesen gerade nicht verfuegbar');
          return;
        }
        run.id = data.id;
        run.parts = data.parts;
        playPart(run, 0);
      })
      .catch(function () { fail(run, 'Vorlesen gerade nicht verfuegbar'); });
  }

  function button(record, sessionId) {
    if (!record || !(Number(record.id) > 0)) return null;
    var speak = makeButton();
    var target = { id: Number(record.id), session_id: String(record.session_id || sessionId || '') };
    speak.dataset.messageId = String(target.id);
    speak.hidden = !visible();
    // The chat redraws its rows; a running read-aloud keeps its button.
    if (current && current.messageId === target.id) {
      current.button = speak;
      speak.classList.add('speaking');
      icon(speak, STOP);
    }
    label(speak);
    speak.addEventListener('click', function () {
      if (current && current.button === speak) { stop(); return; }
      start(target, speak);
    });
    return speak;
  }

  /* A new answer is done: read it aloud when the account switched it on. */
  function onAnswer(record) {
    whenChecked(function () {
      if (!state.allowed || !state.available || !state.autoRead || !record || !(Number(record.id) > 0)) return;
      var speak = document.querySelector('.msg-speak[data-message-id="' + String(Number(record.id)) + '"]');
      if (speak) start({ id: Number(record.id), session_id: String(record.session_id || '') }, speak);
    });
  }

  function setAutoRead(on) {
    return fetch(API + '?action=settings', {
      method: 'POST', credentials: 'include', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CoreUI-CSRF': state.csrf },
      body: JSON.stringify({ auto_read: !!on })
    }).then(function (response) { return response.json(); }).then(function (data) {
      if (data && data.ok) state.autoRead = !!data.auto_read;
      return data;
    });
  }

  /* KI-Profil: "Antworten automatisch vorlesen" (only for signed-in accounts). */
  function bindAutoReadSwitch() {
    var input = document.getElementById('voiceAutoRead');
    var row = document.getElementById('voiceAutoReadRow');
    if (!input || !row) return;
    row.hidden = !visible();
    input.checked = state.autoRead;
    var note = document.getElementById('voiceAutoReadNote');
    if (note) note.textContent = state.available ? 'Eve liest neue Antworten von selbst vor.'
      : (state.loading ? 'Maras Stimme wird gerade geladen.' : 'Die Stimme ist auf diesem Server gerade nicht verfuegbar.');
    if (input.dataset.bound) return;
    input.dataset.bound = '1';
    input.addEventListener('change', function () {
      var wanted = input.checked;
      input.disabled = true;
      setAutoRead(wanted).then(function (data) {
        input.checked = !!(data && data.ok ? data.auto_read : !wanted);
      }).catch(function () { input.checked = !wanted; })
        .then(function () { input.disabled = false; });
    });
  }

  window.CoreAIVoiceRead = {
    button: button,
    onAnswer: onAnswer,
    stop: stop,
    setAutoRead: setAutoRead,
    status: function () { return { allowed: state.allowed, available: state.available, loading: state.loading, autoRead: state.autoRead }; },
    ready: function () { return new Promise(function (resolve) { whenChecked(resolve); }); }
  };
  document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
  loadStatus();
})();
