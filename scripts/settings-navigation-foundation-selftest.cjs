'use strict';
// CoreAI 0.6.71: structural, offline regression for the new navigation.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const settings = read('settings.html');
const admin = read('admin/index.html');
const css = read('css/settings.css');
const scripts = ['js/coreui-settings-menu.js','js/coreui-settings-tabs.js','js/settings.js',
  'js/admin.js','js/coreui-image-samples.js','js/coreui-voice-samples.js'];
scripts.forEach(name => new vm.Script(read(name), {filename:name}));
const sections = {
  personal: ['start','anzeige','benachrichtigungen','profil','sicherheit','konto',
    'archivierte-chats','stimme','speicher','personalisierung','pets','tastatur',
    'nutzung','datenkontrollen'],
  teams: ['teams-start','teams-erstellen'],
  integrations: ['plugins','passwoerter','cloud-computer'],
  programming: ['codex-cloud','code-review'],
  expert: ['ki-profil','wissen','memory','system']
};
const entries = Object.values(sections).flat();
assert.equal(entries.length, 25);
const ids = [...settings.matchAll(/\bid="([^"]+)"/g)].map(x => x[1]);
assert.equal(new Set(ids).size, ids.length, 'no duplicate IDs');
for(const entry of entries) {
  assert.ok(settings.includes('id="tab-' + entry + '"'), entry + ' nav item');
  assert.ok(settings.includes('id="view-' + entry + '"'), entry + ' content view');
  assert.ok(settings.includes('aria-controls="view-' + entry + '"'), entry + ' linked view');
}
const planned = [...settings.matchAll(/class="cfg-view cfg-view-planned"/g)];
assert.equal(planned.length, 16, 'all missing ChatGPT-style categories show honest scaffolds');
assert.ok(settings.includes('id="settingsExpertToggle"'));
assert.ok(settings.includes('id="cfgExpertGroup" hidden'));
assert.ok(settings.includes('id="settingsNavSearch"'));
assert.ok(settings.includes('id="settingsMenuToggle"'));
assert.ok(settings.includes('id="settingsMenuBackdrop"'));
assert.ok(settings.includes('id="adminLinkGroup" hidden'));
assert.ok(!settings.includes('id="adminNavGroup"'));
assert.ok(!settings.includes('id="view-admin-benutzer"'));
assert.ok(!settings.includes('src="./js/admin.js'));
for(const entry of ['admin-uebersicht','admin-benutzer','admin-zugang','admin-betrieb',
  'admin-memory','admin-lore','admin-protokoll','admin-audit']) {
  assert.ok(admin.includes('id="view-' + entry + '"'), entry + ' moved to the separate page');
}
assert.ok(admin.includes('id="adminShell" hidden'));
assert.ok(admin.includes('id="adminAccessGate"'));
assert.ok(admin.includes('id="adminNavGroup"'));
assert.ok(!admin.includes('http-equiv="refresh"'));
assert.ok(read('js/admin.js').includes("rootUrl('api/admin.php?action='"));
assert.ok(read('js/admin.js').includes('if (data.is_guest || Number(data.permission_level) > 1)'));
assert.ok(read('js/settings.js').includes("adminLinkGroup.dataset.authorized = mayAdmin ? '1' : '0'"));
assert.ok(read('js/coreui-image-samples.js').includes("new URL('admin.php', apiRoot)"));
assert.ok(read('js/coreui-voice-samples.js').includes("new URL('admin.php', apiRoot)"));
assert.ok(css.includes('.cfg-nav.is-open'));
assert.ok(css.includes('.cfg-menu-backdrop:not([hidden])'));
assert.ok(css.includes('prefers-reduced-motion: reduce'));
assert.ok(css.includes('.cfg-nav-group[hidden]'));
console.log('CoreAI settings navigation: 25 categories, 16 honest planning pages, expert toggle, accessible mobile menu and standalone role-gated admin wiring OK');
