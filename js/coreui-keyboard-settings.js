/* CoreAI 0.7.29: Settings > Tastatur after the reference screenshots.
   Each shortcut has a switch and its key combination. Choosing the combination records the next key
   press (Esc cancels, Backspace or Delete alone removes the shortcut). The rules (which keys are
   allowed, which clash) live in js/coreui-shortcuts.js; the choice is kept in this browser. */
(function () {
  'use strict';
  var S = window.CoreAIShortcuts;
  var $ = function (id) { return document.getElementById(id); };
  var groups = $('kbGroups');
  if (!S || !groups) return;

  var recording = '';
  var statusTimer = 0;

  function status(text, error) {
    var node = $('kbStatus');
    node.textContent = text || '';
    if (error) node.setAttribute('data-state', 'error'); else node.removeAttribute('data-state');
    clearTimeout(statusTimer);
    if (text && !error) statusTimer = setTimeout(function () { node.textContent = ''; }, 4000);
  }

  function keysLabel(action, item) {
    return 'Tasten für „' + action.label + '“: ' + S.label(item.keys) + '. Zum Ändern auswählen.';
  }

  function row(action, map) {
    var r = document.createElement('div');
    r.className = 'sec-row kb-row' + (action.planned ? ' is-planned' : '');
    r.setAttribute('data-action', action.id);
    var copy = document.createElement('div');
    copy.className = 'sec-copy';
    var name = document.createElement('strong');
    name.id = 'kbLabel-' + action.id;
    name.textContent = action.label;
    copy.appendChild(name);
    r.appendChild(copy);
    if (action.planned) {
      var badge = document.createElement('span');
      badge.className = 'kb-planned';
      badge.textContent = 'In Vorbereitung';
      r.appendChild(badge);
    } else {
      var item = map[action.id];
      var keys = document.createElement('button');
      keys.type = 'button';
      keys.className = 'kb-keys' + (item.keys ? '' : ' is-none');
      keys.id = 'kbKeys-' + action.id;
      keys.textContent = S.label(item.keys);
      keys.disabled = !item.on;
      keys.setAttribute('aria-label', keysLabel(action, item));
      keys.addEventListener('click', function () {
        if (recording === action.id) stop();
        else start(action.id);
      });
      r.appendChild(keys);
    }
    var sw = document.createElement('label');
    sw.className = 'settings-switch sec-switch';
    sw.setAttribute('for', 'kbOn-' + action.id);
    var box = document.createElement('input');
    box.type = 'checkbox';
    box.id = 'kbOn-' + action.id;
    box.setAttribute('aria-labelledby', 'kbLabel-' + action.id);
    box.checked = !action.planned && map[action.id].on;
    box.disabled = !!action.planned;
    box.addEventListener('change', function () {
      stop();
      var result = S.set(action.id, {on: box.checked});
      if (!result.ok) {
        box.checked = !box.checked;
        status(result.message, true);
        return;
      }
      status('„' + action.label + '“ ist ' + (box.checked ? 'an.' : 'aus.'));
      render();
    });
    var knob = document.createElement('i');
    knob.setAttribute('aria-hidden', 'true');
    sw.appendChild(box);
    sw.appendChild(knob);
    r.appendChild(sw);
    return r;
  }

  function render() {
    var map = S.load();
    var focusId = document.activeElement && document.activeElement.id;
    groups.textContent = '';
    S.sections.forEach(function (section) {
      var h = document.createElement('h3');
      h.className = 'sec-heading';
      h.textContent = section.title;
      var panel = document.createElement('section');
      panel.className = 'settings-panel cfg-card wide sec-group kb-group';
      panel.setAttribute('aria-label', section.title);
      S.actions.filter(function (a) { return a.section === section.id; }).forEach(function (a) { panel.appendChild(row(a, map)); });
      groups.appendChild(h);
      groups.appendChild(panel);
    });
    if (focusId && $(focusId)) $(focusId).focus();
  }

  function start(id) {
    stop();
    var btn = $('kbKeys-' + id);
    if (!btn || btn.disabled) return;
    recording = id;
    btn.classList.add('is-recording');
    btn.textContent = 'Tasten drücken …';
    btn.setAttribute('aria-pressed', 'true');
    status('');
  }
  function stop() {
    if (!recording) return;
    var id = recording;
    recording = '';
    var btn = $('kbKeys-' + id);
    var item = S.load()[id];
    if (!btn || !item) return;
    btn.classList.remove('is-recording');
    btn.removeAttribute('aria-pressed');
    btn.textContent = S.label(item.keys);
  }

  document.addEventListener('keydown', function (event) {
    if (!recording) return;
    var id = recording;
    var action = S.action(id);
    event.preventDefault();
    event.stopPropagation();
    if (event.isComposing || event.keyCode === 229) return;
    var plain = !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
    if (plain && event.key === 'Escape') { stop(); return; }
    var combo;
    if (plain && (event.key === 'Backspace' || event.key === 'Delete')) combo = '';
    else combo = S.fromEvent(event);
    if (combo === null) return; // only modifiers so far: wait for the key
    var result = S.set(id, {keys: combo});
    recording = '';
    render();
    if (!result.ok) { status(result.message, true); return; }
    status(combo ? '„' + action.label + '“: ' + S.label(combo) + '.' : '„' + action.label + '“ hat jetzt kein Tastenkürzel.');
  }, true);
  // Clicking elsewhere ends the recording without a change.
  document.addEventListener('click', function (event) {
    if (!recording) return;
    var btn = $('kbKeys-' + recording);
    if (btn && btn.contains(event.target)) return;
    stop();
  }, true);

  $('kbReset').addEventListener('click', function () {
    stop();
    S.reset();
    render();
    status('Standardeinstellungen wiederhergestellt.');
  });
  window.addEventListener('coreai:shortcuts', function () { if (!recording) render(); });

  try {
    if (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
      $('kbNote').textContent += ' Auf dem Telefon oder Tablet brauchst du dafür eine angeschlossene Tastatur.';
    }
  } catch (e) {}
  render();
})();
