// CoreAI 0.6.82: Admin Core > Betrieb > E-Mail-Versand (SMTP) in a real browser (Playwright).
//   node scripts/admin-mail-ui-selftest.cjs
// Serves the dedicated /admin/ page with a stand-in for admin.php that keeps what is saved
// (the password only as "stored", never sent back) and checks: nothing set up yet, the
// port follows the encryption, no login without encryption, saving sends exactly the form
// with the CSRF header, the stored password is offered for keeping only for the same server
// and user, the test mail refuses unsaved changes, success and SMTP errors in plain German,
// input errors from the server, removing, a server configuration shown read-only, an
// unreadable password, an Admin never sees the card, and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};
const shots = process.env.COREUI_SHOTS || '';

function server(level, start) {
  const calls = [];
  const box = {stored: start.stored || null, env: start.env || null, unreadable: !!start.unreadable, testFails: false};
  const publicMail = () => {
    const m = box.env || box.stored;
    if (!m) return {source: 'none', ready: false};
    return {source: box.env ? 'env' : 'db', ready: !box.unreadable, host: m.host, port: m.port, security: m.security, user: m.user,
      has_password: !!m.pass, password_unreadable: box.unreadable, from: m.from, from_name: m.from_name || 'CoreAI: TCI Interface',
      updated_at: box.env ? '' : '2026-11-09T09:30:00+00:00'};
  };
  const state = () => ({ok: true, mail: publicMail(), test_address: 'patrick@starlight.test', csrf_token: 'tok-mail'});
  const api = {
    'auth.php': () => ({ok: true, user_id: 1, username: 'patrick@starlight.test', display_name: 'Patrick', permission_level: level, is_guest: false, csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Patrick', assistant_name: 'Mara', avatars: {}}}),
    'user_settings.php': () => ({ok: true, csrf_token: 'tok', default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
      core_label: 'TCI: Mara Normal', permission_level: level, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true,
        memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null},
      limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192], num_ctx_available: true}, external: {enabled: false, configured: false}}),
    'knowledge.php': () => ({ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}}),
    'memories.php': () => ({ok: true, items: []}),
    'health.php': () => ({ok: true, version: '0.6.82', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'account_security.php': () => ({ok: true, sessions: []}),
    'update.php': () => ({ok: true, running: false, state: 'idle', phase: '', version: '0.6.82'}),
  };
  const route = async r => {
    const req = r.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) {
      const key = rel.slice(4);
      const action = url.searchParams.get('action');
      const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null;
      if (key === 'admin.php' && /^mail_/.test(action || '')) {
        calls.push({action, method: req.method(), body, csrf: req.headers()['x-coreui-csrf'] || ''});
        if (level !== 0) return r.fulfill({status: 403, json: {ok: false, error: 'owner_only'}});
        if (action === 'mail_settings') return r.fulfill({json: state()});
        if (action === 'mail_settings_save') {
          const m = body.mail;
          if (box.env) return r.fulfill({status: 409, json: {ok: false, error: 'mail_env_locked'}});
          if (!/^[^@\s]+@[^@\s]+\.[a-z]+$/i.test(m.from)) return r.fulfill({status: 400, json: {ok: false, error: 'mail_from_invalid'}});
          const same = box.stored && box.stored.host === m.host && box.stored.user === m.user && !box.unreadable;
          if (m.user && !m.pass && !same) return r.fulfill({status: 400, json: {ok: false, error: 'mail_pass_required'}});
          box.stored = {host: m.host, port: m.port || {starttls: 587, tls: 465, none: 25}[m.security], security: m.security, user: m.user,
            pass: m.pass || (box.stored && box.stored.pass) || '', from: m.from, from_name: m.from_name};
          box.unreadable = false;
          return r.fulfill({json: state()});
        }
        if (action === 'mail_settings_delete') { box.stored = null; return r.fulfill({json: state()}); }
        if (action === 'mail_test') {
          if (box.testFails) return r.fulfill({status: 502, json: Object.assign(state(), {ok: false, error: 'smtp_unexpected_535',
            message: 'Der SMTP-Server hat die Anmeldung abgelehnt. Benutzer und Passwort prüfen.'})});
          return r.fulfill({json: Object.assign({sent_to: 'patrick@starlight.test'}, state())});
        }
      }
      if (key === 'admin.php') return r.fulfill({json: {ok: true, csrf_token: 'tok', settings: {}, stats: {}, items: [], models: [], grants: {}, runs: [], run: null}});
      return r.fulfill({json: api[key] ? api[key](url) : {ok: true}});
    }
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return r.fulfill({status: 404, body: ''});
    return r.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  };
  return {calls, box, route};
}

async function open(browser, level, start, viewport) {
  const srv = server(level, start || {});
  const page = await browser.newPage({viewport: viewport || {width: 1440, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://coreui.test/**', route => srv.route(route));
  await page.goto('http://coreui.test/admin/index.html#admin-betrieb');
  return {page, srv, errors};
}

const hint = page => page.textContent('#mailHint');
async function waitHint(page, pattern) {
  await page.waitForFunction(p => new RegExp(p).test(document.getElementById('mailHint').textContent), pattern.source);
  return hint(page);
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // The Inhaber, nothing set up yet.
    let {page, srv, errors} = await open(browser, 0);
    await page.waitForSelector('#mailSmtpCard:not([hidden])');
    await page.waitForFunction(() => document.getElementById('mailStatus').textContent !== 'E-Mail-Versand wird geprüft …');
    assert.equal(await page.textContent('#adminMailTitle'), 'SMTP-Server');
    assert.equal(await page.textContent('#mailStatus'), 'Noch nicht eingerichtet. Ohne SMTP-Server verschickt CoreAI keine E-Mails.');
    assert.equal(await page.isDisabled('#mailTest'), true, 'nothing to test yet');
    assert.equal(await page.isVisible('#mailDelete'), false, 'nothing to remove');
    assert.equal(await page.getAttribute('#mailPort', 'placeholder'), '587');
    assert.deepEqual(await page.$$eval('#mailSecurity option', l => l.map(o => o.textContent)),
      ['STARTTLS (Port 587)', 'SSL/TLS (Port 465)', 'Keine (nur lokales Netz)']);
    // The port follows the encryption; without encryption there is no login.
    await page.selectOption('#mailSecurity', 'tls');
    assert.equal(await page.getAttribute('#mailPort', 'placeholder'), '465');
    await page.fill('#mailUser', 'x');
    await page.selectOption('#mailSecurity', 'none');
    assert.equal(await page.getAttribute('#mailPort', 'placeholder'), '25');
    assert.equal(await page.isDisabled('#mailUser'), true);
    assert.equal(await page.isDisabled('#mailPass'), true);
    assert.equal(await page.inputValue('#mailUser'), '', 'login cleared without encryption');
    await page.selectOption('#mailSecurity', 'starttls');
    assert.equal(await page.isDisabled('#mailUser'), false);

    // Saving sends exactly the form, with the CSRF token of admin.php.
    await page.fill('#mailHost', ' smtp.example.com ');
    await page.fill('#mailUser', 'coreai@example.com');
    await page.fill('#mailPass', 'geheim-123');
    await page.fill('#mailFrom', 'coreai@example.com');
    await page.click('#mailSave');
    assert.equal(await waitHint(page, /Gespeichert/), 'Gespeichert. Mit TESTMAIL SENDEN prüfen, ob die Mail ankommt.');
    let post = srv.calls.filter(c => c.action === 'mail_settings_save').pop();
    assert.equal(post.csrf, 'tok-mail');
    assert.deepEqual(post.body, {mail: {host: 'smtp.example.com', security: 'starttls', port: 0, user: 'coreai@example.com', pass: 'geheim-123',
      from: 'coreai@example.com', from_name: ''}});
    assert.match(await page.textContent('#mailStatus'),
      /^Eingerichtet: smtp\.example\.com:587, STARTTLS, Anmeldung als coreai@example\.com\. Gespeichert am 09\.11\.2026, \d{2}:30 Uhr\.$/);
    assert.equal(await page.getAttribute('#mailStatus', 'data-state'), 'ready');
    assert.equal(await page.inputValue('#mailPass'), '', 'the password never comes back');
    assert.equal(await page.getAttribute('#mailPass', 'placeholder'), '•••••••• gespeichert');
    assert.equal(await page.getAttribute('#mailPass', 'title'), 'Leer lassen behält das gespeicherte Passwort.');
    assert.equal(await page.inputValue('#mailFromName'), 'CoreAI: TCI Interface');
    assert.equal(await page.isVisible('#mailDelete'), true);
    assert.equal(await page.isDisabled('#mailTest'), false);
    assert.equal(await page.getAttribute('#mailTest', 'title'), 'Geht an patrick@starlight.test');
    if (shots) await page.locator('#mailSmtpCard').screenshot({path: path.join(shots, 'admin-mail-saved.png')});

    // The stored password is offered only for the same server and user.
    await page.fill('#mailHost', 'smtp.other.example');
    assert.equal(await page.getAttribute('#mailPass', 'placeholder'), '', 'another server: enter it again');
    await page.fill('#mailHost', 'smtp.example.com');
    assert.equal(await page.getAttribute('#mailPass', 'placeholder'), '•••••••• gespeichert');
    await page.fill('#mailUser', 'other@example.com');
    assert.equal(await page.getAttribute('#mailPass', 'placeholder'), '', 'another user: enter it again');
    await page.fill('#mailUser', 'coreai@example.com');

    // The test uses what is stored: unsaved changes first.
    await page.fill('#mailFromName', 'Mara');
    await page.click('#mailTest');
    assert.equal(await hint(page), 'Bitte zuerst speichern. Die Testmail nutzt die gespeicherten Angaben.');
    assert.equal(srv.calls.some(c => c.action === 'mail_test'), false, 'no test with unsaved changes');
    await page.click('#mailSave');
    await waitHint(page, /Gespeichert/);
    post = srv.calls.filter(c => c.action === 'mail_settings_save').pop();
    assert.equal(post.body.mail.pass, '', 'empty password field keeps the stored one');
    assert.equal(post.body.mail.from_name, 'Mara');
    assert.equal(srv.box.stored.pass, 'geheim-123');

    await page.click('#mailTest');
    assert.equal(await waitHint(page, /Testmail an/), 'Testmail an patrick@starlight.test gesendet. Bitte Posteingang und Spam-Ordner prüfen.');
    assert.equal(await page.getAttribute('#mailHint', 'data-state'), 'ok');
    assert.equal(srv.calls.filter(c => c.action === 'mail_test').pop().csrf, 'tok-mail');
    srv.box.testFails = true;
    await page.click('#mailTest');
    assert.equal(await waitHint(page, /abgelehnt/), 'Der SMTP-Server hat die Anmeldung abgelehnt. Benutzer und Passwort prüfen.');
    assert.equal(await page.getAttribute('#mailHint', 'data-state'), 'error');
    assert.equal(await page.isDisabled('#mailTest'), false, 'test can be repeated');

    // Input errors from the server in plain German.
    await page.fill('#mailFrom', 'kein-absender');
    await page.click('#mailSave');
    assert.equal(await waitHint(page, /Absenderadresse/), 'Die Absenderadresse ist keine gültige E-Mail-Adresse.');
    await page.fill('#mailFrom', 'coreai@example.com');
    await page.fill('#mailHost', 'smtp.other.example');
    await page.click('#mailSave');
    assert.equal(await waitHint(page, /Passwort eingeben/), 'Bitte das Passwort eingeben. Bei einem neuen Server oder Benutzer ist es immer nötig.');

    // Removing asks first.
    page.once('dialog', d => { assert.equal(d.message(), 'SMTP-Einstellungen entfernen? Danach verschickt CoreAI keine E-Mails mehr.'); d.accept(); });
    await page.click('#mailDelete');
    assert.equal(await waitHint(page, /Entfernt/), 'Entfernt.');
    assert.equal(await page.textContent('#mailStatus'), 'Noch nicht eingerichtet. Ohne SMTP-Server verschickt CoreAI keine E-Mails.');
    assert.equal(await page.inputValue('#mailHost'), '');
    assert.equal(await page.isVisible('#mailDelete'), false);
    const sizes = await page.$$eval('#mailSmtpCard *', l => l.filter(n => n.offsetParent && n.textContent.trim())
      .map(n => parseFloat(getComputedStyle(n).fontSize)));
    assert.ok(Math.min(...sizes) >= 11, 'no text below 11px');
    assert.deepEqual(errors, [], 'no page errors');
    await page.close();

    // Set in the server configuration: shown, not changeable; the test still works.
    ({page, srv, errors} = await open(browser, 0, {env: {host: 'smtp.server.example', port: 465, security: 'tls', user: 'srv', pass: 'x',
      from: 'srv@example.com', from_name: 'CoreAI: TCI Interface'}}));
    await page.waitForFunction(() => /Serverkonfiguration/.test(document.getElementById('mailStatus').textContent));
    assert.equal(await page.textContent('#mailStatus'),
      'Festgelegt in der Serverkonfiguration (api/config.local.php oder COREUI_SMTP_*): smtp.server.example:465, SSL/TLS. Ändern nur dort.');
    for (const id of ['#mailHost', '#mailSecurity', '#mailPort', '#mailUser', '#mailPass', '#mailFrom', '#mailFromName']) {
      assert.equal(await page.isDisabled(id), true, id + ' read-only');
    }
    assert.equal(await page.inputValue('#mailHost'), 'smtp.server.example');
    assert.equal(await page.inputValue('#mailSecurity'), 'tls');
    assert.equal(await page.isVisible('#mailSave'), false);
    assert.equal(await page.isVisible('#mailDelete'), false);
    await page.click('#mailTest');
    await waitHint(page, /Testmail an/);
    assert.deepEqual(errors, []);
    await page.close();

    // The stored password cannot be opened any more (the database password changed).
    ({page, srv, errors} = await open(browser, 0, {unreadable: true, stored: {host: 'smtp.example.com', port: 587, security: 'starttls',
      user: 'coreai@example.com', pass: 'x', from: 'coreai@example.com'}}));
    await page.waitForFunction(() => document.getElementById('mailStatus').getAttribute('data-state') === 'warn');
    assert.equal(await page.textContent('#mailStatus'),
      'Das gespeicherte Passwort ist nicht mehr lesbar (Datenbank-Passwort des Servers geändert?). Bitte neu eingeben und speichern.');
    assert.equal(await page.getAttribute('#mailPass', 'placeholder'), '', 'nothing to keep');
    assert.equal(await page.isDisabled('#mailTest'), true);
    await page.fill('#mailPass', 'neu-456');
    await page.click('#mailSave');
    await waitHint(page, /Gespeichert/);
    assert.equal(await page.getAttribute('#mailStatus', 'data-state'), 'ready');
    assert.deepEqual(errors, []);
    await page.close();

    // An Admin: Betrieb yes, E-Mail-Versand never.
    ({page, srv, errors} = await open(browser, 1));
    await page.waitForSelector('#view-admin-betrieb:not([hidden])');
    for (let i = 0; i < 40 && !srv.calls.some(c => c.action === 'mail_settings'); i++) await page.waitForTimeout(50);
    await page.waitForTimeout(150);
    assert.equal(srv.calls.some(c => c.action === 'mail_settings'), true, 'asked once');
    assert.equal(await page.isVisible('#mailSmtpCard'), false, 'hidden for an Admin');
    assert.deepEqual(errors, []);
    await page.close();

    // A phone: everything fits.
    ({page, srv, errors} = await open(browser, 0, {stored: {host: 'smtp.example.com', port: 587, security: 'starttls', user: 'coreai@example.com',
      pass: 'x', from: 'coreai@example.com'}}, {width: 390, height: 844}));
    await page.waitForFunction(() => /Eingerichtet/.test(document.getElementById('mailStatus').textContent));
    await page.locator('#mailSmtpCard').scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scrolling');
    const box = await page.locator('#mailSmtpCard').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 390, 'card inside the screen');
    if (shots) await page.locator('#mailSmtpCard').screenshot({path: path.join(shots, 'admin-mail-phone.png')});
    assert.deepEqual(errors, []);
    await page.close();

    console.log('CoreAI Admin Core E-Mail-Versand UI: setup, port per encryption, no login without encryption, exact save with CSRF, '
      + 'password kept only for the same server and user, unsaved changes before the test, test success and SMTP error, input errors, '
      + 'removing, server configuration read-only, unreadable password, hidden for Admins, phone OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
