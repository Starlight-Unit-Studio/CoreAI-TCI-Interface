/* Offline checks for the install window (0.5.76): hardware check with a
   verdict, admins only, confirmation when advised against, progress while
   the server installs, "Jetzt nutzen" at the end. Cores verwalten: tiers
   with second names, weekly switch, update now with results, Ollama Cloud
   connect link and adding a cloud Core. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag, id) {
    this.tagName = String(tag).toUpperCase();
    this.id = id || '';
    this.attributes = {};
    this.handlers = {};
    this.children = [];
    this.parent = null;
    this.hidden = false;
    this.disabled = false;
    this.style = {};
    this._text = '';
    this.focused = false;
  }
  set textContent(text) { this._text = String(text); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(' '); }
  get firstChild() { return this.children[0] || null; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  removeChild(child) { this.children = this.children.filter(c => c !== child); child.parent = null; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  focus() { this.focused = true; }
}

function page() {
  const ids = ['coreInstall', 'Kicker', 'Title', 'Lead', 'Tech', 'Tiers', 'TierList', 'Check', 'Rows', 'Verdict', 'VerdictTitle',
    'VerdictText', 'Progress', 'BarWrap', 'Bar', 'Step', 'Maint', 'Auto', 'UpdateInfo', 'UpdateNow', 'ToManage', 'Results',
    'Cloud', 'CloudState', 'CloudSteps', 'CloudLink', 'CloudCheck', 'Note', 'Go', 'Cancel', 'Close']
    .map(k => k === 'coreInstall' ? k : 'coreInstall' + k);
  const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
  ['coreInstall', 'coreInstallProgress', 'coreInstallTiers', 'coreInstallMaint', 'coreInstallResults', 'coreInstallCloud',
    'coreInstallCloudSteps', 'coreInstallCloudLink', 'coreInstallToManage'].forEach(id => { els[id].hidden = true; });
  els.coreInstallAuto.checked = false;
  const doc = {
    handlers: {},
    getElementById: id => els[id] || null,
    createElement: tag => new Element(tag),
    addEventListener(type, fn) { this.handlers[type] = fn; }
  };
  return {els, doc};
}

const ROWS = [
  {key: 'ollama', label: 'Ollama', value: 'Version 0.40.6 erreichbar', state: 'ok'},
  {key: 'ram', label: 'Arbeitsspeicher', value: '94 GB gesamt, 61 GB frei · braucht ca. 11 GB', state: 'ok'},
  {key: 'cpu', label: 'Prozessor', value: '20 Threads · AVX-512', state: 'ok'},
  {key: 'disk', label: 'Speicherplatz', value: '800 GB frei · Download ca. 8 GB', state: 'ok'},
  {key: 'speed', label: 'Tempo', value: 'mittel (Richtwert für den Prozessor)', state: 'ok'},
];
const check = extra => Object.assign({tier: 'plus', label: 'TCI: Mara Plus', base: 'gemma4:12b', core: 'ember-coreui:12b',
  download_gb: 8, installed: false, verdict: 'gut', blocked: false, rows: ROWS, cloud_base: null}, extra || {});
const TIERS = [
  {tier: 'schnell', label: 'TCI: Mara Schnell', base: 'gemma4:e2b', download_gb: 7.5, local: ['gemma4:e2b'], removable: ['gemma4:e2b'], cloud: [],
    alias_of: 'gemma4:e4b', cloud_base: null, cloud_missing: false, status: 'installed'},
  {tier: 'normal', label: 'TCI: Mara Normal', base: 'gemma4:e4b', download_gb: 9.5, local: ['ember-coreui:latest', 'gemma4:e4b'], removable: ['gemma4:e4b'],
    cloud: [], alias_of: null, cloud_base: null, cloud_missing: false, status: 'installed'},
  {tier: 'plus', label: 'TCI: Mara Plus', base: 'gemma4:12b', download_gb: 8, local: [], cloud: [], alias_of: null,
    cloud_base: null, cloud_missing: false, status: 'missing'},
  {tier: 'pro', label: 'TCI: Mara Pro', base: 'gemma4:26b', download_gb: 19, local: ['gemma4:26b'], removable: ['gemma4:26b'], cloud: [],
    alias_of: 'gemma4:e4b', cloud_base: null, cloud_missing: false, status: 'installed'},
  {tier: 'premium', label: 'TCI: Mara Premium', base: 'gemma4:31b', download_gb: 20, local: [], cloud: ['gemma4:31b-cloud'],
    alias_of: null, cloud_base: 'gemma4:31b-cloud', cloud_missing: false, status: 'cloud'},
];
const PLAN = [{base: 'gemma4:e2b', label: 'TCI: Mara Schnell'}, {base: 'gemma4:e4b', label: 'TCI: Mara Normal'},
  {base: 'gemma4:26b', label: 'TCI: Mara Pro'}];
const SIGNIN = 'https://ollama.com/connect?name=coreai&key=c3NoLWVkMjU1MTk';

function server(opts) {
  opts = opts || {};
  const calls = [];
  const st = {install: opts.install || null, statuses: (opts.statuses || []).slice(), auto: opts.auto || {enabled: false, last: null, next: null},
    cloud: opts.cloud || {version: '0.40.6', version_ok: true, min_version: '0.12.0', account: {state: 'signed_out', signin_url: SIGNIN}},
    tiers: opts.tiers || TIERS};
  const reply = (status, body) => Promise.resolve({ok: status < 400, status, json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    calls.push({url, init});
    if (opts.offline) return Promise.reject(new Error('offline'));
    if (url.startsWith('./api/user_settings.php?core_install=')) {
      const c = opts.admin === false ? check({verdict: null, rows: [], ram_need: null}) : check(opts.check);
      return reply(200, {ok: true, check: c, can_install: opts.admin !== false, install: st.install, csrf_token: 'tok9',
        auto: opts.admin === false ? null : st.auto});
    }
    if (url === './api/user_settings.php?core_manage=1') {
      return reply(200, {ok: true, tiers: st.tiers, plan: PLAN, auto: st.auto, install: st.install, csrf_token: 'tok7'});
    }
    if (url === './api/user_settings.php?core_cloud=1') {
      if (opts.cloudAfter && calls.filter(c => c.url === url).length > 1) st.cloud = opts.cloudAfter;
      return reply(200, {ok: true, cloud: st.cloud});
    }
    if (url === './api/user_settings.php?core_install_status=1') {
      if (st.statuses.length) st.install = st.statuses.shift();
      return reply(200, {ok: true, install: st.install});
    }
    if (init.method === 'POST') {
      if (opts.refuse) return reply(409, {ok: false, error: opts.refuse});
      const body = JSON.parse(init.body);
      if (body.action === 'core_auto_update') {
        if (opts.failAuto) return reply(503, {ok: false, error: 'save_failed'});
        st.auto = body.enabled ? {enabled: true, last: null, next: 1762300000} : {enabled: false, last: null, next: null};
        return reply(200, {ok: true, auto: st.auto});
      }
      if (body.action === 'core_update') {
        st.install = {kind: 'update', tier: 'schnell', label: 'TCI: Mara Schnell', phase: 'pull', step: 'Startet …', item: 1, items: 3,
          percent: 0, completed: 0, total: 0, running: true};
        return reply(200, {ok: true, install: st.install});
      }
      if (body.action === 'core_uninstall') {
        return reply(200, {ok: true, removed: body.tier === 'pro' ? ['gemma4:26b'] : [], failed: []});
      }
      if (body.action === 'core_cloud_add') {
        st.install = {kind: 'install', cloud: true, tier: 'premium', label: 'TCI: Mara Premium', core: 'gemma4:31b-cloud',
          phase: 'pull', step: 'Startet …', percent: 0, running: true};
        return reply(200, {ok: true, install: st.install});
      }
      st.install = {tier: 'plus', label: 'TCI: Mara Plus', core: 'ember-coreui:12b', phase: 'pull', step: 'Startet …',
        percent: 0, completed: 0, total: 0, running: true};
      return reply(200, {ok: true, install: st.install});
    }
    return reply(404, {ok: false});
  };
  return {fetch, calls, st};
}

const source = fs.readFileSync(path.join(__dirname, '../js/coreui-core-install.js'), 'utf8');
assert(!/innerHTML|insertAdjacentHTML|outerHTML/.test(source), 'the window never injects HTML');
assert(!/Modell(?!file)/.test(source.replace(/\/\*[\s\S]*?\*\//g, '')), 'lore: visible text says Core');
const sandbox = {window: {}, Promise, JSON, Array, Math, Number, String, isFinite, encodeURIComponent, Date};
vm.runInNewContext(source, sandbox);
const api = sandbox.window.CoreUICoreInstall;
assert.equal(api.gb(7.5 * 1073741824), '7,5 GB');
assert.equal(api.gb(20 * 1073741824), '20 GB');

(async () => {
  // Admin, good verdict: check, install, progress, done, use it.
  let {els, doc} = page();
  let srv = server({statuses: [
    {tier: 'plus', label: 'TCI: Mara Plus', core: 'ember-coreui:12b', phase: 'pull', step: 'Lädt herunter …',
      percent: 50, completed: 4 * 1073741824, total: 8 * 1073741824, running: true},
    {tier: 'plus', label: 'TCI: Mara Plus', core: 'ember-coreui:12b', phase: 'done', step: 'Fertig: TCI: Mara Plus ist bereit.',
      percent: 100, running: false},
  ]});
  const timers = [];
  const installed = [];
  const chosen = [];
  let closed = 0;
  let win = api.create(doc, srv.fetch, fn => timers.push(fn), {
    installed: core => installed.push(core), choose: core => chosen.push(core), closed: () => closed++,
  });
  await win.open('plus');
  assert.equal(els.coreInstall.hidden, false);
  assert.equal(srv.calls[0].url, './api/user_settings.php?core_install=plus');
  assert.equal(els.coreInstallTitle.textContent, 'TCI: Mara Plus');
  assert.match(els.coreInstallLead.textContent, /noch nicht installiert.*ca\. 8 GB.*Maras Core mit ihrem Modelfile/);
  assert.match(els.coreInstallTech.textContent, /gemma4:12b, angelegt als ember-coreui:12b/);
  const rows = els.coreInstallRows.children;
  assert.equal(rows.length, 10, 'five rows: label and value');
  assert.deepEqual([rows[0].textContent, rows[1].textContent], ['Ollama', 'Version 0.40.6 erreichbar']);
  assert.equal(rows[1].getAttribute('data-state'), 'ok');
  assert.equal(els.coreInstallCheck.hidden, false);
  assert.equal(els.coreInstallVerdict.hidden, false);
  assert.equal(els.coreInstallVerdict.getAttribute('data-verdict'), 'gut');
  assert.equal(els.coreInstallVerdictTitle.textContent, 'Empfehlung: installieren');
  assert.equal(els.coreInstallGo.hidden, false);
  assert.equal(els.coreInstallGo.textContent, 'Installieren');
  assert.equal(els.coreInstallGo.disabled, false);
  assert.equal(els.coreInstallCancel.textContent, 'Abbrechen');
  assert.equal(els.coreInstallClose.focused, true, 'focus moves into the window');

  await els.coreInstallGo.handlers.click();
  const post = srv.calls.find(c => c.init.method === 'POST');
  assert.deepEqual(JSON.parse(post.init.body), {action: 'core_install', tier: 'plus'});
  assert.equal(post.init.headers['X-CoreUI-CSRF'], 'tok9', 'CSRF from the check');
  assert.equal(els.coreInstallProgress.hidden, false);
  assert.equal(els.coreInstallGo.hidden, true, 'no second start while it runs');
  assert.equal(els.coreInstallCancel.textContent, 'Schließen');
  assert.match(els.coreInstallNote.textContent, /läuft auf dem Server weiter/);
  assert.equal(timers.length, 1, 'progress is polled');
  await timers.shift()();
  assert.equal(els.coreInstallBar.style.width, '50%');
  assert.equal(els.coreInstallBarWrap.getAttribute('aria-valuenow'), '50');
  assert.equal(els.coreInstallStep.textContent, 'Lädt herunter … 4,0 GB von 8,0 GB (50 %)');
  await timers.shift()();
  assert.deepEqual(installed, ['ember-coreui:12b'], 'the Core list is refreshed once');
  assert.equal(timers.length, 0, 'polling stops when done');
  assert.match(els.coreInstallStep.textContent, /Fertig: TCI: Mara Plus ist bereit/);
  assert.match(els.coreInstallLead.textContent, /steht jetzt im Core-Menü/);
  assert.equal(els.coreInstallGo.textContent, 'Jetzt nutzen');
  assert.equal(els.coreInstallVerdict.hidden, true);
  await els.coreInstallGo.handlers.click();
  assert.deepEqual(chosen, ['ember-coreui:12b'], 'the new Core is chosen');
  assert.equal(els.coreInstall.hidden, true);
  assert.equal(closed, 1);

  // Advised against: the first click only asks, the second sends confirm.
  ({els, doc} = page());
  srv = server({check: {verdict: 'abraten', rows: [Object.assign({}, ROWS[1], {state: 'bad'})]}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  assert.equal(els.coreInstallVerdictTitle.textContent, 'Lieber nicht installieren');
  assert.equal(els.coreInstallGo.textContent, 'Trotzdem installieren');
  assert.equal(els.coreInstallGo.getAttribute('data-tone'), 'warn');
  await els.coreInstallGo.handlers.click();
  assert.equal(srv.calls.filter(c => c.init.method === 'POST').length, 0, 'no start without confirmation');
  assert.equal(els.coreInstallGo.textContent, 'Wirklich installieren?');
  await els.coreInstallGo.handlers.click();
  assert.deepEqual(JSON.parse(srv.calls.find(c => c.init.method === 'POST').init.body), {action: 'core_install', tier: 'plus', confirm: true});

  // Not an admin: what is missing and who can help, no server details, no button.
  ({els, doc} = page());
  srv = server({admin: false});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  assert.equal(els.coreInstallGo.hidden, true);
  assert.match(els.coreInstallNote.textContent, /nur Admins/);
  assert.match(els.coreInstallLead.textContent, /noch nicht installiert/);
  assert.equal(els.coreInstallCheck.hidden, true, 'the hardware check is for admins only');
  assert.equal(els.coreInstallVerdict.hidden, true);

  // Ollama unreachable: the button stays locked.
  ({els, doc} = page());
  srv = server({check: {verdict: 'abraten', blocked: true}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  assert.equal(els.coreInstallGo.disabled, true);
  await els.coreInstallGo.handlers.click();
  await els.coreInstallGo.handlers.click();
  assert.equal(srv.calls.filter(c => c.init.method === 'POST').length, 0, 'nothing starts while blocked');

  // Another tier is installing: wait for it, follow it, no start.
  ({els, doc} = page());
  const later = [];
  srv = server({install: {tier: 'pro', label: 'TCI: Mara Pro', phase: 'pull', percent: 10, running: true}});
  win = api.create(doc, srv.fetch, fn => later.push(fn), {});
  await win.open('plus');
  assert.match(els.coreInstallNote.textContent, /Gerade wird TCI: Mara Pro installiert/);
  assert.equal(els.coreInstallGo.hidden, true);
  assert.equal(els.coreInstallProgress.hidden, true, 'no progress bar for a foreign tier');
  assert.equal(later.length, 1, 'the window follows until the other install ends');
  doc.handlers.keydown({key: 'Escape'});
  assert.equal(els.coreInstall.hidden, true, 'Escape closes');
  await later.shift()();
  assert.equal(later.length, 0, 'a closed window stops polling');

  // A failed job of this tier: its message and a retry.
  ({els, doc} = page());
  srv = server({install: {tier: 'plus', phase: 'error', error: 'pull_failed', running: false,
    message: 'Ollama kennt gemma4:12b nicht. Entweder gibt es diese Stufe noch nicht oder Ollama ist zu alt für Gemma 4.'}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  assert.match(els.coreInstallNote.textContent, /Ollama kennt gemma4:12b nicht/);
  assert.equal(els.coreInstallNote.getAttribute('data-tone'), 'bad');
  assert.equal(els.coreInstallGo.textContent, 'Erneut versuchen');

  // The server refuses: the reason is shown, nothing is assumed.
  ({els, doc} = page());
  srv = server({refuse: 'install_running'});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  await els.coreInstallGo.handlers.click();
  assert.match(els.coreInstallNote.textContent, /läuft schon eine Installation/);

  // Check unreachable, backdrop click closes.
  ({els, doc} = page());
  win = api.create(doc, server({offline: true}).fetch, () => {}, {});
  await win.open('plus');
  assert.match(els.coreInstallLead.textContent, /nicht erreichbar/);
  assert.equal(els.coreInstallGo.hidden, true);
  els.coreInstall.handlers.click({target: els.coreInstallTitle});
  assert.equal(els.coreInstall.hidden, false, 'a click inside keeps it open');
  els.coreInstall.handlers.click({target: els.coreInstall});
  assert.equal(els.coreInstall.hidden, true, 'a click on the backdrop closes');

  // Cores verwalten: tiers, second names, weekly switch, update now with results.
  ({els, doc} = page());
  const ticks = [];
  const refreshed = [];
  srv = server({statuses: [
    {kind: 'update', tier: 'normal', label: 'TCI: Mara Normal', phase: 'pull', step: 'Lädt herunter …', item: 2, items: 3,
      percent: 10, completed: 1073741824, total: 9.5 * 1073741824, running: true},
    {kind: 'update', tier: '', label: 'Update', phase: 'done', step: 'Update fertig.', percent: 100, running: false,
      message: 'Eine Stufe hatte eine neue Fassung und wurde neu gebaut.',
      results: [{label: 'TCI: Mara Schnell', result: 'current'}, {label: 'TCI: Mara Normal', result: 'updated'},
        {label: 'TCI: Mara Pro', result: 'error', message: 'Ollama meldet: kaputt'}]},
  ]});
  win = api.create(doc, srv.fetch, fn => ticks.push(fn), {installed: core => refreshed.push(core || '')});
  await win.manage();
  assert.equal(els.coreInstall.hidden, false);
  assert.deepEqual(srv.calls.map(c => c.url), ['./api/user_settings.php?core_manage=1', './api/user_settings.php?core_cloud=1']);
  assert.equal(els.coreInstallKicker.textContent, 'CORES VERWALTEN');
  assert.equal(els.coreInstallTitle.textContent, 'Cores verwalten');
  assert.match(els.coreInstallLead.textContent, /unregelmäßigen Abständen/);
  assert.equal(els.coreInstallTiers.hidden, false);
  assert.equal(els.coreInstallCheck.hidden, true, 'no hardware check in the overview');
  assert.equal(els.coreInstallGo.hidden, true);
  const items = els.coreInstallTierList.children;
  assert.equal(items.length, 5);
  assert.match(items[0].textContent, /TCI: Mara Schnell installiert/);
  assert.match(items[0].textContent, /gemma4:e2b ist nur ein zweiter Name für gemma4:e4b\. Ein Update lädt das echte e2b \(ca\. 7,5 GB\)/);
  assert.match(items[2].textContent, /nicht installiert/);
  assert.match(items[4].textContent, /nur Cloud.*gemma4:31b-cloud.*Lokal installieren/);
  assert.equal(els.coreInstallMaint.hidden, false);
  assert.equal(els.coreInstallToManage.hidden, true, 'no link to itself');
  assert.equal(els.coreInstallAuto.checked, false);
  assert.equal(els.coreInstallUpdateInfo.textContent, 'Noch kein Update gelaufen. Ein Update prüft Mara Schnell, Mara Normal, Mara Pro.');
  assert.equal(els.coreInstallCancel.textContent, 'Schließen');

  els.coreInstallAuto.checked = true;
  await els.coreInstallAuto.handlers.change();
  assert.deepEqual(JSON.parse(srv.calls.filter(c => c.init.method === 'POST').pop().init.body), {action: 'core_auto_update', enabled: true});
  assert.equal(srv.calls.filter(c => c.init.method === 'POST').pop().init.headers['X-CoreUI-CSRF'], 'tok7');
  assert.equal(els.coreInstallAuto.checked, true);
  assert.match(els.coreInstallUpdateInfo.textContent, /Nächstes Auto-Update ab \d\d\.\d\d\.\d{4}, \d\d:\d\d\./);

  await els.coreInstallUpdateNow.handlers.click();
  assert.deepEqual(JSON.parse(srv.calls.filter(c => c.init.method === 'POST').pop().init.body), {action: 'core_update'});
  assert.equal(els.coreInstallProgress.hidden, false);
  assert.equal(els.coreInstallUpdateNow.disabled, true, 'one job at a time');
  assert.match(els.coreInstallNote.textContent, /das Update läuft auf dem Server weiter/);
  await ticks.shift()();
  assert.equal(els.coreInstallStep.textContent, 'TCI: Mara Normal (2 von 3): Lädt herunter … 1,0 GB von 9,5 GB (10 %)');
  await ticks.shift()();
  assert.equal(ticks.length, 0, 'polling stops when the update is done');
  assert.equal(refreshed.length, 1, 'the Core menu is refreshed');
  assert.equal(srv.calls.filter(c => c.url === './api/user_settings.php?core_manage=1').length, 2, 'the overview is read again');
  assert.equal(els.coreInstallNote.textContent, 'Eine Stufe hatte eine neue Fassung und wurde neu gebaut.');
  assert.equal(els.coreInstallResults.hidden, false);
  assert.deepEqual(els.coreInstallResults.children.map(li => [li.textContent, li.getAttribute('data-state')]), [
    ['TCI: Mara Schnell: aktuell', 'ok'], ['TCI: Mara Normal: neue Fassung, neu gebaut', 'ok'],
    ['TCI: Mara Pro: Ollama meldet: kaputt', 'bad']]);

  // 0.5.77: each installed tier can be updated alone and removed with two taps;
  // Mara's own Core is never offered for removal.
  ({els, doc} = page());
  const menuRefresh = [];
  srv = server({tiers: TIERS.map(t => t.tier === 'normal' ? Object.assign({}, t, {local: ['ember-coreui:latest'], removable: []}) : t)});
  win = api.create(doc, srv.fetch, () => {}, {installed: () => menuRefresh.push(1)});
  await win.manage();
  const actionsOf = i => {
    const box = els.coreInstallTierList.children[i].children.find(c => c.className === 'core-install-tier-actions');
    return box ? box.children : [];
  };
  assert.deepEqual(actionsOf(0).map(b => b.textContent), ['Aktualisieren', 'Deinstallieren']);
  assert.deepEqual(actionsOf(1).map(b => b.textContent), ['Aktualisieren'], 'no removal for Mara\'s own Core');
  assert.match(els.coreInstallTierList.children[1].textContent, /Maras Haupt-Core bleibt immer installiert/);
  assert.deepEqual(actionsOf(4).map(b => b.textContent), ['Cloud entfernen', 'Lokal installieren']);
  await actionsOf(0)[0].handlers.click();
  assert.deepEqual(JSON.parse(srv.calls.filter(c => c.init.method === 'POST').pop().init.body), {action: 'core_update', tier: 'schnell'});
  ({els, doc} = page());
  srv = server({});
  win = api.create(doc, srv.fetch, () => {}, {installed: () => menuRefresh.push(1)});
  await win.manage();
  await actionsOf(3)[1].handlers.click();
  assert.equal(srv.calls.filter(c => c.init.method === 'POST').length, 0, 'the first tap only asks');
  assert.equal(actionsOf(3)[1].textContent, 'Wirklich entfernen?');
  assert.match(els.coreInstallTierList.children[3].textContent, /Entfernt gemma4:26b\. Wer diesen Core gewählt hat, chattet danach mit dem Standard-Core\./);
  const manageReads = srv.calls.filter(c => c.url === './api/user_settings.php?core_manage=1').length;
  await actionsOf(3)[1].handlers.click();
  assert.deepEqual(JSON.parse(srv.calls.filter(c => c.init.method === 'POST').pop().init.body), {action: 'core_uninstall', tier: 'pro', cloud: false});
  assert.equal(els.coreInstallNote.textContent, 'gemma4:26b entfernt.');
  assert.equal(srv.calls.filter(c => c.url === './api/user_settings.php?core_manage=1').length, manageReads + 1, 'the overview is read again');
  assert(menuRefresh.length >= 1, 'the Core menu is refreshed');

  // A failed switch is put back.
  ({els, doc} = page());
  srv = server({failAuto: true});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.manage();
  els.coreInstallAuto.checked = true;
  await els.coreInstallAuto.handlers.change();
  assert.equal(els.coreInstallAuto.checked, false);
  assert.match(els.coreInstallNote.textContent, /nicht gespeichert/);

  // Ollama Cloud: not connected -> steps and Ollama's connect link; after
  // confirming there, "Verbindung prüfen" shows the account and a cloud Core can be added.
  ({els, doc} = page());
  const missingPremium = TIERS.map(t => t.tier === 'premium' ? Object.assign({}, t, {cloud: [], cloud_missing: true, status: 'missing'}) : t);
  const cloudTicks = [];
  srv = server({tiers: missingPremium, cloudAfter: {version: '0.40.6', version_ok: true, min_version: '0.12.0',
    account: {state: 'connected', name: 'mara-ops', plan: 'free'}},
    statuses: [{kind: 'install', cloud: true, tier: 'premium', label: 'TCI: Mara Premium', core: 'gemma4:31b-cloud', phase: 'done',
      step: 'Fertig: TCI: Mara Premium (Cloud) ist bereit.', percent: 100, running: false}]});
  win = api.create(doc, srv.fetch, fn => cloudTicks.push(fn), {});
  await win.manage();
  assert.equal(els.coreInstallCloud.hidden, false);
  assert.equal(els.coreInstallCloudState.textContent, 'Noch nicht mit einem Ollama-Konto verbunden.');
  assert.equal(els.coreInstallCloudState.getAttribute('data-state'), 'warn');
  assert.equal(els.coreInstallCloudSteps.hidden, false);
  assert.equal(els.coreInstallCloudLink.hidden, false);
  assert.equal(els.coreInstallCloudLink.getAttribute('href'), SIGNIN, 'Ollama\'s own connect link');
  let premiumItem = els.coreInstallTierList.children[4];
  let cloudBtn = premiumItem.children.find(c => c.className === 'core-install-tier-actions').children.find(b => b.textContent === 'Cloud hinzufügen');
  assert.equal(cloudBtn.disabled, true, 'adding a cloud Core waits for the account');
  await els.coreInstallCloudCheck.handlers.click();
  assert.equal(els.coreInstallCloudState.textContent, 'Verbunden als mara-ops (Plan: free).');
  assert.equal(els.coreInstallCloudLink.hidden, true);
  assert.equal(els.coreInstallCloudSteps.hidden, true);
  assert.equal(els.coreInstallCloudCheck.textContent, 'Erneut prüfen');
  premiumItem = els.coreInstallTierList.children[4];
  cloudBtn = premiumItem.children.find(c => c.className === 'core-install-tier-actions').children.find(b => b.textContent === 'Cloud hinzufügen');
  assert.equal(cloudBtn.disabled, false);
  await cloudBtn.handlers.click();
  assert.deepEqual(JSON.parse(srv.calls.filter(c => c.init.method === 'POST').pop().init.body), {action: 'core_cloud_add', tier: 'premium'});
  assert.equal(els.coreInstallProgress.hidden, false, 'the overview follows the cloud Core it added');
  await cloudTicks.shift()();
  assert.equal(els.coreInstallNote.textContent, 'Fertig: TCI: Mara Premium (Cloud) ist bereit.');

  // Old Ollama: a clear note instead of a link.
  ({els, doc} = page());
  srv = server({cloud: {version: '0.11.4', version_ok: false, min_version: '0.12.0', account: {state: 'unsupported'}}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.manage();
  assert.match(els.coreInstallCloudState.textContent, /Ollama 0\.11\.4 ist zu alt für Cloud-Cores \(ab 0\.12\.0\)/);
  assert.equal(els.coreInstallCloudLink.hidden, true);

  // Install view for admins: switch and update there too, a way to Cores
  // verwalten, and the cloud hint for a tier that also runs in the cloud.
  ({els, doc} = page());
  srv = server({check: {tier: 'premium', label: 'TCI: Mara Premium', base: 'gemma4:31b', cloud_base: 'gemma4:31b-cloud'}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('premium');
  assert.equal(els.coreInstallMaint.hidden, false);
  assert.equal(els.coreInstallToManage.hidden, false);
  assert.equal(els.coreInstallCloud.hidden, true, 'the cloud section lives in the overview');
  assert.match(els.coreInstallLead.textContent, /auch als Cloud-Core ohne Download \(Ollama-Konto nötig\): unter Cores verwalten/);
  await els.coreInstallToManage.handlers.click();
  assert.equal(win.state().mode, 'manage');
  assert.equal(els.coreInstallTitle.textContent, 'Cores verwalten');

  // A running update blocks installing; non-admins see no update controls.
  ({els, doc} = page());
  srv = server({install: {kind: 'update', tier: 'normal', label: 'TCI: Mara Normal', phase: 'pull', running: true, item: 2, items: 3}});
  win = api.create(doc, srv.fetch, () => {}, {});
  await win.open('plus');
  assert.match(els.coreInstallNote.textContent, /Gerade läuft ein Update der Cores/);
  assert.equal(els.coreInstallGo.hidden, true);
  assert.equal(els.coreInstallProgress.hidden, true);
  ({els, doc} = page());
  win = api.create(doc, server({admin: false}).fetch, () => {}, {});
  await win.open('plus');
  assert.equal(els.coreInstallMaint.hidden, true, 'updates are for admins');

  // Markup and wiring.
  const html = fs.readFileSync(path.join(__dirname, '../app.html'), 'utf8');
  for (const id of ['coreInstall', 'coreInstallKicker', 'coreInstallTiers', 'coreInstallTierList', 'coreInstallMaint', 'coreInstallAuto',
    'coreInstallUpdateInfo', 'coreInstallUpdateNow', 'coreInstallToManage', 'coreInstallResults', 'coreInstallCloud',
    'coreInstallCloudState', 'coreInstallCloudSteps', 'coreInstallCloudLink', 'coreInstallCloudCheck', 'coreModeManage',
    'coreInstallTitle', 'coreInstallCheck', 'coreInstallRows', 'coreInstallVerdict', 'coreInstallBarWrap',
    'coreInstallBar', 'coreInstallStep', 'coreInstallNote', 'coreInstallGo', 'coreInstallCancel', 'coreInstallClose']) {
    assert(html.includes(`id="${id}"`), 'app.html has ' + id);
  }
  assert(/role="dialog" aria-modal="true" aria-labelledby="coreInstallTitle"/.test(html), 'the window is a modal dialog');
  assert(/role="progressbar"/.test(html), 'progress is a progress bar');
  assert(/id="coreInstallCloudLink" href="https:\/\/ollama\.com" target="_blank"\s+rel="noopener noreferrer"/.test(html), 'connect link opens safely');
  assert(/Ollama-Konto/.test(html), 'the account need is explained');
  assert(/<script src="\.\/js\/coreui-core-install\.js\?v=\d+"><\/script>/.test(html), 'module is loaded');
  const css = fs.readFileSync(path.join(__dirname, '../css/console.css'), 'utf8');
  assert(/\.core-mode-dot\[data-state="ready"\][^}]*var\(--green\)/.test(css), 'ready Cores carry a green dot');
  assert(/\.core-install\[hidden\]\s*\{\s*display: none;/.test(css), 'the hidden window takes no space');

  console.log('CoreAI install window: check, verdict, admins only, confirmation, progress, Cores verwalten, updates and Ollama Cloud OK');
})().catch(error => { console.error(error); process.exit(1); });
