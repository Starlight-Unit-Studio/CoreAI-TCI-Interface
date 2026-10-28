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

// 0.5.67: a full local context window (3250 + 846 = 4096) is named as such,
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

// 0.5.67: an unreadable cloud tool reply names its bounded reason and the
// single self-correction, never the private model text.
const toolFix = work.create({events:[
  {id:67,kind:'tool',state:'error',title:'Werkzeugantwort nicht lesbar',
    meta:{reason:'multiple_tool_blocks',response_bytes:2777,model:'gemma4:31b-cloud'}},
  {id:68,kind:'model',state:'running',title:'Werkzeugantwort wird einmal korrigiert',
    meta:{reason:'multiple_tool_blocks'}},
  {id:69,kind:'tool',state:'success',title:'Werkzeugantwort korrigiert',
    meta:{reason:'tool_reply_corrected'}},
],expanded:true});
assert(toolFix.textContent.includes('mehrere Werkzeugblöcke in einer Antwort')
  && toolFix.textContent.includes('Daraus führe ich nichts aus')
  && toolFix.textContent.includes('einmal in der vorgesehenen Form neu')
  && toolFix.textContent.includes('Ich setze sie jetzt um'),
  'tool reply correction must be narrated with its reason');

// 0.5.68: Claude-Code-like flow. Routine steps fold into one muted line per
// sequence, Mara's narration stays readable text, a task card lists files.
const flow = work.create({events:[
  {id:201,kind:'run',state:'info',title:'Auftrag gestartet',meta:{}},
  {id:202,kind:'model',state:'running',title:'Modellstream gestartet',meta:{model:'gemma4:31b-cloud'}},
  {id:203,kind:'tool',state:'info',title:'Projektdateien geplant',
    meta:{file_count:3,file_list:['index.html','style.css','main.js','../evil']}},
  {id:204,kind:'tool',state:'info',title:'Einzeldatei angefordert',meta:{file_name:'index.html',attempt:1}},
  {id:205,kind:'model',state:'success',title:'Quelldatei empfangen',
    meta:{file_name:'index.html',eval_count:1200}},
  {id:206,kind:'tool',state:'info',title:'Einzeldatei angefordert',meta:{file_name:'style.css',attempt:1}},
],expanded:true});
const flowEntries = flow.querySelector('.work-entries');
assert.equal(flowEntries.children.length,6,'every event keeps exactly one entry');
const firstRun = flowEntries.children[0];
assert(firstRun.className.includes('work-run-head'),'a routine sequence starts a folded run');
assert.equal(firstRun.querySelector('.work-run-toggle').textContent,'2 Schritte · Core arbeitet▸');
assert.equal(flowEntries.children[1].hidden,true,'folded steps stay hidden');
firstRun.querySelector('.work-run-toggle').handlers.click();
assert.equal(flowEntries.children[1].hidden,false,'expanding a run shows each step');
assert(flowEntries.children[2].className.includes('work-narration'),'a planned file list is narrated');
assert(flow.textContent.includes('Ich erstelle 3 Dateien und prüfe jede einzeln: index.html, style.css, main.js.'));
assert(!flow.textContent.includes('../evil'),'only safe basenames reach the file list');
assert(flowEntries.children[3].querySelector('.work-run-toggle').textContent
  .startsWith('3 Schritte · Einzeldatei angefordert · style.css'),'a new run follows the narration');
const tasksCard = flow.querySelector('.work-tasks');
assert.equal(tasksCard.hidden,false,'two or more files show a task card');
assert(tasksCard.textContent.includes('Projektdateien · 1 von 3 fertig'));
assert(tasksCard.querySelector('.work-task-done').textContent.includes('index.html'));
assert(tasksCard.querySelector('.work-task-running').textContent.includes('style.css'));
assert(tasksCard.querySelector('.work-task-pending').textContent.includes('main.js'));
const liveStatus = work.create({live:true,expanded:true,startedAt:Date.now() - 125000});
work.add(liveStatus.querySelector('.work-body'),{id:220,kind:'model',state:'running',
  title:'Quelldatei wird erzeugt',meta:{file_name:'gallery.html',eval_count:4200}});
assert(liveStatus.querySelector('.work-status').textContent
  .includes('Schreibe gallery.html · 2 Min. 5 Sek. · 4,2k Tokens'),
  'the live status names the activity, elapsed time and tokens');
assert.equal(saved.querySelector('.work-status'),null,'a finished reply has no live status line');

// Issue #115 and Starlight lore: the interface says "Core", never "Modell"
// or "Core-Aufruf". Internal event names stay unchanged.
const coreTitles = ['Modellstream vorbereitet','Modellstream gestartet','Modellaufruf gestartet',
  'Modellstream empfangen','Modellantwort empfangen','Modellstream abgeschnitten',
  'Modellstream fehlgeschlagen','Modellaufruf ohne Antwort','Modellstream unvollstaendig',
  'Modellwarteschlange nicht verfuegbar','Modellprobe kuenftig'];
const coreWording = work.create({expanded:true,events:coreTitles.map((title,n)=>({
  id:400+n,kind:'model',state:n%3 ? 'info' : 'error',title,meta:{model:'gemma4:31b-cloud'}}))});
assert(!/Modell|Core-Aufruf|Core-Übertragung/.test(coreWording.textContent),
  'no "Modell" or "Core-Aufruf" anywhere in the visible work history');
assert(coreWording.textContent.includes('Ich bereite Core vor. Eine Antwort liegt noch nicht vor.')
  && coreWording.textContent.includes('Core arbeitet jetzt. Ich warte auf das vollständige Ergebnis.')
  && coreWording.textContent.includes('Core hat keine bestätigte vollständige Antwort geliefert. Ich prüfe die Ursache.'),
  'the #115 sentences are phrased naturally');
assert.equal(work.displayTitle('Modellstream gestartet'),'Core arbeitet');
assert.equal(work.normalize({id:1,kind:'model',state:'info',title:'Modellstream gestartet'}).title,
  'Modellstream gestartet','the stored event name itself is not renamed');

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
// 0.5.68: only Mara's narration is typed; routine steps fold at once.
const taskTitles = ['Mara hat die Umsetzung des gespeicherten Plans gewaehlt',
  'Freigegebenes Webprojekt im Quelltextmodus gestartet',
  'Homepage-Projektdateien erkannt','Projektquellen vollstaendig'];
taskTitles.forEach((title,n) => work.add(queuedBody,{
  id:101+n,kind:'run',state:'info',title,meta:{}
}));
assert.equal(queuedBody.querySelector('.work-entries').children.length,1,
  'a burst may display only its first step initially');
const firstNote = queuedBody.querySelector('.work-note-text');
assert.equal(firstNote.textContent,'','first explanation begins empty');
assert.equal(work.add(queuedBody,{id:104,kind:'run',state:'info',
  title:'Projektquellen vollstaendig',meta:{}}),false,
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
assert(queuedBody.textContent.includes('Ich setze den bereits gespeicherten Projektplan um')
  && queuedBody.textContent.includes('Alle angeforderten Quelltexte sind vorhanden'),
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
assert(finishedHistory.textContent.includes('Projektquellen vollstaendig'),
  'saved history renders synchronously and in order');
// 0.5.76: a plain answer shows no Arbeitsweg, real work and errors do.
const plain = [
  {id: 1, kind: 'run', state: 'running', title: 'Auftrag gestartet', meta: {}},
  {id: 2, kind: 'run', state: 'success', title: 'Kontext vorbereitet', meta: {}},
  {id: 3, kind: 'tool', state: 'info', title: 'Privates Wissen durchsucht', meta: {knowledge_hits: 0, knowledge_sources: 0}},
  {id: 4, kind: 'model', state: 'running', title: 'Modellstream gestartet', meta: {}},
  {id: 5, kind: 'run', state: 'success', title: 'Antwort gespeichert', meta: {}},
];
assert.equal(work.substantive(plain), false, 'a plain answer needs no Arbeitsweg');
const withHits = plain.map(e => e.id === 3
  ? {...e, state: 'success', meta: {knowledge_hits: 4, knowledge_sources: 2}} : e);
assert.equal(work.substantive(withHits), true, 'private knowledge hits are shown');
assert.equal(work.substantive([...plain, {id: 6, kind: 'tool', state: 'error', title: 'Werkzeugantwort nicht lesbar',
  meta: {reason: 'tool_marker_without_block'}}]), true, 'errors are shown');
assert.equal(work.substantive([...plain, {id: 7, kind: 'artifact', state: 'success', title: 'Downloaddateien gesichert',
  meta: {}}]), true, 'files are shown');
assert.equal(work.substantive([...plain, {id: 8, kind: 'model', state: 'running', title: 'Werkzeugantwort wird einmal korrigiert',
  meta: {}}]), true, 'a correction round is shown');
assert.equal(work.substantive([...plain, {id: 9, kind: 'model', state: 'running', title: 'Anhang wird angesehen',
  meta: {}}]), true, 'looking at an attachment is shown');
assert.equal(work.substantive([]), false);
const app = fs.readFileSync(path.join(__dirname, '../js/console-app.js'), 'utf8');
assert(app.includes('window.CoreUIWorkEvents.substantive(record.work_events)'),
  'finished replies only get an Arbeitsweg with real work');

sandbox.window.setTimeout = undefined;
sandbox.window.clearTimeout = undefined;
sandbox.window.matchMedia = undefined;

console.log('P06 inline work-history UI selftest: OK');
