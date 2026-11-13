// CoreAI 0.7.38: Deep Research in the chat, in a real browser (Playwright).
//   node scripts/deep-research-ui-selftest.cjs
// A page with the real scripts (Markdown, + menu, Deep Research) and a stand-in for api/deep_research.php.
// Checked: the + menu entry and its chip, the plan card (steps, countdown, Starten, Abbrechen, Bearbeiten
// with the plan in the input field), the running card (step states, searches, bar, Stopp), the finished
// card (summary, report preview), the full view (citations, table of contents, sources by domain,
// activity, download, Esc), an ended run, dark mode and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.png': 'image/png', '.svg': 'image/svg+xml'};
const shots = process.env.COREUI_SHOTS || '';

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css">
<link rel="stylesheet" href="/css/coreui-deep-research.css"></head>
<body><main id="messages" style="padding:16px 16px 180px;max-width:760px"></main>
<div class="composer-frame" style="position:fixed;left:0;right:0;bottom:0;z-index:5"><button type="button" id="btnAttach"></button><input type="file" id="fileInput" hidden>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label></div>
<script src="/js/coreui-markdown.js"></script><script src="/js/coreui-composer-menu.js"></script>
<script src="/js/coreui-deep-research.js"></script>
<script>window.addCard = function (text) { var b = document.createElement('div'); b.className = 'msg-bubble msg-deep-research';
  b.appendChild(window.CoreAIDeepResearch.card(text)); document.getElementById('messages').appendChild(b); return true; };</script>
</body></html>`;

const steps = ['Durchsuche die angegebene Website example.test nach Hauptangeboten.', 'Extrahiere Dienstleistungen, Produkte und Zielgruppen.',
  'Ergänze die Recherche mit externen Quellen.', 'Fasse die Erkenntnisse in einem strukturierten Bericht zusammen.'];
const report = '# Example Studio: Analyse\n\n## Zusammenfassung\n\nEin unabhängiges Studio [1] mit Büchern und einem Spiel [2].\n\n'
  + '## Angebote\n\n### Literatur\n\nDrei Romane [1].\n\n### Spiel\n\nEin Strategiespiel [2].\n\n## Empfehlungen\n\nMehr Referenzen zeigen.';

function base(id, over) {
  return Object.assign({id, session_id: 'cs_1', status: 'planned', title: 'Analyse example.test', query: 'https://www.example.test', steps,
    states: ['todo', 'todo', 'todo', 'todo'], phase: '', percent: 0, autostart_in: 45, searches: 0, citations: 0, elapsed: 0,
    cancelling: false, error: '', has_report: false, sources_count: 0}, over || {});
}

async function open(browser, viewport) {
  const st = {
    runs: {
      7: base(7, {autostart_in: 3}),
      8: base(8, {status: 'done', states: ['done', 'done', 'done', 'done'], percent: 100, autostart_in: null, searches: 12, citations: 2, elapsed: 185, has_report: true, sources_count: 3}),
      9: base(9), 10: base(10), 11: base(11, {status: 'error', error: 'worker_lost', autostart_in: null}),
    },
    posts: [], downloads: 0,
  };
  const details = {
    sources: [{n: 1, url: 'https://www.example.test/', title: 'Example Studio · Start', domain: 'example.test', cited: true},
      {n: 2, url: 'https://games.example.test/spiel', title: 'Das Spiel', domain: 'games.example.test', cited: true},
      {n: 3, url: 'https://other.test/', title: 'Nicht zitiert', domain: 'other.test', cited: false}],
    events: [{kind: 'step', text: steps[0], domains: []},
      {kind: 'search', text: 'site:example.test angebote', domains: ['example.test', 'books.example.test', 'games.example.test', 'core.example.test', 'github.test', 'shop.test']},
      {kind: 'read', text: 'Example Studio · Start', domains: ['example.test']},
      {kind: 'note', text: 'Ich habe die Angebote des Studios gefunden.', domains: []}],
    report,
  };
  const context = await browser.newContext({viewport: viewport || {width: 1100, height: 900}, locale: 'de-DE', acceptDownloads: true});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'harness.html') return route.fulfill({contentType: 'text/html', body: harness});
    if (rel === 'api/composer_tools.php') {
      return route.fulfill({json: {ok: true, guest: false, tools: {image: {on: true, why: ''}, web: {on: true, why: ''}, browse: {on: true, why: ''},
        deep: {on: true, why: ''}}, planned: ['connectors']}});
    }
    if (rel === 'api/deep_research.php') {
      if (req.method() === 'POST') {
        const body = JSON.parse(req.postData() || '{}');
        st.posts.push({body, csrf: req.headers()['x-coreui-csrf']});
        const run = st.runs[body.id];
        if (body.action === 'start') Object.assign(run, {status: 'queued', autostart_in: null});
        if (body.action === 'edit') Object.assign(run, {status: 'editing', autostart_in: null});
        if (body.action === 'cancel') {
          if (run.status === 'running') run.cancelling = true; else Object.assign(run, {status: 'cancelled', autostart_in: null});
        }
        return route.fulfill({json: {ok: true, run}});
      }
      const id = Number(u.searchParams.get('id'));
      if (u.searchParams.get('action') === 'download') {
        st.downloads++;
        return route.fulfill({contentType: 'text/markdown', headers: {'Content-Disposition': 'attachment; filename="deep-research-analyse.md"'},
          body: report + '\n\n## Quellen\n\n1. [Example Studio · Start](https://www.example.test/)\n'});
      }
      const run = st.runs[id];
      if (!run) return route.fulfill({status: 404, json: {ok: false, error: 'not_found'}});
      const out = Object.assign({}, run, u.searchParams.get('details') ? (run.has_report ? details : {sources: [], events: [], report: ''}) : {});
      return route.fulfill({json: {ok: true, run: out, csrf_token: 'tok'}});
    }
    const file = path.join(root, rel);
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/harness.html');
  return {context, page, errors, st};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const card = id => `.dr-card[data-dr-id="${id}"]`;

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let {context, page, errors, st} = await open(browser);
    // --- The + menu ------------------------------------------------------------------------------------
    await page.click('#btnAttach');
    const item = page.locator('.cm-item', {hasText: 'Deep Research'});
    await item.waitFor();
    assert.equal(await item.evaluate(n => n.classList.contains('is-off') || n.disabled === true), false, 'Deep Research can be chosen');
    assert.equal(await item.locator('.cm-badge').count(), 0, 'no longer In Vorbereitung');
    await item.click();
    assert.equal((await page.textContent('.composer-intent-label')).trim(), 'Deep Research');
    assert.equal(await page.evaluate(() => window.CoreAIComposerMenu.consume('https://www.example.test')), '[[deep-research]] https://www.example.test');
    assert.equal(await page.evaluate(() => window.CoreAIComposerMenu.parse('[[deep-research]] x').label), 'Deep Research');
    assert.equal(await page.evaluate(() => window.CoreAIComposerMenu.parse('[[deep-research-edit:9]] x').label), 'Deep Research · Plan ändern');
    assert.equal(await page.evaluate(() => window.CoreAIDeepResearch.match('Text [[deep-research:3]]')), 0, 'only a leading mark is a card');

    // --- The plan: countdown, Starten ----------------------------------------------------------------
    await page.evaluate(() => window.addCard('[[deep-research:7]] Ich habe einen Rechercheplan erstellt: Analyse example.test.'));
    await page.waitForSelector(card(7) + ' .dr-title');
    assert.equal(await page.textContent(card(7) + ' .dr-title'), 'Analyse example.test');
    assert.deepEqual(await page.$$eval(card(7) + ' .dr-step', l => l.map(x => x.getAttribute('data-state'))), ['todo', 'todo', 'todo', 'todo']);
    assert.deepEqual(await page.$$eval(card(7) + ' .dr-btn', l => l.map(x => x.childNodes[0].textContent.trim())), ['Bearbeiten', 'Abbrechen', 'Starten']);
    const first = Number(await page.textContent(card(7) + ' .dr-countdown'));
    assert.ok(first >= 2 && first <= 3, 'countdown from the server: ' + first);
    await until(page, sel => Number((document.querySelector(sel) || {}).textContent) < 3, card(7) + ' .dr-countdown');
    if (shots) await page.locator(card(7)).screenshot({path: path.join(shots, 'deep-research-plan.png')});
    await page.click(card(7) + ' .dr-primary');
    await until(page, sel => /Core frei/.test((document.querySelector(sel) || {}).textContent || ''), card(7) + ' .dr-run-line');
    assert.deepEqual(st.posts.pop(), {body: {action: 'start', id: 7}, csrf: 'tok'});

    // --- Running: states, searches, bar, Stopp ------------------------------------------------------
    Object.assign(st.runs[7], {status: 'running', states: ['done', 'active', 'todo', 'todo'], searches: 12, percent: 40});
    await until(page, sel => /recherchiere/.test((document.querySelector(sel) || {}).textContent || ''), card(7) + ' .dr-run-line');
    assert.deepEqual(await page.$$eval(card(7) + ' .dr-step', l => l.map(x => x.getAttribute('data-state'))), ['done', 'active', 'todo', 'todo']);
    assert.equal(await page.textContent(card(7) + ' .dr-run-count'), '12 Suchvorgänge');
    assert.equal(await page.getAttribute(card(7) + ' .dr-bar', 'aria-valuenow'), '40');
    if (shots) await page.locator(card(7)).screenshot({path: path.join(shots, 'deep-research-running.png')});
    await page.click(card(7) + ' .dr-stop');
    await until(page, sel => /höre auf/.test((document.querySelector(sel) || {}).textContent || ''), card(7) + ' .dr-run-line');
    assert.deepEqual(st.posts.pop().body, {action: 'cancel', id: 7});
    assert.equal(await page.isDisabled(card(7) + ' .dr-stop'), true);

    // --- Abbrechen and Bearbeiten ----------------------------------------------------------------------
    await page.evaluate(() => window.addCard('[[deep-research:10]] Plan.'));
    await page.waitForSelector(card(10) + ' .dr-btn');
    await page.click(card(10) + ' .dr-btn >> text=Abbrechen');
    await until(page, sel => !!document.querySelector(sel), card(10) + ' .dr-ended');
    assert.equal(await page.textContent(card(10) + ' .dr-ended'), 'Recherche abgebrochen.');
    await page.evaluate(() => window.addCard('[[deep-research:9]] Plan.'));
    await page.waitForSelector(card(9) + ' .dr-btn');
    await page.click(card(9) + ' .dr-btn >> text=Bearbeiten');
    await until(page, sel => !!document.querySelector(sel), card(9) + ' .dr-hint');
    assert.equal((await page.textContent('.composer-intent-label')).trim(), 'Analyse example.test', 'the plan sits in the input field');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'inputMessage');
    assert.equal(await page.evaluate(() => window.CoreAIComposerMenu.consume('nur Preise')), '[[deep-research-edit:9]] nur Preise');
    assert.equal(await page.isDisabled(card(9) + ' .dr-btn >> text=Bearbeiten'), true);
    assert.equal(await page.locator(card(9) + ' .dr-countdown').count(), 0, 'no countdown while the plan is changed');

    // --- An ended run ---------------------------------------------------------------------------------
    await page.evaluate(() => window.addCard('[[deep-research:11]] Plan.'));
    await page.waitForSelector(card(11) + ' .dr-ended');
    assert.match(await page.textContent(card(11) + ' .dr-ended'), /Hintergrunddienst wurde unterbrochen/);

    // --- Done: summary, report card, full view -----------------------------------------------------------
    await page.evaluate(() => window.addCard('[[deep-research:8]] Plan.'));
    await page.waitForSelector(card(8) + ' .dr-preview h1');
    assert.equal(await page.textContent(card(8) + ' .dr-summary'), 'Recherche abgeschlossen in 3 Min. · 2 Quellenangaben · 12 Suchen');
    assert.equal(await page.textContent(card(8) + ' .dr-report-title'), 'Analyse example.test');
    assert.equal(await page.getAttribute(card(8) + ' a.dr-iconbtn', 'href'), './api/deep_research.php?action=download&id=8');
    if (shots) await page.locator(card(8)).screenshot({path: path.join(shots, 'deep-research-done.png')});
    await page.click(card(8) + ' button[aria-label="Bericht öffnen"]');
    await page.waitForSelector('.dr-viewer[role="dialog"]');
    assert.equal(await page.textContent('.dr-article h1'), 'Example Studio: Analyse');
    assert.deepEqual(await page.$$eval('.dr-article .dr-cite', l => l.map(x => x.textContent)), ['1', '2', '1', '2']);
    assert.equal(await page.evaluate(() => document.body.classList.contains('dr-viewer-open')), true);
    // Table of contents.
    await page.click('.dr-tools button[aria-label="Inhaltsverzeichnis"]');
    assert.deepEqual(await page.$$eval('.dr-toc .dr-toc-link', l => l.map(x => x.textContent)),
      ['Example Studio: Analyse', 'Zusammenfassung', 'Angebote', 'Literatur', 'Spiel', 'Empfehlungen']);
    await page.click('.dr-toc-link >> text=Empfehlungen');
    assert.equal(await page.locator('.dr-sheet').count(), 0, 'a heading closes the sheet');
    // Sources by domain, only the cited ones; activity.
    await page.click('.dr-tools button[aria-label="Quellen und Aktivität"]');
    assert.equal(await page.textContent('.dr-sheet-sub'), 'Quellenangaben · 2');
    assert.deepEqual(await page.$$eval('.dr-src-domain', l => l.map(x => x.textContent)), ['example.test', 'games.example.test']);
    assert.equal(await page.getAttribute('.dr-src[data-n="2"] a', 'target'), '_blank');
    assert.equal(await page.getAttribute('.dr-src[data-n="2"] a', 'rel'), 'noopener noreferrer');
    await page.click('.dr-tab >> text=Aktivität');
    assert.equal(await page.textContent('.dr-tab[aria-selected="true"]'), 'Aktivität · 3 Min.');
    assert.deepEqual(await page.$$eval('.dr-ev-search .dr-chip', l => l.map(x => x.textContent)),
      ['example.test', 'books.example.test', 'games.example.test', 'core.example.test', '2 mehr']);
    assert.equal(await page.textContent('.dr-ev-note .dr-ev-text'), 'Ich habe die Angebote des Studios gefunden.');
    if (shots) await page.screenshot({path: path.join(shots, 'deep-research-activity.png')});
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.dr-sheet').count(), 0, 'Esc closes the sheet first');
    // A citation opens its source.
    await page.click('.dr-article .dr-cite >> nth=1');
    await page.waitForSelector('.dr-src.is-focus');
    assert.equal(await page.getAttribute('.dr-src.is-focus', 'data-n'), '2');
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.dr-viewer').count(), 0, 'Esc closes the view');
    // Download.
    // (A fulfilled route cannot be saved as a real download here, so link, name and content are checked apart.)
    assert.equal(await page.getAttribute(card(8) + ' a.dr-iconbtn', 'download'), 'deep-research-analyse-example-test.md', 'the same file name as the server gives');
    const file = await page.evaluate(href => fetch(href).then(r => r.text()), await page.getAttribute(card(8) + ' a.dr-iconbtn', 'href'));
    assert.match(file, /## Quellen\n\n1\. \[Example Studio/, 'the file has the report and its sources');

    // --- Dark mode ---------------------------------------------------------------------------------------
    const lightBg = await page.$eval(card(8), n => getComputedStyle(n).backgroundColor);
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    assert.notEqual(await page.$eval(card(8), n => getComputedStyle(n).backgroundColor), lightBg, 'dark cards');
    assert.deepEqual(errors, []);
    await context.close();

    // --- A phone ------------------------------------------------------------------------------------------
    ({context, page, errors} = await open(browser, {width: 390, height: 844}));
    await page.evaluate(() => { window.addCard('[[deep-research:7]] Plan.'); window.addCard('[[deep-research:8]] Plan.'); });
    await page.waitForSelector(card(8) + ' .dr-preview h1');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'cards fit a phone');
    if (shots) await page.screenshot({path: path.join(shots, 'deep-research-phone.png'), fullPage: true});
    await page.click(card(8) + ' .dr-preview');
    await page.waitForSelector('.dr-viewer');
    const fit = await page.evaluate(() => ({article: document.querySelector('.dr-article').scrollWidth, view: innerWidth,
      tools: document.querySelector('.dr-tools').getBoundingClientRect().right}));
    assert.ok(fit.article <= fit.view && fit.tools <= fit.view, 'the full view fits: ' + JSON.stringify(fit));
    if (shots) await page.screenshot({path: path.join(shots, 'deep-research-viewer-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI deep research UI: + menu, chip, plan with countdown, Starten, running states, Stopp, Abbrechen, Bearbeiten into the input field, '
    + 'ended run, report card, full view (citations, contents, sources by domain, activity, download, Esc), dark, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
