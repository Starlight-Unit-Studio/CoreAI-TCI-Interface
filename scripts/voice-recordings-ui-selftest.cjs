// CoreAI 0.7.15: "Diktierfunktion aktivieren" and the recordings kept on the device, in a real browser
// (Playwright), js/coreui-voice-input.js with js/coreui-recordings.js.
//   node scripts/voice-recordings-ui-selftest.cjs
// A small page with the input field, a stand-in for the chat's sendVoice and mocked APIs.
// Chromium records from a fake microphone (a tone or silence). Checked: there is one microphone
// button (no second dictation button); "Diktierfunktion aktivieren" (Settings > Stimme) shows it,
// switched off it is gone while the transcript lines under voice messages stay; every
// Stimmsequenz is kept on the device with its outcome ("gesendet", "nicht gesendet"), for this
// account only, and a message says where to find one that could not be sent; silence is
// neither sent nor kept.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const KEY = '0123456789abcdef01234567';
const UUID = 'cd'.repeat(16);
const WHERE = 'Sie liegt unter Einstellungen > Stimme > Zuletzt verwendete Aufnahmen.';

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title></head><body>
<div id="rows"></div>
<footer class="composer"><div class="composer-frame"><div class="composer-inner">
<button id="btnAttach" type="button">A</button>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label>
<button id="btnSend" type="button">S</button></div></div></footer>
<script>
  window.__sent = [];
  window.__answers = [];
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

async function open(browser, status) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://localhost:8766/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (url.pathname === '/api/voice_input.php') {
      if (url.searchParams.get('action') === 'transcript') return route.fulfill({json: {ok: true, state: 'done', text: 'Hallo Mara.'}});
      return route.fulfill({json: Object.assign({ok: true, allowed: true, hears: true, ready: true, max_seconds: 120, csrf_token: 'tok',
        recordings_key: KEY}, status)});
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8766/harness.html');
  return {context, page, errors};
}

const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const kept = (page, key) => page.evaluate(k => window.CoreAIRecordings.list(k).then(l => l.map(x => [x.kind, x.state])), key || KEY);
const title = page => page.textContent('#voiceInputState .voice-input-title');

async function launch(file) {
  return chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + file]});
}

async function record(page, ms) {
  await page.click('#btnMic');
  await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
  await page.waitForTimeout(ms);
  await page.click('#btnMic');
}

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coreai-recordings-ui-'));
  const toneFile = path.join(dir, 'tone.wav');
  const quietFile = path.join(dir, 'quiet.wav');
  fs.writeFileSync(toneFile, wav(4, true));
  fs.writeFileSync(quietFile, wav(4, false));
  let browser = await launch(toneFile);
  try {
    // Dictation on (the default): one microphone button, nothing else.
    let {context, page, errors} = await open(browser, {dictation: true});
    await page.waitForSelector('#btnMic');
    assert.deepEqual(await page.$$eval('.composer-inner > button', l => l.map(b => b.id)), ['btnAttach', 'btnMic', 'btnSend'], 'one microphone');
    assert.equal(await page.getAttribute('#btnMic', 'aria-label'), 'Stimmsequenz aufnehmen');

    // Every Stimmsequenz is kept on the device with its outcome.
    await page.fill('#inputMessage', 'Hör mal:');
    await record(page, 900);
    await until(page, () => window.__sent.length === 1);
    assert.equal(await page.inputValue('#inputMessage'), 'Hör mal:', 'the field is left to the chat');
    await until(page, k => window.CoreAIRecordings.list(k).then(l => l.length === 1 && l[0].state === 'gesendet'), KEY);
    await page.evaluate(() => { window.__answers = ['unavailable']; });
    await record(page, 900);
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.equal(await title(page), 'Die Stimmsequenz ließ sich nicht senden. ' + WHERE);
    await until(page, k => window.CoreAIRecordings.list(k).then(l => l.length === 2 && l[0].state === 'nicht gesendet'), KEY);
    assert.deepEqual(await kept(page), [['stimmsequenz', 'nicht gesendet'], ['stimmsequenz', 'gesendet']], 'newest first');
    assert.deepEqual(await kept(page, 'ffffffffffffffffffffffff'), [], 'nothing for another account');
    // While Mara answers it waits; "Abbrechen" drops it from the chat but not from the device.
    await page.evaluate(() => { window.__answers = ['busy', 'busy', 'busy', 'busy', 'busy', 'busy']; });
    await record(page, 900);
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'waiting');
    await page.click('.voice-input-cancel');
    await until(page, k => window.CoreAIRecordings.list(k).then(l => l.length === 3 && l[0].state === 'nicht gesendet'), KEY);
    assert.deepEqual(errors, []);
    await context.close();

    // Dictation off: no microphone, the transcript line under a voice message stays.
    ({context, page, errors} = await open(browser, {dictation: false}));
    await page.evaluate(uuid => {
      const audio = document.createElement('audio');
      audio.className = 'msg-audio';
      audio.setAttribute('data-uuid', uuid);
      document.getElementById('rows').appendChild(audio);
    }, UUID);
    await until(page, () => !!document.querySelector('.msg-transcript[data-state="done"]'));
    assert.equal(await page.textContent('.msg-transcript'), 'Mitschrift: Hallo Mara.');
    assert.equal(await page.$('#btnMic'), null, 'switched off: no microphone');
    assert.equal(await page.evaluate(() => document.querySelector('.composer-inner').classList.contains('has-mic')), false);
    assert.deepEqual(errors, []);
    await context.close();

    // Guests: none.
    ({context, page, errors} = await open(browser, {allowed: false}));
    await page.waitForTimeout(600);
    assert.equal(await page.$('#btnMic'), null);
    await context.close();
  } finally {
    await browser.close();
  }

  // Silence: neither sent nor kept.
  browser = await launch(quietFile);
  try {
    const {context, page, errors} = await open(browser, {dictation: true});
    await page.waitForSelector('#btnMic');
    await record(page, 1200);
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match(await title(page), /^Ich habe nichts gehört\./);
    assert.equal(await page.evaluate(() => window.__sent.length), 0);
    assert.deepEqual(await kept(page), [], 'silence is not kept');
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
    fs.rmSync(dir, {recursive: true, force: true});
  }
  console.log('CoreAI recordings UI: one microphone, shown or hidden by "Diktierfunktion aktivieren" (transcripts stay),'
    + ' every Stimmsequenz kept on the device with its outcome (this account only), where to find an unsent one, silence neither sent nor kept OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
