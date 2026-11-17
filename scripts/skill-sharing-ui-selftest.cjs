// CoreAI 0.7.84 (P12 B): shared skills, precedence and the skills of a chat, in a real browser (Playwright).
//   node scripts/skill-sharing-ui-selftest.cjs
// Settings > Skills with a stand-in for api/skill_packages.php: "Gemeinsam" with who shared a skill,
// its precedence and which skill serves instead, switching one off for oneself, reading one; the
// editor with precedence and, for the Inhaber and Admins, sharing (never a project skill).
// The chat: the composer as app.html has it with js/coreui-composer-menu.js and
// js/coreui-chat-skills.js: "Skills für diesen Chat" in the + menu, the window (groups, variants of
// one short name, at most three, search, gone skills, the server's reason), the fixed skills above
// the input field and taking one out, another chat, a new chat, guests, a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml'};
const shots = process.env.COREUI_SHOTS || '';
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const TOOLS = {web: 'Websuche', browse: 'Browser-Recherche', python: 'Python', image: 'Bilder', files: 'Projektdateien', run: 'Ausführen im Projekt'};
const PROJECT = 'e'.repeat(32);
const A = 'a'.repeat(32), B = 'b'.repeat(32), C = 'c'.repeat(32), D = 'd'.repeat(32);

// --- Settings ------------------------------------------------------------------------------------------
function settingsServer(canShare) {
  const st = {posts: [],
    own: [{id: A, slug: 'wochenbericht', title: 'Mein Wochenbericht', description: 'Die Woche', triggers: [], requires: [], project: null, enabled: true,
      files: [], missing: [], shared: false, priority: 'normal', body: 'Meine Anleitung.'}],
    shared: [
      {id: B, slug: 'wochenbericht', title: 'Wochenbericht Ines', description: 'Für das ganze Team', triggers: [], requires: ['python'], enabled: true,
        files: [{path: 'templates/bericht.md', kind: 'template', size_bytes: 9, content: '# Bericht'}], missing: [], shared: true, priority: 'high',
        owner: 'Ines', used: true, shadowed_by: {variant: 'own', title: 'Mein Wochenbericht'}, body: 'Schreibe <b>knapp</b>.'},
      {id: C, slug: 'protokoll', title: 'Protokoll', description: 'Sitzungsprotokoll', triggers: [], requires: [], enabled: true, files: [], missing: [],
        shared: true, priority: 'normal', owner: 'Admin', used: true, shadowed_by: null, body: 'Protokolliere.'}]};
  const strip = s => { const o = Object.assign({}, s); delete o.body; o.files = o.files.map(f => ({path: f.path, kind: f.kind, size_bytes: f.size_bytes})); return o; };
  function handle(method, u, body) {
    const action = u.searchParams.get('action');
    if (method === 'GET' && action === 'list') {
      return {json: {ok: true, ready: true, skills: st.own.map(strip), builtin: [{id: 'python', title: 'Python', category: 'Code', description: 'D', required: false, references: 0}],
        projects: [{id: PROJECT, name: 'Kunden'}], tools: TOOLS, sharing: true, can_share: canShare, shared: st.shared.map(strip), csrf_token: 'tok'}};
    }
    if (method === 'GET' && action === 'get') return {json: {ok: true, skill: st.own.find(s => s.id === u.searchParams.get('id'))}};
    if (method === 'GET' && action === 'view') return {json: {ok: true, skill: st.shared.find(s => s.id === u.searchParams.get('id'))}};
    st.posts.push(Object.assign({action}, body));
    if (action === 'use') {
      st.shared.find(s => s.id === body.id).used = !!body.on;
      return {json: {ok: true, shared: st.shared.map(strip)}};
    }
    if (action === 'save') {
      if (body.shared && !canShare) return {status: 403, json: {ok: false, error: 'skill_share_forbidden'}};
      const sk = st.own.find(s => s.id === body.id);
      Object.assign(sk, {priority: body.priority, shared: !!body.shared, project: body.project_id ? {id: PROJECT, name: 'Kunden'} : null});
      return {json: {ok: true, skill: sk}};
    }
    return {status: 404, json: {ok: false, error: 'unknown_action'}};
  }
  return {st, handle};
}
const api = {
  'auth.php': {ok: true, user_id: 9, username: 'ines@example.test', display_name: 'Ines', permission_level: 0, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Ines', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 0, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.84', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};
async function openSettings(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/skill_packages.php') {
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'changes with CSRF');
      const out = srv.handle(req.method(), u, req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null);
      return route.fulfill({status: out.status || 200, json: out.json});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#skills');
  await until(page, () => !document.getElementById('skMain').hidden && document.querySelectorAll('#skShared .sk-row').length > 0);
  return {context, page, errors};
}

// --- Chat -----------------------------------------------------------------------------------------------
const html = read('app.html');
const footerAt = html.indexOf('<footer class="composer">');
const footer = html.slice(footerAt, html.indexOf('</footer>', footerAt) + '</footer>'.length);
const HARNESS = `<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>t</title>
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css"><link rel="stylesheet" href="/css/coreui-chat-skills.css"></head>
<body style="margin:0"><div class="app-layout" style="height:100vh;overflow:hidden;display:flex;flex-direction:column;justify-content:flex-end">
<main class="messages" style="flex:1"><div class="messages-inner"></div></main>${footer}</div>
<script>window.__active = 'cs_one00001'; window.CoreAIChatApp = {active: function () { return window.__active ? {id: window.__active} : null; }};
window.switchChat = function (id) { window.__active = id; window.dispatchEvent(new CustomEvent('coreai:active-session', {detail: {id: id}})); };</script>
<script src="/js/coreui-composer-menu.js"></script><script src="/js/coreui-chat-skills.js"></script></body></html>`;
function chatServer(opts) {
  opts = opts || {};
  const base = () => [
    {ref: D, slug: 'kundenbrief', title: 'Kundenbrief', description: 'Für Kunden', variant: 'project', owner: '', priority: 'normal', requires: []},
    {ref: A, slug: 'wochenbericht', title: 'Mein Wochenbericht', description: 'Die Woche', variant: 'own', owner: '', priority: 'normal', requires: []},
    {ref: B, slug: 'wochenbericht', title: 'Wochenbericht Ines', description: 'Für das Team', variant: 'shared', owner: 'Ines', priority: 'high', requires: []},
    {ref: C, slug: 'protokoll', title: 'Protokoll', description: 'Sitzungsprotokoll', variant: 'shared', owner: 'Admin', priority: 'normal', requires: []},
    {ref: 'b:python', slug: 'python', title: 'Python', description: 'Skripte', variant: 'builtin', owner: '', priority: 'normal', requires: []},
    {ref: 'b:zip-delivery', slug: 'zip-delivery', title: 'ZIP-Lieferung', description: 'Archive', variant: 'builtin', owner: '', priority: 'normal', requires: []}];
  const st = {calls: [], chosen: {cs_one00001: [], cs_two00001: []}, gone: opts.gone || [], removed: []};
  function answer(session) {
    const chosen = st.chosen[session];
    const options = base().filter(o => !st.removed.includes(o.ref));
    const used = {};
    options.forEach(o => { if (!(o.slug in used)) used[o.slug] = o.ref; });
    chosen.forEach(r => { const o = options.find(x => x.ref === r); if (o) used[o.slug] = r; });
    if (!chosen.length) used.wochenbericht = A;
    return {ok: true, csrf_token: 'tok', options: options.map(o => Object.assign({}, o, {chosen: chosen.includes(o.ref), used: used[o.slug] === o.ref})),
      chosen: chosen.concat(st.gone), gone: st.gone, max: 3, project: true};
  }
  function handle(method, u, body) {
    if (opts.guest) return {status: 403, json: {ok: false, error: 'guests_not_allowed'}};
    const action = u.searchParams.get('action');
    st.calls.push(Object.assign({method, action, session: u.searchParams.get('session')}, body || {}));
    if (method === 'GET' && action === 'chat') return {json: answer(u.searchParams.get('session'))};
    if (method === 'POST' && action === 'chat_set') {
      if (body.refs.some(r => st.removed.includes(r))) return {status: 404, json: {ok: false, error: 'skill_not_found'}};
      st.gone = [];
      st.chosen[body.session] = body.refs.slice();
      return {json: answer(body.session)};
    }
    return {status: 404, json: {ok: false, error: 'unknown_action'}};
  }
  return {st, handle};
}
async function openChat(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1000, height: 820}});
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.route('http://localhost:8769/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.pathname === '/harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (u.pathname === '/api/skill_packages.php') {
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'changes with CSRF');
      const out = srv.handle(req.method(), u, req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null);
      return route.fulfill({status: out.status || 200, json: out.json});
    }
    if (u.pathname === '/api/composer_tools.php') return route.fulfill({json: {ok: true, guest: false, tools: {}}});
    const file = path.join(root, u.pathname);
    if (fs.existsSync(file)) return route.fulfill({contentType: types[path.extname(file)] || 'text/plain', body: fs.readFileSync(file)});
    return route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://localhost:8769/harness.html');
  return {context, page, errors};
}
const menuEntry = page => page.evaluate(() => {
  const m = document.getElementById('composerMenu');
  const e = m && m.querySelector('.cm-item[data-id="skills"]');
  return e ? {label: e.querySelector('.cm-label').textContent, detail: e.querySelector('.cm-detail').textContent, off: e.classList.contains('is-off'),
    titles: Array.from(m.querySelectorAll('.cm-title')).map(t => t.textContent)} : null;
});
const chips = page => page.$$eval('.composer-skills .composer-skill-name', l => l.map(x => x.textContent));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // ===== Settings: Gemeinsam, reading, switching off, precedence and sharing =====
    let srv = settingsServer(true);
    let {context, page, errors} = await openSettings(browser, srv);
    assert.deepEqual(await page.$$eval('#view-skills .sec-heading', l => l.filter(x => !x.hidden).map(x => x.textContent)), ['Deine Skills', 'Gemeinsam', 'Mitgeliefert']);
    assert.deepEqual(await page.$$eval('#skShared .sk-row small', l => l.map(x => x.textContent)), [
      'wochenbericht · von Ines · 1 Begleitdatei · braucht Python · Vorrang hoch', 'Für das ganze Team',
      'Dein eigener Skill „Mein Wochenbericht“ mit derselben Kennung geht vor.',
      'protokoll · von Admin · 0 Begleitdateien', 'Sitzungsprotokoll']);
    // Switching off for oneself.
    await page.click('#skShared [data-shared="protokoll"] .sk-use');
    await until(page, () => document.querySelector('#skShared [data-shared="protokoll"].is-off'));
    assert.deepEqual(srv.st.posts.pop(), {action: 'use', id: C, on: false});
    assert.equal(await page.textContent('#skStatus'), '„Protokoll“ ist für dich ausgeschaltet.');
    assert.match(await page.textContent('#skShared [data-shared="protokoll"] small'), / · für dich aus$/);
    await page.click('#skShared [data-shared="protokoll"] .sk-use');
    await until(page, () => !document.querySelector('#skShared [data-shared="protokoll"].is-off'));
    assert.equal(await page.textContent('#skStatus'), 'Mara nutzt „Protokoll“ wieder.');
    // Reading a shared skill, as text.
    await page.click('#skShared [data-shared="wochenbericht"] .sec-copy');
    await until(page, () => document.getElementById('skView'));
    assert.equal(await page.textContent('#skEdit .sk-edit-title'), 'Wochenbericht Ines');
    assert.equal(await page.textContent('#skView .pz-lead'), 'wochenbericht · gemeinsamer Skill von Ines · Vorrang hoch · braucht Python');
    assert.equal(await page.textContent('#skView .sk-pre'), 'Schreibe <b>knapp</b>.');
    assert.equal(await page.locator('#skView b, #skSave, #skDelete, #skView textarea').count(), 0, 'read only, text not HTML');
    assert.equal(await page.textContent('#skView summary'), 'templates/bericht.md · Vorlage · 9 Bytes');
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skills-shared-view.png')});
    await page.click('#skBack');
    await until(page, () => !document.getElementById('skMain').hidden);
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skills-shared-list.png')});
    // The editor: precedence and sharing, never for a project skill.
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skShare'));
    assert.deepEqual(await page.$$eval('#skPriority option', l => l.map(o => o.textContent)), ['Hoch', 'Normal', 'Niedrig']);
    assert.equal(await page.inputValue('#skPriority'), 'normal');
    assert.equal(await page.isChecked('#skShare'), false);
    await page.locator('#skShare').setChecked(true, {force: true});
    await page.selectOption('#skScope', PROJECT);
    assert.equal(await page.isDisabled('#skShare'), true);
    assert.equal(await page.isChecked('#skShare'), false, 'a project skill is not shared');
    assert.match(await page.evaluate(() => document.getElementById('skShare').closest('.setting-row').textContent), /lässt sich nicht teilen/);
    await page.selectOption('#skScope', '');
    assert.equal(await page.isDisabled('#skShare'), false);
    await page.locator('#skShare').setChecked(true, {force: true});
    await page.selectOption('#skPriority', 'high');
    await page.click('#skSave');
    await until(page, () => document.getElementById('skStatus').textContent === 'Gespeichert.');
    const saved = srv.st.posts.pop();
    assert.equal(saved.priority, 'high');
    assert.equal(saved.shared, true);
    await page.click('#skBack');
    await until(page, () => /mit allen Konten geteilt/.test((document.querySelector('#skOwn [data-skill="wochenbericht"] small') || {}).textContent || ''));
    assert.equal(await page.textContent('#skOwn [data-skill="wochenbericht"] small'), 'wochenbericht · alle Chats · 0 Begleitdateien · Vorrang hoch · mit allen Konten geteilt');
    assert.deepEqual(errors, []);
    await context.close();
    // A Nutzer: precedence, no sharing.
    srv = settingsServer(false);
    ({context, page, errors} = await openSettings(browser, srv));
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skPriority'));
    assert.equal(await page.locator('#skShare').count(), 0, 'only the Inhaber and Admins share');
    assert.deepEqual(errors, []);
    await context.close();

    // ===== Chat: Skills für diesen Chat =====
    let chat = chatServer();
    ({context, page, errors} = await openChat(browser, chat));
    await until(page, () => window.CoreAIChatSkills && document.querySelector('.composer-skills'));
    await page.waitForTimeout(100);
    assert.equal(await page.isHidden('.composer-skills'), true, 'nothing fixed: nothing above the input field');
    await page.click('#btnAttach');
    await until(page, () => document.querySelector('#composerMenu .cm-item[data-id="skills"]'));
    let entry = await menuEntry(page);
    assert.deepEqual(entry.titles, ['Anhängen', 'Werkzeuge', 'Skills', 'Konnektoren']);
    assert.deepEqual([entry.label, entry.detail, entry.off], ['Skills für diesen Chat', 'Mara wählt selbst', false]);
    await page.click('#composerMenu .cm-item[data-id="skills"]');
    await until(page, () => document.getElementById('chatSkillsDialog'));
    assert.equal(await page.isHidden('#composerMenu'), true);
    assert.equal(await page.evaluate(() => document.activeElement.className), 'cs-search');
    assert.deepEqual(await page.$$eval('.cs-group-title', l => l.map(x => x.textContent)), ['Projekt', 'Deine Skills', 'Gemeinsam', 'Mitgeliefert']);
    assert.equal(await page.textContent('.cs-count'), '0 von 3 gewählt');
    assert.equal(await page.textContent(`.cs-option[data-ref="${A}"] small`), 'wochenbericht · eigener Skill · gilt hier');
    assert.equal(await page.textContent(`.cs-option[data-ref="${B}"] small`), 'wochenbericht · gemeinsam von Ines · Vorrang hoch · verdeckt');
    assert.equal(await page.textContent(`.cs-option[data-ref="${C}"] small`), 'protokoll · gemeinsam von Admin');
    // One variant per short name: choosing Ines's takes out the own one.
    await page.check(`.cs-option[data-ref="${A}"] input`);
    await page.check(`.cs-option[data-ref="${B}"] input`);
    assert.equal(await page.isChecked(`.cs-option[data-ref="${A}"] input`), false);
    assert.equal(await page.textContent('.cs-count'), '1 von 3 gewählt');
    await page.check('.cs-option[data-ref="b:python"] input');
    await page.check(`.cs-option[data-ref="${C}"] input`);
    assert.equal(await page.textContent('.cs-count'), '3 von 3 gewählt');
    assert.equal(await page.isDisabled('.cs-option[data-ref="b:zip-delivery"] input'), true, 'at most three');
    assert.equal(await page.isDisabled(`.cs-option[data-ref="${D}"] input`), true);
    assert.equal(await page.isDisabled(`.cs-option[data-ref="${A}"] input`), false, 'another variant of a chosen short name stays possible');
    // Search.
    await page.fill('.cs-search', 'proto');
    assert.deepEqual(await page.$$eval('.cs-option', l => l.map(x => x.getAttribute('data-ref'))), [C]);
    await page.fill('.cs-search', 'nichts');
    assert.equal(await page.textContent('.cs-empty'), 'Kein Skill passt zur Suche.');
    await page.fill('.cs-search', '');
    if (shots) await page.screenshot({path: path.join(shots, 'chat-skills-dialog.png')});
    await page.click('#chatSkillsSave');
    await until(page, () => !document.getElementById('chatSkillsDialog'));
    assert.deepEqual(chat.st.calls.pop(), {method: 'POST', action: 'chat_set', session: 'cs_one00001', refs: [B, 'b:python', C]});
    assert.equal(await page.evaluate(() => document.activeElement.id), 'btnAttach', 'the focus comes back to the + button');
    assert.deepEqual(await chips(page), ['Wochenbericht Ines', 'Python', 'Protokoll']);
    assert.equal(await page.getAttribute(`.composer-skill[data-ref="${B}"]`, 'title'), 'wochenbericht · gemeinsam von Ines');
    await page.click('#btnAttach');
    await until(page, () => document.querySelector('#composerMenu .cm-item[data-id="skills"]'));
    assert.equal((await menuEntry(page)).detail, 'Wochenbericht Ines, Python, Protokoll');
    await page.keyboard.press('Escape');
    if (shots) await page.screenshot({path: path.join(shots, 'chat-skills-bar.png')});
    // Taking one out above the input field.
    await page.click('.composer-skill[data-ref="b:python"] .composer-skill-remove');
    await until(page, () => document.querySelectorAll('.composer-skill').length === 2);
    assert.deepEqual(chat.st.calls.pop().refs, [B, C]);
    // Another chat, then back.
    await page.evaluate(() => window.switchChat('cs_two00001'));
    await until(page, () => document.querySelector('.composer-skills').hidden);
    assert.equal(chat.st.calls.pop().session, 'cs_two00001');
    await page.evaluate(() => window.switchChat('cs_one00001'));
    await until(page, () => document.querySelectorAll('.composer-skill').length === 2);
    // A new chat: nothing to fix yet.
    await page.evaluate(() => window.switchChat(''));
    await until(page, () => document.querySelector('.composer-skills').hidden);
    await page.click('#btnAttach');
    await until(page, () => document.querySelector('#composerMenu .cm-item[data-id="skills"]'));
    entry = await menuEntry(page);
    assert.deepEqual([entry.detail, entry.off], ['Nach der ersten Nachricht in diesem Chat', true]);
    await page.click('#composerMenu .cm-item[data-id="skills"]', {force: true});
    assert.equal(await page.locator('#chatSkillsDialog').count(), 0, 'a greyed entry does nothing');
    await page.keyboard.press('Escape');
    // The server's reason, and Escape.
    await page.evaluate(() => window.switchChat('cs_one00001'));
    await until(page, () => document.querySelectorAll('.composer-skill').length === 2);
    chat.st.removed.push(C);
    await page.evaluate(() => window.CoreAIChatSkills.open());
    await page.click('#chatSkillsSave');
    await until(page, () => /gibt es nicht mehr/.test((document.querySelector('#chatSkillsDialog .chat-dialog-error') || {}).textContent || ''));
    assert.equal(await page.textContent('#chatSkillsDialog .chat-dialog-error'), 'Einen der Skills gibt es nicht mehr. Bitte neu wählen.');
    await page.keyboard.press('Escape');
    await until(page, () => !document.getElementById('chatSkillsDialog'));
    // "Mara wählt selbst".
    chat.st.removed.length = 0;
    await page.evaluate(() => window.CoreAIChatSkills.open());
    await page.click('#chatSkillsReset');
    await until(page, () => !document.getElementById('chatSkillsDialog') && document.querySelector('.composer-skills').hidden);
    assert.deepEqual(chat.st.calls.pop().refs, []);
    assert.deepEqual(errors, []);
    await context.close();

    // Gone skills are named.
    chat = chatServer({gone: ['f'.repeat(32)]});
    ({context, page, errors} = await openChat(browser, chat));
    await until(page, () => window.CoreAIChatSkills && window.CoreAIChatSkills.current().length === 1);
    await page.evaluate(() => window.CoreAIChatSkills.open());
    assert.equal(await page.textContent('.cs-gone'), 'Nicht mehr verfügbar und beim Speichern entfernt: 1 Skill.');
    assert.equal(await page.isHidden('.composer-skills'), true, 'a gone skill is no chip');
    await context.close();

    // A phone: the window fits, nothing scrolls sideways.
    chat = chatServer();
    ({context, page, errors} = await openChat(browser, chat, {width: 390, height: 844}));
    await until(page, () => window.CoreAIChatSkills && document.querySelector('.composer-skills'));
    await page.waitForTimeout(100);
    await page.evaluate(() => window.CoreAIChatSkills.open());
    await until(page, () => document.getElementById('chatSkillsDialog'));
    const fit = await page.evaluate(() => {
      const r = document.querySelector('.cs-box').getBoundingClientRect();
      return {left: r.left, right: r.right, width: innerWidth, scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth};
    });
    assert.ok(fit.left >= 0 && fit.right <= fit.width && fit.scroll === 0, 'the window fits on a phone: ' + JSON.stringify(fit));
    if (shots) await page.screenshot({path: path.join(shots, 'chat-skills-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();

    // Guests.
    chat = chatServer({guest: true});
    ({context, page, errors} = await openChat(browser, chat));
    await page.waitForTimeout(150);
    await page.click('#btnAttach');
    await until(page, () => document.querySelector('#composerMenu .cm-item[data-id="skills"]'));
    entry = await menuEntry(page);
    assert.deepEqual([entry.detail, entry.off], ['Skills gibt es nur für angemeldete Konten.', true]);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI skill sharing UI: Gemeinsam with sharer, precedence and the skill that serves instead, switching off, reading as text,'
    + ' precedence and sharing in the editor (never a project skill, only Inhaber and Admins), Skills für diesen Chat (groups, one variant'
    + ' per short name, at most three, search, saving, chips and taking one out, another chat, new chat, server reason, Escape, Mara'
    + ' chooses again, gone skills), phone, guests OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
