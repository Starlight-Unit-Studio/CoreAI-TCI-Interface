/* CoreAI: TCI Interface 0.6.81: Settings > Benachrichtigungen.
   Push for this device (switch on/off, test, the account's devices), whether E-Mail can be sent
   on this server, and one choice per category (Aus, Push, E-Mail, Push und E-Mail). Planned
   categories stay greyed. Everything is saved on the account (api/notifications.php). */
(function () {
  'use strict';

  var view = document.getElementById('view-benachrichtigungen');
  var notify = window.CoreAINotify;
  if (!view || !notify) return;

  var el = {
    pushStatus: document.getElementById('notifyPushStatus'),
    enable: document.getElementById('notifyPushEnable'),
    disable: document.getElementById('notifyPushDisable'),
    test: document.getElementById('notifyPushTest'),
    devices: document.getElementById('notifyDevices'),
    mailStatus: document.getElementById('notifyMailStatus'),
    saveState: document.getElementById('settingsSaveState')
  };
  var selects = Array.prototype.slice.call(view.querySelectorAll('select.notify-select'));
  var hereKey = '';
  var busy = false;

  var PUSH_TEXT = {
    insecure: 'Push braucht eine sichere Verbindung (HTTPS).',
    unsupported: 'Dieser Browser unterstützt keine Push-Benachrichtigungen.',
    ios_home_screen: 'Auf iPhone und iPad funktioniert Push nur, wenn CoreAI über „Zum Home-Bildschirm“ geöffnet wird.',
    denied: 'Benachrichtigungen sind für CoreAI in diesem Browser blockiert. Erlaube sie in den Website-Einstellungen des Browsers.',
    server: 'Push ist auf diesem Server noch nicht bereit. Nach dem nächsten CoreAI-Update ist es verfügbar.',
    on: 'Push ist auf diesem Gerät aktiv.',
    off: 'Push ist auf diesem Gerät aus.'
  };
  var ERRORS = {
    denied: PUSH_TEXT.denied,
    dismissed: 'Die Erlaubnis wurde nicht erteilt. Tippe noch einmal auf „Aktivieren“, um Push einzuschalten.',
    server_unavailable: PUSH_TEXT.server,
    ios_home_screen: PUSH_TEXT.ios_home_screen,
    insecure: PUSH_TEXT.insecure,
    unsupported: PUSH_TEXT.unsupported,
    push_endpoint_not_allowed: 'Der Push-Dienst dieses Browsers wird nicht unterstützt.',
    push_too_many_devices: 'Push ist bereits auf zehn Geräten aktiv. Entferne zuerst ein Gerät.',
    notifications_migration_required: 'Die Datenbank-Erweiterung für Benachrichtigungen fehlt noch. Bitte CoreAI aktualisieren.',
    guest_not_allowed: 'Gäste haben keine Benachrichtigungen.'
  };

  function announce(text, isError) {
    if (!el.saveState) return;
    el.saveState.textContent = text;
    el.saveState.classList.toggle('error', isError === true);
    el.saveState.classList.add('visible');
    clearTimeout(announce.timer);
    announce.timer = setTimeout(function () { el.saveState.classList.remove('visible'); }, 2600);
  }
  function errorText(error) {
    var code = error && error.message ? error.message : '';
    return ERRORS[code] || 'Das hat nicht geklappt. Bitte später noch einmal versuchen.';
  }
  function formatDate(value) {
    var date = new Date(String(value || '').replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit', year: 'numeric'});
  }

  function renderDevices(state) {
    el.devices.textContent = '';
    (state.push && state.push.devices || []).forEach(function (device) {
      var item = document.createElement('li');
      item.className = 'notify-device';
      var text = document.createElement('span');
      text.className = 'notify-device-copy';
      var name = document.createElement('strong');
      name.textContent = device.label || 'Browser';
      var meta = document.createElement('small');
      var since = formatDate(device.created_at);
      meta.textContent = (device.key && device.key === hereKey ? 'Dieses Gerät · ' : '') + (since ? 'seit ' + since : '');
      text.appendChild(name);
      text.appendChild(meta);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'settings-action compact';
      remove.textContent = 'ENTFERNEN';
      remove.setAttribute('aria-label', 'Push für ' + (device.label || 'dieses Gerät') + ' entfernen');
      remove.addEventListener('click', function () { removeDevice(device); });
      item.appendChild(text);
      item.appendChild(remove);
      el.devices.appendChild(item);
    });
    el.devices.hidden = !el.devices.children.length;
  }

  function render(state) {
    var support = notify.support();
    var active = !!hereKey && (state.push && state.push.devices || []).some(function (d) { return d.key === hereKey; });
    var reason = !state.push || !state.push.available ? 'server' : support.reason;
    el.pushStatus.textContent = active ? PUSH_TEXT.on : (reason ? PUSH_TEXT[reason] : PUSH_TEXT.off);
    el.pushStatus.setAttribute('data-state', active ? 'on' : (reason || 'off'));
    el.enable.hidden = active || !!reason;
    el.disable.hidden = !active;
    el.test.hidden = !(state.push && state.push.devices && state.push.devices.length);
    renderDevices(state);

    var mail = state.email || {};
    el.mailStatus.textContent = mail.available
      ? 'E-Mails gehen an ' + (mail.address || 'die E-Mail-Adresse deines Kontos') + '.'
      : 'Auf diesem Server ist kein E-Mail-Versand eingerichtet. E-Mail-Benachrichtigungen werden deshalb nicht verschickt.';
    el.mailStatus.setAttribute('data-state', mail.available ? 'on' : 'off');

    selects.forEach(function (select) {
      var id = select.getAttribute('data-category');
      var row = select.closest('.notify-row');
      var cat = state.categories && state.categories[id];
      if (row && row.hasAttribute('data-owner-only')) row.hidden = !state.owner;
      if (cat && cat.planned) return;
      Array.prototype.forEach.call(select.options, function (option) {
        option.disabled = !mail.available && option.value.indexOf('email') >= 0 && option.value !== select.value;
      });
      if (state.prefs && typeof state.prefs[id] === 'string') select.value = state.prefs[id];
    });
  }

  function refresh() {
    return Promise.all([notify.load(true), notify.currentSubscription()]).then(function (all) {
      var sub = all[1];
      return (sub ? notify.endpointKey(sub.endpoint) : Promise.resolve('')).then(function (key) {
        hereKey = key;
        render(all[0]);
        return all[0];
      });
    }).catch(function (error) {
      el.pushStatus.textContent = errorText(error);
      el.pushStatus.setAttribute('data-state', 'error');
      el.mailStatus.textContent = '';
    });
  }

  function run(job, success) {
    if (busy) return Promise.resolve();
    busy = true;
    view.setAttribute('aria-busy', 'true');
    return job().then(function (result) {
      return refresh().then(function () { if (success) announce(typeof success === 'function' ? success(result) : success); });
    }).catch(function (error) {
      announce(errorText(error), true);
      return refresh();
    }).then(function () {
      busy = false;
      view.removeAttribute('aria-busy');
    });
  }

  function removeDevice(device) {
    run(function () {
      if (device.key && device.key === hereKey) return notify.disable();
      return notify.post({action: 'unsubscribe', id: device.id});
    }, 'GERÄT ENTFERNT');
  }

  el.enable.addEventListener('click', function () { run(notify.enable, 'PUSH AKTIVIERT'); });
  el.disable.addEventListener('click', function () { run(notify.disable, 'PUSH HIER AUSGESCHALTET'); });
  el.test.addEventListener('click', function () {
    run(function () { return notify.post({action: 'test'}); }, function (result) {
      var t = result && result.test ? result.test : {};
      return t.push > 0 ? 'TEST AN ' + t.push + (t.push === 1 ? ' GERÄT' : ' GERÄTE') + ' GESENDET'
        : 'TEST NICHT ZUGESTELLT';
    });
  });
  selects.forEach(function (select) {
    select.addEventListener('change', function () {
      var prefs = {};
      prefs[select.getAttribute('data-category')] = select.value;
      run(function () { return notify.post({action: 'prefs', prefs: prefs}); }, 'GESPEICHERT');
    });
  });

  refresh();
})();
