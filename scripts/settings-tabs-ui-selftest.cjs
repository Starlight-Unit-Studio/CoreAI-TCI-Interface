// CoreAI 0.5.79: settings page with tabs and Admin Core inside it.
// Browser regression (Playwright): node scripts/settings-tabs-ui-selftest.cjs
// Serves the real page with mocked APIs and checks: one view at a time, the
// address keeps the tab, tiles jump, keys move between tabs, Admin Core only
// for Inhaber and Admin (an admin address waits for the role), the admin
// views load and save, the KI-Profil sends no Core, the start view sums up,
// no text below 11 px, no sideways scrolling on a phone, and the old
// admin/index.html lands on the matching tab.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

function server(level) {
  const calls = [];
  const settings = {registration_enabled: '1', guest_enabled: '1', guest_message_limit: '20', guest_network_guests: '1',
    guest_daily_messages: '200', captcha_enabled: '1', maintenance_enabled: '0', memory_default_enabled: '1',
    user_system_prompt_max_chars: '6000', maintenance_message: '', tool_web_enabled: '1', tool_browse_enabled: '1',
    tool_python_enabled: '0', external_api_enabled: '0', external_api_label: 'OpenAI-kompatibel', external_api_base_url: '', external_api_model: ''};
  const api = {
    'auth.php': () => ({ok: true, user_id: level === 0 ? 1 : 9, username: 'patrick@starlight.test', display_name: 'Patrick', permission_level: level, is_guest: false,
      csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Patrick', assistant_name: 'Mara', avatars: {}}}),
    'user_settings.php': () => ({ok: true, csrf_token: 'tok', default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
      core_label: 'TCI: Mara Normal', permission_level: level, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true,
        memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null},
      limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192, 16384, 32768, 65536, 131072], num_ctx_available: true},
      external: {enabled: false, configured: false}}),
    'knowledge.php': () => ({ok: true, csrf_token: 'tok', sources: [{uuid: 'a', title: 'Serverhandbuch', chunk_count: 42, file_size: 182000}],
      totals: {sources: 1, chunks: 42, global_chunks: 310}}),
    'memories.php': () => ({ok: true, items: [{id: 1, fact: 'Patrick mag kompakte Antworten.', relevance: 7, scope: 'user'},
      {id: 2, fact: 'Mara ist die Stimme von CoreAI.', relevance: 9, scope: 'global'}]}),
    'health.php': () => ({ok: true, version: '0.5.79', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'account_security.php': () => ({ok: true, sessions: []}),
    'admin.php': url => {
      const action = url.searchParams.get('action');
      if (action === 'users') return {ok: true, items: [{id: 1, username: 'patrick@starlight.test', display_name: 'Patrick', permission_level: 0, is_guest: 0, session_count: 3},
        {id: 2, username: 'gast-7f3a', display_name: 'Gast', permission_level: 4, is_guest: 1, session_count: 1},
        {id: 3, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: 0, session_count: 2}]};
      // 0.5.83: models outside CoreAI and their releases (Inhaber only).
      if (action === 'model_access') return level === 0 ? {ok: true, models: ['gpt-oss:120b-cloud', 'llama3.2:latest'], grants: {}, ollama: true}
        : {ok: false, error: 'owner_only'};
      if (action === 'audit') return {ok: true, items: [{action_name: 'settings_save', target_type: 'settings', target_id: 'runtime', actor: 'patrick', created_at: '2026-10-28 10:58:00'}]};
      if (action === 'logs') return {ok: true, lines: ['[2026-10-28] ok']};
      if (action === 'memories') return {ok: true, items: []};
      if (action === 'lore') return {ok: true, sources: [], items: []};
      return {ok: true, csrf_token: 'tok', settings, client_address: {source: 'forwarded'},
        stats: {users: 12, sessions: 48, messages: 3120, memories: 96, lore_chunks: 310, user_knowledge_chunks: 42, browse_active: 0}};
    },
  };
  return {calls, async route(route) {
    const url = new URL(route.request().url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) {
      const key = rel.slice(4);
      const post = route.request().method() === 'POST' ? route.request().postData() : null;
      calls.push({key, action: url.searchParams.get('action'), post: post ? JSON.parse(post) : null});
      if (key === 'admin.php' && post && level > 1) return route.fulfill({status: 403, json: {ok: false, error: 'insufficient_permissions'}});
      if (key === 'admin.php' && post && url.searchParams.get('action') === 'model_access_save') {
        return route.fulfill({json: {ok: true, models: JSON.parse(post).models}});
      }
      if (key === 'admin.php' && post && url.searchParams.get('action') === 'user_delete') {
        return route.fulfill({json: {ok: true, deleted: {sessions_deleted: 2, messages_deleted: 14, files_deleted: 3}}});
      }
      if (key === 'admin.php' && post) return route.fulfill({json: {ok: true, settings: JSON.parse(post).settings}});
      return route.fulfill({json: api[key] ? api[key](url) : {ok: true}});
    }
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  }};
}

async function open(browser, level, hash, viewport) {
  const srv = server(level);
  const page = await browser.newPage({viewport: viewport || {width: 1440, height: 1000}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://coreui.test/**', route => srv.route(route));
  await page.goto('http://coreui.test/settings.html' + (hash || ''));
  await page.waitForFunction(() => document.getElementById('startHealth').getAttribute('data-state') !== 'checking');
  return {page, srv, errors};
}

const visibleViews = page => page.$$eval('.cfg-view', views => views.filter(v => !v.hidden).map(v => v.id));
const smallText = page => page.evaluate(() => {
  const out = [];
  document.querySelectorAll('.cfg-view:not([hidden]) *, .cfg-nav *').forEach(node => {
    if (!node.offsetParent && getComputedStyle(node).position !== 'fixed') return;
    const own = Array.from(node.childNodes).some(child => child.nodeType === 3 && child.textContent.trim());
    if (!own) return;
    const size = parseFloat(getComputedStyle(node).fontSize);
    if (size < 11) out.push(node.tagName + '.' + node.className + ' ' + size + 'px: ' + node.textContent.trim().slice(0, 40));
  });
  return out;
});

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // A Nutzer: no Admin Core, an admin address falls back to the start view.
    let {page, srv, errors} = await open(browser, 4, '#admin-benutzer');
    assert.deepEqual(await visibleViews(page), ['view-start']);
    assert.equal(await page.locator('#adminNavGroup').isHidden(), true, 'no Admin Core tabs for a Nutzer');
    assert.equal(await page.locator('#startAdmin').isHidden(), true, 'no Admin Core tiles for a Nutzer');
    assert.equal(srv.calls.filter(c => c.key === 'admin.php').length, 0, 'a Nutzer never asks the admin API');
    // Start view sums up the categories.
    assert.equal(await page.textContent('#startCore'), 'Mara Normal');
    assert.equal(await page.textContent('#startThinking'), 'Denken an · Standard-Core');
    assert.equal(await page.textContent('#startHealth'), 'Alles bereit');
    assert.equal(await page.textContent('#startKnowledge'), '1 Quelle');
    assert.equal(await page.textContent('#startKnowledgeChunks'), '42 Abschnitte');
    assert.equal(await page.textContent('#startMemory'), '2 Fakten');
    assert.equal(await page.textContent('#startRole'), 'NUTZER');
    // Tabs switch in place and the address keeps the tab.
    await page.click('#tab-ki-profil');
    assert.deepEqual(await visibleViews(page), ['view-ki-profil']);
    assert.equal(new URL(page.url()).hash, '#ki-profil');
    assert.equal(await page.getAttribute('#tab-ki-profil', 'aria-selected'), 'true');
    assert.equal(await page.getAttribute('#tab-start', 'aria-selected'), 'false');
    await page.keyboard.press('ArrowDown');
    assert.deepEqual(await visibleViews(page), ['view-wissen'], 'arrow keys move between tabs');
    assert.equal(await page.locator('#tab-skills').count(), 0, 'no Skills tab: Mara picks her skills herself');
    await page.keyboard.press('Home');
    assert.deepEqual(await visibleViews(page), ['view-start']);
    await page.click('.cfg-tile[data-goto="wissen"]');
    assert.deepEqual(await visibleViews(page), ['view-wissen'], 'a tile opens its category');
    await page.click('#tab-system');
    assert.deepEqual(await visibleViews(page), ['view-system']);
    await page.evaluate(() => { location.hash = 'about'; });
    await page.waitForTimeout(50);
    assert.deepEqual(await visibleViews(page), ['view-system'], 'the old #about lands on System');
    // KI-Profil: no Core list, the save sends no Core (it is chosen in the chat).
    await page.click('#tab-ki-profil');
    assert.equal(await page.locator('#aiModelOverride').count(), 0);
    await page.click('#saveAiSettings');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').classList.contains('visible'));
    const aiPost = srv.calls.find(c => c.key === 'user_settings.php' && c.post);
    assert(aiPost && !('model_override' in aiPost.post) && aiPost.post.provider === 'local', 'KI-Profil saves without a Core');
    assert.deepEqual(errors, []);
    await page.close();

    // The Inhaber: Admin Core appears and an admin address opens once the role is known.
    ({page, srv, errors} = await open(browser, 0, '#admin-benutzer'));
    await page.waitForFunction(() => !document.getElementById('adminNavGroup').hidden);
    await page.waitForFunction(() => document.querySelectorAll('#userRows tr').length === 3);
    assert.deepEqual(await visibleViews(page), ['view-admin-benutzer'], 'the admin address opens after the role check');
    assert.equal(await page.$eval('#startAdmin', e => e.hidden), false, 'Admin Core tiles on the start view');
    assert.equal(await page.textContent('#adminIdentity'), 'patrick@starlight.test // INHABER');
    assert.deepEqual(await page.$$eval('[data-stat="users"]', nodes => nodes.map(n => n.textContent)), ['12', '12'],
      'the figures fill the start tile and the overview');
    assert.deepEqual(await page.$$eval('#userRows tr:nth-child(2) select option', o => o.map(x => x.textContent)), ['GAST'], 'a guest stays a guest');
    assert.equal(await page.$eval('#newUserLevel option[value="1"]', o => o.disabled), false, 'the Inhaber may create admins');
    // 0.5.83: the Inhaber releases models outside CoreAI per account.
    assert.deepEqual(await page.$$eval('#userRows .model-access-cell', cells => cells.map(c => c.textContent)),
      ['alle (Inhaber)', 'Standard-Core', 'FREIGABEN (0)'], 'CORES column for Inhaber, guest and Nutzer');
    await page.click('#userRows tr:nth-child(3) .model-access-toggle');
    assert.deepEqual(await page.$$eval('.model-access-row .model-access-choice', l => l.map(x => x.textContent)),
      ['gpt-oss:120b-cloud', 'llama3.2:latest'], 'the foreign models to release');
    assert.deepEqual(await smallText(page), [], 'no text below 11 px in Benutzer');
    await page.click('.model-access-row .model-access-choice:first-child input');
    await page.click('.model-access-row button');
    await page.waitForFunction(() => document.querySelector('#userRows tr:nth-child(3) .model-access-toggle').textContent === 'FREIGABEN (1)');
    const release = srv.calls.find(c => c.key === 'admin.php' && c.action === 'model_access_save');
    assert.deepEqual(release && release.post, {user_id: 3, models: ['gpt-oss:120b-cloud']}, 'the release is saved for Lena');
    await page.click('#userRows tr:nth-child(3) .model-access-toggle');
    assert.equal(await page.locator('.model-access-row').count(), 0, 'the panel closes again');
    // 0.5.84: deleting an account: never the Inhaber himself, the e-mail typed to confirm.
    assert.deepEqual(await page.$$eval('#userRows > tr', rows => rows.map(r => !!r.querySelector('.user-delete-toggle'))), [false, true, true],
      'LÖSCHEN for guest and Nutzer, not for the Inhaber himself');
    await page.click('#userRows tr:nth-child(3) .model-access-toggle');
    await page.click('#userRows tr:nth-child(3) .user-delete-toggle');
    assert.equal(await page.locator('.model-access-row').count(), 0, 'one panel at a time');
    assert.match(await page.textContent('.user-delete-title'), /Lena \(lena@example\.test\) endgültig löschen\?/);
    assert.equal(await page.isDisabled('.user-delete-row .danger'), true, 'not without the typed confirmation');
    await page.fill('.user-delete-confirm input', 'lena@');
    assert.equal(await page.isDisabled('.user-delete-row .danger'), true, 'a partial name is not enough');
    await page.fill('.user-delete-confirm input', 'Lena@Example.test');
    assert.equal(await page.isDisabled('.user-delete-row .danger'), false);
    assert.deepEqual(await smallText(page), [], 'no text below 11 px in the delete panel');
    await page.click('.user-delete-row .danger');
    await page.waitForFunction(() => /gelöscht: 2 Chats, 14 Nachrichten, 3 Dateien/.test(document.getElementById('adminFlash').textContent));
    const removal = srv.calls.find(c => c.key === 'admin.php' && c.action === 'user_delete');
    assert.deepEqual(removal && removal.post, {user_id: 3, confirm: 'Lena@Example.test'}, 'the deletion is sent with the confirmation');
    // Zugang und Schutz saves every runtime key, like Betrieb.
    await page.click('#tab-admin-zugang');
    assert.equal(await page.isChecked('#registrationEnabled'), true);
    assert.equal(await page.inputValue('#guestMessageLimit'), '20');
    await page.click('label[for="captchaEnabled"]');
    await page.click('#saveRuntimeAccess');
    await page.waitForFunction(() => document.getElementById('adminFlash').classList.contains('visible'));
    const save = srv.calls.find(c => c.key === 'admin.php' && c.action === 'settings_save');
    assert(save, 'Zugang und Schutz saves');
    assert.equal(save.post.settings.captcha_enabled, '0');
    assert.equal(save.post.settings.tool_python_enabled, '0', 'the Betrieb values travel along');
    assert.equal(Object.keys(save.post.settings).length, 17);
    // Every admin category loads its data when its tab opens.
    await page.click('#tab-admin-audit');
    await page.waitForFunction(() => document.querySelectorAll('#auditRows .audit-item').length === 1);
    await page.click('#tab-admin-protokoll');
    await page.waitForFunction(() => document.getElementById('logOutput').textContent.includes('ok'));
    for (const view of ['start', 'anzeige', 'profil', 'ki-profil', 'wissen', 'memory', 'konto', 'system', 'admin-uebersicht', 'admin-zugang', 'admin-betrieb']) {
      await page.click('#tab-' + view);
      assert.deepEqual(await smallText(page), [], 'no text below 11 px in ' + view);
    }
    assert.deepEqual(errors, []);
    await page.close();

    // Phone: the tabs scroll sideways, the page never does.
    ({page, errors} = await open(browser, 1, '#admin-betrieb', {width: 390, height: 844}));
    await page.waitForFunction(() => !document.getElementById('adminNavGroup').hidden);
    for (const view of ['admin-betrieb', 'admin-benutzer', 'start', 'ki-profil', 'konto', 'wissen']) {
      await page.click('#tab-' + view);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'no sideways scroll in ' + view);
      const box = await page.locator('#tab-' + view).boundingBox();
      assert.ok(box.x >= 0 && box.x + box.width <= 391, 'the open tab is in sight: ' + view);
    }
    assert.equal(await page.$eval('#newUserLevel option[value="1"]', o => o.disabled), true, 'an Admin cannot create admins');
    await page.click('#tab-admin-benutzer');
    await page.waitForFunction(() => document.querySelectorAll('#userRows > tr').length === 3);
    assert.deepEqual(await page.$$eval('#userRows > tr', rows => rows.map(r => !!r.querySelector('.user-delete-toggle'))), [false, true, true],
      'an Admin deletes guests and Nutzer, never the Inhaber');
    await page.waitForFunction(() => document.querySelectorAll('#userRows .model-access-cell').length === 3);
    assert.deepEqual(await page.$$eval('#userRows .model-access-cell', cells => cells.map(c => c.textContent)),
      ['alle (Inhaber)', 'Standard-Core', 'nur Inhaber'], 'an Admin cannot release models');
    assert.deepEqual(errors, []);
    await page.close();

    // The old admin page lands on the matching tab.
    const srv2 = server(0);
    page = await browser.newPage();
    await page.route('http://coreui.test/**', route => srv2.route(route));
    await page.goto('http://coreui.test/admin/index.html#users');
    await page.waitForURL(/settings\.html#admin-benutzer$/);
    await page.close();

    // Markup and wiring.
    const html = fs.readFileSync(path.join(root, 'settings.html'), 'utf8');
    assert(html.indexOf('coreui-settings-tabs.js') < html.indexOf('js/settings.js') && html.indexOf('js/settings.js') < html.indexOf('js/admin.js'),
      'tabs first, then settings, then admin');
    assert(/<link rel="stylesheet" href="\.\/css\/settings\.css\?v=\d+" \/>/.test(html), 'settings stylesheet');
    assert(!fs.existsSync(path.join(root, 'css/admin.css')), 'the old admin stylesheet is gone');
    console.log('CoreAI settings tabs: one view at a time, address, tiles, keys, Admin Core by role, admin load and save, no Core in the KI-Profil, readable sizes, phone width, old admin link OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
