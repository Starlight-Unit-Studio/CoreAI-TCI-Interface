// CoreAI 0.6.41 (P20.5): the image card while Mara paints, in a real browser (Playwright).
//   node scripts/image-progress-ui-selftest.cjs
// js/coreui-image-progress.js on a page with the chat's stylesheets and a live answer row
// as js/console-app.js builds it. Checked: the events it reads (and ignores); the stage
// texts and percent ranges; the percent eases on but never past a stage and never back; the
// card sits in the live answer instead of the typing dots; the dots move (and stand still
// with reduced motion); the finished image fades in from the owner-only endpoint; a failed
// image says so; a rebuilt row gets the card back; a phone shows it without sideways
// scrolling; and the wiring in the chat, the app page and the work store.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const UUID = 'cd'.repeat(16);

const read = f => fs.readFileSync(path.join(root, f), 'utf8');
const app = read('js/console-app.js');
assert(app.includes('if (event && state.typingEl && window.CoreAIImageProgress) window.CoreAIImageProgress.observe(state.typingEl, event);'),
  'live work events reach the image card');
assert(app.includes('window.CoreAIImageProgress.replay(row, bucket.events);'), 'a rebuilt answer row gets the card back');
assert(app.includes("es.addEventListener('image_preview', function(ev) {")
  && app.includes("window.CoreAIImageProgress.preview(state.typingEl, String(shot.image || ''));"), '0.6.42: step previews reach the card');
assert(/<script src="\.\/js\/coreui-image-progress\.js\?v=\d+"><\/script>\n  <script src="\.\/js\/coreui-work-events\.js/.test(read('app.html')) === false
  && /coreui-work-events\.js\?v=\d+"><\/script>\n  <script src="\.\/js\/coreui-image-progress\.js\?v=\d+"><\/script>/.test(read('app.html')),
  'app.html loads the module after the work view');
const store = read('api/console_work_store.php');
assert(store.includes("'steps','image_px'];") && store.includes("preg_match('/^[a-f0-9]{32}$/D', $raw['image_uuid'])"),
  'the work store keeps steps, width and the image id');
const chat = read('api/chat.php');
assert(chat.includes("coreui_work_record($pdo, 'tool', 'info', coreui_image_progress_title($job), coreui_image_progress_meta($job));")
  && chat.includes("'image_uuid'=>(string)$result['uuid'], 'image_px'=>(int)$result['width']]);"), 'progress and the finished image are announced');

// 0.6.42: a preview image, pixel by pixel.
function pngOf(width, height, fn) {
  const chunk = (kind, data) => {
    const head = Buffer.alloc(4); head.writeUInt32BE(data.length);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(zlib.crc32 ? zlib.crc32(Buffer.concat([Buffer.from(kind), data])) >>> 0 : 0);
    return Buffer.concat([head, Buffer.from(kind), data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  const rows = [];
  for (let y = 0; y < height; y++) {
    const row = Buffer.alloc(1 + width * 3);
    for (let x = 0; x < width; x++) { const c = fn(x, y); row[1 + x * 3] = c[0]; row[2 + x * 3] = c[1]; row[3 + x * 3] = c[2]; }
    rows.push(row);
  }
  return 'data:image/png;base64,' + Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(Buffer.concat(rows))), chunk('IEND', Buffer.alloc(0))]).toString('base64');
}

function png(width, height) {
  const chunk = (kind, data) => {
    const head = Buffer.alloc(4); head.writeUInt32BE(data.length);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(zlib.crc32 ? zlib.crc32(Buffer.concat([Buffer.from(kind), data])) >>> 0 : 0);
    return Buffer.concat([head, Buffer.from(kind), data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(width * 3, 0xb0)]);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(Buffer.concat(Array.from({length: height}, () => row)))), chunk('IEND', Buffer.alloc(0))]);
}

const ROW = id => `<div class="msg-row ember" id="${id}"><div class="msg-avatar"></div><div class="msg-content"><div class="msg-name">MARA</div>
<div class="work-panel">Arbeitsweg</div><button type="button" class="agent-stop-button" hidden>stop</button>
<div class="msg-bubble"><div class="typing-dots"><span></span><span></span><span></span></div></div></div></div>`;
const HARNESS = theme => `<!doctype html><html lang="de" data-coreui-theme="${theme}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>t</title>
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css"></head>
<body><main class="messages"><div class="messages-inner" id="rows">${ROW('row1')}</div></main>
<script src="/js/coreui-image-progress.js"></script></body></html>`;

const ev = (title, meta = {}, state = 'info') => ({kind: 'tool', state, title, meta});
const progress = (reason, step = 0, px = 512) => ev('Bild: x', {source: 'image', reason, step, steps: 4, image_px: px});

async function open(browser, options = {}) {
  const page = await browser.newPage({viewport: options.viewport || {width: 900, height: 1100}, reducedMotion: options.reduced ? 'reduce' : 'no-preference'});
  const errors = [];
  const media = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://localhost:8767/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS(options.theme || 'dark')});
    if (url.pathname === '/api/console_media.php') { media.push(url.searchParams.get('uuid')); return route.fulfill({contentType: 'image/png', body: png(512, 512)}); }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8767/harness.html');
  return {page, errors, media};
}
const observe = (page, event, row = 'row1') => page.evaluate(([e, r]) => { window.CoreAIImageProgress.observe(document.getElementById(r), e); }, [event, row]);
const card = (page, row = 'row1') => page.evaluate(r => {
  const c = document.querySelector('#' + r + ' .img-progress');
  if (!c) return null;
  const bubble = document.querySelector('#' + r + ' .msg-bubble');
  return {stage: c.querySelector('.img-progress-stage').textContent, pct: parseInt(c.querySelector('.img-progress-pct').textContent, 10),
    cls: c.className, bubble: getComputedStyle(bubble).display, before: c.nextElementSibling && c.nextElementSibling.className,
    after: c.previousElementSibling && c.previousElementSibling.className};
}, row);
const dots = page => page.evaluate(() => {
  const c = document.querySelector('.img-progress-dots');
  const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
  let lit = 0, sum = 0;
  for (let i = 3; i < d.length; i += 4) { if (d[i] > 0) lit++; sum = (sum + d[i] * (i % 9973)) % 1000000007; }
  return {lit, sum};
});

(async () => {
  const browser = await chromium.launch();
  try {
    const {page, errors, media} = await open(browser);
    // --- pure parts ---
    const pure = await page.evaluate(() => {
      const P = window.CoreAIImageProgress;
      return {
        start: P._read({kind: 'tool', state: 'running', title: 'Schritt 2 von 6: Bild', meta: {}}),
        notStart: P._read({kind: 'tool', state: 'running', title: 'Schritt 2 von 6: Websuche', meta: {}}),
        other: P._read({kind: 'tool', state: 'info', title: 'Websuche gestartet', meta: {source: 'web'}}),
        sampling: P._read({kind: 'tool', state: 'info', title: 'Bild: Schritt 2 von 4', meta: {source: 'image', reason: 'image_sampling', step: 1, steps: 4, image_px: 1024}}),
        done: P._read({kind: 'artifact', state: 'success', title: 'Bild erzeugt', meta: {source: 'image', image_uuid: 'ab'.repeat(16), image_px: 512}}),
        badDone: P._read({kind: 'artifact', state: 'success', title: 'Bild erzeugt', meta: {image_uuid: '../x'}}),
        failed: P._read({kind: 'tool', state: 'error', title: 'Bild nicht erzeugt', meta: {reason: 'image_timeout'}}),
        texts: [0, 1, 2, 3].map(s => P._band({stage: 'sampling', step: s, steps: 4}).text),
        bands: ['start', 'load', 'text', 'decode', 'done'].map(s => [P._band({stage: s}).text, P._band({stage: s}).low, P._band({stage: s}).high]),
        s1: [0, 2, 6, 13.5, 60, 600].map(t => P._percent({stage: 'sampling', step: 1, steps: 4, px: 512}, t)),
        big: P._percent({stage: 'sampling', step: 1, steps: 4, px: 1024}, 13.5),
      };
    });
    assert.deepEqual(pure.start, {stage: 'start'});
    assert.equal(pure.notStart, null);
    assert.equal(pure.other, null);
    assert.deepEqual(pure.sampling, {stage: 'sampling', step: 1, steps: 4, px: 1024});
    assert.deepEqual(pure.done, {stage: 'done', uuid: 'ab'.repeat(16), px: 512});
    assert.equal(pure.badDone.uuid, '', 'a bad image id is never used');
    assert.deepEqual(pure.failed, {stage: 'failed'});
    assert.deepEqual(pure.texts, ['Erste Skizze entsteht', 'Die Szene nimmt Gestalt an', 'Details werden verfeinert', 'Letzter Feinschliff']);
    assert.deepEqual(pure.bands, [['Bild wird vorbereitet', 0, 4], ['Bild wird vorbereitet', 4, 9], ['Beschreibung wird gelesen', 9, 15],
      ['Fast fertig', 90, 98], ['Fertig', 100, 100]]);
    assert.equal(pure.s1[0], 33.75, 'step 2 of 4 starts where step 1 ended');
    for (let i = 1; i < pure.s1.length; i++) assert(pure.s1[i] > pure.s1[i - 1] && pure.s1[i] < 51.5 + 1e-9, 'eases on, never past the stage: ' + pure.s1);
    assert(pure.s1[3] > 48 && pure.big < pure.s1[3], 'about where the step should end after its measured time; 1024 is slower');

    // --- the card in a live answer ---
    await observe(page, ev('Schritt 1 von 6: Bild', {reason: 'agent_loop'}, 'running'));
    let c = await card(page);
    assert.equal(c.stage, 'Bild wird vorbereitet');
    assert(c.pct <= 4, 'starts low: ' + c.pct);
    assert.equal(c.bubble, 'none', 'the card replaces the typing dots');
    assert.equal(c.after, 'work-panel', 'under the work view');
    assert.equal(c.before, 'agent-stop-button', 'above the stop button');
    const seen = [c.pct];
    for (const [event, stage, low] of [[progress('image_load'), 'Bild wird vorbereitet', 4], [progress('image_text'), 'Beschreibung wird gelesen', 9],
      [progress('image_sampling', 0), 'Erste Skizze entsteht', 15], [progress('image_sampling', 1), 'Die Szene nimmt Gestalt an', 33],
      [progress('image_sampling', 2), 'Details werden verfeinert', 52], [progress('image_sampling', 3), 'Letzter Feinschliff', 71],
      [progress('image_decode', 4), 'Fast fertig', 90]]) {
      await observe(page, event);
      await page.waitForTimeout(250);
      c = await card(page);
      assert.equal(c.stage, stage);
      assert(c.pct >= low && c.pct >= seen[seen.length - 1], `${stage}: ${c.pct} after ${seen}`);
      seen.push(c.pct);
    }
    await observe(page, progress('image_sampling', 1));
    await page.waitForTimeout(150);
    c = await card(page);
    assert(c.pct >= seen[seen.length - 1] && c.stage === 'Fast fertig', 'never back, even with a late event: ' + JSON.stringify(c));
    const a = await dots(page);
    await page.waitForTimeout(350);
    const b = await dots(page);
    assert(a.lit > 100 && a.sum !== b.sum, 'the dots are drawn and move');
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-dark.png'), clip: {x: 0, y: 0, width: 520, height: 560}});
    await observe(page, ev('Bild erzeugt', {source: 'image', image_uuid: UUID, image_px: 512}, 'success'));
    await page.waitForFunction(() => document.querySelector('.img-progress').classList.contains('is-revealed'));
    c = await card(page);
    assert.equal(c.stage, 'Fertig');
    assert.equal(c.pct, 100);
    assert(c.cls.includes('is-done') && c.bubble !== 'none', 'done: the typing dots come back while Mara writes');
    assert.deepEqual(media, [UUID], 'the image comes from the owner-only endpoint');
    assert.equal(await page.getAttribute('.img-progress-picture', 'src'), './api/console_media.php?uuid=' + UUID);

    // 0.6.42: step previews colour the dots: the real image comes into being in the grid.
    await page.evaluate(html => document.getElementById('rows').insertAdjacentHTML('beforeend', html), ROW('row4'));
    await observe(page, ev('Schritt 1 von 6: Bild', {}, 'running'), 'row4');
    await observe(page, progress('image_sampling', 1), 'row4');
    const dotColor = (fx, fy) => page.evaluate(([x, y]) => {
      const c = document.querySelector('#row4 .img-progress-dots');
      const px = c.getContext('2d').getImageData(Math.floor(c.width * x), Math.floor(c.height * y), 1, 1).data;
      return [px[0], px[1], px[2], px[3]];
    }, [fx, fy]);
    const cell = 1 / 30;
    await page.evaluate(() => window.CoreAIImageProgress.preview(document.getElementById('row4'), 'javascript:alert(1)'));
    assert.equal(await page.getAttribute('#row4 .img-progress', 'data-previews'), null, 'only PNG data URLs are taken');
    await page.evaluate(url => window.CoreAIImageProgress.preview(document.getElementById('row4'), url),
      pngOf(64, 64, x => (x < 32 ? [220, 40, 40] : [40, 60, 230])));
    await page.waitForFunction(() => document.querySelector('#row4 .img-progress').getAttribute('data-previews') === '1');
    await page.waitForTimeout(1100);
    const left = await dotColor(cell * 5.5, cell * 15.5);
    const right = await dotColor(cell * 24.5, cell * 15.5);
    assert(left[0] > 150 && left[2] < 100 && right[2] > 150 && right[0] < 100, 'the dots take the colours of the preview: ' + left + ' / ' + right);
    await page.evaluate(url => window.CoreAIImageProgress.preview(document.getElementById('row4'), url), pngOf(64, 64, () => [40, 200, 70]));
    await page.waitForFunction(() => document.querySelector('#row4 .img-progress').getAttribute('data-previews') === '2');
    const between = await dotColor(cell * 5.5, cell * 15.5);
    await page.waitForTimeout(1100);
    const green = await dotColor(cell * 5.5, cell * 15.5);
    assert(green[1] > 150 && green[0] < 100 && between[0] > green[0], 'the next step blends over: ' + between + ' -> ' + green);
    assert(await page.evaluate(() => document.querySelector('#row4 .img-progress').classList.contains('has-preview')));
    if (process.env.COREAI_SHOT) {
      await page.evaluate(url => window.CoreAIImageProgress.preview(document.getElementById('row4'), url), pngOf(64, 64, (x, y) => {
        const sky = y < 40, sun = (x - 44) * (x - 44) + (y - 22) * (y - 22) < 60, hill = y > 38 + 6 * Math.sin(x / 9);
        if (sun) return [255, 214, 120];
        if (hill) return [40 + y, 90 + y, 50];
        return sky ? [70 + y * 2, 120 + y * 2, 220] : [40, 80, 60];
      }));
      await page.waitForTimeout(1100);
      const box = await page.evaluate(() => { const r = document.querySelector('#row4 .img-progress').getBoundingClientRect(); return {x: r.x, y: r.y, w: r.width, h: r.height}; });
      await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-preview.png'), clip: {x: Math.max(0, box.x - 10), y: Math.max(0, box.y - 10), width: box.w + 20, height: box.h + 20}});
    }

    // A failed image, and a rebuilt row.
    await page.evaluate(html => document.getElementById('rows').insertAdjacentHTML('beforeend', html), ROW('row2'));
    await observe(page, ev('Bild nicht erzeugt', {reason: 'image_busy'}, 'error'), 'row2');
    assert.equal(await card(page, 'row2'), null, 'no card for a failure without a start');
    await observe(page, ev('Schritt 1 von 6: Bild', {}, 'running'), 'row2');
    await observe(page, progress('image_sampling', 1), 'row2');
    await observe(page, ev('Bild nicht erzeugt', {reason: 'image_timeout'}, 'error'), 'row2');
    c = await card(page, 'row2');
    assert(c.stage === 'Kein Bild entstanden' && c.cls.includes('is-failed') && c.bubble !== 'none', 'a failed image says so');
    await page.evaluate(html => document.getElementById('rows').insertAdjacentHTML('beforeend', html), ROW('row3'));
    await page.evaluate(events => window.CoreAIImageProgress.replay(document.getElementById('row3'), events),
      [ev('Websuche gestartet', {source: 'web'}), ev('Schritt 1 von 6: Bild', {}, 'running'), progress('image_sampling', 2)]);
    c = await card(page, 'row3');
    assert(c.stage === 'Details werden verfeinert' && c.pct >= 52, 'a rebuilt row gets the card back');
    assert.deepEqual(errors, []);
    await page.close();

    // Reduced motion: the dots stand still.
    const still = await open(browser, {reduced: true, theme: 'light'});
    await observe(still.page, ev('Schritt 1 von 6: Bild', {}, 'running'));
    await observe(still.page, progress('image_sampling', 1));
    const s1 = await dots(still.page);
    await still.page.waitForTimeout(400);
    const s2 = await dots(still.page);
    assert(s1.lit > 50 && s1.sum === s2.sum, 'reduced motion: no animation');
    if (process.env.COREAI_SHOT) await still.page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-light.png'), clip: {x: 0, y: 0, width: 520, height: 560}});
    await still.page.close();

    // A phone.
    const phone = await open(browser, {viewport: {width: 390, height: 844}});
    await observe(phone.page, ev('Schritt 1 von 6: Bild', {}, 'running'));
    const fit = await phone.page.evaluate(() => ({w: document.querySelector('.img-progress').getBoundingClientRect().width,
      scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert(fit.w <= fit.view && fit.scroll <= fit.view, 'fits a phone: ' + JSON.stringify(fit));
    const batch = await phone.page.evaluate(() => {
      const P = window.CoreAIImageProgress, row = document.getElementById('row1');
      const events = [];
      for (let i = 1; i <= 4; i++) {
        events.push({id:i*2, title:'Bild wird vorbereitet', meta:{source:'image', reason:'image_start'}});
        P.observe(row, events[events.length-1]);
        const card = row.querySelector('.img-progress');
        if (card._p.shown >= 5 || card.querySelector('.img-progress-picture')) throw new Error('New image did not reset');
        events.push({id:i*2+1, title:'Bild erzeugt', meta:{image_uuid:String(i).repeat(32), image_px:512}});
        P.observe(row, events[events.length-1]);
      }
      P.replay(row, events); // replaying the same event IDs must not reset current work
      const card = row.querySelector('.img-progress');
      const thumbs = card.querySelector('.img-progress-thumbs').getBoundingClientRect();
      const frame = card.querySelector('.img-progress-frame').getBoundingClientRect();
      return {count:card.querySelectorAll('.img-progress-thumb').length, percent:card._p.shown,
        left:thumbs.right <= frame.left, fits:document.documentElement.scrollWidth <= innerWidth};
    });
    assert.deepEqual(batch, {count:4, percent:100, left:true, fits:true}, 'four thumbnails on the left and no stale replay/reset');
    await phone.page.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI image progress UI: events, stage texts, percent eases on but never past a stage or back, card instead of the typing dots, '
    + 'moving dots (still with reduced motion), the image fades in, failure, rebuilt row, phone width OK');
})().catch(error => { console.error('CoreAI image progress UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
