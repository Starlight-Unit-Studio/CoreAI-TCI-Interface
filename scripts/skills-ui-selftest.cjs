// Optional browser regression: node scripts/skills-ui-selftest.cjs (Playwright required).
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:process.env.PW_CHROMIUM_PATH || undefined, args:['--no-sandbox']});
  try {
    const page = await browser.newPage({viewport:{width:390,height:844}});
    const catalog = JSON.parse(fs.readFileSync(path.join(root,'skills/catalog.json'),'utf8'));
    let enabled = Object.fromEntries(catalog.map(s => [s.id,s.required === true]));
    let ready = true, failSave = false, posts = 0;
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/api/user_skills.php') {
        if (route.request().method() === 'POST') {
          posts++;
          assert.equal(route.request().headers()['x-coreui-csrf'],'test-csrf');
          if (failSave) return route.fulfill({status:503,json:{ok:false,error:'save_failed'}});
          const payload = route.request().postDataJSON().enabled;
          assert.equal(Object.prototype.hasOwnProperty.call(payload,'werkzeuge'),false,
            'Mandatory skill must never be written as a preference');
          enabled = {...enabled,...payload,werkzeuge:true};
          return route.fulfill({json:{ok:true,enabled}});
        }
        return route.fulfill({json:{ok:true,catalog,enabled,ready,csrf_token:'test-csrf'}});
      }
      if (url.pathname === '/settings.html') {
        const html = fs.readFileSync(path.join(root,'settings.html'),'utf8')
          .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'')
          .replace('</body>','<script src="./js/coreui-settings-tabs.js"></script><script src="./js/skills-settings.js"></script></body>');
        return route.fulfill({contentType:'text/html',body:html});
      }
      if (url.pathname === '/js/skills-settings.js') return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'js/skills-settings.js'))});
      // 0.5.79: the skills live in their own tab of the settings page.
      if (url.pathname === '/js/coreui-settings-tabs.js') return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'js/coreui-settings-tabs.js'))});
      if (url.pathname === '/css/console.css') return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'css/console.css'))});
      if (url.pathname === '/css/settings.css') return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'css/settings.css'))});
      return route.fulfill({status:404,body:''});
    });
    await page.goto('http://coreui.test/settings.html#skills');
    assert.equal(await page.locator('#view-skills').isVisible(),true,'the address opens the Skills tab');
    await page.waitForFunction(() => !document.querySelector('#saveSkills').disabled);
    assert.equal(await page.locator('#skillsList input').count(),33);
    const required = page.locator('#skillsList input[data-required="true"]');
    assert.equal(await required.count(),1);
    assert.equal(await required.isChecked(),true);
    assert.equal(await required.isDisabled(),true);
    assert.ok(await page.locator('#skillsList').textContent().then(t=>t.includes('NICHT DEAKTIVIERBAR')));
    await page.locator('#disableAllSkills').click();
    assert.equal(await page.locator('#skillsList input:checked').count(),1,
      'Disable all must preserve always-on Werkzeuge');
    await page.locator('#enableAllSkills').click();
    assert.equal(await page.locator('#skillsList input:checked').count(),33);
    await page.locator('#skillsSearch').fill('Python');
    assert.ok(await page.locator('#skillsList input').count() < 33);
    await page.locator('#saveSkills').click();
    await page.waitForFunction(() => document.querySelector('#skillsStatus').textContent.startsWith('Skills gespeichert'));
    assert.equal(Object.values(enabled).filter(Boolean).length,33);
    await page.reload();
    await page.waitForFunction(() => !document.querySelector('#saveSkills').disabled);
    assert.equal(await page.locator('#skillsList input:checked').count(),33);
    await page.locator('#skillsList label').filter({hasText:'Python-Programme'}).click();
    failSave = true;
    await page.locator('#saveSkills').click();
    await page.waitForFunction(() => document.querySelector('#skillsStatus').textContent.startsWith('Speichern fehlgeschlagen'));
    assert.equal(enabled.python,true);
    assert.equal(await page.getByRole('checkbox', {name:/Python-Programme/}).isChecked(),false);
    failSave = false;
    await page.locator('#saveSkills').click();
    await page.waitForFunction(() => document.querySelector('#skillsStatus').textContent.startsWith('Skills gespeichert'));
    assert.equal(enabled.python,false);
    assert.equal(posts,3);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),'Mobile horizontal overflow');
    ready = false;
    await page.reload();
    await page.waitForFunction(() => document.querySelector('#skillsStatus').textContent.includes('Migration') || document.querySelector('#skillsStatus').textContent.includes('migration'));
    assert.equal(await page.locator('#saveSkills').isDisabled(),true);
    assert.equal(await page.locator('#skillsList input:disabled').count(),33);
    console.log('Skills UI: required system skill, bulk actions, CSRF, reload, save failure, mobile width, migration gate OK');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
