// CoreAI 0.6.17 (P20.1): voice messages in a real browser (Playwright), js/coreui-voice-input.js.
//   node scripts/voice-input-ui-selftest.cjs
// A small page with the input field, a stand-in for the chat's sendVoice
// (js/console-app.js) and mocked APIs. Chromium records from a fake microphone
// that plays a tone (or pure silence) from a WAV file. Checked: the button sits
// next to "send" for signed-in accounts and never for guests; a click records
// with the time shown; "Fertig" (or the button) sends the recording at once as
// a voice message "Sprachnachricht-....webm" and leaves the input field alone;
// while Mara still answers it waits and goes out afterwards, or is dropped on
// "Abbrechen"; pure silence is not sent; the limit sends on its own; a Core
// without audio keeps the button switched off with the reason and does not open
// the microphone; a Core change asks again; a refused microphone is explained;
// a status the server does not answer shows no button; the transcript written
// after Mara's answer appears small under the voice message.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const UUID = 'ab'.repeat(16);

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title></head><body>
<div id="rows"></div>
<footer class="composer"><div class="composer-frame"><div class="composer-inner">
<button id="btnAttach" type="button">A</button>
<label class="composer-field" for="inputMessage"><textarea id="inputMessage"></textarea></label>
<button id="btnSend" type="button">S</button></div></div></footer>
<script>
  // Stand-in for js/console-app.js: records what would be uploaded and sent.
  window.__sent = [];
  window.__answers = [];
  window.CoreUIComposer = { sendVoice: function (file) {
    var answer = window.__answers.length ? window.__answers.shift() : 'ok';
    window.__sent.push({ name: file.name, size: file.size, type: file.type, answer: answer });
    return answer;
  } };
</script>
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
      calls.push({action, method: request.method(), uuid: url.searchParams.get('uuid')});
      if (action === 'status' && options.statusCode) return route.fulfill({status: options.statusCode, contentType: 'text/html', body: '<h1>404</h1>'});
      if (action === 'status') return route.fulfill({json: Object.assign({ok: true, csrf_token: 'tok'}, typeof status === 'function' ? status() : status)});
      if (action === 'transcript') {
        const answer = typeof options.transcript === 'function' ? options.transcript() : (options.transcript || {ok: true, state: 'none'});
        return route.fulfill({json: answer});
      }
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8765/harness.html');
  return {page, calls, errors};
}

const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const strip = page => page.evaluate(() => {
  const s = document.getElementById('voiceInputState');
  return s ? {hidden: s.hidden, mode: s.getAttribute('data-mode'), title: s.querySelector('.voice-input-title').textContent,
    sub: s.querySelector('.voice-input-sub').textContent} : null;
});
const sent = page => page.evaluate(() => window.__sent);
const addPlayer = (page, uuid, voice) => page.evaluate(([uuid, voice]) => {
  const row = document.createElement('div');
  const audio = document.createElement('audio');
  audio.className = 'msg-image msg-audio';
  audio.setAttribute('data-uuid', uuid);
  if (voice) audio.setAttribute('data-voice', '1');
  row.appendChild(audio);
  document.getElementById('rows').appendChild(row);
}, [uuid, voice]);
const transcriptLine = page => page.evaluate(() => {
  const line = document.querySelector('#rows .msg-transcript');
  return line ? {hidden: line.hidden, text: line.textContent, state: line.getAttribute('data-state')} : null;
});

// The real path in js/console-app.js, cut out and run with stand-ins: sendVoice
// uploads the recording like any attachment and then sends the message with
// the text from the input field (the existing "send after upload").
function chatSendPath() {
  const vm = require('node:vm');
  const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
  const begin = app.indexOf('  function sendAfterCompletedUpload() {');
  const end = app.indexOf('  function queueFiles(fileList) {');
  assert(begin > 0 && end > begin, 'send path missing in console-app.js');
  const xhrs = [];
  const sentMessages = [];
  class FakeXhr {
    constructor() { this.upload = {}; xhrs.push(this); }
    open(method, url) { this.method = method; this.url = url; }
    send(body) { this.body = body; }
  }
  const sandbox = {
    window: {}, state: {waitingEmber: false, editingMessage: null, pendingBySession: {}, characterId: 'c1'},
    pendingFiles: [], uploadQueue: [], uploadCurrentFile: null, uploadXhr: null, pendingSendAfterUpload: null,
    MAX_MESSAGE_ATTACHMENTS: 10, autoPasteSources: new Map(), inputEl: {value: 'Hör mal:'}, btnAttach: null,
    attachBarFill: null, attachUploadSub: null, API_BASE: './api', UPLOAD_ERRORS: {},
    attachmentSlotsUsed() { return this.pendingFiles.length + this.uploadQueue.length + (this.uploadCurrentFile ? 1 : 0); },
    activeSession: () => ({id: 'cs_1'}), renderAttachmentList() {}, showUploadState() {}, restoreFailedAutoPaste() {},
    appendMessageEl() {}, scrollToBottom() {}, FormData, XMLHttpRequest: FakeXhr,
    sendMessage(text) { sentMessages.push({text, files: sandbox.pendingFiles.map(f => f.uuid)}); },
  };
  sandbox.attachmentSlotsUsed = sandbox.attachmentSlotsUsed.bind(sandbox);
  vm.runInNewContext(app.slice(begin, end), sandbox);
  const composer = sandbox.window.CoreUIComposer;
  const file = new File([Buffer.alloc(3000, 1)], 'Sprachnachricht-2026-11-05_10-00-00.webm', {type: 'audio/webm'});
  assert.equal(composer.sendVoice(file), 'ok');
  assert.equal(xhrs.length, 1);
  assert.equal(xhrs[0].url, './api/console_upload.php', 'uploaded like any attachment');
  assert.equal(xhrs[0].body.get('file').name, file.name, 'the recording itself');
  assert.equal(sentMessages.length, 0, 'not sent before the upload is confirmed');
  xhrs[0].status = 200;
  xhrs[0].responseText = JSON.stringify({ok: true, uuid: 'ab'.repeat(16), kind: 'audio', name: file.name});
  xhrs[0].onload();
  assert.deepEqual(sentMessages, [{text: 'Hör mal:', files: ['ab'.repeat(16)]}], 'then sent at once, with the text in the field');
  sandbox.state.waitingEmber = true;
  assert.equal(composer.sendVoice(file), 'busy', 'while Mara answers it waits');
  sandbox.state.waitingEmber = false;
  sandbox.state.editingMessage = {id: 1};
  assert.equal(composer.sendVoice(file), 'editing');
  sandbox.state.editingMessage = null;
  sandbox.pendingFiles = new Array(10).fill({uuid: 'x'});
  assert.equal(composer.sendVoice(file), 'full');
  sandbox.pendingFiles = [];
  assert.equal(composer.sendVoice(new File([], 'Sprachnachricht-leer.webm')), 'empty');
  assert.equal(xhrs.length, 1, 'refused voice messages upload nothing');
}

async function launch(file) {
  return chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + file]});
}

async function record(page, ms) {
  await page.click('#btnMic');
  await until(page, () => document.getElementById('btnMic').classList.contains('recording'));
  await page.waitForTimeout(ms);
}

(async () => {
  chatSendPath();
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
    assert.equal(await page.getAttribute('#btnMic', 'aria-label'), 'Stimmsequenz aufnehmen');

    // Record, see the time, "Fertig": the recording goes out at once as a voice message.
    await page.fill('#inputMessage', 'Hör mal:');
    await record(page, 1600);
    assert.equal(await page.getAttribute('#btnMic', 'aria-label'), 'Stimmsequenz beenden und senden');
    let s = await strip(page);
    assert.equal(s.mode, 'recording');
    assert.match(s.title, /^STIMMSEQUENZ 0:0[12] \/ 2:00$/, 'time shown: ' + s.title);
    await page.click('.voice-input-done');
    await until(page, () => window.__sent.length === 1);
    let voice = (await sent(page))[0];
    assert.match(voice.name, /^Sprachnachricht-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(webm|ogg|m4a)$/, 'named as a voice message: ' + voice.name);
    assert.match(voice.type, /^audio\//, 'an audio file: ' + voice.type);
    assert.ok(voice.size > 2000, 'the recording has content: ' + voice.size);
    assert.equal(await page.inputValue('#inputMessage'), 'Hör mal:', 'the input field is left to the chat (it goes along with the message)');
    assert.equal(calls.filter(c => c.action !== 'status' && c.action !== 'transcript').length, 0, 'no transcript request before Mara hears it');
    assert.equal(await page.evaluate(() => window.__stream.getTracks().every(t => t.readyState === 'ended')), true, 'microphone closed');
    assert.equal((await strip(page)).hidden, true);

    // The mic button itself sends too; "Abbrechen" sends nothing.
    await record(page, 1200);
    await page.click('#btnMic');
    await until(page, () => window.__sent.length === 2);
    await record(page, 700);
    await page.click('.voice-input-cancel');
    await until(page, () => !document.getElementById('btnMic').classList.contains('recording'));
    await page.waitForTimeout(300);
    assert.equal((await sent(page)).length, 2, 'cancelled: nothing sent');
    assert.equal(await page.evaluate(() => window.__stream.getTracks().every(t => t.readyState === 'ended')), true, 'microphone closed after cancel');

    // While Mara still answers, the voice message waits and goes out afterwards.
    await page.evaluate(() => { window.__answers = ['busy', 'busy', 'ok']; });
    await record(page, 1200);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'waiting');
    assert.equal((await strip(page)).title, 'STIMMSEQUENZ WARTET');
    assert.equal(await page.getAttribute('#btnMic', 'aria-label'), 'Stimmsequenz wartet auf Mara');
    await until(page, () => window.__sent.length === 5 && window.__sent[4].answer === 'ok', null, 6000);
    assert.equal((await strip(page)).hidden, true, 'sent after Mara answered');
    // ... or is dropped on "Abbrechen".
    await page.evaluate(() => { window.__answers = ['busy', 'busy', 'busy', 'busy', 'busy', 'busy']; });
    await record(page, 1200);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'waiting');
    await page.click('.voice-input-cancel');
    const afterCancel = (await sent(page)).length;
    await page.waitForTimeout(2500);
    assert.equal((await sent(page)).length, afterCancel, 'a dropped voice message is not sent later');
    // Ten attachments already: said, not sent.
    await page.evaluate(() => { window.__answers = ['full']; });
    await record(page, 1000);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match((await strip(page)).title, /zehn Anhänge/);
    assert.deepEqual(errors, []);
    await page.close();

    // The transcript written after Mara's answer: pending first, then small under the voice message.
    let transcript = {ok: true, state: 'pending'};
    ({page, calls, errors} = await open(browser, {allowed: true, hears: true}, {transcript: () => transcript}));
    await page.waitForSelector('#btnMic');
    await addPlayer(page, UUID, true);
    await until(page, () => { const l = document.querySelector('#rows .msg-transcript'); return l && !l.hidden; });
    assert.deepEqual(await transcriptLine(page), {hidden: false, text: 'Mitschrift folgt …', state: 'pending'});
    transcript = {ok: true, state: 'done', text: 'Hallo Mara, wie geht es dir?'};
    await until(page, () => document.querySelector('#rows .msg-transcript').getAttribute('data-state') === 'done', null, 10000);
    assert.equal((await transcriptLine(page)).text, 'Mitschrift: Hallo Mara, wie geht es dir?');
    assert.equal(calls.filter(c => c.action === 'transcript' && c.uuid === UUID).length, 2, 'asked again while pending, then stopped');
    // The same message drawn again (history reload) uses what is known.
    await addPlayer(page, UUID, true);
    await until(page, () => document.querySelectorAll('#rows .msg-transcript[data-state="done"]').length === 2);
    assert.equal(calls.filter(c => c.action === 'transcript').length, 2, 'no new request for a known transcript');
    await page.close();
    ({page, calls} = await open(browser, {allowed: true, hears: true}, {transcript: {ok: true, state: 'none'}}));
    await page.waitForSelector('#btnMic');
    await addPlayer(page, 'cd'.repeat(16), false);
    await until(page, () => document.querySelector('#rows .msg-transcript') !== null);
    await page.waitForTimeout(300);
    assert.equal((await transcriptLine(page)).hidden, true, 'no transcript: no line');
    await page.close();

    // The limit ends the recording and sends it on its own (here 10 seconds).
    ({page} = await open(browser, {allowed: true, hears: true, max_seconds: 10}));
    await page.waitForSelector('#btnMic');
    await page.click('#btnMic');
    await until(page, () => /\/ 0:10$/.test(document.querySelector('.voice-input-title').textContent));
    await until(page, () => window.__sent.length === 1, null, 16000);
    await page.close();

    // A Core without audio: the button stays, switched off, with the reason; the microphone is not opened.
    let hears = false;
    ({page, calls} = await open(browser, () => ({allowed: true, hears})));
    await page.waitForSelector('#btnMic');
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'true');
    assert.equal(await page.getAttribute('#btnMic', 'title'), 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.');
    // aria-disabled keeps the button clickable for people, so the reason can be shown (Playwright needs force).
    await page.click('#btnMic', {force: true});
    assert.equal((await strip(page)).title, 'Dieser Core kann nicht hören. Wähle im Core-Menü einen Core mit Audio.');
    assert.equal(await page.evaluate(() => window.__mics), 0, 'microphone not opened');
    hears = true;
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('coreui:core', {detail: {model: 'x'}})));
    await until(page, () => document.getElementById('btnMic').getAttribute('aria-disabled') === 'false');
    assert.equal(calls.filter(c => c.action === 'status').length, 2, 'a Core change asks again');
    await page.close();

    // No ffmpeg on the server: switched off with the reason.
    ({page} = await open(browser, {allowed: true, hears: null, ready: false}));
    await page.waitForSelector('#btnMic');
    assert.equal(await page.getAttribute('#btnMic', 'aria-disabled'), 'true');
    assert.match(await page.getAttribute('#btnMic', 'title'), /ffmpeg fehlt/);
    await page.close();

    // A status the server does not answer (404) shows no button but says why in the console.
    ({page} = await open(browser, {allowed: true, hears: true}, {statusCode: 404}));
    const warnings = [];
    page.on('console', message => { if (message.type() === 'warning') warnings.push(message.text()); });
    await page.reload();
    for (let i = 0; i < 40 && !warnings.length; i++) await page.waitForTimeout(50);
    assert.equal(await page.$('#btnMic'), null, 'no button without a status');
    assert.ok(warnings.some(text => text.includes('Spracheingabe: Status nicht lesbar (HTTP 404)')), 'the reason is in the console: ' + warnings);
    await page.close();

    // Guests never see the button and never ask for transcripts.
    ({page, calls} = await open(browser, {allowed: false}));
    await page.waitForTimeout(400);
    await addPlayer(page, UUID, true);
    await page.waitForTimeout(300);
    assert.equal(await page.$('#btnMic'), null, 'no button for guests');
    assert.equal(calls.filter(c => c.action === 'transcript').length, 0, 'no transcript for guests');
    await page.close();

    // A refused microphone is explained.
    ({page} = await open(browser, {allowed: true, hears: true}, {deny: true}));
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
    const {page, errors} = await open(browser, {allowed: true, hears: true});
    await page.waitForSelector('#btnMic');
    await record(page, 1500);
    await page.click('.voice-input-done');
    await until(page, () => document.getElementById('voiceInputState').getAttribute('data-mode') === 'message');
    assert.match((await strip(page)).title, /^Ich habe nichts gehört\./);
    assert.equal((await sent(page)).length, 0, 'silence never leaves the browser');
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
    fs.rmSync(dir, {recursive: true, force: true});
  }
  console.log('CoreAI voice input UI: button for accounts only, recording with time, sent at once as a voice message (waits while Mara'
    + ' answers), cancel, silence, limit, Core without audio, Core change, refused microphone, status 404 and the transcript line OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
