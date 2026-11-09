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
assert.ok(settings.includes("generalSendKey.addEventListener('change', saveLocal)"),
  'General control uses the real preference store');
assert.ok(settings.includes('sendKey: oldSendKey'), 'resetting Appearance preserves the shortcut');
assert.ok(app.includes('window.CoreUISendKeys.shouldSend(e, prefs)'),
  'the composer uses the selected keyboard behavior');
assert.ok(chat.includes('id="composerSendKeyHint"'), 'composer announces the active shortcut');
assert.ok(chat.indexOf('js/coreui-send-keys.js') < chat.indexOf('js/console-app.js'),
  'keyboard module is loaded before the composer');
assert.ok(page.includes('id="generalSendKey"'), 'General includes send-key setting');
console.log('CoreAI General send shortcut: Enter, Ctrl/Command+Enter, IME, Shift, persistence and wiring OK');
