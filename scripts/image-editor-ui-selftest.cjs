/* CoreAI 0.6.62: real-browser image editor regression. Playwright/Node required.
 * Offline: no model, external server, internet, or database.
 */
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const app = read('app.html');
const viewer = read('js/coreui-image-viewer.js');
const editor = read('js/coreui-image-editor.js');
const chat = read('js/console-app.js');
const css = read('css/coreui-image-viewer.css');
const jpg = fs.readFileSync(path.join(root,'images/mara_winter.jpg'));
assert(jpg.length>1000,'offline portrait fixture');
assert(/coreui-image-editor\.js\?v=\d+/.test(app), 'editor JS is wired');
assert(/coreui-image-viewer\.js\?v=\d+/.test(app), 'viewer JS is wired');
assert(/coreui-image-viewer\.css\?v=\d+/.test(app), 'viewer/editor CSS is wired');
assert(chat.includes('sendImageEdit: function (files, instruction, size)'), 'chat upload bridge exists');
assert(chat.includes('pendingSendAfterUpload = {text:text, sessionId:String(ses.id)}'), 'send waits for uploads');
assert(editor.includes('drawMarks('), 'brush selection rendering exists');
assert(viewer.includes("ui.edit.addEventListener('click'"), 'viewer opens editor');

const html = [
  '<!doctype html><html lang="de"><head><meta charset="utf-8">',
  '<meta name="viewport" content="width=device-width,initial-scale=1">',
  '<link rel="stylesheet" href="/css/coreui-image-viewer.css"></head>',
  '<body style="background:#111;color:#fff;margin:0">',
  '<div class="msg-row ember"><div class="msg-content"><figure class="msg-generated">',
  '<a class="msg-image-link" href="/images/portrait.jpg" aria-label="Bild in voller Größe öffnen">',
  '<img class="msg-image" alt="Gespeichertes Bild" data-file-name="portrait.jpg"',
  ' data-file-size="' + jpg.length + '" data-file-mime="image/jpeg"',
  ' src="/images/portrait.jpg" width="280"></a>',
  '<figcaption><a href="/images/portrait.jpg" download="portrait.jpg">Herunterladen</a></figcaption>',
  '</figure></div></div>',
  '<script>window.__edits=[]; window.CoreUIComposer={sendImageEdit(files,instruction,size){',
  'window.__edits.push({size,instruction,files:files.map(f=>({name:f.name,size:f.size,type:f.type}))});',
  "return 'ok'; }};</script>",
  '<script src="/js/coreui-image-viewer.js"></script>',
  '<script src="/js/coreui-image-editor.js"></script>',
  '</body></html>'
].join('');

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2});
    const errors = [];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('http://localhost:8778/**',async route=>{
      const u=new URL(route.request().url());
      if(u.pathname==='/test.html') return route.fulfill({contentType:'text/html',body:html});
      if(u.pathname==='/images/portrait.jpg') return route.fulfill({contentType:'image/jpeg',body:jpg});
      if(u.pathname==='/js/coreui-image-viewer.js') return route.fulfill({contentType:'text/javascript',body:viewer});
      if(u.pathname==='/js/coreui-image-editor.js') return route.fulfill({contentType:'text/javascript',body:editor});
      if(u.pathname==='/css/coreui-image-viewer.css') return route.fulfill({contentType:'text/css',body:css});
      return route.fulfill({status:404,body:'not found'});
    });
    await page.goto('http://localhost:8778/test.html');
    await page.waitForFunction(()=>{const i=document.querySelector('.msg-image');return i&&i.complete&&i.naturalWidth>0;});
    await page.locator('a.msg-image-link').click();
    await page.locator('.coreai-image-viewer-edit').click();
    await page.waitForSelector('.coreai-image-editor:not([hidden])');
    await page.locator('.coreai-image-editor-input').fill('Ändere die Jacke zu Rot.');
    await page.locator('.coreai-image-editor-size').selectOption('large');
    await page.locator('.coreai-image-editor-submit').click();
    await page.waitForFunction(()=>window.__edits.length===1);
    let result=await page.evaluate(()=>window.__edits[0]);
    assert.equal(result.size,'large');
    assert.equal(result.files.length,1,'whole-image edit uses original only');
    assert.equal(result.files[0].type,'image/jpeg');
    assert(result.instruction.includes('Ändere die Jacke zu Rot.'));
    assert(!result.instruction.includes('PINKFARBENE'));
    await page.waitForSelector('.coreai-image-editor[hidden]',{state:'attached'});

    await page.locator('a.msg-image-link').click();
    await page.locator('.coreai-image-viewer-edit').click();
    await page.waitForSelector('.coreai-image-editor:not([hidden])');
    await page.locator('.coreai-image-editor-tool[aria-label="Bereiche mit dem Finger markieren"]').click();
    const box=await page.locator('.coreai-image-editor-canvas').boundingBox();
    assert(box&&box.width>20&&box.height>20,'visible canvas');
    await page.mouse.move(box.x+box.width*.35,box.y+box.height*.35);
    await page.mouse.down();
    await page.mouse.move(box.x+box.width*.58,box.y+box.height*.58,{steps:6});
    await page.mouse.up();
    assert(await page.locator('.coreai-image-editor-tool[aria-label="Markierung rückgängig"]').isEnabled());
    await page.locator('.coreai-image-editor-tool[aria-label="Markierung rückgängig"]').click();
    assert(await page.locator('.coreai-image-editor-tool[aria-label="Markierung wiederherstellen"]').isEnabled());
    await page.locator('.coreai-image-editor-tool[aria-label="Markierung wiederherstellen"]').click();
    await page.locator('.coreai-image-editor-input').fill('Nur den markierten Bereich als Metall ersetzen.');
    await page.locator('.coreai-image-editor-submit').click();
    await page.waitForFunction(()=>window.__edits.length===2);
    result=await page.evaluate(()=>window.__edits[1]);
    assert.equal(result.size,'normal');
    assert.equal(result.files.length,2,'brush adds selection reference');
    assert.equal(result.files[0].type,'image/jpeg');
    assert.equal(result.files[1].type,'image/png');
    assert(result.files[1].size>0);
    assert(result.instruction.includes('PINKFARBENE Markierungen'));
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
    assert(overflow<=1,'phone horizontal overflow '+overflow);
    assert.deepEqual(errors,[],'no browser exceptions');
  } finally {await browser.close();}
  console.log('CoreAI image editor UI OK: edit, touch selection, undo/redo, size, original and marked references');
})().catch(error=>{console.error('CoreAI image editor UI FAIL:',error.stack||String(error));process.exit(1)});
