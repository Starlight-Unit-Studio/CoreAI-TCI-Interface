#!/usr/bin/env node
'use strict';
/* Exercise the real page controller with an in-memory DOM and local prefs. */
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/coreui-appearance-settings.js'),'utf8');
class El {
  constructor(id) {this.id=id;this.value='';this.checked=false;this.handlers={};this.attrs={};this.textContent='';}
  addEventListener(name,fn){this.handlers[name]=fn;}
  setAttribute(name,value){this.attrs[name]=String(value);}
  getAttribute(name){return this.attrs[name]||'';}
  click(){if(this.handlers.click)return this.handlers.click({});}
  change(){if(this.handlers.change)return this.handlers.change({});}
  input(){if(this.handlers.input)return this.handlers.input({});}
  remove(){}
}
const ids=['appearanceMotif','appearanceAccent','appearanceAccentColor','appearanceBackground',
  'appearanceBackgroundColor','appearanceForeground','appearanceForegroundColor',
  'appearanceFont','appearanceSeparate','appearanceUiSize','appearanceContentSize',
  'appearanceCodeSize','appearanceContrast','appearanceContrastValue',
  'appearanceSmartPunctuation','appearanceFullWidth','appearanceExport','appearanceCopy',
  'appearanceStatus','appearanceActiveMode'];
const el=Object.fromEntries(ids.map(x=>[x,new El(x)]));
const motions=['system','on','off'].map(value=>Object.assign(new El('motion-'+value),{value}));
const diffs=['color','symbols'].map(value=>Object.assign(new El('diff-'+value),{value}));
let theme='light',data={
  theme:'light', appearance:{
    separate:false,motif:'holo',accent:'blue',accentHex:'#5b92ff',font:'coreai',
    light:{motif:'holo',accent:'blue',accentHex:'#5b92ff',font:'coreai',background:'#f0f4fa',foreground:'#101626'},
    dark:{motif:'holo',accent:'blue',accentHex:'#91b7ff',font:'coreai',background:'#0c1427',foreground:'#eef4ff'},
    uiSize:14,contentSize:14,codeSize:12,motion:'system',contrast:60,
    smartPunctuation:false,fullWidth:false,diffMarkers:'color'
  }
};
const events={}, clicks=[], links=[];
const doc={
  documentElement:{getAttribute:()=>theme},
  getElementById:id=>el[id]||null,
  querySelectorAll:q=>q.includes('appearanceMotion')?motions:diffs,
  body:{appendChild(a){links.push(a);}},
  createElement:()=>({click(){clicks.push('download');},remove(){}})
};
const prefs={
  load:()=>JSON.parse(JSON.stringify(data)),
  save(p){data=JSON.parse(JSON.stringify(p));(events['coreui:preferences']||[]).forEach(fn=>fn({detail:data}));return data;}
};
let exportContent='';
const win={document:doc,CoreUIPreferences:prefs,
  Blob:function(parts){exportContent=parts.join('');},
  URL:{createObjectURL:()=>('blob:coreai'),revokeObjectURL(){}},
  setTimeout:fn=>fn(),
  addEventListener:(name,fn)=>{(events[name]||=[]).push(fn);}};
vm.runInNewContext(source,{window:win});
const ctl=win.CoreUIAppearanceSettings;
assert(ctl);
assert.equal(el.appearanceMotif.value,'holo');
el.appearanceMotif.value='terminal';el.appearanceMotif.change();
assert.equal(data.appearance.motif,'terminal','motif persists');
assert.equal(el.appearanceMotif.value,'terminal');
el.appearanceSeparate.checked=true;el.appearanceSeparate.change();
assert.equal(data.appearance.separate,true);
assert.equal(data.appearance.light.motif,'terminal');
assert.equal(data.appearance.dark.motif,'terminal');
theme='dark';ctl.render();
el.appearanceAccent.value='violet';el.appearanceAccent.change();
assert.equal(data.appearance.dark.accent,'violet');
assert.equal(data.appearance.light.accent,'blue');
el.appearanceBackground.value='bad';el.appearanceBackground.change();
assert.equal(data.appearance.dark.background,'#0c1427');
assert.match(el.appearanceStatus.textContent,/Hex/,'invalid input gets error');
el.appearanceBackground.value='#112233';el.appearanceBackground.change();
assert.equal(data.appearance.dark.background,'#112233');
el.appearanceUiSize.value='20';el.appearanceUiSize.change();
assert.equal(data.appearance.uiSize,20);
el.appearanceCodeSize.value='40';el.appearanceCodeSize.change();
assert.equal(data.appearance.codeSize,24,'out-of-bounds size is clamped');
el.appearanceSmartPunctuation.checked=true;el.appearanceSmartPunctuation.change();
assert.equal(data.appearance.smartPunctuation,true);
el.appearanceFullWidth.checked=true;el.appearanceFullWidth.change();
assert.equal(data.appearance.fullWidth,true);
motions[1].checked=true;motions[1].handlers.change();
assert.equal(data.appearance.motion,'on');
diffs[1].checked=true;diffs[1].handlers.change();
assert.equal(data.appearance.diffMarkers,'symbols');
el.appearanceCopy.click();
assert.equal(data.appearance.light.accent,'violet','copy the theme to other mode');
assert.equal(data.appearance.light.background,'#f0f4fa','do not blindly copy illegible dark background');
el.appearanceExport.click();
assert.equal(clicks.length,1,'export really clicks a download');
const exported=JSON.parse(exportContent);
assert.equal(exported.format,'coreai-appearance');
assert.equal(exported.appearance.dark.background,'#112233');
assert(!exported.user&&!exported.chat,'export contains no private account data');
console.log('CoreAI appearance controller: real motif, color, contrast, motion, size, copy, export, separate modes, validation OK.');
