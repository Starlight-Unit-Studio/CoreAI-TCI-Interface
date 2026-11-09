/* CoreAI: TCI Interface 0.6.85: Settings > Sicherheit und Anmeldung, modeled on the ChatGPT page.
   Overview rows with sub pages ("Zurück"): passkeys and security keys, password, account PIN
   (planned), authenticator app with QR code, push confirmation (planned), E-Mail code, recovery
   codes, active sessions with "Überall abmelden", security history, advanced account security,
   Lockdown-Modus, CSP for custom apps (planned). Sensitive changes ask for the current password.
   Data: api/account_security.php (js/settings.js loads it and shares it via coreai:security). */
(function () {
  'use strict';

  var view = document.getElementById('view-sicherheit');
  if (!view) return;
  var $ = function (id) { return document.getElementById(id); };
  var API = './api/account_security.php';
  var state = {security: null, sessions: [], csrf: '', loaded: false, recovery: [], afterCodes: null};
  var webauthn = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create) && window.isSecureContext;

  var ERRORS = {
    invalid_current_password: 'Das aktuelle Passwort stimmt nicht.',
    code_invalid: 'Der Code stimmt nicht. Bitte den aktuellen Code eingeben.',
    setup_expired: 'Die Einrichtung ist abgelaufen. Bitte noch einmal starten.',
    mail_not_configured: 'Auf diesem Server ist kein E-Mail-Versand eingerichtet.',
    mail_failed: 'Die E-Mail konnte nicht gesendet werden.',
    passkey_invalid: 'Der Passkey konnte nicht geprüft werden.',
    passkey_expired: 'Die Anfrage ist abgelaufen. Bitte noch einmal versuchen.',
    passkey_exists: 'Dieser Passkey ist schon gespeichert.',
    passkey_limit: 'Es sind schon 20 Passkeys gespeichert.',
    passkey_unavailable: 'Passkeys brauchen eine Adresse mit Namen (nicht nur eine IP-Adresse).',
    passkey_not_found: 'Diesen Passkey gibt es nicht mehr.',
    advanced_needs_passkey: 'Für die erweiterte Kontosicherheit brauchst du mindestens einen Passkey.',
    security_migration_required: 'Das Update ist noch nicht vollständig eingespielt (Datenbank-Migration 030).',
    rate_limited: 'Zu viele Versuche. Bitte später noch einmal.',
    guest_not_allowed: 'Gäste haben keine Sicherheitseinstellungen.'
  };

  function status(text, error) {
    var el = $('secStatus');
    el.textContent = text || '';
    el.setAttribute('data-state', error ? 'error' : 'ok');
  }
  function errText(err) {
    var code = err && err.message;
    return ERRORS[code] || ('Fehler: ' + (code || 'unbekannt'));
  }

  function post(action, body) {
    var payload = Object.assign({action: action}, body || {});
    return fetch(API, {method: 'POST', credentials: 'include', cache: 'no-store',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CoreUI-CSRF': state.csrf},
      body: JSON.stringify(payload)}).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (d.csrf_token) state.csrf = d.csrf_token;
        if (!r.ok || !d.ok) { var e = new Error(d.error || ('HTTP_' + r.status)); e.data = d; throw e; }
        return d;
      });
    });
  }
  function load() {
    return fetch(API, {credentials: 'include', cache: 'no-store', headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) accept(d); });
  }
  function accept(data) {
    if (data.csrf_token) state.csrf = data.csrf_token;
    if (data.sessions) state.sessions = data.sessions;
    if (data.security) state.security = data.security;
    state.loaded = true;
    render();
  }
  function shareSessions(d) {
    try { document.dispatchEvent(new CustomEvent('coreai:security-sessions', {detail: {sessions: d.sessions, csrf_token: d.csrf_token}})); } catch (e) {}
  }
  function apply(d) {
    accept(d);
    if (d.sessions) shareSessions(d);
  }

  // --- base64url ------------------------------------------------------------------------------------
  function toBuf(b64u) {
    var s = String(b64u || '').replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }
  function toB64u(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function dateText(value, withTime) {
    if (!value) return '';
    var d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(value);
    var day = d.toLocaleDateString('de-DE', {day: 'numeric', month: 'long', year: 'numeric'});
    return withTime ? day + ', ' + d.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + ' Uhr' : day;
  }

  // --- Sub pages ------------------------------------------------------------------------------------------
  var current = '';
  function open(name) {
    current = name;
    $('secMain').hidden = true;
    Array.prototype.forEach.call(view.querySelectorAll('.sec-sub'), function (el) { el.hidden = el.id !== 'secSub-' + name; });
    status('');
    var sub = $('secSub-' + name);
    if (sub && sub.scrollIntoView) sub.scrollIntoView({block: 'start'});
  }
  function back() {
    current = '';
    Array.prototype.forEach.call(view.querySelectorAll('.sec-sub'), function (el) { el.hidden = true; });
    $('secMain').hidden = false;
    render();
  }

  // --- Password confirmation -----------------------------------------------------------------------------
  var pending = null;
  function confirmPassword(title, text, error) {
    return new Promise(function (resolve) {
      pending = resolve;
      $('secConfirmTitle').textContent = title;
      $('secConfirmText').textContent = text;
      $('secConfirmPassword').value = '';
      $('secConfirmError').textContent = error || '';
      $('secConfirm').hidden = false;
      $('secConfirmPassword').focus();
    });
  }
  function closeConfirm(value) {
    $('secConfirm').hidden = true;
    var done = pending;
    pending = null;
    if (done) done(value);
  }

  // --- Rendering ----------------------------------------------------------------------------------------------
  function render() {
    var sec = state.security || {};
    var m = sec.methods || {};
    $('secPasskeyCount').textContent = String(m.passkeys || 0);
    $('secSessionCount').textContent = String((state.sessions || []).length);
    $('secTotp').checked = !!m.totp;
    $('secEmail').checked = !!m.email;
    $('secEmail').disabled = !m.email && !sec.email_available;
    $('secRow-email').querySelector('small').textContent = sec.email_available || m.email
      ? 'Erhalte 6-stellige Bestätigungscodes per E-Mail' + (sec.email_hint ? ' an ' + sec.email_hint : '') + '.'
      : 'Erhalte 6-stellige Bestätigungscodes per E-Mail. Dafür muss der Inhaber den E-Mail-Versand einrichten.';
    $('secLockdown').checked = !!m.lockdown;
    var mfa = !!(m.totp || m.email);
    $('secRow-recovery').hidden = !mfa && !(m.recovery_left > 0);
    $('secRecoveryCount').textContent = (m.recovery_left || 0) + ' von 10 übrig';
    $('secAdvancedState').textContent = m.advanced ? 'Aktiv' : 'Registrieren';
    $('secRow-advanced').classList.toggle('is-on', !!m.advanced);
    renderPasskeys(sec.passkeys || [], m);
    renderHistory(sec.history || []);
    renderAdvanced(m);
    if (sec.ready === false) status(ERRORS.security_migration_required, true);
  }

  function renderPasskeys(list, m) {
    var ul = $('secPasskeyList');
    ul.textContent = '';
    var hint = $('secPasskeyHint');
    hint.hidden = webauthn;
    hint.textContent = webauthn ? '' : 'Dieser Browser kann hier keine Passkeys anlegen. Passkeys brauchen eine sichere Verbindung (HTTPS) und einen aktuellen Browser.';
    $('secPasskeyAdd').disabled = !webauthn;
    if (!list.length) {
      var empty = document.createElement('li');
      empty.className = 'sec-empty';
      empty.textContent = 'Noch kein Passkey. Mit einem Passkey meldest du dich per Fingerabdruck, Gesicht oder Geräte-PIN an.';
      ul.appendChild(empty);
    }
    list.forEach(function (pk) {
      var li = document.createElement('li');
      li.className = 'sec-item';
      li.setAttribute('data-passkey', pk.id);
      li.innerHTML = '<svg aria-hidden="true"><use href="#i-key" /></svg>';
      var copy = document.createElement('div');
      var name = document.createElement('strong');
      name.textContent = pk.label;
      var small = document.createElement('small');
      small.textContent = 'Hinzugefügt: ' + dateText(pk.created_at) + (pk.last_used_at ? ' · Zuletzt benutzt: ' + dateText(pk.last_used_at, true) : '');
      copy.appendChild(name);
      copy.appendChild(small);
      li.appendChild(copy);
      var rename = document.createElement('button');
      rename.type = 'button';
      rename.className = 'settings-action compact';
      rename.textContent = 'UMBENENNEN';
      rename.addEventListener('click', function () { renamePasskey(pk); });
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'settings-action compact danger';
      remove.textContent = 'ENTFERNEN';
      remove.disabled = !!m.advanced && list.length <= 1;
      remove.title = remove.disabled ? 'Die erweiterte Kontosicherheit braucht mindestens einen Passkey.' : '';
      remove.addEventListener('click', function () { removePasskey(pk); });
      var actions = document.createElement('div');
      actions.className = 'sec-item-actions';
      actions.appendChild(rename);
      actions.appendChild(remove);
      li.appendChild(actions);
      ul.appendChild(li);
    });
  }

  function renderHistory(list) {
    var ol = $('secHistory');
    ol.textContent = '';
    if (!list.length) {
      var empty = document.createElement('li');
      empty.className = 'sec-empty';
      empty.textContent = 'Noch keine Einträge.';
      ol.appendChild(empty);
      return;
    }
    list.forEach(function (ev) {
      var li = document.createElement('li');
      li.className = 'sec-history-item kind-' + ev.kind;
      var strong = document.createElement('strong');
      strong.textContent = ev.label;
      var small = document.createElement('small');
      small.textContent = [dateText(ev.at, true), ev.device, ev.detail].filter(Boolean).join(' · ');
      li.appendChild(strong);
      li.appendChild(small);
      ol.appendChild(li);
    });
  }

  function renderAdvanced(m) {
    $('secAdvancedEnable').hidden = !!m.advanced;
    $('secAdvancedDisable').hidden = !m.advanced;
    $('secAdvancedInfo').textContent = m.advanced
      ? 'Aktiv: Anmeldung nur mit Passkey oder Sicherheitsschlüssel, kein Zurücksetzen per E-Mail, Sitzungen 24 Stunden.'
      : (m.passkeys > 0 ? 'Du hast ' + m.passkeys + (m.passkeys === 1 ? ' Passkey.' : ' Passkeys.') + ' Du kannst die erweiterte Kontosicherheit jetzt aktivieren.'
        : 'Füge zuerst unter Passkeys und Sicherheitsschlüssel mindestens einen Passkey hinzu.');
  }

  function showCodes(codes, text, next) {
    state.recovery = codes || [];
    state.afterCodes = next || null;
    var ol = $('secRecoveryCodes');
    ol.textContent = '';
    state.recovery.forEach(function (c) { var li = document.createElement('li'); li.textContent = c; ol.appendChild(li); });
    $('secRecoveryNew').hidden = false;
    $('secRecoveryCopy').hidden = !state.recovery.length;
    $('secRecoveryDownload').hidden = !state.recovery.length;
    $('secRecoveryText').textContent = text || (state.recovery.length
      ? 'Deine neuen Wiederherstellungscodes. Sie werden nur jetzt angezeigt. Jeder Code gilt einmal.'
      : 'Jeder Code gilt einmal. Bewahre sie sicher auf, zum Beispiel ausgedruckt oder in einem Passwortmanager.');
    open('recovery');
  }

  // --- Actions --------------------------------------------------------------------------------------------------
  function withPassword(title, text, action, body, done, error) {
    return confirmPassword(title, text, error).then(function (password) {
      if (password === null) { render(); return null; }
      return post(action, Object.assign({password: password}, body || {})).then(function (d) {
        return done ? done(d) : (apply(d), d);
      }).catch(function (err) {
        if (err.message === 'invalid_current_password') return withPassword(title, text, action, body, done, errText(err));
        render();
        status(errText(err), true);
        return null;
      });
    });
  }

  $('secTotp').addEventListener('change', function () {
    var on = $('secTotp').checked;
    if (on) {
      withPassword('Authenticator-App einrichten', 'Bestätige mit deinem Passwort, um die Einrichtung zu starten.', 'totp_begin', {}, function (d) {
        var box = $('secTotpQr');
        box.textContent = '';
        var svg = window.CoreAIQR && window.CoreAIQR.svg(d.uri, 'QR-Code für die Authenticator-App');
        if (svg) box.appendChild(svg);
        $('secTotpSecret').textContent = String(d.secret).replace(/(.{4})/g, '$1 ').trim();
        $('secTotpSecret').setAttribute('data-secret', d.secret);
        $('secTotpCode').value = '';
        open('totp');
        $('secTotpCode').focus();
      });
    } else {
      withPassword('Authenticator-App entfernen', 'Danach fragt die Anmeldung keinen Code aus der App mehr ab.', 'totp_disable', {}, function (d) {
        apply(d);
        status('Authenticator-App entfernt.');
      });
    }
  });
  $('secTotpEnable').addEventListener('click', function () {
    post('totp_enable', {code: $('secTotpCode').value.trim()}).then(function (d) {
      apply(d);
      if (d.recovery_codes && d.recovery_codes.length) showCodes(d.recovery_codes);
      else { back(); status('Authenticator-App eingerichtet.'); }
    }).catch(function (err) { status(errText(err), true); });
  });
  $('secTotpCopy').addEventListener('click', function () {
    var secret = $('secTotpSecret').getAttribute('data-secret') || '';
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(secret).then(function () { status('Schlüssel kopiert.'); });
  });

  $('secEmail').addEventListener('change', function () {
    var on = $('secEmail').checked;
    if (on) {
      withPassword('E-Mail-Code einschalten', 'Bestätige mit deinem Passwort. Danach schicken wir dir einen Code.', 'email_begin', {}, function (d) {
        $('secEmailSent').textContent = 'Wir haben einen Code an ' + (d.sent_to || 'deine E-Mail-Adresse') + ' geschickt. Er gilt 10 Minuten.';
        $('secEmailCode').value = '';
        open('email');
        $('secEmailCode').focus();
      });
    } else {
      withPassword('E-Mail-Code ausschalten', 'Danach fragt die Anmeldung keinen Code per E-Mail mehr ab.', 'email_disable', {}, function (d) {
        apply(d);
        status('E-Mail-Code ausgeschaltet.');
      });
    }
  });
  $('secEmailEnable').addEventListener('click', function () {
    post('email_enable', {code: $('secEmailCode').value.trim()}).then(function (d) {
      apply(d);
      if (d.recovery_codes && d.recovery_codes.length) showCodes(d.recovery_codes);
      else { back(); status('E-Mail-Code eingeschaltet.'); }
    }).catch(function (err) { status(errText(err), true); });
  });

  $('secRecoveryOpen').addEventListener('click', function () { showCodes([]); });
  $('secRecoveryNew').addEventListener('click', function () {
    withPassword('Neue Wiederherstellungscodes', 'Die bisherigen Codes werden dabei ungültig.', 'recovery_new', {}, function (d) {
      apply(d);
      showCodes(d.recovery_codes || []);
    });
  });
  $('secRecoveryCopy').addEventListener('click', function () {
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(state.recovery.join('\n')).then(function () { status('Codes kopiert.'); });
  });
  $('secRecoveryDownload').addEventListener('click', function () {
    var blob = new Blob(['CoreAI: Wiederherstellungscodes\n\n' + state.recovery.join('\n') + '\n\nJeder Code gilt einmal.\n'], {type: 'text/plain'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'coreai-wiederherstellungscodes.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });

  $('secLockdown').addEventListener('change', function () {
    var on = $('secLockdown').checked;
    post('lockdown', {on: on}).then(function (d) {
      apply(d);
      status(on ? 'Lockdown-Modus an: Websuche und Recherche im Browser sind für deine Chats aus.' : 'Lockdown-Modus aus.');
    }).catch(function (err) { render(); status(errText(err), true); });
  });

  $('secPasskeyAdd').addEventListener('click', function () {
    if (!webauthn) return;
    $('secPasskeyAdd').disabled = true;
    post('passkey_options', {}).then(function (d) {
      var pk = d.publicKey;
      pk.challenge = toBuf(pk.challenge);
      pk.user.id = toBuf(pk.user.id);
      pk.excludeCredentials = (pk.excludeCredentials || []).map(function (c) { return {type: c.type, id: toBuf(c.id)}; });
      return navigator.credentials.create({publicKey: pk});
    }).then(function (cred) {
      return post('passkey_add', {credential: {id: cred.id, type: cred.type, response: {
        clientDataJSON: toB64u(cred.response.clientDataJSON), attestationObject: toB64u(cred.response.attestationObject)}}});
    }).then(function (d) {
      apply(d);
      status('Passkey hinzugefügt.');
    }).catch(function (err) {
      if (err && (err.name === 'NotAllowedError' || err.name === 'AbortError')) status('Abgebrochen.', true);
      else if (err && err.name === 'InvalidStateError') status(ERRORS.passkey_exists, true);
      else status(errText(err), true);
    }).then(function () { $('secPasskeyAdd').disabled = !webauthn; });
  });
  function renamePasskey(pk) {
    var label = window.prompt('Name für diesen Passkey', pk.label);
    if (label === null || !label.trim()) return;
    post('passkey_rename', {id: pk.id, label: label.trim()}).then(function (d) { apply(d); status('Passkey umbenannt.'); })
      .catch(function (err) { status(errText(err), true); });
  }
  function removePasskey(pk) {
    withPassword('Passkey entfernen', '„' + pk.label + '“ kann danach nicht mehr zur Anmeldung genutzt werden.', 'passkey_remove', {id: pk.id}, function (d) {
      apply(d);
      status('Passkey entfernt.');
    });
  }

  $('secRevokeAll').addEventListener('click', function () {
    if (!window.confirm('Überall abmelden? Auch dieses Gerät wird abgemeldet.')) return;
    post('sessions_revoke_all', {}).then(function () { location.replace('./login.html'); })
      .catch(function (err) { status(errText(err), true); });
  });

  $('secAdvancedEnable').addEventListener('click', function () {
    var m = (state.security || {}).methods || {};
    if (!m.passkeys) { open('passkeys'); status(ERRORS.advanced_needs_passkey, true); return; }
    withPassword('Erweiterte Kontosicherheit aktivieren', 'Danach meldest du dich nur noch mit Passkey an und wirst jetzt überall abgemeldet.', 'advanced_enable', {}, function (d) {
      if (d.recovery_codes && d.recovery_codes.length) {
        showCodes(d.recovery_codes, 'Erweiterte Kontosicherheit ist aktiv. Speichere jetzt deine Wiederherstellungscodes: sie werden nur einmal angezeigt. Danach meldest du dich mit deinem Passkey neu an.',
          function () { location.replace('./login.html'); });
        $('secRecoveryNew').hidden = true;
      } else {
        location.replace('./login.html');
      }
    });
  });
  $('secAdvancedDisable').addEventListener('click', function () {
    withPassword('Erweiterte Kontosicherheit beenden', 'Danach ist die Anmeldung mit Passwort wieder möglich.', 'advanced_disable', {}, function (d) {
      apply(d);
      status('Erweiterte Kontosicherheit beendet.');
    });
  });

  Array.prototype.forEach.call(view.querySelectorAll('[data-sec-open]'), function (btn) {
    btn.addEventListener('click', function () { open(btn.getAttribute('data-sec-open')); });
  });
  Array.prototype.forEach.call(view.querySelectorAll('[data-sec-back]'), function (btn) {
    btn.addEventListener('click', function () {
      if (current === 'recovery' && state.afterCodes) { state.afterCodes(); return; }
      back();
    });
  });
  $('secConfirmOk').addEventListener('click', function () { closeConfirm($('secConfirmPassword').value); });
  $('secConfirmCancel').addEventListener('click', function () { closeConfirm(null); });
  $('secConfirmPassword').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') closeConfirm($('secConfirmPassword').value);
    if (e.key === 'Escape') closeConfirm(null);
  });

  document.addEventListener('coreai:security', function (event) {
    var d = event && event.detail ? event.detail : null;
    if (!d) return;
    if (d.security) accept(d);
    else load();
  });
  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'sicherheit' && !state.loaded) load();
  });
  render();
  // js/settings.js normally shares the data; load it here when that did not happen.
  window.setTimeout(function () { if (!state.loaded && !view.hidden) load(); }, 1500);
  window.CoreAISecuritySettings = {open: open, back: back};
})();
