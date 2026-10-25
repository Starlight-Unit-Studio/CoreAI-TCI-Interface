'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/console-app.js'), 'utf8');
const html = fs.readFileSync(path.join(__dirname, '../app.html'), 'utf8');

function section(from, to) {
  const start = source.indexOf(from);
  const end = source.indexOf(to, start);
  assert(start > 0 && end > start, 'Missing runtime function: ' + from);
  return source.slice(start, end);
}
class MemoryFile {
  constructor(parts, name, opts) {
    this.name = name;
    this.type = opts.type;
    this.text = parts.join('');
    this.size = new TextEncoder().encode(this.text).length;
  }
}
const make = new Function('File', section('  function makeAutoPasteFile(text) {',
  '  function resizeMessageComposer() {') + '\nreturn makeAutoPasteFile;')(MemoryFile);
const raw = '# Beispiel\n\n' + 'Äpfel & 🍀\n'.repeat(8500) + 'Letzte Zeile\n';
const file = make(raw);
assert.match(file.name, /^eingefuegter-text-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.md$/);
assert.equal(file.type, 'text/markdown;charset=utf-8');
assert.equal(file.text, raw, 'Never trim or alter the original pasted text');
assert.equal(file.size, new TextEncoder().encode(raw).length);
assert.equal(html.includes('<textarea'), true);
assert.equal(html.includes('maxlength="12000"'), false);
const state = {waitingEmber:false};
let available = 0, rendered = 0, uploads = 0;
let messages = 0;
const sources = new WeakMap();
const queue = [];
const queueFn = new Function('state', 'attachmentSlotsUsed','MAX_MESSAGE_ATTACHMENTS',
  'AUTO_PASTE_MAX_BYTES','makeAutoPasteFile','autoPasteSources','uploadQueue',
  'renderAttachmentList','processUploadQueue','appendMessageEl','scrollToBottom',
  section('  function queueAutoTextAttachment(text) {',
    '  function sendAfterCompletedUpload() {') + '\nreturn queueAutoTextAttachment;'
)(state,()=>available,10,256*1024*1024,make,sources,queue,
  ()=>rendered++,()=>uploads++,()=>messages++,()=>{});
assert.equal(queueFn(raw),true, 'Large paste should queue its own file');
assert.equal(queue.length,1);
assert.equal(queue[0].text,raw);
assert.equal(sources.get(queue[0]),raw, 'Original text must survive failed uploads');
assert.equal(uploads,1);
assert.equal(rendered,1);
available=10;
assert.equal(queueFn(raw),false, 'Ten-attachment limit must still apply');
available=0;
state.waitingEmber=true;
assert.equal(queueFn(raw),false, 'No new file upload during active model run');
state.waitingEmber=false;
assert.equal(queueFn('  '),false, 'No blank file');
assert.equal(messages,0);
for(const needle of [
  "inputEl.addEventListener('paste'",
  "inputEl.addEventListener('input'",
  'sendAfterCompletedUpload();',
  'restoreFailedAutoPaste(file);',
  'pendingSendAfterUpload = {text:\'\',sessionId:String(sesForPaste.id)}',
  'keepalive: new TextEncoder().encode(postBody).length < 60000'
]) assert(source.includes(needle), 'Pipeline regression: ' + needle);
console.log('CoreUI long paste: original UTF-8 bytes, .md filename, auto queue, attachment limit, failure restore hooks OK');
