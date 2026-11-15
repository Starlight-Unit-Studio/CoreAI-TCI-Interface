'use strict';
// CoreAI 0.7.69: every PHP endpoint the interface calls is allowed by both nginx templates.
//   node scripts/api-allowlist-selftest.cjs
// nginx answers 404 for any api/*.php that is not in the allowlist, before PHP runs. Until 0.7.69
// the project sources (api/console_project_sources.php) were missing there, so the tab "Quellen"
// only worked in tests. This compares the endpoints named in js/*.js and the HTML pages with
// the allowlist of config/nginx.conf.template and config/runtime/nginx.conf.template.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

function allowlist(file) {
  const m = /location ~ \^\/api\/\(\?:([a-z_|]+)\)\\\.php\$/.exec(read(file));
  assert.ok(m, file + ': no api allowlist found');
  return new Set(m[1].split('|'));
}
const templates = ['config/nginx.conf.template', 'config/runtime/nginx.conf.template'];
const lists = templates.map(allowlist);
assert.deepEqual([...lists[0]].sort(), [...lists[1]].sort(), 'both nginx templates allow the same endpoints');

const sources = fs.readdirSync(path.join(root, 'js')).filter(f => f.endsWith('.js')).map(f => 'js/' + f)
  .concat(['app.html', 'index.html', 'login.html', 'settings.html', 'protocols.html', 'admin/index.html'].filter(f => fs.existsSync(path.join(root, f))));
const used = new Map();
// Only names inside string literals count, after comments are removed ("see api/x.php" is no call).
const code = text => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/(^|[\s;{}(),])\/\/[^\n]*/g, '$1');
for (const file of sources) {
  for (const m of code(read(file)).matchAll(/['"`][^'"`\n]*?\b([a-z_]+)\.php\b/g)) {
    if (!used.has(m[1])) used.set(m[1], file);
  }
}
assert.ok(used.has('project_files') && used.has('console_project_sources') && used.has('chat'), 'the scan finds the endpoints');
const missing = [];
for (const [name, file] of used) {
  if (!fs.existsSync(path.join(root, 'api', name + '.php'))) continue; // not an api endpoint (e.g. a page)
  lists.forEach((list, i) => { if (!list.has(name)) missing.push(name + '.php (used in ' + file + ', missing in ' + templates[i] + ')'); });
}
assert.deepEqual(missing, [], 'endpoints the interface calls but nginx refuses:\n' + missing.join('\n'));
console.log('CoreAI API-Freigabe: ' + used.size + ' Endpunkte der Oberflaeche, alle in beiden nginx-Vorlagen erlaubt OK');
