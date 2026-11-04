// CoreAI 0.6.1 (0.6.5): read aloud in a real browser (Playwright), js/coreui-voice-read.js.
//   node scripts/voice-read-ui-selftest.cjs
// A small page with mocked APIs: the speaker button asks for a plan with CSRF,
// plays part 0, fetches the next parts one at a time (never two at once, at
// most three ahead) into one audio element, goes on part by part, skips a part
// that fails twice and stops when two fail in a row, a second click stops,
// a redrawn row keeps the running state, guests never see it,
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

// Part n answers with a valid WAV of 100 + n samples, so a played blob tells its part.
function wav(part) {
  const data = 2 * (100 + part);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + data, 4); head.write('WAVE', 8); head.write('fmt ', 12); head.writeUInt32LE(16, 16);
  head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24); head.writeUInt32LE(32000, 28);
  head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(data, 40);
  return Buffer.concat([head, Buffer.alloc(data)]);
}

// options.parts: parts in the plan; options.fail: parts that always answer 503; options.broken: parts whose audio
// cannot be played (no WAV); options.hold: parts held until released.
async function open(browser, status, planAnswer, options = {}) {
  const calls = [];
  const held = {};
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    window.__audios = [];
    window.__played = [];
    window.__sizes = {};
    const Native = window.Audio;
    window.Audio = function () { const audio = new Native(); window.__audios.push(audio); return audio; };
    const create = URL.createObjectURL.bind(URL);
    URL.createObjectURL = blob => { const url = create(blob); window.__sizes[url] = blob.size; return url; };
    HTMLMediaElement.prototype.play = function () { window.__played.push((window.__sizes[this.src] - 44) / 2 - 100); return Promise.resolve(); };
    HTMLMediaElement.prototype.pause = function () { this.__paused = true; };
  });
  await page.route('http://coreui.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (url.pathname === '/api/voice.php') {
      const action = url.searchParams.get('action');
      const post = route.request().method() === 'POST' ? JSON.parse(route.request().postData() || '{}') : null;
      const part = url.searchParams.get('part');
      calls.push({action, post, part, id: url.searchParams.get('id'), csrf: route.request().headers()['x-coreui-csrf'] || '', done: false});
      const call = calls[calls.length - 1];
      if (action === 'status') return route.fulfill({json: Object.assign({ok: true, csrf_token: 'tok'}, status)});
      if (action === 'plan') return route.fulfill({json: planAnswer || {ok: true, id: ID, parts: options.parts || 3}});
      if (action === 'settings') return route.fulfill({json: {ok: true, auto_read: !!post.auto_read}});
      if (action === 'audio') {
        if ((options.hold || []).includes(Number(part))) await new Promise(resolve => { held[part] = resolve; });
        call.done = true;
        if ((options.fail || []).includes(Number(part))) return route.fulfill({status: 503, json: {ok: false, error: 'voice_busy'}});
        // 0.6.8: a voice that is still loading answers 503 voice_loading (options.loadingFirst: that many times).
        const loadingLeft = (options.loadingFirst || {})[part] || 0;
        if (loadingLeft > 0) {
          options.loadingFirst[part] = loadingLeft - 1;
          return route.fulfill({status: 503, json: {ok: false, error: 'voice_loading'}});
        }
        if ((options.broken || []).includes(Number(part))) {
          return route.fulfill({status: 200, contentType: 'audio/wav', body: Buffer.alloc(wav(Number(part)).length, 120)});
        }
        return route.fulfill({status: 200, contentType: 'audio/wav', body: wav(Number(part))});
      }
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://coreui.test/harness.html');
  await page.evaluate(() => window.CoreAIVoiceRead.ready());
  const audio = calls => calls.filter(c => c.action === 'audio').map(c => Number(c.part));
  const release = part => { held[part](); delete held[part]; };
  const isHeld = part => typeof held[part] === 'function';
  return {page, calls, errors, audio, release, isHeld};
}

const addButton = (page, id) => page.evaluate(id => {
  const b = window.CoreAIVoiceRead.button({id, session_id: 's1'});
  b.id = 'speak' + id + '-' + document.querySelectorAll('.msg-speak').length;
  document.getElementById('rows').appendChild(b);
  return b.id;
}, id);
const ended = page => page.evaluate(() => window.__audios[window.__audios.length - 1].dispatchEvent(new Event('ended')));
const until = (page, fn, arg) => page.waitForFunction(fn, arg, {timeout: 8000});

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // Signed in, Eve ready, auto-read off; five parts, part 1 held back for a while.
    let {page, calls, errors, audio, release, isHeld} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false},
      null, {parts: 5, hold: [1]});
    let button = '#' + await addButton(page, 42);
    assert.equal(await page.isVisible(button), true);
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Antwort vorlesen');
    await page.click(button);
    await until(page, () => window.__played.length === 1);
    const plan = calls.find(c => c.action === 'plan');
    assert.deepEqual(plan.post, {session_id: 's1', message_id: 42}, 'plan for this answer');
    assert.equal(plan.csrf, 'tok', 'with the CSRF token');
    assert.deepEqual(await page.evaluate(() => window.__played), [0], 'part 0 plays first');
    assert.equal(calls.find(c => c.action === 'audio').id, ID);
    // One part at a time: while part 1 is held, part 2 is not asked for.
    for (let i = 0; i < 50 && !isHeld(1); i++) await page.waitForTimeout(20);
    assert.equal(isHeld(1), true, 'part 1 is fetched while part 0 plays');
    await page.waitForTimeout(150);
    assert.deepEqual(audio(calls), [0, 1], 'never two parts at once');
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Vorlesen stoppen');
    assert.match(await page.getAttribute(button, 'class'), /speaking/);
    release(1);
    // Then up to three parts ahead of the playing one: 1, 2, 3, not yet 4.
    for (let i = 0; i < 100 && audio(calls).length < 4; i++) await page.waitForTimeout(20);
    await page.waitForTimeout(150);
    assert.deepEqual(audio(calls), [0, 1, 2, 3], 'three parts ahead, not more');
    assert.equal(await page.evaluate(() => window.__audios.length), 1, 'one audio element for the whole answer');
    // Part 0 ends: part 1 plays at once (already here), part 4 is fetched now.
    await ended(page);
    await until(page, () => window.__played.length === 2);
    assert.deepEqual(await page.evaluate(() => window.__played), [0, 1], 'part 1 right after part 0');
    for (let i = 0; i < 100 && audio(calls).length < 5; i++) await page.waitForTimeout(20);
    assert.deepEqual(audio(calls), [0, 1, 2, 3, 4], 'the last part follows');
    // A redrawn row keeps the running state.
    const redrawn = '#' + await addButton(page, 42);
    assert.match(await page.getAttribute(redrawn, 'class'), /speaking/, 'redrawn button shows it is speaking');
    for (let n = 2; n <= 4; n++) {
      await ended(page);
      await until(page, count => window.__played.length === count, n + 1);
    }
    assert.deepEqual(await page.evaluate(() => window.__played), [0, 1, 2, 3, 4], 'all parts in order');
    await ended(page);
    await until(page, sel => !document.querySelector(sel).classList.contains('speaking'), redrawn);
    assert.equal(await page.getAttribute(redrawn, 'aria-label'), 'Antwort vorlesen', 'after the last part: ready again');
    // A second click stops; nothing is fetched afterwards.
    await page.click(redrawn);
    await until(page, () => window.__played.length === 6);
    await page.click(redrawn);
    assert.doesNotMatch(await page.getAttribute(redrawn, 'class'), /speaking/, 'second click stops');
    assert.equal(await page.evaluate(() => window.__audios[window.__audios.length - 1].__paused), true, 'the audio is paused');
    await page.waitForTimeout(200);
    const afterStop = audio(calls).length;
    await page.waitForTimeout(300);
    assert.equal(audio(calls).length, afterStop, 'nothing fetched after stop');
    // New answers are not read on their own while the switch is off.
    const before = calls.filter(c => c.action === 'plan').length;
    await page.evaluate(() => window.CoreAIVoiceRead.onAnswer({id: 42, session_id: 's1'}));
    await page.waitForTimeout(150);
    assert.equal(calls.filter(c => c.action === 'plan').length, before, 'auto-read off: nothing happens');
    // KI-Profil switch: visible, off, saves.
    assert.equal(await page.isVisible('#voiceAutoReadRow'), true);
    assert.equal(await page.isChecked('#voiceAutoRead'), false);
    await page.click('#voiceAutoRead');
    await until(page, () => document.getElementById('voiceAutoRead').disabled === false);
    const saved = calls.find(c => c.action === 'settings');
    assert.deepEqual(saved.post, {auto_read: true});
    assert.equal(saved.csrf, 'tok');
    assert.equal(await page.isChecked('#voiceAutoRead'), true);
    assert.deepEqual(errors, []);
    await page.close();

    // A part that fails twice is skipped, the reading goes on.
    ({page, calls, errors, audio} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, null, {parts: 3, fail: [1]}));
    button = '#' + await addButton(page, 11);
    await page.click(button);
    await until(page, () => window.__played.length === 1);
    await ended(page);
    await until(page, () => window.__played.length === 2);
    assert.deepEqual(await page.evaluate(() => window.__played), [0, 2], 'part 1 skipped after two tries, part 2 plays');
    assert.equal(audio(calls).filter(p => p === 1).length, 2, 'part 1 tried twice');
    assert.match(await page.getAttribute(button, 'class'), /speaking/, 'still reading');
    await page.close();

    // A part that cannot be played gets a second try too, then it is skipped.
    ({page, calls, errors, audio} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, null, {parts: 3, broken: [1]}));
    button = '#' + await addButton(page, 13);
    await page.click(button);
    await until(page, () => window.__played.length === 1);
    await ended(page);
    await until(page, () => window.__played.length === 4);
    assert.deepEqual(await page.evaluate(() => window.__played), [0, 1, 1, 2], 'part 1 played, tried again, then part 2');
    assert.equal(audio(calls).filter(p => p === 1).length, 2, 'part 1 fetched again for the second try');
    assert.match(await page.getAttribute(button, 'class'), /speaking/, 'one broken part does not stop the reading');
    await page.close();

    // 0.6.8: while the voice loads (English the first time), the part waits; it is no failure.
    ({page, calls, errors, audio} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, null,
      {parts: 2, loadingFirst: {0: 2}}));
    button = '#' + await addButton(page, 14);
    await page.click(button);
    await until(page, sel => document.querySelector(sel).classList.contains('loading'), button);
    await until(page, () => window.__played.length === 1);
    assert.equal(audio(calls).filter(p => p === 0).length, 3, 'part 0 asked again while the voice loaded');
    assert.deepEqual(await page.evaluate(() => window.__played), [0], 'then it plays');
    await ended(page);
    await until(page, () => window.__played.length === 2);
    assert.match(await page.getAttribute(button, 'class'), /speaking/, 'loading is no failure, the reading goes on');
    await page.close();

    // Two failing parts in a row: the voice is gone, stop with a message.
    ({page, calls, errors, audio} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, null, {parts: 4, fail: [1, 2]}));
    button = '#' + await addButton(page, 12);
    await page.click(button);
    await until(page, () => window.__played.length === 1);
    await ended(page);
    await until(page, sel => document.querySelector(sel).classList.contains('error'), button);
    assert.equal(await page.getAttribute(button, 'aria-label'), 'Vorlesen fehlgeschlagen');
    assert.deepEqual(await page.evaluate(() => window.__played), [0]);
    await page.close();

    // Auto-read on: a finished answer is read.
    ({page, calls, errors} = await open(browser, {allowed: true, available: true, loading: false, auto_read: true}));
    await addButton(page, 77);
    await page.evaluate(() => window.CoreAIVoiceRead.onAnswer({id: 77, session_id: 's1'}));
    await until(page, () => window.__played.length === 1);
    assert.deepEqual(calls.find(c => c.action === 'plan').post, {session_id: 's1', message_id: 77}, 'the new answer is read');
    await page.close();

    // Nothing to read.
    ({page, calls, errors} = await open(browser, {allowed: true, available: true, loading: false, auto_read: false}, {ok: false, error: 'nothing_to_read'}));
    button = '#' + await addButton(page, 5);
    await page.click(button);
    await until(page, sel => document.querySelector(sel).classList.contains('error'), button);
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
  console.log('CoreAI voice read UI: plan with CSRF, parts fetched in order and ahead, one audio element, skip and stop on failures, stop, redraw, auto-read, nothing to read, loading, guests, KI-Profil switch OK');
})().catch(error => { console.error(error); process.exit(1); });
