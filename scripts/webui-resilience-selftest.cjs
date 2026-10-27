/* CoreAI 0.5.70 (#112): offline checks for history loading, reconnect,
 * backoff and the render window of long chats. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const netSource = fs.readFileSync(path.join(root, 'js/coreui-net.js'), 'utf8');
const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');

function netSandbox(fetchImpl, online = true) {
  const sandbox = {window: {}, navigator: {onLine: online}, fetch: fetchImpl, AbortController,
    setTimeout, clearTimeout, Promise, JSON, Math, Date};
  vm.runInNewContext(netSource, sandbox);
  return sandbox;
}

function response(status, text) {
  return {ok: status >= 200 && status < 300, status, text: () => Promise.resolve(text)};
}

function snippet(start, end) {
  const begin = app.indexOf(start);
  const stop = app.indexOf(end, begin + 1);
  assert(begin > 0 && stop > begin, 'missing in console-app.js: ' + start);
  return app.slice(begin, stop);
}

// A stalled promise must fail loudly, never end as a silent success.
const stallGuard = setTimeout(() => { console.error('WebUI resilience selftest stalled'); process.exit(1); }, 20000);

(async () => {
  // --- coreui-net.js: error kinds -----------------------------------------
  let net = netSandbox(() => Promise.resolve(response(200, '{"ok":true,"messages":[]}'))).window.CoreUINet;
  assert.deepEqual(JSON.parse(JSON.stringify(await net.fetchJson('/a', {metric: 'history'}))), {ok: true, messages: []});
  assert.equal(net.metrics().entries[0].kind, 'history', 'fetch duration recorded');

  const kinds = async (fetchImpl, opts = {}, online = true) => {
    const n = netSandbox(fetchImpl, online).window.CoreUINet;
    try { await n.fetchJson('/a', opts); return 'resolved'; } catch (e) { return e.kind + ':' + e.status; }
  };
  assert.equal(await kinds(() => Promise.resolve(response(502, '<html>bad gateway</html>'))), 'http:502',
    'an HTML error page is an HTTP error');
  assert.equal(await kinds(() => Promise.resolve(response(200, '{"ok":tru'))), 'json:200', 'cut JSON');
  assert.equal(await kinds(() => Promise.reject(new TypeError('Failed to fetch'))), 'network:0');
  assert.equal(await kinds(() => Promise.reject(new TypeError('Failed to fetch')), {}, false), 'offline:0');
  assert.equal(await kinds(() => { throw new TypeError('sync'); }), 'network:0', 'synchronous fetch failure');
  // Like fetch: rejects once its signal aborts, also when already aborted.
  const hanging = (url, init) => new Promise((resolve, reject) => {
    if (init.signal.aborted) reject(new Error('AbortError'));
    init.signal.addEventListener('abort', () => reject(new Error('AbortError')));
  });
  assert.equal(await kinds(hanging, {timeoutMs: 1000}), 'timeout:0', 'a hanging request times out');
  const outer = new AbortController();
  const pending = kinds(hanging, {signal: outer.signal, timeoutMs: 5000});
  outer.abort();
  assert.equal(await pending, 'aborted:0', 'a session switch aborts');
  const pre = new AbortController();
  pre.abort();
  assert.equal(await kinds(hanging, {signal: pre.signal}), 'aborted:0', 'already aborted signal');
  const appError = await netSandbox(() => Promise.resolve(response(404, '{"ok":false,"error":"session_not_found"}')))
    .window.CoreUINet.fetchJson('/a');
  assert.equal(appError.error, 'session_not_found', 'JSON errors reach the caller');

  // Backoff: exponential, jittered, capped.
  const mid = () => 0.5;
  assert.deepEqual([0, 1, 2, 3, 4, 8].map(n => net.backoff(n, 1500, 20000, mid)), [1500, 3000, 6000, 12000, 20000, 20000]);
  assert.equal(net.backoff(10, 3000, 60000, () => 1), 60000, 'jitter never exceeds the cap');
  assert(net.backoff(0, 2000, 30000, () => 0) >= 1600, 'jitter stays within 20 percent');

  // Texts and data-sparse metrics.
  assert(net.describe({kind: 'offline'}).includes('Keine Netzverbindung'));
  assert(net.describe({kind: 'timeout'}).includes('Zeitüberschreitung'));
  assert(net.describe({kind: 'http', status: 503}).includes('HTTP 503'));
  assert(net.describe(new Error('session_not_found')).includes('session_not_found'));
  const recorded = net.record({kind: 'history', ms: 12.4, records: 60, text: 'Geheimer Prompt mit Inhalt',
    token: 'abc def ghi'});
  assert.equal(recorded.text, undefined, 'no message text in metrics');
  assert.equal(recorded.token, undefined, 'no free text in metrics');
  assert.equal(recorded.ms, 12);
  for (let i = 0; i < 80; i++) net.record({kind: 'x'});
  assert.equal(net.metrics().entries.length, 60, 'metrics ring buffer is bounded');

  // --- console-app.js: render window of long chats -------------------------
  const sessionId = 'sessionAAAA1';
  const rows = [];
  for (let id = 1; id <= 1000; id++) {
    rows.push(id % 2 ? {id, character_id: 'c1'} : {id, is_ember: true, reply_to_id: id - 1});
  }
  const state = {messageCacheBySession: {[sessionId]: rows.slice()}, renderLimitBySession: {},
    pendingBySession: {}, historyHasMoreBySession: {}, historyOldestBySession: {}, sessionLru: []};
  const ctx = {state, RENDER_LIMIT: 240, CACHE_KEEP_INACTIVE: 60, CACHE_MAX_SESSIONS: 12,
    isEmberMsg: m => m.is_ember === true};
  vm.runInNewContext(snippet('  function visibleRecords(ses) {', '  function renderConversationRows(')
    + snippet('  function trimSessionCaches(activeId) {', '  function selectSession(sessionId) {')
    + '\nthis.visible = visibleRecords; this.hidden = hiddenRecordCount; this.trim = trimSessionCaches;', ctx);
  const ses = {id: sessionId};
  let shown = ctx.visible(ses);
  assert(shown.length <= 241 && shown.length >= 240, 'a long chat renders a bounded window');
  assert.equal(shown[0].is_ember, undefined, 'the window starts with a question');
  assert.equal(shown[shown.length - 1].id, 1000, 'the newest message is always rendered');
  assert.equal(ctx.hidden(ses), 1000 - shown.length, 'hidden records counted for the older button');
  // A later regenerated reply to a hidden question is not shown as an orphan.
  state.messageCacheBySession[sessionId].push({id: 1001, is_ember: true, reply_to_id: 3});
  shown = ctx.visible(ses);
  assert(!shown.some(r => r.id === 1001), 'orphan variant of a hidden turn stays hidden');
  state.renderLimitBySession[sessionId] = 2000;
  assert.equal(ctx.visible(ses).length, 1001, 'raising the window shows everything');

  // Inactive caches keep their newest page; pending and active stay whole.
  state.messageCacheBySession.other = rows.slice(0, 500);
  state.messageCacheBySession.busy = rows.slice(0, 500);
  state.pendingBySession.busy = 501;
  state.sessionLru = ['other', 'busy'];
  ctx.trim(sessionId);
  assert.equal(state.messageCacheBySession[sessionId].length, 1001, 'active session untouched');
  assert.equal(state.messageCacheBySession.busy.length, 500, 'a session waiting for a reply untouched');
  assert.equal(state.messageCacheBySession.other.length, 60, 'inactive cache trimmed to its newest page');
  assert.equal(state.historyHasMoreBySession.other, true);
  assert.equal(state.historyOldestBySession.other, 441, 'older pages come back from the server');
  for (let i = 0; i < 14; i++) state.messageCacheBySession['s' + i] = rows.slice(0, 10);
  state.sessionLru = state.sessionLru.concat(Array.from({length: 14}, (_, i) => 's' + i));
  ctx.trim(sessionId);
  assert(Object.keys(state.messageCacheBySession).length <= 13, 'at most twelve cached sessions plus the active one');
  assert.equal(state.messageCacheBySession.s13, undefined, 'least recently used caches are released');

  // --- Static contract of the reconnect path -------------------------------
  const load = snippet('  function loadChatHistory(ses, beforeId, prepend) {', '  // ── Hilfsfunktionen');
  assert(load.includes('state.historyAbort.abort()') && load.includes('timeoutMs: HISTORY_TIMEOUT_MS')
    && load.includes("error.kind === 'aborted'") && load.includes('epoch !== state.viewEpoch'),
    'history load is abortable, times out and keeps the viewEpoch guard');
  assert(load.includes("!messagesInner.querySelector('.msg-row')") && !load.includes("messagesInner.innerHTML = ''"),
    'a failed load never removes rendered messages');
  assert(!/inputEl\.value\s*=/.test(load), 'a failed load never touches the draft');
  const select = snippet('  function selectSession(sessionId) {', '  function workEventMerge(');
  assert(select.includes('state.historyAbort.abort()') && select.includes('clearTimeout(state.historyRetryTimer)'),
    'a session switch cancels the old request and retry');
  const retry = snippet('  function scheduleHistoryRetry(ses, failures) {', '  // Exakte, serverseitig');
  assert(retry.includes('failures > HISTORY_AUTO_RETRIES') && retry.includes('!netOnline()')
    && retry.includes('document.hidden'), 'bounded automatic retries, none while offline or hidden');
  const resync = snippet('  function resyncActiveSession(reason) {', "  window.addEventListener('offline'");
  assert(!/POST|sendMessage|chat\.php|console_stream/.test(resync), 'reconnect never sends a turn again');
  assert(app.includes("window.addEventListener('online'") && app.includes("document.addEventListener('visibilitychange'"),
    'online and visibility signals resynchronise');
  const poll = snippet('  function doPoll(ses, userMsgId) {', '  // Background Poll');
  assert(poll.includes('netBackoff(failures - 1, POLL_FAST_MS, 20000)') && poll.includes('if (!netOnline())'),
    'reply polling backs off and offline attempts do not use up the budget');
  const bg = snippet('  function scheduleBackgroundPoll() {', '  function doBackgroundPoll() {');
  assert(bg.includes('POLL_HIDDEN_MS') && bg.includes('netBackoff(state.bgFailures - 1, POLL_BG_MS, 60000)'),
    'background polling slows down when hidden or failing');
  const prepend = snippet('  function prependOlderRecords(ses, oldestBefore) {', '  // Prepending keeps');
  assert(prepend.includes('insertBefore(fresh, messagesInner.firstChild)') && prepend.includes('straddles'),
    'older pages are inserted, not re-rendered, unless a turn straddles both parts');
  const html = fs.readFileSync(path.join(root, 'app.html'), 'utf8');
  assert(html.indexOf('js/coreui-net.js') > 0 && html.indexOf('js/coreui-net.js') < html.indexOf('js/console-app.js'),
    'net module loads before the console');
  const follow = fs.readFileSync(path.join(root, 'js/coreui-chat-follow.js'), 'utf8');
  assert(follow.includes('release:function(){following=false;refresh();}'), 'loading older messages stops following');
  assert(!/caches\.open|serviceWorker/.test(netSource + app), 'private chats are never put into a browser asset cache');

  clearTimeout(stallGuard);
  console.log('CoreAI WebUI resilience: error kinds, backoff, reconnect, render window and caches OK');
})().catch(error => { console.error(error); process.exit(1); });
