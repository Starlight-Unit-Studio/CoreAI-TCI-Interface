// CoreAI 0.6.71: settings IA, expert mode, mobile category drawer, and
// standalone Admin Core with the full existing functionality.
// Browser regression (Playwright): node scripts/settings-tabs-ui-selftest.cjs
// The mocked APIs enforce role distinctions and test actual save actions.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

function server(level) {
  const calls = [];
  const generalState = {auto_higher_core: false, thinking_level: ''};
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
        memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null, main_language: 'en'},
      limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192, 16384, 32768, 65536, 131072], num_ctx_available: true,
        language_available: true},
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
        {id: 3, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: 0, session_count: 2, login_count: 2}],
        // 0.5.85: own rules (Python blocked for Lena) and the global switches (browser off for everyone).
        policies: {3: {tools: {web: true, browse: true, python: false}, daily_messages: 40, max_num_ctx: 16384, used_today: 12}},
        tools: {web: true, browse: false, python: true}, policy_ready: true, ctx_choices: [4096, 8192, 16384, 32768, 65536, 131072]};
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
      if (key === 'user_settings.php' && url.searchParams.has('general') && !post)
        return route.fulfill({json:{ok:true,csrf_token:'tok',general:generalState,model:'ember-core:e4b',core_label:'TCI Core Normal',
          available:true,auto_core_allowed:true,reasoning:{levels:['low','medium','high'],metadata_available:true,supports_on_off:false}}});
      if (key === 'user_settings.php' && post && JSON.parse(post).action === 'general') {
        Object.assign(generalState, JSON.parse(post).settings);
        return route.fulfill({json:{ok:true,general:generalState}});
      }
      if (key === 'admin.php' && post && level > 1) return route.fulfill({status: 403, json: {ok: false, error: 'insufficient_permissions'}});
      if (key === 'admin.php' && post && url.searchParams.get('action') === 'model_access_save') {
        return route.fulfill({json: {ok: true, models: JSON.parse(post).models}});
      }
      if (key === 'admin.php' && post && ['user_password', 'user_logout_all'].includes(url.searchParams.get('action'))) {
        return route.fulfill({json: {ok: true, logins_ended: 2}});
      }
      if (key === 'admin.php' && post && url.searchParams.get('action') === 'user_policy') {
        const sent = JSON.parse(post);
        return route.fulfill({json: {ok: true, policy: {tools: Object.assign({web: true, browse: true, python: true}, sent.tools || {}),
          daily_messages: 'daily_messages' in sent ? sent.daily_messages : 40, max_num_ctx: 'max_num_ctx' in sent ? sent.max_num_ctx : 16384}}});
      }
      if (key === 'admin.php' && post && url.searchParams.get('action') === 'user_identity') {
        const sent = JSON.parse(post);
        return route.fulfill({json: {ok: true, user: {username: sent.username, display_name: sent.display_name, email_changed: sent.username !== 'lena@example.test'}}});
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

async function openAdmin(browser, level, hash, viewport) {
  const existing = await open(browser, level, '', viewport);
  await existing.page.goto('http://coreui.test/admin/index.html' + (hash || ''));
  if (level <= 1) {
    await existing.page.waitForFunction(() =>
      document.getElementById('adminShell') && !document.getElementById('adminShell').hidden);
  } else {
    await existing.page.waitForFunction(() =>
      document.getElementById('adminAccessMessage') &&
      document.getElementById('adminAccessMessage').textContent.includes('nicht freigegeben'));
  }
  return existing;
}

// 0.6.80: a switch is used like a person does: a tap on its visible track (the <i>), which only
// works when a <label> carries it. The 1 px checkbox underneath is never clicked directly.
async function setSwitch(page, id, on) {
  if (await page.isChecked('#' + id) === on) return;
  await page.click('#' + id + ' + i');
  await page.waitForFunction(([target, value]) => document.getElementById(target).checked === value, [id, on]);
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
    // All categories stay accessible; Expert only toggles technical fields
    // in their original categories and never removes existing settings.
    let {page, srv, errors} = await open(browser, 4, '#admin-benutzer');
    assert.deepEqual(await visibleViews(page), ['view-start']);
    assert.equal(await page.locator('#adminLinkGroup').isHidden(), true, 'no Admin Core link for a Nutzer');
    assert.equal(await page.locator('#settingsAdminShortcut').isHidden(), true,
      'a regular account never sees the header Admin shortcut');
    assert.equal(await page.locator('.cfg-header-expert').count(), 1,
      'one Expert switch remains accessible in the page header');
    assert.equal(await page.locator('#adminNavGroup').count(), 0, 'no embedded Admin Core tabs');
    assert.equal(await page.isChecked('#settingsExpertToggle'), false, 'expert mode starts disabled');
    assert.equal(await page.locator('#cfgCoreGroup').isHidden(), false, 'CoreAI categories always visible');
    assert.equal(await page.locator('#tab-ki-profil').isHidden(), false);
    assert.equal(await page.locator('#tab-wissen').isHidden(), false);
    assert.equal(await page.locator('#tab-memory').isHidden(), false);
    assert.equal(await page.locator('#generalReasoningLevels').isVisible(), true,
      'Denkaufwand is immediately visible in General without Expert');
    await page.waitForFunction(() => !document.getElementById('generalHigherIntelligence').disabled);
    assert.equal(await page.locator('#generalUiLanguage').isDisabled(), false, 'UI language can change');
    await page.selectOption('#generalUiLanguage', 'en');
    assert.equal(await page.evaluate(() => document.documentElement.lang), 'en', 'English applies to the interface');
    assert.equal(await page.locator('#tab-start span').textContent(), 'General', 'navigation is translated');
    await page.selectOption('#generalUiLanguage', 'de');
    assert.equal(await page.locator('#tab-start span').textContent(), 'Allgemein', 'switch back to German');
    assert.equal(await page.locator('#generalHigherIntelligence').isDisabled(), false,
      'authenticated accounts can enable the real auto-Core routing');
    // 0.6.80: tap the visible switch like a person; it used to be a <span> that ignored taps.
    await setSwitch(page, 'generalHigherIntelligence', true);
    await page.waitForFunction(() =>
      document.getElementById('generalHigherIntelligence').checked &&
      !document.getElementById('generalHigherIntelligence').disabled);
    assert.ok(srv.calls.some(c => c.key === 'user_settings.php' && c.post &&
      c.post.action === 'general' && c.post.settings.auto_higher_core === true),
      'auto Core setting is actually saved through the server');
    await page.click('#tab-tastatur');
    assert.deepEqual(await visibleViews(page), ['view-tastatur'],
      'the actual keyboard shortcut moved to its own settings page');
    assert.equal(await page.inputValue('#generalSendKey'), 'enter', 'Enter remains the legacy default');
    await page.selectOption('#generalSendKey', 'mod-enter');
    assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('emberCoreUI.preferences.v1')).sendKey),
      'mod-enter', 'the real send shortcut is saved browser-locally');
    await page.reload();
    await page.waitForFunction(() => document.getElementById('startHealth').getAttribute('data-state') !== 'checking');
    assert.equal(await page.inputValue('#generalSendKey'), 'mod-enter', 'send shortcut survives reload');
    await page.click('#tab-start');
    assert.equal(srv.calls.filter(c => c.key === 'admin.php').length, 0, 'a Nutzer never asks the admin API');
    // Start view sums up the categories.
    assert.equal(await page.textContent('#startCore'), 'Mara Normal');
    assert.equal(await page.textContent('#startThinking'), 'Denken an · Standard-Core');
    assert.equal(await page.textContent('#startHealth'), 'Alles bereit');
    assert.equal(await page.textContent('#startKnowledge'), '1 Quelle');
    assert.equal(await page.textContent('#startKnowledgeChunks'), '42 Abschnitte');
    assert.equal(await page.textContent('#startMemory'), '2 Fakten');
    assert.equal(await page.textContent('#startRole'), 'NUTZER');
    // Existing controls live in their actual settings categories.
    await page.click('#tab-sicherheit');
    assert.deepEqual(await visibleViews(page), ['view-sicherheit']);
    assert.equal(await page.locator('#changePassword').isVisible(), true);
    assert.equal(await page.locator('#loginSessionList').isVisible(), true);
    await page.click('#tab-datenkontrollen');
    assert.equal(await page.locator('#exportAccountData').isVisible(), true,
      'existing private JSON export is a real Data controls action');
    await page.click('#tab-stimme');
    assert.equal(await page.locator('#voiceAutoReadRow').count(), 1);
    assert.equal(await page.locator('#voiceTermsRow').count(), 1);
    await page.click('#tab-memory');
    assert.equal(await page.locator('#saveMemoryAiSettings').isVisible(), true);
    await page.check('#aiMemoryEnabled');
    await page.click('#saveMemoryAiSettings');
    await page.waitForFunction(() => !document.getElementById('saveMemoryAiSettings').disabled);
    assert(srv.calls.some(c => c.key === 'user_settings.php' && c.post &&
      c.post.memory_enabled === true),
      'moved Memory toggle uses the existing account settings API');
    await page.click('#tab-start');
    await page.click('#settingsMenuToggle');
    assert.equal(await page.locator('body').evaluate(node => node.classList.contains('cfg-nav-collapsed')),
      true, 'desktop sidebar can collapse while settings content stays visible');
    assert.equal(await page.locator('#view-start').isVisible(), true);
    await page.click('#settingsMenuToggle');
    assert.equal(await page.locator('body').evaluate(node => node.classList.contains('cfg-nav-collapsed')),
      false, 'desktop sidebar expands without navigating away');
    // Planned categories are reachable without pretending to save settings.
    await page.click('#tab-benachrichtigungen');
    assert.deepEqual(await visibleViews(page), ['view-benachrichtigungen']);
    assert.match(await page.textContent('#view-benachrichtigungen'), /IN VORBEREITUNG/);
    await page.fill('#settingsNavSearch', 'Cloud-Computer');
    assert.equal(await page.locator('#tab-cloud-computer').isHidden(), false, 'search finds planned categories');
    assert.equal(await page.locator('#tab-benachrichtigungen').isHidden(), true, 'search filters unrelated entries');
    await page.fill('#settingsNavSearch', '');
    await page.click('#tab-start');
    await setSwitch(page, 'settingsExpertToggle', true);
    assert.equal(await page.locator('#generalReasoningLevels').isVisible(), true,
      'Denkaufwand stays visible even after Expert is enabled');
    assert.equal(await page.locator('#generalReasoningLevels').isDisabled(), false,
      'Ollama-declared reasoning levels can be selected');
    await page.selectOption('#generalReasoningLevels', 'medium');
    await page.waitForFunction(() =>
      document.getElementById('generalReasoningLevels').value === 'medium' &&
      !document.getElementById('generalReasoningLevels').disabled);
    assert.ok(srv.calls.some(c => c.key === 'user_settings.php' && c.post &&
      c.post.action === 'general' && c.post.settings.thinking_level === 'medium'),
      'chosen model-supported effort is persisted server-side');
    // Tabs switch in place and the address keeps the tab.
    await page.click('#tab-ki-profil');
    assert.deepEqual(await visibleViews(page), ['view-ki-profil']);
    assert.equal(await page.locator('#aiNumPredict').isVisible(), true,
      'existing technical Core values are visible in Expert mode');
    const budget = await page.inputValue('#aiNumPredict');
    await setSwitch(page, 'settingsExpertToggle', false);
    assert.deepEqual(await visibleViews(page), ['view-ki-profil'], 'Expert switch does not leave the category');
    assert.equal(await page.locator('#aiNumPredict').isVisible(), false, 'raw token budget hidden in normal mode');
    assert.equal(await page.locator('#aiTemperature').isVisible(), false, 'raw temperature hidden in normal mode');
    assert.equal(await page.locator('#aiNumCtx').isVisible(), true,
      'smart context slider remains available to every account');
    assert.equal(await page.locator('#aiCtxDefault').isVisible(), true,
      'the smart Core-standard context option is ordinary');
    assert.equal(await page.inputValue('#aiNumPredict'), budget, 'hidden technical value is not reset');
    await page.click('#tab-anzeige');
    assert.equal(await page.locator('#prefShowTelemetry').isVisible(), true,
      'ordinary telemetry display toggle is visible without Expert');
    assert.equal(await page.locator('#prefLiveFrameMotion').isVisible(), true,
      'ordinary browser-frame playback toggle is visible without Expert');
    await page.click('#tab-ki-profil');
    await setSwitch(page, 'settingsExpertToggle', true);
    assert.equal(await page.locator('#aiNumPredict').isVisible(), true, 'technical field comes back');
    assert.equal(new URL(page.url()).hash, '#ki-profil');
    assert.equal(await page.getAttribute('#tab-ki-profil', 'aria-selected'), 'true');
    assert.equal(await page.getAttribute('#tab-start', 'aria-selected'), 'false');
    await page.focus('#tab-ki-profil'); // the tap on the Expert switch moved the focus there
    await page.keyboard.press('ArrowDown');
    assert.deepEqual(await visibleViews(page), ['view-wissen'], 'arrow keys move across advanced tabs');
    await page.keyboard.press('ArrowDown');
    assert.deepEqual(await visibleViews(page), ['view-memory'], 'and on to the next advanced category');
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
    // 0.6.10: the main language shows what the account saved and is sent along.
    await page.waitForFunction(() => document.getElementById('aiLanguage').value === 'en');
    assert.equal(await page.isDisabled('#aiLanguage'), false, 'the language can be chosen');
    await page.selectOption('#aiLanguage', 'de');
    await page.click('#saveAiSettings');
    await page.waitForFunction(() => document.getElementById('settingsSaveState').classList.contains('visible'));
    // The KI-Profil save is the newest POST (Allgemein and Gedächtnis posted earlier in this run).
    const aiPost = srv.calls.filter(c => c.key === 'user_settings.php' && c.post && c.post.action !== 'general').pop();
    assert(aiPost && !('model_override' in aiPost.post) && aiPost.post.provider === 'local', 'KI-Profil saves without a Core');
    assert.equal(aiPost.post.main_language, 'de', 'the chosen language is saved');
    assert.deepEqual(errors, []);
    await page.close();

    // The Inhaber gets a visible, role-gated shortcut without scrolling the
    // categories drawer. Following it still requires the Admin Core gate.
    ({page, srv, errors} = await open(browser, 0, '#start'));
    await page.waitForFunction(() => !document.getElementById('settingsAdminShortcut').hidden);
    assert.equal(await page.locator('#adminLinkGroup').isHidden(), false,
      'owner still has the category link');
    // The click navigates to another page: wait for it first, then for its content.
    await Promise.all([page.waitForURL(/\/admin\/index\.html/), page.click('#settingsAdminShortcut')]);
    await page.waitForFunction(() => document.getElementById('adminNavGroup') && !document.getElementById('adminNavGroup').hidden);
    assert.match(page.url(), /\/admin\/index\.html/,
      'header opens the separate, authorized Admin Core');
    assert.deepEqual(errors, []);
    await page.close();

    // The Inhaber: a single Admin Core link leads to a separate authorized page.
    ({page, srv, errors} = await openAdmin(browser, 0, '#admin-benutzer'));
    await page.waitForFunction(() => !document.getElementById('adminNavGroup').hidden);
    await page.waitForFunction(() => document.querySelectorAll('#userRows tr').length === 3);
    assert.deepEqual(await visibleViews(page), ['view-admin-benutzer'], 'the admin address opens after the role check');
    assert.equal(await page.locator('#adminAccessGate').isHidden(), true, 'admin shell appears only after authorization');
    assert.equal(await page.textContent('#adminIdentity'), 'patrick@starlight.test // INHABER');
    assert.deepEqual(await page.$$eval('[data-stat="users"]', nodes => nodes.map(n => n.textContent)), ['12'],
      'the admin overview receives the user count');
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
    // 0.5.85: BEARBEITEN: name and e-mail, password, signing out everywhere.
    assert.deepEqual(await page.$$eval('#userRows > tr', rows => rows.map(r => !!r.querySelector('.user-edit-toggle'))), [true, true, true],
      'BEARBEITEN for everyone the Inhaber manages, and for himself');
    await page.click('#userRows tr:nth-child(3) .user-edit-toggle');
    assert.deepEqual(await page.$$eval('.user-edit-section h4', h => h.map(x => x.textContent)), ['Name und E-Mail', 'Passwort und Anmeldungen', 'Werkzeuge', 'Limits']);
    // Limits: 40 messages a day (12 sent today), window up to 16K.
    assert.match(await page.textContent('.user-edit-row'), /Heute gesendet: 12 von 40\./);
    assert.equal(await page.inputValue('.user-edit-row input[type="number"]'), '40');
    assert.equal(await page.inputValue('.user-edit-row select'), '16384');
    assert.deepEqual(await page.$$eval('.user-edit-row select option', o => o.map(x => x.textContent)),
      ['ohne Grenze', 'bis 4K', 'bis 8K', 'bis 16K', 'bis 32K', 'bis 64K', 'bis 128K']);
    await page.fill('.user-edit-row input[type="number"]', '');
    await page.selectOption('.user-edit-row select', '32768');
    await page.click('.user-edit-row button:has-text("LIMITS SPEICHERN")');
    await page.waitForFunction(() => /kein Tageslimit, Kontextfenster bis 32K\./.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.filter(c => c.action === 'user_policy').pop().post, {user_id: 3, daily_messages: null, max_num_ctx: 32768});
    // Werkzeuge: Python blocked for Lena, the browser is off for everyone.
    assert.deepEqual(await page.$$eval('.user-tool-choice', l => l.map(x => [x.textContent, x.querySelector('input').checked, x.querySelector('input').disabled])),
      [['Websuche', true, false], ['Browser (global aus)', true, true], ['Python', false, false], ['Bilder', true, false]]);
    await page.click('.user-tool-choice:has-text("Websuche") input');
    await page.click('.user-edit-row button:has-text("WERKZEUGE SPEICHERN")');
    await page.waitForFunction(() => /gesperrt: Websuche, Python\./.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.find(c => c.action === 'user_policy' && c.post.tools).post, {user_id: 3, tools: {web: false, browse: true, python: false, image: true}});
    assert.match(await page.textContent('.user-edit-row'), /Aktive Anmeldungen: 2\./);
    assert.equal(await page.inputValue('.user-edit-row input[type="email"]'), 'lena@example.test');
    await page.fill('.user-edit-row input[type="text"]', 'Lena Berg');
    await page.fill('.user-edit-row input[type="email"]', 'lena.berg@example.test');
    await page.click('.user-edit-row button:has-text("NAME UND E-MAIL SPEICHERN")');
    await page.waitForFunction(() => /Anmeldung ab jetzt mit lena\.berg@example\.test/.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.find(c => c.action === 'user_identity').post, {user_id: 3, display_name: 'Lena Berg', username: 'lena.berg@example.test'});
    await page.waitForFunction(() => document.querySelectorAll('#userRows > tr').length === 3);
    await page.click('#userRows tr:nth-child(3) .user-edit-toggle');
    const setPass = '.user-edit-row button:has-text("PASSWORT SETZEN")';
    await page.fill('.user-edit-row input[type="password"]', 'zu-kurz');
    assert.equal(await page.isDisabled(setPass), true, 'no password below 12 characters');
    await page.fill('.user-edit-row input[type="password"]', 'ein-neues-passwort');
    assert.deepEqual(await smallText(page), [], 'no text below 11 px in the edit panel');
    await page.click(setPass);
    await page.waitForFunction(() => /neues Passwort gesetzt, 2 Anmeldungen beendet/.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.find(c => c.action === 'user_password').post, {user_id: 3, password: 'ein-neues-passwort'});
    await page.waitForFunction(() => document.querySelectorAll('#userRows > tr').length === 3);
    await page.click('#userRows tr:nth-child(3) .user-edit-toggle');
    await page.click('.user-edit-row button:has-text("ALLE GERÄTE ABMELDEN")');
    await page.waitForFunction(() => /2 Anmeldungen beendet/.test(document.getElementById('adminFlash').textContent));
    assert.deepEqual(srv.calls.find(c => c.action === 'user_logout_all').post, {user_id: 3});
    await page.waitForFunction(() => document.querySelectorAll('#userRows > tr').length === 3);
    // The own account: only name and e-mail here; a guest has no password field.
    await page.click('#userRows tr:nth-child(1) .user-edit-toggle');
    assert.equal(await page.locator('.user-edit-row input[type="password"]').count(), 0, 'no password field for the own account');
    assert.equal(await page.locator('.user-tool-choice').count(), 0, 'no tool blocks for the own account');
    assert.match(await page.textContent('.user-edit-row'), /im Tab Konto/);
    await page.click('#userRows tr:nth-child(1) .user-edit-toggle');
    assert.equal(await page.locator('.user-edit-row').count(), 0, 'the panel closes again');
    await page.click('#userRows tr:nth-child(2) .user-edit-toggle');
    assert.equal(await page.locator('.user-edit-row input[type="password"]').count(), 0, 'guests have no password');
    assert.equal(await page.isDisabled('.user-edit-row input[type="email"]'), true, 'a guest keeps its login');
    await page.click('#userRows tr:nth-child(2) .user-edit-toggle');
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
    assert.equal(Object.keys(save.post.settings).length, 18);
    // Every admin category loads its data when its tab opens.
    await page.click('#tab-admin-audit');
    await page.waitForFunction(() => document.querySelectorAll('#auditRows .audit-item').length === 1);
    await page.click('#tab-admin-protokoll');
    await page.waitForFunction(() => document.getElementById('logOutput').textContent.includes('ok'));
    for (const view of ['start', 'admin-uebersicht', 'admin-zugang', 'admin-betrieb', 'admin-memory', 'admin-lore', 'admin-protokoll', 'admin-audit']) {
      await page.click('#tab-' + view);
      assert.deepEqual(await smallText(page), [], 'no text below 11 px in ' + view);
    }
    assert.deepEqual(errors, []);
    await page.close();

    // Phone: categories open as a drawer, selection closes it and the page
    // remains free from horizontal scrolling.
    ({page, errors} = await openAdmin(browser, 1, '#admin-betrieb', {width: 390, height: 844}));
    await page.waitForFunction(() => !document.getElementById('adminNavGroup').hidden);
    for (const view of ['admin-betrieb', 'admin-benutzer', 'admin-uebersicht', 'admin-lore']) {
      await page.click('#settingsMenuToggle');
      assert.equal(await page.getAttribute('#settingsMenuToggle', 'aria-expanded'), 'true');
      await page.click('#tab-' + view);
      assert.deepEqual(await visibleViews(page), ['view-' + view], 'mobile drawer opened ' + view);
      assert.equal(await page.getAttribute('#settingsMenuToggle', 'aria-expanded'), 'false', 'drawer closes on selection');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'no sideways scroll in ' + view);
    }
    assert.equal(await page.$eval('#newUserLevel option[value="1"]', o => o.disabled), true, 'an Admin cannot create admins');
    await page.click('#settingsMenuToggle'); // on a phone the categories sit in the closed drawer
    await page.click('#tab-admin-benutzer');
    await page.waitForFunction(() => document.querySelectorAll('#userRows > tr').length === 3);
    assert.deepEqual(await page.$$eval('#userRows > tr', rows => rows.map(r => !!r.querySelector('.user-delete-toggle'))), [false, true, true],
      'an Admin deletes guests and Nutzer, never the Inhaber');
    assert.deepEqual(await page.$$eval('#userRows > tr', rows => rows.map(r => !!r.querySelector('.user-edit-toggle'))), [false, true, true],
      'an Admin edits guests and Nutzer, never the Inhaber');
    await page.waitForFunction(() => document.querySelectorAll('#userRows .model-access-cell').length === 3);
    assert.deepEqual(await page.$$eval('#userRows .model-access-cell', cells => cells.map(c => c.textContent)),
      ['alle (Inhaber)', 'Standard-Core', 'nur Inhaber'], 'an Admin cannot release models');
    assert.deepEqual(errors, []);
    await page.close();

    // A regular account can open the standalone URL, but sees no admin data.
    ({page, srv} = await openAdmin(browser, 4, '', {width: 390, height: 844}));
    assert.equal(await page.locator('#adminShell').isHidden(), true);
    assert.match(await page.textContent('#adminAccessMessage'), /nicht freigegeben/);
    assert.equal(srv.calls.filter(c => c.key === 'admin.php').length, 0, 'denied without any admin API request');
    await page.close();

    // A legacy Admin bookmark keeps its destination on the standalone page.
    const srv2 = server(0);
    page = await browser.newPage();
    await page.route('http://coreui.test/**', route => srv2.route(route));
    await page.goto('http://coreui.test/admin/index.html#users');
    await page.waitForFunction(() => {
      const nav = document.getElementById('adminNavGroup');
      const view = document.getElementById('view-admin-benutzer');
      return nav && !nav.hidden && view && !view.hidden;
    });
    await page.close();

    // 0.6.73: guard the actual computed mobile layout, not just scrollWidth.
    // The old <=900px horizontal tab-bar styles once arranged Personal and
    // Teams side-by-side inside the new drawer and clipped the left edge.
    for (const width of [320, 390, 768, 900]) {
      const mobileRun = await open(browser, 4, '#start', {width, height: 760});
      const mobilePage = mobileRun.page;
      await mobilePage.click('#settingsMenuToggle');
      // The drawer slides in; measure once it has arrived (it fails below if it never does).
      await mobilePage.waitForFunction(() => document.getElementById('cfgNav').getBoundingClientRect().left >= -1,
        null, {timeout: 3000}).catch(() => {});
      const layout = await mobilePage.evaluate(() => {
        const nav = document.getElementById('cfgNav');
        const navRect = nav.getBoundingClientRect();
        const groups = Array.from(nav.querySelectorAll('.cfg-nav-group'))
          .filter(group => !group.hidden && getComputedStyle(group).display !== 'none');
        const tabs = Array.from(nav.querySelectorAll('.cfg-tab'))
          .filter(tab => !tab.hidden && getComputedStyle(tab).display !== 'none');
        const groupRects = groups.map(group => group.getBoundingClientRect());
        const tabRects = tabs.map(tab => tab.getBoundingClientRect());
        return {
          direction: getComputedStyle(nav).flexDirection,
          groupDirections: groups.map(group => getComputedStyle(group).flexDirection),
          navWidth: navRect.width,
          navLeft: navRect.left,
          viewport: innerWidth,
          overflowsX: nav.scrollWidth > nav.clientWidth + 1,
          topGroups: groupRects.map(rect => rect.top),
          tabOverflow: tabRects.some(rect => rect.left < navRect.left - 1 ||
            rect.right > navRect.right + 1),
          scrollbar: getComputedStyle(nav).overflowY,
        };
      });
      assert.equal(layout.direction, 'column', 'drawer must be vertical at width ' + width);
      assert(layout.groupDirections.every(value => value === 'column'),
        'every category group must be vertical at ' + width);
      assert.equal(layout.overflowsX, false, 'no horizontal content overflow at ' + width);
      assert.equal(layout.tabOverflow, false, 'all category tabs inside the drawer at ' + width);
      assert(layout.navLeft >= -1 && layout.navLeft + layout.navWidth <= layout.viewport + 1,
        'drawer is within the screen at ' + width);
      assert(layout.topGroups.every((top, index, all) => index === 0 || top >= all[index - 1]),
        'category groups stack downward at ' + width);
      assert.equal(layout.scrollbar, 'auto', 'mobile drawer scrolls vertically at ' + width);
      await mobilePage.click('#settingsMenuClose');
      assert.equal(await mobilePage.getAttribute('#settingsMenuToggle', 'aria-expanded'), 'false',
        'close restores the settings page at ' + width);
      // Exercise the same event path as real touch input without requiring an
      // Android browser: edge swipe right opens, swipe left on the nav closes.
      await mobilePage.evaluate(() => {
        function touch(target, type, x) {
          const ev = new Event(type, {bubbles:true});
          Object.defineProperty(ev, type === 'touchstart' ? 'touches' : 'changedTouches',
            {value:[{clientX:x, clientY:220}]});
          target.dispatchEvent(ev);
        }
        touch(document.body, 'touchstart', 12);
        touch(document.body, 'touchend', 128);
      });
      assert.equal(await mobilePage.getAttribute('#settingsMenuToggle', 'aria-expanded'), 'true',
        'swiping right from the edge opens categories at ' + width);
      await mobilePage.evaluate(() => {
        const nav = document.getElementById('cfgNav');
        function touch(type, x) {
          const ev = new Event(type, {bubbles:true});
          Object.defineProperty(ev, type === 'touchstart' ? 'touches' : 'changedTouches',
            {value:[{clientX:x, clientY:220}]});
          nav.dispatchEvent(ev);
        }
        touch('touchstart', 210);
        touch('touchend', 95);
      });
      assert.equal(await mobilePage.getAttribute('#settingsMenuToggle', 'aria-expanded'), 'false',
        'swiping left on the drawer closes categories at ' + width);
      assert.deepEqual(mobileRun.errors, [], 'no browser errors at width ' + width);
      await mobilePage.close();
    }

    // Markup and wiring.
    const html = fs.readFileSync(path.join(root, 'settings.html'), 'utf8');
    const adminHtml = fs.readFileSync(path.join(root, 'admin/index.html'), 'utf8');
    assert(html.indexOf('coreui-settings-menu.js') < html.indexOf('coreui-settings-tabs.js') &&
      html.indexOf('coreui-settings-tabs.js') < html.indexOf('js/settings.js') &&
      !html.includes('src="./js/admin.js'), 'user settings load no admin controller');
    assert(adminHtml.includes('id="adminAccessGate"') && adminHtml.includes('id="view-admin-audit"'),
      'dedicated admin page keeps its actions behind the account gate');
    assert(/<link rel="stylesheet" href="\.\/css\/settings\.css\?v=\d+" \/>/.test(html), 'settings stylesheet');
    assert(!fs.existsSync(path.join(root, 'css/admin.css')), 'no obsolete admin stylesheet');
    console.log('CoreAI settings IA: category search, expert mode, mobile drawer, standalone protected Admin Core, role permissions and management API: OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
