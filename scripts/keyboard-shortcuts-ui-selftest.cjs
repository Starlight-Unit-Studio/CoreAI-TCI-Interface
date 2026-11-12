// CoreAI 0.7.29: Settings > Tastatur after the reference screenshots, and the shortcuts in the chat,
// in a real browser (Playwright).
//   node scripts/keyboard-shortcuts-ui-selftest.cjs
// Settings: the three groups (Nachrichteneditor, Chat, App) with their default keys, the two items
// "In Vorbereitung", recording new keys (a clash, keys that would disturb typing, the browser's own,
// Backspace removes, Esc cancels), switching one off, the send key (and the old sendKey of 0.7.28),
// Standardeinstellungen wiederherstellen, a phone without sideways scrolling.
// Chat: a small page with the ids of app.html (checked against app.html below) runs every action
// through the existing control, the two new windows (Tastenkürzel, Chat wechseln) and the send key.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';

// --- app.html has what js/coreui-shortcuts-app.js reaches for ------------------------------------------------
const app = read('app.html');
for (const id of ['inputMessage', 'btnNewSession', 'sidebarControl', 'drawer', 'reasonEffortChip', 'fileInput', 'btnDrawer'])
  assert.ok(app.includes('id="' + id + '"'), 'app.html has #' + id);
for (const action of ['delete', 'search']) assert.ok(app.includes('data-chat-action="' + action + '"'), 'chat menu has ' + action);
assert.ok(read('js/coreui-voice-input.js').includes("iconButton('btnMic'"), 'the microphone button is #btnMic');
const order = ['js/coreui-send-keys.js', 'js/coreui-shortcuts.js', 'js/console-app.js', 'js/coreui-chat-menu.js', 'js/coreui-shortcuts-app.js'];
order.reduce((at, file) => {
  const next = app.indexOf('src="./' + file + '?v=');
  assert.ok(next > at, file + ' is loaded after the one before');
  return next;
}, -1);
assert.ok(app.includes('css/coreui-shortcuts.css'), 'the chat loads the styles of the two windows');
const settingsPage = read('settings.html');
const at = (html, file) => html.indexOf('src="./' + file + '?v=');
assert.ok(at(settingsPage, 'js/coreui-shortcuts.js') > 0 && at(settingsPage, 'js/coreui-shortcuts.js') < at(settingsPage, 'js/coreui-keyboard-settings.js'),
  'settings loads the rules first');
assert.ok(!settingsPage.includes('id="generalSendKey"'), 'the old two-mode select is gone');
assert.ok(read('js/console-app.js').includes("window.addEventListener('coreai:shortcuts', refreshComposerShortcutHint)"), 'the hint follows the send key');

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 3, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 3, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.29', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>t</title>
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-shortcuts.css"></head><body>
<input id="sidebarControl" type="checkbox" checked>
<nav id="drawer"><button id="btnNewSession" type="button">Neuer Chat</button></nav>
<button id="btnDrawer" type="button">Menü</button>
<div id="chatMenu" hidden><button data-chat-action="delete" type="button">Löschen</button><button data-chat-action="search" type="button">Suchen</button></div>
<textarea id="inputMessage"></textarea><span id="composerSendKeyHint"></span>
<button id="btnMic" type="button">Mic</button>
<button id="reasonEffortChip" type="button">Denken</button>
<input id="fileInput" type="file" hidden>
<div class="chat-dialog" id="otherDialog" role="dialog" aria-modal="true" hidden><div class="chat-dialog-box">Andere</div></div>
<script>
  window.__log = [];
  window.__active = {id: 'cs_aaaaaa1', title: 'Planung'};
  window.CoreAIChatApp = {
    active: function () { return window.__active; },
    getSessions: function () { return [
      {id: 'cs_aaaaaa1', title: 'Planung', pinned: false}, {id: 'cs_aaaaaa2', title: 'Reise nach Rom', pinned: true},
      {id: 'cs_aaaaaa3', title: 'Rezepte', pinned: false}, {id: 'cs_aaaaaa4', title: 'Alt', archived: true}]; },
    select: function (id) { window.__log.push('select:' + id); return Promise.resolve(); },
    openDrawer: function () { window.__log.push('openDrawer'); document.getElementById('drawer').classList.add('open'); },
    closeDrawer: function () { window.__log.push('closeDrawer'); document.getElementById('drawer').classList.remove('open'); }
  };
  ['btnNewSession', 'btnMic', 'reasonEffortChip'].forEach(function (id) {
    document.getElementById(id).addEventListener('click', function () { window.__log.push(id); });
  });
  document.querySelectorAll('[data-chat-action]').forEach(function (b) {
    b.addEventListener('click', function () { window.__log.push('menu:' + b.getAttribute('data-chat-action')); });
  });
  document.getElementById('fileInput').click = function () { window.__log.push('fileInput'); };
</script>
<script src="/js/coreui-preferences.js"></script>
<script src="/js/coreui-send-keys.js"></script>
<script src="/js/coreui-shortcuts.js"></script>
<script src="/js/coreui-shortcuts-app.js"></script>
<script>
  var input = document.getElementById('inputMessage');
  function hint() { document.getElementById('composerSendKeyHint').textContent = window.CoreUISendKeys.hint(window.CoreUIPreferences.load()); }
  hint();
  window.addEventListener('coreai:shortcuts', hint);
  input.addEventListener('keydown', function (e) {
    if (window.CoreUISendKeys.shouldSend(e, window.CoreUIPreferences.load())) { e.preventDefault(); window.__log.push('send:' + input.value); input.value = ''; }
  });
</script></body></html>`;

async function open(browser, url, viewport, init) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  if (init) await context.addInitScript(init);
  await context.route('https://coreui.test/**', async route => {
    const u = new URL(route.request().url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(url);
  return {context, page, errors};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const rows = page => page.$$eval('#kbGroups .kb-row', l => l.map(r => [r.querySelector('strong').textContent,
  (r.querySelector('.kb-keys') || r.querySelector('.kb-planned')).textContent, r.querySelector('input').checked, r.querySelector('input').disabled]));
const stored = page => page.evaluate(() => JSON.parse(localStorage.getItem('coreai.shortcuts.v1') || 'null'));
const sendKey = page => page.evaluate(() => (JSON.parse(localStorage.getItem('emberCoreUI.preferences.v1') || '{}')).sendKey);
const status = page => page.textContent('#kbStatus');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // ===== Settings > Tastatur =====
    let {context, page, errors} = await open(browser, 'https://coreui.test/settings.html#tastatur');
    await until(page, () => document.querySelectorAll('#kbGroups .kb-row').length === 13);
    assert.equal(await page.isVisible('#view-tastatur'), true);
    assert.equal(await page.textContent('#view-tastatur .cfg-view-head p'),
      'Um ein Tastenkürzel zu ändern, wähle die Tastenkombination aus und drücke dann die neuen Tasten.');
    assert.deepEqual(await page.$$eval('#kbGroups .sec-heading', l => l.map(x => x.textContent)), ['Nachrichteneditor', 'Chat', 'App']);
    assert.deepEqual(await rows(page), [
      ['Eingabefeld fokussieren', 'Shift+Esc', true, false],
      ['Nachricht senden', 'Enter', true, false],
      ['Nachricht im Hintergrund senden', 'In Vorbereitung', false, true],
      ['Denken auswählen', 'Strg+Shift+M', true, false],
      ['Diktierfunktion ein-/ausschalten', 'Keine', true, false],
      ['Dateien anhängen', 'Strg+U', true, false],
      ['Chat löschen', 'Strg+Shift+Rücktaste', true, false],
      ['Im Chat suchen', 'Strg+Shift+F', true, false],
      ['Neuer Chat', 'Strg+Shift+O', true, false],
      ['Tastenkürzel anzeigen', 'Strg+/', true, false],
      ['Anmerkungen senden', 'In Vorbereitung', false, true],
      ['Chat wechseln …', 'Strg+K', true, false],
      ['Seitenleiste ein/aus', 'Strg+Shift+S', true, false]]);
    assert.equal(await stored(page), null, 'nothing is written before a change');
    if (shots) await page.locator('#view-tastatur').screenshot({path: path.join(shots, 'keyboard-settings.png')});

    // Recording: the next key press becomes the shortcut.
    await page.click('#kbKeys-dictation');
    assert.equal(await page.textContent('#kbKeys-dictation'), 'Tasten drücken …');
    await page.keyboard.press('Control+Shift+D');
    assert.equal(await page.textContent('#kbKeys-dictation'), 'Strg+Shift+D');
    assert.equal(await status(page), '„Diktierfunktion ein-/ausschalten“: Strg+Shift+D.');
    assert.deepEqual((await stored(page)).items.dictation, {on: true, keys: 'Mod+Shift+D'});
    // A clash is refused and names the other shortcut.
    await page.click('#kbKeys-newChat');
    await page.keyboard.press('Control+K');
    assert.equal(await status(page), 'Schon belegt: „Chat wechseln …“. Ändere zuerst dort die Tasten.');
    assert.equal(await page.textContent('#kbKeys-newChat'), 'Strg+Shift+O');
    // Keys that would disturb typing, the browser's own and the editing keys are refused.
    await page.click('#kbKeys-newChat');
    await page.keyboard.press('Shift+A');
    assert.match(await status(page), /^Kombiniere die Taste mit Strg, Alt oder Super, sonst stört das Kürzel beim Schreiben\.$/);
    await page.click('#kbKeys-newChat');
    await page.keyboard.press('Control+C');
    assert.equal(await status(page), 'Diese Kombination brauchst du zum Bearbeiten von Text.');
    await page.click('#kbKeys-newChat');
    await page.keyboard.press('Escape');
    assert.equal(await page.textContent('#kbKeys-newChat'), 'Strg+Shift+O', 'Esc cancels');
    // Modifiers alone wait for the key; Alt combinations work.
    await page.click('#kbKeys-newChat');
    await page.keyboard.down('Alt');
    assert.equal(await page.textContent('#kbKeys-newChat'), 'Tasten drücken …');
    await page.keyboard.press('N');
    await page.keyboard.up('Alt');
    assert.equal(await page.textContent('#kbKeys-newChat'), 'Alt+N');
    // Backspace alone removes a shortcut ("Keine").
    await page.click('#kbKeys-attach');
    await page.keyboard.press('Backspace');
    assert.equal(await page.textContent('#kbKeys-attach'), 'Keine');
    assert.equal(await status(page), '„Dateien anhängen“ hat jetzt kein Tastenkürzel.');
    // Switching one off disables its keys; on again it checks for a clash.
    await page.click('label[for="kbOn-sidebar"]');
    assert.equal(await page.isDisabled('#kbKeys-sidebar'), true);
    assert.deepEqual((await stored(page)).items.sidebar, {on: false, keys: 'Mod+Shift+S'});
    assert.equal(await status(page), '„Seitenleiste ein/aus“ ist aus.');
    await page.click('#kbKeys-dictation');
    await page.keyboard.press('Control+Shift+S');
    assert.equal(await page.textContent('#kbKeys-dictation'), 'Strg+Shift+S', 'an off shortcut does not block its keys');
    await page.click('label[for="kbOn-sidebar"]');
    assert.equal(await page.isChecked('#kbOn-sidebar'), false, 'back on would clash');
    assert.equal(await status(page), 'Schon belegt: „Diktierfunktion ein-/ausschalten“. Ändere zuerst dort die Tasten.');
    // The send key: Strg+Enter keeps the old sendKey in step for the hint under the chat input.
    await page.click('#kbKeys-send');
    await page.keyboard.press('Control+Enter');
    assert.equal(await page.textContent('#kbKeys-send'), 'Strg+Enter');
    assert.equal(await sendKey(page), 'mod-enter');
    // The planned items have no keys and no working switch.
    assert.equal(await page.isDisabled('#kbOn-background'), true);
    assert.equal(await page.locator('#kbKeys-background').count(), 0);
    // Standardeinstellungen wiederherstellen.
    await page.click('#kbReset');
    assert.equal(await status(page), 'Standardeinstellungen wiederhergestellt.');
    assert.deepEqual((await rows(page)).map(r => r[1]), ['Shift+Esc', 'Enter', 'In Vorbereitung', 'Strg+Shift+M', 'Keine', 'Strg+U',
      'Strg+Shift+Rücktaste', 'Strg+Shift+F', 'Strg+Shift+O', 'Strg+/', 'In Vorbereitung', 'Strg+K', 'Strg+Shift+S']);
    assert.equal(await page.isChecked('#kbOn-sidebar'), true);
    assert.equal(await sendKey(page), 'enter');
    assert.deepEqual(await stored(page), {v: 1, items: {send: {on: true, keys: 'Enter'}}});
    assert.deepEqual(errors, []);
    await context.close();

    // The choice of 0.7.28 ("Strg/⌘ + Enter sendet") carries over.
    ({context, page, errors} = await open(browser, 'https://coreui.test/settings.html#tastatur', null,
      () => localStorage.setItem('emberCoreUI.preferences.v1', JSON.stringify({sendKey: 'mod-enter'}))));
    await until(page, () => document.querySelectorAll('#kbGroups .kb-row').length === 13);
    assert.equal(await page.textContent('#kbKeys-send'), 'Strg+Enter');
    await context.close();

    // Phone: no sideways scrolling, the note about a keyboard.
    ({context, page, errors} = await open(browser, 'https://coreui.test/settings.html#tastatur', {width: 390, height: 844}));
    await until(page, () => document.querySelectorAll('#kbGroups .kb-row').length === 13);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0);
    for (const id of ['#kbKeys-chatDelete', '#kbOn-chatDelete', '#kbKeys-switchChat']) {
      const box = await page.locator(id === '#kbOn-chatDelete' ? 'label[for="kbOn-chatDelete"]' : id).boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= 390, id + ' stays on screen');
    }
    if (shots) await page.screenshot({path: path.join(shots, 'keyboard-phone.png'), fullPage: false});
    assert.deepEqual(errors, []);
    await context.close();

    // ===== Chat =====
    ({context, page, errors} = await open(browser, 'https://coreui.test/harness.html'));
    const log = () => page.evaluate(() => window.__log.splice(0));
    // The send key and the hint.
    assert.equal(await page.textContent('#composerSendKeyHint'), 'ENTER SENDEN · SHIFT+ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD');
    await page.click('#inputMessage');
    await page.keyboard.type('Hallo');
    await page.keyboard.press('Shift+Enter');
    await page.keyboard.type('Mara');
    await page.keyboard.press('Enter');
    assert.deepEqual(await log(), ['send:Hallo\nMara']);
    // Every action through the existing control.
    await page.keyboard.press('Control+Shift+O');
    assert.deepEqual(await log(), ['btnNewSession']);
    await page.keyboard.press('Control+Shift+M');
    await page.keyboard.press('Control+U');
    await page.keyboard.press('Control+Shift+Backspace');
    await page.keyboard.press('Control+Shift+F');
    assert.deepEqual(await log(), ['reasonEffortChip', 'fileInput', 'menu:delete', 'menu:search']);
    await page.click('body');
    await page.keyboard.press('Shift+Escape');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'inputMessage');
    // Seitenleiste: on a wide screen the rail folds (the checkbox of app.html).
    await page.keyboard.press('Control+Shift+S');
    assert.equal(await page.isChecked('#sidebarControl'), false);
    await page.keyboard.press('Control+Shift+S');
    assert.equal(await page.isChecked('#sidebarControl'), true);
    // Without an open chat there is nothing to delete.
    await page.evaluate(() => { window.__active = null; });
    await page.keyboard.press('Control+Shift+Backspace');
    assert.deepEqual(await log(), []);
    assert.equal(await page.textContent('#shortcutToast'), 'Öffne zuerst den Chat, den du löschen möchtest.');
    await page.evaluate(() => { window.__active = {id: 'cs_aaaaaa1', title: 'Planung'}; });
    // Tastenkürzel anzeigen: grouped, only what is on; the same keys close it.
    await page.keyboard.press('Control+/');
    await page.waitForSelector('#shortcutHelp:not([hidden])');
    assert.deepEqual(await page.$$eval('#shortcutHelp h3', l => l.map(x => x.textContent)), ['Nachrichteneditor', 'Chat', 'App']);
    const help = await page.$$eval('#shortcutHelp dt', l => l.map(x => x.textContent));
    assert.ok(help.includes('Neuer Chat') && help.includes('Nachricht senden') && !help.includes('Diktierfunktion ein-/ausschalten')
      && !help.includes('Anmerkungen senden'), 'only shortcuts with keys');
    assert.equal(await page.getAttribute('#shortcutHelp .sc-help-foot a', 'href'), 'settings.html#tastatur');
    if (shots) await page.screenshot({path: path.join(shots, 'keyboard-help.png')});
    await page.keyboard.press('Control+/');
    assert.equal(await page.isHidden('#shortcutHelp'), true);
    // Chat wechseln: pinned first, archived left out, the filter, arrows and Enter.
    await page.keyboard.press('Control+K');
    await page.waitForSelector('#chatSwitch:not([hidden])');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'chatSwitchInput');
    assert.deepEqual(await page.$$eval('#chatSwitchList .sc-switch-item', l => l.map(x => x.textContent)),
      ['Reise nach RomAngepinnt', 'PlanungGeöffnet', 'Rezepte']);
    await page.keyboard.type('re');
    assert.deepEqual(await page.$$eval('#chatSwitchList .sc-switch-item span', l => l.map(x => x.textContent)), ['Reise nach Rom', 'Rezepte']);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    assert.deepEqual(await log(), ['select:cs_aaaaaa3']);
    assert.equal(await page.isHidden('#chatSwitch'), true);
    await page.keyboard.press('Control+K');
    await page.keyboard.type('xyz');
    assert.equal(await page.textContent('#chatSwitchEmpty'), 'Kein Chat mit „xyz“ im Titel.');
    if (shots) await page.screenshot({path: path.join(shots, 'keyboard-switch.png')});
    await page.keyboard.press('Escape');
    assert.equal(await page.isHidden('#chatSwitch'), true);
    // Another window (a confirmation) keeps the keyboard.
    await page.evaluate(() => { document.getElementById('otherDialog').hidden = false; });
    await page.keyboard.press('Control+Shift+O');
    assert.deepEqual(await log(), []);
    await page.evaluate(() => { document.getElementById('otherDialog').hidden = true; });
    // A change in Settings > Tastatur (another tab) applies at once: Strg+Enter sends, Enter is a new line.
    await page.evaluate(() => {
      window.CoreAIShortcuts.set('send', {keys: 'Mod+Enter'});
      window.CoreAIShortcuts.set('sidebar', {on: false});
    });
    assert.equal(await page.textContent('#composerSendKeyHint'), 'STRG+ENTER SENDEN · ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD');
    await page.click('#inputMessage');
    await page.keyboard.type('Eins');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Zwei');
    await page.keyboard.press('Control+Enter');
    assert.deepEqual(await log(), ['send:Eins\nZwei']);
    await page.keyboard.press('Control+Shift+S');
    assert.equal(await page.isChecked('#sidebarControl'), true, 'a switched off shortcut does nothing');
    // Diktieren has no keys by default; with keys it presses the microphone.
    await page.evaluate(() => window.CoreAIShortcuts.set('dictation', {keys: 'Alt+D'}));
    await page.keyboard.press('Alt+D');
    assert.deepEqual(await log(), ['btnMic']);
    await page.evaluate(() => { document.getElementById('btnMic').hidden = true; });
    await page.keyboard.press('Alt+D');
    assert.equal(await page.textContent('#shortcutToast'), 'Das Mikrofon ist aus. Du schaltest es unter Einstellungen > Stimme ein.');
    assert.deepEqual(errors, []);
    await context.close();

    // Phone: the side bar is the drawer.
    ({context, page, errors} = await open(browser, 'https://coreui.test/harness.html', {width: 390, height: 844}));
    await page.keyboard.press('Control+Shift+S');
    await page.keyboard.press('Control+Shift+S');
    assert.deepEqual(await page.evaluate(() => window.__log.splice(0)), ['openDrawer', 'closeDrawer']);
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI keyboard: Settings > Tastatur (groups, defaults, In Vorbereitung, recording, clashes, refused keys, Backspace, Esc, switch,'
    + ' send key and the old sendKey, reset, phone) and the chat (every action, Tastenkürzel, Chat wechseln, send key, other windows) OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
