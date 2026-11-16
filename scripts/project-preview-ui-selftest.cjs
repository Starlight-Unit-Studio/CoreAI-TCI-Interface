// CoreAI 0.7.75 (P10 A): the preview of a web project, in a real browser (Playwright).
//   node scripts/project-preview-ui-selftest.cjs
// The real js/coreui-project-files.js and styles, a stand-in for api/project_files.php that
// builds every preview with the real PHP code (api/project_preview_store.php) and sends it with
// the real sandbox CSP. Checked: the "Vorschau" button, the page with its stylesheet, a deferred
// script that waits for DOMContentLoaded, fetch() and XMLHttpRequest of a project file, an SVG
// picture; the page cannot read cookies or its parent; a link to another page of the project;
// errors with file and line (a script file and the page itself); saving in the editor reloads the
// preview with the change; "In neuem Tab", closing, a phone without sideways scrolling, dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const PROJECT = 'b'.repeat(32);
const ORIGIN = 'http://coreui.test';

function php(code, input) {
  return execFileSync('php', ['-r', code], {input: input || '', encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
}
const CSP = php("require " + JSON.stringify(path.join(root, 'api/project_preview_store.php')) + "; echo COREUI_PREVIEW_CSP;");
function build(files, page) {
  return php("require " + JSON.stringify(path.join(root, 'api/project_preview_store.php')) + ";"
    + "$f = json_decode(stream_get_contents(STDIN), true);"
    + "echo coreui_preview_build($f['files'], $f['page'], './project_files.php?project_id=" + PROJECT + "&preview=');",
  JSON.stringify({files, page}));
}

const files = {
  'index.html': '<!doctype html>\n<html lang="de">\n<head>\n<meta charset="utf-8">\n<title>Spiel</title>\n'
    + '<link rel="stylesheet" href="css/style.css">\n<script src="js/game.js" defer></script>\n</head>\n<body>\n'
    + '<h1 id="title">Laden …</h1>\n<p id="level">?</p>\n<p id="xhr">?</p>\n<p id="secret">?</p>\n'
    + '<img id="logo" src="img/logo.svg" alt="Logo">\n<a id="about" href="about.html">Über das Spiel</a>\n'
    + '<script src="js/oops.js"></script>\n</body>\n</html>\n',
  'css/style.css': 'h1 {\n  color: rgb(200, 30, 40);\n}\n',
  'js/game.js': "document.addEventListener('DOMContentLoaded', function () {\n"
    + "  document.getElementById('title').textContent = 'Spiel bereit';\n"
    + "  fetch('data/level.json').then(function (r) { return r.json(); }).then(function (d) {\n"
    + "    document.getElementById('level').textContent = 'Level ' + d.level;\n  });\n"
    + "  var x = new XMLHttpRequest();\n  x.open('GET', './data/words.txt');\n"
    + "  x.onload = function () { document.getElementById('xhr').textContent = x.responseText.trim(); };\n  x.send();\n"
    + "  var secret = 'blocked';\n  try { secret = 'cookie:' + document.cookie; } catch (e) {}\n"
    + "  try { secret += parent.document.title ? ' parent' : ''; } catch (e) { secret += ' noparent'; }\n"
    + "  document.getElementById('secret').textContent = secret + ' ' + window.origin;\n});\n",
  'js/oops.js': 'var ok = 1;\nmissingFunction();\n',
  'data/level.json': '{"level": 3}',
  'data/words.txt': 'Hallo Welt\n',
  'img/logo.svg': '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"><rect width="40" height="20" fill="#2a6"/></svg>',
  'about.html': '<!doctype html>\n<html>\n<head><link rel="stylesheet" href="css/style.css"></head>\n<body>\n<h1>Über</h1>\n'
    + '<script>\nvar a = 1;\nnotThere.call();\n</script>\n</body>\n</html>\n',
};
const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const st = {revisions: {}, previews: [], saves: 0};
Object.keys(files).forEach(p => { st.revisions[p] = 1; });
const pub = p => ({path: p, size_bytes: Buffer.byteLength(files[p]), sha256: sha(files[p]), revision: st.revisions[p], updated_at: '2026-11-16 18:30:00'});

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css">
<link rel="stylesheet" href="/css/coreui-projects.css"><link rel="stylesheet" href="/css/coreui-project-files.css">
<link rel="stylesheet" href="/css/coreui-project-theme.css"></head>
<body class="app-body project-home-active"><section class="project-home" id="coreuiProjectHome"><div class="project-home-content">
<section class="project-file-results" id="projectFilesPanel" aria-label="Projektdateien"></section>
</div></section><script src="/js/coreui-project-files.js"></script>
<script>window.CoreAIProjectFiles.open(${JSON.stringify(PROJECT)});</script></body></html>`;

async function route(r) {
  const req = r.request();
  const u = new URL(req.url());
  if (u.origin !== ORIGIN) return r.abort();
  if (u.pathname === '/harness.html') return r.fulfill({contentType: 'text/html', body: harness});
  if (u.pathname === '/api/project_files.php') {
    if (req.method() === 'POST') {
      const body = JSON.parse(req.postData() || '{}');
      if (body.action === 'save' && files[body.path] !== undefined) {
        if (body.base_sha256 !== sha(files[body.path])) return r.fulfill({status: 409, contentType: 'application/json', body: '{"ok":false,"error":"file_changed"}'});
        const changed = files[body.path] !== body.content;
        files[body.path] = body.content;
        if (changed) st.revisions[body.path]++;
        st.saves++;
        return r.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, file: pub(body.path), changed})});
      }
      return r.fulfill({status: 400, contentType: 'application/json', body: '{"ok":false,"error":"unknown_action"}'});
    }
    if (u.searchParams.get('project_id') !== PROJECT) return r.fulfill({status: 404, contentType: 'application/json', body: '{"ok":false,"error":"project_not_found"}'});
    if (u.searchParams.has('preview')) {
      const page = u.searchParams.get('preview');
      st.previews.push(page);
      if (files[page] === undefined) return r.fulfill({status: 404, contentType: 'text/html', body: 'gone'});
      return r.fulfill({contentType: 'text/html; charset=utf-8', headers: {'Content-Security-Policy': CSP}, body: build(files, page)});
    }
    const p = u.searchParams.get('path');
    if (p) {
      return r.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, file: Object.assign(pub(p), {content: files[p],
        revisions: [{revision: st.revisions[p], path: p, size_bytes: Buffer.byteLength(files[p]), sha256: sha(files[p]), source: 'build', created_at: '2026-11-16 18:30:00'}]})})});
    }
    const list = Object.keys(files).sort().map(pub);
    return r.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, files: list, count: list.length,
      bytes: list.reduce((s, f) => s + f.size_bytes, 0), limits: {file_bytes: 524288, files: 200, project_bytes: 10485760, revisions: 20}, csrf_token: 'csrf'})});
  }
  const file = path.join(root, u.pathname);
  if (/^\/(css|js)\/[a-z0-9-]+\.(css|js)$/.test(u.pathname) && fs.existsSync(file)) {
    return r.fulfill({contentType: u.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
  }
  return r.fulfill({status: 404, body: ''});
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({viewport: {width: 1100, height: 900}});
    await context.addCookies([{name: 'STU_SESSION', value: 'secret-session', domain: 'coreui.test', path: '/', sameSite: 'Lax'}]);
    await context.route(ORIGIN + '/**', route);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(ORIGIN + '/harness.html');
    await page.waitForSelector('#projectFilesPreview');
    assert.equal(await page.isHidden('.project-preview'), true, 'no preview before it is asked for');

    // The preview of the start page.
    await page.click('#projectFilesPreview');
    const frame = page.frameLocator('.project-preview-frame');
    await frame.locator('#level').filter({hasText: 'Level 3'}).waitFor();
    const inside = await page.frame({url: /preview=index\.html/}).evaluate(() => ({
      title: document.getElementById('title').textContent,
      color: getComputedStyle(document.getElementById('title')).color,
      level: document.getElementById('level').textContent,
      xhr: document.getElementById('xhr').textContent,
      secret: document.getElementById('secret').textContent,
      logo: document.getElementById('logo').naturalWidth,
      origin: window.origin,
    }));
    assert.deepEqual(inside, {title: 'Spiel bereit', color: 'rgb(200, 30, 40)', level: 'Level 3', xhr: 'Hallo Welt',
      secret: 'blocked noparent null', logo: 40, origin: 'null'}, 'the page in the preview: ' + JSON.stringify(inside));
    const head = await page.evaluate(() => ({
      title: document.querySelector('.project-preview-title small').textContent,
      sandbox: document.querySelector('.project-preview-frame').getAttribute('sandbox'),
      tab: document.getElementById('projectPreviewTab').getAttribute('href'),
      target: document.getElementById('projectPreviewTab').target,
    }));
    assert.equal(head.title, 'index.html · gespeicherter Stand');
    assert.equal(head.sandbox, 'allow-scripts allow-forms allow-modals allow-popups');
    assert.match(head.tab, new RegExp('^\\./api/project_files\\.php\\?project_id=' + PROJECT + '&preview=index\\.html&v=\\d+$'));
    assert.equal(head.target, '_blank');

    // The error of a script file, with its own file and line.
    await page.waitForSelector('.project-preview-errors li');
    assert.deepEqual(await page.$$eval('.project-preview-errors li', l => l.map(x => x.textContent)),
      ['Uncaught ReferenceError: missingFunction is not defined · js/oops.js, Zeile 2'], 'error of a script file');

    // A link to another page; an error in the page itself names the page's own line.
    await frame.locator('#about').click();
    await page.waitForFunction(() => document.querySelector('.project-preview-title small').textContent.startsWith('about.html'));
    await page.waitForSelector('.project-preview-errors li');
    assert.deepEqual(await page.$$eval('.project-preview-errors li', l => l.map(x => x.textContent)),
      ['Uncaught ReferenceError: notThere is not defined · about.html, Zeile 8'], 'error of the page with its own line');
    assert.equal(await frame.locator('h1').evaluate(h => getComputedStyle(h).color), 'rgb(200, 30, 40)', 'stylesheet of a second page');

    // Editing and saving reloads the preview with the change.
    await page.click('#projectPreviewClose');
    assert.equal(await page.isHidden('.project-preview'), true, 'closed');
    await page.click('[data-path="index.html"]');
    await page.click('#projectFilePreview');
    await frame.locator('#title').filter({hasText: 'Spiel bereit'}).waitFor();
    const before = st.previews.length;
    await page.fill('.project-file-editor', files['index.html'].replace('<h1 id="title">Laden …</h1>', '<h1 id="title">Laden …</h1>\n<h2 id="new">Neu dabei</h2>'));
    await page.click('#projectFileSave');
    await frame.locator('#new').filter({hasText: 'Neu dabei'}).waitFor();
    assert.ok(st.saves === 1 && st.previews.length === before + 1, 'one save, one new preview');

    // Phone and dark look.
    await page.setViewportSize({width: 360, height: 780});
    const phone = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth,
      frame: document.querySelector('.project-preview-frame').getBoundingClientRect().width}));
    assert.ok(phone.scroll <= phone.view && phone.frame <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await page.evaluate(() => ({frame: getComputedStyle(document.querySelector('.project-preview-frame')).backgroundColor,
      box: getComputedStyle(document.querySelector('.project-preview')).backgroundColor}));
    assert.ok(dark.frame === 'rgb(255, 255, 255)' && dark.box !== 'rgb(255, 255, 255)', 'dark look around a white page: ' + JSON.stringify(dark));
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
    // Only the two mistakes built into the sample pages; anything else is a fault of the interface.
    assert.deepEqual(errors.filter(m => !/^(missingFunction|notThere) is not defined$/.test(m)), [], 'no other errors');
  } finally {
    await browser.close();
  }
  console.log('CoreAI Projektvorschau UI: Vorschau, Stylesheet, defer, fetch und XHR, SVG, keine Cookies und kein Zugriff nach aussen, Seitenlink, Fehler mit Datei und Zeile, Speichern zeigt die Aenderung, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI project preview UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
