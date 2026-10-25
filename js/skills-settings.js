(function () {
  'use strict';
  var list = document.getElementById('skillsList');
  var search = document.getElementById('skillsSearch');
  var status = document.getElementById('skillsStatus');
  var save = document.getElementById('saveSkills');
  var all = document.getElementById('enableAllSkills');
  var none = document.getElementById('disableAllSkills');
  if (!list || !search || !status || !save || !all || !none) return;
  var catalog = [], enabled = {}, csrf = '', ready = false, busy = false;
  function request(options) {
    return fetch('./api/user_skills.php', Object.assign({credentials: 'same-origin', cache: 'no-store'}, options || {}))
      .then(function (response) {
        return response.text().then(function (body) {
          var data;
          try { data = JSON.parse(body); }
          catch (_) { throw new Error('http_' + response.status); }
          if (!response.ok || !data || data.ok !== true) {
            throw new Error((data && data.error) || ('http_' + response.status));
          }
          return data;
        });
      });
  }
  function loadErrorMessage(error) {
    var code = String(error && error.message || '');
    if (code === 'http_404') return 'Skills-API nicht erreichbar (HTTP 404). Nginx-Routing prüfen, anschließend den normalen CoreUI-Updateweg ausführen.';
    if (code === 'not_authenticated' || code === 'http_401') return 'Anmeldung abgelaufen. Bitte erneut anmelden.';
    if (code === 'skills_migration_required' || code === 'skills_save_failed_or_migration_missing') {
      return 'Skills-Datenbankmigration 014 fehlt. Bitte Server-Preflight prüfen.';
    }
    if (code === 'server_error' || code === 'php_fatal' || code === 'http_500') {
      return 'Skills-API meldet einen Serverfehler (HTTP 500). Serverprotokoll prüfen.';
    }
    return 'Skills konnten nicht geladen werden (' + (code || 'Verbindungsfehler') + '). Bitte Server-Preflight prüfen.';
  }
  function controls() {
    save.disabled = all.disabled = none.disabled = !ready || busy;
    list.querySelectorAll('input').forEach(function (input) { input.disabled = !ready || busy; });
  }
  function dirty() { status.textContent = 'Auswahl geändert. Bitte speichern; sie gilt ab der nächsten Anfrage.'; }
  function render() {
    list.replaceChildren();
    var query = search.value.trim().toLocaleLowerCase();
    var filtered = catalog.filter(function (skill) {
      return [skill.id, skill.title, skill.category, skill.description].join(' ').toLocaleLowerCase().includes(query);
    });
    filtered.forEach(function (skill) {
      var card = document.createElement('div');
      card.className = 'skill-card';
      var label = document.createElement('label');
      label.className = 'setting-row';
      var copy = document.createElement('span');
      copy.className = 'setting-copy';
      var title = document.createElement('strong'); title.textContent = skill.title;
      var desc = document.createElement('small'); desc.textContent = skill.description;
      copy.append(title, desc);
      var toggle = document.createElement('span'); toggle.className = 'settings-switch';
      var input = document.createElement('input'); input.type = 'checkbox'; input.checked = enabled[skill.id] === true;
      input.addEventListener('change', function () { enabled[skill.id] = input.checked; dirty(); });
      var decoration = document.createElement('i'); decoration.setAttribute('aria-hidden', 'true');
      toggle.append(input, decoration); label.append(copy, toggle); card.appendChild(label);
      var details = document.createElement('details');
      var summary = document.createElement('summary'); summary.textContent = skill.category + ' · Quellen und Aufruf';
      details.appendChild(summary);
      var info = document.createElement('p');
      info.textContent = '/skill ' + skill.id + ' · Version ' + skill.version + ' · Quellen geprüft: ' + skill.reviewed;
      details.appendChild(info);
      (skill.sources || []).forEach(function (url) {
        try {
          var parsed = new URL(url);
          if (parsed.protocol !== 'https:') return;
          var a = document.createElement('a'); a.href = parsed.href; a.textContent = parsed.hostname;
          a.target = '_blank'; a.rel = 'noopener noreferrer'; details.appendChild(a);
        } catch (_) { /* Ignore invalid catalog URLs. */ }
      });
      card.appendChild(details); list.appendChild(card);
    });
    if (!filtered.length) {
      var empty = document.createElement('p'); empty.textContent = 'Keine passenden Skills gefunden.'; list.appendChild(empty);
    }
    controls();
  }
  search.addEventListener('input', render);
  all.addEventListener('click', function () { catalog.forEach(function (s) { enabled[s.id] = true; }); render(); dirty(); });
  none.addEventListener('click', function () { catalog.forEach(function (s) { enabled[s.id] = false; }); render(); dirty(); });
  save.addEventListener('click', function () {
    if (!ready || busy) return;
    busy = true; controls(); status.textContent = 'Skills werden gespeichert …';
    request({method: 'POST', headers: {'Content-Type': 'application/json', 'X-CoreUI-CSRF': csrf}, body: JSON.stringify({enabled: enabled})})
      .then(function (data) { enabled = data.enabled; status.textContent = 'Skills gespeichert. Die Auswahl gilt ab der nächsten Anfrage.'; })
      .catch(function () { status.textContent = 'Speichern fehlgeschlagen. Auswahl ist noch nicht gespeichert; bitte erneut versuchen.'; })
      .finally(function () { busy = false; render(); });
  });
  controls();
  request().then(function (data) {
    catalog = data.catalog; enabled = data.enabled; csrf = data.csrf_token; ready = data.ready === true;
    status.textContent = ready ? catalog.length + ' Skills verfügbar. Aktiviere deine Auswahl und speichere sie.' : 'Skills noch nicht verfügbar: Datenbankmigration 014 muss eingespielt werden.';
    render();
  }).catch(function (error) { status.textContent = loadErrorMessage(error); });
})();
