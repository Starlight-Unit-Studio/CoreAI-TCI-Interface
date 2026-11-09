'use strict';
// CoreAI 0.6.75: real composer effort. Run: node scripts/reasoning-effort-ui-selftest.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const base = path.resolve(__dirname, '..');
const read = f => fs.readFileSync(path.join(base, f), 'utf8');
const source = read('js/coreui-reasoning-effort.js');
new vm.Script(source);
const winHandlers = {};
const win = {addEventListener: (name, fn) => { winHandlers[name] = fn; }};
vm.runInNewContext(source, {window: win});
const html = read('app.html');
const settings = read('settings.html');
assert.match(html, /id="reasonEffortChip"/);
const footerStart = html.indexOf('<div class="composer-footer-line"');
const footerEnd = html.indexOf('</div>', footerStart);
const footer = html.slice(footerStart, footerEnd);
assert.ok(footerStart > 0 && footerEnd > footerStart, 'composer footer exists');
assert.ok(footer.indexOf('id="reasonEffortChip"') < footer.indexOf('id="coreModeChip"'),
  'Denkaufwand stands immediately left of the Core chip');
assert.match(footer, /id="reasonEffortChip"[\s\S]*id="coreModeChip"/,
  'Core remains the far-right selector');
const css = read('css/console.css');
function rule(selector) {
  const marker = selector + ' {';
  const at = css.indexOf(marker);
  assert.ok(at >= 0, marker + ' is defined');
  const end = css.indexOf('\n}', at);
  assert.ok(end > at, marker + ' closes');
  return css.slice(at, end + 2);
}
const core = rule('.core-mode-chip'), effort = rule('.reason-effort-chip');
for (const prop of ['min-height', 'gap', 'padding', 'border', 'background',
  'color', 'font-family', 'font-size', 'letter-spacing', 'clip-path']) {
  const extract = r => r.match(new RegExp('(?:^|\\n)\\s*' + prop + ':\\s*([^;]+);'));
  const c = extract(core), e = extract(effort);
  assert.ok(c && e && c[1].trim() === e[1].trim(),
    'Denkaufwand matches the native Core chip ' + prop + ' (' +
    (e && e[1]) + ' vs ' + (c && c[1]) + ')');
}
assert.match(effort, /border-radius:\s*0;/,
  'Denkaufwand has angular TCI corners, not generic rounded pills');

assert.match(html, /id="reasonEffortMenu"[^>]*role="dialog"|role="dialog"[^>]*id="reasonEffortMenu"/);
assert.match(html, /id="reasonEffortOptions"/);
assert.match(html, /id="reasonEffortIntro"/);
assert.match(html, /id="reasonEffortMenu"[^>]*aria-describedby="reasonEffortIntro"/);
const intro = 'Deine CoreAI denkt vor der Antwort nach: gründlicher, aber langsamer. Gilt ab der nächsten Nachricht, für alle deine Chats.';
assert.equal(html.split(intro).length - 1, 1, 'original Thinking explanation exists exactly once in Denkaufwand');
const coreMenu = html.slice(html.indexOf('id="coreModeMenu"'), html.indexOf('id="reasonEffortMenu"'));
assert(!coreMenu.includes('id="coreThinkToggle"'), 'Core menu has no duplicate Thinking switch');
assert(!coreMenu.includes(intro), 'Thinking help no longer belongs to Core selection');
assert(!html.includes('id="coreModeThink"'), 'Core chip no longer duplicates Thinking status');
assert.match(html, /src="\.\/js\/coreui-reasoning-effort\.js\?v=\d{25}"/);
assert.match(settings, /<div class="cfg-general-row">\s*<div class="cfg-general-copy">\s*<label for="generalReasoningLevels">Denkaufwand<\/label>/);
assert.doesNotMatch(settings, /<div class="cfg-general-row" data-expert hidden>\s*<div class="cfg-general-copy">\s*<label for="generalReasoningLevels">/);
assert.match(read('api/general_core_controls.php'), /function coreui_general_think_value\(/);
assert.match(read('api/user_settings.php'), /action'\] \?\? ''\) === 'general'/);
assert.match(read('js/coreui-core-mode.js'), /CustomEvent\('coreui:thinking'/);

class El {
  constructor(name) {
    this.name=name;this.textContent='';this.hidden=name==='menu';this.disabled=false;
    this.children=[];this.handlers={};this.attributes={};
  }
  get firstChild() {return this.children[0]||null;}
  addEventListener(name,fn){this.handlers[name]=fn;}
  setAttribute(name,value){this.attributes[name]=value;}
  getAttribute(name){return this.attributes[name];}
  appendChild(el){this.children.push(el);return el;}
  removeChild(el){this.children.splice(this.children.indexOf(el),1);return el;}
  contains(el){return this===el||this.children.includes(el);}
  focus(){}
}
function node(id,doc){return doc.getElementById(id);}
const ids={reasonEffortChip:new El('chip'),reasonEffortMenu:new El('menu'),
  reasonEffortOptions:new El('list'),reasonEffortLabel:new El('label'),
  reasonEffortNote:new El('note'),reasonEffortStatus:new El('status')};
const docHandlers={};
const doc={getElementById:id=>ids[id]||null,createElement:tag=>new El(tag),
  addEventListener:(name,fn)=>{docHandlers[name]=fn}};
const calls=[];let selected='high',capabilities=['low','high'],think=false,seq=0;
const mode={ready:Promise.resolve(),state:()=>({loaded:true,thinking:think}),
  setThinking:on=>{think=on;return Promise.resolve(think);},close:()=>{}};
const fetchFn=async (url,init)=>{
  ++seq;
  if(init.method==='POST'){
    const body=JSON.parse(init.body);calls.push(body);
    if(body.action==='general')selected=body.settings.thinking_level;
    return {ok:true,json:async()=>({ok:true})};
  }
  return {ok:true,json:async()=>({ok:true,available:true,model:'ember-core:e4b',
    csrf_token:'abc',general:{thinking_level:selected},
    reasoning:{levels:capabilities}})};
};
async function run(){
  const control=win.CoreUIReasoningEffort.create(doc,fetchFn,mode);
  await control.reload();
  assert.equal(ids.reasonEffortLabel.textContent,'Aus');
  assert.deepEqual(ids.reasonEffortOptions.children.map(x=>x.getAttribute('data-level')),['off','','low','high']);
  assert.equal(ids.reasonEffortOptions.children.some(x=>x.getAttribute('data-level')==='medium'),false,
    'unknown effort must not appear');
  await control.choose('low');
  assert.equal(think,true);
  assert.equal(selected,'low');
  assert.equal(ids.reasonEffortLabel.textContent,'Gering');
  assert.ok(calls.some(x=>x.action==='general'&&x.settings.thinking_level==='low'));
  await control.choose('off');
  assert.equal(ids.reasonEffortLabel.textContent,'Aus');
  assert.equal(selected,'low','turning thinking off must not erase effort');
  capabilities=[];
  await control.reload();
  assert.deepEqual(ids.reasonEffortOptions.children.map(x=>x.getAttribute('data-level')),['off','on']);
  const before=calls.length;
  await control.choose('on');
  assert.equal(ids.reasonEffortLabel.textContent,'An');
  assert.equal(calls.length,before,'binary Thinking reuses original controller, no fake new API');
  control.open();
  assert.equal(ids.reasonEffortMenu.hidden,false);
  docHandlers.keydown({key:'Escape',preventDefault:()=>{}});
  assert.equal(ids.reasonEffortMenu.hidden,true);
  assert.ok(seq>=5);
  console.log('CoreAI Denkaufwand: visible General and composer, model-filtered levels, persistent save, binary fallback, Escape OK');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
