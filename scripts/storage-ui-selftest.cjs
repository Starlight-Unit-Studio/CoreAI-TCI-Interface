// CoreAI 0.7.28: Settings > Speicher after the reference screenshots, in a real browser (Playwright).
//   node scripts/storage-ui-selftest.cjs
// A stand-in for api/files.php keeps the items. Checks: the overview ("X von Y verwendet" with a bar,
// full, or no limit with the free space), Dateien and Bilder with sizes and counts; Dateien: the three
// tabs, project folders and back, the type filter, search, list and grid, the menu (Öffnen,
// Herunterladen, Im Chat öffnen, Favorit, Löschen), a favourite, deleting with a confirmation; Bilder:
// Deine Kreationen and Uploads, the Referenzbilder; uploading through "+" with the server's reason
// when the storage is full; guests; a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', 'base64');
const PROJECT = 'b'.repeat(32);
const id = n => 'm:' + String(n).repeat(32).slice(0, 32);

function filesServer(opts) {
  opts = opts || {};
  const chat = {id: 'sess1', title: 'Planung', project_id: PROJECT, project: 'Projekt Nord'};
  const st = {
    files: [
      {id: 'a:' + 'c'.repeat(32), name: 'auswertung.zip', kind: 'mara', size: 184320, created: '2026-11-12 10:00:00', url: 'api/console_artifact.php?id=x', thumb: '', chat, favorite: false},
      {id: id(1), name: 'bericht.pdf', kind: 'document', size: 3000000, created: '2026-11-11 09:00:00', url: 'api/console_media.php?uuid=1', thumb: '', chat, favorite: false},
      {id: id(2), name: 'ton.webm', kind: 'audio', size: 22000, created: '2026-11-10 08:00:00', url: 'api/console_media.php?uuid=2', thumb: '', chat: null, favorite: false},
    ],
    images: [
      {id: id(3), name: 'Mara-Bild-1.png', kind: 'image', source: 'creation', size: 900000, created: '2026-11-12 11:00:00', url: 'api/console_media.php?uuid=3', thumb: 'api/console_media.php?uuid=3', chat: null, favorite: false},
      {id: id(4), name: 'foto.png', kind: 'image', source: 'upload', size: 500000, created: '2026-11-09 11:00:00', url: 'api/console_media.php?uuid=4', thumb: 'api/console_media.php?uuid=4', chat: null, favorite: false},
    ],
    posts: [], uploads: 0, limit: opts.limit === undefined ? 20 * 1073741824 : opts.limit, used: opts.used || 6810000000,
  };
  function summary() {
    const bytes = list => list.reduce((s, x) => s + x.size, 0);
    return {ok: true, used: st.used, limit: st.limit, files: {count: st.files.length, bytes: bytes(st.files)}, images: {count: st.images.length, bytes: bytes(st.images)},
      disk_free: st.limit == null ? 120 * 1073741824 : null, favorites_ready: true,
      references: {portrait: {uuid: '5'.repeat(32), url: 'api/console_media.php?uuid=5'}, body: null}, csrf_token: 'tok'};
  }
  function list(u) {
    const view = u.searchParams.get('view');
    const tab = u.searchParams.get('tab');
    const q = (u.searchParams.get('q') || '').toLowerCase();
    const type = u.searchParams.get('type') || '';
    const project = u.searchParams.get('project') || '';
    let items = view === 'images' ? st.images.filter(x => (tab === 'uploads') === (x.source === 'upload')) : st.files.slice();
    if (view !== 'images') {
      if (tab === 'favorites') items = items.filter(x => x.favorite);
      if (type) items = items.filter(x => x.kind === type);
      if (project) items = items.filter(x => x.chat && x.chat.project_id === project);
    }
    if (q) items = items.filter(x => x.name.toLowerCase().includes(q));
    // Like api/files_store.php: folders from the items that pass the filters (Deine Elemente, top level, no search).
    const inProject = items.filter(x => x.chat && x.chat.project_id === PROJECT);
    const folders = view !== 'images' && tab === 'all' && !project && !q && inProject.length
      ? [{id: PROJECT, name: 'Projekt Nord', count: inProject.length, size: inProject.reduce((s, x) => s + x.size, 0)}] : [];
    return {ok: true, items, has_more: false, total: items.length, folders};
  }
  function handle(method, u, body) {
    if (opts.guest) return {status: 403, json: {ok: false, error: 'guests_not_allowed'}};
    const action = u.searchParams.get('action');
    if (method === 'GET' && action === 'summary') return {json: summary()};
    if (method === 'GET' && action === 'list') return {json: list(u)};
    st.posts.push(Object.assign({action}, body));
    const all = st.files.concat(st.images);
    const item = all.find(x => x.id === body.id);
    if (!item) return {status: 404, json: {ok: false, error: 'not_found'}};
    if (action === 'favorite') { item.favorite = !!body.on; return {json: {ok: true, id: item.id, favorite: item.favorite}}; }
    if (action === 'delete') {
      st.files = st.files.filter(x => x.id !== item.id);
      st.images = st.images.filter(x => x.id !== item.id);
      st.used -= item.size;
      const s = summary();
      return {json: {ok: true, id: item.id, freed: item.size, used: st.used, files: s.files, images: s.images}};
    }
    return {status: 404, json: {ok: false, error: 'unknown_action'}};
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 3, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 3, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.28', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, srv, viewport, uploadAnswer) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/files.php') {
      const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null;
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'changes with CSRF');
      const out = srv.handle(req.method(), u, body);
      return route.fulfill({status: out.status || 200, json: out.json});
    }
    if (rel === 'api/console_upload.php') {
      srv.st.uploads++;
      return route.fulfill(uploadAnswer || {status: 200, json: {ok: true, kind: 'document', uuid: 'f'.repeat(32)}});
    }
    if (rel === 'api/console_media.php') return route.fulfill({contentType: 'image/png', body: PNG});
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#speicher');
  return {context, page, errors};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const names = page => page.$$eval('#stList .st-item b', l => l.map(x => x.childNodes[0].textContent));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let srv = filesServer();
    let {context, page, errors} = await open(browser, srv);
    await until(page, () => document.getElementById('stUsage').textContent.length > 0);

    // Overview like the reference.
    assert.equal(await page.textContent('#stUsage'), '6,34 GB von 20 GB verwendet');
    assert.equal(await page.getAttribute('#stBar', 'aria-valuenow'), '32');
    assert.equal(await page.textContent('#stFilesMeta'), '3,1 MB · 3 Dateien');
    assert.equal(await page.textContent('#stImagesMeta'), '1,3 MB · 2 Bilder');
    assert.equal(await page.textContent('#view-speicher .sec-heading'), 'Speicherplatz verwalten');
    assert.equal(await page.textContent('#stStatus'), '');
    if (shots) await page.locator('#view-speicher').screenshot({path: path.join(shots, 'storage-overview.png')});

    // Dateien: Deine Elemente with the project folder first.
    await page.click('[data-st-open="files"] .sec-copy');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 4);
    assert.equal(await page.textContent('#stSubTitle'), 'Alle');
    assert.deepEqual(await page.$$eval('#stTabs .st-tab', l => l.map(x => x.textContent + (x.getAttribute('aria-selected') === 'true' ? '*' : ''))),
      ['Vorgeschlagen', 'Favoriten', 'Deine Elemente*']);
    assert.deepEqual(await names(page), ['Projekt Nord', 'auswertung.zip', 'bericht.pdf', 'ton.webm']);
    assert.match(await page.textContent('#stList .st-folder small'), /^Projekt · 2 Dateien · 3 MB$/);
    assert.match(await page.textContent('#stList .st-item[data-id^="a:"] small'), /^180 KB · 12\.11\.2026 · von Mara · Projekt Nord · Planung$/);
    // Into the folder and back.
    await page.click('#stList .st-folder .st-item-main');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 2 && !document.getElementById('stCrumb').hidden);
    assert.deepEqual(await names(page), ['auswertung.zip', 'bericht.pdf']);
    assert.equal(await page.textContent('#stCrumb span'), 'Alle / Projekt Nord');
    await page.click('#stCrumb');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 4);
    // Type filter.
    await page.click('#stFilter');
    assert.deepEqual(await page.$$eval('#stTypeMenu .arc-option b', l => l.map(x => x.textContent)), ['Alle Typen', 'Dokumente', 'Audio', 'Video', 'Von Mara erstellt']);
    await page.click('#stTypeMenu [data-value="audio"]');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 1);
    assert.deepEqual(await names(page), ['ton.webm']);
    assert.equal(await page.evaluate(() => document.getElementById('stFilter').classList.contains('is-active')), true);
    await page.click('#stFilter');
    await page.click('#stTypeMenu [data-value=""]');
    // Search.
    await page.fill('#stSearch', 'beri');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 1);
    assert.deepEqual(await names(page), ['bericht.pdf']);
    await page.fill('#stSearch', 'nichtda');
    await until(page, () => !document.getElementById('stEmpty').hidden);
    assert.equal(await page.textContent('#stEmpty'), 'Nichts gefunden für „nichtda“.');
    await page.fill('#stSearch', '');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 4);
    // Grid and list.
    await page.click('#stViewGrid');
    assert.equal(await page.getAttribute('#stList', 'data-layout'), 'grid');
    assert.equal(await page.isHidden('#stListHead'), true);
    await page.click('#stViewList');
    assert.equal(await page.getAttribute('#stList', 'data-layout'), 'list');
    // The menu of an item.
    await page.click('#stList .st-item[data-id="' + id(1) + '"] .st-more-btn');
    assert.deepEqual(await page.$$eval('#stItemMenu .arc-option span', l => l.map(x => x.textContent)),
      ['Öffnen', 'Herunterladen', 'Im Chat öffnen', 'Zu Favoriten hinzufügen', 'Löschen']);
    if (shots) await page.screenshot({path: path.join(shots, 'storage-files-menu.png')});
    await page.click('#stItemMenu .arc-option:has-text("Zu Favoriten hinzufügen")');
    await until(page, () => /ist jetzt ein Favorit/.test(document.getElementById('stStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'favorite', id: id(1), on: true});
    await page.click('#stTabs [data-tab="favorites"]');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 1);
    assert.deepEqual(await names(page), ['bericht.pdf']);
    assert.equal(await page.isVisible('#stList .st-fav'), true);
    await page.click('#stTabs [data-tab="suggested"]');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 3);
    // Deleting asks first; "Abbrechen" keeps the file.
    await page.click('#stList .st-item[data-id="' + id(2) + '"] .st-more-btn');
    await page.click('#stItemMenu .arc-option:has-text("Löschen")');
    await page.waitForSelector('#stDeleteConfirm:not([hidden])');
    assert.equal(await page.textContent('#stDeleteText'), '„ton.webm“ (22 KB) wird endgültig gelöscht.');
    await page.click('#stDeleteCancel');
    assert.equal(srv.st.posts.length, 0);
    await page.click('#stList .st-item[data-id="' + id(1) + '"] .st-more-btn');
    await page.click('#stItemMenu .arc-option:has-text("Löschen")');
    assert.equal(await page.textContent('#stDeleteText'), '„bericht.pdf“ (2,9 MB) wird endgültig gelöscht. Im Chat „Planung“ ist die Datei danach nicht mehr zu sehen.');
    await page.click('#stDeleteOk');
    await until(page, () => document.getElementById('stDeleteConfirm').hidden && document.querySelectorAll('#stList .st-item').length === 2);
    assert.deepEqual(srv.st.posts.pop(), {action: 'delete', id: id(1)});
    assert.equal(await page.textContent('#stStatus'), '„bericht.pdf“ gelöscht, 2,9 MB frei.');
    // Back to the overview: the numbers follow.
    await page.click('#stBack');
    await until(page, () => document.getElementById('stFilesMeta').textContent === '202 KB · 2 Dateien');

    // Bilder: Deine Kreationen, Uploads, Referenzbilder.
    await page.click('[data-st-open="images"] .sec-copy');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 1);
    assert.equal(await page.textContent('#stSubTitle'), 'Bilder');
    assert.deepEqual(await page.$$eval('#stTabs .st-tab', l => l.map(x => x.textContent + (x.getAttribute('aria-selected') === 'true' ? '*' : ''))),
      ['Deine Kreationen*', 'Uploads']);
    assert.deepEqual(await names(page), ['Mara-Bild-1.png']);
    assert.equal(await page.getAttribute('#stList', 'data-layout'), 'grid');
    assert.equal(await page.isHidden('#stToolbar'), true);
    assert.deepEqual(await page.$$eval('#stRefs .st-chip', l => l.map(x => x.textContent.trim())), ['Hinzufügen', 'Referenzfoto']);
    assert.equal(await page.getAttribute('#stRefs [data-goto]', 'data-goto'), 'personalisierung');
    assert.equal(await page.getAttribute('#stFile', 'accept'), 'image/png,image/jpeg,image/webp,image/gif');
    await page.click('#stTabs [data-tab="uploads"]');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 1 && document.querySelector('#stList .st-item b').textContent === 'foto.png');
    await page.click('#stList .st-item .st-more-btn');
    assert.deepEqual(await page.$$eval('#stItemMenu .arc-option span', l => l.map(x => x.textContent)), ['Öffnen', 'Herunterladen', 'Löschen'], 'no favourites for pictures');
    await page.keyboard.press('Escape');
    assert.equal(await page.isHidden('#stItemMenu'), true);
    if (shots) await page.screenshot({path: path.join(shots, 'storage-images.png')});
    assert.deepEqual(errors, []);
    await context.close();

    // Full: the bar turns red, an upload says why.
    srv = filesServer({limit: 1073741824, used: 1073741824});
    ({context, page, errors} = await open(browser, srv, null, {status: 413, json: {ok: false, error: 'storage_full', used_label: '1 GB', limit_label: '1 GB'}}));
    await until(page, () => document.getElementById('stUsage').textContent.length > 0);
    assert.equal(await page.textContent('#stUsage'), '1 GB von 1 GB verwendet');
    assert.equal(await page.evaluate(() => document.getElementById('stBar').classList.contains('is-full')), true);
    assert.match(await page.textContent('#stUsageNote'), /^Dein Speicher ist voll\./);
    await page.click('[data-st-open="files"] .sec-copy');
    await page.setInputFiles('#stFile', {name: 'neu.txt', mimeType: 'text/plain', buffer: Buffer.from('Hallo')});
    await until(page, () => /Dein Speicher ist voll \(1 GB von 1 GB\)\./.test(document.getElementById('stStatus').textContent));
    assert.equal(await page.textContent('#stStatus'), '„neu.txt“: Dein Speicher ist voll (1 GB von 1 GB).');
    assert.equal(srv.st.uploads, 1);
    assert.deepEqual(errors, []);
    await context.close();

    // No limit: the free space of the server; a phone without sideways scrolling.
    srv = filesServer({limit: null, used: 210000});
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    await until(page, () => document.getElementById('stUsage').textContent.length > 0);
    assert.equal(await page.textContent('#stUsage'), '206 KB verwendet');
    assert.equal(await page.textContent('#stUsageNote'), 'Für dein Konto gibt es keine Speichergrenze. Auf dem Server sind noch 120 GB frei.');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0);
    await page.click('[data-st-open="files"] .sec-copy');
    await until(page, () => document.querySelectorAll('#stList .st-item').length === 4);
    // An upload that works reloads the list.
    await page.setInputFiles('#stFile', {name: 'neu.txt', mimeType: 'text/plain', buffer: Buffer.from('Hallo')});
    await until(page, () => document.getElementById('stStatus').textContent === 'Hochgeladen.');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'no sideways scrolling in the list');
    if (shots) await page.screenshot({path: path.join(shots, 'storage-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();

    // Guests.
    srv = filesServer({guest: true});
    ({context, page, errors} = await open(browser, srv));
    await until(page, () => /nur für angemeldete Konten/.test(document.getElementById('stStatus').textContent));
    assert.equal(await page.isHidden('#stMain'), true);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI storage UI: overview with limit, full and without limit, Dateien (tabs, project folder, type, search, grid, menu, favourite,'
    + ' delete with confirmation), Bilder (Kreationen, Uploads, Referenzbilder), upload with the server\'s reason, phone, guests OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
