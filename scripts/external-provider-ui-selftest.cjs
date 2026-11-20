// CoreAI 0.8.11: Admin Core > Betrieb > Externer Adapter in a real browser (Playwright).
// 0.8.15: external Cores. The card saves itself (ADAPTER SPEICHERN), loads the provider's Cores
// (CORES VOM ANBIETER LADEN, with filter and own names), checks the connection before it is switched
// on, and only the Inhaber sets it up; the releases under Benutzer list the external Cores; the
// Core menu of the chat shows them under EXTERNE CORES.
//   node scripts/external-provider-ui-selftest.cjs
// Serves the dedicated /admin/ page with a stand-in for admin.php that keeps the adapter's fields
// and only whether a key is stored (never the key), and checks: the Inhaber pastes the key, it is
// sent once with the CSRF header and the field is emptied; what is missing; the check before the
// switch (it saves the card first and says the adapter is still off); loading, filtering and ticking
// the provider's Cores, adding one by hand; switching on and saving; the check naming a Core the
// provider does not list, a refused key; removing the key with a question; unsaved card fields
// survive saving the key; a key in the server configuration locks the field; an Admin sees the status
// but can neither set the key nor change the adapter; the releases per account; a phone without
// sideways scrolling. Then the Core menu (js/coreui-core-mode.js) with a stand-in DOM.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};
const shots = process.env.COREUI_SHOTS || '';
const KEY = 'gsk_live_A1b2C3d4E5f6';
const PROVIDER = ['cloud-core-1', 'cloud-core-2', 'cloud-mini', 'embed-small', 'image-gen-2', 'reason-pro', 'speech-tts', 'vision-lite', 'whisper-x'];
const MODEL = /^[A-Za-z0-9._:/@+-]{1,153}$/;

function server(level, start) {
  const calls = [];
  const box = {enabled: false, label: 'OpenAI-kompatibel', base_url: '', models: [], keySource: start.keySource || '', test: 'auto'};
  const capability = () => {
    const missing = [];
    if (!box.base_url) missing.push('base_url');
    if (!box.keySource) missing.push('key');
    if (!box.models.length) missing.push('models');
    const configured = box.enabled && missing.length === 0;
    return {configured, enabled: box.enabled, connection_ready: !!box.base_url && !!box.keySource, label: box.label, base_url: box.base_url,
      models: box.models.slice(), key_configured: !!box.keySource, key_source: box.keySource, missing,
      status: !box.enabled ? 'disabled' : (configured ? 'ready' : 'incomplete')};
  };
  const api = {
    'auth.php': () => ({ok: true, user_id: 1, username: 'owner@example.test', display_name: 'Inhaber', permission_level: level, is_guest: false, csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Inhaber', assistant_name: 'Mara', avatars: {}}}),
    'health.php': () => ({ok: true, version: '0.8.15', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'update.php': () => ({ok: true, running: false, state: 'idle', phase: '', version: '0.8.15'}),
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
        if (action === 'overview') return r.fulfill({json: {ok: true, csrf_token: 'tok-admin', stats: {}, settings: {},
          external: capability(), permission_level: level, client_address: {source: 'remote'}}});
        if (action === 'settings_save') return r.fulfill({json: {ok: true, settings: body.settings}});
        if (action === 'external_api_save') {
          if (level !== 0) return r.fulfill({status: 403, json: {ok: false, error: 'owner_only'}});
          if (body.base_url && !/^https:\/\//.test(body.base_url)) return r.fulfill({status: 400, json: {ok: false, error: 'external_url_requires_https'}});
          if (!Array.isArray(body.models) || body.models.some(m => !MODEL.test(m))) return r.fulfill({status: 400, json: {ok: false, error: 'external_model_invalid'}});
          Object.assign(box, {enabled: body.enabled === true, label: body.label || 'OpenAI-kompatibel', base_url: body.base_url, models: body.models});
          return r.fulfill({json: {ok: true, external: capability()}});
        }
        if (action === 'external_api_models') {
          const cap = capability();
          const list = cap.connection_ready ? {ok: true, reason: 'listed', ids: PROVIDER, http_code: 200, elapsed_ms: 9}
            : {ok: false, reason: 'not_ready', missing: cap.missing.filter(m => m !== 'models'), ids: []};
          return r.fulfill({json: {ok: true, list, external: cap}});
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
          const cap = capability();
          let test;
          if (box.test === 'key_refused') {
            test = {ok: false, reason: 'key_refused', http_code: 401, elapsed_ms: 40, message: 'Incorrect API key provided: [Schlüssel ausgeblendet]'};
          } else if (!cap.connection_ready) {
            test = {ok: false, reason: 'not_ready', missing: cap.missing.filter(m => m !== 'models'), enabled: cap.enabled, models: cap.models};
          } else {
            const notListed = cap.models.filter(m => !PROVIDER.includes(m));
            test = {ok: true, enabled: cap.enabled, models: cap.models, models_count: PROVIDER.length, http_code: 200, elapsed_ms: 12,
              reason: !cap.models.length ? 'connected' : (notListed.length ? 'models_not_listed' : 'models_listed'), models_not_listed: notListed};
          }
          return r.fulfill({json: {ok: true, test, external: cap}});
        }
        if (action === 'users') return r.fulfill({json: {ok: true, items: [
          {id: 1, username: 'owner@example.test', display_name: 'Inhaber', permission_level: 0, is_guest: 0, session_count: 1},
          {id: 3, username: 'nutzer@example.test', display_name: 'Nutzer', permission_level: 4, is_guest: 0, session_count: 1}],
          policies: {}, tools: {}, ctx_choices: [], plans: {}, plan_catalog: {}, storage_used: {}}});
        if (action === 'model_access') return r.fulfill({json: {ok: true, models: ['extern:cloud-core-1', 'llama3.2:latest'],
          grants: {3: ['extern:cloud-core-1']}, ollama: true, external_offered: ['extern:cloud-core-1'], ready: true}});
        if (action === 'model_access_save') return r.fulfill({json: {ok: true, models: body.models}});
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
async function waitResult(page, pattern) {
  await page.waitForFunction(p => new RegExp(p).test(document.getElementById('externalTestResult').textContent), pattern.source);
  return page.textContent('#externalTestResult');
}
const ticked = page => page.$$eval('#externalModelList input', boxes => boxes.map(b => (b.checked ? '+' : '-') + b.value));

// --- The Core menu of the chat (js/coreui-core-mode.js) with a stand-in DOM -----------------------------------------
class Element {
  constructor(tag, id) {
    this.tagName = String(tag).toUpperCase();
    this.id = id || '';
    this.attributes = {};
    this.handlers = {};
    this.children = [];
    this.parent = null;
    this.hidden = false;
    this.disabled = false;
    this.className = '';
    this._text = '';
  }
  set textContent(text) { this._text = String(text); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(' '); }
  get firstChild() { return this.children[0] || null; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  removeChild(child) { this.children = this.children.filter(c => c !== child); child.parent = null; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  contains(node) { for (let n = node; n; n = n.parent) if (n === this) return true; return false; }
  focus() {}
}
async function coreMenu() {
  const ids = ['coreModeChip', 'coreModeMenu', 'coreModeName', 'coreModeList', 'coreModeStatus', 'coreLegendBtn', 'coreLegend',
    'coreLegendRows', 'coreModeManage', 'coreModeExternal', 'coreModeExternalList'];
  const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
  els.coreModeMenu.hidden = true;
  els.coreModeExternal.hidden = true;
  els.coreModeExternal.appendChild(els.coreModeExternalList);
  const doc = {getElementById: id => els[id] || null, createElement: tag => new Element(tag), addEventListener() {}};
  const st = {override: '', external: [{name: 'extern:cloud-core-1', model: 'cloud-core-1', label: 'cloud-core-1', provider: 'Testanbieter'},
    {name: 'extern:google/gemma-4-e4b-it', model: 'google/gemma-4-e4b-it', label: 'google/gemma-4-e4b-it', provider: 'Testanbieter'}]};
  const posts = [];
  const reply = (status, body) => Promise.resolve({ok: status < 400, status, json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    if (url === './api/models.php') return reply(200, {ok: true, models: [{name: 'ember-coreui:latest', parameter_size: '7.5B', label: 'TCI Core Normal', mara: true}],
      default_model: 'ember-coreui:latest', default_label: 'TCI Core Normal', selected_tiers: {}, missing_tiers: [], install: null, external: st.external});
    if (url === './api/user_settings.php?core_status=1') return reply(200, {ok: true, loaded: ['ember-coreui:latest']});
    if (init && init.method === 'POST') {
      const body = JSON.parse(init.body);
      posts.push(body);
      if (body.model_override === 'extern:gone') return reply(409, {ok: false, error: 'external_not_available'});
      st.override = body.model_override;
      const external = /^extern:/.test(st.override);
      return reply(200, {ok: true, model_override: st.override, model: st.override || 'ember-coreui:latest',
        label: external ? st.override.slice(7) : 'TCI Core Normal', preload: external ? 'external' : 'loaded'});
    }
    return reply(200, {ok: true, settings: {thinking_enabled: true, model_override: st.override}, default_model: 'ember-coreui:latest',
      default_label: 'TCI Core Normal', core_label: /^extern:/.test(st.override) ? st.override.slice(7) : 'TCI Core Normal', csrf_token: 'tok', permission_level: 0});
  };
  const sandbox = {window: {}, Promise, JSON, Array, setTimeout, CustomEvent: function () {}};
  vm.runInNewContext(fs.readFileSync(path.join(root, 'js/coreui-core-mode.js'), 'utf8'), sandbox);
  const mode = sandbox.window.CoreUICoreMode.create(doc, fetch, () => 0, {installer: () => null});
  await mode.ready;
  const flat = list => list.children.flatMap(c => c.className === 'core-mode-item' ? c.children : [c]);
  const options = list => flat(list).filter(c => /(^| )core-mode-option( |$)/.test(c.className));
  const describe = list => options(list).map(b => [b.getAttribute('data-value'), b.children[0].textContent, b.children[1].textContent,
    b.children.find(c => c.className === 'core-mode-dot').getAttribute('data-state'), b.disabled]);

  assert.equal(els.coreModeExternal.hidden, false, 'EXTERNE CORES shows when the account may use external Cores');
  assert.deepEqual(describe(els.coreModeExternalList), [
    ['extern:cloud-core-1', 'cloud-core-1', 'Testanbieter · extern', 'standby', false],
    ['extern:google/gemma-4-e4b-it', 'google/gemma-4-e4b-it', 'Testanbieter · extern', 'standby', false]], 'a tier-like name keeps its own name');
  assert.ok(!options(els.coreModeList).some(b => /^extern:/.test(b.getAttribute('data-value'))), 'external Cores are not in the local list');
  await mode.choose('extern:cloud-core-1');
  assert.deepEqual(posts.pop(), {action: 'core', model_override: 'extern:cloud-core-1'});
  assert.equal(els.coreModeName.textContent, 'cloud-core-1 · extern', 'the chip names the external Core');
  assert.equal(els.coreModeStatus.textContent, 'Externer Core gewählt. Er läuft beim Anbieter und muss nicht geladen werden.');
  assert.equal(mode.state().loading, '', 'nothing is loaded');
  assert.deepEqual(describe(els.coreModeExternalList)[0], ['extern:cloud-core-1', 'cloud-core-1', 'Testanbieter · extern', 'active', false]);
  assert.ok(!options(els.coreModeList).some(b => /nicht mehr installiert/.test(b.children[1].textContent)), 'no "nicht mehr installiert" for it');
  await mode.choose('extern:gone');
  assert.equal(els.coreModeStatus.textContent, 'Dieser externe Core ist gerade nicht verfügbar.');
  // The adapter no longer offers it: greyed until another Core is chosen.
  st.external = [];
  await mode.refresh();
  assert.equal(els.coreModeExternal.hidden, false);
  assert.deepEqual(describe(els.coreModeExternalList), [['extern:cloud-core-1', 'cloud-core-1', 'gerade nicht verfügbar', 'gone', true]]);
  await mode.choose('');
  assert.equal(els.coreModeExternal.hidden, true, 'no external Core, no section');
  assert.equal(els.coreModeName.textContent, 'Core Normal');
}

(async () => {
  await coreMenu();
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // The Inhaber, nothing set up yet: the card names everything that is missing.
    let {page, srv, errors} = await open(browser, 0);
    assert.equal(await page.textContent('#adminExternalTitle'), 'Externer Adapter');
    assert.equal(await page.textContent('#externalStatus'), 'Aus. Alle Konten nutzen ihre lokalen Cores. Zum Einschalten fehlt noch: Base-URL fehlt, '
      + 'API-Schlüssel fehlt (im Feld API-SCHLÜSSEL einfügen), noch kein externer Core angeboten (CORES VOM ANBIETER LADEN oder von Hand hinzufügen).');
    assert.equal(await page.isDisabled('#externalKey'), false, 'the Inhaber can paste a key');
    assert.equal(await page.isVisible('#externalKeyDelete'), false, 'nothing to remove');
    assert.equal(await page.getAttribute('#externalKey', 'type'), 'password');
    assert.match(await page.textContent('#externalKeyHint'), /verschlüsselt gespeichert und nie wieder angezeigt/);
    assert.equal(await page.isVisible('#externalModelsFilter'), false, 'no filter without a list');
    assert.equal(await page.isVisible('#externalSave'), true);

    // Loading the list without address and key says what is missing.
    await page.click('#externalModelsLoad');
    assert.equal(await waitResult(page, /^Für die Liste/),
      'Für die Liste fehlt noch: Base-URL fehlt, API-Schlüssel fehlt (im Feld API-SCHLÜSSEL einfügen).');

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

    // An unsaved address in the card survives saving the key.
    await page.fill('#externalBaseUrl', 'https://api.example.test/v1');
    await page.fill('#externalKey', KEY);
    await page.click('#externalKeySave');
    await page.waitForFunction(() => document.getElementById('externalKey').value === '');
    assert.equal(await page.inputValue('#externalBaseUrl'), 'https://api.example.test/v1', 'the typed address stays');

    // The check before switching on: it saves the card first, then says the adapter is still off.
    await page.click('#externalTest');
    const before = await waitResult(page, /Verbindung steht/);
    assert.equal(before, 'Verbindung steht; der Anbieter listet 9 Cores. Noch kein externer Core angeboten. (HTTP 200, 12 ms) '
      + 'Der Adapter ist noch aus: zum Nutzen den Schalter einschalten und ADAPTER SPEICHERN.');
    const autoSave = srv.calls.filter(c => c.action === 'external_api_save').pop();
    assert.deepEqual(autoSave.body, {enabled: false, label: 'OpenAI-kompatibel', base_url: 'https://api.example.test/v1', models: []});
    assert.equal(srv.box.base_url, 'https://api.example.test/v1');

    // The provider's Cores: loaded, filtered, ticked; one added by hand.
    await page.click('#externalModelsLoad');
    assert.equal(await waitResult(page, /Cores beim Anbieter/), '9 Cores beim Anbieter. Anhaken, was CoreAI anbieten soll, dann ADAPTER SPEICHERN.');
    assert.equal(await page.isVisible('#externalModelsFilter'), true, 'a long list gets a filter');
    assert.deepEqual(await ticked(page), PROVIDER.map(m => '-' + m));
    await page.fill('#externalModelsFilter', 'cloud');
    assert.deepEqual(await ticked(page), ['-cloud-core-1', '-cloud-core-2', '-cloud-mini']);
    await page.check('#externalModelList input[value="cloud-core-1"]');
    await page.check('#externalModelList input[value="cloud-mini"]');
    await page.fill('#externalModelsFilter', 'reason');
    assert.deepEqual(await ticked(page), ['+cloud-core-1', '+cloud-mini', '-reason-pro'], 'ticked Cores stay visible while filtering');
    await page.fill('#externalModelsFilter', '');
    assert.match(await page.textContent('#externalModelsHint'), /2 angeboten, 9 beim Anbieter\. Ändern wirkt nach ADAPTER SPEICHERN\.$/);
    await page.fill('#externalModelName', 'kein name');
    await page.click('#externalModelAdd');
    await page.waitForFunction(() => /nur Buchstaben, Ziffern/.test(document.getElementById('adminFlash').textContent));
    await page.fill('#externalModelName', 'eigener-core@2');
    await page.press('#externalModelName', 'Enter');
    assert.deepEqual((await ticked(page)).filter(t => t.startsWith('+')), ['+cloud-core-1', '+cloud-mini', '+eigener-core@2']);
    assert.equal(await page.textContent('#externalModelList label:has(input[value="eigener-core@2"]) .external-model-note'), 'nicht in der Liste des Anbieters');

    // Switching on and saving the card: ready, with the number of Cores.
    await page.click('label[for="externalEnabled"]');
    await page.click('#externalSave');
    assert.equal(await waitStatus(page, /^Bereit/), 'Bereit: OpenAI-kompatibel mit 3 externen Cores. Sie stehen im Core-Menü des Chats unter '
      + 'EXTERNE CORES (der Inhaber sieht alle, andere Konten ihre Freigaben).');
    assert.equal(await flash(page), 'Externer Adapter gespeichert.');
    assert.deepEqual(srv.calls.filter(c => c.action === 'external_api_save').pop().body,
      {enabled: true, label: 'OpenAI-kompatibel', base_url: 'https://api.example.test/v1', models: ['cloud-core-1', 'cloud-mini', 'eigener-core@2']});
    assert.equal(srv.calls.filter(c => c.action === 'settings_save').length, 0, 'the general SPEICHERN is not needed');

    // The check names the Core the provider does not list; a refused key.
    const saves = srv.calls.filter(c => c.action === 'external_api_save').length;
    await page.click('#externalTest');
    assert.equal(await waitResult(page, /stehen nicht in der Liste/), 'Verbindung steht, aber diese angebotenen Cores stehen nicht in der Liste des '
      + 'Anbieters: eigener-core@2. Bitte die Namen prüfen. (HTTP 200, 12 ms)');
    assert.equal(srv.calls.filter(c => c.action === 'external_api_save').length, saves, 'nothing changed, nothing saved again');
    srv.box.test = 'key_refused';
    await page.click('#externalTest');
    assert.equal(await waitResult(page, /lehnt den Schlüssel ab/),
      'Der Anbieter lehnt den Schlüssel ab. (HTTP 401, 40 ms) Anbieter: Incorrect API key provided: [Schlüssel ausgeblendet]');
    assert.equal(await page.getAttribute('#externalTestResult', 'class'), 'cfg-card-note is-error');
    assert.notEqual(await page.$eval('#externalTestResult', el => getComputedStyle(el).color),
      await page.$eval('#externalStatus', el => getComputedStyle(el).color), 'a failed check stands out');
    srv.box.test = 'auto';
    // A wrong address is refused in plain words.
    await page.fill('#externalBaseUrl', 'http://api.example.test/v1');
    await page.click('#externalSave');
    await page.waitForFunction(() => /braucht HTTPS/.test(document.getElementById('adminFlash').textContent));
    await page.fill('#externalBaseUrl', 'https://api.example.test/v1');

    // Removing the key asks first; then the adapter says what is missing.
    page.once('dialog', d => { assert.match(d.message(), /API-Schlüssel wirklich entfernen/); d.accept(); });
    await page.click('#externalKeyDelete');
    assert.equal(await waitStatus(page, /^Eingeschaltet/),
      'Eingeschaltet, aber noch nicht vollständig: API-Schlüssel fehlt (im Feld API-SCHLÜSSEL einfügen). Bis dahin bleibt jedes Konto lokal.');
    assert.equal(await page.isVisible('#externalKeyDelete'), false);
    if (shots) await page.locator('section[aria-labelledby="adminExternalTitle"]').screenshot({path: path.join(shots, 'admin-external.png')});

    // The releases per account list the external Cores.
    await page.click('#tab-admin-benutzer');
    await page.waitForSelector('.model-access-toggle');
    await page.click('.model-access-toggle');
    await page.waitForSelector('.model-access-row');
    assert.deepEqual(await page.$$eval('.model-access-row .model-access-choice span', s => s.map(x => x.textContent)), ['cloud-core-1 (extern)', 'llama3.2:latest']);
    assert.match(await page.textContent('.model-access-row .model-access-note'), /Fremde Cores außerhalb von CoreAI und externe Cores für Nutzer/);
    await page.check('.model-access-row input[value="llama3.2:latest"]');
    await page.click('.model-access-row .settings-action');
    await page.waitForFunction(() => /Freigaben für Benutzer 3 gespeichert/.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.filter(c => c.action === 'model_access_save').pop().body, {user_id: 3, models: ['extern:cloud-core-1', 'llama3.2:latest']});
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

    // An Admin: sees the status and may check, but can neither set the key nor change the adapter.
    ({page, srv, errors} = await open(browser, 1, {keySource: 'admin'}));
    assert.equal(await page.isDisabled('#externalKey'), true);
    assert.equal(await page.isDisabled('#externalKeySave'), true);
    assert.equal(await page.isVisible('#externalKeyDelete'), false);
    assert.equal(await page.textContent('#externalKeyHint'), 'Den Schlüssel setzt oder entfernt nur der Inhaber. Ein Schlüssel ist gespeichert.');
    for (const id of ['#externalEnabled', '#externalLabel', '#externalBaseUrl', '#externalModelName', '#externalModelAdd', '#externalSave']) {
      assert.equal(await page.isDisabled(id), true, id + ' locked for an Admin');
    }
    assert.match(await page.textContent('#externalStatus'), / Einrichten kann den Adapter nur der Inhaber\.$/);
    assert.equal(await page.isDisabled('#externalTest'), false, 'an Admin may check the connection');
    await page.click('#externalTest');
    await waitResult(page, /fehlt noch/);
    assert.equal(srv.calls.filter(c => c.action === 'external_api_save').length, 0, 'an Admin\'s check saves nothing');
    assert.deepEqual(errors, []);
    await page.close();

    // A phone: everything fits, also with the provider's list.
    ({page, srv, errors} = await open(browser, 0, {keySource: 'admin'}, {width: 390, height: 844}));
    await page.fill('#externalBaseUrl', 'https://api.example.test/v1');
    await page.click('#externalModelsLoad');
    await waitResult(page, /Cores beim Anbieter/);
    await page.locator('section[aria-labelledby="adminExternalTitle"]').scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no sideways scrolling');
    const card = await page.locator('section[aria-labelledby="adminExternalTitle"]').boundingBox();
    assert.ok(card.x >= 0 && card.x + card.width <= 390, 'card inside the screen');
    const box = await page.locator('#externalModelList input').first().boundingBox();
    assert.ok(box.width <= 20 && box.height <= 20, 'the check boxes keep their size');
    if (shots) await page.locator('section[aria-labelledby="adminExternalTitle"]').screenshot({path: path.join(shots, 'admin-external-phone.png')});
    assert.deepEqual(errors, []);
    await page.close();

    console.log('CoreAI externe Cores UI: Core-Menue (EXTERNE CORES, Wahl ohne Laden, nicht mehr angeboten), Schluessel einfuegen (einmal, mit CSRF, '
      + 'Feld geleert), ungueltiger Schluessel, Pruefen vor dem Einschalten, Cores vom Anbieter laden, filtern, anhaken, von Hand, ADAPTER SPEICHERN, '
      + 'nicht gelistete Cores, abgelehnter Schluessel, Entfernen mit Rueckfrage, Freigaben, Serverkonfiguration, Admin ohne Einrichtungsrecht, Telefon OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
