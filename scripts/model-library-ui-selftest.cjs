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
// 0.6.91 removed the Core/Kontext/Werkzeuge/Projekt toolbar from the welcome screen; 0.7.4 deliberately
// restored it for active chats only (CHANGELOG 0.7.4 and 0.7.10). It is mounted once, starts hidden and
// only the active-chat switch in js/console-app.js shows it, so the welcome screen stays free of it.
const insightChipTags = html.match(/<div class="insight-chips" id="insightChips"[^>]*>/g) || [];
assert(insightChipTags.length === 1 && /\shidden(?=[\s>])/.test(insightChipTags[0])
  && read('js/console-app.js').includes('if (insightChips) insightChips.hidden = !active;'),
  'the Core/Kontext/Werkzeuge/Projekt toolbar is hidden on the welcome screen and shown only in active chats');
assert(html.includes('id="welcomeGreeting"') && html.includes('class="welcome-suggestions"')
  && html.includes('id="inputMessage"'), 'greeting, example prompts and composer remain intact');
assert(html.includes('<span>DENKEN</span>') && !html.includes('DENKAUFWAND'),
  'the compact thinking control uses Denken');
assert(!/\bLLMs?\b/.test(html), 'the visible CoreAI markup contains no LLM wording');
assert(html.indexOf('id="coreInstallTiers"') < html.indexOf('id="coreInstallLibraryEntry"')
  && html.indexOf('id="coreInstallLibraryEntry"') < html.indexOf('id="coreInstallCloud"'),
  'library entry is not part of optional Ollama account connection');
assert(html.includes('id="coreLibraryInstalledProvider"')
  && html.includes('id="coreLibraryInstalled"') && html.includes('role="tablist"'),
  'three proper source/inventory tabs are present');
assert(html.includes('id="coreLibrary"') && html.includes('id="coreLibraryDetail"'), 'search and overlay exist');
assert(html.includes('CORE-BIBLIOTHEK') && html.includes('Ollama + Hugging Face')
  && !html.includes('In Vorbereitung'), 'both catalogs are shown together');
assert(html.includes('href="https://ollama.com/search"'), 'official fallback link is retained');
assert(html.indexOf('js/coreui-core-install.js') < html.indexOf('js/coreui-model-library.js'),
  'library controller starts after the TCI install controller');
// 0.7.76 (Unified Core library, commit e7506f74): one shared Ollama + Hugging Face discovery tab beside
// the installed Cores tab. The dashed "future" provider tab is gone; desktop and the phone layout both
// split the tab row into two balanced columns.
const providerTabs = html.match(/<button type="button" class="core-library-provider[ "]/g) || [];
const providerGrid = (css.match(/\n\.core-library-providers \{[^}]*\}/) || [''])[0];
const providerPhone = /@media \(max-width: 520px\) \{[^@]*?\n  \.core-library-providers \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/;
assert(providerTabs.length === 2 && !css.includes('.core-library-provider--future')
  && providerGrid.includes('grid-template-columns: repeat(2, minmax(0, 1fr));')
  && providerPhone.test(css),
  'the two shared tabs include a responsive mobile layout');

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
let requestedUpdate = '', updateKind = '';
const reply = json => Promise.resolve({ok:true, status:200, json: () => Promise.resolve(json)});
function server(url, init) {
  calls.push({url, method:init.method, body:init.body || '', token:init.headers['X-CoreUI-CSRF']});
  if (url.includes('core_library_search=')) return reply({ok:true, models:[
    {name:'qwen3.5', source:'ollama', summary:'Open weights, 4b 9b'},
    {name:'example/GGUF-test', source:'huggingface', summary:'GGUF · Lizenz: apache-2.0'}], csrf_token:'csrf1'});
  if (url.includes('ollama_detail=')) return reply({ok:true, detail:{name:'qwen3.5',
    description:'Official local model page', url:'https://ollama.com/library/qwen3.5',
    tags:[{name:'qwen3.5:4b',size:'3.3GB',installed}]}, csrf_token:'csrf1'});
  if (url.includes('hf_detail=')) return reply({ok:true, detail:{name:'example/GGUF-test',
    description:'Public GGUF model', url:'https://huggingface.co/example/GGUF-test', license:'apache-2.0',
    tags:[{name:'hf.co/example/GGUF-test:model-Q4_K_M.gguf',size:'2.5 GB',installed:false}]}, csrf_token:'csrf1'});
  if (url.includes('core_install_status=1') && requestedUpdate) return reply({ok:true, install:{
    kind:updateKind, core:updateKind==='library' ? requestedUpdate : '',
    phase:'done', step:'Update fertig.', percent:100, running:false
  }});
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
    if (body.action === 'installed_core_update') {
      requestedUpdate = body.model;
      updateKind = body.model.startsWith('ember-') ? 'update' : 'library';
      return reply({ok:true,install:{kind:updateKind,
        core:updateKind==='library' ? body.model : '',
        phase:'pull',step:'Prüft Core-Update …',percent:0,running:true}});
    }
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
  assert.equal(els.coreLibraryResults.children.length, 2, 'both sources appear in one result list');
  assert.match(els.coreLibraryResults.children[1].textContent, /Hugging Face/,
    'Hugging Face sources have a clear label');
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
  assert.equal(standardRow.children.filter(c=>c.tagName === 'BUTTON').length, 1,
    'default Core can update but cannot be deleted');
  assert.equal(baseRow.children.filter(c=>c.tagName === 'BUTTON').length, 1,
    'default source can update but cannot be deleted');
  assert.equal(standardRow.children.find(c=>c.tagName==='BUTTON').textContent, 'Aktualisieren');
  assert.equal(baseRow.children.find(c=>c.tagName==='BUTTON').textContent, 'Aktualisieren');
  const removable = cardByName('qwen3.5:4b');
  const updateButton = removable.children.find(c=>c.tagName==='BUTTON' && c.textContent==='Aktualisieren');
  const removeButton = removable.children.find(c=>c.tagName==='BUTTON' && c.textContent==='Deinstallieren');
  assert(updateButton && removeButton, 'third-party Core has update and removal actions');
  const updates = () => calls.filter(c=>c.method==='POST'
    && JSON.parse(c.body).action==='installed_core_update');
  await updateButton.handlers.click();
  await flush();
  assert.equal(updates().length, 1, 'one specific Core update requested');
  assert.equal(updates()[0].token, 'csrf1', 'Core update requires CSRF');
  assert.equal(JSON.parse(updates()[0].body).model,'qwen3.5:4b');
  assert.equal(timers.length, 1, 'update progress is polled with the existing worker');
  await timers.shift()();
  await flush();
  assert.equal(ui.state().updating, '', 'completed local update clears pending state');
  assert.equal(modeRefresh, 2, 'updating refreshes chat Core list');
  const plus = cardByName('ember-core:12b');
  const plusUpdate = plus.children.find(c=>c.tagName==='BUTTON' && c.textContent==='Aktualisieren');
  await plusUpdate.handlers.click();
  await flush();
  assert.equal(updates().length, 2);
  assert.equal(JSON.parse(updates()[1].body).model, 'ember-core:12b');
  assert.equal(updateKind, 'update', 'TCI Core update runs through the rebuild worker');
  await timers.shift()();
  await flush();
  assert.equal(ui.state().updating, '', 'completed TCI update clears pending state');
  assert.equal(modeRefresh, 3);
  const refreshedRemove = cardByName('qwen3.5:4b').children
    .find(c=>c.tagName==='BUTTON' && c.textContent==='Deinstallieren');
  assert(refreshedRemove, 'Core remains installed after update');
  const removals = () => calls.filter(c=>c.method==='POST'
    && JSON.parse(c.body).action==='installed_core_uninstall');
  await refreshedRemove.handlers.click();
  assert.equal(removals().length, 0, 'first tap only asks to confirm');
  const confirmedRow = cardByName('qwen3.5:4b');
  assert(confirmedRow.textContent.includes('Betroffene Konten wechseln zum Standard-Core'),
    'the consequences are explained before deletion');
  const confirm = confirmedRow.children.find(c=>c.tagName === 'BUTTON'
    && c.textContent === 'Wirklich entfernen?');
  await confirm.handlers.click();
  await flush();
  assert.equal(removals().length, 1, 'second tap sends one removal');
  assert.equal(removals()[0].token, 'csrf1', 'removal also requires CSRF');
  assert.equal(JSON.parse(removals()[0].body).model, 'qwen3.5:4b',
    'only the explicitly selected tag can be removed');
  assert.equal(els.coreLibraryInstalledList.children.length, 3,
    'the removed Core disappears from the refreshed inventory');
  assert.equal(modeRefresh, 4, 'installing, updating and removing refresh the Core selector');
  await ui.switchTab('ollama');
  await flush();
  assert.equal(els.coreLibraryInstalled.hidden, true);
  assert.equal(els.coreLibraryBrowser.hidden, false);
  els.coreLibraryResults.children[1].handlers.click();
  await flush();
  assert.equal(els.coreLibraryModelTitle.textContent, 'example/GGUF-test');
  assert.equal(els.coreLibraryTag.value, 'hf.co/example/GGUF-test:model-Q4_K_M.gguf');
  assert.match(els.coreLibraryModelDescription.textContent, /apache-2.0/,
    'model license is shown before installing');
  els.coreLibraryInstall.handlers.click();
  els.coreLibraryInstall.handlers.click();
  await flush();
  const hfInstall = calls.filter(c => c.method === 'POST').map(c => JSON.parse(c.body))
    .find(c => c.action === 'huggingface_library_install');
  assert(hfInstall && hfInstall.model === 'hf.co/example/GGUF-test:model-Q4_K_M.gguf',
    'Hugging Face install reuses shared worker with exact verified GGUF file');
  ui.close();
  assert.equal(els.coreLibrary.hidden, true);
  assert.equal(els.coreInstallLibraryOpen.focused, true, 'focus returns to prior dialog');
  console.log('CoreAI model library: modal, official search, local version, confirmation, CSRF, progress and refresh OK');
})().catch(err=>{console.error(err);process.exit(1);});
