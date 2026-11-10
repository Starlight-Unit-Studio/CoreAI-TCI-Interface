'use strict';
// Offline UI and wiring regression for the local Ollama model browser.
// Requires Node.js only; no network, Playwright or real downloads.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const html = read('app.html');
const code = read('js/coreui-model-library.js');
const css = read('css/console.css');
new vm.Script(code, {filename:'coreui-model-library.js'});
assert(!/innerHTML|insertAdjacentHTML/.test(code), 'external catalog entries are rendered as text');
assert(html.includes('id="coreInstallLibraryOpen"'), 'Ollama area opens the new dialog');
assert(html.includes('id="coreLibrary"') && html.includes('id="coreLibraryDetail"'), 'search and overlay exist');
assert(html.includes('HUGGING FACE') && html.includes('in Vorbereitung'), 'unfinished source is clearly marked');
assert(html.includes('href="https://ollama.com/search"'), 'official fallback link is retained');
assert(html.indexOf('js/coreui-core-install.js') < html.indexOf('js/coreui-model-library.js'),
  'library controller starts after the TCI install controller');
assert(css.includes('.core-library-provider--future') && css.includes('@media (max-width: 520px)'),
  'the two source cards include a responsive mobile layout');

class Element {
  constructor(tag, id) {
    this.tagName = String(tag).toUpperCase();
    this.id = id || '';
    this.children = [];
    this.handlers = {};
    this.attributes = {};
    this.hidden = true;
    this.disabled = false;
    this.style = {};
    this._text = '';
    this._value = '';
  }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(' '); }
  set textContent(v) { this._text = String(v); this.children = []; }
  get firstChild() { return this.children[0] || null; }
  get value() { return this._value || (this.children[0] && this.children[0].value) || ''; }
  set value(v) { this._value = String(v); }
  appendChild(child) { this.children.push(child); return child; }
  removeChild(child) { this.children = this.children.filter(c => c !== child); return child; }
  addEventListener(name, callback) { this.handlers[name] = callback; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  focus() { this.focused = true; }
}
const ids = ['coreLibrary', 'coreInstallLibraryOpen', 'coreLibraryClose', 'coreLibrarySearchForm',
  'coreLibraryQuery', 'coreLibraryStatus', 'coreLibraryResults', 'coreLibraryDetail',
  'coreLibraryBack', 'coreLibraryModelTitle', 'coreLibraryModelDescription', 'coreLibraryModelLink',
  'coreLibraryTag', 'coreLibraryHint', 'coreLibraryInstall', 'coreLibraryProgress',
  'coreLibraryProgressText', 'coreLibraryProgressBar', 'coreLibraryProgressFill'];
const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
els.coreLibraryQuery.value = '';
const doc = {
  handlers: {},
  getElementById: id => els[id] || null,
  createElement: tag => new Element(tag),
  addEventListener(type, fn) { this.handlers[type] = fn; }
};
const calls = [], timers = [];
let installed = false, modeRefresh = 0;
const reply = json => Promise.resolve({ok:true, status:200, json: () => Promise.resolve(json)});
function server(url, init) {
  calls.push({url, method:init.method, body:init.body || '', token:init.headers['X-CoreUI-CSRF']});
  if (url.includes('ollama_search=')) return reply({ok:true, models:[
    {name:'qwen3.5', summary:'Open weights, 4b 9b'}], csrf_token:'csrf1'});
  if (url.includes('ollama_detail=')) return reply({ok:true, detail:{name:'qwen3.5',
    description:'Official local model page', url:'https://ollama.com/library/qwen3.5',
    tags:[{name:'qwen3.5:4b',size:'3.3GB',installed}]}, csrf_token:'csrf1'});
  if (url.includes('core_install_status=1')) return reply({ok:true, install:installed
    ? {kind:'library',core:'qwen3.5:4b',phase:'done',step:'Das Modell ist lokal installiert.',percent:100,running:false}
    : null});
  if (init.method === 'POST') {
    installed = true;
    return reply({ok:true, install:{kind:'library',core:'qwen3.5:4b',
      step:'Startet …',phase:'pull',percent:0,running:true}});
  }
  return reply({ok:false});
}
const sandbox = {
  window:{CoreUICoreMode:{instance:{refresh:()=>{modeRefresh++;}}}},
  Promise, JSON, Number, String, Array, encodeURIComponent
};
vm.runInNewContext(code, sandbox, {filename:'coreui-model-library.js'});
const api = sandbox.window.CoreUIModelLibrary;
assert.equal(typeof api.create, 'function');
const ui = api.create(doc, server, callback=>timers.push(callback));
const flush = async () => { for(let i=0;i<24;i++) await Promise.resolve(); };
(async () => {
  ui.open();
  await flush();
  assert.equal(els.coreLibrary.hidden, false);
  assert.equal(els.coreLibraryResults.children.length, 1, 'the official search results are listed');
  els.coreLibraryResults.children[0].handlers.click();
  await flush();
  assert.equal(els.coreLibraryDetail.hidden, false, 'selected model opens overlay');
  assert.equal(els.coreLibraryModelTitle.textContent, 'qwen3.5');
  assert.equal(els.coreLibraryTag.value, 'qwen3.5:4b', 'local size is selectable');
  assert.equal(els.coreLibraryInstall.disabled, false);
  els.coreLibraryInstall.handlers.click();
  assert.equal(els.coreLibraryInstall.textContent, 'Wirklich installieren?',
    'the first tap confirms a potentially large download');
  assert.equal(calls.filter(x=>x.method==='POST').length, 0, 'confirmation alone never installs');
  els.coreLibraryInstall.handlers.click();
  await flush();
  const posts = calls.filter(x=>x.method==='POST');
  assert.equal(posts.length, 1, 'only one installation request');
  assert.equal(posts[0].token, 'csrf1', 'CSRF from an authenticated catalog read');
  assert.equal(JSON.parse(posts[0].body).model, 'qwen3.5:4b');
  assert.equal(JSON.parse(posts[0].body).action, 'ollama_library_install');
  assert.equal(els.coreLibraryProgress.hidden, false, 'server-side progress is displayed');
  assert.equal(timers.length, 1, 'one status poll scheduled');
  await timers.shift()();
  await flush();
  assert.equal(modeRefresh, 1, 'installed model list refreshed');
  assert.equal(els.coreLibraryInstall.disabled, true, 'installed tag cannot be pulled again');
  assert.equal(els.coreLibraryInstall.textContent, 'Installiert');
  ui.close();
  assert.equal(els.coreLibrary.hidden, true);
  assert.equal(els.coreInstallLibraryOpen.focused, true, 'focus returns to prior dialog');
  console.log('CoreAI model library: modal, official search, local version, confirmation, CSRF, progress and refresh OK');
})().catch(err=>{console.error(err);process.exit(1);});
