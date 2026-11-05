// CoreAI 0.6.14 (P20.1): voice input in a real browser (Playwright), js/coreui-voice-input.js.
//   node scripts/voice-input-ui-selftest.cjs
// A small page with the input field and mocked APIs. Chromium records from a
// fake microphone that plays a tone (or pure silence) from a WAV file. Checked:
// the button sits next to "send" for signed-in accounts and never for guests;
// a click records with the time shown, "Fertig" sends the recording with CSRF
// and the transcript lands in the input field (after the text already there)
// without being sent; "Abbrechen" sends nothing; pure silence is not sent;
// a Core without audio keeps the button switched off with the reason and does
// not open the microphone; a Core change asks again; a refused microphone is
// explained; errors from the server are shown; a transcript can be cancelled;
// the limit stops the recording on its own.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title></head><body>
<footer class="composer"><div class="composer-frame"><div class="composer-inner">
<button id="btnAttach" type="button">A</button>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label>
<button id="btnSend" type="button">S</button></div></div></footer>
<script>window.__inputs = 0; document.getElementById('inputMessage').addEventListener('input', function () { window.__inputs++; });</script>
<script src="/js/coreui-voice-input.js"></script></body></html>`;

// 16 kHz mono 16-bit WAV: a 440 Hz tone, or silence.
function wav(seconds, tone) {
  const samples = 16000 * seconds;
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + samples * 2, 4); head.write('WAVE', 8); head.write('fmt ', 12); head.writeUInt32LE(16, 16);
  head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24); head.writeUInt32LE(32000, 28);
  head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(samples * 2, 40);
  const data = Buffer.alloc(samples * 2);
  if (tone) for (let i = 0; i < samples; i++) data.writeInt16LE(Math.round(12000 * Math.sin(2 * Math.PI * 440 * i / 16000)), i * 2);
  return Buffer.concat([head, data]);
}

async function open(browser, status, options = {}) {
  const calls = [];
  const held = [];
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(deny => {
    window.__mics = 0;
    const devices = navigator.mediaDevices;
    const real = devices.getUserMedia.bind(devices);
    devices.getUserMedia = constraints => {
      window.__mics++;
      if (deny) return Promise.reject(new DOMException('denied', 'NotAllowedError'));
      return real(constraints).then(stream => { window.__stream = stream; return stream; });
    };
  }, !!options.deny);
  await page.route('http://localhost:8765/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (url.pathname === '/api/voice_input.php') {
      const action = url.searchParams.get('action');
      const body = request.postDataBuffer();
      calls.push({action, csrf: request.headers()['x-coreui-csrf'] || '', type: request.headers()['content-type'] || '',
        body: body ? body.toString('latin1') : '', size: body ? body.length : 0});
      if (action === 'status') return route.fulfill({json: Object.assign({ok: true, csrf_token: 'tok'}, typeof status === 'function' ? status() : status)});
      if (action === 'transcribe') {
        if (options.hold) await new Promise(resolve => held.push(resolve));
        try {
          return await route.fulfill({json: options.answer || {ok: true, text: 'Hallo Mara, wie geht es dir?', seconds: 2.1, truncated: false}});
        } catch (e) { return undefined; } // aborted by the page
      }
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8765/harness.html');
  return {page, calls, errors, held};
}

const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const transcribes = calls => calls.filter(c => c.action === 'transcribe');
const strip = page => page.evaluate(() => {
  const s = document.getElementById('voiceInputState');
  return s ? {hidden: s.hidden, mode: s.getAttribute('data-mode'), title: s.querySelector('.voice-input-title').textContent,
    sub: s.querySelector('.voice-input-sub').textContent} : null;
});

async function launch(file) {
  return chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + file]});
}

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coreai-voice-ui-'));
  const toneFile = path.join(dir, 'tone.wav');
  const quietFile = path.join(dir, 'quiet.wav');
  fs.writeFileSync(toneFile, wav(4, true));
  fs.writeFileSync(quietFile, wav(4, false));
  let browser = await launch(toneFile);
  try {
    // Signed in, a Core that hears: the button sits before "send".
    let {page, calls, errors} = await open(browser, {allowed: true, hears: true, ready: true, max_seconds: 120});
    await page.waitForSelector('#btnMic');
    assert.equal(await page.evaluate(() => document.getElementById('btnMic').nextElementSibling.id), 'btnSend', 'next to send');
    assert.equal(await page.evaluate(() => document.querySelector('.composer-inner').classList.contains('has-mic')), true);
    assert.equal(await page.getAttribute('#btnMic', 'aria-label'), 'Spracheingabe: Aufnahme starten');
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'false');

    // Record, see the time, "Fertig": the transcript goes after the existing text and is not sent.
    await page.fill('#inputMessage', 'Vorher:');
    await page.evaluate(() => { window.__inputs = 0; });
    await page.click('#btnMic');
    await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
    assert.equal(await page.getAttribute('#btnMic', 'aria-pressed'), 'true');
    await page.waitForTimeout(1600);
    let s = await strip(page);
    assert.equal(s.hidden, false);
    assert.equal(s.mode, 'recording');
    assert.match(s.title, /^AUFNAHME 0:0[12] \/ 2:00$/, 'time shown: ' + s.title);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('inputMessage').value.indexOf('Hallo Mara') >= 0);
    const sent = transcribes(calls);
    assert.equal(sent.length, 1, 'one recording sent');
    assert.equal(sent[0].csrf, 'tok', 'with the CSRF token');
    assert.match(sent[0].type, /^multipart\/form-data/);
    assert.match(sent[0].body, /name="audio"; filename="aufnahme\.(webm|ogg|m4a)"/, 'the recording as field "audio"');
    assert.ok(sent[0].size > 2000, 'the recording has content: ' + sent[0].size);
    assert.equal(await page.inputValue('#inputMessage'), 'Vorher: Hallo Mara, wie geht es dir?', 'after the text, with a space');
    assert.ok(await page.evaluate(() => window.__inputs) >= 1, 'the field learns about the new text (height, send button)');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'inputMessage', 'cursor back in the field');
    assert.equal(await page.evaluate(() => window.__stream.getTracks().every(t => t.readyState === 'ended')), true, 'microphone closed');
    assert.equal((await strip(page)).hidden, true);
    assert.equal(await page.getAttribute('#btnMic', 'aria-pressed'), 'false');

    // The mic button itself ends a recording as well; "Abbrechen" sends nothing.
    await page.click('#btnMic');
    await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
    await page.waitForTimeout(700);
    await page.click('.voice-input-cancel');
    await until(page, () => !document.getElementById('btnMic').classList.contains('recording'));
    await page.waitForTimeout(300);
    assert.equal(transcribes(calls).length, 1, 'cancelled: nothing sent');
    assert.equal((await strip(page)).hidden, true);
    assert.equal(await page.evaluate(() => window.__stream.getTracks().every(t => t.readyState === 'ended')), true, 'microphone closed after cancel');
    await page.click('#btnMic');
    await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
    await page.waitForTimeout(1200);
    await page.click('#btnMic');
    await until(page, (n) => document.getElementById('inputMessage').value.split('Hallo Mara').length === 3, null);
    assert.equal(transcribes(calls).length, 2, 'the mic button sends too');
    assert.deepEqual(errors, []);
    await page.close();

    // A server message is shown; the field stays as it was.
    ({page, calls, errors} = await open(browser, {allowed: true, hears: true},
      {answer: {ok: false, error: 'no_speech', message: 'Ich habe keine Sprache gehört.'}}));
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await page.waitForTimeout(1200);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.equal((await strip(page)).title, 'Ich habe keine Sprache gehört.');
    assert.equal(await page.inputValue('#inputMessage'), '');
    await page.close();

    // While Core writes, the transcript can be cancelled; nothing lands in the field.
    let held;
    ({page, calls, errors, held} = await open(browser, {allowed: true, hears: true}, {hold: true}));
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await page.waitForTimeout(1200);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('btnMic').classList.contains('working'));
    s = await strip(page);
    assert.equal(s.mode, 'working');
    assert.equal(s.title, 'CORE SCHREIBT MIT …');
    await page.click('.voice-input-cancel');
    await until(page, () => !document.getElementById('btnMic').classList.contains('working'));
    held.forEach(release => release());
    await page.waitForTimeout(300);
    assert.equal(await page.inputValue('#inputMessage'), '', 'a cancelled transcript is not inserted');
    assert.equal((await strip(page)).hidden, true);
    await page.close();

    // The limit ends the recording on its own (here 10 seconds).
    ({page, calls, errors} = await open(browser, {allowed: true, hears: true, max_seconds: 10}));
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await until(page, () => /\/ 0:10$/.test(document.querySelector('.voice-input-title').textContent));
    await until(page, () => document.getElementById('inputMessage').value.indexOf('Hallo Mara') >= 0, null, 16000);
    assert.equal(transcribes(calls).length, 1, 'sent at the limit without a click');
    await page.close();

    // A Core without audio: the button stays, switched off, with the reason; the microphone is not opened.
    let hears = false;
    ({page, calls, errors} = await open(browser, () => ({allowed: true, hears})));
    await page.waitForSelector('#btnMic');
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'true');
    assert.equal(await page.getAttribute('#btnMic', 'title'), 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.');
    // aria-disabled keeps the button clickable for people, so the reason can be shown (Playwright needs force).
    await page.click('#btnMic', {force: true});
    assert.equal((await strip(page)).title, 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.');
    assert.equal(await page.evaluate(() => window.__mics), 0, 'microphone not opened');
    // A Core change asks again.
    hears = true;
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('coreui:core', {detail: {model: 'x'}})));
    await until(page, () => document.getElementById('btnMic').getAttribute('aria-disabled') === 'false');
    assert.equal(calls.filter(c => c.action === 'status').length, 2);
    await page.close();

    // No ffmpeg on the server: switched off with the reason.
    ({page} = await open(browser, {allowed: true, hears: null, ready: false}));
    await page.waitForSelector('#btnMic');
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'true');
    assert.match(await page.getAttribute('#btnMic', 'title'), /ffmpeg fehlt/);
    await page.close();

    // Guests never see the button.
    ({page, calls} = await open(browser, {allowed: false}));
    await until(page, () => true);
    await page.waitForTimeout(400);
    assert.equal(await page.$('#btnMic'), null, 'no button for guests');
    await page.close();

    // A refused microphone is explained.
    ({page, calls} = await open(browser, {allowed: true, hears: true}, {deny: true}));
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match((await strip(page)).title, /^Mikrofon nicht erlaubt\./);
    assert.equal(await page.evaluate(() => document.getElementById('btnMic').classList.contains('recording')), false);
    await page.close();
  } finally {
    await browser.close();
  }

  // Pure silence is not sent.
  browser = await launch(quietFile);
  try {
    const {page, calls, errors} = await open(browser, {allowed: true, hears: true});
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await page.waitForTimeout(1500);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match((await strip(page)).title, /^Ich habe nichts gehört\./);
    assert.equal(transcribes(calls).length, 0, 'silence never leaves the browser');
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
    fs.rmSync(dir, {recursive: true, force: true});
  }
  console.log('CoreAI voice input UI: button for accounts only, recording with time, transcript into the field, cancel, silence,'
    + ' Core without audio, Core change, refused microphone, server messages and the limit OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
