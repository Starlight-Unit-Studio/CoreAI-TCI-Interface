/* Offline checks for the install window (0.5.76): hardware check with a
   verdict, admins only, confirmation when advised against, progress while
   the server installs, "Jetzt nutzen" at the end. */
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
  const ids = ['coreInstall', 'Title', 'Lead', 'Tech', 'Check', 'Rows', 'Verdict', 'VerdictTitle', 'VerdictText', 'Progress',
    'BarWrap', 'Bar', 'Step', 'Note', 'Go', 'Cancel', 'Close'].map(k => k === 'coreInstall' ? k : 'coreInstall' + k);
  const els = Object.fromEntries(ids.map(id => [id, new Element('div', id)]));
  els.coreInstall.hidden = true;
  els.coreInstallProgress.hidden = true;
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
  download_gb: 8, installed: false, verdict: 'gut', blocked: false, rows: ROWS}, extra || {});

function server(opts) {
  opts = opts || {};
  const calls = [];
  const st = {install: opts.install || null, statuses: (opts.statuses || []).slice()};
  const reply = (status, body) => Promise.resolve({ok: status < 400, status, json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    calls.push({url, init});
    if (opts.offline) return Promise.reject(new Error('offline'));
    if (url.startsWith('./api/user_settings.php?core_install=')) {
      const c = opts.admin === false ? check({verdict: null, rows: [], ram_need: null}) : check(opts.check);
      return reply(200, {ok: true, check: c, can_install: opts.admin !== false, install: st.install, csrf_token: 'tok9'});
    }
    if (url === './api/user_settings.php?core_install_status=1') {
      if (st.statuses.length) st.install = st.statuses.shift();
      return reply(200, {ok: true, install: st.install});
    }
    if (init.method === 'POST') {
      if (opts.refuse) return reply(409, {ok: false, error: opts.refuse});
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
const sandbox = {window: {}, Promise, JSON, Array, Math, Number, String, isFinite, encodeURIComponent};
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

  // Markup and wiring.
  const html = fs.readFileSync(path.join(__dirname, '../app.html'), 'utf8');
  for (const id of ['coreInstall', 'coreInstallTitle', 'coreInstallCheck', 'coreInstallRows', 'coreInstallVerdict', 'coreInstallBarWrap',
    'coreInstallBar', 'coreInstallStep', 'coreInstallNote', 'coreInstallGo', 'coreInstallCancel', 'coreInstallClose']) {
    assert(html.includes(`id="${id}"`), 'app.html has ' + id);
  }
  assert(/role="dialog" aria-modal="true" aria-labelledby="coreInstallTitle"/.test(html), 'the window is a modal dialog');
  assert(/role="progressbar"/.test(html), 'progress is a progress bar');
  assert(/<script src="\.\/js\/coreui-core-install\.js\?v=\d+"><\/script>/.test(html), 'module is loaded');
  const css = fs.readFileSync(path.join(__dirname, '../css/console.css'), 'utf8');
  assert(/\.core-mode-dot\[data-state="ready"\][^}]*var\(--green\)/.test(css), 'ready Cores carry a green dot');
  assert(/\.core-install\[hidden\]\s*\{\s*display: none;/.test(css), 'the hidden window takes no space');

  console.log('CoreAI install window: check, verdict, admins only, confirmation, progress and use OK');
})().catch(error => { console.error(error); process.exit(1); });
