// CoreAI 0.8.11: Admin Core > Betrieb > Externer Adapter in a real browser (Playwright).
//   node scripts/external-provider-ui-selftest.cjs
// Serves the dedicated /admin/ page with a stand-in for admin.php that keeps the adapter's fields
// and only whether a key is stored (never the key), and checks: the Inhaber pastes the key, it is
// sent once with the CSRF header and the field is emptied; what is missing; switching on and
// saving; the connection test in plain words (listed, key refused); removing the key with a
// question; an invalid key; a key in the server configuration locks the field; an Admin sees the
// status but cannot set the key; and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};
const shots = process.env.COREUI_SHOTS || '';
const KEY = 'gsk_live_A1b2C3d4E5f6';

function server(level, start) {
  const calls = [];
  const box = {settings: {external_api_enabled: '0', external_api_label: 'OpenAI-kompatibel', external_api_base_url: '', external_api_model: ''},
    keySource: start.keySource || '', test: start.test || 'model_listed'};
  const capability = () => {
    const s = box.settings;
    const missing = [];
    if (!s.external_api_base_url) missing.push('base_url');
    if (!s.external_api_model) missing.push('model');
    if (!box.keySource) missing.push('key');
    const enabled = s.external_api_enabled === '1';
    const configured = enabled && missing.length === 0;
    return {configured, configuration_complete: configured, enabled, label: s.external_api_label, base_url: s.external_api_base_url,
      model: s.external_api_model, key_configured: !!box.keySource, key_source: box.keySource, missing,
      status: !enabled ? 'disabled' : (configured ? 'ready' : 'incomplete')};
  };
  const api = {
    'auth.php': () => ({ok: true, user_id: 1, username: 'owner@example.test', display_name: 'Inhaber', permission_level: level, is_guest: false, csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Inhaber', assistant_name: 'Mara', avatars: {}}}),
    'health.php': () => ({ok: true, version: '0.8.11', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'update.php': () => ({ok: true, running: false, state: 'idle', phase: '', version: '0.8.11'}),
  };
  const route = async r => {
    const req = r.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) {
      const key = rel.slice(4);
      const action = url.searchParams.get('action');
      const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null;
      if (key === 'admin.php') {
        calls.push({action, method: req.method(), body, csrf: req.headers()['x-coreui-csrf'] || ''});
        if (action === 'overview') return r.fulfill({json: {ok: true, csrf_token: 'tok-admin', stats: {}, settings: box.settings,
          external: capability(), permission_level: level, client_address: {source: 'remote'}}});
        if (action === 'settings_save') {
          Object.assign(box.settings, Object.fromEntries(Object.entries(body.settings).filter(([k]) => k.startsWith('external_api_'))));
          return r.fulfill({json: {ok: true, settings: box.settings}});
        }
        if (action === 'external_api_key_save' || action === 'external_api_key_delete') {
          if (level !== 0) return r.fulfill({status: 403, json: {ok: false, error: 'owner_only'}});
          if (box.keySource === 'server') return r.fulfill({status: 409, json: {ok: false, error: 'external_key_in_server_config'}});
          if (action === 'external_api_key_save') {
            if (!/^[\x21-\x7e]{8,400}$/.test(String(body.key || '').trim())) return r.fulfill({status: 400, json: {ok: false, error: 'external_key_invalid'}});
            box.keySource = 'admin';
          } else {
            box.keySource = '';
          }
          return r.fulfill({json: {ok: true, external: capability()}});
        }
        if (action === 'external_api_test') {
          const test = box.test === 'key_refused'
            ? {ok: false, reason: 'key_refused', http_code: 401, elapsed_ms: 40, message: 'Incorrect API key provided: [Schlüssel ausgeblendet]'}
            : {ok: true, reason: 'model_listed', models_count: 2, http_code: 200, elapsed_ms: 12};
          return r.fulfill({json: {ok: true, test, external: capability()}});
        }
        return r.fulfill({json: {ok: true, csrf_token: 'tok-admin', settings: {}, stats: {}, items: [], models: [], grants: {}, runs: [], run: null}});
      }
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
  await page.waitForSelector('#view-admin-betrieb:not([hidden])');
  await page.waitForFunction(() => document.getElementById('externalStatus').textContent !== 'Externer Adapter wird geprüft …');
  return {page, srv, errors};
}
const flash = page => page.textContent('#adminFlash');
async function waitStatus(page, pattern) {
  await page.waitForFunction(p => new RegExp(p).test(document.getElementById('externalStatus').textContent), pattern.source);
  return page.textContent('#externalStatus');
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // The Inhaber, nothing set up yet.
    let {page, srv, errors} = await open(browser, 0);
    assert.equal(await page.textContent('#adminExternalTitle'), 'Externer Adapter');
    assert.equal(await page.textContent('#externalStatus'), 'Aus. Alle Konten nutzen ihren lokalen Core. Noch kein API-Schlüssel gespeichert.');
    assert.equal(await page.isDisabled('#externalKey'), false, 'the Inhaber can paste a key');
    assert.equal(await page.isVisible('#externalKeyDelete'), false, 'nothing to remove');
    assert.equal(await page.getAttribute('#externalKey', 'type'), 'password');
    assert.match(await page.textContent('#externalKeyHint'), /verschlüsselt gespeichert und nie wieder angezeigt/);

    // Pasting the key: sent once with the CSRF header, then the field is empty again.
    await page.fill('#externalKey', '  ' + KEY + '  ');
    await page.click('#externalKeySave');
    await page.waitForFunction(() => document.getElementById('externalKey').value === '');
    const save = srv.calls.filter(c => c.action === 'external_api_key_save').pop();
    assert.deepEqual(save.body, {key: KEY});
    assert.equal(save.csrf, 'tok-admin');
    assert.equal(await flash(page), 'API-Schlüssel verschlüsselt gespeichert.');
    assert.equal(await page.isVisible('#externalKeyDelete'), true, 'a stored key can be removed');
    assert.equal(await page.getAttribute('#externalKey', 'placeholder'), 'Gespeichert. Zum Ersetzen neuen Schlüssel einfügen');
    assert.equal(srv.calls.filter(c => JSON.stringify(c.body || {}).includes(KEY)).length, 1, 'the key travels exactly once');

    // An invalid key: a plain sentence, nothing stored.
    await page.fill('#externalKey', 'kurz');
    await page.click('#externalKeySave');
    await page.waitForFunction(() => /8 bis 400 Zeichen/.test(document.getElementById('adminFlash').textContent));
    assert.equal(await page.inputValue('#externalKey'), 'kurz', 'the typed text stays to correct it');
    await page.fill('#externalKey', '');

    // Switching on with address and Core: ready.
    await page.click('label[for="externalEnabled"]');
    assert.equal(await page.isChecked('#externalEnabled'), true);
    await page.fill('#externalBaseUrl', 'https://api.example.test/v1');
    await page.fill('#externalModel', 'cloud-core-1');
    await page.click('#saveRuntime');
    assert.equal(await waitStatus(page, /^Bereit/),
      'Bereit: OpenAI-kompatibel mit cloud-core-1. Inhaber und Admins können ihn unter Einstellungen > KI-Profil als Core-Pfad wählen.');

    // The connection test in plain words.
    await page.click('#externalTest');
    await page.waitForFunction(() => /Verbindung steht/.test(document.getElementById('externalTestResult').textContent));
    assert.equal(await page.textContent('#externalTestResult'), 'Verbindung steht; der Core steht in der Liste des Anbieters. (HTTP 200, 12 ms)');
    srv.box.test = 'key_refused';
    await page.click('#externalTest');
    await page.waitForFunction(() => /lehnt den Schlüssel ab/.test(document.getElementById('externalTestResult').textContent));
    assert.equal(await page.textContent('#externalTestResult'),
      'Der Anbieter lehnt den Schlüssel ab. (HTTP 401, 40 ms) Anbieter: Incorrect API key provided: [Schlüssel ausgeblendet]');
    assert.equal(await page.getAttribute('#externalTestResult', 'class'), 'cfg-card-note is-error');
    assert.notEqual(await page.$eval('#externalTestResult', el => getComputedStyle(el).color),
      await page.$eval('#externalStatus', el => getComputedStyle(el).color), 'a failed check stands out');

    // Removing the key asks first; then the adapter says what is missing.
    page.once('dialog', d => { assert.match(d.message(), /API-Schlüssel wirklich entfernen/); d.accept(); });
    await page.click('#externalKeyDelete');
    assert.equal(await waitStatus(page, /^Noch nicht vollständig/),
      'Noch nicht vollständig: API-Schlüssel fehlt (im Feld API-SCHLÜSSEL einfügen). Bis dahin bleibt jedes Konto lokal.');
    assert.equal(await page.isVisible('#externalKeyDelete'), false);
    if (shots) await page.locator('section[aria-labelledby="adminExternalTitle"]').screenshot({path: path.join(shots, 'admin-external.png')});
    assert.deepEqual(errors, []);
    await page.close();

    // A key in the server configuration: shown as there, the field locked.
    ({page, srv, errors} = await open(browser, 0, {keySource: 'server'}));
    assert.equal(await page.isDisabled('#externalKey'), true);
    assert.equal(await page.isDisabled('#externalKeySave'), true);
    assert.equal(await page.isVisible('#externalKeyDelete'), false);
    assert.match(await page.textContent('#externalKeyHint'), /Serverkonfiguration .* gilt vorrangig/);
    assert.deepEqual(errors, []);
    await page.close();

    // An Admin: sees the status, cannot set or remove the key.
    ({page, srv, errors} = await open(browser, 1, {keySource: 'admin'}));
    assert.equal(await page.isDisabled('#externalKey'), true);
    assert.equal(await page.isDisabled('#externalKeySave'), true);
    assert.equal(await page.isVisible('#externalKeyDelete'), false);
    assert.equal(await page.textContent('#externalKeyHint'), 'Den Schlüssel setzt oder entfernt nur der Inhaber. Ein Schlüssel ist gespeichert.');
    assert.deepEqual(errors, []);
    await page.close();

    // A phone: everything fits.
    ({page, srv, errors} = await open(browser, 0, {}, {width: 390, height: 844}));
    await page.locator('section[aria-labelledby="adminExternalTitle"]').scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scrolling');
    const card = await page.locator('section[aria-labelledby="adminExternalTitle"]').boundingBox();
    assert.ok(card.x >= 0 && card.x + card.width <= 390, 'card inside the screen');
    if (shots) await page.locator('section[aria-labelledby="adminExternalTitle"]').screenshot({path: path.join(shots, 'admin-external-phone.png')});
    assert.deepEqual(errors, []);
    await page.close();

    console.log('CoreAI Admin Core externer Adapter UI: Schluessel einfuegen (einmal gesendet, mit CSRF, Feld geleert), ungueltiger Schluessel, '
      + 'Einschalten, Verbindung pruefen (gelistet, abgelehnt), Entfernen mit Rueckfrage, Serverkonfiguration gesperrt, Admin ohne Schluesselrecht, Telefon OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
