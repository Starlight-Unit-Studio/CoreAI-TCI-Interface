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
assert(html.includes('id="coreInstallLibraryOpen"'), 'general Core management has one library entry');
assert(html.indexOf('id="coreInstallTiers"') < html.indexOf('id="coreInstallLibraryEntry"')
  && html.indexOf('id="coreInstallLibraryEntry"') < html.indexOf('id="coreInstallCloud"'),
  'library entry is not part of optional Ollama account connection');
assert(html.includes('id="coreLibraryInstalledProvider"')
  && html.includes('id="coreLibraryInstalled"') && html.includes('role="tablist"'),
  'three proper source/inventory tabs are present');
assert(html.includes('id="coreLibrary"') && html.includes('id="coreLibraryDetail"'), 'search and overlay exist');
assert(html.includes('HUGGING FACE') && html.includes('in Vorbereitung'), 'unfinished source is clearly marked');
assert(html.includes('href="https://ollama.com/search"'), 'official fallback link is retained');
assert(html.indexOf('js/coreui-core-install.js') < html.indexOf('js/coreui-model-library.js'),
  'library controller starts after the TCI install controller');
assert(css.includes('.core-library-provider--future')
  && css.includes('grid-template-columns: repeat(3, minmax(0, 1fr))')
  && css.includes('@media (max-width: 520px)'),
  'the three tabs include a responsive mobile layout');

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
  'coreLibraryProgressText', 'coreLibraryProgressBar', 'coreLibraryProgressFill',
  'coreLibraryBrowser', 'coreLibraryInstalled', 'coreLibraryInstalledStatus',
  'coreLibraryInstalledList', 'coreLibraryOllamaProvider', 'coreLibraryInstalledProvider'];
const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
els.coreLibraryQuery.value = '';
const doc = {
  handlers: {},
  getElementById: id => els[id] || null,
  createElement: tag => new Element(tag),
  addEventListener(type, fn) { this.handlers[type] = fn; }
};
const calls = [], timers = [];
let installed = false, deleted = false, modeRefresh = 0;
const reply = json => Promise.resolve({ok:true, status:200, json: () => Promise.resolve(json)});
function server(url, init) {
  calls.push({url, method:init.method, body:init.body || '', token:init.headers['X-CoreUI-CSRF']});
  if (url.includes('ollama_search=')) return reply({ok:true, models:[
    {name:'qwen3.5', summary:'Open weights, 4b 9b'}], csrf_token:'csrf1'});
  if (url.includes('ollama_detail=')) return reply({ok:true, detail:{name:'qwen3.5',
    description:'Official local model page', url:'https://ollama.com/library/qwen3.5',
    tags:[{name:'qwen3.5:4b',size:'3.3GB',installed}]}, csrf_token:'csrf1'});
  if (url.includes('core_install_status=1')) return reply({ok:true, install:installed
    ? {kind:'library',core:'qwen3.5:4b',phase:'done',step:'Das LLM ist lokal installiert.',percent:100,running:false}
    : null});
  if (url.includes('core_installed=1')) return reply({ok:true, csrf_token:'csrf1', cores:[
    {name:'ember-coreui:latest',label:'TCI Core Normal',kind:'tci',removable:false,
      protected_reason:'Der Standard-Core muss installiert bleiben.',size_bytes:3500000000},
    {name:'ember-core:12b',label:'TCI Core Plus',kind:'tci',removable:true,size_bytes:7400000000},
    {name:'gemma4:26b',label:'gemma4:26b',kind:'local',removable:false,
      protected_reason:'Die Basis des Standard-Cores bleibt geschützt.',size_bytes:16000000000},
    ...(installed && !deleted ? [{name:'qwen3.5:4b',label:'qwen3.5:4b',kind:'local',removable:true,
      size_bytes:3300000000,parameter_size:'4B'}] : [])
  ]});
  if (init.method === 'POST') {
    const body = JSON.parse(init.body);
    if (body.action === 'installed_core_uninstall') {
      if (body.model === 'ember-coreui:latest') {
        return Promise.resolve({ok:false,status:409,json:()=>Promise.resolve({ok:false,error:'core_protected'})});
      }
      deleted = true;
      return reply({ok:true,removed:[body.model],failed:[]});
    }
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
  // Installed Cores are a real third tab, independent of the online catalog.
  await ui.switchTab('installed');
  await flush();
  assert.equal(els.coreLibraryBrowser.hidden, true);
  assert.equal(els.coreLibraryInstalled.hidden, false);
  assert.equal(els.coreLibraryInstalledProvider.attributes['aria-selected'], 'true');
  assert.equal(els.coreLibraryInstalledList.children.length, 4, 'all local and TCI Cores appear');
  const cardByName = name => els.coreLibraryInstalledList.children
    .find(card=>card.textContent.includes(name));
  const standardRow = cardByName('ember-coreui:latest');
  const baseRow = cardByName('gemma4:26b');
  assert(standardRow && baseRow, 'default Core and its source are visible');
  assert.equal(standardRow.children.some(c=>c.tagName === 'BUTTON'), false,
    'default Core cannot have a delete action');
  assert.equal(baseRow.children.some(c=>c.tagName === 'BUTTON'), false,
    'default source cannot have a delete action');
  const removable = cardByName('qwen3.5:4b');
  const removeButton = removable.children.find(c=>c.tagName === 'BUTTON');
  assert(removeButton, 'third-party LLM has a removal action');
  const removals = () => calls.filter(c=>c.method==='POST'
    && JSON.parse(c.body).action==='installed_core_uninstall');
  await removeButton.handlers.click();
  assert.equal(removals().length, 0, 'first tap only asks to confirm');
  const confirmedRow = cardByName('qwen3.5:4b');
  assert(confirmedRow.textContent.includes('Betroffene Konten wechseln zum Standard-Core'),
    'the consequences are explained before deletion');
  const confirm = confirmedRow.children.find(c=>c.tagName === 'BUTTON');
  await confirm.handlers.click();
  await flush();
  assert.equal(removals().length, 1, 'second tap sends one removal');
  assert.equal(removals()[0].token, 'csrf1', 'removal also requires CSRF');
  assert.equal(JSON.parse(removals()[0].body).model, 'qwen3.5:4b',
    'only the explicitly selected tag can be removed');
  assert.equal(els.coreLibraryInstalledList.children.length, 3,
    'the removed Core disappears from the refreshed inventory');
  assert.equal(modeRefresh, 2, 'both installing and removing refresh the chat Core selector');
  await ui.switchTab('ollama');
  await flush();
  assert.equal(els.coreLibraryInstalled.hidden, true);
  assert.equal(els.coreLibraryBrowser.hidden, false);
  ui.close();
  assert.equal(els.coreLibrary.hidden, true);
  assert.equal(els.coreInstallLibraryOpen.focused, true, 'focus returns to prior dialog');
  console.log('CoreAI model library: modal, official search, local version, confirmation, CSRF, progress and refresh OK');
})().catch(err=>{console.error(err);process.exit(1);});
