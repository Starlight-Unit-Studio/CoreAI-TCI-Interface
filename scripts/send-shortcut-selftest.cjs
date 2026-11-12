'use strict';
// CoreAI 0.6.71: regression for General keyboard settings and real composer.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const source = read('js/coreui-send-keys.js');
const sandbox = {window: {}};
vm.runInNewContext(source, sandbox, {filename:'coreui-send-keys.js'});
const helper = sandbox.window.CoreUISendKeys;
assert.equal(helper.mode(), 'enter');
assert.equal(helper.mode({sendKey:'unexpected'}), 'enter');
assert.equal(helper.mode({sendKey:'mod-enter'}), 'mod-enter');
const key = extras => Object.assign({key:'Enter', shiftKey:false, ctrlKey:false,
  metaKey:false, altKey:false, repeat:false, isComposing:false, keyCode:13}, extras || {});
assert.equal(helper.shouldSend(key(), {sendKey:'enter'}), true);
assert.equal(helper.shouldSend(key({shiftKey:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({ctrlKey:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({metaKey:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({altKey:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({repeat:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({isComposing:true}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({keyCode:229}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key({key:'a'}), {sendKey:'enter'}), false);
assert.equal(helper.shouldSend(key(), {sendKey:'mod-enter'}), false);
assert.equal(helper.shouldSend(key({ctrlKey:true}), {sendKey:'mod-enter'}), true);
assert.equal(helper.shouldSend(key({metaKey:true}), {sendKey:'mod-enter'}), true);
assert.equal(helper.shouldSend(key({ctrlKey:true,shiftKey:true}), {sendKey:'mod-enter'}), false);
assert.equal(helper.shouldSend(key({ctrlKey:true,isComposing:true}), {sendKey:'mod-enter'}), false);
assert.match(helper.hint({sendKey:'enter'}), /ENTER SENDEN/);
assert.match(helper.hint({sendKey:'mod-enter'}), /STRG\/⌘\+ENTER SENDEN/);
const prefs = read('js/coreui-preferences.js');
const settings = read('js/settings.js');
const app = read('js/console-app.js');
const page = read('settings.html');
const chat = read('app.html');
for (const file of ['js/coreui-send-keys.js','js/coreui-preferences.js','js/settings.js','js/console-app.js'])
  new vm.Script(read(file), {filename:file});
assert.ok(prefs.includes("sendKey: source.sendKey === 'mod-enter' ? 'mod-enter' : 'enter'"),
  'local storage normalizes shortcut modes');
// 0.7.29: Settings > Tastatur replaces the two-mode select; the send key is recorded like every shortcut
// (js/coreui-shortcuts.js) and keeps sendKey in step, so this fallback stays valid for older pages.
assert.ok(!settings.includes('generalSendKey'), 'no leftover of the old select in settings.js');
const shortcuts = read('js/coreui-shortcuts.js');
assert.ok(shortcuts.includes("var wanted = map.send && map.send.on && map.send.keys === 'Mod+Enter' ? 'mod-enter' : 'enter';"),
  'the shortcuts keep the old sendKey in step');
// With the shortcuts module present, CoreUISendKeys asks it.
const calls = [];
const withShortcuts = {window: {CoreAIShortcuts: {isSend: e => { calls.push(e.key); return e.key === 'Enter' && !!e.ctrlKey; },
  sendHint: () => 'STRG+ENTER SENDEN · ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD'}}};
vm.runInNewContext(source, withShortcuts, {filename: 'coreui-send-keys.js'});
const delegated = withShortcuts.window.CoreUISendKeys;
assert.equal(delegated.shouldSend(key(), {sendKey: 'enter'}), false, 'the shortcut decides, not the old mode');
assert.equal(delegated.shouldSend(key({ctrlKey: true}), {sendKey: 'enter'}), true);
assert.equal(delegated.hint({sendKey: 'enter'}), 'STRG+ENTER SENDEN · ENTER ZEILENUMBRUCH · LANGER TEXT ALS .MD');
assert.deepEqual(calls, ['Enter', 'Enter']);
assert.ok(settings.includes('sendKey: prior.sendKey, uiLanguage: prior.uiLanguage'),
  'resetting Appearance preserves the keyboard shortcut and interface language');
assert.ok(app.includes('window.CoreUISendKeys.shouldSend(e, prefs)'),
  'the composer uses the selected keyboard behavior');
assert.ok(chat.includes('id="composerSendKeyHint"'), 'composer announces the active shortcut');
assert.ok(chat.indexOf('js/coreui-send-keys.js') < chat.indexOf('js/console-app.js'),
  'keyboard module is loaded before the composer');
assert.ok(page.includes('id="kbGroups"') && !page.includes('id="generalSendKey"'), 'Settings > Tastatur holds the send key');
assert.ok(chat.indexOf('js/coreui-shortcuts.js') > chat.indexOf('js/coreui-send-keys.js') && chat.indexOf('js/coreui-shortcuts.js') < chat.indexOf('src="./js/console-app.js'),
  'the shortcuts are loaded before the composer');
console.log('CoreAI send shortcut: Enter, Ctrl/Command+Enter, IME, Shift, persistence, Settings > Tastatur and wiring OK');
