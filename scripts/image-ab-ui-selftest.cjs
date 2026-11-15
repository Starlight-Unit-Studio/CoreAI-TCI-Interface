'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ids = ['imageAbCard','imageAbStart','imageAbCancel','imageAbReload',
  'imageAbStatus','imageAbVerdict','imageAbQwenState','imageAbPonpokeState',
  'imageAbQwen','imageAbPonpoke'];
const nodes = {};
for (const id of ids) {
  nodes[id] = {
    hidden:id === 'imageAbCard' || id === 'imageAbCancel',
    disabled:false, textContent:'', handlers:{},
    classes:new Set(),
    classList:{ toggle(name,on){if(on)this.owner.classes.add(name);else this.owner.classes.delete(name);} },
    addEventListener(name,fn){this.handlers[name]=fn;},
  };
  nodes[id].classList.owner = nodes[id];
}
const listeners = {};
const timers = [];
const requests = [];
const states = [
  {state:'idle',active:'',comparison:'pending',csrf_token:'csrf-123',
   cases:{qwen:{state:'not_started'},ponpoke:{state:'not_started'}}},
  {state:'running',active:'qwen',comparison:'pending',
   cases:{qwen:{state:'running',phase:'sampling',step:2,steps:4,encoder:'original'},
     ponpoke:{state:'not_started'}}},
  {state:'running',active:'ponpoke',comparison:'pending',
   cases:{qwen:{state:'success',seconds:62.3,peak_rss_mb:9600,png_valid:true},
     ponpoke:{state:'running',phase:'load',encoder:'ponpoke-uncensored-q8_0'}}},
  {state:'completed',active:'',comparison:'ponpoke_path_suspected',
   cases:{qwen:{state:'success',seconds:62.3,peak_rss_mb:9600,png_valid:true},
     ponpoke:{state:'failed',error_code:'image_worker_exit',exit_code:137,
       peak_rss_mb:15100,seconds:96.7,phase:'sampling'}}},
];
const sandbox = {
  URL, console,
  window:{
    EMBER_COREUI:{apiUrl:'https://coreai.example/api/'},
    CoreAISettingsTabs:{instance:{current:()=> 'admin-betrieb'}},
  },
  document:{
    getElementById:id=>nodes[id]||null,
    addEventListener(name,fn){listeners[name]=fn;},
  },
  setTimeout(fn){timers.push(fn);return timers.length;},
  clearTimeout(){},
  fetch:async(url,options)=>{
    requests.push({url,options});
    const action=new URL(url).searchParams.get('action');
    const expected=['image_ab_status','image_ab_start','image_ab_poll','image_ab_poll'][requests.length-1];
    assert.equal(action,expected,'status, start, then serial polls');
    if (options.method === 'POST') {
      assert.equal(options.headers['X-CoreUI-CSRF'],'csrf-123','CSRF included on every write');
      assert.equal(options.body,'{}','only fixed server-side params, no custom prompt');
    }
    const item=states[requests.length-1];
    return {ok:true,status:200,json:async()=>({ok:true,...item})};
  },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../js/coreui-image-ab.js'),'utf8'),sandbox);
const flush=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  await flush();
  assert.equal(nodes.imageAbCard.hidden,false,'owner test panel shown after authenticated status');
  assert.equal(nodes.imageAbStart.disabled,false,'owner can run comparison');
  nodes.imageAbStart.handlers.click();
  await flush();
  assert.equal(nodes.imageAbCancel.hidden,false,'abort button shown while running');
  assert.match(nodes.imageAbQwenState.textContent,/Schritt 2\/4/,'real worker progress displayed');
  assert.ok(timers.length,'serial poll scheduled');
  timers.pop()();
  await flush();
  assert.match(nodes.imageAbPonpokeState.textContent,/Bildberechnung läuft/,'second step tracked');
  timers.pop()();
  await flush();
  assert.match(nodes.imageAbVerdict.textContent,/Qwen3 funktioniert, Ponpoke scheitert/,'meaningful interpretation');
  assert.match(nodes.imageAbPonpokeState.textContent,/Exitcode 137/,'real worker error visible');
  assert.equal(nodes.imageAbStart.disabled,false,'rerun possible after complete');
  assert.equal(nodes.imageAbCancel.hidden,true,'cancel control hidden after complete');
  assert.equal(requests.length,4,'no duplicate background jobs');
  console.log('CoreAI image A/B UI selftest: OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
