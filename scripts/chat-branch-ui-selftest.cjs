// CoreAI 0.7.74 (P09 C): conversation branches in a real browser (Playwright).
//   node scripts/chat-branch-ui-selftest.cjs
// The real branchError, branchFromReply and placeBranchLine of js/console-app.js, cut out and
// run on a page with the chat's stylesheets. Checked: the button asks the server for a branch
// at exactly this reply, puts the new chat on top and opens it; errors become a short notice;
// the line after the branch point names the origin (open, archived, gone or locked without its
// title), offers "Ursprungschat öffnen" only for an open chat, sits after the reply even when
// the row is nested, is never doubled, wraps long titles on a phone and follows the dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');

const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const begin = app.indexOf("  // 0.7.74 (P09 C): branch the chat off at Mara's reply.");
const end = app.indexOf('  function startGenerationAction(record, mode, button) {');
assert(begin > 0 && end > begin, 'branch functions missing in console-app.js');
const block = app.slice(begin, end);

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>t</title><link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css"></head><body>
<div class="messages-inner" id="inner"></div>
<script>
  var messagesInner = document.getElementById('inner');
  var state = { assistantName: 'Mara', activeSessionId: 'cs_source', sessions: [{id: 'cs_source', title: 'Spiel bauen'}], branchBySession: {} };
  var calls = { post: [], select: [], notes: [], refresh: 0 };
  var nextPost = null;
  function activeSession() { return state.sessions.find(function (s) { return s.id === state.activeSessionId; }) || null; }
  function sessionById(id) { return state.sessions.find(function (s) { return String(s.id) === String(id); }) || null; }
  function selectSession(id) { calls.select.push(String(id)); state.activeSessionId = String(id); return Promise.resolve(); }
  function postConsoleAction(payload) { calls.post.push(payload); return nextPost(); }
  function appendMessageEl(role, text) { calls.notes.push([role, text]); }
  function scrollToBottom() {}
  window.CoreAIProjects = { refresh: function () { calls.refresh++; return Promise.resolve(); } };
${block}
  window.t = { branchFromReply: branchFromReply, placeBranchLine: placeBranchLine, state: state, calls: calls,
    setPost: function (fn) { nextPost = fn; } };
</script></body></html>`;

function rows(html) {
  return `<div class="msg-row user" data-message-id="11"><div class="msg-bubble">Frage</div></div>
<div class="msg-group"><div class="msg-row ember" data-message-id="12"><div class="msg-bubble">Antwort</div></div></div>
<div class="msg-row user" data-message-id="13"><div class="msg-bubble">Neue Frage im Zweig</div></div>${html || ''}`;
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const page = await browser.newPage({viewport: {width: 360, height: 780}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://localhost:8767/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
      const file = path.join(root, url.pathname);
      if (/^\/css\/[a-z-]+\.css$/.test(url.pathname) && fs.existsSync(file)) return route.fulfill({contentType: 'text/css', body: fs.readFileSync(file)});
      return route.fulfill({status: 404, body: ''});
    });
    await page.goto('http://localhost:8767/harness.html');

    // The button: one request for exactly this reply, the new chat on top and open.
    const opened = await page.evaluate(async () => {
      const button = document.createElement('button');
      t.setPost(() => Promise.resolve({ok: true, session: {id: 'cs_branch', title: 'Zweig: Spiel bauen'}, copied: 2, point_id: 12}));
      t.branchFromReply({id: 4, reply_to_id: 3}, button);
      const during = button.disabled;
      await new Promise(resolve => setTimeout(resolve, 20));
      return {during, after: button.disabled, post: t.calls.post, select: t.calls.select, top: t.state.sessions.map(s => s.id), refresh: t.calls.refresh};
    });
    assert.deepEqual(opened.post, [{action: 'branch', session_id: 'cs_source', message_id: 4}], 'one request for this reply');
    assert.deepEqual(opened.select, ['cs_branch'], 'the new chat opens');
    assert.deepEqual(opened.top, ['cs_branch', 'cs_source'], 'the new chat is on top, the original stays');
    assert(opened.during && !opened.after && opened.refresh === 1, 'the button waits for the server, the project list refreshes');

    // Errors become a short notice; a second click while waiting does nothing.
    const failed = await page.evaluate(async () => {
      t.state.activeSessionId = 'cs_source';
      const out = [];
      for (const code of ['session_busy', 'session_limit_reached', 'branch_needs_reply', 'nonsense']) {
        const button = document.createElement('button');
        t.setPost(() => Promise.reject(new Error(code)));
        t.branchFromReply({id: 5}, button);
        await new Promise(resolve => setTimeout(resolve, 10));
        out.push(t.calls.notes[t.calls.notes.length - 1]);
      }
      const busy = document.createElement('button');
      busy.disabled = true;
      const before = t.calls.post.length;
      t.branchFromReply({id: 5}, busy);
      return {out, again: t.calls.post.length - before};
    });
    assert.deepEqual(failed.out, [
      ['system', '⚠ Abzweigen geht, sobald die laufende Antwort fertig ist.'],
      ['system', '⚠ Es gibt schon 100 aktive Chats. Archiviere einen, dann geht das Abzweigen.'],
      ['system', '⚠ Abzweigen geht nur an einer Antwort von Mara.'],
      ['system', '⚠ Abzweigen fehlgeschlagen.'],
    ], 'error notices');
    assert.equal(failed.again, 0, 'no second request while the button waits');

    // The line after the branch point.
    const line = async (info, extra) => page.evaluate(([info, html]) => {
      const inner = document.getElementById('inner');
      inner.innerHTML = html;
      t.state.branchBySession.cs_branch = info;
      t.placeBranchLine({id: 'cs_branch'});
      t.placeBranchLine({id: 'cs_branch'});
      const lines = inner.querySelectorAll('.chat-branch-line');
      const el = lines[0];
      return {count: lines.length, text: el ? el.querySelector('.chat-branch-line-text').textContent : null,
        button: el && el.querySelector('button') ? el.querySelector('button').textContent : null,
        role: el ? el.getAttribute('role') : null,
        before: el && el.previousElementSibling ? el.previousElementSibling.className : null,
        after: el && el.nextElementSibling ? el.nextElementSibling.getAttribute('data-message-id') : null,
        width: el ? el.getBoundingClientRect().width : 0, scroll: document.documentElement.scrollWidth, view: window.innerWidth};
    }, [info, rows(extra)]);
    let r = await line({point_id: 12, source_state: 'active', source_id: 'cs_source', source_title: 'Spiel bauen'});
    assert.deepEqual([r.count, r.text, r.button, r.role, r.before, r.after],
      [1, 'Abgezweigt von „Spiel bauen“', 'Ursprungschat öffnen', 'note', 'msg-group', '13'], 'active origin: ' + JSON.stringify(r));
    await page.evaluate(() => { t.calls.select.length = 0; document.querySelector('.chat-branch-line-open').click(); });
    assert.deepEqual(await page.evaluate(() => t.calls.select), ['cs_source'], 'the button opens the original chat');
    r = await line({point_id: 12, source_state: 'active', source_id: 'cs_unknown', source_title: 'Spiel bauen'});
    assert.equal(r.button, null, 'no button for a chat that is not in the list');
    r = await line({point_id: 12, source_state: 'archived', source_id: null, source_title: 'Spiel bauen'});
    assert.deepEqual([r.text, r.button], ['Abgezweigt von „Spiel bauen“ (archiviert)', null], 'archived origin');
    r = await line({point_id: 12, source_state: 'gone', source_id: null, source_title: null});
    assert.deepEqual([r.text, r.button], ['Abgezweigt von einem Chat, der nicht mehr verfügbar ist', null], 'deleted or locked origin, no title');
    r = await line({point_id: 99, source_state: 'active', source_id: 'cs_source', source_title: 'x'});
    assert.equal(r.count, 0, 'no line while the branch point is not loaded');
    r = await line(null);
    assert.equal(r.count, 0, 'a chat that is no branch has no line');
    r = await line({point_id: 12, source_state: 'active', source_id: 'cs_source', source_title: 'Ein sehr langer Titel ohne Ende '.repeat(4) + 'Wort'.repeat(30)});
    assert(r.count === 1 && r.width <= r.view && r.scroll <= r.view, 'a long title wraps on a phone: ' + JSON.stringify(r));

    const colors = await page.evaluate(() => {
      const el = document.querySelector('.chat-branch-line');
      const light = getComputedStyle(el).borderTopStyle;
      document.documentElement.setAttribute('data-coreui-theme', 'dark');
      const dark = getComputedStyle(el).color;
      document.documentElement.removeAttribute('data-coreui-theme');
      return {light, dark, plain: getComputedStyle(el).color};
    });
    assert(colors.light === 'dashed' && colors.dark !== colors.plain, 'the line is dashed and follows the dark look: ' + JSON.stringify(colors));
    assert.deepEqual(errors, []);
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
  } finally {
    await browser.close();
  }
  console.log('CoreAI Gespraechszweige UI: Knopf, neuer Chat oben und offen, Hinweise, Herkunftszeile (offen, archiviert, weg), Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI chat branch UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
