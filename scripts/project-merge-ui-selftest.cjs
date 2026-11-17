// CoreAI 0.7.81 (P11 B): merging working states with conflicts in the tab Dateien, in a real browser.
//   node scripts/project-merge-ui-selftest.cjs
// The real js/coreui-project-files.js and styles with a stand-in for api/project_files.php that
// keeps working states, versions with two parents and a three-way merge (line by line for texts of
// the same length; the real line merge is checked in scripts/project-merge-selftest.php).
// Checked: the merge button only where there is something to merge, the preview (taken over,
// merged line by line), merging without conflicts (a version with two parents, files and list
// loaded again), conflicts that block the merge until each has a choice, comparing both versions,
// a text of one's own (markers refused, then accepted), deleted on one side, unsaved work saved
// first, a merge that changed meanwhile, cancelling, no merging without migration 050, a phone
// without sideways scrolling and the dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const PROJECT = 'f'.repeat(32);
const ORIGIN = 'http://coreui.test';

const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const m = {
  canMerge: true, posts: [], lists: 0, nextCommit: 1, nextBranch: 1,
  files: {'index.html': '<h1>Start</h1>\n<p>Text</p>\n', 'style.css': 'h1 { color: red; }\np { margin: 0; }\n', 'app.js': 'let a = 1;\n'},
  commits: [], branches: [], active: 0,
};
const byId = id => m.commits.find(c => c.id === id);
const headFiles = b => (b.head ? byId(b.head).files : {});
const activeBranch = () => m.branches.find(b => b.id === m.active);
function commit(message, author, files, mergeParent) {
  const b = activeBranch();
  const c = {id: m.nextCommit++, parent_id: b.head, merge_parent_id: mergeParent || null, message, author, files: Object.assign({}, files),
    branch_id: b.id, created_at: '2026-11-17 10:' + String(10 + m.nextCommit) + ':00'};
  m.commits.push(c); b.head = c.id;
  return c;
}
function changes(from, to) {
  const added = Object.keys(to).filter(p => !(p in from)).sort();
  const deleted = Object.keys(from).filter(p => !(p in to)).sort();
  const modified = Object.keys(to).filter(p => p in from && from[p] !== to[p]).sort();
  return {added, modified, deleted, clean: !added.length && !modified.length && !deleted.length};
}
function ancestors(id) {
  const seen = new Set();
  const todo = id ? [id] : [];
  while (todo.length) {
    const c = byId(todo.pop());
    if (!c || seen.has(c.id)) continue;
    seen.add(c.id);
    [c.parent_id, c.merge_parent_id].forEach(p => p && todo.push(p));
  }
  return seen;
}
const treeOf = files => sha(JSON.stringify(Object.keys(files).sort().map(p => [p, files[p]])));
function init() {
  if (m.branches.length) return;
  m.branches.push({id: m.nextBranch++, name: 'main', head: null, worktree: null});
  m.active = m.branches[0].id;
  commit('Ausgangsstand', 'auto', m.files);
}
function state() {
  const active = activeBranch();
  const inActive = ancestors(active.head);
  const log = [...inActive].sort((a, b) => b - a).map(id => {
    const c = byId(id);
    return {id: c.id, parent_id: c.parent_id, merge_parent_id: c.merge_parent_id, message: c.message, author: c.author,
      files_count: Object.keys(c.files).length, bytes: 1, branch_id: c.branch_id, created_at: c.created_at};
  });
  const branches = m.branches.slice().sort((a, b) => a.name.localeCompare(b.name)).map(b => ({
    id: b.id, name: b.name, head_commit_id: b.head, active: b.id === m.active, updated_at: '2026-11-17 10:00:00',
    merged: !b.head || inActive.has(b.head),
    unsaved: b.id === m.active ? !changes(headFiles(b), m.files).clean : !!b.worktree && !changes(headFiles(b), b.worktree).clean,
  }));
  return {active: branches.find(b => b.active), branches, status: changes(headFiles(active), m.files), log, can_merge: m.canMerge,
    limits: {branches: 10, commits: 200, used_commits: m.commits.length}};
}
function park(b) { b.worktree = changes(headFiles(b), m.files).clean ? null : Object.assign({}, m.files); }
function take(b) { m.files = Object.assign({}, b.worktree || headFiles(b)); b.worktree = null; m.active = b.id; }
// A line merge for texts of the same length, enough for the stand-in.
function merge3(base, ours, theirs, into, from) {
  const [o, a, t] = [base, ours, theirs].map(x => x.split('\n'));
  if (o.length !== a.length || o.length !== t.length) return {clean: false, content: '<<<<<<< ' + into + '\n' + ours + '=======\n' + theirs + '>>>>>>> ' + from + '\n'};
  let clean = true;
  const out = o.map((line, i) => {
    if (a[i] === line) return t[i];
    if (t[i] === line || a[i] === t[i]) return a[i];
    clean = false;
    return '<<<<<<< ' + into + '\n' + a[i] + '\n=======\n' + t[i] + '\n>>>>>>> ' + from;
  });
  return {clean, content: out.join('\n')};
}
function plan(fromId) {
  const into = activeBranch();
  const from = m.branches.find(b => b.id === fromId);
  if (!from) return {error: [404, 'branch_not_found']};
  if (!from.head || ancestors(into.head).has(from.head)) return {error: [409, 'already_merged']};
  const common = [...ancestors(into.head)].filter(id => ancestors(from.head).has(id));
  const baseId = common.length ? Math.max(...common) : null;
  const base = baseId ? byId(baseId).files : {};
  const ours = m.files;
  const theirs = byId(from.head).files;
  const result = {};
  const merged = [];
  const conflicts = [];
  [...new Set([...Object.keys(base), ...Object.keys(ours), ...Object.keys(theirs)])].sort().forEach(p => {
    const [b, o, t] = [base[p], ours[p], theirs[p]];
    if (o === t) { if (o !== undefined) result[p] = o; return; }
    if (o === b) { if (t !== undefined) result[p] = t; return; }
    if (t === b) { if (o !== undefined) result[p] = o; return; }
    if (o !== undefined && t !== undefined) {
      const x = merge3(b || '', o, t, into.name, from.name);
      if (x.clean) { result[p] = x.content; merged.push(p); return; }
      conflicts.push({path: p, kind: b === undefined ? 'added' : 'content', count: 1, ours: o, theirs: t, merged: x.content});
      return;
    }
    conflicts.push({path: p, kind: o === undefined ? 'deleted_ours' : 'deleted_theirs', count: 1, ours: o === undefined ? null : o,
      theirs: t === undefined ? null : t, merged: null});
  });
  const decided = Object.assign({}, ours);
  conflicts.forEach(c => delete decided[c.path]);
  return {into, from, baseId, result, merged, conflicts, changes: changes(decided, result), unsaved: !changes(headFiles(into), ours).clean};
}

const harness = `<!doctype html><html lang="de" data-coreui-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/console.css"><link rel="stylesheet" href="/css/coreui-theme.css">
<link rel="stylesheet" href="/css/coreui-projects.css"><link rel="stylesheet" href="/css/coreui-project-files.css">
<link rel="stylesheet" href="/css/coreui-project-theme.css"></head>
<body class="app-body project-home-active"><section class="project-home" id="coreuiProjectHome"><div class="project-home-content">
<section class="project-file-results" id="projectFilesPanel" aria-label="Projektdateien"></section>
</div></section><script src="/js/coreui-project-files.js"></script>
<script>window.CoreAIProjectFiles.open(${JSON.stringify(PROJECT)});</script></body></html>`;

const json = (r, status, body) => r.fulfill({status, contentType: 'application/json', body: JSON.stringify(body)});
const fail = (r, status, error) => json(r, status, {ok: false, error});
async function route(r) {
  const req = r.request();
  const u = new URL(req.url());
  if (u.origin !== ORIGIN) return r.abort();
  if (u.pathname === '/harness.html') return r.fulfill({contentType: 'text/html', body: harness});
  if (u.pathname === '/api/project_files.php') {
    init();
    if (req.method() === 'POST') {
      const body = JSON.parse(req.postData() || '{}');
      m.posts.push(body);
      const active = activeBranch();
      if (body.action === 'vcs_commit') {
        if (changes(headFiles(active), m.files).clean) return fail(r, 409, 'nothing_to_commit');
        commit(body.message.trim(), 'person', m.files);
        return json(r, 201, {ok: true, state: state()});
      }
      if (body.action === 'vcs_branch') {
        const b = {id: m.nextBranch++, name: body.name, head: active.head, worktree: null};
        m.branches.push(b);
        if (body.carry) { m.active = b.id; } else { park(active); take(b); }
        return json(r, 201, {ok: true, state: state()});
      }
      if (body.action === 'vcs_switch') {
        park(active); take(m.branches.find(x => x.id === body.branch_id));
        return json(r, 200, {ok: true, state: state()});
      }
      if (body.action === 'vcs_merge') {
        const p = plan(body.branch_id);
        if (p.error) return fail(r, p.error[0], p.error[1]);
        if (body.ours_tree !== treeOf(m.files) || body.theirs_head !== p.from.head) return fail(r, 409, 'merge_changed');
        const final = Object.assign({}, p.result);
        for (const c of p.conflicts) {
          const res = (body.resolutions || {})[c.path];
          if (!res) return fail(r, 409, 'merge_unresolved');
          if (res.choice === 'ours' && c.ours !== null) final[c.path] = c.ours;
          else if (res.choice === 'theirs' && c.theirs !== null) final[c.path] = c.theirs;
          else if (res.choice === 'text') {
            if (/^<{7} /m.test(res.text) || /^>{7} /m.test(res.text)) return fail(r, 400, 'conflict_markers');
            final[c.path] = res.text;
          }
        }
        if (p.unsaved) commit('Automatisch gesichert vor dem Zusammenführen', 'auto', m.files);
        m.files = final;
        commit(body.message || 'Zusammengeführt: ' + p.from.name, 'person', final, p.from.head);
        return json(r, 201, {ok: true, commit: {id: m.nextCommit - 1}, state: state()});
      }
      return fail(r, 400, 'unknown_action');
    }
    if (u.searchParams.has('vcs')) return json(r, 200, Object.assign({ok: true, ready: true, csrf_token: 'csrf'}, state()));
    if (u.searchParams.has('vcs_merge')) {
      if (!m.canMerge) return fail(r, 503, 'project_merge_migration_required');
      const p = plan(Number(u.searchParams.get('vcs_merge')));
      if (p.error) return fail(r, p.error[0], p.error[1]);
      return json(r, 200, {ok: true, merge: {into: {id: p.into.id, name: p.into.name},
        from: {id: p.from.id, name: p.from.name, unsaved: !!p.from.worktree}, base_commit_id: p.baseId, theirs_head: p.from.head,
        ours_tree: treeOf(m.files), unsaved: p.unsaved, changes: p.changes, merged: p.merged, conflicts: p.conflicts,
        message: 'Zusammengeführt: ' + p.from.name}});
    }
    if (u.searchParams.has('vcs_commit')) {
      const c = byId(Number(u.searchParams.get('vcs_commit')));
      return json(r, 200, {ok: true, commit: c, changes: changes(c.parent_id ? byId(c.parent_id).files : {}, c.files)});
    }
    if (u.searchParams.has('vcs_file')) {
      const which = u.searchParams.get('vcs_file');
      const p = u.searchParams.get('path');
      const files = which === 'work' ? m.files : byId(Number(which)).files;
      return json(r, 200, {ok: true, file: {path: p, exists: p in files, content: files[p] || ''}});
    }
    m.lists++;
    const list = Object.keys(m.files).sort().map(p => ({path: p, size_bytes: Buffer.byteLength(m.files[p]), sha256: sha(m.files[p]),
      revision: 1, updated_at: '2026-11-17 10:00:00'}));
    return json(r, 200, {ok: true, files: list, count: list.length, bytes: 100,
      limits: {file_bytes: 524288, files: 200, project_bytes: 10485760, revisions: 20}, csrf_token: 'csrf'});
  }
  const file = path.join(root, u.pathname);
  if (/^\/(css|js)\/[a-z0-9-]+\.(css|js)$/.test(u.pathname) && fs.existsSync(file)) {
    return r.fulfill({contentType: u.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync(file)});
  }
  return r.fulfill({status: 404, body: ''});
}

const text = (pg, sel) => pg.$eval(sel, el => el.textContent);
// A number, never an element handle: a failing assertion must not try to print a whole handle.
const count = (pg, sel) => pg.$$eval(sel, els => els.length);
const reopen = async pg => {
  await pg.click('#projectVcsClose');
  await pg.click('#projectFilesVcs');
  await pg.waitForSelector('.project-vcs-log .project-vcs-commit');
};
const logMessages = pg => pg.$$eval('.project-vcs-commit-text > span', els => els.map(e => e.textContent));
const errorShown = (pg, pattern) => pg.waitForFunction(p => [...document.querySelectorAll('.project-vcs .project-files-status.is-error')]
  .some(e => new RegExp(p).test(e.textContent)), pattern);
async function save(pg, message) {
  await pg.fill('#projectVcsMessage', message);
  await pg.click('#projectVcsCommit');
  await pg.waitForFunction(() => document.querySelector('.project-vcs-status > strong').textContent === 'Alles gesichert');
}
async function switchTo(pg, name) {
  await pg.click('[data-switch="' + name + '"]');
  await pg.waitForFunction(n => new RegExp('^' + n).test(document.querySelector('.project-vcs-branch.is-active code').textContent), name);
}

(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({viewport: {width: 1100, height: 900}});
    await context.route(ORIGIN + '/**', route);
    const pg = await context.newPage();
    const errors = [];
    pg.on('pageerror', e => errors.push(e.message));
    pg.on('dialog', d => d.accept());
    await pg.goto(ORIGIN + '/harness.html');
    await pg.waitForSelector('#projectFilesVcs');
    await pg.click('#projectFilesVcs');
    await pg.waitForSelector('.project-vcs-log .project-vcs-commit');

    // A working state of its own: a new title, a new file, the first line of the styles.
    await pg.click('#projectVcsNewBranch');
    await pg.fill('#projectVcsBranchName', 'level');
    await pg.click('#projectVcsCreate');
    await pg.waitForFunction(() => /level/.test(document.querySelector('.project-vcs-branch.is-active').textContent));
    assert.equal(await count(pg, '[data-merge]'), 0, 'main has nothing new for level: no merge button');
    m.files['index.html'] = '<h1>Level</h1>\n<p>Text</p>\n';
    m.files['level.js'] = 'const level = 2;\n';
    m.files['style.css'] = 'h1 { color: gold; }\np { margin: 0; }\n';
    await reopen(pg);
    await save(pg, 'Level');
    await switchTo(pg, 'main');
    m.files['style.css'] = 'h1 { color: red; }\np { margin: 1em; }\n';
    await reopen(pg);
    await save(pg, 'Abstand');

    // The preview: what comes over, merged line by line, nothing changed yet.
    const before = JSON.stringify(m.files);
    await pg.click('[data-merge="level"]');
    await pg.waitForSelector('#projectVcsMerge');
    assert.equal(await text(pg, '#projectVcsMerge .project-file-compare-head strong'), '„level“ in „main“ zusammenführen');
    const listed = await pg.$$eval('#projectVcsMerge .project-vcs-change', els => els.map(e => e.getAttribute('data-path') + ':' + e.querySelector('small').textContent));
    assert.deepEqual(listed, ['level.js:neu', 'index.html:geändert', 'style.css:geändert'], 'taken over: ' + JSON.stringify(listed));
    assert.match(await text(pg, '#projectVcsMerge'), /Zeilenweise zusammengeführt: style\.css/);
    assert.equal(await count(pg, '.project-vcs-log'), 0, 'while a merge is open it is the only thing in the panel');
    assert.equal(JSON.stringify(m.files), before, 'a preview changes nothing');
    await pg.click('#projectVcsMerge .project-vcs-change[data-path="index.html"]');
    await pg.waitForSelector('.project-vcs-view .project-diff-row.is-add');
    assert.match(await text(pg, '.project-vcs-view .project-file-compare-head strong'), /index\.html · Änderung in „level“/);
    await pg.click('.project-vcs-view .project-file-compare-head button');
    assert.equal(await pg.$eval('#projectVcsMergeGo', b => b.disabled), false, 'no conflicts: ready to merge');
    const listsBefore = m.lists;
    await pg.click('#projectVcsMergeGo');
    await pg.waitForFunction(() => !document.querySelector('#projectVcsMerge') && document.querySelector('.project-vcs-log'));
    assert.deepEqual(m.files, {'app.js': 'let a = 1;\n', 'index.html': '<h1>Level</h1>\n<p>Text</p>\n', 'level.js': 'const level = 2;\n',
      'style.css': 'h1 { color: gold; }\np { margin: 1em; }\n'}, 'both sides are in the files');
    const sent = m.posts.filter(p => p.action === 'vcs_merge').pop();
    assert.equal(sent.message, 'Zusammengeführt: level');
    assert.ok(/^[a-f0-9]{64}$/.test(sent.ours_tree) && sent.theirs_head === m.branches.find(b => b.name === 'level').head, 'what the preview showed goes along');
    assert.deepEqual(await logMessages(pg), ['Zusammengeführt: level', 'Abstand', 'Level', 'Ausgangsstand'], 'the merged versions are in the log, newest first');
    assert.match(await text(pg, '.project-vcs-commit.is-head small'), /Zusammenführung · neueste$/);
    assert.ok(m.lists > listsBefore, 'the file list is loaded again');
    assert.equal(await count(pg, '[data-merge="level"]'), 0, 'level is merged: no merge button any more');

    // Conflicts: the same title on both sides, app.js deleted here and changed there, unsaved work here.
    await switchTo(pg, 'level');
    m.files['index.html'] = '<h1>Bonus</h1>\n<p>Text</p>\n';
    m.files['app.js'] = 'let a = 2;\n';
    await reopen(pg);
    await save(pg, 'Bonus');
    await switchTo(pg, 'main');
    m.files['index.html'] = '<h1>Ende</h1>\n<p>Text</p>\n';
    delete m.files['app.js'];
    await reopen(pg);
    await pg.click('[data-merge="level"]');
    await pg.waitForSelector('#projectVcsMerge .project-vcs-conflict');
    assert.match(await text(pg, '#projectVcsMerge'), /Deine ungesicherten Änderungen werden vorher als eigene Version gesichert\./);
    assert.deepEqual(await pg.$$eval('.project-vcs-conflict', els => els.map(e => e.getAttribute('data-conflict'))), ['app.js', 'index.html']);
    assert.match(await text(pg, '[data-conflict="app.js"] small'), /hier gelöscht, dort geändert/);
    assert.deepEqual(await pg.$$eval('[data-conflict="app.js"] .project-vcs-choice span', els => els.map(e => e.textContent)),
      ['Gelöscht lassen', 'Fassung von „level“'], 'a deleted file has no text of its own');
    assert.deepEqual(await pg.$$eval('[data-conflict="index.html"] .project-vcs-choice span', els => els.map(e => e.textContent)),
      ['Meine Fassung („main“)', 'Fassung von „level“', 'Selbst zusammenführen']);
    assert.equal(await pg.$eval('#projectVcsMergeGo', b => b.disabled), true, 'unsolved conflicts block the merge');
    assert.match(await text(pg, '#projectVcsMerge'), /Noch 2 Konflikte ohne Lösung\./);
    await pg.click('[data-compare="index.html"]');
    await pg.waitForSelector('.project-vcs-view .project-diff-row.is-del');
    assert.match(await text(pg, '.project-vcs-view .project-file-compare-head strong'), /„main“ gegenüber „level“/);
    await pg.click('.project-vcs-view .project-file-compare-head button');
    await pg.check('[data-conflict="app.js"] input[value="theirs"]');
    await pg.check('[data-conflict="index.html"] input[value="text"]');
    await pg.waitForSelector('[data-conflict="index.html"] textarea');
    assert.equal(await pg.$eval('[data-conflict="index.html"] textarea', a => a.value),
      '<<<<<<< main\n<h1>Ende</h1>\n=======\n<h1>Bonus</h1>\n>>>>>>> level\n<p>Text</p>\n', 'the text with markers to edit');
    assert.equal(await pg.$eval('#projectVcsMergeGo', b => b.disabled), false);
    if (process.env.COREAI_SHOT_CONFLICT) await pg.screenshot({path: process.env.COREAI_SHOT_CONFLICT, fullPage: true});
    await pg.click('#projectVcsMergeGo');
    await errorShown(pg, 'Konfliktmarker');
    assert.equal(m.files['index.html'], '<h1>Ende</h1>\n<p>Text</p>\n', 'markers left: nothing changed');
    await pg.fill('[data-conflict="index.html"] textarea', '<h1>Ende mit Bonus</h1>\n<p>Text</p>\n');
    await pg.fill('#projectVcsMergeMessage', 'Ende und Bonus');
    await pg.click('#projectVcsMergeGo');
    await pg.waitForFunction(() => !document.querySelector('#projectVcsMerge') && document.querySelector('.project-vcs-log'));
    assert.equal(m.files['index.html'], '<h1>Ende mit Bonus</h1>\n<p>Text</p>\n');
    assert.equal(m.files['app.js'], 'let a = 2;\n', 'the other version of app.js came back');
    assert.deepEqual((await logMessages(pg)).slice(0, 2), ['Ende und Bonus', 'Automatisch gesichert vor dem Zusammenführen']);
    assert.deepEqual(m.posts.filter(p => p.action === 'vcs_merge').pop().resolutions,
      {'app.js': {choice: 'theirs'}, 'index.html': {choice: 'text', text: '<h1>Ende mit Bonus</h1>\n<p>Text</p>\n'}});

    // Something changes between preview and merge: the preview comes again, with a note.
    await switchTo(pg, 'level');
    m.files['level.js'] = 'const level = 3;\n';
    await reopen(pg);
    await save(pg, 'Level drei');
    await switchTo(pg, 'main');
    await pg.click('[data-merge="level"]');
    await pg.waitForSelector('#projectVcsMerge');
    m.files['app.js'] = 'let a = 3;\n';
    await pg.click('#projectVcsMergeGo');
    await pg.waitForFunction(() => /Inzwischen hat sich etwas geändert/.test(document.querySelector('#projectVcsMerge').textContent));
    assert.equal(m.files['level.js'], 'const level = 2;\n', 'nothing merged while it changed');

    // Phone and dark look, then cancel.
    await pg.setViewportSize({width: 360, height: 780});
    const phone = await pg.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert.ok(phone.scroll <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await pg.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await pg.$eval('#projectVcsMerge', el => ({bg: getComputedStyle(el.closest('.project-vcs')).backgroundColor, color: getComputedStyle(el).color}));
    assert.notEqual(dark.bg, dark.color, 'readable in the dark look');
    if (process.env.COREAI_SHOT) await pg.screenshot({path: process.env.COREAI_SHOT, fullPage: true});
    await pg.click('#projectVcsMergeCancel');
    await pg.waitForSelector('.project-vcs-log');
    assert.equal(await count(pg, '#projectVcsMerge'), 0);

    // Without migration 050 there is no merging.
    m.canMerge = false;
    await reopen(pg);
    assert.equal(await count(pg, '[data-merge]'), 0, 'no merge button without migration 050');
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
  console.log('CoreAI Zusammenfuehren UI: Knopf nur mit Neuem, Vorschau, zeilenweise zusammengefuehrt, Version mit zwei Eltern, Konflikte mit Wahl, Vergleich, eigener Text (Marker abgelehnt), geloeschte Datei, ungesicherte Arbeit zuerst gesichert, inzwischen geaendert, Abbrechen, ohne Migration 050, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI project merge UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
