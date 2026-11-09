#!/usr/bin/env node
'use strict';
/* Real local preference behavior, backcompat, accessibility and content.
   Run: node scripts/appearance-settings-selftest.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const dir = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(dir, name), 'utf8');
const src = read('js/coreui-preferences.js');
const html = read('settings.html');
const css = read('css/coreui-theme.css');
const settings = read('js/settings.js');
const typing = read('js/coreui-smart-punctuation.js');

for (const id of [
  'appearanceMotif','appearanceAccent','appearanceAccentColor','appearanceBackground',
  'appearanceForeground','appearanceFont','appearanceUiSize','appearanceContentSize',
  'appearanceCodeSize','appearanceContrast','appearanceContrastValue','appearanceMotion',
  'appearanceSeparate','appearanceSmartPunctuation','appearanceFullWidth',
  'appearanceDiffMarkers','appearanceExport','appearanceCopy','appearanceStatus']) {
  assert(html.includes('id="' + id + '"'), 'missing real control: ' + id);
}
assert(html.includes('<details class="appearance-advanced wide"'), 'ordinary expanded controls');
assert(!/class="appearance-advanced wide"[^>]*data-expert/.test(html),
  'reference-image controls must never require Expert');
for (const id of ['prefCompactFeed','prefThinkingExpanded','prefShowLiveBrowser',
  'prefLiveFrameMotion','prefShowTelemetry','resetPreferences']) {
  assert(html.includes('id="' + id + '"'), 'previous real display control retained: ' + id);
}
assert(settings.includes('var value = preferences ? preferences.load() : {}'),
  'legacy settings saves must preserve the new appearance object');
assert(!html.includes('id="prefReduceMotion"'),
  'legacy binary motion checkbox replaced with 3-state control');
assert(css.includes('html.pref-full-width .cfg-shell'), 'full-width preference affects layout');
assert(css.includes('html[data-coreui-diff="symbols"] .md-diff-line'),
  'diff markers can be displayed as plain +/- lines');
assert(read('js/coreui-markdown.js').includes("if (lang === 'diff')"),
  'fenced diff code is actually classified');
assert(html.includes('coreui-appearance-settings.js?v='),
  'appearance controller is part of the user settings page');
assert(read('app.html').includes('coreui-smart-punctuation.js?v='),
  'optional typed punctuation behavior is available in chat');
new vm.Script(read('js/coreui-appearance-settings.js'));
new vm.Script(typing);

function fixture(stored = {}, dark = false, reduced = false) {
  const values = {...stored};
  const attributes = {}, classes = {}, styles = {}, listeners = {};
  const system = {dark,reduced};
  const medias = {};
  const root = {style: {setProperty(k,v){styles[k]=v;},
    removeProperty(k){delete styles[k];}},
    setAttribute(k,v){attributes[k]=v;},
    classList:{toggle(k,v){classes[k]=!!v;}}};
  const doc = {documentElement:root,querySelector(){return {setAttribute() {}};}};
  const win = {localStorage:{
    getItem:k=>values[k]||null,setItem:(k,v)=>{values[k]=v;},removeItem:k=>{delete values[k];}},
    navigator:{language:'de-DE'},
    matchMedia(query) {
      if (!medias[query]) medias[query] = {
        get matches(){return query.includes('color-scheme') ? system.dark : system.reduced;},
        addEventListener(event,fn){if(event==='change')(listeners[query] ||= []).push(fn);}
      };
      return medias[query];
    },
    addEventListener(){},dispatchEvent(){}};
  vm.runInNewContext(src,{window:win,document:doc,CustomEvent:function(){}});
  return {prefs:win.CoreUIPreferences,attributes,classes,styles,values,system,listeners,
    change(dark,reduced) {
      system.dark=dark;system.reduced=reduced;
      Object.values(listeners).forEach(a=>a.forEach(fn=>fn()));
    }};
}
let x = fixture();
assert.equal(x.prefs.load().theme,'system');
assert.equal(x.prefs.load().appearance.uiSize,14);
assert.equal(x.attributes['data-coreui-theme'],'light');
assert.equal(x.styles['--cx'],'#5b92ff');
assert.equal(x.classes['pref-reduced-motion'],false);
let p=x.prefs.load();
p.appearance.accent='green';
p.appearance.uiSize=20;
p.appearance.contentSize=18;
p.appearance.codeSize=11;
p.appearance.contrast=90;
p.appearance.motion='on';
p.appearance.fullWidth=true;
p.appearance.diffMarkers='symbols';
x.prefs.save(p);
assert.equal(x.styles['--cx'],'#168663');
assert.equal(x.styles['--appearance-ui-scale'],String(20/14));
assert.equal(x.styles['--appearance-content-scale'],String(18/14));
assert.equal(x.styles['--appearance-code-scale'],String(11/12));
assert.equal(x.attributes['data-coreui-diff'],'symbols');
assert.equal(x.classes['pref-reduced-motion'],true);
assert.equal(x.classes['pref-full-width'],true);
assert.equal(x.prefs.load().appearance.accent,'green');
p=x.prefs.load();
p.theme='dark';
x.prefs.save(p);
assert.equal(x.attributes['data-coreui-theme'],'dark');
assert.equal(x.styles['--cx'],'#72e1b0');
p=x.prefs.load();
p.appearance.separate=true;
p.appearance.dark.motif='terminal';
p.appearance.dark.accent='violet';
p.appearance.dark.background='#112233';
p.appearance.dark.foreground='#ffffff';
x.prefs.save(p);
assert.equal(x.attributes['data-coreui-motif'],'terminal');
assert.equal(x.styles['--cx'],'#c0adff');
assert.equal(x.styles['--bg'],'#112233');
assert.equal(x.styles['--txt'],'#ffffff');
assert.equal(x.attributes['data-coreui-background-custom'],'true');
p=x.prefs.load();
p.theme='light';
x.prefs.save(p);
assert.equal(x.attributes['data-coreui-motif'],'holo','separate Light design unaffected');
assert.equal(x.styles['--cx'],'#5b92ff');
const invalid=x.prefs.load();
invalid.appearance.light.background='red;url(evil)';
invalid.appearance.uiSize=10000;
invalid.appearance.codeSize=-10;
invalid.appearance.motif='mystery';
x.prefs.save(invalid);
assert.equal(x.prefs.load().appearance.light.background,'#f0f4fa','invalid hex rejected');
assert.equal(x.prefs.load().appearance.uiSize,24,'oversized UI clamped');
assert.equal(x.prefs.load().appearance.codeSize,10,'tiny code clamped');
assert.equal(x.prefs.load().appearance.motif,'holo','unknown motif ignored');
let legacy=fixture({'emberCoreUI.preferences.v1': JSON.stringify({
  reduceMotion:true,showTelemetry:false,theme:'dark',sendKey:'mod-enter'})});
assert.equal(legacy.prefs.load().appearance.motion,'on','legacy reduceMotion migrated');
assert.equal(legacy.prefs.load().sendKey,'mod-enter');
assert.equal(legacy.prefs.load().showTelemetry,false);
let auto=fixture({},false,true);
assert.equal(auto.classes['pref-reduced-motion'],true,'system preference honors OS reduced motion');
auto.change(true,false);
assert.equal(auto.attributes['data-coreui-theme'],'dark','OS color scheme refresh');
assert.equal(auto.classes['pref-reduced-motion'],false,'OS motion refresh');
assert.equal(auto.prefs.load().appearance.motion,'system');
console.log('CoreAI Darstellung: modes, separate palettes, real token effects, fonts, motion, width, diff, legacy data, and validation OK.');
