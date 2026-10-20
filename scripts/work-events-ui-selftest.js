'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag) { this.tag=tag; this.children=[]; this.attrs={}; this.className=''; this.handlers={}; this.hidden=false; this._text=''; }
  set textContent(value) { this._text=String(value); this.children=[]; }
  get textContent() { return this._text+this.children.map(x=>x.textContent).join(''); }
  appendChild(child) { this.children.push(child); return child; }
  setAttribute(k,v) { this.attrs[k]=String(v); }
  addEventListener(name,fn) {this.handlers[name]=fn;}
  querySelector(query) {
    if(query[0]==='.') {let cl=query.slice(1); if(this.className.split(' ').includes(cl))return this;}
    if(query.startsWith('[data-work-id=')) {let id=query.match(/"([^"]+)"/)[1]; if(this.attrs['data-work-id']===id)return this;}
    for(let x of this.children){let found=x.querySelector(query); if(found)return found;}
    return null;
  }
  get firstChild(){return this.children[0];}
  removeChild(c){this.children.splice(this.children.indexOf(c),1);}
}
const calls=[];
const sandbox={window:{},document:{createElement:tag=>new Element(tag)},fetch:(url)=>{calls.push(url);return Promise.resolve({ok:true,json:()=>Promise.resolve({ok:true,events:[{id:2,kind:'tool',state:'error',title:'Python failed',meta:{error_type:'FileNotFoundError',exit_code:1},time:'23:10:00'}]})})}};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/coreui-work-events.js'),'utf8'),sandbox);
const w=sandbox.window.CoreUIWorkEvents;
assert.equal(typeof w.create,'function');
const body=new Element('div');
w.add(body,{id:1,kind:'tool',state:'error',title:'<img src=x onerror=alert(1)>',meta:{stderr:'PASSWORD=123',error_type:'FileNotFoundError'}});
assert.equal(body.querySelector('.work-entries').children.length,1);
assert.equal(body.textContent.includes('<img src=x onerror=alert(1)>'),true);
assert.equal(body.textContent.includes('PASSWORD=123'),false);
w.add(body,{id:1,kind:'tool',state:'error',title:'duplicate'});
assert.equal(body.querySelector('.work-entries').children.length,1);
const panel=w.create({sessionId:'abc',turnId:7,responseId:13,apiBase:'/api',expanded:false});
const toggle=panel.querySelector('.work-toggle');
assert.equal(panel.querySelector('.work-body').hidden,true);
toggle.handlers.click();
assert.equal(panel.querySelector('.work-body').hidden,false);
setImmediate(()=>{
  assert.equal(calls.length,1);
  assert(calls[0].includes('turn_id=7'));
  assert(calls[0].includes('response_id=13'));
  assert.equal(panel.querySelector('.work-entries').children.length,1);
  console.log('P06 UI event test: OK');
});
