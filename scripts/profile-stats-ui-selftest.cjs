// CoreAI 0.6.84: Settings > Profil overview in a real browser (Playwright).
//   node scripts/profile-stats-ui-selftest.cjs
// Serves settings.html with a stand-in for api/profile_stats.php and checks: picture, name, role
// and "dabei seit", the figures in German like the reference (2,1 Mrd., 195,4 Mio., 1 Std. 50
// Min., 27 Tage), the note since when tokens are counted, the raster of 53 weeks (tokens after
// the start, messages before, Täglich, Wöchentlich, Kumulativ, months), what Mara made with links
// to its chats, top tools, insights, planned public profile, sharing and highlights, the pencil,
// a saved name in the head at once, a new account, a server without migration 029, a failed
// request, and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg'};
const shots = process.env.COREUI_SHOTS || '';

function raster(today, since) {
  const out = [];
  const end = new Date(today + 'T12:00:00Z');
  for (let i = 370; i >= 0; i--) {
    const d = new Date(end.getTime() - i * 86400000).toISOString().slice(0, 10);
    const m = i % 9 === 0 ? 0 : (i % 5) + 1;
    out.push({d, m, t: d >= since ? 1000000 + i * 1000 : 0});
  }
  out[370].t = 1200200;
  return out;
}

const FULL = {
  ok: true,
  profile: {display_name: 'Patrick Schildgen', assistant_name: 'Mara', avatar: null, role: 'Inhaber', member_since: '2026-03-14'},
  stats: {
    today: '2026-11-09',
    tokens: {ready: true, since: '2026-11-07', total: 2100000000, peak: 195400000, peak_day: '2026-11-08'},
    longest_task_seconds: 6600,
    streak: {longest: 27, current: 22, active_days: 140},
    messages: 4321, chats: 87,
    raster: raster('2026-11-09', '2026-11-07'),
    tools: [{id: 'web', label: 'Websuche', count: 120}, {id: 'image', label: 'Bilder', count: 44}, {id: 'python', label: 'Python', count: 12},
      {id: 'skills', label: 'Skills', count: 390}].sort((a, b) => b.count - a.count),
    made: {images: 44, files: 9, latest: [
      {type: 'image', uuid: 'a'.repeat(32), name: 'Bild', session: 'cs_img0123456789', at: '2026-11-09 07:30:00'},
      {type: 'file', uuid: '', name: 'bericht.pdf', session: 'cs_files012345678', at: '2026-11-08 18:00:00'}]},
    insights: {calls: 300, fast_share: 1, top_core: {label: 'TCI Core Normal', share: 62}, top_thinking: {label: 'Hoch', share: 37},
      skills_found: 15, skills_used: 390},
  },
};
const EMPTY = {
  ok: true,
  profile: {display_name: 'Lena', assistant_name: 'Nova', avatar: null, role: 'Nutzer', member_since: '2026-11-09'},
  stats: {today: '2026-11-09', tokens: {ready: true, since: '2026-11-09', total: 0, peak: 0, peak_day: ''}, longest_task_seconds: 0,
    streak: {longest: 0, current: 0, active_days: 0}, messages: 0, chats: 0, raster: raster('2026-11-09', '2026-11-10').map(r => ({d: r.d, m: 0, t: 0})),
    tools: [], made: {images: 0, files: 0, latest: []},
    insights: {calls: 0, fast_share: 0, top_core: null, top_thinking: null, skills_found: 0, skills_used: 0}},
};

const api = {
  'auth.php': {ok: true, user_id: 1, username: 'patrick@example.test', display_name: 'Patrick Schildgen', permission_level: 0, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Patrick Schildgen', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 0, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'health.php': {ok: true, version: '0.6.84', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
  'account_security.php': {ok: true, sessions: []},
  'notifications.php': {ok: true, ready: true, owner: true, csrf_token: 'tok', answer_seconds: 45, prefs: {}, categories: {},
    push: {available: false, public_key: '', devices: []}, email: {available: false, address: ''}},
};

async function open(browser, stats, opts = {}) {
  const context = await browser.newContext({viewport: opts.viewport || {width: 1200, height: 900}, locale: 'de-DE'});
  const page = await context.newPage();
  const errors = [];
  const asked = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.route('https://coreui.test/**', async route => {
    const url = new URL(route.request().url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    if (rel === 'api/profile_stats.php') {
      asked.push(route.request().method());
      if (opts.fail) return route.fulfill({status: 500, json: {ok: false, error: 'server_error'}});
      return route.fulfill({json: stats});
    }
    if (rel === 'api/console_media.php') return route.fulfill({contentType: 'image/png', body: fs.readFileSync(path.join(root, 'images', 'mara_winter.jpg'))});
    // profile.php and profile_stats.php read the same account, so they agree on the names.
    if (rel === 'api/profile.php') return route.fulfill({json: {ok: true, csrf_token: 'tok',
      profile: {display_name: stats.profile.display_name, assistant_name: stats.profile.assistant_name, avatars: {}}}});
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  await page.goto('https://coreui.test/settings.html#' + (opts.hash || 'profil'));
  return {context, page, errors, asked};
}
const text = (page, sel) => page.textContent(sel);
const ready = page => page.waitForFunction(() => !document.getElementById('profileOverview').hasAttribute('aria-busy'));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    // --- A busy account, like the reference --------------------------------------------------------
    let {context, page, errors, asked} = await open(browser, FULL);
    await ready(page);
    assert.equal(await text(page, '#profileOverviewName'), 'Patrick Schildgen');
    assert.equal(await text(page, '#profileOverviewAvatar'), 'PS');
    assert.equal(await text(page, '#profileOverviewSub'), 'Inhaber · dabei seit März 2026');
    assert.deepEqual(await page.$$eval('.po-stat', l => l.map(x => x.querySelector('strong').textContent + ' | ' + x.querySelector('span').textContent)),
      ['2,1 Mrd. | Tokens insgesamt', '195,4 Mio. | Token-Spitzenwert', '1 Std. 50 Min. | Längste Aufgabe', '27 Tage | Längste Serie', '22 Tage | Aktuelle Serie']);
    assert.equal(await page.$eval('#poTokensPeak', n => n.parentNode.title), 'Höchster Tag: 08.11.2026');
    assert.equal(await text(page, '#poTokensNote'), 'Tokens zählt CoreAI seit dem 07.11.2026. Serien und Aufgaben stammen aus deinem ganzen Verlauf.');
    assert.deepEqual(asked, ['GET'], 'asked once, read only');

    // Raster: 53 weeks from Monday, tokens after the start, messages before.
    const dots = await page.$$eval('#poRaster .po-dot', l => l.map(d => ({c: d.className, t: d.title})));
    const real = dots.filter(d => !/empty/.test(d.c));
    assert.equal(real.length, 371, '371 days');
    const first = new Date('2025-11-04T12:00:00Z').getUTCDay(); // Tuesday
    assert.equal(dots.length - real.length, (first + 6) % 7, 'padded to Monday');
    assert.equal(real[370].t, 'Mo., 09.11.2026: 1.200.200 Tokens');
    assert.match(real[370].c, /po-dot tok l\d/);
    assert.match(real[0].c, /po-dot msg l\d/);
    assert.match(real[0].t, /^Di\., 04\.11\.2025: \d+ Nachrichten$/);
    const months = await page.$$eval('#poMonths span', l => l.map(x => x.textContent));
    assert.ok(months.length >= 11 && months.includes('Nov') && months.includes('Jan') && months.includes('Mär'), 'month labels: ' + months.join(','));
    assert.deepEqual(await page.$$eval('#poLegend .po-legend-item', l => l.map(x => x.textContent)),
      ['Tokens (gezählt seit 07.11.2026)', 'Davor: deine Nachrichten pro Tag']);
    if (shots) await page.locator('#profileOverview').screenshot({path: path.join(shots, 'profile-head.png')});
    await page.click('#poRasterMode label:nth-child(2)');
    const week = await page.$$eval('#poRaster .po-dot:not(.empty)', l => l.slice(-7).map(d => d.title));
    assert.ok(week.every(t => / Woche: /.test(t)), 'weekly titles');
    // The week of 07. and 08.11. (two counted days) has more tokens than the new week with one day.
    const weekOf = await page.$$eval('#poRaster .po-dot:not(.empty)', l => Object.fromEntries(l.slice(-2).map(d => [d.title.slice(0, 14), d.className])));
    assert.deepEqual(weekOf, {'So., 08.11.202': 'po-dot tok l4', 'Mo., 09.11.202': 'po-dot tok l3'}, 'weekly levels: ' + JSON.stringify(weekOf));
    await page.click('#poRasterMode label:nth-child(3)');
    const sum = await page.$$eval('#poRaster .po-dot:not(.empty)', l => l.slice(-1).map(d => d.title)[0]);
    assert.match(sum, /^Mo\., 09\.11\.2026: bis hier: [\d.]+ Tokens$/);
    await page.click('#poRasterMode label:nth-child(1)');

    // What Mara made, tools, insights.
    assert.equal(await text(page, '#poMadeTitle'), 'Von Mara erstellt');
    assert.equal(await text(page, '#poMadeCount'), '44 Bilder · 9 Dateien');
    assert.deepEqual(await page.$$eval('#poMadeList a', l => l.map(a => a.getAttribute('href'))),
      ['./app.html?session=cs_img0123456789', './app.html?session=cs_files012345678']);
    assert.equal(await page.getAttribute('#poMadeList img', 'src'), './api/console_media.php?uuid=' + 'a'.repeat(32));
    assert.match(await text(page, '#poMadeList .po-made-item.file'), /bericht\.pdf\s*08\.11\.2026/);
    assert.equal(await page.isVisible('#poMadeEmpty'), false);
    assert.deepEqual(await page.$$eval('#poTools .po-tool', l => l.map(x => x.querySelector('strong').textContent + ' ' + x.querySelector('small').textContent)),
      ['Skills 390×', 'Websuche 120×', 'Bilder 44×', 'Python 12×']);
    assert.deepEqual(await page.$$eval('#poInsights .po-insight', l => l.map(x => x.querySelector('dt').textContent + ' = ' + x.querySelector('dd').textContent)), [
      'TCI Core Schnell = 1 % der Durchläufe', 'Meistgenutzter Core = TCI Core Normal · 62 %', 'Meistgenutzter Denkaufwand = Hoch · 37 %',
      'Entdeckte Skills = 15', 'Insgesamt genutzte Skills = 390', 'Nachrichten insgesamt = 4.321', 'Chats = 87', 'Aktive Tage = 140']);

    // Planned parts, the pencil, a saved name.
    assert.equal(await page.isDisabled('#profilePublic'), true);
    assert.equal(await page.isDisabled('#profileShare'), true);
    assert.equal(await page.getAttribute('#profilePublic', 'title'), 'Öffentliches Profil: In Vorbereitung');
    assert.equal(await page.isDisabled('.profile-highlights button'), true);
    assert.equal(await text(page, '.profile-highlights .notify-planned'), 'IN VORBEREITUNG');
    await page.click('#profileEditJump');
    await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'profileDisplayName');
    await page.evaluate(() => document.dispatchEvent(new CustomEvent('coreai:profile', {detail: {display_name: 'Patrick S.', assistant_name: 'Nova', avatars: {user: null}}})));
    assert.equal(await text(page, '#profileOverviewName'), 'Patrick S.');
    assert.equal(await text(page, '#poMadeTitle'), 'Von Nova erstellt');
    const sizes = await page.$$eval('#view-profil .profile-overview *, #view-profil .profile-raster *, #view-profil .profile-made *, #view-profil .profile-tools *, #view-profil .profile-insights *, #view-profil .profile-highlights *',
      l => l.filter(n => n.offsetParent && n.childNodes.length && Array.from(n.childNodes).some(c => c.nodeType === 3 && c.textContent.trim()))
        .map(n => parseFloat(getComputedStyle(n).fontSize)));
    assert.ok(Math.min(...sizes) >= 11, 'no text below 11px: ' + Math.min(...sizes));
    if (shots) await page.locator('#view-profil').screenshot({path: path.join(shots, 'profile-desktop.png')});
    assert.deepEqual(errors, [], 'no page errors');
    await context.close();

    // --- A new account ---------------------------------------------------------------------------------
    ({context, page, errors} = await open(browser, EMPTY));
    await ready(page);
    assert.deepEqual(await page.$$eval('.po-stat strong', l => l.map(x => x.textContent)), ['0', '0', '-', '0 Tage', '0 Tage']);
    assert.equal(await page.isVisible('#poMadeEmpty'), true);
    assert.equal(await text(page, '#poMadeEmpty p'), 'Nova hat für dich noch keine Bilder oder Dateien erstellt.');
    assert.equal(await page.getAttribute('#poMadeEmpty a', 'href'), './app.html');
    assert.equal(await page.isVisible('#poToolsEmpty'), true);
    assert.equal(await page.$$eval('#poInsights dd', l => l.slice(0, 3).map(x => x.textContent).join('|')), 'noch keine Daten|noch keine Daten|noch keine Daten');
    assert.equal(await page.$$eval('#poRaster .po-dot.l0', l => l.length), 371, 'all days empty');
    assert.deepEqual(errors, []);
    await context.close();

    // --- Migration 029 not applied yet, then a failed request ---------------------------------------------
    const noMigration = JSON.parse(JSON.stringify(FULL));
    noMigration.stats.tokens = {ready: false, since: '', total: 0, peak: 0, peak_day: ''};
    ({context, page, errors} = await open(browser, noMigration));
    await ready(page);
    assert.deepEqual(await page.$$eval('.po-stat strong', l => l.slice(0, 2).map(x => x.textContent)), ['-', '-']);
    assert.equal(await text(page, '#poTokensNote'), 'Die Token-Zählung startet, sobald das Update vollständig eingespielt ist (Datenbank-Migration 029).');
    assert.equal(await page.$$eval('#poRaster .po-dot.tok', l => l.length), 0, 'only messages without counting');
    assert.deepEqual(errors, []);
    await context.close();
    ({context, page, errors} = await open(browser, FULL, {fail: true}));
    await ready(page);
    assert.equal(await text(page, '#poTokensNote'), 'Die Profilübersicht konnte nicht geladen werden.');
    assert.deepEqual(errors, []);
    await context.close();

    // --- Opened later from another page: loads on the way in --------------------------------------------
    ({context, page, errors, asked} = await open(browser, FULL, {hash: 'anzeige'}));
    await page.waitForTimeout(300);
    assert.deepEqual(asked, [], 'not asked before Profil is opened');
    await page.evaluate(() => { location.hash = '#profil'; });
    await ready(page);
    assert.deepEqual(asked, ['GET']);
    assert.deepEqual(errors, []);
    await context.close();

    // --- A phone ------------------------------------------------------------------------------------------
    ({context, page, errors} = await open(browser, FULL, {viewport: {width: 390, height: 844}}));
    await ready(page);
    const fit = await page.evaluate(() => {
      const view = document.getElementById('view-profil');
      const wide = Array.from(view.querySelectorAll('.po-stats, .po-raster, .po-tools, .po-made, .po-insights, .po-raster-mode'))
        .filter(n => n.getBoundingClientRect().right > innerWidth + 1).map(n => n.className);
      return {scroll: document.documentElement.scrollWidth, view: innerWidth, wide};
    });
    assert.ok(fit.scroll <= fit.view + 1 && fit.wide.length === 0, 'fits a phone: ' + JSON.stringify(fit));
    const dot = await page.$eval('#poRaster .po-dot:not(.empty)', d => d.getBoundingClientRect().width);
    assert.ok(dot >= 3, 'dots stay visible on a phone: ' + dot);
    if (shots) await page.locator('#view-profil').screenshot({path: path.join(shots, 'profile-phone.png')});
    assert.deepEqual(errors, []);
    await context.close();

    console.log('CoreAI profile UI: head, figures like the reference, token note, 53-week raster (tokens/messages, daily, weekly, cumulative, months), '
      + 'made with chat links, top tools, insights, planned public/share/highlights, pencil, saved name, new account, no migration, failed request, '
      + 'loads when opened, phone OK');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
