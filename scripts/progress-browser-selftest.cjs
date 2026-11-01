'use strict';
// Optional real-browser regression. Requires Playwright and a Chromium binary.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname,'..');
(async()=>{
  const browser = await chromium.launch({headless:true,
    ...(process.env.COREAI_BROWSER_PATH ? {executablePath:process.env.COREAI_BROWSER_PATH} : {}),
    args:['--no-sandbox','--disable-gpu','--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage({reducedMotion:'reduce'});
    await page.route('**/*',async route=>{
      const url = new URL(route.request().url());
      const file = path.resolve(root,'.'+url.pathname);
      if(url.hostname==='coreai.local' && file.startsWith(root+path.sep) && fs.existsSync(file)) {
        await route.fulfill({body:fs.readFileSync(file)});
      } else await route.abort();
    });
    for(const width of [390,1000]) {
      await page.setViewportSize({width,height:1000});
      await page.setContent('<base href="http://coreai.local/"><style>'+fs.readFileSync(path.join(root,'css/console.css'),'utf8')+
        '</style><style>body{display:block;overflow:auto;padding:20px}.msg-row{max-width:900px;margin:auto}</style>'+
        '<div class="msg-row ember"><div class="msg-avatar">M</div><div class="msg-content"><div class="msg-name">MARA</div><div id="fixture"></div></div></div>');
      await page.addScriptTag({content:fs.readFileSync(path.join(root,'js/coreui-work-events.js'),'utf8')});
      await page.evaluate(()=>{
        const w=window.CoreUIWorkEvents;
        const events=[
          {id:1,kind:'model',state:'success',title:'Modellstream empfangen',meta:{}},
          {id:2,kind:'tool',state:'running',title:'Websuche gestartet',meta:{}},
          {id:3,kind:'tool',state:'info',title:'Webquelle gefunden',meta:{page_url:'https://example.org/documentation?token=secret',page_title:'Dokumentation zur aktuellen Version'}},
          {id:4,kind:'tool',state:'error',title:'Python-Ausfuehrung fehlgeschlagen',meta:{exit_code:1,error_type:'FileNotFoundError'}},
          {id:5,kind:'commentary',state:'info',title:'Mara meldet ihren naechsten Schritt',meta:{public_note:'Ich prüfe die fehlende Eingabedatei und die verfügbaren Quellen.'}}
        ];
        document.querySelector('#fixture').append(w.create({events}));
        const sites=w.researchList();
        w.researchPage(sites,'https://docs.example.org/start','','Aktuelle Seite');
        w.researchPage(sites,'https://news.example.org/update','','Aktuelle Seite');
        document.querySelector('#fixture').append(sites);
      });
      await page.evaluate(()=>document.fonts.ready);
      assert.equal(await page.locator('.work-toggle').count(),0);
      assert.equal(await page.locator('.work-body').isVisible(),true);
      const toggle=page.locator('.work-run-toggle').first();
      assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      await toggle.click();
      assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      await toggle.click();
      assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      assert.equal(await page.locator('.work-warning').isVisible(),true);
      assert.equal(await page.locator('.work-narration.work-error .work-note-text').evaluate(el=>getComputedStyle(el).borderLeftWidth),'0px');
      assert.equal(await page.locator('.work-site-status').allTextContents().then(x=>x.join('|')),'Suchtreffer|Besucht|Aktuelle Seite');
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
      assert(!(await page.locator('body').innerText()).includes('Arbeitsweg'));
      if(process.env.COREAI_SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.COREAI_SCREENSHOT_DIR,{recursive:true});
        await page.screenshot({path:path.join(process.env.COREAI_SCREENSHOT_DIR,`progress-${width}.png`),fullPage:true});
      }
    }
    console.log('Progress browser: desktop/mobile, step disclosure, warning, safe research cards and overflow OK');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
