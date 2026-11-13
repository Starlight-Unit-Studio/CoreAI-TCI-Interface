#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const app = read('app.html');
const source = read('js/coreui-vision-ollama.js');
const bridge = read('api/user_settings.php');
const nginx = read('config/runtime/nginx.conf.template');

for (const id of ['OllamaTab','OllamaPanel','OllamaForm','OllamaQuery',
  'OllamaResults','OllamaInstalled','OllamaDetail','OllamaBack',
  'OllamaTitle','OllamaDescription','OllamaVariant','OllamaInstall',
  'OllamaLink','OllamaProgress','OllamaProgressText','OllamaProgressFill',
  'OllamaStatus']) {
  assert(app.includes('id="visionLibrary' + id + '"'), 'missing DOM control: ' + id);
}
assert(app.includes('coreui-vision-ollama.js?v='), 'Ollama adapter must be loaded');
assert(nginx.includes('|user_settings|'), 'existing authenticated PHP bridge must be allowed by Nginx');
for (const token of ['ollama_search', 'ollama_detail','ollama_library_install',
  'core_installed', 'core_install_status', 'installed_core_uninstall']) {
  assert(bridge.includes(token), 'shared Ollama operation must exist: ' + token);
}
assert(!source.includes('./api/vision_ollama.php'), 'no extra unapproved PHP endpoint');
assert(!source.includes('/api/generate'), 'no unsupported direct Ollama image rendering');
assert(!source.includes('action:\'core\''), 'Vision tab must not switch the chat model');

const sandbox = {window:{document:null, fetch:null}, setTimeout:()=>0, clearTimeout:()=>{}};
vm.runInNewContext(source, sandbox, {filename:'coreui-vision-ollama.js'});
const adapter = sandbox.window.CoreUIVisionOllama;
assert(adapter && typeof adapter.create === 'function', 'Ollama adapter export missing');
for (const name of ['x/flux2-klein', 'x/flux2-klein:9b',
  'x/z-image-turbo:latest','feadxus/flux2-klein-9b-uncensored',
  'mannix/flux2-klein:4b']) {
  assert(adapter.isImageFamily(name), 'must recognize known image family: ' + name);
}
for (const name of ['TCI Core Plus', 'ember-core:e4b', 'llama3:latest',
  'flux-prompt:3b','x/Flux_AI','qwen3-vl:4b','z-image-prompt']) {
  assert(!adapter.isImageFamily(name), 'must not mislabel chat/vision understanding core: ' + name);
}

class Element {
  constructor(tag='div') {
    this.tagName = tag; this.children = []; this.events = {}; this.hidden = false;
    this.style = {}; this.dataset = {}; this.value = ''; this.textContent = '';
    this.disabled = false; this.className = ''; this.firstChild = null;
  }
  appendChild(item) { this.children.push(item); this.firstChild = this.children[0]; return item; }
  removeChild(item) { this.children.splice(this.children.indexOf(item),1);
    this.firstChild=this.children[0]||null; return item; }
  addEventListener(name,fn) { (this.events[name] ||= []).push(fn); }
  trigger(name) { (this.events[name]||[]).forEach(fn=>fn({preventDefault(){}})); }
}
const ids = ['Form','Query','Results','Installed','Detail','Back','Title','Description',
  'Variant','Install','Link','Progress','ProgressText','ProgressFill','Status'];
const nodes={visionLibrary:new Element(),visionLibraryOllamaPanel:new Element()};
for(const id of ids)nodes['visionLibraryOllama'+id]=new Element(id);
nodes.visionLibraryOllamaQuery.value='flux';
nodes.visionLibraryOllamaDetail.hidden=true;
nodes.visionLibraryOllamaProgress.hidden=true;
const doc = {getElementById:id=>nodes[id]||null,createElement:tag=>new Element(tag)};
let posts=[],calls=[],tasks=[];
let installed=['x/flux2-klein:9b'];
async function fetchMock(url,opts) {
  calls.push(url);
  let body={ok:true,csrf_token:'a'.repeat(48)};
  if(url.includes('core_installed=1')) {
    body.cores=[
      {name:'ember-core:e4b',removable:false,kind:'tci'},
      ...installed.map(name=>({name,kind:'local',removable:true,size_bytes:12345678}))
    ];
  } else if (url.includes('ollama_search=')) {
    body.models=[{name:'x/flux2-klein',summary:'local diffusion'},
      {name:'flux-prompt',summary:'chat prompt model'}];
  } else if (url.includes('ollama_detail=')) {
    body.detail={name:'x/flux2-klein',url:'https://ollama.com/x/flux2-klein',
      description:'FLUX image generation family',tags:[
        {name:'x/flux2-klein:4b',size:'5.7 GB',installed:false}
      ]};
  } else if(url.includes('core_install_status=1')) {
    body.install={kind:'library',core:'x/flux2-klein:4b',running:false,phase:'done',percent:100};
  } else if(opts.method==='POST') {
    const input=JSON.parse(opts.body);posts.push(input);
    assert.equal(opts.headers['X-CoreUI-CSRF'],'a'.repeat(48),'CSRF required on writes');
    if(input.action==='ollama_library_install')body.install={kind:'library',running:true};
    if(input.action==='installed_core_uninstall')installed=installed.filter(x=>x!==input.model);
  }
  return {ok:true,status:200,json:async()=>body};
}
const instance=adapter.create(doc,fetchMock,(fn,delay)=>{tasks.push(fn);return tasks.length;});
assert(instance,'adapter must initialize in Vision dialog');

async function flush() { for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve)); }
(async()=>{
  instance.refresh();
  await flush();
  assert(calls.some(x=>x.includes('core_installed=1')),'reads actual local Ollama inventory');
  assert(calls.some(x=>x.includes('ollama_search=flux')),'queries official Ollama catalog');
  assert.equal(nodes.visionLibraryOllamaInstalled.children.length,1,'installed Ollama image cores only');
  assert.equal(nodes.visionLibraryOllamaResults.children.length,1,'exclude unrelated chat models');
  const detailButton=nodes.visionLibraryOllamaResults.children[0];
  detailButton.trigger('click'); await flush();
  assert.equal(nodes.visionLibraryOllamaDetail.hidden,false,'open exact official tag list');
  nodes.visionLibraryOllamaVariant.value='x/flux2-klein:4b';
  nodes.visionLibraryOllamaVariant.trigger('change');
  nodes.visionLibraryOllamaInstall.trigger('click');
  assert.equal(posts.length,0,'requires two-step download confirmation');
  nodes.visionLibraryOllamaInstall.trigger('click'); await flush();
  assert.deepEqual(posts.map(x=>x.action),['ollama_library_install'],'reuse shared install worker');
  assert.equal(posts[0].model,'x/flux2-klein:4b','pull selected verified local tag');
  assert(tasks.length>0,'progress poll must be scheduled');
  tasks.shift()(); await flush();
  assert.equal(instance.state().busy,false,'finish download and unlock UI');
  const firstInstalled=nodes.visionLibraryOllamaInstalled.children[0];
  const remove=firstInstalled.children.find(c=>c.className==='vision-library-actions').children[0];
  remove.trigger('click'); await flush();
  assert.equal(posts.length,1,'uninstall requires explicit confirmation');
  const again=nodes.visionLibraryOllamaInstalled.children[0]
    .children.find(c=>c.className==='vision-library-actions').children[0];
  again.trigger('click'); await flush();
  assert.equal(posts[1].action,'installed_core_uninstall','reuse protected Core uninstall route');
  assert.equal(posts[1].model,'x/flux2-klein:9b','remove only explicitly selected local tag');
  console.log('PASS: Ollama Vision families, real catalog and installed inventory, confirmed pull, progress, CSRF and uninstall');
})().catch(error=>{console.error(error);process.exitCode=1;});
