// CoreAI 0.7.83 (P12 A): Settings > Skills in a real browser (Playwright).
//   node scripts/skill-packages-ui-selftest.cjs
// A stand-in for api/skill_packages.php keeps the skills. Checks: the page in the settings, the own
// skills and the built-in library (12 without a search, no switches), the search; a new skill with the
// server's reason for a wrong short name, the byte counter, requirements and scope; companion files in
// four folders (add, edit with rename, delete with a confirmation, the server's reason), the files the
// instruction names but lacks; the list with its details, opening, changing the scope and switching
// off, deleting with a confirmation; names shown as text, never as HTML; without migration 052; guests;
// a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml'};
const shots = process.env.COREUI_SHOTS || '';
const PROJECT = 'a'.repeat(32);
const TOOLS = {web: 'Websuche', browse: 'Browser-Recherche', python: 'Python', image: 'Bilder', files: 'Projektdateien', run: 'Ausführen im Projekt'};
const BUILTIN = Array.from({length: 15}, (_, i) => ({id: i === 0 ? 'python' : 'skill-' + i, title: i === 0 ? 'Python' : 'Skill ' + i,
  category: i % 2 ? 'Code' : 'Text', description: i === 0 ? 'Python-Skripte schreiben und pruefen.' : 'Beschreibung ' + i,
  required: false, references: i === 0 ? 2 : 0}));
const KIND = {references: 'reference', templates: 'template', scripts: 'script', assets: 'asset'};

function skillServer(opts) {
  opts = opts || {};
  const st = {skills: (opts.skills || []).map(s => Object.assign({files: [], body: 'Anleitung.'}, s)), posts: [], next: 1};
  const bytes = text => Buffer.byteLength(text || '', 'utf8');
  const missing = sk => {
    const found = [...new Set([...sk.body.matchAll(/(?<![A-Za-z0-9._/-])((?:references|templates|scripts|assets)\/[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.[A-Za-z0-9]+)/g)].map(m => m[1]))];
    return found.filter(p => !sk.files.some(f => f.path === p)).sort();
  };
  const pub = (sk, full) => {
    const out = {id: sk.id, slug: sk.slug, title: sk.title, description: sk.description, triggers: sk.triggers, requires: sk.requires,
      project: sk.project_id ? {id: sk.project_id, name: 'Kunden'} : null, enabled: sk.enabled,
      files: sk.files.map(f => Object.assign({path: f.path, kind: f.kind, size_bytes: bytes(f.content)}, full ? {content: f.content} : {})),
      missing: missing(sk)};
    if (full) out.body = sk.body;
    return out;
  };
  const fail = (status, error) => ({status, json: {ok: false, error}});
  function handle(method, u, body) {
    if (opts.guest) return fail(403, 'guests_not_allowed');
    const action = u.searchParams.get('action');
    if (method === 'GET' && action === 'list') {
      if (opts.notReady) return {json: {ok: true, ready: false, csrf_token: 'tok'}};
      const q = (u.searchParams.get('q') || '').toLowerCase();
      const skills = st.skills.filter(s => !q || (s.title + ' ' + s.slug + ' ' + s.description + ' ' + s.triggers.join(', ')).toLowerCase().includes(q));
      return {json: {ok: true, ready: true, skills: skills.map(s => pub(s, false)), builtin: BUILTIN, projects: [{id: PROJECT, name: 'Kunden'}],
        tools: TOOLS, limits: {skills: 50, files: 20, body_bytes: 4000, file_bytes: 8000, triggers: 12}, csrf_token: 'tok'}};
    }
    if (method === 'GET' && action === 'get') {
      const sk = st.skills.find(s => s.id === u.searchParams.get('id'));
      return sk ? {json: {ok: true, skill: pub(sk, true)}} : fail(404, 'skill_not_found');
    }
    st.posts.push(Object.assign({action}, body));
    const sk = st.skills.find(s => s.id === body.id);
    if (action === 'save') {
      const slug = String(body.slug || '').trim().toLowerCase();
      if (BUILTIN.some(b => b.id === slug)) return fail(422, 'skill_builtin_name');
      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) return fail(400, 'invalid_skill_slug');
      if (bytes(body.body) > 4000) return fail(400, 'skill_body_too_large');
      if (st.skills.some(s => s.slug === slug && s.id !== body.id)) return fail(409, 'skill_exists');
      const data = {slug, title: String(body.title).trim(), description: String(body.description).trim(), body: String(body.body).trim(),
        triggers: String(body.triggers).split(',').map(t => t.trim().toLowerCase()).filter(Boolean), requires: body.requires,
        project_id: body.project_id || null, enabled: !!body.enabled};
      if (body.id) { if (!sk) return fail(404, 'skill_not_found'); Object.assign(sk, data); return {json: {ok: true, skill: pub(sk, true)}}; }
      const created = Object.assign({id: String(st.next++).repeat(32).slice(0, 32), files: []}, data);
      st.skills.push(created);
      return {status: 201, json: {ok: true, skill: pub(created, true)}};
    }
    if (!sk) return fail(404, 'skill_not_found');
    if (action === 'delete') { st.skills = st.skills.filter(s => s !== sk); return {json: {ok: true}}; }
    if (action === 'file_save') {
      const m = /^(references|templates|scripts|assets)\/([A-Za-z0-9][A-Za-z0-9._-]{0,80})$/.exec(body.path || '');
      if (!m || !m[2].includes('.')) return fail(400, 'invalid_skill_path');
      if (bytes(body.content) > 8000) return fail(400, 'skill_file_too_large');
      if (body.old_path && body.old_path !== body.path) {
        if (sk.files.some(f => f.path === body.path)) return fail(409, 'skill_file_exists');
        sk.files = sk.files.filter(f => f.path !== body.old_path);
      }
      sk.files = sk.files.filter(f => f.path !== body.path).concat([{path: body.path, kind: KIND[m[1]], content: body.content}])
        .sort((a, b) => a.path < b.path ? -1 : 1);
      return {json: {ok: true, skill: pub(sk, true)}};
    }
    if (action === 'file_delete') {
      sk.files = sk.files.filter(f => f.path !== body.path);
      return {json: {ok: true, skill: pub(sk, true)}};
    }
    return fail(404, 'unknown_action');
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 3, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 3, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.83', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/skill_packages.php') {
      const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : null;
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'changes with CSRF');
      const out = srv.handle(req.method(), u, body);
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
  return {context, page, errors};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const texts = (page, sel) => page.$$eval(sel, l => l.map(x => x.textContent));
const status = page => page.textContent('#skStatus');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    const evil = '<img src=x onerror="window.__xss=1">Fett';
    let srv = skillServer({skills: [{id: 'e'.repeat(32), slug: 'notiz', title: evil, description: '<b>kein HTML</b>', triggers: ['notiz'], requires: [],
      project_id: null, enabled: true}]});
    let {context, page, errors} = await open(browser, srv);
    await until(page, () => !document.getElementById('skMain').hidden && document.querySelectorAll('#skBuiltin .sk-row').length > 0);

    // The page and its lists.
    assert.equal(await page.textContent('#view-skills h2'), 'Skills');
    assert.equal(await page.getAttribute('#tab-skills', 'aria-selected'), 'true');
    assert.deepEqual(await texts(page, '#view-skills .sec-heading'), ['Deine Skills', 'Mitgeliefert']);
    assert.equal(await page.$$eval('#skBuiltin .sk-row', l => l.length), 12, 'twelve built-in skills without a search');
    assert.equal(await page.textContent('#skBuiltin .sk-empty'), 'Und 3 weitere. Suche nach einem Begriff, um sie zu finden.');
    assert.equal(await page.locator('#skBuiltin input, #skBuiltin button').count(), 0, 'no switches for the built-in skills');
    assert.equal(await page.textContent('#skBuiltin [data-builtin="python"] small'), 'python · Text · 2 Referenzen');
    // Names are text, never HTML.
    assert.equal(await page.textContent('#skOwn [data-skill="notiz"] strong'), evil);
    assert.equal(await page.locator('#skOwn img, #skOwn b').count(), 0);
    assert.equal(await page.evaluate(() => window.__xss), undefined);
    // Search.
    await page.fill('#skSearch', 'python');
    await until(page, () => document.querySelectorAll('#skBuiltin .sk-row').length === 1);
    assert.equal(await page.textContent('#skOwn .sk-empty'), 'Keiner deiner Skills passt zur Suche.');
    await page.fill('#skSearch', 'notiz');
    await until(page, () => document.querySelectorAll('#skOwn .sk-row').length === 1 && document.querySelectorAll('#skBuiltin .sk-row').length === 0);
    assert.equal(await page.textContent('#skBuiltin .sk-empty'), 'Kein mitgelieferter Skill passt zur Suche.');
    await page.fill('#skSearch', '');
    await until(page, () => document.querySelectorAll('#skBuiltin .sk-row').length === 12);
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skills-list.png')});

    // A new skill.
    await page.click('#skNew');
    await until(page, () => !document.getElementById('skEdit').hidden);
    assert.equal(await page.isHidden('#skMain'), true);
    assert.equal(await page.textContent('#skEdit .sk-edit-title'), 'Neuer Skill');
    assert.equal(await page.textContent('#skSave'), 'ANLEGEN');
    assert.equal(await page.locator('#skDelete, #skFileForm').count(), 0, 'files and deleting come once the skill exists');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'skTitle');
    assert.deepEqual(await texts(page, '.sk-requires .sk-check span'), Object.values(TOOLS));
    assert.deepEqual(await texts(page, '#skScope option'), ['Alle meine Chats', 'Nur Projekt „Kunden“']);
    assert.equal(await page.isChecked('#skEnabled'), true);
    await page.fill('#skTitle', 'Wochenbericht');
    await page.fill('#skSlug', 'python');
    await page.fill('#skDescription', 'Fasst die Woche fuer das Team zusammen.');
    await page.fill('#skTriggers', 'Wochenbericht, Statusbericht');
    await page.check('[data-tool="web"]');
    await page.check('[data-tool="python"]');
    await page.fill('#skBody', 'ä'.repeat(2001));
    assert.equal(await page.textContent('.sk-count'), '4.002 von 4.000 Bytes');
    assert.equal(await page.evaluate(() => document.querySelector('.sk-count').classList.contains('is-over')), true);
    const body = 'Nutze templates/bericht.md und references/fehlt.md.\nSchreibe knapp.';
    await page.fill('#skBody', body);
    assert.equal(await page.textContent('.sk-count'), Buffer.byteLength(body) + ' von 4.000 Bytes');
    await page.click('#skSave');
    await until(page, () => /mitgelieferter Skill/.test(document.getElementById('skStatus').textContent));
    assert.equal(await status(page), 'So heißt schon ein mitgelieferter Skill. Bitte eine andere Kennung wählen.');
    assert.equal(await page.evaluate(() => document.getElementById('skStatus').classList.contains('is-error')), true);
    await page.fill('#skSlug', 'wochenbericht');
    await page.click('#skSave');
    await until(page, () => document.getElementById('skSave') && document.getElementById('skSave').textContent === 'SPEICHERN');
    const created = srv.st.posts.filter(p => p.action === 'save').pop();
    assert.deepEqual(created, {action: 'save', id: '', title: 'Wochenbericht', slug: 'wochenbericht', description: 'Fasst die Woche fuer das Team zusammen.',
      triggers: 'Wochenbericht, Statusbericht', requires: ['web', 'python'], project_id: '', enabled: true, body});
    assert.equal(await status(page), 'Der Skill ist angelegt. Jetzt kannst du Begleitdateien hinzufügen.');
    assert.equal(await page.textContent('#skMissing'),
      'Die Anleitung nennt Dateien, die fehlen: references/fehlt.md, templates/bericht.md. Lege sie unten an oder ändere die Anleitung.');
    assert.equal(await page.textContent('.sk-file-list .sk-empty'), 'Noch keine Begleitdateien.');
    assert.deepEqual(await texts(page, '#skFileFolder option'),
      ['Referenz (references/)', 'Vorlage (templates/)', 'Skript (scripts/)', 'Datei (assets/)']);

    // Companion files.
    await page.selectOption('#skFileFolder', 'templates');
    await page.fill('#skFileName', 'bericht.md');
    await page.fill('#skFileContent', '# Bericht {{woche}}\n');
    await page.click('#skFileSave');
    await until(page, () => document.querySelectorAll('.sk-file').length === 1);
    assert.equal(await page.textContent('.sk-file small'), 'Vorlage · 20 Bytes');
    assert.equal(await page.textContent('#skMissing'), 'Die Anleitung nennt Dateien, die fehlen: references/fehlt.md. Lege sie unten an oder ändere die Anleitung.');
    assert.equal(await page.inputValue('#skFileName'), '', 'the form is empty again');
    // The server's reason.
    await page.fill('#skFileName', 'ohne-endung');
    await page.click('#skFileSave');
    await until(page, () => /Endung/.test(document.getElementById('skStatus').textContent));
    // Edit with a new name.
    await page.click('.sk-file[data-file="templates/bericht.md"] >> text=BEARBEITEN');
    await until(page, () => document.getElementById('skFileName').value === 'bericht.md');
    assert.equal(await page.textContent('.sk-file-head'), 'templates/bericht.md bearbeiten');
    assert.equal(await page.inputValue('#skFileFolder'), 'templates');
    assert.equal(await page.inputValue('#skFileContent'), '# Bericht {{woche}}\n');
    await page.selectOption('#skFileFolder', 'references');
    await page.fill('#skFileName', 'fehlt.md');
    await page.click('#skFileSave');
    await until(page, () => document.querySelector('.sk-file') && document.querySelector('.sk-file').dataset.file === 'references/fehlt.md');
    assert.deepEqual(srv.st.posts.filter(p => p.action === 'file_save').pop(),
      {action: 'file_save', id: created.id || srv.st.skills[1].id, path: 'references/fehlt.md', content: '# Bericht {{woche}}\n', old_path: 'templates/bericht.md'});
    assert.equal(await page.textContent('#skMissing'), 'Die Anleitung nennt Dateien, die fehlen: templates/bericht.md. Lege sie unten an oder ändere die Anleitung.');
    await page.selectOption('#skFileFolder', 'templates');
    await page.fill('#skFileName', 'bericht.md');
    await page.fill('#skFileContent', 'Vorlage');
    await page.click('#skFileSave');
    await until(page, () => document.querySelectorAll('.sk-file').length === 2 && !document.getElementById('skMissing'));
    await page.selectOption('#skFileFolder', 'scripts');
    await page.fill('#skFileName', 'zaehlen.py');
    await page.fill('#skFileContent', 'print(1)\n');
    await page.click('#skFileSave');
    await until(page, () => document.querySelectorAll('.sk-file').length === 3);
    assert.deepEqual(await texts(page, '.sk-file strong'), ['references/fehlt.md', 'scripts/zaehlen.py', 'templates/bericht.md']);
    assert.match(await page.textContent('.sk-files .pz-lead'), /\[SKILL: wochenbericht\/ordner\/name\].*Ein Skript bekommt sie nur als Text: Es läuft nie von selbst\./);
    // Deleting a file asks first.
    let asked = '';
    page.once('dialog', d => { asked = d.message(); d.dismiss(); });
    await page.click('.sk-file[data-file="scripts/zaehlen.py"] >> text=LÖSCHEN');
    await page.waitForTimeout(150);
    assert.equal(asked, 'scripts/zaehlen.py löschen?');
    assert.equal(await page.locator('.sk-file').count(), 3, 'nothing deleted without a yes');
    page.once('dialog', d => d.accept());
    await page.click('.sk-file[data-file="scripts/zaehlen.py"] >> text=LÖSCHEN');
    await until(page, () => document.querySelectorAll('.sk-file').length === 2);
    assert.equal(await status(page), 'Die Datei wurde gelöscht.');
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skills-editor.png')});

    // Back to the list.
    await page.click('#skBack');
    await until(page, () => document.querySelectorAll('#skOwn .sk-row').length === 2);
    assert.equal(await page.isHidden('#skEdit'), true);
    assert.deepEqual(await page.$$eval('#skOwn [data-skill="wochenbericht"] small', l => l.map(x => x.textContent)),
      ['wochenbericht · alle Chats · 2 Begleitdateien · braucht Websuche, Python', 'Fasst die Woche fuer das Team zusammen.']);
    // Open, change the scope, switch off.
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skSlug') && document.getElementById('skSlug').value === 'wochenbericht');
    assert.equal(await page.inputValue('#skBody'), body);
    assert.equal(await page.inputValue('#skTriggers'), 'wochenbericht, statusbericht');
    assert.equal(await page.isChecked('[data-tool="python"]'), true);
    assert.equal(await page.isChecked('[data-tool="files"]'), false);
    await page.selectOption('#skScope', PROJECT);
    await page.locator('#skEnabled').setChecked(false, {force: true});
    await page.click('#skSave');
    await until(page, () => document.getElementById('skStatus').textContent === 'Gespeichert.');
    assert.equal(srv.st.posts.filter(p => p.action === 'save').pop().project_id, PROJECT);
    assert.equal(srv.st.posts.filter(p => p.action === 'save').pop().enabled, false);
    await page.click('#skBack');
    await until(page, () => document.querySelector('#skOwn [data-skill="wochenbericht"].is-off'));
    assert.equal(await page.textContent('#skOwn [data-skill="wochenbericht"] small'),
      'wochenbericht · nur Projekt „Kunden“ · 2 Begleitdateien · braucht Websuche, Python · aus');
    // Deleting the skill asks first.
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skDelete'));
    page.once('dialog', d => { asked = d.message(); d.dismiss(); });
    await page.click('#skDelete');
    await page.waitForTimeout(150);
    assert.equal(asked, '„Wochenbericht“ mit allen Begleitdateien löschen? Mara kann ihn danach nicht mehr laden.');
    assert.equal(srv.st.skills.length, 2);
    page.once('dialog', d => d.accept());
    await page.click('#skDelete');
    await until(page, () => !document.getElementById('skMain').hidden && document.querySelectorAll('#skOwn .sk-row').length === 1);
    assert.equal(await status(page), 'Der Skill wurde gelöscht.');
    assert.deepEqual(errors, []);
    await context.close();

    // A phone: no sideways scrolling in the list and the editor.
    srv = skillServer({skills: [{id: 'f'.repeat(32), slug: 'ein-sehr-langer-name-fuer-einen-skill-mit-vielen-teilen', title: 'Langer Skill',
      description: 'x'.repeat(280), triggers: [], requires: ['web', 'browse', 'python', 'image', 'files', 'run'], project_id: PROJECT, enabled: true,
      files: [{path: 'references/eine-sehr-lange-datei-mit-einem-langen-namen.md', kind: 'reference', content: 'x'}]}]});
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    await until(page, () => !document.getElementById('skMain').hidden && document.querySelectorAll('#skOwn .sk-row').length === 1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'no sideways scrolling in the list');
    await page.click('#skOwn .sk-row');
    await until(page, () => document.querySelectorAll('.sk-file').length === 1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'no sideways scrolling in the editor');
    if (shots) await page.screenshot({path: path.join(shots, 'skills-phone.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();

    // Without migration 052.
    srv = skillServer({notReady: true});
    ({context, page, errors} = await open(browser, srv));
    await until(page, () => /Migration 052/.test(document.getElementById('skStatus').textContent));
    assert.equal(await page.isHidden('#skMain'), true);
    assert.equal(await page.isDisabled('#skNew'), true);
    await context.close();

    // Guests.
    srv = skillServer({guest: true});
    ({context, page, errors} = await open(browser, srv));
    await until(page, () => /nur für angemeldete Konten/.test(document.getElementById('skStatus').textContent));
    assert.equal(await page.isHidden('#skMain'), true);
    assert.equal(await page.isDisabled('#skNew'), true);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI skills UI: own skills and the built-in library without switches, search, new skill with the server\'s reason, byte counter,'
    + ' requirements, scope, companion files (add, rename, delete with confirmation), missing files, details, off, delete with confirmation,'
    + ' text not HTML, without migration, guests, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
