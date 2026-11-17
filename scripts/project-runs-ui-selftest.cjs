// CoreAI 0.7.77 (P10 B): commands in the tab Dateien, in a real browser (Playwright).
//   node scripts/project-runs-ui-selftest.cjs
// The real js/coreui-project-files.js and styles with a stand-in for api/project_files.php whose
// runs move through queued, running and done like the worker. Checked: "Ausführen" with the
// suggested command, waiting, running, the exit code with the real stderr, a second run with its
// stdout, the list of the latest runs, cancelling a long run, a refused second run, the Python
// switch, a phone without sideways scrolling, the dark look and closing.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const PROJECT = 'c'.repeat(32);
const ORIGIN = 'http://coreui.test';

const files = {
  'game.py': 'def add(a, b):\n    return a - b\n',
  'tests/test_game.py': 'import unittest\nfrom game import add\n',
};
const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const pub = p => ({path: p, size_bytes: Buffer.byteLength(files[p]), sha256: sha(files[p]), revision: 1, updated_at: '2026-11-17 09:00:00'});
const st = {runs: [], nextId: 1, allowed: true, posts: [], polls: {}};

function publicRun(r, output) {
  const out = {id: r.id, command: r.command, status: r.status, exit_code: r.exit_code, error: r.error, duration_ms: r.duration_ms,
    files_count: r.files_count, from_chat: false, created_at: '2026-11-17 09:00:00', finished_at: null, cancel_requested: !!r.cancel};
  if (output) { out.stdout = r.stdout || ''; out.stderr = r.stderr || ''; }
  return out;
}
// Each poll moves a run one step: queued, running, then its result.
function advance(r) {
  st.polls[r.id] = (st.polls[r.id] || 0) + 1;
  const n = st.polls[r.id];
  if (r.status === 'queued' && n >= 1) { r.status = 'running'; return; }
  if (r.status !== 'running') return;
  if (r.cancel) { Object.assign(r, {status: 'cancelled', error: 'cancelled', duration_ms: 1500}); return; }
  if (r.command.startsWith('sleep')) return;
  if (n < 3) return;
  if (r.command.includes('unittest')) {
    Object.assign(r, {status: 'done', exit_code: 1, duration_ms: 412, files_count: 2, stdout: '',
      stderr: 'test_add (test_game.T.test_add) ... FAIL\n\nAssertionError: -1 != 5\n\nFAILED (failures=1)\n'});
  } else {
    Object.assign(r, {status: 'done', exit_code: 0, duration_ms: 95, files_count: 2, stdout: '1\n', stderr: ''});
  }
}

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css">
<link rel="stylesheet" href="/css/coreui-projects.css"><link rel="stylesheet" href="/css/coreui-project-files.css">
<link rel="stylesheet" href="/css/coreui-project-theme.css"></head>
<body class="app-body project-home-active"><section class="project-home" id="coreuiProjectHome"><div class="project-home-content">
<section class="project-file-results" id="projectFilesPanel" aria-label="Projektdateien"></section>
</div></section><script src="/js/coreui-project-files.js"></script>
<script>window.CoreAIProjectFiles.open(${JSON.stringify(PROJECT)});</script></body></html>`;

const json = (r, status, body) => r.fulfill({status, contentType: 'application/json', body: JSON.stringify(body)});
async function route(r) {
  const req = r.request();
  const u = new URL(req.url());
  if (u.origin !== ORIGIN) return r.abort();
  if (u.pathname === '/harness.html') return r.fulfill({contentType: 'text/html', body: harness});
  if (u.pathname === '/api/project_files.php') {
    if (req.method() === 'POST') {
      const body = JSON.parse(req.postData() || '{}');
      st.posts.push(body);
      if (body.action === 'run') {
        if (!st.allowed) return json(r, 403, {ok: false, error: 'python_disabled'});
        if (st.runs.some(x => x.status === 'queued' || x.status === 'running')) return json(r, 409, {ok: false, error: 'run_busy'});
        const run = {id: st.nextId++, command: body.command, status: 'queued', exit_code: null, error: null, duration_ms: null, files_count: null};
        st.runs.unshift(run);
        return json(r, 202, {ok: true, run: publicRun(run, true)});
      }
      if (body.action === 'run_cancel') {
        const run = st.runs.find(x => x.id === body.run_id);
        if (run.status === 'queued') Object.assign(run, {status: 'cancelled', error: 'cancelled'}); else run.cancel = true;
        return json(r, 200, {ok: true, run: publicRun(run, true)});
      }
      return json(r, 400, {ok: false, error: 'unknown_action'});
    }
    if (u.searchParams.has('runs')) {
      return json(r, 200, {ok: true, ready: true, allowed: st.allowed, runs: st.runs.map(x => publicRun(x, false)),
        suggestion: 'python -m unittest discover -v -s tests', csrf_token: 'csrf'});
    }
    if (u.searchParams.has('run')) {
      const run = st.runs.find(x => x.id === Number(u.searchParams.get('run')));
      if (!run) return json(r, 404, {ok: false, error: 'run_not_found'});
      advance(run);
      return json(r, 200, {ok: true, run: publicRun(run, true)});
    }
    const list = Object.keys(files).sort().map(pub);
    return json(r, 200, {ok: true, files: list, count: list.length, bytes: 100,
      limits: {file_bytes: 524288, files: 200, project_bytes: 10485760, revisions: 20}, csrf_token: 'csrf'});
  }
  const file = path.join(root, u.pathname);
  if (/^\/(css|js)\/[a-z0-9-]+\.(css|js)$/.test(u.pathname) && fs.existsSync(file)) {
    return r.fulfill({contentType: u.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
  }
  return r.fulfill({status: 404, body: ''});
}

const statusText = page => page.$eval('.project-run-status', el => el.textContent);
const waitStatus = (page, pattern, timeout) => page.waitForFunction(re => {
  const line = document.querySelector('.project-run-status');
  return !!line && new RegExp(re).test(line.textContent);
}, pattern, {timeout: timeout || 15000});

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({viewport: {width: 1100, height: 900}});
    await context.route(ORIGIN + '/**', route);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(ORIGIN + '/harness.html');
    await page.waitForSelector('#projectFilesRun');
    assert.equal(await page.isHidden('.project-run'), true, 'no run panel before it is asked for');

    // Open: the suggested command, nothing run yet.
    await page.click('#projectFilesRun');
    await page.waitForFunction(() => document.querySelector('.project-run-command')
      && document.querySelector('.project-run-command').value === 'python -m unittest discover -v -s tests');
    assert.equal(await page.$('.project-run-status'), null, 'no run yet');

    // A failing test: waiting, running, then exit code 1 with the real stderr.
    await page.click('#projectRunStart');
    await waitStatus(page, 'Läuft …|Wartet auf den Sandbox-Dienst …');
    assert.ok(await page.$('#projectRunCancel'), 'a running command can be cancelled');
    assert.equal(await page.$eval('#projectRunStart', b => b.disabled), true, 'no second start while one runs');
    await waitStatus(page, 'Exitcode 1');
    assert.equal(await statusText(page), 'python -m unittest discover -v -s tests · Exitcode 1 · 0,4 s · 2 Dateien');
    assert.ok(await page.$eval('.project-run-status', el => el.classList.contains('is-fail')), 'a red line for a failure');
    const stderr = await page.$eval('.project-run-block.is-stderr .project-run-output', el => el.textContent);
    assert.ok(stderr.includes('AssertionError: -1 != 5') && stderr.includes('FAILED (failures=1)'), 'the real stderr');
    assert.equal(await page.$('.project-run-block.is-stdout'), null, 'no empty stdout block');
    assert.deepEqual(st.posts[0], {action: 'run', command: 'python -m unittest discover -v -s tests', project_id: PROJECT});

    // A second command with stdout; the list of the latest runs.
    await page.fill('.project-run-command', "python -c 'print(1)'");
    await page.press('.project-run-command', 'Enter');
    await waitStatus(page, 'Exitcode 0');
    assert.ok(await page.$eval('.project-run-status', el => el.classList.contains('is-ok')), 'a green line for success');
    assert.equal(await page.$eval('.project-run-block.is-stdout .project-run-output', el => el.textContent), '1\n');
    await page.waitForFunction(() => document.querySelectorAll('.project-run-item').length === 2);
    await page.click('.project-run-item[data-run="1"]');
    await waitStatus(page, 'Exitcode 1');
    assert.ok(await page.$eval('.project-run-item[data-run="1"]', el => el.classList.contains('is-current')), 'the chosen run is marked');

    // Cancelling a long run.
    await page.fill('.project-run-command', 'sleep 60');
    await page.click('#projectRunStart');
    await page.waitForSelector('#projectRunCancel');
    await page.click('#projectRunCancel');
    await waitStatus(page, 'Abgebrochen');
    assert.ok(st.posts.some(p => p.action === 'run_cancel' && p.run_id === 3), 'cancel sent for the long run');

    // A refused run while another runs (the server says so).
    st.runs.unshift({id: st.nextId++, command: 'sleep 99', status: 'running', exit_code: null, error: null, duration_ms: null, files_count: null});
    st.polls[st.runs[0].id] = -1000;
    await page.fill('.project-run-command', 'echo x');
    await page.click('#projectRunStart');
    await page.waitForFunction(() => [...document.querySelectorAll('.project-run .project-files-status.is-error')]
      .some(p => p.textContent === 'In diesem Projekt läuft schon ein Befehl.'));

    // Phone and dark look.
    await page.setViewportSize({width: 360, height: 780});
    const phone = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert.ok(phone.scroll <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await page.click('.project-run-item[data-run="1"]');
    await waitStatus(page, 'Exitcode 1');
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await page.$eval('.project-run-output', el => ({bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color}));
    assert.notEqual(dark.bg, dark.color, 'readable output in the dark look');

    // Closing; the Python switch.
    await page.click('#projectRunClose');
    assert.equal(await page.isHidden('.project-run'), true, 'closed');
    st.allowed = false;
    st.runs.forEach(r => { if (r.status === 'running') Object.assign(r, {status: 'done', exit_code: 0, duration_ms: 5}); });
    await page.click('#projectFilesRun');
    await page.waitForFunction(() => [...document.querySelectorAll('.project-run .project-files-status.is-error')]
      .some(p => p.textContent.startsWith('Ausführen gehört zum Python-Werkzeug')));
    assert.equal(await page.$eval('#projectRunStart', b => b.disabled), true, 'no start without Python');
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
  console.log('CoreAI Projektlaeufe UI: Vorschlag, Warten, Laufen, Exitcode mit echter Ausgabe, zweiter Lauf, Liste, Abbrechen, abgelehnter Lauf, Python-Schalter, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI project runs UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
