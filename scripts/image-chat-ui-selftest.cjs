// CoreAI 0.6.39 (P20.5 C): Mara's images in the chat, in a real browser (Playwright).
//   node scripts/image-chat-ui-selftest.cjs
// The real buildAttachmentEl of js/console-app.js, cut out and run on a page with the
// chat's stylesheet. Checked: an image Mara made shows as a figure whose picture opens in
// full size in a new tab and whose line under it offers the download; it loads from the
// owner-only endpoint (console_media.php), fits a phone screen without sideways scrolling;
// an uploaded image keeps its plain look; the stream's final answer carries the images, so
// they show at once and not only after a reload.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const UUID = 'ab'.repeat(16);

const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const begin = app.indexOf('  // Anhang-Element bauen (v1.1.1.89)');
const end = app.indexOf('  // Aufklappbares, sicheres Denkstatus-Panel bauen');
assert(begin > 0 && end > begin, 'buildAttachmentEl missing in console-app.js');
const builder = app.slice(begin, end);

// The final answer of the stream keeps the images of the answer.
assert(app.includes("attachments: data && Array.isArray(data.attachments) && data.attachments.length ? data.attachments : null,"),
  'finalizeStream keeps the images of the answer');
assert(app.includes("appendMessageEl('ember', text, state.assistantName, m.thinking_content || null, m.attachments || m.attachment || null, m.created_at, m);"),
  'Mara\'s answers render their attachments');

function png(width, height) {
  const chunk = (kind, data) => {
    const head = Buffer.alloc(4); head.writeUInt32BE(data.length);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(zlib.crc32 ? zlib.crc32(Buffer.concat([Buffer.from(kind), data])) >>> 0 : 0);
    return Buffer.concat([head, Buffer.from(kind), data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(width * 3, 0x7a)]);
  const raw = Buffer.concat(Array.from({length: height}, () => row));
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>t</title><link rel="stylesheet" href="/css/console.css"></head><body>
<div class="msg-row ember"><div class="msg-content" id="mara"></div></div>
<div class="msg-row user"><div class="msg-content" id="user"></div></div>
<script>
  var state = { assistantName: 'Nova' };
  var API_BASE = './api';
  var KIND_ICON = {};
  function formatBytes(n) { return String(n) + ' B'; }
${builder}
  window.render = function (target, att) { document.getElementById(target).appendChild(buildAttachmentEl(att)); };
</script></body></html>`;

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    const errors = [];
    const media = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://localhost:8766/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
      if (url.pathname === '/api/console_media.php') {
        media.push(url.searchParams.get('uuid'));
        return route.fulfill({contentType: 'image/png', body: png(512, 512)});
      }
      if (url.pathname === '/assets/chat_media/up.png') return route.fulfill({contentType: 'image/png', body: png(64, 64)});
      const file = path.join(root, url.pathname);
      if (fs.existsSync(file)) return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
      return route.fulfill({status: 404, body: ''});
    });
    await page.goto('http://localhost:8766/harness.html');

    await page.evaluate(([uuid]) => window.render('mara', {uuid, kind: 'image', name: 'Mara-Bild-20261107-1213.png', mime: 'image/png', size: 4096,
      url: './api/console_media.php?uuid=' + uuid, image_url: null, generated: true}), [UUID]);
    await page.waitForFunction(() => { const i = document.querySelector('#mara img'); return i && i.complete && i.naturalWidth === 512; });
    const made = await page.evaluate(() => {
      const fig = document.querySelector('#mara figure.msg-generated');
      const link = fig.querySelector('a.msg-image-link');
      const img = link.querySelector('img.msg-image');
      const cap = fig.querySelector('figcaption.msg-generated-caption');
      const save = cap.querySelector('a');
      return {link: link.getAttribute('href'), target: link.target, rel: link.rel, label: link.getAttribute('aria-label'),
        alt: img.alt, caption: cap.textContent, download: save.getAttribute('download'), saveHref: save.getAttribute('href'),
        figWidth: fig.getBoundingClientRect().width, imgWidth: img.getBoundingClientRect().width, cursor: getComputedStyle(img).cursor,
        scroll: document.documentElement.scrollWidth, view: window.innerWidth};
    });
    assert.equal(made.link, './api/console_media.php?uuid=' + UUID, 'opens the owner-only image');
    assert.equal(made.target, '_blank');
    assert.equal(made.rel, 'noopener');
    assert.equal(made.label, 'Bild in voller Größe öffnen');
    assert.equal(made.alt, 'Bild von TCI: Nova');
    assert.equal(made.caption, 'Herunterladen');
    assert.equal(made.download, 'Mara-Bild-20261107-1213.png');
    assert.equal(made.saveHref, made.link);
    assert.equal(made.cursor, 'zoom-in');
    assert(made.figWidth <= made.view && Math.abs(made.imgWidth - made.figWidth) < 2, 'the image fills its figure: ' + JSON.stringify(made));
    assert(made.scroll <= made.view, 'no sideways scrolling on a phone: ' + JSON.stringify(made));
    assert.deepEqual(media, [UUID], 'loaded from console_media.php only');

    await page.evaluate(() => window.render('user', {uuid: 'cd'.repeat(16), kind: 'image', name: 'urlaub.png', url: './api/console_media.php?uuid=x',
      image_url: '/assets/chat_media/up.png'}));
    const upload = await page.evaluate(() => {
      const box = document.getElementById('user');
      return {figures: box.querySelectorAll('figure').length, img: box.firstElementChild.tagName, cls: box.firstElementChild.className,
        src: box.firstElementChild.getAttribute('src')};
    });
    assert.deepEqual(upload, {figures: 0, img: 'IMG', cls: 'msg-image', src: '/assets/chat_media/up.png'}, 'an upload keeps its plain look');
    assert.deepEqual(errors, []);
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
  } finally {
    await browser.close();
  }
  console.log('CoreAI image chat UI: Mara\'s image opens in full size, can be saved, fits a phone, uploads unchanged, stream keeps it OK');
})().catch(error => { console.error('CoreAI image chat UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
