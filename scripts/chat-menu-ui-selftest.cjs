// CoreAI 0.6.87: the chat menu (⋮) in a real browser (Playwright), modeled on the ChatGPT reference.
//   node scripts/chat-menu-ui-selftest.cjs
// The real header, menu, dialogs and search of app.html with the real stylesheets and
// js/coreui-chat-menu.js; a stand-in for the app (window.CoreAIChatApp) and for
// api/console_sessions.php. Checks: hidden without a chat; the title and the items in the order
// of the reference; keyboard and outside click; Teilen (a Markdown file); Anpinnen and back;
// Chat sperren and Zum Projekt hinzufügen planned (no request); Hochgeladene Dateien (list, empty);
// Im Chat suchen (two letters, hits with marks, a hit jumps to its message); Zum Startbildschirm
// hinzufügen (the address leads to the chat, link copied); Archivieren with the dialog of the
// reference (cancel, then archive); Löschen (archive_first, an error, then deleted); a busy chat;
// a phone; no text under 11px. Also the app's revealMessage (js/console-app.js) loading older pages.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const shots = process.env.COREUI_SHOTS || '';

const app = fs.readFileSync(path.join(root, 'app.html'), 'utf8');
const head = app.slice(app.indexOf('<head>'), app.indexOf('</head>'));
const styles = (head.match(/<link[^>]+stylesheet[^>]*>/g) || []).filter(link => !/fonts\.googleapis/.test(link)).join('\n');
const headerStart = app.indexOf('<header class="app-header">');
const header = app.slice(headerStart, app.indexOf('</header>', headerStart) + '</header>'.length);
assert(header.includes('id="chatMenuBtn"'), 'the menu button sits in the app header');
const blockStart = app.indexOf('<!-- 0.6.87: chat menu, dialogs and the search inside a chat');
const blockEnd = app.indexOf('<script', blockStart);
assert(blockStart > 0 && blockEnd > blockStart, 'chat menu markup in app.html');
const menuHtml = app.slice(blockStart, blockEnd);
const menuScript = (app.match(/<script src="(\.\/js\/coreui-chat-menu\.js\?v=\d{25})"><\/script>/) || [])[1];
assert(menuScript, 'chat menu script tag with version');

const MD = '# Wetter in Köln\n\nAus CoreAI: TCI Interface.\n\n### Lena · 10.11.2026 08:00 (#11)\n\nWie wird das Wetter?\n';

function server() {
  const st = {posts: [], gets: [], files: [{uuid: 'u-1', kind: 'document', name: 'plan.pdf', mime: 'application/pdf', size: 2048, created_at: '2026-11-10T08:00:00+01:00'}],
    deleteError: 'session_busy'};
  function handle(method, url, body) {
    if (method === 'GET') {
      const action = url.searchParams.get('action');
      st.gets.push({action, id: url.searchParams.get('id'), q: url.searchParams.get('q')});
      if (action === 'export') return {ok: true, id: 'cs_test1234', title: 'Wetter in Köln', filename: 'wetter-in-koeln.md', markdown: MD, messages: 1};
      if (action === 'files') return {ok: true, files: st.files};
      if (action === 'search') {
        return {ok: true, results: [{id: 11, mara: false, created_at: '2026-11-10T08:00:00+01:00', snippet: 'Wie wird das Wetter morgen in Köln?'},
          {id: 12, mara: true, created_at: '2026-11-10T08:01:00+01:00', snippet: 'Morgen wird es in Köln sonnig.'}]};
      }
      return {ok: false, error: 'unknown_action'};
    }
    st.posts.push(body);
    if (body.action === 'pin') return {ok: true, id: body.id, pinned: !!body.pinned};
    if (body.action === 'archive') return {ok: true, id: body.id, archived: true};
    if (body.action === 'delete_permanently') {
      if (st.deleteError) { const error = st.deleteError; st.deleteError = ''; return {status: 409, json: {ok: false, error}}; }
      return {ok: true, id: body.id, deleted: true, counts: {}};
    }
    return {ok: false, error: 'unknown_action'};
  }
  return {st, handle};
}

function harness(chat) {
  return `<!doctype html><html lang="de" data-coreui-theme="dark"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body class="app-body">
<div class="app-shell">${header}<main class="messages-area" id="messagesArea"><div id="feed">
${Array.from({length: 4}, (_, i) => `<div class="msg-row user" data-message-id="${11 + i}"><p>Nachricht ${11 + i}</p></div>`).join('')}
</div></main></div>${menuHtml}
<script>
window.__calls = {refresh: 0, removed: [], reveal: []};
window.__chat = ${JSON.stringify(chat)};
window.CoreAIChatApp = {apiBase: './api',
  active: function () { return window.__chat; },
  refresh: function () { window.__calls.refresh++; if (window.__chat) window.__chat.pinned = !window.__chat.pinned; window.CoreAIChatMenu.sync(); return Promise.resolve(); },
  removed: function (id) { window.__calls.removed.push(id); window.__chat = null; window.CoreAIChatMenu.sync(); return Promise.resolve(); },
  reveal: function (id) { window.__calls.reveal.push(id); var row = document.querySelector('[data-message-id="' + id + '"]'); return Promise.resolve(!!row); }};
</script>
<script src="${menuScript}"></script></body></html>`;
}

async function open(browser, chat, viewport) {
  const srv = server();
  const context = await browser.newContext({viewport: viewport || {width: 1280, height: 860}, locale: 'de-DE', acceptDownloads: true,
    permissions: ['clipboard-read', 'clipboard-write']});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.pathname === '/app.html') return route.fulfill({contentType: 'text/html', body: harness(chat)});
    if (url.pathname === '/api/console_sessions.php') {
      const out = srv.handle(req.method(), url, req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null);
      return out.status ? route.fulfill({status: out.status, json: out.json}) : route.fulfill({json: out});
    }
    const file = path.join(root, decodeURIComponent(url.pathname));
    if (fs.existsSync(file) && fs.statSync(file).isFile()) {
      return route.fulfill({contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream', body: fs.readFileSync(file)});
    }
    return route.fulfill({status: 404, body: ''});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/app.html');
  return {context, page, srv, errors};
}

const CHAT = {id: 'cs_test1234', title: 'Wetter in Köln', pinned: false, busy: false, assistant: 'Mara'};
const toast = page => page.waitForFunction(() => !document.getElementById('chatMenuToast').hidden).then(() => page.textContent('#chatMenuToast'));
async function choose(page, action) {
  await page.evaluate(() => { const t = document.getElementById('chatMenuToast'); t.hidden = true; t.textContent = ''; });
  await page.click('#chatMenuBtn');
  await page.waitForSelector('#chatMenu:not([hidden])');
  // Planned items carry aria-disabled; a person can still tap them and gets the hint.
  await page.click(`#chatMenu [data-chat-action="${action}"]`, {force: ['lock', 'project'].includes(action)});
}

(async () => {
  // --- revealMessage of js/console-app.js: older pages until the hit is there --------------------------
  const source = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
  const revealSrc = source.slice(source.indexOf('  function revealMessage(messageId) {'), source.indexOf('  // 0.6.87: what the chat menu needs from the app'));
  assert.ok(revealSrc.length > 200, 'revealMessage in console-app.js');
  const sandbox = {loaded: [3], pages: 0, older: [], scrolled: false};
  const ctx = {
    state: {renderLimitBySession: {}, historyHasMoreBySession: {cs_1: true}, historyOldestBySession: {cs_1: 30}},
    RENDER_STEP: 120, chatFollow: {release() {}}, setTimeout: fn => fn(),
    activeSession: () => ({id: 'cs_1'}), isActiveSession: () => true,
    hiddenRecordCount: () => 0, visibleRecords: () => [], renderHistoryKeepingAnchor: () => 0,
    messagesInner: {querySelector: sel => {
      const id = Number((sel.match(/"(\d+)"/) || [])[1]);
      return sandbox.loaded.includes(id) ? {scrollIntoView() { sandbox.scrolled = true; }, classList: {add() {}, remove() {}}} : null;
    }},
    loadChatHistory: (ses, before) => { sandbox.pages++; sandbox.older.push(before); sandbox.loaded.push(before - 10); ctx.state.historyOldestBySession.cs_1 = before - 10; return Promise.resolve(); },
  };
  vm.createContext(ctx);
  vm.runInContext(revealSrc + '\nthis.revealMessage = revealMessage;', ctx);
  assert.equal(await ctx.revealMessage(10), true, 'a hit two pages back is loaded and shown');
  assert.deepEqual(sandbox.older, [30, 20], 'older pages fetched one after another');
  assert.ok(sandbox.scrolled);
  ctx.state.historyHasMoreBySession.cs_1 = false;
  assert.equal(await ctx.revealMessage(1), false, 'a message that is not there ends the search');

  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // --- Without a chat: no menu --------------------------------------------------------------------
    let {context, page, srv, errors} = await open(browser, null);
    await page.waitForFunction(() => window.CoreAIChatMenu);
    assert.equal(await page.isVisible('#chatMenuBtn'), false, 'no menu on the empty start');
    await context.close();

    ({context, page, srv, errors} = await open(browser, Object.assign({}, CHAT)));
    await page.waitForSelector('#chatMenuBtn:not([hidden])');
    await page.click('#chatMenuBtn');
    await page.waitForSelector('#chatMenu:not([hidden])');
    assert.equal(await page.getAttribute('#chatMenuBtn', 'aria-expanded'), 'true');
    assert.equal(await page.textContent('#chatMenuTitle'), 'Wetter in Köln');
    assert.deepEqual(await page.$$eval('#chatMenu .chat-menu-item span', l => l.map(x => x.textContent)),
      ['Teilen', 'Anpinnen', 'Chat sperren', 'Zum Projekt hinzufügen', 'Hochgeladene Dateien', 'Im Chat suchen', 'Zum Startbildschirm hinzufügen', 'Archivieren', 'Löschen'],
      'the items of the reference in its order');
    assert.deepEqual(await page.$$eval('#chatMenu .is-planned', l => l.map(x => x.getAttribute('data-chat-action'))), ['lock', 'project']);
    assert.match(await page.textContent('[data-chat-action="lock"]'), /IN VORBEREITUNG/);
    assert.equal(await page.getAttribute('[data-chat-action="delete"]', 'class'), 'chat-menu-item danger');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-chat-action')), 'share', 'focus on the first item');
    await page.keyboard.press('ArrowDown');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-chat-action')), 'pin');
    await page.keyboard.press('End');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-chat-action')), 'delete');
    if (shots) await page.screenshot({path: path.join(shots, 'chat-menu.png')});
    await page.keyboard.press('Escape');
    await page.waitForSelector('#chatMenu', {state: 'hidden'});
    assert.equal(await page.evaluate(() => document.activeElement.id), 'chatMenuBtn', 'Escape gives the focus back');
    await page.click('#chatMenuBtn');
    await page.mouse.click(30, 600);
    await page.waitForSelector('#chatMenu', {state: 'hidden'});

    // Teilen: the chat as a Markdown file (no share sheet in this browser).
    const download = page.waitForEvent('download');
    await choose(page, 'share');
    const file = await download;
    assert.equal(file.suggestedFilename(), 'wetter-in-koeln.md');
    assert.equal(fs.readFileSync(await file.path(), 'utf8'), MD);
    assert.equal(await toast(page), 'Chat als Datei gespeichert: wetter-in-koeln.md');

    // Anpinnen, then the label changes.
    await choose(page, 'pin');
    assert.match(await toast(page), /Chat angepinnt/);
    assert.deepEqual(srv.st.posts.pop(), {action: 'pin', id: 'cs_test1234', pinned: true});
    await page.click('#chatMenuBtn');
    assert.equal(await page.textContent('#chatMenuPinLabel'), 'Nicht mehr anpinnen');
    await page.keyboard.press('Escape');

    // Planned items send nothing.
    const before = srv.st.posts.length + srv.st.gets.length;
    await choose(page, 'lock');
    assert.match(await toast(page), /Chat sperren ist in Vorbereitung/);
    await choose(page, 'project');
    assert.match(await toast(page), /Projekte sind in Vorbereitung/);
    assert.equal(srv.st.posts.length + srv.st.gets.length, before, 'planned items make no request');

    // Hochgeladene Dateien.
    await choose(page, 'files');
    await page.waitForSelector('#chatFilesList li');
    assert.equal(await page.textContent('#chatFilesList a'), 'plan.pdf');
    assert.equal(await page.getAttribute('#chatFilesList a', 'href'), './api/console_media.php?uuid=u-1');
    assert.match(await page.textContent('#chatFilesList small'), /^2 KB · /);
    assert.equal(await page.isVisible('#chatFilesEmpty'), false);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#chatFilesDialog', {state: 'hidden'});
    srv.st.files = [];
    await choose(page, 'files');
    await page.waitForSelector('#chatFilesEmpty:not([hidden])');
    assert.equal(await page.textContent('#chatFilesEmpty'), 'In diesem Chat hast du keine Dateien hochgeladen.');
    await page.click('#chatFilesDialog [data-chat-close]');

    // Im Chat suchen.
    await choose(page, 'search');
    await page.waitForSelector('#chatSearch:not([hidden])');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'chatSearchInput');
    await page.type('#chatSearchInput', 'K');
    await page.waitForFunction(() => document.getElementById('chatSearchCount').textContent === 'Mindestens 2 Zeichen');
    await page.type('#chatSearchInput', 'ö');
    await page.waitForFunction(() => document.getElementById('chatSearchCount').textContent === '2 Treffer');
    assert.deepEqual(srv.st.gets.filter(g => g.action === 'search').pop(), {action: 'search', id: 'cs_test1234', q: 'Kö'});
    assert.deepEqual(await page.$$eval('#chatSearchResults mark', l => l.map(x => x.textContent)), ['Kö', 'Kö']);
    assert.match(await page.textContent('#chatSearchResults li:nth-child(1) strong'), /^Du · /);
    assert.match(await page.textContent('#chatSearchResults li:nth-child(2) strong'), /^Mara · /);
    if (shots) await page.screenshot({path: path.join(shots, 'chat-search.png')});
    await page.click('#chatSearchResults li:nth-child(2) button');
    await page.waitForFunction(() => window.__calls.reveal.length === 1);
    assert.deepEqual(await page.evaluate(() => window.__calls.reveal), [12]);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#chatSearch', {state: 'hidden'});

    // Zum Startbildschirm hinzufügen.
    await choose(page, 'home');
    await page.waitForSelector('#chatHomeDialog:not([hidden])');
    assert.equal(new URL(page.url()).search, '?session=cs_test1234', 'the address leads to this chat');
    assert.equal(await page.inputValue('#chatHomeLink'), 'https://coreui.test/app.html?session=cs_test1234');
    assert.match(await page.textContent('#chatHomeDialog'), /Zum Startbildschirm hinzufügen/);
    await page.evaluate(() => { document.getElementById('chatMenuToast').hidden = true; });
    await page.click('#chatHomeCopy');
    assert.equal(await toast(page), 'Link kopiert.');
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'https://coreui.test/app.html?session=cs_test1234');
    await page.click('#chatHomeDialog [data-chat-close]');

    // Löschen: an error first (the chat stays), then deleted in one step.
    await choose(page, 'delete');
    await page.waitForSelector('#chatDeleteDialog:not([hidden])');
    assert.match(await page.textContent('#chatDeleteText'), /^„Wetter in Köln“ wird mit allen Nachrichten und hochgeladenen Dateien endgültig gelöscht\./);
    await page.click('#chatDeleteOk');
    await page.waitForFunction(() => document.getElementById('chatDeleteError').textContent !== '');
    assert.equal(await page.textContent('#chatDeleteError'), 'Der Chat hat noch eine laufende Browser-Recherche und lässt sich erst danach löschen.');
    assert.deepEqual(srv.st.posts.pop(), {action: 'delete_permanently', id: 'cs_test1234', confirmation: 'DELETE:cs_test1234', archive_first: true});
    await page.click('#chatDeleteDialog [data-chat-close]');

    // Archivieren: the dialog of the reference; cancel sends nothing.
    await choose(page, 'archive');
    await page.waitForSelector('#chatArchiveDialog:not([hidden])');
    assert.equal(await page.textContent('#chatArchiveTitle'), 'Chat archivieren');
    assert.equal((await page.textContent('#chatArchiveDialog p')).replace(/\s+/g, ' ').trim(),
      'Möchtest du diesen Chat wirklich archivieren?Du kannst archivierte Chats in den Einstellungen anzeigen.');
    assert.deepEqual(await page.$$eval('#chatArchiveDialog button', l => l.map(x => x.textContent)), ['Archivieren', 'Abbrechen']);
    if (shots) await page.screenshot({path: path.join(shots, 'chat-archive.png')});
    const posts = srv.st.posts.length;
    await page.click('#chatArchiveDialog [data-chat-close]');
    await page.waitForSelector('#chatArchiveDialog', {state: 'hidden'});
    assert.equal(srv.st.posts.length, posts, 'Abbrechen sends nothing');
    await choose(page, 'archive');
    await page.click('#chatArchiveOk');
    assert.match(await toast(page), /Chat archiviert/);
    assert.deepEqual(srv.st.posts.pop(), {action: 'archive', id: 'cs_test1234'});
    assert.deepEqual(await page.evaluate(() => window.__calls.removed), ['cs_test1234']);
    await page.waitForSelector('#chatMenuBtn', {state: 'hidden'});
    assert.equal(new URL(page.url()).search, '', 'the home screen address goes with its chat');
    assert.deepEqual(errors, []);
    await context.close();

    // A busy chat is not archived or deleted.
    ({context, page, srv, errors} = await open(browser, Object.assign({}, CHAT, {busy: true})));
    await page.waitForSelector('#chatMenuBtn:not([hidden])');
    await choose(page, 'archive');
    assert.equal(await toast(page), 'In diesem Chat arbeite ich noch. Bitte warte bis zum Abschluss.');
    assert.equal(await page.isVisible('#chatArchiveDialog'), false);
    await context.close();

    // --- Phone ---------------------------------------------------------------------------------------------
    ({context, page, srv, errors} = await open(browser, Object.assign({}, CHAT), {width: 390, height: 844}));
    await page.waitForSelector('#chatMenuBtn:not([hidden])');
    const btnBox = await page.locator('#chatMenuBtn').boundingBox();
    assert.ok(btnBox.x + btnBox.width <= 390 && btnBox.y < 90, 'the button sits in the header on a phone');
    await page.click('#chatMenuBtn');
    const box = await page.locator('#chatMenu').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 390 && box.y + box.height <= 844, 'the menu fits the phone');
    const tiny = await page.$$eval('#chatMenu *, .app-header *', els => els.filter(e => e.offsetParent !== null
      && Array.from(e.childNodes).some(n => n.nodeType === 3 && n.textContent.trim()) && parseFloat(getComputedStyle(e).fontSize) < 11)
      .map(e => e.textContent.trim()).filter(t => !/COREAI: TCI INTERFACE|LINK|NODE|LOCAL/.test(t)));
    assert.deepEqual(tiny, [], 'no menu text under 11px');
    if (shots) await page.screenshot({path: path.join(shots, 'chat-menu-phone.png')});
    await page.click('#chatMenu [data-chat-action="archive"]');
    const dbox = await page.locator('#chatArchiveDialog .chat-dialog-box').boundingBox();
    assert.ok(dbox.x >= 0 && dbox.x + dbox.width <= 390, 'the dialog fits the phone');
    if (shots) await page.screenshot({path: path.join(shots, 'chat-archive-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI chat menu: hidden without a chat, items like the reference, keyboard, Teilen as Markdown, Anpinnen, planned Chat sperren '
    + 'and Projekt, Hochgeladene Dateien, Im Chat suchen with jump (older pages loaded), Startbildschirm link, Archivieren and Löschen with '
    + 'dialogs, busy chat, phone OK');
})().catch(error => { console.error(error); process.exit(1); });
