/* Offline checks for the Core, Kontext, Werkzeuge and Projekt info boxes. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tagName = tag.toUpperCase();
    this.children = [];
    this.attributes = {};
    this.className = '';
    this.handlers = {};
    this.hidden = false;
    this.style = {};
    this._text = '';
    const self = this;
    this.classList = {
      toggle(name, on) {
        const names = self.className.split(' ').filter(Boolean).filter(n => n !== name);
        if (on) names.push(name);
        self.className = names.join(' ');
      },
      contains(name) { return self.className.split(' ').includes(name); }
    };
  }
  set textContent(text) { this._text = String(text); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
  appendChild(child) { this.children.push(child); return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  all(cls, out = []) {
    if (this.className.split(' ').includes(cls)) out.push(this);
    this.children.forEach(child => child.all(cls, out));
    return out;
  }
  one(cls) { return this.all(cls)[0] || null; }
}

const store = {};
const responses = [];
const sandbox = {
  window: {
    localStorage: {
      getItem(key) { return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null; },
      setItem(key, value) { store[key] = String(value); }
    },
    fetch(url) {
      responses.push(url);
      const session = decodeURIComponent(String(url).split('session_id=')[1] || '');
      return Promise.resolve({json: () => Promise.resolve(session === 'sessionAAAA1' ? snapshot : {
        ok:true, session_id:session, core:{model:'other'}, tools:[], files:[]
      })});
    }
  },
  document: {createElement: tag => new Element(tag)},
  Promise
};
const snapshot = {
  ok:true, session_id:'sessionAAAA1', session_title:'Homepage',
  core:{model:'ember-coreui:latest', runtime:'local', context_tokens:131072,
    context_source:'modelfile', thinking:true},
  usage:{prompt_tokens:2833, output_tokens:1373, context_tokens:131072},
  tools:[{id:'web',label:'WEB',detail:'SearXNG-Suche',on:true},
    {id:'browse',label:'BROWSE',detail:'Browser-Recherche',on:false}],
  skills:{required:['werkzeuge'], library:32},
  plan:{version:3, title:'Private Homepage', draft:false, size_bytes:7120,
    name:'projektplan-v3.md', download_url:'api/console_plan.php?id=abc'},
  files:[{name:'projekt.zip', size_bytes:15260, download_url:'api/console_artifact.php?id=1'},
    {name:'evil.html', size_bytes:10, download_url:'javascript:alert(1)'}]
};

const source = fs.readFileSync(path.join(__dirname, '../js/coreui-insights.js'), 'utf8');
assert(!/innerHTML|insertAdjacentHTML|outerHTML/.test(source), 'info boxes never inject HTML');
vm.runInNewContext(source, sandbox);

(async () => {
  const aside = new Element('aside');
  const chips = new Element('div');
  const insights = sandbox.window.CoreUIInsights.create({aside, chips, apiBase:'/coreai/api'});
  assert.equal(aside.all('insight-box').length, 4, 'four info boxes in the right column');
  assert.equal(chips.all('insight-chip').length, 4, 'four chip buttons on narrow screens');
  assert(aside.textContent.includes('Lade Core-Status'), 'an honest loading state, no invented values');

  await insights.refresh('sessionAAAA1');
  assert.equal(responses[0], '/coreai/api/console_insights.php?session_id=sessionAAAA1');
  const text = aside.textContent;
  assert(text.includes('ember-coreui:latest') && text.includes('Lokal über Ollama')
    && text.includes('131.072 Tokens · Modelfile'), 'Core box shows model, runtime and window source');
  assert(text.includes('3,2 % genutzt') && text.includes('4,2k / 131,1k'),
    'Kontext box shows the share of the window');
  assert(text.includes('Eingabe2.833') && text.includes('Ausgabe1.373'), 'Kontext legend counts');
  assert(text.includes('WEBSearXNG-Suche') && text.includes('BROWSEaus'), 'tool states are explicit');
  assert(text.includes('Skills: Werkzeuge (Pflicht), 32 weitere wählt Mara selbst'));
  const links = aside.all('insight-file-name');
  const plan = links.find(link => link.textContent === 'projektplan-v3.md');
  assert.equal(plan.href, 'api/console_plan.php?id=abc', 'plan download link');
  const evil = links.find(link => link.textContent === 'evil.html');
  assert.equal(evil.tagName, 'SPAN', 'an unsafe URL never becomes a link');
  assert.equal(evil.href, undefined);

  // Live work events update the Kontext box before the reply is saved.
  insights.observe({meta:{prompt_eval_count:'60000', eval_count:'5536', context_tokens:'131072',
    model:'ember-coreui:latest'}});
  assert(aside.textContent.includes('50 % genutzt'), 'live usage replaces the saved snapshot');

  // Cloud models: the provider owns the window, no invented percentage.
  insights.observe({meta:{model:'gpt-oss:120b-cloud', prompt_eval_count:'900', eval_count:'100'}});
  assert(aside.textContent.includes('Cloud über Ollama') && aside.textContent.includes('vom Cloud-Anbieter'));
  assert(!aside.textContent.includes('% genutzt'), 'no percentage for a cloud window');

  // Collapsing a box is remembered per viewer.
  const toolsHead = aside.all('insight-head')[2];
  toolsHead.handlers.click();
  assert.equal(JSON.parse(store['coreai.insights.open']).tools, true, 'open state persisted');

  // Chips open exactly one box as a sheet and close it again.
  const chipList = chips.all('insight-chip');
  chipList[1].handlers.click();
  const sheet = chips.one('insight-sheet');
  assert.equal(sheet.hidden, false);
  assert.equal(sheet.all('insight-box').length, 1);
  assert(sheet.textContent.includes('Eingabe'), 'the Kontext chip opens the Kontext box');
  assert.equal(chipList[1].getAttribute('aria-expanded'), 'true');
  chipList[3].handlers.click();
  assert(sheet.textContent.includes('projekt.zip'), 'another chip switches the sheet');
  chipList[3].handlers.click();
  assert.equal(sheet.hidden, true, 'a second tap closes the sheet');

  // A late answer for a previous session must not overwrite the new one.
  const late = insights.refresh('sessionAAAA1');
  await insights.refresh('sessionBBBB2');
  await late;
  assert(aside.textContent.includes('other') && !aside.textContent.includes('ember-coreui:latest'),
    'stale session data is ignored');

  // A failed request (for example a missing nginx route) never stays "loading".
  const brokenAside = new Element('aside');
  const broken = sandbox.window.CoreUIInsights.create({aside:brokenAside, apiBase:'/coreai/api'});
  const realFetch = sandbox.window.fetch;
  sandbox.window.fetch = () => Promise.resolve({json: () => Promise.reject(new Error('404 html'))});
  await broken.refresh('sessionCCCC3');
  sandbox.window.fetch = realFetch;
  assert(brokenAside.textContent.includes('gerade nicht erreichbar')
    && !brokenAside.textContent.includes('Lade Werkzeuge'), 'an unreachable endpoint is reported');

  // #113: a KI-Profil window is named as such; a differently loaded runner
  // is shown separately instead of being mixed up with the used window.
  const profileAside = new Element('aside');
  const profile = sandbox.window.CoreUIInsights.create({aside:profileAside, apiBase:'/coreai/api'});
  sandbox.window.fetch = () => Promise.resolve({json: () => Promise.resolve({ok:true, session_id:'sessionDDDD4',
    core:{model:'ember-coreui:latest', runtime:'local', context_tokens:32768, context_source:'profile',
      context_loaded:131072, thinking:true}, tools:[], files:[]})});
  await profile.refresh('sessionDDDD4');
  sandbox.window.fetch = realFetch;
  assert(profileAside.textContent.includes('32.768 Tokens · KI-Profil'), 'profile source named');
  assert(profileAside.textContent.includes('Von Ollama geladen131.072 Tokens'), 'loaded window shown apart');
  profile.observe({meta:{model:'ember-coreui:latest', context_tokens:'32768', prompt_eval_count:'30000',
    eval_count:'2768'}});
  assert(profileAside.textContent.includes('32.768 Tokens · KI-Profil'), 'a full profile window keeps its source');

  console.log('CoreAI info boxes: Core, Kontext, Werkzeuge, Projekt, chips and live usage OK');
})().catch(error => { console.error(error); process.exit(1); });
