// CoreAI 0.7.78 (P10 C): processes in the tab Dateien and the preview of a development server,
// in a real browser (Playwright).
//   node scripts/project-procs-ui-selftest.cjs
// The real js/coreui-project-files.js and styles with a stand-in for api/project_files.php: a
// process moves through queued and running, its output grows line by line, input comes back as
// output, Beenden gives exit code 143. Every preview page is built by the real PHP code
// (coreui_pprocs_page in api/project_procs_store.php) from a stand-in server and sent with the real
// sandbox CSP. Checked: the suggested command and port, start, output without repeats, input,
// the preview with the server's stylesheet and script, fetch() through the parent frame, a link
// and a GET form with server paths, an error of the page, Beenden with its exit code, the list,
// the Python switch, a phone without sideways scrolling and the dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const PROJECT = 'd'.repeat(32);
const ORIGIN = 'http://coreui.test';

function php(code, input) {
  return execFileSync('php', ['-r', code], {input: input || '', encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
}
const store = JSON.stringify(path.join(root, 'api/project_procs_store.php'));
const CSP = php('require ' + store + '; echo COREUI_PREVIEW_CSP;');
const html = (title, body) => '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' + title + '</title></head><body>' + body + '</body></html>';
const SERVER = {
  '/': {status: 200, type: 'text/html; charset=utf-8', path: '/', body: '<!doctype html><html lang="de"><head><meta charset="utf-8">'
    + '<link rel="stylesheet" href="style.css"><title>Start</title></head><body><h1 id="t">Startseite</h1><p id="zahl">wartet</p>'
    + '<a id="about" href="about">Über</a> <a id="fail" href="kaputt">Kaputt</a>'
    + '<form action="suche"><input id="q" name="q"><button id="go">Suchen</button></form><script src="app.js"></script></body></html>'},
  '/style.css': {status: 200, type: 'text/css', path: '/style.css', body: 'h1 { color: rgb(200, 10, 10); }'},
  '/app.js': {status: 200, type: 'text/javascript', path: '/app.js',
    body: "fetch('api/zahl').then(function (r) { return r.json(); }).then(function (d) { document.getElementById('zahl').textContent = 'Zahl ' + d.zahl; });"},
  '/api/zahl': {status: 200, type: 'application/json', path: '/api/zahl', body: '{"zahl": 42}'},
  '/about': {status: 200, type: 'text/html', path: '/about', body: html('Über', '<h1 id="t">Über uns</h1>')},
  '/suche?q=abc': {status: 200, type: 'text/html', path: '/suche?q=abc', body: html('Suche', '<h1 id="t">Suche: abc</h1>')},
  '/kaputt': {status: 200, type: 'text/html', path: '/kaputt', body: html('Kaputt', '<h1 id="t">Kaputt</h1><script>\nnichtDa();\n</script>')},
};
function page(serverPath, key) {
  return php('require ' + store + '; $f = json_decode(stream_get_contents(STDIN), true);'
    + '$fetch = static function (string $p) use ($f): array { if (!isset($f["map"][$p])) throw new RuntimeException("server_unreachable"); return $f["map"][$p]; };'
    + 'echo coreui_pprocs_page($fetch, $f["path"], "./project_files.php?project_id=' + PROJECT + '&proc_preview=1&path=", null, coreui_pprocs_key($f["key"]));',
  JSON.stringify({map: SERVER, path: serverPath, key: key || ''}));
}

const files = {'index.html': SERVER['/'].body, 'style.css': SERVER['/style.css'].body};
const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const pub = p => ({path: p, size_bytes: Buffer.byteLength(files[p]), sha256: sha(files[p]), revision: 1, updated_at: '2026-11-17 09:00:00'});
const st = {procs: [], nextId: 1, allowed: true, posts: [], polls: {}, sinces: []};

function publicProc(p, since) {
  const out = {id: p.id, command: p.command, port: p.port, status: p.status, exit_code: p.exit_code, error: p.error, files_count: 2,
    created_at: '2026-11-17 09:00:00', started_at: null, finished_at: null, stop_requested: !!p.stop, output_end: p.output.length};
  if (since !== undefined) {
    const from = Math.min(Math.max(0, since), p.output.length);
    out.output = p.output.slice(from); out.output_from = from; out.output_cut = false;
  }
  return out;
}
// Each poll moves a process: queued, running with a first line, then one log line per poll.
function advance(p) {
  st.polls[p.id] = (st.polls[p.id] || 0) + 1;
  if (p.status === 'queued') { p.status = 'running'; p.output += 'Server bereit auf Port 8000\n'; return; }
  if (p.status !== 'running') return;
  if (p.stop) { Object.assign(p, {status: 'stopped', exit_code: 143}); p.output += 'Beendet.\n'; return; }
  p.inputs.splice(0).forEach(text => { p.output += 'Echo: ' + text; });
  if (st.polls[p.id] <= 4) p.output += 'Anfrage ' + st.polls[p.id] + '\n';
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
      const p = st.procs.find(x => x.id === body.proc_id);
      if (body.action === 'proc_start') {
        if (!st.allowed) return json(r, 403, {ok: false, error: 'python_disabled'});
        const proc = {id: st.nextId++, command: body.command, port: body.port === null ? null : Number(body.port), status: 'queued',
          exit_code: null, error: null, output: '', inputs: [], stop: false};
        st.procs.unshift(proc);
        return json(r, 202, {ok: true, proc: publicProc(proc, 0)});
      }
      if (body.action === 'proc_input') { p.inputs.push(body.text + (body.newline ? '\n' : '')); return json(r, 200, {ok: true, proc: publicProc(p)}); }
      if (body.action === 'proc_stop') { p.stop = true; return json(r, 200, {ok: true, proc: publicProc(p)}); }
      if (body.action === 'proc_fetch') {
        const answer = SERVER[body.path];
        if (!answer) return json(r, 502, {ok: false, error: 'server_unreachable'});
        return json(r, 200, {ok: true, response: Object.assign({}, answer, {base64: false, truncated: false})});
      }
      return json(r, 400, {ok: false, error: 'unknown_action'});
    }
    if (u.searchParams.has('procs')) {
      return json(r, 200, {ok: true, ready: true, allowed: st.allowed, procs: st.procs.map(x => publicProc(x)),
        suggestion: {command: 'python -m http.server 8000', port: 8000}, csrf_token: 'csrf'});
    }
    if (u.searchParams.has('proc')) {
      const p = st.procs.find(x => x.id === Number(u.searchParams.get('proc')));
      if (!p) return json(r, 404, {ok: false, error: 'proc_not_found'});
      st.sinces.push(Number(u.searchParams.get('since')));
      advance(p);
      return json(r, 200, {ok: true, proc: publicProc(p, Number(u.searchParams.get('since') || 0))});
    }
    if (u.searchParams.has('proc_preview')) {
      return r.fulfill({contentType: 'text/html; charset=utf-8', headers: {'Content-Security-Policy': CSP}, body: page(u.searchParams.get('path'), u.searchParams.get('k'))});
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

const statusText = pg => pg.$eval('.project-proc-status p', el => el.textContent);
const waitStatus = (pg, pattern) => pg.waitForFunction(re => {
  const line = document.querySelector('.project-proc-status p');
  return !!line && new RegExp(re).test(line.textContent);
}, pattern, {timeout: 15000});
const output = pg => pg.$eval('.project-proc-output', el => el.textContent);

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({viewport: {width: 1100, height: 900}});
    await context.route(ORIGIN + '/**', route);
    const pg = await context.newPage();
    const errors = [];
    pg.on('pageerror', e => { if (pg.mainFrame() === pg.mainFrame()) errors.push(e.message); });
    await pg.goto(ORIGIN + '/harness.html');
    await pg.waitForSelector('#projectFilesProcs');
    assert.equal(await pg.isHidden('.project-proc'), true, 'no process panel before it is asked for');

    // The suggestion: a web server on port 8000.
    await pg.click('#projectFilesProcs');
    await pg.waitForFunction(() => document.querySelector('.project-proc-command')
      && document.querySelector('.project-proc-command').value === 'python -m http.server 8000');
    assert.equal(await pg.$eval('.project-proc-port', el => el.value), '8000');

    // Start: waiting, running, the output grows line by line without repeats.
    await pg.click('#projectProcStart');
    await waitStatus(pg, 'Läuft · Port 8000');
    assert.deepEqual(st.posts[0], {action: 'proc_start', command: 'python -m http.server 8000', port: '8000', project_id: PROJECT});
    await pg.waitForFunction(() => document.querySelector('.project-proc-output').textContent.includes('Anfrage 4'));
    const text = await output(pg);
    assert.equal(text, 'Server bereit auf Port 8000\nAnfrage 2\nAnfrage 3\nAnfrage 4\n', 'each line once, in order: ' + JSON.stringify(text));
    assert.ok(st.sinces.some(s => s > 0), 'only what is new is asked for');
    assert.equal(await pg.$('.project-proc-command'), null, 'no second start while it runs');

    // Input reaches the program and comes back.
    await pg.fill('.project-proc-input', 'hallo Mara');
    await pg.press('.project-proc-input', 'Enter');
    await pg.waitForFunction(() => document.querySelector('.project-proc-output').textContent.includes('Echo: hallo Mara\n'));
    assert.ok(st.posts.some(p => p.action === 'proc_input' && p.text === 'hallo Mara' && p.newline === true), 'input sent with Enter');
    assert.equal(await pg.$eval('.project-proc-input', el => el.value), '', 'the input line is cleared');

    // The preview of the development server: stylesheet, script and fetch() from the server.
    await pg.click('#projectProcPreview');
    const frame = pg.frameLocator('.project-preview-frame');
    await frame.locator('#zahl').filter({hasText: 'Zahl 42'}).waitFor();
    const head = await pg.evaluate(() => ({
      small: document.querySelector('.project-preview .project-preview-title small').textContent,
      src: document.querySelector('.project-preview-frame').getAttribute('src'),
      sandbox: document.querySelector('.project-preview-frame').getAttribute('sandbox'),
    }));
    assert.equal(head.small, '/ · Entwicklungsserver auf Port 8000');
    assert.match(head.src, new RegExp('^\\./api/project_files\\.php\\?project_id=' + PROJECT + '&proc_preview=1&k=[0-9a-f]{36}&path=%2F&v=\\d+$'));
    assert.equal(head.sandbox, 'allow-scripts allow-forms allow-modals allow-popups');
    const inside = await pg.frame({url: /proc_preview=1&k=[0-9a-f]+&path=%2F&/}).evaluate(() => ({
      color: getComputedStyle(document.getElementById('t')).color, origin: String(window.origin), cookie: (() => { try { return document.cookie; } catch (e) { return 'blocked'; } })(),
    }));
    assert.equal(inside.color, 'rgb(200, 10, 10)', 'the server\'s stylesheet');
    assert.equal(inside.origin, 'null', 'the page runs in an origin of its own');
    assert.ok(st.posts.some(p => p.action === 'proc_fetch' && p.path === '/api/zahl' && p.method === 'GET' && p.proc_id === 1), 'fetch went through the parent');
    // A page without the preview's key (as a foreign page in the same frame would be) gets nothing.
    const before = st.posts.filter(p => p.action === 'proc_fetch').length;
    await pg.frame({url: /proc_preview=1&k=/}).evaluate(() => {
      parent.postMessage({coreaiPreview: 'fetch', id: 'fremd', path: '/api/zahl', method: 'GET', body: null, type: ''}, '*');
      parent.postMessage({coreaiPreview: 'open', path: '/about'}, '*');
    });
    await pg.waitForTimeout(600);
    assert.equal(st.posts.filter(p => p.action === 'proc_fetch').length, before, 'no request without the key');
    assert.equal(await pg.$eval('.project-preview .project-preview-title small', el => el.textContent), '/ · Entwicklungsserver auf Port 8000',
      'no page change without the key');

    // A link and a GET form keep server paths.
    await frame.locator('#about').click();
    await frame.locator('#t').filter({hasText: 'Über uns'}).waitFor();
    assert.equal(await pg.$eval('.project-preview .project-preview-title small', el => el.textContent), '/about · Entwicklungsserver auf Port 8000');
    await pg.evaluate(() => document.getElementById('projectProcPreview').click());
    await frame.locator('#zahl').filter({hasText: 'Zahl 42'}).waitFor();
    await frame.locator('#q').fill('abc');
    await frame.locator('#go').click();
    await frame.locator('#t').filter({hasText: 'Suche: abc'}).waitFor();
    assert.equal(await pg.$eval('.project-preview .project-preview-title small', el => el.textContent), '/suche?q=abc · Entwicklungsserver auf Port 8000');

    // An error of a server page shows with its line.
    await pg.evaluate(() => document.getElementById('projectProcPreview').click());
    await frame.locator('#fail').click();
    await pg.waitForFunction(() => [...document.querySelectorAll('.project-preview-errors li')].some(li => /nichtDa/.test(li.textContent)));
    assert.match(await pg.$eval('.project-preview-errors li', el => el.textContent), /nichtDa is not defined · kaputt, Zeile 2/);

    // Phone and dark look.
    await pg.setViewportSize({width: 360, height: 780});
    const phone = await pg.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert.ok(phone.scroll <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await pg.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await pg.$eval('.project-proc-output', el => ({bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color}));
    assert.notEqual(dark.bg, dark.color, 'readable output in the dark look');
    await pg.setViewportSize({width: 1100, height: 900});

    // Beenden: the real exit code, the start form is back, the list shows the process.
    await pg.click('#projectProcStop');
    await waitStatus(pg, 'Beendet · Exitcode 143');
    assert.ok(await pg.$eval('.project-proc-status', el => el.classList.contains('is-stopped')), 'a grey line for a stopped process');
    assert.ok(await pg.$('#projectProcStart'), 'start again after the end');
    assert.equal(await pg.$('#projectProcPreview'), null, 'no preview after the end');
    assert.ok((await output(pg)).endsWith('Beendet.\n'), 'the last output arrives');

    // A second process without a port: no preview button; the list of the latest processes.
    await pg.fill('.project-proc-command', 'python -i');
    await pg.fill('.project-proc-port', '');
    await pg.click('#projectProcStart');
    await waitStatus(pg, 'python -i · Läuft$');
    assert.equal(await pg.$('#projectProcPreview'), null, 'no preview without a port');
    assert.equal(await statusText(pg), 'python -i · Läuft');
    await pg.waitForFunction(() => document.querySelectorAll('.project-proc-item').length === 2);
    await pg.click('.project-proc-item[data-proc="1"]');
    await waitStatus(pg, 'Exitcode 143');
    assert.ok(await pg.$eval('.project-proc-item[data-proc="1"]', el => el.classList.contains('is-current')), 'the chosen process is marked');
    assert.equal(await output(pg), 'Server bereit auf Port 8000\nAnfrage 2\nAnfrage 3\nAnfrage 4\nEcho: hallo Mara\nBeendet.\n', 'its whole output again');

    // Closing; the Python switch.
    await pg.click('#projectProcClose');
    assert.equal(await pg.isHidden('.project-proc'), true, 'closed');
    st.allowed = false;
    st.procs.forEach(p => { if (p.status !== 'stopped') Object.assign(p, {status: 'exited', exit_code: 0}); });
    await pg.click('#projectFilesProcs');
    await pg.waitForFunction(() => [...document.querySelectorAll('.project-proc .project-files-status.is-error')]
      .some(p => p.textContent.startsWith('Ausführen gehört zum Python-Werkzeug')));
    assert.equal(await pg.$eval('#projectProcStart', b => b.disabled), true, 'no start without Python');
    if (process.env.COREAI_SHOT) await pg.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
    assert.deepEqual(errors.filter(m => !/nichtDa/.test(m)), []);
  } finally {
    await browser.close();
  }
  console.log('CoreAI Projektprozesse UI: Vorschlag, Start, Ausgabe ohne Wiederholung, Eingabe, Servervorschau mit Stylesheet, Skript und fetch, Link, GET-Formular, Seitenfehler, Beenden mit Exitcode, Liste, Python-Schalter, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI project procs UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
