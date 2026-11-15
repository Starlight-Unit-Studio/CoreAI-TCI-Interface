'use strict';
// CoreAI must allow native selection, long-press copying and context menus.
// Do not bring the game interface's global copy/selection restrictions back.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');

const css = read('css/console.css');
const html = read('app.html');
const bodyRule = css.match(/(?:^|\n)body\s*\{([^}]*)\}/);
assert(bodyRule, 'CoreAI body rule must exist');
assert(!/(?:^|;)\s*(?:-webkit-)?user-select\s*:\s*none\b/i.test(bodyRule[1]),
  'The CoreAI body must not suppress selection');
assert(!/(\*|html|body)\s*,?\s*(?:html|body)?\s*\{[^}]*\b(?:-webkit-)?user-select\s*:\s*none\b/mi.test(css),
  'No whole-page selection lock may return');
assert(/\.\/css\/console\.css\?v=\d{25}/.test(html),
  'The updated console CSS must have a numeric content cache key');

// The chat must not hijack right-click, text selection or copying via JS.
const scripts = [...html.matchAll(/\bsrc=["']\.\/(js\/[A-Za-z0-9_./-]+\.js)(?:\?v=\d+)?["']/g)]
  .map(match => match[1]);
assert(scripts.length > 0, 'CoreAI JavaScript bundles are present');
assert(!/\bon(?:contextmenu|copy|selectstart|cut)\s*=/i.test(html),
  'No inline page-wide clipboard/context-menu blocker');
for (const file of scripts) {
  const js = read(file);
  assert(!/\b(?:document|window)\.addEventListener\(\s*["'](?:contextmenu|copy|selectstart|cut)["']/i.test(js),
    'No global context-menu or selection interceptor: ' + file);
  assert(!/\b(?:document|window)\.on(?:contextmenu|copy|selectstart|cut)\s*=/i.test(js),
    'No global clipboard or context-menu handler: ' + file);
}

// Keep legitimate gesture locks for movable pets and image editing surfaces.
assert(/\.coreai-pet-dock\s*\{[^}]*user-select\s*:\s*none/i.test(read('css/coreui-pet-dock.css')),
  'The draggable pet stays independently protected against accidental text selection');

console.log('CoreAI selection/copy/right-click: native browser interaction enabled');
