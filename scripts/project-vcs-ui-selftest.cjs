// CoreAI 0.7.80 (P11 A): versions and working states in the tab Dateien, in a real browser.
//   node scripts/project-vcs-ui-selftest.cjs
// The real js/coreui-project-files.js and styles with a stand-in for api/project_files.php that
// keeps working states, versions and their own files the way api/project_vcs_store.php does.
// Checked: the first version, an unsaved change with its line comparison, saving, a new working
// state that carries the change, switching back (main unchanged), restoring with the automatic
// save first, what a version changed, the Git ZIP link, a refused name, deleting a working state,
// a missing migration, a phone without sideways scrolling and the dark look.
'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const PROJECT = 'e'.repeat(32);
const ORIGIN = 'http://coreui.test';

const sha = s => crypto.createHash('sha256').update(s, 'utf8').digest('hex');
const m = {
  ready: true, posts: [], lists: 0, nextCommit: 1, nextBranch: 1,
  files: {'index.html': '<h1>Alter Titel</h1>\n', 'style.css': 'h1 { color: red; }\n'},
  commits: [], branches: [], active: 0,
};
function commit(message, author, files) {
  const b = m.branches.find(x => x.id === m.active);
  const c = {id: m.nextCommit++, parent_id: b.head, message, author, files: Object.assign({}, files), branch_id: b.id,
    created_at: '2026-11-17 09:0' + m.nextCommit + ':00'};
  m.commits.push(c); b.head = c.id;
  return c;
}
const byId = id => m.commits.find(c => c.id === id);
const headFiles = b => (b.head ? byId(b.head).files : {});
function changes(from, to) {
  const added = Object.keys(to).filter(p => !(p in from)).sort();
  const deleted = Object.keys(from).filter(p => !(p in to)).sort();
  const modified = Object.keys(to).filter(p => p in from && from[p] !== to[p]).sort();
  return {added, modified, deleted, clean: !added.length && !modified.length && !deleted.length};
}
function init() {
  if (m.branches.length) return;
  m.branches.push({id: m.nextBranch++, name: 'main', head: null, worktree: null});
  m.active = m.branches[0].id;
  commit('Ausgangsstand', 'auto', m.files);
}
function state() {
  const active = m.branches.find(b => b.id === m.active);
  const log = [];
  for (let id = active.head; id; id = byId(id).parent_id) {
    const c = byId(id);
    log.push({id: c.id, parent_id: c.parent_id, message: c.message, author: c.author, files_count: Object.keys(c.files).length,
      bytes: 1, branch_id: c.branch_id, created_at: c.created_at});
  }
  const branches = m.branches.slice().sort((a, b) => a.name.localeCompare(b.name)).map(b => ({
    id: b.id, name: b.name, head_commit_id: b.head, active: b.id === m.active, updated_at: '2026-11-17 09:00:00',
    unsaved: b.id === m.active ? !changes(headFiles(b), m.files).clean : !!b.worktree && !changes(headFiles(b), b.worktree).clean,
  }));
  return {active: branches.find(b => b.active), branches, status: changes(headFiles(active), m.files), log,
    limits: {branches: 10, commits: 200, used_commits: m.commits.length}};
}
function park(b) { b.worktree = changes(headFiles(b), m.files).clean ? null : Object.assign({}, m.files); }
function take(b) { m.files = Object.assign({}, b.worktree || headFiles(b)); b.worktree = null; m.active = b.id; }

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
    if (req.method() === 'POST') {
      const body = JSON.parse(req.postData() || '{}');
      m.posts.push(body);
      init();
      const active = m.branches.find(b => b.id === m.active);
      if (body.action === 'vcs_commit') {
        if (!String(body.message || '').trim()) return fail(r, 400, 'invalid_message');
        if (changes(headFiles(active), m.files).clean) return fail(r, 409, 'nothing_to_commit');
        const c = commit(body.message.trim(), 'person', m.files);
        return json(r, 201, {ok: true, commit: {id: c.id}, state: state()});
      }
      if (body.action === 'vcs_branch') {
        if (!/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/.test(body.name || '')) return fail(r, 400, 'invalid_branch_name');
        if (m.branches.some(b => b.name === body.name)) return fail(r, 409, 'branch_exists');
        const b = {id: m.nextBranch++, name: body.name, head: active.head, worktree: null};
        m.branches.push(b);
        if (body.carry) { active.worktree = null; m.active = b.id; } else { park(active); take(b); }
        return json(r, 201, {ok: true, state: state()});
      }
      if (body.action === 'vcs_switch') {
        const b = m.branches.find(x => x.id === body.branch_id);
        if (!b) return fail(r, 404, 'branch_not_found');
        park(active); take(b);
        return json(r, 200, {ok: true, state: state()});
      }
      if (body.action === 'vcs_restore') {
        const c = byId(body.commit_id);
        if (!c) return fail(r, 404, 'commit_not_found');
        if (changes(m.files, c.files).clean) return fail(r, 409, 'already_current');
        if (!changes(headFiles(active), m.files).clean) commit('Automatisch gesichert vor dem Wiederherstellen', 'auto', m.files);
        m.files = Object.assign({}, c.files);
        commit('Wiederhergestellt: ' + c.message, 'person', m.files);
        return json(r, 200, {ok: true, state: state()});
      }
      if (body.action === 'vcs_branch_delete') {
        if (body.branch_id === m.active) return fail(r, 409, 'branch_active');
        m.branches = m.branches.filter(b => b.id !== body.branch_id);
        return json(r, 200, {ok: true, state: state()});
      }
      return fail(r, 400, 'unknown_action');
    }
    if (u.searchParams.has('vcs')) {
      if (!m.ready) return json(r, 200, {ok: true, ready: false, csrf_token: 'csrf'});
      init();
      return json(r, 200, Object.assign({ok: true, ready: true, csrf_token: 'csrf'}, state()));
    }
    if (u.searchParams.has('vcs_commit')) {
      const c = byId(Number(u.searchParams.get('vcs_commit')));
      if (!c) return fail(r, 404, 'commit_not_found');
      return json(r, 200, {ok: true, commit: {id: c.id, parent_id: c.parent_id, message: c.message, author: c.author,
        files_count: Object.keys(c.files).length, created_at: c.created_at}, changes: changes(c.parent_id ? byId(c.parent_id).files : {}, c.files)});
    }
    if (u.searchParams.has('vcs_file')) {
      const which = u.searchParams.get('vcs_file');
      const p = u.searchParams.get('path');
      const files = which === 'work' ? m.files : byId(Number(which)).files;
      return json(r, 200, {ok: true, file: {path: p, exists: p in files, content: files[p] || ''}});
    }
    m.lists++;
    const list = Object.keys(m.files).sort().map(p => ({path: p, size_bytes: Buffer.byteLength(m.files[p]), sha256: sha(m.files[p]),
      revision: 1, updated_at: '2026-11-17 09:00:00'}));
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
const reopen = async pg => {
  await pg.click('#projectVcsClose');
  await pg.click('#projectFilesVcs');
  await pg.waitForSelector('.project-vcs-log .project-vcs-commit');
};
const logMessages = pg => pg.$$eval('.project-vcs-commit-text > span', els => els.map(e => e.textContent));

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
    assert.equal(await pg.isHidden('.project-vcs'), true, 'no versions panel before it is asked for');

    // The first look: main with its first version, everything saved.
    await pg.click('#projectFilesVcs');
    await pg.waitForSelector('.project-vcs-log .project-vcs-commit');
    assert.deepEqual(await logMessages(pg), ['Ausgangsstand']);
    assert.equal(await text(pg, '.project-vcs-status > strong'), 'Alles gesichert');
    assert.equal(await pg.$eval('#projectVcsCommit', b => b.disabled), true, 'nothing to save');
    assert.match(await text(pg, '.project-vcs-branch.is-active'), /main.*aktiv/);
    assert.equal(await pg.$eval('#projectVcsZip', a => a.getAttribute('href')), './api/project_files.php?project_id=' + PROJECT + '&vcs_zip=1');

    // An unsaved change with its line comparison, then saved.
    m.files['style.css'] = 'h1 { color: blue; }\n';
    await reopen(pg);
    assert.equal(await text(pg, '.project-vcs-status > strong'), 'Ungesicherte Änderungen (1)');
    await pg.click('.project-vcs-status .project-vcs-change[data-path="style.css"]');
    await pg.waitForSelector('.project-vcs-view .project-diff');
    const rows = await pg.$$eval('.project-vcs-view .project-diff-row', els => els.map(e => e.className.split(' ').pop() + ':' + e.querySelector('code').textContent));
    assert.deepEqual(rows, ['is-del:h1 { color: red; }', 'is-add:h1 { color: blue; }'], 'the line comparison: ' + JSON.stringify(rows));
    await pg.fill('#projectVcsMessage', 'Blaue Ueberschrift');
    await pg.press('#projectVcsMessage', 'Enter');
    await pg.waitForFunction(() => document.querySelector('.project-vcs-status > strong').textContent === 'Alles gesichert');
    assert.deepEqual(await logMessages(pg), ['Blaue Ueberschrift', 'Ausgangsstand']);
    assert.equal(await pg.$eval('#projectVcsMessage', i => i.value), '', 'the message field is empty after saving');
    assert.deepEqual(m.posts.filter(p => p.action === 'vcs_commit').pop(), {action: 'vcs_commit', message: 'Blaue Ueberschrift', project_id: PROJECT});

    // The acceptance: a change on its own working state; main stays as it was.
    m.files['index.html'] = '<h1>Neuer Titel</h1>\n';
    await reopen(pg);
    await pg.click('#projectVcsNewBranch');
    assert.equal(await pg.$eval('#projectVcsCarry', c => c.checked), true, 'carrying unsaved changes is offered when there are some');
    await pg.fill('#projectVcsBranchName', 'a b');
    await pg.click('#projectVcsCreate');
    await pg.waitForFunction(() => [...document.querySelectorAll('.project-vcs .project-files-status.is-error')].some(p => /Der Name darf/.test(p.textContent)));
    await pg.fill('#projectVcsBranchName', 'neuer-titel');
    await pg.click('#projectVcsCreate');
    await pg.waitForFunction(() => /neuer-titel/.test(document.querySelector('.project-vcs-branch.is-active').textContent));
    assert.equal(await text(pg, '.project-vcs-status > strong'), 'Ungesicherte Änderungen (1)', 'the change came along');
    await pg.fill('#projectVcsMessage', 'Neuer Titel');
    await pg.click('#projectVcsCommit');
    await pg.waitForFunction(() => document.querySelector('.project-vcs-status > strong').textContent === 'Alles gesichert');
    const listsBefore = m.lists;
    await pg.click('[data-switch="main"]');
    await pg.waitForFunction(() => /main/.test(document.querySelector('.project-vcs-branch.is-active').textContent));
    assert.equal(m.files['index.html'], '<h1>Alter Titel</h1>\n', 'main still has the old title');
    assert.deepEqual(await logMessages(pg), ['Blaue Ueberschrift', 'Ausgangsstand'], 'main keeps its own versions');
    assert.ok(m.lists > listsBefore, 'the file list is loaded again after the switch');

    // Restore under control: unsaved work is saved first, the old state comes back as a new version.
    m.files['notiz.txt'] = 'Nur auf main\n';
    await reopen(pg);
    const first = m.commits.find(c => c.message === 'Ausgangsstand').id;
    await pg.click('[data-restore="' + first + '"]');
    await pg.waitForFunction(() => document.querySelector('.project-vcs-commit-text > span').textContent === 'Wiederhergestellt: Ausgangsstand');
    assert.deepEqual(await logMessages(pg), ['Wiederhergestellt: Ausgangsstand', 'Automatisch gesichert vor dem Wiederherstellen', 'Blaue Ueberschrift', 'Ausgangsstand']);
    assert.ok(!('notiz.txt' in m.files) && m.files['style.css'] === 'h1 { color: red; }\n', 'the old state is back');
    assert.match(await text(pg, '.project-vcs-commit:nth-of-type(2) small'), /^automatisch/, 'the automatic save is marked');

    // What a version changed, down to one file.
    const auto = m.commits.find(c => c.message.startsWith('Automatisch')).id;
    await pg.click('[data-show="' + auto + '"]');
    await pg.waitForSelector('.project-vcs-view .project-vcs-change[data-path="notiz.txt"]');
    assert.match(await text(pg, '.project-vcs-view .project-vcs-change[data-path="notiz.txt"] small'), /neu/);
    await pg.click('.project-vcs-view .project-vcs-change[data-path="notiz.txt"]');
    await pg.waitForSelector('.project-vcs-view .project-diff-row.is-add');
    await pg.click('.project-vcs-view .project-file-compare-head button');
    await pg.waitForSelector('.project-vcs-view .project-vcs-change[data-path="notiz.txt"]');

    // Deleting the other working state.
    await pg.click('[data-delete="neuer-titel"]');
    await pg.waitForFunction(() => !document.querySelector('[data-switch="neuer-titel"]'));
    assert.ok(m.posts.some(p => p.action === 'vcs_branch_delete'), 'delete sent');

    // Phone and dark look.
    await pg.setViewportSize({width: 360, height: 780});
    const phone = await pg.evaluate(() => ({scroll: document.documentElement.scrollWidth, view: window.innerWidth}));
    assert.ok(phone.scroll <= phone.view, 'no sideways scrolling on a phone: ' + JSON.stringify(phone));
    await pg.evaluate(() => document.documentElement.setAttribute('data-coreui-theme', 'dark'));
    const dark = await pg.$eval('.project-vcs-commit', el => ({bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color}));
    assert.notEqual(dark.bg, dark.color, 'readable in the dark look');
    if (process.env.COREAI_SHOT) await pg.screenshot({path: process.env.COREAI_SHOT, fullPage: true});

    // Without migration 049.
    m.ready = false;
    await pg.click('#projectVcsClose');
    await pg.click('#projectFilesVcs');
    await pg.waitForFunction(() => [...document.querySelectorAll('.project-vcs .project-files-status.is-error')].some(p => /Migration 049/.test(p.textContent)));
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
  console.log('CoreAI Projektversionen UI: erste Version, ungesicherte Aenderung mit Zeilenvergleich, Sichern, eigener Arbeitsstand mit Uebernahme, main unveraendert, Wiederherstellen mit automatischer Sicherung, Aenderungen einer Version, Git-ZIP, abgelehnter Name, Loeschen, fehlende Migration, Telefon, dunkel OK');
})().catch(error => { console.error('CoreAI project versions UI FAIL: ' + (error && error.stack || error)); process.exit(1); });
