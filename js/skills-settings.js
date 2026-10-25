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
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.error || 'skills_unavailable');
          return data;
        });
      });
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
  }).catch(function () { status.textContent = 'Skills konnten nicht geladen werden. Bitte Anmeldung prüfen und Seite neu laden.'; });
})();
