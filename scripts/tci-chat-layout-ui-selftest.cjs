// Browser regression for Mara's full-width chat text below the avatar.
// node scripts/tci-chat-layout-ui-selftest.cjs
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname,'..');
const app = fs.readFileSync(path.join(root, 'js/console-app.js'), 'utf8');
const start = app.indexOf('  // Same TCI identity in live, saved and browser work messages.');
const end = app.indexOf('  // ── DOM: Nachricht einfügen',start);
assert(start > 0 && end > start,'shared TCI header helper missing');
const builder = app.slice(start,end);
const shell = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
  + '<meta name="viewport" content="width=device-width, initial-scale=1">'
  + '<link rel="stylesheet" href="/css/console.css">'
  + '<link rel="stylesheet" href="/css/coreui-chat-tci-layout.css">'
  + '</head><body style="margin:0">'
  + '<section class="feed-shell"><div class="messages-inner">'
  + '<div class="msg-row ember" id="assistant">'
  + '<div class="msg-avatar" id="avatar">M</div>'
  + '<div class="msg-content" id="content">'
  + '<div class="msg-bubble msg-markdown" id="body">'
  + '<p>Mara antwortet hier unter dem Avatar mit dem gesamten Platz. Ein längerer Satz soll gut umbrechen.</p>'
  + '</div></div></div>'
  + '</div></section></body></html>';

async function inspect(browser, width, height, name) {
  const context = await browser.newContext({viewport:{width,height},isMobile:width<500,hasTouch:width<500});
  await context.route('**/*',async route=>{
    const url = new URL(route.request().url());
    if(url.hostname!=='coreui.test') return route.abort();
    if(url.pathname==='/harness.html') return route.fulfill({contentType:'text/html',body:shell});
    if(/^\/css\/(?:console|coreui-chat-tci-layout)\.css$/.test(url.pathname)) {
      const filename = path.join(root,url.pathname.slice(1));
      return route.fulfill({contentType:'text/css',body:fs.readFileSync(filename)});
    }
    return route.fulfill({status:404,body:''});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror',err=>errors.push(err.message));
  try {
    await page.goto('https://coreui.test/harness.html');
    const measurement = await page.evaluate(({builder,name}) => {
      const state = {assistantName:name};
      // Run the real DOM builder extracted from the actual chat client.
      const getHeading = new Function('state',builder+'return buildTciMessageHeading();');
      const container = document.getElementById('content');
      const heading = getHeading(state);
      container.insertBefore(heading,container.firstChild);
      const rect = el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height};};
      const role = document.querySelector('.msg-core-role');
      const persona = document.querySelector('.msg-core-persona');
      return {
        row:rect(document.getElementById('assistant')),
        avatar:rect(document.getElementById('avatar')),
        heading:rect(heading),
        role:rect(role),
        persona:rect(persona),
        body:rect(document.getElementById('body')),
        roleText:role.textContent,
        personaText:persona.textContent,
        underlined:getComputedStyle(role).textDecorationLine,
        overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth
      };
    },{builder,name});
    assert.equal(measurement.roleText,'TCI CORE:');
    assert.equal(measurement.personaText,name.toUpperCase());
    assert(measurement.underlined.includes('underline'), 'label is actually underlined');
    assert(measurement.role.bottom<=measurement.persona.y+2,'name goes to second line');
    assert(measurement.body.y>=measurement.avatar.bottom-2,'answer starts below avatar');
    assert(Math.abs(measurement.body.x-measurement.row.x)<=2,
      'answer starts at full row width, not beside avatar');
    assert(measurement.body.right<=measurement.row.right+3,
      'answer is inside its chat row');
    assert(measurement.body.width>=measurement.row.width-3,
      'answer uses the full available width');
    assert(measurement.overflow<=0,'no sideways scrolling on a phone');
    assert.deepEqual(errors,[],'no page JS errors');
    const shots=process.env.COREAI_SHOT_DIR;
    if(shots) {
      fs.mkdirSync(shots,{recursive:true});
      await page.screenshot({path:path.join(shots,'tci-chat-'+width+'.png')});
    }
    return measurement;
  } finally { await context.close(); }
}

(async ()=>{
  const browser = await chromium.launch({headless:true,
    executablePath:process.env.PW_CHROMIUM_PATH||undefined,
    args:['--no-sandbox']});
  try {
    const phone=await inspect(browser,390,844,'Mara Thorne');
    const desktop=await inspect(browser,1024,768,'Mara');
    console.log('CoreAI TCI full-width chat UI: 390px phone, 1024px desktop, multiline heading and no overflow OK');
  } finally {await browser.close();}
})().catch(err=>{console.error('TCI chat layout FAIL:',err);process.exit(1);});
