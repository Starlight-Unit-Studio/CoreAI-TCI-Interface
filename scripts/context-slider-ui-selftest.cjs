/* Offline checks for the KI-Profil context window slider (#113). */
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
    this.disabled = false;
    this.checked = false;
    this.value = '';
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
}

const source = fs.readFileSync(path.join(__dirname, '../js/coreui-context-slider.js'), 'utf8');
assert(!/innerHTML|insertAdjacentHTML|outerHTML/.test(source), 'the slider never injects HTML');
assert(!/Modell(?!file)/.test(source.replace(/\/\*[\s\S]*?\*\//g, '')), 'lore: visible text says Core');

const sandbox = {window: {}, document: {createElement: tag => new Element(tag)}, Promise, encodeURIComponent};
vm.runInNewContext(source, sandbox);
const api = sandbox.window.CoreUIContextSlider;

const GIB_STOPS = [[16, 21], [16, 22], [17, 23], [18, 24], [20, 27], [25, 33]];
function context(model, extra) {
  return Object.assign({model, applicable:true, method:'metadata', memory_label:'RAM', processor:'cpu',
    loaded_context:131072, default_context:131072, available_gb:94,
    stops:[4096, 8192, 16384, 32768, 65536, 131072].map((tokens, i) => ({tokens,
      low_gb:GIB_STOPS[i][0], high_gb:GIB_STOPS[i][1], warn:false})),
    default:{tokens:131072, low_gb:25, high_gb:33, warn:false}}, extra || {});
}

(async () => {
  const nodes = {root:new Element('div'), range:new Element('input'), output:new Element('output'),
    useDefault:new Element('input'), stops:new Element('ol'), estimate:new Element('p'),
    warning:new Element('p'), status:new Element('p')};
  const calls = [];
  const answers = {};
  const slider = api.create(Object.assign({}, nodes, {fetchJson(url) {
    calls.push(url);
    return new Promise(resolve => { answers[url] = resolve; });
  }}));

  // Existing accounts: Core-Standard, nothing is sent.
  slider.apply(null, [4096, 8192, 16384, 32768, 65536, 131072], true);
  assert.equal(slider.value(), null, 'default accounts keep the Core default');
  assert.equal(nodes.useDefault.checked, true);
  assert.equal(nodes.range.disabled, true, 'slider locked while the Core default is used');
  assert.equal(nodes.output.textContent, 'Core-Standard');

  const loading = slider.load('ember-coreui:latest');
  assert.equal(calls[0], './api/user_settings.php?context_model=ember-coreui%3Alatest');
  assert(nodes.stops.textContent.includes('wird ermittelt'), 'honest loading state');
  answers[calls[0]]({ok:true, context:context('ember-coreui:latest')});
  await loading;

  // Every stop carries its own guide value, also the unselected ones.
  const items = nodes.stops.all('ctx-stop');
  assert.equal(items.length, 6, 'six stops');
  ['4K', '8K', '16K', '32K', '64K', '128K'].forEach((label, i) => {
    assert(items[i].textContent.startsWith(label), 'stop label ' + label);
    assert(items[i].textContent.includes('etwa ' + GIB_STOPS[i][0] + ' bis ' + GIB_STOPS[i][1] + ' GB RAM'),
      'guide value at ' + label);
  });
  assert(nodes.estimate.textContent.includes('Core-Standard: 131.072 Tokens · geschätzt: etwa 25 bis 33 GB RAM'),
    'the default shows its own estimate');
  assert(nodes.status.textContent.includes('gewählt: Core-Standard')
    && nodes.status.textContent.includes('für Anfragen verwendet: 131.072 Tokens')
    && nodes.status.textContent.includes('von Ollama geladen: 131.072 Tokens'), 'three separate facts');

  // Choosing a window: every stop can be selected.
  nodes.useDefault.checked = false;
  nodes.useDefault.handlers.change();
  assert.equal(nodes.range.disabled, false);
  assert.equal(slider.value(), 131072, 'switching off the default starts at the default window');
  for (const [i, tokens] of [4096, 8192, 16384, 32768, 65536, 131072].entries()) {
    nodes.range.value = String(i);
    nodes.range.handlers.input();
    assert.equal(slider.value(), tokens, 'slider value ' + tokens);
    assert.equal(nodes.output.textContent, api.shortTokens(tokens));
    assert(nodes.estimate.textContent.startsWith('geschätzt: etwa ' + GIB_STOPS[i][0] + ' bis '),
      'plain text estimate below the value at ' + tokens);
  }
  assert(nodes.status.textContent.includes('ungespeicherte Änderung'), 'unsaved choice is marked');
  nodes.stops.all('ctx-stop-button')[1].handlers.click();
  assert.equal(slider.value(), 8192, 'tapping a stop selects it (touch, DeX)');

  // Saved value comes back identical.
  slider.apply(8192, [4096, 8192, 16384, 32768, 65536, 131072], true);
  assert.equal(slider.value(), 8192, 'saved window restored');
  assert(nodes.status.textContent.includes('gewählt: 8K · für Anfragen verwendet: 8.192 Tokens'));

  // Model switch: all estimates are replaced, a late answer is ignored.
  const first = slider.load('gemma4:e4b');
  const second = slider.load('qwen3:4b');
  answers[calls[2]]({ok:true, context:context('qwen3:4b', {stops:[4096, 8192, 16384, 32768, 65536, 131072]
    .map(tokens => ({tokens, low_gb:3, high_gb:4, warn:tokens >= 65536}))})});
  await second;
  answers[calls[1]]({ok:true, context:context('gemma4:e4b')});
  await first;
  assert(nodes.stops.all('ctx-stop')[0].textContent.includes('etwa 3 bis 4 GB RAM'), 'new model, new estimates');
  assert.equal(nodes.stops.all('ctx-stop')[5].className.includes('warn'), true, 'warned stop marked');
  nodes.range.value = '5';
  nodes.range.handlers.input();
  assert.equal(nodes.warning.hidden, false, 'warning for too little RAM');
  assert(nodes.warning.textContent.startsWith('Möglicherweise zu wenig RAM für diese Einstellung'));

  // Missing metadata: no invented number.
  const unknown = slider.load('mystery:latest');
  answers[calls[3]]({ok:true, context:context('mystery:latest', {method:null, stops:[4096, 8192, 16384, 32768,
    65536, 131072].map(tokens => ({tokens, low_gb:null, high_gb:null, warn:false}))})});
  await unknown;
  assert(nodes.estimate.textContent.includes('RAM-Bedarf derzeit nicht zuverlässig schätzbar'));
  assert(nodes.stops.all('ctx-stop')[2].textContent.includes('nicht schätzbar'));

  // Cloud: not applicable, the stored choice is kept but disabled.
  const cloud = slider.load('gpt-oss:120b-cloud');
  answers[calls[4]]({ok:true, context:{model:'gpt-oss:120b-cloud', applicable:false, reason:'cloud', stops:[]}});
  await cloud;
  assert.equal(nodes.range.disabled, true);
  assert.equal(nodes.useDefault.disabled, true);
  assert.equal(nodes.output.textContent, 'nicht anwendbar');
  assert(nodes.estimate.textContent.includes('nie num_ctx'), 'cloud never gets num_ctx');

  // Without migration 017 the slider explains why it is locked.
  slider.apply(null, [4096, 8192, 16384, 32768, 65536, 131072], false);
  const local = slider.load('ember-coreui:latest');
  answers[calls[5]]({ok:true, context:context('ember-coreui:latest')});
  await local;
  assert.equal(nodes.useDefault.disabled, true);
  assert(nodes.estimate.textContent.includes('Migration 017'));

  // A failed estimate request still leaves a usable slider.
  const broken = api.create(Object.assign({}, nodes, {fetchJson: () => Promise.reject(new Error('offline'))}));
  broken.apply(32768, [4096, 8192, 16384, 32768, 65536, 131072], true);
  await broken.load('ember-coreui:latest');
  assert.equal(broken.value(), 32768, 'choice survives an unreachable estimate');
  assert(nodes.estimate.textContent.includes('nicht zuverlässig schätzbar'));

  // Wiring into the KI-Profil page.
  const html = fs.readFileSync(path.join(__dirname, '../settings.html'), 'utf8');
  const js = fs.readFileSync(path.join(__dirname, '../js/settings.js'), 'utf8');
  assert(html.includes('id="aiNumCtx" type="range"') && html.includes('CORE-STANDARD ÜBERNEHMEN')
    && html.includes('./js/coreui-context-slider.js?v='), 'slider markup and script present');
  assert(html.indexOf('coreui-context-slider.js') < html.indexOf('js/settings.js'), 'module loads first');
  assert(js.includes('num_ctx: contextSlider ? contextSlider.value() : null')
    && js.includes("aiModelOverride.addEventListener('change', loadContextEstimate)"), 'save and model switch wired');
  const css = fs.readFileSync(path.join(__dirname, '../css/console.css'), 'utf8');
  assert(/@media \(max-width: 720px\)[\s\S]{0,80}\.ctx-stops[\s\S]{0,60}repeat\(3/.test(css), 'two rows on phones');

  console.log('CoreAI KI-Profil slider: six stops, guide values, default, cloud, model switch OK');
})().catch(error => { console.error(error); process.exit(1); });
