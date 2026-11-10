// CoreAI 0.6.86: Settings > Konto in a real browser (Playwright), modeled on the ChatGPT account page.
//   node scripts/account-ui-selftest.cjs
// A stand-in for api/profile.php keeps the state. Checks: "Allgemein" with Name, Benutzername,
// E-Mail, Plan and Erstellerprofil (planned, pencil disabled); the name dialog (unchanged or empty
// cannot be saved, the CoreAI keeps its name, Profil follows); "Benutzernamen bearbeiten" (note,
// rule text, the suggestion, capitals turned to lower case, invalid, reserved and taken refused,
// saved as @name, Escape and the backdrop close); the plan page (current plan with its rules,
// today's count, tools off on the server, four cards with "DEIN PLAN", "angepasst", back);
// migration 031 missing; a failed request; a phone without sideways scrolling and no text under 11px.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';

const PLANS = [
  {key: 'free', label: 'Free', tagline: 'Für den Einstieg: unbegrenzt schreiben, mit Websuche.',
    features: ['Unbegrenzte Textchats', 'Websuche', 'Ohne Recherche im Browser, Python und Bilderstellung', 'Kontextfenster bis 8K']},
  {key: 'go', label: 'Go', tagline: 'Mehr Gedächtnis im Chat und Bilder erstellen.',
    features: ['Unbegrenzte Textchats', 'Websuche und Bilderstellung', 'Ohne Recherche im Browser und Python', 'Kontextfenster bis 16K']},
  {key: 'plus', label: 'Plus', tagline: 'Das volle Werkzeugpaket.',
    features: ['Unbegrenzte Textchats', 'Alle Werkzeuge: Websuche, Recherche im Browser, Python und Bilderstellung', 'Kontextfenster bis 64K']},
  {key: 'pro', label: 'Pro', tagline: 'Alles, mit dem größten Kontextfenster dieses Servers.',
    features: ['Unbegrenzte Textchats', 'Alle Werkzeuge: Websuche, Recherche im Browser, Python und Bilderstellung', 'Größtes Kontextfenster dieses Servers (128K)']},
];

function accountServer(overrides) {
  const st = Object.assign({
    display_name: 'Lena Berg', assistant_name: 'Mara', email: 'lena.berg@example.test', guest: false,
    username: null, username_ready: true, taken: ['lena.b'], fail: false,
    plan: {key: 'plus', label: 'Plus', customized: false, daily_messages: null, used_today: null, max_num_ctx: 65536, role: 'Nutzer',
      features: PLANS[2].features, note: 'Deinen Plan legt der Inhaber oder ein Admin in Admin Core fest. Alle Pläne sind kostenlos.', server_off: [], plans: PLANS},
    posts: [],
  }, overrides || {});
  const account = () => ({display_name: st.display_name, email: st.email, guest: st.guest, username: st.username, username_ready: st.username_ready,
    username_suggestion: st.username ? '' : 'lena.berg', plan: st.plan, plan_ready: true});
  const profile = () => ({display_name: st.display_name, assistant_name: st.assistant_name, avatars: {}});
  function handle(method, body, url) {
    if (method === 'GET') {
      if (url.searchParams.get('view') === 'account') {
        if (st.fail) return {status: 500, json: {ok: false, error: 'profile_failed'}};
        return {status: 200, json: {ok: true, csrf_token: 'tok', account: account()}};
      }
      return {status: 200, json: {ok: true, csrf_token: 'tok', profile: profile()}};
    }
    st.posts.push(body);
    if (body.action === 'display_name') {
      if (!String(body.display_name || '').trim()) return {status: 400, json: {ok: false, error: 'invalid_display_name'}};
      st.display_name = body.display_name;
      return {status: 200, json: {ok: true, profile: profile(), account: account()}};
    }
    if (body.action === 'username') {
      const name = String(body.username || '').toLowerCase().replace(/^@/, '');
      if (!/^[a-z0-9._-]{3,20}$/.test(name)) return {status: 400, json: {ok: false, error: 'username_invalid'}};
      if (['admin', 'mara', 'coreai'].includes(name)) return {status: 400, json: {ok: false, error: 'username_reserved'}};
      if (st.taken.includes(name)) return {status: 400, json: {ok: false, error: 'username_taken'}};
      st.username = name;
      return {status: 200, json: {ok: true, account: account()}};
    }
    return {status: 200, json: {ok: true, profile: profile()}};
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena.berg@example.test', display_name: 'Lena Berg', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'account_security.php': {ok: true, sessions: [], csrf_token: 'tok'},
  'health.php': {ok: true, version: '0.6.86', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function serve(context, server) {
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel === 'api/profile.php') {
      // The page's first profile load answers only after the name was saved (a slow line):
      // the older answer must not put the old name back.
      if (server.st.holdProfile && req.method() === 'GET' && url.searchParams.get('view') !== 'account') {
        const early = server.handle('GET', null, url);
        for (let i = 0; i < 150 && !server.st.posts.some(p => p.action === 'display_name'); i++) await new Promise(r => setTimeout(r, 100));
        await new Promise(r => setTimeout(r, 80));
        return route.fulfill({status: early.status || 200, json: early.json});
      }
      const out = server.handle(req.method(), req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null, url);
      return route.fulfill({status: out.status || 200, json: out.json});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
}

async function openKonto(browser, server, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE'});
  await serve(context, server);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#konto');
  return {context, page, errors};
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // --- Overview --------------------------------------------------------------------------------------------
    let server = accountServer({holdProfile: true});
    let {context, page, errors} = await openKonto(browser, server);
    await page.waitForFunction(() => document.getElementById('acctName').textContent === 'Lena Berg');
    assert.deepEqual(await page.$$eval('#acctMain .sec-heading', l => l.map(x => x.textContent)), ['Allgemein']);
    assert.deepEqual(await page.$$eval('#acctMain .sec-row .sec-copy strong', l => l.map(x => x.textContent)),
      ['Name', 'Benutzername', 'E-Mail', 'Plan', 'Erstellerprofil'], 'the rows of the reference');
    assert.equal(await page.textContent('#acctUsername'), 'Nicht festgelegt');
    assert.equal(await page.textContent('#acctEmail'), 'lena.berg@example.test');
    assert.equal(await page.textContent('#acctPlan'), 'Plus');
    assert.match(await page.textContent('#acctRow-creator'), /IN VORBEREITUNG/);
    assert.equal(await page.isDisabled('#acctRow-creator .acct-edit'), true, 'Erstellerprofil cannot be edited yet');
    assert.equal(await page.isVisible('#settingsLogout'), true, 'sign out stays on the page');
    if (shots) await page.locator('#view-konto').screenshot({path: path.join(shots, 'account-overview.png')});

    // --- Name ------------------------------------------------------------------------------------------------------
    await page.click('#acctNameEdit');
    await page.waitForSelector('#acctNameDialog:not([hidden])');
    assert.equal(await page.inputValue('#acctNameInput'), 'Lena Berg');
    assert.equal(await page.isDisabled('#acctNameSave'), true, 'unchanged cannot be saved');
    await page.fill('#acctNameInput', '   ');
    assert.equal(await page.isDisabled('#acctNameSave'), true, 'empty cannot be saved');
    await page.fill('#acctNameInput', 'Lena Maria Berg');
    await page.click('#acctNameSave');
    await page.waitForSelector('#acctNameDialog', {state: 'hidden'});
    assert.deepEqual(server.st.posts.pop(), {action: 'display_name', display_name: 'Lena Maria Berg'});
    assert.equal(await page.textContent('#acctName'), 'Lena Maria Berg');
    assert.equal(await page.textContent('#acctStatus'), 'Name gespeichert.');
    await page.waitForTimeout(300); // the held first profile load arrives now
    assert.equal(await page.textContent('#acctName'), 'Lena Maria Berg', 'a slower older load does not put the old name back');
    assert.equal(await page.inputValue('#profileDisplayName'), 'Lena Maria Berg', 'Profil follows');
    assert.equal(await page.inputValue('#assistantDisplayName'), 'Mara', 'the CoreAI keeps its name');

    // --- Benutzername ----------------------------------------------------------------------------------------------
    await page.click('#acctUsernameEdit');
    await page.waitForSelector('#acctUsernameDialog:not([hidden])');
    assert.equal(await page.textContent('#acctUsernameTitle'), 'Benutzernamen bearbeiten');
    assert.match(await page.textContent('#acctUsernameDialog .acct-note'), /einmalig/);
    assert.match(await page.textContent('#acctUsernameDialog'), /Verwende 3 bis 20 Kleinbuchstaben, Zahlen, Punkte, Unterstriche oder Bindestriche\./);
    assert.equal(await page.inputValue('#acctUsernameInput'), 'lena.berg', 'the suggestion');
    assert.equal(await page.isDisabled('#acctUsernameSave'), false, 'a suggestion can be taken over');
    if (shots) await page.locator('#acctUsernameDialog .sec-confirm-box').screenshot({path: path.join(shots, 'account-username.png')});
    await page.fill('#acctUsernameInput', '');
    await page.type('#acctUsernameInput', 'AB');
    assert.equal(await page.inputValue('#acctUsernameInput'), 'ab', 'capitals become lower case');
    assert.equal(await page.isDisabled('#acctUsernameSave'), true);
    assert.match(await page.textContent('#acctUsernameError'), /3 bis 20/);
    await page.fill('#acctUsernameInput', 'lena berg');
    assert.equal(await page.isDisabled('#acctUsernameSave'), true, 'no spaces');
    await page.fill('#acctUsernameInput', 'mara');
    await page.click('#acctUsernameSave');
    await page.waitForFunction(() => document.getElementById('acctUsernameError').textContent === 'Dieser Benutzername ist reserviert.');
    await page.fill('#acctUsernameInput', 'lena.b');
    await page.click('#acctUsernameSave');
    await page.waitForFunction(() => document.getElementById('acctUsernameError').textContent === 'Dieser Benutzername ist schon vergeben.');
    await page.fill('#acctUsernameInput', 'lena_berg');
    await page.click('#acctUsernameSave');
    await page.waitForSelector('#acctUsernameDialog', {state: 'hidden'});
    assert.deepEqual(server.st.posts.pop(), {action: 'username', username: 'lena_berg'});
    assert.equal(await page.textContent('#acctUsername'), '@lena_berg');
    assert.equal(await page.textContent('#acctStatus'), 'Benutzername gespeichert: @lena_berg');
    // The same name again cannot be saved; Escape and the backdrop close the dialog.
    await page.click('#acctUsernameEdit');
    await page.waitForSelector('#acctUsernameDialog:not([hidden])');
    assert.equal(await page.inputValue('#acctUsernameInput'), 'lena_berg');
    assert.equal(await page.isDisabled('#acctUsernameSave'), true);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#acctUsernameDialog', {state: 'hidden'});
    await page.click('#acctNameEdit');
    await page.waitForSelector('#acctNameDialog:not([hidden])');
    await page.mouse.click(5, 5);
    await page.waitForSelector('#acctNameDialog', {state: 'hidden'});

    // --- Plan --------------------------------------------------------------------------------------------------------
    await page.click('#acctPlanOpen');
    await page.waitForSelector('#acctSub-plan:not([hidden])');
    assert.equal(await page.isVisible('#acctMain'), false);
    assert.equal(await page.textContent('#acctPlanName'), 'Plus');
    assert.deepEqual(await page.$$eval('#acctPlanFeatures li', l => l.map(x => x.textContent)), PLANS[2].features);
    assert.equal(await page.isVisible('#acctPlanUsage'), false, 'no daily limit, no count');
    assert.deepEqual(await page.$$eval('.acct-plan-card .acct-plan-head strong', l => l.map(x => x.textContent)), ['Free', 'Go', 'Plus', 'Pro']);
    assert.deepEqual(await page.$$eval('.acct-plan-card.is-current', l => l.map(x => x.getAttribute('data-plan'))), ['plus']);
    assert.equal(await page.textContent('.acct-plan-card.is-current .acct-plan-badge'), 'DEIN PLAN');
    assert.doesNotMatch(await page.textContent('#acctSub-plan'), /€|Preis/, 'no prices');
    if (shots) await page.locator('#view-konto').screenshot({path: path.join(shots, 'account-plan.png')});
    await page.click('#acctPlanBack');
    await page.waitForSelector('#acctMain:not([hidden])');
    assert.deepEqual(errors, []);
    await context.close();

    // A changed plan with a daily limit and a tool off on the server.
    server = accountServer({username: 'lena_berg', plan: {key: 'go', label: 'Go (angepasst)', customized: true, daily_messages: 30, used_today: 4,
      max_num_ctx: 16384, role: 'Nutzer', features: ['Bis zu 30 Nachrichten pro Tag', 'Websuche und Bilderstellung', 'Ohne Recherche im Browser und Python', 'Kontextfenster bis 16K'],
      note: 'Deinen Plan legt der Inhaber oder ein Admin in Admin Core fest. Alle Pläne sind kostenlos.', server_off: ['Bilderstellung'], plans: PLANS}});
    ({context, page, errors} = await openKonto(browser, server));
    await page.waitForFunction(() => document.getElementById('acctPlan').textContent === 'Go (angepasst)');
    assert.equal(await page.textContent('#acctUsername'), '@lena_berg');
    await page.click('#acctPlanOpen');
    assert.equal(await page.textContent('#acctPlanUsage'), 'Heute gesendet: 4 von 30 Nachrichten. Das Tageslimit zählt 24 Stunden ab der ersten Nachricht.');
    assert.equal(await page.textContent('#acctPlanServerOff'), 'Auf diesem Server gerade für alle aus: Bilderstellung.');
    assert.equal(await page.textContent('.acct-plan-card.is-current .acct-plan-badge'), 'DEIN PLAN (ANGEPASST)');
    assert.deepEqual(errors, []);
    await context.close();

    // Migration 031 missing, then a failed request.
    ({context, page, errors} = await openKonto(browser, accountServer({username_ready: false})));
    await page.waitForFunction(() => document.getElementById('acctUsername').textContent === 'Update fehlt (Migration 031)');
    assert.equal(await page.isVisible('#acctUsernameEdit'), false);
    await context.close();
    ({context, page, errors} = await openKonto(browser, accountServer({fail: true})));
    await page.waitForFunction(() => /Konto nicht erreichbar/.test(document.getElementById('acctStatus').textContent));
    assert.deepEqual(errors, []);
    await context.close();

    // --- Phone -------------------------------------------------------------------------------------------------------------
    ({context, page, errors} = await openKonto(browser, accountServer({username: 'lena_berg'}), {width: 390, height: 844}));
    await page.waitForFunction(() => document.getElementById('acctUsername').textContent === '@lena_berg');
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(wide <= 1, 'no sideways scrolling on a phone: ' + wide);
    const tiny = await page.$$eval('#view-konto *', els => els.filter(e => e.offsetParent !== null && e.childNodes.length
      && Array.from(e.childNodes).some(n => n.nodeType === 3 && n.textContent.trim()) && parseFloat(getComputedStyle(e).fontSize) < 11).map(e => e.textContent.trim()));
    assert.deepEqual(tiny, [], 'no text under 11px');
    if (shots) await page.screenshot({path: path.join(shots, 'account-phone.png'), fullPage: true});
    await page.click('#acctUsernameEdit');
    await page.waitForSelector('#acctUsernameDialog:not([hidden])');
    const box = await page.locator('#acctUsernameDialog .sec-confirm-box').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 390, 'the dialog fits the phone');
    if (shots) await page.screenshot({path: path.join(shots, 'account-phone-dialog.png')});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI account UI: Allgemein like the reference (Name, Benutzername, E-Mail, Plan, Erstellerprofil planned), name dialog, '
    + 'Benutzernamen bearbeiten (suggestion, lower case, invalid, reserved, taken, Escape, backdrop), plan page (rules, today, server off, four cards, '
    + 'angepasst, no prices), migration missing, failed request, phone OK');
})().catch(error => { console.error(error); process.exit(1); });
