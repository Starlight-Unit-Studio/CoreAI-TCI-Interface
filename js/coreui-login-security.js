/* CoreAI: TCI Interface 0.6.85: the sign-in page's second step and passkeys.
   - "Mit Passkey anmelden": WebAuthn (passkey on this device, a phone or a security key);
     counts as both factors, works without typing the e-mail (discoverable passkeys) or with it
     (security keys that need the account).
   - after the password, when the account uses a second factor: the code from the authenticator
     app, a code by mail (sent on request) or a recovery code. */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var step = $('mfaStep');
  var form = $('loginForm');
  if (!step || !form) return;
  var state = {methods: {}, method: '', busy: false};

  var TEXT = {
    totp: 'Gib den 6-stelligen Code aus deiner Authenticator-App ein.',
    email: 'Wir schicken dir einen 6-stelligen Code per E-Mail.',
    recovery: 'Gib einen deiner Wiederherstellungscodes ein (zum Beispiel abcde-fghjk). Jeder Code gilt einmal.'
  };
  var ERRORS = {
    mfa_invalid: 'Der Code stimmt nicht.',
    mfa_expired: 'Die Anmeldung ist abgelaufen. Bitte das Passwort noch einmal eingeben.',
    mfa_email_wait: 'Bitte kurz warten, bevor ein neuer Code verschickt wird.',
    mfa_email_failed: 'Die E-Mail konnte nicht gesendet werden.',
    mfa_email_unavailable: 'Der E-Mail-Code ist gerade nicht verfügbar.',
    passkey_unknown: 'Dieser Passkey gehört zu keinem Konto auf diesem Server.',
    passkey_invalid: 'Der Passkey konnte nicht bestätigt werden.',
    passkey_expired: 'Die Anfrage ist abgelaufen. Bitte noch einmal versuchen.',
    passkey_unavailable: 'Passkeys sind auf diesem Server nicht eingerichtet.',
    security_migration_required: 'Das Update ist noch nicht vollständig eingespielt (Datenbank-Migration 030).',
    rate_limited: 'Zu viele Versuche. Bitte warten.',
    banned: 'Konto gesperrt.',
    maintenance_mode: 'Wartungsmodus: gerade keine Anmeldung.'
  };

  function error(text) {
    var el = $('loginError');
    el.textContent = text;
    el.style.display = text ? 'block' : 'none';
  }
  function note(text) { $('mfaNote').textContent = text || ''; }

  function post(action, body) {
    return fetch('./api/auth.php?action=' + action, {
      method: 'POST', credentials: 'include', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  // --- base64url <-> ArrayBuffer ---------------------------------------------------------------
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

  // --- Second step -------------------------------------------------------------------------------
  function choose(method) {
    state.method = method;
    $('mfaText').textContent = TEXT[method];
    $('mfaCode').value = '';
    $('mfaCode').setAttribute('inputmode', method === 'recovery' ? 'text' : 'numeric');
    $('mfaCode').setAttribute('maxlength', method === 'recovery' ? '11' : '6');
    $('mfaCodeLabel').textContent = method === 'recovery' ? 'WIEDERHERSTELLUNGSCODE' : 'CODE';
    $('mfaSend').hidden = method !== 'email';
    ['totp', 'email', 'recovery'].forEach(function (m) {
      var b = $('mfaUse-' + m);
      b.hidden = !state.methods[m] || m === method;
    });
    note('');
    error('');
    if (method !== 'email') $('mfaCode').focus();
  }

  function show(methods) {
    state.methods = methods || {};
    form.hidden = true;
    step.hidden = false;
    choose(state.methods.totp ? 'totp' : (state.methods.email ? 'email' : 'recovery'));
  }

  function back(message) {
    step.hidden = true;
    form.hidden = false;
    $('password').value = '';
    $('password').focus();
    error(message || '');
  }

  function verify() {
    var code = $('mfaCode').value.trim();
    if (!code) { error('Bitte den Code eingeben.'); return; }
    if (state.busy) return;
    state.busy = true;
    $('mfaBtn').disabled = true;
    post('mfa_verify', {method: state.method, code: code}).then(function (d) {
      if (d.ok) { location.replace('app.html'); return; }
      if (d.error === 'mfa_expired') { back(ERRORS.mfa_expired); return; }
      var left = Number(d.tries_left);
      error((ERRORS[d.error] || ('Fehler: ' + (d.error || 'unbekannt'))) + (left > 0 ? ' Noch ' + left + (left === 1 ? ' Versuch.' : ' Versuche.') : ''));
      $('mfaCode').select();
    }).catch(function () { error('Verbindungsfehler. Server nicht erreichbar.'); })
      .then(function () { state.busy = false; $('mfaBtn').disabled = false; });
  }

  function sendMail() {
    $('mfaSend').disabled = true;
    post('mfa_email', {}).then(function (d) {
      if (d.ok) { note('Code an ' + (d.sent_to || 'deine E-Mail-Adresse') + ' gesendet. Er gilt 10 Minuten.'); $('mfaCode').focus(); return; }
      if (d.error === 'mfa_expired') { back(ERRORS.mfa_expired); return; }
      error(ERRORS[d.error] || ('Fehler: ' + (d.error || 'unbekannt')));
    }).catch(function () { error('Verbindungsfehler. Server nicht erreichbar.'); })
      .then(function () { window.setTimeout(function () { $('mfaSend').disabled = false; }, 3000); });
  }

  // --- Passkey ----------------------------------------------------------------------------------------
  var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get) && window.isSecureContext;
  var passkeyBtn = $('passkeyBtn');
  if (passkeyBtn) passkeyBtn.hidden = !supported;

  function passkey() {
    if (!supported || state.busy) return;
    state.busy = true;
    passkeyBtn.disabled = true;
    passkeyBtn.textContent = 'PASSKEY WIRD GEPRÜFT…';
    error('');
    post('passkey_options', {username: $('email').value.trim()}).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'passkey_unavailable');
      var pk = d.publicKey;
      pk.challenge = toBuf(pk.challenge);
      pk.allowCredentials = (pk.allowCredentials || []).map(function (c) { return {type: c.type, id: toBuf(c.id)}; });
      return navigator.credentials.get({publicKey: pk});
    }).then(function (cred) {
      var r = cred.response;
      return post('passkey_login', {credential: {id: cred.id, type: cred.type, response: {
        clientDataJSON: toB64u(r.clientDataJSON), authenticatorData: toB64u(r.authenticatorData),
        signature: toB64u(r.signature), userHandle: r.userHandle ? toB64u(r.userHandle) : ''}}});
    }).then(function (d) {
      if (d.ok) { location.replace('app.html'); return; }
      throw new Error(d.error || 'passkey_invalid');
    }).catch(function (err) {
      var name = err && err.name;
      if (name === 'NotAllowedError' || name === 'AbortError') error('Die Anmeldung mit Passkey wurde abgebrochen.');
      else error(ERRORS[err && err.message] || 'Die Anmeldung mit Passkey hat nicht geklappt.');
    }).then(function () {
      state.busy = false;
      passkeyBtn.disabled = false;
      passkeyBtn.textContent = 'MIT PASSKEY ANMELDEN';
    });
  }

  $('mfaBtn').addEventListener('click', verify);
  $('mfaCode').addEventListener('keydown', function (e) { if (e.key === 'Enter') verify(); });
  $('mfaSend').addEventListener('click', sendMail);
  ['totp', 'email', 'recovery'].forEach(function (m) { $('mfaUse-' + m).addEventListener('click', function () { choose(m); }); });
  $('mfaBack').addEventListener('click', function () { back(''); });
  if (passkeyBtn) passkeyBtn.addEventListener('click', passkey);

  window.CoreAILoginSecurity = {mfa: show, passkey: passkey, supported: supported};
})();
