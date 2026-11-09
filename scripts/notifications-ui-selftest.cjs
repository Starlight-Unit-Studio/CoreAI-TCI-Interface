// CoreAI 0.6.81: Settings > Benachrichtigungen in a real browser (Playwright), plus the service
// worker and the chat's browser fallback.
//   node scripts/notifications-ui-selftest.cjs
// The browser's push service cannot be reached headless, so PushManager and the permission are
// stand-ins; everything the page does with them is real: status texts for every case (off, on,
// blocked, no HTTPS, iPhone without Home screen, server not ready), switching Push on and off,
// the device list with "Dieses Gerät", the test, E-Mail state (options greyed without SMTP),
// one choice per category saved on the account, Inhaber-only and planned rows, a phone. Then
// sw.js in a sandbox (shown, skipped while watching, safe links, click focuses or opens) and
// CoreAINotify.answerDone (only in the background, only without Push here, only long answers).
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

function notifyServer(options) {
  const state = {
    ok: true, ready: true, owner: !!options.owner, csrf_token: 'tok', answer_seconds: 45,
    prefs: {answers: 'push', security: 'email', updates: 'push', tasks: 'push', agents: 'email', library: 'email', projects: 'email', workspace: 'email'},
    categories: {answers: {planned: false}, security: {planned: false}, updates: {planned: false, owner: true}, tasks: {planned: true},
      agents: {planned: true}, library: {planned: true}, projects: {planned: true}, workspace: {planned: true}},
    push: {available: options.pushAvailable !== false, public_key: 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
      devices: options.devices || []},
    email: {available: options.mail !== false, address: 'lena@example.test'},
  };
  const posts = [];
  return {state, posts, handle(method, body) {
    if (method === 'GET') return state;
    posts.push(body);
    if (body.action === 'prefs') Object.assign(state.prefs, body.prefs);
    if (body.action === 'subscribe') {
      const key = crypto.createHash('sha256').update(body.endpoint).digest('hex').slice(0, 16);
      state.push.devices = state.push.devices.filter(d => d.key !== key)
        .concat([{id: state.push.devices.length + 7, key, label: 'Chrome auf Android', created_at: '2026-11-09 08:00:00'}]);
    }
    if (body.action === 'unsubscribe') {
      const key = body.endpoint ? crypto.createHash('sha256').update(body.endpoint).digest('hex').slice(0, 16) : '';
      state.push.devices = state.push.devices.filter(d => d.id !== body.id && d.key !== key);
    }
    if (body.action === 'test') return Object.assign({test: {push: state.push.devices.length, push_failed: 0, push_removed: 0}}, state);
    return state;
  }};
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
  'health.php': {ok: true, version: '0.6.81', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
  'account_security.php': {ok: true, sessions: []},
};

// Stand-ins for the browser's push parts; the permission answer and the platform are chosen per test.
const PUSH_STUB = ({permission, iphone, standalone}) => {
  let subscription = null;
  const sub = endpoint => ({endpoint, options: {applicationServerKey: null},
    toJSON: () => ({endpoint, keys: {p256dh: 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', auth: 'BTBZMqHH6r4Tts7J_aSIgg'}}),
    unsubscribe: () => { subscription = null; window.__unsubscribed = (window.__unsubscribed || 0) + 1; return Promise.resolve(true); }});
  const pushManager = {
    getSubscription: () => Promise.resolve(subscription),
    subscribe: opts => { window.__subscribeKey = Array.from(opts.applicationServerKey).length; subscription = sub('https://fcm.googleapis.com/fcm/send/this-phone'); return Promise.resolve(subscription); },
  };
  const registration = {pushManager, showNotification: (title, o) => { (window.__shown = window.__shown || []).push([title, o]); return Promise.resolve(); }};
  Object.defineProperty(navigator, 'serviceWorker', {configurable: true, value: {
    register: url => { window.__registered = url; return Promise.resolve(registration); },
    ready: Promise.resolve(registration),
    getRegistration: () => Promise.resolve(window.__registered ? registration : null),
  }});
  window.PushManager = function () {};
  let perm = permission;
  window.Notification = function (title, o) { (window.__shown = window.__shown || []).push([title, o]); };
  Object.defineProperty(window.Notification, 'permission', {get: () => perm});
  window.Notification.requestPermission = () => { window.__asked = true; perm = window.__answer || perm; return Promise.resolve(perm); };
  if (iphone) Object.defineProperty(navigator, 'userAgent', {get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'});
  if (standalone) window.matchMedia = q => ({matches: q === '(display-mode: standalone)', addEventListener() {}});
};

async function open(browser, opts = {}) {
  const server = notifyServer(opts);
  const context = await browser.newContext({viewport: opts.viewport || {width: 1200, height: 900}, locale: 'de-DE'});
  await context.addInitScript(PUSH_STUB, {permission: opts.permission || 'default', iphone: !!opts.iphone, standalone: !!opts.standalone});
  if (opts.answer) await context.addInitScript(a => { window.__answer = a; }, opts.answer);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const origin = opts.insecure ? 'http://coreui.test' : 'https://coreui.test';
  await page.route(origin + '/**', async route => {
    const url = new URL(route.request().url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel === 'api/notifications.php') {
      const req = route.request();
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'every POST carries the CSRF token');
      return route.fulfill({json: server.handle(req.method(), req.method() === 'POST' ? JSON.parse(req.postData()) : null)});
    }
    if (rel.startsWith('api/')) {
      const name = rel.slice(4);
      if (name === 'user_settings.php' && url.searchParams.has('general')) {
        return route.fulfill({json: {ok: true, csrf_token: 'tok', general: {auto_higher_core: false, thinking_level: ''}, model: 'ember-core:e4b',
          core_label: 'TCI Core Normal', available: true, auto_core_allowed: true, reasoning: {levels: [], metadata_available: true, supports_on_off: true}}});
      }
      return route.fulfill({json: api[name] || {ok: true}});
    }
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  await page.goto(origin + '/settings.html#benachrichtigungen');
  await page.waitForFunction(() => !document.getElementById('view-benachrichtigungen').hidden);
  await page.waitForFunction(() => !/geprüft/.test(document.getElementById('notifyPushStatus').textContent));
  return {context, page, errors, server};
}
const status = page => page.textContent('#notifyPushStatus');
const shown = (page, id) => page.isVisible('#' + id);

(async () => {
  const browser = await chromium.launch();
  try {
    // --- Off, then switch on, test, choose, switch off --------------------------------------------
    let {context, page, errors, server} = await open(browser, {permission: 'default'});
    assert.equal(await status(page), 'Push ist auf diesem Gerät aus.');
    assert.deepEqual([await shown(page, 'notifyPushEnable'), await shown(page, 'notifyPushDisable'), await shown(page, 'notifyPushTest')], [true, false, false]);
    assert.equal(await page.textContent('#notifyMailStatus'), 'E-Mails gehen an lena@example.test.');
    assert.equal(await page.inputValue('#notify-answers'), 'push');
    assert.equal(await page.inputValue('#notify-security'), 'email');
    assert.equal(await page.isHidden('.notify-row[data-category="updates"]'), true, 'Inhaber-only row hidden for a Nutzer');
    for (const id of ['tasks', 'agents', 'library', 'projects', 'workspace']) {
      assert.equal(await page.isDisabled('#notify-' + id), true, id + ' is planned');
      assert.match(await page.textContent('.notify-row[data-category="' + id + '"] .notify-planned'), /IN VORBEREITUNG/);
    }
    await context.addInitScript(() => { window.__answer = 'granted'; });
    await page.evaluate(() => { window.__answer = 'granted'; });
    await page.click('#notifyPushEnable');
    await page.waitForFunction(() => document.getElementById('notifyPushStatus').textContent === 'Push ist auf diesem Gerät aktiv.');
    assert.equal(await page.evaluate(() => window.__asked), true, 'the browser asked for permission');
    assert.equal(await page.evaluate(() => window.__registered), './sw.js', 'the service worker at the root');
    assert.equal(await page.evaluate(() => window.__subscribeKey), 65, 'the server key as 65 bytes');
    const sub = server.posts.find(p => p.action === 'subscribe');
    assert.equal(sub.endpoint, 'https://fcm.googleapis.com/fcm/send/this-phone');
    assert.ok(sub.keys.p256dh && sub.keys.auth, 'browser keys sent');
    assert.deepEqual([await shown(page, 'notifyPushEnable'), await shown(page, 'notifyPushDisable'), await shown(page, 'notifyPushTest')], [false, true, true]);
    assert.match(await page.textContent('#notifyDevices'), /Chrome auf Android\s*Dieses Gerät · seit 09\.11\.2026/);
    assert.equal(await page.evaluate(() => localStorage.getItem('coreai.push.enabled')), '1');
    await page.click('#notifyPushTest');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').textContent === 'TEST AN 1 GERÄT GESENDET');
    await page.selectOption('#notify-answers', 'push,email');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').textContent === 'GESPEICHERT');
    assert.deepEqual(server.posts.filter(p => p.action === 'prefs').pop(), {action: 'prefs', prefs: {answers: 'push,email'}});
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-on.png'), fullPage: true});
    await page.click('#notifyPushDisable');
    await page.waitForFunction(() => document.getElementById('notifyPushStatus').textContent === 'Push ist auf diesem Gerät aus.');
    assert.equal(await page.evaluate(() => window.__unsubscribed), 1, 'unsubscribed in the browser');
    assert.equal(server.state.push.devices.length, 0, 'and removed on the server');
    assert.equal(await page.evaluate(() => localStorage.getItem('coreai.push.enabled')), null);
    assert.deepEqual(errors, []);
    await context.close();

    // --- Another device in the list can be removed; this one is not "Dieses Gerät" -----------------
    ({context, page, errors, server} = await open(browser, {permission: 'granted', owner: true,
      devices: [{id: 3, key: 'aaaaaaaaaaaaaaaa', label: 'Firefox auf Windows', created_at: '2026-11-01 10:00:00'}]}));
    assert.match(await page.textContent('#notifyDevices'), /Firefox auf Windows\s*seit 01\.11\.2026/);
    assert.doesNotMatch(await page.textContent('#notifyDevices'), /Dieses Gerät/);
    assert.equal(await page.isVisible('.notify-row[data-category="updates"]'), true, 'the Inhaber sees CoreAI-Updates');
    await page.click('#notifyDevices button');
    await page.waitForFunction(() => document.querySelectorAll('#notifyDevices li').length === 0);
    assert.deepEqual(server.posts.pop(), {action: 'unsubscribe', id: 3});
    await context.close();

    // --- Without SMTP: E-Mail options greyed --------------------------------------------------------
    ({context, page, errors, server} = await open(browser, {mail: false}));
    assert.match(await page.textContent('#notifyMailStatus'), /kein E-Mail-Versand eingerichtet/);
    const options = await page.$$eval('#notify-answers option', o => o.map(x => [x.value, x.disabled]));
    assert.deepEqual(options, [['', false], ['push', false], ['email', true], ['push,email', true]], 'E-Mail choices greyed');
    assert.equal(await page.isDisabled('#notify-security option[value="email"]'), false, 'a saved E-Mail choice stays visible');
    await context.close();

    // --- Every reason Push is not possible ----------------------------------------------------------------
    const cases = [
      [{permission: 'denied'}, /blockiert/],
      [{insecure: true}, /sichere Verbindung \(HTTPS\)/],
      [{iphone: true}, /Zum Home-Bildschirm/],
      [{pushAvailable: false}, /auf diesem Server noch nicht bereit/],
    ];
    for (const [opts, text] of cases) {
      ({context, page, errors} = await open(browser, opts));
      assert.match(await status(page), text, JSON.stringify(opts));
      assert.equal(await shown(page, 'notifyPushEnable'), false, 'no switch-on button: ' + JSON.stringify(opts));
      assert.deepEqual(errors, []);
      await context.close();
    }
    ({context, page} = await open(browser, {iphone: true, standalone: true}));
    assert.equal(await status(page), 'Push ist auf diesem Gerät aus.', 'from the Home screen an iPhone can');
    await context.close();
    // A refused permission is explained.
    ({context, page} = await open(browser, {permission: 'default', answer: 'default'}));
    await page.click('#notifyPushEnable');
    await page.waitForFunction(() => /Erlaubnis wurde nicht erteilt/.test(document.getElementById('settingsSaveState').textContent));
    await context.close();

    // --- A phone ------------------------------------------------------------------------------------------
    ({context, page, errors} = await open(browser, {viewport: {width: 390, height: 844}, owner: true}));
    const fit = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: innerWidth,
      wide: Array.from(document.querySelectorAll('#view-benachrichtigungen .notify-select')).filter(s => s.getBoundingClientRect().right > innerWidth).length}));
    assert.ok(fit.scroll <= fit.view + 1 && fit.wide === 0, 'fits a phone: ' + JSON.stringify(fit));
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-phone.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();

    // --- sw.js in a sandbox --------------------------------------------------------------------------------
    const source = fs.readFileSync(path.join(root, 'sw.js'), 'utf8');
    const listeners = {};
    const shownSw = [];
    let clientsList = [];
    const opened = [];
    const self = {
      addEventListener: (type, fn) => { listeners[type] = fn; },
      skipWaiting() {},
      registration: {scope: 'https://coreui.test/', showNotification: (t, o) => { shownSw.push([t, o]); return Promise.resolve(); }},
      clients: {matchAll: () => Promise.resolve(clientsList), claim: () => Promise.resolve(), openWindow: url => { opened.push(url); return Promise.resolve(null); }},
    };
    vm.runInNewContext(source, {self, URL});
    const fire = async (type, event) => { let job; await listeners[type](Object.assign({waitUntil: p => { job = p; }}, event)); await job; };
    const data = obj => ({json: () => obj, text: () => JSON.stringify(obj)});
    await fire('push', {data: data({title: 'Mara hat geantwortet', body: 'Fertig', url: './app.html?session=cs_1', tag: 'coreai-answers-cs_1', skipIfVisible: true})});
    assert.equal(shownSw.length, 1, 'shown when nobody is looking');
    assert.equal(shownSw[0][0], 'Mara hat geantwortet');
    assert.equal(shownSw[0][1].data.url, './app.html?session=cs_1');
    assert.equal(shownSw[0][1].icon, './images/watermark_logo.png');
    clientsList = [{visibilityState: 'visible', focused: true, url: 'https://coreui.test/app.html'}];
    await fire('push', {data: data({title: 'x', skipIfVisible: true})});
    assert.equal(shownSw.length, 1, 'an answer is not shown while CoreAI is in front');
    await fire('push', {data: data({title: 'Neue Anmeldung bei CoreAI', url: 'https://evil.test/'})});
    assert.equal(shownSw.length, 2, 'security is shown even while watching');
    assert.equal(shownSw[1][1].data.url, './app.html', 'a foreign link becomes the chat');
    await fire('push', {data: null});
    assert.equal(shownSw[2][0], 'CoreAI', 'an empty push still shows something (browsers require it)');
    const navigated = [];
    clientsList = [{url: 'https://coreui.test/settings.html', focus() { return Promise.resolve({url: this.url, navigate: u => { navigated.push(u); return Promise.resolve(); }}); }}];
    await fire('notificationclick', {notification: {close() {}, data: {url: './app.html?session=cs_1'}}});
    assert.deepEqual(navigated, ['https://coreui.test/app.html?session=cs_1'], 'a tap focuses CoreAI and opens the chat');
    clientsList = [];
    await fire('notificationclick', {notification: {close() {}, data: {url: './settings.html#sicherheit'}}});
    assert.deepEqual(opened, ['https://coreui.test/settings.html#sicherheit'], 'or opens a window');
    assert.ok(!listeners.fetch, 'no fetch handler: nothing is cached');

    // --- The chat's browser fallback ---------------------------------------------------------------------------
    ({context, page, server} = await open(browser, {permission: 'granted'}));
    const fallback = await page.evaluate(async () => {
      Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
      const N = window.CoreAINotify;
      const out = {};
      window.__shown = [];
      const t = Date.now();
      out.long = await N.answerDone({sessionId: 'cs_1', startedAt: t - 60000, text: '**Fertig** mit `x`', name: 'Nova'}, t);
      out.shown = window.__shown.slice();
      out.short = await N.answerDone({sessionId: 'cs_1', startedAt: t - 10000, text: 'x'}, t);
      Object.defineProperty(document, 'hidden', {configurable: true, get: () => false});
      out.visible = await N.answerDone({sessionId: 'cs_1', startedAt: t - 60000, text: 'x'}, t);
      return out;
    });
    assert.equal(fallback.long, true, 'a long answer in a background tab is announced');
    assert.equal(fallback.shown[0][0], 'Nova hat geantwortet');
    assert.equal(fallback.shown[0][1].body, 'Fertig mit x');
    assert.equal(fallback.short, false, 'not a quick one');
    assert.equal(fallback.visible, false, 'not while the tab is in front');
    server.state.push.devices = [];
    const withPush = await page.evaluate(async () => {
      window.__answer = 'granted';
      await window.CoreAINotify.enable();
      Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
      window.__shown = [];
      return [await window.CoreAINotify.answerDone({sessionId: 'cs_1', startedAt: Date.now() - 60000, text: 'x'}), window.__shown.length];
    });
    assert.deepEqual(withPush, [false, 0], 'with Push on this browser the server announces it, the page stays quiet');
    await context.close();

    // --- Wiring ------------------------------------------------------------------------------------------------
    const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
    assert.ok(app.includes("window.CoreAINotify.answerDone({sessionId: sessionId, startedAt: Number(state.sendTimestampBySession[sessionId]) || 0,"),
      'the chat tells the fallback when an answer is done');
    assert.ok(app.includes("var linked = new URLSearchParams(location.search).get('session') || '';") && app.includes('/^cs_[A-Za-z0-9]{6,64}$/'),
      'a notification link opens its chat');
  } finally {
    await browser.close();
  }
  console.log('CoreAI notifications UI: off/on/blocked/no HTTPS/iPhone/server not ready, switch on and off, devices, test, '
    + 'E-Mail greyed without SMTP, categories saved, Inhaber-only and planned rows, phone, service worker, chat fallback OK');
})().catch(error => { console.error('CoreAI notifications UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
