/* 0.5.91: account-based presentation controls; never changes the Modelfile. */
(function () {
  'use strict';
  var panel = document.getElementById('view-personalisierung');
  if (!panel) return;
  var controls = Array.prototype.slice.call(panel.querySelectorAll('[data-personalization]'));
  var memory = document.getElementById('personaMemoryEnabled');
  var save = document.getElementById('personalizationSave');
  var status = document.getElementById('personalizationStatus');
  var counter = document.getElementById('personaInstructionsCount');
  var instructions = document.getElementById('personaInstructions');
  var csrf = '';
  var canSave = false;

  function notice(message, failure) {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('error',failure === true);
  }
  function request(method,body) {
    return fetch('./api/personalization.php',{
      method:method,credentials:'include',cache:'no-store',
      headers: Object.assign({'Accept':'application/json'},
        method === 'POST' ? {'Content-Type':'application/json','X-CoreUI-CSRF':csrf} : {}),
      body:method === 'POST' ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP_' + res.status));
        return data;
      });
    });
  }
  function show(values) {
    values = values || {};
    controls.forEach(function (node) {
      var name = node.getAttribute('data-personalization');
      if (node.type === 'checkbox') node.checked = values[name] === true;
      else if (values[name] != null) node.value = String(values[name]);
    });
    updateCount();
  }
  function collect() {
    var values = {};
    controls.forEach(function (node) {
      var key = node.getAttribute('data-personalization');
      values[key] = node.type === 'checkbox' ? node.checked : node.value;
    });
    return values;
  }
  function updateCount() {
    if (counter && instructions) counter.textContent = instructions.value.length + ' / 2400';
  }
  function load() {
    notice('Personalisierung wird geladen …');
    return request('GET').then(function (data) {
      csrf = data.csrf_token || csrf;
      canSave = data.schema_available === true;
      if (save) save.disabled = !canSave;
      show(data.settings);
      if (memory) memory.checked = data.memory_enabled === true;
      notice(canSave ? 'Persönliche Einstellungen für dieses Konto geladen.'
        : 'Migration 022 fehlt. Die Einstellungen können noch nicht gespeichert werden.',!canSave);
    }).catch(function (error) {
      canSave = false;
      if (save) save.disabled = true;
      notice('Personalisierung konnte nicht geladen werden: ' + error.message,true);
    });
  }
  function saveSettings() {
    if (!canSave || !save) return;
    save.disabled = true;
    notice('Einstellungen werden gespeichert …');
    request('POST',{action:'save',settings:collect()}).then(function (data) {
      show(data.settings);
      notice('Die Personalisierung deiner CoreAI wurde für dein Konto gespeichert. ✓');
    }).catch(function (error) {
      notice('Speichern fehlgeschlagen: ' + error.message,true);
    }).finally(function () { save.disabled = !canSave; });
  }
  function saveMemory() {
    if (!memory) return;
    var enabled = memory.checked;
    memory.disabled = true;
    request('POST',{action:'memory',enabled:enabled}).then(function (data) {
      memory.checked = data.memory_enabled === true;
      var legacy = document.getElementById('aiMemoryEnabled');
      if (legacy) legacy.checked = memory.checked;
      notice(memory.checked ? 'Erinnerung aktiviert.' : 'Erinnerung deaktiviert. Gespeicherte Fakten bleiben bestehen.');
    }).catch(function (error) {
      memory.checked = !enabled;
      notice('Erinnerung konnte nicht geändert werden: ' + error.message,true);
    }).finally(function () { memory.disabled = false; });
  }
  if (save) save.addEventListener('click',saveSettings);
  if (memory) memory.addEventListener('change',saveMemory);
  if (instructions) instructions.addEventListener('input',updateCount);
  load();
})();
