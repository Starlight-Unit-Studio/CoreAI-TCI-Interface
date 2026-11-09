// CoreAI 0.6.80: Darstellung, phase two, in a real browser (Playwright).
//   node scripts/appearance-ui-selftest.cjs
// settings.html with mocked account APIs: the segments for motion and difference markers,
// the font, hex values beside the color pickers (typed, short form, invalid), "Hell und Dunkel
// getrennt" with its edit target, motif export (file), copy (clipboard) and import (text, file,
// invalid), smart punctuation saved, reset keeps the send key, a phone without sideways
// scrolling. Then the chat input field: smart punctuation while typing (German and English), off
// by default, never inside code or for pasted text, Backspace undoes the last replacement, and
// the chat's own input listeners see the result.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const KEY = 'emberCoreUI.preferences.v1';
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png',
  '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg'};

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false,
    csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal',
    core_label: 'TCI Core Normal', permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true,
      memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192], num_ctx_available: true, language_available: true},
    external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'health.php': {ok: true, version: '0.6.80', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
  'account_security.php': {ok: true, sessions: []},
};

async function route(r) {
  const url = new URL(r.request().url());
  const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
  if (rel === 'harness.html') return r.fulfill({contentType: 'text/html', body: HARNESS});
  if (rel.startsWith('api/')) {
    const name = rel.slice(4);
    if (name === 'user_settings.php' && url.searchParams.has('general')) {
      return r.fulfill({json: {ok: true, csrf_token: 'tok', general: {auto_higher_core: false, thinking_level: ''}, model: 'ember-core:e4b',
        core_label: 'TCI Core Normal', available: true, auto_core_allowed: true, reasoning: {levels: [], metadata_available: true, supports_on_off: true}}});
    }
    return r.fulfill({json: api[name] || {ok: true}});
  }
  const file = path.join(root, rel || 'index.html');
  if (!fs.existsSync(file)) return r.fulfill({status: 404, body: ''});
  return r.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
}

const HARNESS = `<!doctype html><html lang="de" data-coreui-ui-lang="de"><head><meta charset="utf-8"><title>t</title></head>
<body><textarea id="inputMessage" rows="3" cols="60"></textarea>
<script src="/js/coreui-preferences.js"></script>
<script src="/js/coreui-smart-punctuation.js"></script>
<script>window.seen = []; document.getElementById('inputMessage').addEventListener('input', function (e) { window.seen.push(e.target.value); });</script>
</body></html>`;

async function openSettings(browser, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1280, height: 900}, acceptDownloads: true});
  // HTTPS like on the server: only a secure page has navigator.clipboard.
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], {origin: 'https://coreui.test'});
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://coreui.test/**', route);
  await page.goto('https://coreui.test/settings.html#anzeige');
  await page.waitForFunction(() => document.getElementById('startHealth').getAttribute('data-state') !== 'checking');
  await page.waitForFunction(() => !document.getElementById('view-anzeige').hidden);
  return {context, page, errors};
}
const stored = page => page.evaluate(k => JSON.parse(localStorage.getItem(k) || '{}'), KEY);
const rootVar = (page, name) => page.evaluate(n => document.documentElement.style.getPropertyValue(n), name);
// A switch is tapped on its visible track, like a person does.
const tapSwitch = (page, id) => page.click('#' + id + ' + i');
const segment = (page, group, value) => page.click('#' + group + ' label:has(input[value="' + value + '"])');

(async () => {
  const browser = await chromium.launch();
  try {
    let {context, page, errors} = await openSettings(browser);

    // --- Segments instead of dropdowns ------------------------------------------------------
    assert.equal(await page.isChecked('#prefMotionMode input[value="system"]'), true, 'motion follows the system by default');
    await segment(page, 'prefMotionMode', 'on');
    assert.equal((await stored(page)).motionMode, 'on');
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('pref-reduced-motion')), true);
    await segment(page, 'prefMotionMode', 'system');
    await segment(page, 'prefDiffMarkers', 'symbols');
    assert.equal((await stored(page)).diffMarkers, 'symbols');
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('pref-diff-symbols')), true);
    assert.deepEqual(await page.$$eval('#prefDiffMarkers span', s => s.map(x => x.textContent)), ['Farbe', '+/−']);
    // Arrow keys move inside a segment like any radio group.
    await page.focus('#prefDiffMarkers input[value="symbols"]');
    await page.keyboard.press('ArrowLeft');
    assert.equal((await stored(page)).diffMarkers, 'color', 'keyboard works in the segment');

    // --- Schriftart -------------------------------------------------------------------------
    assert.equal(await page.inputValue('#prefFontFamily'), 'coreai');
    const fontBefore = await page.evaluate(() => getComputedStyle(document.querySelector('.cfg-view-head h2')).fontFamily);
    assert.match(fontBefore, /Rajdhani/);
    await page.selectOption('#prefFontFamily', 'system');
    assert.equal((await stored(page)).fontFamily, 'system');
    const fonts = await page.evaluate(() => ({
      heading: getComputedStyle(document.querySelector('.cfg-view-head h2')).fontFamily,
      kicker: getComputedStyle(document.querySelector('.cfg-view-head .cfg-kicker')).fontFamily,
    }));
    assert.match(fonts.heading, /^system-ui/, 'the page follows the system font: ' + fonts.heading);
    assert.match(fonts.kicker, /Share Tech Mono/, 'technical labels stay monospace');
    await page.selectOption('#prefFontFamily', 'coreai');

    // --- Hex values beside the pickers ------------------------------------------------------
    assert.equal(await page.inputValue('#prefBackgroundHex'), '#F0F4FA', 'hex shown in capitals like the picker value');
    await page.fill('#prefBackgroundHex', '#123');
    await page.press('#prefBackgroundHex', 'Enter');
    assert.equal(await page.inputValue('#prefBackground'), '#112233', 'short form #123 becomes #112233');
    assert.equal(await page.inputValue('#prefBackgroundHex'), '#112233'.toUpperCase());
    assert.equal((await stored(page)).backgroundLight, '#112233');
    assert.equal(await rootVar(page, '--bg'), '#112233', 'applied at once');
    await page.fill('#prefBackgroundHex', 'zz9');
    await page.press('#prefBackgroundHex', 'Enter');
    assert.equal(await page.getAttribute('#prefBackgroundHex', 'aria-invalid'), 'true', 'an invalid value is marked');
    assert.equal((await stored(page)).backgroundLight, '#112233', 'and not saved');
    await page.fill('#prefBackgroundHex', 'f0f4fa');
    await page.press('#prefBackgroundHex', 'Enter');
    assert.equal(await page.getAttribute('#prefBackgroundHex', 'aria-invalid'), null);
    assert.equal((await stored(page)).backgroundLight, '#f0f4fa', 'without # works too');
    await page.evaluate(() => {
      const picker = document.getElementById('prefForeground');
      picker.value = '#224466';
      picker.dispatchEvent(new Event('input', {bubbles: true}));
      picker.dispatchEvent(new Event('change', {bubbles: true}));
    });
    assert.equal(await page.inputValue('#prefForegroundHex'), '#224466', 'the picker updates the hex text');
    assert.equal((await stored(page)).foregroundLight, '#224466');

    // --- Hell und Dunkel getrennt -----------------------------------------------------------
    assert.equal(await page.isHidden('#prefEditModeRow'), true, 'no edit target while the look is shared');
    await page.selectOption('#prefAccent', 'green');
    await tapSwitch(page, 'prefSeparateModes');
    let prefs = await stored(page);
    assert.equal(prefs.separateModes, true);
    assert.deepEqual([prefs.accentDark, prefs.motifDark, prefs.fontFamilyDark], ['green', 'holo', 'coreai'],
      'switching on starts Dunkel from the current look');
    assert.equal(await page.isVisible('#prefEditModeRow'), true);
    assert.equal(await page.isChecked('#prefEditMode input[value="light"]'), true, 'editing the mode on screen');
    await segment(page, 'prefEditMode', 'dark');
    await page.selectOption('#prefAccent', 'amber');
    await page.selectOption('#prefMotif', 'quiet');
    await page.fill('#prefBackgroundHex', '#0a1020');
    await page.press('#prefBackgroundHex', 'Enter');
    prefs = await stored(page);
    assert.deepEqual([prefs.accent, prefs.accentDark, prefs.motif, prefs.motifDark, prefs.backgroundDark, prefs.backgroundLight],
      ['green', 'amber', 'holo', 'quiet', '#0a1020', '#f0f4fa'], 'Dunkel edited while Hell is on screen');
    assert.equal(await rootVar(page, '--cx'), '#299b70', 'the screen keeps the light look');
    await segment(page, 'prefEditMode', 'light');
    assert.equal(await page.inputValue('#prefAccent'), 'green', 'back to Hell shows its own accent');
    assert.equal(await page.inputValue('#prefBackgroundHex'), '#F0F4FA');
    await page.click('input[name="coreuiTheme"][value="dark"] + span');
    assert.equal(await rootVar(page, '--cx'), '#ca852a', 'Dunkel uses its own accent');
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('pref-motif-quiet')), true);
    assert.equal(await page.isChecked('#prefEditMode input[value="dark"]'), true, 'after a mode change the screen mode is edited');
    await tapSwitch(page, 'prefSeparateModes');
    assert.equal(await rootVar(page, '--cx'), '#299b70', 'off again: Dunkel uses the shared look (green)');
    assert.equal((await stored(page)).accentDark, 'amber', 'Dunkel keeps its own choice for later');
    await page.click('input[name="coreuiTheme"][value="light"] + span');

    // --- Motif: export, copy, import -------------------------------------------------------
    const downloadWait = page.waitForEvent('download');
    await page.click('#motifExport');
    const download = await downloadWait;
    assert.match(download.suggestedFilename(), /^coreai-motiv-\d{4}-\d{2}-\d{2}\.json$/);
    const file = path.join(os.tmpdir(), 'coreai-motiv-test-' + process.pid + '.json');
    await download.saveAs(file);
    const exported = JSON.parse(fs.readFileSync(file, 'utf8'));
    fs.unlinkSync(file);
    assert.equal(exported.format, 'coreai-motiv');
    assert.equal(exported.light.accent, 'green');
    assert.equal(exported.light.foreground, '#224466');
    await page.click('#motifCopy');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').textContent === 'MOTIV KOPIERT');
    const copied = JSON.parse(await page.evaluate(() => navigator.clipboard.readText()));
    assert.deepEqual(copied, exported, 'copy puts the same motif on the clipboard');
    // Without the clipboard API (an old browser) the copy still works through a hidden text field.
    await page.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', {value: undefined, configurable: true});
      document.getElementById('settingsSaveState').textContent = '';
    });
    await page.click('#motifCopy');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').textContent === 'MOTIV KOPIERT');
    // Import: invalid, then text, then a file.
    assert.equal(await page.isHidden('#motifImportPanel'), true);
    await page.click('#motifImport');
    assert.equal(await page.getAttribute('#motifImport', 'aria-expanded'), 'true');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'motifImportText');
    await page.fill('#motifImportText', '{"format":"anders"}');
    await page.click('#motifImportApply');
    assert.match(await page.textContent('#motifImportStatus'), /kein gültiges CoreAI-Motiv/);
    assert.equal((await stored(page)).accent, 'green', 'nothing changed');
    const other = JSON.parse(JSON.stringify(exported));
    other.light.accent = 'cyan';
    other.light.background = '#eef5ff';
    await page.fill('#motifImportText', JSON.stringify(other));
    await page.click('#motifImportApply');
    assert.equal(await page.isHidden('#motifImportPanel'), true, 'the panel closes');
    prefs = await stored(page);
    assert.deepEqual([prefs.accent, prefs.backgroundLight], ['cyan', '#eef5ff'], 'imported');
    assert.equal(await page.inputValue('#prefAccent'), 'cyan', 'the fields show it');
    await page.click('#motifImport');
    other.light.accent = 'violet';
    await page.setInputFiles('#motifImportFile', {name: 'motiv.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(other))});
    await page.waitForFunction(k => JSON.parse(localStorage.getItem(k)).accent === 'violet', KEY);
    await page.click('#motifImport');
    await page.click('#motifImportCancel');
    assert.equal(await page.isHidden('#motifImportPanel'), true);

    // --- Smart punctuation switch, reset keeps the send key --------------------------------
    assert.equal((await stored(page)).smartPunctuation, false, 'off by default');
    await tapSwitch(page, 'prefSmartPunctuation');
    assert.equal((await stored(page)).smartPunctuation, true);
    await page.evaluate(k => {
      const p = JSON.parse(localStorage.getItem(k));
      p.sendKey = 'mod-enter';
      localStorage.setItem(k, JSON.stringify(p));
    }, KEY);
    await page.click('#resetPreferences');
    prefs = await stored(page);
    assert.deepEqual([prefs.sendKey, prefs.smartPunctuation, prefs.fontFamily, prefs.separateModes, prefs.accent],
      ['mod-enter', false, 'coreai', false, 'blue'], 'reset: appearance back, send key kept');
    assert.equal(await page.inputValue('#prefBackgroundHex'), '#F0F4FA');
    if (process.env.COREAI_SHOT) {
      await tapSwitch(page, 'prefSeparateModes');
      await page.click('#motifImport');
      await page.locator('.appearance-motif-card').screenshot({path: process.env.COREAI_SHOT.replace('.png', '-motif.png')});
      await page.locator('.appearance-advanced-card').screenshot({path: process.env.COREAI_SHOT.replace('.png', '-advanced.png')});
    }
    assert.deepEqual(errors, []);
    await context.close();

    // --- A phone in dark mode: nothing sideways ---------------------------------------------
    ({context, page, errors} = await openSettings(browser, {width: 390, height: 844}));
    await page.evaluate(k => localStorage.setItem(k, JSON.stringify({theme: 'dark', separateModes: true})), KEY);
    await page.reload();
    await page.waitForFunction(() => document.getElementById('startHealth').getAttribute('data-state') !== 'checking');
    await page.click('#motifImport');
    const fit = await page.evaluate(() => {
      const view = document.getElementById('view-anzeige');
      const wide = Array.from(view.querySelectorAll('.appearance-segment, .appearance-color, .appearance-motif-tools, .appearance-import'))
        .filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => el.className);
      return {scroll: document.documentElement.scrollWidth, view: innerWidth, wide};
    });
    assert.ok(fit.scroll <= fit.view + 1 && fit.wide.length === 0, 'fits a phone: ' + JSON.stringify(fit));
    if (process.env.COREAI_SHOT) await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-phone.png'), fullPage: false});
    assert.deepEqual(errors, []);
    await context.close();

    // --- The chat input field ---------------------------------------------------------------
    // A German browser: the interface language (Auto) is German, so German quotes.
    context = await browser.newContext({locale: 'de-DE'});
    page = await context.newPage();
    errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://coreui.test/**', route);
    await page.goto('http://coreui.test/harness.html');
    const field = '#inputMessage';
    const typeIn = async text => { await page.fill(field, ''); await page.focus(field); await page.keyboard.type(text); return page.inputValue(field); };
    assert.equal(await typeIn('Er sagte "Hallo" -> gut...'), 'Er sagte "Hallo" -> gut...', 'off by default: nothing changes');
    await page.evaluate(() => window.CoreUIPreferences.save(Object.assign(window.CoreUIPreferences.load(), {smartPunctuation: true})));
    assert.equal(await typeIn('Er sagte "Hallo" -> gut...'), 'Er sagte „Hallo“ → gut…');
    assert.equal(await typeIn("Das geht's, x <= 5 und a != b, Satz -- Ende"), 'Das geht’s, x ≤ 5 und a ≠ b, Satz – Ende');
    // Backspace right after a replacement brings back what was typed, then deletes normally.
    await typeIn('warte...');
    await page.keyboard.press('Backspace');
    assert.equal(await page.inputValue(field), 'warte...', 'Backspace undoes the replacement');
    await page.keyboard.press('Backspace');
    assert.equal(await page.inputValue(field), 'warte..', 'then deletes as usual');
    // Code and pasted text stay as they are.
    assert.equal(await typeIn('`x = "a" -> b`'), '`x = "a" -> b`', 'inline code untouched');
    assert.equal(await typeIn('```\nprint("hi") # ...\n```\nund "'), '```\nprint("hi") # ...\n```\nund „', 'only outside the code block');
    await page.fill(field, '');
    await page.focus(field);
    await page.keyboard.insertText('kopiert "a" -> b...');
    assert.equal(await page.inputValue(field), 'kopiert "a" -> b...', 'pasted or inserted text untouched');
    // English interface: English quotes.
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-ui-lang', 'en'));
    assert.equal(await typeIn('She said "hi"'), 'She said “hi”');
    // The chat's own listener (registered later) sees the result.
    const seen = await page.evaluate(() => window.seen[window.seen.length - 1]);
    assert.equal(seen, 'She said “hi”', 'later input listeners see the replaced text');
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI Darstellung 0.6.80 UI: segments, Schriftart, hex values, Hell/Dunkel getrennt with edit target, '
    + 'motif export/copy/import (text, file, invalid), smart punctuation switch, reset, phone fit, '
    + 'chat smart punctuation (de/en, off by default, code and paste untouched, Backspace undo) OK');
})().catch(error => { console.error('CoreAI Darstellung UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
