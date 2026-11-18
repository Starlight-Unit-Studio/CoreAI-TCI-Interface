// CoreAI 0.7.90 (P13 A): revisions of a skill and packages, in a real browser (Playwright).
//   node scripts/skill-revisions-ui-selftest.cjs
// Settings > Skills with a stand-in for api/skill_packages.php that keeps revisions: the revision in
// the editor, Verlauf (who, when, what), Vergleichen with a line comparison and two revisions to
// choose, Wiederherstellen with a confirmation as a new revision; a save after someone else's keeps
// the text and offers the differences, saving anyway or reloading; the same for a companion file;
// Exportieren as a download; Importieren: new, as a new revision, under another short name,
// cancelled, refused with the entry; without migration 054 no history; a phone.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.svg': 'image/svg+xml'};
const shots = process.env.COREUI_SHOTS || '';
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const TOOLS = {web: 'Websuche', browse: 'Browser-Recherche', python: 'Python', image: 'Bilder', files: 'Projektdateien', run: 'Ausführen im Projekt'};
const ID = '1'.repeat(32);

function server(opts) {
  opts = opts || {};
  const snap = (rev, kind, note, data) => Object.assign(JSON.parse(JSON.stringify(data)), {rev, kind, note, created_at: '2026-11-18 0' + (rev % 10) + ':15:00'});
  const v1 = {title: 'Wochenbericht', slug: 'wochenbericht', description: 'Die Woche', triggers: ['wochenbericht'], requires: ['web'], priority: 'normal',
    enabled: true, shared: false, body: 'Schreibe knapp.\nNenne Erledigtes.', files: []};
  const v2 = Object.assign({}, v1, {files: [{path: 'templates/bericht.md', kind: 'template', content: '# Bericht\n{{woche}}\n', size_bytes: 20}]});
  const v3 = Object.assign({}, v2, {title: 'Wochenbericht Team', body: 'Schreibe knapp.\nNenne Erledigtes und Offenes.'});
  const st = {posts: [], uploads: [], revs: [snap(1, 'create', '', v1), snap(2, 'file', 'templates/bericht.md', v2), snap(3, 'save', '', v3)], other: []};
  const LABEL = {create: 'Angelegt', save: 'Gespeichert', file: 'Begleitdatei', restore: 'Wiederhergestellt', import: 'Importiert'};
  const current = () => st.revs[st.revs.length - 1];
  const skill = () => {
    const c = current();
    return {id: ID, slug: c.slug, title: c.title, description: c.description, triggers: c.triggers, requires: c.requires, project: null, enabled: c.enabled,
      shared: c.shared, priority: c.priority, body: c.body, files: c.files, missing: [], revision: opts.noRevisions ? 0 : c.rev};
  };
  const push = (kind, note, data) => st.revs.push(snap(current().rev + 1, kind, note, Object.assign({}, current(), data)));
  const changes = (a, b) => {
    if (!a) return {fields: [], body: false, files: {added: b.files.map(f => f.path), removed: [], changed: []}};
    const fields = ['slug', 'title', 'description'].filter(k => a[k] !== b[k]);
    const pa = a.files.map(f => f.path), pb = b.files.map(f => f.path);
    return {fields, body: a.body !== b.body, files: {added: pb.filter(p => !pa.includes(p)), removed: pa.filter(p => !pb.includes(p)),
      changed: pb.filter(p => pa.includes(p) && a.files.find(f => f.path === p).content !== b.files.find(f => f.path === p).content)}};
  };
  const fail = (status, error, extra) => ({status, json: Object.assign({ok: false, error}, extra || {})});
  function handle(method, u, body, raw) {
    const action = u.searchParams.get('action');
    if (method === 'GET' && action === 'list') {
      const own = [skill()].concat(st.other).map(s => Object.assign({}, s, {body: undefined}));
      return {json: {ok: true, ready: true, skills: own, builtin: [], projects: [], tools: TOOLS, sharing: true, can_share: false, shared: [],
        revisions: !opts.noRevisions, csrf_token: 'tok'}};
    }
    if (method === 'GET' && action === 'get') return {json: {ok: true, skill: skill()}};
    if (method === 'GET' && action === 'history') {
      const list = st.revs.slice().reverse().map(r => ({rev: r.rev, kind: r.kind, label: LABEL[r.kind], note: r.note, by: r.rev === 2 ? 'Ines' : 'du',
        created_at: r.created_at, current: r.rev === current().rev, changes: changes(st.revs.find(x => x.rev === r.rev - 1), r)}));
      return {json: {ok: true, revision: current().rev, revisions: list, keep: 100}};
    }
    if (method === 'GET' && action === 'revision') {
      const r = st.revs.find(x => x.rev === Number(u.searchParams.get('rev')));
      return r ? {json: {ok: true, revision: r}} : fail(404, 'skill_revision_not_found');
    }
    if (method === 'GET' && action === 'export') {
      return {status: 200, headers: {'Content-Type': 'application/zip', 'Content-Disposition': "attachment; filename*=UTF-8''wochenbericht" + (u.searchParams.get('rev') ? '-revision-' + u.searchParams.get('rev') : '') + '.zip'},
        body: Buffer.from('PK\x05\x06' + '\0'.repeat(18), 'binary')};
    }
    if (action === 'import') {
      const text = raw.toString('latin1');
      const field = name => { const m = new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)').exec(text); return m ? m[1] : ''; };
      const file = (/filename="([^"]+)"/.exec(text) || [])[1] || '';
      const up = {file, mode: field('mode'), slug: field('slug')};
      st.uploads.push(up);
      if (file === 'kaputt.zip') return fail(400, 'skill_package_unsafe', {path: 'probe/../../evil.md'});
      if (file === 'vorhanden.zip' && up.mode === 'new' && !up.slug) return fail(409, 'skill_exists', {slug: 'wochenbericht', title: current().title});
      if (file === 'vorhanden.zip' && up.mode === 'update') {
        push('import', 'Importiert aus vorhanden.zip', {body: 'Aus dem Paket.'});
        return {json: {ok: true, skill: skill(), created: false, skipped: [], ignored: []}};
      }
      const slug = up.slug || 'neu';
      st.other.push({id: String(st.other.length + 2).repeat(32), slug, title: slug === 'neu' ? 'Neu' : 'Kopie', description: 'D', triggers: [], requires: [],
        project: null, enabled: true, shared: false, priority: 'normal', files: [], missing: [], revision: 1});
      return {status: 201, json: {ok: true, skill: st.other[st.other.length - 1], created: true,
        skipped: slug === 'neu' ? [{name: 'neu/LICENSE.txt', reason: 'outside_folders'}] : [], ignored: slug === 'neu' ? ['license'] : []}};
    }
    st.posts.push(Object.assign({action}, body));
    if (opts.noRevisions && 'base_revision' in body) return fail(400, 'unexpected_base');
    const stale = () => !opts.noRevisions && body.base_revision !== current().rev ? fail(409, 'skill_conflict', {revision: current().rev}) : null;
    if (action === 'save') {
      const s = stale(); if (s) return s;
      push('save', '', {title: body.title, slug: body.slug, description: body.description, body: body.body});
      return {json: {ok: true, skill: skill()}};
    }
    if (action === 'file_save') {
      const s = stale(); if (s) return s;
      const files = current().files.filter(f => f.path !== body.path).concat([{path: body.path, kind: 'reference', content: body.content, size_bytes: body.content.length}]);
      push('file', body.path, {files});
      return {json: {ok: true, skill: skill()}};
    }
    if (action === 'restore') {
      const s = stale(); if (s) return s;
      const r = st.revs.find(x => x.rev === body.rev);
      push('restore', 'Wiederhergestellt aus Revision ' + body.rev, Object.assign({}, r));
      return {json: {ok: true, skill: skill()}};
    }
    return fail(404, 'unknown_action');
  }
  // Someone else saves in between.
  st.someoneElse = () => push('save', '', {description: 'Von jemand anderem geändert'});
  return {st, handle};
}
const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.90', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};
async function open(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', acceptDownloads: true});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/skill_packages.php') {
      if (req.method() === 'POST') assert.equal(req.headers()['x-coreui-csrf'], 'tok', 'changes with CSRF');
      const raw = req.method() === 'POST' ? (req.postDataBuffer() || Buffer.alloc(0)) : Buffer.alloc(0);
      const isJson = (req.headers()['content-type'] || '').includes('application/json');
      const out = srv.handle(req.method(), u, isJson ? JSON.parse(raw.toString('utf8') || '{}') : {}, raw);
      if (out.body) return route.fulfill({status: out.status, headers: out.headers, body: out.body});
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
  await until(page, () => !document.getElementById('skMain').hidden && document.querySelectorAll('#skOwn .sk-row').length > 0);
  return {context, page, errors};
}
const texts = (page, sel) => page.$$eval(sel, l => l.map(x => x.textContent));
const status = page => page.textContent('#skStatus');
const diffLines = page => page.$$eval('#skCompare .sk-diff-line', l => l.map(x => (x.classList.contains('is-add') ? 'add:' : x.classList.contains('is-del') ? 'del:' : 'same:') + x.textContent));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let srv = server();
    let {context, page, errors} = await open(browser, srv);
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skHistory'));
    assert.equal(await page.textContent('#skEdit .sk-rev'), 'Revision 3');
    assert.match(await page.getAttribute('#skExport', 'href'), /api\/skill_packages\.php\?action=export&id=1{32}$/);

    // Verlauf: who, when, what.
    await page.click('#skHistory');
    await until(page, () => document.querySelectorAll('.sk-rev-row').length === 3);
    assert.equal(await page.textContent('#skEdit .sk-edit-title'), 'Verlauf von „Wochenbericht Team“');
    assert.deepEqual(await texts(page, '.sk-rev-row strong'), ['Revision 3 · Gespeichert · aktuell', 'Revision 2 · Begleitdatei', 'Revision 1 · Angelegt']);
    assert.deepEqual(await texts(page, '.sk-rev-row .sk-desc'), ['Geändert: Name, Anleitung', 'templates/bericht.md · Dateien: 1 neu']);
    assert.equal(await page.textContent('.sk-rev-row[data-rev="2"] small'), 'von Ines · 18.11.2026, 02:15');
    assert.deepEqual(await page.$$eval('.sk-rev-row', l => l.map(r => Array.from(r.querySelectorAll('button, a')).map(b => b.textContent).join('|'))),
      ['VERGLEICHEN|ZIP', 'VERGLEICHEN|ZIP|WIEDERHERSTELLEN', 'ZIP|WIEDERHERSTELLEN']);
    assert.match(await page.getAttribute('.sk-rev-row[data-rev="2"] a', 'href'), /action=export&id=1{32}&rev=2$/);
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skill-history.png')});
    // Vergleichen: what revision 3 changed, then from revision 1.
    await page.click('.sk-rev-row[data-rev="3"] >> text=VERGLEICHEN');
    await until(page, () => document.querySelectorAll('#skCompare .sk-diff-line').length > 0);
    assert.equal(await page.textContent('#skCompare .pz-lead'), 'Revision 2 → Revision 3');
    assert.equal(await page.textContent('.sk-cmp-field[data-field="title"]'), 'Name: Wochenbericht → Wochenbericht Team');
    assert.deepEqual(await diffLines(page), ['same:  Schreibe knapp.', 'del:- Nenne Erledigtes.', 'add:+ Nenne Erledigtes und Offenes.']);
    assert.equal(await page.textContent('#skCompare .sk-cmp-body > .sk-empty:last-child'), 'Keine Änderung an den Begleitdateien.');
    await page.selectOption('#skCmpFrom', '1');
    await until(page, () => /Revision 1 → Revision 3/.test((document.querySelector('#skCompare .pz-lead') || {}).textContent || ''));
    await until(page, () => document.querySelector('.sk-cmp-file'));
    assert.equal(await page.textContent('.sk-cmp-file'), 'templates/bericht.md · neu');
    assert.deepEqual((await diffLines(page)).slice(-2), ['add:+ # Bericht', 'add:+ {{woche}}']);
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skill-compare.png')});
    // Wiederherstellen: asks first, then a new revision on top.
    await page.click('#skBack');
    await until(page, () => document.querySelectorAll('.sk-rev-row').length === 3);
    let asked = '';
    page.once('dialog', d => { asked = d.message(); d.dismiss(); });
    await page.click('.sk-rev-row[data-rev="1"] >> text=WIEDERHERSTELLEN');
    await page.waitForTimeout(150);
    assert.equal(asked, 'Revision 1 wiederherstellen? Sie kommt als neue Revision obendrauf; alle anderen bleiben.');
    assert.equal(srv.st.posts.length, 0, 'nothing without a yes');
    page.once('dialog', d => d.accept());
    await page.click('.sk-rev-row[data-rev="1"] >> text=WIEDERHERSTELLEN');
    await until(page, () => document.getElementById('skForm') && document.querySelector('#skEdit .sk-rev').textContent === 'Revision 4');
    assert.deepEqual(srv.st.posts.pop(), {action: 'restore', id: ID, rev: 1, base_revision: 3});
    assert.equal(await status(page), 'Revision 1 ist wiederhergestellt, jetzt Revision 4.');
    assert.equal(await page.inputValue('#skTitle'), 'Wochenbericht');

    // Someone else saved in between: the text stays, the person decides.
    srv.st.someoneElse();
    await page.fill('#skTitle', 'Meine Fassung');
    await page.click('#skSave');
    await until(page, () => document.getElementById('skConflict'));
    assert.equal(srv.st.posts.pop().base_revision, 4);
    assert.equal(await page.textContent('#skConflict p'), 'Jemand hat diesen Skill inzwischen geändert (jetzt Revision 5). Deine Änderungen stehen noch im Editor.');
    assert.equal(await page.inputValue('#skTitle'), 'Meine Fassung', 'the text is kept');
    await page.click('#skConflictDiff');
    await until(page, () => /deine Fassung im Editor/.test((document.querySelector('#skCompare .pz-lead') || {}).textContent || ''));
    assert.equal(await page.textContent('#skCompare .pz-lead'), 'Revision 5 → deine Fassung im Editor');
    assert.deepEqual(await texts(page, '.sk-cmp-field'), ['Name: Wochenbericht → Meine Fassung', 'Beschreibung: Von jemand anderem geändert → Die Woche']);
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skill-conflict-compare.png')});
    await page.click('#skBack');
    await until(page, () => document.getElementById('skConflict'));
    assert.equal(await page.inputValue('#skTitle'), 'Meine Fassung', 'still kept after looking');
    await page.click('#skConflictSave');
    await until(page, () => document.getElementById('skStatus').textContent === 'Gespeichert.');
    assert.equal(srv.st.posts.pop().base_revision, 5);
    assert.equal(await page.locator('#skConflict').count(), 0);
    assert.equal(await page.textContent('#skEdit .sk-rev'), 'Revision 6');
    // Again, and this time reload.
    srv.st.someoneElse();
    await page.fill('#skTitle', 'Verworfen');
    await page.click('#skSave');
    await until(page, () => document.getElementById('skConflict'));
    await page.click('#skConflictDrop');
    await until(page, () => document.getElementById('skTitle') && document.getElementById('skTitle').value === 'Meine Fassung' && !document.getElementById('skConflict'));
    assert.equal(await page.textContent('#skEdit .sk-rev'), 'Revision 7');
    // A companion file after someone else's change.
    srv.st.someoneElse();
    await page.selectOption('#skFileFolder', 'references');
    await page.fill('#skFileName', 'regeln.md');
    await page.fill('#skFileContent', 'Hoechstens eine Seite.');
    await page.click('#skFileSave');
    await until(page, () => /jetzt Revision 8/.test(document.getElementById('skStatus').textContent));
    assert.equal(await status(page), 'Jemand hat diesen Skill inzwischen geändert (jetzt Revision 8). Deine Datei steht noch im Formular; '
      + 'speicherst du sie noch einmal, kommt sie auf die neue Revision.');
    assert.equal(await page.inputValue('#skFileContent'), 'Hoechstens eine Seite.');
    await page.click('#skFileSave');
    await until(page, () => document.getElementById('skStatus').textContent === 'Die Datei ist gespeichert.');
    assert.equal(srv.st.posts.pop().base_revision, 8);
    // Exportieren is a download.
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('#skExport')]);
    assert.equal(download.suggestedFilename(), 'wochenbericht.zip');
    await page.click('#skBack');
    await until(page, () => !document.getElementById('skMain').hidden);

    // Importieren.
    const zipFile = name => ({name, mimeType: 'application/zip', buffer: Buffer.from('PK fake ' + name)});
    await page.setInputFiles('#skImportFile', zipFile('neu.zip'));
    await until(page, () => /ist importiert/.test(document.getElementById('skStatus').textContent));
    assert.equal(await status(page), '„Neu“ ist importiert. Nicht übernommen: neu/LICENSE.txt (außerhalb der vier Ordner). Übergangen: license.');
    assert.deepEqual(srv.st.uploads.pop(), {file: 'neu.zip', mode: 'new', slug: ''});
    await until(page, () => document.querySelector('#skOwn [data-skill="neu"]'));
    await page.setInputFiles('#skImportFile', zipFile('vorhanden.zip'));
    await until(page, () => !document.getElementById('skImportBox').hidden);
    assert.equal(await page.textContent('#skImportBox p'), 'Den Skill „Meine Fassung“ (wochenbericht) hast du schon. Wie soll „vorhanden.zip“ übernommen werden?');
    if (shots) await page.locator('#view-skills').screenshot({path: path.join(shots, 'skill-import-choice.png')});
    await page.click('#skImportUpdate');
    await until(page, () => /als Revision 10 übernommen/.test(document.getElementById('skStatus').textContent));
    assert.equal(await page.isHidden('#skImportBox'), true);
    assert.deepEqual(srv.st.uploads.slice(-2), [{file: 'vorhanden.zip', mode: 'new', slug: ''}, {file: 'vorhanden.zip', mode: 'update', slug: ''}]);
    await page.setInputFiles('#skImportFile', zipFile('vorhanden.zip'));
    await until(page, () => !document.getElementById('skImportBox').hidden);
    await page.click('#skImportRename');
    assert.equal(await page.inputValue('#skImportSlug'), 'wochenbericht-kopie');
    await page.click('#skImportCreate');
    await until(page, () => /„Kopie“ ist importiert/.test(document.getElementById('skStatus').textContent));
    assert.deepEqual(srv.st.uploads.pop(), {file: 'vorhanden.zip', mode: 'new', slug: 'wochenbericht-kopie'});
    await page.setInputFiles('#skImportFile', zipFile('vorhanden.zip'));
    await until(page, () => !document.getElementById('skImportBox').hidden);
    await page.click('#skImportCancel');
    assert.equal(await page.isHidden('#skImportBox'), true);
    await page.setInputFiles('#skImportFile', zipFile('kaputt.zip'));
    await until(page, () => document.getElementById('skStatus').classList.contains('is-error'));
    assert.equal(await status(page), 'Das Paket enthält einen Pfad, der aus dem Paket herausführt, oder einen Link. Es wurde nichts übernommen. (probe/../../evil.md)');
    assert.deepEqual(errors, []);
    await context.close();

    // Without migration 054: no history, no base revision.
    srv = server({noRevisions: true});
    ({context, page, errors} = await open(browser, srv));
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skExport'));
    assert.equal(await page.locator('#skHistory, #skEdit .sk-rev').count(), 0);
    await page.click('#skSave');
    await until(page, () => document.getElementById('skStatus').textContent === 'Gespeichert.');
    assert.equal('base_revision' in srv.st.posts.pop(), false);
    await context.close();

    // A phone: history and comparison without sideways scrolling.
    srv = server();
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    await page.click('#skOwn [data-skill="wochenbericht"]');
    await until(page, () => document.getElementById('skHistory'));
    await page.click('#skHistory');
    await until(page, () => document.querySelectorAll('.sk-rev-row').length === 3);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'history fits');
    await page.click('.sk-rev-row[data-rev="3"] >> text=VERGLEICHEN');
    await until(page, () => document.querySelectorAll('#skCompare .sk-diff-line').length > 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, 'comparison fits');
    if (shots) await page.screenshot({path: path.join(shots, 'skill-compare-phone.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI skill revisions UI: revision in the editor, history (who, when, what), comparison with lines and two revisions,'
    + ' restoring with confirmation as a new revision, conflicts (text kept, differences, saving anyway, reloading, a file), export download,'
    + ' import (new, as a revision, other short name, cancelled, refused with the entry), without migration 054, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
