// CoreAI 0.7.69 (P09): the tab "Dateien" on the project page, in a real browser (Playwright).
//   node scripts/project-files-ui-selftest.cjs
// A page with the real script and styles and a stand-in for api/project_files.php that keeps
// files and revisions like the server. Checked: empty state, new file, edit with "ungespeichert",
// Ctrl+S, the tree with folders, search with line numbers, history, comparison and restore,
// a conflict after a build with both ways out, rename, delete, a refused path, the ZIP and file
// links, unsaved edits only dropped after a question, the line comparison itself, dark mode and
// a phone without sideways scrolling. Plus the wiring in app.html and js/coreui-projects.js.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml'};
const shots = process.env.COREUI_SHOTS || '';
const PROJECT = 'a'.repeat(32);

// --- Wiring (static) ------------------------------------------------------------------------
const html = read('app.html');
const projectsJs = read('js/coreui-projects.js');
assert.match(html, /<button type="button" id="projectTabFiles" aria-selected="false">Dateien<\/button>/, 'tab Dateien');
assert.match(html, /<section class="project-file-results" id="projectFilesPanel" aria-label="Projektdateien" hidden><\/section>/, 'files panel, hidden by default');
assert.ok(html.indexOf('js/coreui-project-files.js') > 0 && html.indexOf('js/coreui-project-files.js') < html.indexOf('js/coreui-projects.js'),
  'the files script loads before the project script');
assert.match(html, /css\/coreui-project-files\.css\?v=\d+/, 'files stylesheet with a content cache key');
assert.ok(projectsJs.includes("$('projectFilesPanel').hidden=next!=='files';") && projectsJs.includes("$('projectTabFiles').addEventListener('click',function(){setTab('files');});")
  && projectsJs.includes('filesTab().open(current)'), 'coreui-projects.js switches to the files tab');
assert.ok(projectsJs.includes('if(dirtyIn&&dirtyIn!==id){if(!files.leave())return;files.reset();}'),
  'unsaved edits of another project are only dropped after a question');
assert.ok(read('css/coreui-project-files.css').includes('#projectFilesPanel[hidden] { display:none !important; }'), 'hidden panel stays hidden');

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css">
<link rel="stylesheet" href="/css/coreui-projects.css"><link rel="stylesheet" href="/css/coreui-project-files.css">
<link rel="stylesheet" href="/css/coreui-project-theme.css"></head>
<body class="app-body project-home-active"><section class="project-home" id="coreuiProjectHome"><div class="project-home-content">
<nav class="project-home-tabs" aria-label="Projektansicht"><button type="button" id="projectTabChats">Chats</button>
<button type="button" id="projectTabSources">Quellen</button><button type="button" id="projectTabFiles" class="active" aria-selected="true">Dateien</button></nav>
<section class="project-file-results" id="projectFilesPanel" aria-label="Projektdateien"></section>
</div></section><script src="/js/coreui-project-files.js"></script></body></html>`;

// A stand-in that behaves like api/project_files.php for one project.
function server() {
  const st = {files: {}, posts: [], csrf: 'csrf-test'};
  const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
  const now = () => '2026-11-15 14:' + String(10 + Object.keys(st.files).length).padStart(2, '0') + ':00';
  const pub = f => ({path: f.path, size_bytes: Buffer.byteLength(f.content), sha256: sha(f.content), revision: f.revision, updated_at: f.updated_at});
  const valid = p => /^[\p{L}\p{N}_.][\p{L}\p{N}_. ()+\/-]*\.(html|css|js|json|md|txt|py)$/u.test(p) && !p.split('/').some(x => x === '..' || x === '.');
  function write(p, content, source) {
    let f = st.files[p];
    if (!f) { f = st.files[p] = {path: p, content, revision: 1, updated_at: now(), revisions: []}; }
    else if (f.content === content) return {file: pub(f), changed: false};
    else { f.content = content; f.revision++; f.updated_at = now(); }
    f.revisions.unshift({revision: f.revision, path: p, content, source, created_at: f.updated_at});
    return {file: pub(f), changed: true};
  }
  function get(p) {
    const f = st.files[p];
    return f && Object.assign(pub(f), {content: f.content, revisions: f.revisions.map(r => ({revision: r.revision, path: r.path,
      size_bytes: Buffer.byteLength(r.content), sha256: sha(r.content), source: r.source, created_at: r.created_at}))});
  }
  function handle(method, query, body) {
    if (method === 'GET') {
      if (query.get('project_id') !== PROJECT) return [404, {ok: false, error: 'project_not_found'}];
      const p = query.get('path');
      if (query.has('search')) {
        const q = query.get('search').toLowerCase();
        const results = Object.values(st.files).sort((a, b) => a.path.localeCompare(b.path)).map(f => ({path: f.path, in_path: f.path.toLowerCase().includes(q),
          lines: f.content.split('\n').map((t, i) => ({line: i + 1, text: t.trim()})).filter(l => l.text.toLowerCase().includes(q)).slice(0, 3)}))
          .filter(r => r.in_path || r.lines.length);
        return [200, {ok: true, results}];
      }
      if (p && query.has('revision')) {
        const r = (st.files[p] || {revisions: []}).revisions.find(x => x.revision === Number(query.get('revision')));
        return r ? [200, {ok: true, revision: r}] : [404, {ok: false, error: 'revision_not_found'}];
      }
      if (p) return st.files[p] ? [200, {ok: true, file: get(p)}] : [404, {ok: false, error: 'file_not_found'}];
      const files = Object.values(st.files).sort((a, b) => a.path.localeCompare(b.path)).map(pub);
      return [200, {ok: true, files, count: files.length, bytes: files.reduce((s, f) => s + f.size_bytes, 0),
        limits: {file_bytes: 524288, files: 200, project_bytes: 10485760, revisions: 20}, csrf_token: st.csrf}];
    }
    st.posts.push(body);
    if (body.project_id !== PROJECT) return [404, {ok: false, error: 'project_not_found'}];
    const p = body.path;
    switch (body.action) {
      case 'create':
        if (!valid(p)) return [400, {ok: false, error: 'invalid_path'}];
        if (st.files[p]) return [409, {ok: false, error: 'file_exists'}];
        return [201, {ok: true, file: write(p, body.content, 'create').file}];
      case 'save': {
        const f = st.files[p];
        if (!f) return [404, {ok: false, error: 'file_not_found'}];
        if (body.base_sha256 !== sha(f.content)) return [409, {ok: false, error: 'file_changed'}];
        const r = write(p, body.content, 'edit');
        return [200, {ok: true, file: r.file, changed: r.changed}];
      }
      case 'rename': {
        const f = st.files[p];
        if (!valid(body.new_path)) return [400, {ok: false, error: 'invalid_path'}];
        if (st.files[body.new_path]) return [409, {ok: false, error: 'file_exists'}];
        delete st.files[p]; f.path = body.new_path; f.revision++;
        f.revisions.unshift({revision: f.revision, path: f.path, content: f.content, source: 'rename', created_at: f.updated_at});
        st.files[f.path] = f;
        return [200, {ok: true, file: pub(f)}];
      }
      case 'delete': delete st.files[p]; return [200, {ok: true}];
      case 'restore': {
        const r = st.files[p].revisions.find(x => x.revision === body.revision);
        const res = write(p, r.content, 'restore');
        return [200, {ok: true, file: res.file, changed: res.changed}];
      }
    }
    return [400, {ok: false, error: 'unknown_action'}];
  }
  return {st, handle, write};
}

async function open(browser, viewport, srv) {
  const context = await browser.newContext({viewport: viewport || {width: 1100, height: 900}, locale: 'de-DE'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.pathname === '/') return route.fulfill({status: 200, contentType: 'text/html', body: harness});
    if (u.pathname === '/api/project_files.php') {
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'csrf-test', 'every write carries the CSRF token');
      const [status, json] = srv.handle(req.method(), u.searchParams, req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null);
      return route.fulfill({status, contentType: 'application/json', body: JSON.stringify(json)});
    }
    const file = path.join(root, u.pathname);
    if (!file.startsWith(root) || !fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({status: 200, contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  await page.goto('https://coreui.test/');
  await page.evaluate(id => window.CoreAIProjectFiles.open(id), PROJECT);
  return {context, page, errors};
}

const status = page => page.textContent('.project-files-status');

(async () => {
  const browser = await chromium.launch();
  try {
    const srv = server();
    const {context, page, errors} = await open(browser, null, srv);

    // Empty project.
    await page.waitForSelector('.project-files-empty');
    assert.match(await page.textContent('.project-files-empty'), /^Noch keine Dateien\. Was Mara in den Chats dieses Projekts baut, landet hier\./);
    assert.equal(await page.$('.project-files-zip'), null, 'no ZIP for an empty project');

    // A refused path, then a new file.
    await page.click('#projectFilesNew');
    await page.fill('.project-files-form input', '../geheim.txt');
    await page.click('.project-files-form button[type=submit]');
    await page.waitForFunction(() => /Ungültiger Pfad/.test(document.querySelector('.project-files-status').textContent));
    await page.fill('.project-files-form input', 'index.html');
    await page.click('.project-files-form button[type=submit]');
    await page.waitForSelector('.project-file-editor');
    assert.equal(await page.textContent('.project-file-title strong'), 'index.html');
    assert.equal(await status(page), 'Datei angelegt.');
    assert.equal(await page.$eval('#projectFileSave', b => b.disabled), true, 'nothing to save yet');

    // Edit, "ungespeichert", Ctrl+S.
    await page.fill('.project-file-editor', '<h1>Hallo</h1>\n<p>Erste Fassung</p>\n');
    assert.match(await page.textContent('.project-file-title small'), /ungespeichert$/);
    assert.equal(await page.$eval('#projectFileSave', b => b.disabled), false);
    assert.equal(await page.textContent('.project-file-gutter'), '1\n2\n3', 'line numbers follow the text');
    await page.press('.project-file-editor', 'Control+s');
    await page.waitForFunction(() => document.querySelector('.project-files-status').textContent === 'Gespeichert als Stand 2.');
    assert.doesNotMatch(await page.textContent('.project-file-title small'), /ungespeichert/);
    assert.equal(srv.st.files['index.html'].content, '<h1>Hallo</h1>\n<p>Erste Fassung</p>\n');
    await page.fill('.project-file-editor', '<h1>Hallo Welt</h1>\n<p>Erste Fassung</p>\n<p>Neu</p>\n');
    await page.click('#projectFileSave');
    await page.waitForFunction(() => document.querySelector('.project-files-status').textContent === 'Gespeichert als Stand 3.');

    // History, comparison, restore.
    await page.click('text=Verlauf');
    const revs = await page.$$eval('.project-file-revision strong', n => n.map(x => x.textContent));
    assert.deepEqual(revs, ['Stand 3 (aktuell)', 'Stand 2', 'Stand 1']);
    assert.match(await page.textContent('.project-file-revision:nth-child(3) small'), /^Neu angelegt · 15\.11\.2026, 14:/);
    await page.click('[data-revision="2"]');
    await page.waitForSelector('.project-diff');
    assert.equal(await page.textContent('.project-file-compare-head strong'), 'Stand 2 → aktueller Stand 3');
    assert.equal(await page.textContent('.project-diff-count'), '+2 −1');
    assert.deepEqual(await page.$$eval('.project-diff-row.is-del .project-diff-text', n => n.map(x => x.textContent)), ['<h1>Hallo</h1>']);
    assert.deepEqual(await page.$$eval('.project-diff-row.is-add .project-diff-text', n => n.map(x => x.textContent)), ['<h1>Hallo Welt</h1>', '<p>Neu</p>']);
    if (shots) await page.screenshot({path: path.join(shots, 'project-files-compare.png'), fullPage: true});
    await page.click('text=Diesen Stand wiederherstellen');
    await page.waitForFunction(() => /^Stand 2 ist wiederhergestellt \(jetzt Stand 4\)\.$/.test(document.querySelector('.project-files-status').textContent));
    assert.equal(await page.$eval('.project-file-editor', t => t.value), '<h1>Hallo</h1>\n<p>Erste Fassung</p>\n');
    assert.equal(await page.$('.project-file-compare'), null, 'the comparison closes after a restore');

    // A build changes the file meanwhile: conflict, both ways out.
    srv.write('index.html', '<h1>Vom Bau</h1>\n', 'build');
    await page.fill('.project-file-editor', '<h1>Meine Fassung</h1>\n');
    await page.click('#projectFileSave');
    await page.waitForSelector('.project-files-conflict');
    assert.match(await status(page), /^Die Datei wurde inzwischen geändert, zum Beispiel durch einen neuen Bau\. Deine Änderungen sind noch im Editor\.$/);
    assert.equal(await page.$eval('.project-file-editor', t => t.value), '<h1>Meine Fassung</h1>\n', 'the draft stays in the editor');
    await page.click('text=Meine Fassung trotzdem speichern');
    await page.waitForFunction(() => /^Gespeichert als Stand 6\.$/.test(document.querySelector('.project-files-status').textContent));
    assert.equal(srv.st.files['index.html'].content, '<h1>Meine Fassung</h1>\n');
    srv.write('index.html', '<h1>Noch ein Bau</h1>\n', 'build');
    await page.fill('.project-file-editor', '<h1>Verworfen</h1>\n');
    await page.click('#projectFileSave');
    await page.waitForSelector('.project-files-conflict');
    await page.click('text=Neu laden (meine Änderungen verwerfen)');
    await page.waitForFunction(() => document.querySelector('.project-file-editor') && document.querySelector('.project-file-editor').value === '<h1>Noch ein Bau</h1>\n');

    // The source label of a build in the history.
    await page.click('text=Verlauf');
    assert.match(await page.textContent('.project-file-revision.is-current small'), /^Von Mara gebaut · /);

    // Rename.
    await page.click('text=Umbenennen');
    await page.fill('.project-files-form input', 'public/index.html');
    await page.click('.project-files-form button[type=submit]');
    await page.waitForFunction(() => document.querySelector('.project-file-title strong').textContent === 'public/index.html');
    assert.equal(await status(page), 'Umbenannt in public/index.html.');
    assert.match(await page.$eval('.project-file-actions a', a => a.href), /project_files\.php\?project_id=a{32}&path=public%2Findex\.html&download=1$/);

    // Unsaved edits only go after a question.
    await page.fill('.project-file-editor', 'Entwurf');
    let asked = '';
    page.once('dialog', d => { asked = d.message(); d.dismiss(); });
    await page.click('.project-files-back');
    assert.equal(asked, 'Ungespeicherte Änderungen an „public/index.html“ verwerfen?');
    assert.ok(await page.$('.project-file-editor'), 'cancel keeps the editor');
    page.once('dialog', d => d.accept());
    await page.click('.project-files-back');
    await page.waitForSelector('.project-files-tree');

    // Tree with folders, more files, search.
    srv.write('src/game.js', 'const speed = 5;\nfunction jump() { return speed * 2; }\n', 'build');
    srv.write('README.md', '# Spiel\n', 'build');
    await page.evaluate(id => { window.CoreAIProjectFiles.reset(); return window.CoreAIProjectFiles.open(id); }, PROJECT);
    await page.waitForSelector('[data-folder="src"]');
    assert.deepEqual(await page.$$eval('.project-files-tree > button', n => n.map(x => x.getAttribute('data-folder') || x.getAttribute('data-path'))),
      ['public', 'public/index.html', 'src', 'src/game.js', 'README.md'], 'folders first, then files');
    assert.match(await page.textContent('.project-files-summary'), /^3 Dateien · \d+ B von 10,0 MB$/);
    assert.match(await page.$eval('.project-files-zip', a => a.href), /project_files\.php\?project_id=a{32}&zip=1$/);
    await page.click('[data-folder="src"]');
    assert.equal(await page.$('[data-path="src/game.js"]'), null, 'a closed folder hides its files');
    assert.equal(await page.getAttribute('[data-folder="src"]', 'aria-expanded'), 'false');
    await page.click('[data-folder="src"]');
    await page.fill('.project-files-search', 'speed');
    await page.waitForSelector('.project-search-result');
    assert.equal(await page.textContent('.project-search-result strong'), 'src/game.js');
    assert.deepEqual(await page.$$eval('.project-search-number', n => n.map(x => x.textContent)), ['Zeile 1', 'Zeile 2']);
    await page.fill('.project-files-search', 'nichtdrin');
    await page.waitForFunction(() => document.querySelector('.project-files-empty') && document.querySelector('.project-files-empty').textContent === 'Nichts gefunden.');
    await page.fill('.project-files-search', '');
    await page.waitForSelector('.project-files-tree');

    // Delete.
    await page.click('[data-path="README.md"]');
    await page.waitForSelector('.project-file-editor');
    page.once('dialog', d => { asked = d.message(); d.accept(); });
    await page.click('.project-files-danger');
    await page.waitForSelector('.project-files-tree');
    assert.equal(asked, '„README.md“ mit seinem ganzen Verlauf löschen? Das lässt sich nicht rückgängig machen.');
    assert.equal(await status(page), '„README.md“ gelöscht.');
    assert.ok(!srv.st.files['README.md']);
    if (shots) await page.screenshot({path: path.join(shots, 'project-files-list.png'), fullPage: true});

    // The line comparison itself: applying it to the old text gives the new text.
    const ok = await page.evaluate(() => {
      const diff = window.CoreAIProjectFiles.diffLines;
      let seed = 7;
      const rnd = n => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed % n; };
      for (let round = 0; round < 300; round++) {
        const a = Array.from({length: rnd(12)}, () => 'x' + rnd(4)), b = Array.from({length: rnd(12)}, () => 'x' + rnd(4));
        const ops = diff(a, b);
        const back = ops.filter(o => o.t !== '+').map(o => o.text), forward = ops.filter(o => o.t !== '-').map(o => o.text);
        if (JSON.stringify(back) !== JSON.stringify(a) || JSON.stringify(forward) !== JSON.stringify(b)) return 'mismatch ' + JSON.stringify([a, b]);
      }
      return diff(Array(5000).fill('a'), Array(4000).fill('b')) === null ? 'ok' : 'no size limit';
    });
    assert.equal(ok, 'ok');

    // Dark mode.
    await page.click('[data-path="src/game.js"]');
    await page.waitForSelector('.project-file-editor');
    const light = await page.$eval('.project-file-editor-wrap', n => getComputedStyle(n).backgroundColor);
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    assert.notEqual(await page.$eval('.project-file-editor-wrap', n => getComputedStyle(n).backgroundColor), light, 'dark editor');
    if (shots) await page.screenshot({path: path.join(shots, 'project-files-dark.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();

    // Phone: no sideways scrolling in the list and in the editor with a long line.
    const phoneSrv = server();
    phoneSrv.write('src/a-very-long-folder-name/with-a-long-file-name-for-phones.js', 'const x = "' + 'y'.repeat(400) + '";\n', 'build');
    const phone = await open(browser, {width: 390, height: 844}, phoneSrv);
    await phone.page.waitForSelector('.project-files-tree');
    const wide = () => phone.page.evaluate(() => document.scrollingElement.scrollWidth - window.innerWidth);
    assert.ok(await wide() <= 0, 'phone list fits');
    await phone.page.click('.project-file-row');
    await phone.page.waitForSelector('.project-file-editor');
    assert.ok(await wide() <= 0, 'phone editor fits, long lines scroll inside the editor');
    if (shots) await phone.page.screenshot({path: path.join(shots, 'project-files-phone.png'), fullPage: true});
    assert.deepEqual(phone.errors, []);
    await phone.context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI Projektdateien UI: Tab und Verdrahtung, leeres Projekt, neue Datei, abgelehnter Pfad, Bearbeiten mit Strg+S, '
    + 'Verlauf, Vergleich, Wiederherstellen, Konflikt nach einem Bau, Umbenennen, ungespeicherte Aenderungen, Baum, Suche, '
    + 'Loeschen, Zeilenvergleich, Dunkel und Telefon OK');
})().catch(e => { console.error(e); process.exit(1); });
