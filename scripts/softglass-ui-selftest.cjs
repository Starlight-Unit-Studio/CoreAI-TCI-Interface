'use strict';
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const root=path.join(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const prefsCode=read('js/coreui-preferences.js');
const settingsCode=read('js/settings.js');
const app=read('js/console-app.js');
const styles=read('css/coreui-softglass.css');
const sidebar=read('css/coreui-sidebar-refresh.css');
const settings=read('settings.html');
const paths=['index.html','login.html','app.html','settings.html','protocols.html','admin/index.html'];

new Function(prefsCode);
new Function(settingsCode);
new Function(app);

assert(settings.includes('name="coreuiSkin" value="coreai"') &&
  settings.includes('name="coreuiSkin" value="softglass"') &&
  settings.includes('Oberflächenstil'), 'both skins must be selectable in Anzeige');
assert(settingsCode.includes("input[name=\"coreuiSkin\"]") &&
  settingsCode.includes("uiSkin: option.value") &&
  settingsCode.includes('value.uiSkin = selectedSkin ? selectedSkin.value'), 
  'skin must save independently of display palette');
for(const name of paths) {
  const html=read(name);
  const cssPath=name.startsWith('admin/')?'../css/':'./css/';
  assert(html.includes(cssPath+'coreui-softglass.css?v='),
    'missing optional skin stylesheet on '+name);
}
assert(styles.includes('html[data-coreui-skin="softglass"]') &&
  styles.includes('data-coreui-theme="dark"') &&
  styles.includes('backdrop-filter:blur') &&
  styles.includes('border-radius:var(--sg-radius-large)') &&
  styles.includes('body.project-home-active') &&
  styles.includes('.project-modal') &&
  styles.includes('.drawer') &&
  styles.includes('.msg-bubble'),
  'SoftGlass must cover chat, sidebar, projects and dialogs, day and night');
assert(app.includes('archive_first: archiveFirst === true') &&
  app.includes('dbDeleteSession(s.id, !s.archived)') &&
  app.includes("permanentlyDelete.textContent = s.archived ? 'ENDGÜLTIG LÖSCHEN' : 'LÖSCHEN'") &&
  app.includes('actions.appendChild(permanentlyDelete)') &&
  app.includes('if (isActiveSession(s.id)) showDraftChat()'),
  'active and archived chats must share the safe deletion path');
assert(app.includes("proof !== 'LÖSCHEN' && proof !== 'LOESCHEN'") &&
  app.includes('confirmation:') &&
  sidebar.includes('.session-item.actions-open .session-action.permanent-danger') &&
  sidebar.includes('flex:1 1 100%') &&
  sidebar.includes('min-height:44px'),
  'deletion needs explicit proof and a full-width phone-friendly destructive action');

// Behavioral test: the new skin preference is independent of the existing
// palette and does not change the default CoreAI selection.
const saved=new Map(), attrs=new Map();
const classes=new Map(), css=new Map();
const doc={
  documentElement:{
    setAttribute:(k,v)=>attrs.set(k,v),
    classList:{toggle:(k,v)=>classes.set(k,!!v)},
    style:{setProperty:(k,v)=>css.set(k,v)}
  },
  querySelector:()=>null
};
const window={
  navigator:{language:'de-DE'},
  localStorage:{
    getItem:k=>saved.has(k)?saved.get(k):null,
    setItem:(k,v)=>saved.set(k,v),
    removeItem:k=>saved.delete(k)
  },
  matchMedia:()=>({matches:false,addEventListener(){}}),
  dispatchEvent(){},
  addEventListener(){}
};
vm.runInNewContext(prefsCode,{window,document:doc,CustomEvent:function(){}},
  {filename:'coreui-preferences.js'});
const prefs=window.CoreUIPreferences;
assert(prefs,'preferences controller must initialize');
assert.equal(attrs.get('data-coreui-skin'),'coreai');
const glass=prefs.save(Object.assign({},prefs.load(),{uiSkin:'softglass',theme:'dark'}));
assert.equal(glass.uiSkin,'softglass');
assert.equal(attrs.get('data-coreui-skin'),'softglass');
assert.equal(attrs.get('data-coreui-theme'),'dark');
const lighter=prefs.save(Object.assign({},glass,{theme:'light'}));
assert.equal(lighter.uiSkin,'softglass');
assert.equal(attrs.get('data-coreui-theme'),'light');
assert.equal(prefs.load().uiSkin,'softglass');
const restored=prefs.reset();
assert.equal(restored.uiSkin,'coreai');
assert.equal(attrs.get('data-coreui-skin'),'coreai');
const bad=prefs.save(Object.assign({},prefs.load(),{uiSkin:'some-other-skin'}));
assert.equal(bad.uiSkin,'coreai','unknown styles must not inject into selector');
console.log('PASS: SoftGlass/UI skin persistence, light/dark, six pages and secure chat deletion');
