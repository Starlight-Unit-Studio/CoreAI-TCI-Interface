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
const scripts = ['js/coreui-settings-menu.js','js/coreui-settings-tabs.js','js/coreui-send-keys.js','js/coreui-ui-language.js','js/coreui-preferences.js','js/settings.js',
  'js/admin.js','js/coreui-image-samples.js','js/coreui-voice-samples.js'];
scripts.forEach(name => new vm.Script(read(name), {filename:name}));
const sections = {
  personal: ['start','anzeige','benachrichtigungen','profil','sicherheit','konto',
    'archivierte-chats','stimme','speicher','personalisierung','pets','tastatur',
    'nutzung','datenkontrollen'],
  teams: ['teams-start','teams-erstellen'],
  integrations: ['plugins','passwoerter','cloud-computer'],
  programming: ['codex-cloud','code-review'],
  system: ['system'],
  expert: ['ki-profil','wissen','memory']
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
assert.ok(settings.includes('id="cfgCoreGroup"'));
assert.ok(!settings.includes('id="cfgExpertGroup"'));
assert.ok(['start','ki-profil','wissen','memory','system'].every(id => settings.includes('id="tab-' + id + '"')),
  'advanced categories remain accessible with Expert disabled');
const expertNodes = [...settings.matchAll(/data-expert hidden/g)];
assert.equal(expertNodes.length, 10, 'numeric and technical controls are conditionally hidden within categories');
assert.ok(settings.includes('id="aiNumCtx"') && settings.includes('id="aiNumPredict"'),
  'prior Core settings are retained');
for(const field of ['generalUiLanguage','generalSendKey','generalHigherIntelligence','generalReasoningLevels']) {
  assert.ok(settings.includes('id="' + field + '"'), 'General field: ' + field);
}
assert.ok(settings.includes('id="generalHigherIntelligence" type="checkbox" disabled'),
  'Core auto-upgrade starts disabled until the server confirms account and migration');
assert.ok(settings.includes('id="generalReasoningLevels" disabled'),
  'reasoning starts disabled until actual per-model capabilities arrive');
assert.ok(settings.includes('<option value="en">English (Beta)</option>') &&
  settings.includes('<option value="auto">Automatisch erkennen</option>'),
  'two translated interface languages and browser detection are selectable');
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
// Protect against the legacy horizontal mobile tab bar overriding the drawer.
const drawerRules = css.slice(css.indexOf('@media (max-width: 980px) {'));
assert.ok(drawerRules.includes('  .settings-body .cfg-nav {') &&
  drawerRules.includes('    flex-direction: column;') &&
  drawerRules.includes('    box-sizing: border-box;') &&
  drawerRules.includes('    overflow-x: hidden;'),
  'mobile navigation must be a vertically stacked, width-bounded drawer');
assert.ok(drawerRules.includes('  .settings-body .cfg-nav-group {') &&
  drawerRules.includes('    width: 100%;') &&
  drawerRules.includes('  .settings-body .cfg-tab {'),
  'mobile groups and tabs fill a single column without horizontal clipping');
console.log('CoreAI settings: 25 categories, General controls, 10 in-category Expert controls, honest planning pages, mobile menu and role-gated admin wiring OK');
