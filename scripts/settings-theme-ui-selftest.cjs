// CoreAI 0.7.33: the CoreAI theme over every settings page, and a tidy Allgemein page, in a real browser (Playwright).
//   node scripts/settings-theme-ui-selftest.cjs
// Checked: css/coreui-settings-theme.css is loaded after css/coreui-theme.css in Settings and Admin Core; the
// markup is balanced and every settings page sits in the main column (stray closing tags once pushed Tastatur,
// Nutzung, Datenkontrollen and the planned pages below the navigation); buttons, boxes, tabs and badges carry
// the CoreAI geometry in light and dark; SoftGlass keeps its own rounded look; real circles stay round;
// Allgemein holds only its own three settings; every Expert setting says "(Expert)" and, without Expert mode,
// is hidden with the next setting taking its place; a phone has no sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml'};
const PREFS = 'emberCoreUI.preferences.v1';

// --- Static: load order --------------------------------------------------------------------------------------
for (const [file, prefix] of [['settings.html', './css/'], ['admin/index.html', '../css/']]) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');
  const base = html.indexOf('href="' + prefix + 'coreui-theme.css?v=');
  const theme = html.indexOf('href="' + prefix + 'coreui-settings-theme.css?v=');
  assert.ok(base > 0, file + ': coreui-theme.css is linked');
  assert.ok(theme > base, file + ': the settings theme comes after coreui-theme.css');
}
const css = fs.readFileSync(path.join(root, 'css/coreui-settings-theme.css'), 'utf8');
const rules = css.replace(/\/\*[\s\S]*?\*\//g, '').split('}').map(r => r.trim()).filter(r => r.includes('{'));
for (const rule of rules) {
  const selector = rule.slice(0, rule.indexOf('{'));
  assert.ok(/html(\[data-coreui-theme="dark"\])?:not\(\[data-coreui-skin="softglass"\]\) \.settings-body/.test(selector),
    'every theme rule leaves SoftGlass alone: ' + selector.slice(0, 80));
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 2, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 2, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.33', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
  'console_sessions.php': {ok: true, active: 2, archived: 1, locked: 0, csrf_token: 'tok', sessions: []},
};

async function open(browser, prefs, viewport, hash) {
  const context = await browser.newContext({viewport: viewport || {width: 1280, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  if (prefs) await context.addInitScript(([k, v]) => localStorage.setItem(k, v), [PREFS, JSON.stringify(prefs)]);
  await context.route('https://coreui.test/**', async route => {
    const u = new URL(route.request().url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#' + (hash || 'start'));
  await page.waitForFunction(() => !document.getElementById('healthSummary').classList.contains('checking'));
  return {context, page, errors};
}
const style = (page, sel, props) => page.$eval(sel, (el, p) => {
  const cs = getComputedStyle(el);
  return Object.fromEntries(p.map(k => [k, cs.getPropertyValue(k)]));
}, props);

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // --- Structure: every page in the main column, beside the navigation ------------------------------------
    let {context, page, errors} = await open(browser);
    const outside = await page.$$eval('.cfg-view', views => views.filter(v => v.parentElement.id !== 'cfgMain').map(v => v.id));
    assert.deepEqual(outside, [], 'every settings page is a child of the main column');
    assert.ok(await page.$$eval('.cfg-view', v => v.length) >= 20);
    for (const view of ['tastatur', 'nutzung', 'datenkontrollen', 'plugins']) {
      await page.evaluate(v => { location.hash = v; }, view);
      await page.waitForFunction(v => !document.getElementById('view-' + v).hidden, view);
      const box = await page.evaluate(v => {
        const nav = document.getElementById('cfgNav').getBoundingClientRect();
        const head = document.querySelector('#view-' + v + ' .cfg-view-head').getBoundingClientRect();
        return {navRight: nav.right, navTop: nav.top, left: head.left, top: head.top};
      }, view);
      assert.ok(box.left >= box.navRight && box.top < box.navTop + 60, view + ' sits beside the navigation, not below it');
    }

    // --- Allgemein: only its own settings -----------------------------------------------------------------
    await page.evaluate(() => { location.hash = 'start'; });
    await page.waitForFunction(() => !document.getElementById('view-start').hidden);
    assert.deepEqual(await page.$$eval('#view-start .cfg-general-copy label', l => l.map(x => x.textContent.trim())),
      ['Sprache', 'Höhere Intelligenz', 'Denkaufwand']);
    assert.equal(await page.locator('#view-start [data-goto], #view-start .cfg-tiles, #view-start .cfg-stats, #view-start .cfg-hero, #view-start h3').count(), 0,
      'no overview, key figures, shortcut tiles or extra headings');
    const startText = await page.textContent('#view-start');
    for (const gone of ['DEIN COREAI IM ÜBERBLICK', 'DIREKT ZU EINSTELLUNGEN', 'WILLKOMMEN ZURÜCK', 'ZUM CORE CHANNEL']) assert.ok(!startText.includes(gone), gone);

    // --- CoreAI geometry in the default skin (light) -------------------------------------------------------
    assert.equal(await page.getAttribute('html', 'data-coreui-skin'), 'coreai');
    const nav = await style(page, '#cfgNav', ['border-top-left-radius', 'clip-path']);
    assert.equal(nav['border-top-left-radius'], '0px');
    assert.match(nav['clip-path'], /^polygon/, 'the navigation box has cut corners');
    await page.evaluate(() => { location.hash = 'datenkontrollen'; });
    await page.waitForFunction(() => !document.getElementById('dcArchiveAll').disabled);
    const btn = await style(page, '#dcArchiveAll', ['border-top-left-radius', 'clip-path', 'font-family', 'text-transform', 'letter-spacing']);
    assert.equal(btn['border-top-left-radius'], '0px');
    assert.match(btn['clip-path'], /^polygon/);
    assert.match(btn['font-family'], /Share Tech Mono/);
    assert.equal(btn['text-transform'], 'uppercase');
    const lightBg = (await style(page, '#dcArchiveAll', ['background-color']))['background-color'];
    const badge = await style(page, '#view-datenkontrollen .kb-planned', ['font-family', 'text-transform', 'border-top-left-radius']);
    assert.match(badge['font-family'], /Share Tech Mono/);
    assert.equal(badge['text-transform'], 'uppercase');
    assert.equal(badge['border-top-left-radius'], '0px');
    await page.evaluate(() => { location.hash = 'nutzung'; });
    await page.waitForSelector('#view-nutzung:not([hidden])');
    const tab = await page.$eval('#view-nutzung [aria-selected="true"], #view-nutzung [aria-pressed="true"]', el => {
      const cs = getComputedStyle(el);
      return {font: cs.fontFamily, radius: cs.borderTopLeftRadius, shadow: cs.boxShadow};
    });
    assert.match(tab.font, /Share Tech Mono/);
    assert.equal(tab.radius, '0px');
    assert.match(tab.shadow, /inset/, 'the active tab is underlined in the accent');
    // Real circles stay round: the round selection marks of the appearance choices.
    await page.evaluate(() => { location.hash = 'anzeige'; });
    await page.waitForSelector('#view-anzeige:not([hidden])');
    const round = await page.$$eval('#view-anzeige *', els => els.filter(el => {
      const cs = getComputedStyle(el);
      return el.getClientRects().length && cs.borderTopLeftRadius === '50%';
    }).length);
    const roundBefore = round;
    assert.deepEqual(errors, []);
    await context.close();

    // --- Dark mode: the dark button colours -----------------------------------------------------------------
    ({context, page, errors} = await open(browser, {theme: 'dark'}, null, 'datenkontrollen'));
    assert.equal(await page.getAttribute('html', 'data-coreui-theme'), 'dark');
    await page.waitForFunction(() => !document.getElementById('dcArchiveAll').disabled);
    const dark = await style(page, '#dcArchiveAll', ['background-color', 'clip-path', 'border-top-left-radius']);
    assert.notEqual(dark['background-color'], lightBg, 'dark mode has its own button background');
    assert.match(dark['clip-path'], /^polygon/);
    assert.equal(dark['border-top-left-radius'], '0px');
    assert.deepEqual(errors, []);
    await context.close();

    // --- SoftGlass keeps its rounded look -------------------------------------------------------------------
    ({context, page, errors} = await open(browser, {uiSkin: 'softglass'}, null, 'datenkontrollen'));
    assert.equal(await page.getAttribute('html', 'data-coreui-skin'), 'softglass');
    await page.waitForFunction(() => !document.getElementById('dcArchiveAll').disabled);
    const glass = await style(page, '#dcArchiveAll', ['border-top-left-radius', 'clip-path', 'text-transform']);
    assert.notEqual(glass['border-top-left-radius'], '0px', 'SoftGlass buttons stay rounded');
    assert.equal(glass['clip-path'], 'none');
    assert.notEqual(glass['text-transform'], 'uppercase');
    assert.notEqual((await style(page, '#cfgNav', ['border-top-left-radius']))['border-top-left-radius'], '0px');
    await page.evaluate(() => { location.hash = 'anzeige'; });
    await page.waitForSelector('#view-anzeige:not([hidden])');
    assert.deepEqual(errors, []);
    await context.close();

    // Circles in the default skin are the same as before the theme: the theme squares no 50% element.
    ({context, page, errors} = await open(browser, null, null, 'anzeige'));
    await page.evaluate(() => document.querySelectorAll('link[href*="coreui-settings-theme.css"]').forEach(l => l.remove()));
    const roundWithout = await page.$$eval('#view-anzeige *', els => els.filter(el => {
      const cs = getComputedStyle(el);
      return el.getClientRects().length && cs.borderTopLeftRadius === '50%';
    }).length);
    assert.equal(roundBefore, roundWithout, 'real circles stay round');
    await context.close();

    // --- Expert settings: "(Expert)" after the title, hidden without a gap ---------------------------------
    ({context, page, errors} = await open(browser, null, null, 'ki-profil'));
    assert.equal(await page.isChecked('#settingsExpertToggle'), false, 'Expert mode starts off');
    const expertState = () => page.$$eval('[data-expert]', els => els.map(el => ({
      id: el.id || el.getAttribute('for') || el.getAttribute('aria-labelledby') || (el.querySelector('input, select, textarea') || {}).id,
      shown: el.getClientRects().length > 0,
      control: !!el.querySelector('input, select, textarea'),
      tag: (el.querySelector('.cfg-expert-tag') || {}).textContent || '',
    })));
    let state = await expertState();
    assert.equal(state.length, 7);
    assert.deepEqual(state.filter(x => x.shown), [], 'no Expert setting is shown without Expert mode');
    // KI-Profil: the system prompt card is gone, Antwort und Gedächtnis moves up; Sprache is the first field.
    const firstCard = await page.$eval('#view-ki-profil .cfg-cards', c => [...c.children].find(x => x.getClientRects().length).getAttribute('aria-labelledby'));
    assert.equal(firstCard, 'aiTuneTitle', 'the next card takes the place of the hidden one');
    const gap = await page.$eval('#view-ki-profil .settings-tuning-grid', grid => {
      const first = [...grid.children].find(x => x.getClientRects().length);
      return {id: first.getAttribute('for'), offset: first.getBoundingClientRect().top - grid.getBoundingClientRect().top};
    });
    assert.equal(gap.id, 'aiLanguage');
    assert.ok(gap.offset < 2, 'Sprache sits at the top of its card, no empty space above it');
    assert.equal(await page.isVisible('#externalProviderNote'), false, 'the external API note goes with the Core path');
    await page.click('#settingsExpertToggle + i');
    await page.waitForFunction(() => document.getElementById('settingsExpertToggle').checked);
    state = await expertState();
    assert.deepEqual(state.filter(x => x.id !== 'aiMemoryLimit' && x.id !== 'memoryRelevance').filter(x => !x.shown).map(x => x.id), [],
      'every Expert element of KI-Profil appears with Expert mode');
    for (const item of state.filter(x => x.control)) assert.equal(item.tag, '(Expert)', item.id + ' says (Expert) after its title');
    const tagStyle = await page.$eval('label[for="aiTemperature"] .cfg-expert-tag', el => {
      const cs = getComputedStyle(el);
      return {font: cs.fontFamily, transform: cs.textTransform};
    });
    assert.match(tagStyle.font, /Share Tech Mono/);
    assert.equal(tagStyle.transform, 'none');
    assert.equal(await page.isVisible('#aiPromptTitle .cfg-expert-tag'), true);
    // Memory: MEMORY-KONTEXT and RELEVANZ come and go the same way.
    await page.evaluate(() => { location.hash = 'memory'; });
    await page.waitForSelector('#view-memory:not([hidden])');
    assert.equal(await page.isVisible('#aiMemoryLimit'), true);
    assert.equal(await page.isVisible('#memoryRelevance'), true);
    assert.equal(await page.textContent('label[for="aiMemoryLimit"] .cfg-expert-tag'), '(Expert)');
    await page.click('#settingsExpertToggle + i');
    await page.waitForFunction(() => !document.getElementById('settingsExpertToggle').checked);
    assert.equal(await page.isVisible('#aiMemoryLimit'), false);
    assert.equal(await page.isVisible('#memoryRelevance'), false);
    const editor = await page.$eval('#view-memory .memory-editor', ed => {
      const shown = [...ed.children].filter(x => x.getClientRects().length).map(x => x.id || x.tagName.toLowerCase());
      return shown;
    });
    assert.deepEqual(editor, ['memoryFact', 'addMemory'], 'the save button moves up to the fact field');
    // A hidden last row leaves no separator under the row before it.
    await page.evaluate(() => { location.hash = 'anzeige'; });
    await page.waitForSelector('#view-anzeige:not([hidden])');
    const lines = await page.evaluate(() => {
      const last = document.querySelector('label[for="prefLiveFrameMotion"]');
      const before = document.querySelector('label[for="prefShowLiveBrowser"]');
      const open = getComputedStyle(before).borderBottomWidth;
      last.hidden = true;
      const closed = getComputedStyle(before).borderBottomWidth;
      last.hidden = false;
      return {open, closed};
    });
    assert.notEqual(lines.open, '0px', 'rows are separated');
    assert.equal(lines.closed, '0px', 'the last visible row of a card has no separator');
    assert.deepEqual(errors, []);
    await context.close();

    // --- A phone: no sideways scrolling on the themed pages ------------------------------------------------
    ({context, page, errors} = await open(browser, null, {width: 390, height: 844}));
    for (const view of ['start', 'anzeige', 'archivierte-chats', 'speicher', 'tastatur', 'nutzung', 'datenkontrollen', 'personalisierung']) {
      await page.evaluate(v => { location.hash = v; }, view);
      await page.waitForFunction(v => !document.getElementById('view-' + v).hidden, view);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, view + ' fits a phone');
    }
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI settings theme UI: load order, every page in the main column, Allgemein without duplicates, CoreAI geometry light and dark, SoftGlass untouched, circles round, Expert settings tagged and hidden without a gap, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
