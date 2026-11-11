// CoreAI 0.7.12: browser layout contract for a mirrored human message header.
// Requires Playwright only in the optional browser-test environment.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const start = app.indexOf('  // Same TCI identity in live, saved and browser work messages.');
const end = app.indexOf('  // ── DOM: Nachricht einfügen', start);
assert(start >= 0 && end > start, 'chat heading builders are present');
const builders = app.slice(start, end);
const shell = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
  + '<meta name="viewport" content="width=device-width, initial-scale=1">'
  + '<link rel="stylesheet" href="/css/console.css">'
  + '<link rel="stylesheet" href="/css/coreui-chat-tci-layout.css"></head>'
  + '<body style="margin:0"><section class="feed-shell"><div class="messages-inner">'
  + '<div class="msg-row user" id="user"><div class="msg-avatar" id="userAvatar">R</div>'
  + '<div class="msg-content" id="userContent"><div class="msg-bubble" id="userBubble">Eine Nachricht mit ausreichend Text für den Umbruch auf kleinen Displays.</div><div class="msg-actions" id="userActions">Aktionen</div><div class="msg-time" id="userTime">Heute</div></div></div>'
  + '<div class="msg-row ember" id="assistant"><div class="msg-avatar">M</div>'
  + '<div class="msg-content" id="assistantContent"><div class="msg-bubble">Antwort</div></div></div>'
  + '</div></section></body></html>';

async function inspect(browser, width) {
  const ctx = await browser.newContext({viewport:{width,height:850},isMobile:width<500,hasTouch:width<500});
  await ctx.route('**/*',async route=>{
    const u = new URL(route.request().url());
    if(u.hostname!=='coreui.test') return route.abort();
    if(u.pathname==='/harness.html') return route.fulfill({contentType:'text/html',body:shell});
    if(/^\/css\/(?:console|coreui-chat-tci-layout)\.css$/.test(u.pathname))
      return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,u.pathname.slice(1)))});
    return route.fulfill({status:404,body:''});
  });
  const page = await ctx.newPage();
  try {
    await page.goto('https://coreui.test/harness.html');
    const data = await page.evaluate(builder => {
      const state={assistantName:'Mara'};
      const build = new Function('state',builder+'return [buildOperatorMessageHeading,buildTciMessageHeading];');
      const [human,assistant] = build(state);
      document.getElementById('userContent').prepend(human('Rick'));
      document.getElementById('assistantContent').prepend(assistant());
      const rect = el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width};};
      const role = document.querySelector('.msg-operator-role');
      const name = document.querySelector('.msg-operator-persona');
      const assistantName = document.querySelector('.msg-core-persona');
      const heading = document.querySelector('.msg-name-operator');
      const assistantHeading = document.querySelector('.msg-name-tci');
      const assistantAvatar = document.querySelector('#assistant .msg-avatar');
      const blueProbe=document.createElement('span');
      blueProbe.style.color='var(--cx, #5b92ff)';document.body.append(blueProbe);
      const blue=getComputedStyle(blueProbe).color;blueProbe.remove();
      const goldProbe=document.createElement('span');
      goldProbe.style.color='#9b651e';document.body.append(goldProbe);
      const gold=getComputedStyle(goldProbe).color;goldProbe.remove();
      const type=el=>{const s=getComputedStyle(el);return {fontSize:s.fontSize,fontFamily:s.fontFamily,fontWeight:s.fontWeight,lineHeight:s.lineHeight,letterSpacing:s.letterSpacing};};
      return {roleText:role.textContent,nameText:name.textContent,
        role:rect(role),name:rect(name),heading:rect(heading),
        bubble:rect(document.getElementById('userBubble')),
        avatar:rect(document.getElementById('userAvatar')),
        row:rect(document.getElementById('user')),
        actions:rect(document.getElementById('userActions')),
        time:rect(document.getElementById('userTime')),
        assistantHeading:rect(assistantHeading),
        assistantAvatar:rect(assistantAvatar),
        roleColor:getComputedStyle(role).color,nameColor:getComputedStyle(name).color,
        nameType:type(name),operatorType:type(role),maraType:type(assistantName),
        underline:getComputedStyle(role).textDecorationLine,
        underlineColor:getComputedStyle(role).textDecorationColor,blue,gold,
        overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth};
    },builders);
    assert.equal(data.roleText,'OPERATOR:');
    assert.equal(data.nameText,'RICK');
    assert(data.role.bottom<=data.name.y+2,'operator heading and name occupy separate lines');
    const operatorGap = data.avatar.x - data.heading.right;
    const maraGap = data.assistantHeading.x - data.assistantAvatar.right;
    assert(Math.abs(operatorGap - maraGap) <= 2,
      'OPERATOR must sit as close to the avatar as Mara: ' + JSON.stringify({operatorGap,maraGap}));
    assert(Math.abs(data.avatar.right - data.row.right) <= 2,
      'human avatar stays pinned at the right edge of the chat row');
    assert(data.bubble.y >= data.avatar.bottom - 2,
      'the user bubble starts underneath the avatar and the two-line header');
    assert(Math.abs(data.bubble.right - data.row.right) <= 2,
      'the user bubble must end at the right chat edge, under the avatar rather than left of it');
    assert(data.actions.right >= data.row.right - 7,
      'user actions remain at the right edge below the bubble');
    assert(data.time.right >= data.row.right - 10,
      'message timestamp stays right-aligned beneath the bubble');
    assert.deepEqual(data.operatorType,data.nameType,'operator and user name have identical typography');
    assert.deepEqual(data.nameType,data.maraType,'operator and Mara headings share the same typography');
    assert.equal(data.roleColor,data.gold,'operator label is gold');
    assert.equal(data.nameColor,data.gold,'Rick is gold');
    assert(data.underline.includes('underline'),'operator role is underlined');
    assert.equal(data.underlineColor,data.blue,'operator underline is Mara blue');
    assert(data.overflow<=1,'no new sideways scroll on the phone');
  } finally { await ctx.close(); }
}
(async()=>{
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    await inspect(browser,340);
    await inspect(browser,390);
    await inspect(browser,1024);
    console.log('CoreAI OPERATOR layout: avatar gaps, right-edge bubbles/actions, phone/desktop OK');
  } finally { await browser.close(); }
})().catch(e=>{console.error('OPERATOR heading UI FAIL:',e);process.exit(1);});
