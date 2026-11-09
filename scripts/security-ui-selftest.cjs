// CoreAI 0.6.85: Settings > Sicherheit und Anmeldung and the sign-in in a real browser (Playwright).
//   node scripts/security-ui-selftest.cjs
// A stand-in for api/account_security.php and api/auth.php keeps the state; Chromium's virtual
// authenticator (CDP WebAuthn) makes real passkeys. Checks: the overview like the reference
// (rows, counts, planned PIN, push and CSP), password confirmation (wrong, then right), the
// authenticator app with QR code and key, recovery codes (shown once, copy, file), the E-Mail
// code, Lockdown-Modus, a real passkey added (client data checked) and renamed and removed,
// sessions with "Überall abmelden", the history, advanced account security (needs a passkey,
// shows the codes, then signs out); the sign-in: second step with authenticator code (wrong,
// then right), code by mail, recovery code, a real passkey sign-in, passkey_required; a phone.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';
const b64u = buf => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64u = s => Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
const CODES = Array.from({length: 10}, (_, i) => 'abcde-' + 'fghj' + i);

function securityServer() {
  const st = {
    methods: {ready: true, totp: false, email: false, passkeys: 0, recovery_left: 0, advanced: false, lockdown: false},
    passkeys: [], history: [
      {kind: 'login_failed', label: 'Fehlgeschlagene Anmeldung', device: 'Firefox auf Linux', detail: 'Passwort', at: '2026-11-09 08:10:00'},
      {kind: 'login', label: 'Anmeldung', device: 'Chrome auf Android', detail: 'Passwort', at: '2026-11-09 08:00:00'}],
    sessions: [{id: 1, device_label: 'Chrome auf Android', current: true, last_seen_at: '2026-11-09 09:00:00', expires_at: '2026-12-09 09:00:00'},
      {id: 2, device_label: 'Firefox auf Linux', current: false, last_seen_at: '2026-11-08 20:00:00', expires_at: '2026-12-08 20:00:00'}],
    posts: [], challenge: '', created: null,
  };
  const security = () => ({ready: true, methods: Object.assign({}, st.methods, {passkeys: st.passkeys.length}), passkeys: st.passkeys,
    passkey_rp_id: 'coreui.test', email_available: true, email_hint: 'l•••@example.test', history: st.history});
  const full = extra => Object.assign({ok: true, security: security(), sessions: st.sessions, csrf_token: 'tok'}, extra || {});
  function handle(method, body) {
    if (method === 'GET') return {status: 200, json: Object.assign(full(), {account: {email: 'lena@example.test'}})};
    st.posts.push(body);
    const needPw = ['totp_begin', 'totp_disable', 'email_begin', 'email_disable', 'recovery_new', 'passkey_remove', 'advanced_enable', 'advanced_disable'];
    if (needPw.includes(body.action) && body.password !== 'richtig') return {status: 403, json: {ok: false, error: 'invalid_current_password'}};
    switch (body.action) {
      case 'totp_begin': return {status: 200, json: {ok: true, secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', csrf_token: 'tok',
        uri: 'otpauth://totp/CoreAI:lena%40example.test?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=CoreAI&algorithm=SHA1&digits=6&period=30'}};
      case 'totp_enable':
        if (body.code !== '123456') return {status: 400, json: {ok: false, error: 'code_invalid'}};
        st.methods.totp = true; st.methods.recovery_left = 10;
        return {status: 200, json: full({recovery_codes: CODES})};
      case 'email_begin': return {status: 200, json: {ok: true, sent_to: 'l•••@example.test', csrf_token: 'tok'}};
      case 'email_enable':
        if (body.code !== '654321') return {status: 400, json: {ok: false, error: 'code_invalid'}};
        st.methods.email = true;
        return {status: 200, json: full({recovery_codes: []})};
      case 'recovery_new': st.methods.recovery_left = 10; return {status: 200, json: full({recovery_codes: CODES})};
      case 'lockdown': st.methods.lockdown = !!body.on; return {status: 200, json: full()};
      case 'passkey_options':
        st.challenge = b64u(require('node:crypto').randomBytes(32));
        return {status: 200, json: {ok: true, csrf_token: 'tok', publicKey: {challenge: st.challenge, rp: {name: 'CoreAI', id: 'coreui.test'},
          user: {id: b64u(Buffer.alloc(32, 7)), name: 'lena@example.test', displayName: 'Lena'},
          pubKeyCredParams: [{type: 'public-key', alg: -7}, {type: 'public-key', alg: -257}], timeout: 60000, attestation: 'none',
          authenticatorSelection: {residentKey: 'preferred', requireResidentKey: false, userVerification: 'preferred'}, excludeCredentials: []}}};
      case 'passkey_add': {
        const client = JSON.parse(unb64u(body.credential.response.clientDataJSON).toString());
        st.created = {client, attestationBytes: unb64u(body.credential.response.attestationObject).length, id: body.credential.id};
        st.passkeys.push({id: st.passkeys.length + 1, label: 'Chrome auf Linux', created_at: '2026-11-09 10:00:00', last_used_at: null});
        return {status: 200, json: full()};
      }
      case 'passkey_rename': st.passkeys.find(p => p.id === body.id).label = body.label; return {status: 200, json: full()};
      case 'passkey_remove': st.passkeys = st.passkeys.filter(p => p.id !== body.id); return {status: 200, json: full()};
      case 'advanced_enable': st.methods.advanced = true; return {status: 200, json: {ok: true, signed_out: true, recovery_codes: st.methods.recovery_left ? [] : CODES}};
      case 'advanced_disable': st.methods.advanced = false; return {status: 200, json: full()};
      case 'sessions_revoke_all': return {status: 200, json: {ok: true, signed_out: true, revoked_count: 2}};
      default: return {status: 400, json: {ok: false, error: 'unknown_action'}};
    }
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'health.php': {ok: true, version: '0.6.85', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function serve(context, handlers) {
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    for (const [match, fn] of handlers) {
      if (rel === match) {
        const out = fn(req.method(), req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null, url);
        return route.fulfill({status: out.status || 200, json: out.json});
      }
    }
    if (rel === 'app.html') return route.fulfill({contentType: 'text/html', body: '<!doctype html><title>app</title><p id="app">app</p>'});
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
}

async function authenticator(page) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', {options: {protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
    hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true}});
  return cdp;
}

const status = page => page.textContent('#secStatus');
async function confirmWith(page, password) {
  await page.waitForSelector('#secConfirm:not([hidden])');
  await page.fill('#secConfirmPassword', password);
  await page.click('#secConfirmOk');
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // --- Settings page ------------------------------------------------------------------------------------
    let context = await browser.newContext({viewport: {width: 1200, height: 900}, locale: 'de-DE'});
    let server = securityServer();
    await serve(context, [['api/account_security.php', server.handle]]);
    let page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.type() === 'prompt' ? d.accept('YubiKey 5') : d.accept());
    await authenticator(page);
    await page.goto('https://coreui.test/settings.html#sicherheit');
    await page.waitForFunction(() => document.getElementById('secSessionCount').textContent === '2');
    assert.deepEqual(await page.$$eval('#secMain .sec-heading', l => l.map(x => x.textContent)),
      ['So meldest du dich an', 'Multi-Faktor-Authentifizierung (MFA)', 'Sitzungen', 'Erweiterte Sicherheit', 'App-Sicherheit']);
    assert.deepEqual(await page.$$eval('#secMain .sec-row strong', l => l.map(x => x.textContent)), ['Passkeys und Sicherheitsschlüssel', 'Passwort', 'Konto-PIN',
      'Authenticator-App', 'Push-Benachrichtigungen', 'E-Mail-Code', 'Wiederherstellungscodes', 'Aktive Sitzungen', 'Sicherheitsverlauf',
      'Erweiterte Kontosicherheit', 'Lockdown-Modus', 'CSP für benutzerdefinierte Apps durchsetzen']);
    for (const id of ['pin', 'push', 'csp']) assert.match(await page.textContent('#secRow-' + id), /IN VORBEREITUNG/, id + ' planned');
    assert.equal(await page.isDisabled('#secPushMfa'), true);
    assert.equal(await page.isDisabled('#secCsp'), true);
    assert.equal(await page.textContent('#secPasskeyCount'), '0');
    assert.equal(await page.isVisible('#secRow-recovery'), false, 'no recovery codes before a second factor');
    assert.equal(await page.textContent('#secAdvancedState'), 'Registrieren');
    if (shots) await page.locator('#view-sicherheit').screenshot({path: path.join(shots, 'security-overview.png')});

    // Authenticator app: wrong password, then right; QR code and key; wrong code, then right; codes once.
    await page.click('#secRow-totp .settings-switch');
    await confirmWith(page, 'falsch');
    await page.waitForFunction(() => document.getElementById('secConfirmError').textContent !== '');
    assert.equal(await page.textContent('#secConfirmError'), 'Das aktuelle Passwort stimmt nicht.');
    await page.fill('#secConfirmPassword', 'richtig');
    await page.click('#secConfirmOk');
    await page.waitForSelector('#secSub-totp:not([hidden])');
    const viewBox = await page.getAttribute('#secTotpQr svg', 'viewBox');
    assert.equal(viewBox, '0 0 57 57', 'QR version 8 (49 modules) with quiet zone');
    assert.ok((await page.getAttribute('#secTotpQr svg path', 'd')).length > 2000, 'QR modules drawn');
    assert.equal(await page.textContent('#secTotpSecret'), 'JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP');
    await page.fill('#secTotpCode', '000000');
    await page.click('#secTotpEnable');
    await page.waitForFunction(() => /Code stimmt nicht/.test(document.getElementById('secStatus').textContent));
    await page.fill('#secTotpCode', '123456');
    await page.click('#secTotpEnable');
    await page.waitForSelector('#secSub-recovery:not([hidden])');
    assert.deepEqual(await page.$$eval('#secRecoveryCodes li', l => l.map(x => x.textContent)), CODES);
    assert.equal(await page.isVisible('#secRecoveryDownload'), true);
    const download = page.waitForEvent('download');
    await page.click('#secRecoveryDownload');
    assert.equal((await download).suggestedFilename(), 'coreai-wiederherstellungscodes.txt');
    if (shots) await page.locator('#secSub-recovery').screenshot({path: path.join(shots, 'security-codes.png')});
    await page.click('#secSub-recovery [data-sec-back]');
    assert.equal(await page.isChecked('#secTotp'), true);
    assert.equal(await page.textContent('#secRecoveryCount'), '10 von 10 übrig');

    // E-Mail code.
    await page.click('#secRow-email .settings-switch');
    await confirmWith(page, 'richtig');
    await page.waitForSelector('#secSub-email:not([hidden])');
    assert.equal(await page.textContent('#secEmailSent'), 'Wir haben einen Code an l•••@example.test geschickt. Er gilt 10 Minuten.');
    await page.fill('#secEmailCode', '654321');
    await page.click('#secEmailEnable');
    await page.waitForFunction(() => /E-Mail-Code eingeschaltet/.test(document.getElementById('secStatus').textContent));
    assert.equal(await page.isChecked('#secEmail'), true);

    // Lockdown-Modus.
    await page.click('#secRow-lockdown .settings-switch');
    await page.waitForFunction(() => /Lockdown-Modus an/.test(document.getElementById('secStatus').textContent));
    assert.deepEqual(server.st.posts.filter(p => p.action === 'lockdown').pop(), {action: 'lockdown', on: true});

    // Advanced account security needs a passkey first.
    await page.click('#secRow-advanced .sec-open');
    await page.click('#secAdvancedEnable');
    await page.waitForSelector('#secSub-passkeys:not([hidden])');
    assert.equal(await status(page), 'Für die erweiterte Kontosicherheit brauchst du mindestens einen Passkey.');

    // A real passkey from the virtual authenticator.
    await page.click('#secPasskeyAdd');
    await page.waitForFunction(() => document.querySelectorAll('#secPasskeyList .sec-item').length === 1);
    assert.equal(server.st.created.client.type, 'webauthn.create');
    assert.equal(server.st.created.client.origin, 'https://coreui.test');
    assert.equal(server.st.created.client.challenge, server.st.challenge, 'the server challenge was signed into the client data');
    assert.ok(server.st.created.attestationBytes > 100, 'attestation object sent');
    assert.match(await page.textContent('#secPasskeyList .sec-item small'), /^Hinzugefügt: 9\. November 2026$/);
    await page.click('#secPasskeyList .sec-item button:text("UMBENENNEN")');
    await page.waitForFunction(() => document.querySelector('#secPasskeyList .sec-item strong').textContent === 'YubiKey 5');
    await page.click('#secSub-passkeys [data-sec-back]');
    assert.equal(await page.textContent('#secPasskeyCount'), '1');

    // Sessions and history.
    await page.click('#secRow-sessions .sec-open');
    assert.equal(await page.locator('#loginSessionList .login-session-item').count(), 2);
    await page.click('#secSub-sessions [data-sec-back]');
    await page.click('#secRow-history .sec-open');
    assert.deepEqual(await page.$$eval('#secHistory li strong', l => l.map(x => x.textContent)), ['Fehlgeschlagene Anmeldung', 'Anmeldung']);
    assert.match(await page.textContent('#secHistory li.kind-login_failed small'), /9\. November 2026, 08:10 Uhr · Firefox auf Linux · Passwort/);
    await page.click('#secSub-history [data-sec-back]');
    const sizes = await page.$$eval('#view-sicherheit *', l => l.filter(n => n.offsetParent && Array.from(n.childNodes).some(c => c.nodeType === 3 && c.textContent.trim()))
      .map(n => parseFloat(getComputedStyle(n).fontSize)));
    assert.ok(Math.min(...sizes) >= 11, 'no text below 11px: ' + Math.min(...sizes));

    // Advanced account security: codes exist already, so it signs out at once.
    await page.click('#secRow-advanced .sec-open');
    assert.match(await page.textContent('#secAdvancedInfo'), /Du hast 1 Passkey\./);
    if (shots) await page.locator('#secSub-advanced').screenshot({path: path.join(shots, 'security-advanced.png')});
    await page.click('#secAdvancedEnable');
    await confirmWith(page, 'richtig');
    await page.waitForURL('https://coreui.test/login.html');
    assert.deepEqual(errors, [], 'no page errors');
    await context.close();

    // Advanced without recovery codes: the codes first, then the sign-in page.
    context = await browser.newContext({viewport: {width: 1200, height: 900}, locale: 'de-DE'});
    server = securityServer();
    server.st.passkeys.push({id: 1, label: 'Pixel', created_at: '2026-11-01 10:00:00', last_used_at: '2026-11-09 07:00:00'});
    await serve(context, [['api/account_security.php', server.handle]]);
    page = await context.newPage();
    page.on('dialog', d => d.accept());
    await page.goto('https://coreui.test/settings.html#sicherheit');
    await page.waitForFunction(() => document.getElementById('secPasskeyCount').textContent === '1');
    await page.click('#secRow-advanced .sec-open');
    await page.click('#secAdvancedEnable');
    await confirmWith(page, 'richtig');
    await page.waitForSelector('#secSub-recovery:not([hidden])');
    assert.match(await page.textContent('#secRecoveryText'), /^Erweiterte Kontosicherheit ist aktiv\. Speichere jetzt deine Wiederherstellungscodes/);
    assert.equal(await page.locator('#secRecoveryCodes li').count(), 10);
    assert.equal(await page.isVisible('#secRecoveryNew'), false);
    await page.click('#secSub-recovery [data-sec-back]');
    await page.waitForURL('https://coreui.test/login.html');
    await context.close();

    // Überall abmelden; a phone.
    context = await browser.newContext({viewport: {width: 390, height: 844}, locale: 'de-DE'});
    server = securityServer();
    await serve(context, [['api/account_security.php', server.handle]]);
    page = await context.newPage();
    page.on('dialog', d => d.accept());
    await page.goto('https://coreui.test/settings.html#sicherheit');
    await page.waitForFunction(() => document.getElementById('secSessionCount').textContent === '2');
    const fits = async () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
    assert.ok(await fits(), 'overview fits a phone');
    if (shots) await page.locator('#view-sicherheit').screenshot({path: path.join(shots, 'security-phone.png')});
    for (const sub of ['passkeys', 'advanced', 'history']) {
      await page.click('#secRow-' + sub + ' .sec-open');
      assert.ok(await fits(), sub + ' fits a phone');
      await page.click('#secSub-' + sub + ' [data-sec-back]');
    }
    await page.click('#secRow-sessions .sec-open');
    await page.click('#secRevokeAll');
    await page.waitForURL('https://coreui.test/login.html');
    assert.equal(server.st.posts.pop().action, 'sessions_revoke_all');
    await context.close();

    // --- Sign-in page ---------------------------------------------------------------------------------------
    context = await browser.newContext({viewport: {width: 1200, height: 900}, locale: 'de-DE'});
    const auth = {posts: [], challenge: '', mode: 'mfa'};
    await serve(context, [['api/auth.php', (method, body, url) => {
      const action = url.searchParams.get('action');
      if (action === 'me') return {json: {ok: false, registration_enabled: false, guest_enabled: false}};
      auth.posts.push(Object.assign({_: action}, body));
      if (action === 'login') return auth.mode === 'advanced' ? {status: 403, json: {ok: false, error: 'passkey_required'}}
        : {json: {ok: false, error: 'mfa_required', mfa_required: true, methods: {totp: true, email: true, recovery: true, email_hint: 'l•••@example.test'}}};
      if (action === 'mfa_email') return {json: {ok: true, sent_to: 'l•••@example.test'}};
      if (action === 'mfa_verify') return body.code === '123456' || body.code === 'abcde-fghj0' ? {json: {ok: true}} : {status: 401, json: {ok: false, error: 'mfa_invalid', tries_left: 4}};
      if (action === 'passkey_options') {
        auth.challenge = b64u(require('node:crypto').randomBytes(32));
        return {json: {ok: true, publicKey: {challenge: auth.challenge, rpId: 'coreui.test', timeout: 60000, userVerification: 'preferred', allowCredentials: []}}};
      }
      if (action === 'passkey_login') {
        const client = JSON.parse(unb64u(body.credential.response.clientDataJSON).toString());
        assert.equal(client.type, 'webauthn.get');
        assert.equal(client.challenge, auth.challenge);
        assert.ok(unb64u(body.credential.response.signature).length > 60, 'signature sent');
        return {json: {ok: true}};
      }
      return {status: 400, json: {ok: false, error: 'unknown'}};
    }]]);
    page = await context.newPage();
    const loginErrors = [];
    page.on('pageerror', e => loginErrors.push(e.message));
    await authenticator(page);
    await page.goto('https://coreui.test/login.html');
    await page.waitForSelector('#passkeyBtn:not([hidden])');
    await page.fill('#email', 'lena@example.test');
    await page.fill('#password', 'geheim-geheim');
    await page.click('#loginBtn');
    await page.waitForSelector('#mfaStep:not([hidden])');
    assert.equal(await page.isVisible('#loginForm'), false);
    assert.equal(await page.textContent('#mfaText'), 'Gib den 6-stelligen Code aus deiner Authenticator-App ein.');
    assert.deepEqual(await page.$$eval('.login-mfa-switch button:not([hidden])', l => l.map(b => b.textContent)), ['CODE PER E-MAIL', 'WIEDERHERSTELLUNGSCODE', 'ZURÜCK']);
    if (shots) await page.locator('.login-card').screenshot({path: path.join(shots, 'login-mfa.png')});
    await page.fill('#mfaCode', '000000');
    await page.click('#mfaBtn');
    await page.waitForFunction(() => /Noch 4 Versuche/.test(document.getElementById('loginError').textContent));
    await page.click('#mfaUse-email');
    assert.equal(await page.isVisible('#mfaSend'), true);
    await page.click('#mfaSend');
    await page.waitForFunction(() => /Code an l•••@example\.test gesendet/.test(document.getElementById('mfaNote').textContent));
    await page.click('#mfaUse-recovery');
    assert.equal(await page.textContent('#mfaCodeLabel'), 'WIEDERHERSTELLUNGSCODE');
    await page.fill('#mfaCode', 'abcde-fghj0');
    await page.click('#mfaBtn');
    await page.waitForURL('https://coreui.test/app.html');
    assert.deepEqual(auth.posts.filter(p => p._ === 'mfa_verify').map(p => p.method), ['totp', 'recovery']);

    // A real passkey sign-in: first a passkey for coreui.test in the virtual authenticator.
    await page.goto('https://coreui.test/login.html');
    await page.evaluate(async () => {
      await navigator.credentials.create({publicKey: {challenge: new Uint8Array(32), rp: {name: 'CoreAI', id: 'coreui.test'},
        user: {id: new Uint8Array(16).fill(3), name: 'lena@example.test', displayName: 'Lena'}, pubKeyCredParams: [{type: 'public-key', alg: -7}],
        authenticatorSelection: {residentKey: 'required', userVerification: 'preferred'}}});
    });
    await page.click('#passkeyBtn');
    await page.waitForURL('https://coreui.test/app.html');
    assert.equal(auth.posts.filter(p => p._ === 'passkey_login').length, 1);

    // Advanced account security: no password sign-in.
    auth.mode = 'advanced';
    await page.goto('https://coreui.test/login.html');
    await page.fill('#email', 'lena@example.test');
    await page.fill('#password', 'geheim-geheim');
    await page.click('#loginBtn');
    await page.waitForFunction(() => /nur mit Passkey/.test(document.getElementById('loginError').textContent));
    assert.deepEqual(loginErrors, []);
    await context.close();

    console.log('CoreAI security UI: overview like the reference, planned PIN/push/CSP, password confirmation, authenticator app with QR and key, '
      + 'recovery codes once with file, E-Mail code, Lockdown-Modus, real passkey added/renamed (virtual authenticator), sessions, history, '
      + 'advanced account security (needs a passkey, codes first, signs out), Überall abmelden, phone; sign-in: second step (wrong, mail, '
      + 'recovery), real passkey sign-in, passkey_required OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
