'use strict';
// Offline guard for the English installer/update experience. Runtime chat locale
// and original audit logs are intentionally not modified by this regression.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const update = read('js/coreui-update.js');
const settings = read('settings.html');
const browserTest = read('scripts/update-ui-selftest.cjs');

new vm.Script(update, {filename: 'coreui-update.js'});
new vm.Script(browserTest, {filename: 'update-ui-selftest.cjs'});

const requiredUI = [
  'Update failed', 'New version available',
  'Update log (latest lines)', 'Restart Ollama afterward',
  'Later', 'Retry', 'Start update', 'Waiting for restart',
  'Installation progress', 'Reload page', 'Cancel request',
  'The database is backed up first.',
  'Preflight reported errors.',
  'Last update failed:', 'New version ', 'Checking for updates',
  'Off by default.', 'Update completed, but the health check still reports errors.'
];
for (const label of requiredUI) {
  assert(update.includes(label), 'Missing English update window string: ' + label);
}

const requiredCard = [
  'OWNER ONLY', 'CoreAI Update', 'Loading update status ...',
  'INSTALLED', 'ON MAIN', 'AUTOMATIC UPDATES',
  'CHECK NOW', 'OPEN UPDATE WINDOW'
];
const card = settings.slice(settings.indexOf('<section', settings.indexOf('id="updateCardTitle"') - 180));
assert(settings.includes('id="updateCard"'), 'Owner update card must remain available');
for (const label of requiredCard) {
  assert(settings.includes(label), 'Missing English update card string: ' + label);
}
// The persisted 0.7.86 failure must be presented in English, but the verbatim
// update log is still shown as recorded for debugging and auditability.
assert(update.includes("'Die Installation meldet Fehler. Details im Update-Protokoll.': 'The installation reported errors. See the update log for details.'"));
assert(update.includes('translateLegacyUpdateMessage(info.message'), 'Owner must see the translated persisted failure');
assert(update.includes("last.log.join('\\n')"), 'Original log output must remain verbatim');
assert(browserTest.includes("locale: 'de-DE'"), 'Test a German-language phone without changing global UI language');
assert(browserTest.includes("'Update log (latest lines)'"), 'Browser fixture checks update details caption');
assert(browserTest.includes("'The installation reported errors. See the update log for details.'"),
  'Browser fixture checks English rendering of older persisted failure messages');
console.log('CoreAI update UI: English owner modal, settings card, legacy-error display and untouched logs OK');
