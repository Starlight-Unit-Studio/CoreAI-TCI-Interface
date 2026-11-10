// CoreAI 0.6.87: Settings > Archivierte Chats in a real browser (Playwright), like the ChatGPT reference.
//   node scripts/archived-chats-ui-selftest.cjs
// A stand-in for api/console_sessions.php keeps the archive. Checks: "Keine archivierten Chats";
// the search field and the two menus of the reference ("Alle Chats": Typ with Alle Chats and Cloud
// (a cloud Core answered, marked with a cloud), Sortieren nach Aktualisiert, Erstellt, Alphabetisch; "Alle Projekte": Chats, Geplante
// Aufgaben planned); the group "Kein Projekt" with its count; each chat with title, date,
// "Dearchivieren" and "Löschen"; search and sort go to the server; Dearchivieren; Löschen with a
// confirmation (cancel, an error, then deleted); a phone without sideways scrolling, no text under 11px.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';

function archiveServer(sessions) {
  const st = {sessions: sessions.map(s => Object.assign({}, s)), gets: [], posts: [], deleteError: 'session_busy'};
  function handle(method, url, body) {
    if (method === 'GET') {
      const q = (url.searchParams.get('q') || '').toLowerCase();
      const sort = url.searchParams.get('sort') || 'updated';
      const type = url.searchParams.get('type') || 'all';
      st.gets.push({action: url.searchParams.get('action'), q, type, sort});
      const key = {updated: s => s.updated_at, created: s => s.created_at}[sort];
      const list = st.sessions.filter(s => (!q || s.title.toLowerCase().includes(q)) && (type === 'all' || s.cloud))
        .sort((a, b) => key ? (key(a) < key(b) ? 1 : -1) : a.title.localeCompare(b.title, 'de'));
      return {status: 200, json: {ok: true, sessions: list, count: list.length, projects_ready: false}};
    }
    st.posts.push(body);
    if (body.action === 'restore') { st.sessions = st.sessions.filter(s => s.id !== body.id); return {status: 200, json: {ok: true, id: body.id, archived: false}}; }
    if (body.action === 'delete_permanently') {
      if (st.deleteError) { const error = st.deleteError; st.deleteError = ''; return {status: 409, json: {ok: false, error}}; }
      st.sessions = st.sessions.filter(s => s.id !== body.id);
      return {status: 200, json: {ok: true, id: body.id, deleted: true, counts: {}}};
    }
    return {status: 400, json: {ok: false, error: 'unknown_action'}};
  }
  return {st, handle};
}

const SESSIONS = [
  {id: 'cs_aaaaaaaa1', title: 'CoreAI-TCI-Interface einrichten und alle Dienste prüfen', created_at: '2026-01-05T09:00:00+01:00',
    updated_at: '2026-11-09T07:45:00+01:00', archived_at: '2026-11-09T08:00:00+01:00', message_count: 12, project: null},
  {id: 'cs_bbbbbbbb2', title: 'Research zu Mara Thorne', created_at: '2026-02-03T13:00:00+01:00',
    updated_at: '2026-02-03T13:10:00+01:00', archived_at: '2026-11-08T08:00:00+01:00', message_count: 4, cloud: true, project: null},
];

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'account_security.php': {ok: true, sessions: [], csrf_token: 'tok'},
  'health.php': {ok: true, version: '0.6.87', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel === 'api/console_sessions.php') {
      const out = srv.handle(req.method(), url, req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null);
      return route.fulfill({status: out.status, json: out.json});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#archivierte-chats');
  return {context, page, errors};
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // Empty archive.
    let srv = archiveServer([]);
    let {context, page, errors} = await open(browser, srv);
    await page.waitForSelector('#arcEmpty:not([hidden])');
    assert.equal(await page.textContent('#arcEmptyText'), 'Keine archivierten Chats');
    assert.equal(await page.$$eval('#arcGroups .arc-row', l => l.length), 0);
    if (shots) await page.locator('#view-archivierte-chats').screenshot({path: path.join(shots, 'archive-empty.png')});
    await context.close();

    // Two archived chats.
    srv = archiveServer(SESSIONS);
    ({context, page, errors} = await open(browser, srv));
    await page.waitForSelector('#arcGroups .arc-row');
    assert.equal(await page.getAttribute('#arcSearch', 'placeholder'), 'Archivierte Chats durchsuchen');
    assert.equal(await page.textContent('#arcTypeLabel'), 'Alle Chats');
    assert.equal(await page.textContent('#arcProjectLabel'), 'Alle Projekte');
    assert.equal(await page.textContent('.arc-group-head h3'), 'Kein Projekt');
    assert.equal(await page.textContent('.arc-count'), '2 Chats');
    assert.deepEqual(await page.$$eval('.arc-row .arc-copy strong', l => l.map(x => x.textContent)), SESSIONS.map(s => s.title));
    assert.match(await page.textContent('.arc-row:nth-child(1) small'), /^9\. Nov\. 2026, 7:45$/);
    assert.deepEqual(await page.$$eval('.arc-row:nth-child(1) .arc-btn', l => l.map(x => x.textContent)), ['Dearchivieren', 'Löschen']);
    assert.equal(await page.getAttribute('.arc-row:nth-child(1) .arc-btn.danger', 'class'), 'arc-btn danger');
    assert.match(await page.textContent('#view-archivierte-chats'), /Projekte sind in Vorbereitung/);
    if (shots) await page.locator('#view-archivierte-chats').screenshot({path: path.join(shots, 'archive-list.png')});

    // Search and filter go to the server.
    await page.fill('#arcSearch', 'mara');
    await page.waitForFunction(() => document.querySelectorAll('.arc-row').length === 1);
    assert.deepEqual(srv.st.gets.pop(), {action: 'archived', q: 'mara', type: 'all', sort: 'updated'});
    await page.fill('#arcSearch', 'gibt es nicht');
    await page.waitForSelector('#arcEmpty:not([hidden])');
    assert.equal(await page.textContent('#arcEmptyText'), 'Keine passenden archivierten Chats');
    await page.fill('#arcSearch', '');
    await page.waitForFunction(() => document.querySelectorAll('.arc-row').length === 2);

    // The menu "Alle Chats": Typ and Sortieren nach, like the reference.
    await page.click('#arcTypeBtn');
    await page.waitForSelector('#arcTypeMenu:not([hidden])');
    assert.equal(await page.getAttribute('#arcTypeBtn', 'aria-expanded'), 'true');
    assert.deepEqual(await page.$$eval('#arcTypeMenu .arc-menu-head', l => l.map(x => x.textContent)), ['Typ', 'Sortieren nach']);
    assert.deepEqual(await page.$$eval('#arcTypeMenu .arc-option span', l => l.map(x => x.textContent)),
      ['Alle Chats', 'Cloud', 'Aktualisiert', 'Erstellt', 'Alphabetisch']);
    assert.deepEqual(await page.$$eval('#arcTypeMenu [aria-checked="true"] span', l => l.map(x => x.textContent)), ['Alle Chats', 'Aktualisiert']);
    if (shots) await page.locator('#view-archivierte-chats').screenshot({path: path.join(shots, 'archive-type-menu.png')});
    // Cloud: only chats in which a cloud Core answered, marked with a cloud.
    assert.equal(await page.$$eval('.arc-row.is-cloud .arc-cloud', l => l.length), 1, 'the cloud chat carries the cloud');
    await page.click('#arcTypeMenu [data-arc-type="cloud"]');
    await page.waitForFunction(() => document.querySelectorAll('.arc-row').length === 1);
    assert.deepEqual(srv.st.gets.pop(), {action: 'archived', q: '', type: 'cloud', sort: 'updated'});
    assert.equal(await page.textContent('#arcTypeLabel'), 'Cloud');
    assert.equal(await page.textContent('.arc-row strong'), 'Research zu Mara Thorne');
    await page.click('#arcTypeBtn');
    assert.deepEqual(await page.$$eval('#arcTypeMenu [aria-checked="true"] span', l => l.map(x => x.textContent)), ['Cloud', 'Aktualisiert']);
    await page.click('#arcTypeMenu [data-arc-type="all"]');
    await page.waitForFunction(() => document.querySelectorAll('.arc-row').length === 2);
    assert.equal(await page.textContent('#arcTypeLabel'), 'Alle Chats');
    await page.click('#arcTypeBtn');
    await page.click('#arcTypeMenu [data-arc-sort="alpha"]');
    await page.waitForFunction(() => document.querySelector('.arc-row strong').textContent.startsWith('CoreAI-TCI'));
    assert.deepEqual(srv.st.gets.pop(), {action: 'archived', q: '', type: 'all', sort: 'alpha'});
    await page.click('#arcTypeBtn');
    await page.click('#arcTypeMenu [data-arc-sort="created"]');
    await page.waitForFunction(() => document.querySelector('.arc-row strong').textContent === 'Research zu Mara Thorne');
    assert.deepEqual(srv.st.gets.pop(), {action: 'archived', q: '', type: 'all', sort: 'created'});
    await page.click('#arcTypeBtn');
    assert.deepEqual(await page.$$eval('#arcTypeMenu [aria-checked="true"] span', l => l.map(x => x.textContent)), ['Alle Chats', 'Erstellt']);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#arcTypeMenu', {state: 'hidden'});
    assert.equal(await page.evaluate(() => document.activeElement.id), 'arcTypeBtn');
    await page.click('#arcTypeBtn');
    await page.click('#arcTypeMenu [data-arc-sort="updated"]');
    await page.waitForFunction(() => document.querySelector('.arc-row strong').textContent.startsWith('CoreAI-TCI'));

    // The menu "Alle Projekte": Chats; Geplante Aufgaben planned.
    await page.click('#arcProjectBtn');
    await page.waitForSelector('#arcProjectMenu:not([hidden])');
    assert.deepEqual(await page.$$eval('#arcProjectMenu .arc-option span', l => l.map(x => x.textContent)), ['Alle Projekte', 'Chats', 'Geplante Aufgaben']);
    assert.match(await page.textContent('#arcProjectMenu [data-arc-project="tasks"]'), /IN VORBEREITUNG/);
    if (shots) await page.locator('#view-archivierte-chats').screenshot({path: path.join(shots, 'archive-project-menu.png')});
    await page.click('#arcProjectMenu [data-arc-project="chats"]');
    await page.waitForSelector('#arcProjectMenu', {state: 'hidden'});
    assert.equal(await page.textContent('#arcProjectLabel'), 'Chats');
    assert.equal(await page.$$eval('.arc-row', l => l.length), 2, 'every chat is a chat without a project');
    await page.click('#arcProjectBtn');
    await page.click('#arcProjectMenu [data-arc-project="tasks"]', {force: true});
    await page.waitForFunction(() => /Geplante Aufgaben sind in Vorbereitung/.test(document.getElementById('arcStatus').textContent));
    await page.mouse.click(5, 5);

    // Dearchivieren.
    await page.click('.arc-row[data-session-id="cs_bbbbbbbb2"] .arc-btn:not(.danger)');
    await page.waitForFunction(() => document.querySelectorAll('.arc-row').length === 1);
    assert.deepEqual(srv.st.posts.pop(), {action: 'restore', id: 'cs_bbbbbbbb2'});
    assert.equal(await page.textContent('#arcStatus'), '„Research zu Mara Thorne“ ist wieder in deiner Chatliste.');
    assert.equal(await page.textContent('.arc-count'), '1 Chat');

    // Löschen: cancel, an error, then deleted.
    await page.click('.arc-row .arc-btn.danger');
    await page.waitForSelector('#arcConfirm:not([hidden])');
    assert.match(await page.textContent('#arcConfirmText'), /^„CoreAI-TCI-Interface einrichten und alle Dienste prüfen“ wird mit allen Nachrichten/);
    const posts = srv.st.posts.length;
    await page.click('#arcConfirmCancel');
    await page.waitForSelector('#arcConfirm', {state: 'hidden'});
    assert.equal(srv.st.posts.length, posts, 'cancel sends nothing');
    await page.click('.arc-row .arc-btn.danger');
    await page.click('#arcConfirmOk');
    await page.waitForFunction(() => document.getElementById('arcConfirmError').textContent !== '');
    assert.equal(await page.textContent('#arcConfirmError'), 'Der Chat hat noch eine laufende Browser-Recherche und lässt sich erst danach löschen.');
    await page.click('#arcConfirmOk');
    await page.waitForSelector('#arcConfirm', {state: 'hidden'});
    assert.deepEqual(srv.st.posts.pop(), {action: 'delete_permanently', id: 'cs_aaaaaaaa1', confirmation: 'DELETE:cs_aaaaaaaa1'});
    await page.waitForSelector('#arcEmpty:not([hidden])');
    assert.equal(await page.textContent('#arcStatus'), '„CoreAI-TCI-Interface einrichten und alle Dienste prüfen“ wurde gelöscht.');
    assert.deepEqual(errors, []);
    await context.close();

    // Phone.
    srv = archiveServer(SESSIONS);
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    await page.waitForSelector('#arcGroups .arc-row');
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(wide <= 1, 'no sideways scrolling on a phone: ' + wide);
    const tiny = await page.$$eval('#view-archivierte-chats *', els => els.filter(e => e.offsetParent !== null
      && Array.from(e.childNodes).some(n => n.nodeType === 3 && n.textContent.trim()) && parseFloat(getComputedStyle(e).fontSize) < 11).map(e => e.textContent.trim()));
    assert.deepEqual(tiny, [], 'no text under 11px');
    if (shots) await page.screenshot({path: path.join(shots, 'archive-phone.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI archived chats: empty state, search, menus like the reference (Typ with Cloud, Sortieren nach, Projekte with '
    + 'Geplante Aufgaben planned), Kein Projekt with count, title, date, Dearchivieren, Löschen with confirmation (cancel, error, deleted), phone OK');
})().catch(error => { console.error(error); process.exit(1); });
