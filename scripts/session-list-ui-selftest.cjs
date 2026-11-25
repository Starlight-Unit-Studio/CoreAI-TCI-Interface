// CoreAI 0.6.19: the chat list in the drawer keeps its buttons whole and easy to hit.
//   node scripts/session-list-ui-selftest.cjs
// The real drawer markup of app.html and the real stylesheets, with chat rows
// as js/console-app.js draws them, on a tablet-sized window where the list has
// to scroll. Reported by the operator: on the active chat, RENAME and ARCHIVE
// were cut in half and hard to hit, because the list (a flex column) squeezed
// its rows to 53 px and the row's clip-path cut the buttons. Checked in the
// night and the day theme, with mouse and with touch: every visible button lies
// fully inside its row, is at least 30 px high (36 px for fingers), and the
// rows are never squeezed; the list scrolls instead. The rows are drawn by the
// real renderSessionList(); a chat in which Mara works shows a spinner, from
// the server and at once for a turn sent in this tab.
// 0.7.21/0.7.24 (compact drawer, separate "Angeheftet" list, a "···" menu control per
// row): rows no longer show their buttons on their own. Each row has the ··· control,
// which opens exactly that row's actions; archived chats appear only in the archive
// view. The page uses the real <body> classes and sidebar control of app.html, because
// the drawer styles are scoped to .app-body since then.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');

const app = fs.readFileSync(path.join(root, 'app.html'), 'utf8');
const drawerStart = app.indexOf('<nav class="drawer"');
const drawerEnd = app.indexOf('</nav>', drawerStart) + '</nav>'.length;
assert(drawerStart > 0 && drawerEnd > drawerStart, 'drawer missing in app.html');
const head = app.slice(app.indexOf('<head>'), app.indexOf('</head>'));
const bodyTag = (app.match(/<body\b[^>]*>/) || [])[0];
const sidebarControl = (app.match(/<input\s+class="sidebar-control"[\s\S]*?\/>/) || [])[0];
assert(bodyTag && /class="app-body/.test(bodyTag) && sidebarControl && /\bchecked\b/.test(sidebarControl),
  'the real body classes and the open sidebar control of app.html');
const styles = (head.match(/<link[^>]+stylesheet[^>]*>/g) || []).filter(link => !/fonts\.googleapis/.test(link)).join('\n');
assert(styles.includes('css/console.css') && styles.includes('css/coreui-theme.css'), 'the page stylesheets');

// The real list code of js/console-app.js (syncPinnedSection, renderSessionList and
// the 0.6.19 working spinner), run with stand-ins for the rest of the app.
const appJs = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const listBegin = appJs.indexOf('  function syncPinnedSection() {');
assert(listBegin > 0 && appJs.indexOf('  function renderSessionList() {') > listBegin, 'syncPinnedSection precedes renderSessionList');
const listEnd = appJs.indexOf('  function netJson(url, opts) {');
assert(listBegin > 0 && listEnd > listBegin, 'renderSessionList missing in console-app.js');
const LIST = `(function () {
  var state = {showArchived: false, pendingBySession: {}, messageCacheBySession: {}, sessions: []};
  state.sessions.push({id: 'cs_active', title: 'Alle Leute kaufen ab Sudo', updated_at: '2026-11-05', message_count: 2, unread_count: 0});
  for (var i = 0; i < 9; i++) state.sessions.push({id: 'cs_' + i, title: 'Chat ' + i, updated_at: '2026-11-04', message_count: i,
    unread_count: 0, archived: i === 1, working: i === 2 || i === 1});
  var sessionListEl = document.getElementById('sessionList');
  var sessionCountEl = null, sessionArchiveToggle = null, headerSessionName = {textContent: ''};
  var pinnedZone = document.getElementById('pinnedZone'), pinnedCountEl = document.getElementById('pinnedCount');
  var pinnedSessionListEl = document.getElementById('pinnedSessionList');
  var pinnedProjectListEl = document.getElementById('sidebarPinnedProjects');
  var pinnedEmptyEl = document.getElementById('sidebarPinnedEmpty');
  var sessionZoneLabel = document.getElementById('sessionZoneLabel');
  function isActiveSession(id) { return String(id) === 'cs_active'; }
  function sessionById(id) { return state.sessions.find(function (s) { return String(s.id) === String(id); }) || null; }
  function ok() { return Promise.resolve({ok: true}); }
  var dbSessionAction = ok, dbRenameSession = ok, dbArchiveSession = ok, dbRestoreSession = ok, dbDeleteSession = ok, dbCreateSession = ok;
  function dbLoadSessions() { return Promise.resolve(); }
  function selectSession() {} function closeDrawer() {} function appendMessageEl() {} function clearSessionRuntime() {} function showDraftChat() {}
` + appJs.slice(listBegin, listEnd) + `
  window.__list = {state: state, render: renderSessionList};
  renderSessionList();
})();`;
async function check(browser, theme, touch) {
  const page = await browser.newPage({viewport: {width: 1075, height: 700}, hasTouch: touch, isMobile: touch});
  await page.route('http://localhost:8768/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/drawer.html') {
      return route.fulfill({contentType: 'text/html', body: `<!doctype html><html lang="de" data-coreui-theme="${theme}"><head><meta charset="utf-8">`
        + `<meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head>${bodyTag}${sidebarControl}${app.slice(drawerStart, drawerEnd)}`
        + `<script src="/list.js"></script></body></html>`});
    }
    if (url.pathname === '/list.js') return route.fulfill({contentType: 'text/javascript', body: LIST});
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file) && fs.statSync(file).isFile()) {
      return route.fulfill({contentType: file.endsWith('.css') ? 'text/css' : 'application/octet-stream', body: fs.readFileSync(file)});
    }
    return route.fulfill({status: 404, body: ''});
  });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('http://localhost:8768/drawer.html');
  await page.waitForTimeout(400); // the actions fade in over 160 ms
  const readRows = () => page.evaluate(() => [...document.querySelectorAll('#sessionList .session-item')].map(li => {
    const box = li.getBoundingClientRect();
    const actions = getComputedStyle(li.querySelector('.session-item-actions'));
    const shown = actions.display !== 'none' && actions.visibility !== 'hidden' && actions.opacity === '1';
    const inside = r => r.top >= box.top - 0.5 && r.bottom <= box.bottom + 0.5 && r.left >= box.left - 0.5 && r.right <= box.right + 0.5;
    const more = li.querySelector('.session-row-more');
    const moreBox = more ? more.getBoundingClientRect() : null;
    return {
      id: li.getAttribute('data-session-id'),
      title: li.querySelector('.session-item-title').textContent, shown, height: box.height, shrink: getComputedStyle(li).flexShrink,
      more: more ? {height: moreBox.height, inside: inside(moreBox), expanded: more.getAttribute('aria-expanded')} : null,
      buttons: [...li.querySelectorAll('.session-action')].map(b => {
        const r = b.getBoundingClientRect();
        return {name: b.textContent, height: r.height, inside: inside(r)};
      }),
    };
  }));
  const rows = await readRows();
  const list = await page.evaluate(() => { const l = document.getElementById('sessionList'); return {scroll: l.scrollHeight, client: l.clientHeight}; });
  assert.ok(list.scroll > list.client, theme + ': the list is full enough to scroll (the case from the operator)');
  const min = touch ? 36 : 30;
  const where = theme + (touch ? ' touch' : '');
  const wholeButtons = row => {
    for (const button of row.buttons) {
      assert.ok(button.inside, `${where}: ${button.name} of "${row.title}" lies fully inside its row`);
      assert.ok(button.height >= min - 0.5, `${where}: ${button.name} is at least ${min} px high (${button.height})`);
    }
  };
  assert.deepEqual(rows.map(r => r.id), ['cs_active', 'cs_0', 'cs_2', 'cs_3', 'cs_4', 'cs_5', 'cs_6', 'cs_7', 'cs_8'],
    where + ': the recent list holds the open chats, archived chats only in the archive view');
  for (const row of rows) {
    assert.equal(row.shrink, '0', theme + ': a row is never squeezed: ' + row.title);
    assert.ok(row.more, `${where}: "${row.title}" has its ··· control`);
    assert.ok(row.more.inside, `${where}: the ··· control of "${row.title}" lies fully inside its row`);
    assert.ok(row.more.height >= min - 0.5, `${where}: the ··· control is at least ${min} px high (${row.more.height})`);
    assert.equal(row.more.expanded, 'false');
  }
  assert.deepEqual(rows.filter(r => r.shown).map(r => r.id), [], where + ': no row shows its buttons before its ··· is opened');

  // The ··· control opens exactly this row's actions, whole and easy to hit; another row closes it again.
  await page.click('#sessionList [data-session-id="cs_active"] .session-row-more');
  let open = await readRows();
  assert.deepEqual(open.filter(r => r.shown).map(r => r.id), ['cs_active'], where + ': the active chat shows its buttons after ···');
  const active = open.find(r => r.id === 'cs_active');
  assert.equal(active.more.expanded, 'true');
  assert.deepEqual(active.buttons.map(b => b.name), ['ANPINNEN', 'UMBENENNEN', 'ARCHIVIEREN', 'LÖSCHEN']);
  wholeButtons(active);
  await page.click('#sessionList [data-session-id="cs_4"] .session-row-more');
  open = await readRows();
  assert.deepEqual(open.filter(r => r.shown).map(r => r.id), ['cs_4'], where + ': opening another row closes the first');
  wholeButtons(open.find(r => r.id === 'cs_4'));
  await page.click('#sessionList [data-session-id="cs_4"] .session-row-more');
  assert.deepEqual((await readRows()).filter(r => r.shown).map(r => r.id), [], where + ': ··· closes its row again');

  // The archived chat (Mara still marked as working there) in the archive view: its own buttons, never a spinner.
  await page.evaluate(() => { window.__list.state.showArchived = true; window.__list.render(); });
  await page.click('#sessionList [data-session-id="cs_1"] .session-row-more');
  const archived = await readRows();
  assert.deepEqual(archived.map(r => r.id), ['cs_1'], where + ': the archive view lists the archived chat');
  assert.deepEqual(archived[0].buttons.map(b => b.name), ['WIEDERHERSTELLEN', 'ENDGÜLTIG LÖSCHEN']);
  assert.ok(archived[0].shown, where + ': the archived chat shows its buttons after ···');
  wholeButtons(archived[0]);
  assert.equal(await page.$$eval('#sessionList .session-working', l => l.length), 0, 'an archived chat never spins');
  await page.evaluate(() => { window.__list.state.showArchived = false; window.__list.render(); });

  // 0.6.19: a spinner while Mara works: from the server (another tab, a reload) and at once for a turn sent here.
  const spinners = () => page.evaluate(() => [...document.querySelectorAll('#sessionList .session-item')]
    .filter(li => li.querySelector('.session-item-title .session-working')).map(li => li.getAttribute('data-session-id')));
  assert.deepEqual(await spinners(), ['cs_2'], 'the server says Mara works in Chat 2 (an archived chat never spins)');
  const label = await page.getAttribute('#sessionList [data-session-id="cs_2"] .session-working', 'aria-label');
  assert.equal(label, 'Mara arbeitet in diesem Chat');
  assert.equal(await page.evaluate(() => document.querySelector('[data-session-id="cs_2"] .session-item-title').textContent), 'Chat 2',
    'the title text stays as it is');
  await page.evaluate(() => { window.__list.state.pendingBySession.cs_active = 7; });
  await page.waitForFunction(() => document.querySelector('[data-session-id="cs_active"] .session-working'), null, {timeout: 3000});
  await page.evaluate(() => { delete window.__list.state.pendingBySession.cs_active; window.__list.state.sessions[3].working = false; });
  await page.waitForFunction(() => !document.querySelector('#sessionList .session-working'), null, {timeout: 3000});
  const spin = await page.evaluate(() => { window.__list.state.sessions[3].working = true; window.__list.render();
    const el = document.querySelector('.session-working'); return el ? getComputedStyle(el).animationName : ''; });
  assert.equal(spin, 'session-working-spin', 'the ring turns');
  assert.deepEqual(errors, [], where + ': no page errors');
  await page.close();
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    for (const theme of ['dark', 'light']) {
      await check(browser, theme, false);
      await check(browser, theme, true);
    }
  } finally {
    await browser.close();
  }
  console.log('CoreAI chat list: rows never squeezed, ··· control and its buttons whole and at least 30 px (36 px by touch), one row open at a time, '
    + 'archive view, night and day, spinner while Mara works OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
