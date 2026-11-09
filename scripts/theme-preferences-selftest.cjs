#!/usr/bin/env node
'use strict';
/* Offline regression test for CoreAI appearance preferences.
   Runs with Node's built-ins, no Playwright, browser or npm dependencies. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js/coreui-preferences.js'), 'utf8');
const settings = fs.readFileSync(path.join(root, 'js/settings.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'settings.html'), 'utf8');
const themeCSS = fs.readFileSync(path.join(root, 'css/coreui-theme.css'), 'utf8');
const key = 'emberCoreUI.preferences.v1';

function fixture(store, initiallyDark, blockedStorage) {
  const values = store || Object.create(null);
  const listeners = Object.create(null);
  const mediaListeners = [];
  const motionListeners = [];
  const attributes = Object.create(null);
  const metaValues = Object.create(null);
  const toggles = Object.create(null);
  const rootEl = {
    style: {setProperty(name, value) { this[name] = String(value); }},
    setAttribute(name, value) { attributes[name] = value; },
    classList: {
      toggle(name, enabled) { toggles[name] = !!enabled; }
    }
  };
  const meta = (type) => ({
    setAttribute(name, value) { metaValues[type] = String(value); }
  });
  const document = {
    documentElement: rootEl,
    querySelector(selector) {
      if (selector === 'meta[name="theme-color"]') return meta('theme-color');
      if (selector === 'meta[name="color-scheme"]') return meta('color-scheme');
      return null;
    }
  };
  const media = {
    matches: !!initiallyDark,
    addEventListener(event, callback) {
      if (event === 'change') mediaListeners.push(callback);
    }
  };
  const reduced = {
    matches: false,
    addEventListener(event, callback) {
      if (event === 'change') motionListeners.push(callback);
    }
  };
  const storage = {
    getItem(name) {
      if (blockedStorage) throw new Error('Storage denied');
      return Object.prototype.hasOwnProperty.call(values, name) ? values[name] : null;
    },
    setItem(name, value) {
      if (blockedStorage) throw new Error('Storage denied');
      values[name] = String(value);
    },
    removeItem(name) {
      if (blockedStorage) throw new Error('Storage denied');
      delete values[name];
    }
  };
  const win = {
    localStorage: storage,
    matchMedia(query) {
      if (query === '(prefers-color-scheme: dark)') return media;
      if (query === '(prefers-reduced-motion: reduce)') return reduced;
      throw new Error('unexpected media query ' + query);
    },
    addEventListener(event, fn) { (listeners[event] ||= []).push(fn); },
    dispatchEvent() { return true; }
  };
  function CustomEvent(event, opts) { this.type = event; this.detail = opts.detail; }
  vm.runInNewContext(source, { window: win, document, CustomEvent });
  return {
    prefs: win.CoreUIPreferences, values, attributes, metaValues, toggles, rootEl,
    changeOS(dark) {
      media.matches = dark;
      mediaListeners.forEach(fn => fn({ matches: dark }));
    },
    changeMotion(isReduced) {
      reduced.matches = isReduced;
      motionListeners.forEach(fn => fn({ matches: isReduced }));
    },
    storageEvent(name) {
      (listeners.storage || []).forEach(fn => fn({ key: name }));
    }
  };
}

// Default picks up the actual operating-system scheme and changes live.
const first = fixture();
assert.equal(first.prefs.load().theme, 'system');
assert.equal(first.prefs.load().sendKey, 'enter', 'existing accounts still use Enter to send');
assert.equal(first.prefs.load().uiLanguage, 'auto', 'interface language defaults to browser detection');
assert.equal(first.attributes['data-coreui-theme'], 'light');
assert.equal(first.attributes['data-coreui-theme-choice'], 'system');
first.changeOS(true);
assert.equal(first.attributes['data-coreui-theme'], 'dark');
assert.equal(first.metaValues['theme-color'], '#0c1427');
assert.equal(first.rootEl.style.colorScheme, 'dark');
first.changeOS(false);
assert.equal(first.attributes['data-coreui-theme'], 'light');

// Manual choices must override OS switches, survive reloads and preserve
// all previously persisted legacy preferences.
first.prefs.save(Object.assign({}, first.prefs.load(), { theme: 'dark', compactFeed: true, sendKey: 'mod-enter' }));
first.changeOS(false);
assert.equal(first.attributes['data-coreui-theme'], 'dark');
assert.equal(first.toggles['pref-compact-feed'], true);
const second = fixture(first.values, false);
assert.equal(second.prefs.load().theme, 'dark');
assert.equal(second.prefs.load().compactFeed, true);
assert.equal(second.prefs.load().sendKey, 'mod-enter');
second.prefs.save(Object.assign({}, second.prefs.load(), { theme: 'light' }));
second.changeOS(true);
assert.equal(second.attributes['data-coreui-theme'], 'light');

// Old v1 preference JSON with no theme field must migrate in memory.
const legacy = fixture({ [key]: JSON.stringify({ reduceMotion: true, showTelemetry: false }) }, true);
assert.equal(legacy.prefs.load().theme, 'system');
assert.equal(legacy.attributes['data-coreui-theme'], 'dark');
assert.equal(legacy.toggles['pref-reduced-motion'], true);
assert.equal(legacy.toggles['pref-hide-telemetry'], true);

// Invalid values cannot turn into arbitrary attributes or CSS selectors.
const invalid = fixture({ [key]: JSON.stringify({ theme: 'other" mode' }) }, false);
assert.equal(invalid.prefs.load().theme, 'system');
invalid.prefs.save({ theme: 'bogus', sendKey: 'nonsense' });
assert.equal(invalid.prefs.load().sendKey, 'enter', 'invalid shortcut normalized to safe default');
assert.equal(invalid.attributes['data-coreui-theme-choice'], 'system');
assert.equal(invalid.attributes['data-coreui-theme'], 'light');
invalid.prefs.reset();
assert.equal(invalid.prefs.load().theme, 'system');

// Cross-tab changes update the current page without a reload.
second.values[key] = JSON.stringify(Object.assign({}, second.prefs.load(), { theme: 'dark' }));
second.storageEvent(key);
assert.equal(second.attributes['data-coreui-theme'], 'dark');
second.storageEvent('unrelated-storage-key');
assert.equal(second.attributes['data-coreui-theme'], 'dark');

// Disabled localStorage must not crash the UI.
const storageBlocked = fixture(null, true, true);
storageBlocked.prefs.save({ theme: 'light' });
assert.equal(storageBlocked.attributes['data-coreui-theme'], 'light');
assert.equal(storageBlocked.prefs.load().theme, 'system');

// Settings radio inputs must be wired into the existing save/reset flow.
for (const name of ['system', 'light', 'dark']) {
  assert.match(page, new RegExp('name="coreuiTheme" value="' + name + '"'));
}
assert.match(settings, /themeOptions\.forEach\(function \(option\)/);
assert.match(settings, /value\.theme = selected \? selected\.value/);
assert.match(settings, /preferences\.reset\(\)/);
assert.match(settings, /sendKey: prior.sendKey/, 'Appearance reset preserves the general send shortcut');

// The stylesheet is loaded everywhere. No global inversion is permitted.
for (const file of ['index.html', 'login.html', 'app.html', 'settings.html',
                    'protocols.html', 'admin/index.html']) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');
  assert.match(html, /coreui-theme\.css\?v=\d{25}/, file + ' has theme stylesheet');
  assert.match(html, /coreui-preferences\.js\?v=\d{25}/, file + ' initializes theme');
}
assert.match(themeCSS, /:root\[data-coreui-theme="dark"\]/);
for (const selector of ['.msg-bubble', '.drawer', '.settings-panel', '.login-card', '.composer']) {
  assert.ok(themeCSS.includes(selector), 'Missing surface ' + selector);
}
assert.doesNotMatch(themeCSS, /filter\s*:\s*invert\(/i);


// Regression for the user-reported 0.6.12 dark-mode contrast defects.
// Use real selectors from the codebase, not hypothetical toolbar classes.
const chatMarkup = fs.readFileSync(path.join(root, 'app.html'), 'utf8');
const settingsMarkup = fs.readFileSync(path.join(root, 'settings.html'), 'utf8');
const contrastRules = themeCSS.split('/* 0.6.13 contrast regression repair.')[1] || '';
assert.ok(contrastRules, 'Contrast hotfix section is missing');
for (const selector of [
  '.insight-chips', '.insight-chips::after', '.insight-chip',
  '.insight-chip.is-open', '.insight-sheet .insight-box', '.insight-box',
  '.drawer .drawer-header', '.drawer .drawer-footer', '.drawer .core-ticker',
  '.settings-body .about-copy .copyright-line',
  '.settings-body .settings-footer'
]) {
  assert.ok(
    contrastRules.includes('html[data-coreui-theme="dark"] ' + selector),
    'Missing dark-mode contrast override for ' + selector
  );
}
assert.match(chatMarkup, /class="insight-chips"/);
// 0.6.14: the decorative tool strip above the input field is gone (the Werkzeuge tab lists the tools).
assert.doesNotMatch(chatMarkup, /capability-strip|capability-item/, 'No tool strip above the input field');
assert.doesNotMatch(fs.readFileSync(path.join(root, 'css/console.css'), 'utf8') + themeCSS,
  /\.capability-(?:strip|item|label)/, 'No leftover tool strip styles');
assert.match(settingsMarkup, /class="copyright-line"/);
assert.match(settingsMarkup, /Patrick Schildgen/);
for (const declaration of [
  'background: #101d34;',
  'background: var(--theme-info-surface);',
  'background: #0a172c;',
  'color: #f4c686;',
  'color: var(--theme-info-muted);'
]) {
  assert.ok(contrastRules.includes(declaration), 'Missing readable surface: ' + declaration);
}
// 0.6.70 (operator): the owner credit uses the same copyright color as the line, no special case.
assert.ok(!contrastRules.includes('.copyright-line strong'), 'no special color for the owner credit');
console.log('CoreAI contrast regression: info tabs, drawer and copyright OK');


// 0.6.79: Appearance colors are per scheme, input validation is strict,
// and typography/motion/layout survive a reload without touching accounts.
const custom = fixture();
custom.prefs.save(Object.assign({}, custom.prefs.load(), {
  motif: 'quiet', accent: 'green',
  backgroundLight: '#e9f1ff', foregroundLight: '#142238',
  backgroundDark: '#101b30', foregroundDark: '#f5efff',
  uiFontSize: 18, contentFontSize: 20, codeFontSize: 14,
  contrast: 80, motionMode: 'system', fullWidth: true, diffMarkers: 'symbols'
}));
assert.equal(custom.toggles['pref-motif-quiet'], true);
assert.equal(custom.toggles['pref-full-width'], true);
assert.equal(custom.toggles['pref-diff-symbols'], true);
assert.equal(custom.rootEl.style['--bg'], '#e9f1ff');
assert.equal(custom.rootEl.style['--cx'], '#299b70');
assert.equal(custom.rootEl.style['--pref-ui-font'], '18px');
assert.equal(custom.rootEl.style['--pref-content-font'], '20px');
assert.equal(custom.rootEl.style['--pref-code-font'], '14px');
assert.equal(custom.toggles['pref-reduced-motion'], false);
custom.changeMotion(true);
assert.equal(custom.toggles['pref-reduced-motion'], true);
custom.changeMotion(false);
assert.equal(custom.toggles['pref-reduced-motion'], false);
custom.prefs.save(Object.assign({}, custom.prefs.load(), {theme: 'dark'}));
assert.equal(custom.rootEl.style['--bg'], '#101b30', 'dark palette is independent');
assert.equal(custom.prefs.load().backgroundLight, '#e9f1ff', 'switching dark preserves light palette');
const reloaded = fixture(custom.values);
assert.equal(reloaded.prefs.load().contrast, 80);
assert.equal(reloaded.prefs.load().uiFontSize, 18);
assert.equal(reloaded.prefs.load().foregroundDark, '#f5efff');
const invalidAppearance = fixture();
invalidAppearance.prefs.save({backgroundLight: '#1234; color:red', foregroundDark: 'url(x)',
  accent: 'javascript', uiFontSize: 5000, codeFontSize: -1,
  contrast: 1000, motionMode: 'invalid', diffMarkers: 'html'});
assert.equal(invalidAppearance.prefs.load().backgroundLight, '#f0f4fa');
assert.equal(invalidAppearance.prefs.load().foregroundDark, '#eef4ff');
assert.equal(invalidAppearance.prefs.load().accent, 'blue');
assert.equal(invalidAppearance.prefs.load().uiFontSize, 14);
assert.equal(invalidAppearance.prefs.load().contrast, 60);
assert.equal(invalidAppearance.prefs.load().diffMarkers, 'color');
for (const id of ['prefMotif', 'prefAccent', 'prefBackground', 'prefForeground',
  'prefUiFont', 'prefContentFont', 'prefCodeFont', 'prefContrast',
  'prefMotionMode', 'prefFullWidth', 'prefDiffMarkers']) {
  assert.ok(page.includes('id="' + id + '"'), 'real Darstellung input ' + id);
}
assert.ok(settings.includes('appearanceFields') && settings.includes('value.backgroundLight'),
  'appearance controls use the local save pipeline');

// 0.6.80: Schriftart, Hell/Dunkel getrennt, motif export/import, smart punctuation.
const look = fixture();
assert.equal(look.prefs.load().fontFamily, 'coreai', 'CoreAI font by default');
assert.equal(look.prefs.load().separateModes, false);
assert.equal(look.prefs.load().smartPunctuation, false, 'smart punctuation is opt-in');
assert.equal(look.rootEl.style['--coreui-font'], '"Rajdhani"');
look.prefs.save(Object.assign({}, look.prefs.load(), {fontFamily: 'system', motif: 'quiet', accent: 'violet',
  motifDark: 'holo', accentDark: 'amber', fontFamilyDark: 'coreai'}));
assert.match(look.rootEl.style['--coreui-font'], /^system-ui, /, 'system font stack');
assert.equal(look.toggles['pref-font-system'], true);
look.prefs.save(Object.assign({}, look.prefs.load(), {theme: 'dark'}));
assert.equal(look.toggles['pref-motif-quiet'], true, 'not separate: dark uses the shared motif');
assert.equal(look.rootEl.style['--cx'], '#8a6ff2', 'not separate: dark uses the shared accent');
look.prefs.save(Object.assign({}, look.prefs.load(), {separateModes: true}));
assert.equal(look.toggles['pref-motif-quiet'], false, 'separate: dark has its own motif');
assert.equal(look.rootEl.style['--cx'], '#ca852a', 'separate: dark has its own accent');
assert.equal(look.rootEl.style['--coreui-font'], '"Rajdhani"', 'separate: dark has its own font');
look.prefs.save(Object.assign({}, look.prefs.load(), {theme: 'light'}));
assert.equal(look.toggles['pref-motif-quiet'], true, 'light keeps the shared look');
assert.match(look.rootEl.style['--coreui-font'], /^system-ui/);
const invalidLook = fixture();
invalidLook.prefs.save({fontFamily: 'Comic"Sans', motifDark: '<x>', accentDark: 'red', fontFamilyDark: 1, separateModes: 'yes',
  smartPunctuation: 'true'});
assert.deepEqual([invalidLook.prefs.load().fontFamily, invalidLook.prefs.load().motifDark, invalidLook.prefs.load().accentDark,
  invalidLook.prefs.load().fontFamilyDark, invalidLook.prefs.load().separateModes, invalidLook.prefs.load().smartPunctuation],
  ['coreai', 'holo', 'blue', 'coreai', false, false], 'only known values, strict booleans');
const older = fixture({[key]: JSON.stringify({motif: 'quiet', accent: 'green'})});
assert.deepEqual([older.prefs.load().motifDark, older.prefs.load().accentDark], ['quiet', 'green'],
  '0.6.79 values: the dark look starts from the shared one');

// Objects from the page's own context: compare their JSON form.
const motif = JSON.parse(JSON.stringify(look.prefs.exportMotif(look.prefs.load())));
assert.deepEqual(Object.keys(motif), ['format', 'version', 'separateModes', 'light', 'dark']);
assert.equal(motif.format, 'coreai-motiv');
assert.deepEqual(motif.light, {motif: 'quiet', accent: 'violet', font: 'system', background: '#f0f4fa', foreground: '#101626'});
assert.deepEqual(motif.dark, {motif: 'holo', accent: 'amber', font: 'coreai', background: '#0c1427', foreground: '#eef4ff'});
assert.ok(!JSON.stringify(motif).includes('sendKey') && !JSON.stringify(motif).includes('uiFontSize'), 'a motif carries no other settings');
const target = fixture();
target.prefs.save(Object.assign({}, target.prefs.load(), {sendKey: 'mod-enter', uiFontSize: 20}));
const imported = target.prefs.importMotif(JSON.stringify(motif), target.prefs.load());
assert.ok(imported, 'an exported motif imports');
assert.deepEqual([imported.motif, imported.accent, imported.fontFamily, imported.separateModes, imported.motifDark, imported.accentDark],
  ['quiet', 'violet', 'system', true, 'holo', 'amber']);
assert.deepEqual([imported.sendKey, imported.uiFontSize], ['mod-enter', 20], 'import keeps everything else');
const shared = target.prefs.importMotif(Object.assign({}, motif, {separateModes: false}), target.prefs.load());
assert.deepEqual([shared.separateModes, shared.motifDark, shared.accentDark], [false, 'quiet', 'violet'],
  'a shared motif gives Dunkel the same look');
for (const bad of ['', 'nonsense', '{}', JSON.stringify(Object.assign({}, motif, {format: 'x'})),
  JSON.stringify(Object.assign({}, motif, {version: 2})),
  JSON.stringify(Object.assign({}, motif, {light: Object.assign({}, motif.light, {background: 'red'})})),
  JSON.stringify(Object.assign({}, motif, {light: Object.assign({}, motif.light, {background: '#fff; x'})})),
  JSON.stringify(Object.assign({}, motif, {light: Object.assign({}, motif.light, {accent: 'pink'})})),
  JSON.stringify(Object.assign({}, motif, {dark: Object.assign({}, motif.dark, {font: 'Comic'})})),
  JSON.stringify(Object.assign({}, motif, {dark: null})),
  JSON.stringify(motif) + ' '.repeat(4000)]) {
  assert.equal(target.prefs.importMotif(bad, target.prefs.load()), null, 'rejected: ' + bad.slice(0, 60));
}

// Smart punctuation: the pure rules (the typing itself is tested in appearance-ui-selftest.cjs).
const punctSource = fs.readFileSync(path.join(root, 'js/coreui-smart-punctuation.js'), 'utf8');
const punctWin = {};
vm.runInNewContext(punctSource, {window: punctWin, document: {getElementById: () => null, documentElement: {getAttribute: () => 'de'}}});
const P = punctWin.CoreAISmartPunctuation;
const typed = (text, lang) => {
  const hit = P.replacement(text, lang || 'de');
  return hit ? text.slice(0, text.length - hit.remove) + hit.insert : text;
};
const cases = [
  ['"', '„'], ['Sie sagte "', 'Sie sagte „'], ['Sie sagte „Hallo"', 'Sie sagte „Hallo“'],
  ['geht\'', 'geht’'], ['\'', '‚'], ['‚so\'', '‚so‘'],
  ['warte...', 'warte…'], ['....', '....'], ['A --', 'A –'], ['A -', 'A -'], ['Wort -', 'Wort -'],
  ['Satz--', 'Satz–'], ['---', '---'], ['--', '--'], ['  --', '  --'],
  ['a ->', 'a →'], ['a <-', 'a ←'], ['a ←>', 'a ↔'], ['a =>', 'a ⇒'],
  ['x <=', 'x ≤'], ['x >=', 'x ≥'], ['x !=', 'x ≠'], ['x ~=', 'x ≈'], ['5 +-', '5 ±'],
  ['Satz–>', 'Satz→'], ['x', 'x'], ['a == ', 'a == '],
  ['`a"', '`a"'], ['```\nx = "', '```\nx = "'], ['```\ncode\n```\nund "', '```\ncode\n```\nund „'],
  ['siehe https://x.de/a->', 'siehe https://x.de/a->'], ['www.a.de/...', 'www.a.de/...'],
];
for (const [input, expected] of cases) assert.equal(typed(input), expected, 'typed ' + JSON.stringify(input));
assert.equal(typed('He said "', 'en'), 'He said “');
assert.equal(typed('He said “hi"', 'en'), 'He said “hi”');
assert.equal(typed('it\'', 'en'), 'it’');
assert.equal(typed('\'', 'en'), '‘');
assert.ok(!/[\u2014]/.test(cases.map(c => typed(c[0])).join('')), 'never a long dash (\u2014)');
const html = page;
for (const id of ['prefFontFamily', 'prefSeparateModes', 'prefSmartPunctuation', 'prefEditMode', 'prefBackgroundHex',
  'prefForegroundHex', 'motifExport', 'motifCopy', 'motifImport', 'motifImportPanel', 'motifImportText', 'motifImportFile']) {
  assert.ok(html.includes('id="' + id + '"'), '0.6.80 Darstellung element ' + id);
}
for (const id of ['prefMotionMode', 'prefDiffMarkers', 'prefEditMode']) {
  assert.ok(new RegExp('id="' + id + '" role="radiogroup"').test(html), id + ' is a segment');
}
assert.ok(fs.readFileSync(path.join(root, 'app.html'), 'utf8').match(/coreui-smart-punctuation\.js\?v=\d{25}"><\/script>\n  <script src="\.\/js\/coreui-send-keys\.js/),
  'the chat loads smart punctuation before the send keys and the chat');
const cssAll = ['css/console.css', 'css/settings.css', 'css/artifacts.css', 'css/coreui-image-viewer.css', 'css/coreui-profile-crop.css']
  .map(f => fs.readFileSync(path.join(root, f), 'utf8')).join('\n');
const outsideFontFace = cssAll.replace(/@font-face\s*\{[^}]*\}/g, '');
assert.ok(!/(^|[^,(] ?)"Rajdhani"/.test(outsideFontFace.replace(/var\(--coreui-font, "Rajdhani"\)/g, '')),
  'every Rajdhani declaration follows the chosen font');
assert.equal((cssAll.match(/@font-face\s*\{[^}]*font-family: "Rajdhani";/g) || []).length, 3, 'the three Rajdhani faces stay');
console.log('CoreAI appearance 0.6.80: font, Hell/Dunkel separate, motif export/import (validated), smart punctuation rules OK');

console.log('CoreAI appearance preferences: 3 modes, OS sync, storage, pages and palette OK');
