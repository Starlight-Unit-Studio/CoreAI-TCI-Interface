#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');

const crop = require('../js/coreui-profile-crop.js');
function approx(actual, expected, why) {
  assert(Math.abs(actual-expected) < 0.00001, why + ': ' + actual + ' vs ' + expected);
}
assert.deepEqual(crop.normalize({x:30,y:60,w:200,h:300},400,600),
  {x:0.075,y:0.1,w:0.5,h:0.5}, 'Canvas box sends normalized source coords');
const back = crop.fromNormalized({x:0.1,y:0.2,w:0.3,h:0.5},800,600);
assert.deepEqual(back,{x:80,y:120,w:240,h:300},'Original crop can be restored on re-edit');
const square = crop.centered(300,500,1,0.9);
approx(square.w,square.h,'Game style square box');
assert.deepEqual(crop.fitRect({x:-50,y:300,w:200,h:200},300,400),
  {x:0,y:200,w:200,h:200},'Dragging never goes off image');

const html = fs.readFileSync(path.join(root,'settings.html'),'utf8');
const settings = fs.readFileSync(path.join(root,'js/settings.js'),'utf8');
const backend = fs.readFileSync(path.join(root,'api/profile_store.php'),'utf8');
const endpoint = fs.readFileSync(path.join(root,'api/profile.php'),'utf8');
const media = fs.readFileSync(path.join(root,'api/profile_media.php'),'utf8');
const deleteApi = fs.readFileSync(path.join(root,'api/account_delete.php'),'utf8');
const exportApi = fs.readFileSync(path.join(root,'api/account_export.php'),'utf8');
for (const id of ['profileCropDialog','profileCropCanvas','profileCropBox','profileCropApply',
  'profileCropCancel','profileCropReset','editUserAvatar','editAssistantAvatar']) {
  assert(html.includes('id="'+id+'"'),id+' exists');
}
assert(html.includes('coreui-profile-crop.js') && html.includes('coreui-profile-crop.css'), 'crop assets included');
assert(settings.includes("openAvatarCrop('user', file)") && settings.includes("openAvatarCrop('assistant', file)"),
  'File chooser opens crop before upload for both profile slots');
assert(settings.includes("action:'avatar_crop'") && settings.includes('previous.source_url'),
  'Re-edit uses private original without reupload');
assert(backend.includes("coreui_profile_source_path") && backend.includes("coreui_profile_media_cleanup")
  && backend.includes("coreui_profile_crop_pixels"),'Private sources, cleanup and server crop exist');
assert(endpoint.includes("if ($action === 'avatar_crop')") && media.includes("$variant === 'source'"),
  'Authenticated route for editing own original exists');
assert(deleteApi.includes("$uuid . '.source.jpg'") && exportApi.includes("source_data_base64"),
  'Account export and delete include source originals');
assert(!backend.includes('INSERT INTO ember_memories'),'Changing photo must never write long-term memory');

class FakeNode {
  constructor(id) {
    this.id=id; this.style={}; this.dataset={}; this.hidden=id==='profileCropDialog';
    this.disabled=false; this.listeners={}; this.attributes={}; this.offsetParent={};
    this.value=''; this.naturalWidth=0; this.naturalHeight=0; this.width=0; this.height=0;
  }
  addEventListener(name,handler){this.listeners[name]=handler;}
  dispatch(name,evt={}){const h=this.listeners[name];if(h)h(Object.assign({target:this},evt));}
  setAttribute(name,v){this.attributes[name]=v;}
  getContext(){return {clearRect(){},drawImage(){}};}
  getBoundingClientRect(){return {left:0,top:0,width:this.width,height:this.height};}
  setPointerCapture(){}
  focus(){this.focused=true;}
  querySelectorAll(){return [];}
}
const ids=['profileCropDialog','profileCropCanvas','profileCropBox','profileCropTitle',
  'profileCropNote','profileCropStatus','profileCropSize','profileCropWidth',
  'profileCropHeight','profileCropSizeFields','profileCropFreeFields','profileCropApply',
  'profileCropCancel','profileCropReset'];
const nodes=Object.fromEntries(ids.map(id=>[id,new FakeNode(id)]));
const formats=['free','2:3','3:4','1:1'].map(r=>{let e=new FakeNode(r);e.dataset.cropRatio=r;return e;});
const documentMock={
  body:{style:{overflow:''}}, activeElement:new FakeNode('before'), listeners:{},
  getElementById(id){return nodes[id]||null;},
  querySelectorAll(sel){return sel==='[data-crop-ratio]'?formats:[];},
  addEventListener(name,fn){this.listeners[name]=fn;}
};
const windowMock={innerWidth:370,innerHeight:740,listeners:{},
  addEventListener(name,fn){this.listeners[name]=fn;}};
global.window=windowMock;
global.document=documentMock;
global.URL={createObjectURL(){return 'blob:mock';},revokeObjectURL(){}};
global.Image=class {
  constructor(){this.naturalWidth=1200;this.naturalHeight=1800;}
  set src(value){this._src=value;if(this.onload)this.onload();}
};
// Reload with browser globals so the IIFE can attach its interactive instance.
delete require.cache[require.resolve('../js/coreui-profile-crop.js')];
const browserCrop = require('../js/coreui-profile-crop.js');
(async()=>{
  const instance=browserCrop.create();
  let userCrop=null;
  assert(instance.open({slot:'user',file:{type:'image/jpeg'},onConfirm:function(c){userCrop=c;return Promise.resolve();}}));
  assert.equal(nodes.profileCropDialog.hidden,false,'dialog opens without upload');
  assert.deepEqual(formats.map(f=>!!f.hidden),[true,true,true,false],
    'only square crop format available for the account avatar');
  nodes.profileCropApply.dispatch('click');
  await new Promise(resolve=>setImmediate(resolve));
  assert(userCrop && userCrop.w>0 && userCrop.h>0,'user crop submitted');
  assert(Math.abs(userCrop.w*1200 - userCrop.h*1800) < 0.01 * Math.max(userCrop.w*1200,userCrop.h*1800),
    'user crop corresponds to a square within canvas rounding; server forces exact square');
  assert.equal(nodes.profileCropDialog.hidden,true,'dialog closes after successful submit');

  let assistantCrop=null;
  assert(instance.open({slot:'assistant',url:'/api/profile_media.php?variant=source',
    crop:{x:0.1,y:0.2,w:0.6,h:0.6},onConfirm:function(c){assistantCrop=c;}}));
  assert.deepEqual(formats.map(f=>!!f.hidden),[false,false,false,false],
    'assistant reference supports free and portrait formats');
  nodes.profileCropApply.dispatch('click');
  await new Promise(resolve=>setImmediate(resolve));
  assert(assistantCrop && assistantCrop.x>0 && assistantCrop.y>0,
    'Re-crop restores source coordinates rather than using already cropped preview');
  assert.equal(nodes.profileCropDialog.hidden,true);
  process.stdout.write('Profile crop UI: square and portrait, source retention and endpoint contracts OK\n');
})().catch(err=>{console.error(err);process.exitCode=1;});
