/* CoreAI: TCI Interface 0.6.82: Admin Core > Betrieb > E-Mail-Versand (SMTP).
   The Inhaber enters the SMTP server here when the server configuration
   (api/config.local.php or COREUI_SMTP_*) sets none; that one always wins and is
   shown read-only. The password never comes back from the server: an empty
   password field keeps the stored one (same server and user only). Admins never
   see the card; the server answers them owner_only anyway. */
(function () {
  'use strict';

  var apiRoot = window.EMBER_COREUI && window.EMBER_COREUI.apiUrl
    ? window.EMBER_COREUI.apiUrl : new URL('../api/', document.baseURI).href;
  var ADMIN_API = new URL('admin.php', apiRoot).href;

  var card = document.getElementById('mailSmtpCard');
  if (!card) return;
  var el = {
    status: document.getElementById('mailStatus'),
    form: document.getElementById('mailForm'),
    host: document.getElementById('mailHost'),
    security: document.getElementById('mailSecurity'),
    port: document.getElementById('mailPort'),
    user: document.getElementById('mailUser'),
    pass: document.getElementById('mailPass'),
    from: document.getElementById('mailFrom'),
    fromName: document.getElementById('mailFromName'),
    save: document.getElementById('mailSave'),
    test: document.getElementById('mailTest'),
    remove: document.getElementById('mailDelete'),
    hint: document.getElementById('mailHint')
  };
  var PORTS = { starttls: 587, tls: 465, none: 25 };
  var SECURITY = { starttls: 'STARTTLS', tls: 'SSL/TLS', none: 'ohne Verschlüsselung' };
  var ERRORS = {
    mail_host_invalid: 'Der SMTP-Server ist ungültig. Nur den Namen eintragen, zum Beispiel smtp.example.com.',
    mail_port_invalid: 'Der Port muss zwischen 1 und 65535 liegen.',
    mail_security_invalid: 'Bitte eine Verschlüsselung wählen.',
    mail_user_invalid: 'Der Benutzer ist zu lang oder enthält Zeilenumbrüche.',
    mail_pass_invalid: 'Das Passwort ist zu lang oder enthält Zeilenumbrüche.',
    mail_from_invalid: 'Die Absenderadresse ist keine gültige E-Mail-Adresse.',
    mail_from_name_invalid: 'Der Absendername darf höchstens 100 Zeichen haben, ohne Anführungszeichen und spitze Klammern.',
    mail_plain_not_local: 'Ohne Verschlüsselung nur zu einem Server auf diesem Rechner oder im lokalen Netz.',
    mail_plain_no_login: 'Ohne Verschlüsselung werden weder Benutzer noch Passwort gesendet. Felder leeren oder STARTTLS beziehungsweise SSL/TLS wählen.',
    mail_pass_required: 'Bitte das Passwort eingeben. Bei einem neuen Server oder Benutzer ist es immer nötig.',
    mail_user_required: 'Zum Passwort gehört ein Benutzer.',
    mail_env_locked: 'Der Versand steht in der Serverkonfiguration und lässt sich hier nicht ändern.',
    mail_test_no_address: 'Dein Konto hat keine E-Mail-Adresse, an die die Testmail gehen könnte.',
    owner_only: 'Nur der Inhaber kann den E-Mail-Versand einrichten.',
    csrf_failed: 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'
  };

  var state = null;
  var loaded = false;
  var loading = false;
  var busy = false;
  var dirty = false;

  function request(action, body) {
    var opts = { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' } };
    if (body) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CoreUI-CSRF'] = (state && state.csrf_token) || '';
      opts.body = JSON.stringify(body);
    }
    return fetch(ADMIN_API + '?action=' + action, opts).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data.ok) {
          var err = new Error(data.message || ERRORS[data.error] || ('Fehler: ' + (data.error || ('HTTP ' + response.status))));
          err.status = response.status;
          err.code = data.error || '';
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function hint(text, kind) {
    el.hint.textContent = text || '';
    if (kind) el.hint.setAttribute('data-state', kind); else el.hint.removeAttribute('data-state');
  }

  function when(iso) {
    var d = iso ? new Date(iso) : null;
    if (!d || isNaN(d.getTime())) return '';
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ', '
      + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr';
  }

  function stored() { return state && state.mail ? state.mail : { source: 'none' }; }

  /* A stored password is kept only for the same server and user. */
  function passwordKept() {
    var m = stored();
    return m.source === 'db' && m.has_password && !m.password_unreadable
      && el.host.value.trim().toLowerCase() === String(m.host || '') && el.user.value.trim() === String(m.user || '');
  }

  function syncFields() {
    var locked = stored().source === 'env';
    var plain = el.security.value === 'none';
    el.port.placeholder = String(PORTS[el.security.value] || 587);
    el.user.disabled = locked || plain;
    el.pass.disabled = locked || plain;
    if (plain && !locked) { el.user.value = ''; el.pass.value = ''; }
    var keep = !locked && passwordKept();
    el.pass.placeholder = keep ? '•••••••• gespeichert' : '';
    el.pass.title = keep ? 'Leer lassen behält das gespeicherte Passwort.' : '';
  }

  function render(data) {
    state = data;
    var m = stored();
    var locked = m.source === 'env';
    card.hidden = false;

    el.host.value = m.host || '';
    el.security.value = PORTS[m.security] ? m.security : 'starttls';
    el.port.value = m.port && m.port !== PORTS[el.security.value] ? String(m.port) : '';
    el.user.value = m.user || '';
    el.pass.value = '';
    dirty = false;
    el.from.value = m.from || '';
    el.fromName.value = m.from_name || '';
    [el.host, el.security, el.port, el.from, el.fromName].forEach(function (input) { input.disabled = locked; });
    el.save.hidden = locked;
    el.remove.hidden = m.source !== 'db';
    syncFields();

    var text;
    var kind = m.ready ? 'ready' : 'off';
    if (m.source === 'env') {
      text = 'Festgelegt in der Serverkonfiguration (api/config.local.php oder COREUI_SMTP_*): ' + m.host + ':' + m.port
        + ', ' + SECURITY[m.security] + '. Ändern nur dort.';
      if (!m.ready) { text += ' Die Angaben dort sind unvollständig.'; kind = 'warn'; }
    } else if (m.source === 'db') {
      text = 'Eingerichtet: ' + m.host + ':' + m.port + ', ' + SECURITY[m.security]
        + (m.user ? ', Anmeldung als ' + m.user : ', ohne Anmeldung') + '.'
        + (m.updated_at ? ' Gespeichert am ' + when(m.updated_at) + '.' : '');
      if (m.password_unreadable) {
        text = 'Das gespeicherte Passwort ist nicht mehr lesbar (Datenbank-Passwort des Servers geändert?). Bitte neu eingeben und speichern.';
        kind = 'warn';
      }
    } else {
      text = 'Noch nicht eingerichtet. Ohne SMTP-Server verschickt CoreAI keine E-Mails.';
    }
    el.status.textContent = text;
    el.status.setAttribute('data-state', kind);

    el.test.disabled = !m.ready || !data.test_address;
    el.test.title = data.test_address ? 'Geht an ' + data.test_address : ERRORS.mail_test_no_address;
  }

  function collect() {
    return {
      host: el.host.value.trim(),
      security: el.security.value,
      port: el.port.value ? Number(el.port.value) : 0,
      user: el.user.value.trim(),
      pass: el.pass.value,
      from: el.from.value.trim(),
      from_name: el.fromName.value.trim()
    };
  }

  function run(button, action, body, done) {
    if (busy) return;
    busy = true;
    button.disabled = true;
    hint(action === 'mail_test' ? 'Testmail wird gesendet …' : 'Wird gespeichert …');
    request(action, body).then(function (data) {
      render(data);
      hint(done(data), 'ok');
    }).catch(function (error) {
      if (error.data && error.data.mail) render(error.data);
      hint(error.message, 'error');
    }).then(function () {
      busy = false;
      button.disabled = false;
      if (state) el.test.disabled = !stored().ready || !state.test_address;
    });
  }

  function load() {
    if (loading) return;
    loading = true;
    request('mail_settings').then(function (data) {
      loaded = true;
      render(data);
    }).catch(function (error) {
      // Admins and users get owner_only: the card stays hidden for them.
      if (error.status === 403 || error.status === 401) { card.hidden = true; loaded = true; return; }
      card.hidden = false;
      el.status.textContent = 'E-Mail-Versand: ' + error.message;
      el.status.setAttribute('data-state', 'warn');
    }).then(function () { loading = false; });
  }

  el.form.addEventListener('submit', function (event) {
    event.preventDefault();
    run(el.save, 'mail_settings_save', { mail: collect() }, function () {
      return 'Gespeichert. Mit TESTMAIL SENDEN prüfen, ob die Mail ankommt.';
    });
  });
  el.test.addEventListener('click', function () {
    // The test uses what is stored, not what is typed but not yet saved.
    if (dirty && stored().source !== 'env') { hint('Bitte zuerst speichern. Die Testmail nutzt die gespeicherten Angaben.', 'error'); return; }
    run(el.test, 'mail_test', {}, function (data) {
      return 'Testmail an ' + data.sent_to + ' gesendet. Bitte Posteingang und Spam-Ordner prüfen.';
    });
  });
  el.remove.addEventListener('click', function () {
    if (!window.confirm('SMTP-Einstellungen entfernen? Danach verschickt CoreAI keine E-Mails mehr.')) return;
    run(el.remove, 'mail_settings_delete', {}, function () { return 'Entfernt.'; });
  });
  el.form.addEventListener('input', function () { dirty = true; });
  el.form.addEventListener('change', function () { dirty = true; });
  el.security.addEventListener('change', syncFields);
  el.host.addEventListener('input', syncFields);
  el.user.addEventListener('input', syncFields);

  document.addEventListener('coreai:view', function (event) {
    var view = event && event.detail ? event.detail.view : '';
    if (view === 'admin-betrieb' && !loaded) load();
  });
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (tabs && typeof tabs.current === 'function' && tabs.current() === 'admin-betrieb') load();
})();
