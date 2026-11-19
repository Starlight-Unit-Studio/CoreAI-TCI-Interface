'use strict';
// Regression for the 0.5.89 history flicker after a malformed WEB work card.
// Exercises the production renderHistory function with a minimal DOM double.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../js/console-app.js'), 'utf8');
const start = source.indexOf('  function renderHistory(ses) {');
const end = source.indexOf('\n  function appendOlderButton(ses) {', start);
assert(start >= 0 && end > start, 'actual renderHistory source must be testable');
const renderSource = source.slice(start, end);

function node(name) {
  return {
    name, childNodes: [],
    appendChild(child) { this.childNodes.push(child); return child; },
    replaceChildren(...items) { this.childNodes = items; }
  };
}
function fixture(shouldFail, pending) {
  const visible = node('visible-history');
  const original = node('old-confirmed-message');
  visible.appendChild(original);
  const oldTyping = node('old-typing');
  const oldThinking = node('old-thinking');
  const oldStop = node('old-stop');
  const state = {
    typingEl: oldTyping, thinkingPanelEl: oldThinking, agentStopEl: oldStop,
    messageCacheBySession: { demo: [{id: 1, message: 'saved reply'}] },
    pendingBySession: pending ? {demo: 1} : {}
  };
  const ctx = {
    messagesInner: visible, state,
    document: { createElement: () => node('detached-history') },
    isActiveSession: id => id === 'demo',
    appendOlderButton() {},
    renderConversationRows() {
      if (shouldFail) throw new Error('simulated_web_research_render_error');
      ctx.messagesInner.appendChild(node('new-confirmed-message'));
    },
    appendMessageEl() {},
    // 0.7.74 (P09 C): the branch line is placed in the draft, before the commit.
    placeBranchLine() { ctx.branchLines.push(ctx.messagesInner.name); },
    branchLines: [],
    showTypingIndicator() {
      const typed = node('pending-response');
      ctx.messagesInner.appendChild(typed);
      state.typingEl = typed;
    },
    setStatus() {},
    renderQueuedTurns() {},
    chatFollow: { refresh() { ctx.refreshes++; } },
    refreshes: 0
  };
  vm.runInNewContext(renderSource, ctx, { filename: 'renderHistory:production' });
  return {visible,original,oldTyping,oldThinking,oldStop,state,ctx};
}

const bad = fixture(true,false);
assert.throws(() => bad.ctx.renderHistory({id:'demo'}), /simulated_web_research_render_error/);
assert.deepEqual(bad.visible.childNodes, [bad.original],
  'render errors must preserve every previously displayed message');
assert.equal(bad.ctx.messagesInner,bad.visible,'the active history host must be restored');
assert.equal(bad.state.typingEl,bad.oldTyping,'pending state must survive a render error');
assert.equal(bad.state.thinkingPanelEl,bad.oldThinking,'work state must survive a render error');
assert.equal(bad.state.agentStopEl,bad.oldStop,'stop control must survive a render error');
assert.equal(bad.ctx.refreshes,0,'failed drafts must never replace the visible DOM');

const good = fixture(false,false);
good.ctx.renderHistory({id:'demo'});
assert.equal(good.visible.childNodes.length,1);
assert.equal(good.visible.childNodes[0].name,'new-confirmed-message');
assert.equal(good.ctx.refreshes,1,'successful rendering commits exactly once');
assert.deepEqual(good.ctx.branchLines,['detached-history'],'the branch line is placed once, in the draft');
assert.deepEqual(bad.ctx.branchLines,[],'a failed render places no branch line');

const pending = fixture(false,true);
pending.ctx.renderHistory({id:'demo'});
assert.deepEqual(pending.visible.childNodes.map(item=>item.name),
  ['new-confirmed-message','pending-response']);
assert.equal(pending.state.typingEl,pending.visible.childNodes[1]);

assert(source.includes("problem.kind = 'render'"));
assert(source.includes('if (!displayError && !prepend) scheduleHistoryRetry'));
// Normal stream completion should not destroy existing full-size uploaded
// images, their scroll anchors or already expanded work panels.
const partialStart = source.indexOf('  function replaceTypingWithSavedReply(sessionId, responseId) {');
const partialEnd = source.indexOf('  function finalizeStream(',partialStart);
assert(partialStart >= 0 && partialEnd > partialStart, 'incremental saved-reply renderer must exist');
const preservedImage = {name:'uploaded-image',src:'image-from-owner'};
const typingRow = {name:'live-work'};
const host = {
  childNodes:[preservedImage,typingRow],
  replaceChild(fresh,prior) {
    const index = this.childNodes.indexOf(prior);
    assert(index >= 0,'only the live row may be replaced');
    this.childNodes[index] = fresh;
    prior.parentNode = null;
    fresh.parentNode = this;
  }
};
typingRow.parentNode = host;
const finalState = {
  typingEl:typingRow,thinkingPanelEl:{expanded:true},agentStopEl:{},
  messageCacheBySession:{demo:[{id:99,is_ember:true,message:'Saved answer'}]}
};
let redraws=0, finalRefresh=0;
const finalContext = {
  messagesInner:host,state:finalState,
  isActiveSession:id=>id==='demo',
  isEmberMsg:record=>record.is_ember===true,
  document:{createElement:()=>({
    childNodes:[],appendChild(child){this.childNodes.push(child);this.firstChild=this.childNodes[0];}
  })},
  renderMessageRecord(record) {
    finalContext.messagesInner.appendChild({name:'confirmed-reply',id:record.id});
  },
  renderHistory(){redraws++;},
  chatFollow:{refresh(){finalRefresh++;}},
  console
};
vm.runInNewContext(source.slice(partialStart,partialEnd)+'\nthis.replaceOne = replaceTypingWithSavedReply;',finalContext);
assert.equal(finalContext.replaceOne('demo',99),true,'replace one saved reply');
assert.equal(host.childNodes[0],preservedImage,'existing uploaded image must retain its DOM node');
assert.equal(host.childNodes[1].id,99,'the pending row becomes the saved message');
assert.equal(redraws,0,'normal reply completion must not rebuild image-heavy history');
assert.equal(finalRefresh,1,'chat follower should update just once');
assert.equal(finalState.typingEl,null,'live typing state is cleared');
assert.equal(finalContext.replaceOne('demo',99),false,'a second final event cannot replace a row twice');
console.log('History-render safety: atomic history, local saved-reply replacement, old images preserved OK');
