// CoreAI 0.6.1: read aloud in a real browser (Playwright), js/coreui-voice-read.js.
//   node scripts/voice-read-ui-selftest.cjs
// A small page with mocked APIs: the speaker button asks for a plan with CSRF,
// plays part 0 and loads part 1 meanwhile, goes on part by part, a second
// click stops, a redrawn row keeps the running state, guests never see it,
// while Eve loads it waits, "nothing to read" is said, new answers are read
// on their own only when the account switched that on, and the KI-Profil
// switch saves the setting.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const ID = 'a'.repeat(64);

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title></head><body>
<div id="rows"></div>
<label id="voiceAutoReadRow" hidden><span id="voiceAutoReadNote"></span><input id="voiceAutoRead" type="checkbox"></label>
<script src="/js/coreui-voice-read.js"></script></body></html>`;

function wav() {
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36, 4); head.write('WAVE', 8); head.write('fmt ', 12); head.writeUInt32LE(16, 16);
  head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24); head.writeUInt32LE(32000, 28);
  head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(0, 40);
  return head;
}

async function open(browser, status, planAnswer) {
  const calls = [];
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    window.__audios = [];
    window.__played = [];
    const Native = window.Audio;
    window.Audio = function () { const audio = new Native(); window.__audios.push(audio); return audio; };
    HTMLMediaElement.prototype.play = function () { window.__played.push(this.src); return Promise.resolve(); };
    HTMLMediaElement.prototype.pause = function () { this.__paused = true; };
  });
  await page.route('http://coreui.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (url.pathname === '/api/voice.php') {
      const action = url.searchParams.get('action');
      const post = route.request().method() === 'POST' ? JSON.parse(route.request().postData() || '{}') : null;
      calls.push({action, post, part: url.searchParams.get('part'), id: url.searchParams.get('id'), csrf: route.request().headers()['x-coreui-csrf'] || ''});
      if (action === 'status') return route.fulfill({json: Object.assign({ok: true, csrf_token: 'tok'}, status)});
      if (action === 'plan') return route.fulfill({json: planAnswer || {ok: true, id: ID, parts: 3}});
      if (action === 'settings') return route.fulfill({json: {ok: true, auto_read: !!post.auto_read}});
      if (action === 'audio') return route.fulfill({status: 200, contentType: 'audio/wav', body: wav()});
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://coreui.test/harness.html');
  await page.evaluate(() => window.CoreAIVoiceRead.ready());
  return {page, calls, errors};
}

const addButton = (page, id) => page.evaluate(id => {
  const b = window.CoreAIVoiceRead.button({id, session_id: 's1'});
  b.id = 'speak' + id + '-' + document.querySelectorAll('.msg-speak').length;
  document.getElementById('rows').appendChild(b);
  return b.id;
}, id);

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // Signed in, Eve ready, auto-read off.
    let {page, calls, errors} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false});
    let button = '#' + await addButton(page, 42);
    assert.equal(await page.isVisible(button), true);
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Antwort vorlesen');
    await page.click(button);
    await page.waitForFunction(() => window.__played.length === 1);
    const plan = calls.find(c => c.action === 'plan');
    assert.deepEqual(plan.post, {session_id: 's1', message_id: 42}, 'plan for this answer');
    assert.equal(plan.csrf, 'tok', 'with the CSRF token');
    assert.match(await page.evaluate(() => window.__played[0]), /action=audio&id=a{64}&part=0$/, 'part 0 plays first');
    await page.waitForFunction(() => window.__audios.length >= 2);
    assert.match(await page.evaluate(() => window.__audios[1].src), /part=1$/, 'part 1 loads while part 0 plays');
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Vorlesen stoppen');
    assert.match(await page.getAttribute(button, 'class'), /speaking/);
    // Part by part to the end.
    await page.evaluate(() => window.__audios[0].dispatchEvent(new Event('ended')));
    await page.waitForFunction(() => window.__played.length === 2);
    assert.match(await page.evaluate(() => window.__played[1]), /part=1$/);
    assert.match(await page.evaluate(() => window.__audios[2].src), /part=2$/, 'then part 2 loads');
    // A redrawn row keeps the running state.
    const redrawn = '#' + await addButton(page, 42);
    assert.match(await page.getAttribute(redrawn, 'class'), /speaking/, 'redrawn button shows it is speaking');
    await page.evaluate(() => window.__audios[1].dispatchEvent(new Event('ended')));
    await page.waitForFunction(() => window.__played.length === 3);
    await page.evaluate(() => window.__audios[2].dispatchEvent(new Event('ended')));
    await page.waitForFunction(sel => !document.querySelector(sel).classList.contains('speaking'), redrawn);
    assert.equal(await page.getAttribute(redrawn, 'aria-label'), 'Antwort vorlesen', 'after the last part: ready again');
    // A second click stops.
    await page.click(redrawn);
    await page.waitForFunction(() => window.__played.length === 4);
    await page.click(redrawn);
    assert.doesNotMatch(await page.getAttribute(redrawn, 'class'), /speaking/, 'second click stops');
    assert.equal(await page.evaluate(() => window.__audios.some(a => a.__paused)), true, 'the audio is paused');
    // New answers are not read on their own while the switch is off.
    const before = calls.filter(c => c.action === 'plan').length;
    await page.evaluate(() => window.CoreAIVoiceRead.onAnswer({id: 42, session_id: 's1'}));
    await page.waitForTimeout(150);
    assert.equal(calls.filter(c => c.action === 'plan').length, before, 'auto-read off: nothing happens');
    // KI-Profil switch: visible, off, saves.
    assert.equal(await page.isVisible('#voiceAutoReadRow'), true);
    assert.equal(await page.isChecked('#voiceAutoRead'), false);
    await page.click('#voiceAutoRead');
    await page.waitForFunction(() => document.getElementById('voiceAutoRead').disabled === false);
    const saved = calls.find(c => c.action === 'settings');
    assert.deepEqual(saved.post, {auto_read: true});
    assert.equal(saved.csrf, 'tok');
    assert.equal(await page.isChecked('#voiceAutoRead'), true);
    assert.deepEqual(errors, []);
    await page.close();

    // Auto-read on: a finished answer is read.
    ({page, calls, errors} = await open(browser, {allowed: true, available: true, loading: false, auto_read: true}));
    await addButton(page, 77);
    await page.evaluate(() => window.CoreAIVoiceRead.onAnswer({id: 77, session_id: 's1'}));
    await page.waitForFunction(() => window.__played.length === 1);
    assert.deepEqual(calls.find(c => c.action === 'plan').post, {session_id: 's1', message_id: 77}, 'the new answer is read');
    await page.close();

    // Nothing to read.
    ({page, calls, errors} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, {ok: false, error: 'nothing_to_read'}));
    button = '#' + await addButton(page, 5);
    await page.click(button);
    await page.waitForFunction(sel => document.querySelector(sel).classList.contains('error'), button);
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Nichts zum Vorlesen');
    assert.equal(await page.evaluate(() => window.__played.length), 0);
    await page.close();

    // Eve still loading: the button waits.
    ({page, calls, errors} = await open(browser, {allowed: true, available: false, loading: true, auto_read: false}));
    button = '#' + await addButton(page, 6);
    assert.equal(await page.isDisabled(button), true);
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Maras Stimme wird geladen');
    assert.equal(await page.textContent('#voiceAutoReadNote'), 'Maras Stimme wird gerade geladen.');
    await page.close();

    // A server without the voice service: nothing shown.
    ({page, calls, errors} = await open(browser, {allowed: true, available: false, loading: false, auto_read: false}));
    button = '#' + await addButton(page, 9);
    assert.equal(await page.isVisible(button), false, 'no voice service: no button');
    assert.equal(await page.isVisible('#voiceAutoReadRow'), false, 'no voice service: no switch');
    await page.close();

    // Guests: no button, no switch, nothing read.
    ({page, calls, errors} = await open(browser, {allowed: false, available: false, loading: false, auto_read: false}));
    button = '#' + await addButton(page, 8);
    assert.equal(await page.isVisible(button), false, 'guests never see the button');
    assert.equal(await page.isVisible('#voiceAutoReadRow'), false);
    await page.evaluate(() => window.CoreAIVoiceRead.onAnswer({id: 8, session_id: 's1'}));
    await page.waitForTimeout(150);
    assert.equal(calls.filter(c => c.action === 'plan').length, 0);
    assert.deepEqual(errors, []);
    await page.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI voice read UI: plan with CSRF, part by part with preloading, stop, redraw, auto-read, nothing to read, loading, guests, KI-Profil switch OK');
})().catch(error => { console.error(error); process.exit(1); });
