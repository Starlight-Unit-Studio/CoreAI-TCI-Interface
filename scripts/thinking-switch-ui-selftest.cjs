/* Offline checks for the Core chip: Ollama list, Core choice with loading
   state, and the thinking switch (0.5.75). 0.5.76: TCI names, fixed tier
   order with green dots, missing tiers greyed and opening the installer. */
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
    'coreThinkToggle', 'coreModeStatus', 'coreLegendBtn', 'coreLegend', 'coreLegendRows', 'coreModeManage'];
  const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
  els.coreModeMenu.hidden = true;
  els.coreModeMenu.appendChild(els.coreModeList);
  els.coreModeMenu.appendChild(els.coreModeManage);
  els.coreModeManage.hidden = true;
  els.coreLegend.hidden = true;
  els.coreLegend.appendChild(els.coreLegendRows);
  const doc = {
    handlers: {},
    getElementById: id => els[id] || null,
    createElement: tag => new Element(tag),
    addEventListener(type, fn) { this.handlers[type] = fn; }
  };
  return {els, doc};
}

// Labels as api/models.php sends them (coreui_core_label).
const MODELS = [
  {name: 'ember-coreui:latest', parameter_size: '7.5B', label: 'TCI: Mara Normal', mara: true},
  {name: 'gemma4:31b-cloud', parameter_size: '32.7B', label: 'TCI: Mara Premium'},
  {name: 'gemma4:e2b', parameter_size: '5.1B', label: 'TCI: Mara Schnell'},
  {name: 'gemma4:e4b', parameter_size: '7.5B', label: 'TCI: Mara Normal'},
  {name: 'gpt-oss:120b-cloud', parameter_size: '117B', label: 'gpt-oss:120b-cloud'},
];
const MISSING = [{tier: 'plus', label: 'TCI: Mara Plus', base: 'gemma4:12b'},
  {tier: 'pro', label: 'TCI: Mara Pro', base: 'gemma4:26b'}, {tier: 'premium', label: 'TCI: Mara Premium', base: 'gemma4:31b'}];

function server(opts) {
  opts = opts || {};
  const calls = [];
  const st = {thinking: opts.thinking !== false, override: opts.override || '', loaded: ['ember-coreui:latest']};
  const models = opts.models || MODELS;
  const labelFor = name => (models.find(m => m.name === name) || {}).label || name;
  const reply = (status, body) => Promise.resolve({ok: status < 400, status, json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    calls.push({url, init});
    if (url === './api/models.php') {
      return opts.failModels ? reply(503, {ok: false, error: 'model_catalog_unavailable'})
        : reply(200, {ok: true, models, default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
          missing_tiers: opts.missing || MISSING, install: opts.install || null});
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
        if (!['', ...models.map(m => m.name)].includes(body.model_override)) return reply(400, {ok: false, error: 'model_not_available'});
        st.override = body.model_override;
        const model = st.override || 'ember-coreui:latest';
        const preload = /cloud/.test(model) ? 'cloud' : (st.loaded.includes(model) ? 'loaded' : 'started');
        return reply(200, {ok: true, model_override: st.override, model, label: labelFor(model), preload});
      }
    }
    return reply(200, {ok: true, settings: {thinking_enabled: st.thinking, model_override: st.override},
      default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
      core_label: labelFor(st.override || 'ember-coreui:latest'), csrf_token: 'tok123', permission_level: opts.level ?? 4});
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
// The client mirror of the TCI tiers (names the server has not labelled yet).
[['gemma4:e2b', 'TCI: Mara Schnell'], ['gemma4:e4b', 'TCI: Mara Normal'], ['gemma4:26b', 'TCI: Mara Pro'],
 ['gemma4:31b-cloud', 'TCI: Mara Premium'], ['hf.co/x/gemma-4-E4B-it-GGUF:Q8_0', 'TCI: Mara Normal'],
 ['gemma4:12b', 'TCI: Mara Plus'], ['gpt-oss:120b-cloud', ''], ['gemma3:27b', '']]
  .forEach(([name, label]) => assert.equal(api.tierLabel(name), label, 'tier of ' + name));

// Rows are wrappers (core-mode-item) holding the option and, for cloud tiers, the Lokal/Cloud switch.
const rowItems = list => list.children.filter(c => c.className === 'core-mode-item');
const flat = list => list.children.flatMap(c => c.className === 'core-mode-item' ? c.children : [c]);
const options = list => flat(list).filter(c => c.className === 'core-mode-option');
const entries = list => flat(list).filter(c => /(^| )core-mode-option( |$)/.test(c.className));
const switchOf = row => row.children.find(c => c.className === 'core-mode-variants') || null;
const dot = button => button.children.find(c => c.className === 'core-mode-dot').getAttribute('data-state');
const flush = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  // Load: chip shows the effective Core and the stored state.
  let {els, doc} = page();
  let srv = server();
  const timers = [];
  const opened = [];
  let mode = api.create(doc, srv.fetch, fn => timers.push(fn), {installer: () => ({open: tier => opened.push(tier),
    manage: () => opened.push('manage')})});
  await mode.ready;
  assert.equal(els.coreModeName.textContent, 'Mara Normal', 'the chip shows the TCI name');
  assert.equal(els.coreModeThink.textContent, 'DENKEN');
  assert.equal(els.coreThinkToggle.disabled, false);

  // Opening lists the Ollama Cores with TCI names in a fixed order (Schnell,
  // Normal, Plus, Pro, Premium, local before cloud, then own names). Mara's
  // own Core is the standard, her raw base (same tier) is folded into it.
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  assert.equal(els.coreModeMenu.hidden, false);
  let opts = options(els.coreModeList);
  assert.deepEqual(opts.map(o => o.getAttribute('data-value')), ['gemma4:e2b', '', 'gemma4:31b-cloud', 'gpt-oss:120b-cloud']);
  assert.deepEqual(opts.map(o => o.children[0].textContent),
    ['TCI: Mara Schnell', 'TCI: Mara Normal', 'TCI: Mara Premium', 'gpt-oss:120b-cloud']);
  assert(!opts.some(o => /gemma4|ember-coreui/.test(o.textContent)), 'no technical Gemma names in the menu');
  assert.equal(opts[1].getAttribute('aria-checked'), 'true', 'server default is the current choice');
  assert.match(opts[1].textContent, /Standard · 7\.5B · geladen/, 'standard, size and loaded state');
  assert.match(opts[2].textContent, /32\.7B · Cloud/, 'cloud Core is marked');

  // One row per tier, no name twice. Missing local tiers stay at their place,
  // greyed with a red dot; the Core in use is green, installed ones yellow.
  let all = entries(els.coreModeList);
  assert.deepEqual(all.map(o => o.children[0].textContent), ['TCI: Mara Schnell', 'TCI: Mara Normal', 'TCI: Mara Plus',
    'TCI: Mara Pro', 'TCI: Mara Premium', 'gpt-oss:120b-cloud']);
  assert.equal(new Set(all.map(o => o.children[0].textContent)).size, all.length, 'no name twice');
  assert.deepEqual(all.map(dot), ['standby', 'active', 'missing', 'missing', 'standby', 'standby']);
  // Premium runs in the cloud only: its row shows the cloud Core with a Lokal/Cloud switch,
  // Lokal is greyed and opens the installer.
  const premium = switchOf(rowItems(els.coreModeList)[4]);
  assert(premium, 'a tier with a cloud Core has a switch');
  assert.deepEqual(premium.children.map(b => [b.textContent, b.getAttribute('aria-pressed')]), [['Lokal', 'false'], ['Cloud', 'true']]);
  assert.equal(premium.children[0].className, 'core-mode-variant core-mode-variant--missing');
  assert.equal(premium.children[0].disabled, false);
  assert.deepEqual(rowItems(els.coreModeList).map(r => !!switchOf(r)), [false, false, false, false, true, false], 'only cloud tiers have a switch');
  const plus = all[2];
  assert.equal(plus.className, 'core-mode-option core-mode-option--missing');
  assert.equal(plus.getAttribute('data-tier'), 'plus');
  assert.equal(plus.disabled, false, 'a missing tier stays clickable');
  assert.match(plus.textContent, /nicht installiert/);
  assert.match(plus.getAttribute('aria-label'), /Öffnet die Installation/);
  let bubbled = false;
  await plus.handlers.click({stopPropagation() { bubbled = true; }});
  assert.deepEqual(opened, ['plus'], 'a missing tier opens the install window');
  assert.equal(bubbled, true);
  assert.equal(els.coreModeMenu.hidden, true, 'the menu closes for the install window');
  assert.equal(srv.calls.filter(c => c.init.method === 'POST').length, 0, 'a missing tier is never chosen');
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  await switchOf(rowItems(els.coreModeList)[4]).children[0].handlers.click({stopPropagation() {}});
  assert.deepEqual(opened, ['plus', 'premium'], 'greyed Lokal opens the installer of its tier');
  assert.equal(els.coreModeManage.hidden, true, 'Cores verwalten is for admins only');
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  opts = options(els.coreModeList);

  // Choosing a local Core that is not loaded: saved, chip shows LÄDT, then polls.
  await opts[0].handlers.click();
  const corePost = srv.calls.find(c => c.init.method === 'POST');
  assert.deepEqual(JSON.parse(corePost.init.body), {action: 'core', model_override: 'gemma4:e2b'});
  assert.equal(corePost.init.headers['X-CoreUI-CSRF'], 'tok123');
  assert.equal(els.coreModeName.textContent, 'Mara Schnell');
  assert.equal(els.coreModeThink.textContent, 'LÄDT');
  assert.equal(els.coreModeChip.getAttribute('data-state'), 'loading');
  assert.match(els.coreModeStatus.textContent, /wird geladen/);
  assert.equal(dot(options(els.coreModeList)[0]), 'loading', 'the Core that loads has an amber dot');
  assert.equal(timers.length, 1, 'one status check is scheduled');
  await timers.shift()();
  assert.equal(els.coreModeThink.textContent, 'LÄDT', 'still loading while Ollama does not report it');
  srv.st.loaded.push('gemma4:e2b');
  await timers.shift()();
  assert.equal(els.coreModeThink.textContent, 'DENKEN', 'loaded: the chip shows the thinking state again');
  assert.match(els.coreModeStatus.textContent, /geladen und bereit/);
  assert.equal(timers.length, 0, 'polling stops once loaded');
  opts = options(els.coreModeList);
  assert.equal(opts[0].getAttribute('aria-checked'), 'true');
  assert.equal(dot(opts[0]), 'active', 'the Core in use is green');
  assert.equal(dot(opts[1]), 'standby', 'the former Core goes to standby (yellow)');

  // Same Core again: nothing is sent. Cloud Core: no loading.
  const before = srv.calls.length;
  await mode.choose('gemma4:e2b');
  assert.equal(srv.calls.length, before, 'no request for the current Core');
  await mode.choose('gemma4:31b-cloud');
  assert.equal(els.coreModeThink.textContent, 'DENKEN');
  assert.match(els.coreModeStatus.textContent, /Cloud-Core/);
  assert.equal(els.coreModeName.textContent, 'Mara Premium');
  assert.equal(timers.length, 0, 'a cloud Core is never polled');
  await mode.choose('nicht:da');
  assert.match(els.coreModeStatus.textContent, /nicht mehr installiert/);
  assert.equal(mode.state().override, 'gemma4:31b-cloud', 'a refused Core changes nothing');

  // Loading that never finishes ends with a clear note after the limit.
  await mode.choose('ember-coreui:latest');
  assert.match(els.coreModeStatus.textContent, /bereits geladen/);
  srv.st.loaded = [];
  await mode.choose('gemma4:e2b');
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

  // Legend: every tier with its technical Cores, own names, and it closes the menu.
  await mode.open();
  await els.coreLegendBtn.handlers.click({stopPropagation() {}});
  assert.equal(els.coreLegend.hidden, false);
  assert.equal(els.coreLegendBtn.getAttribute('aria-expanded'), 'true');
  assert.equal(els.coreModeMenu.hidden, true, 'opening the legend closes the menu');
  const rows = els.coreLegendRows.children.map(tr => tr.children.map(cell => cell.textContent));
  assert.deepEqual(rows, [
    ['TCI: Mara Schnell', 'gemma4:e2b', 'installiert'],
    ['TCI: Mara Normal', 'ember-coreui:latest (Maras Core), gemma4:e4b', 'Standard'],
    ['TCI: Mara Plus', 'Gemma 4 12B', 'nicht installiert'],
    ['TCI: Mara Pro', 'Gemma 4 26B', 'nicht installiert'],
    ['TCI: Mara Premium', 'gemma4:31b-cloud', 'Cloud'],
    ['gpt-oss:120b-cloud', 'gpt-oss:120b-cloud', 'Cloud, eigener Name'],
  ]);
  doc.handlers.click({target: els.coreLegendRows});
  assert.equal(els.coreLegend.hidden, false, 'a click inside keeps the legend open');
  doc.handlers.keydown({key: 'Escape'});
  assert.equal(els.coreLegend.hidden, true, 'Escape closes the legend');
  assert.equal(els.coreLegendBtn.focused, true);
  await els.coreLegendBtn.handlers.click({stopPropagation() {}});
  await els.coreModeChip.handlers.click({stopPropagation() {}});
  assert.equal(els.coreLegend.hidden, true, 'opening the menu closes the legend');
  mode.close(false);

  // A Core the installer built from Mara's Modelfile is preferred over the
  // raw base of the same tier; a running install shows in the menu.
  {
    const p = page();
    const models = MODELS.concat([
      {name: 'gemma4:12b', parameter_size: '12B', label: 'TCI: Mara Plus'},
      {name: 'ember-coreui:12b', parameter_size: '12B', label: 'TCI: Mara Plus', mara: true},
      {name: 'ember-coreui:31b', parameter_size: '31B', label: 'TCI: Mara Premium', mara: true},
    ]);
    const s2 = server({models, missing: MISSING.slice(1, 2), level: 1,
      install: {tier: 'pro', phase: 'pull', running: true, percent: 42}});
    const managed = [];
    const m2 = api.create(p.doc, s2.fetch, () => {}, {installer: () => ({open() {}, manage: () => managed.push(1)})});
    await m2.ready;
    await m2.open();
    const list = entries(p.els.coreModeList);
    assert.equal(list[2].getAttribute('data-value'), 'ember-coreui:12b', 'Mara\'s Core of the tier is listed');
    assert.equal(list[2].children[0].textContent, 'TCI: Mara Plus');
    assert.equal(dot(list[2]), 'standby');
    assert.match(list[3].textContent, /wird installiert · 42 %/, 'running install in the menu');
    // Local and cloud Premium: one row, local first, the switch chooses the cloud.
    let premiumRow = rowItems(p.els.coreModeList)[4];
    assert.equal(premiumRow.children[0].getAttribute('data-value'), 'ember-coreui:31b');
    assert.deepEqual(switchOf(premiumRow).children.map(b => b.getAttribute('aria-pressed')), ['true', 'false']);
    assert.equal(entries(p.els.coreModeList).filter(o => o.children[0].textContent === 'TCI: Mara Premium').length, 1);
    await switchOf(premiumRow).children[1].handlers.click({stopPropagation() {}});
    assert.deepEqual(JSON.parse(s2.calls.filter(c => c.init.method === 'POST').pop().init.body),
      {action: 'core', model_override: 'gemma4:31b-cloud'});
    premiumRow = rowItems(p.els.coreModeList)[4];
    assert.equal(premiumRow.children[0].getAttribute('data-value'), 'gemma4:31b-cloud', 'the row follows the choice');
    assert.equal(premiumRow.children[0].getAttribute('aria-checked'), 'true');
    assert.deepEqual(switchOf(premiumRow).children.map(b => b.getAttribute('aria-pressed')), ['false', 'true']);
    await switchOf(premiumRow).children[0].handlers.click({stopPropagation() {}});
    assert.equal(rowItems(p.els.coreModeList)[4].children[0].getAttribute('data-value'), 'ember-coreui:31b', 'back to local');
    // Admins get Cores verwalten below the list.
    assert.equal(p.els.coreModeManage.hidden, false);
    await p.els.coreModeManage.handlers.click({stopPropagation() {}});
    assert.deepEqual(managed, [1]);
    assert.equal(p.els.coreModeMenu.hidden, true, 'the menu closes for the window');
    await m2.openLegend();
    const plusRow = p.els.coreLegendRows.children[2].children.map(c => c.textContent);
    assert.deepEqual(plusRow, ['TCI: Mara Plus', 'gemma4:12b, ember-coreui:12b (Maras Modelfile)', 'installiert']);
  }

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
  // The start view may show the state; the KI-Profil save never sends it.
  const saveAi = settingsJs.slice(settingsJs.indexOf('function saveAiSettings('), settingsJs.indexOf('function formatSessionDate('));
  assert(saveAi.length > 100 && !saveAi.includes('thinking_enabled'), 'saving the KI-Profil never sends a thinking value');

  console.log('CoreAI Core chip: TCI names, legend, Core choice with loading, thinking switch and failures OK');
})().catch(error => { console.error(error); process.exit(1); });
