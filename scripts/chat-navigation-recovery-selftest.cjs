'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../js/console-app.js'),'utf8');
const begin = source.indexOf('  function recoverActiveRun(ses, data) {');
const end = source.indexOf('  function renderHistory(ses) {',begin);
assert(begin > 0 && end > begin, 'Expected recovery function missing from console-app.js');
const snippet = source.slice(begin,end);
const sessionId = 'cs_112233445566778899001122';
const validRun = {
  id:'a'.repeat(32),turn_id:41,run_key:'base',status:'running',cancel_requested:false
};
const state = {
  messageCacheBySession:{[sessionId]:[{id:41,message:'Testfrage',character_id:'user'}]},
  pendingBySession:{},activeAgentRunsBySession:{},liveWorkEventsBySession:{},
  thinkingPanelEl:null
};
let updates = 0, added = 0, outbound = 0;
const sandbox = {
  state,
  window:{CoreUIWorkEvents:{add:()=>{added++;}}},
  workEventMerge: (left,right) => {
    const all = [...(left||[]),...(right||[])];
    return all.filter((event,index) => all.findIndex(e=>e.id===event.id)===index);
  },
  isEmberMsg: m=>m.is_ember===true||m.character_id==='ember',
  isActiveSession: id=>id===sessionId,
  updateActivePendingUi:()=>{updates++},
  fetch:()=>{outbound++;throw Error('Recovery must never issue a request')},
};
vm.runInNewContext(snippet+'\nthis.recover = recoverActiveRun;', sandbox);
const recover=sandbox.recover, ses={id:sessionId};
const ev={id:1,kind:'run',state:'running',title:'Auftrag gestartet'};
assert.equal(recover(ses,{active_run:validRun,active_work_events:[ev]}),true);
assert.equal(state.pendingBySession[sessionId],41);
assert.equal(state.activeAgentRunsBySession[sessionId].id,validRun.id);
assert.equal(state.liveWorkEventsBySession[sessionId].events.length,1);
assert.equal(recover(ses,{active_run:validRun,active_work_events:[ev]}),false,'Repeat must not reissue work');
assert.equal(state.liveWorkEventsBySession[sessionId].events.length,1,'No repeated events');
assert.equal(updates,2);
assert.equal(added,0);
assert.equal(outbound,0);
delete state.pendingBySession[sessionId];
assert.equal(recover(ses,{active_run:{...validRun,status:'completed'}}),false,'Completed runs must not appear active');
assert.equal(recover(ses,{active_run:{...validRun,id:'not-a-run'}}),false,'Invalid run id');
assert.equal(recover(ses,{active_run:{...validRun,turn_id:42}}),false,'Unowned/absent user turn');
state.pendingBySession[sessionId]=56;
assert.equal(recover(ses,{active_run:validRun}),false,'Existing other turn must not be replaced');
console.log('CoreUI active-run recovery: existing run restored, events merged, invalid state ignored, no duplicate inference');
