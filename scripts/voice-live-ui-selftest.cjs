// CoreAI 0.6.31 (P20.4): the live conversation in a real browser (Playwright), js/coreui-voice-live.js.
//   node scripts/voice-live-ui-selftest.cjs
// The input row with the real stylesheets, the microphone script and the live script,
// a stand-in for the chat (js/console-app.js) and mocked APIs. Chromium hears a fake
// microphone that loops pauses and a tone. Checked: the live button sits after the
// microphone, never for guests, switched off with the reason for a Core without audio; it
// opens the full-screen view with the greeting and the Core; the opening is warmed with the
// chat's session; a pause after speaking sends the round as a WAV Stimmsequenz; what was
// heard shows; every sentence goes to Eve in order and plays; afterwards I listen again;
// muting stops sending; speaking while I talk interrupts me; X ends, hands the rounds to the
// memory step and reloads the chat; without an open chat it says so. Night and day, phone
// width, no text below 11 px, buttons at least 44 px.
// 0.6.32: the live button sits in the send button's place while the input row is empty (the
// real updateComposerEmpty from js/console-app.js decides) and send comes back with text or an
// attachment; Eve's parts are fetched ahead while the part before plays.
// 0.6.34: the live button pulses gently (not with reduced motion), the send button never.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');

const css = ['css/console.css', 'css/coreui-theme.css'].map(f => `<link rel="stylesheet" href="/${f}">`).join('');
// The real switch between send and live from js/console-app.js, run with stand-ins.
const appSource = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const emptyFrom = appSource.indexOf('  function updateComposerEmpty() {');
const emptyTo = appSource.indexOf('\n  }\n', emptyFrom);
assert.ok(emptyFrom > 0 && emptyTo > emptyFrom, 'updateComposerEmpty missing in console-app.js');
const updateComposerEmpty = appSource.slice(emptyFrom, emptyTo + 4);
const page = (theme, context) => `<!doctype html><html lang="de" data-coreui-theme="${theme}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">${css}<title>t</title></head><body>
<footer class="composer"><div class="composer-frame"><div class="composer-inner">
<button id="btnAttach" type="button">A</button>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label>
<button id="btnSend" class="composer-send" type="button">S</button></div></div></footer>
<script>
  var inputEl = document.getElementById('inputMessage');
  var state = { editingMessage: null };
  var attachments = 0;
  function attachmentSlotsUsed() { return attachments; }
${updateComposerEmpty}
  inputEl.addEventListener('input', updateComposerEmpty);
  updateComposerEmpty();
  window.__setAttachments = function (n) { attachments = n; updateComposerEmpty(); };
  window.__reloads = 0;
  window.CoreUIComposer = { sendVoice: function () { return 'ok'; },
    liveContext: function () { return ${context === null ? 'null' : JSON.stringify(context)}; },
    reload: function () { window.__reloads++; return Promise.resolve(); } };
  window.__states = [];
  new MutationObserver(function () {
    var v = document.querySelector('.live-overlay');
    var s = v && v.getAttribute('data-state');
    if (s && window.__states[window.__states.length - 1] !== s) window.__states.push(s);
  }).observe(document.documentElement, { subtree: true, attributes: true, childList: true, attributeFilter: ['data-state'] });
</script>
<script src="/js/coreui-voice-input.js"></script><script src="/js/coreui-voice-live.js"></script></body></html>`;

// 16 kHz mono WAV: pauses and a 440 Hz tone (the "speech"), looped by Chromium.
function wav(parts) {
  const total = parts.reduce((n, p) => n + Math.round(16000 * p[0]), 0);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + total * 2, 4); head.write('WAVE', 8); head.write('fmt ', 12); head.writeUInt32LE(16, 16);
  head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24); head.writeUInt32LE(32000, 28);
  head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(total * 2, 40);
  const data = Buffer.alloc(total * 2);
  let i = 0;
  for (const [seconds, tone] of parts) {
    const n = Math.round(16000 * seconds);
    for (let k = 0; k < n; k++, i++) if (tone) data.writeInt16LE(Math.round(12000 * Math.sin(2 * Math.PI * 440 * k / 16000)), i * 2);
  }
  return Buffer.concat([head, data]);
}

async function until(page, fn, arg, timeout = 15000) {
  await page.waitForFunction(fn, arg, {timeout});
}

async function openPage(browser, opts = {}) {
  const calls = {status: 0, warm: [], upload: [], turn: [], say: [], audio: 0, audioAt: [], end: []};
  const theme = opts.theme || 'dark';
  const context = opts.context === undefined ? {sessionId: 'cs_live', characterId: 'rick', lastMessageId: 41, busy: false} : opts.context;
  const pageObj = await browser.newPage({viewport: opts.viewport || {width: 1280, height: 860}});
  const errors = [];
  pageObj.on('pageerror', e => errors.push(e.message));
  await pageObj.route('http://localhost:8771/**', async route => {
    const url = new URL(route.request().url());
    const action = url.searchParams.get('action');
    if (url.pathname === '/index.html') return route.fulfill({contentType: 'text/html', body: page(theme, context)});
    if (url.pathname === '/api/voice_input.php') {
      return route.fulfill({json: {ok: true, allowed: !opts.guest, hears: true, ready: true, max_seconds: 120, csrf_token: 'tok'}});
    }
    if (url.pathname === '/api/voice_live.php' && action === 'status') {
      calls.status++;
      if (opts.guest) return route.fulfill({json: {ok: true, allowed: false}});
      return route.fulfill({json: {ok: true, allowed: true, core: 'ember-coreui:latest', core_label: 'TCI: Mara Normal',
        hears: opts.hears === undefined ? true : opts.hears, ready: true, voice: {available: true, loading: false}, name: 'Rick', csrf_token: 'tok-live'}});
    }
    if (url.pathname === '/api/voice_live.php' && action === 'warm') {
      calls.warm.push({body: JSON.parse(route.request().postData() || '{}'), csrf: route.request().headers()['x-coreui-csrf']});
      return route.fulfill({json: {ok: true, seconds: 0.4}});
    }
    if (url.pathname === '/api/console_upload.php') {
      const body = route.request().postDataBuffer() || Buffer.alloc(0);
      const text = body.toString('latin1');
      const name = (text.match(/filename="([^"]+)"/) || [])[1] || '';
      calls.upload.push({name, riff: text.includes('RIFF') && text.includes('WAVEfmt '), audioWav: text.includes('Content-Type: audio/wav'),
        character: text.includes('name="character_id"\r\n\r\nrick'), csrf: route.request().headers()['x-coreui-csrf']});
      return route.fulfill({json: {ok: true, uuid: String(calls.upload.length).padStart(32, 'a'), kind: 'audio'}});
    }
    if (url.pathname === '/api/voice_live.php' && action === 'turn') {
      const body = JSON.parse(route.request().postData() || '{}');
      calls.turn.push(body);
      const n = calls.turn.length;
      const ev = (e, d) => `event: ${e}\ndata: ${JSON.stringify(d)}\n\n`;
      return route.fulfill({contentType: 'text/event-stream', body: ev('start', {core: 'ember-coreui:latest'})
        + ev('heard', {text: n === 1 ? 'Wie wird das Wetter morgen?' : 'Danke dir.', user_message_id: 10 * n + 1})
        + ev('sentence', {index: 0, text: n === 1 ? 'Morgen wird es trocken und mild.' : 'Sehr gern, Rick.'})
        + (n === 1 ? ev('sentence', {index: 1, text: 'Ich schaue später gern noch genauer nach!'}) : '')
        + ev('done', {user_message_id: 10 * n + 1, reply_id: 10 * n + 2, interrupted: false})});
    }
    if (url.pathname === '/api/voice_live.php' && action === 'say') {
      calls.say.push(JSON.parse(route.request().postData() || '{}').text);
      return route.fulfill({json: {ok: true, id: 'plan' + calls.say.length, parts: 1}});
    }
    if (url.pathname === '/api/voice.php' && action === 'audio') {
      calls.audio++;
      calls.audioAt.push(Date.now());
      return route.fulfill({contentType: 'audio/wav', body: wav([[opts.voiceSeconds || 0.4, false]])});
    }
    if (url.pathname === '/api/voice_live.php' && action === 'end') {
      calls.end.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({json: {ok: true, reflected: 1}});
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file) && fs.statSync(file).isFile()) {
      return route.fulfill({contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
    }
    return route.fulfill({status: 404, body: ''});
  });
  await pageObj.goto('http://localhost:8771/index.html');
  return {page: pageObj, calls, errors};
}

const smallText = page => page.evaluate(() => {
  const out = [];
  document.querySelectorAll('.live-overlay *').forEach(node => {
    const own = Array.from(node.childNodes).some(c => c.nodeType === 3 && c.textContent.trim());
    if (!own || !node.offsetParent) return;
    const size = parseFloat(getComputedStyle(node).fontSize);
    if (size < 11) out.push(node.className + ' ' + size);
  });
  return out;
});

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coreai-live-ui-'));
  const mic = path.join(dir, 'mic.wav');
  // 2 s pause, 1.2 s "speech", 2.5 s pause: one round about every 6 s.
  fs.writeFileSync(mic, wav([[2, false], [1.2, true], [2.5, false]]));
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + mic,
      '--autoplay-policy=no-user-gesture-required']});
  try {
    for (const theme of ['dark', 'light']) {
      const {page, calls, errors} = await openPage(browser, {theme});
      await until(page, () => document.getElementById('btnLive') && document.getElementById('btnMic'));
      const order = await page.evaluate(() => [...document.querySelector('.composer-inner').children].map(b => b.id || b.className));
      assert.deepEqual(order.slice(-3), ['btnMic', 'btnSend', 'btnLive'], theme + ': microphone, send, live: ' + order);
      // Empty row: live in the send button's place; text or an attachment: send; empty again: live.
      const shown = () => page.evaluate(() => ['btnMic', 'btnSend', 'btnLive'].filter(id => {
        const el = document.getElementById(id); return el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0; }));
      assert.deepEqual(await shown(), ['btnMic', 'btnLive'], theme + ': empty row: microphone and live');
      const slot = await page.evaluate(() => { const r = document.getElementById('btnLive').getBoundingClientRect(); const c = document.querySelector('.composer-inner').getBoundingClientRect(); return c.right - r.right; });
      assert.ok(slot < 2, theme + ': live sits at the end of the row, where send was: ' + slot);
      await page.fill('#inputMessage', 'Hallo Mara');
      assert.deepEqual(await shown(), ['btnMic', 'btnSend'], theme + ': with text: send');
      await page.fill('#inputMessage', '   ');
      assert.deepEqual(await shown(), ['btnMic', 'btnLive'], theme + ': only spaces count as empty');
      await page.evaluate(() => window.__setAttachments(1));
      assert.deepEqual(await shown(), ['btnMic', 'btnSend'], theme + ': an attachment: send');
      await page.evaluate(() => window.__setAttachments(0));
      await page.fill('#inputMessage', '');
      assert.deepEqual(await shown(), ['btnMic', 'btnLive'], theme + ': emptied again: live');
      // 0.6.34: the live button pulses gently, the send button never.
      const pulse = id => page.evaluate(id => [document.getElementById(id), ...document.querySelectorAll('#' + id + ' *')]
        .map(el => getComputedStyle(el).animationName).filter(n => n !== 'none'), id);
      assert.deepEqual(await pulse('btnLive'), ['composer-live-pulse'], theme + ': the live button pulses');
      await page.fill('#inputMessage', 'Hallo');
      assert.deepEqual(await pulse('btnSend'), [], theme + ': the send button does not pulse');
      await page.fill('#inputMessage', '');
      await page.emulateMedia({reducedMotion: 'reduce'});
      assert.deepEqual(await pulse('btnLive'), [], theme + ': no pulse with reduced motion');
      await page.emulateMedia({reducedMotion: 'no-preference'});
      assert.equal(await page.getAttribute('#btnLive', 'aria-label'), 'Live-Gespräch mit Mara');
      const btn = await page.evaluate(() => { const r = document.getElementById('btnLive').getBoundingClientRect(); return {w: r.width, h: r.height}; });
      assert.ok(btn.w >= 40 && btn.h >= 44, theme + ': the live button is large enough to hit: ' + JSON.stringify(btn));

      await page.click('#btnLive');
      await page.waitForSelector('.live-overlay');
      assert.equal(await page.textContent('.live-greeting'), 'Du kannst jetzt sprechen, Rick');
      assert.equal(await page.textContent('.live-core'), 'TCI: Mara Normal');
      assert.equal(await page.getAttribute('.live-overlay', 'role'), 'dialog');
      await until(page, () => document.querySelector('.live-status').textContent === 'Ich höre zu');
      assert.deepEqual(calls.warm[0].body, {session_id: 'cs_live', character_id: 'rick', since_id: 41}, 'the opening of this chat is warmed');
      assert.equal(calls.warm[0].csrf, 'tok-live');
      assert.deepEqual(await smallText(page), [], theme + ': no text below 11 px in the live view');
      const controls = await page.evaluate(() => [...document.querySelectorAll('.live-controls button')].map(b => b.getBoundingClientRect().height));
      assert.ok(controls.every(h => h >= 44), theme + ': control buttons at least 44 px: ' + controls);

      // A round: the tone, a pause, the WAV goes out as a Stimmsequenz, the sentences are read in order.
      await until(page, () => window.__states.includes('hearing'));
      await until(page, n => window.__states.filter(s => s === 'listening').length >= 2 && document.querySelector('.live-heard').textContent !== '', null, 20000);
      assert.ok(calls.upload.length >= 1 && /^Sprachnachricht-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.wav$/.test(calls.upload[0].name)
        && calls.upload[0].riff && calls.upload[0].audioWav && calls.upload[0].character && calls.upload[0].csrf === 'tok-live',
        theme + ': the round goes out as a WAV Stimmsequenz: ' + JSON.stringify(calls.upload[0]));
      assert.deepEqual(calls.turn[0], {session_id: 'cs_live', character_id: 'rick', since_id: 41, uuid: '1'.padStart(32, 'a')});
      assert.equal(await page.textContent('.live-heard'), '„Wie wird das Wetter morgen?“');
      assert.deepEqual(calls.say.slice(0, 2), ['Morgen wird es trocken und mild.', 'Ich schaue später gern noch genauer nach!'],
        theme + ': every sentence to Eve, in order');
      assert.ok(calls.audio >= 2, theme + ': both sentences played');
      const states = await page.evaluate(() => window.__states.slice());
      const iH = states.indexOf('hearing'), iT = states.indexOf('thinking'), iS = states.indexOf('speaking');
      assert.ok(states[0] === 'preparing' && iH > 0 && iT > iH && iS > iT && states.lastIndexOf('listening') > iS,
        theme + ': preparing, listening, hearing, thinking, speaking, listening: ' + states);

      // Muted: no round goes out.
      await page.click('.live-mute');
      assert.equal(await page.getAttribute('.live-mute', 'aria-pressed'), 'true');
      assert.equal(await page.textContent('.live-status'), 'Mikrofon stumm. Tippe auf das Mikrofon, um weiterzusprechen.');
      const uploads = calls.upload.length;
      await page.waitForTimeout(7000);
      assert.equal(calls.upload.length, uploads, theme + ': muted, nothing is sent');
      await page.click('.live-mute');
      assert.equal(await page.getAttribute('.live-mute', 'aria-label'), 'Mikrofon stummschalten');

      // X: the memory step gets the rounds, the chat reloads, the view goes.
      await page.click('.live-end');
      await until(page, () => !document.querySelector('.live-overlay'));
      await until(page, () => window.__reloads === 1);
      assert.ok(calls.end.length === 1 && calls.end[0].session_id === 'cs_live' && calls.end[0].pairs[0][0] === 11 && calls.end[0].pairs[0][1] === 12,
        theme + ': the rounds go to the memory step: ' + JSON.stringify(calls.end));
      assert.deepEqual(errors, [], theme + ': no page errors');
      await page.close();
    }

    // Speaking while I talk interrupts me (my voice lasts 6 s, the next tone comes earlier).
    {
      const {page, calls} = await openPage(browser, {voiceSeconds: 6});
      await until(page, () => document.getElementById('btnLive'));
      await page.click('#btnLive');
      await until(page, () => window.__states.includes('speaking'), null, 20000);
      // Both sentences (6 s each) are fetched while the first one plays, not one after the other.
      for (let i = 0; i < 30 && calls.audio < 2; i++) await page.waitForTimeout(100);
      assert.ok(calls.audio >= 2 && calls.audioAt[1] - calls.audioAt[0] < 3000,
        'Eve\'s next part is fetched ahead while the part before plays: ' + (calls.audioAt[1] - calls.audioAt[0]) + ' ms');
      await until(page, () => { const s = window.__states; const i = s.indexOf('speaking'); return i > 0 && s.indexOf('hearing', i) > i; }, null, 20000);
      await until(page, () => window.__states.filter(s => s === 'thinking').length >= 2, null, 20000);
      for (let i = 0; i < 50 && calls.upload.length < 2; i++) await page.waitForTimeout(100);
      assert.ok(calls.upload.length >= 2, 'the interrupting words go out as the next round');
      await page.click('.live-end');
      await page.close();
    }

    // Guests never see the button; a Core without audio switches it off; without an open chat it says so.
    {
      const {page} = await openPage(browser, {guest: true});
      await page.waitForTimeout(1200);
      assert.equal(await page.$('#btnLive'), null, 'no live button for guests');
      await page.close();
    }
    {
      const {page} = await openPage(browser, {hears: false});
      await until(page, () => document.getElementById('btnLive'));
      assert.equal(await page.getAttribute('#btnLive', 'aria-disabled'), 'true');
      assert.equal(await page.getAttribute('#btnLive', 'title'), 'Der Core für das Live-Gespräch unterstützt keine Audioeingabe.');
      const visible = await page.evaluate(() => ['btnSend', 'btnLive'].map(id => getComputedStyle(document.getElementById(id)).display !== 'none'));
      assert.deepEqual(visible, [true, false], 'a Core without audio: send stays, no live button in its place');
      await page.close();
    }
    {
      const {page, calls} = await openPage(browser, {context: null});
      await until(page, () => document.getElementById('btnLive'));
      await page.click('#btnLive');
      await until(page, () => document.querySelector('.live-status') && document.querySelector('.live-status').textContent.startsWith('Öffne zuerst'));
      assert.equal(calls.warm.length, 0, 'without a chat nothing is warmed');
      await page.click('.live-end');
      await page.close();
    }
    // Phone: the view fits, nothing scrolls sideways.
    {
      const {page} = await openPage(browser, {viewport: {width: 390, height: 844}});
      await until(page, () => document.getElementById('btnLive'));
      await page.click('#btnLive');
      await page.waitForSelector('.live-overlay');
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert.ok(wide <= 1, 'no sideways scrolling on a phone (' + wide + ' px)');
      const fit = await page.evaluate(() => [...document.querySelectorAll('.live-controls button, .live-greeting')].every(el => {
        const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth && r.bottom <= window.innerHeight; }));
      assert.ok(fit, 'greeting and buttons fit the phone');
      await page.click('.live-end');
      await page.close();
    }
  } finally {
    await browser.close();
    fs.rmSync(dir, {recursive: true, force: true});
  }
  console.log('CoreAI live conversation UI: live in the send button\'s place while the row is empty (send with text or attachment),'
    + ' full-screen view with greeting, warm up, rounds as WAV Stimmsequenzen, sentences read in order and'
    + ' fetched ahead, mute, interrupt, end with memory step and reload, guests none, Core without audio off, no chat, night and day, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
