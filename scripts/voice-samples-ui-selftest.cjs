// CoreAI 0.5.94: Admin Core > Betrieb > Stimmproben in a real browser (Playwright).
//   node scripts/voice-samples-ui-selftest.cjs
// Serves the dedicated /admin/ page with mocked APIs and checks: the Inhaber sees every
// voice with its license badge, German figures, one play button per sentence
// (a missing sample is disabled), playing asks admin.php for exactly that
// sample of that run and a second click stops it, older runs can be picked,
// an empty server explains how to start the test, an Admin never sees the
// card, and a phone shows it without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

const SENTENCES = [
  {id: 's1', topic: 'Begrüßung', text: 'Hallo, ich bin Mara. Schön, dass du da bist.', spoken: 'Hallo, ich bin Mara. Schön, dass du da bist.'},
  {id: 's2', topic: 'Zahlen', text: 'Dein Kontextfenster ist auf 32.768 Tokens eingestellt.',
    spoken: 'Dein Kontextfenster ist auf zweiunddreißigtausendsiebenhundertachtundsechzig Tokens eingestellt.'},
  // 0.6.6: English test sentences for the English voices.
  {id: 'e1', topic: 'Englisch, Begrüßung', text: "Hello, I'm Mara.", spoken: "Hello, I'm Mara.", language: 'en'},
];
const sample = (voice, sid, rtf) => ({sentence: sid, file: `${voice}-4c-${sid}.wav`, synth_s: 0.5, audio_s: 2.5, rtf, first_audio_s: 0.12});
function voice(id, label, status, ok, samples, extra) {
  return Object.assign({id, group: id.replace(/-[a-z_]+$/, ''), engine: 'pocket', label, model: 'Pocket TTS Deutsch (6 Schichten)',
    gender: 'weiblich', status, voice_license: status === 'frei' ? 'CC BY 4.0 (VCTK p228, Namensnennung)' : 'M-AILABS-Lizenz, vor einer Auslieferung prüfen',
    weights_license: 'CC BY 4.0 (Kyutai, Namensnennung)', code_license: 'MIT', origin: 'kyutai/tts-voices vctk/p228', note: '', ok,
    error: ok ? '' : 'RuntimeError: SHA-256 weicht ab: hf/x', cores: 4, load_s: 3.456, peak_rss_mb: 912.4, rtf_median: ok ? 0.214 : null,
    first_audio_median_s: ok ? 0.118 : null, samples}, extra || {});
}
const NEW = {stamp: '20261104-153000', created_at: '2026-11-04T14:30:00+00:00', sample_cores: 4, sentences: SENTENCES, errors: 1,
  voices: [
    voice('pocket-de-anna', 'Anna', 'frei', true, [sample('pocket-de-anna', 's1', 0.21), sample('pocket-de-anna', 's2', 0.22)]),
    voice('piper-ramona', 'Ramona', 'pruefen', true, [sample('piper-ramona', 's1', 0.05)], {model: 'Piper, Ramona (low, 16 kHz)', code_license: 'GPL-3.0-or-later'}),
    voice('chatterbox-kerstin', 'Kerstin (Klon)', 'frei', false, []),
    voice('pocket-en-eve_int', 'Eve englisch', 'frei', true, [sample('pocket-en-eve_int', 'e1', 0.3)], {language: 'en', model: 'Pocket TTS Englisch'}),
  ],
  groups: [{id: 'pocket-de', label: 'Pocket TTS Deutsch (6 Schichten)', engine_label: 'Pocket TTS', package: 'pocket-tts==4.0.1', code_license: 'MIT',
    code_note: 'Gewichte ohne Stimmklon (CC BY 4.0, Namensnennung Kyutai).', files: 9,
    runs: [{cores: 2, ok: true, error: '', load_s: 3.1, warmup_s: 0.4, peak_rss_mb: 880, rtf_median: 0.41, first_audio_median_s: 0.2},
      {cores: 4, ok: true, error: '', load_s: 3.456, warmup_s: 0.35, peak_rss_mb: 912.4, rtf_median: 0.214, first_audio_median_s: 0.118}]},
    {id: 'chatterbox', label: 'Chatterbox Multilingual', engine_label: 'Chatterbox Multilingual', package: 'chatterbox-tts==0.1.7', code_license: 'MIT',
      code_note: '', files: 9, runs: [{cores: 4, ok: false, error: 'RuntimeError: SHA-256 weicht ab: hf/x', load_s: null, warmup_s: null,
        peak_rss_mb: 120, rtf_median: null, first_audio_median_s: null}]}],
};
const OLD = Object.assign({}, NEW, {stamp: '20261103-090000', errors: 0, voices: [voice('pocket-de-anna', 'Anna', 'frei', true, [sample('pocket-de-anna', 's1', 0.3)])]});
const RUNS = [{stamp: NEW.stamp, created_at: NEW.created_at, voices: 4, spoken: 3, errors: 1}, {stamp: OLD.stamp, created_at: '', voices: 1, spoken: 1, errors: 0}];

function wav() {
  const data = Buffer.alloc(3200);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + data.length, 4); head.write('WAVE', 8); head.write('fmt ', 12);
  head.writeUInt32LE(16, 16); head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22); head.writeUInt32LE(16000, 24);
  head.writeUInt32LE(32000, 28); head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34); head.write('data', 36); head.writeUInt32LE(data.length, 40);
  return Buffer.concat([head, data]);
}

function server(level, state) {
  const calls = [];
  const api = {
    'auth.php': () => ({ok: true, user_id: 1, username: 'patrick@starlight.test', display_name: 'Patrick', permission_level: level, is_guest: false, csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Patrick', assistant_name: 'Mara', avatars: {}}}),
    'user_settings.php': () => ({ok: true, csrf_token: 'tok', default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
      core_label: 'TCI: Mara Normal', permission_level: level, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true,
        memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null},
      limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192], num_ctx_available: true}, external: {enabled: false, configured: false}}),
    'knowledge.php': () => ({ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}}),
    'memories.php': () => ({ok: true, items: []}),
    'health.php': () => ({ok: true, version: '0.5.94', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'account_security.php': () => ({ok: true, sessions: []}),
    'update.php': () => ({ok: true, running: false, state: 'idle', phase: '', version: '0.5.94'}),
  };
  const route = async r => {
    const url = new URL(r.request().url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) {
      const key = rel.slice(4);
      const action = url.searchParams.get('action');
      calls.push({key, action, run: url.searchParams.get('run'), file: url.searchParams.get('file')});
      if (key === 'admin.php' && action === 'tts_runs') {
        if (level !== 0) return r.fulfill({status: 403, json: {ok: false, error: 'owner_only'}});
        const asked = url.searchParams.get('run') || '';
        if (state.empty) return r.fulfill({json: {ok: true, runs: [], run: null}});
        return r.fulfill({json: {ok: true, runs: RUNS, run: asked === OLD.stamp ? OLD : NEW}});
      }
      if (key === 'admin.php' && action === 'tts_sample') {
        return r.fulfill({status: 200, contentType: 'audio/wav', body: wav()});
      }
      if (key === 'admin.php') return r.fulfill({json: {ok: true, csrf_token: 'tok', settings: {}, stats: {}, items: [], models: [], grants: {}}});
      return r.fulfill({json: api[key] ? api[key](url) : {ok: true}});
    }
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return r.fulfill({status: 404, body: ''});
    return r.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  };
  return {calls, route};
}

async function open(browser, level, viewport, state) {
  const srv = server(level, state || {});
  const page = await browser.newPage({viewport: viewport || {width: 1440, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // Playback is recorded instead of sent to a sound card.
  await page.addInitScript(() => {
    window.__played = [];
    HTMLMediaElement.prototype.play = function () { window.__played.push(this.src); this.__on = true; return Promise.resolve(); };
    HTMLMediaElement.prototype.pause = function () { this.__on = false; };
  });
  await page.route('http://coreui.test/**', route => srv.route(route));
  await page.goto('http://coreui.test/admin/index.html#admin-betrieb');
  return {page, srv, errors};
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // The Inhaber.
    let {page, srv, errors} = await open(browser, 0);
    await page.waitForSelector('#ttsSamplesCard:not([hidden]) .tts-voice');
    assert.equal(await page.isVisible('#view-admin-betrieb'), true, 'Betrieb is open');
    assert.equal(await page.textContent('#adminTtsTitle'), 'Stimme der TCI',
      'the dedicated Admin Core heading uses the unified TCI wording');
    assert.equal(await page.textContent('#ttsMeta'),
      'Lauf vom 04.11.2026, 15:30 Uhr: 3 von 4 Stimmen gesprochen, Hörproben mit 4 Kernen, 1 Fehler (siehe report.md).');
    assert.deepEqual(await page.$$eval('#ttsSentences li', l => l.map(x => x.textContent)),
      ['Begrüßung: Hallo, ich bin Mara. Schön, dass du da bist.',
        'Zahlen: Dein Kontextfenster ist auf 32.768 Tokens eingestellt.Gesprochen als: Dein Kontextfenster ist auf zweiunddreißigtausendsiebenhundertachtundsechzig Tokens eingestellt.',
        "Englisch, Begrüßung: Hello, I'm Mara."]);
    assert.equal(await page.locator('#ttsSentences .tts-spoken').count(), 1, 'spoken text only where it differs');
    assert.deepEqual(await page.$$eval('.tts-voice-name strong', l => l.map(x => x.textContent)), ['Anna', 'Ramona', 'Kerstin (Klon)', 'Eve englisch']);
    assert.deepEqual(await page.$$eval('.tts-badge', l => l.map(x => x.textContent)), ['FREI', 'PRÜFEN', 'FREI', 'FREI'], 'license badge per voice');
    // 0.6.6: each voice gets the sentences of its language only.
    assert.equal(await page.locator('[data-voice="pocket-de-anna"] .tts-play').count(), 2, 'German voice: German sentences');
    assert.equal(await page.locator('[data-voice="pocket-en-eve_int"] .tts-play').count(), 1, 'English voice: English sentences');
    assert.equal(await page.getAttribute('[data-voice="pocket-en-eve_int"] .tts-play', 'aria-label'), 'Eve englisch, Satz 1 abspielen: Englisch, Begrüßung');
    assert.equal(await page.getAttribute('#ttsSentences li >> nth=2', 'lang'), 'en', 'English sentence marked as English');
    assert.deepEqual(await page.$$eval('[data-voice="pocket-de-anna"] .tts-metric strong', l => l.map(x => x.textContent)),
      ['0,21', '0,12 s', '912 MB', '3,5 s'], 'figures in German');
    assert.deepEqual(await page.$$eval('[data-voice="pocket-de-anna"] .tts-license dt', l => l.map(x => x.textContent)), ['Stimme', 'Gewichte', 'Engine', 'Herkunft']);
    assert.match(await page.textContent('[data-voice="piper-ramona"] .tts-license'), /GPL-3\.0-or-later/, 'engine license shown');
    // Ramona has no sample for sentence 2: disabled. Kerstin failed: error shown, both disabled.
    assert.deepEqual(await page.$$eval('[data-voice="piper-ramona"] .tts-play', l => l.map(b => b.disabled)), [false, true]);
    assert.equal(await page.textContent('[data-voice="chatterbox-kerstin"] .tts-error'), 'Keine Hörprobe: RuntimeError: SHA-256 weicht ab: hf/x');
    assert.deepEqual(await page.$$eval('[data-voice="chatterbox-kerstin"] .tts-metric strong', l => l.map(x => x.textContent)), ['-', '-', '912 MB', '3,5 s']);
    assert.equal(await page.getAttribute('[data-voice="pocket-de-anna"] .tts-play >> nth=1', 'aria-label'), 'Anna, Satz 2 abspielen: Zahlen');
    // Play: exactly that sample of that run; a second click stops it; another one switches.
    await page.click('[data-voice="pocket-de-anna"] .tts-play >> nth=1');
    let played = await page.evaluate(() => window.__played.slice());
    assert.equal(played.length, 1);
    const url = new URL(played[0]);
    assert.equal(url.pathname, '/api/admin.php');
    assert.deepEqual([url.searchParams.get('action'), url.searchParams.get('run'), url.searchParams.get('file')],
      ['tts_sample', '20261104-153000', 'pocket-de-anna-4c-s2.wav']);
    assert.equal(await page.getAttribute('[data-voice="pocket-de-anna"] .tts-play >> nth=1', 'class'), 'tts-play playing');
    await page.click('[data-voice="pocket-de-anna"] .tts-play >> nth=1');
    assert.equal(await page.locator('.tts-play.playing').count(), 0, 'second click stops');
    await page.click('[data-voice="piper-ramona"] .tts-play >> nth=0');
    await page.click('[data-voice="pocket-de-anna"] .tts-play >> nth=0');
    assert.equal(await page.locator('.tts-play.playing').count(), 1, 'one sample at a time');
    await page.$eval('#ttsAudio', a => a.dispatchEvent(new Event('ended')));
    assert.equal(await page.locator('.tts-play.playing').count(), 0, 'the end of the sample clears the button');
    // Engines table.
    await page.click('.tts-engines summary');
    assert.deepEqual(await page.$$eval('.tts-table tbody tr', rows => rows.map(r => Array.from(r.cells).map(c => c.textContent))), [
      ['Pocket TTS Deutsch (6 Schichten)', '2', '3,1', '0,40', '880', '0,41', '0,20', 'ok'],
      ['Pocket TTS Deutsch (6 Schichten)', '4', '3,5', '0,35', '912', '0,21', '0,12', 'ok'],
      ['Chatterbox Multilingual', '4', '-', '-', '120', '-', '-', 'RuntimeError: SHA-256 weicht ab: hf/x'],
    ]);
    assert.match(await page.textContent('.tts-groups'), /Pocket TTS \(pocket-tts==4\.0\.1\), Code MIT\. Gewichte ohne Stimmklon/);
    // Older run.
    assert.equal(await page.isVisible('.tts-run-pick'), true, 'run picker with two runs');
    assert.deepEqual(await page.$$eval('#ttsRunSelect option', l => l.map(o => o.textContent)),
      ['04.11.2026, 15:30 Uhr (3 von 4)', '03.11.2026, 09:00 Uhr (1 von 1)']);
    await page.selectOption('#ttsRunSelect', OLD.stamp);
    await page.waitForFunction(() => document.querySelectorAll('.tts-voice').length === 1);
    assert.equal(srv.calls.filter(c => c.action === 'tts_runs').pop().run, OLD.stamp, 'asks for the chosen run');
    await page.click('[data-voice="pocket-de-anna"] .tts-play >> nth=0');
    played = await page.evaluate(() => window.__played.slice());
    assert.equal(new URL(played[played.length - 1]).searchParams.get('run'), OLD.stamp, 'samples of the chosen run');
    assert.deepEqual(errors, [], 'no page errors');
    await page.close();

    // No test yet: how to start it.
    ({page, errors} = await open(browser, 0, null, {empty: true}));
    await page.waitForSelector('#ttsEmpty:not([hidden])');
    assert.equal(await page.isVisible('#ttsBody'), false);
    assert.equal(await page.textContent('#ttsEmpty code'), 'sudo bash /opt/ember-coreui/scripts/tts-spike.sh');
    assert.equal(await page.isVisible('.tts-run-pick'), false);
    assert.deepEqual(errors, []);
    await page.close();

    // An Admin: Betrieb yes, Stimmproben never.
    ({page, srv, errors} = await open(browser, 1));
    await page.waitForSelector('#view-admin-betrieb:not([hidden])');
    await page.waitForFunction(() => true);
    for (let i = 0; i < 20 && !srv.calls.some(c => c.action === 'tts_runs'); i++) await page.waitForTimeout(50);
    await page.waitForTimeout(100);
    assert.equal(await page.isVisible('#ttsSamplesCard'), false, 'hidden for an Admin');
    assert.equal(srv.calls.some(c => c.action === 'tts_sample'), false);
    assert.deepEqual(errors, []);
    await page.close();

    // Phone: no sideways scrolling, figures in two columns.
    ({page, errors} = await open(browser, 0, {width: 390, height: 844}));
    await page.waitForSelector('#ttsSamplesCard:not([hidden]) .tts-voice');
    const fit = await page.evaluate(() => ({width: document.documentElement.scrollWidth, view: window.innerWidth,
      card: document.getElementById('ttsSamplesCard').getBoundingClientRect().right}));
    assert.ok(fit.width <= fit.view, 'no sideways scrolling on a phone: ' + JSON.stringify(fit));
    assert.ok(fit.card <= fit.view, 'card within the screen');
    const cols = await page.$eval('.tts-metrics', e => getComputedStyle(e).gridTemplateColumns.split(' ').length);
    assert.equal(cols, 2, 'figures in two columns on a phone');
    assert.deepEqual(errors, []);
    await page.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI voice samples UI: voices, licenses, German figures, play and stop per sample, runs, empty state, Inhaber only, phone OK');
})().catch(error => { console.error(error); process.exit(1); });
