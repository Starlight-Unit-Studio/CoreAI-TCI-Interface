// CoreAI 0.6.43: the + menu of the input field, in a real browser (Playwright).
//   node scripts/composer-menu-ui-selftest.cjs
// js/coreui-composer-menu.js on a page with the chat's stylesheets and the input row exactly as
// app.html has it. Checked: the marks it reads and writes; the menu (Anhängen, Werkzeuge,
// Konnektoren) opens and closes by click, Escape, a click outside and the arrow keys; Fotos,
// Kamera (devices with a camera) and Dateien open the file picker with the right types and the
// picker is reset afterwards; tools the account may not use stay visible, greyed, with the
// reason; Deep Research and Konnektoren are marked "In Vorbereitung" and do nothing; a chosen
// tool shows as a chip (Normal / Groß for images) and goes in front of the sent text once; an
// edit brings the chip back; a registered connector appears; full attachment slots grey out the
// file entries only; a phone gets a sheet from the bottom edge without sideways scrolling; and
// the wiring in the chat page, the chat, Mara's prompt, the stream, the endpoint and nginx.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8');

// --- Wiring --------------------------------------------------------------------------------
const html = read('app.html');
assert(/<script src="\.\/js\/coreui-composer-menu\.js\?v=\d+"><\/script>\n  <script src="\.\/js\/console-app\.js\?v=\d+"><\/script>/.test(html),
  'app.html loads the menu right before the chat');
assert(html.includes('id="btnAttach" type="button" aria-label="Anhängen und Werkzeuge" aria-haspopup="menu" aria-expanded="false" aria-controls="composerMenu"'),
  'the + button announces its menu');
assert(!html.includes('Bis zu zehn Dateien oder Bilder anhängen'), 'the paperclip label is gone');
const app = read('js/console-app.js');
assert(app.includes('submitMessageEdit(window.CoreAIComposerMenu ? window.CoreAIComposerMenu.marked(text, false) : text);'),
  'an edit carries the chosen tool and keeps the chip until it is saved');
assert(app.includes('queueTurnAfterCurrentReply(ses, composerIntentText(text, false));'), 'a queued message carries the chosen tool');
assert(app.includes("var userText = (options.from_queue ? String(text || '') : composerIntentText(text, pendingFiles.length > 0)).trim();"),
  'a sent message carries the chosen tool, also with files only (a Stimmsequenz)');
assert(app.includes('var parsedIntent = window.CoreAIComposerMenu.parse(text);') && app.includes("intentChip.className = 'msg-intent';")
  && app.includes('if (intentChip) content.appendChild(intentChip);'), 'the person\'s bubble shows the tool as a chip');
assert(app.includes("if (window.CoreAIComposerMenu) text = window.CoreAIComposerMenu.loadFrom(String(record.message || ''), text);"),
  'an edit brings the chip back');
assert(app.includes('if (state.editingMessage && window.CoreAIComposerMenu) window.CoreAIComposerMenu.clear();'), 'a cancelled edit clears the chip');
assert(app.includes("if (btnAttach && !window.CoreAIComposerMenu) btnAttach.addEventListener('click'"), 'the + button no longer opens the picker itself');
assert(app.includes('setFilesAvailable(attachmentSlotsUsed() < MAX_MESSAGE_ATTACHMENTS);') && app.includes('    setFilesAvailable(false);')
  && !/btnAttach\.disabled = attachmentSlotsUsed\(\)/.test(app), 'full slots grey out the file entries, not the tools');
for (const f of ['config/nginx.conf.template', 'config/runtime/nginx.conf.template']) {
  assert(read(f).includes('|console_insights|composer_tools|voice_input|'), f + ' lets the endpoint through');
}
const endpoint = read('api/composer_tools.php');
assert(endpoint.includes("stu_require_user_id()") && endpoint.includes("header('Allow: GET');")
  && endpoint.includes('coreui_composer_tools_answer($guest, ember_image_allowed($uid), ember_web_enabled(), ember_browse_enabled())'),
  'the endpoint: signed in, GET only, the same switches as the work loop');
const chat = read('api/chat.php');
assert((chat.match(/\$composerIntent = \$channel === 'console' \? coreui_composer_intent_take\(\$userMsg\) : null;/g) || []).length === 2,
  'both prompt builders take the mark out of the text');
assert((chat.match(/\. \(\$intentHint !== '' \? \(\$intentHint \. "\\n\\n"\) : ""\)/g) || []).length === 2, 'both prompts carry the instruction');
assert(chat.includes("$out = coreui_composer_intent_ensure($composerIntent, $out, $userMsg, ember_composer_intent_allowed($composerIntent, $consoleUid));"),
  'a chosen tool is not left to chance');
assert(chat.includes("if ($channel === 'console' && !$isEmberRow) $msg = trim(coreui_composer_intent_strip($msg));"), 'history lines without the mark');
const stream = read('api/console_stream.php');
assert(stream.includes('$composerIntentStream = coreui_composer_intent_peek($promptMessage);')
  && stream.includes("} elseif ($isVisionTurn || $composerIntentStream !== null) {"), 'the stream goes straight to the tool path');
assert(read('api/console_compaction.php').includes('coreui_composer_intent_readable((string)$r[\'message\'])'), 'the archive reads "(Bild erstellen) ..."');

// --- Browser -------------------------------------------------------------------------------
const footerAt = html.indexOf('<footer class="composer">');
const footer = html.slice(footerAt, html.indexOf('</footer>', footerAt) + '</footer>'.length);
assert(footer.length > 200, 'composer markup found');
const HARNESS = theme => `<!doctype html><html lang="de" data-coreui-theme="${theme}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>t</title>
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css"></head>
<body style="margin:0"><div class="app-layout" style="height:100vh;overflow:hidden;display:flex;flex-direction:column;justify-content:flex-end">
<main class="messages" style="flex:1"><div class="messages-inner" id="rows"></div></main>${footer}</div>
<script src="/js/coreui-composer-menu.js"></script></body></html>`;
const ALL_ON = {ok: true, guest: false, tools: {image: {on: true, why: ''}, web: {on: true, why: ''}, browse: {on: true, why: ''}}, planned: ['deep_research', 'connectors']};
const IMAGE_OFF = {ok: true, guest: false, tools: {image: {on: false, why: 'Für dein Konto gerade nicht freigegeben'}, web: {on: true, why: ''},
  browse: {on: false, why: 'Für dein Konto gerade nicht freigegeben'}}, planned: ['deep_research', 'connectors']};

async function open(browser, options = {}) {
  const context = await browser.newContext({viewport: options.viewport || {width: 1000, height: 820}, hasTouch: !!options.touch,
    isMobile: !!options.touch});
  const page = await context.newPage();
  const errors = [];
  const calls = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://localhost:8768/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS(options.theme || 'light')});
    if (url.pathname === '/api/composer_tools.php') {
      calls.push(route.request().method());
      return route.fulfill({contentType: 'application/json', body: JSON.stringify(options.tools || ALL_ON)});
    }
    const file = path.join(root, url.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8768/harness.html');
  return {page, context, errors, calls};
}
const menuState = page => page.evaluate(() => {
  const menu = document.getElementById('composerMenu');
  const btn = document.getElementById('btnAttach');
  if (!menu) return {open: false, expanded: btn.getAttribute('aria-expanded')};
  return {
    open: true, expanded: btn.getAttribute('aria-expanded'), role: menu.getAttribute('role'),
    titles: Array.from(menu.querySelectorAll('.cm-title')).map(t => t.textContent),
    items: Array.from(menu.querySelectorAll('.cm-item')).map(i => ({id: i.getAttribute('data-id'), label: i.querySelector('.cm-label').textContent,
      detail: (i.querySelector('.cm-detail') || {}).textContent || '', badge: (i.querySelector('.cm-badge') || {}).textContent || '',
      off: i.classList.contains('is-off'), aria: i.getAttribute('aria-disabled'), role: i.getAttribute('role')})),
    focus: document.activeElement && document.activeElement.getAttribute('data-id'),
  };
});
const chipState = page => page.evaluate(() => {
  const chip = document.querySelector('.composer-intent');
  return {hidden: chip.hidden, tool: chip.getAttribute('data-tool'), label: chip.querySelector('.composer-intent-label').textContent,
    sizeHidden: chip.querySelector('.composer-intent-size').hidden,
    pressed: Array.from(chip.querySelectorAll('.composer-intent-size-btn')).map(b => b.textContent + ':' + b.getAttribute('aria-pressed')),
    inField: chip.parentNode.classList.contains('composer-field') && chip.nextElementSibling.id === 'inputMessage'};
});
// A greyed entry (aria-disabled) is clicked anyway, to see that it does nothing.
const item = (page, id, force = false) => page.click('#composerMenu .cm-item[data-id="' + id + '"]', {force});

(async () => {
  const browser = await chromium.launch();
  try {
    const {page, context, errors, calls} = await open(browser);
    // --- pure parts ---
    const pure = await page.evaluate(() => {
      const M = window.CoreAIComposerMenu;
      return {
        image: M.parse('[[bild]] ein Fuchs im Schnee'), large: M.parse('  [[bild:gross]]\nein Schloss'), web: M.parse('[[websuche]] Wetter Köln'),
        browse: M.parse('[[recherche]] Öffnungszeiten'), none: M.parse('Ein [[bild]] mitten im Text'), odd: M.parse('[[bild:klein]] x'),
        empty: M.parse(''), nil: M.parse(null),
        labels: [M.label({tool: 'image', size: 'normal'}), M.label({tool: 'image', size: 'large'}), M.label({tool: 'web'}), M.label({tool: 'browse'}), M.label(null)],
        icon: M.icon('image').startsWith('<svg class="msg-intent-icon"'), noIcon: M.icon('x'),
      };
    });
    assert.deepEqual(pure.image, {intent: {tool: 'image', size: 'normal'}, text: 'ein Fuchs im Schnee', label: 'Bild erstellen'});
    assert.deepEqual(pure.large, {intent: {tool: 'image', size: 'large'}, text: 'ein Schloss', label: 'Bild erstellen · Groß'});
    assert.deepEqual(pure.web.intent, {tool: 'web', size: 'normal'});
    assert.equal(pure.web.label, 'Websuche');
    assert.deepEqual(pure.browse.intent, {tool: 'browse', size: 'normal'});
    assert.equal(pure.browse.text, 'Öffnungszeiten');
    assert.equal(pure.none.intent, null, 'only a mark at the very start counts');
    assert.equal(pure.none.text, 'Ein [[bild]] mitten im Text');
    assert.equal(pure.odd.intent, null, 'unknown marks are text');
    assert.deepEqual(pure.empty, {intent: null, text: '', label: ''});
    assert.equal(pure.nil.text, '');
    assert.deepEqual(pure.labels, ['Bild erstellen', 'Bild erstellen · Groß', 'Websuche', 'Recherche', '']);
    assert(pure.icon && pure.noIcon === '', 'symbols for the bubble');

    for (const count of [1, 2, 4]) {
      const roundTrip = await page.evaluate(n => {
        const M = window.CoreAIComposerMenu;
        M.set('image', 'large');
        document.querySelector('[aria-label="' + n + (n === 1 ? ' Bild' : ' Bilder') + '"]').click();
        const stored = M.consume('a fox', false);
        M.loadFrom(stored, stored);
        const result = {stored, current: M.current(), text: M.parse(stored).text};
        M.clear();
        return result;
      }, count);
      assert.deepEqual(roundTrip, {stored: '[[bild:gross:' + count + ']] a fox', current: {tool:'image', size:'large', count}, text:'a fox'});
    }

    // --- the + button ---
    const btn = await page.evaluate(() => {
      const b = document.getElementById('btnAttach');
      return {label: b.getAttribute('aria-label'), popup: b.getAttribute('aria-haspopup'), plus: b.classList.contains('composer-plus-btn'),
        disabled: b.disabled, svg: b.querySelector('svg path').getAttribute('d')};
    });
    assert.deepEqual(btn, {label: 'Anhängen und Werkzeuge', popup: 'menu', plus: true, disabled: false, svg: 'M12 5v14M5 12h14'});
    assert.equal((await chipState(page)).hidden, true, 'no chip before a tool is chosen');
    assert.equal((await chipState(page)).inField, true, 'the chip sits in the input field, above the text');

    // --- open by click: sections, entries, focus ---
    await page.click('#btnAttach');
    await page.waitForFunction(() => document.querySelector('#composerMenu .cm-item[data-id="image"] .cm-detail').textContent.includes('malt'));
    let m = await menuState(page);
    assert.equal(m.open, true);
    assert.equal(m.expanded, 'true');
    assert.equal(m.role, 'menu');
    assert.deepEqual(m.titles, ['Anhängen', 'Werkzeuge', 'Konnektoren']);
    assert.deepEqual(m.items.map(i => i.id), ['photos', 'files', 'image', 'web', 'browse', 'deep', 'connectors'], 'no camera on a desktop');
    assert(m.items.every(i => i.role === 'menuitem'));
    const by = id => m.items.find(i => i.id === id);
    assert.deepEqual([by('photos').label, by('files').label, by('image').label, by('web').label, by('browse').label, by('deep').label, by('connectors').label],
      ['Fotos', 'Dateien', 'Bild erstellen', 'Websuche', 'Recherche', 'Deep Research', 'Konnektoren']);
    assert.equal(by('files').detail, 'PDF, Audio, Video, Dokumente');
    assert.equal(by('image').detail, 'Deine CoreAI malt ein Bild zu deiner Nachricht');
    assert.equal(by('image').off, false);
    assert.deepEqual([by('deep').off, by('deep').aria, by('deep').badge], [true, 'true', 'In Vorbereitung'], 'Deep Research is planned, not offered');
    assert.deepEqual([by('connectors').off, by('connectors').aria, by('connectors').badge], [true, 'true', 'In Vorbereitung'], 'connectors are planned');
    assert.equal(m.focus, 'photos', 'the first entry has the focus');
    assert.deepEqual(calls, ['GET'], 'the menu asks which tools this account may use');
    // Arrow keys walk the usable entries only, around the end.
    await page.keyboard.press('ArrowUp');
    assert.equal((await menuState(page)).focus, 'browse', 'ArrowUp from the first goes to the last usable entry');
    await page.keyboard.press('ArrowDown');
    assert.equal((await menuState(page)).focus, 'photos');
    // A planned entry does nothing.
    await item(page, 'deep', true);
    assert.equal((await menuState(page)).open, true, 'Deep Research does nothing');
    assert.equal((await chipState(page)).hidden, true);
    // Escape closes and gives the focus back.
    await page.keyboard.press('Escape');
    m = await menuState(page);
    assert.deepEqual(m, {open: false, expanded: 'false'});
    assert.equal(await page.evaluate(() => document.activeElement.id), 'btnAttach', 'focus back on the + button');
    // Enter opens, a click outside closes.
    await page.keyboard.press('Enter');
    assert.equal((await menuState(page)).open, true, 'Enter opens');
    await page.mouse.click(500, 120);
    assert.equal((await menuState(page)).open, false, 'a click outside closes');
    // A second click on + closes again.
    await page.click('#btnAttach');
    await page.click('#btnAttach');
    assert.equal((await menuState(page)).open, false, 'the + button toggles');
    assert.deepEqual(calls, ['GET'], 'the answer is kept for a minute');

    // --- files: the picker with the right types, reset afterwards ---
    const defaultAccept = await page.getAttribute('#fileInput', 'accept');
    assert(defaultAccept.startsWith('image/*,video/*,audio/*,.pdf'), 'the plain file types');
    await page.click('#btnAttach');
    let chooser = page.waitForEvent('filechooser');
    await item(page, 'photos');
    let fc = await chooser;
    assert.equal(await page.getAttribute('#fileInput', 'accept'), 'image/*', 'Fotos: images only');
    assert.equal(await page.getAttribute('#fileInput', 'capture'), null);
    assert.equal(fc.isMultiple(), true);
    assert.equal((await menuState(page)).open, false, 'the menu closes for the picker');
    await fc.setFiles({name: 'a.png', mimeType: 'image/png', buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47])});
    await page.waitForFunction(a => document.getElementById('fileInput').getAttribute('accept') === a, defaultAccept);
    await page.click('#btnAttach');
    chooser = page.waitForEvent('filechooser');
    await item(page, 'files');
    fc = await chooser;
    assert.equal(await page.getAttribute('#fileInput', 'accept'), defaultAccept, 'Dateien: everything as before');
    await fc.setFiles([]);

    // --- a tool: chip, size, sending ---
    await page.click('#btnAttach');
    await item(page, 'image');
    assert.equal((await menuState(page)).open, false);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'inputMessage', 'back to typing');
    let chip = await chipState(page);
    assert.deepEqual([chip.hidden, chip.tool, chip.label, chip.sizeHidden, chip.pressed],
      [false, 'image', 'Bild erstellen', false, ['Normal:true', 'Groß:false', '1:true', '2:false', '4:false']]);
    await page.getByRole('button', {name: 'Groß', exact: true}).click();
    chip = await chipState(page);
    assert.deepEqual(chip.pressed, ['Normal:false', 'Groß:true', '1:true', '2:false', '4:false'], 'Groß chosen');
    await page.click('#btnAttach');
    m = await menuState(page);
    assert(await page.evaluate(() => document.querySelector('#composerMenu .cm-item[data-id="image"]').classList.contains('is-active')), 'the chosen tool is marked');
    await page.keyboard.press('Escape');
    const sent = await page.evaluate(() => {
      const M = window.CoreAIComposerMenu;
      const r = {};
      r.empty = M.consume('   ', false);
      r.still = M.current();
      r.text = M.consume('  ein Schloss bei Nacht', false);
      r.after = M.current();
      r.again = M.consume('noch eins', false);
      M.set('web');
      r.twice = M.consume('[[recherche]] schon markiert', false);
      r.keep = M.current();
      r.voice = M.consume('', true);
      M.set('browse');
      r.browse = M.consume('Fahrplan', false);
      M.set('image', 'large');
      r.markedOnly = M.marked('ein Turm', false);
      r.kept = M.current();
      r.markedEmpty = M.marked('', false);
      M.clear();
      return r;
    });
    assert.equal(sent.empty, '   ', 'no text and no files: nothing is sent, the chip stays');
    assert.deepEqual(sent.still, {tool: 'image', size: 'large'});
    assert.equal(sent.text, '[[bild:gross]] ein Schloss bei Nacht', 'the mark goes in front');
    assert.equal(sent.after, null, 'and the chip is gone');
    assert.equal(sent.again, 'noch eins', 'only once');
    assert.equal(sent.twice, '[[recherche]] schon markiert', 'never two marks');
    assert.deepEqual(sent.keep, {tool: 'web', size: 'normal'});
    assert.equal(sent.voice, '[[websuche]]', 'with files only (a Stimmsequenz) the mark goes alone');
    assert.equal(sent.browse, '[[recherche]] Fahrplan');
    assert.equal(sent.markedOnly, '[[bild:gross]] ein Turm', 'an edit: marked ...');
    assert.deepEqual(sent.kept, {tool: 'image', size: 'large'}, '... and the chip stays until the edit is saved');
    assert.equal(sent.markedEmpty, '', 'nothing to mark');
    chip = await chipState(page);
    assert.equal(chip.hidden, true);
    // The × removes the tool.
    await page.click('#btnAttach');
    await item(page, 'web');
    chip = await chipState(page);
    assert.deepEqual([chip.tool, chip.label, chip.sizeHidden], ['web', 'Websuche', true], 'no size for a search');
    await page.click('.composer-intent-close');
    assert.equal((await chipState(page)).hidden, true, '× removes the tool');

    // --- an edit brings the chip back ---
    const edit = await page.evaluate(() => {
      const M = window.CoreAIComposerMenu;
      const r = {text: M.loadFrom('[[bild:gross]] ein Leuchtturm', 'ein Leuchtturm'), cur: M.current()};
      r.plain = M.loadFrom('nur Text', 'nur Text');
      r.none = M.current();
      return r;
    });
    assert.deepEqual(edit, {text: 'ein Leuchtturm', cur: {tool: 'image', size: 'large'}, plain: 'nur Text', none: null});

    // --- full attachment slots grey out the file entries only ---
    await page.evaluate(() => window.CoreAIComposerMenu.filesAvailable(false));
    await page.click('#btnAttach');
    m = await menuState(page);
    assert.deepEqual(m.items.filter(i => ['photos', 'files'].includes(i.id)).map(i => i.off), [true, true]);
    assert.equal(m.items.find(i => i.id === 'files').detail, 'Zehn Anhänge sind schon gewählt');
    assert.equal(m.items.find(i => i.id === 'image').off, false, 'the tools stay');
    await page.keyboard.press('Escape');
    await page.evaluate(() => window.CoreAIComposerMenu.filesAvailable(true));

    // --- a connector registers itself (roadmap P14) ---
    const reg = await page.evaluate(() => {
      const M = window.CoreAIComposerMenu;
      window.__picked = 0;
      return [M.registerConnector({id: 'Bad Id!', label: 'x', onSelect() {}}), M.registerConnector({id: 'drive', label: 'x'}),
        M.registerConnector({id: 'drive', label: 'Cloud-Speicher', detail: 'Dateien aus dem Speicher', onSelect() { window.__picked++; }})];
    });
    assert.deepEqual(reg, [false, false, true], 'only a valid connector with an action');
    await page.click('#btnAttach');
    m = await menuState(page);
    assert.deepEqual(m.items.filter(i => i.id.startsWith('connector')).map(i => [i.id, i.label, i.off]), [['connector-drive', 'Cloud-Speicher', false]],
      'the registered connector replaces the placeholder');
    await item(page, 'connector-drive');
    assert.equal(await page.evaluate(() => window.__picked), 1);
    assert.equal((await menuState(page)).open, false);

    // Light mode: readable chip text on its own background.
    const look = await page.evaluate(() => {
      window.CoreAIComposerMenu.set('image');
      const c = getComputedStyle(document.querySelector('.composer-intent'));
      return {color: c.color, display: c.display};
    });
    assert.deepEqual(look, {color: 'rgb(53, 111, 220)', display: 'flex'});
    if (process.env.COREAI_SHOT) {
      await page.click('#btnAttach');
      await page.waitForTimeout(250);
      await page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-light.png')});
      await page.keyboard.press('Escape');
    }
    assert.deepEqual(errors, []);
    await context.close();

    // --- tools this account may not use: visible, greyed, with the reason ---
    const off = await open(browser, {tools: IMAGE_OFF, theme: 'dark'});
    await off.page.click('#btnAttach');
    await off.page.waitForFunction(() => document.querySelector('#composerMenu .cm-item[data-id="image"]').classList.contains('is-off'));
    m = await menuState(off.page);
    const image = m.items.find(i => i.id === 'image');
    assert.deepEqual([image.off, image.aria, image.detail], [true, 'true', 'Für dein Konto gerade nicht freigegeben']);
    assert.equal(m.items.find(i => i.id === 'browse').off, true);
    assert.equal(m.items.find(i => i.id === 'web').off, false);
    await item(off.page, 'image', true);
    assert.equal((await chipState(off.page)).hidden, true, 'a tool that is off cannot be chosen');
    const dark = await off.page.evaluate(() => getComputedStyle(document.getElementById('composerMenu')).backgroundColor);
    assert.equal(dark, 'rgb(23, 38, 63)', 'dark mode: the dark panel');
    if (process.env.COREAI_SHOT) await off.page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-dark.png')});
    assert.deepEqual(off.errors, []);
    await off.context.close();

    // --- a phone with a camera: Kamera, a sheet from the bottom edge ---
    const phone = await open(browser, {viewport: {width: 390, height: 844}, touch: true, theme: 'dark'});
    // The two real footer controls must retain CoreAI's angular style, with
    // the Core selector the rightmost action on the phone.
    const chips = await phone.page.evaluate(() => {
      const effort = document.getElementById('reasonEffortChip');
      const core = document.getElementById('coreModeChip');
      const r1 = effort.getBoundingClientRect(), r2 = core.getBoundingClientRect();
      const c1 = getComputedStyle(effort), c2 = getComputedStyle(core);
      return {effortLeft:r1.left, effortRight:r1.right, coreLeft:r2.left,
        coreRight:r2.right, effortTop:r1.top, coreTop:r2.top,
        clipEffort:c1.clipPath, clipCore:c2.clipPath,
        familyEffort:c1.fontFamily, familyCore:c2.fontFamily,
        radiusEffort:c1.borderRadius, radiusCore:c2.borderRadius,
        scroll: document.documentElement.scrollWidth, viewport:innerWidth};
    });
    assert(chips.effortLeft < chips.coreLeft && chips.effortRight <= chips.coreRight,
      'Denkaufwand is left of the Core selector: ' + JSON.stringify(chips));
    assert(chips.coreRight <= chips.viewport + 1,
      'Core stays inside the right edge on mobile');
    assert.equal(chips.clipEffort, chips.clipCore,
      'both controls use the same angled CoreAI silhouette');
    assert.equal(chips.familyEffort, chips.familyCore,
      'both controls use the same TCI typography');
    assert.equal(chips.radiusEffort, chips.radiusCore,
      'both controls use the same square/faceted corners');
    assert(chips.scroll <= chips.viewport, 'buttons do not introduce horizontal scrolling');
    await phone.page.tap('#btnAttach');
    await phone.page.waitForFunction(() => document.querySelector('#composerMenu .cm-item[data-id="image"] .cm-detail').textContent.includes('malt'));
    m = await menuState(phone.page);
    assert.deepEqual(m.items.map(i => i.id).slice(0, 3), ['photos', 'camera', 'files'], 'Kamera on a device with a camera');
    assert.equal(m.items.find(i => i.id === 'camera').detail, 'Foto aufnehmen und deiner CoreAI zeigen');
    const sheet = await phone.page.evaluate(() => {
      const r = document.getElementById('composerMenu').getBoundingClientRect();
      return {left: r.left, right: r.right, bottom: Math.round(r.bottom), top: r.top, view: window.innerWidth, h: window.innerHeight,
        composer: Math.round(document.querySelector('.composer').getBoundingClientRect().bottom),
        scroll: document.documentElement.scrollWidth, position: getComputedStyle(document.getElementById('composerMenu')).position};
    });
    assert(sheet.position === 'fixed' && sheet.left === 0 && sheet.right === sheet.view && sheet.bottom === sheet.composer && sheet.bottom <= sheet.h && sheet.top > 0,
      'a sheet from the bottom edge: ' + JSON.stringify(sheet));
    assert(sheet.scroll <= sheet.view, 'no sideways scrolling');
    if (process.env.COREAI_SHOT) await phone.page.screenshot({path: process.env.COREAI_SHOT.replace('.png', '-phone.png')});
    chooser = phone.page.waitForEvent('filechooser');
    await phone.page.tap('#composerMenu .cm-item[data-id="camera"]');
    fc = await chooser;
    assert.deepEqual([await phone.page.getAttribute('#fileInput', 'accept'), await phone.page.getAttribute('#fileInput', 'capture')],
      ['image/*', 'environment'], 'Kamera: the back camera');
    await fc.setFiles([]);
    assert.deepEqual(phone.errors, []);
    await phone.context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI composer menu UI: marks, + button, menu by click/Enter/Escape/outside/arrows, Fotos/Kamera/Dateien pickers, '
    + 'tools off with reason, Deep Research and Konnektoren in Vorbereitung, chip with Normal/Groß, mark once in front, edit, '
    + 'full slots, registered connector, light/dark, phone sheet OK');
})().catch(error => { console.error('CoreAI composer menu UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
