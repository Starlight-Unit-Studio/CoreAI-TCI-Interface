'use strict';
// CoreAI 0.6.68: offline logic regression for unsaved drafts and composer Stop.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const html=fs.readFileSync(path.join(root,'app.html'),'utf8');
const source=fs.readFileSync(path.join(root,'js/console-app.js'),'utf8');
const voice=fs.readFileSync(path.join(root,'js/coreui-voice-live.js'),'utf8');
const css=fs.readFileSync(path.join(root,'css/console.css'),'utf8');
new vm.Script(source);new vm.Script(voice);
assert.match(html,/id="welcomeScreen"/);
assert.equal((html.match(/data-welcome-prompt=/g)||[]).length,4);
assert.ok(source.includes('.then(showDraftChat)'),'login starts with unsaved draft');
assert.ok(!source.slice(source.indexOf('function init()')).includes('dbCreateSession('),'login must not create a session');
assert.ok(voice.includes('composer.ensureLiveSession'),'first Live utterance creates a chat');
assert.ok(voice.includes('var warmed = ctx.sessionId'),'opening Live alone must not create a chat');
assert.match(css,/\.composer-inner\.is-working\.has-live\.is-empty > #btnSend/);
assert.match(css,/prefers-reduced-motion:reduce/);
function extract(name){
 const start=source.indexOf('  function '+name+'(');
 assert.ok(start>=0,'missing '+name);
 const end=source.indexOf('\n  }',start);
 assert.ok(end>start,'unterminated '+name);
 return source.slice(start,end+4);
}
function classes(){
 const set=new Set();
 return {toggle(k,v){if(v)set.add(k);else set.delete(k);},contains(k){return set.has(k);}};
}
(async()=>{
 const inner={classList:classes()};
 const btnSend={classList:classes(),disabled:false,attrs:{},title:'',
   closest(){return inner;},setAttribute(k,v){this.attrs[k]=v;}};
 const state={sessions:[{id:'s1'}],activeSessionId:'s1',pendingBySession:{},
   pendingCancelBySession:{},activeAgentRunsBySession:{},draftSessionPromise:null,editingMessage:null};
 const ctx={state,btnSend,activeSession:()=>state.sessions[0],
   postConsoleAction:()=>Promise.resolve({status:'running',cancel_requested:true}),
   pollCancelledRun:()=>{},clearAgentRun:()=>{},resetGenerationUi:()=>{},
   appendMessageEl:()=>{},generationActionError:()=>{}};
 vm.runInNewContext(extract('updateAgentCancelControl')+'\n'+extract('requestActiveRunCancel')+
   '\nthis.updateUI=updateAgentCancelControl;this.stop=requestActiveRunCancel;',ctx);
 ctx.updateUI();assert.equal(btnSend.classList.contains('is-stop'),false);
 assert.equal(btnSend.attrs['aria-label'],'Nachricht senden');
 state.pendingBySession.s1=12;ctx.updateUI();
 assert.equal(btnSend.classList.contains('is-stop'),true);
 assert.equal(inner.classList.contains('is-working'),true);
 ctx.stop();assert.equal(state.pendingCancelBySession.s1,true,'early stop is queued');
 assert.equal(btnSend.disabled,true);
 state.activeAgentRunsBySession.s1={id:'a'.repeat(32),cancel_requested:false};
 ctx.stop();assert.equal(state.activeAgentRunsBySession.s1.cancel_requested,true);
 let created=0,selected=0;
 const draft={sessions:[],activeSessionId:null,characterId:'character',viewEpoch:4,draftSessionPromise:null};
 const lazy={state:draft,Promise,updateActivePendingUi:()=>{},
   activeSession:()=>draft.sessions.find(s=>s.id===draft.activeSessionId)||null,
   dbCreateSession:()=>{created++;const s={id:'fresh'};draft.sessions.push(s);return Promise.resolve(s);},
   selectSession:id=>{selected++;draft.activeSessionId=id;return Promise.resolve();}};
 vm.runInNewContext(extract('ensureDraftSession')+'\nthis.ensure=ensureDraftSession;',lazy);
 assert.equal(created,0,'showing welcome is not a DB insert');
 const a=lazy.ensure(),b=lazy.ensure();
 assert.equal(created,1,'concurrent first turns share one session');
 assert.equal((await a).id,'fresh');assert.equal((await b).id,'fresh');
 assert.equal(selected,1);
 assert.equal(draft.draftSessionPromise,null);

 // Voice messages must preserve the entered text after upload in both
 // saved chats and the new, not-yet-persisted welcome channel.
 for(const [label,session,pendingId,expected] of [
   ['existing chat',{id:'saved'},'saved',true],
   ['welcome draft',null,'__draft__',true],
   ['different chat',{id:'other'},'saved',false]
 ]){
   const sent=[];
   const upload={
     uploadXhr:null,uploadCurrentFile:null,uploadQueue:[],
     pendingSendAfterUpload:{text:'Hör mal:',sessionId:pendingId},
     activeSession:()=>session,
     sendMessage:text=>sent.push(text)
   };
   vm.runInNewContext(extract('sendAfterCompletedUpload')+
     '\nthis.completeUpload=sendAfterCompletedUpload;',upload);
   upload.completeUpload();
   assert.deepEqual(sent,expected?['Hör mal:']:[],label+' after upload');
   assert.equal(upload.pendingSendAfterUpload,null,label+' pending send consumed');
 }
 console.log('CoreAI welcome draft, voice upload and Live/Send/Stop: OK');
})().catch(e=>{console.error(e);process.exit(1);});
