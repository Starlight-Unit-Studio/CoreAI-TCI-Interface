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
assert(live.textContent.includes('E.M.B.E.R. CoreAI arbeitet'),
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
assert(steps.textContent.includes('Modell-Endgrundlength')
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

console.log('P06 inline work-history UI selftest: OK');
