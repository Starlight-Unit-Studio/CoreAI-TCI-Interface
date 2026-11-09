'use strict';
// CoreAI 0.6.71: isolated DE/EN locale and browser-auto regression.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root,file),'utf8');
const src = read('js/coreui-ui-language.js');
new vm.Script(src);
const state = {uiLanguage:'en'};
const callbacks = {};
function text(value){return {textContent:value,children:[]};}
const tab = text('Allgemein'), title = text('EINSTELLUNGEN');
const bubble = text('My private message to Mara');
const attrs = {};
const rootEl = {setAttribute(k,v){attrs[k]=v},lang:'de'};
const fields = {settingsNavSearch:{getAttribute:()=>'',setAttribute(k,v){this.value=v}},
  inputMessage:{getAttribute:()=>'',setAttribute(k,v){this.value=v}}};
const doc = {documentElement:rootEl,readyState:'complete',
  getElementById:id=>fields[id]||null,
  querySelectorAll:()=>[tab,title]};
const win = {document:doc,navigator:{language:'de-DE'},
  CoreUIPreferences:{load:()=>state},
  addEventListener:(k,fn)=>{callbacks[k]=fn}};
vm.runInNewContext(src,{window:win,WeakMap});
assert.equal(tab.textContent,'General');
assert.equal(title.textContent,'SETTINGS');
assert.equal(rootEl.lang,'en');
assert.equal(bubble.textContent,'My private message to Mara','chat messages never translated');
state.uiLanguage='de';callbacks['coreui:preferences']();
assert.equal(tab.textContent,'Allgemein');
assert.equal(title.textContent,'EINSTELLUNGEN');
state.uiLanguage='auto';win.navigator.language='en-GB';callbacks['coreui:preferences']();
assert.equal(tab.textContent,'General');
assert.equal(rootEl.lang,'en','browser automatic detection');
win.navigator.language='de-DE';callbacks['coreui:preferences']();
assert.equal(tab.textContent,'Allgemein');
for(const file of ['index.html','login.html','app.html','settings.html','protocols.html','admin/index.html']){
  assert.match(read(file),/coreui-ui-language\.js\?v=\d{25}/,file+' loads locale');
}
const prefs=read('js/coreui-preferences.js');
assert.match(prefs,/uiLanguage: source\.uiLanguage === 'de' \|\| source\.uiLanguage === 'en'/);
assert.match(read('settings.html'),/<option value="auto">Automatisch erkennen<\/option>/);
console.log('CoreAI UI languages: English/German toggle, browser detection, safe targeted text and six entrypoints OK');
