/* Offline browser-free checks for reader-owned CoreAI chat scrolling. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const frames = [];
const area = {
  scrollTop:1200,scrollHeight:1600,clientHeight:400,listeners:{},
  addEventListener(type,fn) { this.listeners[type]=fn; },
  getBoundingClientRect() { return {top:200,height:400}; },
  scrollTo(opts) { this.scrollTop=opts.top;this.listeners.scroll(); }
};
const flags = new Set();
const button = {
  hidden:false,listeners:{},attributes:{},
  classList:{toggle(name,on){if(on)flags.add(name);else flags.delete(name);}},
  addEventListener(type,fn){this.listeners[type]=fn;},
  setAttribute(name,value){this.attributes[name]=value;}
};
let working=false;
let active=null;
const sandbox={window:{
  requestAnimationFrame(fn){frames.push(fn);},
  matchMedia(){return {matches:false};}
}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../js/coreui-chat-follow.js'),'utf8'),sandbox);
const follow = sandbox.window.CoreUIChatFollow.create(area,button,()=>working,()=>active);
const flush=()=>{while(frames.length)frames.shift()();};

assert.equal(follow.atBottom(),true);
assert.equal(button.hidden,true);
// New streamed work follows only when the user was already following.
area.scrollHeight=1970;
follow.follow();flush();
assert.equal(area.scrollTop,1570);
assert.equal(button.hidden,true);

// A wheel-up gesture pins the user's reading position.
area.listeners.wheel({deltaY:-84});
area.scrollTop=1200;area.listeners.scroll();
assert.equal(follow.isFollowing(),false);
assert.equal(flags.has('visible'),true);
const saved=area.scrollTop;
area.scrollHeight=2400;
follow.follow();flush();
assert.equal(area.scrollTop,saved,'SSE must never pull the reader down');

// During active work, the same button becomes three working dots.
working=true;follow.refresh();
assert.equal(flags.has('working'),true);
assert.match(button.attributes['aria-label'],/Mara arbeitet/);

// A click moves toward the working assistant block, never empty space.
active={getBoundingClientRect(){return {top:560,height:680};}};
button.listeners.click();
assert(area.scrollTop > saved);
assert(area.scrollTop < area.scrollHeight-area.clientHeight);

// Returning to the actual bottom restores automatic follow and hides the button.
area.scrollTop=area.scrollHeight-area.clientHeight;
area.listeners.scroll();
assert.equal(follow.isFollowing(),true);
assert.equal(button.hidden,true);
working=false;follow.refresh();
area.listeners.wheel({deltaY:-10});
area.scrollTop-=180;area.listeners.scroll();
assert.equal(flags.has('working'),false);
assert.equal(flags.has('visible'),true);

// A touch swipe down also freezes the old position.
area.listeners.touchstart({touches:[{clientY:100}]});
area.listeners.touchmove({touches:[{clientY:140}]});
assert.equal(follow.isFollowing(),false);
const top=area.scrollTop;area.scrollHeight+=300;
follow.follow();flush();
assert.equal(area.scrollTop,top);

// An explicit new user turn is intentionally allowed to follow again.
active=null;follow.follow(true);flush();
assert.equal(follow.isFollowing(),true);
assert.equal(area.scrollTop,area.scrollHeight-area.clientHeight);
console.log('CoreAI reader-aware follow, working dots and jump button: OK');
