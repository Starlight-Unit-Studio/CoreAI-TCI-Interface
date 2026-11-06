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
const styles = (head.match(/<link[^>]+stylesheet[^>]*>/g) || []).filter(link => !/fonts\.googleapis/.test(link)).join('\n');
assert(styles.includes('css/console.css') && styles.includes('css/coreui-theme.css'), 'the page stylesheets');

// The real list code of js/console-app.js (renderSessionList and the 0.6.19
// working spinner), run with stand-ins for the rest of the app.
const appJs = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const listBegin = appJs.indexOf('  function renderSessionList() {');
const listEnd = appJs.indexOf('  function netJson(url, opts) {');
assert(listBegin > 0 && listEnd > listBegin, 'renderSessionList missing in console-app.js');
const LIST = `(function () {
  var state = {showArchived: false, pendingBySession: {}, messageCacheBySession: {}, sessions: []};
  state.sessions.push({id: 'cs_active', title: 'Alle Leute kaufen ab Sudo', updated_at: '2026-11-05', message_count: 2, unread_count: 0});
  for (var i = 0; i < 9; i++) state.sessions.push({id: 'cs_' + i, title: 'Chat ' + i, updated_at: '2026-11-04', message_count: i,
    unread_count: 0, archived: i === 1, working: i === 2 || i === 1});
  var sessionListEl = document.getElementById('sessionList');
  var sessionCountEl = null, sessionArchiveToggle = null, headerSessionName = {textContent: ''};
  function isActiveSession(id) { return String(id) === 'cs_active'; }
  function sessionById(id) { return state.sessions.find(function (s) { return String(s.id) === String(id); }) || null; }
  function ok() { return Promise.resolve({ok: true}); }
  var dbRenameSession = ok, dbArchiveSession = ok, dbRestoreSession = ok, dbDeleteSession = ok, dbCreateSession = ok;
  function dbLoadSessions() { return Promise.resolve(); }
  function selectSession() {} function closeDrawer() {} function appendMessageEl() {} function clearSessionRuntime() {}
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
        + `<meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body>${app.slice(drawerStart, drawerEnd)}`
        + `<script src="/list.js"></script></body></html>`});
    }
    if (url.pathname === '/list.js') return route.fulfill({contentType: 'text/javascript', body: LIST});
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file) && fs.statSync(file).isFile()) {
      return route.fulfill({contentType: file.endsWith('.css') ? 'text/css' : 'application/octet-stream', body: fs.readFileSync(file)});
    }
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8768/drawer.html');
  await page.waitForTimeout(400); // the actions fade in over 160 ms
  const rows = await page.evaluate(() => [...document.querySelectorAll('#sessionList .session-item')].map(li => {
    const box = li.getBoundingClientRect();
    const shown = getComputedStyle(li.querySelector('.session-item-actions')).opacity === '1';
    return {
      title: li.querySelector('.session-item-title').textContent, shown, height: box.height, shrink: getComputedStyle(li).flexShrink,
      buttons: [...li.querySelectorAll('.session-action')].map(b => {
        const r = b.getBoundingClientRect();
        return {name: b.textContent, height: r.height, inside: r.top >= box.top - 0.5 && r.bottom <= box.bottom + 0.5};
      }),
    };
  }));
  const list = await page.evaluate(() => { const l = document.getElementById('sessionList'); return {scroll: l.scrollHeight, client: l.clientHeight}; });
  assert.ok(list.scroll > list.client, theme + ': the list is full enough to scroll (the case from the operator)');
  const min = touch ? 36 : 30;
  for (const row of rows) {
    assert.equal(row.shrink, '0', theme + ': a row is never squeezed: ' + row.title);
    if (!row.shown) continue;
    for (const button of row.buttons) {
      assert.ok(button.inside, `${theme}${touch ? ' touch' : ''}: ${button.name} of "${row.title}" lies fully inside its row`);
      assert.ok(button.height >= min - 0.5, `${theme}${touch ? ' touch' : ''}: ${button.name} is at least ${min} px high (${button.height})`);
    }
  }
  assert.ok(rows.find(r => r.title.startsWith('Alle Leute')).shown, 'the active chat shows its buttons');
  assert.ok(rows.filter(r => r.shown).length === 2, 'the active and the archived chat show their buttons, the others on hover');

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
  console.log('CoreAI chat list: rows never squeezed, buttons whole and at least 30 px (36 px by touch), night and day, spinner while Mara works OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
