// CoreAI 0.7.14: dictation and the recordings kept on the device, in a real browser (Playwright),
// js/coreui-voice-input.js with js/coreui-recordings.js.
//   node scripts/voice-dictation-ui-selftest.cjs
// A small page with the input field, a stand-in for the chat's sendVoice and mocked APIs.
// Chromium records from a fake microphone (a tone or silence). Checked: the dictation button
// sits before the microphone while dictation is on (Settings > Stimme) and is gone when it is off;
// a dictation records with the time shown, goes to api/voice_dictate.php with CSRF, and the
// Core's text lands in the input field at the caret, with spaces where needed; nothing is
// sent; the reasons when nothing was heard or the Core cannot write it; "Abbrechen" while the
// Core writes leaves the field alone; while one recording runs the other button is off; every
// recording (Stimmsequenz and dictation) is kept on the device with its outcome, for this
// account only; silence never leaves the browser.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const KEY = '0123456789abcdef01234567';
const TEXT = 'Bitte erinnere mich morgen um 9 Uhr an den Termin.';
const WHERE = 'Sie liegt unter Einstellungen > Stimme > Zuletzt verwendete Aufnahmen.';

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title></head><body>
<footer class="composer"><div class="composer-frame"><div class="composer-inner">
<button id="btnAttach" type="button">A</button>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label>
<button id="btnSend" type="button">S</button></div></div></footer>
<script>
  window.__sent = [];
  window.__answers = [];
  window.__inputs = 0;
  document.addEventListener('input', function (e) { if (e.target.id === 'inputMessage') window.__inputs++; });
  window.CoreUIComposer = { sendVoice: function (file) {
    var answer = window.__answers.length ? window.__answers.shift() : 'ok';
    window.__sent.push({ name: file.name, answer: answer });
    return answer;
  } };
</script>
<script src="/js/coreui-recordings.js"></script>
<script src="/js/coreui-voice-input.js"></script></body></html>`;

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

async function open(browser, status, dictate) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const posts = [];
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://localhost:8766/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (url.pathname === '/api/voice_input.php') {
      return route.fulfill({json: Object.assign({ok: true, allowed: true, hears: true, ready: true, max_seconds: 120, csrf_token: 'tok',
        recordings_key: KEY}, status)});
    }
    if (url.pathname === '/api/voice_dictate.php') {
      const type = request.headers()['content-type'] || '';
      posts.push({method: request.method(), csrf: request.headers()['x-coreui-csrf'], multipart: type.startsWith('multipart/form-data'),
        size: (request.postDataBuffer() || Buffer.alloc(0)).length, audio: /name="audio"; filename="diktat\.(webm|ogg|m4a)"/.test((request.postDataBuffer() || '').toString('latin1'))});
      const answer = dictate.length ? dictate.shift() : {json: {ok: true, text: TEXT, seconds: 2}};
      if (answer.delay) await new Promise(r => setTimeout(r, answer.delay));
      return route.fulfill({status: answer.status || 200, json: answer.json}).catch(() => {});
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8766/harness.html');
  return {context, page, posts, errors};
}

const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const strip = page => page.evaluate(() => {
  const s = document.getElementById('voiceInputState');
  return {hidden: s.hidden, mode: s.getAttribute('data-mode'), title: s.querySelector('.voice-input-title').textContent};
});
const kept = (page, key) => page.evaluate(k => window.CoreAIRecordings.list(k).then(l => l.map(x => [x.kind, x.state, x.text])), key || KEY);

async function launch(file) {
  return chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + file]});
}

async function dictateFor(page, ms) {
  await page.click('#btnDictate');
  await until(page, () => document.getElementById('btnDictate').classList.contains('recording'));
  await page.waitForTimeout(ms);
}

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coreai-dictation-ui-'));
  const toneFile = path.join(dir, 'tone.wav');
  const quietFile = path.join(dir, 'quiet.wav');
  fs.writeFileSync(toneFile, wav(4, true));
  fs.writeFileSync(quietFile, wav(4, false));
  let browser = await launch(toneFile);
  try {
    // Dictation on: its button before the microphone.
    const dictate = [];
    let {context, page, posts, errors} = await open(browser, {dictation: true}, dictate);
    await page.waitForSelector('#btnDictate');
    assert.deepEqual(await page.$$eval('.composer-inner > button', l => l.map(b => b.id)), ['btnAttach', 'btnDictate', 'btnMic', 'btnSend']);
    assert.equal(await page.evaluate(() => document.querySelector('.composer-inner').classList.contains('has-dictate')), true);
    assert.equal(await page.getAttribute('#btnDictate', 'aria-label'), 'Diktieren: Text ins Eingabefeld sprechen');

    // Dictate: the time shows, the mic is off meanwhile, the text lands at the caret.
    await page.fill('#inputMessage', 'Hallo Welt');
    await page.evaluate(() => { const f = document.getElementById('inputMessage'); f.focus(); f.setSelectionRange(5, 5); });
    await dictateFor(page, 1500);
    let s = await strip(page);
    assert.equal(s.mode, 'recording');
    assert.match(s.title, /^DIKTAT 0:0[12] \/ 2:00$/, s.title);
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'true', 'the microphone waits');
    assert.equal(await page.getAttribute('#btnDictate', 'aria-label'), 'Diktat beenden');
    await page.click('#btnMic', {force: true});
    assert.equal((await strip(page)).title, 'Beende zuerst dein Diktat.');
    await page.click('#btnDictate');
    await until(page, () => /Termin/.test(document.getElementById('inputMessage').value));
    assert.equal(await page.inputValue('#inputMessage'), 'Hallo ' + TEXT + ' Welt', 'at the caret with spaces');
    assert.equal(posts.length, 1);
    assert.deepEqual({method: posts[0].method, csrf: posts[0].csrf, multipart: posts[0].multipart, audio: posts[0].audio},
      {method: 'POST', csrf: 'tok', multipart: true, audio: true}, 'the recording goes with CSRF');
    assert.ok(posts[0].size > 2000, 'with content: ' + posts[0].size);
    assert.equal(await page.evaluate(() => window.__sent.length), 0, 'a dictation is never sent');
    assert.ok(await page.evaluate(() => window.__inputs) > 0, 'the chat learns the field changed');
    assert.equal((await strip(page)).hidden, true);
    assert.deepEqual(await kept(page), [['diktat', 'mitgeschrieben', TEXT]], 'kept on the device with its text');

    // At the end of the field (no caret in it).
    await page.fill('#inputMessage', 'Notiz:');
    await page.evaluate(() => document.activeElement.blur());
    await dictateFor(page, 900);
    await page.click('.voice-input-done');
    await until(page, () => /Termin/.test(document.getElementById('inputMessage').value));
    assert.equal(await page.inputValue('#inputMessage'), 'Notiz: ' + TEXT);

    // Nothing heard, or the Core cannot: the reason, the field stays, the recording stays on the device.
    dictate.push({status: 422, json: {ok: false, error: 'no_speech'}});
    await page.fill('#inputMessage', '');
    await dictateFor(page, 900);
    await page.click('#btnDictate');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.equal((await strip(page)).title, 'Ich habe im Diktat keine Worte erkannt. ' + WHERE);
    assert.equal(await page.inputValue('#inputMessage'), '');
    dictate.push({status: 409, json: {ok: false, error: 'core_without_audio'}});
    await dictateFor(page, 900);
    await page.click('#btnDictate');
    await until(page, () => /Core kann nicht hören/.test(document.querySelector('.voice-input-title').textContent));
    dictate.push({status: 502, json: {ok: false, error: 'core_failed'}});
    await dictateFor(page, 900);
    await page.click('#btnDictate');
    await until(page, () => document.querySelector('.voice-input-title').textContent === 'Ich konnte dein Diktat nicht mitschreiben. ' + 'Sie liegt unter Einstellungen > Stimme > Zuletzt verwendete Aufnahmen.');

    // "Abbrechen" while the Core writes: the field stays as it is.
    dictate.push({delay: 3000, json: {ok: true, text: 'Zu spät.'}});
    await page.fill('#inputMessage', 'Bleibt.');
    await dictateFor(page, 900);
    await page.click('#btnDictate');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'waiting');
    assert.equal((await strip(page)).title, 'DIKTAT WIRD MITGESCHRIEBEN');
    assert.equal(await page.getAttribute('#btnDictate', 'aria-label'), 'Ich schreibe dein Diktat mit');
    await page.click('.voice-input-cancel');
    await until(page, () => document.getElementById('voiceInputState').hidden);
    await page.waitForTimeout(3300);
    assert.equal(await page.inputValue('#inputMessage'), 'Bleibt.', 'cancelled: nothing written');
    const states = (await kept(page)).map(r => r[1]);
    assert.deepEqual(states, ['nicht mitgeschrieben', 'nicht mitgeschrieben', 'nicht mitgeschrieben', 'nicht mitgeschrieben', 'mitgeschrieben', 'mitgeschrieben'],
      'every dictation kept with its outcome, newest first: ' + JSON.stringify(states));

    // A Stimmsequenz is kept too, with "gesendet" or "nicht gesendet" and where to find it.
    await page.click('#btnMic');
    await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
    assert.equal(await page.getAttribute('#btnDictate', 'aria-disabled'), 'true', 'dictation waits for the Stimmsequenz');
    await page.waitForTimeout(900);
    await page.click('#btnMic');
    await until(page, () => window.__sent.length === 1);
    await page.evaluate(() => { window.__answers = ['unavailable']; });
    await page.click('#btnMic');
    await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
    await page.waitForTimeout(900);
    await page.click('#btnMic');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.equal((await strip(page)).title, 'Die Stimmsequenz ließ sich nicht senden. ' + WHERE);
    await until(page, (k) => window.CoreAIRecordings.list(k).then(l => l[0].state === 'nicht gesendet'), KEY).catch(() => {});
    const voices = (await kept(page)).filter(r => r[0] === 'stimmsequenz').map(r => r[1]);
    assert.deepEqual(voices, ['nicht gesendet', 'gesendet']);
    assert.deepEqual(await kept(page, 'ffffffffffffffffffffffff'), [], 'nothing for another account');
    assert.deepEqual(errors, []);
    await context.close();

    // Dictation off (Settings > Stimme): no button, the microphone as before.
    ({context, page, errors} = await open(browser, {dictation: false}, []));
    await page.waitForSelector('#btnMic');
    assert.equal(await page.$('#btnDictate'), null);
    assert.equal(await page.evaluate(() => document.querySelector('.composer-inner').classList.contains('has-dictate')), false);
    assert.deepEqual(errors, []);
    await context.close();

    // Guests: neither button.
    ({context, page, errors} = await open(browser, {allowed: false}, []));
    await page.waitForTimeout(600);
    assert.equal(await page.$('#btnDictate'), null);
    assert.equal(await page.$('#btnMic'), null);
    await context.close();
  } finally {
    await browser.close();
  }

  // Silence: no dictation leaves the browser.
  browser = await launch(quietFile);
  try {
    const {context, page, posts, errors} = await open(browser, {dictation: true}, []);
    await page.waitForSelector('#btnDictate');
    await dictateFor(page, 1200);
    await page.click('#btnDictate');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match((await strip(page)).title, /^Ich habe nichts gehört\./);
    assert.equal(posts.length, 0, 'silence never leaves the browser');
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
    fs.rmSync(dir, {recursive: true, force: true});
  }
  console.log('CoreAI dictation UI: button while dictation is on, recording with time, sent with CSRF, the Core\'s text at the caret,'
    + ' never sent, reasons, cancel, one recording at a time, every recording kept on the device with its outcome (this account only),'
    + ' off and guests without button, silence stays OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
