/* CoreAI 0.6.64: offline mobile viewer/editor regression on Chromium.
 * No model, DB, external network, or server state.
 */
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const app = read('app.html');
const viewer = read('js/coreui-image-viewer.js');
const editor = read('js/coreui-image-editor.js');
const chat = read('js/console-app.js');
const css = read('css/coreui-image-viewer.css');
const jpg = fs.readFileSync(path.join(root, 'images/mara_winter.jpg'));
assert(jpg.length > 1000, 'local test portrait');
assert(/coreui-image-editor\.js\?v=\d+/.test(app), 'editor JS has numeric cache key');
assert(/coreui-image-viewer\.css\?v=\d+/.test(app), 'editor CSS has numeric cache key');
assert(chat.includes('sendImageEdit: function (files, instruction, size)'), 'existing queue bridge');
assert(editor.includes('window.CoreAIImageEditor=Object.freeze'), 'editor API remains available');
assert(editor.includes('drawAnnotations(markedCtx,false)'), 'marked PNG includes every annotation type');
assert(editor.includes("new File([mainBlob],mainName"), 'original image retained');
assert(editor.includes("setTool('eraser')"), 'eraser is wired');
assert(editor.includes("setTool('select')"), 'select/move is wired');
assert(editor.includes("setTool('brush')"), 'freehand is wired');
assert(editor.includes("setTool('text')"), 'text is wired');
assert(editor.includes("setTool('shape')"), 'shape tool is wired');
assert(editor.includes("buttons.undo.addEventListener"), 'undo is wired');
assert(editor.includes("buttons.redo.addEventListener"), 'redo is wired');
assert(css.includes('--editor-blue: var(--cx'), 'TCI blue variable retained');
assert(css.includes('--editor-gold: var(--amb'), 'TCI gold variable retained');
assert(css.includes('.coreai-image-editor-color-palette'), 'color palette styled');
assert(css.includes('.coreai-image-editor-shape-palette'), 'shape palette styled');
assert(css.includes('html[data-coreui-theme="dark"] .coreai-image-editor'), 'dark theme remains supported');

const html = [
  '<!doctype html><html lang="de"><head><meta charset="utf-8">',
  '<meta name="viewport" content="width=device-width,initial-scale=1">',
  '<link rel="stylesheet" href="/css/coreui-image-viewer.css"></head>',
  '<body style="background:#111;color:#fff;margin:0">',
  '<div class="msg-content"><figure class="msg-generated">',
  '<a class="msg-image-link" href="/images/portrait.jpg">',
  '<img class="msg-image" src="/images/portrait.jpg" alt="Bild"',
  ' data-file-name="portrait.jpg" data-file-mime="image/jpeg" width="280"></a>',
  '<a href="/images/portrait.jpg" download="portrait.jpg">Download</a>',
  '</figure></div>',
  '<script>window.__edits=[];window.CoreUIComposer={sendImageEdit(files,instruction,size){',
  'window.__edits.push({instruction,size,files:files.map(f=>({name:f.name,size:f.size,type:f.type}))});',
  "return 'ok';}};</script>",
  '<script src="/js/coreui-image-viewer.js"></script>',
  '<script src="/js/coreui-image-editor.js"></script>',
  '</body></html>'
].join('');

(async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({
      viewport:{width:390,height:844},
      isMobile:true,hasTouch:true,deviceScaleFactor:2
    });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('http://localhost:8778/**', async route => {
      const u = new URL(route.request().url());
      if (u.pathname === '/test.html') return route.fulfill({contentType:'text/html',body:html});
      if (u.pathname === '/images/portrait.jpg') return route.fulfill({contentType:'image/jpeg',body:jpg});
      if (u.pathname === '/js/coreui-image-viewer.js') return route.fulfill({contentType:'text/javascript',body:viewer});
      if (u.pathname === '/js/coreui-image-editor.js') return route.fulfill({contentType:'text/javascript',body:editor});
      if (u.pathname === '/css/coreui-image-viewer.css') return route.fulfill({contentType:'text/css',body:css});
      return route.fulfill({status:404,body:'not found'});
    });
    await page.goto('http://localhost:8778/test.html');
    await page.waitForFunction(() => {
      const img = document.querySelector('.msg-image');
      return img && img.complete && img.naturalWidth > 0;
    });
    async function openEditor() {
      await page.locator('a.msg-image-link').click();
      await page.locator('.coreai-image-viewer-edit').click();
      await page.waitForSelector('.coreai-image-editor:not([hidden])');
      await page.waitForFunction(() => {
        const img=document.querySelector('.coreai-image-editor-canvas');
        return img && img.width > 1 && img.height > 1;
      });
    }
    await openEditor();
    const light = await page.evaluate(() => ({
      scheme:getComputedStyle(document.querySelector('.coreai-image-editor')).colorScheme,
      toolbar:getComputedStyle(document.querySelector('.coreai-image-editor-toolbar')).backgroundImage,
      overflow:document.documentElement.scrollWidth - innerWidth
    }));
    assert.equal(light.scheme,'light');
    assert(light.toolbar.includes('linear-gradient'));
    assert(light.overflow<=1,'mobile horizontal overflow');
    await page.evaluate(() => document.documentElement.setAttribute('data-coreui-theme','dark'));
    assert.equal(await page.locator('.coreai-image-editor').evaluate(el=>getComputedStyle(el).colorScheme),'dark');
    await page.evaluate(() => document.documentElement.removeAttribute('data-coreui-theme'));

    assert.equal(await page.locator('.coreai-image-editor-toolbar button').count(),9);
    assert(await page.locator('.coreai-image-editor-size-popover').isVisible());
    const brush = page.locator('.coreai-image-editor-size-popover input[type="range"]');
    await brush.evaluate(el=>{el.value='80';el.dispatchEvent(new Event('input',{bubbles:true}));});
    assert.equal(await brush.evaluate(el=>el.style.getPropertyValue('--editor-brush-fill').trim()),'81.8%');

    // Entire-image edits still upload ONE unchanged original reference.
    await page.locator('.coreai-image-editor-input').fill('Ändere die Jacke zu Rot.');
    await page.locator('.coreai-image-editor-size').selectOption('large');
    await page.locator('.coreai-image-editor-submit').click();
    await page.waitForFunction(() => window.__edits.length === 1);
    let result = await page.evaluate(() => window.__edits[0]);
    assert.equal(result.files.length,1);
    assert.equal(result.files[0].type,'image/jpeg');
    assert.equal(result.size,'large');

    // Test shape popover, color picker, brush and text placement.
    await openEditor();
    await page.locator('.coreai-image-editor-tool-color').click();
    assert(await page.locator('.coreai-image-editor-color-palette').isVisible());
    await page.locator('.coreai-image-editor-color-option[data-color="#2868e8"]').click();
    await page.locator('.coreai-image-editor-tool-shapes').click();
    assert.equal(await page.locator('.coreai-image-editor-shape-option').count(),8);
    await page.locator('.coreai-image-editor-shape-option[data-shape="arrow"]').click();
    let rect = await page.locator('.coreai-image-editor-canvas').boundingBox();
    assert(rect && rect.width>30 && rect.height>30,'canvas visible');
    await page.mouse.move(rect.x+rect.width*.32,rect.y+rect.height*.30);
    await page.mouse.down();
    await page.mouse.move(rect.x+rect.width*.68,rect.y+rect.height*.55,{steps:8});
    await page.mouse.up();
    assert(await page.locator('.coreai-image-editor-mark-preview').isVisible(), 'shape created');
    assert(await page.locator('.coreai-image-editor-tool-undo').isEnabled(), 'undo shape enabled');
    await page.locator('.coreai-image-editor-tool-undo').click();
    assert.equal(await page.locator('.coreai-image-editor-mark-preview').isVisible(),false,'shape removed by undo');
    await page.locator('.coreai-image-editor-tool-redo').click();
    assert(await page.locator('.coreai-image-editor-mark-preview').isVisible(), 'shape restored by redo');

    await page.locator('.coreai-image-editor-tool-text').click();
    assert(await page.locator('.coreai-image-editor-text-palette').isVisible());
    await page.locator('.coreai-image-editor-text-value').fill('Antenne');
    await page.locator('.coreai-image-editor-text-apply').click();
    rect = await page.locator('.coreai-image-editor-canvas').boundingBox();
    await page.mouse.click(rect.x+rect.width*.48, rect.y+rect.height*.52);
    assert(await page.locator('.coreai-image-editor-mark-preview').isVisible(),'text placed');
    await page.locator('.coreai-image-editor-tool-brush').click();
    rect=await page.locator('.coreai-image-editor-canvas').boundingBox();
    await page.mouse.move(rect.x+rect.width*.2,rect.y+rect.height*.65);
    await page.mouse.down();
    await page.mouse.move(rect.x+rect.width*.42,rect.y+rect.height*.74,{steps:5});
    await page.mouse.up();
    await page.locator('.coreai-image-editor-tool-eraser').click();
    assert(await page.locator('.coreai-image-editor-size-popover').isVisible(),'eraser shows stroke-width input');
    await page.locator('.coreai-image-editor-tool-undo').click();
    await page.locator('.coreai-image-editor-tool-redo').click();

    await page.locator('.coreai-image-editor-input').fill('Nur Markierung ändern.');
    await page.locator('.coreai-image-editor-submit').click();
    await page.waitForFunction(() => window.__edits.length === 2);
    result=await page.evaluate(() => window.__edits[1]);
    assert.equal(result.size,'normal');
    assert.equal(result.files.length,2,'annotations add a second reference image');
    assert.equal(result.files[0].type,'image/jpeg');
    assert.equal(result.files[1].type,'image/png');
    assert(result.files[1].size>0);
    assert(result.instruction.includes('farbige Zeichnungen, Formen oder Text'));
    assert.deepEqual(errors,[],'no browser page exceptions');
  } finally {
    await browser.close();
  }
  console.log('CoreAI 0.6.64 editor UI OK: toolbar, color, shapes, text, brush, undo/redo, dark mode and references');
})().catch(err=>{console.error('CoreAI image editor selftest FAIL:',err.stack||err);process.exit(1);});
