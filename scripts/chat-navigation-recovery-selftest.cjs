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
  pendingBySession:{},pendingCancelBySession:{},activeAgentRunsBySession:{},liveWorkEventsBySession:{},
  thinkingPanelEl:null
};
let updates = 0, added = 0, outbound = 0;
const streamStarts = [], fastPolls = [], generationPolls = [], browseStreams = [];
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
  openStreamForReply:(...args)=>streamStarts.push(args),
  openBrowseWindow:(...args)=>browseStreams.push(args),
  startFastPoll:(...args)=>fastPolls.push(args),
  startGenerationStatusPoll:(...args)=>generationPolls.push(args),
};
vm.runInNewContext(snippet+'\nthis.recover = recoverActiveRun; this.reconnect = reconnectRecoveredRun;', sandbox);
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

// 0.5.68: a poll snapshot taken just before the run finished must not
// resurrect it after the reply arrived (the chat re-rendered and flickered).
delete state.pendingBySession[sessionId];
state.finishedRunsBySession = {[sessionId]:{[validRun.id]:true}};
assert.equal(recover(ses,{active_run:validRun}),false,'A run this tab saw finish stays finished');
state.finishedRunsBySession = {};
state.messageCacheBySession[sessionId].push({id:42,is_ember:true,reply_to_id:41,
  agent_run:{id:validRun.id,key:'base'},generation:null});
assert.equal(recover(ses,{active_run:validRun}),false,'A run with a saved reply is not active');
state.messageCacheBySession[sessionId][1].agent_run = null;
assert.equal(recover(ses,{active_run:{...validRun,id:'b'.repeat(32)}}),false,
  'The base run of an answered turn is a stale snapshot');
const regenerate = {...validRun,id:'c'.repeat(32),run_key:'d'.repeat(32)};
assert.equal(recover(ses,{active_run:regenerate}),true,'A real regeneration of an answered turn still resumes');
assert.equal(state.pendingBySession[sessionId],41);
// A newly posted user message is already queued on the server. If the client
// navigated away before EventSource started, restoration must CLAIM that exact
// saved run, not wait forever for a response from an unstarted worker.
delete state.pendingBySession[sessionId];
state.messageCacheBySession[sessionId] = [{id:41,message:'Testfrage',character_id:'user'}];
const queued = {...validRun,id:'d'.repeat(32),status:'queued'};
assert.equal(recover(ses,{active_run:queued,active_work_events:[ev]}),true);
sandbox.reconnect(ses,queued);
assert.equal(streamStarts.length,1,'queued server run starts exactly once');
assert.equal(streamStarts[0][1],41,'restored stream claims original turn');
assert.equal(streamStarts[0][3],'user','restored stream keeps original character');
assert.equal(recover(ses,{active_run:queued}),false,'already restored pending run is not restarted');
assert.equal(streamStarts.length,1,'repeated history response does not duplicate inference');
delete state.pendingBySession[sessionId];
const running = {...validRun,id:'e'.repeat(32),status:'running'};
assert.equal(recover(ses,{active_run:running}),true);
sandbox.reconnect(ses,running);
assert.equal(streamStarts.length,1,'running server work must never start a second stream');
assert.equal(fastPolls.length,1,'running server work is observed via status polling');
delete state.pendingBySession[sessionId];
const queuedVariant={...queued,id:'f'.repeat(32),run_key:'c'.repeat(32)};
assert.equal(recover(ses,{active_run:queuedVariant}),true);
sandbox.reconnect(ses,queuedVariant);
assert.equal(streamStarts.length,2,'queued regeneration claims its own request');
assert.equal(streamStarts[1][2].id,queuedVariant.run_key,'the owned generation request ID is retained');
delete state.pendingBySession[sessionId];
sandbox.reconnect(ses,{...queuedVariant,status:'running'});
assert.equal(generationPolls.length,1,'running regeneration polls its request, not an old answer');

delete state.pendingBySession[sessionId];
const delegated = {...validRun,status:'waiting_tool',browse_job_id:55};
assert.equal(recover(ses,{active_run:delegated}),true);
sandbox.reconnect(ses,delegated);
assert.equal(streamStarts.length,2,'returning to a browser job must not start inference');
assert.deepEqual(browseStreams,[[55,sessionId,false,41,'base']],'automatic research restores the text-only view');
sandbox.reconnect(ses,{...delegated,browse_show_live:true});
assert.deepEqual(browseStreams[1],[55,sessionId,true,41,'base'],'explicit research restores the same live browser job');
sandbox.reconnect(ses,{...delegated,id:'b'.repeat(32),browse_job_id:56});
sandbox.reconnect(ses,{...delegated,browse_job_id:0});
assert.equal(browseStreams.length,2,'skip stale runs and missing browser IDs');

const chatSource = fs.readFileSync(path.join(__dirname,'../api/chat.php'),'utf8');
const enqueue = chatSource.indexOf("coreui_agent_run_ensure($pdo, (int)$uid, (string)$consoleSessionId, (int)$id, 'base')");
const acknowledge = chatSource.indexOf("$okPayload['ember_pending'] = true;", enqueue);
assert(enqueue > 0 && acknowledge > enqueue,
  'the console POST must store the queued owner-scoped run before acknowledging async work');

console.log('CoreUI active-run recovery: queued job claimed once, running job observed, action scoped, events retained');
