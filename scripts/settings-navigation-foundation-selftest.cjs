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
assert.equal(planned.length, 12, 'only genuinely unimplemented settings remain planned');
assert.ok(settings.includes('id="settingsExpertToggle"'));
assert.ok(settings.includes('class="cfg-header-expert"') &&
  settings.indexOf('id="settingsExpertToggle"') < settings.indexOf('<div class="cfg-shell">'),
  'single Expert control lives in the header, not in the scrolling navigation');
assert.ok(settings.includes('id="settingsAdminShortcut"') &&
  settings.includes('id="settingsAdminShortcut" class="cfg-admin-shortcut"') &&
  settings.includes('href="./admin/index.html" hidden'),
  'standalone Admin Core has an initially hidden header shortcut');
const section = id => {
  const at = settings.indexOf('id="view-' + id + '"');
  assert(at > -1, 'settings page ' + id + ' exists');
  const start = settings.lastIndexOf('<section class="cfg-view', at);
  const next = settings.indexOf('<section class="cfg-view', at + 9);
  return settings.slice(start, next === -1 ? settings.length : next);
};
for (const [id, fields] of Object.entries({
  sicherheit: ['changePassword', 'currentPassword', 'loginSessionList', 'revokeOtherSessions'],
  tastatur: ['generalSendKey'],
  datenkontrollen: ['exportAccountData'],
  stimme: ['voiceAutoRead', 'voiceTerms'],
  memory: ['aiMemoryEnabled', 'aiMemoryLimit', 'saveMemoryAiSettings'],
})) {
  const html = section(id);
  assert.ok(!html.includes('cfg-view-planned'), id + ' is a real page');
  for (const field of fields) assert.ok(html.includes('id="' + field + '"'),
    field + ' belongs to ' + id);
}
assert.ok(!section('konto').includes('id="changePassword"') &&
  !section('konto').includes('id="exportAccountData"'),
  'account retains identity and logout without misplaced controls');
assert.ok(!section('start').includes('id="generalSendKey"'),
  'the send shortcut is no longer duplicated in General');
assert.ok(settings.includes('id="cfgCoreGroup"'));
assert.ok(!settings.includes('id="cfgExpertGroup"'));
assert.ok(['start','ki-profil','wissen','memory','system'].every(id => settings.includes('id="tab-' + id + '"')),
  'advanced categories remain accessible with Expert disabled');
const expertNodes = [...settings.matchAll(/data-expert hidden/g)];
assert.equal(expertNodes.length, 6, 'only raw technical controls remain Expert-gated, while smart controls stay visible');
for (const id of ['aiNumPredict', 'aiTemperature', 'aiProvider', 'aiMemoryLimit']) {
  assert.ok(new RegExp('<label[^>]*for="' + id + '"[^>]*data-expert hidden>').test(settings),
    id + ' is a raw technical input kept behind Expert');
}
assert.ok(/<label data-expert hidden>RELEVANZ\s*<input id="memoryRelevance"/.test(settings),
  'raw numeric fact relevance remains Expert-only');
assert.ok(/<section[^>]*aria-labelledby="aiPromptTitle" data-expert hidden>/.test(settings),
  'system prompt editing remains an advanced instruction tool');
for (const id of ['prefShowTelemetry', 'prefLiveFrameMotion']) {
  assert.ok(new RegExp('<label[^>]*for="' + id + '"[^>]*>').test(settings),
    'normal display control ' + id + ' is present');
  assert.ok(!new RegExp('<label[^>]*for="' + id + '"[^>]*data-expert').test(settings),
    'normal display control ' + id + ' is not Expert-only');
}
assert.ok(/<section[^>]*aria-labelledby="aiCtxTitle">/.test(settings) &&
  !/<section[^>]*aria-labelledby="aiCtxTitle" data-expert/.test(settings),
  'smart context slider remains ordinary; raw numeric context inputs would be Expert-only');
assert.ok(settings.includes('id="aiNumCtx"') && settings.includes('id="aiNumPredict"'),
  'prior Core settings are retained');
for(const field of ['generalUiLanguage','generalSendKey','generalHigherIntelligence','generalReasoningLevels']) {
  assert.ok(settings.includes('id="' + field + '"'), 'General field: ' + field);
}
assert.ok(settings.includes('id="generalHigherIntelligence" type="checkbox" disabled'),
  'Core auto-upgrade starts disabled until the server confirms account and migration');
assert.ok(settings.includes('<label for="generalReasoningLevels">Denkaufwand</label>') &&
  !settings.includes('class="cfg-general-row" data-expert hidden>\n              <div class="cfg-general-copy">\n                <label for="generalReasoningLevels">'),
  'Denkaufwand is directly available in General without Expert mode');
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
assert.ok(read('js/settings.js').includes('adminShortcut.hidden = !mayAdmin'),
  'header Admin shortcut follows the same authenticated role gate');
assert.ok(read('js/settings.js').includes("memorySettingsSaveButton.addEventListener('click', saveAiSettings)"),
  'relocated Memory controls still save via the existing account API');
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
assert.ok(css.includes('cfg-nav-collapsed') && css.includes('cfg-header-expert'));
assert.ok(read('js/coreui-settings-menu.js').includes("doc.addEventListener('touchstart'") &&
  read('js/coreui-settings-menu.js').includes("doc.addEventListener('touchend'"),
  'swipe navigation is implemented alongside keyboard and button access');
console.log('CoreAI settings: 25 categories, 12 honest planning pages, migrated live controls, header Expert/Admin, responsive swipe navigation OK');
