// CoreAI 0.7.82 (P11 C): the files Mara made in the chat, in a real browser.
//   node scripts/artifact-lifecycle-ui-selftest.cjs
// The real js/coreui-artifacts.js and styles with stand-ins for api/console_artifacts.php,
// api/console_artifact.php (bytes with X-CoreAI-SHA256) and api/files.php (Behalten, Löschen).
// Served as https so the browser checks the checksum itself as well.
// Checked: version and the day a file goes, the preview of a picture and of a text from exactly the
// verified bytes, the download from the preview saves the same bytes, another version or changed
// bytes show no preview, SVG only as text, no preview for an archive, Behalten (with CSRF), Löschen
// with a note in the chat, the note of the retention, guests without buttons, a phone without
// sideways scrolling and the dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const ORIGIN = 'https://coreui.test';

const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
const CSV = Buffer.from('Monat;Umsatz\nJanuar;120\nFebruar;135\n', 'utf8');
const SVG = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>window.SVG_RAN=1</script></svg>', 'utf8');
const ZIP = Buffer.from('PK\u0003\u0004 not really a zip', 'utf8');
const id = n => String(n).repeat(32).slice(0, 32);
const m = {
  manage: true, retention: 30, posts: [], tamperHeader: null, tamperBytes: null,
  files: [
    {id: id(1), name: 'bild.png', mime_type: 'image/png', body: PNG, kept: true, expires_at: null},
    {id: id(2), name: 'umsatz.csv', mime_type: 'text/csv', body: CSV, kept: false, expires_at: '2026-12-17 10:00:00'},
    {id: id(3), name: 'logo.svg', mime_type: 'image/svg+xml', body: SVG, kept: false, expires_at: '2026-12-17 10:00:00'},
    {id: id(4), name: 'archiv.zip', mime_type: 'application/zip', body: ZIP, kept: false, expires_at: '2026-12-17 10:00:00'},
  ],
  notes: [{id: id(5), name: 'alt.txt', mime_type: 'text/plain', size_bytes: 4, sha256: sha('alt\n'), version: sha('alt\n').slice(0, 12),
    partial: false, available: false, deleted: true, deleted_reason: 'retention', deleted_at: '2026-11-17 08:00:00'}],
};
function cards() {
  return m.files.map(f => ({id: f.id, name: f.name, mime_type: f.mime_type, size_bytes: f.body.length, sha256: sha(f.body),
    partial: false, available: true, download_url: 'api/console_artifact.php?id=' + f.id, version: sha(f.body).slice(0, 12),
    kept: f.kept, project: '', created_at: '2026-11-17 10:00:00', expires_at: f.kept ? null : f.expires_at})).concat(m.notes);
}

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css"><link rel="stylesheet" href="/css/artifacts.css">
<style>body{margin:0;padding:16px;box-sizing:border-box}.msg-row,.msg-content,#messagesInner{width:100%;min-width:0;max-width:100%}</style></head>
<body><div id="messagesInner"><div class="msg-row ember" data-message-id="2"><div class="msg-content"><p>Hier sind die Dateien.</p>
<div class="msg-actions"></div></div></div></div><script src="/js/coreui-artifacts.js"></script></body></html>`;

const json = (r, status, body) => r.fulfill({status, contentType: 'application/json', body: JSON.stringify(body)});
async function route(r) {
  const req = r.request();
  const u = new URL(req.url());
  if (u.origin !== ORIGIN) return r.abort();
  if (u.pathname === '/harness.html') return r.fulfill({contentType: 'text/html', body: harness});
  if (u.pathname === '/api/console_artifacts.php') {
    return json(r, 200, {ok: true, schema_ready: true, artifacts: {2: cards()}, retention_days: m.retention, can_manage: m.manage,
      can_keep: m.manage, csrf_token: m.manage ? 'csrf-123' : ''});
  }
  if (u.pathname === '/api/console_artifact.php') {
    const f = m.files.find(x => x.id === u.searchParams.get('id'));
    if (!f) return r.fulfill({status: 404, body: 'artifact_not_found'});
    let body = f.body;
    if (m.tamperBytes === f.id) body = Buffer.from(body.toString('latin1').replace(/1/g, '7'), 'latin1');
    return r.fulfill({status: 200, body, headers: {'Content-Type': 'application/octet-stream',
      'X-CoreAI-SHA256': m.tamperHeader === f.id ? sha('anders') : sha(f.body)}});
  }
  if (u.pathname === '/api/files.php' && req.method() === 'POST') {
    const body = JSON.parse(req.postData() || '{}');
    m.posts.push({action: u.searchParams.get('action'), csrf: req.headers()['x-coreui-csrf'], body});
    if (req.headers()['x-coreui-csrf'] !== 'csrf-123') return json(r, 403, {ok: false, error: 'csrf_failed'});
    const fid = String(body.id || '').slice(2);
    const f = m.files.find(x => x.id === fid);
    if (!f) return json(r, 404, {ok: false, error: 'not_found'});
    if (u.searchParams.get('action') === 'favorite') { f.kept = body.on === true; return json(r, 200, {ok: true, id: body.id, favorite: f.kept}); }
    if (u.searchParams.get('action') === 'delete') {
      m.files = m.files.filter(x => x !== f);
      m.notes.push({id: f.id, name: f.name, mime_type: f.mime_type, size_bytes: f.body.length, sha256: sha(f.body), version: sha(f.body).slice(0, 12),
        partial: false, available: false, deleted: true, deleted_reason: 'person', deleted_at: '2026-11-17 12:00:00'});
      return json(r, 200, {ok: true, id: body.id, freed: f.body.length});
    }
  }
  const file = path.join(root, u.pathname);
  if (/^\/(css|js)\/[a-z0-9-]+\.(css|js)$/.test(u.pathname) && fs.existsSync(file)) {
    return r.fulfill({contentType: u.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
  }
  return r.fulfill({status: 404, body: ''});
}

const card = name => `.msg-artifact:has(.msg-file-name:text-is("${name}"))`;
const text = (pg, sel) => pg.$eval(sel, el => el.textContent);
const count = (pg, sel) => pg.$$eval(sel, els => els.length);
// Playwright selectors (with :text-is) to an element, then the check runs on that element.
async function waitText(pg, sel, re) {
  const handle = await pg.waitForSelector(sel);
  await pg.waitForFunction(([el, source]) => new RegExp(source).test(el.textContent || ''), [handle, re.source]);
}
// The chat draws the message again (history, a new answer): the cards come from the server anew,
// never as copies of the old ones.
async function reload(pg) {
  await pg.evaluate(() => {
    const inner = document.getElementById('messagesInner');
    inner.querySelectorAll('.msg-artifacts').forEach(section => section.remove());
    inner.innerHTML = inner.innerHTML;
  });
  await pg.waitForSelector('.msg-artifacts .msg-artifact');
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({viewport: {width: 1000, height: 900}, acceptDownloads: true});
    await context.route(ORIGIN + '/**', route);
    const pg = await context.newPage();
    const errors = [];
    pg.on('pageerror', e => errors.push(e.message));
    pg.on('dialog', d => d.accept());
    await pg.goto(ORIGIN + '/harness.html');
    await pg.waitForSelector(card('umsatz.csv'));

    // Version and the day a file goes.
    assert.equal(await text(pg, card('umsatz.csv') + ' .msg-artifact-life'),
      'Version ' + sha(CSV).slice(0, 12) + ' · Wird am 17.12.2026 automatisch gelöscht.');
    assert.equal(await text(pg, card('bild.png') + ' .msg-artifact-life'), 'Version ' + sha(PNG).slice(0, 12) + ' · Behalten: wird nicht automatisch gelöscht.');
    assert.equal(await text(pg, '.msg-artifact.is-deleted .msg-artifact-deleted'), '„alt.txt“ wurde am 17.11.2026 gelöscht, weil die Aufbewahrungsfrist abgelaufen war.');
    assert.equal(await pg.evaluate(() => window.isSecureContext && !!window.crypto.subtle), true, 'the browser checks the checksum too');

    // A text preview from the verified bytes; its download saves exactly those bytes.
    await pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    await pg.waitForSelector(card('umsatz.csv') + ' .msg-artifact-view-text');
    assert.equal(await text(pg, card('umsatz.csv') + ' .msg-artifact-view-text'), CSV.toString('utf8'));
    assert.equal(await text(pg, card('umsatz.csv') + ' .msg-artifact-view-head strong'), 'Vorschau · Version ' + sha(CSV).slice(0, 12));
    const [download] = await Promise.all([pg.waitForEvent('download'), pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-download')]);
    assert.equal(download.suggestedFilename(), 'umsatz.csv');
    assert.equal(sha(fs.readFileSync(await download.path())), sha(CSV), 'the download of the preview is the same version');
    await pg.click(card('umsatz.csv') + ' .msg-artifact-view .msg-artifact-action:not(.is-download)');
    assert.equal(await count(pg, card('umsatz.csv') + ' .msg-artifact-view'), 0, 'the preview closes');

    // A picture preview.
    await pg.click(card('bild.png') + ' .msg-artifact-action.is-preview');
    await pg.waitForSelector(card('bild.png') + ' img.msg-artifact-view-image');
    const image = await pg.$(card('bild.png') + ' img.msg-artifact-view-image');
    await pg.waitForFunction(el => el.naturalWidth === 1, image);

    // SVG only as text, never as a picture; no preview for an archive.
    await pg.click(card('logo.svg') + ' .msg-artifact-action.is-preview');
    await pg.waitForSelector(card('logo.svg') + ' .msg-artifact-view-text');
    assert.equal(await count(pg, card('logo.svg') + ' img'), 0);
    assert.equal(await pg.evaluate(() => window.SVG_RAN), undefined, 'the SVG did not run');
    assert.equal(await count(pg, card('archiv.zip') + ' .msg-artifact-action.is-preview'), 0, 'no preview for an archive');

    // Another version (the header) or changed bytes (the browser's own check): no preview.
    m.tamperHeader = id(2);
    await reload(pg);
    await pg.waitForSelector(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    await pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    await waitText(pg, card('umsatz.csv') + ' .msg-artifact-status', /nicht mehr dieselbe Version/);
    assert.equal(await count(pg, card('umsatz.csv') + ' .msg-artifact-view'), 0);
    m.tamperHeader = null;
    m.tamperBytes = id(2);
    await pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    await waitText(pg, card('umsatz.csv') + ' .msg-artifact-status', /Pruefsumme stimmt nicht/);
    assert.equal(await count(pg, card('umsatz.csv') + ' .msg-artifact-view'), 0);
    // The download link refuses changed bytes as well.
    await pg.evaluate(sel => { document.querySelector(sel).textContent = ''; }, '.msg-artifact-status');
    await pg.click(card('umsatz.csv') + ' .msg-artifact-link');
    await waitText(pg, card('umsatz.csv') + ' .msg-artifact-status', /Pruefsumme stimmt nicht/);
    m.tamperBytes = null;

    // Behalten with CSRF; the card follows.
    await pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-keep');
    await pg.waitForSelector(card('umsatz.csv') + ' .msg-artifact-life:has-text("Behalten: wird nicht automatisch gelöscht.")');
    assert.deepEqual(m.posts.pop(), {action: 'favorite', csrf: 'csrf-123', body: {id: 'a:' + id(2), on: true}});
    assert.equal(await text(pg, card('umsatz.csv') + ' .msg-artifact-action.is-keep'), 'Nicht mehr behalten');

    // Löschen: a note stays in the chat.
    await pg.click(card('archiv.zip') + ' .msg-artifact-action.is-delete');
    await pg.waitForFunction(() => [...document.querySelectorAll('.msg-artifact-deleted')].some(p => p.textContent === '„archiv.zip“ wurde am 17.11.2026 gelöscht (von dir).'));
    assert.deepEqual(m.posts.pop(), {action: 'delete', csrf: 'csrf-123', body: {id: 'a:' + id(4)}});
    assert.equal(await count(pg, card('archiv.zip') + ' .msg-artifact-link'), 0, 'the deleted file has no download any more');

    // Phone and dark look.
    await pg.setViewportSize({width: 360, height: 780});
    await pg.click(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    await pg.waitForSelector(card('umsatz.csv') + ' .msg-artifact-view-text');
    const phone = await pg.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert.ok(phone.scroll <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await pg.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await pg.$eval('.msg-artifact-view', el => ({bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color}));
    assert.ok(dark.bg !== dark.color && dark.bg !== 'rgb(251, 253, 255)', 'readable in the dark look: ' + JSON.stringify(dark));
    if (process.env.COREAI_SHOT) await pg.screenshot({path: process.env.COREAI_SHOT, fullPage: true});

    // A guest: previews yes, Behalten and Löschen no.
    m.manage = false;
    await reload(pg);
    await pg.waitForSelector(card('umsatz.csv') + ' .msg-artifact-action.is-preview');
    assert.equal(await count(pg, '.msg-artifact-action.is-keep, .msg-artifact-action.is-delete'), 0, 'no managing for guests');
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
  console.log('CoreAI Maras Dateien UI: Version und Frist, Vorschau von Bild und Text aus den geprueften Bytes, gleicher Download, andere Version und veraenderte Bytes abgelehnt, SVG nur als Text, kein Archiv, Behalten mit CSRF, Loeschen mit Hinweis, Hinweis der Aufbewahrung, Gaeste ohne Knoepfe, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI artifact lifecycle UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
