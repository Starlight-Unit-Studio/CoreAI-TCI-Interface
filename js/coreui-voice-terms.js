/* CoreAI: TCI Interface 0.6.22: names and terms for voice messages (KI-Profil).
   One term per line. Core gets them as a spelling hint when it writes the
   transcript, and Mara in the task of a voice message, so a place such as
   "Bergisch Gladbach" is no longer heard as "Berlin". Signed-in accounts only,
   never guests. Saved when the field loses focus and two seconds after typing
   stops; the server cleans the list (api/voice_input.php?action=terms). */
(function () {
  'use strict';

  var API = './api/voice_input.php';
  var state = { csrf: '', saved: '', max: 40, timer: 0, busy: false, again: false };

  function el(id) { return document.getElementById(id); }

  function show(text) {
    var note = el('voiceTermsState');
    if (note) note.textContent = text;
  }

  function summary(terms) {
    return terms.length ? terms.length + ' von ' + state.max + (terms.length === 1 ? ' Begriff' : ' Begriffen') : 'Noch keine Begriffe';
  }

  function save() {
    var input = el('voiceTerms');
    clearTimeout(state.timer);
    if (!input || input.disabled || input.value === state.saved) return;
    if (state.busy) { state.again = true; return; }
    var wanted = input.value;
    state.busy = true;
    show('Speichere …');
    fetch(API + '?action=terms', {
      method: 'POST', credentials: 'include', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CoreUI-CSRF': state.csrf },
      body: JSON.stringify({ terms: wanted })
    }).then(function (response) { return response.json(); }).then(function (data) {
      if (!data || !data.ok) {
        show(data && data.error === 'missing_schema_025' ? 'Nicht gespeichert: Die Datenbank braucht noch das Update.'
          : 'Nicht gespeichert. Versuch es gleich noch einmal.');
        return;
      }
      var terms = Array.isArray(data.terms) ? data.terms : [];
      state.saved = terms.join('\n');
      // The cleaned list replaces the text once the field is left (while typing, the next blur saves it again).
      if (document.activeElement !== input && input.value === wanted) input.value = state.saved;
      show('Gespeichert · ' + summary(terms));
    }).catch(function () {
      show('Nicht gespeichert. Versuch es gleich noch einmal.');
    }).then(function () {
      state.busy = false;
      if (state.again) { state.again = false; save(); }
    });
  }

  function bind(input) {
    if (input.dataset.bound) return;
    input.dataset.bound = '1';
    input.addEventListener('input', function () {
      clearTimeout(state.timer);
      show('Wird gleich gespeichert …');
      state.timer = setTimeout(save, 2000);
    });
    input.addEventListener('blur', save);
  }

  function load() {
    var row = el('voiceTermsRow');
    var input = el('voiceTerms');
    if (!row || !input) return;
    fetch(API + '?action=status', { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.allowed) { row.hidden = true; return; }
        state.csrf = data.csrf_token || state.csrf;
        state.max = Number(data.terms_max) > 0 ? Number(data.terms_max) : state.max;
        row.hidden = false;
        if (data.terms_ready === false) {
          input.disabled = true;
          show('Nach dem nächsten Update verfügbar.');
          return;
        }
        var terms = Array.isArray(data.terms) ? data.terms : [];
        input.value = terms.join('\n');
        state.saved = input.value;
        show(summary(terms));
        bind(input);
      })
      .catch(function () { row.hidden = true; });
  }

  window.CoreAIVoiceTerms = { reload: load, save: save };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
