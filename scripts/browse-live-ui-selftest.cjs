/* CoreAI 0.5.77: the live browser view never looks frozen. Offline with a
   fake DOM, fake EventSource and fake clock around the real code from
   console-app.js: "Mara ueberlegt" with running seconds while the Core
   works, the cursor glides to the target and clicks after arriving, the
   queue shows "WARTET", warnings colour the status, the stream resumes
   after the 15-minute cap and after network errors without replaying old
   pictures, and the view ends cleanly on done or a real error. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'css/console.css'), 'utf8');
const begin = app.indexOf('  // ── Live-Browser-Agentenansicht');
const end = app.indexOf('  function consoleMessagesUrl(ses, afterId) {');
assert(begin > 0 && end > begin, 'browse view missing in console-app.js');
const source = app.slice(begin, end);

// --- fake clock ------------------------------------------------------------
let now = 1_000_000;
let timers = [];
let timerSeq = 0;
function addTimer(fn, ms, repeat) {
  const id = ++timerSeq;
  timers.push({id, at: now + Math.max(0, ms | 0), fn, repeat: repeat ? Math.max(1, ms | 0) : 0});
  return id;
}
function clearTimer(id) { timers = timers.filter(t => t.id !== id); }
function advance(ms) {
  const target = now + ms;
  for (;;) {
    timers.sort((a, b) => a.at - b.at || a.id - b.id);
    const next = timers[0];
    if (!next || next.at > target) break;
    now = next.at;
    if (next.repeat) next.at += next.repeat; else timers.shift();
    next.fn();
  }
  now = target;
}

// --- fake DOM ----------------------------------------------------------------
class ClassList {
  constructor() { this.set = new Set(); }
  add(...c) { c.forEach(x => this.set.add(x)); }
  remove(...c) { c.forEach(x => this.set.delete(x)); }
  contains(c) { return this.set.has(c); }
  toggle(c, on) { if (on === undefined ? !this.set.has(c) : on) this.set.add(c); else this.set.delete(c); }
}
class Element {
  constructor(tag) {
    this.tagName = String(tag).toUpperCase();
    this.classList = new ClassList();
    this.style = {};
    this.children = [];
    this.parentNode = null;
    this.hidden = false;
    this.handlers = {};
    this.textContent = '';
    this.title = '';
    this.parts = {};
    this._src = '';
    this.complete = false;
    this.naturalWidth = 0;
  }
  set className(v) { this.classList = new ClassList(); String(v).split(/\s+/).filter(Boolean).forEach(c => this.classList.add(c)); }
  get className() { return Array.from(this.classList.set).join(' '); }
  set innerHTML(html) {
    this._html = html;
    // The panel's parts, found by class like the real markup.
    for (const m of html.matchAll(/class="([^"]+)"/g)) {
      const name = m[1].split(' ')[0];
      if (!this.parts[name]) {
        const el = new Element(name === 'browse-live-shot' ? 'img' : 'span');
        el.className = m[1];
        if (/\shidden>/.test(html.slice(m.index, html.indexOf('>', m.index) + 1))) el.hidden = true;
        this.parts[name] = el;
      }
    }
    this.parts['browse-live-placeholder strong'] = new Element('strong');
  }
  get innerHTML() { return this._html || ''; }
  set src(v) {
    this._src = v;
    const el = this;
    addTimer(() => { el.complete = true; el.naturalWidth = 1280; if (el.onload) el.onload(); }, 5, false);
  }
  get src() { return this._src; }
  get offsetWidth() { return 10; }
  querySelector(sel) { return this.parts[sel.replace(/^\./, '')] || null; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
}

// --- fake EventSource --------------------------------------------------------
const sources = [];
class FakeEventSource {
  constructor(url) { this.url = url; this.handlers = {}; this.closed = false; sources.push(this); }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  close() { this.closed = true; }
  emit(type, data) {
    assert(!this.closed, 'event on a closed stream: ' + type);
    this.handlers[type]({data: data === undefined ? undefined : JSON.stringify(data)});
  }
}

function harness() {
  timers = [];
  sources.length = 0;
  const messagesInner = new Element('div');
  const calls = {status: [], drained: 0, scrolled: 0};
  const state = {pendingBySession: {s1: {}}, assistantName: 'Mara', agentStopEl: null};
  const sandbox = {
    API_BASE: '/api', BROWSE_IDLE_MS: 720000, uiPreferences: {liveFrameMotion: true}, state, messagesInner,
    document: {createElement: tag => new Element(tag)},
    EventSource: FakeEventSource,
    setTimeout: (fn, ms) => addTimer(fn, ms, false), clearTimeout: clearTimer,
    setInterval: (fn, ms) => addTimer(fn, ms, true), clearInterval: clearTimer,
    Date: {now: () => now}, JSON, Math, Number, String, encodeURIComponent,
    scrollToBottom: () => { calls.scrolled++; },
    applyMessageAvatar: () => {},
    requestActiveRunCancel: () => {},
    updateAgentCancelControl: () => {},
    isActiveSession: () => true,
    updateActivePendingUi: () => {},
    setStatus: (kind, text) => calls.status.push(kind + ':' + text),
    drainQueuedTurn: () => { calls.drained++; },
  };
  vm.runInNewContext(source + '\nthis.openBrowseWindow = openBrowseWindow;', sandbox);
  sandbox.openBrowseWindow(77, 's1');
  const row = messagesInner.children[0];
  const panel = row.children[1].children[0];
  const part = name => panel.parts[name];
  return {sandbox, panel, part, calls, state};
}

const frame = (id, extra) => Object.assign({id, step: 1, b64: 'AAAA' + id, cx: 640, cy: 450, vw: 1280, vh: 900,
  click: false, url: 'https://shop.example/', label: 'Bild ' + id}, extra || {});

// --- a normal run ------------------------------------------------------------
let h = harness();
let es = sources[0];
assert.match(es.url, /console_browse_stream\.php\?job_id=77&session_id=s1&after_frame=0&after_step=0$/, 'first connect from the start');

// The queue: Mara is still busy with another turn.
es.emit('status', {status: 'queued'});
assert.equal(h.part('browse-live-state').textContent, 'WARTET');
assert.equal(h.part('browse-live-placeholder strong').textContent, 'WARTET AUF MARA');
assert.match(h.part('browse-live-status').textContent, /noch beschäftigt/);
es.emit('status', {status: 'running'});
assert.equal(h.part('browse-live-state').textContent, 'LIVE');

// A picture arrives and is shown.
es.emit('frame', frame(1));
advance(10);
assert.equal(h.part('browse-live-shot').src, 'data:image/jpeg;base64,AAAA1');
assert(h.part('browse-live-placeholder').classList.contains('hidden'), 'placeholder gone');
assert.equal(h.part('browse-live-status').textContent, 'Bild 1');

// Mara thinks: badge with running seconds, the cursor reads along.
es.emit('step', {id: 5, kind: 'think', text: 'Mara liest die Seite und überlegt den nächsten Schritt (2 von 12)'});
assert(h.panel.classList.contains('thinking'), 'thinking class');
assert.equal(h.part('browse-live-think').hidden, false, 'badge visible');
assert.equal(h.part('browse-think-text').textContent, 'Mara überlegt', 'short in the picture');
assert.equal(h.part('browse-live-status').textContent, 'Mara liest die Seite und überlegt den nächsten Schritt (2 von 12)', 'full sentence below');
assert.equal(h.part('browse-think-time').textContent, '0 s');
advance(23000);
assert.equal(h.part('browse-think-time').textContent, '23 s', 'seconds keep running');
es.emit('step', {id: 6, kind: 'think', text: 'Mara überlegt noch, 15 s (2 von 12)'});
assert.equal(h.part('browse-think-time').textContent, '23 s', 'update keeps the timer');
advance(60000);
assert.equal(h.part('browse-think-time').textContent, '1 min 23 s');
// A fresh picture while thinking does not overwrite her status.
es.emit('frame', frame(2, {label: 'Mara liest die Seite'}));
advance(300);
assert.equal(h.part('browse-live-status').textContent, 'Mara überlegt noch, 15 s (2 von 12)');

// An action: thinking ends, the cursor glides first, then clicks.
es.emit('step', {id: 7, kind: 'step', text: 'Klicke auf „Suchen“'});
assert(!h.panel.classList.contains('thinking') && h.part('browse-live-think').hidden, 'badge gone on action');
es.emit('frame', frame(3, {cx: 320, cy: 90, click: true, label: 'Klick: Suchen'}));
advance(10);
const cursor = h.part('browse-ghost-cursor');
assert.equal(cursor.style.left, '25%');
assert.equal(cursor.style.top, '10%');
assert(!cursor.classList.contains('clicking'), 'no click before the cursor arrived');
advance(560);
assert(cursor.classList.contains('clicking'), 'click pulse after the glide');

// A blocked action colours the status.
es.emit('step', {id: 8, kind: 'warn', text: 'Nicht erlaubt: Anmeldeformulare fuellt Mara nicht aus'});
assert(h.panel.classList.contains('warned'));
es.emit('step', {id: 9, text: 'Öffne shop.example'});
assert(!h.panel.classList.contains('warned'), 'old lines without kind count as steps');

// The 15-minute cap: resume after the last picture and step, nothing twice.
es.emit('timeout', {note: 'stream_cap', after_frame: 3, after_step: 9});
assert(es.closed, 'old stream closed');
advance(250);
assert.equal(sources.length, 2);
es = sources[1];
assert.match(es.url, /after_frame=3&after_step=9$/, 'resumed at the right place');

// Network errors: reconnect with growing pauses, stop after six tries.
es.emit('error');
advance(1600);
assert.equal(sources.length, 3, 'reconnect after a network error');
assert.match(sources[2].url, /after_frame=3&after_step=9$/);
for (let i = 0; i < 6; i++) {
  sources[sources.length - 1].emit('error');
  advance(16000);
}
assert.equal(h.part('browse-live-state').textContent, 'GETRENNT', 'gives up after six tries');
assert.match(h.part('browse-live-status').textContent, /kommt trotzdem in den Chat/);
assert(h.panel.classList.contains('complete'));

// --- done and a real error --------------------------------------------------
h = harness();
es = sources[0];
es.emit('step', {id: 1, kind: 'think', text: 'Mara schreibt ihr Ergebnis auf'});
assert.equal(h.part('browse-think-text').textContent, 'Mara schreibt', 'writing the report');
es.emit('done', {status: 'done'});
assert.equal(h.part('browse-live-state').textContent, 'FERTIG ✓');
assert(es.closed && !h.panel.classList.contains('thinking') && h.part('browse-live-think').hidden, 'done stops everything');
const leftover = timers.length;
advance(5000);
assert.equal(sources.length, 1, 'no reconnect after done');
assert(leftover <= 1, 'no ticking timers left after done');

h = harness();
sources[0].emit('error', {status: 'error'});
assert.equal(h.part('browse-live-state').textContent, 'ABGESCHLOSSEN');
assert(!('s1' in h.state.pendingBySession) && h.calls.drained === 1 && h.calls.status.includes('error:RECHERCHEFEHLER'),
  'a failed job frees the chat');
advance(20000);
assert.equal(sources.length, 1, 'no reconnect after a real error');

h = harness();
sources[0].emit('error', {error: 'forbidden'});
advance(20000);
assert.equal(sources.length, 1, 'no reconnect when access is refused');

// --- styles -----------------------------------------------------------------
assert.match(css, /\.browse-live\.thinking \.browse-ghost-cursor \{\s*animation: browse-cursor-read/, 'cursor reads while thinking');
assert.match(css, /transition: left 520ms/, 'cursor glides');
assert.match(css, /\.browse-live-think\[hidden\] \{\s*display: none;/, 'badge hides');
assert.match(css, /\.pref-live-static \.browse-live\.thinking \.browse-ghost-cursor,/, 'still view without motion');

console.log('CoreAI browse live view: thinking with seconds, gliding cursor, queue, warnings, resume, reconnect, done OK');
