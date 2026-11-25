// CoreAI frontend stabilisation checks (offline, no network, no browser).
//   node scripts/stabilize-frontend-selftest.cjs
// Each check runs the real functions of js/console-app.js, js/coreui-work-events.js,
// js/coreui-project-files.js and js/coreui-projects.js in a node:vm sandbox with a small
// stand-in DOM. Checked:
//   C1  a follow-up queued during a fast reply waits for the server's 4 s turn spacing
//   C2  a named SSE error and a Stop on an already ended run end the busy state
//   C3  a redrawn chat keeps watching its research job (and its later error)
//   M1  a regenerate or edit answer arriving after a chat switch leaves the other chat alone
//   M2  a finished action in a closed chat does not abort or relabel the open chat
//   M3  a session list refresh does not move the welcome screen into another tab's chat
//   M4  a send the server did not take goes back into the input row
//   L1  a live work timeline replaced before it was shown stops its 1 s ticker
//   L2  project command/process polls stop with the project page and in a hidden tab
//   L3  the welcome screen ends the research view of the chat it leaves
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');
const APP = read('js/console-app.js');
const FILES = read('js/coreui-project-files.js');
const PROJECTS = read('js/coreui-projects.js');

function pick(source, name) {
  const start = source.indexOf('function ' + name + '(');
  if (start < 0) throw new Error('function ' + name + ' is missing');
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i++) {
    if (source[i] === '{') depth++;
    if (source[i] === '}' && --depth === 0) {
      const code = source.slice(start, i + 1);
      new vm.Script(code); // a wrong brace match must not pass silently
      return code;
    }
  }
  throw new Error('function ' + name + ' is not closed');
}
const has = (source, name) => source.indexOf('function ' + name + '(') >= 0;
function load(source, ctx, names) {
  vm.createContext(ctx);
  vm.runInContext(names.map(n => pick(source, n)).join('\n') + '\n'
    + names.map(n => 'this.' + n + '=' + n + ';').join(''), ctx);
  return ctx;
}
const flush = async (n = 6) => { for (let i = 0; i < n; i++) await new Promise(r => setImmediate(r)); };
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; }

// A tiny element: parent links, isConnected via a root flag, querySelector by one class.
class El {
  constructor(tag) {
    this.tagName = String(tag || 'div').toUpperCase(); this.children = []; this.parentNode = null;
    this.className = ''; this.hidden = false; this.textContent = ''; this.attrs = {}; this.isRoot = false;
    this.style = {}; this.classList = { add() {}, remove() {}, toggle() {}, contains() { return false; } };
  }
  get childNodes() { return this.children; }
  get firstChild() { return this.children[0] || null; }
  get lastElementChild() { return this.children[this.children.length - 1] || null; }
  get isConnected() { for (let n = this; n; n = n.parentNode) if (n.isRoot) return true; return false; }
  appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; }
  removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); c.parentNode = null; return c; }
  replaceChildren(...nodes) { this.children.slice().forEach(c => this.removeChild(c)); nodes.forEach(n => this.appendChild(n)); }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] === undefined ? null : this.attrs[k]; }
  addEventListener() {}
  querySelector(sel) {
    const cls = sel.charAt(0) === '.' ? sel.slice(1) : null;
    for (const c of this.children) {
      if (cls && String(c.className).split(/\s+/).includes(cls)) return c;
      const deep = c.querySelector ? c.querySelector(sel) : null;
      if (deep) return deep;
    }
    return null;
  }
  querySelectorAll() { return []; }
}

const tests = [];
const test = (id, name, fn) => tests.push({id, name, fn});

// ---------------------------------------------------------------- C1 + M4: sendMessage
function sendHarness(opts = {}) {
  let now = 5000000;
  class FakeDate extends Date {
    constructor(...a) { if (a.length) super(...a); else super(now); }
    static now() { return now; }
  }
  const ses = { id: 's1', title: 'Chat', last_message_id: 0, last_read_message_id: 0, client_cursor: 0 };
  const messagesInner = new El('div'); messagesInner.isRoot = true;
  const log = { system: [], timers: [], streams: [], polls: [], queued: [] };
  const fetches = [];
  const ctx = {
    Date: FakeDate, JSON, String, Number, Math, Array, TextEncoder, Promise, Error,
    API_BASE: '/api', CHANNEL: 'console', USE_STREAM: true, INLINE_TEXT_LIMIT: 12000, MAX_MESSAGE_ATTACHMENTS: 10,
    pendingFiles: [], uploadQueue: [], uploadCurrentFile: null, uploadXhr: null, pendingSendAfterUpload: null,
    window: {}, location: { replace() {} }, inputEl: { value: '' }, btnSend: { disabled: false },
    headerSessionName: {}, messagesInner,
    state: {
      editingMessage: null, draftSendRequested: false, characterId: 'c1', charName: 'Ava', userDisplayName: 'Ava',
      assistantName: 'Mara', sessions: [ses], activeSessionId: 's1', pendingBySession: {}, sendTimestamp: 0,
      sendTimestampBySession: {}, lastSendAtBySession: {}, queuedTurnsBySession: {}, waitingEmber: false, guest: null
    },
    activeSession: () => ctx.state.sessions.find(s => String(s.id) === String(ctx.state.activeSessionId)) || null,
    isActiveSession: id => String(ctx.state.activeSessionId || '') === String(id || ''),
    sessionById: id => ctx.state.sessions.find(s => String(s.id) === String(id)) || null,
    composerIntentText: t => String(t || ''),
    setComposerText: t => { ctx.inputEl.value = String(t || ''); },
    appendMessageEl: (role, text) => {
      const row = new El('div'); row.className = 'msg-row ' + role; row.textContent = String(text);
      messagesInner.appendChild(row);
      if (role === 'system') log.system.push(String(text));
      return row;
    },
    clearAttachments() {}, restoreAttachments() {}, scrollToBottom() {}, setStatus() {}, updateActivePendingUi() {},
    showTypingIndicator() {}, removeTypingIndicator() {}, guestLimitReached() {}, renderGuestQuota() {},
    dbMarkRead() {}, mergeSessionMessages() {}, renderSessionList() {}, renderHistory() {}, clientTimezone: () => '',
    restoreQueuedTurn: (sid, turn) => log.queued.push(turn),
    openStreamForReply: (s, id) => { if (opts.streamThrows) throw new Error('render broke'); log.streams.push(id); },
    startFastPoll: (s, id) => log.polls.push(id),
    setTimeout: (fn, ms) => { log.timers.push(ms); return log.timers.length; },
    fetch: () => { const d = deferred(); fetches.push(d); return d.promise; },
  };
  load(APP, ctx, ['sendMessage', 'showResponseTime', 'drainQueuedTurn']);
  return { ctx, log, fetches, ses, messagesInner, advance: ms => { now += ms; } };
}
const reply = body => ({ json: () => Promise.resolve(body) });

test('C1', 'a follow-up queued during a fast SSE reply waits for the 4 s turn spacing', async () => {
  const h = sendHarness();
  h.ctx.sendMessage('Erste Frage');
  assert.equal(h.fetches.length, 1, 'the first turn is posted');
  h.advance(300);                                   // the server confirms after 0.3 s
  h.fetches[0].resolve(reply({ ok: true, id: 41, ember_pending: true }));
  await flush();
  assert.deepEqual(h.log.streams, [41], 'the confirmed turn opens its stream');
  h.ctx.state.queuedTurnsBySession.s1 = [{ id: 'q1', text: 'Folgefrage' }];
  h.advance(1200);                                  // a fast reply 1.5 s after the send
  delete h.ctx.state.pendingBySession.s1;           // finalizeStream: the turn is done ...
  h.ctx.showResponseTime({ querySelector: () => ({ textContent: '12:00' }) }, 's1'); // ... shows its time ...
  h.ctx.drainQueuedTurn('s1');                      // ... and then sends the queued turn
  assert.equal(h.log.timers.length, 1, 'the queued turn is scheduled once');
  const delay = h.log.timers[0];
  assert.ok(delay >= 4200 - 1500, 'the queued turn waits 4.2 s from the send, got ' + delay + ' ms');
  assert.ok(delay >= 4200 - 1200, 'the 4.2 s count from the server confirmation, got ' + delay + ' ms');
  assert.ok(delay <= 4200, 'no longer than the spacing itself, got ' + delay + ' ms');
});

test('C1', 'a queued turn long after the last send goes at once', async () => {
  const h = sendHarness();
  h.ctx.sendMessage('Erste Frage');
  h.fetches[0].resolve(reply({ ok: true, id: 41, ember_pending: true }));
  await flush();
  h.ctx.state.queuedTurnsBySession.s1 = [{ id: 'q1', text: 'Folgefrage' }];
  h.advance(30000);
  delete h.ctx.state.pendingBySession.s1;
  h.ctx.showResponseTime({ querySelector: () => null }, 's1');
  h.ctx.drainQueuedTurn('s1');
  assert.deepEqual(h.log.timers, [0]);
});

test('M4', 'a rejected send (duplicate_message) returns its text to the input row', async () => {
  const h = sendHarness();
  h.ctx.inputEl.value = 'Doppelt';
  h.ctx.sendMessage('Doppelt');
  assert.equal(h.ctx.inputEl.value, '', 'sending empties the input row');
  const bubble = h.messagesInner.children.find(c => c.className === 'msg-row user');
  assert.ok(bubble, 'the turn is shown right away');
  h.fetches[0].resolve(reply({ ok: false, error: 'duplicate_message' }));
  await flush();
  assert.equal(h.ctx.inputEl.value, 'Doppelt', 'the text is back in the input row');
  assert.equal(bubble.parentNode, null, 'the unsent bubble does not look sent');
  assert.deepEqual(h.log.system, ['⚠ Diese Nachricht wurde gerade schon gesendet.']);
  assert.equal(h.ctx.state.pendingBySession.s1, undefined);
  assert.ok(!/[\u2013\u2014]/.test(h.log.system[0]), 'no long dash in the notice');
});

test('M4', 'a send lost on the network returns its text, a new draft is kept', async () => {
  const h = sendHarness();
  h.ctx.sendMessage('Hallo Mara');
  h.fetches[0].reject(new TypeError('Failed to fetch'));
  await flush();
  assert.equal(h.ctx.inputEl.value, 'Hallo Mara');
  assert.deepEqual(h.log.system, ['⚠ Verbindungsfehler.']);
  assert.equal(h.messagesInner.children.filter(c => c.className === 'msg-row user').length, 0);

  const typed = sendHarness();
  typed.ctx.sendMessage('Alt');
  typed.ctx.inputEl.value = 'Schon neu getippt';
  typed.fetches[0].reject(new TypeError('Failed to fetch'));
  await flush();
  assert.equal(typed.ctx.inputEl.value, 'Schon neu getippt', 'a new draft is never overwritten');
});

test('M4', 'a confirmed send stays sent; a queued send goes back to the queue only', async () => {
  const h = sendHarness({ streamThrows: true });
  h.ctx.sendMessage('Bestaetigt');
  h.fetches[0].resolve(reply({ ok: true, id: 41, ember_pending: true }));
  await flush();
  assert.equal(h.ctx.inputEl.value, '', 'a confirmed turn is not offered again');
  assert.equal(h.messagesInner.children.filter(c => c.className === 'msg-row user').length, 1);

  const q = sendHarness();
  q.ctx.sendMessage('Vorgemerkt', { from_queue: true, queued_turn: { id: 'q1', text: 'Vorgemerkt' } });
  q.fetches[0].resolve(reply({ ok: false, error: 'rate_limited' }));
  await flush();
  assert.equal(q.ctx.inputEl.value, '', 'a queued turn is not put into the input row');
  assert.equal(q.log.queued.length, 1, 'it returns to the queue');
});

// ---------------------------------------------------------------- C2: SSE error and Stop
function streamHarness() {
  const listeners = {}; const log = { polls: [], genPolls: [], system: [], actionErrors: [], history: 0, closed: 0, drained: 0 };
  class FakeES { constructor(url) { this.url = url; } addEventListener(t, f) { listeners[t] = f; } close() { log.closed++; } }
  const ctx = {
    API_BASE: '/api', EventSource: FakeES, encodeURIComponent, String, Number, JSON, STREAM_IDLE_MS: 780000,
    setTimeout: () => 1, clearTimeout() {}, window: {}, insights: null,
    state: {
      characterId: 'c1', activeSessionId: 's1', liveWorkEventsBySession: {}, activeAgentRunsBySession: {},
      pendingBySession: { s1: 41 }, pendingCancelBySession: {}, finishedRunsBySession: {}, generationPollTimers: {},
      sendTimestampBySession: {}, messageCacheBySession: { s1: [{ id: 41, message: 'Frage' }] }
    },
    clientTimezone: () => '', isActiveSession: id => String(id) === String(ctx.state.activeSessionId),
    activeSession: () => ({ id: ctx.state.activeSessionId }),
    startFastPoll: (s, id) => log.polls.push(id), startGenerationStatusPoll: (s, r) => log.genPolls.push(r.id),
    updateAgentCancelControl() {}, removeTypingIndicator() {}, updateActivePendingUi() {}, setStatus() {},
    generationActionError: code => log.actionErrors.push(code), drainQueuedTurn: () => { log.drained++; },
    appendMessageEl: (role, text) => { if (role === 'system') log.system.push(text); }, scrollToBottom() {},
    loadChatHistory: () => { log.history++; return Promise.resolve(); }, pollCancelledRun() {},
    rememberLiveWorkEvent() {}, finalizeStream() {},
  };
  load(APP, ctx, ['openStreamForReply', 'resetGenerationUi', 'clearAgentRun', 'rememberFinishedRun', 'requestActiveRunCancel']);
  return { ctx, log, listeners };
}

test('C2', 'a named SSE error ends the turn instead of a 17 min fast poll', async () => {
  for (const code of ['exception', 'model_queue_timeout', 'agent_run_interrupted']) {
    const h = streamHarness();
    h.ctx.openStreamForReply({ id: 's1' }, 41);
    h.listeners.error({ data: JSON.stringify({ error: code }) });
    assert.deepEqual(h.log.polls, [], code + ': no fast poll for a reply that will not come');
    assert.equal(h.ctx.state.pendingBySession.s1, undefined, code + ': the chat is no longer busy');
    assert.deepEqual(h.log.system, ['Der Auftrag wurde unterbrochen.'], code + ': the failure is shown');
    assert.equal(h.log.closed, 1, code + ': the stream is closed');
    assert.deepEqual(h.ctx.state.messageCacheBySession.s1, [{ id: 41, message: 'Frage' }], code + ': the user turn stays');
    assert.equal(h.log.drained, 1, code + ': queued turns continue');
  }
});

test('C2', 'a bare connection error still falls back to the poll; regenerate errors are named', async () => {
  const h = streamHarness();
  h.ctx.openStreamForReply({ id: 's1' }, 41);
  h.listeners.error({});
  assert.deepEqual(h.log.polls, [41], 'a transport error polls for the reply the server may still save');
  assert.equal(h.ctx.state.pendingBySession.s1, 41);

  const g = streamHarness();
  g.ctx.openStreamForReply({ id: 's1' }, 41, { id: 'f'.repeat(32) });
  g.listeners.error({ data: JSON.stringify({ error: 'exception' }) });
  assert.deepEqual(g.log.genPolls, [], 'no status poll after a named error');
  assert.deepEqual(g.log.actionErrors, ['exception']);
  assert.equal(g.ctx.state.pendingBySession.s1, undefined);

  const c = streamHarness();
  c.ctx.openStreamForReply({ id: 's1' }, 41);
  c.listeners.error({ data: JSON.stringify({ error: 'agent_run_completed' }) });
  assert.equal(c.log.history, 1, 'a completed run shows its saved reply from the history');
  assert.deepEqual(c.log.system, []);
  assert.deepEqual(c.log.polls, []);
});

test('C2', 'Stop on a run that already ended frees the chat (no endless "Stopp angefordert")', async () => {
  for (const status of ['interrupted', 'failed', 'completed']) {
    const h = streamHarness();
    h.ctx.state.activeAgentRunsBySession.s1 = { id: 'a'.repeat(32), cancel_requested: false };
    h.ctx.postConsoleAction = () => Promise.resolve({ ok: true, status, cancel_requested: false });
    h.ctx.requestActiveRunCancel();
    await flush();
    assert.equal(h.ctx.state.pendingBySession.s1, undefined, status + ': the chat is no longer busy');
    h.ctx.requestActiveRunCancel();                 // a second tap
    assert.equal(h.ctx.state.pendingCancelBySession.s1, undefined, status + ': no stuck Stop request');
    if (status === 'completed') {
      assert.equal(h.log.history, 1, 'completed: the saved reply comes from the history');
      assert.deepEqual(h.log.system, []);
    } else {
      assert.deepEqual(h.log.system, ['Der Auftrag wurde unterbrochen.'], status + ': the end is shown');
    }
  }
});

// ---------------------------------------------------------------- C3 + L3: live research view
function browseHarness() {
  const sources = [];
  class FakeES { constructor(url) { this.url = url; this.closed = false; sources.push(this); } addEventListener() {} close() { this.closed = true; } }
  const messagesInner = new El('div'); messagesInner.isRoot = true;
  const ctx = {
    Array, Number, String, JSON, Math, EventSource: FakeES, messagesInner, chatFollow: null,
    BROWSE_IDLE_MS: 60000, BROWSE_RECONNECT_MAX: 6, uiPreferences: { showLiveBrowser: true },
    setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
    document: { createElement: t => new El(t) },
    browseStreamUrl: (job, sid) => '/api/console_browse_stream.php?job=' + job + '&session_id=' + sid,
    state: { activeSessionId: 's1', pendingBySession: { s1: 41 }, messageCacheBySession: { s1: [{ id: 41 }] },
      liveBrowse: null, typingEl: null, thinkingPanelEl: null, agentStopEl: null, assistantName: 'Mara' },
    isActiveSession: id => String(id) === String(ctx.state.activeSessionId),
    showTypingIndicator() {
      if (ctx.state.typingEl) return;
      const row = new El('div'); row.className = 'msg-row ember';
      const content = new El('div'); content.className = 'msg-content'; row.appendChild(content);
      ctx.messagesInner.appendChild(row); ctx.state.typingEl = row;
    },
    appendOlderButton() {}, renderConversationRows() {}, placeBranchLine() {}, renderQueuedTurns() {},
    appendMessageEl() {}, setStatus() {}, scrollToBottom() {}, rememberLiveWorkEvent() {}, liveWorkForReply: () => [],
    clearAgentRun() {}, updateActivePendingUi() {}, drainQueuedTurn() {},
  };
  load(APP, ctx, ['renderHistory', 'openBrowseWindow']);
  return { ctx, sources };
}

test('C3', 'a redrawn chat keeps watching its research job', async () => {
  const h = browseHarness();
  h.ctx.showTypingIndicator();
  h.ctx.openBrowseWindow(7, 's1', false, 41, 'base');
  assert.equal(h.sources.length, 1, 'the research stream is open');
  h.ctx.renderHistory({ id: 's1' });                // e.g. a follow-up was queued
  assert.equal(h.sources[0].closed, true, 'the stream of the removed panel is closed');
  assert.equal(h.sources.length, 2, 'the research job is watched again');
  assert.ok(/job=7&session_id=s1/.test(h.sources[1].url), 'the same job of the same chat');
  assert.equal(h.sources[1].closed, false);
  assert.ok(h.ctx.state.liveBrowse && h.ctx.state.liveBrowse.panel.isConnected, 'the panel is in the new typing row');

  h.ctx.state.activeSessionId = 's2';               // another chat opens
  h.ctx.renderHistory({ id: 's2' });
  assert.equal(h.sources.length, 2, 'another chat does not get this research view');
  assert.equal(h.sources[1].closed, true);
  assert.equal(h.ctx.state.liveBrowse, null);
});

test('C3', 'no research view again once the reply arrived', async () => {
  const h = browseHarness();
  h.ctx.showTypingIndicator();
  h.ctx.openBrowseWindow(7, 's1', false, 41, 'base');
  delete h.ctx.state.pendingBySession.s1;
  h.ctx.renderHistory({ id: 's1' });
  assert.equal(h.sources.length, 1);
  assert.equal(h.ctx.state.liveBrowse, null);
});

test('L3', 'the welcome screen ends the research view of the chat it leaves', async () => {
  let closed = 0;
  const ctx = {
    inputEl: {}, window: {}, messagesInner: new El('div'), chatFollow: null, jumpLatest: null, welcomeScreen: null,
    welcomeError: null, messagesArea: { classList: { add() {} } }, headerSessionName: {},
    clearTimeout() {}, rememberActiveSession() {}, clearMessageEdit() {}, showSessionInsights() {}, updateWelcomeGreeting() {},
    setComposerText() {}, updateActivePendingUi() {}, renderSessionList() {}, setStatus() {},
    state: { activeSessionId: 's1', viewEpoch: 0, draftSessionPromise: null, bgTimer: null, historyAbort: null,
      historyRetryTimer: null, editingMessage: null, liveBrowse: null },
  };
  ctx.state.liveBrowse = { close() { closed++; ctx.state.liveBrowse = null; } };
  load(APP, ctx, ['showDraftChat']);
  ctx.showDraftChat();
  assert.equal(closed, 1, 'the research stream is closed');
  assert.equal(ctx.state.liveBrowse, null);
});

// ---------------------------------------------------------------- M1: answers after a chat switch
test('M1', 'a regenerate answer after a chat switch draws nothing into the other chat', async () => {
  const calls = []; let resolve;
  const ctx = {
    Number, String, Promise,
    state: { activeSessionId: 'A', pendingBySession: {}, characterId: 'c1', assistantName: 'Mara' },
    activeSession: () => ({ id: ctx.state.activeSessionId }), isActiveSession: id => String(id) === String(ctx.state.activeSessionId),
    postConsoleAction: () => new Promise(r => { resolve = r; }), generationActionError() {}, guestTurnCounted() {},
    updateActivePendingUi: () => calls.push('pendingUi@' + ctx.state.activeSessionId),
    setStatus: (t, l) => calls.push('status@' + ctx.state.activeSessionId),
    showTypingIndicator: () => calls.push('typingRow@' + ctx.state.activeSessionId),
    openStreamForReply: s => calls.push('stream:' + s.id),
  };
  load(APP, ctx, ['startGenerationAction']);
  ctx.startGenerationAction({ id: 9, reply_to_id: 8 }, 'regenerate', { disabled: false });
  ctx.state.activeSessionId = 'B';
  resolve({ ok: true, request: { id: 'f'.repeat(32), trigger_message_id: 8 } });
  await flush();
  assert.deepEqual(calls, ['stream:A'], 'only the stream of chat A starts: ' + calls.join(', '));
  assert.equal(ctx.state.pendingBySession.A, 8);
});

function editHarness() {
  const calls = []; let resolve;
  const ctx = {
    Number, String, Date, Promise, Error,
    btnSend: { disabled: false }, inputEl: { value: 'Neu' },
    state: { activeSessionId: 'A', pendingBySession: {}, sendTimestampBySession: {}, assistantName: 'Mara',
      messageCacheBySession: { A: [{ id: 5, message: 'alt' }] }, variantSelectionBySession: {}, editingMessage: null },
    sessions: { A: { id: 'A' }, B: { id: 'B' } },
    activeSession: () => ctx.sessions[ctx.state.activeSessionId], isActiveSession: id => String(id) === String(ctx.state.activeSessionId),
    postConsoleAction: () => new Promise(r => { resolve = r; }), generationActionError() {}, guestTurnCounted() {},
    setComposerText: t => { ctx.inputEl.value = t; calls.push('composer@' + ctx.state.activeSessionId); },
    clearMessageEdit: () => { ctx.state.editingMessage = null; },
    updateActivePendingUi() {}, scrollToBottom() {},
    renderHistory: () => calls.push('render@' + ctx.state.activeSessionId),
    setStatus: () => calls.push('status@' + ctx.state.activeSessionId),
    showTypingIndicator: () => calls.push('typingRow@' + ctx.state.activeSessionId),
    openStreamForReply: (s, id) => calls.push('stream:' + s.id + ':' + id),
  };
  load(APP, ctx, ['submitMessageEdit']);
  ctx.state.editingMessage = { session_id: 'A', message_id: 5, original_text: 'alt' };
  return { ctx, calls, answer: () => resolve({ ok: true, request: { id: 'e'.repeat(32) }, message_id: 5, message: 'neu' }) };
}

test('M1', 'a saved edit after a chat switch keeps the other chat and its input row', async () => {
  const h = editHarness();
  h.ctx.submitMessageEdit('neu');
  h.ctx.state.activeSessionId = 'B'; h.ctx.state.editingMessage = null; // selectSession(B)
  h.ctx.inputEl.value = 'Entwurf in B';
  h.answer();
  await flush();
  assert.deepEqual(h.calls, ['stream:A:5'], 'only the stream of chat A starts: ' + h.calls.join(', '));
  assert.equal(h.ctx.inputEl.value, 'Entwurf in B', 'the draft in chat B stays');
  assert.equal(h.ctx.state.pendingBySession.A, 5);

  const same = editHarness();                       // regression: the open chat still updates
  same.ctx.submitMessageEdit('neu');
  same.answer();
  await flush();
  assert.equal(same.ctx.inputEl.value, '');
  assert.deepEqual(same.calls, ['composer@A', 'render@A', 'status@A', 'typingRow@A', 'stream:A:5']);
});

// ---------------------------------------------------------------- M2: history of a closed chat
test('M2', 'a finished action in a closed chat does not abort or relabel the open chat', async () => {
  const status = []; const requests = []; const controllers = [];
  class AC { constructor() { this.aborted = false; this.signal = {}; controllers.push(this); } abort() { this.aborted = true; } }
  const ctx = {
    API_BASE: '/api', AbortController: AC, encodeURIComponent, String, Number, Date, Array, HISTORY_TIMEOUT_MS: 20000,
    state: { characterId: 'c1', viewEpoch: 5, activeSessionId: 'B', historyAbort: null, pendingBySession: {},
      messageCacheBySession: { A: [{ id: 1 }] }, historyHasMoreBySession: { A: true }, historyOldestBySession: { A: 1 },
      branchBySession: {}, historyFailuresBySession: {}, renderLimitBySession: {} },
    netJson: url => { requests.push(url); return new Promise(() => {}); },
    setStatus: (t, l) => status.push(l), isActiveSession: id => String(id) === String(ctx.state.activeSessionId),
  };
  load(APP, ctx, ['loadChatHistory']);
  ctx.loadChatHistory({ id: 'B' });                 // the open chat loads
  ctx.loadChatHistory({ id: 'A' });                 // a regenerate in chat A finished meanwhile
  await flush();
  assert.equal(controllers[0].aborted, false, 'the open chat request is not cancelled');
  assert.equal(ctx.state.historyAbort, controllers[0]);
  assert.equal(requests.length, 1, 'only the open chat is requested');
  assert.deepEqual(status, ['LADE…'], 'the status is only the open chat\'s');
  assert.equal(ctx.state.messageCacheBySession.A, undefined, 'chat A loads fresh when opened');
});

// ---------------------------------------------------------------- M3: remembered chat only at start
test('M3', 'a session list refresh keeps the welcome screen on its own draft', async () => {
  const store = { 'emberCoreUI.activeSession.7': 'cs_otherTab1' };
  const ctx = {
    API_BASE: '/api', String, Array, URLSearchParams, Error, location: { search: '', pathname: '/app.html', hash: '' },
    history: { replaceState() {} }, localStorage: { getItem: k => store[k] || null, setItem: (k, v) => { store[k] = v; } },
    window: { dispatchEvent() {} }, CustomEvent: function () {},
    state: { uid: 7, showArchived: false, activeSessionId: null, sessions: [] },
    fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, sessions: [{ id: 'cs_otherTab1', title: 'X' }] }) }),
  };
  load(APP, ctx, ['dbLoadSessions', 'rememberedActiveSession', 'activeSessionStorageKey', 'sessionById', 'rememberActiveSession']);
  await ctx.dbLoadSessions();                       // pollSessionWork every 15 to 60 s
  assert.equal(ctx.state.activeSessionId, null, 'another tab\'s chat is not adopted');
  await ctx.dbLoadSessions(true);                   // the page start may use the remembered chat
  assert.equal(ctx.state.activeSessionId, 'cs_otherTab1');
  assert.ok(/return dbLoadSessions\(true\)/.test(pick(APP, 'init')), 'init reads the remembered chat');
});

// ---------------------------------------------------------------- L1: live timeline ticker
function timelineHarness() {
  class TEl extends El { constructor(t) { super(t); this.connectedFlag = true; } get isConnected() { return this.connectedFlag; } }
  const intervals = []; let cleared = 0;
  const sandbox = { URL, document: { createElement: t => new TEl(t), hidden: false }, window: {
    setInterval: fn => { intervals.push(fn); return intervals.length; }, clearInterval: () => { cleared++; },
    setTimeout: () => 0, clearTimeout() {} } };
  vm.runInNewContext(read('js/coreui-work-events.js'), sandbox);
  const tl = sandbox.window.CoreUIWorkEvents.create({ live: true, expanded: true, events: [] });
  return { tl, tick: n => { for (let i = 0; i < n; i++) intervals[0](); }, cleared: () => cleared, count: () => intervals.length };
}

test('L1', 'a live timeline replaced before it was shown stops its 1 s ticker', async () => {
  const h = timelineHarness();
  assert.equal(h.count(), 1);
  h.tl.connectedFlag = false;                       // typing row replaced within the first second
  h.tick(3);
  assert.equal(h.cleared(), 0, 'a short detach (an off-screen build) keeps ticking');
  h.tick(1);
  assert.equal(h.cleared(), 1, 'after about 3 detached ticks the ticker stops');

  const shown = timelineHarness();                  // regression: shown, then replaced
  shown.tick(1);
  shown.tl.connectedFlag = false;
  shown.tick(1);
  assert.equal(shown.cleared(), 1);

  const back = timelineHarness();                   // detached briefly, then shown: keeps running
  back.tl.connectedFlag = false; back.tick(2);
  back.tl.connectedFlag = true; back.tick(5);
  assert.equal(back.cleared(), 0);
});

// ---------------------------------------------------------------- L2: project polls
function procHarness(hidden) {
  const timers = [];
  const ctx = {
    document: { hidden }, procTimer: 0, procShown: '', procOutput: null, runTimer: 0, pollsPaused: false,
    setTimeout: (fn, ms) => { timers.push(ms); return timers.length; }, clearTimeout() {},
    state: { project: 'p'.repeat(32), proc: { open: true, list: [], current: null, since: 0 }, run: { open: false, list: [], current: null } },
    getJson: () => Promise.resolve({ proc: { id: 'x1', status: 'running', output: 'a', output_end: 1, output_from: 0 } }),
    renderProc() {}, appendOutput() {}, message: e => String(e), showRun() {},
  };
  const names = ['showProc', 'stopProcPoll', 'procActive', 'stopRunPoll']
    .concat(['pollsAllowed', 'pausePolls', 'resumePolls'].filter(n => has(FILES, n)));
  load(FILES, ctx, names);
  return { ctx, timers };
}

test('L2', 'a hidden tab schedules no 1 s process poll', async () => {
  const visible = procHarness(false);
  visible.ctx.showProc('x1');
  await flush();
  assert.deepEqual(visible.timers, [1000], 'a shown page keeps polling');
  const hidden = procHarness(true);
  hidden.ctx.showProc('x1');
  await flush();
  assert.deepEqual(hidden.timers, [], 'a hidden tab does not poll every second');
});

test('L2', 'leaving the project page stops the poll, the Dateien tab resumes it', async () => {
  const h = procHarness(false);
  assert.ok(has(FILES, 'pausePolls') && has(FILES, 'resumePolls'), 'pause and resume exist');
  h.ctx.showProc('x1');
  await flush();
  assert.deepEqual(h.timers, [1000]);
  h.ctx.pausePolls();
  assert.equal(h.ctx.procTimer, 0, 'the pending poll is cancelled');
  h.ctx.showProc('x1');                             // a request that was still under way
  await flush();
  assert.deepEqual(h.timers, [1000], 'no new poll while the page is closed');
  vm.runInContext('pollsPaused = false;', h.ctx);   // CoreAIProjectFiles.open()
  h.ctx.resumePolls();
  await flush();
  assert.deepEqual(h.timers, [1000, 1000], 'the running process is followed again');
  assert.ok(/pollsPaused = false;[\s\S]*resumePolls\(\);/.test(pick(FILES, 'open')), 'open() resumes the polls');
  assert.ok(/visibilitychange[\s\S]{0,160}resumePolls\(\)/.test(FILES), 'a shown tab resumes the polls');
  assert.ok(/pause: pausePolls/.test(FILES), 'the module offers pause()');
});

test('L2', 'closeHome pauses the project file polls without dropping a draft', async () => {
  let paused = 0, reset = 0;
  const el = () => ({ hidden: false, setAttribute() {} });
  const els = {};
  const ctx = {
    current: 'p'.repeat(32), directoryVisible: false, URLSearchParams,
    $: id => (els[id] = els[id] || el()), dismissFloatingMenu() {}, restoreComposer() {}, renderSidebar() {},
    document: { body: { classList: { remove() {} } } }, location: { search: '', pathname: '/app.html' }, history: { replaceState() {} },
    window: { CoreAIProjectFiles: { pause: () => { paused++; }, reset: () => { reset++; } } },
  };
  load(PROJECTS, ctx, ['closeHome', 'filesTab']);
  ctx.closeHome();
  assert.equal(paused, 1, 'the 1 s polls stop with the page');
  assert.equal(reset, 0, 'an unsaved draft in Dateien stays');
});

// ---------------------------------------------------------------- run
(async () => {
  let failed = 0;
  for (const t of tests) {
    try {
      await t.fn();
      console.log('PASS ' + t.id + ' ' + t.name);
    } catch (error) {
      failed++;
      console.log('FAIL ' + t.id + ' ' + t.name + ': ' + (error && error.message ? error.message.split('\n')[0] : error));
    }
  }
  console.log(failed ? 'Frontend stabilisation selftest FAILED: ' + failed + ' of ' + tests.length + ' checks'
    : 'Frontend stabilisation selftest OK: ' + tests.length + ' checks');
  process.exitCode = failed ? 1 : 0;
})();
