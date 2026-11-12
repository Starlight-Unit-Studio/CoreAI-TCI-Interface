// CoreAI 0.7.30: Settings > Nutzung after the reference screenshots, in a real browser (Playwright).
//   node scripts/usage-ui-selftest.cjs
// A stand-in for api/usage.php. Checked: Übersicht (plan, the daily limit with its countdown and bar,
// storage with "Speicher verwalten", no limit, today by hour and the last 7 days), Analysen (range and
// its steps, tokens by Core or thinking with shares and a tooltip, the top chats with links, a locked
// chat without one and "Mehr anzeigen", the days, tokens by Core with the Core menu, tools and skills,
// messages all or by kind), empty ranges, guests and a phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';

const TODAY = '2026-11-12';
const addDays = (d, n) => { const t = new Date(d + 'T12:00:00Z'); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); };
function range(days, back) {
  const to = addDays(TODAY, -back * days);
  const from = addDays(to, -(days - 1));
  const list = Array.from({length: days}, (_, i) => addDays(from, i));
  return {days, back, from, to, list, latest: back === 0, oldest: back >= 52};
}
const spread = (days, values) => { const out = new Array(days).fill(0); values.forEach(([i, v]) => { out[days - 1 - i] = v; }); return out; };

function overview(opts) {
  return {ok: true, today: TODAY,
    plan: opts.noLimit ? {label: 'Kein Plan', role: 'Nutzer', note: 'Für dein Konto gelten keine eigenen Limits.', features: ['Unbegrenzte Textchats'], server_off: [], max_num_ctx: null}
      : {label: 'Plus', role: 'Nutzer', note: 'Deinen Plan legt der Inhaber oder ein Admin in Admin Core fest. Alle Pläne sind kostenlos.',
        features: ['Bis zu 10 Nachrichten pro Tag', 'Websuche, Recherche im Browser und Bilder', 'Ohne Python', 'Kontextfenster bis 16K'], server_off: ['Python'], max_num_ctx: 16384},
    daily: opts.noLimit ? {limit: null, used: 0, left: null, reset_at: null, started: false}
      : {limit: 10, used: 3, left: 7, reset_at: new Date(Date.now() + (3 * 3600 + 34 * 60 - 10) * 1000).toISOString(), started: true},
    storage: opts.noLimit ? {used: 210000, limit: null} : {used: 1610612736, limit: 21474836480},
    hours: Array.from({length: 24}, (_, h) => (h === 9 ? 2 : h === 11 ? 4 : 0)),
    week: range(7, 0).list.map((d, i) => ({d, n: [0, 0, 0, 1, 0, 6, 6][i]})),
    messages_today: 6, tokens_today: 2000};
}
function analytics(days, back, opts) {
  const r = range(days, back);
  if (opts.empty || back > 0) {
    const zero = new Array(days).fill(0);
    return {ok: true, range: r, tokens: {ready: !opts.notReady, since: '2026-11-04', by_core: [], by_thinking: [], total: 0},
      tools: {series: ['Websuche', 'Recherche im Browser', 'Python', 'Bilder'].map((label, i) => ({id: ['web', 'browse', 'python', 'image'][i], label, total: 0, days: zero})), total: 0},
      skills: {days: zero, total: 0, names: []},
      messages: {series: [['text', 'Text'], ['voice', 'Stimmsequenzen'], ['attach', 'Mit Anhang']].map(([id, label]) => ({id, label, total: 0, days: zero})), total: 0, days: zero}};
  }
  return {ok: true, range: r,
    tokens: {ready: true, since: '2026-11-04', total: 5000,
      by_core: [{label: 'TCI Core Normal', total: 4500, days: spread(days, [[0, 1500], [3, 3000]])}, {label: 'TCI Core Schnell', total: 500, days: spread(days, [[0, 500]])}],
      by_thinking: [{label: 'Denken an', total: 3500, days: spread(days, [[0, 500], [3, 3000]])}, {label: 'Aus', total: 1500, days: spread(days, [[0, 1500]])}]},
    tools: {total: 2, series: [{id: 'web', label: 'Websuche', total: 1, days: spread(days, [[0, 1]])}, {id: 'browse', label: 'Recherche im Browser', total: 0, days: spread(days, [])},
      {id: 'python', label: 'Python', total: 1, days: spread(days, [[0, 1]])}, {id: 'image', label: 'Bilder', total: 0, days: spread(days, [])}]},
    skills: {days: spread(days, [[0, 1]]), total: 1, names: [{name: 'tabellen', n: 1}]},
    messages: {total: 7, days: spread(days, [[0, 6], [3, 1]]), series: [{id: 'text', label: 'Text', total: 4, days: spread(days, [[0, 3], [3, 1]])},
      {id: 'voice', label: 'Stimmsequenzen', total: 2, days: spread(days, [[0, 2]])}, {id: 'attach', label: 'Mit Anhang', total: 1, days: spread(days, [[0, 1]])}]}};
}
function chats(offset, opts) {
  if (opts.empty) return {ok: true, items: [], has_more: false, offset, total_messages: 0};
  const all = [
    {id: 'cs_plan0001', title: 'Planung', locked: false, archived: false, messages: 3, share: 42.9, tools: 2, last: TODAY + ' 11:05:00'},
    {id: 'cs_trip0001', title: 'Reise', locked: false, archived: true, messages: 3, share: 42.9, tools: 0, last: TODAY + ' 11:45:00'},
    {id: '', title: 'Gesperrter Chat', locked: true, archived: false, messages: 1, share: 14.3, tools: 0, last: TODAY},
    {id: 'cs_a4', title: 'Vier', locked: false, archived: false, messages: 1, share: 1, tools: 0, last: TODAY},
    {id: 'cs_a5', title: 'Fünf', locked: false, archived: false, messages: 1, share: 1, tools: 0, last: TODAY},
    {id: 'cs_a6', title: 'Sechs', locked: false, archived: false, messages: 1, share: 1, tools: 0, last: TODAY}];
  return {ok: true, items: all.slice(offset, offset + 5), has_more: all.length > offset + 5, offset, total_messages: 7};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 3, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 3, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'health.php': {ok: true, version: '0.7.30', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, opts, viewport) {
  opts = opts || {};
  const calls = [];
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const u = new URL(route.request().url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'api/usage.php') {
      calls.push(u.search);
      if (opts.guest) return route.fulfill({status: 403, json: {ok: false, error: 'guests_not_allowed'}});
      const a = u.searchParams.get('action');
      const days = Number(u.searchParams.get('days') || 7), back = Number(u.searchParams.get('back') || 0);
      if (a === 'overview') return route.fulfill({json: overview(opts)});
      if (a === 'analytics') return route.fulfill({json: analytics(days, back, opts)});
      if (a === 'chats') return route.fulfill({json: chats(Number(u.searchParams.get('offset') || 0), back > 0 ? {empty: true} : opts)});
      return route.fulfill({status: 404, json: {ok: false}});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#nutzung');
  return {context, page, errors, calls};
}
const until = (page, fn, arg, timeout = 8000) => page.waitForFunction(fn, arg, {timeout});
const texts = (page, sel) => page.$$eval(sel, l => l.map(x => x.textContent.trim()));
const noSideways = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let {context, page, errors, calls} = await open(browser);
    await until(page, () => !document.getElementById('nuMain').hidden);
    // Übersicht: plan and limits.
    assert.equal(await page.isHidden('#nuAnalytics'), true);
    assert.equal(await page.textContent('#nuPlanIntro'), 'Dein Plan: Plus. Deinen Plan legt der Inhaber oder ein Admin in Admin Core fest. Alle Pläne sind kostenlos.');
    assert.deepEqual(await texts(page, '#nuLimits .nu-limit-head strong'), ['Tageslimit', 'Speicher']);
    assert.deepEqual(await texts(page, '#nuLimits .nu-limit-line span'), ['Bis zum Zurücksetzen: 3 Std., 34 Min.', '1,5 GB von 20 GB verwendet']);
    assert.deepEqual(await texts(page, '#nuLimits .nu-limit-line b'), ['70 % übrig', '93 % übrig']);
    assert.deepEqual(await page.$$eval('#nuLimits .nu-bar', l => l.map(x => x.getAttribute('aria-valuenow'))), ['70', '93']);
    assert.equal(await page.textContent('#nuLimits .nu-limit-foot'), '3 Nachrichten von 10 verwendet.');
    assert.equal(await page.getAttribute('#nuLimits [data-goto]', 'data-goto'), 'speicher');
    assert.deepEqual(await texts(page, '#nuFeatures li'), ['Bis zu 10 Nachrichten pro Tag', 'Websuche, Recherche im Browser und Bilder', 'Ohne Python',
      'Kontextfenster bis 16K', 'Auf diesem Server aus: Python']);
    // Today by hour, then the last 7 days.
    assert.equal(await page.textContent('#nuNowBig'), '6 Nachrichten');
    assert.equal(await page.textContent('#nuNowSub'), 'Heute · 2.000 Tokens');
    assert.equal(await page.locator('#nuNowChart rect').count(), 2);
    if (shots) await page.locator('#view-nutzung').screenshot({path: path.join(shots, 'usage-overview.png')});
    await page.click('[data-nu-now="week"]');
    assert.equal(await page.textContent('#nuNowBig'), '13 Nachrichten');
    assert.equal(await page.textContent('#nuNowSub'), 'Letzte 7 Tage · 6.–12. Nov. 2026');
    assert.equal(await page.locator('#nuNowChart rect').count(), 3);
    assert.equal(calls.filter(c => c.includes('analytics')).length, 0, 'Analysen load only when opened');

    // Analysen.
    await page.click('#nuTab-analytics');
    await until(page, () => document.getElementById('nuTokenLegend').children.length === 2);
    assert.ok(calls.includes('?action=analytics&days=7&back=0') && calls.includes('?action=chats&days=7&back=0&offset=0'));
    assert.equal(await page.textContent('#nuRangeLabel'), '6.–12. Nov. 2026');
    assert.equal(await page.isDisabled('#nuNext'), true);
    assert.equal(await page.isDisabled('#nuPrev'), false);
    assert.equal(await page.textContent('#nuTokensIntro'), 'So viele Tokens haben deine Cores gelesen und geschrieben. Gezählt seit 4. Nov. 2026.');
    assert.deepEqual(await page.$$eval('#nuTokenLegend .nu-legend-item', l => l.map(x => x.querySelector('span').textContent + ' ' + x.querySelector('b').textContent)),
      ['TCI Core Normal 90 %', 'TCI Core Schnell 10 %']);
    assert.equal(await page.locator('#nuTokenBars rect').count(), 3, 'stacked bars: two Cores today, one three days ago');
    // A tooltip for the bar of today.
    const bar = await page.locator('#nuTokenBars svg').boundingBox();
    await page.mouse.move(bar.x + bar.width - 20, bar.y + bar.height / 2);
    await until(page, () => !document.querySelector('#nuTokenBars .nu-tip').hidden);
    assert.match(await page.textContent('#nuTokenBars .nu-tip'), /^2\.000 TokensDonnerstag, 12\. Nov\.TCI Core Normal1\.500TCI Core Schnell500$/);
    await page.click('[data-nu-split="thinking"]');
    assert.deepEqual(await texts(page, '#nuTokenLegend .nu-legend-item span'), ['Denken an', 'Aus']);
    // Top chats.
    assert.deepEqual(await page.$$eval('#nuChats tbody tr', l => l.map(r => Array.from(r.children).map(c => c.textContent.trim()))),
      [['Planung', '3', '42,9 %', '2'], ['Reise archiviert', '3', '42,9 %', '–'], ['Gesperrter Chat', '1', '14,3 %', '–'], ['Vier', '1', '1 %', '–'], ['Fünf', '1', '1 %', '–']]);
    assert.equal(await page.getAttribute('#nuChats tbody tr:first-child a', 'href'), 'app.html?session=cs_plan0001');
    assert.equal(await page.locator('#nuChats tbody tr:nth-child(3) a').count(), 0, 'a locked chat has no link');
    await page.click('#nuChatsMore');
    await until(page, () => document.querySelectorAll('#nuChats tbody tr').length === 6);
    assert.ok(calls.includes('?action=chats&days=7&back=0&offset=5'));
    assert.equal(await page.isHidden('#nuChatsMore'), true);
    // The days: newest first, the first one open.
    assert.deepEqual(await texts(page, '#nuDays .nu-day-head'), ['Donnerstag, 12. Nov.6 Nachrichten', 'Montag, 9. Nov.1 Nachricht']);
    assert.deepEqual(await page.$$eval('#nuDays .nu-day:first-child .nu-day-line', l => l.map(x => x.textContent)),
      ['Tokens2.000', 'TCI Core Normal1.500', 'TCI Core Schnell500', 'Websuche1', 'Python1', 'Skills1']);
    assert.equal(await page.isHidden('#nuDays .nu-day:nth-child(2) .nu-day-body'), true);
    await page.click('#nuDays .nu-day:nth-child(2) .nu-day-head');
    assert.equal(await page.isHidden('#nuDays .nu-day:nth-child(2) .nu-day-body'), false);
    // Tokens by Core and the Core menu.
    assert.equal(await page.textContent('#nuCoreTotal'), '5.000');
    assert.equal(await page.locator('#nuCoreLines path').count(), 2);
    await page.click('#nuCoreSelect');
    assert.deepEqual(await texts(page, '#nuCoreMenu .arc-option b'), ['Alle Cores', 'TCI Core Normal', 'TCI Core Schnell']);
    await page.click('#nuCoreMenu [data-value="TCI Core Schnell"]');
    assert.equal(await page.textContent('#nuCoreTotal'), '500');
    assert.equal(await page.textContent('#nuCoreSelect span'), 'TCI Core Schnell');
    assert.equal(await page.locator('#nuCoreLines path').count(), 1);
    // Tools, skills and messages.
    assert.equal(await page.textContent('#nuToolsTotal'), '2');
    assert.deepEqual(await texts(page, '#nuToolLegend .nu-legend-item span'), ['Websuche', 'Python']);
    assert.equal(await page.textContent('#nuSkillsTotal'), '1');
    assert.equal(await page.textContent('#nuSkillNames'), 'Am häufigsten: tabellen (1)');
    assert.equal(await page.textContent('#nuMsgTotal'), '7');
    assert.equal(await page.locator('#nuMsgLines path').count(), 1);
    await page.click('[data-nu-msg="kind"]');
    assert.deepEqual(await page.$$eval('#nuMsgLegend .nu-legend-item', l => l.map(x => x.querySelector('span').textContent + ' ' + x.querySelector('b').textContent)),
      ['Text 57,1 %', 'Stimmsequenzen 28,6 %', 'Mit Anhang 14,3 %']);
    assert.equal(await page.locator('#nuMsgLines path').count(), 3);
    if (shots) await page.locator('#nuAnalytics').screenshot({path: path.join(shots, 'usage-analytics.png')});
    // 30 days and stepping back.
    await page.click('[data-nu-days="30"]');
    await until(page, () => document.getElementById('nuRangeLabel').textContent === '14. Okt. – 12. Nov. 2026');
    await page.click('#nuPrev');
    await until(page, () => document.getElementById('nuRangeLabel').textContent === '14. Sept. – 13. Okt. 2026');
    assert.ok(calls.includes('?action=analytics&days=30&back=1'));
    assert.equal(await page.isDisabled('#nuNext'), false);
    assert.equal(await page.textContent('#nuTokenBars .nu-chart-empty'), 'In diesem Zeitraum hat kein Core für dich gearbeitet.');
    assert.equal(await page.isVisible('#nuChatsEmpty'), true);
    assert.equal(await page.isVisible('#nuDaysEmpty'), true);
    await page.click('#nuNext');
    await until(page, () => document.getElementById('nuRangeLabel').textContent === '14. Okt. – 12. Nov. 2026');
    assert.deepEqual(errors, []);
    await context.close();

    // No limits; tokens not counted on this server.
    ({context, page, errors} = await open(browser, {noLimit: true, empty: true, notReady: true}));
    await until(page, () => !document.getElementById('nuMain').hidden);
    assert.deepEqual(await texts(page, '#nuLimits .nu-limit-line span'), ['Für dein Konto gibt es kein Tageslimit.', '206 KB verwendet']);
    assert.deepEqual(await texts(page, '#nuLimits .nu-limit-line b'), ['Ohne Grenze', 'Ohne Grenze']);
    assert.equal(await page.locator('#nuLimits .nu-bar').count(), 0);
    await page.click('#nuTab-analytics');
    await until(page, () => !!document.querySelector('#nuTokenBars .nu-chart-empty'));
    assert.equal(await page.textContent('#nuTokenBars .nu-chart-empty'), 'Tokens werden auf diesem Server noch nicht gezählt.');
    assert.equal(await page.textContent('#nuToolLines .nu-chart-empty'), 'In diesem Zeitraum hat Mara keine Werkzeuge aufgerufen.');
    assert.deepEqual(errors, []);
    await context.close();

    // Guests.
    ({context, page} = await open(browser, {guest: true}));
    await until(page, () => /nur für angemeldete Konten/.test(document.getElementById('nuStatus').textContent));
    assert.equal(await page.isHidden('#nuMain'), true);
    await context.close();

    // Phone.
    ({context, page, errors} = await open(browser, {}, {width: 390, height: 844}));
    await until(page, () => !document.getElementById('nuMain').hidden);
    assert.equal(await noSideways(page), 0, 'Übersicht without sideways scrolling');
    if (shots) await page.screenshot({path: path.join(shots, 'usage-phone.png')});
    await page.click('#nuTab-analytics');
    await until(page, () => document.getElementById('nuTokenLegend').children.length === 2);
    assert.equal(await noSideways(page), 0, 'Analysen without sideways scrolling');
    if (shots) await page.screenshot({path: path.join(shots, 'usage-phone-analytics.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI usage UI: Übersicht (plan, daily limit, storage, today, 7 days, no limit), Analysen (range, tokens by Core/thinking with tooltip,'
    + ' top chats, days, Core menu, tools, skills, messages by kind, 30 days, going back, empty), guests, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
