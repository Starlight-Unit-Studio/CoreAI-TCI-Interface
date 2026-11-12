// CoreAI 0.7.32: Settings > Datenkontrollen after the reference screenshot, in a real browser (Playwright).
//   node scripts/data-controls-ui-selftest.cjs
// A stand-in for api/console_sessions.php (data_summary, archive_all, delete_all). Checked: the rows in
// order (two honestly In Vorbereitung, the crossed-out ones absent), Daten exportieren, Archivierte Chats
// with its count and link, Alle Chats archivieren (confirmation, CSRF, result), Alle Chats löschen (the
// typed word, Esc, CSRF, busy and locked chats in the result, an error from the server), nothing to do,
// and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 3, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 3, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.32', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, opts, viewport) {
  opts = opts || {};
  const st = {summary: Object.assign({active: 4, archived: 1, locked: 1}, opts.summary || {}), posts: [], exports: 0};
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/console_sessions.php') {
      if (req.method() === 'GET' && u.searchParams.get('action') === 'data_summary') return route.fulfill({json: Object.assign({ok: true, csrf_token: 'tok'}, st.summary)});
      if (req.method() === 'POST') {
        const body = JSON.parse(req.postData() || '{}');
        st.posts.push({body, csrf: req.headers()['x-coreui-csrf']});
        if (opts.fail) return route.fulfill({status: 403, json: {ok: false, error: 'csrf_failed'}});  // what stu_require_csrf answers
        if (body.action === 'archive_all') {
          const n = st.summary.active;
          st.summary = {active: 0, archived: st.summary.archived + n, locked: st.summary.locked};
          return route.fulfill({json: Object.assign({ok: true, archived_now: n}, st.summary)});
        }
        if (body.action === 'delete_all') {
          const total = st.summary.active + st.summary.archived;
          st.summary = {active: 1, archived: 1, locked: st.summary.locked};
          return route.fulfill({json: {ok: true, deleted: total - 2, busy: 2, failed: 0, locked: st.summary.locked, files: 3, summary: st.summary}});
        }
      }
      return route.fulfill({json: {ok: true, sessions: []}});
    }
    if (rel === 'api/account_export.php') {
      st.exports++;
      return route.fulfill({contentType: 'application/json', headers: {'Content-Disposition': 'attachment; filename="coreai-export.json"'}, body: '{}'});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#datenkontrollen');
  return {context, page, errors, st};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const texts = (page, sel) => page.$$eval(sel, l => l.map(x => x.textContent.trim()));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let {context, page, errors, st} = await open(browser);
    await until(page, () => !document.getElementById('dcArchiveAll').disabled);
    assert.deepEqual(await texts(page, '#view-datenkontrollen .dc-row .sec-copy strong'),
      ['Geteilte Links', 'Persönliche Daten zusammenführen', 'Daten exportieren', 'Archivierte Chats', 'Alle Chats archivieren', 'Alle Chats löschen']);
    assert.deepEqual(await texts(page, '#view-datenkontrollen .dc-row .kb-planned'), ['In Vorbereitung', 'In Vorbereitung']);
    const pageText = await page.textContent('#view-datenkontrollen');
    for (const gone of ['Modell für alle verbessern', 'Netzwerkzugriff', 'Work zurücksetzen']) assert.ok(!pageText.includes(gone), gone);
    assert.equal(await page.textContent('#dcArchivedNote'), '1 Chat im Archiv.');
    assert.equal(await page.textContent('#dcActiveNote'), '4 Chats sind aktiv. Du kannst sie im Archiv wiederherstellen.');
    assert.equal(await page.textContent('#dcDeleteNote'), '5 Chats samt Nachrichten und Dateien. Ein gesperrter Chat bleibt unberührt.');
    if (shots) await page.locator('#view-datenkontrollen').screenshot({path: path.join(shots, 'data-controls.png')});
    // Daten exportieren keeps working.
    await page.click('#exportAccountData');
    await until(page, () => !document.getElementById('exportAccountData').disabled);
    assert.equal(st.exports, 1);

    // Alle Chats archivieren: cancel, then confirm.
    await page.click('#dcArchiveAll');
    await page.waitForSelector('#dcArchiveConfirm:not([hidden])');
    assert.equal(await page.textContent('#dcArchiveText'),
      '4 Chats werden archiviert. Du findest sie unter Archivierte Chats und kannst sie dort wiederherstellen. Gesperrte Chats bleiben, wo sie sind.');
    await page.click('#dcArchiveCancel');
    assert.equal(await page.isHidden('#dcArchiveConfirm'), true);
    assert.equal(st.posts.length, 0);
    await page.click('#dcArchiveAll');
    await page.click('#dcArchiveOk');
    await until(page, () => document.getElementById('dcArchiveConfirm').hidden);
    assert.deepEqual(st.posts.pop(), {body: {action: 'archive_all', confirm: 'ARCHIVE_ALL_CHATS'}, csrf: 'tok'});
    assert.equal(await page.textContent('#dcStatus'), '4 Chats archiviert.');
    assert.equal(await page.isDisabled('#dcArchiveAll'), true, 'nothing left to archive');
    assert.equal(await page.textContent('#dcArchivedNote'), '5 Chats im Archiv.');

    // Alle Chats löschen: the word first.
    await page.click('#dcDeleteAll');
    await page.waitForSelector('#dcDeleteConfirm:not([hidden])');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'dcDeleteWord');
    assert.equal(await page.textContent('#dcDeleteText'),
      '5 Chats (davon 5 im Archiv) werden mit allen Nachrichten und hochgeladenen Dateien endgültig gelöscht. Das lässt sich nicht rückgängig machen. Gesperrte Chats bleiben erhalten.');
    assert.equal(await page.isDisabled('#dcDeleteOk'), true);
    await page.fill('#dcDeleteWord', 'loesch');
    assert.equal(await page.isDisabled('#dcDeleteOk'), true);
    await page.keyboard.press('Escape');
    assert.equal(await page.isHidden('#dcDeleteConfirm'), true, 'Esc closes');
    await page.click('#dcDeleteAll');
    assert.equal(await page.inputValue('#dcDeleteWord'), '', 'the word is asked again');
    await page.fill('#dcDeleteWord', 'löschen');
    assert.equal(await page.isDisabled('#dcDeleteOk'), false);
    if (shots) await page.screenshot({path: path.join(shots, 'data-controls-delete.png')});
    await page.keyboard.press('Enter');
    await until(page, () => document.getElementById('dcDeleteConfirm').hidden);
    assert.deepEqual(st.posts.pop(), {body: {action: 'delete_all', confirm: 'DELETE_ALL_CHATS'}, csrf: 'tok'});
    assert.equal(await page.textContent('#dcStatus'), '3 Chats gelöscht. 2 Chats, in denen ich noch arbeite, bleiben.');
    assert.equal(await page.textContent('#dcDeleteNote'), '2 Chats samt Nachrichten und Dateien. Ein gesperrter Chat bleibt unberührt.');
    // Archivierte Chats opens its page.
    await page.click('#view-datenkontrollen [data-goto="archivierte-chats"]');
    await until(page, () => !document.getElementById('view-archivierte-chats').hidden);
    assert.deepEqual(errors, []);
    await context.close();

    // An error from the server stays in the dialog.
    ({context, page, errors, st} = await open(browser, {fail: true}));
    await until(page, () => !document.getElementById('dcDeleteAll').disabled);
    await page.click('#dcDeleteAll');
    await page.fill('#dcDeleteWord', 'LÖSCHEN');
    await page.click('#dcDeleteOk');
    await until(page, () => document.getElementById('dcDeleteError').textContent !== '');
    assert.equal(await page.textContent('#dcDeleteError'), 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.');
    assert.equal(await page.isVisible('#dcDeleteConfirm'), true);
    assert.equal(await page.textContent('#dcDeleteOk'), 'ALLE LÖSCHEN');
    await context.close();

    // Nothing to do; a phone.
    ({context, page, errors} = await open(browser, {summary: {active: 0, archived: 0, locked: 0}}, {width: 390, height: 844}));
    await until(page, () => document.getElementById('dcArchivedNote').textContent !== 'Wird geladen …');
    assert.equal(await page.textContent('#dcArchivedNote'), 'Keine archivierten Chats.');
    assert.equal(await page.textContent('#dcDeleteNote'), 'Keine Chats.');
    assert.equal(await page.isDisabled('#dcArchiveAll'), true);
    assert.equal(await page.isDisabled('#dcDeleteAll'), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0);
    if (shots) await page.screenshot({path: path.join(shots, 'data-controls-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI data controls UI: rows, export, Archivierte Chats, archiving all, deleting all (word, Esc, busy, locked, error), nothing to do, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
