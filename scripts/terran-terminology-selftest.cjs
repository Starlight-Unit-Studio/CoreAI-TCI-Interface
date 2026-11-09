#!/usr/bin/env node
'use strict';
/* CoreAI terminology regression: user-facing terran interface only.
   Historical changelogs, lore documents and internal technical identifiers
   are deliberately outside this check. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
function read(file) {
  return fs.readFileSync(path.join(root, file), 'utf8');
}
const settings = read('settings.html');
const app = read('app.html');
const splash = read('index.html');
const chat = read('js/console-app.js');

assert.match(settings, /TERRANISCHE SYSTEMKONFIGURATION/);
assert.match(app, /TERRA-NET MATRIX \/\/ N-73/);
assert.match(app, /aria-label="Terra-Net Systemticker"/);
assert.match(splash, /TERRA-NET MATRIX INIT/);
assert.match(splash, /TERRA-NET VERBINDUNG/);
// 0.6.68: the welcome screen replaced the second connection line (empty account); one remains.
assert.equal((chat.match(/TERRA-NET VERBINDUNG HERGESTELLT/g) || []).length, 1);
for (const [file, content] of [
  ['settings.html', settings],
  ['app.html', app],
  ['index.html', splash],
  ['js/console-app.js', chat]
]) {
  assert.doesNotMatch(content, /AYAL['’]?REN|ARCH[ -]?NET/i, file + ': outdated terminal lore');
}
console.log('CoreAI terran UI terminology: correct');
