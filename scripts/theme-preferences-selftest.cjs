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
  const attributes = Object.create(null);
  const metaValues = Object.create(null);
  const toggles = Object.create(null);
  const rootEl = {
    style: {},
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
      assert.equal(query, '(prefers-color-scheme: dark)');
      return media;
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
    storageEvent(name) {
      (listeners.storage || []).forEach(fn => fn({ key: name }));
    }
  };
}

// Default picks up the actual operating-system scheme and changes live.
const first = fixture();
assert.equal(first.prefs.load().theme, 'system');
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
first.prefs.save(Object.assign({}, first.prefs.load(), { theme: 'dark', compactFeed: true }));
first.changeOS(false);
assert.equal(first.attributes['data-coreui-theme'], 'dark');
assert.equal(first.toggles['pref-compact-feed'], true);
const second = fixture(first.values, false);
assert.equal(second.prefs.load().theme, 'dark');
assert.equal(second.prefs.load().compactFeed, true);
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
invalid.prefs.save({ theme: 'bogus' });
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
assert.match(settings, /syncFields\(preferences\.reset\(\)\)/);

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
  '.settings-body .about-copy .copyright-line strong',
  '.settings-body .settings-footer', '.capability-item'
]) {
  assert.ok(
    contrastRules.includes('html[data-coreui-theme="dark"] ' + selector),
    'Missing dark-mode contrast override for ' + selector
  );
}
assert.match(chatMarkup, /class="insight-chips"/);
assert.match(settingsMarkup, /class="copyright-line"/);
assert.match(settingsMarkup, /Patrick Schildgen/);
for (const declaration of [
  'background: #101d34;',
  'background: var(--theme-info-surface);',
  'background: #0a172c;',
  'color: #f4f7ff;',
  'color: #f4c686;',
  'color: var(--theme-info-muted);'
]) {
  assert.ok(contrastRules.includes(declaration), 'Missing readable surface: ' + declaration);
}
console.log('CoreAI contrast regression: info tabs, drawer and copyright OK');

console.log('CoreAI appearance preferences: 3 modes, OS sync, storage, pages and palette OK');
