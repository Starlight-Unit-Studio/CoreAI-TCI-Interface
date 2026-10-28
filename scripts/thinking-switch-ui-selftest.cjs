/* Offline checks for the Core chip: Ollama list, Core choice with loading
   state, and the thinking switch (0.5.75). */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag, id) {
    this.tagName = String(tag).toUpperCase();
    this.id = id || '';
    this.attributes = {};
    this.handlers = {};
    this.children = [];
    this.parent = null;
    this.hidden = false;
    this.disabled = false;
    this.checked = false;
    this.className = '';
    this.type = '';
    this._text = '';
    this.focused = false;
  }
  set textContent(text) { this._text = String(text); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(' '); }
  get firstChild() { return this.children[0] || null; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  removeChild(child) { this.children = this.children.filter(c => c !== child); child.parent = null; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  contains(node) { for (let n = node; n; n = n.parent) if (n === this) return true; return false; }
  focus() { this.focused = true; }
}

function page() {
  const ids = ['coreModeChip', 'coreModeMenu', 'coreModeName', 'coreModeThink', 'coreModeList',
    'coreThinkToggle', 'coreModeStatus'];
  const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
  els.coreModeMenu.hidden = true;
  els.coreModeMenu.appendChild(els.coreModeList);
  const doc = {
    handlers: {},
    getElementById: id => els[id] || null,
    createElement: tag => new Element(tag),
    addEventListener(type, fn) { this.handlers[type] = fn; }
  };
  return {els, doc};
}

const MODELS = [
  {name: 'ember-coreui:latest', parameter_size: '7.5B'},
  {name: 'gemma4:e4b', parameter_size: '7.5B'},
  {name: 'gemma4:31b-cloud', parameter_size: '32.7B'},
];

function server(opts) {
  opts = opts || {};
  const calls = [];
  const st = {thinking: opts.thinking !== false, override: opts.override || '', loaded: ['ember-coreui:latest']};
  const reply = (status, body) => Promise.resolve({ok: status < 400, status, json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    calls.push({url, init});
    if (url === './api/models.php') {
      return opts.failModels ? reply(503, {ok: false, error: 'model_catalog_unavailable'})
        : reply(200, {ok: true, models: MODELS, default_model: 'ember-coreui:latest'});
    }
    if (url === './api/user_settings.php?core_status=1') return reply(200, {ok: true, loaded: st.loaded});
    if (init.method === 'POST') {
      const body = JSON.parse(init.body);
      if (body.action === 'thinking') {
        if (opts.failPost) return reply(503, {ok: false, error: 'save_failed'});
        st.thinking = body.thinking_enabled;
        return reply(200, {ok: true, thinking_enabled: st.thinking});
      }
      if (body.action === 'core') {
        if (!['', ...MODELS.map(m => m.name)].includes(body.model_override)) return reply(400, {ok: false, error: 'model_not_available'});
        st.override = body.model_override;
        const model = st.override || 'ember-coreui:latest';
        const preload = /cloud/.test(model) ? 'cloud' : (st.loaded.includes(model) ? 'loaded' : 'started');
        return reply(200, {ok: true, model_override: st.override, model, preload});
      }
    }
    return reply(200, {ok: true, settings: {thinking_enabled: st.thinking, model_override: st.override},
      default_model: 'ember-coreui:latest', csrf_token: 'tok123'});
  };
  return {fetch, calls, st};
}

const source = fs.readFileSync(path.join(__dirname, '../js/coreui-core-mode.js'), 'utf8');
assert(!/innerHTML|insertAdjacentHTML|outerHTML/.test(source), 'the chip never injects HTML');
assert(!/Modell(?!file)/.test(source.replace(/\/\*[\s\S]*?\*\//g, '')), 'lore: visible text says Core');
const sandbox = {window: {}, Promise, JSON, Array};
vm.runInNewContext(source, sandbox);
const api = sandbox.window.CoreUICoreMode;
assert.equal(api.shortCore('ember-coreui:latest'), 'ember-coreui');
assert.equal(api.shortCore(''), 'Core');
assert.equal(api.modelKey('Ember-CoreUI'), 'ember-coreui:latest');

const options = list => list.children.filter(c => c.className === 'core-mode-option');
const flush = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  // Load: chip shows the effective Core and the stored state.
  let {els, doc} = page();
  let srv = server();
  const timers = [];
  let mode = api.create(doc, srv.fetch, fn => timers.push(fn));
  await mode.ready;
  assert.equal(els.coreModeName.textContent, 'ember-coreui');
  assert.equal(els.coreModeThink.textContent, 'DENKEN');
  assert.equal(els.coreThinkToggle.disabled, false);

  // Opening lists the Ollama Cores like the KI-Profil, with a server default.
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  assert.equal(els.coreModeMenu.hidden, false);
  let opts = options(els.coreModeList);
  assert.deepEqual(opts.map(o => o.getAttribute('data-value')), ['', 'ember-coreui:latest', 'gemma4:e4b', 'gemma4:31b-cloud']);
  assert.equal(opts[0].getAttribute('aria-checked'), 'true', 'server default is the current choice');
  assert.match(opts[1].textContent, /7\.5B · geladen/, 'loaded Core is marked');
  assert.match(opts[3].textContent, /Cloud/, 'cloud Core is marked');

  // Choosing a local Core that is not loaded: saved, chip shows LÄDT, then polls.
  await opts[2].handlers.click();
  const corePost = srv.calls.find(c => c.init.method === 'POST');
  assert.deepEqual(JSON.parse(corePost.init.body), {action: 'core', model_override: 'gemma4:e4b'});
  assert.equal(corePost.init.headers['X-CoreUI-CSRF'], 'tok123');
  assert.equal(els.coreModeName.textContent, 'gemma4:e4b');
  assert.equal(els.coreModeThink.textContent, 'LÄDT');
  assert.equal(els.coreModeChip.getAttribute('data-state'), 'loading');
  assert.match(els.coreModeStatus.textContent, /wird geladen/);
  assert.equal(timers.length, 1, 'one status check is scheduled');
  await timers.shift()();
  assert.equal(els.coreModeThink.textContent, 'LÄDT', 'still loading while Ollama does not report it');
  srv.st.loaded.push('gemma4:e4b');
  await timers.shift()();
  assert.equal(els.coreModeThink.textContent, 'DENKEN', 'loaded: the chip shows the thinking state again');
  assert.match(els.coreModeStatus.textContent, /geladen und bereit/);
  assert.equal(timers.length, 0, 'polling stops once loaded');
  opts = options(els.coreModeList);
  assert.equal(opts[2].getAttribute('aria-checked'), 'true');

  // Same Core again: nothing is sent. Cloud Core: no loading.
  const before = srv.calls.length;
  await mode.choose('gemma4:e4b');
  assert.equal(srv.calls.length, before, 'no request for the current Core');
  await mode.choose('gemma4:31b-cloud');
  assert.equal(els.coreModeThink.textContent, 'DENKEN');
  assert.match(els.coreModeStatus.textContent, /Cloud-Core/);
  assert.equal(timers.length, 0, 'a cloud Core is never polled');
  await mode.choose('nicht:da');
  assert.match(els.coreModeStatus.textContent, /nicht mehr installiert/);
  assert.equal(mode.state().override, 'gemma4:31b-cloud', 'a refused Core changes nothing');

  // Loading that never finishes ends with a clear note after the limit.
  await mode.choose('ember-coreui:latest');
  assert.match(els.coreModeStatus.textContent, /bereits geladen/);
  srv.st.loaded = [];
  await mode.choose('gemma4:e4b');
  for (let i = 0; i < 45 && timers.length; i++) await timers.shift()();
  assert.equal(timers.length, 0);
  assert.equal(mode.state().loading, '');
  assert.match(els.coreModeStatus.textContent, /dauert länger/);

  // Escape and outside clicks close; a click on an option stays inside, also
  // when the list was rebuilt and the clicked button is detached.
  doc.handlers.click({target: options(els.coreModeList)[1]});
  assert.equal(els.coreModeMenu.hidden, false, 'a click on an option keeps the menu open');
  const detached = new Element('button');
  doc.handlers.click({target: detached, composedPath: () => [detached, els.coreModeList, els.coreModeMenu]});
  assert.equal(els.coreModeMenu.hidden, false, 'a rebuilt option still counts as inside');
  let stopped = false;
  await options(els.coreModeList)[2].handlers.click({stopPropagation() { stopped = true; }});
  assert.equal(stopped, true, 'choosing a Core never bubbles to the outside-click handler');
  doc.handlers.keydown({key: 'Escape'});
  assert.equal(els.coreModeMenu.hidden, true);
  assert.equal(els.coreModeChip.focused, true);
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  doc.handlers.click({target: new Element('div')});
  assert.equal(els.coreModeMenu.hidden, true, 'a click outside closes the menu');

  // Thinking switch: only its own value, with CSRF.
  els.coreThinkToggle.checked = false;
  await els.coreThinkToggle.handlers.change();
  const thinkPost = srv.calls.filter(c => c.init.method === 'POST').pop();
  assert.deepEqual(JSON.parse(thinkPost.init.body), {action: 'thinking', thinking_enabled: false});
  assert.equal(els.coreModeThink.textContent, 'SCHNELL');
  assert.match(els.coreModeStatus.textContent, /Denken aus/);

  // A failed save restores the previous state and says so.
  ({els, doc} = page());
  srv = server({thinking: false, failPost: true});
  mode = api.create(doc, srv.fetch, () => {});
  await mode.ready;
  els.coreThinkToggle.checked = true;
  await els.coreThinkToggle.handlers.change();
  assert.equal(mode.state().thinking, false);
  assert.equal(els.coreThinkToggle.checked, false);
  assert.match(els.coreModeStatus.textContent, /Nicht gespeichert/);

  // Catalog unreachable: the current Core stays visible, nothing is guessed.
  ({els, doc} = page());
  srv = server({override: 'gemma4:e4b', failModels: true});
  mode = api.create(doc, srv.fetch, () => {});
  await mode.ready;
  await mode.open();
  await flush();
  assert.match(els.coreModeList.textContent, /nicht erreichbar/);
  assert.deepEqual(options(els.coreModeList).map(o => o.getAttribute('data-value')), ['', 'gemma4:e4b']);

  // Settings unreachable: switch and choice stay locked.
  ({els, doc} = page());
  mode = api.create(doc, () => Promise.reject(new Error('offline')), () => {});
  await mode.ready;
  assert.equal(els.coreThinkToggle.disabled, true);
  assert.equal(await mode.setThinking(false), true, 'no save before the state is known');
  assert.equal(await mode.choose('gemma4:e4b'), '', 'no Core change before the state is known');

  // Markup and wiring.
  const html = fs.readFileSync(path.join(__dirname, '../app.html'), 'utf8');
  for (const id of ['coreModeChip', 'coreModeMenu', 'coreModeList', 'coreThinkToggle', 'coreModeStatus']) {
    assert(html.includes(`id="${id}"`), 'app.html has ' + id);
  }
  assert(html.includes('role="radiogroup"'), 'the Core list is a radio group');
  assert(/<script src="\.\/js\/coreui-core-mode\.js\?v=\d+"><\/script>/.test(html), 'module is loaded');
  const settings = fs.readFileSync(path.join(__dirname, '../settings.html'), 'utf8');
  assert(!settings.includes('aiThinkingEnabled'), 'the KI-Profil switch is gone');
  assert(settings.includes('id="thinkingMovedNote"'), 'the KI-Profil points to the chat');
  const settingsJs = fs.readFileSync(path.join(__dirname, '../js/settings.js'), 'utf8');
  assert(!settingsJs.includes('thinking_enabled'), 'saving the KI-Profil never sends a thinking value');

  console.log('CoreAI Core chip: Ollama list, Core choice with loading, thinking switch and failures OK');
})().catch(error => { console.error(error); process.exit(1); });
