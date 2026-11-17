// CoreAI 0.5.83: the update window in a real browser (Playwright).
//   node scripts/update-ui-selftest.cjs
// Serves the settings page with mocked APIs and checks: the Inhaber gets the
// matte golden window (a third of the screen, slightly transparent) at load,
// "Später" pauses it across reloads for an hour, the settings card reopens it,
// "Update starten" sends the Ollama choice and shows "Bitte auf Neustart
// warten" with the host's real step and percent (kept while CoreAI is down,
// never going back), the page reloads once CoreAI is back (not after a fixed wait), a
// failed update shows its log, a Nutzer never gets the window but does get the
// waiting window while CoreAI restarts, and a phone shows it without sideways
// scrolling. The clock is simulated, so the minutes pass in no time.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

const NOTES = '## 0.5.84 - 30.10.2026\n\n- **Neue Stimme:** Mara spricht.\n- Tests: `voice-selftest.php` neu.';

function info(over) {
  return Object.assign({installed: true, state: 'idle', phase: '', running: false, queued_seconds: 0, stalled: false, message: '',
    available: true, current_version: '0.5.83', current_commit: 'aaaaaaa', latest_version: '0.5.84', latest_commit: 'bbbbbbb',
    notes: NOTES, checked_at: '2026-10-29T12:00:00Z', check_error: '', checking: false, started_at: '', finished_at: '',
    from_version: '', to_version: '', restart_ollama: false}, over || {});
}

function server(level) {
  const calls = [];
  const srv = {calls, level, status: null, autoEnabled: false, snoozeUntil: 0};
  // What update.php answers for GET status; 'down' simulates the restart (502).
  srv.status = () => level === 0 ? {ok: true, running: false, state: 'idle', phase: '', version: '0.5.83', owner: true, update: info({auto_enabled: srv.autoEnabled, snooze_until: srv.snoozeUntil}), csrf_token: 'tok'}
    : {ok: true, running: false, state: 'idle', phase: '', version: '0.5.83'};
  const api = {
    'auth.php': () => ({ok: true, username: 'patrick@starlight.test', display_name: 'Patrick', permission_level: level, is_guest: false, csrf_token: 'tok', avatars: {}}),
    'profile.php': () => ({ok: true, csrf_token: 'tok', profile: {display_name: 'Patrick', assistant_name: 'Mara', avatars: {}}}),
    'user_settings.php': () => ({ok: true, csrf_token: 'tok', default_model: 'ember-coreui:latest', default_label: 'TCI: Mara Normal',
      core_label: 'TCI: Mara Normal', permission_level: level, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true,
        memory_limit: 16, num_predict: 6500, temperature: 1, provider: 'local', model_override: '', num_ctx: null},
      limits: {system_prompt_chars: 6000, num_ctx_choices: [4096, 8192], num_ctx_available: true}, external: {enabled: false, configured: false}}),
    'knowledge.php': () => ({ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}}),
    'memories.php': () => ({ok: true, items: []}),
    'health.php': () => ({ok: true, version: '0.5.83', model: 'ember-coreui:latest', checks: {database: true, schema: true, ollama: true, storage: true}}),
    'account_security.php': () => ({ok: true, sessions: []}),
    'admin.php': () => ({ok: true, csrf_token: 'tok', settings: {}, stats: {}, items: [], models: [], grants: {}}),
  };
  srv.route = async route => {
    const url = new URL(route.request().url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel.startsWith('api/')) {
      const key = rel.slice(4);
      const post = route.request().method() === 'POST' ? route.request().postData() : null;
      calls.push({key, action: url.searchParams.get('action'), post: post ? JSON.parse(post) : null,
        csrf: route.request().headers()['x-coreui-csrf'] || ''});
      if (key === 'update.php') {
        if (post) {
          if (level !== 0) return route.fulfill({status: 403, json: {ok: false, error: 'owner_only'}});
          const action = url.searchParams.get('action');
          if (action === 'later') {
            srv.snoozeUntil = Math.floor(Date.now() / 1000) + 3600;
            return route.fulfill({json: {ok: true, snooze_until: srv.snoozeUntil, snooze_seconds: 3600}});
          }
          if (action === 'auto') {
            srv.autoEnabled = JSON.parse(post).enabled === true;
            return route.fulfill({json: {ok: true, auto_enabled: srv.autoEnabled, snooze_until: srv.snoozeUntil}});
          }
          if (action === 'start') {
            srv.status = () => ({ok: true, running: true, state: 'queued', phase: 'queued', version: '0.5.83', owner: true,
              update: info({state: 'queued', phase: 'queued', running: true, available: false}), csrf_token: 'tok'});
            return route.fulfill({json: {ok: true, running: true, state: 'queued', phase: 'queued', version: '0.5.83', update: info({state: 'queued', running: true})}});
          }
          return route.fulfill({json: {ok: true}});
        }
        const answer = srv.status();
        if (answer === 'down') return route.fulfill({status: 502, contentType: 'text/html', body: '<h1>502 Bad Gateway</h1>'});
        return route.fulfill({json: answer});
      }
      return route.fulfill({json: api[key] ? api[key](url) : {ok: true}});
    }
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  };
  return srv;
}

const navigationState = new WeakMap();

async function open(browser, level, viewport, prepare) {
  const srv = server(level);
  if (prepare) prepare(srv);
  const page = await browser.newPage({viewport: viewport || {width: 1440, height: 900}, locale: 'de-DE'});
  const errors = [];
  const navigation = {count: 0, baseline: 0, urls: []};
  // Only full page loads count as reloads; URL fragment navigation inside
  // Settings must not masquerade as an update-driven page reload.
  page.on('load', () => { navigation.count += 1; navigation.urls.push(page.url()); });
  page.on('pageerror', error => errors.push(error.message));
  await page.clock.install();
  await page.route('http://coreui.test/**', route => srv.route(route));
  await page.goto('http://coreui.test/settings.html#system');
  navigation.baseline = navigation.count;
  navigationState.set(page, navigation);
  return {page, srv, errors};
}

const mode = page => page.evaluate(() => window.CoreAIUpdate && window.CoreAIUpdate.mode());
const reloaded = page => {
  const nav = navigationState.get(page);
  return !!nav && nav.count > nav.baseline;
};
async function advance(page, ms) {
  await page.clock.runFor(ms);
  await page.waitForTimeout(30);
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // The Inhaber: the golden window opens by itself.
    let {page, srv, errors} = await open(browser, 0);
    await page.waitForSelector('#coreaiUpdate .cu-window');
    const box = await page.locator('.cu-window').boundingBox();
    const share = box.width * box.height / (1440 * 900);
    assert.ok(share > 0.3 && share < 0.37, 'a third of the screen, got ' + share.toFixed(3));
    const look = await page.$eval('.cu-window', e => ({bg: getComputedStyle(e).backgroundImage, filter: getComputedStyle(e).backdropFilter}));
    assert.match(look.bg, /rgba\(233, 205, 134, 0\.9\)/, 'gold, slightly transparent');
    assert.match(look.filter, /blur/, 'frosted, so the page shimmers through');
    assert.equal(await page.textContent('.cu-versions'), '0.5.83→0.5.84');
    assert.equal(await page.textContent('#cuTitle'), 'New version available');
    assert.equal(await page.textContent('#updateCardTitle'), 'CoreAI Update');
    assert.equal(await page.textContent('.cu-later'), 'Later');
    assert.equal(await page.textContent('.cu-start'), 'Start update');
    assert.equal(await page.textContent('.cu-option span'), 'Restart Ollama afterward');
    assert.match(await page.textContent('.cu-hint'), /database is backed up first/i);
    assert.equal(await page.textContent('#updateCardCheck'), 'CHECK NOW');
    assert.deepEqual(await page.$$eval('.cu-notes li', l => l.map(x => x.textContent)), ['Neue Stimme: Mara spricht.', 'Tests: voice-selftest.php neu.']);
    assert.equal(await page.$eval('.cu-notes li strong', e => e.textContent), 'Neue Stimme:', 'bold stays bold, no HTML from the changelog');
    assert.equal(await page.isChecked('#cuOllama'), true, 'Ollama restart is preselected');
    assert.equal(await page.evaluate(() => document.activeElement.className), 'cu-start', 'focus on Update starten');
    // The modal covers settings, so the owner first chooses Later,
    // then enables optional auto-updates through the regular settings UI.
    assert.equal(await page.isChecked('#updateAutoEnable'), false, 'automatic updates are disabled by default');
    await page.click('.cu-later');
    await page.waitForSelector('#coreaiUpdate', {state:'detached'});
    // Click the visible custom switch; the checkbox is covered by its styled track.
    await page.locator('label[for="updateAutoEnable"] .settings-switch i').click();
    assert.equal(await page.isChecked('#updateAutoEnable'), true, 'custom switch toggles the real checkbox');
    await page.waitForFunction(() => !document.querySelector('#updateAutoEnable').disabled);
    assert(srv.calls.some(c=>c.key==='update.php' && c.action==='auto' && c.post.enabled===true),
      'the opt-in is an authenticated server action');
    // The settings card must still allow manual opening during the snooze.
    await page.click('#updateCardOpen');
    await page.waitForSelector('.cu-window');
    // 'Später' persists for 60 min, suppressing the popup AND automatic start.
    await page.click('.cu-later');
    await page.waitForSelector('#coreaiUpdate', {state:'detached'});
    assert(srv.calls.some(c=>c.key==='update.php' && c.action==='later'),
      'Later uses server-side scheduling, not just a tab flag');
    await page.reload();
    await advance(page, 61000);
    assert.equal(await page.locator('#coreaiUpdate').count(), 0, 'Später survives a page reload and repeated polling');
    assert.equal(await page.isChecked('#updateAutoEnable'), true, 'the opt-in survives the next page load');
    assert.equal(await page.isVisible('#updateCard'), true, 'the settings card remains available');
    assert.match(await page.textContent('#updateCardState'), /^New version 0\.5\.84 available\./);
    assert.equal(await page.textContent('#updateCardLatest'), '0.5.84');
    // The card reopens it; start without Ollama restart.
    await page.click('#updateCardOpen');
    await page.waitForSelector('.cu-window');
    await page.uncheck('#cuOllama');
    await page.click('.cu-start');
    await page.waitForSelector('.cu-wait');
    const start = srv.calls.find(c => c.key === 'update.php' && c.action === 'start');
    assert.deepEqual(start.post, {restart_ollama: false}, 'the Ollama choice is sent');
    assert.equal(start.csrf, 'tok', 'with the CSRF token');
    assert.equal(await page.textContent('#cuWaitTitle'), 'Waiting for restart');
    assert.equal(await page.textContent('.cu-phase'), 'Preparing the update.');
    assert.equal(await page.textContent('.cu-percent'), '0 %');
    // Host: backup with its real progress.
    const running = (phase, percent, step) => () => ({ok: true, running: true, state: 'running', phase, percent, step, version: '0.5.83'});
    srv.status = running('backup', 2, 'Backing up the database.');
    await advance(page, 3100);
    assert.equal(await page.textContent('.cu-phase'), 'Backing up the database.');
    assert.equal(await page.textContent('.cu-percent'), '2 %');
    assert.deepEqual(await page.$$eval('.cu-steps li.is-active', l => l.map(x => x.textContent)), ['Backup']);
    // The installer's step and percent come from the host, not from a timer.
    srv.status = running('install', 47, 'Building container images.');
    await advance(page, 3100);
    assert.equal(await page.textContent('.cu-phase'), 'Building container images.');
    assert.equal(await page.textContent('.cu-percent'), '47 %');
    assert.equal(await page.$eval('.cu-bar', b => b.getAttribute('aria-valuenow')), '47');
    assert.equal(await page.$eval('.cu-fill', f => f.style.width), '47%', 'the bar fills to the real value');
    assert.equal(await page.$eval('.cu-bar', b => b.classList.contains('is-moving')), true, 'the sheen runs while the host works');
    assert.match(await page.$eval('.cu-fill', f => getComputedStyle(f).backgroundImage), /rgb\(24, 42, 102\).*rgb\(91, 146, 255\)/,
      'navy into CoreAI blue on gold');
    assert.deepEqual(await page.$$eval('.cu-steps li.is-done', l => l.map(x => x.textContent)), ['Backup', 'Download']);
    assert.match(await page.textContent('.cu-eta'), /^Running for 0:0\d min\./);
    await advance(page, 20000);
    assert.equal(await page.textContent('.cu-percent'), '47 %', 'no made-up progress while the host reports nothing new');
    // CoreAI is down while PHP and web restart: the last progress stays.
    srv.status = () => 'down';
    await advance(page, 30000);
    assert.equal(await mode(page), 'wait', 'a restart (502) keeps the window waiting');
    assert.equal(await reloaded(page), false, 'no reload while CoreAI is down: ' + JSON.stringify(navigationState.get(page)));
    assert.equal(await page.textContent('.cu-percent'), '47 %', 'the last progress stays while CoreAI is down');
    assert.match(await page.textContent('.cu-eta'), /CoreAI is restarting/);
    assert.equal(await page.$eval('.cu-bar', b => b.classList.contains('is-moving')), false);
    // Back with the preflight; a lower value never moves the bar back.
    srv.status = running('install', 30, 'Checking installed components.');
    await advance(page, 3100);
    assert.equal(await page.textContent('.cu-percent'), '47 %', 'progress never goes back');
    srv.status = running('install', 84, 'Checking installed components.');
    await advance(page, 3100);
    assert.equal(await page.textContent('.cu-percent'), '84 %');
    assert.match(await page.textContent('.cu-eta'), /^Running for 1:\d\d min\./);
    assert.equal(await page.isVisible('.cu-reload'), false, 'no manual reload yet');
    srv.status = running('restart', 94, 'Restarting Ollama.');
    await advance(page, 3100);
    assert.deepEqual(await page.$$eval('.cu-steps li.is-active', l => l.map(x => x.textContent)), ['Restart']);
    // Back on the new version: reload by itself.
    srv.status = () => ({ok: true, running: false, state: 'done', phase: 'done', percent: 100, step: 'Done.', version: '0.5.84'});
    await advance(page, 3100);
    assert.equal(await page.textContent('.cu-phase'), 'Restart complete.');
    assert.equal(await page.textContent('.cu-percent'), '100 %');
    await Promise.all([page.waitForEvent('load'), page.clock.runFor(1300)]);
    assert.equal(await reloaded(page), true, 'the page reloads when CoreAI is back');
    assert.deepEqual(errors, []);
    await page.close();

    // A failed update: the Inhaber sees the error and the log, and can retry.
    ({page, srv, errors} = await open(browser, 0, null, s => {
      s.status = () => ({ok: true, running: false, state: 'failed', phase: 'install', version: '0.5.83', owner: true, csrf_token: 'tok',
        update: info({state: 'failed', phase: 'install', failure_kind: 'preflight', message: 'Die Installation meldet Fehler. Details im Update-Protokoll.'}),
        log: ['[CoreAI Update] FEHLER in Phase install']});
    }));
    await page.waitForSelector('.cu-window');
    assert.equal(await page.textContent('#cuTitle'), 'Update failed');
    assert.equal(await page.textContent('.cu-window > .cu-error'), 'The installation reported errors. See the update log for details.');
    assert.equal(await page.textContent('.cu-log pre'), '[CoreAI Update] FEHLER in Phase install');
    assert.equal(await page.textContent('.cu-log summary'), 'Update log (latest lines)');
    assert.equal(await page.textContent('#updateCardOpen'), 'OPEN UPDATE WINDOW');
    assert.equal(await page.textContent('.cu-start'), 'Retry');
    assert.equal(await page.locator('.cu-start svg.cu-retry-icon').count(), 1,
      'the retry action contains a circular-arrow icon');
    assert.match(await page.textContent('.cu-retry-hint'), /Preflight reported errors.*update log/,
      'a Preflight failure gets a friendly but factual retry explanation');
    assert.match(await page.textContent('#updateCardState'), /^Last update failed/);
    assert.deepEqual(errors, []);
    await page.close();

    // Host service missing (first update not yet through the one-liner).
    ({page, srv, errors} = await open(browser, 0, null, s => {
      s.status = () => ({ok: true, running: false, state: 'idle', phase: '', version: '0.5.83', owner: true, csrf_token: 'tok', update: info({installed: false})});
    }));
    await page.waitForSelector('.cu-window');
    assert.equal(await page.$eval('.cu-start', b => b.disabled), true, 'no start without the host service');
    assert.match(await page.textContent('.cu-start-error'), /setup one-liner/);
    await page.close();

    // A Nutzer: never the golden window, no card, but the waiting window while CoreAI restarts.
    ({page, srv, errors} = await open(browser, 4));
    await advance(page, 500);
    assert.equal(await page.locator('#coreaiUpdate').count(), 0, 'no update window for a Nutzer');
    assert.equal(await page.isHidden('#updateCard'), true, 'no update card for a Nutzer');
    srv.status = () => ({ok: true, running: true, state: 'running', phase: 'install', percent: 60, step: 'Applying database migrations.', version: '0.5.83'});
    await advance(page, 61000);
    await page.waitForSelector('.cu-wait');
    assert.equal(await page.textContent('.cu-phase'), 'Applying database migrations.');
    assert.equal(await page.textContent('.cu-percent'), '60 %', 'a Nutzer sees the same progress');
    assert.deepEqual(await page.$$eval('.cu-steps li.is-done', l => l.map(x => x.textContent)), ['Backup', 'Download'],
      'earlier steps marked done');
    assert.equal(srv.calls.filter(c => c.key === 'update.php' && c.post).length, 0, 'a Nutzer never posts');
    srv.status = () => ({ok: true, running: false, state: 'done', phase: 'done', version: '0.5.84'});
    await advance(page, 3100);
    await Promise.all([page.waitForEvent('load'), page.clock.runFor(1300)]);
    assert.equal(await reloaded(page), true, 'the Nutzer page reloads too');
    assert.deepEqual(errors, []);
    await page.close();

    // Phone: the window fits and the page does not scroll sideways.
    ({page, srv, errors} = await open(browser, 0, {width: 390, height: 844}));
    await page.waitForSelector('.cu-window');
    const phone = await page.locator('.cu-window').boundingBox();
    assert.ok(phone.x >= 15 && phone.x + phone.width <= 375 && phone.y >= 0 && phone.y + phone.height <= 844, 'fits a phone');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'no sideways scroll');
    const tiny = await page.$$eval('#coreaiUpdate *', nodes => nodes.filter(n => Array.from(n.childNodes).some(c => c.nodeType === 3 && c.textContent.trim())
      && parseFloat(getComputedStyle(n).fontSize) < 11).map(n => n.className));
    assert.deepEqual(tiny, [], 'no text below 11 px');
    await page.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI update window: English owner UI on a German phone locale, real progress, retry, failure log, user safety and phone layout OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
