// CoreAI 0.7.14: Settings > Stimme after the reference screenshots, in a real browser (Playwright).
//   node scripts/voice-settings-ui-selftest.cjs
// A stand-in for api/voice.php keeps the choices. Checks: the page (Stimme with the chosen voice,
// Sprache, Diktieren, Zuletzt verwendete Aufnahmen, reading aloud and the terms below); the voice
// dialog grouped by language with every voice, a tap plays the preview (asked again while the voice
// loads, the reason when it cannot), "Fertig" saves only what changed, "Abbrechen", the cross and
// Escape keep the old choice; the language menu with "Automatisch erkennen"; the dictation switch;
// a refused save goes back; the recordings of this account only (newest first, play, save as a
// file, delete, "Alle löschen" after a second click, at most 20); guests; a phone without sideways
// scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';
const KEY = 'aaaabbbbccccddddeeeeffff';
const registry = JSON.parse(fs.readFileSync(path.join(root, 'config/voice/mara-voice.json'), 'utf8'));
const VOICES = registry.choices.voices.map(v => ({name: v.name, label: v.label, gender: v.gender, license: v.license, origin: v.origin, languages: ['de', 'en']}));

function wav(seconds) {
  const samples = Math.round(16000 * seconds);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + samples * 2, 4); head.write('WAVE', 8); head.write('fmt ', 12); head.writeUInt32LE(16, 16);
  head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24); head.writeUInt32LE(32000, 28);
  head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(samples * 2, 40);
  const data = Buffer.alloc(samples * 2);
  for (let i = 0; i < samples; i++) data.writeInt16LE(Math.round(3000 * Math.sin(2 * Math.PI * 330 * i / 16000)), i * 2);
  return Buffer.concat([head, data]);
}
const WAV = wav(0.6);

function voiceServer(guest) {
  const st = {chosen: {de: 'eve', en: 'eve'}, input: '', dictation: true, posts: [], previews: [], failNext: '', previewErrors: []};
  const prefs = () => ({ok: true, voices: VOICES, default_voice: 'eve', chosen: st.chosen, input_language: st.input,
    input_languages: ['de', 'en', 'fr', 'it', 'pt', 'es'], main_language: 'de', dictation: st.dictation, auto_read: false,
    prefs_ready: true, recordings_key: KEY, csrf_token: 'tok'});
  function handle(method, url, body) {
    const action = url.searchParams.get('action');
    if (action === 'status') return {json: guest ? {ok: true, allowed: false} : {ok: true, allowed: true, available: true, loading: false, auto_read: false, settings_ready: true, csrf_token: 'tok'}};
    if (guest) return {status: 403, json: {ok: false, error: 'guests_not_allowed'}};
    if (action === 'prefs') return {json: prefs()};
    if (action === 'preview') {
      st.previews.push(url.searchParams.get('language') + ':' + url.searchParams.get('voice'));
      const error = st.previewErrors.shift();
      if (error) return {status: 503, json: {ok: false, error}};
      return {status: 200, body: WAV, type: 'audio/wav'};
    }
    if (method === 'POST' && action === 'settings') {
      st.posts.push(body);
      if (st.failNext) { const error = st.failNext; st.failNext = ''; return {status: 503, json: {ok: false, error}}; }
      if (body.voice_de) st.chosen = Object.assign({}, st.chosen, {de: body.voice_de});
      if (body.voice_en) st.chosen = Object.assign({}, st.chosen, {en: body.voice_en});
      if (typeof body.input_language === 'string') st.input = body.input_language;
      if (typeof body.dictation === 'boolean') st.dictation = body.dictation;
      return {json: {ok: true, auto_read: false, chosen: st.chosen, input_language: st.input, dictation: st.dictation}};
    }
    return {status: 404, json: {ok: false, error: 'unknown_action'}};
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'voice_input.php': {ok: true, allowed: true, hears: true, ready: true, max_seconds: 120, terms: [], terms_ready: true, terms_max: 40, dictation: true,
    recordings_key: KEY, csrf_token: 'tok'},
  'health.php': {ok: true, version: '0.7.14', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/voice.php') {
      const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null;
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'every save with CSRF');
      const out = srv.handle(req.method(), u, body);
      return out.body ? route.fulfill({status: out.status, contentType: out.type, body: out.body}) : route.fulfill({status: out.status || 200, json: out.json});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#stimme');
  return {context, page, errors};
}

const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const status = page => page.textContent('#vsStatus');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--autoplay-policy=no-user-gesture-required']});
  try {
    let srv = voiceServer(false);
    let {context, page, errors} = await open(browser, srv);
    await until(page, () => !document.getElementById('vsVoiceOpen').disabled);

    // The page like the reference.
    assert.equal(await page.textContent('#vsVoiceOpen'), 'Eve');
    assert.equal(await page.textContent('#vsLabel-voice'), 'Stimme');
    assert.equal(await page.textContent('#vsLanguage span'), 'Deutsch', 'unset: the main language');
    assert.equal(await page.isChecked('#vsDictation'), true, 'dictation on by default');
    assert.equal(await page.textContent('#vsLabel-dictation'), 'Diktierfunktion aktivieren');
    assert.equal(await page.textContent('#vsRecordingsNote'), 'Deine letzten 20 Aufnahmen sind auf diesem Gerät gespeichert.');
    assert.equal(await status(page), '');
    assert.deepEqual(await page.$$eval('#view-stimme .sec-heading', l => l.map(x => x.textContent)), ['Diktieren', 'Vorlesen und Mitschrift']);
    assert.equal(await page.isVisible('#voiceAutoReadRow'), true, 'reading aloud stays on the page');
    assert.equal(await page.isVisible('#voiceTermsRow'), true, 'the terms stay on the page');
    assert.match(await page.textContent('#vsAttribution'), /Kyutai.*CC BY 4\.0.*CC0/s);
    if (shots) await page.locator('#view-stimme').screenshot({path: path.join(shots, 'voice-page.png')});

    // The voice dialog: grouped by language, every voice, Eve chosen.
    await page.click('#vsVoiceOpen');
    await page.waitForSelector('#vsVoiceDialog:not([hidden])');
    assert.deepEqual(await page.$$eval('.vs-group-title', l => l.map(x => x.textContent)), ['Deutsch', 'Englisch']);
    for (const code of ['de', 'en']) {
      assert.deepEqual(await page.$$eval(`.vs-voice[data-language="${code}"] b`, l => l.map(x => x.textContent)), VOICES.map(v => v.label), code + ': every voice');
      assert.deepEqual(await page.$$eval(`.vs-voice[data-language="${code}"][aria-checked="true"]`, l => l.map(x => x.dataset.voice)), ['eve']);
    }
    assert.equal(await page.textContent('.vs-voice[data-language="de"][data-voice="eve"] small'), 'weiblich · CC BY 4.0 · Standard');
    assert.equal(await page.textContent('.vs-voice[data-language="de"][data-voice="bill_boerst"] small'), 'männlich · CC0 1.0');
    assert.equal(await page.evaluate(() => document.activeElement.dataset.voice), 'eve', 'focus on the chosen voice');

    // A tap chooses and plays the preview.
    await page.click('.vs-voice[data-language="de"][data-voice="anna"]');
    await until(page, () => document.querySelector('.vs-voice[data-voice="anna"][data-language="de"]').classList.contains('is-playing'));
    assert.deepEqual(srv.st.previews, ['de:anna']);
    assert.deepEqual(await page.$$eval('.vs-voice[data-language="de"][aria-checked="true"]', l => l.map(x => x.dataset.voice)), ['anna']);
    await until(page, () => !document.querySelector('.vs-voice.is-playing'), null, 6000);
    // While a voice loads, the page asks again; when it cannot, it says why.
    srv.st.previewErrors = ['voice_loading'];
    await page.click('.vs-voice[data-language="en"][data-voice="george"]');
    await until(page, () => document.getElementById('vsVoiceState').textContent === 'Die Stimme wird gerade geladen. Einen Moment …'
      || document.querySelector('.vs-voice[data-voice="george"][data-language="en"]').classList.contains('is-loading'));
    await until(page, () => document.querySelector('.vs-voice[data-voice="george"][data-language="en"]').classList.contains('is-playing'), null, 6000);
    assert.deepEqual(srv.st.previews.slice(-2), ['en:george', 'en:george'], 'asked again after "loading"');
    srv.st.previewErrors = ['voice_unavailable'];
    await page.click('.vs-voice[data-language="en"][data-voice="jane"]');
    await until(page, () => /Sprachdienst ist gerade nicht erreichbar/.test(document.getElementById('vsVoiceState').textContent));
    assert.equal(await page.getAttribute('.vs-voice[data-language="en"][data-voice="jane"]', 'aria-checked'), 'true', 'chosen even without a preview');
    // The second preview of a voice comes from the page, not the server.
    const asked = srv.st.previews.length;
    await page.click('.vs-voice[data-language="de"][data-voice="anna"]');
    await until(page, () => document.querySelector('.vs-voice[data-voice="anna"][data-language="de"]').classList.contains('is-playing'));
    assert.equal(srv.st.previews.length, asked, 'kept in the page');
    if (shots) await page.screenshot({path: path.join(shots, 'voice-dialog.png')});
    // "Fertig" saves only what changed.
    await page.click('#vsVoiceDone');
    await page.waitForSelector('#vsVoiceDialog', {state: 'hidden'});
    assert.deepEqual(srv.st.posts.pop(), {voice_de: 'anna', voice_en: 'jane'});
    assert.equal(await page.textContent('#vsVoiceOpen'), 'Anna / Jane');
    assert.equal(await status(page), 'Stimme gespeichert.');
    // "Abbrechen", the cross and Escape keep the old choice and save nothing.
    for (const close of ['#vsVoiceCancel', '#vsVoiceClose', 'Escape']) {
      await page.click('#vsVoiceOpen');
      await page.click('.vs-voice[data-language="de"][data-voice="paul"]');
      if (close === 'Escape') await page.keyboard.press('Escape'); else await page.click(close);
      await page.waitForSelector('#vsVoiceDialog', {state: 'hidden'});
      assert.equal(await page.textContent('#vsVoiceOpen'), 'Anna / Jane', close + ' keeps the choice');
    }
    assert.equal(srv.st.posts.length, 0, 'nothing saved');
    // "Fertig" without a change saves nothing; the same voice in both languages shows one name.
    await page.click('#vsVoiceOpen');
    await page.click('#vsVoiceDone');
    assert.equal(srv.st.posts.length, 0);
    await page.click('#vsVoiceOpen');
    await page.click('.vs-voice[data-language="en"][data-voice="anna"]');
    await page.click('#vsVoiceDone');
    await until(page, () => document.getElementById('vsVoiceOpen').textContent === 'Anna');
    assert.deepEqual(srv.st.posts.pop(), {voice_en: 'anna'});
    // A refused save keeps the dialog open with the reason.
    srv.st.failNext = 'missing_schema_036';
    await page.click('#vsVoiceOpen');
    await page.click('.vs-voice[data-language="de"][data-voice="michael"]');
    await page.click('#vsVoiceDone');
    await until(page, () => /Migration 036/.test(document.getElementById('vsVoiceState').textContent));
    assert.equal(await page.isVisible('#vsVoiceDialog'), true);
    await page.click('#vsVoiceCancel');
    assert.equal(await page.textContent('#vsVoiceOpen'), 'Anna');
    srv.st.posts.length = 0;

    // Sprache: "Automatisch erkennen" and the languages.
    await page.click('#vsLanguage');
    await page.waitForSelector('#vsLanguageMenu:not([hidden])');
    assert.deepEqual(await page.$$eval('#vsLanguageMenu .pz-option b', l => l.map(x => x.textContent)),
      ['Automatisch erkennen', 'Deutsch', 'Englisch', 'Französisch', 'Italienisch', 'Portugiesisch', 'Spanisch']);
    assert.deepEqual(await page.$$eval('#vsLanguageMenu [aria-checked="true"]', l => l.map(x => x.dataset.value)), ['de']);
    if (shots) await page.screenshot({path: path.join(shots, 'voice-language.png')});
    await page.click('#vsLanguageMenu [data-value="auto"]');
    await until(page, () => document.getElementById('vsStatus').textContent === 'Sprache gespeichert.');
    assert.deepEqual(srv.st.posts.pop(), {input_language: 'auto'});
    assert.equal(await page.textContent('#vsLanguage span'), 'Automatisch erkennen');
    srv.st.failNext = 'voice_failed';
    await page.click('#vsLanguage');
    await page.click('#vsLanguageMenu [data-value="en"]');
    await until(page, () => /Sprache ließ sich nicht speichern/.test(document.getElementById('vsStatus').textContent));
    assert.equal(await page.textContent('#vsLanguage span'), 'Automatisch erkennen', 'refused: back to the old language');
    srv.st.posts.length = 0;
    await page.click('#vsLanguage');
    await page.keyboard.press('Escape');
    assert.equal(await page.isHidden('#vsLanguageMenu'), true, 'Escape closes the menu');

    // Diktieren.
    await page.click('label[for="vsDictation"]');
    await until(page, () => document.getElementById('vsStatus').textContent === 'Diktierfunktion gespeichert.');
    assert.deepEqual(srv.st.posts.pop(), {dictation: false});
    assert.equal(await page.isChecked('#vsDictation'), false);
    srv.st.failNext = 'voice_failed';
    await page.click('label[for="vsDictation"]');
    await until(page, () => /Diktierfunktion ließ sich nicht speichern/.test(document.getElementById('vsStatus').textContent));
    await until(page, () => !document.getElementById('vsDictation').disabled);
    assert.equal(await page.isChecked('#vsDictation'), false, 'refused: the switch goes back');

    // Zuletzt verwendete Aufnahmen: this account's only, newest first.
    await page.evaluate(async key => {
      const blob = new Blob([new Uint8Array(4096)], {type: 'audio/webm'});
      await window.CoreAIRecordings.add(key, {blob, kind: 'stimmsequenz', seconds: 72, state: 'nicht gesendet'});
      await new Promise(r => setTimeout(r, 15));
      await window.CoreAIRecordings.add(key, {blob, kind: 'diktat', seconds: 5, text: 'Bitte erinnere mich morgen an den Termin.', state: 'mitgeschrieben'});
      await window.CoreAIRecordings.add('ffffffffffffffffffffffff', {blob, kind: 'diktat', seconds: 3, text: 'Ein anderes Konto'});
    }, KEY);
    await page.click('#vsRecordingsRow .sec-copy');
    await page.waitForSelector('#vsRecDialog:not([hidden]) .vs-rec');
    assert.deepEqual(await page.$$eval('.vs-rec-head b', l => l.map(x => x.textContent)), ['Diktat', 'Stimmsequenz']);
    assert.equal(await page.textContent('.vs-rec-text'), 'Bitte erinnere mich morgen an den Termin.');
    assert.match(await page.textContent('.vs-rec >> nth=1 >> .vs-rec-head small'), /^\d\d\.\d\d\.\d{4}, \d\d:\d\d · 1:12 · nicht gesendet$/);
    assert.equal((await page.content()).includes('Ein anderes Konto'), false, 'never another account\'s recordings');
    assert.match(await page.getAttribute('.vs-rec >> nth=0 >> a', 'download'), /^Diktat-\d{4}-\d\d-\d\d_\d\d-\d\d-\d\d\.webm$/);
    assert.match(await page.getAttribute('.vs-rec >> nth=1 >> a', 'download'), /^Stimmsequenz-\d{4}-\d\d-\d\d_\d\d-\d\d-\d\d\.webm$/);
    assert.match(await page.getAttribute('.vs-rec >> nth=0 >> audio', 'src'), /^blob:/);
    if (shots) await page.screenshot({path: path.join(shots, 'voice-recordings.png')});
    await page.click('.vs-rec >> nth=1 >> .vs-danger');
    await until(page, () => document.querySelectorAll('.vs-rec').length === 1);
    await page.click('#vsRecClear');
    assert.equal(await page.textContent('#vsRecClear'), 'Wirklich alle löschen', 'a second click deletes all');
    assert.equal(await page.$$eval('.vs-rec', l => l.length), 1);
    await page.click('#vsRecClear');
    await until(page, () => document.getElementById('vsRecState').textContent === 'Noch keine Aufnahmen auf diesem Gerät.');
    assert.equal(await page.isDisabled('#vsRecClear'), true);
    assert.equal(await page.evaluate(() => window.CoreAIRecordings.list('ffffffffffffffffffffffff').then(l => l.length)), 1, 'the other account keeps its own');
    await page.click('#vsRecDone');
    await page.waitForSelector('#vsRecDialog', {state: 'hidden'});
    // At most 20 per account; the oldest goes.
    const kept = await page.evaluate(async key => {
      const blob = new Blob([new Uint8Array(64)], {type: 'audio/webm'});
      for (let i = 0; i < 22; i++) await window.CoreAIRecordings.add(key, {blob, kind: 'diktat', seconds: i, text: 'Nummer ' + i});
      const list = await window.CoreAIRecordings.list(key);
      return {count: list.length, texts: list.map(x => x.text)};
    }, KEY);
    assert.equal(kept.count, 20);
    assert.equal(kept.texts.includes('Nummer 0') || kept.texts.includes('Nummer 1'), false, 'the two oldest went');
    assert.deepEqual(errors, []);
    await context.close();

    // Phone: everything fits, no sideways scrolling, the dialog fills the screen.
    srv = voiceServer(false);
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    await until(page, () => !document.getElementById('vsVoiceOpen').disabled);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'no sideways scrolling');
    await page.click('#vsVoiceOpen');
    const box = await page.locator('#vsVoiceDialog .pz-sheet').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 390 && box.y >= 0 && box.y + box.height <= 844, 'the dialog fits: ' + JSON.stringify(box));
    assert.equal(await page.isVisible('#vsVoiceDone'), true);
    if (shots) await page.screenshot({path: path.join(shots, 'voice-dialog-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();

    // Guests: no choices, the reason, nothing to click.
    srv = voiceServer(true);
    ({context, page, errors} = await open(browser, srv));
    await until(page, () => /nur für angemeldete Konten/.test(document.getElementById('vsStatus').textContent));
    assert.equal(await page.isDisabled('#vsVoiceOpen'), true);
    assert.equal(await page.isDisabled('#vsLanguage'), true);
    assert.equal(await page.isDisabled('#vsDictation'), true);
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI voice settings UI: page after the reference, voice dialog by language with preview (asked again while loading, reasons),'
    + ' Fertig saves only changes, Abbrechen/cross/Escape keep, Sprache with Automatisch erkennen, Diktieren, refused saves go back,'
    + ' recordings of this account only (newest first, play, save, delete, all after a second click, at most 20), phone, guests OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
