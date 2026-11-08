/* CoreAI 0.6.63: real-browser image editor regression. Playwright/Node required.
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
assert(viewer.includes("ui.info = action('info'"), 'single accessible info action replaces split dropdown');
assert(!viewer.includes("icon('chevron')"), 'no dropdown chevron in title');
assert(css.includes('--viewer-accent-gold: var(--amb'), 'TCI amber token used');
assert(css.includes('--viewer-accent-blue: var(--cx'), 'TCI blue token used');
assert(css.includes('html[data-coreui-theme="dark"] .coreai-image-viewer'), 'viewer honors theme setting');
assert(css.includes('--editor-blue: var(--cx'), 'editor uses the native TCI blue token');
assert(css.includes('--editor-gold: var(--amb'), 'editor uses the native TCI amber token');
assert(css.includes('html[data-coreui-theme="dark"] .coreai-image-editor'), 'editor has a native dark theme');
assert(css.includes('.coreai-image-editor-brush-label input[type="range"]::-webkit-slider-thumb'), 'custom TCI brush thumb exists');
assert(editor.includes("brush.style.setProperty('--editor-brush-fill'"), 'brush fill follows actual size');

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
    const infoButton = page.locator('.coreai-image-viewer-info');
    assert(await infoButton.isVisible(), 'info button visible on phone');
    assert.equal(await infoButton.textContent(), '', 'info is a single icon, not a split dropdown');
    await infoButton.click();
    assert.equal(await infoButton.getAttribute('aria-expanded'), 'true', 'metadata popover opens');
    assert(await page.locator('.coreai-image-viewer-details').isVisible(), 'metadata visible');
    await infoButton.click();
    assert.equal(await infoButton.getAttribute('aria-expanded'), 'false', 'metadata popover closes');
    const metrics = await page.evaluate(() => {
      const toolbar = document.querySelector('.coreai-image-viewer-toolbar');
      const left = document.querySelector('.coreai-image-viewer-info').getBoundingClientRect();
      const right = document.querySelector('.coreai-image-viewer-close').getBoundingClientRect();
      const bg = getComputedStyle(toolbar).backgroundImage;
      return {left:left.left,right:right.right,width:window.innerWidth,bg};
    });
    assert(metrics.left>=0 && metrics.right<=metrics.width+1, 'all buttons fit phone viewport: '+JSON.stringify(metrics));
    assert(metrics.bg.includes('linear-gradient'), 'TCI toolbar has a gradient surface');
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme','dark'));
    const darkBg = await page.locator('.coreai-image-viewer-toolbar').evaluate(node=>getComputedStyle(node).backgroundImage);
    assert.notEqual(darkBg,metrics.bg, 'dark mode changes viewer chrome');
    await page.evaluate(() => document.documentElement.removeAttribute('data-coreui-theme'));
    await page.locator('.coreai-image-viewer-edit').click();
    await page.waitForSelector('.coreai-image-editor:not([hidden])');
    const lightEditor = await page.evaluate(() => {
      const root = document.querySelector('.coreai-image-editor');
      const bar = document.querySelector('.coreai-image-editor-header');
      const button = document.querySelector('.coreai-image-editor-submit');
      const field = document.querySelector('.coreai-image-editor-input');
      return {
        scheme: getComputedStyle(root).colorScheme,
        header: getComputedStyle(bar).backgroundImage,
        button: getComputedStyle(button).backgroundImage,
        field: getComputedStyle(field).backgroundImage,
        width: document.documentElement.scrollWidth
      };
    });
    assert.equal(lightEditor.scheme, 'light', 'light theme uses the TCI light palette');
    assert(lightEditor.header.includes('linear-gradient'), 'TCI header gradient is rendered');
    assert(lightEditor.button.includes('linear-gradient'), 'TCI primary button is rendered');
    assert(lightEditor.field.includes('linear-gradient'), 'TCI form input is rendered');
    assert(lightEditor.width <= 390, 'light editor stays within phone width');
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme','dark'));
    const darkEditor = await page.evaluate(() => {
      const root = document.querySelector('.coreai-image-editor');
      const bar = document.querySelector('.coreai-image-editor-header');
      const input = document.querySelector('.coreai-image-editor-input');
      return {
        scheme: getComputedStyle(root).colorScheme,
        header: getComputedStyle(bar).backgroundImage,
        field: getComputedStyle(input).backgroundImage
      };
    });
    assert.equal(darkEditor.scheme, 'dark', 'dark theme uses the TCI dark palette');
    assert.notEqual(darkEditor.header, lightEditor.header, 'editor header changes with selected theme');
    assert.notEqual(darkEditor.field, lightEditor.field, 'editor inputs change with selected theme');
    await page.evaluate(() => document.documentElement.removeAttribute('data-coreui-theme'));
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
    const brushInput = page.locator('.coreai-image-editor-brush-label input[type="range"]');
    await brushInput.evaluate(el => { el.value = '80'; el.dispatchEvent(new Event('input', {bubbles:true})); });
    const brushFill = await brushInput.evaluate(el => el.style.getPropertyValue('--editor-brush-fill').trim());
    assert.equal(brushFill, '81.8%', 'blue brush track fill follows selected size');
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
