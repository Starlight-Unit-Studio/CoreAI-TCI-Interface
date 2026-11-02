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

const pending = fixture(false,true);
pending.ctx.renderHistory({id:'demo'});
assert.deepEqual(pending.visible.childNodes.map(item=>item.name),
  ['new-confirmed-message','pending-response']);
assert.equal(pending.state.typingEl,pending.visible.childNodes[1]);

assert(source.includes("problem.kind = 'render'"));
assert(source.includes('if (!displayError && !prepend) scheduleHistoryRetry'));
console.log('History-render safety: atomic commit, error rollback, state and no auto retry OK');
