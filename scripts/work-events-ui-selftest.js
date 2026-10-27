'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.attributes = {};
    this.className = '';
    this.handlers = {};
    this.hidden = false;
    this._text = '';
  }
  set textContent(text) {
    this._text = String(text);
    this.children = [];
  }
  get textContent() {
    return this._text + this.children.map(child => child.textContent).join('');
  }
  appendChild(child) {
    this.children.push(child);
    return child;
  }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  addEventListener(name, handler) { this.handlers[name] = handler; }
  querySelector(selector) {
    if (selector.startsWith('.')
        && this.className.split(' ').includes(selector.slice(1))) return this;
    if (selector.startsWith('[data-work-id=')
        && this.attributes['data-work-id'] === selector.match(/"([^"]+)"/)[1]) return this;
    for (const child of this.children) {
      const result = child.querySelector(selector);
      if (result) return result;
    }
    return null;
  }
  get firstChild() { return this.children[0]; }
  removeChild(child) { this.children.splice(this.children.indexOf(child), 1); }
}

const sandbox = {
  window: {},
  document: {createElement: tag => new Element(tag)},
  fetch: () => { throw new Error('Never perform separate work-history HTTP requests'); },
};
vm.runInNewContext(fs.readFileSync(
  path.join(__dirname, '../js/coreui-work-events.js'), 'utf8'), sandbox);
const work = sandbox.window.CoreUIWorkEvents;
assert.equal(work.create({events: []}), null, 'no empty message card');
const timeline = work.create({
  expanded:false,
  events:[
    {id:1,kind:'tool',state:'running',title:'Python-Job eingereiht',
      meta:{job_id:13},time:'2026-10-20T21:34:07+00:00'},
    {id:2,kind:'tool',state:'error',title:'Python-Ausfuehrung fehlgeschlagen',
      meta:{exit_code:1,error_type:'FileNotFoundError',stderr:'SECRET'},time:'2026-10-20T21:34:09+00:00'},
  ],
});
assert(timeline);
const body = timeline.querySelector('.work-body');
assert.equal(body.hidden,true);
assert.equal(body.querySelector('.work-entries').children.length,2);
assert(!timeline.textContent.includes('SECRET'), 'never expose raw stderr');
const toggle = timeline.querySelector('.work-toggle');
toggle.handlers.click();
assert.equal(body.hidden,false);
toggle.handlers.click();
assert.equal(body.hidden,true);
work.add(body,{id:2,kind:'tool',state:'error',title:'Duplicated'});
assert.equal(body.querySelector('.work-entries').children.length,2,'SSE dedup');
work.add(body,{id:3,kind:'artifact',state:'info',
  title:'<script>alert(1)</script>',meta:{error_type:'output_missing'}});
assert(timeline.textContent.includes('<script>alert(1)</script>'));
assert.equal(body.querySelector('.work-entries').children.length,3);
const live=work.create({live:true,expanded:true});
assert(live.textContent.includes('Arbeitsweg · laufend'),
  'live operational header must identify CoreAI rather than terminal');
assert(live && !live.querySelector('.work-body').hidden);
work.add(live.querySelector('.work-body'),{
  id:4,kind:'artifact',state:'success',title:'Datei gesichert',meta:{file_count:3}
});
assert(live.textContent.includes('Datei gesichert'));
work.add(live.querySelector('.work-body'),{
  id:5,kind:'model',state:'error',title:'JavaScript-Ausgabe abgeschnitten',
  meta:{file_name:'game.js',source_chars:4203,eval_count:1900,
    prompt_eval_count:733,code:'NEVER_DISPLAY_PRIVATE_SOURCE'}
});
assert(live.textContent.includes('Quelltext (Zeichen)4203'),
  'safe source length must be visible in technical details');
assert(live.textContent.includes('Ausgabe-Tokens1900')
  && live.textContent.includes('Eingabe-Tokens733'),
  'Ollama context/output counters must be visible');
assert(!live.textContent.includes('NEVER_DISPLAY_PRIVATE_SOURCE'),
  'private model source must be excluded by the work UI whitelist');
// The final answer replaces the live row, but the sanitized SSE events must
// remain available even when the first authenticated history response is empty.
const recovered = work.merge([], [
  {id:31,kind:'run',state:'running',title:'Auftrag gestartet',
    meta:{stderr:'SECRET'},time:'2026-10-21T08:00:00Z'},
  {id:32,kind:'model',state:'success',title:'Modellantwort erhalten',
    meta:{model:'gpt-oss:120b'},time:'2026-10-21T08:00:02Z'},
]);
assert.equal(recovered.length,2,'SSE snapshot preserved');
assert(!JSON.stringify(recovered).includes('SECRET'),'snapshot is sanitized');
const serverFirst = work.merge([
  {id:31,kind:'run',state:'success',title:'Serverseitig bestaetigt',
    meta:{},time:'2026-10-21T08:00:03Z'}
],recovered);
assert.equal(serverFirst.length,2,'server and live events deduplicated');
assert.equal(serverFirst[0].title,'Serverseitig bestaetigt','server wins duplicate ID');
const saved = work.create({events:serverFirst,expanded:true});
assert(saved,'completed message keeps its timeline');
assert.equal(saved.querySelector('.work-body').hidden,false,'just-finished work stays open');
assert(saved.textContent.includes('Arbeitsweg (2 Schritte) ausblenden'));
saved.querySelector('.work-toggle').handlers.click();
assert(saved.textContent.includes('Arbeitsweg (2 Schritte) anzeigen'));
assert.equal(saved.querySelector('.work-body').hidden,true);
const reloaded = work.create({events:serverFirst,expanded:false});
assert(reloaded.textContent.includes('Arbeitsweg (2 Schritte) anzeigen'),
  'history after reload has an explicit reopen control');
// 0.5.56: Live and saved work should explain the next action and the blockers
// in plain, first-person public notes. These are derived from confirmed events,
// never private model reasoning, tool code, stderr, or prompts.
const steps = work.create({events:[
  {id:60,kind:'tool',state:'info',title:'Einzeldatei angefordert',
    meta:{file_name:'index.html',attempt:1,secret_prompt:'NOT_PUBLIC'}},
  {id:61,kind:'model',state:'error',title:'Quelldatei nicht lesbar',
    meta:{file_name:'index.html',done_reason:'length',thinking_chars:1900,
      raw_thinking:'NEVER_SHOW_INTERNAL_THOUGHTS'}},
],expanded:false});
assert(steps.textContent.includes('Ich fordere „index.html“ an'),
  'public progress note must explain the concrete file action');
assert(steps.textContent.includes('fehlt noch ein nutzbares Ergebnis'),
  'file blocker must be explained in first person');
assert(steps.querySelector('.work-digest').textContent.includes('index.html'),
  'collapsed timeline keeps last useful public diagnostic');
assert(steps.querySelector('.work-counts').textContent.includes('2 Schritte'),
  'collapsed summary must show work volume');
assert(!steps.textContent.includes('NEVER_SHOW_INTERNAL_THOUGHTS')
  && !steps.textContent.includes('NOT_PUBLIC'),
  'no private model thinking/prompt content in narrative');
assert(steps.textContent.includes('Core-Endgrundlength')
  && steps.textContent.includes('Denkstatus (Zeichen)1900'),
  'new bounded diagnostic fields are available after opening');
steps.querySelector('.work-toggle').handlers.click();
assert(steps.querySelector('.work-digest').hidden,
  'expanded steps must not duplicate the condensed preview');
const liveNarration = work.create({live:true,expanded:true,events:[]});
work.add(liveNarration.querySelector('.work-body'),
  {id:62,kind:'tool',state:'running',title:'Python-Job eingereiht',meta:{job_id:123}});
assert(liveNarration.textContent.includes('isolierten Python-Worker'),
  'live SSE event must update first-person progress');
assert(liveNarration.querySelector('.work-counts').textContent.includes('1 Schritt'),
  'live counter must update when a new event arrives');
let manuallyOpened = false;
const toggled = work.create({events:[{id:63,kind:'run',state:'success',
  title:'Antwort gespeichert'}],onToggle:function(open){manuallyOpened=open;}});
toggled.querySelector('.work-toggle').handlers.click();
assert.equal(manuallyOpened,true,'caller can remember user disclosure choice');

// 0.5.66: a full local context window (3250 + 846 = 4096) is named as such,
// with its size, instead of a generic "abgeschnitten".
const windowFull = work.create({events:[
  {id:64,kind:'tool',state:'info',title:'Kontextfenster ermittelt',
    meta:{context_tokens:4096,model:'ember-coreui:latest'}},
  {id:65,kind:'model',state:'error',title:'Quelldatei nicht lesbar',
    meta:{file_name:'index.html',reason:'context_window_full',context_tokens:4096,
      eval_count:846,prompt_eval_count:3250,done_reason:'length'}},
  {id:66,kind:'tool',state:'error',title:'Unvollstaendiger Python-Code nicht ausgefuehrt',
    meta:{reason:'output_truncated'}},
],expanded:true});
assert(windowFull.textContent.includes('Kontextfenster (4096 Tokens)'),
  'the detected window size must be explained');
assert(windowFull.textContent.includes('Das Kontextfenster von Core war voll (4096 Tokens)'),
  'a full window must not be shown as a generic truncation');
assert(windowFull.textContent.includes('Kontextfenster (Tokens)4096'),
  'the window size must be available in the details');
assert(windowFull.textContent.includes('führe es deshalb nicht aus'),
  'blocked incomplete Python must be explained truthfully');

const futureTool = work.create({events:[{id:71,kind:'tool',state:'running',
  title:'Neuen Repository-Pruefschritt gestartet'}],expanded:false});
assert(futureTool.textContent.includes('Ich führe gerade „Neuen Repository-Pruefschritt gestartet“ aus'),
  'future tool events must receive a generic evidence-based explanation');


// 0.5.56: optional in-band, genuinely public commentary must be
// separate from automatically explained server events.
const modelPublic = work.create({events:[{
  id:72,kind:'commentary',state:'info',title:'Mara meldet ihren naechsten Schritt',
  meta:{public_note:'Ich pruefe zuerst die vorhandene HTML-Datei.',
    source:'model_public_text',raw_thinking:'DO_NOT_LEAK'}
}],expanded:false});
assert(modelPublic && modelPublic.textContent.includes('Ich pruefe zuerst die vorhandene HTML-Datei.'));
assert(!modelPublic.textContent.includes('Öffentliche Zwischenmeldung'),
  'only public prose appears, without provenance badge');
assert(!modelPublic.textContent.includes('DO_NOT_LEAK'));
assert(!modelPublic.textContent.includes('Automatisch erläutert'));
assert.equal(work.normalize({id:73,kind:'commentary',state:'info',
  title:'Mara meldet ihren naechsten Schritt',meta:{raw_thinking:'SECRET'}}),null);
assert.equal(modelPublic.querySelector('.work-chevron').textContent,'▸',
  'toggle glyph must be rendered, not escaped');

// 0.5.62: actual source-code chunks display below the same live work
// timeline. They are never loaded into saved events or rendered as HTML.
const liveCode = work.create({live:true,expanded:true});
const liveCodeBody = liveCode.querySelector('.work-body');
assert.equal(work.source(liveCodeBody,{phase:'begin',file_name:'index.html',attempt:1}),true);
assert.equal(work.source(liveCodeBody,{phase:'chunk',file_name:'index.html',
  attempt:1,text:'<!DOCTYPE html>\\n<html lang="de">'}),true);
assert(liveCode.textContent.includes('<!DOCTYPE html>') && liveCode.textContent.includes('index.html'));
assert.equal(work.source(liveCodeBody,{phase:'chunk',file_name:'../passwd',attempt:1,text:'SECRET'}),false);
assert.equal(work.source(liveCodeBody,{phase:'chunk',file_name:'index.html',
  attempt:1,text:'<script>not-html-executed</script>'}),true);
assert(liveCode.textContent.includes('<script>not-html-executed</script>'));
assert(!liveCode.textContent.includes('SECRET'));
assert.equal(work.source(liveCodeBody,{phase:'end',file_name:'index.html',attempt:1}),true);
assert(liveCode.textContent.includes('Prüfung läuft'));
const oldCodePanel=liveCodeBody.querySelector('.work-source');
assert.equal(work.source(liveCodeBody,{phase:'begin',file_name:'styles.css',attempt:1}),true);
assert.equal(liveCodeBody.querySelector('.work-source-file').textContent,'styles.css');
assert(!liveCode.textContent.includes('not-html-executed'), 'preview replaces previous file');
assert.equal(work.source(liveCodeBody,{phase:'chunk',file_name:'index.html',attempt:1,text:'stale'}),false);
const history=work.create({events:[{id:180,kind:'model',state:'success',
  title:'Modellstream empfangen',meta:{model:'gpt-oss:120b'}}],expanded:true});
assert(!history.querySelector('.work-source'), 'private source preview is not reloaded as history');

// 0.5.60: SSE bursts must reveal one work event at a time, writing the
// complete safe public note before displaying the next event. Storage is not
// delayed, only its visual presentation.
const timers = [];
let timerId = 0;
sandbox.window.setTimeout = fn => {
  const timer = {id:++timerId,fn,cancelled:false};
  timers.push(timer);
  return timer.id;
};
sandbox.window.clearTimeout = id => {
  const entry = timers.find(timer => timer.id === id);
  if (entry) entry.cancelled = true;
};
sandbox.window.matchMedia = () => ({matches:false});
function tickWork(max = 1500) {
  let count = 0;
  while (timers.length && count < max) {
    const timer = timers.shift();
    if (!timer.cancelled) timer.fn();
    count++;
  }
  assert(count < max,'typing animation must terminate');
}
const queued = work.create({live:true,expanded:true});
const queuedBody = queued.querySelector('.work-body');
const taskTitles = ['Auftrag gestartet','Kontext vorbereitet',
  'Modellstream vorbereitet','Modellstream gestartet'];
taskTitles.forEach((title,n) => work.add(queuedBody,{
  id:101+n,kind:'run',state:'info',title,meta:{}
}));
assert.equal(queuedBody.querySelector('.work-entries').children.length,1,
  'a burst may display only its first step initially');
const firstNote = queuedBody.querySelector('.work-note-text');
assert.equal(firstNote.textContent,'','first explanation begins empty');
assert.equal(work.add(queuedBody,{id:104,kind:'run',state:'info',
  title:'Modellstream gestartet',meta:{}}),false,
  'SSE duplicates already pending must not produce two rows');
assert.equal(queuedBody._workQueue.length,3,'remaining steps stay queued');
const firstTick = timers.shift();
assert(firstTick && !firstTick.cancelled);
firstTick.fn();
assert(firstNote.textContent.length>0
  && firstNote.textContent.length<90,'safe note appears gradually');
assert.equal(queuedBody.querySelector('.work-entries').children.length,1,
  'second event must wait until first note is complete');
tickWork();
assert.equal(queuedBody.querySelector('.work-entries').children.length,4);
assert(queuedBody.textContent.includes('Ich übernehme den Auftrag')
  && queuedBody.textContent.includes('Ich bereite den Core-Aufruf vor'),
  'typed steps must retain complete safe narratives');
assert(queued.querySelector('.work-counts').textContent.includes('4 Schritte'));
assert.equal(queuedBody._workQueue.length,0,'no SSE events silently discarded');

// Collapse during an animation: finish all queued notes immediately.
const collapsedLive = work.create({live:true,expanded:true});
const collapsedBody = collapsedLive.querySelector('.work-body');
taskTitles.forEach((title,n) => work.add(collapsedBody,{
  id:121+n,kind:'run',state:'info',title,meta:{}
}));
collapsedLive.querySelector('.work-toggle').handlers.click();
assert(collapsedBody.hidden);
assert.equal(collapsedBody.querySelector('.work-entries').children.length,4);
assert(!collapsedBody.textContent.includes('SECRET'));
assert.equal(collapsedBody._workQueue.length,0);
tickWork();

// Motion-accessibility: on reduced-motion devices, all steps render without
// an animation and saved work never retypes after a reload.
sandbox.window.matchMedia = () => ({matches:true});
const reduced = work.create({live:true,expanded:true});
const reducedBody = reduced.querySelector('.work-body');
taskTitles.forEach((title,n) => work.add(reducedBody,{
  id:141+n,kind:'run',state:'info',title,meta:{}
}));
assert.equal(reducedBody.querySelector('.work-entries').children.length,4);
assert.equal(timers.length,0);
const finishedHistory = work.create({expanded:true,events:taskTitles.map((title,n)=>({
  id:141+n,kind:'run',state:'info',title,meta:{}
}))});
assert(finishedHistory.textContent.includes('Modellstream gestartet'),
  'saved history renders synchronously and in order');
sandbox.window.setTimeout = undefined;
sandbox.window.clearTimeout = undefined;
sandbox.window.matchMedia = undefined;

console.log('P06 inline work-history UI selftest: OK');
