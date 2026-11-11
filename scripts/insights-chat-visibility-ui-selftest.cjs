// Active-chat-only Core/Kontext/Werkzeuge/Projekt visibility, 390px + desktop.
// node scripts/insights-chat-visibility-ui-selftest.cjs
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const app = read('js/console-app.js');
const html = read('app.html');
const chipMarkup = html.match(/<div class="insight-chips" id="insightChips"[^>]*><\/div>/);
const asideMarkup = html.match(/<aside class="core-insights" id="coreInsights"[^>]*><\/aside>/);
assert(chipMarkup && asideMarkup, 'real phone and desktop insight containers must exist');
const first = app.indexOf('  function showSessionInsights(active) {');
const last = app.indexOf('\n  }', first) + 4;
assert(first > 0 && last > first, 'real chat/welcome visibility controller exists');
const toggle = app.slice(first, last);

const HARNESS = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
  + '<meta name="viewport" content="width=device-width,initial-scale=1">'
  + '<link rel="stylesheet" href="/css/console.css">'
  + '<link rel="stylesheet" href="/css/coreui-chat-tci-layout.css">'
  + '</head><body class="app-body coreui-body"><div class="app-shell">'
  + '<main class="messages-area is-welcome" id="messagesArea">'
  + '<section class="welcome-screen" id="welcomeScreen">Hallo, Mara!</section>'
  + chipMarkup[0]
  + '<section class="feed-shell"><p>Der aktive Chat</p></section>'
  + '</main>' + asideMarkup[0] + '</div>'
  + '<script src="/js/coreui-insights.js"></script>'
  + '<script>const messagesArea = document.getElementById("messagesArea");'
  + 'const welcomeScreen = document.getElementById("welcomeScreen");'
  + 'const insightAside = document.getElementById("coreInsights");'
  + 'const insightChips = document.getElementById("insightChips");'
  + 'const insights = window.CoreUIInsights.create({aside:insightAside,chips:insightChips,apiBase:"api"});'
  + toggle
  + 'window.insightTest={'
  + 'enterChat(){messagesArea.classList.remove("is-welcome");welcomeScreen.hidden=true;'
  + 'showSessionInsights(true);return insights.refresh("sessionAAAA1");},'
  + 'welcome(){messagesArea.classList.add("is-welcome");welcomeScreen.hidden=false;showSessionInsights(false);}'
  + '};</script></body></html>';

const snapshot = {
  ok:true, session_id:'sessionAAAA1', session_title:'Testprojekt',
  core:{model:'ember-core:e4b',runtime:'local',context_tokens:32768,context_source:'profile',thinking:false},
  usage:{prompt_tokens:2000,output_tokens:400,context_tokens:32768},
  tools:[{id:'web',label:'WEB',detail:'Websuche',on:true}],
  skills:{required:['werkzeuge'],library:6},
  files:[{name:'test.zip',size_bytes:100,download_url:'api/console_artifact.php?id=1'}]
};

async function run(browser,width,height) {
  const context = await browser.newContext({
    viewport:{width,height},isMobile:width<500,hasTouch:width<500
  });
  await context.route('**/*',route=>{
    const url = new URL(route.request().url());
    if (url.hostname !== 'coreui.test') return route.abort();
    if (url.pathname === '/harness.html')
      return route.fulfill({contentType:'text/html',body:HARNESS});
    if (url.pathname === '/api/console_insights.php')
      return route.fulfill({contentType:'application/json',body:JSON.stringify(snapshot)});
    if (['/css/console.css','/css/coreui-chat-tci-layout.css','/js/coreui-insights.js'].includes(url.pathname)) {
      const file = url.pathname.slice(1);
      return route.fulfill({
        contentType:file.endsWith('.css')?'text/css':'application/javascript',
        body:read(file)
      });
    }
    return route.fulfill({status:404,body:''});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror',error=>errors.push(error.message));
  try {
    await page.goto('https://coreui.test/harness.html');
    const aside = page.locator('#coreInsights');
    const chips = page.locator('#insightChips');
    assert.equal(await aside.isVisible(),false,'desktop rail absent on welcome');
    assert.equal(await chips.isVisible(),false,'phone shortcuts absent on welcome');
    assert.equal(await page.locator('#insightChips .insight-chip').count(),4,
      'four controls remain mounted while hidden');
    await page.evaluate(()=>window.insightTest.enterChat());
    if (width < 1400) {
      assert.equal(await chips.isVisible(),true,'four shortcuts return on phone');
      assert.equal(await aside.isVisible(),false,'no desktop rail on phone');
      const labels = await page.locator('.insight-chip-label').allTextContents();
      assert.deepEqual(labels,['Core','Kontext','Werkzeuge','Projekt']);
      // Never shrink the original 11px / 13px-padded buttons just to fit
      // all four into a fixed width. The row may scroll horizontally.
      const geometry = await page.locator('#insightChips .insight-chip-row').evaluate(node=>{
        const chip = node.querySelector('button');
        const style = getComputedStyle(chip), bar = getComputedStyle(node);
        return {font:style.fontSize, leftPadding:style.paddingLeft,
          rightPadding:style.paddingRight, spacing:style.letterSpacing,
          labelGap:style.gap, rowGap:bar.gap, overflowX:bar.overflowX,
          scrollWidth:node.scrollWidth, clientWidth:node.clientWidth};
      });
      assert.equal(geometry.font,'11px','restore the original readable chip font');
      assert.equal(geometry.leftPadding,'13px','restore original left padding');
      assert.equal(geometry.rightPadding,'13px','restore original right padding');
      assert.equal(geometry.labelGap,'6px','restore original icon/label spacing');
      assert.equal(geometry.rowGap,'8px','restore original gap between blocks');
      assert.equal(geometry.overflowX,'auto','overflow should scroll, not shrink');
      assert(geometry.scrollWidth>=geometry.clientWidth,'row remains scrollable');
      await page.locator('#insightChips .insight-chip').last().scrollIntoViewIfNeeded();
      assert.equal(await page.locator('#insightChips .insight-chip').last().isVisible(),true,
        'Projekt stays reachable even on a narrow phone');
      await page.locator('#insightChips .insight-chip').nth(1).click();
      assert.equal(await page.locator('.insight-sheet').isVisible(),true,
        'Kontext panel opens in active chat');
      await page.locator('#insightChips .insight-chip').nth(2).click();
      assert((await page.locator('.insight-sheet').innerText()).includes('WEB'),
        'Werkzeuge switch shows actual tool data');
    } else {
      assert.equal(await chips.isVisible(),false,'chips not duplicated on wide screens');
      assert.equal(await aside.isVisible(),true,'right rail restored in active chat');
      assert.equal(await aside.locator('.insight-box').count(),4);
      assert((await aside.innerText()).includes('Testprojekt'));
    }
    await page.evaluate(()=>window.insightTest.welcome());
    assert.equal(await aside.isVisible(),false,'right rail hidden again on welcome');
    assert.equal(await chips.isVisible(),false,'phone buttons hidden again on welcome');
    assert.deepEqual(errors,[],'no JavaScript errors in browser');
    if (process.env.COREAI_SHOT_DIR) {
      fs.mkdirSync(process.env.COREAI_SHOT_DIR,{recursive:true});
      await page.screenshot({path:path.join(process.env.COREAI_SHOT_DIR,'insights-'+width+'.png')});
    }
  } finally { await context.close(); }
}
(async()=>{
  const browser = await chromium.launch({
    headless:true,executablePath:process.env.PW_CHROMIUM_PATH||undefined,
    args:['--no-sandbox']
  });
  try {
    await run(browser,390,844);
    await run(browser,1600,900);
    console.log('CoreAI insights: welcome hidden, four phone shortcuts and desktop rail restored OK');
  } finally { await browser.close(); }
})().catch(error=>{
  console.error('CoreAI insights chat visibility FAIL:',error);
  process.exit(1);
});
